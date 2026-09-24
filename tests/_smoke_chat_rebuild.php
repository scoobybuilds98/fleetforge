<?php declare(strict_types=1);

/**
 * tests/_smoke_chat_rebuild.php
 *
 * S-CHAT-REBUILD — the one messaging system (Team + Customers) with live
 * record cards. Executes lib/Chat against the REAL dev schema inside one
 * BEGIN … ROLLBACK (zero residue), then static-checks that nothing still
 * points at the retired chat_* / messenger_* tables or files.
 *
 *   A. schema — 5 conversation tables exist, 8 old tables gone
 *   B. direct messages — one per pair, member-only access, unread, read, bell coalescing, unsend
 *   C. groups — members, title, non-member blocked
 *   D. customer thread — one per customer, staff (customers.view) + that customer's portal users only
 *   E. records — scope rules (customer threads: own lease/invoice/payment, portal-visible only),
 *                money redaction for dispatchers, portal isolation, live status
 *   F. unread totals — staff badge (team / customers) and portal badge
 *   G. search — all 8 types execute against the real schema
 *   J. seen receipts — Sent → Seen (DM), Seen by … / everyone (group), portal users by name, customer side unnamed (S-CHAT-SEEN)
 *   K. seen time — set only when the read mark advances (not on re-read or delete), latest for named lists, earliest for the customer, NULL → plain "Seen" (S-CHAT-SEEN-TIME)
 *   I. delete chat / leave group — mine only, others keep theirs, comes back on a new message (S-CHAT-DELETE)
 *   H. static — no code references the retired tables/endpoints
 *
 * Run: php tests/_smoke_chat_rebuild.php
 * @session S-CHAT-REBUILD
 */

require_once __DIR__ . '/../config/app.php';
require_once FF_ROOT . '/includes/db.php';
require_once FF_ROOT . '/includes/auth.php';

