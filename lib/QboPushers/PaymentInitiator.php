<?php
declare(strict_types=1);

/**
 * lib/QboPushers/PaymentInitiator.php
 *
 * Phase QBO-6 / 3 of 3 (S-QBO-15) — generates QBO Payments hosted-page URLs
 * for FF portal customers paying invoices online.
 *
 * Per QUICKBOOKS_SPEC.md §11 (QBO Payments embed), the flow is:
 *   1. Portal customer clicks "Pay Online" on invoice view page
 *   2. api/v1/portal/invoices/initiate_qbo_payment.php calls
 *      PaymentInitiator::generate($ffInvoiceId, $portalUserId)
 *   3. This class runs 7 preflight gates, generates a hosted URL via
 *      QuickBooksClient::generatePaymentsHostedUrl, persists an
 *      acc_qbo_payment_initiations row, returns {url, token, expires_at}
 *   4. Portal JS redirects window.location = url
 *   5. Customer pays at Intuit's hosted page (PCI scope avoided per §11.4)
 *   6. QBO fires webhook + redirects browser to ?token=X return URL
 *   7. PaymentWebhookHandler::handle() (extended this session) calls
 *      PaymentInitiator::matchByQboInvoice() to handshake the initiation
 *      row → marks status='completed' + sets qbo_payment_id
 *   8. Return URL page polls for completion (up to 30s) for UX
 *
 * Per D-QBO-15-1: match strategy is qbo_invoice_id + latest pending. The
 * UNIQUE-pending-per-invoice invariant is enforced at the app layer via
 * expire-before-insert because MySQL lacks partial unique indexes.
 *
 * Per D-QBO-15-5: idempotency — calling generate() twice with the same
 * (ff_invoice_id, ff_portal_user_id) within the URL TTL returns the
 * same URL (existing pending row, unexpired). Past the TTL, a new URL
 * is generated.
 *
 * @session  S-QBO-15
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §11 (QBO Payments embed)
 * @decision D-QBO-15-1 (match by qbo_invoice_id + latest pending),
 *           D-QBO-15-2 (Intuit Payments API abstraction),
 *           D-QBO-15-3 (preflight gates: invoice status + currency match
 *               + QBO mapping + payments_enabled + portal-user ownership),
 *           D-QBO-15-4 (webhook handshake via matchByQboInvoice),
 *           D-QBO-15-5 (idempotency: existing pending+unexpired returns
 *               same URL)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;
use FleetForge\Exceptions\QuickBooksException;

class PaymentInitiator
{
    /**
     * §6.8 canonical return shape — every return path includes these keys
     * so the caller (portal endpoint) gets predictable output.
     */
    private const RESULT_BASE = [
        'success'    => false,
        'status'     => null,
        'url'        => null,
        'token'      => null,
        'expires_at' => null,
        'error'      => null,
    ];

    /**
     * Generate (or return existing) a QBO Payments hosted-page URL for
     * the given FF invoice. Called from the portal Pay Online endpoint.
     *
     * Idempotency per D-QBO-15-5: if an existing pending+unexpired row
     * exists for this invoice, the SAME URL is returned (no new Intuit
     * API call). Existing expired+pending rows get auto-expired and a
     * fresh URL is generated.
     *
     * @param  int $ffInvoiceId  invoices.id
     * @param  int $portalUserId portal_users.id (for audit)
     * @return array{success: bool, status: string, url: ?string, token: ?string, expires_at: ?string, error: ?string}
     */
    public static function generate(int $ffInvoiceId, int $portalUserId): array
    {
        // ── Preflight gates per D-QBO-15-3 ──────────────────────────────

        // Gate 0: feature gate.
        if ((string) settings_get('quickbooks.payments_enabled', '0') !== '1') {
            return [
                'success' => false,
                'status'  => 'feature_disabled',
                'error'   => 'QBO Payments not enabled (settings.quickbooks.payments_enabled). Operator must enable in /quickbooks/settings.',
            ] + self::RESULT_BASE;
        }

        // Gate 1: connection.
        if ((string) settings_get('quickbooks.connection_status', '') !== 'connected') {
            return [
                'success' => false,
                'status'  => 'not_connected',
                'error'   => 'QBO not connected.',
            ] + self::RESULT_BASE;
        }

        // Gate 2: invoice exists + customer match (portal user must own
        // the invoice's customer for security; trap-8 portal isolation).
        $ff = db_row(
            "SELECT i.id, i.invoice_number, i.customer_id, i.status, i.currency,
                    i.balance_due, i.total_amount
               FROM invoices i
              WHERE i.id = ? AND i.deleted_at IS NULL",
            [$ffInvoiceId]
        );
        if ($ff === null) {
            return [
                'success' => false,
                'status'  => 'invoice_not_found',
                'error'   => "FF invoice {$ffInvoiceId} not found",
            ] + self::RESULT_BASE;
        }

        // Verify portal user owns this customer.
        $portalUser = db_row(
            "SELECT id, customer_id FROM portal_users WHERE id = ?",
            [$portalUserId]
        );
        if ($portalUser === null) {
            return [
                'success' => false,
                'status'  => 'portal_user_not_found',
                'error'   => "Portal user {$portalUserId} not found",
            ] + self::RESULT_BASE;
        }
        if ((int) $portalUser['customer_id'] !== (int) $ff['customer_id']) {
            return [
                'success' => false,
                'status'  => 'unauthorized',
                'error'   => "Portal user does not own this invoice (Trap 8 violation)",
            ] + self::RESULT_BASE;
        }

        // Gate 3: invoice status eligibility (only sent/overdue/partially_paid
        // can accept payment per §11.2 step 3 visibility rule).
        $payableStatuses = ['sent', 'overdue', 'partially_paid'];
        if (!in_array($ff['status'], $payableStatuses, true)) {
            return [
                'success' => false,
                'status'  => 'invoice_not_payable',
                'error'   => "Invoice status='{$ff['status']}' not in payable allowlist [" . implode(',', $payableStatuses) . "]",
            ] + self::RESULT_BASE;
        }

        // Gate 4: balance > 0.
        if (bccomp((string) $ff['balance_due'], '0.00', 2) <= 0) {
            return [
                'success' => false,
                'status'  => 'invoice_no_balance',
                'error'   => "Invoice balance_due = {$ff['balance_due']}; nothing to pay",
            ] + self::RESULT_BASE;
        }

        // Gate 5: invoice has QBO mapping (S-QBO-11 invoice push must
        // have shipped this invoice to QBO already, otherwise QBO doesn't
        // know what invoice the hosted page is for).
        $invMap = db_row(
            "SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ?",
            [$ffInvoiceId]
        );
        if ($invMap === null || empty($invMap['qbo_invoice_id'])) {
            return [
                'success' => false,
                'status'  => 'invoice_not_synced',
                'error'   => "FF invoice {$ffInvoiceId} not yet pushed to QBO (run invoice sync first).",
            ] + self::RESULT_BASE;
        }

        // Gate 6: currency match against mapped customer (mirror
        // PaymentPusher D-QBO-14-7 currency-mismatch gate). When
        // multi_currency_enabled='1' AND mapped QBO customer currency
        // differs from FF invoice currency, the hosted page would create
        // a QBO Payment in the wrong currency.
        if ((string) settings_get('quickbooks.multi_currency_enabled', '0') === '1') {
            $customerMap = db_row(
                "SELECT qbo_customer_id FROM acc_qbo_customer_map WHERE ff_customer_id = ?",
                [(int) $ff['customer_id']]
            );
            if ($customerMap !== null && !empty($customerMap['qbo_customer_id'])) {
                try {
                    $client = new QuickBooksClient();
                    $qboCustomerResp = $client->getEntity('customer', (string) $customerMap['qbo_customer_id']);
                    $qboCustomer = $qboCustomerResp['Customer'] ?? null;
                    if ($qboCustomer !== null) {
                        $qboCurrency = strtoupper((string) ($qboCustomer['CurrencyRef']['value'] ?? ''));
                        $ffCurrency  = strtoupper((string) ($ff['currency'] ?? ''));
                        if ($qboCurrency !== '' && $ffCurrency !== '' && $qboCurrency !== $ffCurrency) {
                            return [
                                'success' => false,
                                'status'  => 'currency_mismatch',
                                'error'   => "FF invoice {$ffCurrency} ≠ QBO customer {$qboCurrency}",
                            ] + self::RESULT_BASE;
                        }
                    }
                } catch (\Throwable $e) {
                    // Transient error — log + fall through.
                    error_log("[PaymentInitiator] gate 6 (currency-mismatch) probe failed for ff_invoice={$ffInvoiceId}: " . $e->getMessage());
                }
            }
        }

        // ── Idempotency check per D-QBO-15-5 ────────────────────────────
        // If an existing pending+unexpired row exists for this invoice,
        // return the SAME URL.
        $existing = db_row(
            "SELECT id, qbo_hosted_url, initiation_token, expires_at, status
               FROM acc_qbo_payment_initiations
              WHERE ff_invoice_id = ? AND status = 'pending'
              ORDER BY generated_at DESC
              LIMIT 1",
            [$ffInvoiceId]
        );
        if ($existing !== null) {
            $now = date('Y-m-d H:i:s');
            if ($existing['expires_at'] > $now) {
                // Still valid — return same URL (idempotent).
                return [
                    'success'    => true,
                    'status'     => 'existing_pending',
                    'url'        => (string) $existing['qbo_hosted_url'],
                    'token'      => (string) $existing['initiation_token'],
                    'expires_at' => (string) $existing['expires_at'],
                ] + self::RESULT_BASE;
            }
            // Expired — mark as such before generating new URL.
            db_update(
                'acc_qbo_payment_initiations',
                ['status' => 'expired', 'completed_at' => $now],
                'id = ?',
                [(int) $existing['id']]
            );
        }

        // ── Generate new URL via Intuit Payments API ────────────────────
        $token = bin2hex(random_bytes(32));  // 64-hex-char token

        $ttlMinutes = (int) settings_get('quickbooks.payments.url_ttl_minutes', '30');
        if ($ttlMinutes < 5) {
            $ttlMinutes = 30;  // sanity floor
        }

        $successRelative = (string) settings_get('quickbooks.payments.success_url', 'portal/payments/payment_success');
        $cancelRelative  = (string) settings_get('quickbooks.payments.cancel_url',  'portal/payments/payment_cancel');
        $successUrl = self::buildAbsoluteUrl($successRelative) . '?token=' . $token;
        $cancelUrl  = self::buildAbsoluteUrl($cancelRelative)  . '?token=' . $token;

        $realmId = (string) settings_get('quickbooks.realm_id', '');

        try {
            $client = new QuickBooksClient();
            $intuitResp = $client->generatePaymentsHostedUrl(
                (string) $invMap['qbo_invoice_id'],
                $successUrl,
                $cancelUrl
            );
        } catch (QuickBooksException $e) {
            // Persist failed row for operator visibility per D-UI-COMPLETENESS-1
            // pattern (failures show up in /quickbooks/payments admin via the
            // extended initiation columns).
            self::persistFailedRow($ffInvoiceId, $portalUserId, $invMap, $ff, $token, $realmId, $ttlMinutes, $e->getMessage());
            return [
                'success' => false,
                'status'  => 'qbo_error',
                'error'   => $e->getMessage(),
            ] + self::RESULT_BASE;
        } catch (\Throwable $e) {
            self::persistFailedRow($ffInvoiceId, $portalUserId, $invMap, $ff, $token, $realmId, $ttlMinutes, $e->getMessage());
            return [
                'success' => false,
                'status'  => 'qbo_error',
                'error'   => 'Unexpected: ' . $e->getMessage(),
            ] + self::RESULT_BASE;
        }

        // ── Persist successful initiation row ───────────────────────────
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

        $insertedId = db_insert('acc_qbo_payment_initiations', [
            'ff_invoice_id'     => $ffInvoiceId,
            'ff_portal_user_id' => $portalUserId,
            'qbo_invoice_id'    => (string) $invMap['qbo_invoice_id'],
            'qbo_hosted_url'    => $intuitResp['url'],
            'initiation_token'  => $token,
            'amount'            => (string) $ff['balance_due'],
            'currency'          => (string) $ff['currency'],
            'realm_id'          => $realmId,
            'generated_at'      => date('Y-m-d H:i:s'),
            'expires_at'        => $expiresAt,
            'status'            => 'pending',
        ]);

        return [
            'success'    => true,
            'status'     => 'generated',
            'url'        => $intuitResp['url'],
            'token'      => $token,
            'expires_at' => $expiresAt,
        ] + self::RESULT_BASE;
    }

    /**
     * Webhook handshake helper — called from PaymentWebhookHandler::handle
     * after the QBO Payment entity is pulled, BEFORE the FF payment row
     * is created. Looks up the latest pending initiation row for the QBO
     * invoice; if found, marks it completed + sets qbo_payment_id.
     *
     * Per D-QBO-15-1: match by qbo_invoice_id + latest pending. If no
     * match, returns null (webhook handler continues with no handshake;
     * payment is still created from webhook payload).
     *
     * @param  string $qboInvoiceId   from QBO Payment.Line[0].LinkedTxn[0].TxnId
     * @param  string $qboPaymentId   from QBO Payment.Id
     * @return array|null  the updated initiation row, or null if no match
     */
    public static function matchByQboInvoice(string $qboInvoiceId, string $qboPaymentId): ?array
    {
        $row = db_row(
            "SELECT id, ff_invoice_id, ff_portal_user_id, initiation_token, status
               FROM acc_qbo_payment_initiations
              WHERE qbo_invoice_id = ? AND status = 'pending'
              ORDER BY generated_at DESC
              LIMIT 1",
            [$qboInvoiceId]
        );
        if ($row === null) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        db_update(
            'acc_qbo_payment_initiations',
            [
                'status'         => 'completed',
                'qbo_payment_id' => $qboPaymentId,
                'completed_at'   => $now,
            ],
            'id = ?',
            [(int) $row['id']]
        );

        return $row + ['qbo_payment_id' => $qboPaymentId, 'completed_at' => $now];
    }

    /**
     * Lookup by token — used by portal return URL handlers to show
     * appropriate landing page.
     *
     * @param  string $token  initiation_token from ?token=X query param
     * @return array|null     initiation row or null if not found
     */
    public static function findByToken(string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return db_row(
            "SELECT id, ff_invoice_id, ff_portal_user_id, qbo_invoice_id,
                    initiation_token, amount, currency, generated_at,
                    expires_at, status, qbo_payment_id, completed_at
               FROM acc_qbo_payment_initiations
              WHERE initiation_token = ?",
            [$token]
        );
    }

    /**
     * Mark an initiation row as cancelled (called by portal cancel URL).
     * Idempotent — already-cancelled rows are no-ops.
     */
    public static function markCancelled(string $token): bool
    {
        $row = self::findByToken($token);
        if ($row === null) {
            return false;
        }
        if ($row['status'] !== 'pending') {
            return true;  // idempotent
        }
        db_update(
            'acc_qbo_payment_initiations',
            ['status' => 'cancelled', 'completed_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [(int) $row['id']]
        );
        return true;
    }

    // ────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ────────────────────────────────────────────────────────────────────

    /**
     * Persist a 'failed' initiation row when QBO API rejected the URL
     * generation. Operator-visible in /quickbooks/payments admin per
     * D-UI-COMPLETENESS-1 (initiation column extension).
     */
    private static function persistFailedRow(int $ffInvoiceId, int $portalUserId, array $invMap, array $ff, string $token, string $realmId, int $ttlMinutes, string $error): void
    {
        try {
            db_insert('acc_qbo_payment_initiations', [
                'ff_invoice_id'     => $ffInvoiceId,
                'ff_portal_user_id' => $portalUserId,
                'qbo_invoice_id'    => (string) $invMap['qbo_invoice_id'],
                'qbo_hosted_url'    => '',  // never generated
                'initiation_token'  => $token,
                'amount'            => (string) $ff['balance_due'],
                'currency'          => (string) $ff['currency'],
                'realm_id'          => $realmId,
                'generated_at'      => date('Y-m-d H:i:s'),
                'expires_at'        => date('Y-m-d H:i:s', time() + ($ttlMinutes * 60)),
                'status'            => 'failed',
                'error_message'     => substr($error, 0, 65535),
                'completed_at'      => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log("[PaymentInitiator] persistFailedRow failed for ff_invoice={$ffInvoiceId}: " . $e->getMessage());
        }
    }

    /**
     * Build an absolute URL from a relative path (resolved against the
     * FF base URL). QBO Payments hosted-page success/cancel URLs MUST
     * be absolute (https://...) per Intuit API contract.
     */
    private static function buildAbsoluteUrl(string $relative): string
    {
        $baseUrl = function_exists('env') ? (string) env('APP_URL', '') : '';
        if ($baseUrl === '') {
            // Reasonable default for sandbox / dev.
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base   = function_exists('base_url') ? base_url('') : '/fleetforge/';
            $baseUrl = $scheme . '://' . $host . $base;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($relative, '/');
    }
}
