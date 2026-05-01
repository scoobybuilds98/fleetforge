<?php
/**
 * api/v1/chat/customer/index.php
 *
 * GET — Returns all customer conversations for current staff member.
 * WHY: Separate endpoint allows customer-specific filtering and
 *      different sidebar rendering from regular channels.
 *
 * Dependencies: api/bootstrap.php, chat_channels, chat_channel_members,
 *               customers
 * Spec: CHAT-2
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/bootstrap.php';
require_method('GET');
require_auth_api();

$userId = current_user_id();

$convos = db_select(
    "SELECT cc.id, cc.name, cc.slug, cc.description, cc.type, cc.is_archived,
            cc.last_message_at, cc.last_message_preview, cc.customer_id,
            ccm.role, ccm.is_muted, ccm.last_read_message_id,
            (
                SELECT COUNT(*) FROM chat_messages cm
                WHERE cm.channel_id = cc.id
                  AND cm.is_deleted = 0
                  AND (ccm.last_read_message_id IS NULL OR cm.id > ccm.last_read_message_id)
                  AND cm.user_id != ?
            ) AS unread_count,
            c.company_name AS customer_company_name,
            c.contact_name AS customer_contact_name
     FROM chat_channels cc
     JOIN chat_channel_members ccm ON ccm.channel_id = cc.id AND ccm.user_id = ?
     LEFT JOIN customers c ON c.id = cc.customer_id AND c.deleted_at IS NULL
     WHERE cc.type = 'customer' AND cc.is_archived = 0
     ORDER BY cc.last_message_at DESC",
    [$userId, $userId]
);

json_success(['conversations' => $convos]);
