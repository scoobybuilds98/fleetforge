<?php
declare(strict_types=1);

/**
 * lib/Support/ErrorGuidance.php
 *
 * The app-wide error explainer. Turns any API error into the explain-and-fix
 * popup content — what happened, what to do, and the link that does it.
 *
 * WHY (S-BILLING-GUIDANCE → S-ERROR-GUIDANCE-ALL): FLEETFORGE-1E showed the
 * cost of a dead-end error. The operator saw "AN UNEXPECTED ERROR OCCURRED",
 * retried nine times across two endpoints, and the one sentence that would
 * have unblocked them ("this lease has an estimated mileage but no rate")
 * went to Sentry instead of to their screen. That is not a billing problem —
 * every module can dead-end the same way.
 *
 * WHY CENTRAL: there are ~1,478 json_error() call sites across 469 files and
 * 132 distinct codes. Hand-writing guidance at each site would be unfinishable
 * and would rot on the first refactor. Instead json_error() consults this
 * registry ONCE, keyed on the error code, so every endpoint in every module is
 * covered without touching a single call site — and a new endpoint that reuses
 * an existing code inherits the explanation for free.
 *
 * THE THREE LAYERS (first match wins):
 *   1. Endpoint-supplied  — a call site that already passed its own `guidance`
 *      keeps it (e.g. BillingRateGuidance, which knows the actual numbers).
 *   2. Curated by code    — the codes below, written for the operator.
 *   3. Status fallback    — anything not in the registry still gets a correct,
 *      non-embarrassing popup derived from the HTTP status + the endpoint's own
 *      message. A long-tail code is never worse off than the banner it had.
 *
 * WHAT DELIBERATELY GETS NO POPUP (see suppressed()):
 *   - VALIDATION_ERROR carrying a `fields` map. The form already highlights the
 *     offending input inline, which is the better surface; a modal on every
 *     mistyped field would be punishing. VALIDATION_ERROR *without* fields (a
 *     whole-request rejection with nowhere to point) does get one.
 *   - PERIOD_OVERLAP, which drives its own confirm-and-resend dialog on the
 *     invoice form — two stacked modals is worse than one.
 *
 * Defines: FleetForge\Support\ErrorGuidance
 * Used by: api/bootstrap.php (json_error + the global exception handler)
 * Payload shape + the modal that renders it: lib/Billing/BillingRateGuidance.php,
 *                                            public/assets/js/app.js (FF_Guidance)
 * @session S-ERROR-GUIDANCE-ALL
 */

namespace FleetForge\Support;

final class ErrorGuidance
{
    /**
     * Codes that must NOT pop a modal — a better surface already exists.
     * VALIDATION_ERROR is conditional on carrying a fields map, so it is
     * handled in build() rather than listed here.
     */
    private const NO_POPUP = [
        'PERIOD_OVERLAP',   // invoice form runs its own confirm-and-resend
    ];

    /**
     * $extra id key → [route, human noun]. Used to offer "Open the …" links
     * on any error whose payload names the record it was about. Every route
     * here is one that exists in app/admin — a guidance link must never 404.
     */
    private const ENTITY_ROUTES = [
        'lease_id'          => ['leases/show',                     'lease'],
        'invoice_id'        => ['invoices/show',                   'invoice'],
        'customer_id'       => ['customers/show',                  'customer'],
        'equipment_unit_id' => ['equipment/show',                  'unit'],
        'unit_id'           => ['equipment/show',                  'unit'],
        'vendor_id'         => ['vendors/show',                    'vendor'],
        'reservation_id'    => ['reservations/show',               'reservation'],
        'damage_claim_id'   => ['damage_claims/show',              'damage claim'],
        'journal_entry_id'  => ['accounting/journal-entries/show', 'journal entry'],
    ];

