<?php
declare(strict_types=1);

/**
 * lib/QboPushers/InvoiceLinker.php
 *
 * Cutover linker (S-QBO-GOLIVE-AUDIT). FleetForge goes live after a year of
 * invoicing done directly in QuickBooks, in a company file that also holds
 * other businesses' books. Many FF invoices (and a few credit notes, and the
 * bills operators enter for per-unit costing) describe documents QuickBooks
 * ALREADY has — pushing them would create duplicates.
 *
 * Instead of pushing, the linker LINKS an FF document to the existing
 * QuickBooks one:
 *   1. candidates() — for FF documents with no QuickBooks link, fetch the
 *      customer's (vendor's, for a bill) QuickBooks documents around the same date and propose a
 *      match (exact doc number + amount, or a unique amount + date match);
 *   2. link()       — re-reads the QuickBooks document, checks customer /
 *      currency / not-already-linked, and writes the map row a successful
 *      push would have written (origin='cutover_link'), without pushing;
 *   3. importPayments() — mirrors the payments QuickBooks recorded against a
 *      linked invoice into FF (payment + allocations + counters + GL, via the
 *      same PaymentWebhookHandler path the live webhook uses), so FF shows
 *      the invoice paid and the FF accounting module moves with it;
 *      importBillPayments() does the same for a linked bill's QuickBooks
 *      bill payments via BillPaymentWebhookHandler (S-QBO-BILLPAY-MIRROR).
 *
 * Safety rules (the company file is shared — FleetForge must not disturb it):
 *   - linking never writes to QuickBooks;
 *   - a linked document is never updated or voided in QuickBooks by FF
 *     (InvoicePusher / CreditMemoPusher skip it and raise a drift event);
 *   - cutoverBlockReason() stops a document dated / sent BEFORE go-live from
 *     being pushed as NEW unless someone chooses "Push as new" for it.
 *
 * @session  S-QBO-GOLIVE-AUDIT
 * @spec     FLEETFORGE_QUICKBOOKS_SPEC.md §16 (go-live; cutover linking)
 */

namespace FleetForge\QboPushers;

use FleetForge\QuickBooksClient;

class InvoiceLinker
{
    public const DEFAULT_WINDOW_DAYS = 7;
    public const MAX_WINDOW_DAYS     = 45;
    public const MAX_DOCS            = 500;

    /**
     * Largest total difference still treated as "the same amount". FF rounds
     * tax per line, QuickBooks once per invoice — on the production copy one
     * invoice in five differs by exactly $0.01 from the accountant's copy.
     */
    public const AMOUNT_TOLERANCE = '0.05';

    /** FF invoice statuses that mean "issued to the customer". */
    public const ISSUED_INVOICE_STATUSES = ['sent', 'partially_paid', 'paid', 'overdue', 'written_off'];

    /** FF invoice statuses a payment can be allocated to (recordFromQbo gate). */
    public const PAYABLE_INVOICE_STATUSES = ['sent', 'partially_paid', 'overdue'];

    /** link_method values. 'push_new' = operator chose to push a pre-go-live doc as new. */
    public const METHODS = ['exact', 'doc_number', 'amount_date', 'amount_unit', 'manual'];

    /**
     * Per-kind table / column names. Invoices, credit notes and bills share
     * the whole flow; only the names differ. 'number' is FF's own number
     * (shown); 'match_number' is the one the accountant typed into
     * QuickBooks' DocNumber — for a bill that is the VENDOR's bill number.
     */
    private const KINDS = [
        'invoice' => [
            'table'        => 'invoices',
            'number'       => 'invoice_number',
            'match_number' => 'invoice_number',
            'date'         => 'invoice_date',
            'total'        => 'total_amount',
            'balance'      => 'balance_due',
            'soft_delete'  => true,
            'issued'       => self::ISSUED_INVOICE_STATUSES,
            'party'        => 'customer',
            'map'          => 'acc_qbo_invoice_map',
            'map_fk'       => 'ff_invoice_id',
            'map_qbo'      => 'qbo_invoice_id',
            'snapshot'     => 'ff_invoice_snapshot_total',
            'qbo'          => 'Invoice',
            'endpoint'     => 'invoice',
            'label'        => 'invoice',
            'queue'        => 'invoice',
            'enqueuer'     => InvoiceEnqueuer::class,
        ],
        'credit_memo' => [
            'table'        => 'credit_notes',
            'number'       => 'credit_note_number',
            'match_number' => 'credit_note_number',
            'date'         => null,               // header-only: local day of created_at
            'total'        => 'amount',
            'balance'      => 'amount_remaining',
            'soft_delete'  => true,
            'issued'       => ['active', 'partially_used', 'fully_used', 'expired'],
            'party'        => 'customer',
            'map'          => 'acc_qbo_credit_memo_map',
            'map_fk'       => 'ff_credit_note_id',
            'map_qbo'      => 'qbo_credit_memo_id',
            'snapshot'     => 'ff_credit_note_snapshot_total',
            'qbo'          => 'CreditMemo',
            'endpoint'     => 'creditmemo',
            'label'        => 'credit note',
            'queue'        => 'credit_memo',
            'enqueuer'     => CreditMemoEnqueuer::class,
        ],
        'bill' => [
            'table'        => 'acc_bills',
            'number'       => 'bill_number',
            'match_number' => 'vendor_bill_number',
            'date'         => 'bill_date',
            'total'        => 'total_amount',
            'balance'      => 'balance_due',
            'soft_delete'  => false,
            'issued'       => ['approved', 'scheduled', 'partially_paid', 'paid'],
            'party'        => 'vendor',
            'map'          => 'acc_qbo_bill_map',
            'map_fk'       => 'ff_bill_id',
            'map_qbo'      => 'qbo_bill_id',
            'snapshot'     => 'ff_bill_snapshot_total',
            'qbo'          => 'Bill',
            'endpoint'     => 'bill',
            'label'        => 'bill',
            'queue'        => 'bill',
            'enqueuer'     => BillEnqueuer::class,
        ],
    ];

    /** Counterparty per kind: its map, FK, QBO ref field, name. */
    private const PARTIES = [
        'customer' => [
            'map' => 'acc_qbo_customer_map', 'ff' => 'ff_customer_id', 'qbo' => 'qbo_customer_id',
            'fk' => 'customer_id', 'ref' => 'CustomerRef', 'table' => 'customers',
            'name' => 'COALESCE(p.company_name, p.contact_name)', 'label' => 'customer', 'page' => 'Customers',
        ],
        'vendor' => [
            'map' => 'acc_qbo_vendor_map', 'ff' => 'ff_vendor_id', 'qbo' => 'qbo_vendor_id',
            'fk' => 'vendor_id', 'ref' => 'VendorRef', 'table' => 'vendors',
            'name' => 'p.name', 'label' => 'vendor', 'page' => 'Vendors',
        ],
    ];

