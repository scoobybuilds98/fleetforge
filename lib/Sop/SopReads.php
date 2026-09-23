<?php
declare(strict_types=1);

/**
 * FleetForge — SOP "Mark as read" (S-SOP-MODULE)
 *
 * @file        lib/Sop/SopReads.php
 * @description Per-user read state for SOP chapters, and the super-admin
 *              team view of who has read what.
 *
 *              A read is stored with the chapter's content_hash at the time
 *              (SopLibrary). Three states follow for any chapter:
 *                read    — hash matches the chapter today
 *                updated — read, but the chapter has changed since
 *                unread  — never marked
 *              WHY track "updated": an SOP that changes silently under a
 *              "read" tick is how a team keeps following the old procedure.
 *
 * @session     S-SOP-MODULE
 */

namespace FleetForge\Sop;

final class SopReads
{
    /**
     * This user's reads: slug → hash + when.
     *
     * @return array<string, array{hash:string, read_at:string}>
     */
    public static function forUser(int $userId): array
    {
        $out = [];
        foreach (db_select("SELECT chapter_slug, content_hash, read_at FROM sop_chapter_reads WHERE user_id = ?", [$userId]) as $r) {
            $out[(string) $r['chapter_slug']] = ['hash' => (string) $r['content_hash'], 'read_at' => (string) $r['read_at']];
        }
        return $out;
    }

    /** read | updated | unread for one chapter, given the user's reads. */
    public static function status(array $chapter, array $reads): string
    {
        $r = $reads[$chapter['slug']] ?? null;
        if ($r === null) {
            return 'unread';
        }
        return $r['hash'] === $chapter['hash'] ? 'read' : 'updated';
    }

    /**
     * Mark (or un-mark) the CURRENT version of a chapter as read.
     *
     * @throws \InvalidArgumentException on an unknown chapter
     */
    public static function set(int $userId, string $slug, bool $read): ?array
    {
        $chapter = SopLibrary::chapter($slug);
        if ($chapter === null) {
            throw new \InvalidArgumentException("No SOP chapter '{$slug}'.");
        }
        if (!$read) {
            db_execute("DELETE FROM sop_chapter_reads WHERE user_id = ? AND chapter_slug = ?", [$userId, $slug]);
            return null;
        }
        $now = ff_now_utc();
        db_execute(
            "INSERT INTO sop_chapter_reads (user_id, chapter_slug, content_hash, read_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE content_hash = VALUES(content_hash), read_at = VALUES(read_at)",
            [$userId, $slug, $chapter['hash'], $now]
        );
        return ['hash' => $chapter['hash'], 'read_at' => $now];
    }

    /**
     * Team view (super admins): every active staff user with how many
     * chapters they have read in their current version.
     *
     * @return list<array{id:int, name:string, role:string, read:int, updated:int, total:int, last_read:?string, missing:list<array{number:int, short:string}>}>
     */
    public static function team(): array
    {
        $chapters = SopLibrary::chapters();
        $bySlug   = array_column($chapters, null, 'slug');
        $reads    = [];
        foreach (db_select("SELECT user_id, chapter_slug, content_hash, read_at FROM sop_chapter_reads") as $r) {
            $reads[(int) $r['user_id']][(string) $r['chapter_slug']] = ['hash' => (string) $r['content_hash'], 'read_at' => (string) $r['read_at']];
        }

        $out = [];
        foreach (db_select(
            "SELECT u.id, u.name, COALESCE(r.name, '') AS role
               FROM users u
               LEFT JOIN user_roles r ON r.id = u.role_id
              WHERE u.deleted_at IS NULL AND u.status = 'active'
              ORDER BY u.name"
        ) as $u) {
            $mine    = $reads[(int) $u['id']] ?? [];
            $read    = 0;
            $updated = 0;
            $missing = [];
            foreach ($bySlug as $slug => $c) {
                $st = self::status($c, $mine);
                if ($st === 'read') {
                    $read++;
                } else {
                    $updated += $st === 'updated' ? 1 : 0;
                    $missing[] = ['number' => $c['number'], 'short' => $c['short']];
                }
            }
            $last = $mine === [] ? null : max(array_column($mine, 'read_at'));
            $out[] = [
                'id'        => (int) $u['id'],
                'name'      => (string) $u['name'],
                'role'      => (string) $u['role'],
                'read'      => $read,
                'updated'   => $updated,
                'total'     => count($chapters),
                'last_read' => $last,
                'missing'   => $missing,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$b['read'], $a['name']] <=> [$a['read'], $b['name']]);
        return $out;
    }
}
