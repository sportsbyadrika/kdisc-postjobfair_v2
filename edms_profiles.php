<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_auth();
$viewer = current_user() ?? [];
// EDMS is the approval authority; admin-group roles get the same
// read-only view of the profile list.
if (!is_edms($viewer) && !is_admin($viewer)) {
    http_response_code(403);
    echo 'Access denied — EDMS or admin role required.';
    exit;
}
district_pmu_bootstrap();

$rows = db()->query("SELECT p.*, u.name AS updated_by_name
    FROM district_pmu_office_profile p
    LEFT JOIN users u ON u.id = p.updated_by
    ORDER BY p.district = 'State Level' DESC, p.district ASC")->fetchAll();

render_header('PMU · District Profiles', ['main_container_class' => 'container-fluid']);
render_page_header('PMU Assets · District Profiles', [
    'icon' => 'bi-building-check',
    'subtitle' => 'Every district / state-level PMU office profile on record. Click View to see photos, SPOC and coordinates.',
    'actions' => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-collection text-primary me-1"></i>Profiles</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>District</th>
                    <th>Office Name</th>
                    <th>SPOC</th>
                    <th>Pincode</th>
                    <th>Photos</th>
                    <th>Last Updated</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No district / state office profiles have been created yet.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $r): ?>
                    <?php
                        $hasBuilding = !empty($r['building_photo_path']);
                        $hasRoom     = !empty($r['room_photo_path']);
                    ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="fw-semibold">
                            <?= esc((string) $r['district']) ?>
                            <?php if ((string) $r['district'] === 'State Level'): ?>
                                <span class="badge text-bg-primary ms-1">State</span>
                            <?php endif; ?>
                        </td>
                        <td><?= esc((string) ($r['office_name'] ?? '—')) ?></td>
                        <td>
                            <?= esc((string) ($r['spoc_name'] ?? '')) ?>
                            <?php if (!empty($r['spoc_contact'])): ?>
                                <div class="small text-muted"><?= esc((string) $r['spoc_contact']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= esc((string) ($r['pincode'] ?? '—')) ?></td>
                        <td>
                            <?php if ($hasBuilding): ?><span class="badge text-bg-success me-1"><i class="bi bi-building me-1"></i>Building</span><?php else: ?><span class="badge text-bg-light border me-1 text-muted">No building</span><?php endif; ?>
                            <?php if ($hasRoom): ?><span class="badge text-bg-success"><i class="bi bi-door-open me-1"></i>Room</span><?php else: ?><span class="badge text-bg-light border text-muted">No room</span><?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= esc(substr((string) ($r['updated_at'] ?? ''), 0, 16)) ?>
                            <?php if (!empty($r['updated_by_name'])): ?>
                                <div class="small text-muted">by <?= esc((string) $r['updated_by_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/edms_profile_detail.php?district=<?= urlencode((string) $r['district']) ?>"><i class="bi bi-eye me-1"></i>View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
