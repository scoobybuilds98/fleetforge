---
description: Browse, search, and manage every file uploaded across FleetForge — customer agreements, equipment compliance certificates, lease contracts, and more — from one place.
---

# Documents

Your central file store for everything uploaded across customers, equipment, and leases.

## Reading the dashboard

When you open **Documents** in the sidebar, the page lists every uploaded file across the whole system in one table. Each row tells you, at a glance:

- **Type** — the document type, shown as a colored badge (e.g. CVI, Registration, Contract, Tax Exempt).
- **Title** — the document's title, or its filename if no title was given.
- **Entity** — which record the file belongs to, shown as a type badge (Customer, Equipment, Lease, Inspection, Damage Claim) above a clickable link to that record.
- **Size** — file size in KB.
- **Expires** — the expiration date, if one was set. **Red** means already expired; **amber** means expiring within 30 days.
- **Uploaded** — the upload date.
- **By** — the staff member who uploaded it.

The count on the right of the filter bar (e.g. "42 documents") reflects whatever filters are currently applied.

---

## Finding a document

1. Open **Documents** in the sidebar.
2. Use the **All Types** dropdown to filter by entity: Customers, Equipment, Leases, Inspections, or Damage Claims.
3. Type in the **Search title or filename…** box to narrow by title or filename — results update as you type.
4. Click any column header (**Type**, **Title**, **Size**, **Expires**, **Uploaded**) to sort; click again to flip the direction. An ↑ or ↓ shows the active sort.
5. Click the entity link in the **Entity** column to jump to the customer, unit, or lease the file belongs to.
6. Click **Load more** at the bottom to pull in more rows (the list loads 25 at a time).

---

## Viewing a document

1. Find the document in the list.
2. Click **View** on its row.

The file opens in a new browser tab through a temporary, signed link. The link is valid for one hour — if you leave it open and come back later, just click **View** again to get a fresh one.

---

## Uploading a document

You can upload from this central page when you already know which record the file belongs to.

1. Click **+ Upload** (top right). The **Upload Document** modal opens.
2. Choose the **Entity Type** — Customer, Equipment Unit, Lease, Inspection, or Damage Claim.
3. Enter the **Entity ID** — the numeric ID of the record to attach to (e.g. `42`).
4. Pick a **Document Type**. The options change based on the entity type you chose (see the reference below).
5. Optionally enter a **Title** — if left blank, it defaults to the document type name (e.g. "CVI Certificate").
6. Optionally set an **Expiration Date** — useful for certificates and insurance that need renewal tracking.
7. Click **Choose File** and select your file — **PDF, JPEG, or PNG — max 20 MB**.
8. Optionally add **Notes**.
9. Click **Upload**. The new document appears at the top of the list.

> **Tip:** Not sure of the Entity ID? It's easier to upload from the record itself. Open the customer, unit, or lease, go to its **Documents** tab, and click **+ Upload** there — the entity is pre-filled for you, and the file shows up here in the central list automatically.

---

## Removing a document

1. Find the document in the list.
2. Click **Remove** on its row.
3. Confirm when prompted — *"Remove "…"? This cannot be undone."*

The document disappears from all lists immediately. (See *Under the hood* for what removal does and doesn't touch.)

---

## Document type reference

The document types available depend on which entity the file is attached to:

| Entity type | Document types |
|-------------|----------------|
| **Customer** | Tax Exemption, Credit Agreement, Other |
| **Equipment Unit** | CVI Certificate, Registration, Insurance, Other |
| **Lease** | Lease Contract, Pre-Lease Inspection, Post-Lease Inspection, Amendment, Other |
| **Inspection** | Inspection Report, Other |
| **Damage Claim** | Repair Estimate, Repair Invoice, Other |

CVI, Registration, and Insurance documents are highlighted in amber as compliance-critical; Contracts are highlighted as primary.

---

<details>
<summary>Under the hood — how it works technically</summary>

- **One table, many owners (polymorphic)** — every file lives in a single `documents` table keyed by `entity_type` + `entity_id`. The central page is **Mode B** of `api/v1/documents` (no `entity_id` supplied): it lists everything, with optional `entity_type` and text filters. The per-entity **Documents** tabs use **Mode A** (entity_type + entity_id) to show just that record's files.

- **Allowed files** — only PDF, JPEG, and PNG, up to **20 MB**. The file type is detected server-side from the file's actual contents (`finfo_file`), never from the browser-supplied name or content-type, and the stored extension is derived from that detected type.

- **Safe storage** — files are stored via the `StorageClient` abstraction under `documents/{entity_type}/{entity_id}/{type}_{timestamp}.{ext}`. In development that's the local `storage/` folder; in production it's a private Amazon S3 bucket. The raw storage path is never returned to the browser.

- **Signed URLs** — **View** links are time-limited and expire after one hour. Locally they're HMAC-signed and served through `api/v1/storage/serve`; on S3 they're AWS pre-signed URLs. Files are never publicly accessible.

- **Uploads happen in two places** — the central **+ Upload** button (you type the Entity ID), and each record's own **Documents** tab on **Customers**, **Equipment**, and **Leases** show pages (entity pre-filled). Both write to the same table and call the same upload endpoint.

- **Compliance & lease column sync** — uploading a CVI / Registration / Insurance file to an equipment unit also updates that unit's compliance columns (and its expiry date, if you set one), so the Compliance grid's document icons stay current without a separate step. Uploading a Contract / Pre- or Post-Lease Inspection to a lease similarly updates the lease's file references.

- **Expiry coloring** — the **Expires** column turns red once a date is in the past, and amber when it's within 30 days.

- **Soft delete** — **Remove** marks the row deleted (`deleted_at`) so it vanishes from lists, but the underlying file is **not** erased from storage (it may be referenced by audit trails). If a removed file was the one a unit's or lease's column still pointed to, that column is cleared so compliance icons and document links stay accurate. Every upload and removal is written to the audit log.

- **Permissions** — anyone signed in can browse and view the central list. Uploading and removing require **edit** permission on the owning module (Equipment, Leases, Customers, Inspections, or Maintenance for damage claims). The **+ Upload** button only shows if you have edit rights on at least one of Equipment, Customers, or Leases.

</details>

## Related guides

- [Customers](/help/customers)
- [Equipment](/help/equipment)
- [Compliance](/help/compliance)
