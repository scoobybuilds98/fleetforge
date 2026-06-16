// tests/_smoke_form_draft_autosave.js
//
// S-FORM-DRAFT-AUTOSAVE / S-FORM-DRAFT-ROLLOUT — FF_FormDraft helper logic smoke.
//
// Loads the REAL window.FF_FormDraft IIFE out of public/assets/js/app.js (NOT a
// re-implementation) into a mocked window/document/localStorage sandbox and
// asserts the storage contract:
//   - key = ff_draft:<formId>:<entityId>  ('new' default for create forms)
//   - save persists tracked state as JSON + numeric timestamp + DRAFT_VERSION tag
//   - restore rehydrates the tracked fields from a stored draft
//   - DRAFT_VERSION mismatch -> stored draft is discarded (deleted), not applied
//   - section merge: writing section B does NOT clobber section A under the same key
//   - exclude-list fields (incl. dotted paths) are NEVER serialized into the draft
//   - clear() deletes the entity key
//
// Part C (sensitive fields) is proved here too: an excluded credential/lock-token
// field present in form state yields a stored draft that does NOT contain it,
// while ordinary fields (amounts, contact, address) ARE retained.
//
// Run:   node tests/_smoke_form_draft_autosave.js
// Exit:  0 on all PASS, 1 on any FAIL.
//
// View-layer helper only; not a D131 gate (the helper is additive JS). Invoke
// when public/assets/js/app.js §07h FF_FormDraft is touched.

'use strict';

const fs   = require('fs');
const path = require('path');

// ── Load the real FF_FormDraft IIFE from app.js ───────────────────────────
const APP_JS = path.join(__dirname, '..', 'public', 'assets', 'js', 'app.js');
const src    = fs.readFileSync(APP_JS, 'utf8');

const startMarker = 'const FF_FormDraft = (function () {';
const endMarker   = 'window.FF_FormDraft = FF_FormDraft;';
const startIdx = src.indexOf(startMarker);
const endIdx   = src.indexOf(endMarker);
if (startIdx === -1 || endIdx === -1) {
    console.error('Could not locate FF_FormDraft IIFE in app.js — markers moved?');
    process.exit(1);
}
const iifeSrc = src.slice(startIdx, endIdx + endMarker.length);

