<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$currentUser = current_user();
$isDistrictUser = is_district_user($currentUser);
$manageSourceMode = $isDistrictUser ? 'district_candidate_data' : '';

/**
 * Each definition describes one discrepancy check: which rows it surfaces
 * (the SQL `where` clause beyond the common filters) and the extra columns
 * relevant to that discrepancy. The base column set (Job Fair / Candidate /
 * Districts / Selection Status / Manage) is shared across all checks.
 */
$checkDefinitions = [
    1 => [
        'label' => 'Future Date · Missing Future Joining Date',
        'menu_label' => 'Future Date missing date',
        'icon' => 'bi-calendar-x',
        'description' => 'Candidate Joined Status is <strong>Future Date</strong> but no <strong>Candidate Joining Future Date</strong> has been recorded.',
        'where' => "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'future date'
            AND (Candidate_Joining_Future_Date IS NULL OR Candidate_Joining_Future_Date = '0000-00-00')",
        'extra_columns' => [
            'Joined Status' => ['field' => 'Candidate_Joined_Status', 'type' => 'chip'],
            'Future Joining Date' => ['field' => 'Candidate_Joining_Future_Date', 'type' => 'missing'],
        ],
    ],
    2 => [
        'label' => 'Joined "Yes" · Missing Joined Date',
        'menu_label' => 'Joined Yes missing date',
        'icon' => 'bi-calendar-check',
        'description' => 'Candidate Joined Status is <strong>Yes</strong> but no <strong>Candidate Joined Date</strong> has been recorded.',
        'where' => "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes'
            AND (Candidate_Joined_Date IS NULL OR Candidate_Joined_Date = '0000-00-00')",
        'extra_columns' => [
            'Joined Status' => ['field' => 'Candidate_Joined_Status', 'type' => 'chip'],
            'Joined Date' => ['field' => 'Candidate_Joined_Date', 'type' => 'missing'],
        ],
    ],
    3 => [
        'label' => 'Joined "No" · Missing Join Remarks',
        'menu_label' => 'Joined No missing remarks',
        'icon' => 'bi-chat-left-text',
        'description' => 'Candidate Joined Status is <strong>No</strong> for a genuinely selected candidate (Selection Status = Selected, or Shortlisted/On hold whose Final Status = Selected) but <strong>Candidate Join Remarks Type</strong> or <strong>Remarks Candidate Join</strong> is missing. Rejected, Onhold (no final selection) and other non-selected outcomes are excluded.',
        'where' => "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'no'
            AND (
                LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
                OR (
                    LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
                    AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
                )
            )
            AND (
                Candidate_Join_Remarks_Type IS NULL OR TRIM(Candidate_Join_Remarks_Type) = ''
                OR Remarks_Candidate_Join IS NULL OR TRIM(Remarks_Candidate_Join) = ''
            )",
        'extra_columns' => [
            'Joined Status' => ['field' => 'Candidate_Joined_Status', 'type' => 'chip'],
            'Join Remarks Type' => ['field' => 'Candidate_Join_Remarks_Type', 'type' => 'text_missing'],
            'Remarks (Candidate Join)' => ['field' => 'Remarks_Candidate_Join', 'type' => 'text_missing'],
        ],
    ],
    4 => [
        'label' => 'Joined "Pending" · Receipt Yes but missing Remarks',
        'menu_label' => 'Pending with receipt but no remarks',
        'icon' => 'bi-question-circle',
        'description' => 'Candidate Joined Status is <strong>Pending</strong>, the Offer Letter Receipt is <strong>Yes</strong> (candidate has the offer), but no <strong>Join Remarks Type</strong> or <strong>Remarks Candidate Join</strong> follow-up has been recorded.',
        'where' => "LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'pending'
            AND LOWER(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, ''))) = 'yes'
            AND (
                Candidate_Join_Remarks_Type IS NULL OR TRIM(Candidate_Join_Remarks_Type) = ''
                OR Remarks_Candidate_Join IS NULL OR TRIM(Remarks_Candidate_Join) = ''
            )",
        'extra_columns' => [
            'Joined Status' => ['field' => 'Candidate_Joined_Status', 'type' => 'chip'],
            'Receipt Confirmed' => ['field' => 'Confirm_Offer_Letter_Receipt_by_Candidate', 'type' => 'chip'],
            'Join Remarks Type' => ['field' => 'Candidate_Join_Remarks_Type', 'type' => 'text_missing'],
            'Remarks (Candidate Join)' => ['field' => 'Remarks_Candidate_Join', 'type' => 'text_missing'],
        ],
    ],
];

