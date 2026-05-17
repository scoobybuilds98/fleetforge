<?php
declare(strict_types=1);

/**
 * config/legal.php
 *
 * Single source of truth for every piece of company / legal metadata
 * that the footers, login page, customer portal, and the 6 legal pages
 * under /legal/* read from. Change a value here and it propagates to
 * every surface in one shot — no scattered hardcoded strings.
 *
 * Loaded once by config/app.php into $GLOBALS['_ff_legal']; consumers
 * use the legal_config('dot.path') helper in includes/functions.php
 * rather than touching the global directly.
 *
 * Decisions: D7 (router), single-write metadata pattern
 * Session:   S-LEGAL-FOOTER-COMMERCIAL (2026-05-17)
 */

return [
    'company' => [
        'legal_name'    => 'Avi Technologies Inc.',
        'brand_name'    => 'Avi Technologies',
        'product_name'  => 'FleetForge',
        'address'       => 'Surrey, British Columbia, Canada',
        'email_legal'   => 'legal@avitechnologies.ca',
        'email_privacy' => 'privacy@avitechnologies.ca',
        'email_support' => 'support@avitechnologies.ca',
        'email_security' => 'security@avitechnologies.ca',
        'website'       => 'https://avitechnologies.ca',
        'governing_law' => 'British Columbia, Canada',
    ],
    'effective_date' => '2026-01-01',
    'last_updated'   => '2026-05-17',
    'pages' => [
        'terms'    => ['title' => 'Terms of Service',           'url' => '/legal/terms'],
        'privacy'  => ['title' => 'Privacy Policy',             'url' => '/legal/privacy'],
        'aup'      => ['title' => 'Acceptable Use Policy',      'url' => '/legal/aup'],
        'dpa'      => ['title' => 'Data Processing Agreement',  'url' => '/legal/dpa'],
        'cookies'  => ['title' => 'Cookie Policy',              'url' => '/legal/cookies'],
        'security' => ['title' => 'Security Policy',            'url' => '/legal/security'],
    ],
];
