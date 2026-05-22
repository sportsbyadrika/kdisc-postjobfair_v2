<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
$uid = $user['id'];
$isDistrictUser = is_district_user($user);

$totalUsers = 0;
if (is_admin($user)) {
    $totalUsers = (int) db()->query('SELECT COUNT(*) FROM users WHERE active_status = 1')->fetchColumn();
}

$selectedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'selected'")->fetchColumn();
$shortlistedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'shortlisted'")->fetchColumn();
$onHoldCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'onhold'")->fetchColumn();
$shortlistOnHoldSelectedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Shortlist_Candidate_Status), ' ', '')) = 'selected'")->fetchColumn();
$totalSelectedCount = $selectedCount + $shortlistOnHoldSelectedCount;
$totalJoinedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(TRIM(Candidate_Joined_Status)) = 'yes'")->fetchColumn();
$totalCallsCount = (int) db()->query('SELECT COUNT(*) FROM candidate_call_history')->fetchColumn();


$pivotRows = db()->query('SELECT Job_Fair_No, Selection_Status, COUNT(*) AS total_count FROM job_fair_result GROUP BY Job_Fair_No, Selection_Status ORDER BY Job_Fair_No, Selection_Status')->fetchAll();
$pivotStatuses = [];
$pivotData = [];
$statusOrder = ['Selected', 'Shortlisted', 'OnHold'];
$statusAliases = [
    'onhold' => 'OnHold',
    'on hold' => 'OnHold',
];
foreach ($pivotRows as $pivotRow) {
    $jobFairNo = (string) ($pivotRow['Job_Fair_No'] ?? '');
    $rawStatus = (string) ($pivotRow['Selection_Status'] ?? 'Unknown');
    $statusKey = strtolower(trim($rawStatus));
    $status = $statusAliases[$statusKey] ?? $rawStatus;
    $total = (int) ($pivotRow['total_count'] ?? 0);
    if (!in_array($status, $pivotStatuses, true)) {
        $pivotStatuses[] = $status;
    }
    if (!isset($pivotData[$jobFairNo])) {
        $pivotData[$jobFairNo] = [];
    }
    $pivotData[$jobFairNo][$status] = ($pivotData[$jobFairNo][$status] ?? 0) + $total;
}
$orderedStatuses = [];
foreach ($statusOrder as $statusLabel) {
    if (in_array($statusLabel, $pivotStatuses, true)) {
        $orderedStatuses[] = $statusLabel;
    }
}
$remainingStatuses = array_values(array_diff($pivotStatuses, $orderedStatuses));
sort($remainingStatuses);
$pivotStatuses = [...$orderedStatuses, ...$remainingStatuses];
ksort($pivotData);

$districtRows = db()->query("SELECT
    COALESCE(NULLIF(TRIM(SDPK_District), ''), 'Unknown') AS district,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS selected_count,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted_count,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'onhold' THEN 1 ELSE 0 END) AS on_hold_count,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Shortlist_Candidate_Status), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS shortlisted_selected_count,
    SUM(CASE WHEN LOWER(TRIM(Offer_Letter_Generated)) = 'yes' THEN 1 ELSE 0 END) AS offer_letter_generated_count,
    COUNT(*) AS total_count
FROM job_fair_result
GROUP BY COALESCE(NULLIF(TRIM(SDPK_District), ''), 'Unknown')
ORDER BY district")->fetchAll();