    /** @return array<string,mixed> */
    private static function cfg(string $kind): array
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException("Unknown link kind '{$kind}' (invoice | credit_memo | bill).");
        }
        return self::KINDS[$kind];
    }

    /** @return array<string,string> */
    private static function party(array $cfg): array
    {
        return self::PARTIES[$cfg['party']];
    }

    // ────────────────────────────────────────────────────────────────
    //  Candidates
    // ────────────────────────────────────────────────────────────────

    /**
     * FF documents with no QuickBooks link yet, each with a proposed
     * QuickBooks match (or the nearby QuickBooks documents to choose from).
     *
     * @param array{from?:string,to?:string,include_drafts?:bool,window_days?:int,party_id?:int,limit?:int} $opts
     * @return array{rows: list<array<string,mixed>>, summary: array<string,int>, truncated: bool}
     */
    public static function candidates(string $kind, array $opts = [], ?QuickBooksClient $client = null): array
    {
        $cfg    = self::cfg($kind);
        $window = max(0, min(self::MAX_WINDOW_DAYS, (int) ($opts['window_days'] ?? self::DEFAULT_WINDOW_DAYS)));
        $limit  = max(1, min(self::MAX_DOCS, (int) ($opts['limit'] ?? 200)));

        $docs      = self::unlinkedFfDocs($kind, $opts, $limit + 1);
        $truncated = count($docs) > $limit;
        $docs      = array_slice($docs, 0, $limit);

        $party = self::party($cfg);
        $rows = [];
        $byParty = [];
        foreach ($docs as $d) {
            if (empty($d['qbo_party_id'])) {
                $rows[(int) $d['id']] = self::row($d, 'customer_unmapped', null, [],
                    ucfirst($party['label']) . " is not linked to a QuickBooks {$party['label']} yet — map it on QuickBooks → {$party['page']} first.");
                continue;
            }
            $byParty[(string) $d['qbo_party_id']][] = $d;
        }

        if ($byParty !== []) {
            $client ??= new QuickBooksClient();
            $taken = self::linkedQboIds($kind);
            foreach ($byParty as $qboPartyId => $group) {
                $dates = array_map(static fn($d) => (string) $d['doc_date'], $group);
                $from  = ff_local_date_add(min($dates), -$window);
                $to    = ff_local_date_add(max($dates), $window);
                try {
                    $qboDocs = self::fetchQboDocs($client, $cfg, (string) $qboPartyId, $from, $to);
                } catch (\Throwable $e) {
                    foreach ($group as $d) {
                        $rows[(int) $d['id']] = self::row($d, 'qbo_error', null, [], 'Could not read QuickBooks: ' . $e->getMessage());
                    }
                    continue;
                }
                $qboDocs = array_values(array_filter($qboDocs, static fn($q) => !isset($taken[(string) ($q['Id'] ?? '')])));
                foreach (self::match($group, $qboDocs, $window) as $ffId => $row) {
                    $rows[$ffId] = $row;
                }
            }
        }

        $rows = array_values($rows);
        usort($rows, static fn($a, $b) => [$a['ff']['doc_date'], $a['ff']['number']] <=> [$b['ff']['doc_date'], $b['ff']['number']]);
        $summary = [];
        foreach ($rows as $r) {
            $summary[$r['confidence']] = ($summary[$r['confidence']] ?? 0) + 1;
        }
        return ['rows' => $rows, 'summary' => $summary, 'truncated' => $truncated];
    }

    /**
     * Pure matcher (no I/O — the smoke drives it directly). Pairs each FF
     * document with at most one QuickBooks document, one-to-one:
     *   exact       doc number AND amount equal (any date);
     *   doc_number  doc number equal, amount differs → review;
     *   amount_date amount equal within ±window days, and the pairing is
     *               unique both ways → strong;
     *   review      several same-amount documents on nearby dates (e.g. one
     *               customer, several units at one rate) — paired in date /
     *               number order as a suggestion, a person confirms;
     *   none        no proposal; alternatives list the nearest documents.
     *
     * @param list<array<string,mixed>> $ffDocs  rows from unlinkedFfDocs()
     * @param list<array<string,mixed>> $qboDocs QuickBooks entities (Id, DocNumber, TxnDate, TotalAmt, Balance, CurrencyRef…)
     * @return array<int, array<string,mixed>> keyed by FF id
     */
    public static function match(array $ffDocs, array $qboDocs, int $window): array
    {
        $qboById = [];
        foreach ($qboDocs as $q) {
            $qboById[(string) $q['Id']] = $q;
        }

        // Candidate facts per FF doc × QBO doc.
        $facts = [];
        foreach ($ffDocs as $d) {
            $ffId = (int) $d['id'];
            foreach ($qboById as $qid => $q) {
                if (!self::currencyCompatible($d, $q)) {
                    continue;
                }
                $days     = self::dayDiff((string) $d['doc_date'], (string) ($q['TxnDate'] ?? ''));
                $ffNum    = self::normNumber((string) ($d['match_number'] ?? $d['number']));
                $docMatch = $ffNum !== '' && $ffNum === self::normNumber((string) ($q['DocNumber'] ?? ''));
                $amtMatch = self::sameAmount(self::money($d['total']), self::money($q['TotalAmt'] ?? '0'));
                if (!$docMatch && $days > $window) {
                    continue;
                }
                $facts[$ffId][$qid] = ['doc' => $docMatch, 'amt' => $amtMatch, 'days' => $days,
                                       'unit' => self::mentionsUnit($q, (string) ($d['unit'] ?? ''))];
            }
        }

        $assigned = [];   // qbo id → ff id
        $result   = [];   // ff id → [qbo id, confidence]

        // Pass 1 + 2: doc-number matches (exact, then doc_number). A QBO
        // document claimed by two FF documents is left for review.
        foreach (['exact' => true, 'doc_number' => false] as $conf => $needAmt) {
            $claims = [];
            foreach ($facts as $ffId => $cands) {
                if (isset($result[$ffId])) {
                    continue;
                }
                foreach ($cands as $qid => $f) {
                    if ($f['doc'] && $f['amt'] === $needAmt && !isset($assigned[$qid])) {
                        $claims[$qid][] = $ffId;
                    }
                }
            }
            foreach ($claims as $qid => $ffIds) {
                if (count($ffIds) === 1 && !isset($result[$ffIds[0]])) {
                    $result[$ffIds[0]] = [(string) $qid, $conf];
                    $assigned[$qid] = $ffIds[0];
                }
            }
        }

        // Pass 3: amount + date. A pairing that is unique BOTH ways (this FF
        // document has one same-amount QBO document in the window, and that
        // QBO document has no other same-amount FF claimant) is
        // 'amount_date' — the normal case for a monthly rental, whose
        // same-amount invoices sit a month apart. What stays ambiguous (one
        // customer, several units at one rate, same day) is paired nearest
        // date first, in (date, number) order, as 'review' for a person.
        $ffToQ = [];
        $qToFf = [];
        foreach ($facts as $ffId => $cands) {
            if (isset($result[$ffId])) {
                continue;
            }
            foreach ($cands as $qid => $f) {
                if ($f['amt'] && !isset($assigned[$qid])) {
                    $ffToQ[$ffId][] = (string) $qid;
                    $qToFf[(string) $qid][] = $ffId;
                }
            }
        }
        // Same amount, same day, several units (one customer renting a fleet
        // at one rate): the accountant writes the unit on the invoice, so a
        // QBO copy that mentions exactly this invoice's unit decides it.
        $unitClaims = [];
        foreach ($ffToQ as $ffId => $qids) {
            foreach ($qids as $qid) {
                if (!empty($facts[$ffId][$qid]['unit'])) {
                    $unitClaims[$qid][] = $ffId;
                }
            }
        }
        foreach ($ffToQ as $ffId => $qids) {
            $hits = array_values(array_filter($qids, static fn($q) => !empty($facts[$ffId][$q]['unit'])));
            if (count($hits) === 1 && count($unitClaims[$hits[0]] ?? []) === 1 && !isset($assigned[$hits[0]])) {
                $result[$ffId] = [$hits[0], 'amount_unit'];
                $assigned[$hits[0]] = $ffId;
            }
        }
        foreach ($ffToQ as $ffId => $qids) {
            if (isset($result[$ffId])) {
                continue;
            }
            $open = array_values(array_filter($qids, static fn($q) => !isset($assigned[$q])));
            $rivals = count($open) === 1 ? array_filter($qToFf[$open[0]], static fn($f) => !isset($result[$f])) : [];
            if (count($open) === 1 && count($rivals) === 1) {
                $result[$ffId] = [$open[0], 'amount_date'];
                $assigned[$open[0]] = $ffId;
            }
        }
        $left = array_keys(array_diff_key($ffToQ, $result));
        usort($left, static function ($a, $b) use ($ffDocs) {
            $da = self::ffDoc($ffDocs, $a);
            $db = self::ffDoc($ffDocs, $b);
            return [(string) $da['doc_date'], (string) $da['number']] <=> [(string) $db['doc_date'], (string) $db['number']];
        });
        foreach ($left as $ffId) {
            $qids = array_values(array_filter($ffToQ[$ffId], static fn($q) => !isset($assigned[$q])));
            if ($qids === []) {
                continue;
            }
            usort($qids, static fn($a, $b) => [$facts[$ffId][$a]['days'], (string) ($qboById[$a]['TxnDate'] ?? ''), (string) ($qboById[$a]['DocNumber'] ?? '')]
                <=> [$facts[$ffId][$b]['days'], (string) ($qboById[$b]['TxnDate'] ?? ''), (string) ($qboById[$b]['DocNumber'] ?? '')]);
            $result[$ffId] = [$qids[0], 'review'];
            $assigned[$qids[0]] = $ffId;
        }


        // Rows: proposal + up to 5 alternatives (closest amount, then date).
        $rows = [];
        foreach ($ffDocs as $d) {
            $ffId  = (int) $d['id'];
            $cands = $facts[$ffId] ?? [];
            $alts  = [];
            foreach ($cands as $qid => $f) {
                $alts[] = [
                    'qid'   => (string) $qid,
                    'diff'  => ltrim(bcsub(self::money($qboById[$qid]['TotalAmt'] ?? '0'), self::money($d['total']), 2), '-'),
                    'days'  => $f['days'],
                ];
            }
            usort($alts, static function ($a, $b) {
                $c = bccomp($a['diff'], $b['diff'], 2);
                return $c !== 0 ? $c : $a['days'] <=> $b['days'];
            });
            $proposal = $result[$ffId] ?? null;
            $altRows  = [];
            foreach (array_slice($alts, 0, 5) as $a) {
                $altRows[] = self::qboSummary($qboById[$a['qid']], $d);
            }
            if ($proposal === null) {
                $rows[$ffId] = self::row($d, 'none', null, $altRows,
                    $altRows === [] ? 'No QuickBooks ' . self::cfgLabel($d) . ' for this customer within the date window.' : 'No confident match — pick one below if it is the same document.');
                continue;
            }
            $rows[$ffId] = self::row($d, $proposal[1], self::qboSummary($qboById[$proposal[0]], $d), $altRows, null);
        }
        return $rows;
    }

    // ────────────────────────────────────────────────────────────────
    //  Link / unlink / push as new
    // ────────────────────────────────────────────────────────────────

    /**
     * Link one FF document to an existing QuickBooks document. Re-reads the
     * QuickBooks document (never trusts the browser), validates, writes the
     * map row (no QuickBooks write) and, for an invoice, mirrors its
     * QuickBooks payments into FF.
     *
     * @param array{id?:int,name?:string}|null $user
     * @return array{ok:bool, code?:string, error?:string, qbo?:array, payments?:array}
     */
    public static function link(string $kind, int $ffId, string $qboId, string $method, ?array $user, bool $acceptDifference = false, ?QuickBooksClient $client = null): array
    {
        $cfg = self::cfg($kind);
        if (!in_array($method, self::METHODS, true)) {
            $method = 'manual';
        }
        if (!ctype_digit($qboId)) {
            return ['ok' => false, 'code' => 'bad_qbo_id', 'error' => 'QuickBooks id must be numeric.'];
        }
        $client ??= new QuickBooksClient();
        try {
            $resp = $client->getEntity($cfg['endpoint'], $qboId, ['entity_type' => $cfg['queue'], 'entity_id' => $ffId, 'operation' => 'cutover_link']);
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'qbo_error', 'error' => 'Could not read the QuickBooks ' . $cfg['label'] . ': ' . $e->getMessage()];
        }
        $qbo = $resp[$cfg['qbo']] ?? null;
        if (!is_array($qbo) || (string) ($qbo['Id'] ?? '') !== $qboId) {
            return ['ok' => false, 'code' => 'qbo_not_found', 'error' => "QuickBooks {$cfg['label']} {$qboId} not found."];
        }

        $res = self::linkWithQboDoc($kind, $ffId, $qbo, $method, $user, $acceptDifference);
        if (!$res['ok'] || $kind === 'credit_memo') {
            return $res;
        }
        // S-QBO-BILLPAY-MIRROR: a linked bill brings in the payments the
        // accountant made in QuickBooks, as a linked invoice does.
        $res['payments'] = $kind === 'bill' ? self::importBillPayments($ffId, $qbo) : self::importPayments($ffId, $qbo);
        return $res;
    }

    /**
     * The validate-and-write half of link(), given an already-fetched
     * QuickBooks document (the smoke drives this offline).
     */
    public static function linkWithQboDoc(string $kind, int $ffId, array $qbo, string $method, ?array $user, bool $acceptDifference = false): array
    {
        $cfg   = self::cfg($kind);
        $qboId = (string) ($qbo['Id'] ?? '');
        return db_transaction(function () use ($kind, $cfg, $ffId, $qbo, $qboId, $method, $user, $acceptDifference): array {
            $party = self::party($cfg);
            $doc = db_row("SELECT * FROM {$cfg['table']} WHERE id = ? FOR UPDATE", [$ffId]);
            if (!$doc || ($doc['deleted_at'] ?? null) !== null) {
                return ['ok' => false, 'code' => 'ff_not_found', 'error' => "FleetForge {$cfg['label']} {$ffId} not found."];
            }
            if ($doc['status'] === 'void') {
                return ['ok' => false, 'code' => 'ff_void', 'error' => "FleetForge {$cfg['label']} {$doc[$cfg['number']]} is void — there is nothing to link."];
            }
            $map = db_row("SELECT id, {$cfg['map_qbo']} AS qbo_id FROM {$cfg['map']} WHERE {$cfg['map_fk']} = ? FOR UPDATE", [$ffId]);
            if ($map && !empty($map['qbo_id'])) {
                return ['ok' => false, 'code' => 'already_linked', 'error' => "{$doc[$cfg['number']]} is already linked to QuickBooks {$cfg['label']} {$map['qbo_id']}."];
            }
            $other = db_row("SELECT {$cfg['map_fk']} AS ff_id FROM {$cfg['map']} WHERE {$cfg['map_qbo']} = ?", [$qboId]);
            if ($other) {
                return ['ok' => false, 'code' => 'qbo_taken', 'error' => "QuickBooks {$cfg['label']} {$qboId} is already linked to FleetForge {$cfg['label']} id {$other['ff_id']}."];
            }
            $partyMap = db_row(
                "SELECT {$party['qbo']} AS qbo_id FROM {$party['map']} WHERE {$party['ff']} = ? AND mapping_status = 'mapped'",
                [(int) $doc[$party['fk']]]
            );
            if (!$partyMap || empty($partyMap['qbo_id'])) {
                return ['ok' => false, 'code' => 'customer_unmapped', 'error' => "The {$party['label']} is not linked to a QuickBooks {$party['label']} yet."];
            }
            if ((string) ($qbo[$party['ref']]['value'] ?? '') !== (string) $partyMap['qbo_id']) {
                return ['ok' => false, 'code' => 'customer_mismatch',
                        'error' => "That QuickBooks {$cfg['label']} belongs to a different {$party['label']} (" . ($qbo[$party['ref']]['name'] ?? '?') . ').'];
            }
            $ffDoc = self::ffDocShape($kind, $doc);
            if (!self::currencyCompatible($ffDoc, $qbo)) {
                return ['ok' => false, 'code' => 'currency_mismatch',
                        'error' => "Currency differs: FleetForge {$ffDoc['currency']}, QuickBooks " . ($qbo['CurrencyRef']['value'] ?? '?') . '.'];
            }
            $ffTotal  = self::money($doc[$cfg['total']]);
            $qboTotal = self::money($qbo['TotalAmt'] ?? '0');
            if (!self::sameAmount($ffTotal, $qboTotal) && !$acceptDifference) {
                return ['ok' => false, 'code' => 'amount_differs',
                        'error' => "Amounts differ: FleetForge {$ffTotal}, QuickBooks {$qboTotal}. Confirm to link anyway.",
                        'ff_total' => $ffTotal, 'qbo_total' => $qboTotal];
            }

            $now = ff_now_utc();
            $fields = [
                $cfg['map_qbo']     => $qboId,
                'qbo_sync_token'    => (string) ($qbo['SyncToken'] ?? '0'),
                'qbo_doc_number'    => substr((string) ($qbo['DocNumber'] ?? ''), 0, 100) ?: null,
                'qbo_total_amt'     => $qboTotal,
                'qbo_balance'       => isset($qbo['Balance']) ? self::money($qbo['Balance']) : null,
                'qbo_currency'      => isset($qbo['CurrencyRef']['value']) ? strtoupper((string) $qbo['CurrencyRef']['value']) : null,
                'qbo_exchange_rate' => isset($qbo['ExchangeRate']) ? (string) $qbo['ExchangeRate'] : null,
                $cfg['snapshot']    => $ffTotal,
                'push_status'       => 'pushed',
                'push_error'        => null,
                'origin'            => 'cutover_link',
                'link_method'       => $method,
                'linked_at'         => $now,
                'last_synced_at'    => $now,
            ];
            if ($kind === 'invoice') {
                $fields['ff_engine_version'] = (string) ($doc['engine_version'] ?? 'unknown');
            }
            if ($kind === 'bill') {
                // Bill map carries no balance/status columns FF reads back
                // and snapshots the vendor id instead.
                $fields['qbo_vendor_id'] = (string) $partyMap['qbo_id'];
            }
            if ($map) {
                db_update($cfg['map'], $fields, 'id = ?', [(int) $map['id']]);
            } else {
                db_insert($cfg['map'], [$cfg['map_fk'] => $ffId] + $fields);
            }

            // Anything still waiting to push this document would now either
            // no-op (create) or be refused (update/void) — clear it so the
            // queue page doesn't show phantom work.
            db_execute(
                "UPDATE acc_qbo_sync_queue
                    SET status = 'skipped', error_code = 'cutover_linked',
                        error_message = 'Linked to an existing QuickBooks document at go-live; not pushed.',
                        completed_at = ?
                  WHERE entity_type = ? AND entity_id = ? AND status = 'queued'",
                [$now, $cfg['queue'], $ffId]
            );

            db_insert('audit_log', [
                'user_id'      => $user['id'] ?? null,
                'user_name'    => $user['name'] ?? 'system',
                'action'       => 'update',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_cutover_link',
                'entity_id'    => $ffId,
                'entity_label' => (string) $doc[$cfg['number']],
                'notes'        => "Linked FleetForge {$cfg['label']} {$doc[$cfg['number']]} ({$ffTotal}) to existing QuickBooks {$cfg['label']} "
                    . ($qbo['DocNumber'] ?? '') . " id={$qboId} ({$qboTotal}) — method={$method}"
                    . (bccomp($ffTotal, $qboTotal, 2) === 0 ? ''
                        : (self::sameAmount($ffTotal, $qboTotal) ? '; rounding difference ' . bcsub($qboTotal, $ffTotal, 2) : '; amount difference accepted')),
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);

            return ['ok' => true, 'qbo' => ['id' => $qboId, 'doc_number' => (string) ($qbo['DocNumber'] ?? ''), 'total' => $qboTotal,
                                            'balance' => isset($qbo['Balance']) ? self::money($qbo['Balance']) : null]];
        });
    }

    /**
     * Undo a cutover link (only a link — never a real push). Refused while
     * QuickBooks payments are mirrored onto the invoice: void those first,
     * or FF would keep money it can no longer trace to QuickBooks.
     */
    public static function unlink(string $kind, int $ffId, ?array $user): array
    {
        $cfg = self::cfg($kind);
        return db_transaction(function () use ($kind, $cfg, $ffId, $user): array {
            $map = db_row("SELECT * FROM {$cfg['map']} WHERE {$cfg['map_fk']} = ? FOR UPDATE", [$ffId]);
            if (!$map || ($map['origin'] ?? 'ff_push') !== 'cutover_link') {
                return ['ok' => false, 'code' => 'not_linked', 'error' => 'Only a go-live link can be undone here (this document was not linked, or FleetForge pushed it).'];
            }
            if ($kind === 'invoice') {
                $mirrored = (int) (db_row(
                    "SELECT COUNT(*) AS n FROM payment_allocations pa JOIN payments p ON p.id = pa.payment_id
                      WHERE pa.invoice_id = ? AND p.deleted_at IS NULL AND p.status <> 'void'
                        AND p.origin IN ('qbo_payments_webhook','qbo_other')",
                    [$ffId]
                )['n'] ?? 0);
                if ($mirrored > 0) {
                    return ['ok' => false, 'code' => 'has_mirrored_payments',
                            'error' => "{$mirrored} QuickBooks payment(s) are mirrored onto this invoice — void them in FleetForge first."];
                }
            }
            if ($kind === 'bill') {
                // S-QBO-BILLPAY-MIRROR: same rule for bills paid in QuickBooks.
                $mirrored = (int) (db_row(
                    "SELECT COUNT(*) AS n FROM acc_ap_payment_allocations al JOIN acc_ap_payments p ON p.id = al.ap_payment_id
                      WHERE al.bill_id = ? AND p.status <> 'void' AND p.origin IN ('qbo_payments_webhook','qbo_other')",
                    [$ffId]
                )['n'] ?? 0);
                if ($mirrored > 0) {
                    return ['ok' => false, 'code' => 'has_mirrored_payments',
                            'error' => "{$mirrored} QuickBooks bill payment(s) are mirrored onto this bill — void them in FleetForge first."];
                }
            }
            db_execute("DELETE FROM {$cfg['map']} WHERE id = ?", [(int) $map['id']]);
            db_insert('audit_log', [
                'user_id'      => $user['id'] ?? null,
                'user_name'    => $user['name'] ?? 'system',
                'action'       => 'delete',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_cutover_link',
                'entity_id'    => $ffId,
                'notes'        => "Removed go-live link of FleetForge {$cfg['label']} id={$ffId} to QuickBooks id={$map[$cfg['map_qbo']]}",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            return ['ok' => true];
        });
    }

    /**
     * Operator decision for a pre-go-live document that QuickBooks really
     * does NOT have: allow it to be pushed as a new QuickBooks document
     * (clears the cutover guard for this one document) and queue it.
     */
    public static function pushAsNew(string $kind, int $ffId, ?array $user): array
    {
        $cfg = self::cfg($kind);
        $res = db_transaction(function () use ($kind, $cfg, $ffId, $user): array {
            $doc = db_row("SELECT *, {$cfg['number']} AS num FROM {$cfg['table']} WHERE id = ?", [$ffId]);
            if (!$doc || ($doc['deleted_at'] ?? null) !== null || $doc['status'] === 'void') {
                return ['ok' => false, 'code' => 'ff_not_found', 'error' => "FleetForge {$cfg['label']} {$ffId} not found or void."];
            }
            $map = db_row("SELECT id, {$cfg['map_qbo']} AS qbo_id FROM {$cfg['map']} WHERE {$cfg['map_fk']} = ? FOR UPDATE", [$ffId]);
            if ($map && !empty($map['qbo_id'])) {
                return ['ok' => false, 'code' => 'already_linked', 'error' => "{$doc['num']} is already in QuickBooks (id {$map['qbo_id']})."];
            }
            if ($map) {
                db_update($cfg['map'], ['link_method' => 'push_new', 'push_error' => null], 'id = ?', [(int) $map['id']]);
            } else {
                $row = [$cfg['map_fk'] => $ffId, 'push_status' => 'pending', 'link_method' => 'push_new'];
                db_insert($cfg['map'], $row);
            }
            db_insert('audit_log', [
                'user_id'      => $user['id'] ?? null,
                'user_name'    => $user['name'] ?? 'system',
                'action'       => 'update',
                'module'       => 'quickbooks',
                'entity_type'  => 'qbo_cutover_link',
                'entity_id'    => $ffId,
                'entity_label' => (string) $doc['num'],
                'notes'        => "Pre-go-live {$cfg['label']} {$doc['num']} released to push as a NEW QuickBooks {$cfg['label']} (operator confirmed QuickBooks does not have it)",
                'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            return ['ok' => true];
        });
        if (!$res['ok']) {
            return $res;
        }
        $res['enqueued'] = $cfg['enqueuer']::enqueue($ffId, 'create');
        return $res;
    }

    // ────────────────────────────────────────────────────────────────
    //  Payments
    // ────────────────────────────────────────────────────────────────

    /**
     * Mirror the payments QuickBooks holds against a linked invoice into FF.
     * Each QuickBooks Payment goes through PaymentWebhookHandler (Update
     * semantics: new → create; already mirrored → re-plan, so a payment that
     * also covers a second, later-linked invoice gets re-allocated).
     *
     * @return array{status:string, detail?:string, results?:list<array>, ff_balance?:string, qbo_balance?:?string}
     */
    public static function importPayments(int $ffInvoiceId, ?array $qboInvoice = null, ?QuickBooksClient $client = null): array
    {
        $map = db_row("SELECT qbo_invoice_id FROM acc_qbo_invoice_map WHERE ff_invoice_id = ? AND qbo_invoice_id IS NOT NULL", [$ffInvoiceId]);
        if (!$map) {
            return ['status' => 'not_linked', 'detail' => 'Invoice is not linked to QuickBooks.'];
        }
        $inv = db_row("SELECT status, balance_due FROM invoices WHERE id = ?", [$ffInvoiceId]);
        if ($qboInvoice === null) {
            try {
                $client ??= new QuickBooksClient();
                $qboInvoice = $client->getEntity('invoice', (string) $map['qbo_invoice_id'], ['entity_type' => 'invoice', 'entity_id' => $ffInvoiceId, 'operation' => 'payment_catchup'])['Invoice'] ?? null;
            } catch (\Throwable $e) {
                return ['status' => 'qbo_error', 'detail' => $e->getMessage()];
            }
            if (!is_array($qboInvoice)) {
                return ['status' => 'qbo_error', 'detail' => 'QuickBooks returned no Invoice.'];
            }
        }
        $paymentIds = [];
        foreach ($qboInvoice['LinkedTxn'] ?? [] as $lt) {
            if (($lt['TxnType'] ?? '') === 'Payment' && !empty($lt['TxnId'])) {
                $paymentIds[(string) $lt['TxnId']] = true;
            }
        }
        if ($paymentIds === []) {
            return ['status' => 'none', 'detail' => 'QuickBooks has no payments on this invoice.',
                    'ff_balance' => (string) ($inv['balance_due'] ?? ''), 'qbo_balance' => isset($qboInvoice['Balance']) ? self::money($qboInvoice['Balance']) : null];
        }
        if (!$inv || !in_array($inv['status'], array_merge(self::PAYABLE_INVOICE_STATUSES, ['paid']), true)) {
            return ['status' => 'not_payable',
                    'detail' => 'QuickBooks has ' . count($paymentIds) . " payment(s) on this invoice, but it is '" . ($inv['status'] ?? '?')
                        . "' in FleetForge — send it (or void it) in FleetForge, then import payments again."];
        }
        $realm   = (string) settings_get('quickbooks.realm_id', '');
        $results = [];
        foreach (array_keys($paymentIds) as $pid) {
            $pid = (string) $pid;
            $r = PaymentWebhookHandler::handle($pid, 'Update', $realm, 'cutover-import', 'qbo_other');
            $results[] = ['qbo_payment_id' => $pid] + $r;
        }
        // Cents left only because FF rounds tax per line and QuickBooks per
        // invoice: settle them when QuickBooks shows the invoice paid.
        $rounding = RoundingSettler::settleIfQboPaid($ffInvoiceId, $qboInvoice);
        $after = db_row("SELECT status, balance_due FROM invoices WHERE id = ?", [$ffInvoiceId]);
        return [
            'status'      => 'imported',
            'rounding'    => $rounding['settled'] ? $rounding : null,
            'results'     => $results,
            'ff_status'   => (string) ($after['status'] ?? ''),
            'ff_balance'  => (string) ($after['balance_due'] ?? ''),
            'qbo_balance' => isset($qboInvoice['Balance']) ? self::money($qboInvoice['Balance']) : null,
        ];
    }

    /**
     * Catch-up sweep: every linked/pushed FF invoice that is still open in
     * FF but paid down in QuickBooks gets its QuickBooks payments mirrored
     * (covers go-live history and any webhook that never arrived).
     *
     * @return array{checked:int, imported:int, unchanged:int, errors:int, details:list<array>}
     */
    public static function syncOpenInvoicePayments(int $limit = 200, ?QuickBooksClient $client = null, int $afterId = 0, ?float $deadline = null): array
    {
        $limit = max(1, min(1000, $limit));
        // Keyset by invoice id so a caller working in time-boxed slices
        // resumes where the last one stopped (next_after_id).
        $rows = db_select(
            "SELECT m.ff_invoice_id, m.qbo_invoice_id, i.invoice_number, i.balance_due
               FROM acc_qbo_invoice_map m
               JOIN invoices i ON i.id = m.ff_invoice_id
              WHERE m.qbo_invoice_id IS NOT NULL
                AND i.deleted_at IS NULL
                AND i.status IN ('sent','partially_paid','overdue')
                AND i.balance_due > 0
                AND i.id > ?
              ORDER BY i.id ASC
              LIMIT {$limit}",
            [$afterId]
        );
        $client ??= new QuickBooksClient();
        $out = ['checked' => 0, 'imported' => 0, 'unchanged' => 0, 'errors' => 0, 'details' => [], 'next_after_id' => null];
        foreach ($rows as $r) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $out['next_after_id'] = (int) $r['ff_invoice_id'] - 1;   // resume here
                break;
            }
            $out['checked']++;
            try {
                $qbo = $client->getEntity('invoice', (string) $r['qbo_invoice_id'], ['entity_type' => 'invoice', 'entity_id' => (int) $r['ff_invoice_id'], 'operation' => 'payment_catchup'])['Invoice'] ?? null;
            } catch (\Throwable $e) {
                $out['errors']++;
                $out['details'][] = ['invoice' => $r['invoice_number'], 'status' => 'qbo_error', 'detail' => $e->getMessage()];
                continue;
            }
            $qboBal = self::money($qbo['Balance'] ?? $r['balance_due']);
            $ffBal  = self::money($r['balance_due']);
            // Nothing to do when QuickBooks is not lower — or lower only by a
            // tax-rounding cent on an invoice it still shows open (that cent
            // settles when the customer pays, RoundingSettler).
            if (!is_array($qbo) || bccomp($qboBal, $ffBal, 2) >= 0
                || (bccomp($qboBal, '0', 2) > 0 && self::sameAmount($qboBal, $ffBal))) {
                $out['unchanged']++;
                continue;
            }
            $res = self::importPayments((int) $r['ff_invoice_id'], $qbo);
            if ($res['status'] === 'imported') {
                $out['imported']++;
            } elseif ($res['status'] === 'none') {
                $out['unchanged']++;
            } else {
                $out['errors']++;
            }
            $out['details'][] = ['invoice' => $r['invoice_number']] + $res;
        }
        return $out;
    }

    /**
     * Mirror the payments QuickBooks holds against a linked / pushed bill
     * into FF (S-QBO-BILLPAY-MIRROR). Each QuickBooks BillPayment goes
     * through BillPaymentWebhookHandler with Update semantics: new → create;
     * already mirrored → unchanged unless QuickBooks edited it.
     *
     * A QuickBooks Bill lists its payments in LinkedTxn; Intuit tags them
     * "BillPaymentCheck" / "BillPaymentCreditCard" (older responses:
     * "BillPayment"), so any BillPayment* type counts.
     *
     * @return array{status:string, detail?:string, results?:list<array>, ff_balance?:string, qbo_balance?:?string}
     */
    public static function importBillPayments(int $ffBillId, ?array $qboBill = null, ?QuickBooksClient $client = null): array
    {
        $map = db_row("SELECT qbo_bill_id FROM acc_qbo_bill_map WHERE ff_bill_id = ? AND qbo_bill_id IS NOT NULL", [$ffBillId]);
        if (!$map) {
            return ['status' => 'not_linked', 'detail' => 'Bill is not linked to QuickBooks.'];
        }
        $bill = db_row("SELECT status, balance_due FROM acc_bills WHERE id = ?", [$ffBillId]);
        if ($qboBill === null) {
            try {
                $client ??= new QuickBooksClient();
                $qboBill = $client->getEntity('bill', (string) $map['qbo_bill_id'], ['entity_type' => 'bill', 'entity_id' => $ffBillId, 'operation' => 'payment_catchup'])['Bill'] ?? null;
            } catch (\Throwable $e) {
                return ['status' => 'qbo_error', 'detail' => $e->getMessage()];
            }
            if (!is_array($qboBill)) {
                return ['status' => 'qbo_error', 'detail' => 'QuickBooks returned no Bill.'];
            }
        }
        $paymentIds = [];
        foreach ($qboBill['LinkedTxn'] ?? [] as $lt) {
            if (str_starts_with((string) ($lt['TxnType'] ?? ''), 'BillPayment') && !empty($lt['TxnId'])) {
                $paymentIds[(string) $lt['TxnId']] = true;
            }
        }
        if ($paymentIds === []) {
            return ['status' => 'none', 'detail' => 'QuickBooks has no payments on this bill.',
                    'ff_balance' => (string) ($bill['balance_due'] ?? ''), 'qbo_balance' => isset($qboBill['Balance']) ? self::money($qboBill['Balance']) : null];
        }
        if (!$bill || !in_array($bill['status'], array_merge(BillPaymentWebhookHandler::PAYABLE_BILL_STATUSES, ['paid']), true)) {
            return ['status' => 'not_payable',
                    'detail' => 'QuickBooks has ' . count($paymentIds) . " payment(s) on this bill, but it is '" . ($bill['status'] ?? '?')
                        . "' in FleetForge — approve it (or void it) in FleetForge, then check again."];
        }
        $realm   = (string) settings_get('quickbooks.realm_id', '');
        $results = [];
        foreach (array_keys($paymentIds) as $pid) {
            $pid = (string) $pid;
            $r = BillPaymentWebhookHandler::handle($pid, 'Update', $realm, 'cutover-import', 'qbo_other');
            $results[] = ['qbo_bill_payment_id' => $pid] + $r;
        }
        $after = db_row("SELECT status, balance_due FROM acc_bills WHERE id = ?", [$ffBillId]);
        return [
            'status'      => 'imported',
            'results'     => $results,
            'ff_status'   => (string) ($after['status'] ?? ''),
            'ff_balance'  => (string) ($after['balance_due'] ?? ''),
            'qbo_balance' => isset($qboBill['Balance']) ? self::money($qboBill['Balance']) : null,
        ];
    }

    /**
     * Catch-up sweep for bills (S-QBO-BILLPAY-MIRROR): every linked/pushed
     * FF bill still open in FF but paid down in QuickBooks gets its
     * QuickBooks bill payments mirrored — covers go-live history and any
     * webhook that never arrived. Same keyset / deadline contract as
     * syncOpenInvoicePayments (next_after_id = resume point).
     *
     * @return array{checked:int, imported:int, unchanged:int, errors:int, details:list<array>, next_after_id:?int}
     */
    public static function syncOpenBillPayments(int $limit = 200, ?QuickBooksClient $client = null, int $afterId = 0, ?float $deadline = null): array
    {
        $limit = max(1, min(1000, $limit));
        $rows = db_select(
            "SELECT m.ff_bill_id, m.qbo_bill_id, b.bill_number, b.balance_due
               FROM acc_qbo_bill_map m
               JOIN acc_bills b ON b.id = m.ff_bill_id
              WHERE m.qbo_bill_id IS NOT NULL
                AND b.status IN ('approved','scheduled','partially_paid')
                AND b.balance_due > 0
                AND b.id > ?
              ORDER BY b.id ASC
              LIMIT {$limit}",
            [$afterId]
        );
        $client ??= new QuickBooksClient();
        $out = ['checked' => 0, 'imported' => 0, 'unchanged' => 0, 'errors' => 0, 'details' => [], 'next_after_id' => null];
        foreach ($rows as $r) {
            if ($deadline !== null && microtime(true) > $deadline) {
                $out['next_after_id'] = (int) $r['ff_bill_id'] - 1;   // resume here
                break;
            }
            $out['checked']++;
            try {
                $qbo = $client->getEntity('bill', (string) $r['qbo_bill_id'], ['entity_type' => 'bill', 'entity_id' => (int) $r['ff_bill_id'], 'operation' => 'payment_catchup'])['Bill'] ?? null;
            } catch (\Throwable $e) {
                $out['errors']++;
                $out['details'][] = ['bill' => $r['bill_number'], 'status' => 'qbo_error', 'detail' => $e->getMessage()];
                continue;
            }
            $qboBal = self::money($qbo['Balance'] ?? $r['balance_due']);
            $ffBal  = self::money($r['balance_due']);
            // Nothing to do when QuickBooks is not lower — or lower only by a
            // tax-rounding cent on a bill it still shows open.
            if (!is_array($qbo) || bccomp($qboBal, $ffBal, 2) >= 0
                || (bccomp($qboBal, '0', 2) > 0 && self::sameAmount($qboBal, $ffBal))) {
                $out['unchanged']++;
                continue;
            }
            $res = self::importBillPayments((int) $r['ff_bill_id'], $qbo);
            $created = array_filter($res['results'] ?? [], static fn($x) => in_array($x['result'] ?? '', ['payment_created', 'payment_resynced'], true));
            if ($res['status'] === 'imported' && $created !== []) {
                $out['imported']++;
            } elseif ($res['status'] === 'none' || ($res['status'] === 'imported' && self::allSettled($res['results'] ?? []))) {
                $out['unchanged']++;
            } else {
                $out['errors']++;
                if ($res['status'] === 'imported') {
                    // Payments were read but not recorded (e.g. paid from a
                    // QuickBooks account with no FF bank account) — say why.
                    $res['status'] = 'needs_attention';
                    $res['detail'] = implode('; ', array_map(
                        static fn($x) => ($x['result'] ?? '?') . ': ' . ($x['detail'] ?? ''),
                        array_filter($res['results'] ?? [], static fn($x) => !self::allSettled([$x]))
                    ));
                }
            }
            $out['details'][] = ['bill' => $r['bill_number']] + $res;
        }
        return $out;
    }

    /**
     * True when every BillPayment result is a benign no-op (already in FF).
     *
     * @param list<array<string,mixed>> $results
     */
    private static function allSettled(array $results): bool
    {
        foreach ($results as $x) {
            if (!in_array($x['result'] ?? '', ['already_mapped', 'unchanged', 'ff_origin_echo', 'already_voided'], true)) {
                return false;
            }
        }
        return true;
    }

    // ────────────────────────────────────────────────────────────────
    //  Pre-go-live push guard
    // ────────────────────────────────────────────────────────────────

    /**
     * Reason to refuse pushing $doc as a NEW QuickBooks document, or null.
     * A document dated (or, for an invoice, sent) before go-live
     * (quickbooks.cutover_at, stamped the first time sync is switched on)
     * is almost certainly already in QuickBooks. It must be linked — or
     * explicitly released with "Push as new" — never silently duplicated.
     */
    public static function cutoverBlockReason(string $kind, array $doc): ?string
    {
        $cfg = self::cfg($kind);
        $cutoverAt = (string) settings_get('quickbooks.cutover_at', '');
        $ts = $cutoverAt !== '' ? strtotime($cutoverAt) : false;
        if ($ts === false) {
            return null;
        }
        $cutoverUtc = gmdate('Y-m-d H:i:s', $ts);
        $override   = self::pushFromOverride();
        $cutoverDay = $override ?? ff_utc_to_local($cutoverUtc, 'Y-m-d');

        $shape   = self::ffDocShape($kind, $doc);
        $before  = (string) $shape['doc_date'] !== '' && (string) $shape['doc_date'] < $cutoverDay;
        // "Sent before go-live" only counts while no explicit push-from date
        // is set — that date is the operator's own statement of where
        // QuickBooks' hand-entered history stops.
        $sentPre = $override === null && $kind === 'invoice' && !empty($doc['sent_at']) && (string) $doc['sent_at'] < $cutoverUtc;
        if (!$before && !$sentPre) {
            return null;
        }
        $released = db_row("SELECT link_method FROM {$cfg['map']} WHERE {$cfg['map_fk']} = ?", [(int) $doc['id']]);
        if ($released && ($released['link_method'] ?? '') === 'push_new') {
            return null;
        }
        return ucfirst($cfg['label']) . " {$shape['number']} is dated before QuickBooks go-live ({$cutoverDay}), so QuickBooks probably already has it. "
            . 'Link it on QuickBooks → Invoices → Link existing, or choose "Push as new" there if QuickBooks really does not have it.';
    }

    /**
     * The first local day FleetForge pushes transactions for:
     * quickbooks.push_from_date when the operator set one (Settings →
     * Business tagging), else the go-live day (quickbooks.cutover_at).
     * Null before go-live (nothing is held back).
     */
    public static function pushFromDay(): ?string
    {
        $override = self::pushFromOverride();
        if ($override !== null) {
            return $override;
        }
        $cutoverAt = (string) settings_get('quickbooks.cutover_at', '');
        $ts = $cutoverAt !== '' ? strtotime($cutoverAt) : false;
        return $ts === false ? null : ff_utc_to_local(gmdate('Y-m-d H:i:s', $ts), 'Y-m-d');
    }

    private static function pushFromOverride(): ?string
    {
        return clean_date((string) settings_get('quickbooks.push_from_date', '')) ?: null;
    }

    /**
     * Reason to refuse pushing a TRANSACTION (customer payment, bill
     * payment, journal entry) dated before the push-from day, or null.
     * QuickBooks already holds the books for those periods — the accountant
     * recorded them by hand before go-live — so pushing FF's copy would
     * book it twice (e.g. a depreciation catch-up run for the past year, or
     * historical bill payments entered for per-unit costing).
     */
    public static function preGoLiveDateReason(string $what, ?string $txnDate): ?string
    {
        $from = self::pushFromDay();
        $day  = substr((string) $txnDate, 0, 10);
        if ($from === null || $day === '' || $day >= $from) {
            return null;
        }
        return "{$what} is dated {$day}, before QuickBooks go-live ({$from}). QuickBooks already holds the books for that period, "
            . "so FleetForge does not push it (it would be recorded twice). If QuickBooks really lacks it, enter it there by hand — "
            . "or move \"Push transactions dated from\" on QuickBooks → Settings earlier.";
    }

    /**
     * Reason to refuse CREATING a QuickBooks customer / vendor for an FF
     * record that existed before go-live, or null. The company file already
     * holds the business's customers and vendors (typed in by the
     * accountant, often under a slightly different name than FF's), so an
     * unmapped pre-go-live record most likely has a QuickBooks twin: a
     * silent create would make a duplicate in the shared file. It must be
     * linked, or released with "Create in QuickBooks" (that decision is
     * stored as match_confidence='manual' on its ff_only map row).
     *
     * @param string      $label        'Customer' | 'Vendor'
     * @param string|null $createdAtUtc the FF row's created_at (UTC DATETIME)
     * @param array|null  $mapping      its map row (needs match_confidence)
     */
    public static function partyCreateBlockReason(string $label, ?string $createdAtUtc, ?array $mapping): ?string
    {
        $cutoverAt = (string) settings_get('quickbooks.cutover_at', '');
        $ts = $cutoverAt !== '' ? strtotime($cutoverAt) : false;
        if ($ts === false || $createdAtUtc === null || $createdAtUtc === '') {
            return null;
        }
        if ($mapping !== null && ($mapping['match_confidence'] ?? null) === 'manual') {
            return null;
        }
        if ($createdAtUtc >= gmdate('Y-m-d H:i:s', $ts)) {
            return null;
        }
        $page = $label === 'Vendor' ? 'Vendors' : 'Customers';
        return "{$label} existed in FleetForge before QuickBooks go-live, so QuickBooks probably already has them (perhaps under a slightly different name). "
            . "Link them on QuickBooks → {$page}, or choose \"Create in QuickBooks\" there if QuickBooks really does not have them.";
    }

    /**
     * An FF edit / void of a document LINKED at go-live. FleetForge never
     * rewrites a QuickBooks document the accountant created, so instead of
     * pushing: a SKIP row in the sync log (Push History shows it) and one
     * open drift event per document + operation, telling a person to make
     * the same change in QuickBooks.
     *
     * @param string $entityType 'invoice' | 'credit_memo' (sync-log / drift naming)
     */
    public static function recordLinkedChange(string $entityType, int $ffId, string $qboId, string $number, string $operation): void
    {
        $verb = $operation === 'void' ? 'voided' : 'changed';
        $label = ['credit_memo' => 'credit note', 'bill' => 'bill'][$entityType] ?? 'invoice';
        $msg = ucfirst($label) . " {$number} was {$verb} in FleetForge. It is linked to a QuickBooks {$label} that existed before go-live, "
            . "so FleetForge does not {$operation} it in QuickBooks — make the same change in QuickBooks by hand, then resolve this.";
        try {
            db_insert('acc_qbo_sync_log', [
                'direction'     => 'push',
                'entity_type'   => $entityType,
                'entity_id'     => $ffId,
                'qbo_entity_id' => $qboId,
                'operation'     => $operation,
                'http_method'   => 'SKIP',
                'endpoint'      => '',
                'error_code'    => 'skipped_cutover_link',
                'error_message' => $msg,
                'queue_id'      => QuickBooksClient::workerQueueId(),
                'realm_id'      => (string) settings_get('quickbooks.realm_id', 'unknown'),
                'environment'   => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
            $open = db_row(
                "SELECT id FROM acc_qbo_drift_events
                  WHERE entity_type = ? AND entity_id = ? AND qbo_entity_id = ? AND resolved_at IS NULL
                    AND detection_source = 'push_failure' AND description LIKE ?",
                [$entityType, $ffId, $qboId, '%was ' . $verb . ' in FleetForge%']
            );
            if ($open) {
                return;
            }
            $id = db_insert('acc_qbo_drift_events', [
                'detection_source' => 'push_failure',
                'category'         => 'field_mismatch',
                'entity_type'      => $entityType,
                'entity_id'        => $ffId,
                'qbo_entity_id'    => $qboId,
                'description'      => $msg,
                'queue_id'         => QuickBooksClient::workerQueueId(),
                'realm_id'         => (string) settings_get('quickbooks.realm_id', 'unknown') ?: 'unknown',
                'environment'      => (string) settings_get('quickbooks.environment', 'sandbox'),
            ]);
            if (class_exists('\\FleetForge\\Notifications\\NotificationService')) {
                \FleetForge\Notifications\NotificationService::notify(
                    type:       'quickbooks.drift',
                    title:      'Update QuickBooks by hand',
                    message:    substr($msg, 0, 300),
                    entityType: 'qbo_drift',
                    entityId:   (int) $id,
                    url:        base_url('quickbooks/drift_show') . '?id=' . (int) $id,
                    severity:   'warning'
                );
            }
        } catch (\Throwable $e) {
            error_log("[InvoiceLinker] recordLinkedChange failed for {$entityType} {$ffId}: " . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────────────────
    //  Internals
    // ────────────────────────────────────────────────────────────────

    /**
     * FF documents with no QuickBooks id on their map row.
     *
     * @return list<array<string,mixed>> normalized: id, number, match_number, doc_date, total, currency, status, party_id, customer_name (the counterparty), qbo_party_id, balance_due
     */
    private static function unlinkedFfDocs(string $kind, array $opts, int $limit): array
    {
        $cfg    = self::cfg($kind);
        $party  = self::party($cfg);
        $where  = ["d.status <> 'void'", "(m.id IS NULL OR m.{$cfg['map_qbo']} IS NULL)"];
        $params = [];
        if ($cfg['soft_delete']) {
            $where[] = 'd.deleted_at IS NULL';
        }
        $statuses = $cfg['issued'];
        if (!empty($opts['include_drafts']) && $kind !== 'credit_memo') {
            $statuses[] = 'draft';
        }
        $where[] = "d.status IN ('" . implode("','", $statuses) . "')";
        $from = clean_date($opts['from'] ?? null);
        $to   = clean_date($opts['to'] ?? null);
        if ($cfg['date'] !== null) {
            $dateExpr = "d.{$cfg['date']}";
            if ($from) { $where[] = "{$dateExpr} >= ?"; $params[] = $from; }
            if ($to)   { $where[] = "{$dateExpr} <= ?"; $params[] = $to; }
        } else {
            $dateExpr = 'd.created_at';   // UTC DATETIME → local-day bounds
            if ($from) { $where[] = 'd.created_at >= ?'; $params[] = ff_local_day_start_utc($from); }
            if ($to)   { $where[] = 'd.created_at < ?';  $params[] = ff_local_day_start_utc(ff_local_date_add($to, 1)); }
        }
        if ($kind === 'credit_memo') {
            // Overpayment credit notes mirror a Payment's unapplied amount
            // in QuickBooks — they are never a QuickBooks credit memo.
            $where[] = "d.source <> 'overpayment'";
            // Rounding settlements exist in FF only (RoundingSettler).
            $where[] = "(d.internal_notes IS NULL OR d.internal_notes NOT LIKE '[qbo-rounding]%')";
        }
        if (!empty($opts['party_id'])) {
            $where[] = "d.{$party['fk']} = ?";
            $params[] = (int) $opts['party_id'];
        }
        $rows = db_select(
            "SELECT d.*, {$party['name']} AS _party_name, pm.{$party['qbo']} AS _qbo_party_id
               FROM {$cfg['table']} d
               LEFT JOIN {$cfg['map']} m ON m.{$cfg['map_fk']} = d.id
               LEFT JOIN {$party['table']} p ON p.id = d.{$party['fk']}
               LEFT JOIN {$party['map']} pm ON pm.{$party['ff']} = d.{$party['fk']} AND pm.mapping_status = 'mapped'
              WHERE " . implode(' AND ', $where) . "
              ORDER BY {$dateExpr} ASC, d.id ASC
              LIMIT {$limit}",
            $params
        );
        return array_map(static function (array $r) use ($kind, $cfg) {
            $shape = self::ffDocShape($kind, $r);
            $shape['customer_name'] = (string) ($r['_party_name'] ?? '');
            $shape['qbo_party_id']  = $r['_qbo_party_id'] !== null ? (string) $r['_qbo_party_id'] : null;
            $shape['balance_due']   = self::money($r[$cfg['balance']] ?? '0');
            return $shape;
        }, $rows);
    }

    /** Normalized FF document fields shared by every kind. */
    private static function ffDocShape(string $kind, array $r): array
    {
        $cfg   = self::cfg($kind);
        $party = self::party($cfg);
        $date  = $cfg['date'] !== null
            ? (string) ($r[$cfg['date']] ?? '')
            : (!empty($r['created_at']) ? ff_utc_to_local((string) $r['created_at'], 'Y-m-d') : '');
        return [
            'kind'         => $kind,
            'id'           => (int) $r['id'],
            'number'       => (string) ($r[$cfg['number']] ?? ''),
            'match_number' => (string) ($r[$cfg['match_number']] ?? ''),
            'doc_date'     => $date,
            'total'        => self::money($r[$cfg['total']] ?? '0'),
            'currency'     => strtoupper((string) ($r['currency'] ?? 'CAD')),
            'status'       => (string) ($r['status'] ?? ''),
            'party_id'     => (int) ($r[$party['fk']] ?? 0),
            'unit'         => (string) ($r['unit_number_invoice_snapshot'] ?? ''),
        ];
    }

    /** QuickBooks documents for one customer / vendor in a TxnDate window (paged). */
    private static function fetchQboDocs(QuickBooksClient $client, array $cfg, string $qboPartyId, string $from, string $to): array
    {
        if (!ctype_digit($qboPartyId)) {
            return [];
        }
        $ref = self::party($cfg)['ref'];
        $out = [];
        $start = 1;
        $page = 500;
        do {
            $resp = $client->query(
                "SELECT * FROM {$cfg['qbo']} WHERE {$ref} = '{$qboPartyId}' AND TxnDate >= '{$from}' AND TxnDate <= '{$to}'"
                . " STARTPOSITION {$start} MAXRESULTS {$page}",
                ['entity_type' => $cfg['queue'], 'operation' => 'cutover_match']
            );
            $rows = $resp['QueryResponse'][$cfg['qbo']] ?? [];
            foreach ($rows as $r) {
                if (!empty($r['Id'])) {
                    $out[] = $r;
                }
            }
            $start += $page;
        } while (count($rows) === $page && $start < 10000);
        return $out;
    }

    /** QuickBooks ids of this kind already linked or pushed. */
    private static function linkedQboIds(string $kind): array
    {
        $cfg = self::cfg($kind);
        $ids = [];
        foreach (db_select("SELECT {$cfg['map_qbo']} AS q FROM {$cfg['map']} WHERE {$cfg['map_qbo']} IS NOT NULL") as $r) {
            $ids[(string) $r['q']] = true;
        }
        return $ids;
    }

    private static function row(array $ff, string $confidence, ?array $proposal, array $alternatives, ?string $note): array
    {
        return [
            'ff'           => $ff,
            'confidence'   => $confidence,
            'proposal'     => $proposal,
            'alternatives' => $alternatives,
            'note'         => $note,
        ];
    }

    private static function qboSummary(array $q, array $ff): array
    {
        $total = self::money($q['TotalAmt'] ?? '0');
        return [
            'id'          => (string) $q['Id'],
            'doc_number'  => (string) ($q['DocNumber'] ?? ''),
            'txn_date'    => (string) ($q['TxnDate'] ?? ''),
            'total'       => $total,
            'balance'     => isset($q['Balance']) ? self::money($q['Balance']) : null,
            'currency'    => strtoupper((string) ($q['CurrencyRef']['value'] ?? '')),
            'amount_diff' => bcsub($total, self::money($ff['total']), 2),
            'day_diff'    => self::dayDiff((string) $ff['doc_date'], (string) ($q['TxnDate'] ?? '')),
            'memo'        => substr((string) ($q['CustomerMemo']['value'] ?? $q['PrivateNote'] ?? ''), 0, 160),
        ];
    }

    /** A QBO doc without CurrencyRef (single-currency company) matches any FF currency equal to home. */
    private static function currencyCompatible(array $ff, array $q): bool
    {
        $qc = strtoupper((string) ($q['CurrencyRef']['value'] ?? ''));
        if ($qc === '') {
            $home = strtoupper((string) settings_get('quickbooks.home_currency', 'CAD')) ?: 'CAD';
            return strtoupper((string) $ff['currency']) === $home;
        }
        return $qc === strtoupper((string) $ff['currency']);
    }

    private static function ffDoc(array $ffDocs, int $id): array
    {
        foreach ($ffDocs as $d) {
            if ((int) $d['id'] === $id) {
                return $d;
            }
        }
        return ['id' => $id, 'total' => '0', 'doc_date' => '', 'number' => ''];
    }

    private static function cfgLabel(array $ff): string
    {
        return self::KINDS[$ff['kind'] ?? 'invoice']['label'] ?? 'document';
    }

    /**
     * Does the QuickBooks document mention this unit number (line
     * descriptions, customer memo)? Whole-token match, so unit "12" does not
     * hit "1200".
     */
    public static function mentionsUnit(array $qbo, string $unit): bool
    {
        $unit = trim($unit);
        if ($unit === '') {
            return false;
        }
        $text = (string) ($qbo['CustomerMemo']['value'] ?? '');
        foreach ($qbo['Line'] ?? [] as $l) {
            $text .= ' ' . (string) ($l['Description'] ?? '');
        }
        return preg_match('/(?<![A-Za-z0-9])' . preg_quote($unit, '/') . '(?![A-Za-z0-9])/i', $text) === 1;
    }

    /** Equal within AMOUNT_TOLERANCE (per-line vs per-invoice tax rounding). */
    public static function sameAmount(string $a, string $b): bool
    {
        return bccomp(ltrim(bcsub($a, $b, 2), '-'), self::AMOUNT_TOLERANCE, 2) <= 0;
    }

    /** Doc numbers compare case- and space-insensitively ("INV-2026-00012" ≡ "inv-2026-00012 "). */
    public static function normNumber(string $n): string
    {
        return strtoupper(preg_replace('/\s+/', '', $n) ?? '');
    }

    private static function money($v): string
    {
        return is_numeric((string) $v) ? bcadd((string) $v, '0', 2) : '0.00';
    }

    private static function dayDiff(string $a, string $b): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $a) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}/', $b) !== 1) {
            return PHP_INT_MAX;
        }
        return (int) abs((new \DateTimeImmutable(substr($a, 0, 10)))->diff(new \DateTimeImmutable(substr($b, 0, 10)))->days);
    }
}