    /**
     * Build the guidance block for an error, or null when it should not pop.
     *
     * @param string              $code    SCREAMING_SNAKE error code.
     * @param string              $message The endpoint's own message.
     * @param int                 $status  HTTP status.
     * @param array<string,mixed> $extra   The json_error() extras (ids, fields…).
     * @return array<string,mixed>|null
     */
    public static function build(string $code, string $message, int $status, array $extra = []): ?array
    {
        if (self::suppressed($code, $extra)) {
            return null;
        }

        $entity  = self::entityAction($extra);
        $curated = self::curated($code, $message, $status, $entity);
        $g       = $curated ?? self::byStatus($code, $message, $status, $entity);

        // Every popup ends with somewhere to go. When the curated entry has no
        // action of its own, offer the record the error was about.
        if (($g['actions'] ?? []) === [] && $entity !== null) {
            $g['actions'] = [$entity];
        }

        return [
            'title'   => $g['title'],
            'summary' => $g['summary'],
            'cause'   => $g['cause']   ?? null,
            'steps'   => $g['steps']   ?? [],
            'actions' => $g['actions'] ?? [],
            'detail'  => $g['detail']  ?? null,
        ];
    }

    /** A popup here would fight a better-suited surface. */
    private static function suppressed(string $code, array $extra): bool
    {
        if ($code === 'VALIDATION_ERROR' && !empty($extra['fields'])) {
            return true;  // the form highlights the field inline
        }
        return in_array($code, self::NO_POPUP, true);
    }