$districtTotals = [
    'selected_count' => 0,
    'shortlisted_count' => 0,
    'on_hold_count' => 0,
    'shortlisted_selected_count' => 0,
    'offer_letter_generated_count' => 0,
    'total_count' => 0,
    'total_selected_count' => 0,
];
render_header('Dashboard');
?>
<h1 class="h3 mb-4">Welcome, <?= esc($user['name']) ?></h1>
<div class="row g-3 mb-3">
    <?php if (is_admin($user)): ?>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card card-stat h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <p class="text-muted mb-1">Active Users</p>
                        <h2 class="h4 mb-1"><?= $totalUsers ?></h2>
                        <a href="/users.php">Manage</a>
                    </div>
                    <i class="bi bi-people-fill stat-icon text-primary" aria-hidden="true"></i>
                </div>
            </div>
        </div>
    <?php endif; ?>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div>
                    <p class="text-muted mb-1">Selected</p>
                    <h2 class="h4 mb-0"><?= $selectedCount ?></h2>
                </div>
                <i class="bi bi-check-circle-fill stat-icon text-success" aria-hidden="true"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div>
                    <p class="text-muted mb-1">Shortlisted</p>
                    <h2 class="h4 mb-0"><?= $shortlistedCount ?></h2>
                </div>
                <i class="bi bi-list-check stat-icon text-info" aria-hidden="true"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div>
                    <p class="text-muted mb-1">On hold</p>
                    <h2 class="h4 mb-0"><?= $onHoldCount ?></h2>
                </div>
                <i class="bi bi-pause-circle-fill stat-icon text-warning" aria-hidden="true"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div>
                    <p class="text-muted mb-1">Shortlist/On Hold Selected</p>
                    <h2 class="h4 mb-0"><?= $shortlistOnHoldSelectedCount ?></h2>
                </div>
                <i class="bi bi-person-check-fill stat-icon text-secondary" aria-hidden="true"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div>
                    <p class="text-muted mb-1">Total Selected</p>
                    <h2 class="h4 mb-0"><?= $totalSelectedCount ?></h2>
                </div>
                <i class="bi bi-person-badge-fill stat-icon text-primary" aria-hidden="true"></i>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-lg-3">
        <div class="card card-stat h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div>
                    <p class="text-muted mb-1">Total Joined</p>
                    <h2 class="h4 mb-0"><?= $totalJoinedCount ?></h2>
                </div>
                <i class="bi bi-door-open-fill stat-icon text-success" aria-hidden="true"></i>
            </div>
        </div>
    </div>
    <?php if (!$isDistrictUser): ?>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card card-stat h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <p class="text-muted mb-1">Total Calls</p>
                        <h2 class="h4 mb-0"><?= $totalCallsCount ?></h2>
                    </div>
                    <i class="bi bi-telephone-fill stat-icon text-danger" aria-hidden="true"></i>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!$isDistrictUser): ?>
