<?php
declare(strict_types=1);

/**
 * app/legal/security.php — Security Policy (public route /legal/security)
 *
 * Session: S-LEGAL-FOOTER-COMMERCIAL (2026-05-17)
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');

$pageTitle = 'Security & Trust';
require FF_ROOT . '/includes/legal_header.php';
?>

<div class="legal-hero">
    <h1>Security &amp; Trust</h1>
    <p>How FleetForge protects your data — last updated May 17, 2026</p>
</div>

<div class="legal-highlight">
    Security is the foundation of trust. This page describes how Avi Technologies protects the data you store in FleetForge — at the infrastructure level, the application level, and the operational level — and how to report security issues responsibly.
</div>

<div class="legal-toc">
    <h3>Contents</h3>
    <ol>
        <li><a href="#commitment">Our Security Commitment</a></li>
        <li><a href="#infrastructure">Infrastructure Security</a></li>
        <li><a href="#application">Application Security</a></li>
        <li><a href="#access">Access Controls</a></li>
        <li><a href="#data">Data Security</a></li>
        <li><a href="#disclosure">Responsible Disclosure</a></li>
        <li><a href="#incident">Incident Response</a></li>
        <li><a href="#compliance">Compliance</a></li>
        <li><a href="#third-party">Third-Party Security</a></li>
        <li><a href="#questions">Questions</a></li>
    </ol>
</div>

<div class="legal-section" id="commitment">
    <h2>1. Our Security Commitment</h2>
    <p>Avi Technologies designs FleetForge with defense in depth — multiple layers of protection so a failure at any one layer does not compromise client data. We are committed to:</p>
    <ul>
        <li>Encrypting data at rest and in transit</li>
        <li>Principle of least privilege for both staff and code</li>
        <li>Auditable record of every state-changing action</li>
        <li>Transparent communication when something goes wrong</li>
        <li>Continuous improvement through internal review and external testing</li>
    </ul>
</div>

<div class="legal-section" id="infrastructure">
    <h2>2. Infrastructure Security</h2>
    <ul>
        <li><strong>Hosted on Amazon Web Services</strong> with primary region in Canada (ca-central-1) and disaster recovery infrastructure in the United States</li>
        <li><strong>Encryption at rest</strong> — AES-256 for databases (RDS), file storage (S3), and backups</li>
        <li><strong>Encryption in transit</strong> — TLS 1.2 minimum, modern cipher suites only, HSTS enforced</li>
        <li><strong>Network isolation</strong> — VPCs, security groups, and least-privilege IAM policies; production database is not directly accessible from the public internet</li>
        <li><strong>Automated backups</strong> — daily full backups with 30-day retention; point-in-time recovery available for the last 7 days</li>
        <li><strong>Disaster recovery</strong> — RPO ≤ 24 hours, RTO ≤ 8 hours; recovery procedures tested at least annually</li>
        <li><strong>Uptime commitment</strong> — 99.5% monthly SLA</li>
    </ul>
</div>

<div class="legal-section" id="application">
    <h2>3. Application Security</h2>
    <ul>
        <li><strong>Authentication</strong> — passwords hashed with bcrypt (cost factor 12); never stored in plain text or reversibly encrypted</li>
        <li><strong>Session management</strong> — secure, HttpOnly, SameSite=Lax cookies; session regenerated on login and privilege change</li>
        <li><strong>CSRF protection</strong> — token validated on every state-changing request</li>
        <li><strong>Input validation</strong> — server-side validation on every endpoint; allow-lists where the value space is bounded</li>
        <li><strong>Output escaping</strong> — context-appropriate escaping (HTML, attribute, JS, URL) to prevent XSS</li>
        <li><strong>SQL injection prevention</strong> — exclusively parameterized queries via PDO; no string concatenation of user input into SQL</li>
        <li><strong>Rate limiting</strong> — login attempts and API endpoints rate-limited per IP and per user</li>
        <li><strong>Account lockout</strong> — automatic 15-minute lockout after 5 failed login attempts within 15 minutes</li>
        <li><strong>File uploads</strong> — MIME type detected server-side (never trusting client headers); allowed types restricted; uploaded files served via signed URLs only</li>
        <li><strong>Content Security Policy</strong> — strict CSP with no inline scripts, no unsafe-eval, vendor assets self-hosted</li>
        <li><strong>Dependency management</strong> — Composer + npm dependencies scanned for known vulnerabilities on every build; security advisories tracked</li>
    </ul>
</div>

<div class="legal-section" id="access">
    <h2>4. Access Controls</h2>
    <ul>
        <li><strong>Role-based access control</strong> — five built-in roles (Super Admin, Manager, Dispatcher, Accountant, Read-only) plus per-user permission overrides</li>
        <li><strong>Audit logging</strong> — every create / update / delete recorded in an immutable audit log with user, IP, user-agent, and timestamp</li>
        <li><strong>Admin-only account creation</strong> — no public self-registration; new users join only by invitation from an existing admin</li>
        <li><strong>Multi-factor authentication</strong> — TOTP-based MFA supported; required-for-role policy enforceable per Client</li>
        <li><strong>Session timeout</strong> — idle sessions expire after 8 hours by default; configurable per Client</li>
        <li><strong>Internal access</strong> — Avi Technologies staff access production data only on a need-to-know basis, with all access logged and reviewed</li>
    </ul>
</div>

<div class="legal-section" id="data">
    <h2>5. Data Security</h2>
    <ul>
        <li><strong>Tenant isolation</strong> — each Client's data is logically isolated at the database row level with foreign-key constraints; queries always scoped to the current Client</li>
        <li><strong>GPS &amp; telematics data</strong> — encrypted at rest, retained for 90 days hot + up to 1 year archived, never sold</li>
        <li><strong>Financial data</strong> — encrypted at rest; tax data retained per CRA requirements (7 years)</li>
        <li><strong>PII handling</strong> — minimum necessary for service operation; see the <a href="<?= e(legal_url('privacy')) ?>">Privacy Policy</a> for full details</li>
        <li><strong>Data deletion</strong> — upon Subscription termination, data is deleted or anonymized within 90 days (subject to legal retention)</li>
    </ul>
</div>

<div class="legal-section" id="disclosure">
    <h2>6. Responsible Disclosure</h2>
    <p>We welcome reports from security researchers and any user who identifies a vulnerability in FleetForge. Please report responsibly:</p>
    <p><strong>Email:</strong> <a href="mailto:security@avitechnologies.ca">security@avitechnologies.ca</a></p>
    <p>We commit to:</p>
    <ul>
        <li>Acknowledge your report within 48 hours</li>
        <li>Investigate promptly and keep you informed of progress</li>
        <li>Not pursue legal action against good-faith security researchers who:
            <ul>
                <li>Do not access client data beyond what is necessary to demonstrate the vulnerability</li>
                <li>Do not perform denial-of-service attacks or destructive testing</li>
                <li>Do not use social engineering against Avi Technologies staff or its customers</li>
                <li>Give us a reasonable opportunity to remediate before public disclosure</li>
            </ul>
        </li>
        <li>Credit researchers in our security acknowledgements (if requested)</li>
    </ul>
    <p>We do not currently operate a paid bug bounty program but appreciate every report.</p>
</div>

<div class="legal-section" id="incident">
    <h2>7. Incident Response</h2>
    <ul>
        <li><strong>Initial assessment</strong> within 4 hours of detection</li>
        <li><strong>Containment</strong> as the first priority — affected systems isolated, credentials rotated</li>
        <li><strong>Client notification</strong> within 72 hours where personal data is affected, per PIPEDA obligations</li>
        <li><strong>Root cause analysis</strong> conducted after the incident is resolved</li>
        <li><strong>Post-incident report</strong> available to affected Clients on request</li>
        <li><strong>Regulatory notification</strong> handled per PIPEDA, BC&nbsp;PIPA, and applicable foreign law</li>
    </ul>
</div>

<div class="legal-section" id="compliance">
    <h2>8. Compliance</h2>
    <p>FleetForge is designed to support our Clients' compliance with:</p>
    <ul>
        <li><strong>PIPEDA</strong> — Personal Information Protection and Electronic Documents Act (Canada)</li>
        <li><strong>BC&nbsp;PIPA</strong> — Personal Information Protection Act (British Columbia)</li>
        <li><strong>CASL</strong> — Canada's Anti-Spam Legislation (transactional emails are permitted; commercial messaging requires Client-side consent management)</li>
        <li><strong>Canadian transportation regulations</strong> — record retention for commercial fleet operations</li>
        <li><strong>GDPR</strong> — for Clients processing personal data of EU residents, supported via the <a href="<?= e(legal_url('dpa')) ?>">Data Processing Agreement</a> and SCCs as applicable</li>
        <li><strong>CRA invoice requirements</strong> — sequential numbering, GST/HST disclosure, 7-year retention</li>
    </ul>
</div>

<div class="legal-section" id="third-party">
    <h2>9. Third-Party Security</h2>
    <p>We rely on infrastructure providers that maintain industry-standard certifications:</p>
    <ul>
        <li><strong>Amazon Web Services</strong> — ISO 27001, SOC 1, SOC 2 Type II, SOC 3, PCI DSS Level 1, FedRAMP. <a href="https://aws.amazon.com/compliance/" target="_blank" rel="noopener">AWS Compliance →</a></li>
        <li><strong>Samsara</strong> (optional telematics integration) — ISO 27001, SOC 2 Type II</li>
        <li><strong>Anthropic</strong> (optional AI features) — SOC 2 Type II, zero-retention enterprise terms</li>
    </ul>
    <p>Application dependencies are self-hosted within FleetForge (no runtime CDN dependencies for fonts, scripts, or styles) to minimize third-party attack surface.</p>
</div>

<div class="legal-section" id="questions">
    <h2>10. Questions</h2>
    <p>Security questions, compliance documentation requests, or vulnerability reports: <a href="mailto:security@avitechnologies.ca">security@avitechnologies.ca</a></p>
    <p>For SOC 2 reports, penetration test summaries, or other security artefacts under NDA, please contact <a href="mailto:security@avitechnologies.ca">security@avitechnologies.ca</a> and reference your account.</p>
</div>

<?php require FF_ROOT . '/includes/legal_footer.php'; ?>
