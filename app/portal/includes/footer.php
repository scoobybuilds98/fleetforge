<?php
declare(strict_types=1);

/**
 * app/portal/includes/footer.php
 *
 * Customer portal shell — closing half (S-PORTAL-REDESIGN). Closes <main>,
 * renders the slim legal footer and the phone tab bar, and hosts the
 * overlays every page can open:
 *
 *   Search palette      ⌘K / "/" / the topbar search button (PT_Search)
 *   Pay drawer          $store.checkout.start([ids])  — [] = every open invoice
 *   Payment notice      $store.notice.show({ids})     — "I've sent a payment"
 *   Statement picker    $store.statement.show()       — date range → PDF
 *
 * Scripts: app.js (FF_Api, FF_Toast, FF_PortalNotifications…) → portal.js
 * (registers the stores on alpine:init) → Alpine (deferred).
 *
 * Legal links: S-LEGAL-FOOTER-COMMERCIAL routes (/legal/*).
 */

$_ftCompany  = (string) (settings_get('company.name') ?: legal_config('company.brand_name'));
$_ftSummary  = $_summary ?? pt_account_summary(portal_customer_id());
$_ftOnline   = $_onlinePay ?? pt_online_pay_enabled();
$_ftPath     = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$_ftBase     = FF_BASE_PATH . '/portal';
$_ftIs       = static fn (string $p): bool => $p === ''
    ? in_array($_ftPath, [$_ftBase, $_ftBase . '/', $_ftBase . '/index'], true)
    : ($_ftPath === $_ftBase . '/' . $p || str_starts_with($_ftPath, $_ftBase . '/' . $p . '/'));
