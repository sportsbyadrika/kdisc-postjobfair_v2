<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

function fetch_distinct_values(string $column): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM($column), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";

    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function build_report_filters(): array
{
    return [
        'job_fair' => trim((string) ($_GET['job_fair'] ?? '')),
        'category' => trim((string) ($_GET['category'] ?? '')),
        'aggregator' => trim((string) ($_GET['aggregator'] ?? '')),
    ];
}

function build_where_clause(array $filters, array &$params): string
{
    $conditions = [];

    if ($filters['job_fair'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') = :job_fair";
        $params['job_fair'] = $filters['job_fair'];
    }

    if ($filters['category'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Category), ''), 'Unknown') = :category";
        $params['category'] = $filters['category'];
    }

    if ($filters['aggregator'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') = :aggregator";
        $params['aggregator'] = $filters['aggregator'];
    }

    if ($conditions === []) {
        return '';
    }

    return 'WHERE ' . implode(' AND ', $conditions);
}

function run_grouped_query(string $selectGroup, string $whereClause, array $params, string $metricSql): array
{
    $sql = "SELECT $selectGroup, $metricSql
        FROM job_fair_result
        $whereClause
        GROUP BY group_name
        ORDER BY group_name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function fetch_data_count_rows(string $groupExpression, array $filters): array
{
    $params = [];
    $whereClause = build_where_clause($filters, $params);

    return run_grouped_query(
        "$groupExpression AS group_name",
        $whereClause,
        $params,
        "SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS selected_count,
        SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted_count,
        SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'onhold' THEN 1 ELSE 0 END) AS on_hold_count,
        COUNT(*) AS total_count"
    );
}

function fetch_process_status_rows(string $groupExpression, array $filters): array
{
    $params = [];
    $whereClause = build_where_clause($filters, $params);

    return run_grouped_query(
        "$groupExpression AS group_name",
        $whereClause,
        $params,
        "COUNT(*) AS total_count,
        SUM(CASE WHEN LOWER(TRIM(First_Call_Done)) = 'yes' THEN 1 ELSE 0 END) AS first_call_done_count,
        SUM(CASE WHEN LOWER(TRIM(Offer_Letter_Generated)) = 'yes' THEN 1 ELSE 0 END) AS offer_letter_generated_count,
        SUM(CASE WHEN LOWER(TRIM(Link_to_Offer_letter_verified)) = 'yes' THEN 1 ELSE 0 END) AS offer_letter_verified_count,
        SUM(CASE WHEN LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'yes' THEN 1 ELSE 0 END) AS candidate_confirmed_count,
        SUM(CASE WHEN LOWER(TRIM(Candidate_Joined_Status)) = 'yes' THEN 1 ELSE 0 END) AS candidate_joined_count,
        SUM(CASE WHEN LOWER(REPLACE(TRIM(Shortlist_Candidate_Status), ' ', '')) = 'rejected' THEN 1 ELSE 0 END) AS rejected_count"
    );
}

function fetch_escalation_rows(string $groupExpression, array $filters): array
{
    $params = [];
    $whereClause = build_where_clause($filters, $params);

    return run_grouped_query(
        "$groupExpression AS group_name",
        $whereClause,
        $params,
        "COUNT(*) AS total_count,
        SUM(CASE WHEN LOWER(TRIM(Shortlist_Current_Process_Status)) = 'pending' THEN 1 ELSE 0 END) AS shortlist_process_pending_count,
        SUM(CASE WHEN LOWER(TRIM(First_Call_Done)) = 'pending' THEN 1 ELSE 0 END) AS first_call_pending_count,
        SUM(CASE WHEN LOWER(TRIM(Offer_Letter_Generated)) = 'pending' THEN 1 ELSE 0 END) AS offer_letter_pending_count,
        SUM(CASE WHEN LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'pending' THEN 1 ELSE 0 END) AS candidate_confirmation_pending_count,
        SUM(CASE WHEN LOWER(TRIM(Candidate_Joined_Status)) = 'pending' THEN 1 ELSE 0 END) AS joining_status_pending_count"
    );
}

function calculate_totals(array $rows, array $keys): array
{
    $totals = array_fill_keys($keys, 0);

    foreach ($rows as $row) {
        foreach ($keys as $key) {
            $totals[$key] += (int) ($row[$key] ?? 0);
        }
    }

    return $totals;
}

$filters = build_report_filters();
$jobFairOptions = fetch_distinct_values('Job_Fair_No');
$categoryOptions = fetch_distinct_values('Category');
$aggregatorOptions = fetch_distinct_values('Aggregator');

$groups = [
    'Aggregator wise' => "COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown')",
    'District wise' => "COALESCE(NULLIF(TRIM(SDPK_District), ''), 'Unknown')",
    'Employer wise' => "COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown')",
];

$dataCountReports = [];
$processReports = [];
$escalationReports = [];

foreach ($groups as $title => $expression) {
    $dataCountReports[$title] = fetch_data_count_rows($expression, $filters);
    $processReports[$title] = fetch_process_status_rows($expression, $filters);
    $escalationReports[$title] = fetch_escalation_rows($expression, $filters);
}

