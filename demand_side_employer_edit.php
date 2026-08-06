<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();
demand_side_bootstrap();

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);

$employerRowId = (int) ($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? 'view') === 'edit' ? 'edit' : 'view';

$flashMessage = null;
$flashType = 'success';

if ($employerRowId <= 0) {
    render_header('Employer');
    render_page_header('Employer not found', ['icon' => 'bi-exclamation-triangle']);
    echo '<div class="alert alert-danger">Missing employer id.</div>';
    render_footer();
    exit;
}

$employerStmt = db()->prepare('SELECT * FROM demand_employers WHERE id = ?');
$employerStmt->execute([$employerRowId]);
$employer = $employerStmt->fetch();
if (!$employer) {
    render_header('Employer');
    render_page_header('Employer not found', ['icon' => 'bi-exclamation-triangle']);
    echo '<div class="alert alert-danger">Employer not found.</div>';
    render_footer();
    exit;
}

/* ------------------------------------------------------------------------- *
 * Save handlers (mode=edit)
 * ------------------------------------------------------------------------- */
if (is_post() && $mode === 'edit') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_employer') {
        $newActive = trim((string) ($_POST['active_status'] ?? ''));
        $newRemarks = trim((string) ($_POST['remarks'] ?? ''));
        $allowedActive = demand_employer_active_status_options();
        if ($newActive !== '' && !in_array($newActive, $allowedActive, true)) {
            $flashMessage = 'Invalid active status value.';
            $flashType = 'danger';
        } else {
            $oldActive = (string) ($employer['active_status'] ?? '');
            $oldRemarks = (string) ($employer['remarks'] ?? '');
            $update = db()->prepare('UPDATE demand_employers SET active_status = ?, remarks = ?, task_owner_id = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
            $update->execute([
                $newActive === '' ? null : $newActive,
                $newRemarks === '' ? null : $newRemarks,
                $userId,
                $userId,
                $employerRowId,
            ]);
            demand_write_edit_log('employer', $employerRowId, 'active_status', $oldActive, $newActive, $userId);
            demand_write_edit_log('employer', $employerRowId, 'remarks', $oldRemarks, $newRemarks, $userId);
            $flashMessage = 'Employer updated.';
            // Refresh
            $employerStmt->execute([$employerRowId]);
            $employer = $employerStmt->fetch();
        }
    } elseif ($action === 'save_job') {
        $jobRowId = (int) ($_POST['job_row_id'] ?? 0);
        if ($jobRowId <= 0) {
            $flashMessage = 'Invalid job id.';
            $flashType = 'danger';
        } else {
            $jobStmt = db()->prepare('SELECT * FROM demand_employer_jobs WHERE id = ? AND emp_id = ?');
            $jobStmt->execute([$jobRowId, (int) $employer['employer_id']]);
            $job = $jobStmt->fetch();
            if (!$job) {
                $flashMessage = 'Job not found for this employer.';
                $flashType = 'danger';
            } else {
                $postedOn = demand_parse_date((string) ($_POST['posted_on'] ?? ''));
                $postedBy = trim((string) ($_POST['posted_by'] ?? ''));
                $expiredDate = demand_parse_date((string) ($_POST['expired_date'] ?? ''));
                $correctedPos = demand_parse_int((string) ($_POST['corrected_open_position'] ?? ''));
                $status = trim((string) ($_POST['status'] ?? ''));
                $remarks = trim((string) ($_POST['remarks'] ?? ''));
                $remarksGroupId = demand_parse_int((string) ($_POST['remarks_group_id'] ?? ''));
                $allowedStatus = demand_employer_job_status_options();
                if ($status !== '' && !in_array($status, $allowedStatus, true)) {
                    $flashMessage = 'Invalid status value.';
                    $flashType = 'danger';
                } else {
                    $update = db()->prepare('UPDATE demand_employer_jobs
                        SET posted_on = ?, posted_by = ?, expired_date = ?, corrected_open_position = ?,
                            status = ?, remarks = ?, remarks_group_id = ?, task_owner_id = ?, updated_by = ?, updated_at = NOW()
                        WHERE id = ?');
                    $update->execute([
                        $postedOn,
                        $postedBy === '' ? null : $postedBy,
                        $expiredDate,
                        $correctedPos,
                        $status === '' ? null : $status,
                        $remarks === '' ? null : $remarks,
                        $remarksGroupId,
                        $userId,
                        $userId,
                        $jobRowId,
                    ]);
                    demand_write_edit_log('job', $jobRowId, 'posted_on', (string) ($job['posted_on'] ?? ''), (string) ($postedOn ?? ''), $userId);
                    demand_write_edit_log('job', $jobRowId, 'posted_by', (string) ($job['posted_by'] ?? ''), $postedBy, $userId);
                    demand_write_edit_log('job', $jobRowId, 'expired_date', (string) ($job['expired_date'] ?? ''), (string) ($expiredDate ?? ''), $userId);
                    demand_write_edit_log('job', $jobRowId, 'corrected_open_position', (string) ($job['corrected_open_position'] ?? ''), (string) ($correctedPos ?? ''), $userId);
                    demand_write_edit_log('job', $jobRowId, 'status', (string) ($job['status'] ?? ''), $status, $userId);
                    demand_write_edit_log('job', $jobRowId, 'remarks', (string) ($job['remarks'] ?? ''), $remarks, $userId);
                    demand_write_edit_log('job', $jobRowId, 'remarks_group_id', (string) ($job['remarks_group_id'] ?? ''), (string) ($remarksGroupId ?? ''), $userId);
                    $flashMessage = 'Job updated (Job ID ' . (int) $job['job_id'] . ').';
                }
            }
        }
    }
}

