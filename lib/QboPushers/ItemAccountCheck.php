<?php
declare(strict_types=1);

/**
 * lib/QboPushers/ItemAccountCheck.php
 *
 * S-QBO-ITEM-ACCOUNT-CHECK — does each QuickBooks item post to the same
 * account FleetForge books that revenue to?
 *
 * A pushed invoice line lands in QuickBooks on its ITEM's income account;
 * FleetForge's own GL books the same line to the account its revenue
 * mapping names (AutoEntryBridge — accounting.revenue_account_map, the GPS
 * gross / net accounts, Bad Debt Expense for write-offs). Nothing compared
 * the two (audit B8), so the two P&Ls could disagree account by account
 * without anyone noticing — the production-copy rehearsal put all 28 items
 * on "Sales" while FF splits revenue across 4010–4122.
 *
 * This is a REPORT, not a gate: which account is right is the accountant's
 * call (fix the QuickBooks item, or FF's revenue mapping). Statuses:
 *   ok                  — the item's account is the one mapped to FF's account
 *   different           — it isn't (both named, so the fix is obvious)
 *   ff_account_unmapped — FF's account has no QuickBooks account linked yet
 *   no_ff_account       — FF has no account for this line type at all
 *   item_account_unknown— the item's income account wasn't pulled (Pull again)
 * plus notes: FF used its 'other' FALLBACK (no specific mapping for the
 * line type), and GPS NET presentation (FF splits margin + Samsara cost
 * into two accounts; a QuickBooks item has one — expected to differ).
 *
 * @session  S-QBO-ITEM-ACCOUNT-CHECK
 * @decision D-QBO-ITEM-ACCOUNT-CHECK-1
 */

namespace FleetForge\QboPushers;

use FleetForge\Accounting\AccountingService;
use FleetForge\Accounting\AutoEntryBridge;

final class ItemAccountCheck
{
    /**
     * The FF GL account a line of this type books to, with how it was
     * chosen.
     *
     * @return array{account_id:?int, fallback:bool, note:?string}
     */
    public static function ffAccountFor(string $itemType, ?string $variant): array
    {
        if ($itemType === InvoiceWriteoffPusher::ITEM_TYPE) {
            $id = AccountingService::setting('accounting.bad_debt_expense_account_id');
            return ['account_id' => $id ? (int) $id : null, 'fallback' => false, 'note' => null];
        }
        if ($itemType === 'gps') {
            // AutoEntryBridge::onInvoiceSent: gross → the GPS gross account;
            // net → margin to the net account + Samsara cost to the
            // recoverable account (two accounts; one QuickBooks item).
            if ($variant === 'net') {
                $id = AccountingService::setting('accounting.gps_net_revenue_account_id');
                if ($id) {
                    return ['account_id' => (int) $id, 'fallback' => false,
                            'note' => 'Net GPS: FleetForge books the margin here and the Samsara cost to the recoverable account; a QuickBooks item posts the whole line to one account, so the P&L lines differ by design until the accountant decides.'];
                }
            } else {
                $id = AccountingService::setting('accounting.gps_gross_revenue_account_id');
                if ($id) {
                    return ['account_id' => (int) $id, 'fallback' => false, 'note' => null];
                }
            }
        }
        $map = AccountingService::setting('accounting.revenue_account_map', []);
        if (is_string($map)) {
            $map = json_decode($map, true) ?? [];
        }
        return [
            'account_id' => AutoEntryBridge::revenueAccountForLineType($itemType),
            'fallback'   => !isset($map[$itemType]),
            'note'       => null,
        ];
    }

