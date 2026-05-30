<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

/**
 * Candidates master: per-candidate row from job_fair_result with filters
 * (Job Fair / District / DWMS ID / Name), CSV download and a bulk update
 * uploader that maps Job Station + Local Body by the composite business
 * key (Job_Fair_No + Job_Id + DWMS_ID).
 */

$jobFairFilter = trim((string) ($_GET['job_fair'] ?? ''));
$districtFilter = trim((string) ($_GET['district'] ?? ''));
$dwmsIdFilter = trim((string) ($_GET['dwms_id'] ?? ''));
$nameFilter = trim((string) ($_GET['name'] ?? ''));
$page = max((int) ($_GET['page'] ?? 1), 1);
$perPage = 50;

$flashMessage = null;
$flashType = 'success';

/* ------------------------------------------------------------------------- *
 * Bulk update via CSV upload. Required columns (case / underscore insensitive):
 *   DWMS_ID, Job_Id, Job_Fair_No, Candidate_Jobstation, Candidate_Localbody
 * (also accepts "DWMS ID" / "Job ID" / "Job Fair No" / "Job Station" /
 * "Local Body" labels).
 * Each row UPDATEs Candidate_Jobstation + Candidate_Localbody on every
 * job_fair_result whose (Job_Fair_No, Job_Id, DWMS_ID) match the CSV row.
 * ------------------------------------------------------------------------- */
if (is_post() && ($_POST['action'] ?? '') === 'bulk_update') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $flashMessage = 'No CSV file uploaded or upload failed.';
        $flashType = 'danger';
    } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $flashMessage = 'Could not open the uploaded CSV.';
            $flashType = 'danger';
        } else {
            $header = fgetcsv($fh);
            if (!$header) {
                $flashMessage = 'CSV appears to be empty.';
                $flashType = 'danger';
                fclose($fh);
            } else {
                $norm = static function (string $s): string {
                    return strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($s)));
                };
                $headerNorm = array_map($norm, $header);
                $aliases = [
                    'dwms_id'              => ['dwms_id', 'dwmsid'],
                    'job_id'               => ['job_id', 'jobid'],
                    'job_fair_no'          => ['job_fair_no', 'jobfairno', 'job_fair_number', 'jobfairnumber'],
                    'candidate_jobstation' => ['candidate_jobstation', 'job_station', 'jobstation'],
                    'candidate_localbody'  => ['candidate_localbody', 'local_body', 'localbody'],
                ];
                $colIndex = [];
                $missing = [];
                foreach ($aliases as $canonical => $accepted) {
                    $found = -1;
                    foreach ($headerNorm as $i => $h) {
                        if (in_array($h, $accepted, true)) { $found = $i; break; }
                    }
                    if ($found < 0) {
                        $missing[] = $canonical;
                    } else {
                        $colIndex[$canonical] = $found;
                    }
                }
                if ($missing !== []) {
                    $flashMessage = 'Required column(s) missing in CSV: ' . implode(', ', $missing);
                    $flashType = 'danger';
                    fclose($fh);
                } else {
                    $updateStmt = db()->prepare(
                        "UPDATE job_fair_result
                            SET Candidate_Jobstation = ?, Candidate_Localbody = ?
                          WHERE TRIM(COALESCE(Job_Fair_No, '')) = ?
                            AND TRIM(COALESCE(Job_Id, '')) = ?
                            AND TRIM(COALESCE(DWMS_ID, '')) = ?"
                    );
                    $processed = 0; $rowsAffected = 0; $skipped = 0; $skipReasons = [];
                    while (($row = fgetcsv($fh)) !== false) {
                        $processed++;
                        $dwms = trim((string) ($row[$colIndex['dwms_id']] ?? ''));
                        $jobId = trim((string) ($row[$colIndex['job_id']] ?? ''));
                        $jfn = trim((string) ($row[$colIndex['job_fair_no']] ?? ''));
                        $js = trim((string) ($row[$colIndex['candidate_jobstation']] ?? ''));
                        $lb = trim((string) ($row[$colIndex['candidate_localbody']] ?? ''));
                        if ($dwms === '' || $jobId === '' || $jfn === '') {
                            $skipped++;
                            if (count($skipReasons) < 5) {
                                $skipReasons[] = "Row $processed: missing key (DWMS / Job ID / Job Fair No)";
                            }
                            continue;
                        }
                        $updateStmt->execute([
                            $js === '' ? null : $js,
                            $lb === '' ? null : $lb,
                            $jfn,
                            $jobId,
                            $dwms,
                        ]);
                        $rowsAffected += $updateStmt->affectedRows();
                    }
                    fclose($fh);
                    $flashMessage = sprintf(
                        'CSV processed: %d row(s) read, %d candidate record(s) updated, %d row(s) skipped.%s',
                        $processed,
                        $rowsAffected,
                        $skipped,
                        $skipReasons === [] ? '' : ' First skips: ' . implode('; ', $skipReasons)
                    );
                    $flashType = $skipped > 0 ? 'warning' : 'success';
                }
            }
        }
    }
}

