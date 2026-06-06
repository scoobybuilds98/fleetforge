<?php
declare(strict_types=1);

/**
 * api/v1/admin/intelligence/send_brief_now.php
 *
 * Operator's "Send to all recipients now" button (Intelligence settings tab →
 * Morning Briefing section).
 *
 * Sends today's cached brief to EVERY current recipient immediately, instead of
 * waiting for the scheduled digest hour. Reuses the exact production dispatch —
 * cron/notification_digest.php's run_morning_digest_emails() — so there is ONE
 * code path for "who gets the brief and how it's rendered/sent". The only
 * difference from the cron is that the per-user hour gate is bypassed
 * ($GLOBALS['ff_force'] = true): the operator clicked send, so every opted-in
 * allow-list recipient is in scope regardless of their preferred briefing_hour.
 *
 * IMPORTANT — what is and isn't bypassed (S-INTEL-BRIEF-SEND-NOW):
 *   - Hour gate:   BYPASSED (force) — send now, any hour.
 *   - Role gate:   ENFORCED — only ai.briefing_recipient_roles slugs.
 *   - Opt-in gate: ENFORCED — only morning_briefing_opt_in = 1.
 *   - Snooze gate: ENFORCED — snoozed users still skipped.
 *   - 23h dedup:   ENFORCED — a user who already got today's digest is skipped
 *                  (prevents an accidental double full-send). Use "Test send"
 *                  to re-send to yourself for testing.
 *
 * Distinct from test_briefing.php (sends only to the CURRENT user). This emails
 * the real recipient list, so it is gated the same as editing the settings.
 *
 * @method   POST
 * @auth     require_permission('settings','edit')
 * @returns  200 { sent, skipped, errors, recipient_count, message }
 *           422 if AI/briefing disabled or no cached brief
 *
 * @session  S-INTEL-BRIEF-SEND-NOW
 */

require_once dirname(__DIR__, 4) . '/api/bootstrap.php';

require_method('POST');
require_auth_api();
require_permission('settings', 'edit');

use FleetForge\Notifications\MorningBriefingRenderer;

try {
    // ── Gate: master AI + briefing toggles (mirror test_briefing.php). ──
    if ((string) settings_get('ai.enabled', '1') !== '1') {
        json_error('AI_DISABLED', 'AI Features are disabled (ai.enabled=0). Turn on the master switch in AI Core before sending.', 422);
    }
    if ((string) settings_get('ai.briefing_enabled', '1') !== '1') {
        json_error('BRIEFING_DISABLED', 'Morning briefing is disabled (ai.briefing_enabled=0). Toggle it on before sending.', 422);
    }

    // ── Gate: a cached brief must exist (same as test_briefing.php). The
    //    dispatch renders from the cached brief; without one there is nothing
    //    to send. Operator should "Generate brief now" first. ──
    if (!MorningBriefingRenderer::hasCachedBrief()) {
        json_error(
            'NO_CACHED_BRIEFING',
            'No cached briefing available. Click "Generate brief now" first (or wait for the 04:00 cron), then send.',
            422
        );
    }

    // ── Load the real dispatch helper WITHOUT running the cron body. The
    //    FF_NOTIFICATION_DIGEST_INCLUDE guard returns before the cron's
    //    timezone gate + advisory lock; the top-level functions are hoisted
    //    and become available to call directly. ──
    if (!defined('FF_NOTIFICATION_DIGEST_INCLUDE')) {
        define('FF_NOTIFICATION_DIGEST_INCLUDE', true);
    }
    require_once dirname(__DIR__, 4) . '/cron/notification_digest.php';

    if (!function_exists('run_morning_digest_emails')) {
        json_error('INTERNAL_ERROR', 'Digest dispatch helper unavailable.', 500);
    }

    // ── Force the hour gate open so every recipient is in scope right now.
    //    role / opt-in / snooze / 23h-dedup gates all remain in force. ──
    $GLOBALS['ff_force']              = true;
    $GLOBALS['ff_current_local_hour'] = (int) date('G');
    $GLOBALS['ff_global_digest_hour'] = (int) settings_get('notifications.digest_hour', 7);

    [$sent, $skipped, $errors] = run_morning_digest_emails();

    db_insert('audit_log', [
        'user_id'      => current_user_id(),
        'user_name'    => current_user()['name'] ?? 'system',
        'action'       => 'create',
        'module'       => 'settings',
        'entity_type'  => 'ai_brief_sent_manual',
        'entity_id'    => null,
        'entity_label' => 'Manual send-to-all morning briefing',
        'notes'        => sprintf('Operator triggered send-now. sent=%d skipped=%d errors=%d', $sent, $skipped, $errors),
        'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    $msg = sprintf(
        'Brief dispatched: %d sent, %d skipped (already received today or opted out), %d error%s.',
        $sent, $skipped, $errors, $errors === 1 ? '' : 's'
    );

    json_success([
        'sent'    => $sent,
        'skipped' => $skipped,
        'errors'  => $errors,
        'message' => $msg,
    ]);

} catch (\Throwable $e) {
    json_error('INTERNAL_ERROR', 'Send-now failed: ' . $e->getMessage(), 500);
}
