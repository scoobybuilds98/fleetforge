<?php
declare(strict_types=1);

/**
 * lib/Notifications/WhatsApp/WhatsAppDeliveries.php
 *
 * Who gets what on WhatsApp, and when (S-ATTENTION-WHATSAPP).
 *
 * Only a few things ever reach a phone — that's the point:
 *   alert       an item opens / reopens / becomes urgent in a kind set to
 *               "Right away" (Settings → Notifications). For kinds whose
 *               urgency is decided per item (unit documents, overdue
 *               customers, tax), "Right away" means the URGENT ones; the rest
 *               wait for the summary. Sent to everyone who can see the item
 *               and chose "Urgent + morning summary".
 *   escalation  an urgent item nobody took within the escalation window —
 *               to super admins (anyone who didn't switch WhatsApp off).
 *   summary     one message per person per day at their hour: urgent / to-do
 *               counts, the most urgent item, yesterday's activity.
 *   update      an update type the person ticked (e.g. every new lease).
 *
 * Every message is QUEUED (notification_deliveries) — often from inside a
 * business transaction, where an HTTP call would hold locks and could fail
 * the save — and sent by cron/whatsapp_dispatch.php (every minute) via
 * dispatchDue(). dedupe_key (UNIQUE) = send once per person per event.
 *
 * Quiet hours (per person, default 21:00–07:00 in their timezone) push a
 * due message to the end of the window. An alert whose item was dealt with
 * before it went out is skipped, not sent late.
 *
 * Money: facts marked as money are dropped for recipients without
 * payments:view, resolved from the DB (no session in cron) — fail closed.
 *
 * Required by: lib/Attention/AttentionService.php (fire), lib/Notifications/
 *              NotificationService.php (updates), cron/attention_sweep.php
 *              (summaries), cron/whatsapp_dispatch.php, api/v1/webhooks/whatsapp.php
 * Defines:     FleetForge\Notifications\WhatsApp\WhatsAppDeliveries
 *
 * @session S-ATTENTION-WHATSAPP
 */

namespace FleetForge\Notifications\WhatsApp;

use FleetForge\Attention\AttentionService;
use FleetForge\Attention\KindRegistry;
use FleetForge\Attention\Kinds\Kind;

final class WhatsAppDeliveries
{
    /** Update types a person can also get instantly (Profile → Notifications). */
    public const UPDATE_CHOICES = [
        'lease.created'       => 'New leases',
        'lease.activated'     => 'Leases going out',
        'lease.closed'        => 'Leases coming back',
        'invoice.sent'        => 'Invoices sent',
        'payment.received'    => 'Payments received',
        'customer.created'    => 'New customers',
        'reservation.created' => 'New reservations',
    ];

    /** Retry delays in minutes, by attempt number (1-based). */
    private const BACKOFF_MIN = [1 => 1, 2 => 5, 3 => 30, 4 => 120];

    /** Give up after this many attempts. */
    public const MAX_ATTEMPTS = 5;

    /** Delivery status rank (webhook callbacks can arrive out of order). */
    private const STATUS_RANK = ['queued' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5, 'skipped' => 5];

    /** @var array<int, array>|null Per-request cache of eligible people. */
    private static ?array $people = null;

    // ────────────────────────────────────────────────────────────────────────
    // People
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Active staff who turned WhatsApp on and have a number, keyed by id.
     *
     * @param  bool $fresh  Bypass the per-request cache
     * @return array<int, array>
     */
    public static function people(bool $fresh = false): array
    {
        if (self::$people !== null && !$fresh) {
            return self::$people;
        }
        $rows = \db_select(
            "SELECT u.id, u.name, u.role_id, u.phone_e164, u.timezone, u.whatsapp_mode, u.whatsapp_summary_hour,
                    u.whatsapp_quiet_start, u.whatsapp_quiet_end, u.whatsapp_updates, r.slug AS role_slug
               FROM users u JOIN user_roles r ON r.id = u.role_id
              WHERE u.status = 'active' AND u.deleted_at IS NULL
                AND u.whatsapp_mode <> 'off' AND u.phone_e164 IS NOT NULL AND u.phone_e164 <> ''"
        );
        self::$people = [];
        foreach ($rows as $r) {
            if (WhatsAppClient::toWaId((string) $r['phone_e164']) !== null) {
                self::$people[(int) $r['id']] = $r;
            }
        }
        return self::$people;
    }

