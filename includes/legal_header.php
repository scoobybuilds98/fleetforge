<?php
declare(strict_types=1);

/**
 * includes/legal_header.php
 *
 * Top of every public /legal/* page. Standalone shell — no sidebar,
 * no topbar, no auth requirement. Light theme forced so the legal
 * copy reads comfortably regardless of the visitor's preference
 * (a logged-in admin clicking from the dark dashboard sees a
 * familiar "documents" surface).
 *
 * Required globals before include:
 *   $pageTitle — short title shown in <title> and on the hero block
 *
 * Pairs with: includes/legal_footer.php
 * Session:    S-LEGAL-FOOTER-COMMERCIAL
 */

use FleetForge\Storage\StorageClient;

$_legal = legal_config();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="index, follow">
    <title><?= e($pageTitle ?? 'Legal') ?> — <?= e($_legal['company']['product_name']) ?></title>
    <?= ff_favicon_tags() ?>
    <link rel="stylesheet" href="<?= asset_url('assets/css/app.css') ?>?v=<?= e(FF_ASSET_VERSION) ?>">
    <style>
        /* Legal page overrides — light theme forced, no sidebar.
           Scoped tokens override the global ones for this surface only. */
        body { background:#f8fafc; color:#0f172a; font-family:'DM Sans', sans-serif; min-height:100vh; display:flex; flex-direction:column; }
        .legal-nav { display:flex; align-items:center; justify-content:space-between; padding:16px 40px; background:#fff; border-bottom:1px solid #e2e8f0; position:sticky; top:0; z-index:100; }
        .legal-nav-brand { display:flex; align-items:center; gap:10px; font-weight:600; font-size:1rem; color:#0f172a; text-decoration:none; }
        .legal-nav-brand img { height:32px; width:auto; object-fit:contain; }
        .legal-nav-links { display:flex; gap:24px; flex-wrap:wrap; }
        .legal-nav-links a { font-size:0.8125rem; color:#64748b; text-decoration:none; }
        .legal-nav-links a:hover { color:#0f172a; }
        .legal-wrap { max-width:860px; margin:0 auto; padding:48px 24px 80px; flex:1; width:100%; }
        .legal-hero { margin-bottom:40px; padding-bottom:32px; border-bottom:1px solid #e2e8f0; }
        .legal-hero h1 { font-size:2rem; font-weight:700; margin:0 0 8px; color:#0f172a; line-height:1.2; }
        .legal-hero p { color:#64748b; font-size:0.9375rem; margin:0; }
        .legal-toc { background:#f1f5f9; border-radius:10px; padding:20px 24px; margin-bottom:40px; }
        .legal-toc h3 { font-size:0.8125rem; font-weight:600; color:#475569; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 12px; }
        .legal-toc ol { margin:0; padding-left:20px; columns:2; column-gap:24px; }
        .legal-toc li { margin-bottom:6px; break-inside:avoid; }
        .legal-toc a { color:#2596be; font-size:0.875rem; text-decoration:none; }
        .legal-toc a:hover { text-decoration:underline; }
        .legal-section { margin-bottom:40px; scroll-margin-top:80px; }
        .legal-section h2 { font-size:1.25rem; font-weight:600; color:#0f172a; margin:0 0 16px; padding-top:8px; }
        .legal-section h3 { font-size:1rem; font-weight:600; color:#1e293b; margin:24px 0 10px; }
        .legal-section p { color:#334155; line-height:1.75; margin:0 0 14px; font-size:0.9375rem; }
        .legal-section ul, .legal-section ol { color:#334155; line-height:1.75; padding-left:24px; margin:0 0 14px; font-size:0.9375rem; }
        .legal-section li { margin-bottom:6px; }
        .legal-section a { color:#2596be; }
        .legal-section table { width:100%; border-collapse:collapse; margin:16px 0; font-size:0.875rem; }
        .legal-section table th, .legal-section table td { padding:10px 14px; border:1px solid #e2e8f0; text-align:left; }
        .legal-section table th { background:#f1f5f9; font-weight:600; color:#1e293b; }
        .legal-highlight { background:#eff6ff; border-left:3px solid #2596be; border-radius:0 8px 8px 0; padding:14px 18px; margin:20px 0; font-size:0.9rem; color:#1e40af; }
        .legal-bottom { background:#fff; border-top:1px solid #e2e8f0; padding:32px 40px 24px; }
        .legal-footer-nav { display:flex; flex-wrap:wrap; gap:16px 24px; justify-content:center; }
        .legal-footer-nav a { font-size:0.8125rem; color:#64748b; text-decoration:none; }
        .legal-footer-nav a:hover { color:#0f172a; }
        .legal-copyright { text-align:center; margin-top:16px; font-size:0.75rem; color:#94a3b8; }
        @media (max-width:640px) {
            .legal-nav { padding:14px 20px; }
            .legal-wrap { padding:32px 16px 60px; }
            .legal-hero h1 { font-size:1.5rem; }
            .legal-toc ol { columns:1; }
            .legal-bottom { padding:24px 20px 20px; }
        }
    </style>
</head>
<body>
<nav class="legal-nav">
    <a href="<?= e(base_url('dashboard')) ?>" class="legal-nav-brand">
        <?php
        // Brand logo lookup mirrors the login page: settings_get first,
        // public/media/login-logo.* second, inline SVG truck as last resort.
        $_navLogoKey = (string) (settings_get('brand.logo_path') ?? '');
        $_navLogoUrl = '';
        if ($_navLogoKey !== '') {
            try {
                $_navLogoUrl = StorageClient::url($_navLogoKey, 3600);
            } catch (\Throwable) { /* fall through to file fallback */ }
        }
        if ($_navLogoUrl === '') {
            foreach (['svg', 'png', 'jpg', 'jpeg'] as $_ext) {
                $_candidate = FF_ROOT . '/public/media/login-logo.' . $_ext;
                if (is_file($_candidate)) {
                    $_navLogoUrl = asset_url('media/login-logo.' . $_ext);
                    break;
                }
            }
        }
        ?>
        <?php if ($_navLogoUrl !== ''): ?>
            <img src="<?= e($_navLogoUrl) ?>" alt="<?= e(settings_get('company.name') ?: $_legal['company']['product_name']) ?>">
        <?php else: ?>
            <svg width="24" height="24" viewBox="0 0 32 32" fill="none" aria-hidden="true">
                <rect x="2" y="12" width="18" height="11" rx="2" fill="#2596be"/>
                <path d="M20 17 L20 23 L28 23 L28 19 L25 13 L22 13 L20 17Z" fill="#2596be"/>
                <circle cx="7" cy="24" r="3" fill="#1e293b" stroke="#2596be" stroke-width="1.5"/>
                <circle cx="23" cy="24" r="3" fill="#1e293b" stroke="#2596be" stroke-width="1.5"/>
            </svg>
            <span><?= e($_legal['company']['product_name']) ?></span>
        <?php endif; ?>
    </a>
    <div class="legal-nav-links">
        <a href="<?= e(legal_url('terms')) ?>">Terms</a>
        <a href="<?= e(legal_url('privacy')) ?>">Privacy</a>
        <a href="<?= e(legal_url('aup')) ?>">Acceptable Use</a>
        <a href="<?= e(legal_url('security')) ?>">Security</a>
        <a href="mailto:<?= e($_legal['company']['email_support']) ?>">Contact</a>
    </div>
</nav>
<main class="legal-wrap">
