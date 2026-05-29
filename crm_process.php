<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$currentUser = current_user();
if (is_district_user($currentUser)) {
    http_response_code(403);
    exit('Forbidden');
}

$jobFair = trim((string) ($_GET['job_fair'] ?? ''));
$selectionStatusFilter = trim((string) ($_GET['selection_status'] ?? ''));
$finalStatusFilter = trim((string) ($_GET['final_status'] ?? ''));

/* ------------------------------------------------------------------------- *
 * AJAX: list candidates for a (filter + Aggregator + Employer) combo.
 * ------------------------------------------------------------------------- */
if (($_GET['ajax'] ?? '') === 'candidates') {
    $aggregator = trim((string) ($_GET['aggregator'] ?? ''));
    $employerName = trim((string) ($_GET['employer_name'] ?? ''));
    $employerCode = trim((string) ($_GET['employer_code'] ?? ''));

    $conds = [];
    $bind = [];

    $matchCol = static function (string $col, string $val) use (&$conds, &$bind): void {
        if ($val === '' || $val === 'Unknown') {
            $conds[] = "($col IS NULL OR TRIM($col) = '')";
        } else {
            $conds[] = "TRIM(COALESCE($col, '')) = ?";
            $bind[] = $val;
        }
    };
    $matchCol('Aggregator', $aggregator);
    $matchCol('Employer_Name', $employerName);
    $matchCol('Employer_ID', $employerCode);
    if ($jobFair !== '') { $conds[] = "TRIM(COALESCE(Job_Fair_No, '')) = ?"; $bind[] = $jobFair; }
    if ($selectionStatusFilter !== '') { $conds[] = "TRIM(COALESCE(Selection_Status, '')) = ?"; $bind[] = $selectionStatusFilter; }
    if ($finalStatusFilter !== '') { $conds[] = "TRIM(COALESCE(Shortlist_Candidate_Status, '')) = ?"; $bind[] = $finalStatusFilter; }

    $where = 'WHERE ' . implode(' AND ', $conds);
    $sql = "SELECT id, DWMS_ID, Candidate_Name, Mobile_Number,
            Selection_Status, Shortlist_Candidate_Status,
            First_Call_Done, Offer_Letter_Generated, Link_to_Offer_letter,
            Link_to_Offer_letter_verified, Confirm_Offer_Letter_Receipt_by_Candidate,
            Candidate_Joined_Status
        FROM job_fair_result
        $where
        ORDER BY Candidate_Name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($bind);

    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll());
    exit;
}

/* ------------------------------------------------------------------------- *
 * POST: drag-drop status update on a single candidate.
 * Updates one allowed field and writes an audit row to
 * candidate_manage_activity_log so the change shows up in existing reports.
 * ------------------------------------------------------------------------- */
