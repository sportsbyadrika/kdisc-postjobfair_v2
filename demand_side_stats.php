<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();
demand_side_bootstrap();

$selectedDate = trim((string) ($_GET['date'] ?? ''));
if ($selectedDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = '';
}

$fromFilter = trim((string) ($_GET['from'] ?? ''));
$toFilter = trim((string) ($_GET['to'] ?? ''));
$fromFilter = $fromFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromFilter) ? $fromFilter : '';
$toFilter = $toFilter !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toFilter) ? $toFilter : '';

$dateConds = ["field_name = 'status'"];
$dateParams = [];
if ($fromFilter !== '') { $dateConds[] = 'DATE(edited_at) >= ?'; $dateParams[] = $fromFilter; }
if ($toFilter !== '')   { $dateConds[] = 'DATE(edited_at) <= ?'; $dateParams[] = $toFilter; }
$dateWhere = 'WHERE ' . implode(' AND ', $dateConds);

$dateSql = "SELECT
        DATE(edited_at) AS d,
        SUM(CASE WHEN new_value = 'Valid'     THEN 1 ELSE 0 END) AS valid_count,
        SUM(CASE WHEN new_value = 'Invalid'   THEN 1 ELSE 0 END) AS invalid_count,
        SUM(CASE WHEN new_value = 'Corrected' THEN 1 ELSE 0 END) AS corrected_count,
        COUNT(*) AS total_changes
    FROM demand_employer_job_edit_log
    $dateWhere
    GROUP BY DATE(edited_at)
    ORDER BY d DESC";
$dateStmt = db()->prepare($dateSql);
$dateStmt->execute($dateParams);
$dateRows = $dateStmt->fetchAll();

$userRows = [];
if ($selectedDate !== '') {
    $userSql = "SELECT
            u.id AS user_id,
            u.name AS user_name,
            u.role AS user_role,
            SUM(CASE WHEN src = 'employer' THEN 1 ELSE 0 END) AS employer_edits,
            SUM(CASE WHEN src = 'job'      THEN 1 ELSE 0 END) AS job_edits,
            SUM(CASE WHEN src = 'job' AND field_name = 'status' AND new_value = 'Valid'     THEN 1 ELSE 0 END) AS job_valid,
            SUM(CASE WHEN src = 'job' AND field_name = 'status' AND new_value = 'Invalid'   THEN 1 ELSE 0 END) AS job_invalid,
            SUM(CASE WHEN src = 'job' AND field_name = 'status' AND new_value = 'Corrected' THEN 1 ELSE 0 END) AS job_corrected,
            COUNT(*) AS total_edits
        FROM (
            SELECT 'employer' AS src, edited_by, field_name, new_value FROM demand_employer_edit_log WHERE DATE(edited_at) = ?
            UNION ALL
            SELECT 'job'      AS src, edited_by, field_name, new_value FROM demand_employer_job_edit_log WHERE DATE(edited_at) = ?
        ) t
        LEFT JOIN users u ON u.id = t.edited_by
        GROUP BY u.id, u.name, u.role
        ORDER BY total_edits DESC, u.name ASC";
    $userStmt = db()->prepare($userSql);
    $userStmt->execute([$selectedDate, $selectedDate]);
    $userRows = $userStmt->fetchAll();
}

render_header('Demand Side · Data Modification Statistics', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Data Modification Statistics', [
    'icon' => 'bi-bar-chart-line',
    'subtitle' => 'Date-wise employer_jobs status change counts (Valid / Invalid / Corrected). Click a date row for user-wise breakdown across employers and jobs.',
    'actions' => '<a class="btn btn-light" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">From date</label>
                <input type="date" class="form-control" name="from" value="<?= esc($fromFilter) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To date</label>
                <input type="date" class="form-control" name="to" value="<?= esc($toFilter) ?>">
            </div>
            <?php if ($selectedDate !== ''): ?>
                <input type="hidden" name="date" value="<?= esc($selectedDate) ?>">
            <?php endif; ?>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary">Apply</button>
                <a class="btn btn-light" href="/demand_side_stats.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-week text-primary me-1"></i>Date-wise Employer Jobs Status Changes</span>
        <span class="status-chip status-info"><?= number_format(count($dateRows)) ?> dates</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="text-end">Valid</th>
                    <th class="text-end">Invalid</th>
                    <th class="text-end">Corrected</th>
                    <th class="text-end">Total status changes</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($dateRows === []): ?>
                    <tr><td colspan="5"><div class="empty-state"><i class="bi bi-inbox"></i>No employer_jobs status changes recorded in this range yet.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($dateRows as $row): ?>
                    <?php
                        $d = (string) $row['d'];
                        $url = '/demand_side_stats.php?' . http_build_query(array_filter([
                            'date' => $d, 'from' => $fromFilter, 'to' => $toFilter,
                        ], static fn($v): bool => $v !== ''));
                        $isSelected = $selectedDate === $d;
                    ?>
                    <tr <?= $isSelected ? 'class="table-primary"' : '' ?>>
                        <td class="fw-semibold"><a href="<?= esc($url) ?>"><?= esc($d) ?></a></td>
                        <td class="text-end"><?= number_format((int) $row['valid_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['invalid_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['corrected_count']) ?></td>
                        <td class="text-end fw-semibold"><?= number_format((int) $row['total_changes']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($selectedDate !== ''): ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people text-primary me-1"></i>User-wise correction counts on <?= esc($selectedDate) ?></span>
            <span class="status-chip status-info"><?= number_format(count($userRows)) ?> users</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Role</th>
                        <th class="text-end">Employer edits</th>
                        <th class="text-end">Job edits</th>
                        <th class="text-end">Job — Valid</th>
                        <th class="text-end">Job — Invalid</th>
                        <th class="text-end">Job — Corrected</th>
                        <th class="text-end">Total edits</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($userRows === []): ?>
                        <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No user edits recorded on this date.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($userRows as $row): ?>
                        <tr>
                            <td class="fw-semibold"><?= esc((string) ($row['user_name'] ?? '(unknown)')) ?></td>
                            <td><span class="status-chip status-neutral"><?= esc(role_label((string) ($row['user_role'] ?? ''))) ?></span></td>
                            <td class="text-end"><?= number_format((int) $row['employer_edits']) ?></td>
                            <td class="text-end"><?= number_format((int) $row['job_edits']) ?></td>
                            <td class="text-end"><?= number_format((int) $row['job_valid']) ?></td>
                            <td class="text-end"><?= number_format((int) $row['job_invalid']) ?></td>
                            <td class="text-end"><?= number_format((int) $row['job_corrected']) ?></td>
                            <td class="text-end fw-semibold"><?= number_format((int) $row['total_edits']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