$jobFairFilter = trim((string) ($_GET['job_fair'] ?? ''));
$candidateDistrictFilter = trim((string) ($_GET['candidate_district'] ?? ''));
$sdpkDistrictFilter = trim((string) ($_GET['sdpk_district'] ?? ''));
$finalStatusSelectedFilter = isset($_GET['final_status_selected']) && $_GET['final_status_selected'] !== '';
$check = (int) ($_GET['check'] ?? 1);
if (!isset($checkDefinitions[$check])) {
    $check = 1;
}

/** Which checks the Final Status filter applies to (per request: 1, 3, 4). */
$finalStatusApplicableChecks = [1, 3, 4];

$jobFairOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT Job_Fair_No AS value FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND TRIM(Job_Fair_No) <> '' ORDER BY value ASC")->fetchAll()
);
$candidateDistrictOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT Candidate_District AS value FROM job_fair_result WHERE Candidate_District IS NOT NULL AND TRIM(Candidate_District) <> '' ORDER BY value ASC")->fetchAll()
);
$sdpkDistrictOptions = array_map(
    static fn(array $r): string => (string) $r['value'],
    db()->query("SELECT DISTINCT SDPK_District AS value FROM job_fair_result WHERE SDPK_District IS NOT NULL AND TRIM(SDPK_District) <> '' ORDER BY value ASC")->fetchAll()
);

$commonConditions = [];
$commonParams = [];
if ($jobFairFilter !== '') {
    $commonConditions[] = "TRIM(COALESCE(Job_Fair_No, '')) = ?";
    $commonParams[] = $jobFairFilter;
}
if ($candidateDistrictFilter !== '') {
    $commonConditions[] = "TRIM(COALESCE(Candidate_District, '')) = ?";
    $commonParams[] = $candidateDistrictFilter;
}
if ($sdpkDistrictFilter !== '') {
    $commonConditions[] = "TRIM(COALESCE(SDPK_District, '')) = ?";
    $commonParams[] = $sdpkDistrictFilter;
}