$_ftPay      = pt_offline_payment_details();
$_ftMethods  = [
    'e_transfer'  => 'Interac e-Transfer',
    'ach'         => 'Bank transfer (EFT)',
    'wire'        => 'Wire transfer',
    'check'       => 'Cheque',
    'credit_card' => 'Card (by phone)',
    'other'       => 'Other',
];
?>
        </main>

        <footer class="pt-foot">
            <div class="pt-foot-brand">
                <span>&copy; <?= date('Y') ?> <?= e($_ftCompany) ?></span>
            </div>
            <nav class="pt-foot-links" aria-label="Legal">
                <a href="<?= e(legal_url('terms')) ?>" target="_blank" rel="noopener">Terms</a>
                <a href="<?= e(legal_url('privacy')) ?>" target="_blank" rel="noopener">Privacy</a>
                <a href="<?= e(legal_url('security')) ?>" target="_blank" rel="noopener">Security</a>
                <a href="<?= e(legal_url('cookies')) ?>" target="_blank" rel="noopener">Cookies</a>
                <a href="mailto:<?= e(legal_config('company.email_support')) ?>">Support</a>
            </nav>
            <span>Powered by <?= e(legal_config('company.product_name')) ?></span>
        </footer>

    </div><!-- /.pt-main -->

    <!-- ── Phone tab bar ────────────────────────────────────────────────── -->
    <nav class="pt-tabbar" aria-label="Quick navigation">
        <a href="<?= e(pt_url()) ?>" class="<?= $_ftIs('') ? 'is-active' : '' ?>"><?= pt_icon('home') ?><span>Home</span></a>
        <a href="<?= e(pt_url('invoices')) ?>" class="<?= $_ftIs('invoices') ? 'is-active' : '' ?>">
            <?= pt_icon('document-text') ?><span>Invoices</span>
            <?php if ($_ftSummary['past_due_count'] > 0): ?><i class="pt-tabbar-dot" aria-label="Past-due invoices"></i><?php endif; ?>
        </a>
        <a href="<?= e(pt_url('payments')) ?>" class="pt-tabbar-pay <?= $_ftIs('payments') ? 'is-active' : '' ?>">
            <span class="pt-tabbar-fab"><?= pt_icon('credit-card') ?></span><span>Pay</span>
        </a>
        <a href="<?= e(pt_url('leases')) ?>" class="<?= $_ftIs('leases') || $_ftIs('equipment') ? 'is-active' : '' ?>"><?= pt_icon('truck') ?><span>Fleet</span></a>
        <button type="button" @click="navOpen = true" :class="{ 'is-active': navOpen }"><?= pt_icon('squares-2x2') ?><span>More</span></button>
    </nav>

    <!-- ── Search palette ───────────────────────────────────────────────── -->
    <div x-show="searchOpen" x-cloak>
        <div class="pt-overlay" x-show="searchOpen" x-transition:enter="pt-fade-enter" x-transition:enter-start="pt-fade-enter-start" x-transition:enter-end="pt-fade-enter-end" @click="searchOpen = false"></div>
        <div class="pt-cmdk-wrap" x-data="PT_Search()" x-effect="searchOpen ? onOpen() : onClose()" @click.self="searchOpen = false">
            <div class="pt-cmdk" role="dialog" aria-modal="true" aria-label="Search"
                 x-show="searchOpen" x-transition:enter="pt-modal-enter" x-transition:enter-start="pt-modal-enter-start" x-transition:enter-end="pt-modal-enter-end"
                 @keydown.escape.stop="searchOpen = false">
                <div class="pt-cmdk-input">
                    <?= pt_icon('magnifying-glass') ?>
                    <input type="text" x-ref="input" x-model="q" @input="onInput()"
                           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter.prevent="go()"
                           placeholder="Invoice number, unit, lease, request…" aria-label="Search" autocomplete="off" spellcheck="false">
                    <span class="pt-spin" x-show="loading" x-cloak style="color:var(--text-tertiary)"></span>
                    <kbd class="pt-kbd">esc</kbd>
                </div>
                <div class="pt-cmdk-list" role="listbox">
                    <template x-for="(item, i) in flat" :key="item.url + i">
                        <div>
                            <div class="pt-cmdk-group" x-show="groupStart(i)" x-text="item.group"></div>
                            <a class="pt-cmdk-item" :href="item.url" :class="{ 'is-active': i === active }" @mouseenter="active = i" role="option" :aria-selected="i === active">
                                <span class="pt-cmdk-item-ic" x-html="item.icon_svg"></span>
                                <span class="pt-cmdk-item-main">
                                    <span class="pt-cmdk-item-title" x-text="item.title" style="display:block"></span>
                                    <span class="pt-cmdk-item-sub" x-text="item.sub" style="display:block"></span>
                                </span>
                                <span class="pt-cmdk-item-right" x-show="item.right" x-text="item.right"></span>
                            </a>
                        </div>
                    </template>
                    <div class="pt-cmdk-empty" x-show="q.trim().length >= 2 && !loading && results.length === 0">
                        No matches for “<span x-text="q.trim()"></span>”.
                    </div>
                </div>
                <div class="pt-cmdk-foot">
                    <span><kbd class="pt-kbd">↑</kbd><kbd class="pt-kbd">↓</kbd> to move</span>
                    <span><kbd class="pt-kbd">↵</kbd> to open</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Pay drawer ───────────────────────────────────────────────────── -->
    <div x-data x-show="$store.checkout.open" x-cloak @keydown.escape.window="$store.checkout.open && $store.checkout.close()">
        <div class="pt-overlay" x-show="$store.checkout.open" x-transition:enter="pt-fade-enter" x-transition:enter-start="pt-fade-enter-start" x-transition:enter-end="pt-fade-enter-end" x-transition:leave="pt-fade-leave" x-transition:leave-start="pt-fade-leave-start" x-transition:leave-end="pt-fade-leave-end" @click="$store.checkout.close()"></div>
        <aside class="pt-drawer" role="dialog" aria-modal="true" aria-labelledby="pt-co-title" x-show="$store.checkout.open"
               x-transition:enter="pt-drawer-enter" x-transition:enter-start="pt-drawer-enter-start" x-transition:enter-end="pt-drawer-enter-end"
               x-transition:leave="pt-drawer-leave" x-transition:leave-start="pt-drawer-leave-start" x-transition:leave-end="pt-drawer-leave-end">
            <div class="pt-drawer-head">
                <div>
                    <h2 class="pt-drawer-title" id="pt-co-title" x-text="$store.checkout.allPaid ? 'All paid — thank you!' : 'Pay invoices'"></h2>
                    <p class="pt-drawer-sub" x-show="$store.checkout.online && !$store.checkout.allPaid">
                        Each invoice opens its own secure QuickBooks payment page in a new tab — card or bank transfer. We tick it off here as soon as the payment lands.
                    </p>
                    <p class="pt-drawer-sub" x-show="!$store.checkout.online">
                        Online card payment isn't available yet — here's how to pay us directly.
                    </p>
                </div>
                <button type="button" class="pt-icon-btn" @click="$store.checkout.close()" aria-label="Close"><?= pt_icon('x-mark') ?></button>
            </div>

            <div class="pt-drawer-body" style="display:flex;flex-direction:column;gap:14px">
                <template x-if="$store.checkout.loading">
                    <div class="pt-co-list">
                        <div class="pt-skel" style="height:64px;border-radius:14px"></div>
                        <div class="pt-skel" style="height:64px;border-radius:14px"></div>
                    </div>
                </template>

                <div class="pt-note pt-note--danger" x-show="$store.checkout.error" x-cloak>
                    <?= pt_icon('exclamation-triangle') ?><span x-text="$store.checkout.error"></span>
                </div>

                <template x-if="!$store.checkout.loading && !$store.checkout.error && $store.checkout.items.length === 0">
                    <?= pt_empty('check-circle', 'Nothing to pay', 'There\'s no balance due on these invoices.') ?>
                </template>

                <div class="pt-card-title" style="font-size:14px" x-show="!$store.checkout.online && $store.checkout.items.length" x-cloak>What you owe</div>
                <ul class="pt-co-list" x-show="!$store.checkout.loading && $store.checkout.items.length">
                    <template x-for="item in $store.checkout.items" :key="item.id">
                        <li class="pt-co-item" :class="{ 'is-paid': item.state === 'paid', 'is-waiting': item.state === 'waiting' }">
                            <div class="pt-co-main">
                                <div class="pt-co-num" x-text="'Invoice ' + item.number"></div>
                                <div class="pt-co-meta">
                                    <template x-if="item.state === 'paid'"><span class="pt-co-state pt-success-ink"><?= pt_icon('check-circle', 'pt-ic pt-ic--sm') ?> Paid</span></template>
                                    <template x-if="item.state === 'waiting'"><span class="pt-co-state"><span class="pt-spin" style="width:12px;height:12px"></span> Waiting for QuickBooks…</span></template>
                                    <template x-if="item.state === 'idle'"><span><span x-text="item.status_label"></span> · due <span x-text="PT.date(item.due_date)"></span><span x-show="$store.checkout.online && !item.online_ready"> · online payment not ready yet</span></span></template>
                                </div>
                            </div>
                            <div class="pt-co-amt" x-text="PT.money(item.balance, item.currency)"></div>
                            <template x-if="$store.checkout.online && item.state !== 'paid' && item.online_ready">
                                <a class="pt-btn pt-btn--sm" :class="item.state === 'waiting' ? 'pt-btn--secondary' : 'pt-btn--primary'"
                                   :href="$store.checkout.payUrl(item)" target="_blank" rel="noopener"
                                   @click="$store.checkout.opened(item)"
                                   x-text="item.state === 'waiting' ? 'Reopen' : 'Pay'"></a>
                            </template>
                        </li>
                    </template>
                </ul>

                <div x-show="$store.checkout.waitingCount > 0" x-cloak class="pt-note">
                    <?= pt_icon('information-circle') ?>
                    <span>Finished paying in the other tab? Confirmation usually arrives within a minute. You can close this panel — your account updates automatically.</span>
                </div>

                <!-- Offline ways to pay (always available; primary when online pay is off) -->
                <div x-show="!$store.checkout.loading" :style="$store.checkout.online ? 'order:9;margin-top:8px' : 'order:-1'">
                    <div class="pt-card-title" style="font-size:14px;margin-bottom:10px" x-text="$store.checkout.online ? 'Prefer to pay another way?' : 'How to pay'"></div>
                    <?php if ($_ftPay['bank_name'] !== '' || $_ftPay['bank_account'] !== ''): ?>
                        <?php if ($_ftPay['bank_name'] !== ''): ?>
                        <div class="pt-copy-row"><div><div class="pt-copy-k">Bank</div><div class="pt-copy-v"><?= e($_ftPay['bank_name']) ?></div></div></div>
                        <?php endif; ?>
                        <?php if ($_ftPay['bank_account'] !== ''): ?>
                        <div class="pt-copy-row"><div><div class="pt-copy-k">Account</div><div class="pt-copy-v pt-mono"><?= e($_ftPay['bank_account']) ?></div></div>
                            <button type="button" class="pt-btn pt-btn--ghost pt-btn--sm pt-copy-btn" @click="PT.copy(<?= e(json_encode($_ftPay['bank_account'])) ?>, 'Account number')"><?= pt_icon('clipboard') ?> Copy</button></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($_ftPay['payable_to'] !== ''): ?>
                    <div class="pt-copy-row"><div><div class="pt-copy-k">Cheques payable to</div><div class="pt-copy-v"><?= e($_ftPay['payable_to']) ?></div>
                        <?php if ($_ftPay['remit_address'] !== ''): ?><div class="pt-hint" style="margin-top:2px"><?= e($_ftPay['remit_address']) ?></div><?php endif; ?></div></div>
                    <?php endif; ?>
                    <?php if ($_ftPay['email'] !== ''): ?>
                    <div class="pt-copy-row"><div><div class="pt-copy-k">Interac e-Transfer / remittance email</div><div class="pt-copy-v"><?= e($_ftPay['email']) ?></div></div>
                        <button type="button" class="pt-btn pt-btn--ghost pt-btn--sm pt-copy-btn" @click="PT.copy(<?= e(json_encode($_ftPay['email'])) ?>, 'Email')"><?= pt_icon('clipboard') ?> Copy</button></div>
                    <?php endif; ?>
                    <?php if ($_ftPay['instructions'] !== ''): ?>
                    <div class="pt-note" style="margin-top:10px"><?= pt_icon('information-circle') ?><span><?= nl2br(e($_ftPay['instructions'])) ?></span></div>
                    <?php endif; ?>
                    <p class="pt-hint" style="margin:10px 0 0">Please include your invoice numbers as the payment reference.</p>
                </div>
            </div>

            <div class="pt-drawer-foot" x-show="!$store.checkout.loading">
                <div class="pt-co-total" x-show="!$store.checkout.allPaid">
                    <span>Total due</span>
                    <span x-text="$store.checkout.totalLabel"></span>
                </div>
                <div class="pt-btn-row">
                    <button type="button" class="pt-btn pt-btn--secondary" style="flex:1"
                            @click="const ids = $store.checkout.payable.map(i => i.id); $store.checkout.close(); $store.notice.show({ ids })"
                            x-show="!$store.checkout.allPaid">
                        <?= pt_icon('paper-airplane') ?> I've paid another way
                    </button>
                    <button type="button" class="pt-btn pt-btn--primary" style="flex:1" x-show="$store.checkout.allPaid" @click="$store.checkout.close()">Done</button>
                </div>
                <div class="pt-secure" x-show="$store.checkout.online"><?= pt_icon('lock-closed') ?> Payments are processed securely by QuickBooks. We never see your card or bank details.</div>
            </div>
        </aside>
    </div>

    <!-- ── Payment notice (remittance advice) ───────────────────────────── -->
    <div x-data x-show="$store.notice.open" x-cloak @keydown.escape.window="$store.notice.open && $store.notice.close()">
        <div class="pt-overlay" x-show="$store.notice.open" x-transition:enter="pt-fade-enter" x-transition:enter-start="pt-fade-enter-start" x-transition:enter-end="pt-fade-enter-end" @click="$store.notice.close()"></div>
        <div class="pt-modal-wrap" @click.self="$store.notice.close()">
            <div class="pt-modal" role="dialog" aria-modal="true" aria-labelledby="pt-notice-title" x-show="$store.notice.open"
                 x-transition:enter="pt-modal-enter" x-transition:enter-start="pt-modal-enter-start" x-transition:enter-end="pt-modal-enter-end">
                <div class="pt-drawer-head">
                    <div>
                        <h2 class="pt-drawer-title" id="pt-notice-title" x-text="$store.notice.done ? 'Thanks — we\'ve got it' : 'Tell us about a payment'"></h2>
                        <p class="pt-drawer-sub" x-show="!$store.notice.done">Sent a cheque, e-Transfer or bank transfer? Let our billing team know so they can match it to your invoices quickly.</p>
                    </div>
                    <button type="button" class="pt-icon-btn" @click="$store.notice.close()" aria-label="Close"><?= pt_icon('x-mark') ?></button>
                </div>

                <template x-if="$store.notice.done">
                    <div class="pt-drawer-body">
                        <div class="pt-note pt-note--success"><?= pt_icon('check-circle') ?>
                            <span>Your payment notice is with our billing team. Your invoices will update as soon as the payment arrives and is recorded.</span>
                        </div>
                        <div class="pt-btn-row" style="margin-top:18px;justify-content:flex-end">
                            <a class="pt-btn pt-btn--secondary" :href="$store.notice.done.url">View the conversation</a>
                            <button type="button" class="pt-btn pt-btn--primary" @click="$store.notice.close()">Done</button>
                        </div>
                    </div>
                </template>

                <form x-show="!$store.notice.done" @submit.prevent="$store.notice.submit()" class="pt-drawer-body" novalidate>
                    <div class="pt-form-grid">
                        <div class="pt-field">
                            <label class="pt-label" for="pt-n-amount">Amount paid</label>
                            <input id="pt-n-amount" class="pt-input pt-num" type="text" inputmode="decimal" placeholder="0.00" x-model="$store.notice.form.amount" required>
                        </div>
                        <div class="pt-field">
                            <label class="pt-label" for="pt-n-date">Date sent</label>
                            <input id="pt-n-date" class="pt-input" type="date" x-model="$store.notice.form.paid_on" :max="PT_CONFIG.today" required>
                        </div>
                        <div class="pt-field">
                            <label class="pt-label" for="pt-n-method">How you paid</label>
                            <select id="pt-n-method" class="pt-select" x-model="$store.notice.form.method">
                                <?php foreach ($_ftMethods as $_k => $_v): ?>
                                    <option value="<?= e($_k) ?>"><?= e($_v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="pt-field">
                            <label class="pt-label" for="pt-n-ref">Reference <small>(cheque #, confirmation #)</small></label>
                            <input id="pt-n-ref" class="pt-input" type="text" maxlength="100" x-model="$store.notice.form.reference" placeholder="Optional">
                        </div>
                        <div class="pt-field span-2" x-show="$store.notice.invoices.length">
                            <span class="pt-label">Which invoices does it cover? <small>(optional)</small></span>
                            <div style="max-height:190px;overflow:auto;border:1px solid var(--border-color);border-radius:12px">
                                <template x-for="inv in $store.notice.invoices" :key="inv.id">
                                    <label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border-bottom:1px solid var(--border-color);cursor:pointer;font-size:14px">
                                        <input type="checkbox" class="pt-check" :checked="$store.notice.form.invoice_ids.includes(Number(inv.id))" @change="$store.notice.toggle(inv.id)">
                                        <span style="flex:1;font-weight:600" x-text="inv.number"></span>
                                        <span class="pt-muted" style="font-size:12.5px" x-text="'due ' + PT.date(inv.due_date)"></span>
                                        <span class="pt-num" style="font-weight:600" x-text="PT.money(inv.balance, inv.currency)"></span>
                                    </label>
                                </template>
                            </div>
                        </div>
                        <div class="pt-field span-2">
                            <label class="pt-label" for="pt-n-note">Anything else? <small>(optional)</small></label>
                            <textarea id="pt-n-note" class="pt-textarea" style="min-height:80px" maxlength="1000" x-model="$store.notice.form.note" placeholder="e.g. Paid from our US account"></textarea>
                        </div>
                    </div>
                    <div class="pt-note pt-note--danger" x-show="$store.notice.error" x-cloak style="margin-top:14px"><?= pt_icon('exclamation-triangle') ?><span x-text="$store.notice.error"></span></div>
                    <div class="pt-btn-row" style="margin-top:18px;justify-content:flex-end">
                        <button type="button" class="pt-btn pt-btn--ghost" @click="$store.notice.close()">Cancel</button>
                        <button type="submit" class="pt-btn pt-btn--primary" :disabled="$store.notice.sending">
                            <span class="pt-spin" x-show="$store.notice.sending" x-cloak></span>
                            <span x-text="$store.notice.sending ? 'Sending…' : 'Send to billing'"></span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Statement picker ─────────────────────────────────────────────── -->
    <div x-data x-show="$store.statement.open" x-cloak @keydown.escape.window="$store.statement.open && $store.statement.close()">
        <div class="pt-overlay" x-show="$store.statement.open" x-transition:enter="pt-fade-enter" x-transition:enter-start="pt-fade-enter-start" x-transition:enter-end="pt-fade-enter-end" @click="$store.statement.close()"></div>
        <div class="pt-modal-wrap" @click.self="$store.statement.close()">
            <div class="pt-modal pt-modal--sm" role="dialog" aria-modal="true" aria-labelledby="pt-st-title" x-show="$store.statement.open"
                 x-transition:enter="pt-modal-enter" x-transition:enter-start="pt-modal-enter-start" x-transition:enter-end="pt-modal-enter-end">
                <div class="pt-drawer-head">
                    <div>
                        <h2 class="pt-drawer-title" id="pt-st-title">Statement of account</h2>
                        <p class="pt-drawer-sub">Every invoice, payment and credit in the period with a running balance, plus what's still open.</p>
                    </div>
                    <button type="button" class="pt-icon-btn" @click="$store.statement.close()" aria-label="Close"><?= pt_icon('x-mark') ?></button>
                </div>
                <div class="pt-drawer-body">
                    <div class="pt-tabs" style="margin-bottom:16px">
                        <template x-for="p in [['month','This month'],['last','Last month'],['3m','3 months'],['ytd','Year to date'],['12m','12 months']]" :key="p[0]">
                            <button type="button" class="pt-tab" :class="{ 'is-active': $store.statement.preset === p[0] }" @click="$store.statement.apply(p[0])" x-text="p[1]"></button>
                        </template>
                    </div>
                    <div class="pt-form-grid">
                        <div class="pt-field"><label class="pt-label" for="pt-st-from">From</label><input id="pt-st-from" type="date" class="pt-input" x-model="$store.statement.from" @input="$store.statement.preset = ''"></div>
                        <div class="pt-field"><label class="pt-label" for="pt-st-to">To</label><input id="pt-st-to" type="date" class="pt-input" x-model="$store.statement.to" :max="PT_CONFIG.today" @input="$store.statement.preset = ''"></div>
                    </div>
                    <div class="pt-btn-row" style="margin-top:20px;justify-content:flex-end">
                        <button type="button" class="pt-btn pt-btn--ghost" @click="$store.statement.close()">Cancel</button>
                        <a class="pt-btn pt-btn--primary" :class="{ 'is-disabled': !$store.statement.valid }" :href="$store.statement.href" target="_blank" rel="noopener" @click="$store.statement.close()">
                            <?= pt_icon('arrow-down-tray') ?> Get statement (PDF)
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.pt-app -->

<div id="ff-toast-container" role="region" aria-live="polite" aria-label="Notifications" aria-atomic="false"></div>

<script src="<?= asset_url('assets/js/app.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<script src="<?= asset_url('assets/js/portal.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
<script defer src="<?= asset_url('assets/vendor/alpinejs/cdn.min.js') ?>?v=<?= e(FF_ASSET_VERSION) ?>"></script>
</body>
</html>
<?php unset($_ftCompany, $_ftSummary, $_ftOnline, $_ftPath, $_ftBase, $_ftIs, $_ftPay, $_ftMethods, $_k, $_v); ?>
