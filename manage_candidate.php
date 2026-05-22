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
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-1">Manage Candidate</h1>
        <div class="text-muted small"><?= esc($candidate['Candidate_Name'] ?: 'N/A') ?> (<?= esc($candidate['DWMS_ID'] ?: 'N/A') ?>)</div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= esc($returnUrl) ?>">← Back to Results</a>
</div>

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

<style>
.status-chip { display:inline-block; padding:0.2rem 0.55rem; border-radius:999px; font-size:0.75rem; font-weight:600; border:1px solid transparent; }
.status-selected { color:#146c43; background:#d1e7dd; border-color:#a3cfbb; }
.status-shortlisted { color:#7a3f00; background:#ffe5cc; border-color:#ffca99; }
.status-onhold { color:#084298; background:#cfe2ff; border-color:#9ec5fe; }
.status-rejected { color:#dc3545; background:#f8d7da; border-color:#f1aeb5; }
.offer-letter-link-icon { font-size:1rem; line-height:1; text-decoration:none; }
.offer-letter-link-icon.disabled { opacity:0.45; pointer-events:none; cursor:not-allowed; }
.focus-field-label { color:#800000; font-weight:600; }
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

function renderPanels(row) {
    const details = ['Job_Fair_No','Selection_Status','DWMS_ID','Employer_ID','Job_Id','Job_Title_Name','CRM_Member','DSM_Member_1','DSM_Member_2','Category','Job_Fair_Date'];
    const candidateMobile = row.Mobile_Number || row.Mobile_number || '';
    const candidateContact = candidateMobile ? `<a href="tel:${escapeHtml(candidateMobile)}" class="small text-primary text-decoration-none">${escapeHtml(candidateMobile)}</a>` : '<span class="small text-muted">N/A</span>';
    const employerContact = `<span class="small text-info">${escapeHtml(row.Employer_SPOC_Name || 'N/A')} • ${row.Employer_SPOC_Mobile ? `<a href="tel:${escapeHtml(row.Employer_SPOC_Mobile)}" class="small text-primary text-decoration-none">${escapeHtml(row.Employer_SPOC_Mobile)}</a>` : '<span class="small text-muted">N/A</span>'}</span>`;
    const aggregatorSpocMobile = row.Aggregator_SPOC_Mobile || row.Aggregator_Spoc_mobile || '';
    const aggregatorContact = `<span class="small text-success">${escapeHtml(row.Aggregator_SPOC_Name || 'N/A')} • ${aggregatorSpocMobile ? `<a href="tel:${escapeHtml(aggregatorSpocMobile)}" class="small text-primary text-decoration-none">${escapeHtml(aggregatorSpocMobile)}</a>` : '<span class="small text-muted">N/A</span>'}</span>`;

    candidateDetailStatuses.innerHTML = [
        statusChip('Job Fair Status', row.Selection_Status),
        statusChip('Final Status', row.Shortlist_Candidate_Status)
    ].join('');

    detailPanel.innerHTML = `
        <div class="col-12 col-md-4"><label class="form-label text-muted small">Candidate Name <span class="ms-1">${candidateContact}</span></label><div class="form-control bg-light">${escapeHtml(row.Candidate_Name || 'N/A')}</div></div>
        <div class="col-12 col-md-4"><label class="form-label text-muted small">Employer Name <span class="ms-1">${employerContact}</span></label><div class="form-control bg-light">${escapeHtml(row.Employer_Name || 'N/A')}</div></div>
        <div class="col-12 col-md-4"><label class="form-label text-muted small">Aggregator <span class="ms-1">${aggregatorContact}</span></label><div class="form-control bg-light">${escapeHtml(row.Aggregator || 'N/A')}</div></div>
        ${details.map((name) => `<div class="col-12 col-md-4"><label class="form-label text-muted small">${formatLabel(name)}</label><div class="form-control bg-light">${escapeHtml(row[name] || 'N/A')}</div></div>`).join('')}
    `;

    const panelNames = isDistrictCandidateDataMode
        ? ['Selected', 'Call History']
        : (row.Selection_Status === 'Selected' ? ['Selected', 'Call History'] : ['Shortlist/Onhold', 'Selected', 'Call History']);
    const tabs = panelNames.map((p, i) => `<li class="nav-item"><button class="nav-link ${i===0?'active':''}" data-bs-toggle="tab" data-bs-target="#panel-${p.replace(/[^a-zA-Z0-9]/g,'')}" type="button">${p}</button></li>`).join('');

    const tabBodies = panelNames.map((panel, i) => {
        const panelKey = panel.replace(/[^a-zA-Z0-9]/g,'');
        if (panel === 'Call History') {
            return `<div class="tab-pane fade ${i===0?'show active':''}" id="panel-${panelKey}">
                <div class="card mb-3"><div class="card-header">Add Call Detail</div><div class="card-body"><div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Stage</label><select class="form-select" name="call_history_stage"><option value="">Select</option><option>Employer Connect</option><option>Candidate Connect</option><option>Aggregator Contact</option></select></div>
                    <div class="col-md-4"><label class="form-label">Purpose</label><select class="form-select" name="call_history_purpose_id"><option value="">Select</option>${callHistoryPurposeOptions.map((o) => `<option value="${o.id}">${escapeHtml(o.purpose_name)}</option>`).join('')}</select></div>
                    <div class="col-md-4"><label class="form-label">Call Date time</label><input type="datetime-local" class="form-control" name="call_history_call_datetime" value="${nowIstInput()}" readonly></div>
                    <div class="col-md-4"><label class="form-label">Call Status</label><select class="form-select" name="call_history_call_status"><option value="">Select</option><option>Attended</option><option>Not attended</option><option>Invalid number</option></select></div>
                    <div class="col-12"><label class="form-label">Call Remarks</label><textarea class="form-control" name="call_history_call_remarks" rows="2"></textarea></div>
                </div></div></div>
                <div class="d-flex justify-content-end mb-3"><button type="submit" class="btn btn-primary btn-sm" name="update_section" value="call_history" formnovalidate>Add Call History</button></div>
                <div class="card mb-3"><div class="card-header">Call History</div><div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped mb-0"><thead><tr><th>Sl no</th><th>Stage</th><th>Purpose</th><th>Date time</th><th>Status</th><th>Remarks</th></tr></thead><tbody id="callHistoryBody"></tbody></table></div></div></div>
                <div class="card"><div class="card-header">Activity Log</div><div class="card-body"><div class="table-responsive"><table class="table table-bordered table-striped mb-0"><thead><tr><th>Sl no</th><th>Section</th><th>Type</th><th>Details</th><th>Updated By</th><th>Updated At</th></tr></thead><tbody id="activityLogBody"></tbody></table></div></div></div>
            </div>`;
        }

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
