<?php
/**
 * includes/partials/pickers/lookup_picker.php
 *
 * Reusable HTML markup for the FF_LookupPicker Alpine component.
 * Set the following variables BEFORE including this partial:
 *
 *   $pickerId       — unique DOM id prefix (no spaces)
 *   $pickerLabel    — label text shown above the picker
 *   $pickerRequired — bool, adds * to the label
 *   $pickerConfig   — JS object literal passed to FF_LookupPicker(...)
 *                     e.g. "{ endpoint: '/api/v1/...', extraParams: {...},
 *                            format: r => r.code + ' — ' + r.name,
 *                            initialId: form.asset_account_id,
 *                            onSelect: id => form.asset_account_id = id }"
 *   $pickerPlaceholder       — placeholder for search input (default 'Search…')
 *   $pickerManualPlaceholder — placeholder when in manual entry mode (default 'Enter ID')
 *   $pickerLabelHint — optional helper text under the input
 *
 * Each include of this partial creates a new Alpine x-data scope.
 *
 * @session S-FORM-PICKERS
 */

$pickerId               = $pickerId               ?? ('picker_' . substr(md5((string) microtime(true)), 0, 6));
$pickerLabel            = $pickerLabel            ?? 'Lookup';
$pickerRequired         = $pickerRequired         ?? false;
$pickerConfig           = $pickerConfig           ?? '{}';
$pickerPlaceholder      = $pickerPlaceholder      ?? 'Search…';
$pickerManualPlaceholder = $pickerManualPlaceholder ?? 'Enter ID';
$pickerLabelHint        = $pickerLabelHint        ?? '';
?>
<div class="form-group ff-picker" x-data="FF_LookupPicker(<?= $pickerConfig ?>)">
    <label for="<?= e($pickerId) ?>"><?= e($pickerLabel) ?><?= $pickerRequired ? ' *' : '' ?></label>

    <div class="ff-picker-input-wrap">
        <!-- Search mode -->
        <template x-if="!manualMode">
            <div style="flex:1;min-width:0;position:relative;">
                <input type="text"
                       id="<?= e($pickerId) ?>"
                       class="form-control form-control-sm ff-picker-input"
                       placeholder="<?= e($pickerPlaceholder) ?>"
                       x-model="query"
                       @input="onInput()"
                       @focus="openDropdown()"
                       @blur="closeDropdown()"
                       @keydown="onKeyDown($event)"
                       autocomplete="off">

                <!-- Clear: visible when there's a selection or partial query -->
                <button type="button" class="ff-picker-clear"
                        x-show="selectedId || query"
                        x-cloak
                        @click="clear()"
                        aria-label="Clear">&times;</button>

                <!-- Chevron: visible when nothing is selected/typed — signals "picker" -->
                <button type="button"
                        class="ff-picker-chevron"
                        :class="{ 'is-open': open }"
                        x-show="!selectedId && !query"
                        x-cloak
                        @mousedown.prevent="open ? closeDropdown() : openDropdown()"
                        tabindex="-1"
                        aria-hidden="true"
                        aria-label="Open options">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M6 9l6 6 6-6"/>
                    </svg>
                </button>

                <div class="ff-picker-dropdown" x-show="open" x-cloak>
                    <template x-if="loading">
                        <div class="ff-picker-loading">Searching…</div>
                    </template>
                    <template x-if="!loading && results.length === 0 && !query">
                        <div class="ff-picker-hint">Start typing to search, or scroll to browse.</div>
                    </template>
                    <template x-if="!loading && results.length === 0 && query">
                        <div class="ff-picker-empty"
                             x-text="'No matches for &quot;' + query + '&quot;.'"></div>
                    </template>
                    <template x-for="(item, i) in results" :key="item.id">
                        <div class="ff-picker-item"
                             :class="i === highlightIndex ? 'ff-picker-item--highlight' : ''"
                             @mousedown.prevent="choose(item)"
                             x-text="formatItem(item)"></div>
                    </template>
                </div>
            </div>
        </template>

        <!-- Manual entry mode -->
        <template x-if="manualMode">
            <input type="number"
                   class="form-control form-control-sm ff-picker-input"
                   placeholder="<?= e($pickerManualPlaceholder) ?>"
                   :value="selectedId"
                   @input="setManualId($event.target.value)">
        </template>

        <!-- Toggle button -->
        <button type="button"
                class="ff-picker-toggle"
                :class="manualMode ? 'ff-picker-toggle--active' : ''"
                @click="toggleManual()"
                :title="manualMode ? 'Switch to search' : 'Switch to manual ID entry'">
            <span x-show="!manualMode" x-cloak>Manual</span>
            <span x-show="manualMode" x-cloak>Search</span>
        </button>
    </div>

    <!-- Show currently-selected pill so user always sees what's bound -->
    <div x-show="selectedId" x-cloak class="ff-picker-selected-pill">
        <span>Selected:</span>
        <strong x-text="selectedLabel || ('#' + selectedId)"></strong>
    </div>

    <?php if ($pickerLabelHint): ?>
        <div class="form-text" style="font-size:0.7rem;color:var(--text-tertiary);margin-top:2px;">
            <?= e($pickerLabelHint) ?>
        </div>
    <?php endif; ?>
</div>