    /**
     * Drop cached people (tests, and after a person changes their settings).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$people = null;
    }

    /**
     * Can this person see money? Mirrors can('payments','view') without a
     * session: super_admin → user override → role override → factory
     * default. Fails CLOSED (a phone is the wrong place to guess).
     *
     * @param  array $u  people() row
     * @return bool
     */
    public static function canSeeMoney(array $u): bool
    {
        try {
            if (($u['role_slug'] ?? '') === 'super_admin') {
                return true;
            }
            $o = \db_row(
                "SELECT granted FROM user_permission_overrides WHERE user_id = ? AND module = 'payments' AND action = 'view'",
                [(int) $u['id']]
            );
            if ($o !== null) {
                return (int) $o['granted'] === 1;
            }
            $ro = \db_row(
                "SELECT granted FROM role_permission_overrides WHERE role_id = ? AND module = 'payments' AND action = 'view'",
                [(int) $u['role_id']]
            );
            if ($ro !== null) {
                return (int) $ro['granted'] === 1;
            }
            $perm = require FF_ROOT . '/config/permissions.php';
            return (bool) ($perm[$u['role_slug']]['payments']['view'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    // Events → queue
    // ────────────────────────────────────────────────────────────────────────

    /**
     * AttentionService listener: queue alerts / escalations. Never throws.
     *
     * @param  string $event  opened | reopened | urgent | escalated
     * @param  array  $row    attention_items row
     * @param  Kind   $kind
     * @return void
     */
    public static function onAttentionEvent(string $event, array $row, Kind $kind): void
    {
        try {
            if (!WhatsAppClient::configured() || empty($row['id'])) {
                return;
            }
            $mode = KindRegistry::whatsappFor($kind);
            if ($mode === 'off') {
                return;
            }
            $id = (int) $row['id'];

            if ($event === 'escalated') {
                $hours = max(1, (int) \settings_get('notifications.escalate_after_hours', '24'));
                foreach (self::people() as $u) {
                    if ($u['role_slug'] !== 'super_admin') {
                        continue;
                    }
                    self::queueItem($u, 'escalation', 'Escalated, nobody has taken it in ' . $hours . 'h', $row,
                        'esc:' . $id . ':' . ($row['escalated_at'] ?? '') . ':' . $u['id']);
                }
                return;
            }

            if (!in_array($event, ['opened', 'reopened', 'urgent'], true)) {
                return;
            }
            // "Right away" = urgent items; for kinds with a fixed priority it
            // means every item (an operator chose instant for a to-do kind).
            $instant = $mode === 'now' && ($row['priority'] === 'urgent' || !$kind->dynamicPriority());
            if (!$instant) {
                return;
            }
            $label = $row['priority'] === 'urgent' ? 'Urgent' : 'To do';
            foreach (self::people() as $u) {
                if ($u['whatsapp_mode'] !== 'urgent') {
                    continue;
                }
                if (AttentionService::findFor($id, (int) $u['id'], (string) $u['role_slug']) === null) {
                    continue;
                }
                self::queueItem($u, 'alert', $label, $row,
                    'alert:' . $id . ':' . $event . ':' . ($row['urgent_since'] ?? $row['first_seen_at'] ?? '') . ':' . $u['id']);
            }
        } catch (\Throwable $e) {
            error_log('[WhatsApp] onAttentionEvent failed: ' . $e->getMessage());
        }
    }

    /**
     * NotificationService hook: a NEW update row was written for a person
     * (a burst folding into an existing row doesn't message again). Queues a
     * message only if they ticked this update type. Never throws.
     *
     * @param  int      $userId
     * @param  string   $type
     * @param  string   $title
     * @param  string   $message
     * @param  string   $url
     * @param  int|null $notificationId
     * @return void
     */
    public static function onUpdate(int $userId, string $type, string $title, string $message, string $url, ?int $notificationId): void
    {
        try {
            if (!isset(self::UPDATE_CHOICES[$type]) || !WhatsAppClient::configured()) {
                return;
            }
            $u = self::people()[$userId] ?? null;
            if ($u === null) {
                return;
            }
            $wanted = json_decode((string) ($u['whatsapp_updates'] ?? ''), true);
            if (!is_array($wanted) || !in_array($type, $wanted, true)) {
                return;
            }
            $money = self::canSeeMoney($u);
            $t = $money ? $title : \ff_scrub_money_text($title);
            $m = $money ? $message : \ff_scrub_money_text($message);
            $m = trim((string) (preg_split('/\R/', $m)[0] ?? ''));
            $params = ['Update', $t, $m !== '' ? $m : '—', self::absoluteUrl($url)];
            self::enqueue($u, 'update', self::template('alert'), $params, 'Update: ' . $t . ($m !== '' ? ' — ' . $m : ''),
                'update:' . ($notificationId ?? md5($type . $title . microtime())) . ':' . $userId, null, $notificationId);
        } catch (\Throwable $e) {
            error_log('[WhatsApp] onUpdate failed: ' . $e->getMessage());
        }
    }

    /**
     * Queue today's morning summary for everyone whose summary hour is now
     * (their timezone). Called hourly by cron/attention_sweep.php; the
     * dedupe key makes a second call the same day a no-op.
     *
     * @param  int|null $onlyUserId  Queue for this person regardless of the hour (tests / "send me one now")
     * @return int                   Summaries queued
     */
    public static function enqueueSummaries(?int $onlyUserId = null): int
    {
        if (!WhatsAppClient::configured()) {
            return 0;
        }
        $n = 0;
        foreach (self::people() as $u) {
            if ($onlyUserId !== null && (int) $u['id'] !== $onlyUserId) {
                continue;
            }
            $tz    = self::tz($u);
            $local = new \DateTimeImmutable('now', $tz);
            $hour  = $u['whatsapp_summary_hour'] === null ? 7 : (int) $u['whatsapp_summary_hour'];
            if ($onlyUserId === null && (int) $local->format('G') !== $hour) {
                continue;
            }
            $summary = self::buildSummary($u, $local);
            if ($summary === null) {
                continue; // nothing open and nothing happened — no "all clear" noise
            }
            if (self::enqueue($u, 'summary', self::template('summary'), $summary['params'], $summary['preview'],
                'summary:' . $local->format('Y-m-d') . ':' . $u['id'] . ($onlyUserId !== null ? ':now' . time() : ''))) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * The summary parameters for one person, or null when there's nothing to say.
     *
     * @param  array              $u
     * @param  \DateTimeImmutable $local  Now, in the person's timezone
     * @return array{params: string[], preview: string}|null
     */
    public static function buildSummary(array $u, \DateTimeImmutable $local): ?array
    {
        $uid    = (int) $u['id'];
        $role   = (string) $u['role_slug'];
        $counts = AttentionService::counts($uid, $role);
        $urgent = $counts['urgent'];
        $todo   = $counts['total'] - $counts['urgent'];

        $top = 'Nothing urgent';
        if ($counts['total'] > 0) {
            $first = AttentionService::listFor($uid, $role, ['limit' => 1])['items'][0] ?? null;
            if ($first !== null) {
                $top = (string) $first['title'] . ($first['priority'] === 'urgent' ? '' : ' (to do)');
            }
        }

        // Yesterday = the person's own Updates feed for their local yesterday.
        $today = $local->setTime(0, 0);
        $from  = $today->modify('-1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to    = $today->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $byType = [];
        foreach (\db_select(
            'SELECT type, SUM(group_count) AS n FROM notifications
              WHERE user_id = ? AND deleted_at IS NULL AND created_at >= ? AND created_at < ?
              GROUP BY type',
            [$uid, $from, $to]
        ) as $r) {
            $byType[(string) $r['type']] = (int) $r['n'];
        }
        $phrases = [
            'lease.activated'  => ['lease out', 'leases out'],
            'lease.closed'     => ['lease back', 'leases back'],
            'lease.created'    => ['lease created', 'leases created'],
            'invoice.created'  => ['invoice created', 'invoices created'],
            'invoice.sent'     => ['invoice sent', 'invoices sent'],
            'payment.received' => ['payment received', 'payments received'],
            'customer.created' => ['new customer', 'new customers'],
        ];
        $parts = [];
        foreach ($phrases as $type => [$one, $many]) {
            $c = $byType[$type] ?? 0;
            if ($c > 0) {
                $parts[] = $c . ' ' . ($c === 1 ? $one : $many);
            }
        }
        $yesterday = $parts ? implode(', ', array_slice($parts, 0, 5)) : 'No activity';

        if ($counts['total'] === 0 && !$parts) {
            return null;
        }

        $params = [(string) $urgent, (string) $todo, $top, $yesterday, self::absoluteUrl('/fleetforge/notifications')];
        return [
            'params'  => $params,
            'preview' => "Morning summary: {$urgent} urgent, {$todo} to do. Most urgent: {$top}. Yesterday: {$yesterday}.",
        ];
    }

    // ────────────────────────────────────────────────────────────────────────
    // Queue → Meta
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Send what's due. Called every minute by cron/whatsapp_dispatch.php.
     *
     * @param  int $limit
     * @return array{sent: int, failed: int, retry: int, deferred: int, skipped: int}
     */
    public static function dispatchDue(int $limit = 50): array
    {
        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'deferred' => 0, 'skipped' => 0];

        // Switched off (or never connected): drop what's waiting rather than
        // blasting stale messages the day it's turned back on.
        if (!WhatsAppClient::configured()) {
            $stats['skipped'] = \db_execute(
                "UPDATE notification_deliveries SET status = 'skipped', error = 'WhatsApp is switched off'
                  WHERE status = 'queued'"
            );
            return $stats;
        }

        self::reset();
        $rows = \db_select(
            "SELECT * FROM notification_deliveries
              WHERE status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP()
              ORDER BY id ASC LIMIT " . max(1, min(200, $limit))
        );
        foreach ($rows as $d) {
            // Claim it: a second dispatcher run (or a slow previous one) can't
            // send the same message twice.
            $claimed = \db_execute(
                "UPDATE notification_deliveries SET status = 'sending' WHERE id = ? AND status = 'queued'",
                [(int) $d['id']]
            );
            if ($claimed !== 1) {
                continue;
            }
            $stats[self::sendOne($d)]++;
        }
        return $stats;
    }

    /**
     * Send one claimed delivery.
     *
     * @param  array $d  notification_deliveries row
     * @return string    sent | failed | retry | deferred | skipped
     */
    private static function sendOne(array $d): string
    {
        $id = (int) $d['id'];
        $u  = self::people()[(int) $d['user_id']] ?? null;

        if ($u === null) {
            self::finish($id, 'skipped', 'The person turned WhatsApp off, removed their number or left');
            return 'skipped';
        }
        if ($d['purpose'] === 'alert' && $d['attention_item_id'] !== null) {
            $item = AttentionService::row((int) $d['attention_item_id']);
            if ($item === null || $item['status'] !== 'open') {
                self::finish($id, 'skipped', 'Already dealt with before it was sent');
                return 'skipped';
            }
        }
        if ($d['purpose'] !== 'test') {
            $until = self::quietUntil($u);
            if ($until !== null) {
                \db_update('notification_deliveries', ['status' => 'queued', 'next_attempt_at' => $until], 'id = ?', [$id]);
                return 'deferred';
            }
        }

        $params = json_decode((string) $d['params'], true) ?: [];
        $res    = WhatsAppClient::sendTemplate((string) $u['phone_e164'], (string) $d['template'], $params);
        $tries  = (int) $d['attempts'] + 1;

        if ($res['ok']) {
            \db_update('notification_deliveries', [
                'status'              => 'sent',
                'attempts'            => $tries,
                'to_phone'            => (string) $u['phone_e164'],
                'provider_message_id' => $res['id'] ?? null,
                'sent_at'             => \ff_now_utc(),
                'error'               => null,
            ], 'id = ?', [$id]);
            return 'sent';
        }
        if (!empty($res['retryable']) && $tries < self::MAX_ATTEMPTS) {
            \db_update('notification_deliveries', [
                'status'          => 'queued',
                'attempts'        => $tries,
                'next_attempt_at' => \ff_now_utc('+' . (self::BACKOFF_MIN[$tries] ?? 360) . ' minutes'),
                'error'           => mb_substr((string) ($res['error'] ?? 'Temporary error'), 0, 500),
            ], 'id = ?', [$id]);
            return 'retry';
        }
        \db_update('notification_deliveries', [
            'status'   => 'failed',
            'attempts' => $tries,
            'error'    => mb_substr((string) ($res['error'] ?? 'Failed'), 0, 500),
        ], 'id = ?', [$id]);
        return 'failed';
    }

    /**
     * Apply a status callback from Meta's webhook (never moves backwards).
     *
     * @param  string      $wamid
     * @param  string      $status  sent | delivered | read | failed
     * @param  string|null $error
     * @return bool        True when a delivery was updated
     */
    public static function applyStatus(string $wamid, string $status, ?string $error = null): bool
    {
        if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
            return false;
        }
        $d = \db_row('SELECT id, status FROM notification_deliveries WHERE provider_message_id = ?', [$wamid]);
        if ($d === null) {
            return false;
        }
        if ((self::STATUS_RANK[$status] ?? 0) <= (self::STATUS_RANK[$d['status']] ?? 0) && $status !== 'failed') {
            return false;
        }
        $upd = ['status' => $status];
        if ($status === 'failed') {
            $upd['error'] = mb_substr((string) ($error ?? 'WhatsApp reported the message failed'), 0, 500);
        }
        \db_update('notification_deliveries', $upd, 'id = ?', [(int) $d['id']]);
        return true;
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Queue an alert/escalation for one attention item, with facts filtered
     * for this recipient's money visibility.
     *
     * @param  array  $u
     * @param  string $purpose  alert | escalation
     * @param  string $label
     * @param  array  $row      attention_items row
     * @param  string $dedupe
     * @return void
     */
    private static function queueItem(array $u, string $purpose, string $label, array $row, string $dedupe): void
    {
        $money = self::canSeeMoney($u);
        $facts = [];
        foreach (json_decode((string) ($row['facts'] ?? '[]'), true) ?: [] as $f) {
            if (is_array($f) && isset($f['t']) && (empty($f['m']) || $money)) {
                $facts[] = (string) $f['t'];
            }
        }
        $details = $facts ? implode(' · ', $facts) : '—';
        $params  = [$label, (string) $row['title'], $details, self::absoluteUrl((string) ($row['url'] ?? '/fleetforge/notifications'))];
        self::enqueue($u, $purpose, self::template('alert'), $params,
            "FleetForge alert ({$label}): {$row['title']} — {$details}", $dedupe, (int) $row['id']);
    }

    /**
     * Insert one queued delivery; a duplicate dedupe key is "already queued".
     *
     * @param  array       $u
     * @param  string      $purpose
     * @param  string      $template
     * @param  string[]    $params
     * @param  string      $preview
     * @param  string|null $dedupe
     * @param  int|null    $itemId
     * @param  int|null    $notificationId
     * @return bool        True when newly queued
     */
    private static function enqueue(array $u, string $purpose, string $template, array $params, string $preview,
                                    ?string $dedupe, ?int $itemId = null, ?int $notificationId = null): bool
    {
        try {
            \db_insert('notification_deliveries', [
                'user_id'           => (int) $u['id'],
                'to_phone'          => (string) $u['phone_e164'],
                'purpose'           => $purpose,
                'attention_item_id' => $itemId,
                'notification_id'   => $notificationId,
                'dedupe_key'        => $dedupe !== null ? mb_substr($dedupe, 0, 191) : null,
                'template'          => $template,
                'params'            => json_encode(array_map(static fn($p) => WhatsAppClient::cleanParam((string) $p), array_values($params)), JSON_UNESCAPED_UNICODE),
                'preview'           => mb_substr($preview, 0, 1000),
                'status'            => 'queued',
                'next_attempt_at'   => \ff_now_utc(),
            ]);
            return true;
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000' && str_contains($e->getMessage(), '1062')) {
                return false; // already queued — send once
            }
            throw $e;
        }
    }

    /**
     * Close a claimed delivery without sending.
     *
     * @param  int    $id
     * @param  string $status
     * @param  string $why
     * @return void
     */
    private static function finish(int $id, string $status, string $why): void
    {
        \db_update('notification_deliveries', ['status' => $status, 'error' => $why], 'id = ?', [$id]);
    }

    /**
     * If it's quiet time for this person now, the UTC moment it ends.
     *
     * @param  array                   $u
     * @param  \DateTimeImmutable|null $nowUtc  (tests)
     * @return string|null  UTC 'Y-m-d H:i:s', or null when not quiet
     */
    public static function quietUntil(array $u, ?\DateTimeImmutable $nowUtc = null): ?string
    {
        $start = $u['whatsapp_quiet_start'] === null ? 21 : (int) $u['whatsapp_quiet_start'];
        $end   = $u['whatsapp_quiet_end'] === null ? 7 : (int) $u['whatsapp_quiet_end'];
        if ($start === $end) {
            return null;
        }
        $local = ($nowUtc ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(self::tz($u));
        $h     = (int) $local->format('G');
        $quiet = $start < $end ? ($h >= $start && $h < $end) : ($h >= $start || $h < $end);
        if (!$quiet) {
            return null;
        }
        $endToday = $local->setTime($end, 0);
        $at = $endToday > $local ? $endToday : $endToday->modify('+1 day');
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * The person's timezone (their profile), else the business timezone.
     *
     * @param  array $u
     * @return \DateTimeZone
     */
    private static function tz(array $u): \DateTimeZone
    {
        $name = trim((string) ($u['timezone'] ?? ''));
        if ($name !== '') {
            try {
                return new \DateTimeZone($name);
            } catch (\Throwable) {
                // fall through
            }
        }
        return \ff_business_timezone();
    }

    /**
     * Template name for a purpose (settings).
     *
     * @param  string $which alert | summary
     * @return string
     */
    public static function template(string $which): string
    {
        return $which === 'summary'
            ? (trim((string) \settings_get('whatsapp.template_summary', 'fleetforge_summary')) ?: 'fleetforge_summary')
            : (trim((string) \settings_get('whatsapp.template_alert', 'fleetforge_alert')) ?: 'fleetforge_alert');
    }

    /**
     * Absolute link for a root-relative app URL ('/fleetforge/...').
     *
     * @param  string|null $url
     * @return string
     */
    public static function absoluteUrl(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '' || $url === '#') {
            return \base_url('notifications');
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return rtrim((string) APP_URL, '/') . '/' . ltrim($url, '/');
    }
}
