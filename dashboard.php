<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
$uid = $user['id'];
$isDistrictUser = is_district_user($user);
$isAdmin = is_admin($user);

$totalUsers = 0;
if ($isAdmin) {
    $totalUsers = (int) db()->query('SELECT COUNT(*) FROM users WHERE active_status = 1')->fetchColumn();
}

$selectedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'selected'")->fetchColumn();
$shortlistedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'shortlisted'")->fetchColumn();
$onHoldCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'onhold'")->fetchColumn();
$shortlistOnHoldSelectedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(REPLACE(TRIM(Shortlist_Candidate_Status), ' ', '')) = 'selected'")->fetchColumn();
$totalSelectedCount = $selectedCount + $shortlistOnHoldSelectedCount;
$totalJoinedCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(TRIM(Candidate_Joined_Status)) = 'yes'")->fetchColumn();
$totalCallsCount = (int) db()->query('SELECT COUNT(*) FROM candidate_call_history')->fetchColumn();
$totalCandidates = (int) db()->query('SELECT COUNT(*) FROM job_fair_result')->fetchColumn();
$jobFairCount = (int) db()->query("SELECT COUNT(DISTINCT Job_Fair_No) FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND Job_Fair_No <> ''")->fetchColumn();
$offerLetterCount = (int) db()->query("SELECT COUNT(*) FROM job_fair_result WHERE LOWER(TRIM(Offer_Letter_Generated)) = 'yes'")->fetchColumn();
$latestUpload = db()->query('SELECT MAX(Data_uploaded_date) FROM job_fair_result')->fetchColumn();
$offerLetterPct = $totalSelectedCount > 0 ? round(($offerLetterCount / $totalSelectedCount) * 100, 1) : 0;
$joinedPct = $totalSelectedCount > 0 ? round(($totalJoinedCount / $totalSelectedCount) * 100, 1) : 0;

// Last 5 days call statistics (today + previous 4 days) — shown on CRM & District user dashboards.
// Adds "mine_*" columns that count calls authored by the currently logged in user, so the user
// can compare their own activity against the team total.
$last5DaysCallRows = [];
if (!$isAdmin) {
    $stmt = db()->prepare(
        "SELECT
            DATE(call_datetime) AS call_date,
            COUNT(*) AS total_calls,
            SUM(CASE WHEN stage = 'Employer Connect' THEN 1 ELSE 0 END) AS employer_calls,
            SUM(CASE WHEN stage = 'Candidate Connect' THEN 1 ELSE 0 END) AS candidate_calls,
            SUM(CASE WHEN stage = 'Aggregator Contact' THEN 1 ELSE 0 END) AS aggregator_calls,
            SUM(CASE WHEN call_status = 'Attended' THEN 1 ELSE 0 END) AS attended,
            SUM(CASE WHEN call_status = 'Not attended' THEN 1 ELSE 0 END) AS not_attended,
            SUM(CASE WHEN call_status = 'Invalid number' THEN 1 ELSE 0 END) AS invalid_number,
            SUM(CASE WHEN created_by = ? THEN 1 ELSE 0 END) AS mine_total,
            SUM(CASE WHEN created_by = ? AND stage = 'Employer Connect' THEN 1 ELSE 0 END) AS mine_employer,
            SUM(CASE WHEN created_by = ? AND stage = 'Candidate Connect' THEN 1 ELSE 0 END) AS mine_candidate,
            SUM(CASE WHEN created_by = ? AND stage = 'Aggregator Contact' THEN 1 ELSE 0 END) AS mine_aggregator,
            SUM(CASE WHEN created_by = ? AND call_status = 'Attended' THEN 1 ELSE 0 END) AS mine_attended,
            SUM(CASE WHEN created_by = ? AND call_status = 'Not attended' THEN 1 ELSE 0 END) AS mine_not_attended,
            SUM(CASE WHEN created_by = ? AND call_status = 'Invalid number' THEN 1 ELSE 0 END) AS mine_invalid_number
        FROM candidate_call_history
        WHERE DATE(call_datetime) >= DATE_SUB(CURDATE(), INTERVAL 4 DAY)
        GROUP BY DATE(call_datetime)
        ORDER BY call_date DESC"
    );
    $stmt->execute(array_fill(0, 7, (int) $uid));
    $last5DaysCallRows = $stmt->fetchAll();
}
$last5DaysCallByDate = [];
foreach ($last5DaysCallRows as $r) {
    $last5DaysCallByDate[(string) $r['call_date']] = $r;
}
$last5DaysDates = [];
for ($i = 0; $i < 5; $i++) {
    $last5DaysDates[] = date('Y-m-d', strtotime('-' . $i . ' day'));
}

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