/* ------------------------------------------------------------------------- *
 * Build the WHERE clause shared by listing query, count query and CSV.
 * ------------------------------------------------------------------------- */
function candidates_master_build_where(string $jobFair, string $district, string $dwmsId, string $name, array &$params): string
{
    $conds = ['1=1'];
    if ($jobFair !== '') {
        $conds[] = "TRIM(COALESCE(Job_Fair_No, '')) = ?";
        $params[] = $jobFair;
    }
    if ($district !== '') {
        // Match against either home district or SDPK district so the filter
        // picks up candidates wherever the chosen district is recorded.
        $conds[] = "(TRIM(COALESCE(Candidate_District, '')) = ? OR TRIM(COALESCE(SDPK_District, '')) = ?)";
        $params[] = $district;
        $params[] = $district;
    }
    if ($dwmsId !== '') {
        $conds[] = "DWMS_ID LIKE ?";
        $params[] = '%' . $dwmsId . '%';
    }
    if ($name !== '') {
        $conds[] = "Candidate_Name LIKE ?";
        $params[] = '%' . $name . '%';
    }
    return 'WHERE ' . implode(' AND ', $conds);
}

/* ------------------------------------------------------------------------- *
 * CSV download (full filtered list, no pagination).
 * ------------------------------------------------------------------------- */
if (($_GET['download'] ?? '') === 'csv') {
    $params = [];
    $whereClause = candidates_master_build_where($jobFairFilter, $districtFilter, $dwmsIdFilter, $nameFilter, $params);
    $sql = "SELECT DWMS_ID, Candidate_Name, Mobile_Number, Job_Fair_No, Job_Fair_Date,
        Candidate_District, SDPK_District, SDPK, Candidate_Jobstation, Candidate_Localbody,
        Job_Id, Job_Title_Name
        FROM job_fair_result $whereClause
        ORDER BY Job_Fair_No DESC, Candidate_Name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $filename = 'candidates_master_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'DWMS_ID', 'Candidate_Name', 'Mobile_Number',
        'Job_Fair_No', 'Job_Fair_Date',
        'Candidate_District', 'SDPK_District', 'SDPK',
        'Candidate_Jobstation', 'Candidate_Localbody',
        'Job_Id', 'Job_Title_Name',
    ]);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['DWMS_ID'] ?? ''),
            (string) ($r['Candidate_Name'] ?? ''),
            (string) ($r['Mobile_Number'] ?? ''),
            (string) ($r['Job_Fair_No'] ?? ''),
            (string) ($r['Job_Fair_Date'] ?? ''),
            (string) ($r['Candidate_District'] ?? ''),
            (string) ($r['SDPK_District'] ?? ''),
            (string) ($r['SDPK'] ?? ''),
            (string) ($r['Candidate_Jobstation'] ?? ''),
            (string) ($r['Candidate_Localbody'] ?? ''),
            (string) ($r['Job_Id'] ?? ''),
            (string) ($r['Job_Title_Name'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

/* ------------------------------------------------------------------------- *
 * Listing query (filtered + paginated) and count.
 * ------------------------------------------------------------------------- */
$params = [];
$whereClause = candidates_master_build_where($jobFairFilter, $districtFilter, $dwmsIdFilter, $nameFilter, $params);
$countStmt = db()->prepare("SELECT COUNT(*) FROM job_fair_result $whereClause");
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max((int) ceil($totalRecords / $perPage), 1);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT id, DWMS_ID, Candidate_Name, Mobile_Number, Job_Fair_No,
        Candidate_District, SDPK_District, SDPK, Candidate_Jobstation, Candidate_Localbody,
        Job_Id, Job_Title_Name
    FROM job_fair_result
    $whereClause
    ORDER BY Job_Fair_No DESC, Candidate_Name ASC
    LIMIT ? OFFSET ?";
$listStmt = db()->prepare($sql);
$listStmt->execute([...$params, $perPage, $offset]);
$rows = $listStmt->fetchAll();

$jobFairOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT Job_Fair_No AS value FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND TRIM(Job_Fair_No) <> '' ORDER BY value DESC")->fetchAll()
);
$districtOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT value FROM (
        SELECT Candidate_District AS value FROM job_fair_result WHERE Candidate_District IS NOT NULL AND TRIM(Candidate_District) <> ''
        UNION
        SELECT SDPK_District       AS value FROM job_fair_result WHERE SDPK_District IS NOT NULL AND TRIM(SDPK_District) <> ''
    ) d ORDER BY value ASC")->fetchAll()
);

$downloadUrl = '/candidates_master.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'job_fair' => $jobFairFilter,
    'district' => $districtFilter,
    'dwms_id' => $dwmsIdFilter,
    'name' => $nameFilter,
], static fn($v): bool => $v !== ''));

