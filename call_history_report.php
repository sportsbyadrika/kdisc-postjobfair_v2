<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();

db()->query(
    "CREATE TABLE IF NOT EXISTS candidate_call_purpose (
        id INT AUTO_INCREMENT PRIMARY KEY,
        purpose_name VARCHAR(255) NOT NULL UNIQUE,
        active_status TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$hasPurposeIdColumnStmt = db()->query("SHOW COLUMNS FROM candidate_call_history LIKE 'purpose_id'");
$hasPurposeIdColumnRows = $hasPurposeIdColumnStmt->fetchAll();
$hasPurposeIdColumn = $hasPurposeIdColumnRows !== [];
if (!$hasPurposeIdColumn) {
    db()->query("ALTER TABLE candidate_call_history ADD COLUMN purpose_id INT DEFAULT NULL AFTER stage");
    db()->query("ALTER TABLE candidate_call_history ADD INDEX idx_candidate_call_history_purpose_id (purpose_id)");
    db()->query(
        "ALTER TABLE candidate_call_history
            ADD CONSTRAINT fk_candidate_call_history_purpose
                FOREIGN KEY (purpose_id) REFERENCES candidate_call_purpose(id)
                ON DELETE SET NULL"
    );
}

$crmMemberRows = db()->query(
    "SELECT DISTINCT cmu.name AS CRM_Member
     FROM candidate_call_history h
     INNER JOIN users cmu ON cmu.id = h.created_by
     WHERE cmu.name IS NOT NULL AND TRIM(cmu.name) <> ''
     ORDER BY cmu.name"
)->fetchAll();

$crmMemberOptions = [];
foreach ($crmMemberRows as $crmMemberRow) {
    $crmMemberValue = $crmMemberRow;
    if (is_array($crmMemberRow)) {
        $crmMemberValue = $crmMemberRow['CRM_Member'] ?? reset($crmMemberRow);
    }

    $crmMemberValue = trim((string) $crmMemberValue);
    if ($crmMemberValue !== '') {
        $crmMemberOptions[] = $crmMemberValue;
    }
}

$selectedMember = trim((string) ($_GET['crm_member'] ?? ''));
$selectedUserRole = trim((string) ($_GET['user_role'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$hasDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) === 1;
$hasDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1;

$roleOptions = [
    'administrator' => 'Administrator',
    'crm_member' => 'CRM Member',
    'district_user' => 'District User',
];
if (!array_key_exists($selectedUserRole, $roleOptions)) {
    $selectedUserRole = '';
}

$viewMode = ($_GET['view'] ?? '') === 'details' ? 'details' : 'summary';
$selectedMetric = trim((string) ($_GET['metric'] ?? 'total_calls'));
$isFullStretch = ($_GET['stretch'] ?? '') === '1';

$metricConfig = [
    'total_calls' => [
        'label' => 'Total Calls',
        'condition' => '',
    ],
    'employer_connect' => [
        'label' => 'Employer Connect',
        'condition' => "h.stage = 'Employer Connect'",
    ],
    'candidate_connect' => [
        'label' => 'Candidate Connect',
        'condition' => "h.stage = 'Candidate Connect'",
    ],
    'aggregator_connect' => [
        'label' => 'Aggregator Connect',
        'condition' => "h.stage = 'Aggregator Contact'",
    ],
    'attended' => [
        'label' => 'Attended',
        'condition' => "h.call_status = 'Attended'",
    ],
    'not_attended' => [
        'label' => 'Not attended',
        'condition' => "h.call_status = 'Not attended'",
    ],
    'invalid_number' => [
        'label' => 'Invalid number',
        'condition' => "h.call_status = 'Invalid number'",
    ],
];

if (!isset($metricConfig[$selectedMetric])) {
    $selectedMetric = 'total_calls';
}

$baseConditions = ["cu.name IS NOT NULL", "TRIM(cu.name) <> ''"];
$baseParams = [];
if ($selectedMember !== '') {
    $baseConditions[] = 'cu.name = ?';
    $baseParams[] = $selectedMember;
}
if ($selectedUserRole !== '') {
    $baseConditions[] = 'cu.role = ?';
    $baseParams[] = $selectedUserRole;
}
if ($hasDateFrom) {
    $baseConditions[] = 'h.call_datetime >= ?';
    $baseParams[] = $dateFrom . ' 00:00:00';
}
if ($hasDateTo) {
    $baseConditions[] = 'h.call_datetime <= ?';
    $baseParams[] = $dateTo . ' 23:59:59';
}
$whereSql = ' WHERE ' . implode(' AND ', $baseConditions);

$summarySql = "
    SELECT
        cu.name AS CRM_Member,
        COUNT(h.id) AS total_calls,
        SUM(CASE WHEN h.stage = 'Employer Connect' THEN 1 ELSE 0 END) AS employer_connect_calls,
        COUNT(DISTINCT CASE
            WHEN h.stage = 'Employer Connect' AND j.Employer_Name IS NOT NULL AND TRIM(j.Employer_Name) <> ''
                THEN j.Employer_Name
            ELSE NULL
        END) AS employer_connect_employer_count,
        SUM(CASE WHEN h.stage = 'Candidate Connect' THEN 1 ELSE 0 END) AS candidate_connect_calls,
        COUNT(DISTINCT CASE
            WHEN h.stage = 'Candidate Connect'
                THEN h.candidate_id
            ELSE NULL
        END) AS candidate_connect_candidate_count,
        COALESCE(MAX(a.assigned_candidate_count), 0) AS assigned_candidate_count,
        COALESCE(MAX(a.assigned_employer_count), 0) AS assigned_employer_count,
        SUM(CASE WHEN h.stage = 'Aggregator Contact' THEN 1 ELSE 0 END) AS aggregator_contact_calls,
        COUNT(DISTINCT CASE
            WHEN h.stage = 'Aggregator Contact' AND j.Aggregator IS NOT NULL AND TRIM(j.Aggregator) <> ''
                THEN j.Aggregator
            ELSE NULL
        END) AS aggregator_connect_aggregator_count,
        SUM(CASE WHEN h.call_status = 'Attended' THEN 1 ELSE 0 END) AS attended_calls,
        SUM(CASE WHEN h.call_status = 'Not attended' THEN 1 ELSE 0 END) AS not_attended_calls,
        SUM(CASE WHEN h.call_status = 'Invalid number' THEN 1 ELSE 0 END) AS invalid_number_calls
    FROM candidate_call_history h
    INNER JOIN users cu ON cu.id = h.created_by
    INNER JOIN job_fair_result j ON j.id = h.candidate_id
    LEFT JOIN users u ON u.name = j.CRM_Member
    LEFT JOIN (
        SELECT
            CRM_Member,
            COUNT(*) AS assigned_candidate_count,
            COUNT(DISTINCT CASE
                WHEN Employer_Name IS NOT NULL AND TRIM(Employer_Name) <> ''
                    THEN Employer_Name
                ELSE NULL
            END) AS assigned_employer_count
        FROM job_fair_result
        WHERE CRM_Member IS NOT NULL AND TRIM(CRM_Member) <> ''
        GROUP BY CRM_Member
    ) a ON a.CRM_Member = cu.name
    LEFT JOIN candidate_call_purpose p ON p.id = h.purpose_id
    " . $whereSql . "
    GROUP BY cu.name
    ORDER BY total_calls DESC, cu.name";

$summaryStmt = db()->prepare($summarySql);
$summaryStmt->execute($baseParams);
$summaryRows = $summaryStmt->fetchAll();

$summaryTotals = [
    'total_calls' => 0,
    'employer_connect_calls' => 0,
    'employer_connect_employer_count' => 0,
    'candidate_connect_calls' => 0,
    'candidate_connect_candidate_count' => 0,
    'assigned_candidate_count' => 0,
    'assigned_employer_count' => 0,
    'aggregator_contact_calls' => 0,
    'aggregator_connect_aggregator_count' => 0,
    'attended_calls' => 0,
    'not_attended_calls' => 0,
    'invalid_number_calls' => 0,
];
foreach ($summaryRows as $summaryRow) {
    foreach (array_keys($summaryTotals) as $totalKey) {
        $summaryTotals[$totalKey] += (int) ($summaryRow[$totalKey] ?? 0);
    }
}

$detailConditions = $baseConditions;
$detailParams = $baseParams;
$metricCondition = (string) ($metricConfig[$selectedMetric]['condition'] ?? '');
if ($viewMode === 'details' && $metricCondition !== '') {
    $detailConditions[] = $metricCondition;
}
$detailWhereSql = ' WHERE ' . implode(' AND ', $detailConditions);

$detailSql = "
    SELECT
        h.id,
        h.stage,
        h.call_datetime,
        COALESCE(p.purpose_name, '') AS purpose_name,
        h.call_status,
        h.call_remarks,
        cu.name AS CRM_Member,
        j.Job_Fair_No,
        j.Candidate_Name,
        j.Mobile_Number,
        j.Selection_Status,
        j.Employer_Name,
        j.Aggregator
    FROM candidate_call_history h
    INNER JOIN users cu ON cu.id = h.created_by
    INNER JOIN job_fair_result j ON j.id = h.candidate_id
    LEFT JOIN users u ON u.name = j.CRM_Member
    LEFT JOIN candidate_call_purpose p ON p.id = h.purpose_id
    " . $detailWhereSql . "
    ORDER BY h.call_datetime DESC, h.id DESC";
$detailStmt = db()->prepare($detailSql);
$detailStmt->execute($detailParams);
$details = $detailStmt->fetchAll();

$baseQueryParams = [];
if ($hasDateFrom) {
    $baseQueryParams['date_from'] = $dateFrom;
}
if ($hasDateTo) {
    $baseQueryParams['date_to'] = $dateTo;
}
if ($selectedUserRole !== '') {
    $baseQueryParams['user_role'] = $selectedUserRole;
}

$buildReportUrl = static function (array $params) use ($baseQueryParams): string {
    $query = array_merge($baseQueryParams, $params);
    return '/call_history_report.php?' . http_build_query($query);
};

$renderMetricCell = static function (int $value, string $url, string $extraText = ''): string {
    if ($value <= 0) {
        return (string) $value;
    }

    return '<a href="' . esc($url) . '">' . $value . '</a>' . $extraText;
};

$isDetailsMode = $viewMode === 'details';
render_header('Call History Report', [
    'show_navigation' => !$isDetailsMode,
    'main_container_class' => 'container-fluid px-3',
]);
?>
<?php if ($viewMode === 'details'): ?>
    <?php render_page_header('Call Details Report', [
        'icon' => 'bi-telephone',
        'subtitle' => 'Individual call records for the selected metric and user.',
    ]); ?>
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div><strong>Metric:</strong> <?= esc((string) $metricConfig[$selectedMetric]['label']) ?></div>
                <div><strong>User:</strong> <?= $selectedMember !== '' ? esc($selectedMember) : 'All Users' ?></div>
                <div><strong>User Role:</strong> <?= $selectedUserRole !== '' ? esc((string) ($roleOptions[$selectedUserRole] ?? $selectedUserRole)) : 'All Roles' ?></div>
                <div><strong>Date Range:</strong> <?= esc($hasDateFrom ? $dateFrom : '-') ?> to <?= esc($hasDateTo ? $dateTo : '-') ?></div>
            </div>
            <div>
                <a class="btn btn-outline-secondary" href="<?= esc($buildReportUrl(['crm_member' => $selectedMember, 'user_role' => $selectedUserRole])) ?>">Back to Summary</a>
            </div>
        </div>
    </div>

    <table class="table table-bordered table-striped align-middle w-100">
            <thead>
                <tr>
                    <th>Call Date/Time</th>
                    <th>User</th>
                    <th>Candidate</th>
                    <th>Mobile</th>
                    <th>Job Fair No</th>
                    <th>Employer</th>
                    <th>Aggregator</th>
                    <th>Selection Status</th>
                    <th>Stage</th>
                    <th>Purpose</th>
                    <th>Call Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($details === []): ?>
                    <tr>
                        <td colspan="12" class="text-center text-muted">No call history records found for the selected filter.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($details as $detail): ?>
                    <tr>
                        <td><?= esc((string) $detail['call_datetime']) ?></td>
                        <td><?= esc((string) ($detail['CRM_Member'] ?? '-')) ?></td>
                        <td><?= esc((string) ($detail['Candidate_Name'] ?? '-')) ?></td>
                        <td><?= esc((string) ($detail['Mobile_Number'] ?? '-')) ?></td>
                        <td><?= esc((string) ($detail['Job_Fair_No'] ?? '-')) ?></td>
                        <td><?= esc((string) ($detail['Employer_Name'] ?? '-')) ?></td>
                        <td><?= esc((string) ($detail['Aggregator'] ?? '-')) ?></td>
                        <td><?= esc((string) ($detail['Selection_Status'] ?? '-')) ?></td>
                        <td><?= esc((string) $detail['stage']) ?></td>
                        <td><?= esc((string) ($detail['purpose_name'] ?? '-')) ?></td>
                        <td><?= esc((string) $detail['call_status']) ?></td>
                        <td><?= esc((string) ($detail['call_remarks'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
    </table>
<?php else: ?>
    <?php render_page_header('User-wise Call History Report', [
        'icon' => 'bi-telephone-fill',
        'subtitle' => 'Call activity grouped by CRM member, stage and call status.',
    ]); ?>

    <div class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-12 col-md-4 col-lg-3">
                    <label for="crm_member" class="form-label">Filter by User</label>
                    <select id="crm_member" name="crm_member" class="form-select">
                        <option value="">All Users</option>
                        <?php foreach ($crmMemberOptions as $memberOption): ?>
                            <option value="<?= esc((string) $memberOption) ?>" <?= $selectedMember === (string) $memberOption ? 'selected' : '' ?>>
                                <?= esc((string) $memberOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label for="date_from" class="form-label">Date from</label>
                    <input type="date" id="date_from" name="date_from" class="form-control" value="<?= esc($hasDateFrom ? $dateFrom : '') ?>">
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label for="user_role" class="form-label">Filter by User role</label>
                    <select id="user_role" name="user_role" class="form-select">
                        <option value="">All Roles</option>
                        <?php foreach ($roleOptions as $roleValue => $roleLabel): ?>
                            <option value="<?= esc($roleValue) ?>" <?= $selectedUserRole === $roleValue ? 'selected' : '' ?>>
                                <?= esc($roleLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label for="date_to" class="form-label">Date to</label>
                    <input type="date" id="date_to" name="date_to" class="form-control" value="<?= esc($hasDateTo ? $dateTo : '') ?>">
                </div>
                <div class="col-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <a href="/call_history_report.php" class="btn btn-outline-secondary">Reset</a>
                    <?php
                    $stretchQueryParams = [];
                    if ($selectedMember !== '') {
                        $stretchQueryParams['crm_member'] = $selectedMember;
                    }
                    if ($selectedUserRole !== '') {
                        $stretchQueryParams['user_role'] = $selectedUserRole;
                    }
                    if ($hasDateFrom) {
                        $stretchQueryParams['date_from'] = $dateFrom;
                    }
                    if ($hasDateTo) {
                        $stretchQueryParams['date_to'] = $dateTo;
                    }
                    ?>
                    <?php if ($isFullStretch): ?>
                        <a href="<?= esc('/call_history_report.php?' . http_build_query($stretchQueryParams)) ?>" class="btn btn-outline-dark">Exit Full Screen</a>
                    <?php else: ?>
                        <a href="<?= esc('/call_history_report.php?' . http_build_query(array_merge($stretchQueryParams, ['stretch' => '1']))) ?>" class="btn btn-outline-dark">Full Screen</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped align-middle">
            <thead>
                <tr>
                    <th rowspan="2">Sl No</th>
                    <th rowspan="2">User</th>
                    <th rowspan="2">Total Calls</th>
                    <th colspan="3" class="text-center">Employer Connect</th>
                    <th colspan="3" class="text-center">Candidate Connect</th>
                    <th colspan="2" class="text-center">Aggregator Connect</th>
                    <th colspan="3" class="text-center">Call Status</th>
                </tr>
                <tr>
                    <th>Calls</th>
                    <th>Employers</th>
                    <th>Assigned Employers</th>
                    <th>Calls</th>
                    <th>Candidates</th>
                    <th>Assigned Candidates</th>
                    <th>Calls</th>
                    <th>Aggregators</th>
                    <th>Attended</th>
                    <th>Not attended</th>
                    <th>Invalid number</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($summaryRows === []): ?>
                    <tr>
                        <td colspan="14" class="text-center text-muted">No call history records found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($summaryRows as $rowIndex => $row): ?>
                    <?php $memberKey = (string) ($row['CRM_Member'] ?? 'Unassigned'); ?>
                    <?php
                    $memberQuery = ['view' => 'details', 'crm_member' => $memberKey];
                    if ($isFullStretch) {
                        $memberQuery['stretch'] = '1';
                    }
                    $employerExtra = (int) ($row['employer_connect_employer_count'] ?? 0);
                    $candidateExtra = (int) ($row['candidate_connect_candidate_count'] ?? 0);
                    $assignedCandidateExtra = (int) ($row['assigned_candidate_count'] ?? 0);
                    $assignedEmployerExtra = (int) ($row['assigned_employer_count'] ?? 0);
                    $aggregatorExtra = (int) ($row['aggregator_connect_aggregator_count'] ?? 0);
                    ?>
                    <tr>
                        <td><?= $rowIndex + 1 ?></td>
                        <td><?= esc($memberKey) ?></td>
                        <td><?= $renderMetricCell((int) $row['total_calls'], $buildReportUrl(array_merge($memberQuery, ['metric' => 'total_calls']))) ?></td>
                        <td><?= $renderMetricCell((int) $row['employer_connect_calls'], $buildReportUrl(array_merge($memberQuery, ['metric' => 'employer_connect']))) ?></td>
                        <td><?= $employerExtra ?></td>
                        <td><?= $assignedEmployerExtra ?></td>
                        <td><?= $renderMetricCell((int) $row['candidate_connect_calls'], $buildReportUrl(array_merge($memberQuery, ['metric' => 'candidate_connect']))) ?></td>
                        <td><?= $candidateExtra ?></td>
                        <td><?= $assignedCandidateExtra ?></td>
                        <td><?= $renderMetricCell((int) $row['aggregator_contact_calls'], $buildReportUrl(array_merge($memberQuery, ['metric' => 'aggregator_connect']))) ?></td>
                        <td><?= $aggregatorExtra ?></td>
                        <td><?= $renderMetricCell((int) $row['attended_calls'], $buildReportUrl(array_merge($memberQuery, ['metric' => 'attended']))) ?></td>
                        <td><?= $renderMetricCell((int) $row['not_attended_calls'], $buildReportUrl(array_merge($memberQuery, ['metric' => 'not_attended']))) ?></td>
                        <td><?= $renderMetricCell((int) $row['invalid_number_calls'], $buildReportUrl(array_merge($memberQuery, ['metric' => 'invalid_number']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($summaryRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td><?= $summaryTotals['total_calls'] ?></td>
                        <td><?= $summaryTotals['employer_connect_calls'] ?></td>
                        <td><?= $summaryTotals['employer_connect_employer_count'] ?></td>
                        <td><?= $summaryTotals['assigned_employer_count'] ?></td>
                        <td><?= $summaryTotals['candidate_connect_calls'] ?></td>
                        <td><?= $summaryTotals['candidate_connect_candidate_count'] ?></td>
                        <td><?= $summaryTotals['assigned_candidate_count'] ?></td>
                        <td><?= $summaryTotals['aggregator_contact_calls'] ?></td>
                        <td><?= $summaryTotals['aggregator_connect_aggregator_count'] ?></td>
                        <td><?= $summaryTotals['attended_calls'] ?></td>
                        <td><?= $summaryTotals['not_attended_calls'] ?></td>
                        <td><?= $summaryTotals['invalid_number_calls'] ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php render_footer(!$isDetailsMode); ?>
