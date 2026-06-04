<?php
declare(strict_types=1);

/**
 * includes/partials/record-picker.php
 *
 * Reusable search-as-you-type record picker partial.
 *
 * REQUIRED variables (set BEFORE include):
 *   $pickerName      string   unique Alpine component reference name (for events)
 *   $pickerConfig    array    JSON-encoded config for FF_RecordPicker factory:
 *                             [
 *                                'endpoint'    => '/api/v1/customers/index.php',
 *                                'searchParam' => 'search',        // or 'q'
 *                                'resultKey'   => 'items',
 *                                'mapResult'   => "r => ({ id: r.id, label: r.company_name, sublabel: r.city + ', ' + r.province })",
 *                                'placeholder' => 'Search customers…',
 *                                'extraParams' => 'status=active', // optional
 *                                'initialId'   => 0,               // edit mode
 *                                'initialLabel'=> '',              // edit mode
 *                             ]
 *   $pickerOnPicked  string   Alpine expression to run on @record-picked
 *                             (e.g. "form.customer_id = $event.detail.id; onCustomerChange($event.detail.raw)")
 *   $pickerOnCleared string   Alpine expression for @record-cleared
 *                             (e.g. "form.customer_id = ''")
 *   $pickerError     string   Optional x-show expression for inline error
 *                             (e.g. "errors.customer_id")
 *
 * WHY a partial: every admin create/edit form that selects a customer,
 * lease, equipment unit, vendor, or invoice can drop in this markup with
 * a 5-line config block, rather than reimplementing 40+ lines of picker
 * HTML per form. Keeps styling + keyboard behavior consistent.
 *
 * Spec: SELECTOR-UNIFY (2026-04-09)
 */

// mapResult default
$_pickerConfig = $pickerConfig ?? [];
$_mapResult    = $_pickerConfig['mapResult'] ?? "r => ({ id: r.id, label: r.name || ('#' + r.id), sublabel: '' })";

// Build the x-data expression — we inline mapResult as a JS function literal
// rather than JSON so the arrow-function survives the encode step.
$_cfgKeys = [];
foreach (['endpoint','searchParam','resultKey','perPage','extraParams','minChars','placeholder','initialId','initialLabel'] as $k) {
    if (array_key_exists($k, $_pickerConfig)) {
        $_cfgKeys[] = $k . ': ' . json_encode($_pickerConfig[$k], JSON_UNESCAPED_SLASHES);
    }
}
$_cfgKeys[] = 'mapResult: ' . $_mapResult;
$_xData     = 'FF_RecordPicker({ ' . implode(', ', $_cfgKeys) . ' })';

$_name       = $pickerName       ?? 'picker';
$_onPicked   = $pickerOnPicked   ?? '';
$_onCleared  = $pickerOnCleared  ?? '';
$_errorExpr  = $pickerError      ?? '';
?>
<div class="ff-picker"
     x-data='<?= htmlspecialchars($_xData, ENT_QUOTES) ?>'
     x-id="['ff-picker']"
     @record-picked="<?= htmlspecialchars($_onPicked, ENT_QUOTES) ?>"
     @record-cleared="<?= htmlspecialchars($_onCleared, ENT_QUOTES) ?>"
     @click.outside="showDropdown = false">

    <input type="text"
           class="form-control ff-picker-input"
           :class="{ 'is-selected': selected, 'is-invalid': <?= $_errorExpr !== '' ? htmlspecialchars($_errorExpr, ENT_QUOTES) : 'false' ?> }"
           x-model="query"
           :placeholder="_cfg.placeholder"
           @input="onInput()"
           @focus="onFocus()"
           @blur="onBlur()"
           @keydown="handleKey($event)"
           autocomplete="off">

    <!-- Clear button: visible once something is selected -->
    <button type="button"
            class="ff-picker-clear"
            x-show="selected"
            x-cloak
            @click="clear()"
            aria-label="Clear selection">✕</button>

    <!-- Chevron: visible when nothing is selected — signals "this is a picker" -->
    <button type="button"
            class="ff-picker-chevron"
            :class="{ 'is-open': showDropdown }"
            x-show="!selected"
            x-cloak
            @mousedown.prevent="onChevronClick()"
            tabindex="-1"
            aria-hidden="true"
            aria-label="Open options">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M6 9l6 6 6-6"/>
        </svg>
    </button>

    <div class="ff-picker-dropdown"
         x-show="showDropdown"
         x-cloak
         x-transition.opacity.duration.100ms>

        <div class="ff-picker-loading" x-show="loading" x-cloak>Searching…</div>

        <!-- Hint shown when dropdown opens before the user types -->
        <div class="ff-picker-hint"
             x-show="!loading && results.length === 0 && !query">
            Start typing to search, or scroll to browse.
        </div>

        <template x-for="(r, i) in results" :key="r.id">
            <div class="ff-picker-option"
                 :class="{ 'is-highlighted': i === highlightIdx }"
                 @mousedown.prevent="pick(r)"
                 @mouseenter="highlightIdx = i">
                <div class="ff-picker-option-label" x-text="r.label"></div>
                <div class="ff-picker-option-sub" x-show="r.sublabel" x-text="r.sublabel"></div>
            </div>
        </template>

        <div class="ff-picker-empty" x-show="!loading && results.length === 0 && query">
            No matches found for "<span x-text="query"></span>".
        </div>
    </div>
</div>
<?php unset($_pickerConfig, $_mapResult, $_cfgKeys, $_xData, $_name, $_onPicked, $_onCleared, $_errorExpr); ?>
