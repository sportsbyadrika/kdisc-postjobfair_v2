<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
$uid = $user['id'];
$isDistrictUser = is_district_user($user);
$isAdmin = is_admin($user);
$isEdms  = is_edms($user);
// EDMS gets the admin-style dashboard (DPMU snapshot + photos gallery)
// but is NOT is_admin (Demand Side / Statistics / Administration stay
// admin-only). Use this flag for dashboard-view gates only.
$isDashboardAdminView = $isAdmin || $isEdms;

// District PMU + State PMU share a scoped dashboard (office profile
// status + asset counts). Redirect early so they don't fall through
// to the generic Post Job Fair blocks below.
if (is_pmu_user($user)) {
    header('Location: /district_pmu_dashboard.php');
    exit;
}

$totalUsers = 0;
$demandEmployerCount = 0;
$demandJobCount = 0;
$demandOpenPositions = 0;
$demandStatusValid = 0;
$demandStatusInvalid = 0;
$demandStatusCorrected = 0;
$demandStatusNotStarted = 0;
$demandStatusValidPositions = 0;
$demandStatusInvalidPositions = 0;
$demandStatusCorrectedPositions = 0;
$demandStatusNotStartedPositions = 0;
if ($isDashboardAdminView) {
    $totalUsers = (int) db()->query('SELECT COUNT(*) FROM users WHERE active_status = 1')->fetchColumn();
    // Demand-side + District PMU snapshot for the admin / EDMS dashboard.
    // Wrapped in a try so a fresh install without those tables yet still
    // renders the rest of the page.
    try {
        require_once __DIR__ . '/includes/demand_side_helpers.php';
        demand_side_bootstrap();
        $demandEmployerCount = (int) db()->query('SELECT COUNT(*) FROM demand_employers')->fetchColumn();
        $demandJobCount = (int) db()->query('SELECT COUNT(*) FROM demand_employer_jobs')->fetchColumn();
        $demandOpenPositions = (int) db()->query('SELECT COALESCE(SUM(open_positions), 0) FROM demand_employer_jobs')->fetchColumn();
        $demandStatusValid = (int) db()->query("SELECT COUNT(*) FROM demand_employer_jobs WHERE status = 'Valid'")->fetchColumn();
        $demandStatusInvalid = (int) db()->query("SELECT COUNT(*) FROM demand_employer_jobs WHERE status = 'Invalid'")->fetchColumn();
        $demandStatusCorrected = (int) db()->query("SELECT COUNT(*) FROM demand_employer_jobs WHERE status = 'Corrected'")->fetchColumn();
        $demandStatusNotStarted = (int) db()->query("SELECT COUNT(*) FROM demand_employer_jobs WHERE status IS NULL OR TRIM(status) = ''")->fetchColumn();
        // Affected open positions per status. Corrected uses the operator's
        // corrected_open_position when set, otherwise the original count.
        $demandStatusValidPositions = (int) db()->query("SELECT COALESCE(SUM(open_positions), 0) FROM demand_employer_jobs WHERE status = 'Valid'")->fetchColumn();
        $demandStatusInvalidPositions = (int) db()->query("SELECT COALESCE(SUM(open_positions), 0) FROM demand_employer_jobs WHERE status = 'Invalid'")->fetchColumn();
        $demandStatusCorrectedPositions = (int) db()->query("SELECT COALESCE(SUM(COALESCE(corrected_open_position, open_positions)), 0) FROM demand_employer_jobs WHERE status = 'Corrected'")->fetchColumn();
        $demandStatusNotStartedPositions = (int) db()->query("SELECT COALESCE(SUM(open_positions), 0) FROM demand_employer_jobs WHERE status IS NULL OR TRIM(status) = ''")->fetchColumn();
        // Per-job-agency employer count for the Employers card.
        $demandEmployerAgencyBreakdown = db()->query(
            "SELECT COALESCE(NULLIF(TRIM(jobagency), ''), '(Unknown)') AS agency_label,
                    COUNT(*) AS employer_count
             FROM demand_employers
             GROUP BY COALESCE(NULLIF(TRIM(jobagency), ''), '(Unknown)')
             ORDER BY employer_count DESC"
        )->fetchAll();
        // Per-source-status breakdown for the Job Positions card. Uses
        // job_status_data (the DWMS source status like Active / Expired /
        // Closed / On Hold), NOT the in-app verification `status` column.
        // GROUPs on the normalised expression rather than an alias named
        // `status` — an alias with the same name as a real column silently
        // resolves back to the column in MySQL, which was collapsing every
        // group into the wrong bucket (all rows appeared as "Closed").
        $demandJobStatusBreakdown = db()->query(
            "SELECT COALESCE(NULLIF(TRIM(job_status_data), ''), '(Unknown)') AS status_label,
                    COUNT(*) AS jobs_count,
                    COALESCE(SUM(open_positions), 0) AS positions_sum
             FROM demand_employer_jobs
             GROUP BY COALESCE(NULLIF(TRIM(job_status_data), ''), '(Unknown)')
             ORDER BY jobs_count DESC"
        )->fetchAll();
        // Per-(verification-status, source-status) breakdown so each
        // Verification Status card can show the same DWMS job_status_data
        // chip block as the Jobs card, scoped to that verification bucket.
        $rawVerBreakdown = db()->query(
            "SELECT COALESCE(NULLIF(TRIM(status), ''), '') AS verification_status,
                    COALESCE(NULLIF(TRIM(job_status_data), ''), '(Unknown)') AS status_label,
                    COUNT(*) AS jobs_count,
                    COALESCE(SUM(open_positions), 0) AS positions_sum
             FROM demand_employer_jobs
             GROUP BY 1, 2
             ORDER BY 1 ASC, jobs_count DESC"
        )->fetchAll();
        $demandVerificationStatusBreakdown = [];
        foreach ($rawVerBreakdown as $r) {
            $demandVerificationStatusBreakdown[(string) $r['verification_status']][] = [
                'status_label'  => (string) ($r['status_label'] ?? ''),
                'jobs_count'    => (int) ($r['jobs_count'] ?? 0),
                'positions_sum' => (int) ($r['positions_sum'] ?? 0),
            ];
        }
    } catch (Throwable $e) { /* demand-side tables not yet available */ }

    /* -------------------------------------------------------------------- *
     * District PMU snapshot — office-profile coverage + asset register
     * activity. Wrapped in try/catch so a fresh install where the tables
     * haven't been bootstrapped yet still renders the rest of the page.
     * -------------------------------------------------------------------- */
    $dpmuUserCount            = 0;
    $dpmuDistrictsCoveredCount = 0;
    $dpmuProfilesFilledCount  = 0;
    $dpmuAssetsTotal          = 0;
    $dpmuAssetsPending        = 0;
    $dpmuAssetsSubmitted      = 0;
    $dpmuSubmissionsCount     = 0;
    $dpmuTypeBreakdown        = [];   // [{name, count}]
    $dpmuDistrictProfiles     = [];   // [district => bool]
    $dpmuSubmissionsByDistrict = [];  // [{district, submissions, assets}]
    $dpmuPhotoRows            = [];   // [{district, office_name, building_photo_path, room_photo_path}]
    // Ensure the district_pmu_* tables exist before we query them.
    // Runs on its own try — a bootstrap failure only skips the DPMU
    // block, it doesn't zero out counters that a later query populates.
    try {
        require_once __DIR__ . '/includes/district_pmu_helpers.php';
        district_pmu_bootstrap();
    } catch (Throwable $e) { /* schema not available yet */ }

    // Every metric below runs in its OWN try/catch so one bad query
    // (e.g. a stale column) can't quietly zero out the others.
    try {
        $dpmuUserCount = (int) db()->query(
            "SELECT COUNT(*) FROM users WHERE role IN ('district_pmu', 'state_pmu') AND active_status = 1"
        )->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }

    try {
        // Distinct districts across every active PMU user's
        // assigned_districts CSV. Small enough to parse in PHP.
        $districtsSet = [];
        $ur = db()->query("SELECT assigned_districts FROM users
            WHERE role IN ('district_pmu', 'state_pmu') AND active_status = 1
              AND assigned_districts IS NOT NULL AND TRIM(assigned_districts) <> ''");
        foreach ($ur->fetchAll() as $row) {
            foreach (explode(',', (string) $row['assigned_districts']) as $p) {
                $p = trim($p);
                if ($p !== '') $districtsSet[$p] = true;
            }
        }
        $dpmuDistrictsCoveredCount = count($districtsSet);
        foreach ($districtsSet as $d => $_) { $dpmuDistrictProfiles[$d] = false; }

        // Which of those districts have a profile row.
        if ($dpmuDistrictProfiles !== []) {
            $ph = implode(',', array_fill(0, count($dpmuDistrictProfiles), '?'));
            $ps = db()->prepare("SELECT district FROM district_pmu_office_profile
                WHERE district IN ($ph)");
            $ps->execute(array_keys($dpmuDistrictProfiles));
            foreach ($ps->fetchAll() as $row) {
                if (isset($dpmuDistrictProfiles[(string) $row['district']])) {
                    $dpmuDistrictProfiles[(string) $row['district']] = true;
                    $dpmuProfilesFilledCount++;
                }
            }
        }
    } catch (Throwable $e) { /* ignore */ }

    // Asset totals — split so total/pending/submitted come from three
    // independent COUNT queries instead of one CASE/SUM that would zero
    // out all three if a single column was mis-named on a stale install.
    try {
        $dpmuAssetsTotal = (int) db()->query('SELECT COUNT(*) FROM district_pmu_assets')->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        $dpmuAssetsPending = (int) db()->query('SELECT COUNT(*) FROM district_pmu_assets WHERE submission_id IS NULL')->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        $dpmuAssetsSubmitted = (int) db()->query('SELECT COUNT(*) FROM district_pmu_assets WHERE submission_id IS NOT NULL')->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }

    try {
        $dpmuTypeBreakdown = db()->query("SELECT COALESCE(t.name, '(Unknown)') AS name, COUNT(*) AS c
            FROM district_pmu_assets a
            LEFT JOIN district_pmu_asset_types t ON t.id = a.asset_type_id
            GROUP BY COALESCE(t.name, '(Unknown)')
            ORDER BY c DESC")->fetchAll();
    } catch (Throwable $e) { /* ignore */ }

    try {
        $dpmuSubmissionsCount = (int) db()->query('SELECT COUNT(*) FROM district_pmu_asset_submissions')->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        $dpmuSubmissionsByDistrict = db()->query("SELECT district, COUNT(*) AS submissions,
                COALESCE(SUM(asset_count), 0) AS assets
            FROM district_pmu_asset_submissions
            GROUP BY district
            ORDER BY submissions DESC, district ASC")->fetchAll();
    } catch (Throwable $e) { /* ignore */ }

    try {
        $dpmuPhotoRows = db()->query("SELECT district, office_name, building_photo_path, room_photo_path, updated_at
            FROM district_pmu_office_profile
            WHERE building_photo_path IS NOT NULL OR room_photo_path IS NOT NULL
            ORDER BY district ASC")->fetchAll();
    } catch (Throwable $e) { /* ignore */ }
}

/* Semantic tone for each source-status label — same buckets the Job Status
 * badge uses on the Employer Jobs table, kept here so the dashboard chips
 * read the same way. Returns a Bootstrap "text-bg-*" suffix. */
if (!function_exists('demand_job_source_status_tone')) {
    function demand_job_source_status_tone(string $label): string {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $label));
        if ($key === '' || $key === 'unknown') return 'secondary';
        if (in_array($key, ['active', 'open', 'live', 'valid'], true)) return 'success';
        if (in_array($key, ['expired', 'closed', 'inactive', 'invalid'], true)) return 'danger';
        if (in_array($key, ['draft', 'pending', 'unpublished', 'onhold', 'hold'], true)) return 'warning';
        if (in_array($key, ['corrected', 'updated', 'modified'], true)) return 'info';
        return 'secondary';
    }
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
if (!$isDashboardAdminView) {
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
// Count notifications visible to the logged-in user (mirrors the strict
// visibility logic in notifications.php). Same rule for every role - admin
// included - so specific notifications stay with the named recipient and
// group messages stay inside the group.
$rolesByToWhom = ['State CRM' => 'crm_member', 'State DSM' => 'state_dsm', 'District User' => 'district_user'];
$myNotificationCount = 0;
try {
    $roleToWhom = array_search((string) ($user['role'] ?? ''), $rolesByToWhom, true) ?: '';
    $cs = db()->prepare(
        "SELECT COUNT(*) FROM activities WHERE active_status = 1 AND status <> 'closed'
         AND (owner_user_id = ? OR (to_whom = ? AND (read_by = 'any' OR target_user_id = ?)))"
    );
    $cs->execute([(int) $user['id'], $roleToWhom, (int) $user['id']]);
    $myNotificationCount = (int) $cs->fetchColumn();
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

if ($isDashboardAdminView) {
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

<?php if ($isDashboardAdminView): ?>
<div class="mb-1">
    <h2 class="h6 text-muted text-uppercase mb-2"><i class="bi bi-building me-1"></i>Demand Side Snapshot <span class="text-muted small">(based on DWMS database)</span></h2>
    <div class="row g-3">
        <div class="col-6 col-md-4">
            <div class="card card-stat accent-info h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div class="w-100">
                        <p class="stat-label">Employers</p>
                        <p class="stat-value"><?= number_format($demandEmployerCount) ?></p>
                        <a class="stat-link" href="/demand_side_employers.php">Open list <i class="bi bi-arrow-right-short"></i></a>
                        <?php if (!empty($demandEmployerAgencyBreakdown)): ?>
                            <div class="mt-2">
                                <div class="small text-muted text-uppercase mb-1" style="letter-spacing:.03em;">By job agency</div>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($demandEmployerAgencyBreakdown as $ab): ?>
                                        <?php
                                            $agencyLabel = (string) ($ab['agency_label'] ?? '');
                                            $agencyCnt   = (int) ($ab['employer_count'] ?? 0);
                                            // Same fallback tone rule the status helper uses for
                                            // '(Unknown)'; every real agency reads as info blue so
                                            // the list stays scannable without a colour code the
                                            // operator has to memorise.
                                            $agencyTone  = $agencyLabel === '(Unknown)' ? 'secondary' : 'info';
                                            $agencyHref  = $agencyLabel === '(Unknown)'
                                                ? '/demand_side_employers.php'
                                                : '/demand_side_employers.php?jobagency=' . urlencode($agencyLabel);
                                        ?>
                                        <a class="badge text-bg-<?= esc($agencyTone) ?> text-decoration-none"
                                           href="<?= esc($agencyHref) ?>"
                                           title="<?= number_format($agencyCnt) ?> employer(s) under <?= esc($agencyLabel) ?>">
                                            <?= esc($agencyLabel) ?> <span class="fw-bold ms-1"><?= number_format($agencyCnt) ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="stat-icon-box tone-info"><i class="bi bi-building"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card card-stat accent-primary h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div class="w-100">
                        <p class="stat-label">Job Titles</p>
                        <p class="stat-value"><?= number_format($demandJobCount) ?></p>
                        <?php
                            $netJobs = max(0, $demandJobCount - $demandStatusInvalid);
                            $netTone = ($demandStatusInvalid > 0) ? 'danger' : 'success';
                        ?>
                        <div class="small mb-1">
                            <span class="badge text-bg-<?= esc($netTone) ?>"><i class="bi bi-dash-circle me-1"></i>Net <?= number_format($netJobs) ?></span>
                            <span class="text-muted ms-1">(jobs &minus; invalid)</span>
                        </div>
                        <span class="data-meta">Across all employers</span>
                        <?php if (!empty($demandJobStatusBreakdown)): ?>
                            <div class="mt-2">
                                <div class="small text-muted text-uppercase mb-1" style="letter-spacing:.03em;">By job status</div>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($demandJobStatusBreakdown as $sb): ?>
                                        <?php
                                            $label = (string) ($sb['status_label'] ?? '');
                                            $jobs  = (int) ($sb['jobs_count'] ?? 0);
                                            $pos   = (int) ($sb['positions_sum'] ?? 0);
                                            $tone  = demand_job_source_status_tone($label);
                                        ?>
                                        <span class="badge text-bg-<?= esc($tone) ?>"
                                              title="<?= number_format($jobs) ?> job(s) &middot; <?= number_format($pos) ?> position(s)">
                                            <?= esc($label) ?> <span class="fw-bold ms-1"><?= number_format($jobs) ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="stat-icon-box tone-primary"><i class="bi bi-briefcase"></i></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card card-stat accent-success h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div class="w-100">
                        <p class="stat-label">Job Vacancies</p>
                        <p class="stat-value"><?= number_format($demandOpenPositions) ?></p>
                        <?php
                            $netPositions = max(0, $demandOpenPositions - $demandStatusInvalidPositions);
                            $netPosTone   = ($demandStatusInvalidPositions > 0) ? 'danger' : 'success';
                        ?>
                        <div class="small mb-1">
                            <span class="badge text-bg-<?= esc($netPosTone) ?>"><i class="bi bi-dash-circle me-1"></i>Net <?= number_format($netPositions) ?></span>
                            <span class="text-muted ms-1">(positions &minus; invalid)</span>
                        </div>
                        <span class="data-meta">Sum of open_positions</span>
                        <?php if (!empty($demandJobStatusBreakdown)): ?>
                            <div class="mt-2">
                                <div class="small text-muted text-uppercase mb-1" style="letter-spacing:.03em;">Positions by job status</div>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($demandJobStatusBreakdown as $sb): ?>
                                        <?php
                                            $label = (string) ($sb['status_label'] ?? '');
                                            $jobs  = (int) ($sb['jobs_count'] ?? 0);
                                            $pos   = (int) ($sb['positions_sum'] ?? 0);
                                            $tone  = demand_job_source_status_tone($label);
                                        ?>
                                        <span class="badge text-bg-<?= esc($tone) ?>"
                                              title="<?= number_format($pos) ?> position(s) &middot; <?= number_format($jobs) ?> job(s)">
                                            <?= esc($label) ?> <span class="fw-bold ms-1"><?= number_format($pos) ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="stat-icon-box tone-success"><i class="bi bi-person-plus"></i></span>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h6 text-muted text-uppercase mb-2 mt-4"><i class="bi bi-clipboard-check me-1"></i>Demand Side &middot; Verification Status</h2>
    <div class="row g-3">
        <?php
            // Only the Valid card's positions read as "verified"; Invalid and
            // Corrected keep "affected" since those aren't verified yet.
            $editStatCards = [
                ['label' => 'Valid',           'value' => $demandStatusValid,       'positions' => $demandStatusValidPositions,     'positions_label' => 'verified open positions', 'tone' => 'success', 'icon' => 'bi-check-circle-fill',  'ver_key' => 'Valid'],
                ['label' => 'Invalid',         'value' => $demandStatusInvalid,     'positions' => $demandStatusInvalidPositions,   'positions_label' => 'affected open positions', 'tone' => 'danger',  'icon' => 'bi-x-circle-fill',      'ver_key' => 'Invalid'],
                ['label' => 'Corrected',       'value' => $demandStatusCorrected,   'positions' => $demandStatusCorrectedPositions, 'positions_label' => 'affected open positions', 'tone' => 'warning', 'icon' => 'bi-pencil-square',      'ver_key' => 'Corrected'],
                ['label' => 'Not Yet Started', 'value' => $demandStatusNotStarted,  'positions' => $demandStatusNotStartedPositions, 'positions_label' => 'affected open positions', 'tone' => 'neutral', 'icon' => 'bi-hourglass-split',    'ver_key' => ''],
            ];
        ?>
        <?php foreach ($editStatCards as $card): ?>
            <?php $cardBreakdown = $demandVerificationStatusBreakdown[$card['ver_key']] ?? []; ?>
            <div class="col-6 col-md-3">
                <div class="card card-stat accent-<?= esc($card['tone']) ?> h-100">
                    <div class="card-body d-flex align-items-start justify-content-between gap-2">
                        <div class="w-100">
                            <p class="stat-label"><?= esc($card['label']) ?></p>
                            <p class="stat-value"><?= number_format((int) $card['value']) ?></p>
                            <?php /* The former "N verified/affected open positions"
                                     line was removed — it summed open_positions for
                                     three cards but corrected_open_position on the
                                     Corrected card, so the number didn't match the
                                     chip totals below. The single "N position(s)
                                     across the chips" line is now the authoritative
                                     positions figure per card. */ ?>
                            <a class="stat-link" href="/demand_side_stats.php">View statistics <i class="bi bi-arrow-right-short"></i></a>
                            <?php if ($cardBreakdown !== []): ?>
                                <div class="mt-2">
                                    <div class="small text-muted text-uppercase mb-1" style="letter-spacing:.03em;">By job status</div>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($cardBreakdown as $sb): ?>
                                            <?php
                                                $label = (string) $sb['status_label'];
                                                $jobs  = (int) $sb['jobs_count'];
                                                $pos   = (int) $sb['positions_sum'];
                                                $tone  = demand_job_source_status_tone($label);
                                            ?>
                                            <span class="badge text-bg-<?= esc($tone) ?>"
                                                  title="<?= number_format($jobs) ?> job(s) &middot; <?= number_format($pos) ?> position(s)">
                                                <?= esc($label) ?>
                                                <span class="fw-bold ms-1"><?= number_format($jobs) ?></span>
                                                <span class="fw-normal opacity-75 ms-1">&middot; <?= number_format($pos) ?> pos</span>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php
                                        // Same weighted per-status positions row summed across
                                        // this card's bucket. Emitted only when the operator can
                                        // reconcile against the Job Positions card — the invariant
                                        // is: Valid + Invalid + Corrected + Not Yet Started =
                                        // Job Positions card's chip for the same status.
                                        $cardPosTotal = 0;
                                        foreach ($cardBreakdown as $sb2) { $cardPosTotal += (int) $sb2['positions_sum']; }
                                    ?>
                                    <?php if ($cardPosTotal > 0): ?>
                                        <div class="small text-muted mt-1">
                                            <i class="bi bi-person-plus me-1"></i><strong><?= number_format($cardPosTotal) ?></strong> position(s) across the chips
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($card['label'] === 'Not Yet Started'): ?>
                            <button type="button"
                                    class="stat-icon-box tone-<?= esc($card['tone']) ?> border-0 bg-transparent p-0 js-verification-jobs-btn"
                                    data-bs-toggle="modal" data-bs-target="#verificationJobsModal"
                                    data-verification="NotYetStarted"
                                    data-label="Not Yet Started"
                                    title="Show the jobs in this bucket"
                                    style="cursor:pointer;">
                                <i class="bi <?= esc($card['icon']) ?>"></i>
                            </button>
                        <?php else: ?>
                            <span class="stat-icon-box tone-<?= esc($card['tone']) ?>"><i class="bi <?= esc($card['icon']) ?>"></i></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isDashboardAdminView): ?>
    <?php
        $dpmuProfilePct  = $dpmuDistrictsCoveredCount > 0
            ? (int) round(($dpmuProfilesFilledCount / $dpmuDistrictsCoveredCount) * 100)
            : 0;
        $dpmuProfileTone = $dpmuProfilePct >= 90 ? 'success' : ($dpmuProfilePct >= 50 ? 'warning' : 'danger');
        $dpmuAssetPendPct = $dpmuAssetsTotal > 0 ? (int) round(($dpmuAssetsSubmitted / $dpmuAssetsTotal) * 100) : 0;
    ?>
    <h2 class="h6 text-muted text-uppercase mb-2 mt-4"><i class="bi bi-building-check me-1"></i>District PMU Snapshot</h2>
    <div class="row g-3 mb-1">
        <div class="col-6 col-md-3">
            <div class="card card-stat accent-info h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div class="w-100">
                        <p class="stat-label">District PMU Users</p>
                        <p class="stat-value"><?= number_format($dpmuUserCount) ?></p>
                        <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i><strong><?= number_format($dpmuDistrictsCoveredCount) ?></strong> distinct district(s) covered</div>
                        <a class="stat-link" href="/users.php?role=district_pmu">Manage users <i class="bi bi-arrow-right-short"></i></a>
                    </div>
                    <span class="stat-icon-box tone-info"><i class="bi bi-people-fill"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-stat accent-<?= esc($dpmuProfileTone) ?> h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div class="w-100">
                        <p class="stat-label">Office Profiles Set Up</p>
                        <p class="stat-value"><?= number_format($dpmuProfilesFilledCount) ?> / <?= number_format($dpmuDistrictsCoveredCount) ?></p>
                        <div class="progress mb-1" style="height:8px; max-width:180px;">
                            <div class="progress-bar bg-<?= esc($dpmuProfileTone) ?>" role="progressbar" style="width: <?= (int) $dpmuProfilePct ?>%;"></div>
                        </div>
                        <div class="small text-muted mb-1"><strong><?= $dpmuProfilePct ?>%</strong> coverage</div>
                        <?php $missing = array_keys(array_filter($dpmuDistrictProfiles, static fn(bool $set): bool => !$set)); ?>
                        <?php if ($missing !== []): ?>
                            <div class="small text-muted"><i class="bi bi-exclamation-circle me-1"></i>Missing: <?= esc(implode(', ', array_slice($missing, 0, 3))) ?><?= count($missing) > 3 ? ' +' . (count($missing) - 3) . ' more' : '' ?></div>
                        <?php endif; ?>
                    </div>
                    <span class="stat-icon-box tone-<?= esc($dpmuProfileTone) ?>"><i class="bi bi-building-check"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-stat accent-primary h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div class="w-100">
                        <p class="stat-label">Asset Register Entries</p>
                        <p class="stat-value"><?= number_format($dpmuAssetsTotal) ?></p>
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <span class="badge text-bg-success" title="Locked behind a submission"><i class="bi bi-lock-fill me-1"></i><?= number_format($dpmuAssetsSubmitted) ?> submitted</span>
                            <span class="badge text-bg-warning" title="Not yet part of any submission"><i class="bi bi-hourglass-split me-1"></i><?= number_format($dpmuAssetsPending) ?> pending</span>
                        </div>
                        <?php if ($dpmuTypeBreakdown !== []): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($dpmuTypeBreakdown as $tb): ?>
                                    <?php $isNonIt = str_contains(strtolower((string) $tb['name']), 'non'); ?>
                                    <span class="badge text-bg-<?= $isNonIt ? 'secondary' : 'info' ?>"><?= esc((string) $tb['name']) ?> <span class="fw-bold ms-1"><?= number_format((int) $tb['c']) ?></span></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="stat-icon-box tone-primary"><i class="bi bi-box-seam"></i></span>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card card-stat accent-success h-100">
                <div class="card-body d-flex align-items-start justify-content-between gap-2">
                    <div class="w-100">
                        <p class="stat-label">Asset Submissions</p>
                        <p class="stat-value"><?= number_format($dpmuSubmissionsCount) ?></p>
                        <div class="small text-muted mb-1"><strong><?= number_format($dpmuAssetsSubmitted) ?></strong> asset row(s) locked · <?= $dpmuAssetPendPct ?>% of all entries</div>
                        <?php if ($dpmuSubmissionsByDistrict !== []): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach (array_slice($dpmuSubmissionsByDistrict, 0, 6) as $sb): ?>
                                    <span class="badge text-bg-success" title="<?= number_format((int) $sb['assets']) ?> asset row(s) across these submissions">
                                        <?= esc((string) $sb['district']) ?> <span class="fw-bold ms-1"><?= number_format((int) $sb['submissions']) ?></span>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (count($dpmuSubmissionsByDistrict) > 6): ?>
                                    <span class="badge text-bg-light border">+<?= count($dpmuSubmissionsByDistrict) - 6 ?> more</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <span class="stat-icon-box tone-success"><i class="bi bi-check2-square"></i></span>
                </div>
            </div>
        </div>
    </div>

    <?php if ($dpmuPhotoRows !== []): ?>
        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-images text-primary me-1"></i>District PMU Office Photos</span>
                <span class="status-chip status-info"><?= number_format(count($dpmuPhotoRows)) ?> district<?= count($dpmuPhotoRows) === 1 ? '' : 's' ?> with photos</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($dpmuPhotoRows as $pr): ?>
                        <div class="col-12 col-md-6 col-xl-4">
                            <div class="border rounded p-2 h-100">
                                <div class="d-flex justify-content-between align-items-baseline mb-2">
                                    <div>
                                        <div class="fw-semibold"><?= esc((string) $pr['district']) ?></div>
                                        <?php if (trim((string) ($pr['office_name'] ?? '')) !== ''): ?>
                                            <div class="small text-muted"><?= esc((string) $pr['office_name']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted"><?= esc(substr((string) ($pr['updated_at'] ?? ''), 0, 10)) ?></div>
                                </div>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <div class="small text-muted mb-1"><i class="bi bi-building me-1"></i>Building</div>
                                        <?php if (!empty($pr['building_photo_path'])): ?>
                                            <a href="<?= esc((string) $pr['building_photo_path']) ?>" target="_blank" rel="noopener">
                                                <img src="<?= esc((string) $pr['building_photo_path']) ?>" alt="Building photo · <?= esc((string) $pr['district']) ?>"
                                                     style="width:100%; aspect-ratio: 4/3; object-fit: cover; border-radius:.25rem; border:1px solid var(--bs-border-color);">
                                            </a>
                                        <?php else: ?>
                                            <div class="d-flex align-items-center justify-content-center text-muted small border rounded"
                                                 style="width:100%; aspect-ratio: 4/3; background: var(--bs-secondary-bg);">Not uploaded</div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-6">
                                        <div class="small text-muted mb-1"><i class="bi bi-door-open me-1"></i>Room</div>
                                        <?php if (!empty($pr['room_photo_path'])): ?>
                                            <a href="<?= esc((string) $pr['room_photo_path']) ?>" target="_blank" rel="noopener">
                                                <img src="<?= esc((string) $pr['room_photo_path']) ?>" alt="Room photo · <?= esc((string) $pr['district']) ?>"
                                                     style="width:100%; aspect-ratio: 4/3; object-fit: cover; border-radius:.25rem; border:1px solid var(--bs-border-color);">
                                            </a>
                                        <?php else: ?>
                                            <div class="d-flex align-items-center justify-content-center text-muted small border rounded"
                                                 style="width:100%; aspect-ratio: 4/3; background: var(--bs-secondary-bg);">Not uploaded</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer small text-muted"><i class="bi bi-info-circle me-1"></i>Click a thumbnail to open the full-size photo in a new tab.</div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<h2 class="h6 text-muted text-uppercase mb-2 <?= $isDashboardAdminView ? 'mt-4' : '' ?>"><i class="bi bi-clipboard2-data me-1"></i>Post Job Fair Status</h2>
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

<?php if (!$isDashboardAdminView): ?>
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
<?php endif; ?>

<?php if (!$isDashboardAdminView): ?>
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

<?php if (!$isDashboardAdminView): ?>
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
<?php endif; /* !$isDashboardAdminView — hides Job Fair wise Status + District wise Record Count */ ?>

<?php if ($isDashboardAdminView): ?>
<!-- Verification bucket jobs modal — populated on click of a
     Verification Status card's icon (currently only wired for the
     Not Yet Started card). Body is fetched from a small AJAX endpoint
     so we don't emit a 5000-row DOM tree that isn't needed until the
     admin actually opens the modal. -->
<div class="modal fade" id="verificationJobsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="verificationJobsTitle"><i class="bi bi-hourglass-split me-1"></i>Jobs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="verificationJobsBody">
                <div class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Loading&hellip;</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
<script>
(function () {
    const modalEl = document.getElementById('verificationJobsModal');
    if (!modalEl) return;
    modalEl.addEventListener('show.bs.modal', (ev) => {
        const trigger = ev.relatedTarget;
        if (!trigger) return;
        const verification = trigger.getAttribute('data-verification') || '';
        const label        = trigger.getAttribute('data-label') || 'Jobs';
        const title = document.getElementById('verificationJobsTitle');
        const body  = document.getElementById('verificationJobsBody');
        if (title) title.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>' + label + ' &mdash; jobs list';
        if (body)  body.innerHTML  = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-1"></span>Loading&hellip;</div>';
        fetch('/demand_side_ajax_jobs_by_status.php?verification=' + encodeURIComponent(verification), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
        .then((r) => r.ok ? r.text() : Promise.reject(new Error('HTTP ' + r.status)))
        .then((html) => { if (body) body.innerHTML = html; })
        .catch((err) => {
            if (body) body.innerHTML = '<div class="alert alert-danger m-0">Could not load: ' + String(err).replace(/</g, '&lt;') + '</div>';
        });
    });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
