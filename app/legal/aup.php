<?php
declare(strict_types=1);

/**
 * app/legal/aup.php — Acceptable Use Policy (public route /legal/aup)
 *
 * Session: S-LEGAL-FOOTER-COMMERCIAL (2026-05-17)
 */

require_once realpath(dirname(__DIR__, 2) . '/config/app.php');

$pageTitle = 'Acceptable Use Policy';
require FF_ROOT . '/includes/legal_header.php';
?>

<div class="legal-hero">
    <h1>Acceptable Use Policy</h1>
    <p>Effective: January 1, 2026 &nbsp;·&nbsp; Last updated: May 17, 2026 &nbsp;·&nbsp; Incorporated into the <a href="<?= e(legal_url('terms')) ?>">Terms of Service</a></p>
</div>

<div class="legal-highlight">
    Using FleetForge means agreeing to use it for legitimate fleet operations only. This Acceptable Use Policy sets out what the platform is for, what it isn't, and what happens when those lines are crossed.
</div>

<div class="legal-toc">
    <h3>Contents</h3>
    <ol>
        <li><a href="#overview">Overview</a></li>
        <li><a href="#permitted">Permitted Uses</a></li>
        <li><a href="#prohibited">Prohibited Uses</a></li>
        <li><a href="#driver-privacy">Driver &amp; Employee Privacy</a></li>
        <li><a href="#data-integrity">Data Integrity</a></li>
        <li><a href="#api">API Use</a></li>
        <li><a href="#reporting">Reporting Violations</a></li>
        <li><a href="#consequences">Consequences</a></li>
        <li><a href="#governing">Governing Terms</a></li>
    </ol>
</div>

<div class="legal-section" id="overview">
    <h2>1. Overview</h2>
    <p>This Acceptable Use Policy ("AUP") describes the acceptable and unacceptable uses of FleetForge. It is incorporated into and forms part of the <a href="<?= e(legal_url('terms')) ?>">Terms of Service</a>. By using FleetForge you agree to follow this AUP, and you agree to ensure that your Authorized Users follow it.</p>
</div>

<div class="legal-section" id="permitted">
    <h2>2. Permitted Uses</h2>
    <p>FleetForge is built for commercial fleet management. Typical and welcome use cases include:</p>
    <ul>
        <li>Tracking trucks, trailers, chassis, and related equipment</li>
        <li>Managing leases, rental agreements, reservations, and pickup/return logistics</li>
        <li>Generating customer invoices, recording payments, and tracking accounts receivable</li>
        <li>Compliance tracking (CVI, MVI, registration, insurance expiries)</li>
        <li>Maintenance work orders and damage claim records</li>
        <li>Driver inspection reports (DVIR) and post-trip inspections</li>
        <li>Telematics monitoring of fleet vehicles in line with employment law and driver notification requirements</li>
        <li>Financial reporting, mileage logs, and operational analytics</li>
        <li>Customer portal access for your end customers to view their own invoices and reservations</li>
    </ul>
</div>

<div class="legal-section" id="prohibited">
    <h2>3. Prohibited Uses</h2>
    <p>You may not use FleetForge (or permit others to use it) to:</p>
    <h3>3.1 Illegal Activity</h3>
    <ul>
        <li>Facilitate drug trafficking, contraband transport, smuggling, or other criminal logistics</li>
        <li>Transport stolen cargo or stolen equipment, or conceal records relating to such activity</li>
        <li>Violate Canadian transportation law (hours-of-service falsification, weight law violations, cabotage breaches)</li>
        <li>Engage in tax evasion or fraud against carriers, shippers, or insurance providers</li>
    </ul>
    <h3>3.2 Security &amp; Abuse</h3>
    <ul>
        <li>Attempt to gain unauthorized access to any part of FleetForge or any other Client's data</li>
        <li>Circumvent, disable, or interfere with security features (rate limiting, authentication, audit logging)</li>
        <li>Scrape, harvest, or systematically extract data from FleetForge using automated tools beyond the published API</li>
        <li>Launch denial-of-service attacks, brute-force credential attacks, or vulnerability probing against FleetForge or its infrastructure</li>
        <li>Upload malware, viruses, worms, or any code intended to disrupt or damage software or hardware</li>
        <li>Share or sell login credentials; each Authorized User must be a real individual</li>
    </ul>
    <h3>3.3 Privacy &amp; Surveillance Misuse</h3>
    <ul>
        <li>Use GPS / telematics features to monitor drivers or employees without complying with applicable employment, labour, and privacy law (BC&nbsp;PIPA requires worker notification)</li>
        <li>Process personal data of any individual without an appropriate legal basis</li>
        <li>Use the platform to harass, stalk, or intimidate any individual, including drivers and customers</li>
        <li>Re-identify individuals from aggregated or anonymized data</li>
    </ul>
    <h3>3.4 Commercial Misuse</h3>
    <ul>
        <li>Resell, sublicense, or distribute FleetForge to third parties without a written white-label or reseller agreement</li>
        <li>Reverse engineer, decompile, or extract the source code or algorithms</li>
        <li>Build a competing fleet management product using insights gained from FleetForge</li>
        <li>Use FleetForge to send unsolicited bulk email (spam) in violation of CASL</li>
    </ul>
    <h3>3.5 Content &amp; Conduct</h3>
    <ul>
        <li>Upload content that infringes intellectual property rights of any third party</li>
        <li>Upload content that is defamatory, obscene, threatening, or harmful to minors</li>
        <li>Misrepresent your identity or your relationship to a customer, driver, or carrier</li>
    </ul>
