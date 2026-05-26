<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$currentUser = current_user();
$canEdit = is_admin($currentUser);

/**
 * The eight columns that together identify a single row in the Job Fair
 * Masters table. Displayed value "Unknown" maps back to NULL/empty in the
 * underlying job_fair_result data.
 */
const MASTERS_SIGNATURE_COLUMNS = [
    'aggregator' => 'Aggregator',
    'employer_code' => 'Employer_ID',
    'employer_name' => 'Employer_Name',
    'employer_spoc_name' => 'Employer_SPOC_Name',
    'employer_spoc_mobile' => 'Employer_SPOC_Mobile',
    'aggregator_spoc_name' => 'Aggregator_SPOC_Name',
    'aggregator_spoc_mobile' => 'Aggregator_SPOC_Mobile',
    'crm_member' => 'CRM_Member',
];

function masters_signature_from_request(array $source): array
{
    $signature = [];
    foreach (array_keys(MASTERS_SIGNATURE_COLUMNS) as $key) {
        $signature[$key] = trim((string) ($source[$key] ?? ''));
    }
    return $signature;
}

function masters_row_conditions(array $signature, array &$params): array
{
    $conditions = [];
    foreach (MASTERS_SIGNATURE_COLUMNS as $key => $column) {
        $value = (string) ($signature[$key] ?? '');
        if ($value === '' || $value === 'Unknown') {
            $conditions[] = "($column IS NULL OR TRIM($column) = '')";
        } else {
            $conditions[] = "TRIM(COALESCE($column, '')) = ?";
            $params[] = $value;
        }
    }
    return $conditions;
}

function fetch_master_distinct_values(string $column): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM($column), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";

    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function build_master_filters(): array
{
    return [
        'aggregator' => trim((string) ($_GET['aggregator'] ?? '')),
        'employer' => trim((string) ($_GET['employer'] ?? '')),
        'crm_member' => trim((string) ($_GET['crm_member'] ?? '')),
    ];
}

function fetch_master_rows(array $filters): array
{
    $conditions = [];
    $params = [];

    if ($filters['aggregator'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') = ?";
        $params[] = $filters['aggregator'];
    }

    if ($filters['employer'] !== '') {
        $conditions[] = "Employer_Name LIKE ?";
        $params[] = '%' . $filters['employer'] . '%';
    }

    if ($filters['crm_member'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(CRM_Member), ''), 'Unknown') = ?";
        $params[] = $filters['crm_member'];
    }

    $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

    // Raise the GROUP_CONCAT limit so very large master rows (thousands of
    // candidates) still get a complete ID snapshot bundled with the row.
    db()->query('SET SESSION group_concat_max_len = 4194304');

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') AS aggregator,
            COALESCE(NULLIF(TRIM(Employer_ID), ''), 'Unknown') AS employer_code,
            COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown') AS employer_name,
            COALESCE(NULLIF(TRIM(Employer_SPOC_Name), ''), 'Unknown') AS employer_spoc_name,
            COALESCE(NULLIF(TRIM(Employer_SPOC_Mobile), ''), 'Unknown') AS employer_spoc_mobile,
            COALESCE(NULLIF(TRIM(Aggregator_SPOC_Name), ''), 'Unknown') AS aggregator_spoc_name,
            COALESCE(NULLIF(TRIM(Aggregator_SPOC_Mobile), ''), 'Unknown') AS aggregator_spoc_mobile,
            COALESCE(NULLIF(TRIM(CRM_Member), ''), 'Unknown') AS crm_member,
            COUNT(*) AS candidate_count,
            COUNT(DISTINCT NULLIF(TRIM(Job_Title_Name), '')) AS job_title_count,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS selected_count,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold') THEN 1 ELSE 0 END) AS shortlisted_onhold_count,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
                     AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
                THEN 1 ELSE 0 END) AS shortlist_final_selected_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_count,
            GROUP_CONCAT(id ORDER BY id) AS record_ids
        FROM job_fair_result
        $whereClause
        GROUP BY
            aggregator,
            employer_code,
            employer_name,
            employer_spoc_name,
            employer_spoc_mobile,
            aggregator_spoc_name,
            aggregator_spoc_mobile,
            crm_member
        ORDER BY aggregator ASC, employer_name ASC, employer_spoc_name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