if (is_post() && ($_POST['action'] ?? '') === 'update_status') {
    $candidateId = (int) ($_POST['candidate_id'] ?? 0);
    $field = (string) ($_POST['field'] ?? '');
    $value = (string) ($_POST['value'] ?? '');

    $allowedFields = [
        'First_Call_Done',
        'Offer_Letter_Generated',
        'Link_to_Offer_letter_verified',
        'Confirm_Offer_Letter_Receipt_by_Candidate',
        'Candidate_Joined_Status',
    ];

    if (!in_array($field, $allowedFields, true) || $candidateId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid request']);
        exit;
    }

    $beforeStmt = db()->prepare("SELECT $field FROM job_fair_result WHERE id = ?");
    $beforeStmt->execute([$candidateId]);
    $oldValue = (string) ($beforeStmt->fetchColumn() ?: '');

    $newValue = $value === '' ? null : $value;
    $stmt = db()->prepare("UPDATE job_fair_result SET $field = ? WHERE id = ?");
    $stmt->execute([$newValue, $candidateId]);

    if ($oldValue !== (string) ($newValue ?? '')) {
        $logStmt = db()->prepare(
            'INSERT INTO candidate_manage_activity_log (candidate_id, activity_section, activity_type, activity_details, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $logStmt->execute([
            $candidateId,
            'crm_process_kanban',
            'update',
            str_replace('_', ' ', $field) . ': ' . ($oldValue === '' ? 'N/A' : $oldValue)
                . ' -> ' . ($newValue === null ? 'N/A' : $newValue),
            (int) ($currentUser['id'] ?? 0),
        ]);
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

/* ------------------------------------------------------------------------- *
 * First-load defaults: when the page is opened fresh (no filter params in
 * the URL), pre-select the latest Job Fair and Selection Status = Selected.
 * AJAX and POST flows above are unaffected; they only run when their own
 * params are present.
 * ------------------------------------------------------------------------- */
$noFilterParams = !array_key_exists('job_fair', $_GET)
    && !array_key_exists('selection_status', $_GET)
    && !array_key_exists('final_status', $_GET);
if ($noFilterParams) {
    $latestJobFair = (string) (db()->query(
        "SELECT Job_Fair_No FROM job_fair_result
         WHERE Job_Fair_No IS NOT NULL AND TRIM(Job_Fair_No) <> ''
         ORDER BY Job_Fair_No DESC LIMIT 1"
    )->fetchColumn() ?: '');
    if ($latestJobFair !== '') {
        $jobFair = $latestJobFair;
    }
    $selectionStatusFilter = 'Selected';
}

/* ------------------------------------------------------------------------- *
 * Page render: filter form, left tree (Aggregator -> Employer), right
 * kanban board area populated on demand by JS.
 * ------------------------------------------------------------------------- */
$jobFairOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT Job_Fair_No AS value FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND TRIM(Job_Fair_No) <> '' ORDER BY value DESC")->fetchAll()
);
$selectionStatusOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT Selection_Status AS value FROM job_fair_result WHERE Selection_Status IS NOT NULL AND TRIM(Selection_Status) <> '' ORDER BY value")->fetchAll()
);
$finalStatusOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT Shortlist_Candidate_Status AS value FROM job_fair_result WHERE Shortlist_Candidate_Status IS NOT NULL AND TRIM(Shortlist_Candidate_Status) <> '' ORDER BY value")->fetchAll()
);

$treeConds = [];
$treeBind = [];
if ($jobFair !== '') { $treeConds[] = "TRIM(COALESCE(Job_Fair_No, '')) = ?"; $treeBind[] = $jobFair; }
if ($selectionStatusFilter !== '') { $treeConds[] = "TRIM(COALESCE(Selection_Status, '')) = ?"; $treeBind[] = $selectionStatusFilter; }
if ($finalStatusFilter !== '') { $treeConds[] = "TRIM(COALESCE(Shortlist_Candidate_Status, '')) = ?"; $treeBind[] = $finalStatusFilter; }
$treeWhere = $treeConds === [] ? '' : 'WHERE ' . implode(' AND ', $treeConds);

$treeStmt = db()->prepare(
    "SELECT
        COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') AS aggregator,
        COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown') AS employer_name,
        COALESCE(NULLIF(TRIM(Employer_ID), ''), 'Unknown') AS employer_code,
        COUNT(*) AS candidate_count
    FROM job_fair_result
    $treeWhere
    GROUP BY aggregator, employer_name, employer_code
    ORDER BY aggregator ASC, employer_name ASC"
);
$treeStmt->execute($treeBind);
$treeRows = $treeStmt->fetchAll();

$tree = [];
foreach ($treeRows as $r) {
    $a = (string) $r['aggregator'];
    if (!isset($tree[$a])) {
        $tree[$a] = ['count' => 0, 'employers' => []];
    }
    $tree[$a]['count'] += (int) $r['candidate_count'];
    $tree[$a]['employers'][] = [
        'name' => (string) $r['employer_name'],
        'code' => (string) $r['employer_code'],
        'count' => (int) $r['candidate_count'],
    ];
}

render_header('CRM Process', ['main_container_class' => 'container-fluid']);
render_page_header('CRM Process', [
    'icon' => 'bi-kanban',
    'subtitle' => 'Drag-and-drop kanban for managing candidate status across the post job fair pipeline.',
]);
?>

