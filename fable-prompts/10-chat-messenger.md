# Domain 10 — Chat & Messenger (realtime)

> Prereq: read `00-mission-and-method.md` + `bug-taxonomy.md`. Output →
> `fable-prompts/findings/10-chat.md`.

Modules: `chat` (~28 endpoints), `messenger` (~8 endpoints), plus the realtime
transport (Pusher). Realtime means two contracts to keep in sync: the REST write
AND the broadcast — and the UI presence of both is enforced by a doc smoke.

## Scope
```
for g in chat messenger; do echo "== $g =="; find api/v1/$g -name '*.php' | sort; done
ls app/admin/chat app/admin/messenger
grep -rln "Pusher\|pusher\|broadcast\|trigger" lib/ api/v1/chat api/v1/messenger app/admin/chat app/admin/messenger | head
```

## End-to-end flows
1. **Send message** — REST insert AND the Pusher broadcast must both fire and carry
   the same shape. The `_smoke_doc_freshness.php` CLASS 12 enforces Pusher↔UI
   presence (`feedback_canonical_doc_checklist`); confirm every broadcast a sender
   emits has a UI subscriber, and vice-versa (Class 4: a message saved but never
   broadcast = "it didn't send" to the user).
2. **Channel auth** — private/presence channel authorization endpoint: can a user
   subscribe to a conversation they're not a participant in (Class 8 access leak)?
3. **Conversation create / participants** — adding/removing members; soft-deleted
   users in a conversation (Class 9); permission scope.
4. **Read receipts / unread counts** — a Class 7 counter: does unread increment on
   receive and decrement on read, symmetrically? Reconcile against actual messages.
5. **Attachments** — ties to storage (Domain 08): access control, size/type limits.
6. **Messenger vs chat** — confirm they're distinct features and not duplicating /
   racing on the same tables.

## Hotspots
- **Class 4:** message persisted but broadcast failed (or vice-versa) → user sees
  nothing / a ghost message. The two writes must be consistent.
- **Class 8:** channel-auth — the single most important check; attempt to subscribe
  to a foreign conversation and report any success as CRITICAL.
- **Class 7:** unread-count drift.
- **Idempotency:** double-send / retry creating duplicate messages.
- **Class 3:** message/conversation status enums.

## Start here
Trace send-message through REST insert → Pusher broadcast → UI subscriber and
confirm shape parity, then attack channel-auth for cross-conversation access.
