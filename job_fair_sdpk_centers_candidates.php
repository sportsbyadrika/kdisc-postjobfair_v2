<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

/**
 * Lists candidates that share an (SDPK_District, SDPK) signature - the
 * drill-down from the SDPK Centers master.
 */
function sdpk_row_conditions(string $sdpkDistrict, string $sdpkCenter, array &$params): array
{
    $conditions = [];

    if ($sdpkDistrict === '' || $sdpkDistrict === 'Unknown') {
        $conditions[] = "(SDPK_District IS NULL OR TRIM(SDPK_District) = '')";
    } else {
        $conditions[] = "TRIM(COALESCE(SDPK_District, '')) = ?";
        $params[] = $sdpkDistrict;
    }

    if ($sdpkCenter === '' || $sdpkCenter === 'Unknown') {
        $conditions[] = "(SDPK IS NULL OR TRIM(SDPK) = '')";
    } else {
        $conditions[] = "TRIM(COALESCE(SDPK, '')) = ?";
        $params[] = $sdpkCenter;
    }

    return $conditions;
}

$sdpkDistrict = trim((string) ($_GET['sdpk_district'] ?? ''));
$sdpkCenter = trim((string) ($_GET['sdpk_center'] ?? ''));

$params = [];
$conditions = sdpk_row_conditions($sdpkDistrict, $sdpkCenter, $params);
$whereClause = 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT id, DWMS_ID, Candidate_Name, Mobile_Number, Candidate_District,
        SDPK_District, SDPK,
        Employer_ID, Employer_Name, Job_Id, Job_Title_Name,
        Job_Fair_No, Job_Fair_Date,
        Selection_Status, Shortlist_Candidate_Status, Candidate_Joined_Status
    FROM job_fair_result
    $whereClause
    ORDER BY Job_Fair_Date DESC, Candidate_Name ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (($_GET['download'] ?? '') === 'csv') {
    $filename = 'sdpk_center_candidates_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Sl No', 'DWMS ID', 'Candidate Name', 'Mobile', 'Candidate District',
        'SDPK District', 'SDPK Center',
        'Employer Code', 'Employer Name', 'Job ID', 'Job Title',
        'Job Fair No', 'Job Fair Date',
        'Selection Status', 'Final (Shortlist) Status', 'Candidate Joined Status',
    ]);
    $i = 1;
    foreach ($rows as $row) {
        fputcsv($out, [
            $i++,
            (string) ($row['DWMS_ID'] ?? ''),
            (string) ($row['Candidate_Name'] ?? ''),
            (string) ($row['Mobile_Number'] ?? ''),
            (string) ($row['Candidate_District'] ?? ''),
            (string) ($row['SDPK_District'] ?? ''),
            (string) ($row['SDPK'] ?? ''),
            (string) ($row['Employer_ID'] ?? ''),
            (string) ($row['Employer_Name'] ?? ''),
            (string) ($row['Job_Id'] ?? ''),
            (string) ($row['Job_Title_Name'] ?? ''),
            (string) ($row['Job_Fair_No'] ?? ''),
            (string) ($row['Job_Fair_Date'] ?? ''),
            (string) ($row['Selection_Status'] ?? ''),
            (string) ($row['Shortlist_Candidate_Status'] ?? ''),
            (string) ($row['Candidate_Joined_Status'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$downloadUrl = '/job_fair_sdpk_centers_candidates.php?' . http_build_query([
    'download' => 'csv',
    'sdpk_district' => $sdpkDistrict,
    'sdpk_center' => $sdpkCenter,
]);

render_header('Candidates · SDPK Center', ['main_container_class' => 'container-fluid']);
render_page_header('Candidates for SDPK Center', [
    'icon' => 'bi-people',
    'subtitle' => 'All candidate records that attended this SDPK Center.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>'
               . ' <a class="btn btn-light ms-1" href="/job_fair_sdpk_centers.php"><i class="bi bi-arrow-left me-1"></i>Back to SDPK Centers</a>',
]);
?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="form-label mb-0 me-1">Mapping:</span>
        <span class="status-chip status-neutral"><strong>SDPK District:</strong>&nbsp;<?= esc($sdpkDistrict !== '' ? $sdpkDistrict : 'Unknown') ?></span>
        <span class="status-chip status-neutral"><strong>SDPK Center:</strong>&nbsp;<?= esc($sdpkCenter !== '' ? $sdpkCenter : 'Unknown') ?></span>
        <span class="status-chip status-info ms-auto"><?= number_format(count($rows)) ?> records</span>
    </div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>DWMS ID</th>
                    <th>Candidate Name</th>
                    <th>Candidate District</th>
                    <th>Employer Code</th>
                    <th>Employer Name</th>
                    <th>Job ID</th>
                    <th>Job Title</th>
                    <th>Job Fair</th>
                    <th>Selection Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i>No candidate records match this SDPK Center.</div></td></tr>
                <?php endif; ?>
                <?php $idx = 1; foreach ($rows as $row): ?>
                    <?php
                        $selKey = strtolower(str_replace(' ', '', (string) ($row['Selection_Status'] ?? '')));
                        $isShortlistOrOnhold = in_array($selKey, ['shortlisted', 'onhold'], true);
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><?= esc((string) ($row['DWMS_ID'] ?? '')) ?></td>
                        <td class="fw-semibold"><?= esc((string) ($row['Candidate_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Candidate_District'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Employer_ID'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Employer_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Job_Id'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Job_Title_Name'] ?? '')) ?></td>
                        <td>
                            <div><?= esc((string) ($row['Job_Fair_No'] ?? '')) ?></div>
                            <div class="small text-muted"><?= $row['Job_Fair_Date'] ? esc(date('d M Y', strtotime((string) $row['Job_Fair_Date']))) : '' ?></div>
                        </td>
                        <td>
                            <?= render_status_chip($row['Selection_Status']) ?>
                            <?php if ($isShortlistOrOnhold && !empty($row['Shortlist_Candidate_Status'])): ?>
                                <div class="mt-1"><?= render_status_chip($row['Shortlist_Candidate_Status'], 'Final:') ?></div>
                            <?php endif; ?>
                        </td>
                        <td><a class="btn btn-sm btn-outline-primary" href="/manage_candidate.php?candidate_id=<?= (int) $row['id'] ?>"><i class="bi bi-pencil-square"></i> Manage</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