<form class="filter-bar">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label for="job_fair" class="form-label">Job Fair</label>
            <select class="form-select" id="job_fair" name="job_fair">
                <option value="">All Job Fairs</option>
                <?php foreach ($jobFairOptions as $option): ?>
                    <option value="<?= esc($option) ?>" <?= $jobFair === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="selection_status" class="form-label">Selection Status</label>
            <select class="form-select" id="selection_status" name="selection_status">
                <option value="">All</option>
                <?php foreach ($selectionStatusOptions as $option): ?>
                    <option value="<?= esc($option) ?>" <?= $selectionStatusFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label for="final_status" class="form-label">Final Status</label>
            <select class="form-select" id="final_status" name="final_status">
                <option value="">All</option>
                <?php foreach ($finalStatusOptions as $option): ?>
                    <option value="<?= esc($option) ?>" <?= $finalStatusFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
            <a class="btn btn-light" href="/crm_process.php">Reset</a>
        </div>
    </div>
</form>

<div class="row g-3 mt-1">
    <div class="col-12 col-lg-3">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-diagram-3 text-primary me-1"></i>Aggregator / Employer</div>
            <div class="card-body p-2" style="max-height: 75vh; overflow-y: auto;">
                <?php if ($tree === []): ?>
                    <div class="empty-state"><i class="bi bi-inbox"></i>No data matches the selected filters.</div>
                <?php else: ?>
                    <?php foreach ($tree as $aggName => $aggData): ?>
                        <details open class="crm-tree-agg mb-2">
                            <summary class="fw-semibold py-1">
                                <i class="bi bi-building me-1 text-muted"></i><?= esc($aggName) ?>
                                <span class="status-chip status-neutral ms-1"><?= number_format($aggData['count']) ?></span>
                            </summary>
                            <ul class="list-unstyled ms-3 mb-0">
                                <?php foreach ($aggData['employers'] as $emp): ?>
                                    <li>
                                        <a href="#" class="crm-emp-link d-flex justify-content-between align-items-center text-decoration-none py-1 px-2 rounded"
                                           data-aggregator="<?= esc($aggName) ?>"
                                           data-employer-name="<?= esc($emp['name']) ?>"
                                           data-employer-code="<?= esc($emp['code']) ?>">
                                            <span class="text-truncate" title="<?= esc($emp['name']) ?>"><i class="bi bi-shop me-1 text-muted"></i><?= esc($emp['name']) ?></span>
                                            <span class="small text-muted ms-1"><?= number_format($emp['count']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-9">
        <div class="card h-100">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span id="kanbanHeader"><i class="bi bi-kanban text-primary me-1"></i>Select an employer on the left to load the kanban.</span>
                <span id="kanbanStatus" class="data-meta"></span>
            </div>
            <div class="card-body p-2">
                <ul class="nav nav-tabs mb-2" role="tablist">
                    <li class="nav-item"><button class="nav-link active" type="button" data-bs-toggle="tab" data-bs-target="#tab-selected" role="tab"><i class="bi bi-check-circle me-1"></i>Selected</button></li>
                    <li class="nav-item"><button class="nav-link" type="button" data-bs-toggle="tab" data-bs-target="#tab-shortlisted" role="tab"><i class="bi bi-list-check me-1"></i>Shortlisted / On hold</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-selected" role="tabpanel">
                        <div class="kanban-board" id="selectedKanban"></div>
                    </div>
                    <div class="tab-pane fade" id="tab-shortlisted" role="tabpanel">
                        <div class="p-4 text-center text-muted">
                            <i class="bi bi-hourglass-split fs-3 d-block mb-2"></i>
                            Shortlisted / On hold workflow rules are pending. The board will be wired up once the process is defined.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.kanban-board {
    display: flex;
    gap: 0.5rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
    min-height: 65vh;
}
.kanban-col {
    flex: 0 0 220px;
    background: var(--pjf-surface-alt, #f8fafc);
    border: 1px solid var(--pjf-border, #e2e8f0);
    border-radius: 10px;
    padding: 0.5rem;
    display: flex;
    flex-direction: column;
    max-height: 70vh;
}
.kanban-col-header {
    font-size: .75rem;
    font-weight: 600;
    color: var(--pjf-text, #1e293b);
    text-transform: uppercase;
    letter-spacing: .02em;
    padding: .25rem .25rem .5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.kanban-col-header .col-count {
    background: var(--pjf-surface, #fff);
    border: 1px solid var(--pjf-border, #e2e8f0);
    border-radius: 999px;
    padding: 0 .45rem;
    font-size: .7rem;
    color: var(--pjf-muted, #64748b);
}
.kanban-col-body {
    flex: 1 1 auto;
    overflow-y: auto;
    min-height: 60px;
    border-radius: 6px;
    padding: 2px;
}
.kanban-col.drop-disabled .kanban-col-body { background: repeating-linear-gradient(45deg, transparent, transparent 6px, rgba(0,0,0,0.02) 6px, rgba(0,0,0,0.02) 12px); }
.kanban-col.drag-over .kanban-col-body { background: #eff4ff; outline: 2px dashed var(--pjf-primary, #2563eb); outline-offset: -2px; }
.kanban-card {
    background: #fff;
    border: 1px solid var(--pjf-border, #e2e8f0);
    border-radius: 8px;
    padding: .55rem .65rem;
    margin-bottom: .4rem;
    cursor: grab;
    box-shadow: 0 1px 1px rgba(15,23,42,.04);
}
.kanban-card:hover { box-shadow: 0 2px 4px rgba(15,23,42,.08); }
.kanban-card.dragging { opacity: .45; }
.kanban-card .name { font-weight: 600; font-size: .85rem; }
.kanban-card .dwms { font-size: .72rem; color: var(--pjf-muted, #64748b); }
.kanban-card .meta { font-size: .7rem; color: var(--pjf-muted, #64748b); margin-top: .15rem; }
.kanban-card .toast-error { color: var(--pjf-danger, #dc2626); font-size: .7rem; }
.crm-emp-link:hover { background: var(--pjf-primary-soft, #eff4ff); color: var(--pjf-primary-dark, #1d4ed8); }
.crm-emp-link.active { background: var(--pjf-primary, #2563eb); color: #fff; }
.crm-emp-link.active .text-muted, .crm-emp-link.active .small { color: #cbd5e1 !important; }
</style>

<script>
(function () {
    const SELECTED_COLUMNS = [
        { id: 'pending',         label: 'Pending',                droppable: false },
        { id: 'first_call',      label: 'First Call',             droppable: true,  field: 'First_Call_Done',                          value: 'Yes' },
        { id: 'ol_gen_pending',  label: 'OL Generated: Pending',  droppable: true,  field: 'Offer_Letter_Generated',                   value: 'Pending' },
        { id: 'ol_gen_yes',      label: 'OL Generated: Yes',      droppable: true,  field: 'Offer_Letter_Generated',                   value: 'Yes' },
        { id: 'ol_gen_no',       label: 'OL Generated: No',       droppable: true,  field: 'Offer_Letter_Generated',                   value: 'No' },
        { id: 'ol_copy_yes',     label: 'OL Copy: Yes',           droppable: false },
        { id: 'ol_copy_no',      label: 'OL Copy: No',            droppable: false },
        { id: 'ol_ver_yes',      label: 'OL Verified: Yes',       droppable: true,  field: 'Link_to_Offer_letter_verified',            value: 'Yes' },
        { id: 'ol_ver_no',       label: 'OL Verified: No',        droppable: true,  field: 'Link_to_Offer_letter_verified',            value: 'No' },
        { id: 'rcv_pending',     label: 'Receipt: Pending',       droppable: true,  field: 'Confirm_Offer_Letter_Receipt_by_Candidate', value: 'Pending' },
        { id: 'rcv_yes',         label: 'Receipt: Yes',           droppable: true,  field: 'Confirm_Offer_Letter_Receipt_by_Candidate', value: 'Yes' },
        { id: 'rcv_no',          label: 'Receipt: No',            droppable: true,  field: 'Confirm_Offer_Letter_Receipt_by_Candidate', value: 'No' },
        { id: 'join_yes',        label: 'Joined: Yes',            droppable: true,  field: 'Candidate_Joined_Status',                  value: 'Yes' },
        { id: 'join_no',         label: 'Joined: No',             droppable: true,  field: 'Candidate_Joined_Status',                  value: 'No' },
        { id: 'join_future',     label: 'Joined: Future Date',    droppable: true,  field: 'Candidate_Joined_Status',                  value: 'Future Date' },
        { id: 'join_pending',    label: 'Joined: Pending',        droppable: true,  field: 'Candidate_Joined_Status',                  value: 'Pending' },
    ];

    const norm = (v) => (v == null ? '' : String(v).toLowerCase().trim());

    function placeCandidate(c) {
        const j = norm(c.Candidate_Joined_Status);
        if (j === 'yes') return 'join_yes';
        if (j === 'no') return 'join_no';
        if (j === 'future date') return 'join_future';
        if (j === 'pending') return 'join_pending';

        const r = norm(c.Confirm_Offer_Letter_Receipt_by_Candidate);
        if (r === 'yes') return 'rcv_yes';
        if (r === 'no') return 'rcv_no';
        if (r === 'pending') return 'rcv_pending';

        const v = norm(c.Link_to_Offer_letter_verified);
        if (v === 'yes') return 'ol_ver_yes';
        if (v === 'no') return 'ol_ver_no';

        const link = (c.Link_to_Offer_letter || '').trim();
        const og = norm(c.Offer_Letter_Generated);
        if (link !== '') return 'ol_copy_yes';
        if (og === 'yes') return 'ol_copy_no';     // generated but no softcopy link yet
        if (og === 'no') return 'ol_gen_no';
        if (og === 'pending') return 'ol_gen_pending';

        const fc = norm(c.First_Call_Done);
        if (fc === 'yes') return 'first_call';

        return 'pending';
    }

    function buildCard(c) {
        const card = document.createElement('div');
        card.className = 'kanban-card';
        card.draggable = true;
        card.dataset.candidateId = c.id;
        card.innerHTML = `
            <div class="name">${escapeHtml(c.Candidate_Name || '(unnamed)')}</div>
            <div class="dwms">DWMS: ${escapeHtml(c.DWMS_ID || '')}</div>
            <div class="meta">${escapeHtml(c.Mobile_Number || '')}</div>
            <div class="d-flex gap-1 mt-1">
                <a class="btn btn-sm btn-outline-primary py-0 px-1" style="font-size: .65rem;" href="/manage_candidate.php?candidate_id=${encodeURIComponent(c.id)}"><i class="bi bi-pencil-square"></i> Manage</a>
            </div>
            <div class="toast-error d-none mt-1"></div>
        `;
        return card;
    }

    function escapeHtml(s) {
        return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
    }

    function buildBoard() {
        const board = document.getElementById('selectedKanban');
        board.innerHTML = '';
        SELECTED_COLUMNS.forEach((col) => {
            const div = document.createElement('div');
            div.className = 'kanban-col' + (col.droppable ? '' : ' drop-disabled');
            div.dataset.colId = col.id;
            if (col.droppable) {
                div.dataset.field = col.field;
                div.dataset.value = col.value;
            }
            div.innerHTML = `
                <div class="kanban-col-header">
                    <span>${escapeHtml(col.label)}</span>
                    <span class="col-count" data-count>0</span>
                </div>
                <div class="kanban-col-body"></div>
            `;
            board.appendChild(div);
        });
    }

    function updateCounts() {
        document.querySelectorAll('#selectedKanban .kanban-col').forEach((col) => {
            const count = col.querySelectorAll('.kanban-card').length;
            const tgt = col.querySelector('[data-count]');
            if (tgt) tgt.textContent = count;
        });
    }

    function distributeCards(candidates) {
        const byCol = {};
        SELECTED_COLUMNS.forEach((c) => { byCol[c.id] = []; });
        candidates.forEach((c) => {
            const id = placeCandidate(c);
            (byCol[id] || (byCol['pending'])).push(c);
        });
        SELECTED_COLUMNS.forEach((col) => {
            const body = document.querySelector(`#selectedKanban .kanban-col[data-col-id="${col.id}"] .kanban-col-body`);
            body.innerHTML = '';
            byCol[col.id].forEach((c) => body.appendChild(buildCard(c)));
        });
        updateCounts();
    }

    let activeEmployerLink = null;
    let dragSourceCol = null;
    let draggedCard = null;

    function attachDragHandlers() {
        const board = document.getElementById('selectedKanban');

        board.addEventListener('dragstart', (event) => {
            const card = event.target.closest('.kanban-card');
            if (!card) return;
            draggedCard = card;
            dragSourceCol = card.closest('.kanban-col');
            card.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.candidateId);
        });
        board.addEventListener('dragend', (event) => {
            const card = event.target.closest('.kanban-card');
            if (card) card.classList.remove('dragging');
            board.querySelectorAll('.kanban-col.drag-over').forEach((c) => c.classList.remove('drag-over'));
            draggedCard = null;
            dragSourceCol = null;
        });
        board.addEventListener('dragover', (event) => {
            const col = event.target.closest('.kanban-col');
            if (!col || col.classList.contains('drop-disabled')) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
            col.classList.add('drag-over');
        });
        board.addEventListener('dragleave', (event) => {
            const col = event.target.closest('.kanban-col');
            if (col) col.classList.remove('drag-over');
        });
        board.addEventListener('drop', (event) => {
            event.preventDefault();
            const col = event.target.closest('.kanban-col');
            if (!col || col.classList.contains('drop-disabled') || !draggedCard) return;
            col.classList.remove('drag-over');
            if (col === dragSourceCol) return;

            const field = col.dataset.field;
            const value = col.dataset.value;
            const candidateId = draggedCard.dataset.candidateId;
            const errSlot = draggedCard.querySelector('.toast-error');
            errSlot.classList.add('d-none');

            // Optimistic move
            col.querySelector('.kanban-col-body').appendChild(draggedCard);
            updateCounts();

            const form = new FormData();
            form.append('action', 'update_status');
            form.append('candidate_id', candidateId);
            form.append('field', field);
            form.append('value', value);

            fetch('/crm_process.php', { method: 'POST', body: form })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.ok) throw new Error(data.error || 'Update failed');
                })
                .catch((err) => {
                    // Roll back
                    if (dragSourceCol) {
                        dragSourceCol.querySelector('.kanban-col-body').appendChild(draggedCard);
                    }
                    updateCounts();
                    errSlot.textContent = '⚠ ' + err.message;
                    errSlot.classList.remove('d-none');
                });
        });
    }

    function loadEmployer(link) {
        if (activeEmployerLink) activeEmployerLink.classList.remove('active');
        activeEmployerLink = link;
        link.classList.add('active');

        const aggregator = link.dataset.aggregator;
        const employerName = link.dataset.employerName;
        const employerCode = link.dataset.employerCode;
        const params = new URLSearchParams({
            ajax: 'candidates',
            aggregator: aggregator,
            employer_name: employerName,
            employer_code: employerCode,
            job_fair: <?= json_encode($jobFair) ?>,
            selection_status: <?= json_encode($selectionStatusFilter) ?>,
            final_status: <?= json_encode($finalStatusFilter) ?>,
        });

        document.getElementById('kanbanHeader').innerHTML = '<i class="bi bi-kanban text-primary me-1"></i>Loading <strong>' + escapeHtml(employerName) + '</strong>...';
        document.getElementById('kanbanStatus').textContent = '';

        fetch('/crm_process.php?' + params.toString())
            .then((r) => r.json())
            .then((rows) => {
                buildBoard();
                attachDragHandlers();
                distributeCards(rows);
                document.getElementById('kanbanHeader').innerHTML =
                    '<i class="bi bi-kanban text-primary me-1"></i><strong>' + escapeHtml(employerName) + '</strong>' +
                    (employerCode && employerCode !== 'Unknown' ? ' &middot; <span class="text-muted">Code: ' + escapeHtml(employerCode) + '</span>' : '');
                document.getElementById('kanbanStatus').textContent = rows.length + ' candidate(s)';
            })
            .catch((err) => {
                document.getElementById('kanbanHeader').textContent = 'Failed to load: ' + err.message;
            });
    }

    document.querySelectorAll('.crm-emp-link').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            loadEmployer(link);
        });
    });
})();
</script>

<?php render_footer(); ?>
