<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

function fetch_joined_distinct(string $column): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM($column), ''), 'Unknown') AS value
        FROM job_fair_result
        WHERE LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes'
        ORDER BY value ASC";
    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function fetch_joined_candidates(array $filters): array
{
    $conditions = ["LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes'"];
    $params = [];

    if ($filters['candidate_district'] !== '') {
        if ($filters['candidate_district'] === 'Unknown') {
            $conditions[] = "(Candidate_District IS NULL OR TRIM(Candidate_District) = '')";
        } else {
            $conditions[] = "TRIM(COALESCE(Candidate_District, '')) = ?";
            $params[] = $filters['candidate_district'];
        }
    }

    if ($filters['job_fair'] !== '') {
        if ($filters['job_fair'] === 'Unknown') {
            $conditions[] = "(Job_Fair_No IS NULL OR TRIM(Job_Fair_No) = '')";
        } else {
            $conditions[] = "TRIM(COALESCE(Job_Fair_No, '')) = ?";
            $params[] = $filters['job_fair'];
        }
    }

    $whereClause = 'WHERE ' . implode(' AND ', $conditions);

    $selectionLabel = "CASE
        WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected' THEN 'Selected'
        WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
             AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected' THEN 'Shortlist Selected'
        ELSE COALESCE(Selection_Status, '')
    END";

    $sql = "SELECT
            id,
            Job_Fair_No,
            DWMS_ID,
            Candidate_Name,
            Employer_ID,
            Employer_Name,
            Job_Id,
            Job_Title_Name,
            Candidate_District,
            Candidate_Jobstation,
            SDPK,
            $selectionLabel AS selection_label,
            Candidate_Joined_Date
        FROM job_fair_result
        $whereClause
        ORDER BY Candidate_Joined_Date DESC, Candidate_Name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$filters = [
    'candidate_district' => trim((string) ($_GET['candidate_district'] ?? '')),
    'job_fair' => trim((string) ($_GET['job_fair'] ?? '')),
];

if (($_GET['download'] ?? '') === 'csv') {
    $rows = fetch_joined_candidates($filters);
    $filename = 'joined_candidates_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Job Fair No',
        'DWMS ID',
        'Candidate Name',
        'Employer Code',
        'Employer Name',
        'Job ID',
        'Job Title',
        'Candidate District',
        'Job Station',
        'SDPK Center',
        'Selection',
        'Date of Joined',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) ($row['Job_Fair_No'] ?? ''),
            (string) ($row['DWMS_ID'] ?? ''),
            (string) ($row['Candidate_Name'] ?? ''),
            (string) ($row['Employer_ID'] ?? ''),
            (string) ($row['Employer_Name'] ?? ''),
            (string) ($row['Job_Id'] ?? ''),
            (string) ($row['Job_Title_Name'] ?? ''),
            (string) ($row['Candidate_District'] ?? ''),
            (string) ($row['Candidate_Jobstation'] ?? ''),
            (string) ($row['SDPK'] ?? ''),
            (string) ($row['selection_label'] ?? ''),
            (string) ($row['Candidate_Joined_Date'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$districtOptions = fetch_joined_distinct('Candidate_District');
$jobFairOptions = fetch_joined_distinct('Job_Fair_No');
$rows = fetch_joined_candidates($filters);

$downloadUrl = '/joined_candidates_report.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'candidate_district' => $filters['candidate_district'],
    'job_fair' => $filters['job_fair'],
], static fn($v): bool => $v !== ''));

$selectionChipClass = static function (string $label): string {
    return match ($label) {
        'Selected' => 'status-selected',
        'Shortlist Selected' => 'status-shortlisted',
        default => 'status-neutral',
    };
};

render_header('Joined Candidates Report', ['main_container_class' => 'container-fluid']);
render_page_header('Joined Candidates', [
    'icon' => 'bi-door-open-fill',
    'subtitle' => 'Candidates with Candidate Joined Status = Yes, filtered by district and job fair.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="candidate_district" class="form-label">Candidate District</label>
                <select class="form-select" id="candidate_district" name="candidate_district">
                    <option value="">All Candidate Districts</option>
                    <?php foreach ($districtOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['candidate_district'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="job_fair" class="form-label">Job Fair</label>
                <select class="form-select" id="job_fair" name="job_fair">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['job_fair'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a class="btn btn-light" href="/joined_candidates_report.php">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-door-open-fill text-primary me-1"></i>Joined Candidates</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Job Fair</th>
                    <th>DWMS ID</th>
                    <th>Candidate Name</th>
                    <th>Employer Code</th>
                    <th>Employer Name</th>
                    <th>Job ID</th>
                    <th>Job Title</th>
                    <th>Candidate District</th>
                    <th>Job Station</th>
                    <th>SDPK Center</th>
                    <th>Selection</th>
                    <th>Date of Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="13"><div class="empty-state"><i class="bi bi-inbox"></i>No joined candidates found for the selected filters.</div></td></tr>
                <?php endif; ?>
                <?php $idx = 1; foreach ($rows as $row): ?>
                    <?php $label = (string) ($row['selection_label'] ?? ''); ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><?= esc((string) ($row['Job_Fair_No'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['DWMS_ID'] ?? '')) ?></td>
                        <td class="fw-semibold"><?= esc((string) ($row['Candidate_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Employer_ID'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Employer_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Job_Id'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Job_Title_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Candidate_District'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Candidate_Jobstation'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['SDPK'] ?? '')) ?></td>
                        <td><?php if ($label !== ''): ?><span class="status-chip <?= esc($selectionChipClass($label)) ?>"><?= esc($label) ?></span><?php endif; ?></td>
                        <td><?= $row['Candidate_Joined_Date'] ? esc(date('d M Y', strtotime((string) $row['Candidate_Joined_Date']))) : '<span class="text-muted">&mdash;</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
