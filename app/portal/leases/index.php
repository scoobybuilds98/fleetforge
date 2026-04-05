<?php
declare(strict_types=1);

/**
 * app/portal/leases/index.php
 *
 * Portal lease list — Active / Historical / All tabs.
 * All queries filter by portal_customer_id() (Trap 8).
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();

$cid = portal_customer_id();

// ── AJAX handler (must run before header to avoid HTML output) ──
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');

    $tab    = clean_string($_GET['tab'] ?? 'active');
    $search = clean_string($_GET['q'] ?? null);

    $where  = ['l.customer_id = ?', 'l.deleted_at IS NULL', 'eu.deleted_at IS NULL', 'et.deleted_at IS NULL'];
    $params = [$cid];

    if ($tab === 'active') {
        $where[] = "l.status = 'active'";
    } elseif ($tab === 'historical') {
        $where[] = "l.status IN ('completed','cancelled')";
    }

    if ($search) {
        $where[] = "(l.contract_number LIKE ? OR eu.unit_number LIKE ?)";
        $params[] = "%{$search}%";
        $params[] = "%{$search}%";
    }

    $whereSQL = implode(' AND ', $where);

    $rows = db_select(
        "SELECT l.id, l.contract_number, l.start_date, l.end_date, l.status,
                l.monthly_rate, eu.unit_number, et.category
         FROM leases l
         JOIN equipment_units eu ON eu.id = l.equipment_unit_id
         JOIN equipment_templates et ON et.id = eu.template_id
         WHERE {$whereSQL}
         ORDER BY l.start_date DESC LIMIT 50",
        $params
    );

    foreach ($rows as &$r) {
        $r['start_date_fmt']   = format_date($r['start_date']);
        $r['end_date_fmt']     = $r['end_date'] ? format_date($r['end_date']) : null;
        $r['monthly_rate_fmt'] = format_currency($r['monthly_rate']);
    }

    $counts = [
        'active'     => db_count("SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status = 'active' AND deleted_at IS NULL", [$cid]),
        'historical' => db_count("SELECT COUNT(*) FROM leases WHERE customer_id = ? AND status IN ('completed','cancelled') AND deleted_at IS NULL", [$cid]),
        'all'        => db_count("SELECT COUNT(*) FROM leases WHERE customer_id = ? AND deleted_at IS NULL", [$cid]),
    ];

    echo json_encode(['success' => true, 'data' => ['leases' => $rows, 'counts' => $counts]]);
    exit;
}

$pageTitle = 'Leases';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div x-data="PortalLeases()" x-init="load()" x-cloak>

    <!-- Tabs -->
    <div class="portal-tabs">
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'active' }" @click="tab = 'active'; load()">
            Active <span class="portal-tab-count" x-show="counts.active > 0" x-text="counts.active"></span>
        </button>
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'historical' }" @click="tab = 'historical'; load()">
            Historical <span class="portal-tab-count" x-show="counts.historical > 0" x-text="counts.historical"></span>
        </button>
        <button class="portal-tab-btn" :class="{ 'is-active': tab === 'all' }" @click="tab = 'all'; load()">
            All <span class="portal-tab-count" x-show="counts.all > 0" x-text="counts.all"></span>
        </button>
    </div>

    <!-- Search -->
    <div class="portal-filters">
        <input type="text" class="portal-search-input"
               placeholder="Search by contract #, unit..."
               x-model="search" @input.debounce.400ms="load()">
    </div>

    <!-- Table -->
    <div class="portal-section">
        <div class="portal-section-body--flush">
            <template x-if="loading">
                <div class="portal-empty">
                    <p class="portal-empty-text">Loading leases...</p>
                </div>
            </template>
            <template x-if="!loading && leases.length === 0">
                <div class="portal-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>
                    <p class="portal-empty-title">No leases found</p>
                    <p class="portal-empty-text" x-text="tab === 'active' ? 'No active leases at the moment.' : 'No leases match your filters.'"></p>
                </div>
            </template>
            <table class="portal-table" x-show="!loading && leases.length > 0">
                <thead>
                    <tr>
                        <th>Contract #</th>
                        <th>Unit</th>
                        <th>Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th class="text-right">Monthly Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="l in leases" :key="l.id">
                        <tr>
                            <td>
                                <a :href="viewUrl(l.id)" class="portal-table-link" x-text="l.contract_number"></a>
                            </td>
                            <td x-text="l.unit_number"></td>
                            <td><span style="color:var(--text-secondary);font-size:0.8125rem;" x-text="l.category"></span></td>
                            <td class="font-mono" x-text="l.start_date_fmt"></td>
                            <td class="font-mono" x-text="l.end_date_fmt || '—'"></td>
                            <td class="text-right font-mono" x-text="l.monthly_rate_fmt"></td>
                            <td><span class="badge" :class="badgeClass(l.status)" x-text="statusLabel(l.status)"></span></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function PortalLeases() {
    return {
        tab: new URLSearchParams(location.search).get('tab') || 'active',
        search: '',
        leases: [],
        loading: true,
        counts: { active: 0, historical: 0, all: 0 },

        load() {
            this.loading = true;
            const params = new URLSearchParams({ tab: this.tab, q: this.search });
            FF_Api.get(FF_Api.url('/portal/leases/index.php?ajax=1&' + params.toString()))
                .then(d => {
                    if (d.success) {
                        this.leases = d.data.leases || [];
                        this.counts = d.data.counts || this.counts;
                    }
                    this.loading = false;
                }).catch(() => { this.loading = false; });
        },

        viewUrl(id) { return (window.FF_BASE_PATH || '') + '/portal/leases/view?id=' + id; },
        badgeClass(s) {
            return { active: 'badge-success', completed: 'badge-neutral', cancelled: 'badge-danger', pending: 'badge-info' }[s] || 'badge-neutral';
        },
        statusLabel(s) { return s.charAt(0).toUpperCase() + s.slice(1); },
    };
}
</script>


<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
