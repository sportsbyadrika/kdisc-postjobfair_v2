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

$district = trim((string) ($_GET['district'] ?? ''));
if ($district === '') {
    http_response_code(400);
    echo 'Missing district.';
    exit;
}
$profile = district_pmu_get_profile_by_district($district);
if ($profile === null) {
    http_response_code(404);
    render_header('Not found');
    render_page_header('Profile not found', ['icon' => 'bi-exclamation-triangle', 'subtitle' => $district]);
    echo '<div class="alert alert-warning">No office profile has been created for this district / state level yet.</div>';
    echo '<a class="btn btn-primary" href="/edms_profiles.php"><i class="bi bi-arrow-left me-1"></i>Back to Profiles</a>';
    render_footer();
    exit;
}
$updatedByName = null;
if (!empty($profile['updated_by'])) {
    $stmt = db()->prepare('SELECT name FROM users WHERE id = ?');
    $stmt->execute([(int) $profile['updated_by']]);
    $row = $stmt->fetch();
    if ($row !== false) $updatedByName = (string) $row['name'];
}

// Also compute the count of asset submissions from this district as a
// quick cross-reference — helpful for EDMS when reviewing.
$subCount   = 0;
$assetCount = 0;
try {
    $s = db()->prepare('SELECT COUNT(*) AS sc, COALESCE(SUM(asset_count),0) AS ac FROM district_pmu_asset_submissions WHERE district = ?');
    $s->execute([$district]);
    $r = $s->fetch() ?: [];
    $subCount   = (int) ($r['sc'] ?? 0);
    $assetCount = (int) ($r['ac'] ?? 0);
} catch (Throwable $e) { /* ignore */ }

render_header('PMU · Profile detail');
render_page_header('District Profile · ' . esc($district), [
    'icon' => 'bi-building-check',
    'subtitle' => 'Read-only view of the PMU office profile for this ' . ((string) $district === 'State Level' ? 'state level' : 'district') . '.',
    'actions' => '<a class="btn btn-light" href="/edms_profiles.php"><i class="bi bi-arrow-left me-1"></i>Back to Profiles</a>',
]);

$fmt = static fn(?string $v): string => (trim((string) $v) === '' ? '<span class="text-muted">—</span>' : esc(trim((string) $v)));
?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-info-circle text-primary me-1"></i>Office details</span>
                <span class="small text-muted">Last updated <?= esc(substr((string) ($profile['updated_at'] ?? ''), 0, 16)) ?><?= $updatedByName !== null ? ' by ' . esc($updatedByName) : '' ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><div class="small text-muted">District</div><div class="fw-semibold"><?= esc($district) ?></div></div>
                    <div class="col-md-5"><div class="small text-muted">Office name</div><div class="fw-semibold"><?= $fmt((string) ($profile['office_name'] ?? '')) ?></div></div>
                    <div class="col-md-2"><div class="small text-muted">Pincode</div><div class="fw-semibold"><?= $fmt((string) ($profile['pincode'] ?? '')) ?></div></div>
                    <div class="col-md-2"><div class="small text-muted">Submissions</div><div class="fw-semibold"><?= number_format($subCount) ?> · <?= number_format($assetCount) ?> asset(s)</div></div>
                    <div class="col-12"><div class="small text-muted">Address</div><div class="fw-semibold"><?= $fmt((string) ($profile['address'] ?? '')) ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Existing SPOC Name</div><div class="fw-semibold"><?= $fmt((string) ($profile['spoc_name'] ?? '')) ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Existing SPOC contact (Mobile Number)</div><div class="fw-semibold"><?= $fmt((string) ($profile['spoc_contact'] ?? '')) ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">Latitude</div><div class="fw-semibold"><?= $fmt((string) ($profile['latitude'] ?? '')) ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">Longitude</div><div class="fw-semibold"><?= $fmt((string) ($profile['longitude'] ?? '')) ?></div></div>
                    <?php if (!empty($profile['latitude']) && !empty($profile['longitude'])): ?>
                        <div class="col-md-6 d-flex align-items-end">
                            <a class="btn btn-sm btn-outline-primary"
                               href="https://www.openstreetmap.org/?mlat=<?= urlencode((string) $profile['latitude']) ?>&amp;mlon=<?= urlencode((string) $profile['longitude']) ?>&amp;zoom=17"
                               target="_blank" rel="noopener"><i class="bi bi-map me-1"></i>Open on OpenStreetMap</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-images text-primary me-1"></i>Photos</div>
            <div class="card-body">
                <div class="mb-3">
                    <div class="small text-muted mb-1"><i class="bi bi-building me-1"></i>Building</div>
                    <?php if (!empty($profile['building_photo_path'])): ?>
                        <a href="<?= esc((string) $profile['building_photo_path']) ?>" target="_blank" rel="noopener">
                            <img src="<?= esc((string) $profile['building_photo_path']) ?>" alt="Building" style="width:100%; max-height:220px; object-fit:cover; border:1px solid var(--bs-border-color); border-radius:.375rem;">
                        </a>
                    <?php else: ?>
                        <div class="text-muted small">Not uploaded</div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="small text-muted mb-1"><i class="bi bi-door-open me-1"></i>Room</div>
                    <?php if (!empty($profile['room_photo_path'])): ?>
                        <a href="<?= esc((string) $profile['room_photo_path']) ?>" target="_blank" rel="noopener">
                            <img src="<?= esc((string) $profile['room_photo_path']) ?>" alt="Room" style="width:100%; max-height:220px; object-fit:cover; border:1px solid var(--bs-border-color); border-radius:.375rem;">
                        </a>
                    <?php else: ?>
                        <div class="text-muted small">Not uploaded</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>
