<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_auth();
$viewer = current_user() ?? [];
if (!is_edms($viewer) && !is_admin($viewer)) {
    http_response_code(403);
    echo 'Access denied — EDMS or admin role required.';
    exit;
}
district_pmu_bootstrap();

$statusFilter   = trim((string) ($_GET['status']   ?? ''));
$districtFilter = trim((string) ($_GET['district'] ?? ''));

$conds  = ['1=1'];
$params = [];
if ($statusFilter !== '' && in_array($statusFilter, ['pending', 'approved', 'rejected', 'returned'], true)) {
    $conds[] = 's.approval_status = ?';
    $params[] = $statusFilter;
}
if ($districtFilter !== '') {
    $conds[] = 's.district = ?';
    $params[] = $districtFilter;
}
$whereSql = 'WHERE ' . implode(' AND ', $conds);

// Distinct districts across submissions (for the filter dropdown).
$districtOptions = array_map(
    static fn(array $r): string => (string) $r['district'],
    db()->query('SELECT DISTINCT district FROM district_pmu_asset_submissions ORDER BY district ASC')->fetchAll()
);

$stmt = db()->prepare("SELECT s.*, u.name AS submitted_by_name, ru.name AS reviewed_by_name
    FROM district_pmu_asset_submissions s
    LEFT JOIN users u  ON u.id  = s.submitted_by
    LEFT JOIN users ru ON ru.id = s.reviewed_by
    $whereSql
    ORDER BY s.submitted_at DESC, s.id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$statusTone = static function (string $s): string {
    return match ($s) {
        'approved' => 'success',
        'rejected' => 'danger',
        'returned' => 'warning',
        default    => 'secondary',
    };
};

render_header('PMU · Asset Register (EDMS)', ['main_container_class' => 'container-fluid']);
render_page_header('PMU Assets · Asset Register (Approvals)', [
    'icon' => 'bi-box-seam',
    'subtitle' => 'Every submitted asset register across every district / state level. Approve, reject or return with remarks.',
    'actions' => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Approval status</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'returned' => 'Returned'] as $k => $lbl): ?>
                        <option value="<?= esc($k) ?>" <?= $statusFilter === $k ? 'selected' : '' ?>><?= esc($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">District</label>
                <select class="form-select" name="district">
                    <option value="">All</option>
                    <?php foreach ($districtOptions as $d): ?>
                        <option value="<?= esc($d) ?>" <?= $districtFilter === $d ? 'selected' : '' ?>><?= esc($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
                <a class="btn btn-light" href="/edms_submissions.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection text-primary me-1"></i>Submissions</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Submission #</th>
                    <th>District</th>
                    <th>Submitted By</th>
                    <th>Submitted At</th>
                    <th class="text-end">Assets</th>
                    <th>Approval Status</th>
                    <th>Reviewer</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No submissions match these filters.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r): ?>
                    <?php $st = (string) ($r['approval_status'] ?? 'pending'); ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) ($r['submission_number'] ?? '')) ?></td>
                        <td>
                            <?= esc((string) $r['district']) ?>
                            <?php if ((string) $r['district'] === 'State Level'): ?><span class="badge text-bg-primary ms-1">State</span><?php endif; ?>
                        </td>
                        <td><?= esc((string) ($r['submitted_by_name'] ?? '')) ?></td>
                        <td class="small text-muted"><?= esc((string) ($r['submitted_at'] ?? '')) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) ($r['asset_count'] ?? 0)) ?></td>
                        <td><span class="badge text-bg-<?= esc($statusTone($st)) ?> text-uppercase"><?= esc($st) ?></span></td>
                        <td class="small text-muted">
                            <?php if (!empty($r['reviewed_by_name'])): ?>
                                <?= esc((string) $r['reviewed_by_name']) ?>
                                <div class="small text-muted"><?= esc((string) ($r['reviewed_at'] ?? '')) ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-primary" href="/edms_submission_detail.php?submission=<?= (int) $r['id'] ?>"><i class="bi bi-eye me-1"></i>View</a>
                                <a class="btn btn-sm btn-outline-secondary" href="/district_pmu_report_asset.php?submission=<?= (int) $r['id'] ?>" target="_blank" title="Print-ready report"><i class="bi bi-printer"></i></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
