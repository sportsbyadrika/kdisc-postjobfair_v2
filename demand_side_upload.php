<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();
demand_side_bootstrap();

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);

$type = ($_GET['type'] ?? $_POST['type'] ?? '') === 'jobs' ? 'jobs' : 'employer';
$flashMessage = null;
$flashType = 'success';
$previewRows = [];
$previewSkips = [];

$aliasSet = $type === 'employer'
    ? demand_employer_upload_columns()
    : demand_employer_job_upload_columns();

function demand_map_headers(array $header, array $aliasSet): array
{
    $normalized = array_map('demand_normalise_header', $header);
    $map = [];
    foreach ($aliasSet as $canonical => $accepted) {
        $found = -1;
        foreach ($normalized as $i => $h) {
            if (in_array($h, $accepted, true)) { $found = $i; break; }
        }
        $map[$canonical] = $found;
    }
    return $map;
}

/* ------------------------------------------------------------------------- *
 * Step 2: Commit the import from a previously staged file (session-based).
 * ------------------------------------------------------------------------- */
if (is_post() && ($_POST['action'] ?? '') === 'commit') {
    session_status() === PHP_SESSION_ACTIVE || session_start();
    $stagedPath = (string) ($_SESSION['demand_upload_path'] ?? '');
    $stagedType = (string) ($_SESSION['demand_upload_type'] ?? '');
    if (!$stagedPath || !is_readable($stagedPath) || $stagedType !== $type) {
        $flashMessage = 'Upload session expired. Please choose the CSV again.';
        $flashType = 'danger';
    } else {
        $fh = fopen($stagedPath, 'r');
        $header = $fh ? fgetcsv($fh) : null;
        if (!$fh || !$header) {
            $flashMessage = 'Could not read the staged CSV.';
            $flashType = 'danger';
        } else {
            $colIndex = demand_map_headers($header, $aliasSet);
            $processed = 0; $inserted = 0; $updated = 0; $skipped = 0; $skipReasons = [];

            if ($type === 'employer') {
                $upsert = db()->prepare("INSERT INTO demand_employers (
                        clustered_employer_id, employer_id, created_datetime, employer_name, website, company_address,
                        job_agency_id, jobagency, jobfair_flag, vk_flag, clusteremployername,
                        nic_section_code, nic_section_name, nic_division_code, nic_division_name,
                        nic_group_code, nic_group_name, nic_class_code, nic_class_name,
                        nic_sub_class_code, nic_sub_class_name, reason_for_classification, type_of_company,
                        final_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        clustered_employer_id = VALUES(clustered_employer_id),
                        created_datetime = VALUES(created_datetime),
                        employer_name = VALUES(employer_name),
                        website = VALUES(website),
                        company_address = VALUES(company_address),
                        job_agency_id = VALUES(job_agency_id),
                        jobagency = VALUES(jobagency),
                        jobfair_flag = VALUES(jobfair_flag),
                        vk_flag = VALUES(vk_flag),
                        clusteremployername = VALUES(clusteremployername),
                        nic_section_code = VALUES(nic_section_code),
                        nic_section_name = VALUES(nic_section_name),
                        nic_division_code = VALUES(nic_division_code),
                        nic_division_name = VALUES(nic_division_name),
                        nic_group_code = VALUES(nic_group_code),
                        nic_group_name = VALUES(nic_group_name),
                        nic_class_code = VALUES(nic_class_code),
                        nic_class_name = VALUES(nic_class_name),
                        nic_sub_class_code = VALUES(nic_sub_class_code),
                        nic_sub_class_name = VALUES(nic_sub_class_name),
                        reason_for_classification = VALUES(reason_for_classification),
                        type_of_company = VALUES(type_of_company),
                        final_status = VALUES(final_status)");
                while (($row = fgetcsv($fh)) !== false) {
                    $processed++;
                    $get = static fn(string $c): string => $colIndex[$c] < 0 ? '' : trim((string) ($row[$colIndex[$c]] ?? ''));
                    $eid = demand_parse_int($get('employer_id'));
                    if ($eid === null || $eid <= 0) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: missing/invalid employer_id";
                        continue;
                    }
                    $upsert->execute([
                        demand_parse_int($get('clustered_employer_id')),
                        $eid,
                        demand_parse_datetime($get('created_datetime')),
                        $get('employer_name') ?: null,
                        $get('website') ?: null,
                        $get('company_address') ?: null,
                        $get('job_agency_id') ?: null,
                        $get('jobagency') ?: null,
                        $get('jobfair_flag') ?: null,
                        $get('vk_flag') ?: null,
                        $get('clusteremployername') ?: null,
                        $get('nic_section_code') ?: null,
                        $get('nic_section_name') ?: null,
                        $get('nic_division_code') ?: null,
                        $get('nic_division_name') ?: null,
                        $get('nic_group_code') ?: null,
                        $get('nic_group_name') ?: null,
                        $get('nic_class_code') ?: null,
                        $get('nic_class_name') ?: null,
                        $get('nic_sub_class_code') ?: null,
                        $get('nic_sub_class_name') ?: null,
                        $get('reason_for_classification') ?: null,
                        $get('type_of_company') ?: null,
                        $get('final_status') ?: null,
                    ]);
                    // affectedRows: 1 = insert, 2 = update, 0 = no change
                    $affected = $upsert->affectedRows();
                    if ($affected === 1) $inserted++;
                    elseif ($affected >= 2) $updated++;
                }
            } else {
                // Employer Jobs. emp_id is a foreign key to
                // demand_employers.employer_id — pre-load the valid set once
                // so we can reject orphan rows with a clear message even when
                // the DB-level FK isn't in place yet.
                $validEmpIds = [];
                foreach (db()->query('SELECT employer_id FROM demand_employers')->fetchAll() as $er) {
                    $validEmpIds[(int) $er['employer_id']] = true;
                }
                $upsert = db()->prepare("INSERT INTO demand_employer_jobs (
                        job_id, jobtitle, emp_id, emp_name, open_positions,
                        salary_type, salary_slab, qualificationcategory, vk_flag
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        jobtitle = VALUES(jobtitle),
                        emp_id = VALUES(emp_id),
                        emp_name = VALUES(emp_name),
                        open_positions = VALUES(open_positions),
                        salary_type = VALUES(salary_type),
                        salary_slab = VALUES(salary_slab),
                        qualificationcategory = VALUES(qualificationcategory),
                        vk_flag = VALUES(vk_flag)");
                while (($row = fgetcsv($fh)) !== false) {
                    $processed++;
                    $get = static fn(string $c): string => $colIndex[$c] < 0 ? '' : trim((string) ($row[$colIndex[$c]] ?? ''));
                    $jid = demand_parse_int($get('job_id'));
                    $empId = demand_parse_int($get('emp_id'));
                    if ($jid === null || $jid <= 0 || $empId === null || $empId <= 0) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: missing/invalid job_id or emp_id";
                        continue;
                    }
                    if (!isset($validEmpIds[$empId])) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: emp_id $empId not found in Employers (upload Employer first)";
                        continue;
                    }
                    $upsert->execute([
                        $jid,
                        $get('jobtitle') ?: null,
                        $empId,
                        $get('emp_name') ?: null,
                        demand_parse_int($get('open_positions')),
                        $get('salary_type') ?: null,
                        $get('salary_slab') ?: null,
                        $get('qualificationcategory') ?: null,
                        $get('vk_flag') ?: null,
                    ]);
                    $affected = $upsert->affectedRows();
                    if ($affected === 1) $inserted++;
                    elseif ($affected >= 2) $updated++;
                }
            }
            fclose($fh);
            @unlink($stagedPath);
            unset($_SESSION['demand_upload_path'], $_SESSION['demand_upload_type']);
            $flashMessage = sprintf(
                'Import complete: %d row(s) processed, %d inserted, %d updated, %d skipped.%s',
                $processed, $inserted, $updated, $skipped,
                $skipReasons === [] ? '' : ' First skips: ' . implode('; ', $skipReasons)
            );
            $flashType = ($skipped > 0 && ($inserted + $updated) === 0) ? 'warning' : 'success';
        }
    }
}

