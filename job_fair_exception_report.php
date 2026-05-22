<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

const EXCEPTION_FILTER_COLUMNS = [
    'aggregator' => 'Aggregator',
    'job_fair_no' => 'Job_Fair_No',
    'selection_status' => 'Selection_Status',
    'category' => 'Category',
];

const EXCEPTION_DETAIL_COLUMNS = [
    'job_fair_no' => 'Job_Fair_No',
    'job_fair_date' => 'Job_Fair_Date',
    'aggregator' => 'Aggregator',
    'employer_id' => 'Employer_ID',
    'employer_name' => 'Employer_Name',
    'job_id' => 'Job_Id',
    'job_title' => 'Job_Title_Name',
    'dwms_id' => 'DWMS_ID',
    'candidate_name' => 'Candidate_Name',
    'category' => 'Category',
    'selection_status' => 'Selection_Status',
    'shortlist_candidate_status' => 'Shortlist_Candidate_Status',
    'shortlist_current_call_status' => 'Shortlist_Current_Call_Status',
    'offer_letter_generated' => 'Offer_Letter_Generated',
    'link_to_offer_letter_verified' => 'Link_to_Offer_letter_verified',
    'confirm_offer_letter_receipt_by_candidate' => 'Confirm_Offer_Letter_Receipt_by_Candidate',
    'candidate_joined_status' => 'Candidate_Joined_Status',
];

const EXCEPTION_METRIC_LABELS = [
    'total_count' => 'Total',
    'offer_generated_yes' => 'Offer Letter Generated: Yes',
    'offer_generated_no' => 'Offer Letter Generated: No',
    'offer_generated_pending' => 'Offer Letter Generated: Pending',
    'offer_link_with' => 'Link to Offer Letter: With Link',
    'offer_link_blank' => 'Link to Offer Letter: Blank',
    'link_verified_yes' => 'Link Verified: Yes',
    'link_verified_no' => 'Link Verified: No',
    'link_verified_pending' => 'Link Verified: Pending',
    'receipt_confirmed_yes' => 'Offer Letter Receipt Confirmed: Yes',
    'receipt_confirmed_no' => 'Offer Letter Receipt Confirmed: No',
    'receipt_confirmed_pending' => 'Offer Letter Receipt Confirmed: Pending',
    'candidate_joined_yes' => 'Candidate Joined: Yes',
    'candidate_joined_no' => 'Candidate Joined: No',
    'candidate_joined_pending' => 'Candidate Joined: Pending',
    'shortlist_candidate_shortlisted' => 'Shortlist Candidate Status: Shortlisted',
    'shortlist_candidate_selected' => 'Shortlist Candidate Status: Selected',
    'shortlist_candidate_rejected' => 'Shortlist Candidate Status: Rejected',
    'shortlist_candidate_onhold' => 'Shortlist Candidate Status: Onhold',
    'shortlist_candidate_not_interested' => 'Shortlist Candidate Status: Candidate Not Interested',
];

function fetch_filter_options(string $column): array
{
    $allowedColumns = array_values(EXCEPTION_FILTER_COLUMNS);
    if (!in_array($column, $allowedColumns, true)) {
        return [];
    }

    $sql = "SELECT DISTINCT $column AS value
        FROM job_fair_result
        WHERE $column IS NOT NULL AND TRIM($column) <> ''
        ORDER BY $column ASC";

    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function build_exception_filters(): array
{
    $jobFairNo = $_GET['job_fair_no'] ?? [];
    if (!is_array($jobFairNo)) {
        $jobFairNo = $jobFairNo === '' ? [] : [$jobFairNo];
    }

    $selectionStatus = $_GET['selection_status'] ?? [];
    if (!is_array($selectionStatus)) {
        $selectionStatus = $selectionStatus === '' ? [] : [$selectionStatus];
    }

    $aggregator = $_GET['aggregator'] ?? [];
    if (!is_array($aggregator)) {
        $aggregator = $aggregator === '' ? [] : [$aggregator];
    }

    $category = $_GET['category'] ?? [];
    if (!is_array($category)) {
        $category = $category === '' ? [] : [$category];
    }

    return [
        'aggregator' => array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $aggregator), static fn(string $value): bool => $value !== '')),
        'job_fair_no' => array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $jobFairNo), static fn(string $value): bool => $value !== '')),
        'selection_status' => array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $selectionStatus), static fn(string $value): bool => $value !== '')),
        'category' => array_values(array_filter(array_map(static fn($value): string => trim((string) $value), $category), static fn(string $value): bool => $value !== '')),
    ];
}

