<?php
declare(strict_types=1);

/**
 * scripts/training/publish_videos.php
 *
 * S-TRAINING-MODULE — publishes the rendered training chapters into the
 * in-app Training page: uploads each NN-slug.mp4 (+ captions, converted from
 * .srt to WebVTT because <track> only reads VTT) to storage under
 * training/<slug>.mp4 / .vtt, and upserts the training_videos catalog row.
 *
 * Idempotent. Upsert is by slug and only touches catalog columns, so
 * re-publishing a re-recorded chapter keeps everyone's progress. A chapter
 * whose file is no longer in --dir is left alone unless --deactivate-missing.
 * The combined full-course file and demo-* files are skipped: the course is
 * tracked chapter by chapter.
 *
 * Title comes from the chapter's .timeline.json, the description from the
 * chapter script's `subtitle:` (scripts/walkthrough/chapters/<file>.mjs),
 * duration from the timeline.
 *
 * Usage (dry run by default):
 *   php scripts/training/publish_videos.php --dir=/path/to/training-videos
 *   php scripts/training/publish_videos.php --dir=/path/to/training-videos --apply [--deactivate-missing]
 *
 * PROD: operator-run (the agent never writes to prod). Copy the rendered
 * NN-*.mp4 / .srt / .timeline.json files to the server, then run with
 * `sudo -u www-data` so the storage driver (S3) and credentials are the app's.
 *
 * @session S-TRAINING-MODULE
 */

require_once dirname(__DIR__, 2) . '/config/app.php';

use FleetForge\Storage\StorageClient;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$opts   = getopt('', ['dir:', 'apply', 'deactivate-missing']);
$dir    = rtrim((string) ($opts['dir'] ?? (FF_ROOT . '/training-videos')), '/');
$apply  = isset($opts['apply']);
$deact  = isset($opts['deactivate-missing']);

if (!is_dir($dir)) {
    fwrite(STDERR, "Directory not found: {$dir}\n");
    exit(2);
}

/** SRT → WebVTT: header + comma→dot in cue timestamps; cue numbers are valid VTT identifiers. */
function srt_to_vtt(string $srt): string
{
    $srt = str_replace("\r\n", "\n", preg_replace('/^\xEF\xBB\xBF/', '', $srt));
    return "WEBVTT\n\n" . preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt);
}

$files = glob($dir . '/[0-9][0-9]-*.mp4') ?: [];
sort($files);
if (!$files) {
    fwrite(STDERR, "No NN-*.mp4 chapter files in {$dir}\n");
    exit(2);
}

echo ($apply ? 'APPLY' : 'DRY RUN') . " — {$dir}\n";
$seen = [];
foreach ($files as $mp4) {
    $base = basename($mp4, '.mp4');                    // 02-customers
    preg_match('/^(\d+)-(.+)$/', $base, $m);
    $chapterNo = (int) $m[1];
    $slug      = $m[2];                                // customers
    $seen[]    = $slug;

    $timeline = json_decode((string) @file_get_contents("{$dir}/{$base}.timeline.json"), true) ?: [];
    $title    = (string) ($timeline['chapter'] ?? ucwords(str_replace('-', ' ', $slug)));
    $duration = (int) round((float) ($timeline['duration'] ?? 0));

    $description = null;
    $script = FF_ROOT . "/scripts/walkthrough/chapters/{$base}.mjs";
    if (is_file($script) && preg_match("/subtitle:\\s*(['\"])(.*?)(?<!\\\\)\\1/s", (string) file_get_contents($script), $sm)) {
        $description = mb_substr(stripslashes($sm[2]), 0, 500);
    }

    $srt       = "{$dir}/{$base}.srt";
    $videoKey  = "training/{$slug}.mp4";
    $capKey    = is_file($srt) ? "training/{$slug}.vtt" : null;

    printf("  %02d %-28s %5ds  %s%s\n", $chapterNo, $title, $duration, $videoKey, $capKey ? ' +captions' : '');
    if ($duration <= 0) {
        echo "     ! no duration in {$base}.timeline.json — skipped\n";
        continue;
    }
    if (!$apply) continue;

    // upload() consumes its source (the local driver unlinks it, as it would a
    // PHP upload tmp file), so hand it a copy — never the rendered original.
    $tmpMp4 = tempnam(sys_get_temp_dir(), 'ffmp4');
    copy($mp4, $tmpMp4);
    StorageClient::upload($tmpMp4, $videoKey);
    @unlink($tmpMp4);
    if ($capKey) {
        $tmp = tempnam(sys_get_temp_dir(), 'ffvtt');
        file_put_contents($tmp, srt_to_vtt((string) file_get_contents($srt)));
        StorageClient::upload($tmp, $capKey);
        @unlink($tmp);
    }

    db_execute(
        "INSERT INTO training_videos (slug, chapter_no, title, description, duration_seconds, video_key, captions_key, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE chapter_no = VALUES(chapter_no), title = VALUES(title),
            description = VALUES(description), duration_seconds = VALUES(duration_seconds),
            video_key = VALUES(video_key), captions_key = VALUES(captions_key), is_active = 1",
        [$slug, $chapterNo, $title, $description, $duration, $videoKey, $capKey]
    );
}

if ($deact && $apply) {
    $ph = implode(',', array_fill(0, count($seen), '?'));
    $n  = db_execute("UPDATE training_videos SET is_active = 0 WHERE is_active = 1 AND slug NOT IN ({$ph})", $seen);
    echo "Deactivated {$n} chapter(s) not in {$dir}\n";
}

echo $apply ? "Published " . count($seen) . " chapter(s).\n" : "Nothing written. Re-run with --apply.\n";
