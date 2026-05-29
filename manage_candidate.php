<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$candidateId = (int) ($_GET['candidate_id'] ?? 0);
$returnQuery = trim((string) ($_GET['return_query'] ?? ''));
$sourceMode = trim((string) ($_GET['source_mode'] ?? ''));
$isDistrictCandidateDataMode = $sourceMode === 'district_candidate_data';
if ($returnQuery === '') {
    $queryParams = $_GET;
    unset($queryParams['candidate_id'], $queryParams['tab'], $queryParams['return_query']);
    $returnQuery = $queryParams !== [] ? http_build_query($queryParams) : '';
}
$returnPage = $isDistrictCandidateDataMode ? '/district_candidate_data.php' : '/job_fair_results.php';
$returnUrl = $returnPage . ($returnQuery !== '' ? ('?' . $returnQuery) : '');

if ($candidateId <= 0) {
    header('Location: ' . $returnUrl);
    exit;
}

$candidateStmt = db()->prepare('SELECT id, Candidate_Name, DWMS_ID, Selection_Status FROM job_fair_result WHERE id = ? LIMIT 1');
$candidateStmt->execute([$candidateId]);
$candidate = $candidateStmt->fetch();
if (!$candidate) {
    header('Location: ' . $returnUrl);
    exit;
}

render_header('Manage Candidate');
render_page_header('Manage Candidate', [
    'icon' => 'bi-person-vcard',
    'subtitle' => ($candidate['Candidate_Name'] ?: 'N/A') . ' · DWMS ID ' . ($candidate['DWMS_ID'] ?: 'N/A'),
    'actions' => '<button type="button" class="btn btn-outline-primary me-1" id="openCallHistoryFloaterBtn"><i class="bi bi-telephone-fill me-1"></i>Call History</button>'
        . '<a class="btn btn-light" href="' . esc($returnUrl) . '"><i class="bi bi-arrow-left me-1"></i>Back to Results</a>',
]);
?>

<form method="post" action="/job_fair_results.php" id="manageCandidateForm">
    <input type="hidden" name="candidate_id" value="<?= (int) $candidateId ?>" id="candidateId">
    <input type="hidden" name="modal_candidate_id" value="<?= (int) $candidateId ?>">
    <input type="hidden" name="modal_active_tab" value="" id="activeTabInput">
    <input type="hidden" name="return_to" value="manage_candidate.php">
    <input type="hidden" name="return_query" value="<?= esc($returnQuery) ?>">
    <input type="hidden" name="source_mode" value="<?= esc($sourceMode) ?>">

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <span>Candidate Details</span>
            <div id="candidateDetailStatuses" class="d-flex gap-2"></div>
        </div>
        <div class="card-body">
            <div class="row g-3" id="candidateDetailPanel"></div>
        </div>
    </div>

    <div id="dynamicPanels"></div>
</form>

<div id="callHistoryFloater" aria-hidden="true">
    <div class="ch-header" id="callHistoryFloaterHeader">
        <span class="fw-semibold"><i class="bi bi-telephone-fill me-1 text-primary"></i>Call History</span>
        <div class="d-flex gap-1">
            <button type="button" class="btn btn-sm btn-link p-0 text-muted" id="callHistoryFloaterMinimise" aria-label="Minimise" title="Minimise"><i class="bi bi-dash-square"></i></button>
            <button type="button" class="btn btn-sm btn-link p-0 text-muted" id="callHistoryFloaterClose" aria-label="Close" title="Close"><i class="bi bi-x-square"></i></button>
        </div>
    </div>
    <div class="ch-body" id="callHistoryFloaterBody">
        <div class="text-muted small">Loading...</div>
    </div>
</div>