// ── Mocked browser globals ─────────────────────────────────────────────────
function makeSandbox() {
    const store = {};
    const localStorage = {
        getItem:    (k) => (Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null),
        setItem:    (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
    };
    const noopEl = () => ({ style: {}, setAttribute() {}, addEventListener() {}, appendChild() {}, classList: { add() {} } });
    const win = { localStorage, addEventListener() {} };
    const doc = { addEventListener() {}, visibilityState: 'visible', createElement: noopEl };
    // eslint-disable-next-line no-new-func
    const factory = new Function('window', 'document', iifeSrc + '\n;return window.FF_FormDraft;');
    const FF_FormDraft = factory(win, doc);
    return { FF_FormDraft, store };
}

// ── Tiny assertion harness (mirrors the repo's node-smoke convention) ──────
let pass = 0, fail = 0;
function ok(label, cond, detail) {
    if (cond) { pass++; console.log(`  PASS  ${label}`); }
    else { fail++; console.log(`  FAIL  ${label}${detail ? '  — ' + detail : ''}`); }
}
function eq(label, got, want) {
    ok(label, JSON.stringify(got) === JSON.stringify(want), `got ${JSON.stringify(got)} want ${JSON.stringify(want)}`);
}

console.log('FF_FormDraft helper logic smoke');
console.log('='.repeat(72));

// 1 — key format + entityId default
(() => {
    const { FF_FormDraft } = makeSandbox();
    eq('1a key: create form keys on "new"',
        FF_FormDraft.attach({ formId: 'lease-create', entityId: 'new', model: {} }).key,
        'ff_draft:lease-create:new');
    eq('1b key: edit form keys on record id',
        FF_FormDraft.attach({ formId: 'equipment-unit-edit', entityId: 42, model: {} }).key,
        'ff_draft:equipment-unit-edit:42');
    eq('1c key: missing entityId defaults to "new"',
        FF_FormDraft.attach({ formId: 'customer-create', model: {} }).key,
        'ff_draft:customer-create:new');
})();

// 2 — save persists JSON + timestamp + DRAFT_VERSION
(() => {
    const { FF_FormDraft, store } = makeSandbox();
    const model = { contract_number: 'CN-1', monthly_rate: '2500', insurance_opt_in: true };
    const c = FF_FormDraft.attach({ formId: 'lease-create', entityId: 'new', model });
    c.save();
    const raw = store['ff_draft:lease-create:new'];
    ok('2a save wrote the entity key', !!raw);
    const parsed = JSON.parse(raw);
    eq('2b payload carries DRAFT_VERSION tag', parsed.v, FF_FormDraft.DRAFT_VERSION);
    ok('2c payload carries a numeric timestamp', typeof parsed.t === 'number' && parsed.t > 0, `t=${parsed.t}`);
    eq('2d tracked state stored under section slice (_main)', parsed.s._main.d, model);
})();

// 3 — restore rehydrates the tracked fields
(() => {
    const { FF_FormDraft } = makeSandbox();
    const src = { a: 'one', b: 'two', nested: { x: 1 } };
    FF_FormDraft.attach({ formId: 't', entityId: 'new', model: src }).save();   // write a draft
    const target = {};                                                           // fresh empty model
    const c = FF_FormDraft.attach({ formId: 't', entityId: 'new', model: target });
    const restored = c.restore();
    ok('3a restore() returns true when a draft exists', restored === true);
    eq('3b restore rehydrated all tracked fields', target, src);
})();

// 4 — DRAFT_VERSION mismatch -> discarded, not applied
(() => {
    const { FF_FormDraft, store } = makeSandbox();
    const KEY = 'ff_draft:t:new';
    store[KEY] = JSON.stringify({ v: '__OLD__', t: 1, s: { _main: { d: { a: 'STALE' }, t: 1 } } });
    const target = {};
    const c = FF_FormDraft.attach({ formId: 't', entityId: 'new', model: target });
    ok('4a version-mismatch draft reports hasDraft()=false', c.hasDraft() === false);
    ok('4b version-mismatch draft was DELETED from storage', store[KEY] === undefined);
    ok('4c restore() does nothing on a version-mismatch draft', c.restore() === false);
    eq('4d target model left untouched (stale value not applied)', target, {});
})();

// 5 — multi-section merge: section B does not clobber section A
(() => {
    const { FF_FormDraft, store } = makeSandbox();
    const KEY = 'ff_draft:wizard:new';
    FF_FormDraft.attach({ formId: 'wizard', entityId: 'new', section: 'step-1', model: { a: 'A-val' } }).save();
    FF_FormDraft.attach({ formId: 'wizard', entityId: 'new', section: 'step-2', model: { b: 'B-val' } }).save();
    const parsed = JSON.parse(store[KEY]);
    ok('5a one entity key holds BOTH section slices', !!parsed.s['step-1'] && !!parsed.s['step-2']);
    eq('5b section step-1 slice intact after step-2 write', parsed.s['step-1'].d, { a: 'A-val' });
    eq('5c section step-2 slice present', parsed.s['step-2'].d, { b: 'B-val' });
})();

// 6 — exclude-list fields NEVER serialized (incl. dotted paths) — Part C core
(() => {
    const { FF_FormDraft, store } = makeSandbox();
    const model = {
        id: 7, updated_at: '2026-06-16 10:00:00',   // optimistic-lock token (edit forms)
        password: 'hunter2',                          // credential
        customer_id: 99,                              // picker-owned id
        company_name: 'Acme', address: '1 Main St',   // ordinary — must be kept
        lessor: { bpo_amount: '500', secret_token: 'sk_live_x' },
    };
    const c = FF_FormDraft.attach({
        formId: 'edit', entityId: 7, model,
        exclude: ['id', 'updated_at', 'password', 'customer_id', 'lessor.secret_token'],
    });
    c.save();
    const d = JSON.parse(store['ff_draft:edit:7']).s._main.d;
    ok('6a excluded id NOT serialized',              !('id' in d));
    ok('6b excluded updated_at (lock token) NOT serialized', !('updated_at' in d));
    ok('6c excluded password (credential) NOT serialized',   !('password' in d));
    ok('6d excluded picker id NOT serialized',       !('customer_id' in d));
    ok('6e dotted-path exclude removed only the nested field', d.lessor && !('secret_token' in d.lessor));
    ok('6f ordinary fields ARE retained (company_name)', d.company_name === 'Acme');
    ok('6g ordinary fields ARE retained (address)',      d.address === '1 Main St');
    ok('6h nested non-excluded field retained (lessor.bpo_amount)', d.lessor.bpo_amount === '500');
    ok('6i excludes do NOT mutate the live model (id still present)', model.id === 7 && model.password === 'hunter2');
})();

// 7 — clear() deletes the entity key
(() => {
    const { FF_FormDraft, store } = makeSandbox();
    const c = FF_FormDraft.attach({ formId: 't', entityId: 'new', model: { a: 1 } });
    c.save();
    ok('7a key exists after save', store['ff_draft:t:new'] !== undefined);
    c.clear(true);
    ok('7b clear() removed the entity key', store['ff_draft:t:new'] === undefined);
})();

// 8 — Part C: invoice-shape (amounts/line-items kept; any credential excluded)
//     and customer-shape (banking excluded; contact/address kept)
(() => {
    const { FF_FormDraft, store } = makeSandbox();
    // invoice-like manual fields + a (hypothetical) payment credential to exclude
    const invoice = {
        amount: '1250.00',
        line_items: [{ desc: 'rental', amount: '1000.00' }, { desc: 'mileage', amount: '250.00' }],
        po_number: 'PO-1', notes: 'half month',
        card_number: '4111111111111111', bank_account: '000123456',  // credentials -> exclude
    };
    FF_FormDraft.attach({ formId: 'invoice-create', entityId: 'new', model: invoice,
        exclude: ['card_number', 'bank_account'] }).save();
    const di = JSON.parse(store['ff_draft:invoice-create:new']).s._main.d;
    ok('8a invoice: card_number excluded',  !('card_number' in di));
    ok('8b invoice: bank_account excluded',  !('bank_account' in di));
    ok('8c invoice: amount RETAINED',        di.amount === '1250.00');
    eq('8d invoice: line_items RETAINED',     di.line_items, invoice.line_items);
    ok('8e invoice: po_number RETAINED',      di.po_number === 'PO-1');

    // customer-like: banking excluded, contact/address kept
    const cust = { company_name: 'Acme', contact_name: 'Jo', address: '1 Main', city: 'Van',
                   payout_account: '987654321', routing_number: '021000021' };
    FF_FormDraft.attach({ formId: 'customer-create', entityId: 'new', model: cust,
        exclude: ['payout_account', 'routing_number'] }).save();
    const dc = JSON.parse(store['ff_draft:customer-create:new']).s._main.d;
    ok('8f customer: payout_account excluded',  !('payout_account' in dc));
    ok('8g customer: routing_number excluded',  !('routing_number' in dc));
    ok('8h customer: contact_name RETAINED',     dc.contact_name === 'Jo');
    ok('8i customer: address RETAINED',          dc.address === '1 Main');
})();

console.log('-'.repeat(72));
console.log(`PASS: ${pass}   FAIL: ${fail}   TOTAL: ${pass + fail}`);
process.exit(fail === 0 ? 0 : 1);