    /**
     * The curated entries — codes that carry real operator meaning.
     * Ordered roughly by how often they fire across the app.
     *
     * @return array<string,mixed>|null
     */
    private static function curated(string $code, string $message, int $status, ?array $entity): ?array
    {
        $reload  = 'Reload the page so you are working from the current data, then try again.';
        $support = 'If it keeps happening, send this error to your administrator with what you were doing.';

        switch ($code) {
            // ── Concurrency + record state ──────────────────────────────────
            case 'STALE_DATA':
            case 'CONCURRENT_MODIFICATION':
                return [
                    'title'   => 'Someone else changed this first',
                    'summary' => 'This record was modified by another user (or another tab) while you '
                               . 'had it open. Your changes were NOT saved — saving them now would '
                               . 'silently overwrite theirs.',
                    'steps'   => [
                        'Reload the page to see the current version.',
                        'Re-apply your change on top of theirs, then save again.',
                    ],
                    'detail'  => $message,
                ];

            case 'INVALID_TRANSITION':
            case 'INVALID_STATUS':
            case 'INVALID_STATE':
            case 'LEASE_NOT_ACTIVE':
            case 'UNIT_STATE_CHANGED':
                return [
                    'title'   => 'That is not possible in the current status',
                    'summary' => 'This record is in a status that does not allow the action you asked '
                               . 'for. Nothing was changed. Usually it moved on since the page loaded '
                               . '— for example it was already closed, sent, cancelled or activated.',
                    'cause'   => $message,
                    'steps'   => [$reload, 'If the status looks right, the action may need to happen '
                                         . 'from a different screen for this record type.'],
                ];

            case 'IMMUTABLE_RECORD':
            case 'INVOICE_VOID':
            case 'PRECHARGE_LOCKED':
                return [
                    'title'   => 'This record is locked',
                    'summary' => 'This record can no longer be edited — records are frozen once the '
                               . 'money or the paperwork behind them is final (sent, paid, closed or '
                               . 'voided). Nothing was changed.',
                    'cause'   => $message,
                    'steps'   => [
                        'To correct something on a locked record, issue the matching adjustment '
                        . '(a credit note, an amendment, or a new document) rather than editing it.',
                        'If it should not be locked, an administrator can check its status history.',
                    ],
                ];

            case 'CONFLICT':
            case 'IN_USE':
                return [
                    'title'   => 'This is in use somewhere else',
                    'summary' => 'The action was refused because something else depends on this '
                               . 'record, or it is already committed elsewhere. Nothing was changed.',
                    'cause'   => $message,
                    'steps'   => ['Clear or reassign whatever depends on it, then try again.', $reload],
                ];

            case 'ALREADY_EXISTS':
            case 'CONTRACT_NUMBER_TAKEN':
                return [
                    'title'   => 'That already exists',
                    'summary' => 'Another record already uses this identifier, and identifiers have '
                               . 'to be unique. Nothing was saved.',
                    'cause'   => $message,
                    'steps'   => ['Pick a different value and save again.',
                                  'If you expected to find the existing record, search for it first — '
                                  . 'it may be archived rather than deleted.'],
                ];

            case 'UNIT_UNAVAILABLE':
                return [
                    'title'   => 'That unit is not available',
                    'summary' => 'The equipment unit is already committed — on another active lease or '
                               . 'reservation, or not in an available status. Nothing was saved.',
                    'cause'   => $message,
                    'steps'   => ['Pick a different unit, or close/cancel the commitment that holds '
                                  . 'this one first.'],
                ];

            // ── Rates + billing configuration ───────────────────────────────
            case 'BILLING_RATE_INCOMPLETE':
            case 'RATE_TIER_INCOMPLETE':
                return [
                    'title'   => 'The lease rates are incomplete',
                    'summary' => 'The billing engine refuses to bill a lease whose rates do not add up '
                               . '— it would produce a wrong or $0 invoice. Nothing was billed.',
                    'cause'   => $message,
                    'steps'   => [
                        'Open the lease and use the Rate Amendment workflow to fill in the missing '
                        . 'rate (rates are audit-trailed, so they are not editable directly).',
                        'When any rental tier is set, all three (daily, weekly, monthly) must be > 0.',
                        'An estimate — mileage per day, allowance, or engine hours — needs a matching '
                        . 'rate, or it must be cleared on the lease.',
                    ],
                ];

            case 'RATE_AMENDMENT_REQUIRED':
                return [
                    'title'   => 'Rates change through the amendment workflow',
                    'summary' => 'Rate fields cannot be edited directly on a lease — every rate change '
                               . 'has to be captured in the audit trail. Nothing was saved.',
                    'cause'   => $message,
                    'steps'   => ['Open the lease, go to the Amendments tab, and record the change '
                                  . 'through the Rate Amendment workflow.'],
                ];

            case 'ALLOCATION_EXCEEDS_BALANCE':
            case 'CREDIT_EXCEEDS_BALANCE':
            case 'INVALID_BALANCE':
                return [
                    'title'   => 'That is more than the balance',
                    'summary' => 'The amount you entered is larger than the balance it would be applied '
                               . 'to. Nothing was posted.',
                    'cause'   => $message,
                    'steps'   => ['Lower the amount to the remaining balance or less.',
                                  'If the balance looks wrong, reload — another payment or credit may '
                                  . 'have been applied since this page loaded.'],
                ];

            case 'ACCOUNTING_CONFIG_INCOMPLETE':
            case 'CONFIG_INCOMPLETE':
                return [
                    'title'   => 'This needs to be set up first',
                    'summary' => 'The action depends on configuration that has not been completed yet, '
                               . 'so it was not run.',
                    'cause'   => $message,
                    'steps'   => ['Open Settings and complete the configuration named above.',
                                  'An administrator may need to do this if you cannot see the setting.'],
                    'actions' => [
                        ['label' => 'Open Settings', 'url' => self::url('settings'), 'primary' => true],
                    ],
                ];

            // ── Access + session ────────────────────────────────────────────
            case 'UNAUTHORIZED':
            case 'NOT_AUTHENTICATED':
            case 'SESSION_EXPIRED':
                return [
                    'title'   => 'Your session has ended',
                    'summary' => 'You are no longer signed in, so the action was not carried out. '
                               . 'This normally means the session timed out.',
                    'steps'   => ['Sign in again, then repeat what you were doing.',
                                  'If you had unsaved text on screen, copy it before signing in — '
                                  . 'reloading will clear it.'],
                    'actions' => [
                        ['label' => 'Sign in again', 'url' => self::url('auth/login'), 'primary' => true],
                    ],
                ];

            case 'FORBIDDEN':
            case 'SUPER_ADMIN_PROTECTED':
                return [
                    'title'   => 'You do not have access to that',
                    'summary' => 'Your role does not include permission for this action, so it was not '
                               . 'carried out. Nothing was changed.',
                    'cause'   => $message,
                    'steps'   => ['If you need this access, ask an administrator to grant the '
                                  . 'permission for your role.',
                                  'If you believe you already have it, sign out and back in — '
                                  . 'permission changes apply at next sign-in.'],
                ];

            case 'RATE_LIMITED':
                return [
                    'title'   => 'Too many attempts — slow down',
                    'summary' => 'This action was blocked because it was repeated too quickly. This is '
                               . 'a safety limit, not a failure of what you were doing.',
                    'cause'   => $message,
                    'steps'   => ['Wait a minute, then try once more.',
                                  'Avoid repeatedly clicking the button — each click counts.'],
                ];

            // ── Files, documents, integrations ──────────────────────────────
            case 'UPLOAD_ERROR':
            case 'UPLOAD_FAILED':
            case 'INVALID_FILE_TYPE':
            case 'UNSUPPORTED_FILE_TYPE':
                return [
                    'title'   => 'That file could not be uploaded',
                    'summary' => 'The file was rejected, so nothing was attached.',
                    'cause'   => $message,
                    'steps'   => ['Check the file type and size against the limits shown on the '
                                  . 'upload control.',
                                  'If it is a photo from a phone, try re-saving or screenshotting it '
                                  . 'and uploading that.'],
                ];

            case 'PDF_GEN_FAILED':
            case 'PDF_GENERATION_FAILED':
            case 'ZIP_FAILED':
                return [
                    'title'   => 'The document could not be generated',
                    'summary' => 'The record itself is fine — only the file could not be produced, so '
                               . 'nothing was sent or saved.',
                    'cause'   => $message,
                    'steps'   => ['Try again — this is often temporary.', $support],
                ];

            case 'SEND_FAILED':
            case 'NO_EMAIL':
                return [
                    'title'   => 'That could not be sent',
                    'summary' => 'The message was not delivered. The underlying record was not changed '
                               . 'by the failed send.',
                    'cause'   => $message,
                    'steps'   => ['Check the recipient has a valid email address on file.',
                                  'Try again, then ' . lcfirst($support)],
                ];

            case 'QBO_PULL_FAILED':
            case 'QBO_CREATE_FAILED':
            case 'NOT_LINKED':
                return [
                    'title'   => 'QuickBooks could not be reached',
                    'summary' => 'The QuickBooks step did not complete. Your FleetForge data is '
                               . 'unchanged — only the sync failed.',
                    'cause'   => $message,
                    'steps'   => ['Open the QuickBooks settings and check the connection is still '
                                  . 'authorised — the token expires periodically and needs '
                                  . 're-connecting.',
                                  'Then retry the action.'],
                    'actions' => [
                        ['label' => 'QuickBooks settings', 'url' => self::url('quickbooks/settings'), 'primary' => true],
                    ],
                ];

            case 'AI_DISABLED':
            case 'TOKEN_LIMIT':
                return [
                    'title'   => 'The assistant is not available right now',
                    'summary' => 'The AI feature did not run. Nothing else was affected.',
                    'cause'   => $message,
                    'steps'   => ['If this is a length limit, shorten the request and try again.',
                                  'Otherwise an administrator can enable or re-configure the feature '
                                  . 'in Settings.'],
                ];

            case 'MFA_NOT_ENABLED':
                return [
                    'title'   => 'Two-factor authentication is not set up',
                    'summary' => 'This action needs two-factor authentication on your account first.',
                    'cause'   => $message,
                    'steps'   => ['Open your profile and enrol a two-factor device, then retry.'],
                    'actions' => [
                        ['label' => 'Open my profile', 'url' => self::url('profile'), 'primary' => true],
                    ],
                ];

            // ── Generic shapes worth a better sentence than the fallback ─────
            case 'NOT_FOUND':
            case 'GONE':
                return [
                    'title'   => 'That record could not be found',
                    'summary' => 'It may have been deleted or archived since this page was opened, or '
                               . 'the link that brought you here is out of date.',
                    'cause'   => $message,
                    'steps'   => ['Go back to the list and open the record from there.',
                                  'If it was deleted in error, an administrator can check the audit log.'],
                ];

            case 'MISSING_REQUIRED':
                return [
                    'title'   => 'Something required is missing',
                    'summary' => 'The action was not carried out because a required value did not '
                               . 'reach the server.',
                    'cause'   => $message,
                    'steps'   => ['Fill in the field named above and try again.',
                                  'If the form looks complete, reload the page — a stale form can '
                                  . 'drop a value.'],
                ];

            case 'EXPIRED':
                return [
                    'title'   => 'That link or code has expired',
                    'summary' => 'It was valid for a limited time and that window has passed. Nothing '
                               . 'was changed.',
                    'cause'   => $message,
                    'steps'   => ['Request a fresh link or code, then use it straight away.'],
                ];

            default:
                return null;
        }
    }