<style>
.detail-group { padding: .25rem .5rem; height: 100%; }
.detail-group-title { font-size: .7rem; text-transform: uppercase; color: var(--pjf-muted, #64748b); letter-spacing: .04em; font-weight: 600; margin-bottom: .35rem; padding-bottom: .25rem; border-bottom: 1px solid var(--pjf-border, #e2e8f0); }
.detail-line { display: flex; justify-content: space-between; gap: .5rem; padding: 3px 0; }
.detail-line + .detail-line { border-top: 1px dashed var(--pjf-border, #e2e8f0); }
.dl-label { font-size: .72rem; color: var(--pjf-muted, #64748b); flex: 0 0 auto; }
.dl-value { font-size: .82rem; font-weight: 500; text-align: right; word-break: break-word; }
.dl-value .small-chip { font-size: .65rem; padding: 1px 6px; border-radius: 10px; background: var(--pjf-primary-soft, #eff4ff); color: var(--pjf-primary-dark, #1d4ed8); margin-left: .35rem; }
#callHistoryFloater { position: fixed; top: 110px; right: 20px; width: 520px; max-width: 95vw; max-height: 78vh; background: #fff; border: 1px solid var(--pjf-border, #e2e8f0); border-radius: 10px; box-shadow: 0 12px 32px rgba(15, 23, 42, .18); z-index: 1055; display: none; flex-direction: column; }
#callHistoryFloater.show { display: flex; }
#callHistoryFloater.minimised .ch-body { display: none; }
#callHistoryFloater .ch-header { padding: .5rem .75rem; background: var(--pjf-surface-alt, #f8fafc); border-bottom: 1px solid var(--pjf-border, #e2e8f0); border-radius: 10px 10px 0 0; cursor: move; user-select: none; display: flex; justify-content: space-between; align-items: center; }
#callHistoryFloater .ch-body { padding: .75rem; overflow-y: auto; flex: 1 1 auto; }
@media (max-width: 575.98px) {
    #callHistoryFloater { left: 10px; right: 10px; width: auto; }
}
</style>

<script>
const candidateId = <?= json_encode($candidateId) ?>;
const detailPanel = document.getElementById('candidateDetailPanel');
const candidateDetailStatuses = document.getElementById('candidateDetailStatuses');
const dynamicPanels = document.getElementById('dynamicPanels');
const activeTabInput = document.getElementById('activeTabInput');
const requestedTab = <?= json_encode(trim((string) ($_GET['tab'] ?? ''))) ?>;
const isDistrictCandidateDataMode = <?= json_encode($isDistrictCandidateDataMode) ?>;

let fieldConfig = [];
let callHistoryPurposeOptions = [];
let shortlistRoundOptions = {
    round_type: [],
    round_status: [],
    round_remarks: [],
    round_selection_status: []
};
let shortlistRounds = [];
let currentRow = null;

function escapeHtml(value) { return String(value ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;'); }
function formatLabel(name) { return String(name || '').replaceAll('_', ' '); }
function toInputDatetime(value) {
    if (!value) return '';
    const text = String(value).replace(' ', 'T').slice(0,19);
    const date = new Date(text);
    if (Number.isNaN(date.getTime())) return String(value).replace(' ', 'T').slice(0,16);
    const ist = new Date(date.getTime() + (5.5 * 60 * 60 * 1000));
    return ist.toISOString().slice(0,16);
}
function formatDisplayDatetime(value) {
    if (!value) return 'N/A';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' }).format(date) + ' IST';
}
function formatDisplayDate(value) {
    if (!value) return 'N/A';
    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) return String(value);
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeZone: 'Asia/Kolkata' }).format(date);
}
function nowIstInput() {
    const now = new Date();
    const ist = new Date(now.getTime() + (5.5 * 60 * 60 * 1000));
    return ist.toISOString().slice(0,16);
}
function enumValues(type) {
    const m = String(type || '').match(/^enum\((.+)\)$/i);
    if (!m) return [];
    return m[1].split(',').map((v) => v.trim().replace(/^'+|'+$/g, ''));
}

function statusChip(label, value) {
    const normalizedValue = String(value || '').trim();
    const statusClass = normalizedValue ? `status-${normalizedValue.toLowerCase().replace(/[^a-z0-9]+/g, '')}` : '';
    return `<span class="status-chip ${statusClass}">${escapeHtml(label)}: ${escapeHtml(normalizedValue || 'N/A')}</span>`;
}


function shouldHighlightFieldLabel(fieldName) {
    const highlightFields = new Set([
        'offerlettergeneratedremarks',
        'confirmletterreceiptremarks',
        'willingtojoinremarks',
        'responsefromemployer',
        'challengetobeaddressed',
        'remarkscandidatejoin',
        'shortlistremarks',
        'shortlistcurrentprocessstatus',
        'shortlistnextprocess'
    ]);
    const normalizedFieldName = String(fieldName || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    return highlightFields.has(normalizedFieldName);
}

function renderFieldControl(config, row) {
    const value = row[config.field_name] ?? '';
    const fieldNameNormalized = String(config.field_name || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    const isOfferLetterField = fieldNameNormalized === 'linktoofferletter';

    const labelClass = shouldHighlightFieldLabel(config.field_name) ? 'form-label focus-field-label' : 'form-label';

    if (config.field_type === 'label') {
        return `<label class="${labelClass}">${formatLabel(config.field_name)}</label><div class="form-control bg-light">${escapeHtml(value || 'N/A')}</div>`;
    }
    if (String(config.field_type).toLowerCase().startsWith('enum(')) {
        const options = enumValues(config.field_type).map((opt) => `<option value="${escapeHtml(opt)}" ${opt === value ? 'selected' : ''}>${escapeHtml(opt)}</option>`).join('');
        return `<label class="${labelClass}">${formatLabel(config.field_name)}</label><select class="form-select" name="${config.field_name}"><option value="">Select</option>${options}</select>`;
    }
    if (String(config.field_type).toLowerCase().includes('date textbox')) {
        return `<label class="${labelClass}">${formatLabel(config.field_name)}</label><input class="form-control" type="date" name="${config.field_name}" value="${escapeHtml(value || '')}">`;
    }
    if (String(config.field_type).toLowerCase().includes('date time')) {
        return `<label class="${labelClass}">${formatLabel(config.field_name)}</label><input class="form-control" type="datetime-local" name="${config.field_name}" value="${toInputDatetime(value)}">`;
    }

    if (isOfferLetterField) {
        const inputId = `offer-letter-link-input-${candidateId}`;
        const iconId = `offer-letter-link-icon-${candidateId}`;
        const safeValue = escapeHtml(value || '');
        const canOpen = String(value || '').trim() !== '';
        return `
            <label class="${labelClass} d-flex justify-content-between align-items-center">
                <span>${formatLabel(config.field_name)}</span>
                <a id="${iconId}" class="offer-letter-link-icon ${canOpen ? '' : 'disabled'}" href="${canOpen ? safeValue : '#'}" target="_blank" rel="noopener noreferrer" title="Open offer letter in a new tab" aria-label="Open offer letter in a new tab">🔗</a>
            </label>
            <input id="${inputId}" class="form-control" type="text" name="${config.field_name}" value="${safeValue}" oninput="updateOfferLetterLink('${inputId}', '${iconId}')">
        `;
    }

    return `<label class="${labelClass}">${formatLabel(config.field_name)}</label><input class="form-control" type="text" name="${config.field_name}" value="${escapeHtml(value || '')}">`;
}

function updateOfferLetterLink(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (!input || !icon) return;

    const rawValue = String(input.value || '').trim();
    if (!rawValue) {
        icon.href = '#';
        icon.classList.add('disabled');
        return;
    }

    icon.href = rawValue;
    icon.classList.remove('disabled');
}

function renderCallHistoryRows(rows) {
    const body = document.getElementById('callHistoryBody');
    if (!body) return;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No call history found.</td></tr>';
        return;
    }
    body.innerHTML = rows.map((r, i) => `<tr><td>${i+1}</td><td>${escapeHtml(r.stage || 'N/A')}</td><td>${escapeHtml(r.purpose_name || 'N/A')}</td><td>${escapeHtml(formatDisplayDatetime(r.call_datetime || ''))}</td><td>${escapeHtml(r.call_status || 'N/A')}</td><td>${escapeHtml(r.call_remarks || 'N/A')}</td></tr>`).join('');
}

function renderActivityRows(rows) {
    const body = document.getElementById('activityLogBody');
    if (!body) return;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No activity log found.</td></tr>';
        return;
    }
    body.innerHTML = rows.map((r, i) => `<tr><td>${i+1}</td><td>${escapeHtml(r.activity_section || 'N/A')}</td><td>${escapeHtml(r.activity_type || 'N/A')}</td><td>${escapeHtml(String(r.activity_details || 'N/A')).replaceAll('\n','<br>')}</td><td>${escapeHtml(r.created_by_name || 'N/A')}</td><td>${escapeHtml(formatDisplayDatetime(r.created_at || ''))}</td></tr>`).join('');
}

function shortlistRoundSelectOptions(values, selectedValue, includeBlank = true) {
    const options = values.map((value) => `<option value="${escapeHtml(value)}" ${value === selectedValue ? 'selected' : ''}>${escapeHtml(value)}</option>`).join('');
    return `${includeBlank ? '<option value="">Select</option>' : ''}${options}`;
}

function shortlistRoundForm(round = null) {
    const isEdit = Boolean(round && round.id);
    return `
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>${isEdit ? 'Edit Shortlist Round' : 'Add Shortlist Round'}</span>
                ${isEdit ? '<button type="button" class="btn btn-outline-secondary btn-sm" onclick="resetShortlistRoundForm()">Cancel Edit</button>' : ''}
            </div>
            <div class="card-body">
                <input type="hidden" name="shortlist_round_id" value="${escapeHtml(round?.id || '')}">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Round Number <span class="text-danger">*</span></label>
                        <input class="form-control" type="number" min="1" step="1" name="shortlist_round_number" value="${escapeHtml(round?.round_number || '')}" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Round Scheduled Date <span class="text-danger">*</span></label>
                        <input class="form-control" type="date" name="shortlist_round_scheduled_date" value="${escapeHtml(round?.round_scheduled_date || '')}" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Round Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="shortlist_round_type" required>${shortlistRoundSelectOptions(shortlistRoundOptions.round_type || [], round?.round_type || '')}</select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Round Status <span class="text-danger">*</span></label>
                        <select class="form-select" name="shortlist_round_status" required>${shortlistRoundSelectOptions(shortlistRoundOptions.round_status || [], round?.round_status || '')}</select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Round Remarks</label>
                        <select class="form-select" name="shortlist_round_remarks">${shortlistRoundSelectOptions(shortlistRoundOptions.round_remarks || [], round?.round_remarks || '')}</select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="shortlist_round_additional_remarks" rows="1">${escapeHtml(round?.additional_remarks || '')}</textarea>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Round Selection Status <span class="text-danger">*</span></label>
                        <select class="form-select" name="shortlist_round_selection_status" required>${shortlistRoundSelectOptions(shortlistRoundOptions.round_selection_status || [], round?.round_selection_status || '')}</select>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button type="submit" class="btn btn-primary btn-sm" name="update_section" value="shortlist_round_save">${isEdit ? 'Update Round' : 'Add Round'}</button>
            </div>
        </div>
    `;
}

function renderShortlistRoundRows(rows) {
    const body = document.getElementById('shortlistRoundBody');
    if (!body) return;
    if (!rows.length) {
        body.innerHTML = '<tr><td colspan="9" class="text-center text-muted">No shortlist rounds added yet.</td></tr>';
        return;
    }
    body.innerHTML = rows.map((round, index) => `
        <tr>
            <td>${index + 1}</td>
            <td>${escapeHtml(round.round_number || 'N/A')}</td>
            <td>${escapeHtml(formatDisplayDate(round.round_scheduled_date || ''))}</td>
            <td>${escapeHtml(round.round_type || 'N/A')}</td>
            <td>${escapeHtml(round.round_status || 'N/A')}</td>
            <td>${escapeHtml(round.round_remarks || 'N/A')}</td>
            <td>${escapeHtml(round.additional_remarks || 'N/A')}</td>
            <td>${escapeHtml(round.round_selection_status || 'N/A')}</td>
            <td><button type="button" class="btn btn-outline-primary btn-sm" onclick="editShortlistRound(${Number(round.id)})">Edit</button></td>
        </tr>
    `).join('');
}

function renderShortlistRoundsSection() {
    const container = document.getElementById('shortlistRoundsSection');
    if (!container) return;
    const editingId = Number(container.dataset.editingId || 0);
    const editingRound = editingId > 0 ? shortlistRounds.find((round) => Number(round.id) === editingId) || null : null;
    container.innerHTML = `
        ${shortlistRoundForm(editingRound)}
        <div class="card">
            <div class="card-header">Shortlist Round History</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Sl no</th>
                                <th>Round Number</th>
                                <th>Scheduled Date</th>
                                <th>Round Type</th>
                                <th>Round Status</th>
                                <th>Round Remarks</th>
                                <th>Remarks</th>
                                <th>Selection Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="shortlistRoundBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
    renderShortlistRoundRows(shortlistRounds);
}

function editShortlistRound(roundId) {
    const container = document.getElementById('shortlistRoundsSection');
    if (!container) return;
    container.dataset.editingId = String(roundId || '');
    renderShortlistRoundsSection();
}

function resetShortlistRoundForm() {
    const container = document.getElementById('shortlistRoundsSection');
    if (!container) return;
    container.dataset.editingId = '';
    renderShortlistRoundsSection();
}

function loadHistory() {
    fetch(`/job_fair_results.php?candidate_call_history=${candidateId}`).then(r => r.json()).then((rows) => renderCallHistoryRows(Array.isArray(rows) ? rows : [])).catch(() => renderCallHistoryRows([]));
    fetch(`/job_fair_results.php?candidate_manage_activity_log=${candidateId}`).then(r => r.json()).then((rows) => renderActivityRows(Array.isArray(rows) ? rows : [])).catch(() => renderActivityRows([]));
}

function loadShortlistRounds() {
    fetch(`/job_fair_results.php?candidate_shortlist_rounds=${candidateId}`)
        .then((r) => r.json())
        .then((rows) => {
            shortlistRounds = Array.isArray(rows) ? rows : [];
            renderShortlistRoundsSection();
        })
        .catch(() => {
            shortlistRounds = [];
            renderShortlistRoundsSection();
        });
}

function renderCallHistoryFloater(row) {
    const body = document.getElementById('callHistoryFloaterBody');
    if (!body) return;
    body.innerHTML = `
        <div class="card mb-2"><div class="card-header py-2 small">Add Call Detail</div><div class="card-body p-2"><div class="row g-2">
            <div class="col-md-6"><label class="form-label small mb-1">Stage</label><select class="form-select form-select-sm" name="call_history_stage" form="manageCandidateForm"><option value="">Select</option><option>Employer Connect</option><option>Candidate Connect</option><option>Aggregator Contact</option></select></div>
            <div class="col-md-6"><label class="form-label small mb-1">Purpose</label><select class="form-select form-select-sm" name="call_history_purpose_id" form="manageCandidateForm"><option value="">Select</option>${callHistoryPurposeOptions.map((o) => `<option value="${o.id}">${escapeHtml(o.purpose_name)}</option>`).join('')}</select></div>
            <div class="col-md-6"><label class="form-label small mb-1">Call Date Time</label><input type="datetime-local" class="form-control form-control-sm" name="call_history_call_datetime" value="${nowIstInput()}" readonly form="manageCandidateForm"></div>
            <div class="col-md-6"><label class="form-label small mb-1">Call Status</label><select class="form-select form-select-sm" name="call_history_call_status" form="manageCandidateForm"><option value="">Select</option><option>Attended</option><option>Not attended</option><option>Invalid number</option></select></div>
            <div class="col-12"><label class="form-label small mb-1">Call Remarks</label><textarea class="form-control form-control-sm" name="call_history_call_remarks" rows="2" form="manageCandidateForm"></textarea></div>
        </div>
        <div class="d-flex justify-content-end mt-2"><button type="submit" class="btn btn-primary btn-sm" name="update_section" value="call_history" formnovalidate form="manageCandidateForm"><i class="bi bi-plus-lg me-1"></i>Add Call History</button></div>
        </div></div>

        <div class="card mb-2"><div class="card-header py-2 small">Call History</div><div class="card-body p-0"><div class="table-responsive" style="max-height: 22vh;"><table class="table table-sm mb-0"><thead class="sticky-top bg-light"><tr><th>#</th><th>Stage</th><th>Purpose</th><th>Date</th><th>Status</th><th>Remarks</th></tr></thead><tbody id="callHistoryBody"></tbody></table></div></div></div>

        <div class="card"><div class="card-header py-2 small">Activity Log</div><div class="card-body p-0"><div class="table-responsive" style="max-height: 22vh;"><table class="table table-sm mb-0"><thead class="sticky-top bg-light"><tr><th>#</th><th>Section</th><th>Type</th><th>Details</th><th>By</th><th>At</th></tr></thead><tbody id="activityLogBody"></tbody></table></div></div></div>
    `;
}

(function initCallHistoryFloater() {
    const floater = document.getElementById('callHistoryFloater');
    const header = document.getElementById('callHistoryFloaterHeader');
    const openBtn = document.getElementById('openCallHistoryFloaterBtn');
    const closeBtn = document.getElementById('callHistoryFloaterClose');
    const minBtn = document.getElementById('callHistoryFloaterMinimise');
    if (!floater || !header) return;

    openBtn?.addEventListener('click', () => {
        floater.classList.add('show');
        floater.classList.remove('minimised');
    });
    closeBtn?.addEventListener('click', () => floater.classList.remove('show'));
    minBtn?.addEventListener('click', () => floater.classList.toggle('minimised'));

    let dragging = false, sx = 0, sy = 0, ox = 0, oy = 0;
    header.addEventListener('mousedown', (e) => {
        if (e.target.closest('button')) return;
        dragging = true;
        const rect = floater.getBoundingClientRect();
        sx = e.clientX; sy = e.clientY; ox = rect.left; oy = rect.top;
        e.preventDefault();
    });
    document.addEventListener('mousemove', (e) => {
        if (!dragging) return;
        floater.style.left = (ox + e.clientX - sx) + 'px';
        floater.style.top = Math.max(0, oy + e.clientY - sy) + 'px';
        floater.style.right = 'auto';
    });
    document.addEventListener('mouseup', () => { dragging = false; });
})();

function daysFromTodayLabel(dateStr) {
    if (!dateStr) return '';
    const text = String(dateStr).replace(' ', 'T');
    const d = new Date(text);
    if (Number.isNaN(d.getTime())) return '';
    const today = new Date();
    today.setHours(0,0,0,0);
    const target = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    const diffDays = Math.round((target - today) / (24 * 60 * 60 * 1000));
    if (diffDays === 0) return 'Today';
    if (diffDays > 0) return `in ${diffDays} day${diffDays === 1 ? '' : 's'}`;
    return `${Math.abs(diffDays)} day${Math.abs(diffDays) === 1 ? '' : 's'} ago`;
}

function dlLine(label, value, extraChip) {
    const v = value == null || String(value).trim() === '' ? '<span class="text-muted">N/A</span>' : escapeHtml(String(value));
    const c = extraChip ? `<span class="small-chip">${escapeHtml(extraChip)}</span>` : '';
    return `<div class="detail-line"><span class="dl-label">${escapeHtml(label)}</span><span class="dl-value">${v}${c}</span></div>`;
}

function dlLineRaw(label, htmlValue) {
    return `<div class="detail-line"><span class="dl-label">${escapeHtml(label)}</span><span class="dl-value">${htmlValue}</span></div>`;
}

function renderPanels(row) {
    const candidateMobile = row.Mobile_Number || row.Mobile_number || '';
    const employerSpocMobile = row.Employer_SPOC_Mobile || '';
    const aggregatorSpocMobile = row.Aggregator_SPOC_Mobile || row.Aggregator_Spoc_mobile || '';
    const telLink = (m) => m ? `<a href="tel:${escapeHtml(m)}" class="text-decoration-none">${escapeHtml(m)}</a>` : '<span class="text-muted">N/A</span>';
    const jobFairDateValue = row.Job_Fair_Date || '';
    const jobFairDaysLabel = daysFromTodayLabel(jobFairDateValue);

    candidateDetailStatuses.innerHTML = [
        statusChip('Job Fair Status', row.Selection_Status),
        statusChip('Final Status', row.Shortlist_Candidate_Status),
        statusChip('Joined Status', row.Candidate_Joined_Status)
    ].join('');

    const candidateGroup = `
        <div class="col-12 col-md-6 col-lg-3"><div class="detail-group">
            <div class="detail-group-title"><i class="bi bi-person-circle me-1"></i>Candidate</div>
            ${dlLine('Name', row.Candidate_Name)}
            ${dlLine('DWMS ID', row.DWMS_ID)}
            ${dlLineRaw('Mobile', telLink(candidateMobile))}
        </div></div>`;

    const jobFairGroup = `
        <div class="col-12 col-md-6 col-lg-3"><div class="detail-group">
            <div class="detail-group-title"><i class="bi bi-calendar-event me-1"></i>Job Fair</div>
            ${dlLine('Job Fair No', row.Job_Fair_No)}
            ${dlLine('Job Fair Date', jobFairDateValue, jobFairDaysLabel || null)}
            ${dlLine('CRM Member', row.CRM_Member)}
            ${dlLine('DSM Member 1', row.DSM_Member_1)}
            ${dlLine('DSM Member 2', row.DSM_Member_2)}
            ${dlLine('Category', row.Category)}
        </div></div>`;

    const employerGroup = `
        <div class="col-12 col-md-6 col-lg-3"><div class="detail-group">
            <div class="detail-group-title"><i class="bi bi-shop me-1"></i>Employer</div>
            ${dlLine('Employer', row.Employer_Name)}
            ${dlLine('Employer Code', row.Employer_ID)}
            ${dlLine('Job ID', row.Job_Id)}
            ${dlLine('Job Title', row.Job_Title_Name)}
            ${dlLine('SPOC Name', row.Employer_SPOC_Name)}
            ${dlLineRaw('SPOC Mobile', telLink(employerSpocMobile))}
        </div></div>`;

    const aggregatorGroup = `
        <div class="col-12 col-md-6 col-lg-3"><div class="detail-group">
            <div class="detail-group-title"><i class="bi bi-diagram-3 me-1"></i>Aggregator</div>
            ${dlLine('Aggregator', row.Aggregator)}
            ${dlLine('SPOC Name', row.Aggregator_SPOC_Name)}
            ${dlLineRaw('SPOC Mobile', telLink(aggregatorSpocMobile))}
        </div></div>`;

    detailPanel.innerHTML = candidateGroup + jobFairGroup + employerGroup + aggregatorGroup;

    // Render the Call History floating panel (outside the tab content so it
    // stays available while working on Selected / Shortlisted tabs).
    renderCallHistoryFloater(row);

    const panelNames = isDistrictCandidateDataMode
        ? ['Selected']
        : (row.Selection_Status === 'Selected' ? ['Selected'] : ['Shortlist/Onhold', 'Selected']);
    const tabs = panelNames.map((p, i) => `<li class="nav-item"><button class="nav-link ${i===0?'active':''}" data-bs-toggle="tab" data-bs-target="#panel-${p.replace(/[^a-zA-Z0-9]/g,'')}" type="button">${p}</button></li>`).join('');

    const tabBodies = panelNames.map((panel, i) => {
        const panelKey = panel.replace(/[^a-zA-Z0-9]/g,'');
        let panelFields = fieldConfig.filter((f) => f.panel_label === panel);
        if (isDistrictCandidateDataMode && panel === 'Selected') {
            const districtEditableFieldNames = new Set([
                'Confirm_Offer_Letter_Receipt_by_Candidate',
                'Confirm_letter_receipt_remarks',
                'confirmation_date',
                'Offer_Letter_Join_Date',
                'Willing_to_Join',
                'willing_to_join_remarks',
                'Challenge_Type',
                'Challenge_to_be_addressed',
                'Candidate_Joined_Status',
                'Candidate_Joined_Date',
                'Candidate_Joining_Future_Date',
                'Remarks_Candidate_Join',
                'Candidate_Join_Remarks_Type'
            ]);
            panelFields = panelFields.map((field) => {
                if (districtEditableFieldNames.has(field.field_name)) {
                    return field;
                }
                return { ...field, field_type: 'label' };
            });
        }
        const groups = [...new Set(panelFields.map((f) => f.group_label))];
        const groupHtml = groups.map((g) => {
            const fields = panelFields.filter((f) => f.group_label === g).sort((a,b) => (a.row_position-b.row_position) || (a.column_position-b.column_position));
            return `<div class="card mb-3"><div class="card-header">${escapeHtml(g)}</div><div class="card-body"><div class="row g-3">${fields.map((f) => `<div class="col-12 col-md-4">${renderFieldControl(f,row)}</div>`).join('')}</div></div></div>`;
        }).join('');
        const updateSection = panel === 'Shortlist/Onhold' ? 'shortlist_onhold' : 'selected';
        const shortlistRoundsHtml = panel === 'Shortlist/Onhold' ? '<div id="shortlistRoundsSection" data-editing-id=""></div>' : '';
        return `<div class="tab-pane fade ${i===0?'show active':''}" id="panel-${panelKey}"><div class="d-flex justify-content-end mb-3"><button type="submit" class="btn btn-primary btn-sm" name="update_section" value="${updateSection}" formnovalidate>Update ${panel} Details</button></div>${groupHtml}${shortlistRoundsHtml}</div>`;
    }).join('');

    dynamicPanels.innerHTML = `<ul class="nav nav-tabs mb-3">${tabs}</ul><div class="tab-content">${tabBodies}</div>`;
    loadHistory();
    loadShortlistRounds();

    dynamicPanels.querySelectorAll('.nav-link').forEach((btn) => {
        btn.addEventListener('shown.bs.tab', () => {
            const target = String(btn.dataset.bsTarget || '').replace('#panel-', '');
            activeTabInput.value = target;
        });
    });

    const requestedTabButton = requestedTab
        ? dynamicPanels.querySelector(`[data-bs-target="#panel-${requestedTab}"]`)
        : null;

    if (requestedTabButton && typeof bootstrap !== 'undefined' && bootstrap?.Tab) {
        bootstrap.Tab.getOrCreateInstance(requestedTabButton).show();
    } else {
        const firstTab = dynamicPanels.querySelector('.nav-link.active');
        if (firstTab) {
            activeTabInput.value = String(firstTab.dataset.bsTarget || '').replace('#panel-', '');
        }
    }
}

Promise.all([
    fetch(`/job_fair_results.php?manage_candidate_meta=1`).then((r) => r.json()),
    fetch(`/job_fair_results.php?candidate_row=${candidateId}`).then((r) => r.json())
]).then(([meta, row]) => {
    fieldConfig = Array.isArray(meta?.field_config) ? meta.field_config : [];
    callHistoryPurposeOptions = Array.isArray(meta?.call_purpose_options) ? meta.call_purpose_options : [];
    shortlistRoundOptions = meta?.shortlist_round_options || shortlistRoundOptions;
    currentRow = row || null;
    if (!currentRow) {
        window.location.href = <?= json_encode($returnUrl) ?>;
        return;
    }
    renderPanels(currentRow);
}).catch(() => {
    window.location.href = <?= json_encode($returnUrl) ?>;
});

document.getElementById('manageCandidateForm').addEventListener('submit', (event) => {
    const submitter = event.submitter;
    if (submitter?.value === 'call_history') {
        activeTabInput.value = 'CallHistory';
    }
    if (submitter?.value === 'shortlist_round_save') {
        activeTabInput.value = 'ShortlistOnhold';
    }
});
</script>

<?php render_footer(); ?>
