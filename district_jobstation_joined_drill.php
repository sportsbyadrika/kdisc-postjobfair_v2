<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/consolidated_report_helpers.php';
require_auth();

/**
 * Drill-down for the District wise / Job Station / Joined Status table on
 * the Consolidated Report Shortlisted / On hold tab. Lets the user click
 * any count and see the exact candidate records contributing to that cell
 * so a mismatch with the raw data can be traced back.
 */

$filters = build_consolidated_filters();
$hasDistrictParam = array_key_exists('district', $_GET);
$district = trim((string) ($_GET['district'] ?? ''));
$cohort = trim((string) ($_GET['cohort'] ?? ''));   // selected | shortlist_final | any
$joined = trim((string) ($_GET['joined'] ?? ''));   // yes | no | pending | (blank = all)

$allowedCohorts = ['selected', 'shortlist_final', 'any'];
if (!in_array($cohort, $allowedCohorts, true)) {
    $cohort = 'any';
}
$allowedJoined = ['yes', 'no', 'pending', 'future_date', ''];
if (!in_array($joined, $allowedJoined, true)) {
    $joined = '';
}
$offerLetterYes = (($_GET['offer_letter'] ?? '') === 'yes');
$futureBucket = trim((string) ($_GET['future_bucket'] ?? ''));
if (!in_array($futureBucket, ['before_today', 'today_onwards', ''], true)) {
    $futureBucket = '';
}
$hasRemarkTypeParam = array_key_exists('remark_type', $_GET);
$remarkType = trim((string) ($_GET['remark_type'] ?? ''));

$conditions = [];
$params = [];

// Cohort filter (mirrors the SQL in fetch_shortlisted_district_jobstation_joined_report).
$selectedDirect = "LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'";
$shortlistFinal = "(LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
                   AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected')";
if ($cohort === 'selected') {
    $conditions[] = $selectedDirect;
} elseif ($cohort === 'shortlist_final') {
    $conditions[] = $shortlistFinal;
} else {
    $conditions[] = "($selectedDirect OR $shortlistFinal)";
}

// District scope. Absent or empty `district` param means "all districts"
// (used by the Total row). The literal value "Unknown" maps to NULL/blank.
if (!$hasDistrictParam || $district === '') {
    // no district filter
} elseif ($district === 'Unknown') {
    $conditions[] = "(Candidate_District IS NULL OR TRIM(Candidate_District) = '')";
} else {
    $conditions[] = "TRIM(COALESCE(Candidate_District, '')) = ?";
    $params[] = $district;
}

// Joined status filter.
if ($joined === 'yes') {
    $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes'";
} elseif ($joined === 'no') {
    $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no'";
} elseif ($joined === 'pending') {
    $conditions[] = "(LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending' OR TRIM(COALESCE(Candidate_Joined_Status, '')) = '')";
} elseif ($joined === 'future_date') {
    $conditions[] = "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date'";
}

// Offer Letter Generated filter.
if ($offerLetterYes) {
    $conditions[] = "LOWER(TRIM(COALESCE(Offer_Letter_Generated, ''))) = 'yes'";
}

// Future-date bucket filter. Only relevant when joined=future_date is set.
if ($futureBucket === 'before_today') {
    $conditions[] = "Candidate_Joining_Future_Date IS NOT NULL AND DATE(Candidate_Joining_Future_Date) < CURDATE()";
} elseif ($futureBucket === 'today_onwards') {
    $conditions[] = "Candidate_Joining_Future_Date IS NOT NULL AND DATE(Candidate_Joining_Future_Date) >= CURDATE()";
}

// Candidate Join Remarks Type filter (matches the pivot's COALESCE bucket).
if ($hasRemarkTypeParam) {
    if ($remarkType === '' || $remarkType === '(No Remark Type)') {
        $conditions[] = "(Candidate_Join_Remarks_Type IS NULL OR TRIM(Candidate_Join_Remarks_Type) = '')";
    } else {
        $conditions[] = "TRIM(COALESCE(Candidate_Join_Remarks_Type, '')) = ?";
        $params[] = $remarkType;
    }
}

// Common filters (aggregator, job_fair, category) - reuse helper but it expects them in $params.
$commonConditions = build_common_conditions($filters, $params);
foreach ($commonConditions as $c) {
    $conditions[] = $c;
}

$whereClause = 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT id, Job_Fair_No, Job_Fair_Date, DWMS_ID, Candidate_Name, Mobile_Number,
        Candidate_District, Candidate_Jobstation, SDPK_District, SDPK,
        Employer_ID, Employer_Name, Job_Id, Job_Title_Name,
        Selection_Status, Shortlist_Candidate_Status,
        Confirm_Offer_Letter_Receipt_by_Candidate, confirmation_date,
        Candidate_Joined_Status, Candidate_Joined_Date, Candidate_Joining_Future_Date,
        Aggregator, Category
    FROM job_fair_result
    $whereClause
    ORDER BY Candidate_District ASC, Candidate_Jobstation ASC, Candidate_Name ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cohortLabels = [
    'selected' => 'Selected (Selection Status = Selected)',
    'shortlist_final' => 'Shortlist Final Selected (Shortlisted / On hold with Final Status = Selected)',
    'any' => 'Selected OR Shortlist Final Selected',
];
$joinedLabels = [
    'yes' => 'Yes', 'no' => 'No', 'pending' => 'Pending (incl. empty)', 'future_date' => 'Future Date', '' => 'Any',
];