    /**
     * The fallback every uncurated code lands on. Still correct and still
     * actionable — it just cannot be as specific as a curated entry.
     *
     * @return array<string,mixed>
     */
    private static function byStatus(string $code, string $message, int $status, ?array $entity): array
    {
        if ($status >= 500) {
            return [
                'title'   => 'Something went wrong on our end',
                'summary' => 'This is a fault in FleetForge, not something you did wrong. The action '
                           . 'may not have completed — check the record before repeating it, so you '
                           . 'do not create it twice.',
                'cause'   => $message,
                'steps'   => [
                    'Reload the page and check whether the change actually went through.',
                    'If it did not, try once more.',
                    'If it fails again, send your administrator the reference below and what you '
                    . 'were doing — it has already been reported automatically.',
                ],
                'detail'  => 'Error code: ' . $code,
            ];
        }

        if ($status === 401 || $status === 403) {
            return [
                'title'   => 'That action was not permitted',
                'summary' => 'The request was refused, so nothing was changed.',
                'cause'   => $message,
                'steps'   => ['If you need this access, ask an administrator.',
                              'If you were signed in a long time ago, sign out and back in.'],
            ];
        }

        // 4xx — the endpoint refused for a business reason and its message is
        // the most specific thing anyone has. Present it properly rather than
        // inventing a worse sentence around it.
        return [
            'title'   => 'That action could not be completed',
            'summary' => $message,
            'steps'   => [
                'Nothing was changed — you can safely correct the details and try again.',
                'Reload the page first if the record may have changed since you opened it.',
            ],
            'detail'  => 'Error code: ' . $code,
        ];
    }

    /**
     * "Open the lease/invoice/…" for whichever record the error names.
     *
     * @return array{label:string,url:string,primary:bool}|null
     */
    private static function entityAction(array $extra): ?array
    {
        foreach (self::ENTITY_ROUTES as $key => [$route, $noun]) {
            if (!isset($extra[$key])) {
                continue;
            }
            $id = $extra[$key];
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                continue;   // ids only — never interpolate free text into a URL
            }
            return [
                'label'   => 'Open the ' . $noun,
                'url'     => self::url($route . '?id=' . (int) $id),
                'primary' => false,
            ];
        }
        return null;
    }

    /** base_url() when the app helpers are loaded; a root-relative path otherwise. */
    private static function url(string $path): string
    {
        return function_exists('base_url') ? base_url($path) : '/' . ltrim($path, '/');
    }
}
