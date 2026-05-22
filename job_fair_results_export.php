<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

require_admin();

$jobFairFilter = trim((string) ($_GET['job_fair_no'] ?? ''));
$statusFilter = trim((string) ($_GET['selection_status'] ?? ''));
$aggregatorFilter = trim((string) ($_GET['aggregator'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));

$jobFairs = db()->query("SELECT DISTINCT Job_Fair_No FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND Job_Fair_No <> '' ORDER BY Job_Fair_No")->fetchAll();
$statuses = db()->query("SELECT DISTINCT Selection_Status FROM job_fair_result WHERE Selection_Status IS NOT NULL AND Selection_Status <> '' ORDER BY Selection_Status")->fetchAll();
$aggregators = db()->query("SELECT DISTINCT Aggregator FROM job_fair_result WHERE Aggregator IS NOT NULL AND Aggregator <> '' ORDER BY Aggregator")->fetchAll();
$categories = db()->query("SELECT DISTINCT Category FROM job_fair_result WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category")->fetchAll();

$whereSql = ' WHERE 1=1';
$params = [];
if ($jobFairFilter !== '') {
    $whereSql .= ' AND Job_Fair_No = ?';
    $params[] = $jobFairFilter;
}
if ($statusFilter !== '') {
    $whereSql .= ' AND Selection_Status = ?';
    $params[] = $statusFilter;
}
if ($aggregatorFilter !== '') {
    $whereSql .= ' AND Aggregator = ?';
    $params[] = $aggregatorFilter;
}
if ($categoryFilter !== '') {
    $whereSql .= ' AND Category = ?';
    $params[] = $categoryFilter;
}

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $columnStmt = db()->query('SHOW COLUMNS FROM job_fair_result');
    $columnRows = $columnStmt->fetchAll();
    $columns = array_map(static fn(array $column): string => $column['Field'], $columnRows);

    $dataSql = 'SELECT * FROM job_fair_result' . $whereSql . ' ORDER BY id DESC';
    $dataStmt = db()->prepare($dataSql);
    $dataStmt->execute($params);

    $filename = 'job_fair_results_' . date('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        echo 'Unable to open CSV stream.';
        exit;
    }

    fputcsv($output, $columns);

    while ($row = $dataStmt->fetch()) {
        $csvRow = [];
        foreach ($columns as $column) {
            $csvRow[] = $row[$column];
        }
        fputcsv($output, $csvRow);
    }

    fclose($output);
    exit;
}

$countStmt = db()->prepare('SELECT COUNT(*) AS total_rows FROM job_fair_result' . $whereSql);
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetch()['total_rows'] ?? 0);

render_header('Export job fair result CSV');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">Export Job Fair Results CSV</h1>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Job Fair</label>
                <select class="form-select" name="job_fair_no">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairs as $jobFair): ?>
                        <option value="<?= esc($jobFair['Job_Fair_No']) ?>" <?= $jobFairFilter === $jobFair['Job_Fair_No'] ? 'selected' : '' ?>><?= esc($jobFair['Job_Fair_No']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select class="form-select" name="selection_status">
                    <option value="">All Statuses</option>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= esc($status['Selection_Status']) ?>" <?= $statusFilter === $status['Selection_Status'] ? 'selected' : '' ?>><?= esc($status['Selection_Status']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Aggregator</label>
                <select class="form-select" name="aggregator">
                    <option value="">All Aggregators</option>
                    <?php foreach ($aggregators as $aggregator): ?>
                        <option value="<?= esc($aggregator['Aggregator']) ?>" <?= $aggregatorFilter === $aggregator['Aggregator'] ? 'selected' : '' ?>><?= esc($aggregator['Aggregator']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= esc($category['Category']) ?>" <?= $categoryFilter === $category['Category'] ? 'selected' : '' ?>><?= esc($category['Category']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary" name="download" value="1">Download CSV</button>
                <a href="/job_fair_results_export.php" class="btn btn-outline-secondary">Reset filters</a>
                <span class="ms-auto text-muted small">Matching rows: <?= esc((string) $totalRows) ?></span>
            </div>
        </form>
    </div>
</div>
<?php render_footer(); ?>
