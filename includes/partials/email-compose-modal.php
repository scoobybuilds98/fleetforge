<?php
declare(strict_types=1);

/**
 * includes/partials/email-compose-modal.php
 *
 * Global email compose modal — included once from includes/footer.php
 * so that ANY admin page can call window.openEmailCompose({...}) to
 * open it without rendering its own modal markup.
 *
 * The Alpine component is FF_EmailCompose() (defined in app.js).
 * The opener stub is window.openEmailCompose() (also in app.js).
 *
 * @session  EMAIL-1
 * @depends  app.js → FF_EmailCompose, FF_Api, FF_Toast
 * @schema   email_templates, email_logs, email_attachments
 */
?>
<!-- ============================================================
     GLOBAL EMAIL COMPOSE MODAL — opened via window.openEmailCompose
     ============================================================ -->
<div id="ff-email-compose"
     x-data="FF_EmailCompose()"
     x-init="window._ffEmailCompose = $data"
     x-show="show"
     x-cloak
     class="modal-overlay"
     style="z-index: var(--z-modal);"
     @keydown.escape.window="close()"
     @ff-email-compose.window="open($event.detail || {})">

    <!-- Click-outside backdrop -->
    <div class="modal-backdrop" @click="close()" aria-hidden="true"></div>

    <div class="modal modal-email" @click.stop style="max-height: 90vh;">

        <!-- Header -->
        <div class="modal-header">
            <h3 class="modal-title">
                <?= heroicon('envelope', 'modal-icon') ?>
                Compose Email
            </h3>
            <button type="button" class="modal-close-btn" @click="close()" aria-label="Close">
                <?= heroicon('x-mark', 'modal-icon') ?>
            </button>
        </div>

        <!-- Body -->
        <div class="modal-body">

            <!-- ── Template selector + Quick contacts row ─────── -->
            <div class="form-group">
                <label class="form-label">Template</label>
                <select class="form-control" x-model="selectedTemplateId" @change="loadTemplate()">
                    <option value="">Custom message (no template)</option>
                    <template x-for="t in templates" :key="t.id">
                        <option :value="t.id"
                                x-text="t.name + ' (' + t.category + ')'"></option>
                    </template>
                </select>
            </div>

            <!-- ── To email + name ─────────────────────────────── -->
            <div class="form-row" style="display:grid; grid-template-columns: 2fr 1fr; gap: 12px;">
                <div class="form-group">
                    <label class="form-label">To <span class="required">*</span></label>
                    <input type="email"
                           class="form-control"
                           x-model="toEmail"
                           placeholder="customer@example.com"
                           required>
                </div>
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text"
                           class="form-control"
                           x-model="toName"
                           placeholder="Contact name">
                </div>
            </div>

            <!-- Quick-fill contact chips -->
            <div class="form-group" x-show="contacts.length > 0" style="margin-top:-8px;">
                <div class="email-chip-row">
                    <span class="email-chip-label">Quick fill:</span>
                    <template x-for="c in contacts" :key="c.id">
                        <button type="button"
                                class="email-chip"
                                @click="fillContact(c)"
                                :title="c.email">
                            <span x-text="c.name"></span>
                            <span class="email-chip-meta" x-text="' — ' + c.email"></span>
                            <span x-show="c.label"
                                  class="email-chip-tag"
                                  x-text="c.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            <!-- ── Reply-to ────────────────────────────────────── -->
            <div class="form-group">
                <label class="form-label">Reply-to</label>
                <input type="email"
                       class="form-control"
                       x-model="replyTo"
                       :placeholder="defaultReplyTo || 'Leave blank to use default sender'">
            </div>

            <!-- ── Subject ─────────────────────────────────────── -->
            <div class="form-group">
                <label class="form-label">Subject <span class="required">*</span></label>
                <input type="text"
                       class="form-control"
                       x-model="subject"
                       placeholder="Email subject"
                       required>
            </div>

            <!-- ── Body ────────────────────────────────────────── -->
            <div class="form-group">
                <label class="form-label">
                    Message <span class="required">*</span>
                    <button type="button"
                            class="btn btn-link btn-xs"
                            style="float:right; padding:0;"
                            @click="showVariables = !showVariables">
                        <span x-show="!showVariables">Show variables</span>
                        <span x-show="showVariables">Hide variables</span>
                    </button>
                </label>

                <textarea class="form-control"
                          x-model="bodyHtml"
                          x-ref="bodyField"
                          rows="11"
                          placeholder="Email message... Basic HTML is supported."
                          style="font-family: ui-monospace, 'SF Mono', Menlo, Consolas, monospace; font-size: 12.5px;"></textarea>

                <p class="form-hint">
                    Basic HTML is supported. Use <code>{customer_name}</code>,
                    <code>{invoice_number}</code>, etc. for variables.
                </p>

                <!-- Variables panel -->
                <div class="email-variables-panel" x-show="showVariables" x-cloak>
                    <div class="email-variables-label">Click to insert:</div>
                    <div class="email-variables-list">
                        <template x-for="v in availableVariables" :key="v">
                            <button type="button"
                                    class="email-variable-chip"
                                    @click="insertVariable(v)">
                                <span x-text="'{' + v + '}'"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ── Attachments ─────────────────────────────────── -->
            <div class="form-group">
                <label class="form-label">Attachments</label>

                <!-- Selected attachments -->
                <div class="email-attachment-list" x-show="attachments.length > 0">
                    <template x-for="(a, i) in attachments" :key="i">
                        <div class="email-attachment-item">
                            <?= heroicon('paper-clip', 'email-attachment-icon') ?>
                            <span class="email-attachment-name" x-text="a.name"></span>
                            <span class="email-attachment-size" x-text="formatSize(a.size)"></span>
                            <button type="button"
                                    class="email-attachment-remove"
                                    @click="removeAttachment(i)"
                                    aria-label="Remove attachment">
                                <?= heroicon('x-mark', 'email-attachment-icon') ?>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Add attachment buttons -->
                <div class="email-attachment-actions">
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            @click="showDocumentPicker = !showDocumentPicker"
                            x-show="customerId">
                        <?= heroicon('folder-open', 'btn-icon') ?>
                        From Documents
                    </button>
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            @click="showInvoicePicker = !showInvoicePicker"
                            x-show="invoices.length > 0">
                        <?= heroicon('document-text', 'btn-icon') ?>
                        Attach Invoice PDF
                    </button>
                    <label class="btn btn-secondary btn-sm" style="cursor:pointer;">
                        <?= heroicon('arrow-up-tray', 'btn-icon') ?>
                        Upload File
                        <input type="file"
                               @change="uploadAttachment($event)"
                               multiple
                               accept=".pdf,.doc,.docx,.xlsx,.xls,.csv,.txt,.jpg,.jpeg,.png,.gif"
                               style="display:none;">
                    </label>
                </div>

                <!-- Document picker drawer -->
                <div class="email-picker-drawer" x-show="showDocumentPicker" x-cloak>
                    <div class="email-picker-header">
                        <span>Select documents to attach</span>
                        <button type="button" class="btn btn-link btn-xs" @click="showDocumentPicker = false">Close</button>
                    </div>
                    <div class="email-picker-search">
                        <input type="text"
                               class="form-control form-control-sm"
                               x-model="docSearch"
                               placeholder="Search documents...">
                    </div>
                    <div class="email-picker-list">
                        <template x-for="doc in filteredDocs" :key="doc.id">
                            <div class="email-picker-item" @click="addDocumentAttachment(doc)">
                                <?= heroicon('document-text', 'email-picker-icon') ?>
                                <div class="email-picker-item-body">
                                    <div class="email-picker-item-name" x-text="doc.name"></div>
                                    <div class="email-picker-item-meta" x-text="doc.entity_label"></div>
                                </div>
                                <span class="email-picker-add">+ Add</span>
                            </div>
                        </template>
                        <div x-show="filteredDocs.length === 0" class="email-picker-empty">
                            No documents available for this customer.
                        </div>
                    </div>
                </div>

                <!-- Invoice picker drawer -->
                <div class="email-picker-drawer" x-show="showInvoicePicker" x-cloak>
                    <div class="email-picker-header">
                        <span>Select invoice PDF to attach</span>
                        <button type="button" class="btn btn-link btn-xs" @click="showInvoicePicker = false">Close</button>
                    </div>
                    <div class="email-picker-list">
                        <template x-for="inv in invoices" :key="inv.id">
                            <div class="email-picker-item" @click="addInvoiceAttachment(inv)">
                                <?= heroicon('document-text', 'email-picker-icon') ?>
                                <div class="email-picker-item-body">
                                    <div class="email-picker-item-name" x-text="inv.invoice_number"></div>
                                    <div class="email-picker-item-meta">
                                        <span x-text="inv.total_amount_formatted"></span>
                                        <span class="badge ml-2" :class="'badge-' + inv.status_class" x-text="inv.status"></span>
                                    </div>
                                </div>
                                <span class="email-picker-add">+ Add</span>
                            </div>
                        </template>
                        <div x-show="invoices.length === 0" class="email-picker-empty">
                            No invoices available for this customer.
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Live preview ────────────────────────────────── -->
            <div class="email-preview-frame" x-show="showPreview" x-cloak>
                <div class="email-preview-label">
                    <?= heroicon('eye', 'modal-icon') ?>
                    Email Preview
                </div>
                <div class="email-preview-body" x-html="bodyHtmlPreview"></div>
            </div>

        </div>

        <!-- Footer -->
        <div class="modal-footer" style="justify-content: space-between;">
            <button type="button"
                    class="btn btn-secondary btn-sm"
                    @click="togglePreview()">
                <?= heroicon('eye', 'btn-icon') ?>
                <span x-text="showPreview ? 'Hide Preview' : 'Preview Email'"></span>
            </button>
            <div style="display:flex; gap:8px;">
                <button type="button" class="btn btn-ghost btn-sm" @click="close()">Cancel</button>
                <button type="button"
                        class="btn btn-primary btn-sm"
                        @click="send()"
                        :disabled="sending">
                    <span x-show="!sending" style="display:inline-flex; align-items:center; gap:6px;">
                        <?= heroicon('paper-airplane', 'btn-icon') ?>
                        Send Email
                    </span>
                    <span x-show="sending">Sending...</span>
                </button>
            </div>
        </div>

    </div>
</div>
