<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
if (!is_district_user($user)) {
    header('Location: /dashboard.php');
    exit;
}

$candidateDistrictFilter = trim($_GET['candidate_district'] ?? '');
$jobFairNoFilter = trim($_GET['job_fair_no'] ?? '');
$dwmsIdFilter = trim($_GET['dwms_id'] ?? '');
$candidateNameFilter = trim($_GET['candidate_name'] ?? '');
$candidateLocalbodyFilter = trim($_GET['candidate_localbody'] ?? '');
$candidateJobstationFilter = trim($_GET['candidate_jobstation'] ?? '');
$offerLetterGeneratedFilter = trim($_GET['offer_letter_generated'] ?? '');
$confirmOfferLetterReceiptByCandidateFilter = trim($_GET['confirm_offer_letter_receipt_by_candidate'] ?? '');
$candidateJoinedStatusFilter = trim($_GET['candidate_joined_status'] ?? '');
$currentQueryString = $_SERVER['QUERY_STRING'] ?? '';
$page = max((int) ($_GET['page'] ?? 1), 1);
$perPage = 25;

$candidateDistricts = db()->query("SELECT DISTINCT Candidate_District FROM job_fair_result WHERE Candidate_District IS NOT NULL AND Candidate_District <> '' ORDER BY Candidate_District")->fetchAll();
$jobFairNos = db()->query("SELECT DISTINCT Job_Fair_No FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND Job_Fair_No <> '' ORDER BY Job_Fair_No")->fetchAll();
$offerLetterGeneratedStatuses = db()->query("SELECT DISTINCT Offer_Letter_Generated FROM job_fair_result WHERE Offer_Letter_Generated IS NOT NULL AND Offer_Letter_Generated <> '' ORDER BY Offer_Letter_Generated")->fetchAll();
$confirmOfferLetterReceiptByCandidateStatuses = db()->query("SELECT DISTINCT Confirm_Offer_Letter_Receipt_by_Candidate FROM job_fair_result WHERE Confirm_Offer_Letter_Receipt_by_Candidate IS NOT NULL AND Confirm_Offer_Letter_Receipt_by_Candidate <> '' ORDER BY Confirm_Offer_Letter_Receipt_by_Candidate")->fetchAll();
$candidateJoinedStatuses = db()->query("SELECT DISTINCT Candidate_Joined_Status FROM job_fair_result WHERE Candidate_Joined_Status IS NOT NULL AND Candidate_Joined_Status <> '' ORDER BY Candidate_Joined_Status")->fetchAll();

$whereSql = ' FROM job_fair_result WHERE 1=1';
$params = [];

if ($candidateDistrictFilter !== '') {
    $whereSql .= ' AND Candidate_District = ?';
    $params[] = $candidateDistrictFilter;
}
if ($jobFairNoFilter !== '') {
    $whereSql .= ' AND Job_Fair_No = ?';
    $params[] = $jobFairNoFilter;
}
if ($dwmsIdFilter !== '') {
    $whereSql .= ' AND DWMS_ID LIKE ?';
    $params[] = '%' . $dwmsIdFilter . '%';
}
if ($candidateNameFilter !== '') {
    $whereSql .= ' AND Candidate_Name LIKE ?';
    $params[] = '%' . $candidateNameFilter . '%';
}
if ($candidateLocalbodyFilter !== '') {
    $whereSql .= ' AND Candidate_Localbody LIKE ?';
    $params[] = '%' . $candidateLocalbodyFilter . '%';
}
if ($candidateJobstationFilter !== '') {
    $whereSql .= ' AND Candidate_Jobstation LIKE ?';
    $params[] = '%' . $candidateJobstationFilter . '%';
}
if ($offerLetterGeneratedFilter !== '') {
    $whereSql .= ' AND Offer_Letter_Generated = ?';
    $params[] = $offerLetterGeneratedFilter;
}
if ($confirmOfferLetterReceiptByCandidateFilter !== '') {
    $whereSql .= ' AND Confirm_Offer_Letter_Receipt_by_Candidate = ?';
    $params[] = $confirmOfferLetterReceiptByCandidateFilter;
}
if ($candidateJoinedStatusFilter !== '') {
    $whereSql .= ' AND Candidate_Joined_Status = ?';
    $params[] = $candidateJoinedStatusFilter;
}

