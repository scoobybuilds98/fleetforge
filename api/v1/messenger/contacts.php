<?php
/**
 * api/v1/messenger/contacts.php
 *
 * GET ?q=search_term[&type=customers|portal_users]
 *
 * Returns the contact list an admin can start a customer conversation with.
 * The response has two buckets so the UI can render them as expandable groups:
 *
 *   {
 *     "customers": [
 *       { "id": 1, "company_name": "LP Logistics Inc.", "portal_user_count": 2 },
 *       ...
 *     ],
 *     "portal_users": [
 *       { "id": 1, "customer_id": 1, "name": "John Apex", "email": "john@...",
 *         "company_name": "LP Logistics Inc." },
 *       ...
 *     ]
 *   }
 *
 * Both lists are filtered by the ?q search term (case-insensitive LIKE).
 * If `type` is provided, only that bucket is populated; the other is [].
 *
 * Dependencies: api/bootstrap.php, customers, portal_users
 * Spec: MSGR-1
 */
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/api/bootstrap.php';
require_method('GET');
require_auth_api();

// WHY: customer messenger is a customer-facing tool, so reuse customer
// view permission — any user who can see customers can message them.
require_permission('customers', 'view');

$q    = clean_string($_GET['q'] ?? null, 100) ?? '';
$type = clean_string($_GET['type'] ?? null, 20);

$customers    = [];
$portalUsers  = [];

// WHY the 500 cap: the UI shows the whole list unpaginated in a modal,
// so an admin can just scroll through every customer. 500 is well above
// any realistic B2B trucking customer count and keeps the query bounded.
if ($type === null || $type === 'customers') {
    // Customers matching the search term, with portal_user_count so the UI
    // can tell the admin how many contacts exist at each company.
    if ($q !== '') {
        $customers = db_select(
            "SELECT c.id,
                    c.company_name,
                    c.contact_name,
                    c.email,
                    c.status,
                    (SELECT COUNT(*) FROM portal_users pu
                      WHERE pu.customer_id = c.id AND pu.status = 'active') AS portal_user_count
               FROM customers c
              WHERE c.deleted_at IS NULL
                AND (c.company_name LIKE ? OR c.contact_name LIKE ? OR c.email LIKE ?)
              ORDER BY c.company_name ASC
              LIMIT 500",
            ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']
        );
    } else {
        $customers = db_select(
            "SELECT c.id,
                    c.company_name,
                    c.contact_name,
                    c.email,
                    c.status,
                    (SELECT COUNT(*) FROM portal_users pu
                      WHERE pu.customer_id = c.id AND pu.status = 'active') AS portal_user_count
               FROM customers c
              WHERE c.deleted_at IS NULL
              ORDER BY c.company_name ASC
              LIMIT 500"
        );
    }
}

if ($type === null || $type === 'portal_users') {
    // Only active portal users are addressable — invited/inactive cannot
    // read their messages. Joined to customers for the company name.
    if ($q !== '') {
        $portalUsers = db_select(
            "SELECT pu.id, pu.customer_id, pu.name, pu.email,
                    c.company_name
               FROM portal_users pu
               JOIN customers c ON c.id = pu.customer_id AND c.deleted_at IS NULL
              WHERE pu.status = 'active'
                AND (pu.name LIKE ? OR pu.email LIKE ? OR c.company_name LIKE ?)
              ORDER BY c.company_name ASC, pu.name ASC
              LIMIT 500",
            ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%']
        );
    } else {
        $portalUsers = db_select(
            "SELECT pu.id, pu.customer_id, pu.name, pu.email,
                    c.company_name
               FROM portal_users pu
               JOIN customers c ON c.id = pu.customer_id AND c.deleted_at IS NULL
              WHERE pu.status = 'active'
              ORDER BY c.company_name ASC, pu.name ASC
              LIMIT 500"
        );
    }
}

json_success([
    'customers'    => $customers,
    'portal_users' => $portalUsers,
]);
