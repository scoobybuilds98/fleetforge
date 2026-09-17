<?php
declare(strict_types=1);

/**
 * lib/Training/TrainingProgress.php
 *
 * S-TRAINING-MODULE — the one place that turns a player heartbeat into a
 * stored progress row, and that reads progress back for the viewer and the
 * super-admin team report.
 *
 * WHY progress is computed server-side from the catalog duration instead of
 * trusting a percent from the browser: the player reports only "I am at
 * second N"; the percent, the furthest point and the completion stamp are
 * derived here, so a stale tab or a hand-crafted request cannot mark a
 * chapter complete by sending percent=100.
 *
 * @session S-TRAINING-MODULE
 */

namespace FleetForge\Training;

final class TrainingProgress
{
    /** A chapter counts as watched at 90% — the last few seconds are the outro card. */
    public const COMPLETE_PERCENT = 90;

    private function __construct() {}

    /**
     * Record a heartbeat: the viewer is at $position seconds of $videoId.
     *
     * position_seconds follows the viewer (rewinds included) so resume
     * lands where they left; max_position_seconds and percent only move
     * forward; completed_at is stamped the first time percent reaches the
     * threshold and is never cleared.
     *
     * @return array{position_seconds:int,max_position_seconds:int,percent:int,completed_at:?string}
     * @throws \InvalidArgumentException when the video does not exist or is inactive
     */
    public static function record(int $userId, int $videoId, float $position): array
    {
        $video = db_row(
            "SELECT id, duration_seconds FROM training_videos WHERE id = ? AND is_active = 1",
            [$videoId]
        );
        if (!$video) {
            throw new \InvalidArgumentException('Training video not found.');
        }

        $duration = max(1, (int) $video['duration_seconds']);
        // Clamp: a player can report a hair past the end, or a negative on a glitch.
        $pos     = (int) min($duration, max(0, floor($position)));
        $percent = (int) min(100, floor($pos * 100 / $duration));
        $now     = ff_now_utc();

        // One statement so two tabs heartbeating at once cannot lose the
        // furthest point (read-modify-write would).
        db_execute(
            "INSERT INTO training_progress
                (user_id, video_id, position_seconds, max_position_seconds, percent, completed_at, started_at, last_watched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                position_seconds     = VALUES(position_seconds),
                max_position_seconds = GREATEST(max_position_seconds, VALUES(max_position_seconds)),
                percent              = GREATEST(percent, VALUES(percent)),
                completed_at         = COALESCE(completed_at, VALUES(completed_at)),
                last_watched_at      = VALUES(last_watched_at)",
            [
                $userId, $videoId, $pos, $pos, $percent,
                $percent >= self::COMPLETE_PERCENT ? $now : null,
                $now, $now,
            ]
        );

        $row = db_row(
            "SELECT position_seconds, max_position_seconds, percent, completed_at
               FROM training_progress WHERE user_id = ? AND video_id = ?",
            [$userId, $videoId]
        );
        return [
            'position_seconds'     => (int) $row['position_seconds'],
            'max_position_seconds' => (int) $row['max_position_seconds'],
            'percent'              => (int) $row['percent'],
            'completed_at'         => $row['completed_at'],
        ];
    }

    /**
     * The active catalog in chapter order, each with this user's progress.
     *
     * @return list<array<string,mixed>>
     */
    public static function catalogFor(int $userId): array
    {
        $rows = db_select(
            "SELECT v.id, v.slug, v.chapter_no, v.title, v.description, v.duration_seconds,
                    (v.captions_key IS NOT NULL) AS has_captions,
                    COALESCE(p.position_seconds, 0) AS position_seconds,
                    COALESCE(p.percent, 0)          AS percent,
                    p.completed_at, p.last_watched_at
               FROM training_videos v
               LEFT JOIN training_progress p ON p.video_id = v.id AND p.user_id = ?
              WHERE v.is_active = 1
              ORDER BY v.chapter_no, v.id",
            [$userId]
        );
        foreach ($rows as &$r) {
            foreach (['id', 'chapter_no', 'duration_seconds', 'position_seconds', 'percent'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['has_captions'] = (bool) $r['has_captions'];
        }
        return $rows;
    }

    /**
     * Course-level summary for a catalog from catalogFor().
     *
     * Overall percent is weighted by chapter LENGTH, not chapter count — finishing
     * a 2-minute chapter should not move the bar as much as a 12-minute one.
     *
     * @param list<array<string,mixed>> $catalog
     * @return array{chapters:int,completed:int,percent:int,watched_seconds:int,total_seconds:int}
     */
    public static function summarize(array $catalog): array
    {
        $total = 0; $watched = 0; $done = 0;
        foreach ($catalog as $v) {
            $total   += $v['duration_seconds'];
            $watched += $v['completed_at'] ? $v['duration_seconds'] : intdiv($v['duration_seconds'] * $v['percent'], 100);
            if ($v['completed_at']) $done++;
        }
        return [
            'chapters'        => count($catalog),
            'completed'       => $done,
            'percent'         => $total > 0 ? (int) floor($watched * 100 / $total) : 0,
            'watched_seconds' => $watched,
            'total_seconds'   => $total,
        ];
    }

    /**
     * Team report: every active or invited-but-not-removed staff user with their
     * course summary. Users who never opened Training appear at 0% — the point
     * of the report is to see who has NOT started.
     *
     * @return list<array<string,mixed>>
     */
    public static function teamReport(): array
    {
        $totalSeconds = (int) (db_row(
            "SELECT COALESCE(SUM(duration_seconds),0) AS s FROM training_videos WHERE is_active = 1"
        )['s'] ?? 0);

        $rows = db_select(
            "SELECT u.id, u.name, u.email, u.status, r.name AS role_name,
                    COUNT(p.id) AS started,
                    SUM(p.completed_at IS NOT NULL) AS completed,
                    COALESCE(SUM(CASE WHEN p.completed_at IS NOT NULL THEN v.duration_seconds
                                      ELSE FLOOR(v.duration_seconds * p.percent / 100) END), 0) AS watched_seconds,
                    MAX(p.last_watched_at) AS last_watched_at
               FROM users u
               LEFT JOIN user_roles r ON r.id = u.role_id
               LEFT JOIN training_progress p ON p.user_id = u.id
               LEFT JOIN training_videos v ON v.id = p.video_id AND v.is_active = 1
              WHERE u.status IN ('active','invited')
              GROUP BY u.id, u.name, u.email, u.status, r.name
              ORDER BY u.name"
        );
        foreach ($rows as &$r) {
            $r['id']              = (int) $r['id'];
            $r['started']         = (int) $r['started'];
            $r['completed']       = (int) $r['completed'];
            $r['watched_seconds'] = (int) $r['watched_seconds'];
            $r['percent']         = $totalSeconds > 0 ? (int) floor($r['watched_seconds'] * 100 / $totalSeconds) : 0;
        }
        return $rows;
    }
}
