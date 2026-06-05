<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

require_admin();

$jobFairFilter = trim((string) ($_GET['job_fair_no'] ?? ''));
$categoryFilter = trim((string) ($_GET['category'] ?? ''));

$jobFairs = db()->query("SELECT DISTINCT Job_Fair_No FROM job_fair_result WHERE Job_Fair_No IS NOT NULL AND Job_Fair_No <> '' ORDER BY Job_Fair_No")->fetchAll();
$categories = db()->query("SELECT DISTINCT Category FROM job_fair_result WHERE Category IS NOT NULL AND Category <> '' ORDER BY Category")->fetchAll();

$whereSql = ' WHERE 1=1';
$params = [];
if ($jobFairFilter !== '') {
    $whereSql .= ' AND jfr.Job_Fair_No = ?';
    $params[] = $jobFairFilter;
}
if ($categoryFilter !== '') {
    $whereSql .= ' AND jfr.Category = ?';
    $params[] = $categoryFilter;
}

// Count distinct round_numbers that have a recorded round_selection_status.
$roundsCompletedSql = "(
    SELECT COUNT(DISTINCT csr.round_number)
    FROM candidate_shortlist_rounds csr
    WHERE csr.candidate_id = jfr.id
      AND TRIM(COALESCE(csr.round_selection_status, '')) <> ''
)";

// Derived single-label "Final selection status of the candidate":
//   - 'Selected (Direct)'      when Selection_Status = Selected
//   - 'Selected (Shortlisted)' when Shortlisted/On hold AND Shortlist final = Selected
//   - the Shortlist_Candidate_Status if set
//   - else the Selection_Status as-is
//   - else 'Pending'
$finalSelectionSql = "
    CASE
        WHEN LOWER(REPLACE(TRIM(COALESCE(jfr.Selection_Status, '')), ' ', '')) = 'selected' THEN 'Selected (Direct)'
        WHEN LOWER(REPLACE(TRIM(COALESCE(jfr.Selection_Status, '')), ' ', '')) IN ('shortlisted', 'onhold')
             AND LOWER(REPLACE(TRIM(COALESCE(jfr.Shortlist_Candidate_Status, '')), ' ', '')) = 'selected'
            THEN 'Selected (Shortlisted)'
        WHEN TRIM(COALESCE(jfr.Shortlist_Candidate_Status, '')) <> ''
            THEN jfr.Shortlist_Candidate_Status
        WHEN TRIM(COALESCE(jfr.Selection_Status, '')) <> ''
            THEN jfr.Selection_Status
        ELSE 'Pending'
    END
";

if (isset($_GET['download']) && $_GET['download'] === '1') {
    $sql = "SELECT
            jfr.Job_Fair_No AS job_fair_id,
            jfr.DWMS_ID AS dwms_id,
            jfr.Candidate_Name AS candidate_name,
            jfr.Employer_ID AS employer_id,
            jfr.Employer_Name AS employer_name,
            jfr.Job_Id AS job_id,
            jfr.Job_Title_Name AS job_title,
            jfr.Category AS category,
            jfr.Selection_Status AS initial_status,
            jfr.Shortlist_Candidate_Status AS final_status,
            $roundsCompletedSql AS rounds_completed,
            $finalSelectionSql AS final_selection_status
        FROM job_fair_result jfr
        $whereSql
        ORDER BY jfr.Job_Fair_No DESC, jfr.Employer_Name ASC, jfr.Candidate_Name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $filename = 'conversion_data_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        echo 'Unable to open CSV stream.';
        exit;
    }
    // UTF-8 BOM for Excel.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Job Fair ID',
        'DWMS ID',
        'Candidate Name',
        'Employer ID',
        'Employer Name',
        'Job ID',
        'Job Title',
        'Category',
        'Initial Status',
        'Final Status',
        'Number of Rounds of Selection Process Completed',
        'Final Selection Status of the Candidate',
    ]);

    while ($row = $stmt->fetch()) {
        fputcsv($output, [
            (string) ($row['job_fair_id'] ?? ''),
            (string) ($row['dwms_id'] ?? ''),
            (string) ($row['candidate_name'] ?? ''),
            (string) ($row['employer_id'] ?? ''),
            (string) ($row['employer_name'] ?? ''),
            (string) ($row['job_id'] ?? ''),
            (string) ($row['job_title'] ?? ''),
            (string) ($row['category'] ?? ''),
            (string) ($row['initial_status'] ?? ''),
            (string) ($row['final_status'] ?? ''),
            (string) ($row['rounds_completed'] ?? '0'),
            (string) ($row['final_selection_status'] ?? ''),
        ]);
    }

    fclose($output);
    exit;
}

$countStmt = db()->prepare('SELECT COUNT(*) AS total_rows FROM job_fair_result jfr' . $whereSql);
$countStmt->execute($params);
$totalRows = (int) ($countStmt->fetch()['total_rows'] ?? 0);

render_header('Export Conversion Data CSV');
render_page_header('Export Conversion Data CSV', [
    'icon' => 'bi-download',
    'subtitle' => 'Download candidate-level conversion data: initial vs final selection status, rounds completed and derived final selection.',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Job Fair</label>
                <select class="form-select" name="job_fair_no">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairs as $jobFair): ?>
                        <option value="<?= esc($jobFair['Job_Fair_No']) ?>" <?= $jobFairFilter === $jobFair['Job_Fair_No'] ? 'selected' : '' ?>><?= esc($jobFair['Job_Fair_No']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= esc($category['Category']) ?>" <?= $categoryFilter === $category['Category'] ? 'selected' : '' ?>><?= esc($category['Category']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary" name="download" value="1"><i class="bi bi-download me-1"></i>Download CSV</button>
                <a href="/job_fair_conversion_data_export.php" class="btn btn-outline-secondary">Reset filters</a>
                <span class="ms-auto text-muted small">Matching rows: <?= esc((string) $totalRows) ?></span>
            </div>
        </form>
        <p class="data-meta mt-3 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            Columns: Job Fair ID, DWMS ID, Candidate Name, Employer ID, Employer Name, Job ID, Job Title, Category,
            Initial Status, Final Status, Number of Rounds of Selection Process Completed (distinct rounds with a recorded status),
            Final Selection Status of the Candidate (derived: Selected (Direct) / Selected (Shortlisted) / falls back to the Shortlist or Initial status / Pending).
        </p>
    </div>
</div>
<?php render_footer(); ?>