/* ------------------------------------------------------------------------- *
 * Step 1: Upload file, parse header, show preview & mapping.
 * ------------------------------------------------------------------------- */
$previewMap = [];
$stagedForCommit = false;
if (is_post() && ($_POST['action'] ?? '') === 'preview') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $flashMessage = 'No CSV file uploaded or upload failed.';
        $flashType = 'danger';
    } else {
        session_status() === PHP_SESSION_ACTIVE || session_start();
        $stagedDir = sys_get_temp_dir();
        $stagedPath = $stagedDir . '/demand_upload_' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $stagedPath)) {
            $flashMessage = 'Could not stage the uploaded CSV.';
            $flashType = 'danger';
        } else {
            $_SESSION['demand_upload_path'] = $stagedPath;
            $_SESSION['demand_upload_type'] = $type;
            $fh = fopen($stagedPath, 'r');
            $header = $fh ? fgetcsv($fh) : null;
            if (!$header) {
                $flashMessage = 'CSV appears to be empty.';
                $flashType = 'danger';
            } else {
                $previewMap = demand_map_headers($header, $aliasSet);
                $required = $type === 'employer'
                    ? demand_employer_upload_required()
                    : demand_employer_job_upload_required();
                $missing = array_values(array_filter(
                    $required,
                    static fn(string $col): bool => ($previewMap[$col] ?? -1) < 0
                ));
                if ($missing !== []) {
                    $flashMessage = 'Required column(s) missing in CSV: ' . implode(', ', $missing);
                    $flashType = 'danger';
                    unset($_SESSION['demand_upload_path'], $_SESSION['demand_upload_type']);
                    @unlink($stagedPath);
                } else {
                    // Read up to 5 rows for preview.
                    $count = 0;
                    while ($count < 5 && ($row = fgetcsv($fh)) !== false) {
                        $previewRows[] = $row;
                        $count++;
                    }
                    // Count total lines (approx)
                    $totalRows = $count;
                    while (fgetcsv($fh) !== false) $totalRows++;
                    $stagedForCommit = true;
                    $flashMessage = 'Preview ready. ' . $totalRows . ' data row(s) detected. Review the first ' . count($previewRows) . ' below and click Confirm to import.';
                }
            }
            if ($fh) fclose($fh);
        }
    }
}

