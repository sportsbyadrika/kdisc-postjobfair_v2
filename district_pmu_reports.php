<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_pmu_user();
district_pmu_bootstrap();

$user      = current_user();
$userId    = (int) $user['id'];
$districts = district_pmu_user_districts($user);
$district  = district_pmu_current_district($user);

// Only one report kind so far; kept parameterised for future additions.
$report = (string) ($_GET['report'] ?? 'assets');
if (!in_array($report, ['assets'], true)) { $report = 'assets'; }

$rows = [];
if ($district !== '') {
    $stmt = db()->prepare("SELECT s.id, s.submission_number, s.submitted_at, s.asset_count, s.district,
            u.name AS submitted_by_name
        FROM district_pmu_asset_submissions s
        LEFT JOIN users u ON u.id = s.submitted_by
        WHERE s.district = ?
        ORDER BY s.submitted_at DESC, s.id DESC");
    $stmt->execute([$district]);
    $rows = $stmt->fetchAll();
}

render_header('District PMU · Reports', ['main_container_class' => 'container-fluid']);
render_page_header('District PMU · Reports · Asset Register', [
    'icon'     => 'bi-file-earmark-text',
    'subtitle' => 'Approved asset register submissions for ' . ($district !== '' ? esc($district) : 'your district') . '. Click Generate Report to open the print-ready page and save it as PDF from your browser (File → Print → Save as PDF).',
    'actions'  => district_pmu_render_district_switcher($user, $district)
        . '<a class="btn btn-light ms-2" href="/district_pmu_dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($districts === []): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i>Your user account has no assigned district. Ask an Administrator to set one before submissions can be recorded.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection text-primary me-1"></i>Submissions</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> submission<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Submission Number</th>
                    <th>Date</th>
                    <th>District</th>
                    <th>Submitted By</th>
                    <th class="text-end">Assets</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No submissions yet. Go to the Asset Register, tick the rows you want to lock in and click "Submit selected".</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold"><?= esc((string) ($r['submission_number'] ?? '')) ?></td>
                        <td><?= esc((string) ($r['submitted_at'] ?? '')) ?></td>
                        <td><?= esc((string) ($r['district'] ?? '')) ?></td>
                        <td><?= esc((string) ($r['submitted_by_name'] ?? '')) ?></td>
                        <td class="text-end fw-bold"><?= number_format((int) ($r['asset_count'] ?? 0)) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-primary" href="/district_pmu_report_asset.php?submission=<?= (int) $r['id'] ?>" target="_blank" title="Opens the print-ready page in a new tab">
                                <i class="bi bi-printer me-1"></i>Generate Report
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        <i class="bi bi-info-circle me-1"></i>The report opens in a new tab formatted for A4 landscape print. Use your browser's <em>Print &rarr; Save as PDF</em> if you need to keep or share the file.
    </div>
</div>

<?php render_footer(); ?>