$baseParams = $_GET;
unset($baseParams['page']);

render_header('Candidates Master', ['main_container_class' => 'container-fluid']);
render_page_header('Candidates Master', [
    'icon' => 'bi-people',
    'subtitle' => 'Manage candidate-level details. Composite business key: Job Fair No + Job ID + DWMS ID.',
    'actions' => '<button class="btn btn-primary me-1" data-bs-toggle="modal" data-bs-target="#bulkUpdateModal"><i class="bi bi-upload me-1"></i>Bulk Update CSV</button>'
        . '<a class="btn btn-light" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?> d-flex align-items-start gap-2">
        <i class="bi bi-<?= $flashType === 'success' ? 'check-circle-fill' : ($flashType === 'warning' ? 'exclamation-triangle-fill' : 'exclamation-circle-fill') ?> mt-1"></i>
        <span><?= esc($flashMessage) ?></span>
    </div>
<?php endif; ?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="job_fair" class="form-label">Job Fair</label>
                <select class="form-select" id="job_fair" name="job_fair">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairOptions as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $jobFairFilter === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="district" class="form-label">District <span class="small text-muted">(Candidate or SDPK)</span></label>
                <select class="form-select" id="district" name="district">
                    <option value="">All Districts</option>
                    <?php foreach ($districtOptions as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= $districtFilter === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="dwms_id" class="form-label">DWMS ID</label>
                <input type="text" class="form-control" id="dwms_id" name="dwms_id" value="<?= esc($dwmsIdFilter) ?>" placeholder="Search DWMS ID">
            </div>
            <div class="col-md-2">
                <label for="name" class="form-label">Name</label>
                <input type="text" class="form-control" id="name" name="name" value="<?= esc($nameFilter) ?>" placeholder="Search name">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a class="btn btn-light" href="/candidates_master.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/candidates_master.php', $baseParams, 'Candidates master pagination'); ?>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people text-primary me-1"></i>Candidates</span>
        <span class="status-chip status-info"><?= number_format($totalRecords) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>DWMS ID</th>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Job Fair No</th>
                    <th>District<br><span class="small text-muted">SDPK / Personal</span></th>
                    <th>SDPK Center</th>
                    <th>Job Station</th>
                    <th>Local Body</th>
                    <th>Job Title<br><span class="small text-muted">Job ID</span></th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i>No candidate records match the selected filters.</div></td></tr>
                <?php endif; ?>
                <?php $idx = $offset + 1; foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><?= esc((string) ($row['DWMS_ID'] ?? '')) ?></td>
                        <td class="fw-semibold"><?= esc((string) ($row['Candidate_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Mobile_Number'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Job_Fair_No'] ?? '')) ?></td>
                        <td>
                            <div><span class="small text-muted">SDPK:</span> <?= esc((string) ($row['SDPK_District'] ?? '')) ?></div>
                            <div><span class="small text-muted">Personal:</span> <?= esc((string) ($row['Candidate_District'] ?? '')) ?></div>
                        </td>
                        <td><?= esc((string) ($row['SDPK'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Candidate_Jobstation'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Candidate_Localbody'] ?? '')) ?></td>
                        <td>
                            <div><?= esc((string) ($row['Job_Title_Name'] ?? '')) ?></div>
                            <div class="small text-muted"><?= esc((string) ($row['Job_Id'] ?? '')) ?></div>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/manage_candidate.php?candidate_id=<?= (int) $row['id'] ?>"><i class="bi bi-pencil-square"></i> Manage</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/candidates_master.php', $baseParams, 'Candidates master pagination'); ?>

<!-- Bulk update modal -->
<div class="modal fade" id="bulkUpdateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="bulk_update">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-1"></i>Bulk Update &mdash; Job Station &amp; Local Body</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        Upload a CSV with the following columns (header row required):
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <span class="status-chip status-neutral">DWMS_ID</span>
                            <span class="status-chip status-neutral">Job_Id</span>
                            <span class="status-chip status-neutral">Job_Fair_No</span>
                            <span class="status-chip status-neutral">Candidate_Jobstation</span>
                            <span class="status-chip status-neutral">Candidate_Localbody</span>
                        </div>
                        <div class="small text-muted mt-2">
                            Each row updates <code>Candidate_Jobstation</code> and <code>Candidate_Localbody</code>
                            on every <code>job_fair_result</code> whose
                            <strong>Job Fair No + Job ID + DWMS ID</strong> matches the row. Empty
                            Job Station / Local Body values are stored as NULL. Common header
                            spellings are accepted (<em>DWMS ID</em>, <em>Job Fair Number</em>,
                            <em>Job Station</em>, <em>Local Body</em>).
                        </div>
                    </div>
                    <div>
                        <label for="csv_file" class="form-label">CSV file</label>
                        <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-arrow-up me-1"></i>Upload &amp; Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php render_footer(); ?>
