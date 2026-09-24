<?php
declare(strict_types=1);

/**
 * app/portal/includes/sidebar.php
 *
 * Portal sidebar (S-PORTAL-REDESIGN): brand, the account you're signed in
 * for, grouped navigation with live badges, and a contact card. Included by
 * header.php only; on phones it becomes the slide-in menu (the bottom tab
 * bar in footer.php opens it).
 *
 * Logo: the same trimmed derivative the staff sidebar uses
 * (FleetForge\Ui\BrandLogo — served by the public api/v1/storage/logo).
 * A logo exported on a dark canvas sits on a matching dark plate so it
 * blends edge to edge; no logo (or a failed load) falls back to the brand
 * mark + company name.
 *
 * Badges (all Trap 8 — scoped by the signed-in customer / portal user):
 *   Invoices  past-due invoices (red) — was "overdue OR sent", which lit the
 *             badge for every invoice that simply hadn't been paid yet
 *   Requests  requests still open or in review
 *   Messages  unread staff messages in threads this user can see (MSGR-1)
 *   Chat      unread staff chat messages (CHAT-2)
 */

$_sbUser      = portal_user();
$_sbCompany   = (string) settings_get('company.name', 'FleetForge');
$_sbCustomer  = (string) ($_sbUser['company_name'] ?? '');
$_sbPath      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$_sbCid       = portal_customer_id();
$_sbPid       = portal_user_id();
$_sbSummary   = pt_account_summary($_sbCid);

$_sbOpenReq = $_sbUnreadMsg = $_sbUnreadChat = 0;
try {
    $_sbOpenReq = db_count(
        "SELECT COUNT(*) FROM portal_service_requests WHERE customer_id = ? AND status IN ('open','in_review')",
        [$_sbCid]
    );
    // [CHAT-2] Unread staff messages in customer chat channels
    $_sbUnreadChat = db_count(
        "SELECT COUNT(*) FROM chat_messages cm
           JOIN chat_channels cc ON cc.id = cm.channel_id
                AND cc.type = 'customer' AND cc.customer_id = ? AND cc.is_archived = 0
           LEFT JOIN chat_channel_members ccm
                ON ccm.channel_id = cm.channel_id AND ccm.portal_user_id = ?
          WHERE cm.is_deleted = 0
            AND cm.portal_user_id IS NULL
            AND (ccm.last_read_message_id IS NULL OR cm.id > ccm.last_read_message_id)",
        [$_sbCid, $_sbPid]
    );
    // [MSGR-1] Unread admin messages in threads this portal user may see
    $_sbUnreadMsg = db_count(
        "SELECT COUNT(*) AS cnt
           FROM messenger_messages mm
           JOIN messenger_threads mt ON mt.id = mm.thread_id AND mt.is_archived = 0
           LEFT JOIN messenger_thread_reads mtr
                  ON mtr.thread_id = mm.thread_id AND mtr.portal_user_id = ?
          WHERE mm.sender_type = 'admin'
            AND mm.is_archived = 0
            AND mt.customer_id = ?
            AND (mt.scope = 'customer' OR (mt.scope = 'portal_user' AND mt.portal_user_id = ?))
            AND (mtr.last_read_message_id IS NULL OR mm.id > mtr.last_read_message_id)",
        [$_sbPid, $_sbCid, $_sbPid]
    );
} catch (Throwable) {}

// [label, path under /portal, icon, badge count, badge style]
$_sbNav = [
    '' => [
        ['Home', '', 'home', 0, ''],
    ],
    'Billing' => [
        ['Pay & payments', 'payments', 'credit-card', 0, ''],
        ['Invoices',       'invoices', 'document-text', $_sbSummary['past_due_count'], 'is-alert'],
    ],
    'Fleet' => [
        ['Leases',    'leases',    'clipboard-document-list', $_sbSummary['active_leases'], ''],
        ['Equipment', 'equipment', 'truck', 0, ''],
        ['Documents', 'documents', 'folder-open', 0, ''],
    ],
    'Help' => [
        ['Requests', 'requests', 'wrench-screwdriver', $_sbOpenReq, ''],
        ['Messages', 'messages', 'envelope', $_sbUnreadMsg, 'is-brand'],
        ['Chat',     'chat',     'chat-bubble-left-right', $_sbUnreadChat, 'is-brand'],
    ],
    'Account' => [
        ['Credit application', 'credit-applications', 'clipboard-document-check', 0, ''],
        ['Settings',           'account',             'cog-6-tooth', 0, ''],
    ],
];

