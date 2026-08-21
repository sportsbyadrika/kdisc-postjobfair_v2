<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_district_pmu();
district_pmu_bootstrap();

$user       = current_user();
$userId     = (int) $user['id'];
$districts  = district_pmu_user_districts($user);
$district   = district_pmu_current_district($user);

$profile = $district !== '' ? district_pmu_get_profile_by_district($district) : null;

/* Profile completeness — which of the required fields have a value.
   Photos count as one point each. */
$profileFields = [
    'office_name'         => 'Office name',
    'address'             => 'Address',
    'pincode'             => 'Pincode',
    'spoc_name'           => 'SPOC name',
    'spoc_contact'        => 'SPOC contact',
    'latitude'            => 'Latitude',
    'longitude'           => 'Longitude',
    'building_photo_path' => 'Building photo',
    'room_photo_path'     => 'Room photo',
];
$profileTotal  = count($profileFields);
$profileFilled = 0;
$profileMissing = [];
foreach ($profileFields as $col => $label) {
    $val = $profile[$col] ?? null;
    if ($val !== null && trim((string) $val) !== '') {
        $profileFilled++;
    } else {
        $profileMissing[] = $label;
    }
}
$profilePct  = $profileTotal > 0 ? (int) round(($profileFilled / $profileTotal) * 100) : 0;
$profileTone = $profilePct >= 90 ? 'success' : ($profilePct >= 50 ? 'warning' : 'danger');

/* Asset counters — scoped to the currently-selected district (assets are
   the district's, not the user's). Legacy rows with a NULL district
   won't show up here — they need to be re-saved to attach a district. */
$assetTotal = 0;
$assetsByType = [];
$assetsByAuthority = [];
if ($district !== '') {
    try {
        $countStmt = db()->prepare('SELECT COUNT(*) FROM district_pmu_assets WHERE district = ?');
        $countStmt->execute([$district]);
        $assetTotal = (int) $countStmt->fetchColumn();

        $tStmt = db()->prepare('SELECT t.name AS type_name, COUNT(*) AS c, COALESCE(SUM(a.quantity), 0) AS q
            FROM district_pmu_assets a
            LEFT JOIN district_pmu_asset_types t ON t.id = a.asset_type_id
            WHERE a.district = ?
            GROUP BY t.name');
        $tStmt->execute([$district]);
        $assetsByType = $tStmt->fetchAll();

        $aStmt = db()->prepare('SELECT COALESCE(auth.name, "(Unassigned)") AS auth_name, COUNT(*) AS c, COALESCE(SUM(a.quantity), 0) AS q
            FROM district_pmu_assets a
            LEFT JOIN district_pmu_owning_authorities auth ON auth.id = a.owning_authority_id
            WHERE a.district = ?
            GROUP BY COALESCE(auth.name, "(Unassigned)")
            ORDER BY c DESC');
        $aStmt->execute([$district]);
        $assetsByAuthority = $aStmt->fetchAll();
    } catch (Throwable $e) { /* tables not bootstrapped yet — treat as empty */ }
}

render_header('District PMU Dashboard');
render_page_header('District PMU · Dashboard', [
    'icon'     => 'bi-speedometer2',
    'subtitle' => 'Office profile status + asset register summary for ' . ($district !== '' ? esc($district) : 'your district') . '.',
    'actions'  => district_pmu_render_district_switcher($user, $district),
]);
?>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="card card-stat accent-<?= esc($profileTone) ?> h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Office Profile</p>
                    <p class="stat-value"><?= $profilePct ?>%</p>
                    <div class="progress mb-2" style="height:8px; max-width:220px;">
                        <div class="progress-bar bg-<?= esc($profileTone) ?>" role="progressbar" style="width: <?= $profilePct ?>%;"></div>
                    </div>
                    <div class="small text-muted mb-2"><strong><?= $profileFilled ?></strong> of <?= $profileTotal ?> fields filled</div>
                    <?php if ($profileMissing !== []): ?>
                        <div class="small text-muted mb-2">
                            <i class="bi bi-exclamation-circle me-1"></i>Missing: <?= esc(implode(', ', array_slice($profileMissing, 0, 4))) ?><?= count($profileMissing) > 4 ? ' &hellip;' : '' ?>
                        </div>
                    <?php endif; ?>
                    <a class="stat-link" href="/district_pmu_office_profile.php"><?= $profileFilled === 0 ? 'Set up profile' : 'Update profile' ?> <i class="bi bi-arrow-right-short"></i></a>
                </div>
                <span class="stat-icon-box tone-<?= esc($profileTone) ?>"><i class="bi bi-building-check"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card card-stat accent-info h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">Assets on record</p>
                    <p class="stat-value"><?= number_format($assetTotal) ?></p>
                    <?php if ($assetsByType !== []): ?>
                        <div class="d-flex flex-wrap gap-1 mt-1 mb-2">
                            <?php foreach ($assetsByType as $tRow): ?>
                                <?php
                                    $isIt = str_contains(strtolower((string) $tRow['type_name']), 'non') ? false : true;
                                    $tone = $isIt ? 'primary' : 'secondary';
                                ?>
                                <span class="badge text-bg-<?= esc($tone) ?>"
                                      title="<?= number_format((int) $tRow['q']) ?> item(s) total"><?= esc((string) ($tRow['type_name'] ?? 'Unknown')) ?>
                                    <span class="fw-bold ms-1"><?= number_format((int) $tRow['c']) ?></span>
                                    <span class="fw-normal opacity-75 ms-1">&middot; <?= number_format((int) $tRow['q']) ?> qty</span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a class="stat-link" href="/district_pmu_assets.php">Open register <i class="bi bi-arrow-right-short"></i></a>
                </div>
                <span class="stat-icon-box tone-info"><i class="bi bi-box-seam"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card card-stat accent-success h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100">
                    <p class="stat-label">By owning authority</p>
                    <p class="stat-value"><?= number_format(count($assetsByAuthority)) ?></p>
                    <div class="small text-muted mb-2">distinct authorities</div>
                    <?php if ($assetsByAuthority !== []): ?>
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($assetsByAuthority as $aRow): ?>
                                <span class="badge text-bg-success" title="<?= number_format((int) $aRow['q']) ?> item(s) total">
                                    <?= esc((string) $aRow['auth_name']) ?>
                                    <span class="fw-bold ms-1"><?= number_format((int) $aRow['c']) ?></span>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted small">No assets recorded yet.</span>
                    <?php endif; ?>
                </div>
                <span class="stat-icon-box tone-success"><i class="bi bi-shield-check"></i></span>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-info-circle text-primary"></i>
        <span class="fw-semibold">Getting started</span>
    </div>
    <div class="card-body">
        <ol class="mb-0">
            <li class="mb-2"><a href="/district_pmu_office_profile.php">Complete your Office Profile</a> — the pincode, SPOC contact, latitude &amp; longitude (via the map), and both photos help the central team identify your district's PMU office.</li>
            <li><a href="/district_pmu_assets.php">Add rows to the Asset Register</a> — one per asset item you hold. Use the pre-seeded IT / Non-IT subtypes; if you need something that's not on the list, ask your Administrator to add it under District PMU Masters.</li>
        </ol>
    </div>
</div>

<?php render_footer(); ?>
