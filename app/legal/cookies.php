<?php
declare(strict_types=1);

/**
 * app/legal/cookies.php — Cookie Policy (public route /legal/cookies)
 *
 * Session: S-LEGAL-FOOTER-COMMERCIAL (2026-05-17)
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');

$pageTitle = 'Cookie Policy';
require FF_ROOT . '/includes/legal_header.php';
?>

<div class="legal-hero">
    <h1>Cookie Policy</h1>
    <p>Effective: January 1, 2026 &nbsp;·&nbsp; Last updated: May 17, 2026</p>
</div>

<div class="legal-highlight">
    FleetForge uses only the cookies strictly necessary to operate the service. We do not use advertising cookies, third-party analytics, or cross-site tracking of any kind.
</div>

<div class="legal-toc">
    <h3>Contents</h3>
    <ol>
        <li><a href="#what">What Are Cookies</a></li>
        <li><a href="#how">How FleetForge Uses Cookies</a></li>
        <li><a href="#table">Cookie Inventory</a></li>
        <li><a href="#localstorage">Browser localStorage (not cookies)</a></li>
        <li><a href="#manage">Managing Cookies</a></li>
        <li><a href="#third-party">Third-Party Cookies</a></li>
        <li><a href="#changes">Changes to This Policy</a></li>
        <li><a href="#contact">Contact</a></li>
    </ol>
</div>

<div class="legal-section" id="what">
    <h2>1. What Are Cookies</h2>
    <p>Cookies are small text files stored in your browser when you visit a website. They allow the site to remember information across requests (for example, that you are logged in) and to function correctly across page loads.</p>
    <p>Cookies are categorized as:</p>
    <ul>
        <li><strong>Strictly necessary</strong> — required for the website to function (authentication, security)</li>
        <li><strong>Functional</strong> — remembers user preferences</li>
        <li><strong>Analytics</strong> — measures usage patterns</li>
        <li><strong>Advertising</strong> — tracks users across sites for targeted ads</li>
    </ul>
    <p>FleetForge uses only <strong>strictly necessary</strong> cookies. We do not use functional, analytics, or advertising cookies.</p>
</div>

<div class="legal-section" id="how">
    <h2>2. How FleetForge Uses Cookies</h2>
    <p>The cookies set by FleetForge exist solely to authenticate your session, protect against cross-site request forgery, and remember a 30-day login if you opted in. They are not used for marketing, analytics, or any kind of tracking.</p>
</div>

<div class="legal-section" id="table">
    <h2>3. Cookie Inventory</h2>
    <table>
        <thead>
            <tr><th>Cookie name</th><th>Type</th><th>Duration</th><th>Purpose</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><code>ff_session</code></td>
                <td>Strictly necessary</td>
                <td>Session (8 hours of inactivity, then expires)</td>
                <td>Authentication. Identifies your signed-in account on the server.</td>
            </tr>
            <tr>
                <td><code>ff_remember</code></td>
                <td>Strictly necessary</td>
                <td>30 days (when "Keep me signed in" is selected)</td>
                <td>Persistent login. Cleared on logout.</td>
            </tr>
            <tr>
                <td><code>csrf_token</code></td>
                <td>Strictly necessary</td>
                <td>Session</td>
                <td>CSRF protection. Prevents cross-site request forgery on state-changing actions.</td>
            </tr>
        </tbody>
    </table>
    <p>All cookies are set with <code>HttpOnly</code> (not readable by JavaScript), <code>SameSite=Lax</code> (not sent on cross-site POST requests), and over HTTPS in production.</p>
</div>

<div class="legal-section" id="localstorage">
    <h2>4. Browser localStorage (not cookies)</h2>
    <p>In addition to cookies, FleetForge uses your browser's <code>localStorage</code> to remember UI preferences. These are stored on your device only and are <strong>never transmitted to our servers</strong>:</p>
    <ul>
        <li><code>ff-theme</code> — your dark / light theme preference</li>
        <li><code>ff-display-font-size</code> — display font size scaling</li>
        <li><code>ff-display-density</code> — UI density (compact / comfortable / spacious)</li>
        <li><code>ff-sidebar-scroll</code> — sidebar scroll position between page loads</li>
        <li><code>ff-recent-searches</code> — your recent search queries</li>
    </ul>
    <p>You can clear localStorage at any time using your browser's developer tools or "Clear site data" feature, with no effect on your account.</p>
</div>

<div class="legal-section" id="manage">
    <h2>5. Managing Cookies</h2>
    <p>You can disable cookies in your browser settings. Note that disabling the <code>ff_session</code> cookie will prevent you from signing in to FleetForge — the cookie is the mechanism that proves you are signed in.</p>
    <p>Browser-specific instructions:</p>
    <ul>
        <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener">Chrome</a></li>
        <li><a href="https://support.mozilla.org/en-US/kb/cookies-information-websites-store-on-your-computer" target="_blank" rel="noopener">Firefox</a></li>
        <li><a href="https://support.apple.com/guide/safari/manage-cookies-sfri11471/mac" target="_blank" rel="noopener">Safari</a></li>
        <li><a href="https://support.microsoft.com/en-us/microsoft-edge/delete-cookies-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" rel="noopener">Edge</a></li>
    </ul>
</div>

<div class="legal-section" id="third-party">
    <h2>6. Third-Party Cookies</h2>
    <p>FleetForge does not load third-party scripts that set cookies (no Google Analytics, no Mixpanel, no Facebook Pixel, no advertising networks). Vendor assets such as fonts and chart libraries are self-hosted within the application — no third-party CDN cookies are created during normal use.</p>
</div>

<div class="legal-section" id="changes">
    <h2>7. Changes to This Policy</h2>
    <p>We will update this Policy if our use of cookies changes. The current version is always available at this URL and dated at the top.</p>
</div>

<div class="legal-section" id="contact">
    <h2>8. Contact</h2>
    <p>Questions about cookies? Email <a href="mailto:privacy@avitechnologies.ca">privacy@avitechnologies.ca</a>.</p>
</div>

<?php require FF_ROOT . '/includes/legal_footer.php'; ?>
