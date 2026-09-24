<?php
declare(strict_types=1);

/**
 * app/portal/requests/create.php
 *
 * Customer portal — start a request (S-PORTAL-REDESIGN).
 *
 * The customer picks what they need (extend, return, report a problem,
 * billing question, paperwork, rent more, something else) from cards,
 * optionally ties it to a lease/unit, and writes a message. It lands in
 * the staff Requests inbox routed per Settings → Portal & Requests
 * (PortalRequestNotifier via pt_create_request()).
 *
 * Pre-fill (GET) — used by buttons across the portal:
 *   type, lease_id, equipment_id, subject, message
 *
 * Fixed here: a validation error used to reset the type, lease and unit to
 * the GET pre-fill (only subject/message survived). Every field now keeps
 * what the customer entered.
 *
 * Validation (unchanged): type ∈ the DB enum; subject ≤ 500; message ≤ 5000;
 * a lease/unit id that isn't the customer's is silently dropped (Trap 8).
 *
 * @session S-PORTAL-REDESIGN
 */

require_once dirname(__DIR__) . '/includes/auth.php';
require_portal_auth();
require_once dirname(__DIR__) . '/includes/ui.php';

$cid   = portal_customer_id();
$types = pt_request_types();

$leases = db_select(
    "SELECT l.id, l.contract_number, eu.id AS unit_id, eu.unit_number
       FROM leases l
       JOIN equipment_units eu ON eu.id = l.equipment_unit_id
      WHERE l.customer_id = ? AND l.status = 'active' AND l.deleted_at IS NULL AND eu.deleted_at IS NULL
      ORDER BY l.contract_number",
    [$cid]
);

// Positive id as a string, '' otherwise (so "0" never reads as a selection).
$idStr = static fn (mixed $v): string => ($i = clean_int($v)) && $i > 0 ? (string) $i : '';

$form = [
    'request_type' => (string) ($_GET['type'] ?? ''),
    'lease_id'     => $idStr($_GET['lease_id'] ?? null),
    'equipment_id' => $idStr($_GET['equipment_id'] ?? null),
    'subject'      => (string) (clean_string($_GET['subject'] ?? '', 500) ?? ''),
    'message'      => (string) (clean_string($_GET['message'] ?? '', 5000) ?? ''),
];
if (!isset($types[$form['request_type']])) {
    $form['request_type'] = '';
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = [
        'request_type' => (string) clean_string($_POST['request_type'] ?? '', 50),
        'lease_id'     => $idStr($_POST['lease_id'] ?? null),
        'equipment_id' => $idStr($_POST['equipment_id'] ?? null),
        'subject'      => trim((string) clean_string($_POST['subject'] ?? '', 500)),
        'message'      => trim((string) clean_string($_POST['message'] ?? '', 5000)),
    ];

    if (!portal_verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        $error = 'Your session token expired. Please try again.';
    } elseif (!isset($types[$form['request_type']])) {
        $error = 'Choose what your request is about.';
    } elseif ($form['subject'] === '') {
        $error = 'Add a short subject.';
    } elseif ($form['message'] === '') {
        $error = 'Tell us a little about what you need.';
    } else {
        $leaseId = $form['lease_id'] !== '' ? (int) $form['lease_id'] : null;
        if ($leaseId && !db_exists('leases', 'id = ? AND customer_id = ?', [$leaseId, $cid])) {
            $leaseId = null;
        }
        $unitId = $form['equipment_id'] !== '' ? (int) $form['equipment_id'] : null;
        if ($unitId) {
            $ok = db_row(
                "SELECT eu.id FROM equipment_units eu
                   JOIN leases l ON eu.id = l.equipment_unit_id
                  WHERE eu.id = ? AND l.customer_id = ? AND l.deleted_at IS NULL AND eu.deleted_at IS NULL",
                [$unitId, $cid]
            );
            if (!$ok) $unitId = null;
        }

        $newId = pt_create_request([
            'type'              => $form['request_type'],
            'subject'           => $form['subject'],
            'message'           => $form['message'],
            'lease_id'          => $leaseId,
            'equipment_unit_id' => $unitId,
        ]);
        header('Location: ' . pt_url('requests/view?id=' . $newId . '&created=1'));
        exit;
    }
}

$pageTitle = 'New request';
require_once dirname(__DIR__) . '/includes/header.php';

echo pt_page_head([
    'back'  => ['Requests', pt_url('requests')],
    'title' => 'How can we help?',
    'sub'   => 'Tell us what you need and the right person on our team will pick it up. You\'ll get replies right here and by notification.',
]);

$leasesJs = array_map(static fn ($l) => ['id' => (string) $l['id'], 'contract' => $l['contract_number'], 'unit_id' => (string) $l['unit_id'], 'unit' => $l['unit_number']], $leases);
$suggest = [
    'lease_extension'   => 'Extend lease %c',
    'early_return'      => 'Return unit %u',
    'damage_report'     => 'Problem with unit %u',
    'billing_inquiry'   => 'Billing question',
    'document_request'  => 'Document request',
    'new_lease_inquiry' => 'Looking to rent more equipment',
    'general'           => '',
];
?>