$countStmt = db()->prepare('SELECT COUNT(*)' . $whereSql);
$countStmt->execute($params);
$totalRecords = (int) $countStmt->fetchColumn();
$totalPages = max((int) ceil($totalRecords / $perPage), 1);
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT *' . $whereSql . ' ORDER BY Data_uploaded_date DESC, id DESC LIMIT ? OFFSET ?';
$stmt = db()->prepare($sql);
$stmt->execute([...$params, $perPage, $offset]);
$rows = $stmt->fetchAll();

render_header('Candidate Data', ['main_container_class' => 'container-fluid']);
render_page_header('Candidate Data', [
    'icon' => 'bi-people',
    'subtitle' => 'Candidate records scoped to your district.',
    'actions' => '<span class="status-chip status-info">' . (int) $totalRecords . ' records</span>',
]);

$baseParams = $_GET;
unset($baseParams['page']);
render_pagination($page, $totalPages, $totalRecords, $perPage, '/district_candidate_data.php', $baseParams, 'Candidate data pagination');
?>

<form method="get" class="card mb-4">
    <div class="card-body">
        <h2 class="h6 mb-3">Filters</h2>
        <div class="row g-2">
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Candidate District</label>
                <select class="form-select" name="candidate_district">
                    <option value="">All</option>
                    <?php foreach ($candidateDistricts as $district): ?>
                        <option value="<?= esc($district['Candidate_District']) ?>" <?= $candidateDistrictFilter === $district['Candidate_District'] ? 'selected' : '' ?>><?= esc($district['Candidate_District']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Job Fair No</label>
                <select class="form-select" name="job_fair_no">
                    <option value="">All</option>
                    <?php foreach ($jobFairNos as $jobFairNo): ?>
                        <option value="<?= esc($jobFairNo['Job_Fair_No']) ?>" <?= $jobFairNoFilter === $jobFairNo['Job_Fair_No'] ? 'selected' : '' ?>><?= esc($jobFairNo['Job_Fair_No']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">DWMS ID</label>
                <input class="form-control" name="dwms_id" type="text" value="<?= esc($dwmsIdFilter) ?>" placeholder="Enter DWMS ID">
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Candidate Name</label>
                <input class="form-control" name="candidate_name" type="text" value="<?= esc($candidateNameFilter) ?>" placeholder="Enter candidate name">
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Candidate Local body</label>
                <input class="form-control" name="candidate_localbody" type="text" value="<?= esc($candidateLocalbodyFilter) ?>" placeholder="Enter candidate local body">
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Candidate Job station</label>
                <input class="form-control" name="candidate_jobstation" type="text" value="<?= esc($candidateJobstationFilter) ?>" placeholder="Enter candidate job station">
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Offer Letter Generated</label>
                <select class="form-select" name="offer_letter_generated">
                    <option value="">All</option>
                    <?php foreach ($offerLetterGeneratedStatuses as $status): ?>
                        <option value="<?= esc($status['Offer_Letter_Generated']) ?>" <?= $offerLetterGeneratedFilter === $status['Offer_Letter_Generated'] ? 'selected' : '' ?>><?= esc($status['Offer_Letter_Generated']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Confirm offer letter receipt by candidate</label>
                <select class="form-select" name="confirm_offer_letter_receipt_by_candidate">
                    <option value="">All</option>
                    <?php foreach ($confirmOfferLetterReceiptByCandidateStatuses as $status): ?>
                        <option value="<?= esc($status['Confirm_Offer_Letter_Receipt_by_Candidate']) ?>" <?= $confirmOfferLetterReceiptByCandidateFilter === $status['Confirm_Offer_Letter_Receipt_by_Candidate'] ? 'selected' : '' ?>><?= esc($status['Confirm_Offer_Letter_Receipt_by_Candidate']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label">Candidate Joined Status</label>
                <select class="form-select" name="candidate_joined_status">
                    <option value="">All</option>
                    <?php foreach ($candidateJoinedStatuses as $status): ?>
                        <option value="<?= esc($status['Candidate_Joined_Status']) ?>" <?= $candidateJoinedStatusFilter === $status['Candidate_Joined_Status'] ? 'selected' : '' ?>><?= esc($status['Candidate_Joined_Status']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2 mt-3">
                <button class="btn btn-primary" type="submit">Apply filters</button>
                <a class="btn btn-outline-secondary" href="/district_candidate_data.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<div class="card table-card mb-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th rowspan="2">Job Fair / Status</th>
                        <th rowspan="2">Candidate</th>
                        <th rowspan="2">Employer</th>
                        <th rowspan="2">Job</th>
                        <th rowspan="2">Days since Job Fair Date</th>
                        <th colspan="3" class="text-center">Offer Letter</th>
                        <th rowspan="2">Candidate Joined Status</th>
                        <th rowspan="2">Manage</th>
                    </tr>
                    <tr>
                        <th>Generated</th>
                        <th>Verified</th>
                        <th>Receipt Confirmed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []): ?>
                        <tr>
                            <td colspan="10"><div class="empty-state"><i class="bi bi-search"></i>No results found for the selected filters.</div></td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $daySinceJobFair = null;
                        if (!empty($row['Job_Fair_Date'])) {
                            $jobFairDate = new DateTime($row['Job_Fair_Date']);
                            $today = new DateTime();
                            $daySinceJobFair = (int) $jobFairDate->diff($today)->format('%a');
                        }
                        $selKey = strtolower(str_replace(' ', '', (string) ($row['Selection_Status'] ?? '')));
                        $isShortlistOrOnhold = in_array($selKey, ['shortlisted', 'onhold'], true);
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= esc($row['Job_Fair_No'] ?: 'N/A') ?></div>
                                <div class="mt-1"><?= render_status_chip($row['Selection_Status']) ?></div>
                                <?php if ($isShortlistOrOnhold && !empty($row['Shortlist_Candidate_Status'])): ?>
                                    <div class="mt-1"><?= render_status_chip($row['Shortlist_Candidate_Status'], 'Final:') ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= esc($row['DWMS_ID'] ?: 'N/A') ?></div>
                                <div class="small text-muted"><?= esc($row['Candidate_Name'] ?: 'N/A') ?></div>
                            </td>
                            <td>
                                <div><?= esc($row['Employer_ID'] ?: 'N/A') ?></div>
                                <div class="small text-muted"><?= esc($row['Employer_Name'] ?: 'N/A') ?></div>
                            </td>
                            <td>
                                <div><?= esc($row['Job_Id'] ?: 'N/A') ?></div>
                                <div class="small text-muted"><?= esc($row['Job_Title_Name'] ?: 'N/A') ?></div>
                            </td>
                            <td><?= $daySinceJobFair !== null ? $daySinceJobFair : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td><?= render_status_chip($row['Offer_Letter_Generated']) ?></td>
                            <td><?= render_status_chip($row['Link_to_Offer_letter_verified']) ?></td>
                            <td><?= render_status_chip($row['Confirm_Offer_Letter_Receipt_by_Candidate']) ?></td>
                            <td><?= render_status_chip($row['Candidate_Joined_Status']) ?></td>
                            <td>
                                <a
                                    class="btn btn-sm btn-outline-primary"
                                    href="/manage_candidate.php?candidate_id=<?= (int) $row['id'] ?>&source_mode=district_candidate_data&return_query=<?= urlencode($currentQueryString) ?>"
                                    aria-label="Manage candidate"
                                >
                                    ✏️
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
</div>

<?php render_pagination($page, $totalPages, $totalRecords, $perPage, '/district_candidate_data.php', $baseParams, 'Candidate data pagination'); ?>

<?php render_footer(); ?>