render_header('Demand Side · Upload Data', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Upload Data', [
    'icon' => 'bi-upload',
    'subtitle' => 'Two-step CSV wizard: preview headers and rows, then confirm to import. Existing rows are updated by their business key (Employer ID / Job ID); manually edited fields are preserved.',
    'actions' => '<a class="btn btn-light" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $type === 'employer' ? 'active' : '' ?>" href="/demand_side_upload.php?type=employer"><i class="bi bi-building me-1"></i>Upload Employer</a></li>
    <li class="nav-item"><a class="nav-link <?= $type === 'jobs' ? 'active' : '' ?>" href="/demand_side_upload.php?type=jobs"><i class="bi bi-briefcase me-1"></i>Upload Employer Jobs</a></li>
</ul>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3">
            <i class="bi bi-1-circle text-primary me-1"></i>Step 1 · Upload &amp; Preview
        </h2>
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="type" value="<?= esc($type) ?>">
            <div class="col-md-6">
                <label class="form-label">CSV file (<?= $type === 'employer' ? 'Employer' : 'Employer Jobs' ?>)</label>
                <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary"><i class="bi bi-eye me-1"></i>Preview</button>
            </div>
        </form>
        <?php
            $requiredCols = $type === 'employer'
                ? demand_employer_upload_required()
                : demand_employer_job_upload_required();
            $optionalCols = array_values(array_diff(array_keys($aliasSet), $requiredCols));
        ?>
        <div class="mt-3 small text-muted">
            Accepted columns (case / underscore insensitive) — <strong>required</strong> ones are highlighted, others are optional:
            <div class="d-flex flex-wrap gap-1 mt-1">
                <?php foreach ($requiredCols as $col): ?>
                    <span class="status-chip status-danger"><?= esc($col) ?> *</span>
                <?php endforeach; ?>
                <?php foreach ($optionalCols as $col): ?>
                    <span class="status-chip status-neutral"><?= esc($col) ?></span>
                <?php endforeach; ?>
            </div>
            <?php if ($type === 'jobs'): ?>
                <div class="mt-2"><em>Note:</em> <code>emp_id</code> is a foreign key to <code>Employers.employer_id</code>&nbsp;— upload Employer first, then Employer Jobs. Rows whose <code>emp_id</code> isn't found in Employers are skipped and reported.</div>
                <div class="mt-1"><em>Also:</em> <code>status</code>, <code>remarks</code>, <code>remarks_group</code>, <code>posted_on</code>, <code>posted_by</code>, <code>expired_date</code> and <code>corrected_open_position</code> are managed inside the app on the Edit screen, so they are not part of the upload.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($stagedForCommit && $previewRows !== []): ?>
    <?php
        // Fetch header for preview render
        $fh2 = fopen($_SESSION['demand_upload_path'], 'r');
        $headerCells = $fh2 ? fgetcsv($fh2) : [];
        if ($fh2) fclose($fh2);
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">
                <i class="bi bi-2-circle text-primary me-1"></i>Step 2 · Preview &amp; Confirm
            </h2>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($headerCells as $h): ?>
                                <th class="small"><?= esc((string) $h) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $r): ?>
                            <tr>
                                <?php foreach ($headerCells as $i => $_): ?>
                                    <td class="small"><?= esc((string) ($r[$i] ?? '')) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="commit">
                <input type="hidden" name="type" value="<?= esc($type) ?>">
                <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Confirm import</button>
                <a href="/demand_side_upload.php?type=<?= esc($type) ?>" class="btn btn-light">Discard</a>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