<div class="card">
    <div class="card-body">
        <h2 class="h5">Quick navigation</h2>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary btn-sm" href="/activities.php">Activities</a>
            <a class="btn btn-outline-info btn-sm" href="/call_history_report.php">Call History Report</a>
            <a class="btn btn-outline-dark btn-sm" href="/consolidated_report.php">Consolidated Report</a>
            <?php if (is_admin($user)): ?>
                <a class="btn btn-outline-secondary btn-sm" href="/users.php">Users</a>
                <a class="btn btn-outline-success btn-sm" href="/reports.php">Login Reports</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h5 mb-0">Job Fair wise Status</h2>
            <?php if (!$isDistrictUser): ?>
                <a class="btn btn-sm btn-outline-primary" href="/job_fair_results.php">Open Job fair result data</a>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>Job Fair No</th>
                        <?php foreach ($pivotStatuses as $pivotStatus): ?>
                            <th><?= esc($pivotStatus) ?></th>
                        <?php endforeach; ?>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($pivotData === []): ?>
                    <tr><td colspan="<?= count($pivotStatuses) + 3 ?>" class="text-center text-muted">No job fair result data available.</td></tr>
                <?php endif; ?>
                <?php $columnTotals = array_fill_keys($pivotStatuses, 0); $grandTotal = 0; ?>
                <?php $rowIndex = 1; ?>
                <?php foreach ($pivotData as $jobFairNo => $statusCounts): ?>
                    <?php $rowTotal = 0; ?>
                    <tr>
                        <td><?= $rowIndex ?></td>
                        <td><?= esc($jobFairNo) ?></td>
                        <?php foreach ($pivotStatuses as $pivotStatus): ?>
                            <?php $value = (int) ($statusCounts[$pivotStatus] ?? 0); $rowTotal += $value; $columnTotals[$pivotStatus] += $value; ?>
                            <td><?= $value ?></td>
                        <?php endforeach; ?>
                        <td><strong><?= $rowTotal ?></strong></td>
                    </tr>
                    <?php $grandTotal += $rowTotal; ?>
                    <?php $rowIndex++; ?>
                <?php endforeach; ?>
                <?php if ($pivotData !== []): ?>
                    <tr>
                        <td colspan="2"><strong>Total</strong></td>
                        <?php foreach ($pivotStatuses as $pivotStatus): ?>
                            <td><strong><?= $columnTotals[$pivotStatus] ?></strong></td>
                        <?php endforeach; ?>
                        <td><strong><?= $grandTotal ?></strong></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5 mb-2">District wise Record Count</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>District</th>
                        <th>Selected</th>
                        <th>Shortlisted</th>
                        <th>On Hold</th>
                        <th>Total</th>
                        <th>Shortlisted Selected</th>
                        <th>Total Selected</th>
                        <th>Offer Letter Generated</th>
                        <th>% of Offer Letter Generated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($districtRows === []): ?>
                    <tr><td colspan="10" class="text-center text-muted">No district-wise data available.</td></tr>
                <?php endif; ?>
                <?php $districtIndex = 1; ?>
                <?php foreach ($districtRows as $districtRow): ?>
                    <?php
                        $selected = (int) ($districtRow['selected_count'] ?? 0);
                        $shortlisted = (int) ($districtRow['shortlisted_count'] ?? 0);
                        $onHold = (int) ($districtRow['on_hold_count'] ?? 0);
                        $total = (int) ($districtRow['total_count'] ?? 0);
                        $shortlistedSelected = (int) ($districtRow['shortlisted_selected_count'] ?? 0);
                        $totalSelected = $selected + $shortlistedSelected;
                        $offerLetterGenerated = (int) ($districtRow['offer_letter_generated_count'] ?? 0);
                        $offerLetterPercentage = $totalSelected > 0 ? round(($offerLetterGenerated / $totalSelected) * 100, 2) : 0;

                        $districtTotals['selected_count'] += $selected;
                        $districtTotals['shortlisted_count'] += $shortlisted;
                        $districtTotals['on_hold_count'] += $onHold;
                        $districtTotals['total_count'] += $total;
                        $districtTotals['shortlisted_selected_count'] += $shortlistedSelected;
                        $districtTotals['total_selected_count'] += $totalSelected;
                        $districtTotals['offer_letter_generated_count'] += $offerLetterGenerated;
                    ?>
                    <tr>
                        <td><?= $districtIndex ?></td>
                        <td><?= esc($districtRow['district']) ?></td>
                        <td><?= $selected ?></td>
                        <td><?= $shortlisted ?></td>
                        <td><?= $onHold ?></td>
                        <td><?= $total ?></td>
                        <td><?= $shortlistedSelected ?></td>
                        <td><?= $totalSelected ?></td>
                        <td><?= $offerLetterGenerated ?></td>
                        <td><?= $offerLetterPercentage ?>%</td>
                    </tr>
                    <?php $districtIndex++; ?>
                <?php endforeach; ?>
                <?php if ($districtRows !== []): ?>
                    <?php
                        $grandOfferLetterPercentage = $districtTotals['total_selected_count'] > 0
                            ? round(($districtTotals['offer_letter_generated_count'] / $districtTotals['total_selected_count']) * 100, 2)
                            : 0;
                    ?>
                    <tr>
                        <td colspan="2"><strong>Total</strong></td>
                        <td><strong><?= $districtTotals['selected_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['shortlisted_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['on_hold_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['total_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['shortlisted_selected_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['total_selected_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['offer_letter_generated_count'] ?></strong></td>
                        <td><strong><?= $grandOfferLetterPercentage ?>%</strong></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php render_footer(); ?>