function build_exception_where_clause(array $filters, array &$params): string
{
    $conditions = [];

    foreach (EXCEPTION_FILTER_COLUMNS as $filterKey => $column) {
        $value = $filters[$filterKey] ?? '';
        if (is_array($value)) {
            if ($value === []) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            $conditions[] = "$column IN ($placeholders)";
            foreach ($value as $item) {
                $params[] = $item;
            }

            continue;
        }

        if ($value === '') {
            continue;
        }

        $conditions[] = "$column = ?";
        $params[] = $value;
    }

    if ($conditions === []) {
        return '';
    }

    return 'WHERE ' . implode(' AND ', $conditions);
}

function fetch_exception_rows(array $filters): array
{
    $params = [];
    $whereClause = build_exception_where_clause($filters, $params);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            COUNT(*) AS total_count,
            SUM(CASE WHEN LOWER(TRIM(Offer_Letter_Generated)) = 'yes' THEN 1 ELSE 0 END) AS offer_generated_yes,
            SUM(CASE WHEN LOWER(TRIM(Offer_Letter_Generated)) = 'no' THEN 1 ELSE 0 END) AS offer_generated_no,
            SUM(CASE WHEN TRIM(COALESCE(Offer_Letter_Generated, '')) = '' OR LOWER(TRIM(Offer_Letter_Generated)) = 'pending' THEN 1 ELSE 0 END) AS offer_generated_pending,
            SUM(CASE WHEN TRIM(COALESCE(Link_to_Offer_letter, '')) <> '' THEN 1 ELSE 0 END) AS offer_link_with,
            SUM(CASE WHEN TRIM(COALESCE(Link_to_Offer_letter, '')) = '' THEN 1 ELSE 0 END) AS offer_link_blank,
            SUM(CASE WHEN LOWER(TRIM(Link_to_Offer_letter_verified)) = 'yes' THEN 1 ELSE 0 END) AS link_verified_yes,
            SUM(CASE WHEN LOWER(TRIM(Link_to_Offer_letter_verified)) = 'no' THEN 1 ELSE 0 END) AS link_verified_no,
            SUM(CASE WHEN TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '' OR LOWER(TRIM(Link_to_Offer_letter_verified)) = 'pending' THEN 1 ELSE 0 END) AS link_verified_pending,
            SUM(CASE WHEN LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'yes' THEN 1 ELSE 0 END) AS receipt_confirmed_yes,
            SUM(CASE WHEN LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'no' THEN 1 ELSE 0 END) AS receipt_confirmed_no,
            SUM(CASE WHEN TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '' OR LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'pending' THEN 1 ELSE 0 END) AS receipt_confirmed_pending,
            SUM(CASE WHEN LOWER(TRIM(Candidate_Joined_Status)) = 'yes' THEN 1 ELSE 0 END) AS candidate_joined_yes,
            SUM(CASE WHEN LOWER(TRIM(Candidate_Joined_Status)) = 'no' THEN 1 ELSE 0 END) AS candidate_joined_no,
            SUM(CASE WHEN TRIM(COALESCE(Candidate_Joined_Status, '')) = '' OR LOWER(TRIM(Candidate_Joined_Status)) = 'pending' THEN 1 ELSE 0 END) AS candidate_joined_pending,
            SUM(CASE WHEN LOWER(TRIM(Shortlist_Candidate_Status)) = 'shortlisted' THEN 1 ELSE 0 END) AS shortlist_candidate_shortlisted,
            SUM(CASE WHEN LOWER(TRIM(Shortlist_Candidate_Status)) = 'selected' THEN 1 ELSE 0 END) AS shortlist_candidate_selected,
            SUM(CASE WHEN LOWER(TRIM(Shortlist_Candidate_Status)) = 'rejected' THEN 1 ELSE 0 END) AS shortlist_candidate_rejected,
            SUM(CASE WHEN LOWER(TRIM(Shortlist_Candidate_Status)) = 'onhold' THEN 1 ELSE 0 END) AS shortlist_candidate_onhold,
            SUM(CASE WHEN LOWER(TRIM(Shortlist_Candidate_Status)) = 'candidate not interested' THEN 1 ELSE 0 END) AS shortlist_candidate_not_interested
        FROM job_fair_result
        $whereClause
        GROUP BY job_fair_no
        ORDER BY job_fair_no ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function calculate_exception_totals(array $rows): array
{
    $keys = array_keys(EXCEPTION_METRIC_LABELS);
    $totals = array_fill_keys($keys, 0);

    foreach ($rows as $row) {
        foreach ($keys as $key) {
            $totals[$key] += (int) ($row[$key] ?? 0);
        }
    }

    return $totals;
}

function build_exception_detail_url(array $filters, string $jobFairNo, string $metric): string
{
    $query = [
        'job_fair_row' => $jobFairNo,
        'metric' => $metric,
    ];

    foreach (array_keys(EXCEPTION_FILTER_COLUMNS) as $key) {
        if (!empty($filters[$key])) {
            $query[$key] = $filters[$key];
        }
    }

    return 'job_fair_exception_candidates.php?' . http_build_query($query);
}

function render_linked_metric(array $filters, string $jobFairNo, string $metric, int $count): string
{
    $url = build_exception_detail_url($filters, $jobFairNo, $metric);
    $text = (string) $count;

    return '<a href="' . esc($url) . '" target="_blank" rel="noopener">' . esc($text) . '</a>';
}

$filters = build_exception_filters();
$aggregatorOptions = fetch_filter_options('Aggregator');
$jobFairNoOptions = fetch_filter_options('Job_Fair_No');
$selectionStatusOptions = fetch_filter_options('Selection_Status');
$categoryOptions = fetch_filter_options('Category');
$rows = fetch_exception_rows($filters);
$totals = calculate_exception_totals($rows);

render_header('Job Fair Exception Report', ['main_container_class' => 'container-fluid']);
render_page_header('Job Fair Exception Report', [
    'icon' => 'bi-exclamation-triangle',
    'subtitle' => 'Candidates with challenges, escalations and follow-up exceptions.',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="aggregator" class="form-label">Aggregator</label>
                <select class="form-select" id="aggregator" name="aggregator[]" multiple size="6">
                    <?php foreach ($aggregatorOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= in_array($option, $filters['aggregator'], true) ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Hold Ctrl (Windows) or Command (Mac) to select multiple.</div>
            </div>
            <div class="col-md-3">
                <label for="job_fair_no" class="form-label">Job Fair No</label>
                <select class="form-select" id="job_fair_no" name="job_fair_no[]" multiple size="6">
                    <?php foreach ($jobFairNoOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= in_array($option, $filters['job_fair_no'], true) ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Hold Ctrl (Windows) or Command (Mac) to select multiple.</div>
            </div>
            <div class="col-md-3">
                <label for="selection_status" class="form-label">Selection Status</label>
                <select class="form-select" id="selection_status" name="selection_status[]" multiple size="6">
                    <?php foreach ($selectionStatusOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= in_array($option, $filters['selection_status'], true) ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Hold Ctrl (Windows) or Command (Mac) to select multiple.</div>
            </div>
            <div class="col-md-3">
                <label for="category" class="form-label">Category</label>
                <select class="form-select" id="category" name="category[]" multiple size="6">
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= in_array($option, $filters['category'], true) ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Hold Ctrl (Windows) or Command (Mac) to select multiple.</div>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <a href="job_fair_exception_report.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Pivot Table (Job Fair wise)</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Job Fair No</th>
                    <th rowspan="2">Total</th>
                    <th colspan="3">Offer Letter Generated</th>
                    <th colspan="2">Link to Offer Letter</th>
                    <th colspan="3">Link Verified</th>
                    <th colspan="3">Offer Letter Receipt Confirmed</th>
                    <th colspan="3">Candidate Joined</th>
                    <th colspan="5">Shortlist Candidate Status</th>
                </tr>
                <tr>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>With Link</th>
                    <th>Blank</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Shortlisted</th>
                    <th>Selected</th>
                    <th>Rejected</th>
                    <th>Onhold</th>
                    <th>Candidate Not Interested</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="22" class="text-center text-muted">No data available.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'total_count', (int) $row['total_count']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'offer_generated_yes', (int) $row['offer_generated_yes']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'offer_generated_no', (int) $row['offer_generated_no']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'offer_generated_pending', (int) $row['offer_generated_pending']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'offer_link_with', (int) $row['offer_link_with']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'offer_link_blank', (int) $row['offer_link_blank']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'link_verified_yes', (int) $row['link_verified_yes']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'link_verified_no', (int) $row['link_verified_no']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'link_verified_pending', (int) $row['link_verified_pending']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'receipt_confirmed_yes', (int) $row['receipt_confirmed_yes']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'receipt_confirmed_no', (int) $row['receipt_confirmed_no']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'receipt_confirmed_pending', (int) $row['receipt_confirmed_pending']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'candidate_joined_yes', (int) $row['candidate_joined_yes']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'candidate_joined_no', (int) $row['candidate_joined_no']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'candidate_joined_pending', (int) $row['candidate_joined_pending']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'shortlist_candidate_shortlisted', (int) $row['shortlist_candidate_shortlisted']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'shortlist_candidate_selected', (int) $row['shortlist_candidate_selected']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'shortlist_candidate_rejected', (int) $row['shortlist_candidate_rejected']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'shortlist_candidate_onhold', (int) $row['shortlist_candidate_onhold']) ?></td>
                        <td><?= render_linked_metric($filters, (string) $row['job_fair_no'], 'shortlist_candidate_not_interested', (int) $row['shortlist_candidate_not_interested']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total</td>
                        <td><?= $totals['total_count'] ?></td>
                        <td><?= $totals['offer_generated_yes'] ?></td>
                        <td><?= $totals['offer_generated_no'] ?></td>
                        <td><?= $totals['offer_generated_pending'] ?></td>
                        <td><?= $totals['offer_link_with'] ?></td>
                        <td><?= $totals['offer_link_blank'] ?></td>
                        <td><?= $totals['link_verified_yes'] ?></td>
                        <td><?= $totals['link_verified_no'] ?></td>
                        <td><?= $totals['link_verified_pending'] ?></td>
                        <td><?= $totals['receipt_confirmed_yes'] ?></td>
                        <td><?= $totals['receipt_confirmed_no'] ?></td>
                        <td><?= $totals['receipt_confirmed_pending'] ?></td>
                        <td><?= $totals['candidate_joined_yes'] ?></td>
                        <td><?= $totals['candidate_joined_no'] ?></td>
                        <td><?= $totals['candidate_joined_pending'] ?></td>
                        <td><?= $totals['shortlist_candidate_shortlisted'] ?></td>
                        <td><?= $totals['shortlist_candidate_selected'] ?></td>
                        <td><?= $totals['shortlist_candidate_rejected'] ?></td>
                        <td><?= $totals['shortlist_candidate_onhold'] ?></td>
                        <td><?= $totals['shortlist_candidate_not_interested'] ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php render_footer(); ?>
