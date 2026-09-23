<?php
declare(strict_types=1);

namespace FleetForge\Notifications;

use FleetForge\Email\EmailService;

/**
 * lib/Notifications/CustomerReminders.php
 *
 * Engine + gate for the customer-facing email / reminder system surfaced in
 * Settings → Customer Emails. This is the runtime half of the registry in
 * config/customer_notifications.php: every sender (the customer_reminders cron,
 * the compliance_alerts cron, the "send test" endpoint, and — via
 * mayEmailCustomer() — the dunning letters from the notification_digest cron
 * and the Collections "Generate & Send" endpoint, I22) resolves a reminder
 * type's config, decides who is in scope, and dispatches through HERE so the
 * gating, audience rules, dedup, and logging live in exactly one place.
 *
 * Resolution contract (mirrors cron_enabled()): every getter falls back to the
 * registry `default_*` when the settings row is absent, so a fresh install — or
 * a deploy that lands before the seed migration — behaves exactly as the
 * registry declares. There is no "row missing → send anyway" path for
 * customer-facing email; the registry ships every type OFF.
 *
 * Audience: three per-type modes ('all' | 'selected' | 'all_except') resolved
 * against customer_notification_audience, plus a '*' global suppression list and
 * the per-portal-user opt-out in portal_users.notification_preferences. A
 * customer must clear ALL of these to receive a reminder.
 *
 * Channels: 'email' (Mailer + branded shell), 'in_app' (portal notification),
 * 'sms' (best-effort via SmsClient when a number + provider exist). Email is the
 * canonical dedup channel — dedup/rate-limit read the 'email' notification_log
 * rows so an added in_app row never inflates the overdue cadence counter.
 *
 * @session S-CUSTOMER-NOTIFICATIONS
 * @see     config/customer_notifications.php  (registry / defaults)
 * @depends includes/db.php, includes/functions.php (settings_get),
 *          lib/Email/EmailService.php, lib/Notifications/Mailer.php,
 *          lib/Notifications/NotificationService.php, lib/Notifications/SmsClient.php
 */
final class CustomerReminders
{
    /** Settings group + key prefix for every value this engine reads/writes. */
    public const GROUP  = 'customer_notifications';
    public const PREFIX = 'customer_notifications.';

    /** Cached registry (config/customer_notifications.php). */
    private static ?array $registry = null;

    // ================================================================
    // REGISTRY
    // ================================================================

    /** The reminder-type registry, loaded once. */
    public static function registry(): array
    {
        if (self::$registry === null) {
            $reg = @require FF_ROOT . '/config/customer_notifications.php';
            self::$registry = is_array($reg) ? $reg : [];
        }
        return self::$registry;
    }

    /** Registry metadata for one type, or null if the key is unknown. */
    public static function meta(string $key): ?array
    {
        return self::registry()[$key] ?? null;
    }