$activeDef = $checkDefinitions[$check];
$conditions = $commonConditions;
$params = $commonParams;
if ($finalStatusSelectedFilter && in_array($check, $finalStatusApplicableChecks, true)) {
    $conditions[] = "LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')";
    $conditions[] = "LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'";
}
$conditions[] = '(' . $activeDef['where'] . ')';
$whereClause = 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT id, Job_Fair_No, Job_Fair_Date, DWMS_ID, Candidate_Name, Mobile_Number,
        Candidate_District, SDPK_District, SDPK, Selection_Status, Shortlist_Candidate_Status,
        Candidate_Joined_Status, Candidate_Joined_Date, Candidate_Joining_Future_Date,
        Candidate_Join_Remarks_Type, Remarks_Candidate_Join, Confirm_Offer_Letter_Receipt_by_Candidate,
        Employer_ID, Employer_Name, Job_Id, Job_Title_Name, CRM_Member
    FROM job_fair_result
    $whereClause
    ORDER BY Candidate_District ASC, Job_Fair_Date DESC, Candidate_Name ASC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Counts for every check (so the tab pills can show a badge with how many
// records currently fall into each discrepancy bucket).
$checkCounts = [];
foreach ($checkDefinitions as $idx => $def) {
    $cConds = $commonConditions;
    $cParams = $commonParams;
    if ($finalStatusSelectedFilter && in_array($idx, $finalStatusApplicableChecks, true)) {
        $cConds[] = "LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')";
        $cConds[] = "LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'";
    }
    $cConds[] = '(' . $def['where'] . ')';
    $countSql = "SELECT COUNT(*) FROM job_fair_result WHERE " . implode(' AND ', $cConds);
    $cStmt = db()->prepare($countSql);
    $cStmt->execute($cParams);
    $checkCounts[$idx] = (int) $cStmt->fetchColumn();
}

$buildCheckUrl = static function (int $newCheck) use ($jobFairFilter, $candidateDistrictFilter, $sdpkDistrictFilter, $finalStatusSelectedFilter): string {
    return '/district_discrepancy_report.php?' . http_build_query(array_filter([
        'check' => $newCheck,
        'job_fair' => $jobFairFilter,
        'candidate_district' => $candidateDistrictFilter,
        'sdpk_district' => $sdpkDistrictFilter,
        'final_status_selected' => $finalStatusSelectedFilter ? '1' : '',
    ], static fn($v): bool => $v !== ''));
};

$downloadUrl = '/district_discrepancy_report.php?' . http_build_query(array_filter([
    'check' => $check,
    'download' => 'csv',
    'job_fair' => $jobFairFilter,
    'candidate_district' => $candidateDistrictFilter,
    'sdpk_district' => $sdpkDistrictFilter,
    'final_status_selected' => $finalStatusSelectedFilter ? '1' : '',
], static fn($v): bool => $v !== ''));

if (($_GET['download'] ?? '') === 'csv') {
    $filename = 'discrepancy_check_' . $check . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($check === 2) {
        // Joined Yes missing date: extended employer / job / CRM columns, no Mobile,
        // Candidate and SDPK districts merged into a single CSV column.
        $csvHeader = ['Sl No', 'DWMS ID', 'Candidate Name', 'Job Fair No', 'Job Fair Date',
            'Employer Code', 'Employer Name', 'Job ID', 'Job Title', 'CRM Member',
            'Candidate District', 'SDPK District', 'Selection Status', 'Final Status'];
    } else {
        $csvHeader = ['Sl No', 'DWMS ID', 'Candidate Name', 'Mobile', 'Job Fair No', 'Job Fair Date', 'Candidate District', 'SDPK District', 'Selection Status', 'Final Status'];
    }
    foreach ($activeDef['extra_columns'] as $label => $_) {
        $csvHeader[] = $label;
    }
    fputcsv($out, $csvHeader);
    $i = 1;
    foreach ($rows as $row) {
        if ($check === 2) {
            $line = [
                $i++,
                (string) ($row['DWMS_ID'] ?? ''),
                (string) ($row['Candidate_Name'] ?? ''),
                (string) ($row['Job_Fair_No'] ?? ''),
                (string) ($row['Job_Fair_Date'] ?? ''),
                (string) ($row['Employer_ID'] ?? ''),
                (string) ($row['Employer_Name'] ?? ''),
                (string) ($row['Job_Id'] ?? ''),
                (string) ($row['Job_Title_Name'] ?? ''),
                (string) ($row['CRM_Member'] ?? ''),
                (string) ($row['Candidate_District'] ?? ''),
                (string) ($row['SDPK_District'] ?? ''),
                (string) ($row['Selection_Status'] ?? ''),
                (string) ($row['Shortlist_Candidate_Status'] ?? ''),
            ];
        } else {
            $line = [
                $i++,
                (string) ($row['DWMS_ID'] ?? ''),
                (string) ($row['Candidate_Name'] ?? ''),
                (string) ($row['Mobile_Number'] ?? ''),
                (string) ($row['Job_Fair_No'] ?? ''),
                (string) ($row['Job_Fair_Date'] ?? ''),
                (string) ($row['Candidate_District'] ?? ''),
                (string) ($row['SDPK_District'] ?? ''),
                (string) ($row['Selection_Status'] ?? ''),
                (string) ($row['Shortlist_Candidate_Status'] ?? ''),
            ];
        }
        foreach ($activeDef['extra_columns'] as $col) {
            $line[] = (string) ($row[$col['field']] ?? '');
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

render_header('Discrepancy Report', ['main_container_class' => 'container-fluid']);
render_page_header('Discrepancy Report', [
    'icon' => 'bi-exclamation-diamond',
    'subtitle' => 'Data integrity checks - candidates with inconsistent or missing follow-up information.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]);
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <input type="hidden" name="check" value="<?= (int) $check ?>">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="job_fair">Job Fair</label>
                <select class="form-select" id="job_fair" name="job_fair">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $jobFairFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="candidate_district">Candidate District</label>
                <select class="form-select" id="candidate_district" name="candidate_district">
                    <option value="">All Candidate Districts</option>
                    <?php foreach ($candidateDistrictOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $candidateDistrictFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="sdpk_district">SDPK District</label>
                <select class="form-select" id="sdpk_district" name="sdpk_district">
                    <option value="">All SDPK Districts</option>
                    <?php foreach ($sdpkDistrictOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $sdpkDistrictFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a class="btn btn-light" href="<?= esc('/district_discrepancy_report.php?check=' . (int) $check) ?>">Reset</a>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="final_status_selected" name="final_status_selected" value="1" <?= $finalStatusSelectedFilter ? 'checked' : '' ?>>
                    <label class="form-check-label" for="final_status_selected">
                        <strong>Final Status = Selected</strong> (Shortlisted / On hold candidates whose Final status is Selected)
                        <span class="text-muted small d-block">Applies only to: Future Date missing date &middot; Joined No missing remarks &middot; Pending with receipt but no remarks. Ignored for Joined Yes missing date.</span>
                    </label>
                </div>
            </div>
        </div>
    </div>
</form>

<ul class="nav nav-pills mb-3 flex-wrap">
    <?php foreach ($checkDefinitions as $idx => $def): ?>
        <li class="nav-item">
            <a class="nav-link <?= $idx === $check ? 'active' : '' ?>" href="<?= esc($buildCheckUrl($idx)) ?>">
                <i class="bi <?= esc($def['icon']) ?> me-1"></i>
                <?= esc($def['menu_label']) ?>
                <span class="badge bg-<?= $idx === $check ? 'light text-dark' : 'secondary' ?> ms-1"><?= number_format($checkCounts[$idx]) ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<div class="card mb-2">
    <div class="card-body py-3">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-info-circle-fill text-primary mt-1"></i>
            <div>
                <div class="fw-semibold"><?= esc($activeDef['label']) ?></div>
                <div class="small text-muted mt-1"><?= $activeDef['description'] ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi <?= esc($activeDef['icon']) ?> text-primary me-1"></i><?= esc($activeDef['label']) ?></span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <?php $isJoinedYesCheck = $check === 2; ?>
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Candidate</th>
                    <?php if ($isJoinedYesCheck): ?>
                        <th>Employer</th>
                        <th>Job</th>
                        <th>CRM Member</th>
                    <?php else: ?>
                        <th>Mobile</th>
                    <?php endif; ?>
                    <th>Job Fair</th>
                    <?php if ($isJoinedYesCheck): ?>
                        <th>Districts</th>
                    <?php else: ?>
                        <th>Candidate District</th>
                        <th>SDPK District</th>
                    <?php endif; ?>
                    <th>Selection Status</th>
                    <th>Final Status</th>
                    <?php foreach ($activeDef['extra_columns'] as $label => $_): ?>
                        <th><?= esc($label) ?></th>
                    <?php endforeach; ?>
                    <th>Manage</th>
                </tr>
            </thead>
            <tbody>
                <?php $colCount = ($isJoinedYesCheck ? 10 : 9) + count($activeDef['extra_columns']); ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= $colCount ?>"><div class="empty-state"><i class="bi bi-check2-circle"></i>No discrepancies found for this check with the selected filters.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td>
                            <div class="fw-semibold"><?= esc((string) $row['Candidate_Name']) ?></div>
                            <div class="small text-muted"><?= esc((string) $row['DWMS_ID']) ?></div>
                        </td>
                        <?php if ($isJoinedYesCheck): ?>
                            <td>
                                <div class="fw-semibold"><?= esc((string) ($row['Employer_ID'] ?? '')) ?></div>
                                <div class="small text-muted"><?= esc((string) ($row['Employer_Name'] ?? '')) ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= esc((string) ($row['Job_Id'] ?? '')) ?></div>
                                <div class="small text-muted"><?= esc((string) ($row['Job_Title_Name'] ?? '')) ?></div>
                            </td>
                            <td><?= esc((string) ($row['CRM_Member'] ?? '')) ?></td>
                        <?php else: ?>
                            <td><?= esc((string) $row['Mobile_Number']) ?></td>
                        <?php endif; ?>
                        <td>
                            <div><?= esc((string) ($row['Job_Fair_No'] ?: 'N/A')) ?></div>
                            <div class="small text-muted"><?= $row['Job_Fair_Date'] ? esc(date('d M Y', strtotime((string) $row['Job_Fair_Date']))) : '' ?></div>
                        </td>
                        <?php if ($isJoinedYesCheck): ?>
                            <td>
                                <div><span class="small text-muted">Cand:</span> <?= esc((string) ($row['Candidate_District'] ?? '')) ?></div>
                                <div><span class="small text-muted">SDPK:</span> <?= esc((string) ($row['SDPK_District'] ?? '')) ?></div>
                            </td>
                        <?php else: ?>
                            <td><?= esc((string) ($row['Candidate_District'] ?? '')) ?></td>
                            <td><?= esc((string) ($row['SDPK_District'] ?? '')) ?></td>
                        <?php endif; ?>
                        <td><?= render_status_chip($row['Selection_Status']) ?></td>
                        <td><?= render_status_chip($row['Shortlist_Candidate_Status']) ?></td>
                        <?php foreach ($activeDef['extra_columns'] as $col): ?>
                            <td>
                                <?php
                                    $val = (string) ($row[$col['field']] ?? '');
                                    if ($col['type'] === 'chip') {
                                        echo render_status_chip($val);
                                    } elseif ($col['type'] === 'missing') {
                                        if ($val === '' || $val === '0000-00-00') {
                                            echo '<span class="status-chip status-no">Missing</span>';
                                        } else {
                                            echo esc(date('d M Y', strtotime($val)));
                                        }
                                    } elseif ($col['type'] === 'text_missing') {
                                        if (trim($val) === '') {
                                            echo '<span class="status-chip status-no">Missing</span>';
                                        } else {
                                            echo esc($val);
                                        }
                                    } else {
                                        echo esc($val);
                                    }
                                ?>
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <?php $manageHref = '/manage_candidate.php?candidate_id=' . (int) $row['id'] . ($manageSourceMode !== '' ? '&source_mode=' . urlencode($manageSourceMode) : ''); ?>
                            <a class="btn btn-sm btn-outline-primary"
                                href="<?= esc($manageHref) ?>"
                                aria-label="Manage candidate">
                                <i class="bi bi-pencil-square"></i> Manage
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
