<?php
declare(strict_types=1);

/**
 * app/portal/chat/index.php
 *
 * S-CHAT-REBUILD — Messages (customer side). One text thread with the team,
 * shared by everyone on this customer's portal. Customers can attach their
 * own leases, invoices and payments; staff replies can carry the same live
 * cards. Replaces the old Chat (CHAT-2) and Messages (MSGR-1) pages.
 *
 * Deep link: ?attach=invoice:42 pre-attaches one of their records (the
 * "Message us" button on an invoice / lease page).
 *
 * Dependencies: app/portal/includes/{auth,ui,header,footer}.php,
 *               includes/partials/chat-thread.php, app/portal/api/chat/*,
 *               public/assets/js/chat.js (FF_ChatApp), public/assets/css/chat.css
 * @session S-CHAT-REBUILD
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

require_once dirname(__DIR__) . '/includes/ui.php';
require_once FF_ROOT . '/includes/partials/chat-icons.php';

$pageTitle = 'Messages';
$company   = (string) settings_get('company.name', 'our team');
$attach    = (string) ($_GET['attach'] ?? '');

$chatConfig = [
    'side'        => 'portal',
    'companyName' => $company,
    'deepLink'    => ['attach' => preg_match('/^(lease|invoice|payment):\d{1,10}$/', $attach) ? $attach : null],
    'icons'       => ff_chat_record_icons(),
    'api'         => [
        'thread'  => '/portal/api/chat/thread.php',
        'send'    => '/portal/api/chat/send.php',
        'unsend'  => '/portal/api/chat/unsend.php',
        'records' => '/portal/api/chat/records.php',
    ],
];

require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'eyebrow' => 'Help',
    'title'   => 'Messages',
    'sub'     => 'Text ' . $company . ' — everyone on your account sees this conversation. Tap the paper clip to share an invoice, lease or payment.',
]);
?>
<link rel="stylesheet" href="<?= asset_url('assets/css/chat.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">

<div class="cx cx--portal" x-data="FF_ChatApp(<?= e(json_encode($chatConfig, JSON_UNESCAPED_SLASHES)) ?>)">
    <section class="cx-thread" aria-label="Conversation with <?= e($company) ?>">
        <template x-if="!loadingThread && !messages.length">
            <div class="cx-blank">
                <?= ff_chat_icon('chat-bubble-left-right') ?>
                <strong>Send us a message</strong>
                <p>Questions about an invoice, a unit or your lease? Ask here — our team replies in this thread.</p>
            </div>
        </template>
        <?php $cxPlaceholder = "'Message " . addslashes($company) . "…'"; ?>
        <?php require FF_ROOT . '/includes/partials/chat-thread.php'; ?>
    </section>
</div>

<script src="<?= asset_url('assets/js/chat.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
