-- ============================================================================
-- S-CCA-2 — Customer Credit Application: public form + submit (Phase CCA / session 2 of 4)
-- ============================================================================
--
-- Adds the credit_application.disclaimer_html settings row. The other 5
-- credit_application.* rows were seeded in 202606060002_S-CCA-1 (INSERT IGNORE).
-- This is a pure data-seed migration — no schema motion, no MASTER update needed.
--
-- Legal entity name "Mainland Truck and Trailer Sales Ltd." is VERBATIM — do
-- NOT tokenize with {company_name} (which is "Mainland Truck & Trailer Sales &
-- Leasing" — a different string).
--
-- MIGRATE COUNT: 95 → 96.
--
-- @session  S-CCA-2
-- @decision D-CCA-2-A (uploads + disclaimer land in this session)
-- ============================================================================

INSERT IGNORE INTO `settings` (`key`, `value`, `value_type`, `group_name`, `label`, `description`) VALUES
(
    'credit_application.disclaimer_html',
    '<p>Applicant requests credit from Mainland Truck and Trailer Sales Ltd. for leasing
or purchasing equipment/services and agrees to pay invoices per the terms below and
any related agreements (e.g., Rental Lease Agreement dated [Commencement Date]).
Applicant authorizes Mainland Truck and Trailer Sales Ltd. to obtain credit reports
or other necessary information from any source to evaluate this agreement. If the
account becomes delinquent and is placed with a third party for collection, Applicant
shall pay all fees, including legal fees, incurred by Mainland Truck and Trailer
Sales Ltd. Applicant certifies all provided information is true and correct and will
notify Lessor of any changes. Governed by British Columbia law. Applicant submits to
British Columbia courts\' nonexclusive jurisdiction. This agreement, with any related
agreements, is the entire agreement on credit and may only be amended in writing. The
Rental Lease Agreement prevails in conflicts.</p>',
    'text',
    'credit_application',
    'Credit application disclaimer (HTML)',
    'Legal disclaimer block rendered above the sign-off section of the public credit-application form. Legal entity name "Mainland Truck and Trailer Sales Ltd." is verbatim — do NOT tokenize with {company_name}.'
);
