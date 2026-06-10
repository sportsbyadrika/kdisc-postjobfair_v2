<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/consolidated_report_helpers.php';
require_auth();

$currentUser = current_user();
$userRole = (string) ($currentUser['role'] ?? '');
$canEdit = in_array($userRole, ['administrator', 'state_dsm', 'crm_member'], true);

const CANDIDATE_JOIN_REMARKS_TYPES = [
    'Not Joined - No Reason Specified',
    'Not Interested - General',
    'Not Interested - Location / Relocation',
    'Not Interested - Salary',
    'Not Interested - Accommodation / Food',
    'Not Interested - Got Another Job',
    'Not Interested - Job Mismatch / Field',
    'Exam / Study Related',
    'Personal Reasons',
    'Offer Letter Issues',
    'Not Responding',
    'Interview / Selection Process',
    'Rejected',
    'NAPS / Apprenticeship Related',
    'Medical / Visa Proceedings',
    'Joined',
    'Joining - Confirmed / Upcoming',
];

/* AJAX endpoint: update Candidate_Join_Remarks_Type for one candidate. */
if (is_post() && ($_POST['action'] ?? '') === 'update_remark_type') {
    header('Content-Type: application/json');
    if (!$canEdit) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed']);
        exit;
    }
    $candidateId = (int) ($_POST['candidate_id'] ?? 0);
    $value = (string) ($_POST['value'] ?? '');
    if ($candidateId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid candidate']);
        exit;
    }
    if ($value !== '' && !in_array($value, CANDIDATE_JOIN_REMARKS_TYPES, true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid remark type']);
        exit;
    }

    $beforeStmt = db()->prepare('SELECT Candidate_Join_Remarks_Type FROM job_fair_result WHERE id = ?');
    $beforeStmt->execute([$candidateId]);
    $oldValue = (string) ($beforeStmt->fetchColumn() ?: '');

    $newValue = $value === '' ? null : $value;
    $stmt = db()->prepare('UPDATE job_fair_result SET Candidate_Join_Remarks_Type = ? WHERE id = ?');
    $stmt->execute([$newValue, $candidateId]);

    if ($oldValue !== (string) ($newValue ?? '')) {
        $logStmt = db()->prepare(
            'INSERT INTO candidate_manage_activity_log (candidate_id, activity_section, activity_type, activity_details, created_by) VALUES (?, ?, ?, ?, ?)'
        );
        $logStmt->execute([
            $candidateId,
            'candidate_join_remarks_bulk_update',
            'update',
            'Candidate Join Remarks Type: ' . ($oldValue === '' ? 'N/A' : $oldValue)
                . ' -> ' . ($newValue === null ? 'N/A' : $newValue),
            (int) ($currentUser['id'] ?? 0),
        ]);
    }

    echo json_encode(['ok' => true, 'new_value' => (string) ($newValue ?? '')]);
    exit;
}

/* Filter handling — mirror the drill page so the link from the pivot works. */
$filters = build_consolidated_filters();
$joined = trim((string) ($_GET['joined'] ?? ''));
$allowedJoined = ['yes', 'no', 'pending', 'future_date', ''];
if (!in_array($joined, $allowedJoined, true)) {
    $joined = '';
}
$hasRemarkTypeParam = array_key_exists('remark_type', $_GET);
$remarkType = trim((string) ($_GET['remark_type'] ?? ''));

$conditions = [];
$params = [];

// Universe: genuinely selected (matches the pivot's WHERE clause).
$conditions[] = "(
    LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
    OR (
        LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
        AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
    )
)";

if ($joined === 'yes') {
    $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes'";
} elseif ($joined === 'no') {
    $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no'";
} elseif ($joined === 'pending') {
    $conditions[] = "(LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '')";
} elseif ($joined === 'future_date') {
    $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date'";
}

if ($hasRemarkTypeParam) {
    if ($remarkType === '' || $remarkType === '(No Remark Type)') {
        $conditions[] = "(Candidate_Join_Remarks_Type IS NULL OR TRIM(Candidate_Join_Remarks_Type) = '')";
    } else {
        $conditions[] = "TRIM(COALESCE(Candidate_Join_Remarks_Type, '')) = ?";
        $params[] = $remarkType;
    }
}

