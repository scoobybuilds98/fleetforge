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

require_once __DIR__ . '/../../../config/app.php';
require_once FF_ROOT . '/includes/auth.php';

require_auth_api();

if (!can('ai', 'view')) {
    http_response_code(403);
    echo json_encode(['error' => true, 'message' => 'Forbidden']);
    exit;
}

header('Content-Type: application/json');

$userId    = (int) ($_SESSION['ff_user']['id'] ?? 0);
$sessionId = (int) ($_GET['id'] ?? 0);

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => true, 'message' => 'Session ID is required']);
    exit;
}

// WHY: Verify session belongs to the requesting user
$session = db_row(
    "SELECT id, session_title AS title, context_type, context_id, created_at, last_message_at
     FROM ai_chat_sessions
     WHERE id = ? AND user_id = ?",
    [$sessionId, $userId]
);

if (!$session) {
    http_response_code(404);
    echo json_encode(['error' => true, 'message' => 'Chat session not found']);
    exit;
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
        http_response_code(403);
        echo json_encode(['error' => true, 'message' => 'Forbidden']);
        exit;
    }

    // WHY: Messages have FK CASCADE on session_id, so deleting session removes messages
    db_execute("DELETE FROM ai_chat_sessions WHERE id = ? AND user_id = ?", [$sessionId, $userId]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => true, 'message' => 'Method not allowed']);