/* ---- Role-tailored KPI definitions ---- */
// Count notifications visible to the logged-in user (mirrors the visibility
// logic in notifications.php). Used as a dashboard KPI.
$rolesByToWhom = ['State CRM' => 'crm_member', 'State DSM' => 'state_dsm', 'District User' => 'district_user'];
$myNotificationCount = 0;
try {
    if (is_admin($user)) {
        $myNotificationCount = (int) db()->query("SELECT COUNT(*) FROM activities WHERE active_status = 1 AND status <> 'closed'")->fetchColumn();
    } else {
        $roleToWhom = array_search((string) ($user['role'] ?? ''), $rolesByToWhom, true) ?: '';
        $cs = db()->prepare(
            "SELECT COUNT(*) FROM activities WHERE active_status = 1 AND status <> 'closed'
             AND (owner_user_id = ? OR (to_whom = ? AND (read_by = 'any' OR target_user_id = ?)))"
        );
        $cs->execute([(int) $user['id'], $roleToWhom, (int) $user['id']]);
        $myNotificationCount = (int) $cs->fetchColumn();
    }
} catch (Throwable $e) {
    $myNotificationCount = 0;
}

$allKpis = [
    'users'        => ['label' => 'Active Users',        'value' => $totalUsers,                   'icon' => 'bi-people-fill',        'tone' => 'primary', 'link' => '/users.php',              'link_text' => 'Manage users'],
    'job_fairs'    => ['label' => 'Job Fairs',           'value' => $jobFairCount,                 'icon' => 'bi-calendar-event',     'tone' => 'slate',   'link' => null,                       'link_text' => null],
    'candidates'   => ['label' => 'Total Candidates',    'value' => $totalCandidates,              'icon' => 'bi-people',             'tone' => 'slate',   'link' => null,                       'link_text' => null],
    'selected'     => ['label' => 'Selected',            'value' => $selectedCount,                'icon' => 'bi-check-circle-fill',  'tone' => 'success', 'link' => null,                       'link_text' => null],
    'shortlisted'  => ['label' => 'Shortlisted',         'value' => $shortlistedCount,             'icon' => 'bi-list-check',         'tone' => 'info',    'link' => null,                       'link_text' => null],
    'onhold'       => ['label' => 'On Hold',             'value' => $onHoldCount,                  'icon' => 'bi-pause-circle-fill',  'tone' => 'warning', 'link' => null,                       'link_text' => null],
    'sl_selected'  => ['label' => 'Shortlist Selected',  'value' => $shortlistOnHoldSelectedCount, 'icon' => 'bi-person-check-fill',  'tone' => 'purple',  'link' => null,                       'link_text' => null],
    'total_sel'    => ['label' => 'Total Selected',      'value' => $totalSelectedCount,           'icon' => 'bi-person-badge-fill',  'tone' => 'primary', 'link' => null,                       'link_text' => null],
    'joined'       => ['label' => 'Total Joined',        'value' => $totalJoinedCount,             'icon' => 'bi-door-open-fill',     'tone' => 'success', 'link' => null,                       'link_text' => $joinedPct . '% of selected'],
    'offer'        => ['label' => 'Offer Letters',       'value' => $offerLetterCount,             'icon' => 'bi-envelope-paper-fill','tone' => 'info',    'link' => null,                       'link_text' => $offerLetterPct . '% of selected'],
    'calls'        => ['label' => 'Total Calls Logged',  'value' => $totalCallsCount,              'icon' => 'bi-telephone-fill',     'tone' => 'danger',  'link' => '/call_history_report.php', 'link_text' => 'View call report'],
    'notif'        => ['label' => 'My Notifications',    'value' => $myNotificationCount,          'icon' => 'bi-bell-fill',          'tone' => 'warning', 'link' => '/notifications.php',       'link_text' => 'Open notifications'],
];

