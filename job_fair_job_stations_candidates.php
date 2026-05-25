<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

/**
 * Lists candidates that share a (Candidate_District, Candidate_Jobstation)
 * signature - the drill-down from the Job Stations master.
 */
function jobstation_row_conditions(string $candidateDistrict, string $jobStation, array &$params): array
{
    $conditions = [];

    if ($candidateDistrict === '' || $candidateDistrict === 'Unknown') {
        $conditions[] = "(Candidate_District IS NULL OR TRIM(Candidate_District) = '')";
    } else {
        $conditions[] = "TRIM(COALESCE(Candidate_District, '')) = ?";
        $params[] = $candidateDistrict;
    }

    if ($jobStation === '' || $jobStation === 'Unknown') {
        $conditions[] = "(Candidate_Jobstation IS NULL OR TRIM(Candidate_Jobstation) = '')";
    } else {
        $conditions[] = "TRIM(COALESCE(Candidate_Jobstation, '')) = ?";
        $params[] = $jobStation;
    }

    return $conditions;
}

$candidateDistrict = trim((string) ($_GET['candidate_district'] ?? ''));
$jobStation = trim((string) ($_GET['job_station'] ?? ''));

$params = [];
$conditions = jobstation_row_conditions($candidateDistrict, $jobStation, $params);
$whereClause = 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT id, DWMS_ID, Candidate_Name, Mobile_Number, Candidate_District,
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
    $filename = 'job_station_candidates_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Sl No', 'DWMS ID', 'Candidate Name', 'Mobile', 'Candidate District',
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

$downloadUrl = '/job_fair_job_stations_candidates.php?' . http_build_query([
    'download' => 'csv',
    'candidate_district' => $candidateDistrict,
    'job_station' => $jobStation,
]);

render_header('Candidates · Job Station', ['main_container_class' => 'container-fluid']);
render_page_header('Candidates for Job Station', [
    'icon' => 'bi-people',
    'subtitle' => 'All candidate records matching this Candidate District and Job Station.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>'
               . ' <a class="btn btn-light ms-1" href="/job_fair_job_stations.php"><i class="bi bi-arrow-left me-1"></i>Back to Job Stations</a>',
]);
?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="form-label mb-0 me-1">Mapping:</span>
        <span class="status-chip status-neutral"><strong>Candidate District:</strong>&nbsp;<?= esc($candidateDistrict !== '' ? $candidateDistrict : 'Unknown') ?></span>
        <span class="status-chip status-neutral"><strong>Job Station:</strong>&nbsp;<?= esc($jobStation !== '' ? $jobStation : 'Unknown') ?></span>
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
                    <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i>No candidate records match this Job Station.</div></td></tr>
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
