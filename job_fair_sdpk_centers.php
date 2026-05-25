<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

function fetch_sdpk_district_options(): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM(SDPK_District), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";
    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function fetch_sdpk_centers(string $districtFilter): array
{
    $conditions = [];
    $params = [];

    if ($districtFilter !== '') {
        if ($districtFilter === 'Unknown') {
            $conditions[] = "(SDPK_District IS NULL OR TRIM(SDPK_District) = '')";
        } else {
            $conditions[] = "TRIM(COALESCE(SDPK_District, '')) = ?";
            $params[] = $districtFilter;
        }
    }

    $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(SDPK_District), ''), 'Unknown') AS sdpk_district,
            COALESCE(NULLIF(TRIM(SDPK), ''), 'Unknown') AS sdpk_center,
            COUNT(*) AS candidate_count,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS selected_count,
            SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold') THEN 1 ELSE 0 END) AS shortlisted_onhold_count,
            SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined_count
        FROM job_fair_result
        $whereClause
        GROUP BY sdpk_district, sdpk_center
        ORDER BY sdpk_district ASC, sdpk_center ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$districtFilter = trim((string) ($_GET['sdpk_district'] ?? ''));

if (($_GET['download'] ?? '') === 'csv') {
    $rows = fetch_sdpk_centers($districtFilter);
    $filename = 'sdpk_centers_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['SDPK District', 'SDPK Center', 'Candidates', 'Selected', 'Shortlisted/Onhold', 'Joined']);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) $row['sdpk_district'],
            (string) $row['sdpk_center'],
            (int) $row['candidate_count'],
            (int) $row['selected_count'],
            (int) $row['shortlisted_onhold_count'],
            (int) $row['joined_count'],
        ]);
    }
    fclose($out);
    exit;
}

$districtOptions = fetch_sdpk_district_options();
$rows = fetch_sdpk_centers($districtFilter);

$downloadUrl = '/job_fair_sdpk_centers.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'sdpk_district' => $districtFilter,
], static fn($v): bool => $v !== ''));

render_header('SDPK Centers', ['main_container_class' => 'container-fluid']);
render_page_header('SDPK Centers', [
    'icon' => 'bi-buildings',
    'subtitle' => 'SDPK centers grouped by SDPK District, with candidate counts.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="sdpk_district" class="form-label">SDPK District</label>
                <select class="form-select" id="sdpk_district" name="sdpk_district">
                    <option value="">All SDPK Districts</option>
                    <?php foreach ($districtOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $districtFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a class="btn btn-light" href="/job_fair_sdpk_centers.php">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-buildings text-primary me-1"></i>SDPK Centers</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> rows</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>SDPK District</th>
                    <th>SDPK Center</th>
                    <th class="text-end">Candidates</th>
                    <th class="text-end">Selected</th>
                    <th class="text-end">SL / OH</th>
                    <th class="text-end">Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No SDPK centers found.</div></td></tr>
                <?php endif; ?>
                <?php
                    $totals = ['candidate_count' => 0, 'selected_count' => 0, 'shortlisted_onhold_count' => 0, 'joined_count' => 0];
                    $idx = 1;
                ?>
                <?php foreach ($rows as $row): ?>
                    <?php foreach (array_keys($totals) as $k) { $totals[$k] += (int) ($row[$k] ?? 0); } ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $row['sdpk_district']) ?></td>
                        <td><?= esc((string) $row['sdpk_center']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['candidate_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['selected_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['shortlisted_onhold_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $row['joined_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="3">Total</td>
                        <td class="text-end"><?= number_format($totals['candidate_count']) ?></td>
                        <td class="text-end"><?= number_format($totals['selected_count']) ?></td>
                        <td class="text-end"><?= number_format($totals['shortlisted_onhold_count']) ?></td>
                        <td class="text-end"><?= number_format($totals['joined_count']) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