$filters = build_master_filters();
$flash = null;
$flashType = 'success';

if (is_post() && ($_POST['action'] ?? '') === 'update_row') {
    if (!$canEdit) {
        http_response_code(403);
        exit('Forbidden.');
    }

    // ID snapshot captured when the modal opened. The update is scoped strictly to
    // these record IDs, so any change to identity fields (Aggregator / Employer
    // Code / Employer Name) only affects the records the admin actually saw.
    $rawIds = (string) ($_POST['target_ids'] ?? '');
    $targetIds = array_values(array_filter(array_map(
        static fn ($v) => (int) $v,
        explode(',', $rawIds)
    ), static fn (int $v): bool => $v > 0));

    $newAggregator = trim((string) ($_POST['new_aggregator'] ?? ''));
    $newEmployerCode = trim((string) ($_POST['new_employer_code'] ?? ''));
    $newEmployerName = trim((string) ($_POST['new_employer_name'] ?? ''));
    $newEmployerSpocName = trim((string) ($_POST['new_employer_spoc_name'] ?? ''));
    $newEmployerSpocMobile = trim((string) ($_POST['new_employer_spoc_mobile'] ?? ''));
    $newAggregatorSpocName = trim((string) ($_POST['new_aggregator_spoc_name'] ?? ''));
    $newAggregatorSpocMobile = trim((string) ($_POST['new_aggregator_spoc_mobile'] ?? ''));
    $newCrmMember = trim((string) ($_POST['new_crm_member'] ?? ''));

    if ($targetIds === []) {
        $redirectUrl = '/job_fair_masters.php?' . http_build_query(array_filter([
            'aggregator' => $filters['aggregator'],
            'employer' => $filters['employer'],
            'crm_member' => $filters['crm_member'],
            'flash' => 'No target records were selected. Reopen the row and try again.',
            'flash_type' => 'warning',
        ], static fn ($v): bool => $v !== ''));
        header('Location: ' . $redirectUrl);
        exit;
    }

    $setParams = [
        $newAggregator === '' ? null : $newAggregator,
        $newEmployerCode === '' ? null : $newEmployerCode,
        $newEmployerName === '' ? null : $newEmployerName,
        $newEmployerSpocName === '' ? null : $newEmployerSpocName,
        $newEmployerSpocMobile === '' ? null : $newEmployerSpocMobile,
        $newAggregatorSpocName === '' ? null : $newAggregatorSpocName,
        $newAggregatorSpocMobile === '' ? null : $newAggregatorSpocMobile,
        $newCrmMember === '' ? null : $newCrmMember,
    ];

    $placeholders = implode(',', array_fill(0, count($targetIds), '?'));
    $updateSql = "UPDATE job_fair_result
        SET Aggregator = ?,
            Employer_ID = ?,
            Employer_Name = ?,
            Employer_SPOC_Name = ?,
            Employer_SPOC_Mobile = ?,
            Aggregator_SPOC_Name = ?,
            Aggregator_SPOC_Mobile = ?,
            CRM_Member = ?
        WHERE id IN ($placeholders)";

    $execParams = array_merge($setParams, $targetIds);
    $stmt = db()->prepare($updateSql);
    $stmt->execute($execParams);
    $affected = $stmt->affectedRows();

    $redirectUrl = '/job_fair_masters.php?' . http_build_query(array_filter([
        'aggregator' => $filters['aggregator'],
        'employer' => $filters['employer'],
        'crm_member' => $filters['crm_member'],
        'flash' => sprintf('Updated %d of %d candidate record(s). Records may now appear under a different mapping if Aggregator, Employer Code or Employer Name was changed.', $affected, count($targetIds)),
        'flash_type' => 'success',
    ], static fn ($v): bool => $v !== ''));
    header('Location: ' . $redirectUrl);
    exit;
}