</div>

<div class="legal-section" id="driver-privacy">
    <h2>4. Driver &amp; Employee Privacy</h2>
    <p>BC&nbsp;PIPA (Personal Information Protection Act) and similar Canadian privacy laws place specific obligations on employers who monitor employees through telematics or GPS.</p>
    <p>If you enable GPS monitoring features in FleetForge, you are responsible for:</p>
    <ul>
        <li>Notifying drivers and employees in writing of the type and purpose of monitoring</li>
        <li>Ensuring monitoring is reasonable and proportionate to legitimate business needs</li>
        <li>Limiting use of telematics data to those business purposes (e.g. dispatch, safety, billing) and not for off-duty surveillance</li>
        <li>Honouring any applicable collective agreements, employment contracts, or industry codes</li>
    </ul>
    <p>Avi Technologies does not independently monitor your drivers. The telematics integration is enabled by you and the data belongs to you.</p>
</div>

<div class="legal-section" id="data-integrity">
    <h2>5. Data Integrity</h2>
    <p>You are responsible for the accuracy of data entered into FleetForge. You may not:</p>
    <ul>
        <li>Falsify or back-date compliance records (CVI, MVI, registration, inspection reports)</li>
        <li>Fabricate invoices, payment receipts, or financial entries</li>
        <li>Alter audit logs or attempt to suppress automatic record-keeping</li>
        <li>Misrepresent equipment specifications, condition, or ownership in lease documents</li>
    </ul>
    <p>FleetForge maintains an immutable audit trail of state-changing actions. This audit trail may be requested as evidence by regulators, courts, or insurance providers in the event of investigation.</p>
</div>

<div class="legal-section" id="api">
    <h2>6. API Use</h2>
    <p>FleetForge's HTTP API is subject to rate limiting. You agree to:</p>
    <ul>
        <li>Use only documented API endpoints</li>
        <li>Respect rate limits (default: 60 requests per minute per IP, higher for authenticated sessions)</li>
        <li>Include a clear identifier in your User-Agent header for non-browser automation</li>
        <li>Not retry requests in tight loops on receiving a 429 or 5xx response</li>
        <li>Not use the API to circumvent UI restrictions (e.g. permission checks)</li>
    </ul>
    <p>Sustained API abuse may result in immediate IP-level blocking with no prior notice.</p>
</div>

<div class="legal-section" id="reporting">
    <h2>7. Reporting Violations</h2>
    <p>To report a violation of this AUP, suspected security issue, or abusive use of FleetForge, email <a href="mailto:security@avitechnologies.ca">security@avitechnologies.ca</a>. Provide as much detail as possible: account name, observed behaviour, timestamps, and any relevant URLs. We treat reports confidentially.</p>
</div>

<div class="legal-section" id="consequences">
    <h2>8. Consequences</h2>
    <p>Avi Technologies reserves the right to enforce this AUP at our discretion. Depending on the severity and frequency of a violation, consequences may include:</p>
    <ul>
        <li>A written warning</li>
        <li>Temporary suspension of the offending Authorized User's access</li>
        <li>Temporary suspension of the entire Client account</li>
        <li>Permanent termination of the Subscription (without refund, where permitted by law)</li>
        <li>Referral to law enforcement or regulatory authorities for serious violations</li>
    </ul>
    <p>For violations involving illegal activity or serious security risk, Avi Technologies may suspend access immediately without prior notice, while the issue is investigated.</p>
</div>

<div class="legal-section" id="governing">
    <h2>9. Governing Terms</h2>
    <p>This AUP forms part of the <a href="<?= e(legal_url('terms')) ?>">Terms of Service</a> between you and Avi Technologies Inc. Capitalized terms used but not defined here have the meanings given in the Terms.</p>
    <p>For questions about this AUP, contact <a href="mailto:legal@avitechnologies.ca">legal@avitechnologies.ca</a>.</p>
</div>

<?php require FF_ROOT . '/includes/legal_footer.php'; ?>
