-- ============================================================================
-- S-PORTAL-REQUEST-ROUTING — Portal service request notification routing
-- ============================================================================
--
-- Closes the structural gap that portal service requests submitted via
-- app/portal/requests/create.php INSERT'd into portal_service_requests but
-- never fired any notification — operator-caught 2026-05-29 ("submitted a
-- service request from the customer portal, didnt get any notification on
-- super admin? also how do we decide who gets these service requests? you
-- forget to map it").
--
-- Seeds routing settings keys per request_type ENUM value. Each request type
-- has TWO settings:
--   - portal_requests.routing.{type}.role_slugs — JSON array of user_roles.slug
--   - portal_requests.routing.{type}.user_ids   — JSON array of users.id (override)
--
-- The 7 request_types are taken verbatim from app/portal/requests/create.php
-- $validTypes array (lease_extension, early_return, damage_report,
-- billing_inquiry, document_request, new_lease_inquiry, general) plus a
-- 'default' fallback bucket used when a request type's routing is empty.
--
-- Plus one safety-net toggle:
--   - portal_requests.routing.always_include_super_admin = '1' (default)
--     When '1', super_admin users are ALWAYS notified regardless of routing
--     config. Prevents an empty/broken routing config from silently dropping
--     requests on the floor. Operator can flip to '0' for finer control once
--     routing is verified working.
--
-- Per D-PORTAL-REQUEST-ROUTING-1: settings-keys storage (no new table)
--   mirrors ai.briefing_recipient_roles / ai.budget_alert_recipients JSON
--   array convention; same selective-save (_form_keys[]) pattern enables
--   per-request-type save without clobbering sibling routing rows.
-- Per D-PORTAL-REQUEST-ROUTING-2: role_slugs + user_ids stored separately
--   (NOT unified objects) so the JSON shape matches the existing AI multi-
--   checkbox / typeahead pattern exactly; union resolution at dispatch time.
--
-- @session  S-PORTAL-REQUEST-ROUTING
-- @decision D-PORTAL-REQUEST-ROUTING-1 (settings-keys storage),
--           D-PORTAL-REQUEST-ROUTING-2 (role_slugs + user_ids stored
--               separately; union at dispatch),
--           D-PORTAL-REQUEST-ROUTING-3 (always-include-super_admin safety-
--               net toggle; default '1' to prevent silent-drop bug class),
--           D-PORTAL-REQUEST-ROUTING-4 (default fallback bucket used when
--               type-specific routing is empty)

INSERT INTO `settings` (`key`, `value`, `value_type`, `group_name`, `is_public`, `is_sensitive`, `description`) VALUES
    ('portal_requests.routing.always_include_super_admin', '1', 'boolean', 'portal_requests', 0, 0, 'Safety net: always notify super_admin users regardless of per-type routing config. Prevents silent-drop bug class when routing is misconfigured. Default 1.'),

    ('portal_requests.routing.default.role_slugs', '["super_admin"]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs used when a specific request type has empty routing. Default super_admin.'),
    ('portal_requests.routing.default.user_ids',   '[]',              'string', 'portal_requests', 0, 0, 'JSON array of user IDs (additive to default.role_slugs).'),

    ('portal_requests.routing.lease_extension.role_slugs',   '[]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs to notify for lease_extension requests.'),
    ('portal_requests.routing.lease_extension.user_ids',     '[]', 'string', 'portal_requests', 0, 0, 'JSON array of user IDs to notify for lease_extension requests.'),

    ('portal_requests.routing.early_return.role_slugs',      '[]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs to notify for early_return requests.'),
    ('portal_requests.routing.early_return.user_ids',        '[]', 'string', 'portal_requests', 0, 0, 'JSON array of user IDs to notify for early_return requests.'),

    ('portal_requests.routing.damage_report.role_slugs',     '[]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs to notify for damage_report requests.'),
    ('portal_requests.routing.damage_report.user_ids',       '[]', 'string', 'portal_requests', 0, 0, 'JSON array of user IDs to notify for damage_report requests.'),

    ('portal_requests.routing.billing_inquiry.role_slugs',   '[]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs to notify for billing_inquiry requests.'),
    ('portal_requests.routing.billing_inquiry.user_ids',     '[]', 'string', 'portal_requests', 0, 0, 'JSON array of user IDs to notify for billing_inquiry requests.'),

    ('portal_requests.routing.document_request.role_slugs',  '[]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs to notify for document_request requests.'),
    ('portal_requests.routing.document_request.user_ids',    '[]', 'string', 'portal_requests', 0, 0, 'JSON array of user IDs to notify for document_request requests.'),

    ('portal_requests.routing.new_lease_inquiry.role_slugs', '[]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs to notify for new_lease_inquiry requests.'),
    ('portal_requests.routing.new_lease_inquiry.user_ids',   '[]', 'string', 'portal_requests', 0, 0, 'JSON array of user IDs to notify for new_lease_inquiry requests.'),

    ('portal_requests.routing.general.role_slugs',           '[]', 'string', 'portal_requests', 0, 0, 'JSON array of role slugs to notify for general requests.'),
    ('portal_requests.routing.general.user_ids',             '[]', 'string', 'portal_requests', 0, 0, 'JSON array of user IDs to notify for general requests.')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