    /**
     * available() — is this type usable in THIS deployment?
     * A type with a `requires_table` (e.g. reservations) is hidden/skipped when
     * that table is absent, so we never advertise a reminder we can't source.
     */
    public static function available(string $key): bool
    {
        $meta = self::meta($key);
        if ($meta === null) {
            return false;
        }
        $needs = (string) ($meta['requires_table'] ?? '');
        if ($needs === '') {
            return true;
        }
        try {
            // INFORMATION_SCHEMA check — cheap, and true only for a real table.
            return \db_count(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name = ?",
                [$needs]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    // ================================================================
    // GLOBAL CONFIG
    // ================================================================

    /** Read a global setting (customer_notifications.<field>) with a default. */
    public static function global(string $field, mixed $default = null): mixed
    {
        return \settings_get(self::PREFIX . $field, $default);
    }

    /** Master kill switch. Default ON — individual types still ship OFF. */
    public static function masterEnabled(): bool
    {
        return (string) self::global('master_enabled', '1') === '1';
    }

    /** Honor portal_users.notification_preferences opt-outs? Default yes. */
    public static function respectPortalOptOut(): bool
    {
        return (string) self::global('respect_portal_optout', '1') === '1';
    }

    /** Reply-To for customer reminders, or '' when unset. */
    public static function replyTo(): string
    {
        return trim((string) self::global('reply_to', ''));
    }

    /** Parsed BCC list (operator copy). Accepts comma/semicolon/space-separated. */
    public static function bccList(): array
    {
        $raw = trim((string) self::global('bcc', ''));
        if ($raw === '') {
            return [];
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn($a) => $a !== ''));
    }

    /** Optional extra footer line appended to every reminder body. */
    public static function footerNote(): string
    {
        return trim((string) self::global('footer_note', ''));
    }

    /** Hour (0–23) in company timezone at which scheduled reminders may send. */
    public static function sendHour(): int
    {
        return max(0, min(23, (int) self::global('send_hour', '8')));
    }

    /** Allowed weekdays (lowercase 3-letter). Empty/absent → all days. */
    public static function sendDays(): array
    {
        $raw = (string) self::global('send_days', '');
        if ($raw === '') {
            return ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || empty($decoded)) {
            return ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        }
        return array_map(static fn($d) => strtolower((string) $d), $decoded);
    }

    /**
     * inSendWindow() — should the SCHEDULED cron dispatch at this local time?
     * Gates on send_hour (exact match) and send_days. The compliance cron keeps
     * its own 6 AM schedule; this governs cron/customer_reminders.php only.
     */
    public static function inSendWindow(\DateTimeInterface $localNow): bool
    {
        $hourOk = ((int) $localNow->format('G')) === self::sendHour();
        $dow    = strtolower($localNow->format('D')); // Mon → "mon"
        return $hourOk && in_array($dow, self::sendDays(), true);
    }

    // ================================================================
    // PER-TYPE CONFIG
    // ================================================================

    /** Read a per-type setting (customer_notifications.<key>.<field>). */
    private static function typeSetting(string $key, string $field, mixed $default = null): mixed
    {
        return \settings_get(self::PREFIX . $key . '.' . $field, $default);
    }

    /**
     * config() — fully resolved config for one reminder type.
     * Every field falls back to the registry default; callers get a complete,
     * typed array and never touch the settings table directly.
     *
     * @return array{
     *   key:string, label:string, category:string, description:string,
     *   timing:string, enabled:bool, channels:array, lead_days:int,
     *   offset_days:int, repeat_days:int, max_count:int, send_day:int,
     *   audience_mode:string, template_slug:string, subject:string,
     *   docs:array, dedup_type:string, entity:string, handler:string,
     *   only_with_balance:bool, supports_docs:bool
     * }
     */
    public static function config(string $key): array
    {
        $m = self::meta($key) ?? [];

        // channels / docs are stored as JSON strings; decode with a fallback.
        $channels = self::jsonSetting(
            self::typeSetting($key, 'channels'),
            (array) ($m['default_channels'] ?? ['email'])
        );
        $docsDefault = (array) ($m['docs'] ?? []);
        $docs = self::jsonSetting(self::typeSetting($key, 'docs'), $docsDefault);
        // A saved docs map may still carry slugs retired from the config (mvi,
        // insurance) — keep only the documents the registry currently defines so
        // the settings page never renders a toggle that no sender honours.
        if ($docsDefault) {
            $docs = array_intersect_key($docs + $docsDefault, $docsDefault);
        }

        return [
            'key'           => $key,
            'label'         => (string) ($m['label'] ?? $key),
            'category'      => (string) ($m['category'] ?? 'General'),
            'description'   => (string) ($m['description'] ?? ''),
            'timing'        => (string) ($m['timing'] ?? 'before'),
            'enabled'       => (string) self::typeSetting($key, 'enabled', (string) ($m['default_enabled'] ?? '0')) === '1',
            'channels'      => array_values(array_filter($channels, 'is_string')) ?: ['email'],
            'lead_days'     => (int) self::typeSetting($key, 'lead_days',   (string) ($m['default_lead_days']   ?? 0)),
            'offset_days'   => (int) self::typeSetting($key, 'offset_days', (string) ($m['default_offset_days'] ?? 0)),
            'repeat_days'   => (int) self::typeSetting($key, 'repeat_days', (string) ($m['default_repeat_days'] ?? 0)),
            'max_count'     => (int) self::typeSetting($key, 'max_count',   (string) ($m['default_max_count']   ?? 0)),
            'send_day'      => max(1, min(28, (int) self::typeSetting($key, 'send_day', (string) ($m['default_send_day'] ?? 1)))),
            'audience_mode' => self::normalizeAudienceMode((string) self::typeSetting($key, 'audience_mode', 'all')),
            'template_slug' => (string) self::typeSetting($key, 'template_slug', (string) ($m['template_slug'] ?? '')),
            'subject'       => (string) self::typeSetting($key, 'subject', ''),
            'docs'          => $docs,
            'dedup_type'    => (string) ($m['dedup_type'] ?? ('customer_' . $key)),
            'entity'        => (string) ($m['entity'] ?? 'customer'),
            'handler'       => (string) ($m['handler'] ?? 'customer_reminders'),
            'only_with_balance' => (bool) ($m['only_with_balance'] ?? false),
            'supports_docs'     => (bool) ($m['supports_docs'] ?? false),
        ];
    }

    /** True when master switch AND this type are both enabled. */
    public static function typeEnabled(string $key): bool
    {
        return self::masterEnabled() && self::config($key)['enabled'];
    }

    /** For compliance, the per-document toggle (defaults to on when unset). */
    public static function docEnabled(string $key, string $docSlug): bool
    {
        $docs = self::config($key)['docs'];
        return (bool) ($docs[$docSlug] ?? false);
    }

    // ================================================================
    // AUDIENCE
    // ================================================================

    /** Clamp an arbitrary string to a known audience mode. */
    private static function normalizeAudienceMode(string $mode): string
    {
        return in_array($mode, ['all', 'selected', 'all_except'], true) ? $mode : 'all';
    }

    /**
     * audienceSets() — the include / exclude / global-suppress customer-id sets
     * for a type, fetched in ONE query so a cron loop does not re-hit the DB per
     * customer. Returns empty sets if the table is absent (pre-migration).
     *
     * @return array{include:array<int,bool>, exclude:array<int,bool>, suppressed:array<int,bool>}
     */
    public static function audienceSets(string $key): array
    {
        $sets = ['include' => [], 'exclude' => [], 'suppressed' => []];
        try {
            $rows = \db_select(
                "SELECT reminder_key, customer_id, mode
                   FROM customer_notification_audience
                  WHERE reminder_key IN (?, '*')",
                [$key]
            );
        } catch (\Throwable) {
            return $sets;   // table not created yet
        }
        foreach ($rows as $r) {
            $cid  = (int) $r['customer_id'];
            $mode = (string) $r['mode'];
            if ((string) $r['reminder_key'] === '*') {
                if ($mode === 'exclude') {
                    $sets['suppressed'][$cid] = true;
                }
            } elseif ($mode === 'include') {
                $sets['include'][$cid] = true;
            } elseif ($mode === 'exclude') {
                $sets['exclude'][$cid] = true;
            }
        }
        return $sets;
    }

    /**
     * customerAllowed() — final yes/no for one customer, given the pre-fetched
     * audience sets and the customer's portal-pref JSON. Pure decision (no DB).
     *
     * Order: global suppression → per-type audience mode → portal opt-out.
     */
    public static function customerAllowed(
        string $key,
        int $customerId,
        ?string $portalPrefsJson,
        array $sets,
        ?array $cfg = null
    ): bool {
        // Global do-not-email list always wins.
        if (!empty($sets['suppressed'][$customerId])) {
            return false;
        }

        $cfg  = $cfg ?? self::config($key);
        $mode = $cfg['audience_mode'];
        if ($mode === 'selected' && empty($sets['include'][$customerId])) {
            return false;
        }
        if ($mode === 'all_except' && !empty($sets['exclude'][$customerId])) {
            return false;
        }

        // Per-portal-user soft opt-out (the checkbox in the customer portal).
        if (self::respectPortalOptOut() && $portalPrefsJson !== null && $portalPrefsJson !== '') {
            $prefs = json_decode($portalPrefsJson, true);
            if (is_array($prefs)) {
                $prefKey = self::portalPrefKey($key);
                if (array_key_exists($prefKey, $prefs) && $prefs[$prefKey] === false) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * mayEmailCustomer() — the ONE gate for a customer email that is sent
     * outside cron/customer_reminders.php (I22: dunning letters, both the
     * manual "Generate & Send" and the nightly digest). Before this, those
     * paths called Mailer::send() directly and ignored the Customer Emails
     * master switch and the do-not-email list.
     *
     * Checks, in order (first failure wins, reason is operator-readable):
     *   1. Master switch (customer_notifications.master_enabled).
     *   2. The type's own toggle — only when $requireTypeEnabled (automatic
     *      sends). A manual operator action skips it: the operator IS the
     *      decision, but still may not override the master switch or the
     *      do-not-email list.
     *   3. Global do-not-email list ('*' exclude rows) — always.
     *   4. Per-type audience + portal opt-out (customerAllowed) — automatic
     *      sends only, for the same reason as 2.
     *   5. Recipient: invoice_email → billing_email → email (the same
     *      precedence invoices use); the main email is skipped when
     *      customers.email_disabled=1 (hard bounce / complaint).
     *
     * @param  string $key                Registry key (e.g. 'dunning').
     * @param  int    $customerId         customers.id
     * @param  bool   $requireTypeEnabled true for automatic/cron sends.
     * @return array{ok:bool, reason:string, to:?string, to_name:string}
     */
    public static function mayEmailCustomer(string $key, int $customerId, bool $requireTypeEnabled): array
    {
        $deny = static fn(string $reason): array => ['ok' => false, 'reason' => $reason, 'to' => null, 'to_name' => ''];

        if (!self::masterEnabled()) {
            return $deny('Customer emails are switched off (Settings → Customer Emails master switch).');
        }
        if ($requireTypeEnabled && !self::typeEnabled($key)) {
            $label = (string) (self::meta($key)['label'] ?? $key);
            return $deny("\"{$label}\" emails are switched off in Settings → Customer Emails.");
        }

        $customer = \db_row(
            "SELECT c.id, c.company_name, c.contact_name, c.billing_contact_name,
                    c.email, c.billing_email, c.invoice_email, c.email_disabled,
                    pu.notification_preferences
               FROM customers c
               LEFT JOIN portal_users pu
                      ON pu.customer_id = c.id AND pu.is_primary = 1 AND pu.status = 'active'
              WHERE c.id = ? AND c.deleted_at IS NULL
              LIMIT 1",
            [$customerId]
        );
        if ($customer === null) {
            return $deny('Customer not found.');
        }

        // Global do-not-email list applies to EVERY send, manual included.
        $sets = self::audienceSets($key);
        if (!empty($sets['suppressed'][$customerId])) {
            return $deny('Customer is on the do-not-email list (Settings → Customer Emails).');
        }
        // Per-type audience + portal opt-out: automatic sends only. The global
        // list was checked above, so a false here is audience/opt-out.
        if ($requireTypeEnabled && !self::customerAllowed(
            $key, $customerId, $customer['notification_preferences'] ?? null, $sets
        )) {
            return $deny('Customer is outside this email\'s audience or opted out in the portal.');
        }

        // Recipient — first VALID address wins; a bounced main address is skipped.
        $mainDisabled = (int) ($customer['email_disabled'] ?? 0) === 1;
        $to = null;
        foreach (['invoice_email', 'billing_email', 'email'] as $col) {
            $candidate = trim((string) ($customer[$col] ?? ''));
            if ($col === 'email' && $mainDisabled) {
                continue;
            }
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                $to = $candidate;
                break;
            }
        }
        if ($to === null) {
            return $deny($mainDisabled && trim((string) ($customer['email'] ?? '')) !== ''
                ? 'The customer\'s email address is disabled after a bounce or complaint, and no invoice/billing email is set.'
                : 'No valid email address on file (invoice, billing or main email).');
        }

        $name = trim((string) ($customer['billing_contact_name'] ?? ''))
            ?: trim((string) ($customer['contact_name'] ?? ''))
            ?: trim((string) ($customer['company_name'] ?? ''));

        return ['ok' => true, 'reason' => '', 'to' => $to, 'to_name' => $name];
    }

    /**
     * portalPrefKey() — map a reminder key to the portal-preference key.
     * compliance_expiry keeps the legacy 'compliance_expiring' key already shown
     * in the portal UI; every other type uses its own registry key.
     */
    public static function portalPrefKey(string $key): string
    {
        return $key === 'compliance_expiry' ? 'compliance_expiring' : $key;
    }

    // ================================================================
    // DEDUP / RATE-LIMIT (reads notification_log)
    // ================================================================

    /**
     * recentlyLogged() — has a reminder of this type for this entity been
     * logged within $intervalExpr (a MySQL interval, e.g. '24 HOUR')? Any status
     * counts, so a failed attempt also rate-limits retries.
     */
    public static function recentlyLogged(
        string $dedupType,
        string $entityType,
        ?int $entityId,
        string $intervalExpr,
        string $channel = 'email'
    ): bool {
        try {
            $sql = "SELECT id FROM notification_log
                     WHERE notification_type = ?
                       AND channel = ?
                       AND entity_type = ?
                       AND " . ($entityId === null ? 'entity_id IS NULL' : 'entity_id = ?') . "
                       AND created_at >= NOW() - INTERVAL {$intervalExpr}
                     LIMIT 1";
            $params = $entityId === null
                ? [$dedupType, $channel, $entityType]
                : [$dedupType, $channel, $entityType, $entityId];
            return \db_row($sql, $params) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * sentCount() — how many times this reminder was SUCCESSFULLY sent for this
     * entity (status='sent'). Drives the overdue-cadence cap (max_count) and the
     * "send one-shot reminders once" rule.
     */
    public static function sentCount(
        string $dedupType,
        string $entityType,
        ?int $entityId,
        string $channel = 'email'
    ): int {
        try {
            $sql = "SELECT COUNT(*) FROM notification_log
                     WHERE notification_type = ?
                       AND channel = ?
                       AND entity_type = ?
                       AND " . ($entityId === null ? 'entity_id IS NULL' : 'entity_id = ?') . "
                       AND status = 'sent'";
            $params = $entityId === null
                ? [$dedupType, $channel, $entityType]
                : [$dedupType, $channel, $entityType, $entityId];
            return \db_count($sql, $params);
        } catch (\Throwable) {
            return 0;
        }
    }

    // ================================================================
    // DELIVERY
    // ================================================================

    /**
     * deliver() — send ONE reminder to ONE customer across the configured
     * channels, writing a notification_log row per channel attempted.
     *
     * The caller has already resolved the recipient, decided the customer is in
     * scope (customerAllowed) and passed the dedup gate. This method does the
     * channel fan-out, the branded HTML wrap, reply-to/bcc plumbing, and audit
     * logging — nothing customer-selection related.
     *
     * @param array $a {
     *   reminder_key:string, customer_id:int, dedup_type:string,
     *   entity_type:string, entity_id:?int, channels:string[],
     *   to_email:string, to_name:string, subject:string, body_html:string,
     *   log_summary?:string,
     *   raw_html?:bool,   // true = body_html is already a complete letter
     *                     // (e.g. a dunning letter with its own letterhead);
     *                     // skip the branded EmailService shell.
     *   in_app?:array{type:string,title:string,message:string,url:string,severity?:string},
     *   sms?:array{phone:string,body:string}
     * }
     * @return array{email:?bool, in_app:?bool, sms:?bool}
     */
    public static function deliver(array $a): array
    {
        $result   = ['email' => null, 'in_app' => null, 'sms' => null];
        $channels = (array) ($a['channels'] ?? ['email']);
        $dedup    = (string) ($a['dedup_type'] ?? '');
        $etype    = (string) ($a['entity_type'] ?? 'customer');
        $eid      = isset($a['entity_id']) ? (int) $a['entity_id'] : null;
        $summary  = mb_substr((string) ($a['log_summary'] ?? strip_tags((string) ($a['body_html'] ?? ''))), 0, 2000);

        // ── EMAIL ──────────────────────────────────────────────────
        if (in_array('email', $channels, true)) {
            $toEmail = (string) ($a['to_email'] ?? '');
            $subject = (string) ($a['subject'] ?? '');
            $bodyFrag = (string) ($a['body_html'] ?? '');
            $note = self::footerNote();
            if ($note !== '') {
                $bodyFrag .= '<p style="margin:16px 0 0;font-size:12px;color:#9ca3af;">'
                    . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>';
            }

            $sent = false;
            $err  = null;
            if (filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false) {
                $err = 'No deliverable email address.';
            } else {
                // I22: a dunning letter carries its own letterhead + styles;
                // wrapping it in the branded shell would double the header.
                $wrapped = !empty($a['raw_html']) ? $bodyFrag : EmailService::renderEmailHtml($bodyFrag);
                $replyTo = self::replyTo();
                $replyToArr = ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL))
                    ? [['email' => $replyTo, 'name' => '']]
                    : [];
                try {
                    $sent = Mailer::send(
                        toEmail:  $toEmail,
                        toName:   (string) ($a['to_name'] ?? ''),
                        subject:  $subject,
                        htmlBody: $wrapped,
                        textBody: '',
                        replyTo:  $replyToArr,
                        attachments: [],
                        bcc:      self::bccList(),
                    );
                } catch (\Throwable $e) {
                    $err = $e->getMessage();
                    error_log('[CustomerReminders] email send threw: ' . $err);
                }
                if (!$sent && $err === null) {
                    $err = 'Mailer::send() returned false (see logs/mail.log or email_disabled).';
                }
            }

            self::logRow('email', $toEmail, (string) ($a['subject'] ?? ''), $summary, $etype, $eid, $dedup, $sent ? 'sent' : 'failed', $sent ? null : $err);
            $result['email'] = $sent;
        }

        // ── IN-APP (portal notification) ───────────────────────────
        if (in_array('in_app', $channels, true) && !empty($a['in_app']) && (int) ($a['customer_id'] ?? 0) > 0) {
            $ia = $a['in_app'];
            try {
                NotificationService::notifyPortal(
                    (string) ($ia['type'] ?? ('reminder.' . ($a['reminder_key'] ?? 'customer'))),
                    (int) $a['customer_id'],
                    (string) ($ia['title'] ?? ''),
                    (string) ($ia['message'] ?? ''),
                    $etype,
                    $eid,
                    (string) ($ia['url'] ?? ''),
                    (string) ($ia['severity'] ?? 'info')
                );
                self::logRow('in_app', 'portal:customer:' . (int) $a['customer_id'], (string) ($ia['title'] ?? ''), $summary, $etype, $eid, $dedup, 'sent', null);
                $result['in_app'] = true;
            } catch (\Throwable $e) {
                self::logRow('in_app', 'portal:customer:' . (int) $a['customer_id'], (string) ($ia['title'] ?? ''), $summary, $etype, $eid, $dedup, 'failed', $e->getMessage());
                $result['in_app'] = false;
            }
        }

        // ── SMS (best-effort) ──────────────────────────────────────
        if (in_array('sms', $channels, true) && !empty($a['sms']['phone']) && !empty($a['sms']['body'])) {
            $phone = (string) $a['sms']['phone'];
            $body  = (string) $a['sms']['body'];
            $ok = false;
            $err = null;
            try {
                $res = SmsClient::send($phone, $body);
                $ok = is_array($res) && (
                    ($res['success'] ?? false) === true
                    || ($res['ok'] ?? false) === true
                    || !empty($res['sid'])
                    || in_array((string) ($res['status'] ?? ''), ['sent', 'queued', 'delivered'], true)
                );
                if (!$ok) {
                    $err = (string) ($res['error'] ?? 'SMS not sent.');
                }
            } catch (\Throwable $e) {
                $err = $e->getMessage();
            }
            self::logRow('sms', $phone, mb_substr($body, 0, 200), $summary, $etype, $eid, $dedup, $ok ? 'sent' : 'failed', $ok ? null : $err);
            $result['sms'] = $ok;
        }

        return $result;
    }

    /** Insert one notification_log row (never throws — logging is best-effort). */
    private static function logRow(
        string $channel,
        string $recipient,
        string $subject,
        string $body,
        string $entityType,
        ?int $entityId,
        string $dedupType,
        string $status,
        ?string $error
    ): void {
        try {
            \db_insert('notification_log', [
                'rule_id'           => null,
                'channel'           => $channel,
                'recipient'         => mb_substr($recipient, 0, 255),
                'subject'           => mb_substr($subject, 0, 500),
                'body'              => $body,
                'entity_type'       => $entityType,
                'entity_id'         => $entityId,
                'notification_type' => $dedupType,
                'status'            => $status,
                'error_message'     => $error,
                // S-LOCAL-DAY-TS: UTC like the DB-defaulted created_at (was Pacific wall time).
                'sent_at'           => $status === 'sent' ? \ff_now_utc() : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[CustomerReminders] notification_log insert failed: ' . $e->getMessage());
        }
    }

    /** Decode a JSON settings value to an array, falling back on any error. */
    private static function jsonSetting(mixed $raw, array $default): array
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : $default;
    }
}