/* ------------------------------------------------------------------------- *
 * Load jobs list (post-save so counts reflect updates)
 * ------------------------------------------------------------------------- */
$jobsStmt = db()->prepare('SELECT j.*, u.name AS task_owner_name, rg.name AS remarks_group_name
    FROM demand_employer_jobs j
    LEFT JOIN users u ON u.id = j.task_owner_id
    LEFT JOIN demand_remarks_groups rg ON rg.id = j.remarks_group_id
    WHERE j.emp_id = ?
    ORDER BY j.job_id ASC');
$jobsStmt->execute([(int) $employer['employer_id']]);
$jobs = $jobsStmt->fetchAll();

$remarksGroups = db()->query('SELECT id, name FROM demand_remarks_groups WHERE active = 1 ORDER BY name ASC')->fetchAll();

$employerLogs = db()->prepare('SELECT l.*, u.name AS editor_name FROM demand_employer_edit_log l LEFT JOIN users u ON u.id = l.edited_by WHERE l.employer_row_id = ? ORDER BY l.edited_at DESC, l.id DESC LIMIT 200');
$employerLogs->execute([$employerRowId]);
$employerLogRows = $employerLogs->fetchAll();

$jobIds = array_map(static fn(array $j): int => (int) $j['id'], $jobs);
$jobLogRows = [];
if ($jobIds !== []) {
    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    $stmt = db()->prepare("SELECT l.*, u.name AS editor_name, j.job_id
        FROM demand_employer_job_edit_log l
        LEFT JOIN users u ON u.id = l.edited_by
        LEFT JOIN demand_employer_jobs j ON j.id = l.job_row_id
        WHERE l.job_row_id IN ($placeholders)
        ORDER BY l.edited_at DESC, l.id DESC
        LIMIT 500");
    $stmt->execute($jobIds);
    $jobLogRows = $stmt->fetchAll();
}

$isEdit = ($mode === 'edit');

render_header('Employer ' . ($isEdit ? 'Edit' : 'View'), ['main_container_class' => 'container-fluid']);
render_page_header('Employer ' . ($isEdit ? 'Edit' : 'View') . ' · ' . esc((string) ($employer['employer_name'] ?? '')), [
    'icon' => $isEdit ? 'bi-pencil-square' : 'bi-eye',
    'subtitle' => 'Employer ID ' . (int) $employer['employer_id'] . ' · ' . (int) count($jobs) . ' job(s)',
    'actions' => '<a class="btn btn-light me-1" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to List</a>'
        . ($isEdit
            ? '<a class="btn btn-outline-secondary" href="/demand_side_employer_edit.php?id=' . $employerRowId . '&mode=view"><i class="bi bi-eye me-1"></i>View mode</a>'
            : '<a class="btn btn-primary" href="/demand_side_employer_edit.php?id=' . $employerRowId . '&mode=edit"><i class="bi bi-pencil-square me-1"></i>Edit mode</a>'),
]);

if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?> d-flex align-items-start gap-2">
        <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?> mt-1"></i>
        <span><?= esc($flashMessage) ?></span>
    </div>
<?php endif; ?>

<?php
/**
 * $renderKvTable(title, rows) prints a striped 2-column table where each row
 * is [Field, Value]. Values are bold; empty values fall through to a muted
 * em-dash. Alternate row colouring comes from Bootstrap's table-striped.
 */
$renderKvTable = static function (string $title, array $pairs): void {
    ?>
    <div class="mb-3">
        <div class="detail-group-title mb-1"><?= esc($title) ?></div>
        <div class="table-responsive">
            <table class="table table-sm table-striped table-bordered align-middle mb-0" style="table-layout: fixed;">
                <colgroup>
                    <col style="width: 38%;">
                    <col>
                </colgroup>
                <tbody>
                <?php foreach ($pairs as [$k, $v]): ?>
                    <?php $val = (string) ($v ?? ''); ?>
                    <tr>
                        <th class="fw-normal text-muted"><?= esc($k) ?></th>
                        <td class="fw-bold text-break"><?= $val === '' ? '<span class="text-muted fw-normal">&mdash;</span>' : nl2br(esc($val)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
};

$ownerName = '';
if ((int) ($employer['task_owner_id'] ?? 0) > 0) {
    $ownerStmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $ownerStmt->execute([(int) $employer['task_owner_id']]);
    $ownerName = (string) ($ownerStmt->fetchColumn() ?: '');
}

$nicJoin = static function (?string $code, ?string $name): string {
    $c = trim((string) ($code ?? ''));
    $n = trim((string) ($name ?? ''));
    if ($c === '' && $n === '') return '';
    if ($c === '') return $n;
    if ($n === '') return $c;
    return $c . ' — ' . $n;
};
?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building text-primary me-1"></i>Employer</span>
        <?php if ($isEdit): ?><span class="status-chip status-warning"><i class="bi bi-pencil-square me-1"></i>Edit mode</span><?php endif; ?>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <?php $renderKvTable('Identity', [
                    ['Employer ID',           (string) $employer['employer_id']],
                    ['Clustered Employer ID', (string) ($employer['clustered_employer_id'] ?? '')],
                    ['Employer Name',         (string) ($employer['employer_name'] ?? '')],
                    ['Cluster Employer Name', (string) ($employer['clusteremployername'] ?? '')],
                    ['Website',               (string) ($employer['website'] ?? '')],
                    ['Company Address',       (string) ($employer['company_address'] ?? '')],
                    ['Type of Company',       (string) ($employer['type_of_company'] ?? '')],
                    ['Created',               (string) ($employer['created_datetime'] ?? '')],
                ]); ?>
            </div>
            <div class="col-lg-6">
                <?php $renderKvTable('Job Agency & Flags', [
                    ['Job Agency',              (string) ($employer['jobagency'] ?? '')],
                    ['Job Agency ID',           (string) ($employer['job_agency_id'] ?? '')],
                    ['Job Fair Flag',           (string) ($employer['jobfair_flag'] ?? '')],
                    ['VK Flag',                 (string) ($employer['vk_flag'] ?? '')],
                    ['Final Status',            (string) ($employer['final_status'] ?? '')],
                    ['Task Owner (last edit)',  $ownerName],
                ]); ?>
            </div>
            <div class="col-lg-6">
                <?php $renderKvTable('NIC Classification (1/2)', [
                    ['Section',  $nicJoin($employer['nic_section_code']  ?? '', $employer['nic_section_name']  ?? '')],
                    ['Division', $nicJoin($employer['nic_division_code'] ?? '', $employer['nic_division_name'] ?? '')],
                    ['Group',    $nicJoin($employer['nic_group_code']    ?? '', $employer['nic_group_name']    ?? '')],
                ]); ?>
            </div>
            <div class="col-lg-6">
                <?php $renderKvTable('NIC Classification (2/2)', [
                    ['Class',                     $nicJoin($employer['nic_class_code']     ?? '', $employer['nic_class_name']     ?? '')],
                    ['Sub-class',                 $nicJoin($employer['nic_sub_class_code'] ?? '', $employer['nic_sub_class_name'] ?? '')],
                    ['Reason for Classification', (string) ($employer['reason_for_classification'] ?? '')],
                ]); ?>
            </div>
        </div>

        <?php if ($isEdit): ?>
            <hr>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save_employer">
                <div class="col-md-4">
                    <label class="form-label">Active Status</label>
                    <select class="form-select" name="active_status">
                        <option value="">Select</option>
                        <?php foreach (demand_employer_active_status_options() as $opt): ?>
                            <option value="<?= esc($opt) ?>" <?= ((string) ($employer['active_status'] ?? '')) === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Remarks</label>
                    <textarea class="form-control" name="remarks" rows="2"><?= esc((string) ($employer['remarks'] ?? '')) ?></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save employer</button>
                </div>
            </form>
        <?php else: ?>
            <hr>
            <div class="row g-3">
                <div class="col-md-4"><span class="form-label">Active Status</span><div><?= render_status_chip((string) ($employer['active_status'] ?? '')) ?></div></div>
                <div class="col-md-8"><span class="form-label">Remarks</span><div class="border rounded p-2 bg-light-subtle"><?= nl2br(esc((string) ($employer['remarks'] ?? ''))) ?: '<span class="text-muted">—</span>' ?></div></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4" id="jobs">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-briefcase text-primary me-1"></i>Employer Jobs <span class="text-muted small ms-1">emp_id = <?= (int) $employer['employer_id'] ?></span></span>
        <span class="status-chip status-info"><?= number_format(count($jobs)) ?> jobs</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Job Title</th>
                        <th class="text-end">Open Positions</th>
                        <th>Salary</th>
                        <th>Qualification</th>
                        <th>Posted On</th>
                        <th>Posted By</th>
                        <th>Expired Date</th>
                        <th class="text-end">Corrected Open Position</th>
                        <th>Status</th>
                        <th>Remarks Group</th>
                        <th>Remarks</th>
                        <th>Task Owner</th>
                        <?php if ($isEdit): ?><th class="text-end">Save</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if ($jobs === []): ?>
                    <tr><td colspan="<?= $isEdit ? 14 : 13 ?>"><div class="empty-state"><i class="bi bi-inbox"></i>No jobs on file for this employer.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($jobs as $job): ?>
                    <?php $status = (string) ($job['status'] ?? ''); ?>
                    <?php if ($isEdit): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="save_job">
                            <input type="hidden" name="job_row_id" value="<?= (int) $job['id'] ?>">
                            <tr>
                                <td><?= (int) $job['job_id'] ?></td>
                                <td class="fw-semibold" style="min-width:180px;"><?= esc((string) ($job['jobtitle'] ?? '')) ?></td>
                                <td class="text-end"><?= (int) ($job['open_positions'] ?? 0) ?></td>
                                <td class="small text-muted"><?= esc((string) ($job['salary_type'] ?? '')) ?><br><?= esc((string) ($job['salary_slab'] ?? '')) ?></td>
                                <td class="small text-muted"><?= esc((string) ($job['qualificationcategory'] ?? '')) ?></td>
                                <td><input type="date" class="form-control form-control-sm" name="posted_on" value="<?= esc(substr((string) ($job['posted_on'] ?? ''), 0, 10)) ?>"></td>
                                <td><input type="text" class="form-control form-control-sm" name="posted_by" value="<?= esc((string) ($job['posted_by'] ?? '')) ?>" style="min-width:120px;"></td>
                                <td><input type="date" class="form-control form-control-sm" name="expired_date" value="<?= esc(substr((string) ($job['expired_date'] ?? ''), 0, 10)) ?>"></td>
                                <td><input type="number" class="form-control form-control-sm text-end" name="corrected_open_position" value="<?= esc((string) ($job['corrected_open_position'] ?? '')) ?>" style="min-width:100px;"></td>
                                <td>
                                    <select class="form-select form-select-sm" name="status" style="min-width:120px;">
                                        <option value="">Select</option>
                                        <?php foreach (demand_employer_job_status_options() as $opt): ?>
                                            <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <select class="form-select form-select-sm" name="remarks_group_id" style="min-width:150px;">
                                        <option value="">Select</option>
                                        <?php foreach ($remarksGroups as $rg): ?>
                                            <option value="<?= (int) $rg['id'] ?>" <?= (int) ($job['remarks_group_id'] ?? 0) === (int) $rg['id'] ? 'selected' : '' ?>><?= esc((string) $rg['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><textarea class="form-control form-control-sm" name="remarks" rows="2" style="min-width:180px;"><?= esc((string) ($job['remarks'] ?? '')) ?></textarea></td>
                                <td class="small text-muted"><?= esc((string) ($job['task_owner_name'] ?? '')) ?></td>
                                <td class="text-end"><button class="btn btn-sm btn-primary"><i class="bi bi-save"></i></button></td>
                            </tr>
                        </form>
                    <?php else: ?>
                        <tr>
                            <td><?= (int) $job['job_id'] ?></td>
                            <td class="fw-semibold"><?= esc((string) ($job['jobtitle'] ?? '')) ?></td>
                            <td class="text-end"><?= (int) ($job['open_positions'] ?? 0) ?></td>
                            <td class="small text-muted"><?= esc((string) ($job['salary_type'] ?? '')) ?><br><?= esc((string) ($job['salary_slab'] ?? '')) ?></td>
                            <td class="small text-muted"><?= esc((string) ($job['qualificationcategory'] ?? '')) ?></td>
                            <td><?= esc(substr((string) ($job['posted_on'] ?? ''), 0, 10)) ?></td>
                            <td><?= esc((string) ($job['posted_by'] ?? '')) ?></td>
                            <td><?= esc(substr((string) ($job['expired_date'] ?? ''), 0, 10)) ?></td>
                            <td class="text-end"><?= esc((string) ($job['corrected_open_position'] ?? '')) ?></td>
                            <td><?= render_status_chip($status) ?></td>
                            <td><?= esc((string) ($job['remarks_group_name'] ?? '')) ?></td>
                            <td><?= nl2br(esc((string) ($job['remarks'] ?? ''))) ?></td>
                            <td class="small text-muted"><?= esc((string) ($job['task_owner_name'] ?? '')) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history text-primary me-1"></i>Employer Edit History</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>When</th><th>Field</th><th>Old</th><th>New</th><th>Edited By</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($employerLogRows === []): ?>
                                <tr><td colspan="5" class="text-center text-muted">No employer edits yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($employerLogRows as $log): ?>
                                <tr>
                                    <td class="small text-muted"><?= esc((string) $log['edited_at']) ?></td>
                                    <td class="small"><?= esc((string) $log['field_name']) ?></td>
                                    <td class="small text-muted"><?= esc((string) ($log['old_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['new_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['editor_name'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history text-primary me-1"></i>Employer Jobs Edit History</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr><th>When</th><th>Job ID</th><th>Field</th><th>Old</th><th>New</th><th>Edited By</th></tr>
                        </thead>
                        <tbody>
                            <?php if ($jobLogRows === []): ?>
                                <tr><td colspan="6" class="text-center text-muted">No job edits yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($jobLogRows as $log): ?>
                                <tr>
                                    <td class="small text-muted"><?= esc((string) $log['edited_at']) ?></td>
                                    <td class="small"><?= (int) ($log['job_id'] ?? 0) ?></td>
                                    <td class="small"><?= esc((string) $log['field_name']) ?></td>
                                    <td class="small text-muted"><?= esc((string) ($log['old_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['new_value'] ?? '')) ?></td>
                                    <td class="small"><?= esc((string) ($log['editor_name'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>