if ($isAdmin) {
    $kpiKeys = ['users', 'job_fairs', 'selected', 'shortlisted', 'onhold', 'total_sel', 'joined', 'notif'];
} elseif ($isDistrictUser) {
    $kpiKeys = ['job_fairs', 'candidates', 'selected', 'shortlisted', 'total_sel', 'offer', 'joined', 'notif'];
} else {
    $kpiKeys = ['selected', 'shortlisted', 'onhold', 'total_sel', 'offer', 'joined', 'calls', 'notif'];
}

/* ---- Role-tailored quick actions ---- */
if ($isAdmin) {
    $quickActions = [
        ['label' => 'Manage Users',        'href' => '/users.php',                    'icon' => 'bi-people'],
        ['label' => 'Upload Job Fair CSV', 'href' => '/job_fair_result_upload.php',   'icon' => 'bi-upload'],
        ['label' => 'Job Fair Data',       'href' => '/job_fair_results.php',         'icon' => 'bi-table'],
        ['label' => 'Consolidated Report', 'href' => '/consolidated_report.php',      'icon' => 'bi-clipboard-data'],
        ['label' => 'Exception Report',    'href' => '/job_fair_exception_report.php','icon' => 'bi-exclamation-triangle'],
        ['label' => 'Login Reports',       'href' => '/reports.php',                  'icon' => 'bi-clock-history'],
    ];
} elseif ($isDistrictUser) {
    $quickActions = [
        ['label' => 'District Overview',    'href' => '/district_data.php',                 'icon' => 'bi-bar-chart-line'],
        ['label' => 'Candidate Data',       'href' => '/district_candidate_data.php',       'icon' => 'bi-people'],
        ['label' => 'Consolidated Report',  'href' => '/district_consolidated_report.php',  'icon' => 'bi-clipboard-data'],
        ['label' => 'Job Station Report',   'href' => '/job_station_consolidated_report.php','icon' => 'bi-buildings'],
        ['label' => 'Phone Directory',      'href' => '/phone_directory.php',               'icon' => 'bi-person-rolodex'],
    ];
} else {
    $quickActions = [
        ['label' => 'Job Fair Data',       'href' => '/job_fair_results.php',         'icon' => 'bi-table'],
        ['label' => 'Call History Report', 'href' => '/call_history_report.php',      'icon' => 'bi-telephone'],
        ['label' => 'Consolidated Report', 'href' => '/consolidated_report.php',      'icon' => 'bi-clipboard-data'],
        ['label' => 'Exception Report',    'href' => '/job_fair_exception_report.php','icon' => 'bi-exclamation-triangle'],
        ['label' => 'Notifications',       'href' => '/notifications.php',            'icon' => 'bi-bell'],
    ];
}

$uploadLabel = $latestUpload ? date('d M Y', strtotime((string) $latestUpload)) : 'No data uploaded yet';

render_header('Dashboard');
?>
<div class="page-header">
    <div class="page-title-group">
        <span class="page-title-icon"><i class="bi bi-grid-1x2"></i></span>
        <div>
            <h1>Welcome, <?= esc($user['name']) ?></h1>
            <p class="page-subtitle">
                <span class="badge bg-primary-subtle text-primary-emphasis"><?= esc(role_label($user['role'])) ?></span>
                <span class="ms-1">Here is the current post job fair tracking overview.</span>
            </p>
        </div>
    </div>
    <div class="page-actions">
        <span class="data-meta">
            <i class="bi bi-clock-history me-1"></i>Latest data upload: <strong><?= esc($uploadLabel) ?></strong>
        </span>
    </div>
</div>