if (($_GET['download'] ?? '') === 'csv') {
    $rows = fetch_master_rows($filters);
    $filename = 'job_fair_masters_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders non-ASCII correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Aggregator',
        'Employer Code',
        'Employer Name',
        'Employer SPOC Name',
        'Employer SPOC Mobile',
        'Aggregator SPOC Name',
        'Aggregator SPOC Mobile',
        'CRM Member',
        'Job Titles',
        'Candidates',
        'Selected',
        'Shortlisted/Onhold',
        'Shortlist Final Selected',
        'Candidates Joined',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) $row['aggregator'],
            (string) $row['employer_code'],
            (string) $row['employer_name'],
            (string) $row['employer_spoc_name'],
            (string) $row['employer_spoc_mobile'],
            (string) $row['aggregator_spoc_name'],
            (string) $row['aggregator_spoc_mobile'],
            (string) $row['crm_member'],
            (int) ($row['job_title_count'] ?? 0),
            (int) ($row['candidate_count'] ?? 0),
            (int) ($row['selected_count'] ?? 0),
            (int) ($row['shortlisted_onhold_count'] ?? 0),
            (int) ($row['shortlist_final_selected_count'] ?? 0),
            (int) ($row['joined_count'] ?? 0),
        ]);
    }
    fclose($out);
    exit;
}

$aggregatorOptions = fetch_master_distinct_values('Aggregator');
$crmMemberOptions = fetch_master_distinct_values('CRM_Member');
$rows = fetch_master_rows($filters);

$flashMessage = isset($_GET['flash']) ? (string) $_GET['flash'] : null;
$flashTypeIn = (string) ($_GET['flash_type'] ?? 'success');
$flashType = in_array($flashTypeIn, ['success', 'warning', 'danger', 'info'], true) ? $flashTypeIn : 'info';

$downloadUrl = '/job_fair_masters.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'aggregator' => $filters['aggregator'],
    'employer' => $filters['employer'],
    'crm_member' => $filters['crm_member'],
], static fn($value): bool => $value !== ''));

$buildCandidatesUrl = static function (array $row): string {
    return '/job_fair_masters_candidates.php?' . http_build_query([
        'aggregator' => $row['aggregator'],
        'employer_code' => $row['employer_code'],
        'employer_name' => $row['employer_name'],
        'employer_spoc_name' => $row['employer_spoc_name'],
        'employer_spoc_mobile' => $row['employer_spoc_mobile'],
        'aggregator_spoc_name' => $row['aggregator_spoc_name'],
        'aggregator_spoc_mobile' => $row['aggregator_spoc_mobile'],
        'crm_member' => $row['crm_member'],
    ]);
};