$commonConditions = build_common_conditions($filters, $params);
foreach ($commonConditions as $c) {
    $conditions[] = $c;
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT id, Job_Fair_No, DWMS_ID, Candidate_Name, Mobile_Number,
        Employer_ID, Employer_Name, Job_Id, Job_Title_Name,
        Candidate_Joined_Status, Remarks_Candidate_Join, Candidate_Join_Remarks_Type
    FROM job_fair_result
    $whereClause
    ORDER BY Job_Fair_No DESC, Employer_Name ASC, Candidate_Name ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$joinedLabels = [
    'yes' => 'Yes', 'no' => 'No', 'pending' => 'Pending (incl. empty)',
    'future_date' => 'Future Date', '' => 'Any',
];

$backUrl = '/consolidated_report.php?' . http_build_query(array_filter([
    'aggregator' => $filters['aggregator'] ?? '',
    'job_fair' => $filters['job_fair'] ?? '',
    'category' => $filters['category'] ?? '',
    'selection_status' => $filters['selection_status'] ?? '',
], static fn($v): bool => $v !== '')) . '#tab-shortlisted';

render_header('Bulk update Candidate Join Remarks Type', ['main_container_class' => 'container-fluid']);
render_page_header('Bulk Update — Candidate Join Remarks Type', [
    'icon' => 'bi-pencil-square',
    'subtitle' => 'Inline-edit the Candidate Join Remarks Type for candidates matching the selected Join Remarks Type × Joined Status pivot cell.',
    'actions' => '<a class="btn btn-light ms-1" href="' . esc($backUrl) . '"><i class="bi bi-arrow-left me-1"></i>Back to Consolidated Report</a>',
]);
?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="form-label mb-0 me-1">Scope:</span>
        <span class="status-chip status-neutral"><strong>Cohort:</strong>&nbsp;Selected OR Shortlist Final Selected</span>
        <span class="status-chip status-neutral"><strong>Joined Status:</strong>&nbsp;<?= esc($joinedLabels[$joined]) ?></span>
        <?php if ($hasRemarkTypeParam): ?>
            <span class="status-chip status-neutral"><strong>Current Remarks Type:</strong>&nbsp;<?= esc($remarkType !== '' ? $remarkType : '(No Remark Type)') ?></span>
        <?php endif; ?>
        <?php foreach (['aggregator' => 'Aggregator', 'job_fair' => 'Job Fair', 'category' => 'Category'] as $k => $label): ?>
            <?php if (($filters[$k] ?? '') !== ''): ?>
                <span class="status-chip status-neutral"><strong><?= esc($label) ?>:</strong>&nbsp;<?= esc($filters[$k]) ?></span>
            <?php endif; ?>
        <?php endforeach; ?>
        <span class="status-chip status-info ms-auto"><?= number_format(count($rows)) ?> candidates</span>
    </div>
</div>

<?php if (!$canEdit): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-2"></i>Your role can browse this list but cannot save changes.</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>DWMS ID</th>
                        <th>Candidate Name &amp; Mobile</th>
                        <th>Employer / Job</th>
                        <th>Job Fair</th>
                        <th>Candidate Joined Status</th>
                        <th>Remarks Candidate Join</th>
                        <th>Candidate Join Remarks Type</th>
                        <th class="text-end">Manage</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No candidates match the selected pivot cell.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $row): ?>
                    <?php
                    $cid = (int) $row['id'];
                    $current = (string) ($row['Candidate_Join_Remarks_Type'] ?? '');
                    ?>
                    <tr data-candidate-id="<?= $cid ?>">
                        <td><?= $i++ ?></td>
                        <td><?= esc((string) ($row['DWMS_ID'] ?? '')) ?></td>
                        <td>
                            <div class="fw-semibold"><?= esc((string) ($row['Candidate_Name'] ?? '')) ?></div>
                            <div class="text-muted small"><?= esc((string) ($row['Mobile_Number'] ?? '')) ?></div>
                        </td>
                        <td>
                            <div><?= esc((string) ($row['Employer_Name'] ?? '')) ?><?php if (!empty($row['Employer_ID'])): ?> <span class="text-muted small">(<?= esc((string) $row['Employer_ID']) ?>)</span><?php endif; ?></div>
                            <div class="text-muted small"><?= esc((string) ($row['Job_Title_Name'] ?? '')) ?><?php if (!empty($row['Job_Id'])): ?> <span>· <?= esc((string) $row['Job_Id']) ?></span><?php endif; ?></div>
                        </td>
                        <td><?= esc((string) ($row['Job_Fair_No'] ?? '')) ?></td>
                        <td><?= render_status_chip((string) ($row['Candidate_Joined_Status'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Remarks_Candidate_Join'] ?? '')) ?></td>
                        <td style="min-width:240px;">
                            <select class="form-select form-select-sm remark-type-select" data-original="<?= esc($current) ?>" <?= $canEdit ? '' : 'disabled' ?>>
                                <option value="">Select</option>
                                <?php foreach (CANDIDATE_JOIN_REMARKS_TYPES as $opt): ?>
                                    <option value="<?= esc($opt) ?>" <?= $opt === $current ? 'selected' : '' ?>><?= esc($opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="small mt-1 remark-type-status text-muted" style="min-height:1em;"></div>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/manage_candidate.php?candidate_id=<?= $cid ?>"><i class="bi bi-pencil-square me-1"></i>Manage</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    document.querySelectorAll('.remark-type-select').forEach((select) => {
        select.addEventListener('change', function () {
            const row = this.closest('tr');
            const cid = row?.getAttribute('data-candidate-id');
            const status = row?.querySelector('.remark-type-status');
            if (!cid) return;
            const newValue = this.value;
            const original = this.getAttribute('data-original') || '';
            if (status) {
                status.textContent = 'Saving…';
                status.className = 'small mt-1 remark-type-status text-muted';
            }
            this.disabled = true;
            const body = new URLSearchParams();
            body.append('action', 'update_remark_type');
            body.append('candidate_id', cid);
            body.append('value', newValue);
            fetch(window.location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
            })
            .then((r) => r.json().catch(() => ({ ok: false, error: 'Bad response' })))
            .then((data) => {
                this.disabled = false;
                if (data && data.ok) {
                    this.setAttribute('data-original', newValue);
                    if (status) {
                        status.textContent = 'Saved';
                        status.className = 'small mt-1 remark-type-status text-success';
                        setTimeout(() => { status.textContent = ''; }, 2000);
                    }
                } else {
                    this.value = original;
                    if (status) {
                        status.textContent = (data && data.error) ? data.error : 'Save failed';
                        status.className = 'small mt-1 remark-type-status text-danger';
                    }
                }
            })
            .catch(() => {
                this.disabled = false;
                this.value = original;
                if (status) {
                    status.textContent = 'Network error';
                    status.className = 'small mt-1 remark-type-status text-danger';
                }
            });
        });
    });
})();
</script>

<?php render_footer(); ?>