<div class="row g-3 mb-1">
    <?php foreach ($kpiKeys as $kpiKey): ?>
        <?php $kpi = $allKpis[$kpiKey]; ?>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-<?= esc($kpi['tone']) ?> h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div>
                        <p class="stat-label"><?= esc($kpi['label']) ?></p>
                        <p class="stat-value"><?= number_format((int) $kpi['value']) ?></p>
                        <?php if ($kpi['link']): ?>
                            <a class="stat-link" href="<?= esc($kpi['link']) ?>"><?= esc($kpi['link_text']) ?> <i class="bi bi-arrow-right-short"></i></a>
                        <?php elseif ($kpi['link_text']): ?>
                            <span class="data-meta"><?= esc($kpi['link_text']) ?></span>
                        <?php endif; ?>
                    </div>
                    <span class="stat-icon-box tone-<?= esc($kpi['tone']) ?>"><i class="bi <?= esc($kpi['icon']) ?>"></i></span>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-lightning-charge-fill text-primary me-1"></i>Quick Actions</h2>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($quickActions as $action): ?>
                <a class="btn btn-outline-primary btn-sm" href="<?= esc($action['href']) ?>">
                    <i class="bi <?= esc($action['icon']) ?> me-1"></i><?= esc($action['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (!$isAdmin): ?>
<div class="card table-card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-telephone-fill text-primary me-1"></i>Last 5 Days Call Statistics</span>
        <span class="data-meta"><strong>You</strong> / Team &mdash; each cell shows your count vs the team total.</span>
        <a class="btn btn-sm btn-outline-primary" href="/call_history_report.php">Open Call History Report</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th rowspan="2">Date</th>
                    <th colspan="3" class="text-center">Stage</th>
                    <th colspan="3" class="text-center">Call Status</th>
                    <th rowspan="2" class="text-end">Total</th>
                </tr>
                <tr>
                    <th class="text-end">Employer</th>
                    <th class="text-end">Candidate</th>
                    <th class="text-end">Aggregator</th>
                    <th class="text-end">Attended</th>
                    <th class="text-end">Not Attended</th>
                    <th class="text-end">Invalid Number</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $callTotals = [
                        'employer_calls' => 0, 'candidate_calls' => 0, 'aggregator_calls' => 0,
                        'attended' => 0, 'not_attended' => 0, 'invalid_number' => 0, 'total_calls' => 0,
                        'mine_employer' => 0, 'mine_candidate' => 0, 'mine_aggregator' => 0,
                        'mine_attended' => 0, 'mine_not_attended' => 0, 'mine_invalid_number' => 0, 'mine_total' => 0,
                    ];
                    $anyCalls = false;
                    $renderCmpCell = static function (int $mine, int $total): string {
                        if ($total === 0 && $mine === 0) {
                            return '<span class="text-muted">0</span>';
                        }
                        $minePart = $mine > 0
                            ? '<strong class="text-primary">' . number_format($mine) . '</strong>'
                            : '<span class="text-muted">' . number_format($mine) . '</span>';
                        return $minePart . ' <span class="text-muted">/ ' . number_format($total) . '</span>';
                    };
                ?>
                <?php foreach ($last5DaysDates as $dateKey): ?>
                    <?php $r = $last5DaysCallByDate[$dateKey] ?? null; ?>
                    <?php
                        $employer = (int) ($r['employer_calls'] ?? 0);
                        $candidate = (int) ($r['candidate_calls'] ?? 0);
                        $aggregator = (int) ($r['aggregator_calls'] ?? 0);
                        $attended = (int) ($r['attended'] ?? 0);
                        $notAttended = (int) ($r['not_attended'] ?? 0);
                        $invalid = (int) ($r['invalid_number'] ?? 0);
                        $total = (int) ($r['total_calls'] ?? 0);
                        $mineEmployer = (int) ($r['mine_employer'] ?? 0);
                        $mineCandidate = (int) ($r['mine_candidate'] ?? 0);
                        $mineAggregator = (int) ($r['mine_aggregator'] ?? 0);
                        $mineAttended = (int) ($r['mine_attended'] ?? 0);
                        $mineNotAttended = (int) ($r['mine_not_attended'] ?? 0);
                        $mineInvalid = (int) ($r['mine_invalid_number'] ?? 0);
                        $mineTotal = (int) ($r['mine_total'] ?? 0);
                        $callTotals['employer_calls'] += $employer;
                        $callTotals['candidate_calls'] += $candidate;
                        $callTotals['aggregator_calls'] += $aggregator;
                        $callTotals['attended'] += $attended;
                        $callTotals['not_attended'] += $notAttended;
                        $callTotals['invalid_number'] += $invalid;
                        $callTotals['total_calls'] += $total;
                        $callTotals['mine_employer'] += $mineEmployer;
                        $callTotals['mine_candidate'] += $mineCandidate;
                        $callTotals['mine_aggregator'] += $mineAggregator;
                        $callTotals['mine_attended'] += $mineAttended;
                        $callTotals['mine_not_attended'] += $mineNotAttended;
                        $callTotals['mine_invalid_number'] += $mineInvalid;
                        $callTotals['mine_total'] += $mineTotal;
                        if ($total > 0) { $anyCalls = true; }
                    ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= esc(date('d M Y', strtotime($dateKey))) ?></div>
                            <div class="small text-muted"><?= esc(date('l', strtotime($dateKey))) ?><?= $dateKey === date('Y-m-d') ? ' · Today' : '' ?></div>
                        </td>
                        <td class="text-end"><?= $renderCmpCell($mineEmployer, $employer) ?></td>
                        <td class="text-end"><?= $renderCmpCell($mineCandidate, $candidate) ?></td>
                        <td class="text-end"><?= $renderCmpCell($mineAggregator, $aggregator) ?></td>
                        <td class="text-end"><?= $renderCmpCell($mineAttended, $attended) ?></td>
                        <td class="text-end"><?= $renderCmpCell($mineNotAttended, $notAttended) ?></td>
                        <td class="text-end"><?= $renderCmpCell($mineInvalid, $invalid) ?></td>
                        <td class="text-end"><?= $renderCmpCell($mineTotal, $total) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="table-secondary fw-semibold">
                    <td>Total (last 5 days)</td>
                    <td class="text-end"><?= $renderCmpCell($callTotals['mine_employer'], $callTotals['employer_calls']) ?></td>
                    <td class="text-end"><?= $renderCmpCell($callTotals['mine_candidate'], $callTotals['candidate_calls']) ?></td>
                    <td class="text-end"><?= $renderCmpCell($callTotals['mine_aggregator'], $callTotals['aggregator_calls']) ?></td>
                    <td class="text-end"><?= $renderCmpCell($callTotals['mine_attended'], $callTotals['attended']) ?></td>
                    <td class="text-end"><?= $renderCmpCell($callTotals['mine_not_attended'], $callTotals['not_attended']) ?></td>
                    <td class="text-end"><?= $renderCmpCell($callTotals['mine_invalid_number'], $callTotals['invalid_number']) ?></td>
                    <td class="text-end"><?= $renderCmpCell($callTotals['mine_total'], $callTotals['total_calls']) ?></td>
                </tr>
                <?php if (!$anyCalls): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-telephone-x"></i>No calls logged in the last 5 days.</div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card table-card mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-calendar-event text-primary me-1"></i>Job Fair wise Status</span>
        <?php if (!$isDistrictUser): ?>
            <a class="btn btn-sm btn-outline-primary" href="/job_fair_results.php">Open Job Fair Data</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>Job Fair No</th>
                        <?php foreach ($pivotStatuses as $pivotStatus): ?>
                            <th class="text-end"><?= esc($pivotStatus) ?></th>
                        <?php endforeach; ?>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($pivotData === []): ?>
                    <tr><td colspan="<?= count($pivotStatuses) + 3 ?>">
                        <div class="empty-state"><i class="bi bi-inbox"></i>No job fair result data available.</div>
                    </td></tr>
                <?php endif; ?>
                <?php $columnTotals = array_fill_keys($pivotStatuses, 0); $grandTotal = 0; ?>
                <?php $rowIndex = 1; ?>
                <?php foreach ($pivotData as $jobFairNo => $statusCounts): ?>
                    <?php $rowTotal = 0; ?>
                    <tr>
                        <td><?= $rowIndex ?></td>
                        <td class="fw-semibold"><?= esc($jobFairNo) ?></td>
                        <?php foreach ($pivotStatuses as $pivotStatus): ?>
                            <?php $value = (int) ($statusCounts[$pivotStatus] ?? 0); $rowTotal += $value; $columnTotals[$pivotStatus] += $value; ?>
                            <td class="text-end"><?= number_format($value) ?></td>
                        <?php endforeach; ?>
                        <td class="text-end"><strong><?= number_format($rowTotal) ?></strong></td>
                    </tr>
                    <?php $grandTotal += $rowTotal; ?>
                    <?php $rowIndex++; ?>
                <?php endforeach; ?>
                <?php if ($pivotData !== []): ?>
                    <tr class="table-light">
                        <td colspan="2"><strong>Total</strong></td>
                        <?php foreach ($pivotStatuses as $pivotStatus): ?>
                            <td class="text-end"><strong><?= number_format($columnTotals[$pivotStatus]) ?></strong></td>
                        <?php endforeach; ?>
                        <td class="text-end"><strong><?= number_format($grandTotal) ?></strong></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card table-card mt-3">
    <div class="card-header"><i class="bi bi-geo-alt-fill text-primary me-1"></i>District wise Record Count</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>District</th>
                        <th class="text-end">Selected</th>
                        <th class="text-end">Shortlisted</th>
                        <th class="text-end">On Hold</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Shortlisted Selected</th>
                        <th class="text-end">Total Selected</th>
                        <th class="text-end">Offer Letters</th>
                        <th class="text-end">% Offer Letters</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($districtRows === []): ?>
                    <tr><td colspan="10">
                        <div class="empty-state"><i class="bi bi-inbox"></i>No district-wise data available.</div>
                    </td></tr>
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
                        <td class="fw-semibold"><?= esc($districtRow['district']) ?></td>
                        <td class="text-end"><?= number_format($selected) ?></td>
                        <td class="text-end"><?= number_format($shortlisted) ?></td>
                        <td class="text-end"><?= number_format($onHold) ?></td>
                        <td class="text-end"><?= number_format($total) ?></td>
                        <td class="text-end"><?= number_format($shortlistedSelected) ?></td>
                        <td class="text-end"><?= number_format($totalSelected) ?></td>
                        <td class="text-end"><?= number_format($offerLetterGenerated) ?></td>
                        <td class="text-end"><?= $offerLetterPercentage ?>%</td>
                    </tr>
                    <?php $districtIndex++; ?>
                <?php endforeach; ?>
                <?php if ($districtRows !== []): ?>
                    <?php
                        $grandOfferLetterPercentage = $districtTotals['total_selected_count'] > 0
                            ? round(($districtTotals['offer_letter_generated_count'] / $districtTotals['total_selected_count']) * 100, 2)
                            : 0;
                    ?>
                    <tr class="table-light">
                        <td colspan="2"><strong>Total</strong></td>
                        <td class="text-end"><strong><?= number_format($districtTotals['selected_count']) ?></strong></td>
                        <td class="text-end"><strong><?= number_format($districtTotals['shortlisted_count']) ?></strong></td>
                        <td class="text-end"><strong><?= number_format($districtTotals['on_hold_count']) ?></strong></td>
                        <td class="text-end"><strong><?= number_format($districtTotals['total_count']) ?></strong></td>
                        <td class="text-end"><strong><?= number_format($districtTotals['shortlisted_selected_count']) ?></strong></td>
                        <td class="text-end"><strong><?= number_format($districtTotals['total_selected_count']) ?></strong></td>
                        <td class="text-end"><strong><?= number_format($districtTotals['offer_letter_generated_count']) ?></strong></td>
                        <td class="text-end"><strong><?= $grandOfferLetterPercentage ?>%</strong></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php render_footer(); ?>
