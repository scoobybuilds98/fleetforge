<?php
declare(strict_types=1);

/**
 * api/v1/ai/chat-session.php
 *
 * Manages individual AI chat sessions — load messages or delete.
 *
 * GET /api/v1/ai/chat-session?id=N
 *   → { session: {id, title, context_type, context_id, created_at},
 *       messages: [{id, role, content, tokens_used, created_at}] }
 *
 * DELETE /api/v1/ai/chat-session?id=N
 *   → { success: true }
 *
 * Permission: ai:view (GET), ai:edit (DELETE)
 *
 * @depends ai_chat_sessions, ai_chat_messages tables
 * @session S027
 */

require_once dirname(__DIR__, 2) . '/bootstrap.php';

require_auth_api();

if (!can('ai', 'view')) {
    json_error('FORBIDDEN', 'Forbidden', 403);
}

header('Content-Type: application/json');

$userId    = (int) ($_SESSION['ff_user']['id'] ?? 0);
$sessionId = (int) ($_GET['id'] ?? 0);

if ($sessionId <= 0) {
    json_error('VALIDATION_ERROR', 'Session ID is required', 400);
}

// WHY: Verify session belongs to the requesting user
$session = db_row(
    "SELECT id, session_title AS title, context_type, context_id, created_at, last_message_at
     FROM ai_chat_sessions
     WHERE id = ? AND user_id = ?",
    [$sessionId, $userId]
);

if (!$session) {
    json_error('NOT_FOUND', 'Chat session not found', 404);
}

// ────────────────────────────────────────────────────────────
// GET — load session with all messages
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $messages = db_select(
        "SELECT id, role, content, tokens_used, created_at
         FROM ai_chat_messages
         WHERE session_id = ?
         ORDER BY id ASC",
        [$sessionId]
    );

    echo json_encode([
        'session'  => $session,
        'messages' => $messages,
    ]);
    exit;
}

// ────────────────────────────────────────────────────────────
// DELETE — remove session and all its messages
// ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if (!can('ai', 'edit')) {
        json_error('FORBIDDEN', 'Forbidden', 403);
    }

    // WHY: Messages have FK CASCADE on session_id, so deleting session removes messages
    db_execute("DELETE FROM ai_chat_sessions WHERE id = ? AND user_id = ?", [$sessionId, $userId]);

    echo json_encode(['success' => true]);
    exit;
}

json_error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
