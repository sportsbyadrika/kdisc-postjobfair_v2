<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

function fetch_candidate_district_options(): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM(Candidate_District), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";
    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function fetch_job_station_job_fair_options(): array
{
    $sql = "SELECT DISTINCT Job_Fair_No AS value
        FROM job_fair_result
        WHERE Job_Fair_No IS NOT NULL AND TRIM(Job_Fair_No) <> ''
        ORDER BY value ASC";
    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function fetch_job_stations(string $districtFilter, string $jobFairFilter): array
{
    $conditions = [];
    $params = [];

    if ($districtFilter !== '') {
        if ($districtFilter === 'Unknown') {
            $conditions[] = "(Candidate_District IS NULL OR TRIM(Candidate_District) = '')";
        } else {
            $conditions[] = "TRIM(COALESCE(Candidate_District, '')) = ?";
            $params[] = $districtFilter;
        }
    }

    if ($jobFairFilter !== '') {
        $conditions[] = "TRIM(COALESCE(Job_Fair_No, '')) = ?";
        $params[] = $jobFairFilter;
    }

    $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Candidate_District), ''), 'Unknown') AS candidate_district,
            COALESCE(NULLIF(TRIM(Candidate_Jobstation), ''), 'Unknown') AS job_station,
            COUNT(*) AS candidate_count,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS selected_count,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold') THEN 1 ELSE 0 END) AS shortlisted_onhold_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_count
        FROM job_fair_result
        $whereClause
        GROUP BY candidate_district, job_station
        ORDER BY candidate_district ASC, job_station ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$districtFilter = trim((string) ($_GET['candidate_district'] ?? ''));
$jobFairFilter = trim((string) ($_GET['job_fair'] ?? ''));

if (($_GET['download'] ?? '') === 'csv') {
    $rows = fetch_job_stations($districtFilter, $jobFairFilter);
    $filename = 'job_stations_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Candidate District', 'Job Station', 'Candidates', 'Selected', 'Shortlisted/Onhold', 'Joined']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) $row['candidate_district'],
            (string) $row['job_station'],
            (int) $row['candidate_count'],
            (int) $row['selected_count'],
            (int) $row['shortlisted_onhold_count'],
            (int) $row['joined_count'],
        ]);
    }
    fclose($out);
    exit;
}

$districtOptions = fetch_candidate_district_options();
$jobFairOptions = fetch_job_station_job_fair_options();
$rows = fetch_job_stations($districtFilter, $jobFairFilter);

$downloadUrl = '/job_fair_job_stations.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'candidate_district' => $districtFilter,
    'job_fair' => $jobFairFilter,
], static fn($v): bool => $v !== ''));

render_header('Job Stations', ['main_container_class' => 'container-fluid']);
render_page_header('Job Stations', [
    'icon' => 'bi-geo-alt-fill',
    'subtitle' => 'Job stations grouped by Candidate District, with candidate counts.',
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
                        <option value="<?= esc($option) ?>" <?= $districtFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="job_fair" class="form-label">Job Fair</label>
                <select class="form-select" id="job_fair" name="job_fair">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $jobFairFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a class="btn btn-light" href="/job_fair_job_stations.php">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-geo-alt-fill text-primary me-1"></i>Job Stations</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> rows</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Candidate District</th>
                    <th>Job Station</th>
                    <th class="text-end">Candidates</th>
                    <th class="text-end">Selected</th>
                    <th class="text-end">SL / OH</th>
                    <th class="text-end">Joined</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No job stations found.</div></td></tr>
                <?php endif; ?>
                <?php
                    $totals = ['candidate_count' => 0, 'selected_count' => 0, 'shortlisted_onhold_count' => 0, 'joined_count' => 0];
                    $idx = 1;
                ?>
                <?php foreach ($rows as $row): ?>
                    <?php
                        foreach (array_keys($totals) as $k) { $totals[$k] += (int) ($row[$k] ?? 0); }
                        $viewUrl = '/job_fair_job_stations_candidates.php?' . http_build_query([
                            'candidate_district' => (string) $row['candidate_district'],
                            'job_station' => (string) $row['job_station'],
                        ]);
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $row['candidate_district']) ?></td>
                        <td><?= esc((string) $row['job_station']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['candidate_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['selected_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['shortlisted_onhold_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['joined_count']) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?= esc($viewUrl) ?>"><i class="bi bi-people"></i> View Candidates</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="3">Total</td>
                        <td class="text-end"><?= number_format($totals['candidate_count']) ?></td>
                        <td class="text-end"><?= number_format($totals['selected_count']) ?></td>
                        <td class="text-end"><?= number_format($totals['shortlisted_onhold_count']) ?></td>
                        <td class="text-end"><?= number_format($totals['joined_count']) ?></td>
                        <td></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