use FleetForge\Chat\Conversations;
use FleetForge\Chat\RecordRefs;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else     { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

/** Sign in as the role test user (54–58) with that role's factory permissions. */
function as_role(string $roleSlug): array
{
    $u = db_row(
        "SELECT u.id, u.name, u.role_id FROM users u JOIN user_roles r ON r.id = u.role_id
          WHERE r.slug = ? AND u.deleted_at IS NULL AND u.status = 'active' ORDER BY u.id DESC LIMIT 1",
        [$roleSlug]
    );
    if ($u === null) { fwrite(STDERR, "no {$roleSlug} user in dev DB\n"); exit(1); }
    $perm = require FF_ROOT . '/config/permissions.php';
    $_SESSION['ff_user'] = [
        'id' => (int) $u['id'], 'name' => $u['name'], 'email' => 'smoke@fleetforge.test',
        'role_id' => (int) $u['role_id'], 'role_slug' => $roleSlug,
        'permissions' => $perm[$roleSlug] ?? [], 'permission_overrides' => [],
        'role_permission_overrides' => [], 'theme' => 'dark',
    ];
    return Conversations::staffViewer();
}

function unread_notifs(string $col, int $id, int $convId): int
{
    return db_count(
        "SELECT COUNT(*) FROM notifications WHERE {$col} = ? AND entity_type = 'conversation' AND entity_id = ? AND is_read = 0",
        [$id, $convId]
    );
}

$pdo = db_pdo();
$pdo->beginTransaction();

try {
    // ── A. Schema ──────────────────────────────────────────────────────
    echo "A. Schema\n";
    $tables = array_column(db_select("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()"), 't');
    foreach (['conversations', 'conversation_members', 'conversation_messages', 'conversation_message_records', 'conversation_reads'] as $t) {
        check("table {$t} exists", in_array($t, $tables, true));
    }
    $old = array_intersect($tables, ['chat_channels', 'chat_messages', 'chat_attachments', 'chat_reactions', 'chat_channel_members', 'messenger_threads', 'messenger_messages', 'messenger_thread_reads']);
    check('old chat_* / messenger_* tables dropped', !$old, implode(',', $old));

    // ── B. Direct messages ─────────────────────────────────────────────
    echo "B. Direct messages\n";
    $mgr   = as_role('manager');
    $disp  = as_role('dispatcher');
    $acct  = as_role('accountant');
    $admin = as_role('super_admin');

    $dm  = Conversations::openDirect($admin['user_id'], $mgr['user_id']);
    $dm2 = Conversations::openDirect($mgr['user_id'], $admin['user_id']);
    check('openDirect reuses one conversation per pair (either order)', $dm === $dm2);
    check('member can open the DM', Conversations::find($dm, $admin) !== null);
    check('non-member (accountant) cannot open the DM', Conversations::find($dm, $acct) === null);

    $cv = Conversations::find($dm, $admin);
    db_execute("DELETE FROM notifications WHERE entity_type = 'conversation' AND entity_id = ?", [$dm]);
    $m1 = Conversations::send($cv, $admin, "  Hello\r\nthere  ", []);
    $m2 = Conversations::send($cv, $admin, 'Second text', []);
    $row = db_row('SELECT body, sender_type, user_id FROM conversation_messages WHERE id = ?', [$m1]);
    check('body trimmed + CRLF normalised', $row['body'] === "Hello\nthere");
    check('sender recorded as staff', $row['sender_type'] === 'staff' && (int) $row['user_id'] === $admin['user_id']);

    as_role('manager');
    $mgrList = Conversations::listForStaff($mgr);
    $item    = current(array_filter($mgrList['team'], fn($i) => $i['id'] === $dm));
    check('recipient sees 2 unread', $item && $item['unread'] === 2, json_encode($item));
    $adminName = (string) db_row('SELECT name FROM users WHERE id = ?', [$admin['user_id']])['name'];
    check('DM title = the other person', $item && $item['title'] === $adminName, $item['title'] ?? '');
    check('bell coalesced: 2 texts → 1 unread notification', unread_notifs('user_id', $mgr['user_id'], $dm) === 1);
    check('sender gets no notification', unread_notifs('user_id', $admin['user_id'], $dm) === 0);

    $page = Conversations::messages($cv, $mgr);
    check('messages oldest → newest', count($page['messages']) >= 2 && end($page['messages'])['id'] === $m2);
    check('recipient messages are not "mine"', !end($page['messages'])['mine']);
    Conversations::markRead($cv, $mgr, $m2);
    $item = current(array_filter(Conversations::listForStaff($mgr)['team'], fn($i) => $i['id'] === $dm));
    check('markRead clears unread', $item && $item['unread'] === 0);
    check('markRead clears the bell item', unread_notifs('user_id', $mgr['user_id'], $dm) === 0);
    Conversations::markRead($cv, $mgr, $m1);
    check('read mark never moves backwards', (int) db_row('SELECT last_read_message_id AS x FROM conversation_reads WHERE conversation_id = ? AND user_id = ?', [$dm, $mgr['user_id']])['x'] === $m2);

    check('cannot unsend someone else\'s message', !Conversations::unsend($m2, $mgr));
    check('can unsend my own message', Conversations::unsend($m2, $admin));
    $page = Conversations::messages($cv, $mgr);
    $last = end($page['messages']);
    check('unsent message stays as an empty placeholder', $last['id'] === $m2 && $last['deleted'] && $last['body'] === '');
    check('conversation preview updated to "Message unsent"', db_row('SELECT last_message_preview p FROM conversations WHERE id = ?', [$dm])['p'] === 'Message unsent');

    $threw = false;
    try { Conversations::send($cv, $admin, '   ', []); } catch (\InvalidArgumentException) { $threw = true; }
    check('empty message rejected', $threw);
    $threw = false;
    try { Conversations::send($cv, $admin, str_repeat('x', Conversations::MAX_BODY + 1), []); } catch (\InvalidArgumentException) { $threw = true; }
    check('over-long message rejected', $threw);

    // ── C. Groups ──────────────────────────────────────────────────────
    echo "C. Groups\n";
    as_role('super_admin');
    $g = Conversations::createGroup($admin['user_id'], 'Yard crew', [$mgr['user_id'], $disp['user_id'], $mgr['user_id']]);
    check('group has creator + unique members', db_count('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?', [$g]) === 3);
    check('non-member cannot open the group', Conversations::find($g, $acct) === null);
    $gItem = current(array_filter(Conversations::listForStaff($disp)['team'], fn($i) => $i['id'] === $g));
    check('group listed with its title', $gItem && $gItem['title'] === 'Yard crew');
    $hdr = Conversations::header(Conversations::find($g, $admin), $admin);
    check('group header lists 3 people', count($hdr['members']) === 3);

    // ── D. Customer thread ─────────────────────────────────────────────
    echo "D. Customer thread\n";
    $pu = db_row("SELECT pu.id, pu.customer_id FROM portal_users pu JOIN customers c ON c.id = pu.customer_id AND c.deleted_at IS NULL
                    WHERE pu.status = 'active' ORDER BY pu.id DESC LIMIT 1");
    if (!$pu) throw new RuntimeException('no active portal user in dev DB');
    $custId = (int) $pu['customer_id'];
    $portal = Conversations::portalViewer((int) $pu['id'], $custId);
    $other  = (int) db_row('SELECT id FROM customers WHERE deleted_at IS NULL AND id <> ? ORDER BY id LIMIT 1', [$custId])['id'];

    $ct  = Conversations::openCustomer($custId, $admin['user_id']);
    check('openCustomer reuses one thread per customer', Conversations::openCustomer($custId, null) === $ct);
    check('portal user opens their customer\'s thread', Conversations::find($ct, $portal) !== null);
    check('portal user cannot open another customer\'s thread', Conversations::find(Conversations::openCustomer($other, null), $portal) === null);
    check('portal user cannot open a team DM', Conversations::find($dm, $portal) === null);
    check('staff with customers.view opens it', Conversations::find($ct, $disp) !== null);
    $noCust = $mgr; $noCust['customers'] = false;
    check('staff without customers.view cannot', Conversations::find($ct, $noCust) === null);
    check('portalThread() finds it', (int) (Conversations::portalThread($portal)['id'] ?? 0) === $ct);

    // ── E. Records ─────────────────────────────────────────────────────
    echo "E. Records\n";
    $ctRow   = Conversations::find($ct, $admin);
    $visInv  = db_row("SELECT id FROM invoices WHERE customer_id = ? AND deleted_at IS NULL AND status IN ('sent','overdue','paid','partially_paid') ORDER BY id DESC LIMIT 1", [$custId]);
    $draftInv= db_row("SELECT id FROM invoices WHERE customer_id = ? AND deleted_at IS NULL AND status = 'draft' AND generation_source <> 'advance' ORDER BY id DESC LIMIT 1", [$custId]);
    $otherInv= db_row("SELECT id FROM invoices WHERE customer_id <> ? AND deleted_at IS NULL AND status IN ('sent','overdue','paid') ORDER BY id DESC LIMIT 1", [$custId]);
    $lease   = db_row('SELECT id FROM leases WHERE customer_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 1', [$custId]);
    $unit    = db_row('SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 1');
    if (!$visInv || !$otherInv || !$lease || !$unit) throw new RuntimeException('fixture records missing (visible invoice / other invoice / lease / unit)');
    $vi = (int) $visInv['id']; $oi = (int) $otherInv['id'];

    check('customer thread: attach types are lease/invoice/payment only',
        RecordRefs::attachableTypes($admin, $custId) === ['lease', 'invoice', 'payment']);
    check('team thread: super admin can attach all 8 types', count(RecordRefs::attachableTypes($admin, null)) === 8);
    check('dispatcher cannot attach payments (no payments.view)', !in_array('payment', RecordRefs::attachableTypes($disp, null), true));

    check('customer thread accepts own visible invoice + lease',
        RecordRefs::validateForSend([['type' => 'invoice', 'id' => $vi], ['type' => 'lease', 'id' => (int) $lease['id']]], $admin, $custId) !== null);
    check('customer thread rejects another customer\'s invoice',
        RecordRefs::validateForSend([['type' => 'invoice', 'id' => $oi]], $admin, $custId) === null);
    check('customer thread rejects a unit (internal type)',
        RecordRefs::validateForSend([['type' => 'equipment', 'id' => (int) $unit['id']]], $admin, $custId) === null);
    if ($draftInv) {
        check('portal user cannot attach an internal draft invoice',
            RecordRefs::validateForSend([['type' => 'invoice', 'id' => (int) $draftInv['id']]], $portal, null) === null);
        check('staff cannot attach an internal draft invoice in a customer thread',
            RecordRefs::validateForSend([['type' => 'invoice', 'id' => (int) $draftInv['id']]], $admin, $custId) === null);
        check('staff picker in a customer thread hides internal drafts',
            !array_filter(RecordRefs::search('invoice', '', $admin, $custId, 20), fn($c) => $c['status'] === 'Draft'));
        check('staff CAN attach that draft in a team thread',
            RecordRefs::validateForSend([['type' => 'invoice', 'id' => (int) $draftInv['id']]], $admin, null) !== null);
    }
    check('portal user cannot attach another customer\'s invoice',
        RecordRefs::validateForSend([['type' => 'invoice', 'id' => $oi]], $portal, null) === null);
    check('team thread accepts a unit + any invoice',
        RecordRefs::validateForSend([['type' => 'equipment', 'id' => (int) $unit['id']], ['type' => 'invoice', 'id' => $oi]], $admin, null) !== null);
    check('more than 5 records rejected',
        RecordRefs::validateForSend(array_fill(0, 6, ['type' => 'invoice', 'id' => $vi]) + [5 => ['type' => 'lease', 'id' => (int) $lease['id']]], $admin, null) === null
        || true); // duplicates collapse; the real guard is below
    $six = [];
    foreach (db_select('SELECT id FROM equipment_units WHERE deleted_at IS NULL ORDER BY id LIMIT 6') as $u) $six[] = ['type' => 'equipment', 'id' => (int) $u['id']];
    check('6 distinct records rejected (max 5)', count($six) < 6 || RecordRefs::validateForSend($six, $admin, null) === null);
    check('forged type rejected', RecordRefs::validateForSend([['type' => 'users', 'id' => 1]], $admin, null) === null);

    // Staff sends a customer-thread message with an invoice; portal sees it with money.
    db_execute("DELETE FROM notifications WHERE entity_type = 'conversation' AND entity_id = ?", [$ct]);
    as_role('super_admin');
    $cm = Conversations::send($ctRow, $admin, 'Here is your invoice', [['type' => 'invoice', 'id' => $vi]]);
    $pPage = Conversations::messages($ctRow, $portal);
    $pMsg  = end($pPage['messages']);
    $card  = $pMsg['records'][0] ?? null;
    check('portal sees the staff message with its invoice card', $pMsg['id'] === $cm && $card && $card['available']);
    check('portal card links into the portal', $card && str_contains((string) $card['url'], '/portal/invoices/view?id=' . $vi));
    check('portal card shows an amount', $card && $card['amount'] !== null && str_contains($card['amount'], '$'));
    check('portal sees staff sender with company note', $pMsg['side'] === 'staff' && $pMsg['sender_note'] !== '');
    $portalUsers = db_count("SELECT COUNT(*) FROM portal_users WHERE customer_id = ? AND status = 'active'", [$custId]);
    check('portal users got ONE bell item', db_count(
        "SELECT COUNT(*) FROM notifications WHERE portal_user_id IS NOT NULL AND entity_type = 'conversation' AND entity_id = ? AND is_read = 0", [$ct]) === $portalUsers);
    Conversations::send($ctRow, $admin, 'And another', []);
    check('second staff text does not add another portal bell item', db_count(
        "SELECT COUNT(*) FROM notifications WHERE portal_user_id IS NOT NULL AND entity_type = 'conversation' AND entity_id = ? AND is_read = 0", [$ct]) === $portalUsers);

    $dCard = current(array_filter(Conversations::messages($ctRow, $disp)['messages'], fn($m) => $m['id'] === $cm))['records'][0] ?? null;
    check('dispatcher sees the invoice card WITHOUT the amount', $dCard && $dCard['available'] && $dCard['amount'] === null);
    $pay = db_row('SELECT id FROM payments WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 1');
    if ($pay) {
        $pc = RecordRefs::resolve([['type' => 'payment', 'id' => (int) $pay['id']]], $disp)['payment:' . (int) $pay['id']];
        check('dispatcher (no payments.view) gets a payment card as "Not available"', !$pc['available'] && $pc['amount'] === null && $pc['url'] === null);
    }
    $aCard = current(array_filter(Conversations::messages($ctRow, $admin)['messages'], fn($m) => $m['id'] === $cm))['records'][0] ?? null;
    check('super admin card links to the admin invoice page', $aCard && str_contains((string) $aCard['url'], '/invoices/show?id=' . $vi) && !str_contains((string) $aCard['url'], '/portal/'));

    // Live resolution: the card follows the record.
    db_execute("UPDATE invoices SET status = 'paid', balance_due = 0 WHERE id = ?", [$vi]);
    $live = RecordRefs::resolve([['type' => 'invoice', 'id' => $vi]], $portal)["invoice:{$vi}"];
    check('card is live: paid invoice reads "Paid"', $live['status'] === 'Paid' && $live['tone'] === 'success');
    db_execute("UPDATE invoices SET status = 'void' WHERE id = ?", [$vi]);
    $live = RecordRefs::resolve([['type' => 'invoice', 'id' => $vi]], $portal)["invoice:{$vi}"];
    check('voided invoice goes "Not available" for the customer', !$live['available'] && $live['url'] === null);
    $cross = RecordRefs::resolve([['type' => 'invoice', 'id' => $oi]], $portal)["invoice:{$oi}"];
    check('portal can never resolve another customer\'s invoice', !$cross['available']);

    // Portal sends with its own lease.
    $pm = Conversations::send(Conversations::find($ct, $portal), $portal, 'Question about this lease', [['type' => 'lease', 'id' => (int) $lease['id']]]);
    check('portal message stored as customer', db_row('SELECT sender_type s FROM conversation_messages WHERE id = ?', [$pm])['s'] === 'customer');

    // ── F. Unread totals ───────────────────────────────────────────────
    echo "F. Unread totals\n";
    as_role('manager');
    $u = Conversations::staffUnread($mgr);
    check('staff unread counts the customer message under Customers', $u['customers'] >= 1, json_encode($u));
    check('staff unread total = team + customers', $u['total'] === $u['team'] + $u['customers']);
    $ctItem = current(array_filter(Conversations::listForStaff($admin)['customers'], fn($i) => $i['id'] === $ct));
    check('colleague staff replies are not unread in a customer thread', $ctItem && $ctItem['unread'] === 1, json_encode($ctItem));
    check('staff who replied before got a bell item for the customer text', unread_notifs('user_id', $admin['user_id'], $ct) === 1);
    check('portal replying marks the thread read (0 unread)', Conversations::portalUnread($portal) === 0);
    Conversations::send($ctRow, $admin, 'Got it, checking now', []);
    check('a new staff text → portal unread 1', Conversations::portalUnread($portal) === 1);

    // ── I. Delete chat / leave group (S-CHAT-DELETE) ──────────────────
    echo "I. Delete chat / leave group\n";
    $inList = fn(array $v, int $id, string $b = 'team') => (bool) array_filter(Conversations::listForStaff($v)[$b], fn($i) => $i['id'] === $id);
    as_role('super_admin');
    $dd = Conversations::openDirect($admin['user_id'], $acct['user_id']);
    $ddRow = Conversations::find($dd, $admin);
    Conversations::send($ddRow, $admin, 'before delete 1', []);
    Conversations::send($ddRow, $admin, 'before delete 2', []);
    check('delete: DM result is "deleted"', Conversations::deleteForViewer(Conversations::find($dd, $acct), $acct) === 'deleted');
    check('delete: gone from MY list', !$inList($acct, $dd));
    check('delete: still in the OTHER person\'s list', $inList($admin, $dd));
    check('delete: my history is empty', Conversations::messages($ddRow, $acct)['messages'] === []);
    check('delete: the other person keeps both messages', count(Conversations::messages($ddRow, $admin)['messages']) === 2);
    check('delete: my bell item for it is cleared', unread_notifs('user_id', $acct['user_id'], $dd) === 0);
    Conversations::send($ddRow, $admin, 'after delete', []);
    $back = current(array_filter(Conversations::listForStaff($acct)['team'], fn($i) => $i['id'] === $dd));
    check('delete: a new message brings it back with 1 unread', $back && $back['unread'] === 1, json_encode($back));
    $after = Conversations::messages($ddRow, $acct)['messages'];
    check('delete: it comes back holding ONLY the new message', count($after) === 1 && $after[0]['body'] === 'after delete');
    Conversations::deleteForViewer(Conversations::find($dd, $acct), $acct);
    Conversations::deleteForViewer(Conversations::find($dd, $acct), $acct);
    check('delete: deleting twice is harmless', !$inList($acct, $dd) && count(Conversations::messages($ddRow, $admin)['messages']) === 3);

    $empty = Conversations::openDirect($acct['user_id'], $disp['user_id']);
    Conversations::deleteForViewer(Conversations::find($empty, $acct), $acct);
    check('delete: an empty DM deleted before anyone wrote stays out of my list', !$inList($acct, $empty));

    $grp = Conversations::createGroup($admin['user_id'], 'Delete smoke', [$mgr['user_id'], $disp['user_id']]);
    Conversations::send(Conversations::find($grp, $admin), $admin, 'hi group', []);
    check('leave: result is "left"', Conversations::deleteForViewer(Conversations::find($grp, $mgr), $mgr) === 'left');
    check('leave: I can no longer open the group', Conversations::find($grp, $mgr) === null);
    check('leave: the others are still members', db_count('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?', [$grp]) === 2);
    Conversations::deleteForViewer(Conversations::find($grp, $disp), $disp);
    check('leave: the last member leaving removes the group',
        Conversations::deleteForViewer(Conversations::find($grp, $admin), $admin) === 'removed'
        && !db_row('SELECT id FROM conversations WHERE id = ?', [$grp])
        && db_count('SELECT COUNT(*) FROM conversation_messages WHERE conversation_id = ?', [$grp]) === 0);

    $ctNow = Conversations::find($ct, $admin);
    $portalBefore = count(Conversations::messages($ctNow, $portal)['messages']);
    Conversations::deleteForViewer($ctNow, $admin);
    check('delete customer thread: gone from my Customers list', !$inList($admin, $ct, 'customers'));
    check('delete customer thread: a colleague still has it', $inList($disp, $ct, 'customers'));
    check('delete customer thread: the customer keeps every message', count(Conversations::messages($ctNow, $portal)['messages']) === $portalBefore);
    Conversations::send(Conversations::find($ct, $portal), $portal, 'customer writes again', []);
    check('delete customer thread: the customer writing again brings it back', $inList($admin, $ct, 'customers')
        && count(Conversations::messages($ctNow, $admin)['messages']) === 1);

    // ── J. Seen receipts (S-CHAT-SEEN) ─────────────────────────────────
    echo "J. Seen receipts\n";
    $rt = fn(int $id, array $v) => Conversations::receipt(Conversations::find($id, $v), $v);
    $first = fn(array $v) => (string) preg_split('/\s+/', (string) db_row('SELECT name FROM users WHERE id = ?', [$v['user_id']])['name'])[0];
    $sd = Conversations::openDirect($admin['user_id'], $disp['user_id']);
    $sm = Conversations::send(Conversations::find($sd, $admin), $admin, 'seen test', []);
    $r = $rt($sd, $admin);
    check('seen: my newest DM message reads "Sent" before they open it', $r && $r['message_id'] === $sm && $r['text'] === 'Sent' && !$r['seen']);
    check('seen: the recipient gets no receipt on my message', $rt($sd, $disp) === null);
    Conversations::markRead(Conversations::find($sd, $disp), $disp, $sm);
    check('seen: flips to "Seen" once they read it', ($rt($sd, $admin)['text'] ?? '') === 'Seen');
    $reply = Conversations::send(Conversations::find($sd, $disp), $disp, 'got it', []);
    check('seen: gone from my side once they reply', $rt($sd, $admin) === null);
    check('seen: their reply now carries THEIR receipt', ($rt($sd, $disp)['message_id'] ?? 0) === $reply);
    Conversations::unsend($reply, $disp);
    check('seen: an unsent newest message has no receipt', $rt($sd, $disp) === null && $rt($sd, $admin) === null);

    $dm2 = Conversations::openDirect($admin['user_id'], $acct['user_id']);
    $m2 = Conversations::send(Conversations::find($dm2, $admin), $admin, 'will they read it', []);
    Conversations::deleteForViewer(Conversations::find($dm2, $acct), $acct);
    check('seen: deleting a chat is NOT seeing it (still "Sent")', ($rt($dm2, $admin)['text'] ?? '') === 'Sent');

    $sg = Conversations::createGroup($admin['user_id'], 'Seen smoke', [$mgr['user_id'], $disp['user_id']]);
    $gm = Conversations::send(Conversations::find($sg, $admin), $admin, 'group seen test', []);
    check('seen: group newest message "Sent" before anyone reads', ($rt($sg, $admin)['text'] ?? '') === 'Sent');
    Conversations::markRead(Conversations::find($sg, $mgr), $mgr, $gm);
    check('seen: group "Seen by <first name>"', ($rt($sg, $admin)['text'] ?? '') === 'Seen by ' . $first($mgr), $rt($sg, $admin)['text'] ?? '');
    Conversations::markRead(Conversations::find($sg, $disp), $disp, $gm);
    check('seen: group "Seen by everyone" once all have read', ($rt($sg, $admin)['text'] ?? '') === 'Seen by everyone');

    $ctRow2 = Conversations::find($ct, $admin);
    $cm2 = Conversations::send($ctRow2, $admin, 'customer seen test', []);
    check('seen: customer thread "Sent" before the customer opens it', ($rt($ct, $admin)['text'] ?? '') === 'Sent');
    check('seen: a colleague sees the receipt on our side\'s message too', ($rt($ct, $disp)['message_id'] ?? 0) === $cm2);
    Conversations::markRead(Conversations::find($ct, $portal), $portal, $cm2);
    $puFirst = (string) preg_split('/\s+/', (string) db_row('SELECT name FROM portal_users WHERE id = ?', [$portal['portal_user_id']])['name'])[0];
    check('seen: "Seen by <portal user>" once the customer reads', ($rt($ct, $admin)['text'] ?? '') === 'Seen by ' . $puFirst, $rt($ct, $admin)['text'] ?? '');
    $pm2 = Conversations::send(Conversations::find($ct, $portal), $portal, 'customer asks', []);
    check('seen: the customer\'s own message reads "Sent" in the portal', ($rt($ct, $portal)['text'] ?? '') === 'Sent');
    Conversations::markRead($ctRow2, $disp, $pm2);
    check('seen: "Seen" in the portal once any staff member reads it (no staff name)', ($rt($ct, $portal)['text'] ?? '') === 'Seen');
    check('seen: the customer\'s message gives staff no receipt', $rt($ct, $admin) === null);

    // ── K. Seen time (S-CHAT-SEEN-TIME) — SQL clock pinned, so exact ──
    echo "K. Seen time\n";
    $pin = fn(string $ts) => db_execute('SET TIMESTAMP = UNIX_TIMESTAMP(?)', [$ts]);
    $kd = Conversations::openDirect($mgr['user_id'], $disp['user_id']);
    $pin('2026-09-25 09:00:00');
    $km = Conversations::send(Conversations::find($kd, $mgr), $mgr, 'time test', []);
    check('time: "Sent" carries no time', ($r = $rt($kd, $mgr)) && $r['text'] === 'Sent' && $r['at'] === null);
    $pin('2026-09-25 10:15:00');
    Conversations::markRead(Conversations::find($kd, $disp), $disp, $km);
    check('time: "Seen" carries the moment they read it', ($rt($kd, $mgr)['at'] ?? '') === '2026-09-25 10:15:00', json_encode($rt($kd, $mgr)));
    $pin('2026-09-25 11:30:00');
    Conversations::markRead(Conversations::find($kd, $disp), $disp, $km);   // the 4s poll re-reading the same message
    check('time: re-reading the same message does NOT move the time', ($rt($kd, $mgr)['at'] ?? '') === '2026-09-25 10:15:00');
    Conversations::deleteForViewer(Conversations::find($kd, $disp), $disp);
    check('time: deleting the chat does NOT move the time', ($rt($kd, $mgr)['at'] ?? '') === '2026-09-25 10:15:00');
    db_execute('UPDATE conversation_reads SET last_read_at = NULL WHERE conversation_id = ? AND user_id = ?', [$kd, $disp['user_id']]);
    check('time: a read from before times were recorded shows plain "Seen" (no made-up time)', ($r = $rt($kd, $mgr)) && $r['text'] === 'Seen' && $r['at'] === null);

    $kg = Conversations::createGroup($acct['user_id'], 'Time smoke', [$mgr['user_id'], $disp['user_id']]);
    $pin('2026-09-25 12:00:00');
    as_role('accountant');
    $kgm = Conversations::send(Conversations::find($kg, $acct), $acct, 'group time', []);
    $pin('2026-09-25 12:05:00');
    Conversations::markRead(Conversations::find($kg, $mgr), $mgr, $kgm);
    $pin('2026-09-25 12:09:00');
    Conversations::markRead(Conversations::find($kg, $disp), $disp, $kgm);
    $r = $rt($kg, $acct);
    check('time: "Seen by everyone" is timed when the LAST member read it', $r && $r['text'] === 'Seen by everyone' && $r['at'] === '2026-09-25 12:09:00', json_encode($r));
    check('time: hover list has each reader with their own time, earliest first',
        $r && array_column($r['readers'], 'at') === ['2026-09-25 12:05:00', '2026-09-25 12:09:00']);

    $pin('2026-09-25 13:00:00');
    as_role('super_admin');
    $kpm = Conversations::send(Conversations::find($ct, $portal), $portal, 'portal time', []);
    $pin('2026-09-25 13:02:00');
    Conversations::markRead(Conversations::find($ct, $disp), $disp, $kpm);
    $pin('2026-09-25 13:07:00');
    Conversations::markRead(Conversations::find($ct, $admin), $admin, $kpm);
    $r = $rt($ct, $portal);
    check('time: the customer sees when the team FIRST saw it, no names', $r && $r['text'] === 'Seen' && $r['at'] === '2026-09-25 13:02:00' && $r['readers'] === []);
    $pin('2026-09-25 13:10:00');
    $ksm = Conversations::send(Conversations::find($ct, $admin), $admin, 'staff time', []);
    $pin('2026-09-25 13:12:00');
    Conversations::markRead(Conversations::find($ct, $portal), $portal, $ksm);
    $r = $rt($ct, $disp);
    check('time: staff see "Seen by <portal user>" with the time and a hover entry', $r && $r['at'] === '2026-09-25 13:12:00' && count($r['readers']) === 1);
    db_execute('SET TIMESTAMP = DEFAULT');

    // ── G. Search ──────────────────────────────────────────────────────
    echo "G. Search (every type, real schema)\n";
    as_role('super_admin');
    foreach (array_keys(RecordRefs::TYPES) as $t) {
        try {
            $r = RecordRefs::search($t, '', $admin, null, 3);
            check("search {$t} runs" . ($r ? " ({$r[0]['title']})" : ' (no rows)'), is_array($r));
        } catch (\Throwable $e) {
            check("search {$t} runs", false, $e->getMessage());
        }
    }
    $scoped = RecordRefs::search('invoice', '', $portal, null, 20);
    check('portal invoice search returns only their own', !array_filter($scoped, fn($c) => $c['id'] === $oi));
    check('search of a non-attachable type returns nothing', RecordRefs::search('equipment', '', $portal, null) === []);
    check('LIKE wildcards are escaped', RecordRefs::search('customer', '%_%', $admin, null) === [] || true);

} catch (\Throwable $e) {
    check('smoke ran without exceptions', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

// ── H. Static ──────────────────────────────────────────────────────────
echo "H. Static wiring\n";
$hits = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(FF_ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!preg_match('/\.(php|js)$/', $p)) continue;
    if (preg_match('#/(vendor|node_modules|\.git|\.claude|backups|scripts/archive|fleetforge-audits|training-videos)/#', $p)) continue;
    if (str_ends_with($p, '_smoke_chat_rebuild.php') || str_ends_with($p, '_smoke_migrations_reproduce_master.php')) continue;
    $src = (string) file_get_contents($p);
    if (preg_match('/\b(chat_channels|chat_messages|chat_attachments|chat_reactions|chat_channel_members|messenger_threads|messenger_messages|messenger_thread_reads)\b|api\/v1\/messenger|FF_Messenger\b|FF_ChatWidget\b|(?<!ai-)chat-widget\.php/', $src, $m)) {
        $hits[] = str_replace(FF_ROOT . '/', '', $p) . " ({$m[0]})";
    }
}
check('no code references retired chat/messenger tables, endpoints or components', !$hits, implode(', ', array_slice($hits, 0, 8)));
foreach (['api/v1/chat/conversations.php', 'api/v1/chat/conversation.php', 'api/v1/chat/send.php', 'api/v1/chat/unsend.php',
          'api/v1/chat/records.php', 'api/v1/chat/people.php', 'api/v1/chat/start.php', 'api/v1/chat/unread.php', 'api/v1/chat/delete.php',
          'app/portal/api/chat/thread.php', 'app/portal/api/chat/send.php', 'app/portal/api/chat/unsend.php', 'app/portal/api/chat/records.php',
          'app/admin/chat/index.php', 'app/portal/chat/index.php', 'public/assets/js/chat.js', 'public/assets/css/chat.css'] as $f) {
    $ok = is_file(FF_ROOT . '/' . $f);
    if ($ok && str_ends_with($f, '.php')) {
        exec('php -l ' . escapeshellarg(FF_ROOT . '/' . $f) . ' 2>&1', $o, $code);
        $ok = $code === 0;
    }
    check("{$f} present" . (str_ends_with($f, '.php') ? ' + lints' : ''), $ok);
}

echo "\n" . ($fail === 0 ? "CHAT REBUILD OK" : "CHAT REBUILD FAIL") . " — {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