    /**
     * Check one mapped item row (acc_qbo_item_map columns).
     *
     * @param array<string,mixed> $row
     * @return array{status:string, ff_account:?array, expected_qbo_account:?array, fallback:bool, note:?string, message:string}
     */
    public static function checkRow(array $row): array
    {
        $ff = self::ffAccountFor((string) $row['ff_item_type'], $row['ff_item_type_variant'] ?? null);
        $out = ['status' => 'ok', 'ff_account' => null, 'expected_qbo_account' => null,
                'fallback' => $ff['fallback'], 'note' => $ff['note'], 'message' => ''];
        if ($ff['account_id'] === null) {
            $out['status']  = 'no_ff_account';
            $out['message'] = 'FleetForge has no revenue account for this line type (Accounting → Settings → Revenue Mapping).';
            return $out;
        }
        $acct = db_row("SELECT id, code, name FROM acc_accounts WHERE id = ?", [$ff['account_id']]);
        $out['ff_account'] = $acct ? ['id' => (int) $acct['id'], 'code' => (string) $acct['code'], 'name' => (string) $acct['name']] : null;
        $ffLabel = $acct ? "{$acct['code']} {$acct['name']}" : "account #{$ff['account_id']}";
        $fallbackTail = $ff['fallback'] ? " (FleetForge's fallback — no specific revenue account is mapped for this line type)" : '';

        $map = db_row(
            "SELECT qbo_account_id, qbo_name FROM acc_qbo_account_map WHERE ff_account_id = ? AND mapping_status = 'mapped' AND qbo_account_id IS NOT NULL",
            [$ff['account_id']]
        );
        if (!$map) {
            $out['status']  = 'ff_account_unmapped';
            $out['message'] = "FleetForge books this to {$ffLabel}{$fallbackTail}, which isn't linked to a QuickBooks account yet (QuickBooks → Accounts).";
            return $out;
        }
        $out['expected_qbo_account'] = ['id' => (string) $map['qbo_account_id'], 'name' => (string) ($map['qbo_name'] ?? '')];
        if ((string) ($row['qbo_income_account_id'] ?? '') === '') {
            $out['status']  = 'item_account_unknown';
            $out['message'] = "The QuickBooks item's income account isn't known yet — press Pull. FleetForge books this to {$ffLabel}.";
            return $out;
        }
        if ((string) $row['qbo_income_account_id'] !== (string) $map['qbo_account_id']) {
            $out['status']  = 'different';
            $out['message'] = "QuickBooks posts this item to \"" . ($row['qbo_income_account_name'] ?? $row['qbo_income_account_id'])
                . "\"; FleetForge books it to {$ffLabel}{$fallbackTail} = QuickBooks \"" . ($map['qbo_name'] ?? $map['qbo_account_id']) . '".';
            return $out;
        }
        $out['message'] = "Matches FleetForge ({$ffLabel}){$fallbackTail}.";
        return $out;
    }

    /**
     * Check every mapped item — the Items page summary.
     *
     * @return array{counts: array<string,int>, fallback: int, rows: list<array<string,mixed>>}
     */
    public static function checkAll(): array
    {
        $counts = ['ok' => 0, 'different' => 0, 'ff_account_unmapped' => 0, 'no_ff_account' => 0, 'item_account_unknown' => 0];
        $fallback = 0;
        $rows = [];
        foreach (db_select(
            "SELECT id, ff_item_type, ff_item_type_variant, qbo_name, qbo_income_account_id, qbo_income_account_name
               FROM acc_qbo_item_map
              WHERE mapping_status = 'mapped' AND ff_item_type IS NOT NULL AND qbo_item_id IS NOT NULL
              ORDER BY ff_item_type, ff_item_type_variant"
        ) as $r) {
            $c = self::checkRow($r);
            $counts[$c['status']]++;
            $fallback += $c['fallback'] ? 1 : 0;
            $rows[] = ['mapping_id' => (int) $r['id'], 'ff_item_type' => $r['ff_item_type'], 'variant' => $r['ff_item_type_variant'], 'qbo_name' => $r['qbo_name']] + $c;
        }
        return ['counts' => $counts, 'fallback' => $fallback, 'rows' => $rows];
    }
}