$activeFilters = [];
if (($filters['aggregator'] ?? '') !== '') {
    $activeFilters[] = ['label' => 'Aggregator', 'value' => $filters['aggregator']];
}
if (($filters['job_fair'] ?? '') !== '') {
    $activeFilters[] = ['label' => 'Job Fair', 'value' => $filters['job_fair']];
}
if (($filters['category'] ?? '') !== '') {
    $activeFilters[] = ['label' => 'Category', 'value' => $filters['category']];
}

if (($_GET['download'] ?? '') === 'csv') {
    $filename = 'district_jobstation_drill_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Sl No', 'DWMS ID', 'Candidate Name', 'Mobile', 'Candidate District', 'Job Station',
        'SDPK District', 'SDPK Center', 'Employer Code', 'Employer Name', 'Job ID', 'Job Title',
        'Job Fair No', 'Job Fair Date', 'Aggregator', 'Category',
        'Selection Status', 'Final (Shortlist) Status',
        'Receipt Confirmed', 'Confirmation Date',
        'Candidate Joined Status', 'Candidate Joined Date', 'Candidate Joining Future Date',
    ]);
    $i = 1;
    foreach ($rows as $row) {
        fputcsv($out, [
            $i++,
            (string) ($row['DWMS_ID'] ?? ''),
            (string) ($row['Candidate_Name'] ?? ''),
            (string) ($row['Mobile_Number'] ?? ''),
            (string) ($row['Candidate_District'] ?? ''),
            (string) ($row['Candidate_Jobstation'] ?? ''),
            (string) ($row['SDPK_District'] ?? ''),
            (string) ($row['SDPK'] ?? ''),
            (string) ($row['Employer_ID'] ?? ''),
            (string) ($row['Employer_Name'] ?? ''),
            (string) ($row['Job_Id'] ?? ''),
            (string) ($row['Job_Title_Name'] ?? ''),
            (string) ($row['Job_Fair_No'] ?? ''),
            (string) ($row['Job_Fair_Date'] ?? ''),
            (string) ($row['Aggregator'] ?? ''),
            (string) ($row['Category'] ?? ''),
            (string) ($row['Selection_Status'] ?? ''),
            (string) ($row['Shortlist_Candidate_Status'] ?? ''),
            (string) ($row['Confirm_Offer_Letter_Receipt_by_Candidate'] ?? ''),
            (string) ($row['confirmation_date'] ?? ''),
            (string) ($row['Candidate_Joined_Status'] ?? ''),
            (string) ($row['Candidate_Joined_Date'] ?? ''),
            (string) ($row['Candidate_Joining_Future_Date'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$downloadUrl = '/district_jobstation_joined_drill.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'district' => $district,
    'cohort' => $cohort,
    'joined' => $joined,
    'offer_letter' => $offerLetterYes ? 'yes' : '',
    'future_bucket' => $futureBucket,
    'remark_type' => $hasRemarkTypeParam ? $remarkType : '',
    'aggregator' => $filters['aggregator'] ?? '',
    'job_fair' => $filters['job_fair'] ?? '',
    'category' => $filters['category'] ?? '',
], static fn($v): bool => $v !== ''));

$backUrl = '/consolidated_report.php?' . http_build_query(array_filter([
    'aggregator' => $filters['aggregator'] ?? '',
    'job_fair' => $filters['job_fair'] ?? '',
    'category' => $filters['category'] ?? '',
    'selection_status' => $filters['selection_status'] ?? '',
], static fn($v): bool => $v !== '')) . '#tab-shortlisted';

render_header('District / Job Station drill-down', ['main_container_class' => 'container-fluid']);
render_page_header('District / Job Station — Drill-down', [
    'icon' => 'bi-search',
    'subtitle' => 'Exact candidate records contributing to the District wise · Job Station and Joined Status table cell.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>'
               . ' <a class="btn btn-light ms-1" href="' . esc($backUrl) . '"><i class="bi bi-arrow-left me-1"></i>Back to Consolidated Report</a>',
]);
?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="form-label mb-0 me-1">Drill-down scope:</span>
        <?php if ($offerLetterYes): ?>
            <span class="status-chip status-success"><strong>Offer Letter Generated:</strong>&nbsp;Yes</span>
        <?php endif; ?>
        <?php if ($futureBucket === 'before_today'): ?>
            <span class="status-chip status-warning"><strong>Future Date:</strong>&nbsp;Before Today</span>
        <?php elseif ($futureBucket === 'today_onwards'): ?>
            <span class="status-chip status-info"><strong>Future Date:</strong>&nbsp;Today Onwards</span>
        <?php endif; ?>
        <?php if ($hasRemarkTypeParam): ?>
            <span class="status-chip status-neutral"><strong>Join Remarks Type:</strong>&nbsp;<?= esc($remarkType !== '' ? $remarkType : '(No Remark Type)') ?></span>
        <?php endif; ?>
        <?php
            if (!$hasDistrictParam || $district === '') {
                $districtChipLabel = 'All Districts';
            } elseif ($district === 'Unknown') {
                $districtChipLabel = 'Unknown (blank district)';
            } else {
                $districtChipLabel = $district;
            }
        ?>
        <span class="status-chip status-neutral"><strong>District:</strong>&nbsp;<?= esc($districtChipLabel) ?></span>
        <span class="status-chip status-neutral"><strong>Cohort:</strong>&nbsp;<?= esc($cohortLabels[$cohort]) ?></span>
        <span class="status-chip status-neutral"><strong>Joined Status:</strong>&nbsp;<?= esc($joinedLabels[$joined]) ?></span>
        <?php foreach ($activeFilters as $f): ?>
            <span class="status-chip status-neutral"><strong><?= esc($f['label']) ?>:</strong>&nbsp;<?= esc($f['value']) ?></span>
        <?php endforeach; ?>
        <span class="status-chip status-info ms-auto"><?= number_format(count($rows)) ?> records</span>
    </div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>DWMS ID</th>
                    <th>Candidate</th>
                    <th>District / Job Station</th>
                    <th>Employer / Job</th>
                    <th>Job Fair</th>
                    <th>Selection / Final</th>
                    <th>Receipt Confirmed</th>
                    <th>Joined Status</th>
                    <th>Joined / Future Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11"><div class="empty-state"><i class="bi bi-search"></i>No candidate records match this scope.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $row): ?>
                    <?php
                        $selKey = strtolower(str_replace(' ', '', (string) ($row['Selection_Status'] ?? '')));
                        $isShortlistOrOnhold = in_array($selKey, ['shortlisted', 'onhold'], true);
                    ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= esc((string) ($row['DWMS_ID'] ?? '')) ?></td>
                        <td>
                            <div class="fw-semibold"><?= esc((string) ($row['Candidate_Name'] ?? '')) ?></div>
                            <div class="small text-muted"><?= esc((string) ($row['Mobile_Number'] ?? '')) ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold"><?= esc((string) ($row['Candidate_District'] ?? '')) ?></div>
                            <div class="small text-muted"><?= esc((string) ($row['Candidate_Jobstation'] ?? '')) ?></div>
                        </td>
                        <td>
                            <div><?= esc((string) ($row['Employer_ID'] ?? '')) ?> &middot; <?= esc((string) ($row['Employer_Name'] ?? '')) ?></div>
                            <div class="small text-muted"><?= esc((string) ($row['Job_Id'] ?? '')) ?> &middot; <?= esc((string) ($row['Job_Title_Name'] ?? '')) ?></div>
                        </td>
                        <td>
                            <div><?= esc((string) ($row['Job_Fair_No'] ?? '')) ?></div>
                            <div class="small text-muted"><?= $row['Job_Fair_Date'] ? esc(date('d M Y', strtotime((string) $row['Job_Fair_Date']))) : '' ?></div>
                        </td>
                        <td>
                            <?= render_status_chip($row['Selection_Status']) ?>
                            <?php if ($isShortlistOrOnhold && !empty($row['Shortlist_Candidate_Status'])): ?>
                                <div class="mt-1"><?= render_status_chip($row['Shortlist_Candidate_Status'], 'Final:') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= render_status_chip($row['Confirm_Offer_Letter_Receipt_by_Candidate']) ?>
                            <?php if (!empty($row['confirmation_date']) && $row['confirmation_date'] !== '0000-00-00 00:00:00' && $row['confirmation_date'] !== '0000-00-00'): ?>
                                <div class="small text-muted"><?= esc(date('d M Y', strtotime((string) $row['confirmation_date']))) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= render_status_chip($row['Candidate_Joined_Status']) ?></td>
                        <td>
                            <?php
                                $jd = (string) ($row['Candidate_Joined_Date'] ?? '');
                                $fjd = (string) ($row['Candidate_Joining_Future_Date'] ?? '');
                                if ($jd !== '' && $jd !== '0000-00-00') {
                                    echo esc(date('d M Y', strtotime($jd)));
                                } elseif ($fjd !== '' && $fjd !== '0000-00-00') {
                                    echo '<span class="small text-muted">Future:</span> ' . esc(date('d M Y', strtotime($fjd)));
                                } else {
                                    echo '<span class="text-muted">&mdash;</span>';
                                }
                            ?>
                        </td>
                        <td><a class="btn btn-sm btn-outline-primary" href="/manage_candidate.php?candidate_id=<?= (int) $row['id'] ?>"><i class="bi bi-pencil-square"></i> Manage</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