render_header('Job fair masters', ['main_container_class' => 'container-fluid']);
render_page_header('Job Fair Masters', [
    'icon' => 'bi-sliders',
    'subtitle' => 'Employer and SPOC mapping derived from job fair result data.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?> d-flex align-items-center gap-2">
        <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : ($flashType === 'warning' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?>"></i>
        <span><?= esc($flashMessage) ?></span>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="aggregator" class="form-label">Aggregator</label>
                <select class="form-select" id="aggregator" name="aggregator">
                    <option value="">All Aggregators</option>
                    <?php foreach ($aggregatorOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['aggregator'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="employer" class="form-label">Employer</label>
                <input type="text" class="form-control" id="employer" name="employer" value="<?= esc($filters['employer']) ?>" placeholder="Search employer name">
            </div>
            <div class="col-md-4">
                <label for="crm_member" class="form-label">CRM Member</label>
                <select class="form-select" id="crm_member" name="crm_member">
                    <option value="">All CRM Members</option>
                    <?php foreach ($crmMemberOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['crm_member'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a href="job_fair_masters.php" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 text-primary me-1"></i>Employer and SPOC Mapping</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> rows</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
            <tr>
                <th rowspan="2">Aggregator</th>
                <th rowspan="2">Employer Code</th>
                <th rowspan="2">Employer Name</th>
                <th rowspan="2">Employer SPOC</th>
                <th rowspan="2">Aggregator SPOC</th>
                <th rowspan="2">CRM Member</th>
                <th colspan="6" class="text-center">Counts</th>
                <th rowspan="2" class="text-end">Actions</th>
            </tr>
            <tr>
                <th class="text-end">Job Titles</th>
                <th class="text-end">Candidates</th>
                <th class="text-end">Selected</th>
                <th class="text-end">SL / OH</th>
                <th class="text-end">SL Final Selected</th>
                <th class="text-end">Joined</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="13"><div class="empty-state"><i class="bi bi-inbox"></i>No records found for selected filters.</div></td></tr>
            <?php endif; ?>
            <?php
                $totalsAcrossRows = [
                    'job_title_count' => 0,
                    'candidate_count' => 0,
                    'selected_count' => 0,
                    'shortlisted_onhold_count' => 0,
                    'shortlist_final_selected_count' => 0,
                    'joined_count' => 0,
                ];
            ?>
            <?php foreach ($rows as $row): ?>
                <?php
                    $empSpocName = (string) $row['employer_spoc_name'];
                    $empSpocMobile = (string) $row['employer_spoc_mobile'];
                    $aggSpocName = (string) $row['aggregator_spoc_name'];
                    $aggSpocMobile = (string) $row['aggregator_spoc_mobile'];
                    $jobTitles = (int) ($row['job_title_count'] ?? 0);
                    $candidates = (int) ($row['candidate_count'] ?? 0);
                    $selected = (int) ($row['selected_count'] ?? 0);
                    $slOh = (int) ($row['shortlisted_onhold_count'] ?? 0);
                    $slFinal = (int) ($row['shortlist_final_selected_count'] ?? 0);
                    $joined = (int) ($row['joined_count'] ?? 0);
                    foreach (array_keys($totalsAcrossRows) as $k) {
                        $totalsAcrossRows[$k] += (int) ($row[$k] ?? 0);
                    }
                ?>
                <tr>
                    <td class="fw-semibold"><?= esc((string) $row['aggregator']) ?></td>
                    <td><?= esc((string) $row['employer_code']) ?></td>
                    <td><?= esc((string) $row['employer_name']) ?></td>
                    <td>
                        <?php if ($empSpocName === 'Unknown' && $empSpocMobile === 'Unknown'): ?>
                            <span class="text-muted">&mdash;</span>
                        <?php else: ?>
                            <div class="fw-semibold"><?= esc($empSpocName) ?></div>
                            <div class="small text-muted"><?= esc($empSpocMobile) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($aggSpocName === 'Unknown' && $aggSpocMobile === 'Unknown'): ?>
                            <span class="text-muted">&mdash;</span>
                        <?php else: ?>
                            <div class="fw-semibold"><?= esc($aggSpocName) ?></div>
                            <div class="small text-muted"><?= esc($aggSpocMobile) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= esc((string) $row['crm_member']) ?></td>
                    <td class="text-end"><?= number_format($jobTitles) ?></td>
                    <td class="text-end"><?= number_format($candidates) ?></td>
                    <td class="text-end"><?= number_format($selected) ?></td>
                    <td class="text-end"><?= number_format($slOh) ?></td>
                    <td class="text-end"><?= number_format($slFinal) ?></td>
                    <td class="text-end"><?= number_format($joined) ?></td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end flex-wrap">
                            <a class="btn btn-sm btn-outline-primary" href="<?= esc($buildCandidatesUrl($row)) ?>"><i class="bi bi-people"></i> View Candidates</a>
                            <?php if ($canEdit): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editMappingModal" data-row='<?= esc(json_encode($row, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT)) ?>'><i class="bi bi-pencil"></i> Edit</button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($rows !== []): ?>
                <tr class="table-secondary fw-semibold">
                    <td colspan="6">Total</td>
                    <td class="text-end"><?= number_format($totalsAcrossRows['job_title_count']) ?></td>
                    <td class="text-end"><?= number_format($totalsAcrossRows['candidate_count']) ?></td>
                    <td class="text-end"><?= number_format($totalsAcrossRows['selected_count']) ?></td>
                    <td class="text-end"><?= number_format($totalsAcrossRows['shortlisted_onhold_count']) ?></td>
                    <td class="text-end"><?= number_format($totalsAcrossRows['shortlist_final_selected_count']) ?></td>
                    <td class="text-end"><?= number_format($totalsAcrossRows['joined_count']) ?></td>
                    <td></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="editMappingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="editMappingForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>Edit Mapping</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="update_row">
                    <input type="hidden" name="target_ids" id="editTargetIds">

                    <div class="alert alert-info d-flex align-items-start gap-2">
                        <i class="bi bi-info-circle-fill mt-1"></i>
                        <div>
                            <div class="fw-semibold">
                                This update affects <span id="editAffectCount">all</span> candidate record(s).
                                Record IDs were captured when you opened this dialog; only those records will be modified.
                            </div>
                            <div class="small">
                                Changing <strong>Aggregator</strong>, <strong>Employer Code</strong> or <strong>Employer Name</strong>
                                will move these records into a different master mapping &mdash; useful for correcting spelling
                                variants (e.g. <em>"sureshkumar"</em> &rarr; <em>"suresh kumar"</em>) so all variants
                                merge under a single master row. Leaving a field blank stores it as empty (shown as
                                <em>Unknown</em>).
                            </div>
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted small mb-2">Identity</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="new_aggregator">Aggregator</label>
                            <input type="text" class="form-control" id="new_aggregator" name="new_aggregator" maxlength="255">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="new_employer_code">Employer Code</label>
                            <input type="text" class="form-control" id="new_employer_code" name="new_employer_code" maxlength="100">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="new_employer_name">Employer Name</label>
                            <input type="text" class="form-control" id="new_employer_name" name="new_employer_name" maxlength="255">
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted small mb-2">SPOC &amp; CRM</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="new_employer_spoc_name">Employer SPOC Name</label>
                            <input type="text" class="form-control" id="new_employer_spoc_name" name="new_employer_spoc_name" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new_employer_spoc_mobile">Employer SPOC Mobile</label>
                            <input type="text" class="form-control" id="new_employer_spoc_mobile" name="new_employer_spoc_mobile" maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new_aggregator_spoc_name">Aggregator SPOC Name</label>
                            <input type="text" class="form-control" id="new_aggregator_spoc_name" name="new_aggregator_spoc_name" maxlength="255">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="new_aggregator_spoc_mobile">Aggregator SPOC Mobile</label>
                            <input type="text" class="form-control" id="new_aggregator_spoc_mobile" name="new_aggregator_spoc_mobile" maxlength="50">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="new_crm_member">CRM Member</label>
                            <input type="text" class="form-control" id="new_crm_member" name="new_crm_member" maxlength="255">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="editMappingSubmit"><i class="bi bi-save me-1"></i>Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const modalEl = document.getElementById('editMappingModal');
    if (!modalEl) return;

    function sanitize(value) {
        return value === 'Unknown' ? '' : (value ?? '');
    }

    modalEl.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        if (!trigger) return;
        let row;
        try {
            row = JSON.parse(trigger.getAttribute('data-row') || '{}');
        } catch (e) {
            row = {};
        }

        // Snapshot the candidate IDs at modal-open time so the update is scoped
        // to exactly the records the admin saw, regardless of later edits to
        // the identity fields.
        document.getElementById('editTargetIds').value = row.record_ids ?? '';

        document.getElementById('new_aggregator').value = sanitize(row.aggregator);
        document.getElementById('new_employer_code').value = sanitize(row.employer_code);
        document.getElementById('new_employer_name').value = sanitize(row.employer_name);
        document.getElementById('new_employer_spoc_name').value = sanitize(row.employer_spoc_name);
        document.getElementById('new_employer_spoc_mobile').value = sanitize(row.employer_spoc_mobile);
        document.getElementById('new_aggregator_spoc_name').value = sanitize(row.aggregator_spoc_name);
        document.getElementById('new_aggregator_spoc_mobile').value = sanitize(row.aggregator_spoc_mobile);
        document.getElementById('new_crm_member').value = sanitize(row.crm_member);

        document.getElementById('editAffectCount').textContent = row.candidate_count ?? 'all';
    });

    document.getElementById('editMappingForm').addEventListener('submit', (event) => {
        const count = document.getElementById('editAffectCount').textContent;
        const confirmed = window.confirm('This will update ' + count + ' candidate record(s). Continue?');
        if (!confirmed) {
            event.preventDefault();
        } else {
            document.getElementById('editMappingSubmit').disabled = true;
        }
    });
})();
</script>
<?php endif; ?>

<?php render_footer();
