<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$hasDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1;
$hasDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1;

$conditions = [];
$params = [];
if ($hasDateFrom) {
    $conditions[] = 'l.login_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}
if ($hasDateTo) {
    $conditions[] = 'l.login_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}
$whereSql = $conditions === [] ? '' : (' WHERE ' . implode(' AND ', $conditions));

$summarySql = '
    SELECT
        u.id,
        u.name,
        u.mobile_number,
        COUNT(l.id) AS login_count
    FROM users u
    LEFT JOIN login_logs l ON l.user_id = u.id' . $whereSql . '
    GROUP BY u.id, u.name, u.mobile_number
    ORDER BY login_count DESC, u.name';
$summaryStmt = db()->prepare($summarySql);
$summaryStmt->execute($params);
$summary = $summaryStmt->fetchAll();

$detailSql = '
    SELECT
        l.id,
        l.user_id,
        u.name,
        u.mobile_number,
        l.login_at,
        l.logout_at,
        l.ip_address
    FROM login_logs l
    JOIN users u ON u.id = l.user_id' . $whereSql . '
    ORDER BY l.id DESC';
$detailsStmt = db()->prepare($detailSql);
$detailsStmt->execute($params);
$allLogs = $detailsStmt->fetchAll();

$logsByUser = [];
foreach ($allLogs as $log) {
    $logsByUser[$log['user_id']][] = $log;
}

render_header('Login Reports');
?>
<h1 class="h3 mb-3">User-wise Login Report</h1>

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-12 col-md-4 col-lg-3">
                <label for="date_from" class="form-label">Date from</label>
                <input type="date" id="date_from" name="date_from" class="form-control" value="<?= esc($hasDateFrom ? $dateFrom : '') ?>">
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label for="date_to" class="form-label">Date to</label>
                <input type="date" id="date_to" name="date_to" class="form-control" value="<?= esc($hasDateTo ? $dateTo : '') ?>">
            </div>
            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply</button>
                <a href="/reports.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="table-responsive">
<table class="table table-bordered table-striped align-middle">
<thead><tr><th>User</th><th>Mobile</th><th>Total Logins</th><th>Drill-down</th></tr></thead>
<tbody>
<?php foreach ($summary as $s): ?>
<tr>
    <td><?= esc($s['name']) ?></td>
    <td><?= esc($s['mobile_number']) ?></td>
    <td><?= (int) $s['login_count'] ?></td>
    <td><button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#logs-<?= (int) $s['id'] ?>">View details</button></td>
</tr>
<tr class="collapse" id="logs-<?= (int) $s['id'] ?>">
    <td colspan="4">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Login At</th><th>Logout At</th><th>IP Address</th></tr></thead>
                <tbody>
                <?php foreach (($logsByUser[$s['id']] ?? []) as $log): ?>
                    <tr><td><?= esc($log['login_at']) ?></td><td><?= esc($log['logout_at'] ?? '-') ?></td><td><?= esc($log['ip_address']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php render_footer(); ?>