<form method="POST" novalidate
      x-data="{
        f: <?= e(json_encode($form)) ?>,
        leases: <?= e(json_encode($leasesJs)) ?>,
        suggest: <?= e(json_encode($suggest)) ?>,
        touched: <?= $form['subject'] !== '' ? 'true' : 'false' ?>,
        get lease() { return this.leases.find(l => l.id === this.f.lease_id) || null; },
        pickLease() { if (this.lease) this.f.equipment_id = this.lease.unit_id; this.autoSubject(); },
        pickUnit() { const l = this.leases.find(x => x.unit_id === this.f.equipment_id); if (l && !this.f.lease_id) this.f.lease_id = l.id; this.autoSubject(); },
        autoSubject() {
            if (this.touched) return;
            const tpl = this.suggest[this.f.request_type] || '';
            const l = this.lease;
            this.f.subject = tpl.replace('%c', l ? l.contract : '').replace('%u', l ? l.unit : '').trim();
        },
        init() {
            if (this.f.lease_id && !this.f.equipment_id && this.lease) this.f.equipment_id = this.lease.unit_id;
            if (!this.f.subject) this.autoSubject();
        },
        get needsLease() { return ['lease_extension','early_return','damage_report'].includes(this.f.request_type); }
      }">
    <input type="hidden" name="csrf_token" value="<?= e(portal_csrf_token()) ?>">

    <?php if ($error !== ''): ?>
        <div class="pt-note pt-note--danger" style="margin-bottom:18px"><?= pt_icon('exclamation-triangle') ?><span><?= e($error) ?></span></div>
    <?php endif; ?>

    <div class="pt-grid pt-grid--main">
        <div class="pt-stack">
            <section class="pt-card">
                <div class="pt-card-head"><h2 class="pt-card-title">1. What's it about?</h2></div>
                <div class="pt-card-body">
                    <div class="pt-choice-grid" role="radiogroup" aria-label="Request type">
                        <?php foreach ($types as $key => [$label, $icon, $blurb]): ?>
                            <label class="pt-choice" :class="{ 'is-checked': f.request_type === '<?= e($key) ?>' }">
                                <input type="radio" name="request_type" value="<?= e($key) ?>" x-model="f.request_type" @change="autoSubject()">
                                <?= pt_icon($icon) ?>
                                <span class="pt-choice-title"><?= e($label) ?></span>
                                <span class="pt-choice-text"><?= e($blurb) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="pt-card">
                <div class="pt-card-head"><h2 class="pt-card-title">2. The details</h2></div>
                <div class="pt-card-body">
                    <div class="pt-form-grid">
                        <?php if ($leases): ?>
                        <div class="pt-field">
                            <label class="pt-label" for="rq-lease">Lease <small x-text="needsLease ? '' : '(optional)'"></small></label>
                            <select id="rq-lease" name="lease_id" class="pt-select" x-model="f.lease_id" @change="pickLease()">
                                <option value="">Not about a specific lease</option>
                                <?php foreach ($leases as $l): ?>
                                    <option value="<?= (int) $l['id'] ?>"><?= e($l['contract_number']) ?> — unit <?= e($l['unit_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pt-field">
                            <label class="pt-label" for="rq-unit">Unit <small>(optional)</small></label>
                            <select id="rq-unit" name="equipment_id" class="pt-select" x-model="f.equipment_id" @change="pickUnit()">
                                <option value="">Not about a specific unit</option>
                                <?php foreach ($leases as $l): ?>
                                    <option value="<?= (int) $l['unit_id'] ?>"><?= e($l['unit_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="pt-field span-2">
                            <label class="pt-label" for="rq-subject">Subject</label>
                            <input id="rq-subject" name="subject" class="pt-input" maxlength="500" x-model="f.subject" @input="touched = true" placeholder="A few words, e.g. “Brake light out on 40TR1319”" required>
                        </div>
                        <div class="pt-field span-2">
                            <label class="pt-label" for="rq-message">Message</label>
                            <textarea id="rq-message" name="message" class="pt-textarea" maxlength="5000" rows="7" x-model="f.message" required
                                      :placeholder="{
                                        lease_extension: 'How much longer do you need it? Any change to how you are using it?',
                                        early_return: 'When and where would you like to return it? Anything we should know about its condition?',
                                        damage_report: 'What happened, where is the unit now, and is it safe to use?',
                                        billing_inquiry: 'Which invoice(s), and what looks off?',
                                        document_request: 'Which document do you need, and for which unit or lease?',
                                        new_lease_inquiry: 'What equipment, how many, from when and for how long?'
                                      }[f.request_type] || 'How can we help?'"></textarea>
                            <span class="pt-hint" x-text="(f.message || '').length + ' / 5000'"></span>
                        </div>
                    </div>
                </div>
                <div class="pt-card-foot">
                    <span>Our team replies right here — you'll get a notification too.</span>
                    <button type="submit" class="pt-btn pt-btn--primary" :disabled="!f.request_type"><?= pt_icon('paper-airplane') ?> Send request</button>
                </div>
            </section>
        </div>

        <aside class="pt-stack">
            <section class="pt-card">
                <div class="pt-card-body">
                    <p class="pt-card-title" style="font-size:14px;margin:0 0 6px"><?= pt_icon('exclamation-triangle') ?> Is it urgent?</p>
                    <p class="pt-muted" style="font-size:13.5px;line-height:1.5;margin:0 0 12px">If a unit is unsafe or broken down on the road, call us right away so we can help.</p>
                    <?php $phone = (string) settings_get('company.phone', ''); if ($phone !== ''): ?>
                        <a class="pt-btn pt-btn--secondary pt-btn--block" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= pt_icon('phone') ?> <?= e($phone) ?></a>
                    <?php endif; ?>
                </div>
            </section>
            <section class="pt-card">
                <div class="pt-card-body">
                    <p class="pt-card-title" style="font-size:14px;margin:0 0 6px"><?= pt_icon('credit-card') ?> Paying an invoice?</p>
                    <p class="pt-muted" style="font-size:13.5px;line-height:1.5;margin:0 0 12px">You don't need a request — pay online or tell us about a payment from the Pay page.</p>
                    <a class="pt-btn pt-btn--soft pt-btn--block" href="<?= e(pt_url('payments')) ?>">Go to Pay &amp; payments</a>
                </div>
            </section>
        </aside>
    </div>
</form>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