$_sbLogo = null;
try { $_sbLogo = \FleetForge\Ui\BrandLogo::forSidebar(); } catch (\Throwable) { $_sbLogo = null; }
$_sbLogoUrl  = (string) ($_sbLogo['url'] ?? '');
$_sbLogoDark = $_sbLogo !== null && in_array((string) ($_sbLogo['bg'] ?? ''), ['dark', ''], true);

$_sbPhone = (string) settings_get('company.phone', '');
$_sbEmail = (string) settings_get('company.email', '');
?>
<aside class="pt-side" aria-label="Portal navigation">

    <div class="pt-brand">
        <a class="pt-brand-link" href="<?= e(pt_url()) ?>" aria-label="<?= e($_sbCompany) ?> — Home">
            <?php if ($_sbLogoUrl !== ''): ?>
                <span class="pt-brand-plate<?= $_sbLogoDark ? ' is-dark' : '' ?>">
                    <img src="<?= e($_sbLogoUrl) ?>" alt="<?= e($_sbCompany) ?>"
                         onerror="this.closest('.pt-brand').classList.add('is-logo-broken')">
                </span>
            <?php endif; ?>
            <span class="pt-brand-fallback<?= $_sbLogoUrl !== '' ? ' has-logo' : '' ?>">
                <span class="pt-brand-mark"><?= pt_icon('truck') ?></span>
                <span class="pt-brand-name"><?= e($_sbCompany) ?></span>
            </span>
        </a>
        <span class="pt-brand-sub">Customer portal</span>
    </div>

    <?php if ($_sbCustomer !== ''): ?>
    <div class="pt-account" title="Signed in for <?= e($_sbCustomer) ?>">
        <span class="pt-account-ic"><?= pt_icon('building-office') ?></span>
        <span class="pt-account-text">
            <span class="pt-account-label">Account</span>
            <span class="pt-account-name" style="display:block"><?= e($_sbCustomer) ?></span>
        </span>
    </div>
    <?php endif; ?>

    <nav class="pt-nav">
        <?php foreach ($_sbNav as $_sbGroup => $_sbItems): ?>
            <?php if ($_sbGroup !== ''): ?>
                <div class="pt-nav-label"><?= e($_sbGroup) ?></div>
            <?php endif; ?>
            <?php foreach ($_sbItems as [$_l, $_p, $_i, $_b, $_bs]):
                $_full = FF_BASE_PATH . '/portal' . ($_p !== '' ? '/' . $_p : '');
                $_active = $_p === ''
                    ? ($_sbPath === $_full || $_sbPath === $_full . '/' || $_sbPath === $_full . '/index')
                    : ($_sbPath === $_full || str_starts_with($_sbPath, $_full . '/'));
            ?>
                <a href="<?= e(pt_url($_p)) ?>" class="pt-nav-item<?= $_active ? ' is-active' : '' ?>"<?= $_active ? ' aria-current="page"' : '' ?>>
                    <?= pt_icon($_i) ?>
                    <span><?= e($_l) ?></span>
                    <?php if ($_b > 0): ?>
                        <span class="pt-nav-badge <?= e($_bs) ?>"><?= e($_b > 99 ? '99+' : (string) $_b) ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>

    <div class="pt-side-foot">
        <div class="pt-help">
            <p class="pt-help-title">Need a hand?</p>
            <p class="pt-help-text">Our team is here for billing questions, extensions and repairs.</p>
            <?php if ($_sbPhone !== ''): ?>
                <a class="pt-help-row" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $_sbPhone)) ?>"><?= pt_icon('phone') ?><span><?= e($_sbPhone) ?></span></a>
            <?php endif; ?>
            <?php if ($_sbEmail !== ''): ?>
                <a class="pt-help-row" href="mailto:<?= e($_sbEmail) ?>"><?= pt_icon('envelope') ?><span><?= e($_sbEmail) ?></span></a>
            <?php endif; ?>
            <a class="pt-help-row" href="<?= e(pt_url('requests/create')) ?>"><?= pt_icon('plus') ?><span>Start a request</span></a>
        </div>
    </div>

</aside>
<?php
unset($_sbUser, $_sbCompany, $_sbCustomer, $_sbPath, $_sbCid, $_sbPid, $_sbSummary, $_sbOpenReq, $_sbUnreadMsg,
      $_sbUnreadChat, $_sbNav, $_sbGroup, $_sbItems, $_l, $_p, $_i, $_b, $_bs, $_full, $_active, $_sbLogo,
      $_sbLogoUrl, $_sbLogoDark, $_sbPhone, $_sbEmail);
?>
