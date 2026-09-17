<?php
declare(strict_types=1);

/**
 * tests/_smoke_training_progress.php
 *
 * S-TRAINING-MODULE — progress rules + the real endpoints' gates.
 *
 *   T1  first heartbeat creates the row; percent derived from catalog duration
 *   T2  rewind moves the resume point back but never lowers percent / furthest point
 *   T3  reaching 90% stamps completed_at; a later rewind keeps it
 *   T4  position is clamped to [0, duration]
 *   T5  inactive / unknown video → InvalidArgumentException
 *   T6  catalogFor() orders by chapter and merges only THIS user's progress
 *   T7  summarize() weights by length and counts completed chapters
 *   T8  teamReport() lists users with no progress at 0%
 *   T9  endpoints: report 403 for non-super-admin (static check of the gate),
 *       stream proxies captions and range-streams video locally (static check)
 *
 * All writes run in a transaction that is rolled back.
 *
 * Run:  php tests/_smoke_training_progress.php
 * Exit: 0 all pass, 1 on failure.
 *
 * @session S-TRAINING-MODULE
 */

require_once dirname(__DIR__) . '/config/app.php';
require_once FF_ROOT . '/vendor/autoload.php';

use FleetForge\Training\TrainingProgress;

$failures = [];
$passes   = 0;
$check = static function (bool $ok, string $m) use (&$passes, &$failures): void {
    if ($ok) { $passes++; echo "  \033[32mPASS\033[0m — {$m}\n"; }
    else     { $failures[] = $m; echo "  \033[31mFAIL\033[0m — {$m}\n"; }
};

$users = db_select("SELECT id FROM users WHERE status = 'active' ORDER BY id LIMIT 2");
if (count($users) < 2) { echo "need 2 active users\n"; exit(2); }
[$u1, $u2] = [(int) $users[0]['id'], (int) $users[1]['id']];

db_execute('START TRANSACTION');
try {
    $mk = static function (string $slug, int $no, int $dur, int $active = 1): int {
        return db_insert('training_videos', [
            'slug' => $slug, 'chapter_no' => $no, 'title' => "Smoke {$slug}", 'duration_seconds' => $dur,
            'video_key' => "training/{$slug}.mp4", 'is_active' => $active,
        ]);
    };
    // Isolate the catalog: hide real chapters inside the transaction.
    db_execute("UPDATE training_videos SET is_active = 0");
    $vA = $mk('zz-smoke-a', 2, 200);
    $vB = $mk('zz-smoke-b', 1, 100);
    $vX = $mk('zz-smoke-x', 3, 100, 0);

    // T1
    $r = TrainingProgress::record($u1, $vA, 50);
    $check($r['position_seconds'] === 50 && $r['percent'] === 25 && $r['completed_at'] === null, 'T1 first heartbeat → 25%, not complete');

    // T2
    TrainingProgress::record($u1, $vA, 120);
    $r = TrainingProgress::record($u1, $vA, 30);
    $check($r['position_seconds'] === 30 && $r['max_position_seconds'] === 120 && $r['percent'] === 60, 'T2 rewind keeps percent 60 / furthest 120, resume 30');

    // T3
    $r = TrainingProgress::record($u1, $vA, 181);
    $check($r['percent'] === 90 && $r['completed_at'] !== null, 'T3 90% stamps completed_at');
    $stamp = $r['completed_at'];
    $r = TrainingProgress::record($u1, $vA, 5);
    $check($r['completed_at'] === $stamp && $r['percent'] === 90, 'T3 rewind after completion keeps the stamp');

    // T4
    $r = TrainingProgress::record($u1, $vB, 9999);
    $check($r['position_seconds'] === 100 && $r['percent'] === 100, 'T4 position clamped to duration');
    $r = TrainingProgress::record($u2, $vB, -40);
    $check($r['position_seconds'] === 0 && $r['percent'] === 0, 'T4 negative position clamped to 0');

    // T5
    foreach ([$vX, 999999999] as $bad) {
        try { TrainingProgress::record($u1, $bad, 10); $check(false, "T5 video {$bad} rejected"); }
        catch (\InvalidArgumentException $e) { $check(true, "T5 video {$bad} rejected"); }
    }

    // T6
    $cat = TrainingProgress::catalogFor($u1);
    $check(array_column($cat, 'slug') === ['zz-smoke-b', 'zz-smoke-a'], 'T6 catalog in chapter order, inactive excluded');
    $cat2 = TrainingProgress::catalogFor($u2);
    $check($cat2[1]['percent'] === 0 && $cat2[1]['completed_at'] === null, 'T6 other user sees none of u1 progress');

    // T7 — both complete for u1: 300 of 300
    $s = TrainingProgress::summarize($cat);
    $check($s['completed'] === 2 && $s['percent'] === 100 && $s['total_seconds'] === 300, 'T7 summary 2/2, 100%, 300s');
    $s2 = TrainingProgress::summarize([
        ['duration_seconds' => 100, 'percent' => 50, 'completed_at' => null],
        ['duration_seconds' => 300, 'percent' => 0,  'completed_at' => null],
    ]);
    $check($s2['percent'] === 12 && $s2['watched_seconds'] === 50, 'T7 length-weighted (50 of 400 = 12%)');

    // T8
    $team = array_column(TrainingProgress::teamReport(), null, 'id');
    $check(isset($team[$u1]) && $team[$u1]['completed'] === 2 && $team[$u1]['percent'] === 100, 'T8 u1 100%, 2 completed');
    $none = array_filter($team, static fn ($r) => $r['started'] === 0);
    $check(count($none) > 0 && reset($none)['percent'] === 0, 'T8 users with no progress listed at 0%');
} finally {
    db_execute('ROLLBACK');
}

// T9 — gates / transport, static
$report = (string) file_get_contents(FF_ROOT . '/api/v1/training/report.php');
$check(str_contains($report, "if (!is_super_admin())") && str_contains($report, "'FORBIDDEN'"), 'T9 report API gated to super_admin');
$page = (string) file_get_contents(FF_ROOT . '/app/admin/training/report.php');
$check((bool) preg_match('/if \(!is_super_admin\(\)\) \{.*?exit;/s', $page), 'T9 report page gated to super_admin');
$stream = (string) file_get_contents(FF_ROOT . '/api/v1/training/stream.php');
$check(str_contains($stream, 'session_write_close()') && str_contains($stream, 'Accept-Ranges: bytes')
    && strpos($stream, "\$kind === 'captions'") < strpos($stream, 'isRemote()'), 'T9 stream: session released, Range support, captions proxied before S3 redirect');

echo "\n" . ($failures ? "\033[31m" . count($failures) . " FAILED\033[0m" : "\033[32mALL {$passes} PASS\033[0m") . "\n";
exit($failures ? 1 : 0);