render_header('Job fair reports');
?>
<h1 class="h3 mb-4">Job fair reports</h1>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="job_fair" class="form-label">Job Fair</label>
                <select class="form-select" id="job_fair" name="job_fair">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['job_fair'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="category" class="form-label">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['category'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="aggregator" class="form-label">Aggregator</label>
                <select class="form-select" id="aggregator" name="aggregator">
                    <option value="">All Aggregators</option>
                    <?php foreach ($aggregatorOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['aggregator'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <a href="job_fair_reports.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php foreach ($dataCountReports as $reportTitle => $rows): ?>
    <?php $totals = calculate_totals($rows, ['selected_count', 'shortlisted_count', 'on_hold_count', 'total_count']); ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Data Count related report: <?= esc($reportTitle) ?></h2>
            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle mb-0">
                    <thead>
                    <tr>
                        <th><?= esc(str_replace(' wise', '', $reportTitle)) ?></th>
                        <th>Selected</th>
                        <th>Shortlisted</th>
                        <th>On Hold</th>
                        <th>Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="5" class="text-center text-muted">No data available.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['group_name']) ?></td>
                            <td><?= (int) $row['selected_count'] ?></td>
                            <td><?= (int) $row['shortlisted_count'] ?></td>
                            <td><?= (int) $row['on_hold_count'] ?></td>
                            <td><strong><?= (int) $row['total_count'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows !== []): ?>
                        <tr class="table-secondary fw-semibold">
                            <td>Total</td>
                            <td><?= $totals['selected_count'] ?></td>
                            <td><?= $totals['shortlisted_count'] ?></td>
                            <td><?= $totals['on_hold_count'] ?></td>
                            <td><strong><?= $totals['total_count'] ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($processReports as $reportTitle => $rows): ?>
    <?php $totals = calculate_totals($rows, ['total_count', 'first_call_done_count', 'offer_letter_generated_count', 'offer_letter_verified_count', 'candidate_confirmed_count', 'candidate_joined_count', 'rejected_count']); ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Process Status related report: <?= esc($reportTitle) ?></h2>
            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle mb-0">
                    <thead>
                    <tr>
                        <th><?= esc(str_replace(' wise', '', $reportTitle)) ?></th>
                        <th>Total</th>
                        <th>First call done</th>
                        <th>Offer letter generated</th>
                        <th>Offer letter verified</th>
                        <th>Candidate confirmed</th>
                        <th>Candidate joined</th>
                        <th>Rejected</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="8" class="text-center text-muted">No data available.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['group_name']) ?></td>
                            <td><?= (int) $row['total_count'] ?></td>
                            <td><?= (int) $row['first_call_done_count'] ?></td>
                            <td><?= (int) $row['offer_letter_generated_count'] ?></td>
                            <td><?= (int) $row['offer_letter_verified_count'] ?></td>
                            <td><?= (int) $row['candidate_confirmed_count'] ?></td>
                            <td><strong><?= (int) $row['candidate_joined_count'] ?></strong></td>
                            <td><?= (int) $row['rejected_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows !== []): ?>
                        <tr class="table-secondary fw-semibold">
                            <td>Total</td>
                            <td><?= $totals['total_count'] ?></td>
                            <td><?= $totals['first_call_done_count'] ?></td>
                            <td><?= $totals['offer_letter_generated_count'] ?></td>
                            <td><?= $totals['offer_letter_verified_count'] ?></td>
                            <td><?= $totals['candidate_confirmed_count'] ?></td>
                            <td><strong><?= $totals['candidate_joined_count'] ?></strong></td>
                            <td><?= $totals['rejected_count'] ?></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php foreach ($escalationReports as $reportTitle => $rows): ?>
    <?php $totals = calculate_totals($rows, ['total_count', 'shortlist_process_pending_count', 'first_call_pending_count', 'offer_letter_pending_count', 'candidate_confirmation_pending_count', 'joining_status_pending_count']); ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5">Escalation related report: <?= esc($reportTitle) ?></h2>
            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle mb-0">
                    <thead>
                    <tr>
                        <th><?= esc(str_replace(' wise', '', $reportTitle)) ?></th>
                        <th>Total</th>
                        <th>Shortlist process pending</th>
                        <th>First call pending</th>
                        <th>Offer letter pending</th>
                        <th>Candidate confirmation pending</th>
                        <th>Joining status pending</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($rows === []): ?>
                        <tr><td colspan="7" class="text-center text-muted">No data available.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['group_name']) ?></td>
                            <td><?= (int) $row['total_count'] ?></td>
                            <td><?= (int) $row['shortlist_process_pending_count'] ?></td>
                            <td><?= (int) $row['first_call_pending_count'] ?></td>
                            <td><?= (int) $row['offer_letter_pending_count'] ?></td>
                            <td><?= (int) $row['candidate_confirmation_pending_count'] ?></td>
                            <td><strong><?= (int) $row['joining_status_pending_count'] ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($rows !== []): ?>
                        <tr class="table-secondary fw-semibold">
                            <td>Total</td>
                            <td><?= $totals['total_count'] ?></td>
                            <td><?= $totals['shortlist_process_pending_count'] ?></td>
                            <td><?= $totals['first_call_pending_count'] ?></td>
                            <td><?= $totals['offer_letter_pending_count'] ?></td>
                            <td><?= $totals['candidate_confirmation_pending_count'] ?></td>
                            <td><strong><?= $totals['joining_status_pending_count'] ?></strong></td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php render_footer(); ?>
