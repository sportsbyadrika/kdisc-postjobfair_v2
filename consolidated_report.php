<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/consolidated_report_helpers.php';
require_auth();

function consolidated_metric_url(string $section, string $metric, ?string $jobFairRow, array $filters, array $extraParams = []): string
{
    $params = array_filter([
        'section' => $section,
        'metric' => $metric,
        'job_fair_row' => $jobFairRow,
        'aggregator' => $filters['aggregator'],
        'job_fair' => $filters['job_fair'],
        'category' => $filters['category'],
        'selection_status' => $filters['selection_status'],
        'round_number' => $extraParams['round_number'] ?? '',
        'round_selection_status' => $extraParams['round_selection_status'] ?? '',
        'call_stage' => $extraParams['call_stage'] ?? '',
    ], static fn($value): bool => $value !== null && $value !== '');

    return 'consolidated_report_candidates.php?' . http_build_query($params);
}

function render_metric_link(int $value, string $section, string $metric, ?string $jobFairRow, array $filters, array $extraParams = []): string
{
    $url = consolidated_metric_url($section, $metric, $jobFairRow, $filters, $extraParams);

    return sprintf(
        '<a href="%s">%d</a>',
        esc($url),
        $value
    );
}

$filters = build_consolidated_filters();
$aggregatorOptions = fetch_consolidated_distinct_values('Aggregator');
$jobFairOptions = fetch_consolidated_distinct_values('Job_Fair_No');
$categoryOptions = array_values(array_filter(
    fetch_consolidated_distinct_values('Category'),
    static fn(string $value): bool => strtolower($value) !== 'unknown'
));
$selectionStatusOptions = fetch_consolidated_distinct_values('Selection_Status');

$selectedRows = fetch_selected_candidates_report($filters);
$selectedTotals = calculate_consolidated_totals($selectedRows, [
    'total_selected_candidate',
    'offer_generated_yes',
    'offer_generated_no',
    'offer_link_with_link',
    'offer_link_blank',
    'link_verified_yes',
    'link_verified_no',
    'link_verified_pending',
    'receipt_confirmed_yes',
    'receipt_confirmed_no',
    'receipt_confirmed_pending',
    'joined_yes',
    'joined_no',
    'joined_pending',
]);

$shortlistedRows = fetch_shortlisted_onhold_report($filters);
$shortlistedTotals = calculate_consolidated_totals($shortlistedRows, [
    'total_shortlisted_onhold_candidate',
    'shortlist_status_selected',
    'shortlist_status_rtd_jobs',
    'shortlist_status_rejected',
    'shortlist_status_onhold_only',
    'shortlist_status_yet_to_be_contacted',
    'shortlist_status_review_in_progress',
    'shortlist_status_selected_for_next_round_net',
    'offer_generated_yes',
    'offer_generated_no',
    'offer_link_with_link',
    'offer_link_blank',
    'link_verified_yes',
    'link_verified_no',
    'link_verified_pending',
    'receipt_confirmed_yes',
    'receipt_confirmed_no',
    'receipt_confirmed_pending',
    'joined_yes',
    'joined_no',
    'joined_pending',
    'joined_future_date',
]);
$roundPivotReport = fetch_shortlisted_onhold_round_pivot_report($filters);
$roundPivotRows = $roundPivotReport['rows'];
$roundPivotRounds = $roundPivotReport['round_numbers'];
$roundPivotStatusLabels = $roundPivotReport['status_labels'];
$roundPivotSelectedReport = fetch_shortlisted_onhold_round_pivot_report($filters, 'selected');
$roundPivotSelectedRows = $roundPivotSelectedReport['rows'];
$roundPivotSelectedRounds = $roundPivotSelectedReport['round_numbers'];
$roundPivotSelectedStatusLabels = $roundPivotSelectedReport['status_labels'];
$callStagePivotReport = fetch_shortlisted_onhold_call_stage_pivot_report($filters);
$callStagePivotRows = $callStagePivotReport['rows'];
$callStagePivotStages = $callStagePivotReport['stages'];
$joinedStatusCallStagePivotReport = fetch_shortlisted_onhold_joined_status_call_stage_pivot_report($filters);
$joinedStatusCallStagePivotRows = $joinedStatusCallStagePivotReport['rows'];
$joinedStatusCallStagePivotStages = $joinedStatusCallStagePivotReport['stages'];

$districtUserActivityRows = fetch_district_user_activity_report($filters);
$districtUserActivityTotals = [
    'total_updates' => 0,
    'receipt_confirm_updates' => 0,
    'joined_status_updates' => 0,
    'willing_to_join_updates' => 0,
    'challenge_updates' => 0,
    'join_remarks_updates' => 0,
    'distinct_candidates' => 0,
    'distinct_users' => 0,
];

$crmUserActivityRows = fetch_crm_user_activity_report($filters);
$crmUserActivityTotals = [
    'total_updates' => 0,
    'shortlist_updates' => 0,
    'first_part_updates' => 0,
    'offer_generated_updates' => 0,
    'receipt_confirm_updates' => 0,
    'field_level_updates' => 0,
    'joined_status_updates' => 0,
    'calls_count' => 0,
    'distinct_candidates' => 0,
];

$districtDrillUrl = static function (string $district, string $view) use ($filters): string {
    return '/district_user_activity_detail.php?' . http_build_query(array_filter([
        'district' => $district,
        'view' => $view,
        'aggregator' => $filters['aggregator'] ?? '',
        'job_fair' => $filters['job_fair'] ?? '',
        'category' => $filters['category'] ?? '',
    ], static fn ($value): bool => $value !== ''));
};

// Over all summary tiles
$overallSelected = (int) ($selectedTotals['total_selected_candidate'] ?? 0);
$overallShortlisted = (int) ($shortlistedTotals['total_shortlisted_onhold_candidate'] ?? 0);
$overallShortlistSelected = (int) ($shortlistedTotals['shortlist_status_selected'] ?? 0);
$overallOfferGenerated = (int) ($selectedTotals['offer_generated_yes'] ?? 0) + (int) ($shortlistedTotals['offer_generated_yes'] ?? 0);
$overallReceiptYes = (int) ($selectedTotals['receipt_confirmed_yes'] ?? 0) + (int) ($shortlistedTotals['receipt_confirmed_yes'] ?? 0);
$overallJoinedYes = (int) ($selectedTotals['joined_yes'] ?? 0) + (int) ($shortlistedTotals['joined_yes'] ?? 0);
foreach ($crmUserActivityRows as $cr) {
    $crmUserActivityTotals['total_updates'] += (int) $cr['total_updates'];
    $crmUserActivityTotals['calls_count'] += (int) $cr['calls_count'];
}
$overallCrmUsers = count($crmUserActivityRows);
$overallDuUsers = 0;
foreach ($districtUserActivityRows as $dr) {
    $overallDuUsers += (int) $dr['distinct_users'];
}

render_header('Consolidated report', ['main_container_class' => 'container-fluid']);
render_page_header('Consolidated Report', [
    'icon' => 'bi-clipboard-data',
    'subtitle' => 'Cross-functional metrics across aggregators, employers and job fairs.',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="aggregator" class="form-label">Aggregator</label>
                <select class="form-select" id="aggregator" name="aggregator">
                    <option value="">All Aggregators</option>
                    <?php foreach ($aggregatorOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['aggregator'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="job_fair" class="form-label">Job Fair No</label>
                <select class="form-select" id="job_fair" name="job_fair">
                    <option value="">All Job Fairs</option>
                    <?php foreach ($jobFairOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['job_fair'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="category" class="form-label">Category</label>
                <select class="form-select" id="category" name="category">
                    <option value="">All Categories</option>
                    <?php foreach ($categoryOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['category'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="selection_status" class="form-label">Selection Status (section 2)</label>
                <select class="form-select" id="selection_status" name="selection_status">
                    <option value="">All</option>
                    <?php foreach ($selectionStatusOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['selection_status'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a href="consolidated_report.php" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<ul class="nav nav-tabs mb-3" id="reportTabs" role="tablist">
    <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-overall" type="button" role="tab"><i class="bi bi-bar-chart-line me-1"></i>Over all</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-selected" type="button" role="tab"><i class="bi bi-check-circle me-1"></i>Selected</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-shortlisted" type="button" role="tab"><i class="bi bi-list-check me-1"></i>Shortlisted / On hold</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-crm" type="button" role="tab"><i class="bi bi-headset me-1"></i>CRM</button></li>
    <li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-district" type="button" role="tab"><i class="bi bi-geo-alt me-1"></i>District User</button></li>
</ul>
<div class="tab-content">

<div class="tab-pane fade show active" id="tab-overall" role="tabpanel">
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-primary h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">Total Selected</p><p class="stat-value"><?= number_format($overallSelected) ?></p></div>
                <span class="stat-icon-box tone-primary"><i class="bi bi-person-badge-fill"></i></span>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-info h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">Shortlisted / On hold</p><p class="stat-value"><?= number_format($overallShortlisted) ?></p></div>
                <span class="stat-icon-box tone-info"><i class="bi bi-list-check"></i></span>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-purple h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">Shortlist Selected</p><p class="stat-value"><?= number_format($overallShortlistSelected) ?></p></div>
                <span class="stat-icon-box tone-purple"><i class="bi bi-person-check-fill"></i></span>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-info h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">Offer Letters Generated</p><p class="stat-value"><?= number_format($overallOfferGenerated) ?></p></div>
                <span class="stat-icon-box tone-info"><i class="bi bi-envelope-paper-fill"></i></span>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-success h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">Offer Receipt Confirmed</p><p class="stat-value"><?= number_format($overallReceiptYes) ?></p></div>
                <span class="stat-icon-box tone-success"><i class="bi bi-check2-circle"></i></span>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-success h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">Candidates Joined</p><p class="stat-value"><?= number_format($overallJoinedYes) ?></p></div>
                <span class="stat-icon-box tone-success"><i class="bi bi-door-open-fill"></i></span>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-danger h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">CRM Calls Logged</p><p class="stat-value"><?= number_format($crmUserActivityTotals['calls_count']) ?></p></div>
                <span class="stat-icon-box tone-danger"><i class="bi bi-telephone-fill"></i></span>
            </div></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="card card-stat accent-slate h-100"><div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div><p class="stat-label">Active Users (CRM &middot; DU)</p><p class="stat-value"><?= number_format($overallCrmUsers) ?> &middot; <?= number_format($overallDuUsers) ?></p></div>
                <span class="stat-icon-box tone-slate"><i class="bi bi-people-fill"></i></span>
            </div></div>
        </div>
    </div>
    <div class="card">
        <div class="card-body">
            <h2 class="h5 mb-2"><i class="bi bi-info-circle text-primary me-1"></i>How this report is organised</h2>
            <ul class="mb-0 small">
                <li><strong>Selected</strong> &mdash; section 1: Selected candidate funnel from offer letter generation to joining.</li>
                <li><strong>Shortlisted / On hold</strong> &mdash; sections 2&ndash;4: Shortlist conversion, interview rounds, and rounds of converted-selected candidates.</li>
                <li><strong>CRM</strong> &mdash; sections 5&ndash;6 plus CRM team activity review: Calls logged and per-CRM-member updates split by first part (up to receipt confirmed) vs field level.</li>
                <li><strong>District User</strong> &mdash; section 7: District-wise breakdown of updates done by District User accounts, with drill-down on users, challenges and join remarks.</li>
            </ul>
        </div>
    </div>
</div>

<div class="tab-pane fade" id="tab-selected" role="tabpanel">

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">First Section: List of Selected Candidate</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Job Fair No</th>
                    <th rowspan="2">Total Selected Candidate</th>
                    <th colspan="2" class="text-center">Offer Letter Generated</th>
                    <th colspan="2" class="text-center">Offer Letter Softcopy</th>
                    <th colspan="3" class="text-center">Softcopy Verified</th>
                    <th colspan="3" class="text-center">Offer Letter Receipt</th>
                    <th colspan="3" class="text-center">Candidate Joined</th>
                </tr>
                <tr>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Received</th>
                    <th>Not Received</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($selectedRows === []): ?>
                    <tr><td colspan="15" class="text-center text-muted">No data available.</td></tr>
                <?php endif; ?>
                <?php foreach ($selectedRows as $row): ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_metric_link((int) $row['total_selected_candidate'], 'selected', 'total_selected_candidate', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_generated_yes'], 'selected', 'offer_generated_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_generated_no'], 'selected', 'offer_generated_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_link_with_link'], 'selected', 'offer_link_with_link', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_link_blank'], 'selected', 'offer_link_blank', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['link_verified_yes'], 'selected', 'link_verified_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['link_verified_no'], 'selected', 'link_verified_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['link_verified_pending'], 'selected', 'link_verified_pending', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['receipt_confirmed_yes'], 'selected', 'receipt_confirmed_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['receipt_confirmed_no'], 'selected', 'receipt_confirmed_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['receipt_confirmed_pending'], 'selected', 'receipt_confirmed_pending', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['joined_yes'], 'selected', 'joined_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['joined_no'], 'selected', 'joined_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['joined_pending'], 'selected', 'joined_pending', (string) $row['job_fair_no'], $filters) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($selectedRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total</td>
                        <td><?= render_metric_link($selectedTotals['total_selected_candidate'], 'selected', 'total_selected_candidate', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['offer_generated_yes'], 'selected', 'offer_generated_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['offer_generated_no'], 'selected', 'offer_generated_no', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['offer_link_with_link'], 'selected', 'offer_link_with_link', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['offer_link_blank'], 'selected', 'offer_link_blank', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['link_verified_yes'], 'selected', 'link_verified_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['link_verified_no'], 'selected', 'link_verified_no', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['link_verified_pending'], 'selected', 'link_verified_pending', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['receipt_confirmed_yes'], 'selected', 'receipt_confirmed_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['receipt_confirmed_no'], 'selected', 'receipt_confirmed_no', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['receipt_confirmed_pending'], 'selected', 'receipt_confirmed_pending', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['joined_yes'], 'selected', 'joined_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['joined_no'], 'selected', 'joined_no', null, $filters) ?></td>
                        <td><?= render_metric_link($selectedTotals['joined_pending'], 'selected', 'joined_pending', null, $filters) ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /#tab-selected -->

<div class="tab-pane fade" id="tab-shortlisted" role="tabpanel">

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Second Section: List of Shortlisted/Onhold Candidates</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Job Fair No</th>
                    <th rowspan="2">Total Shortlisted/Onhold Candidate</th>
                    <th colspan="7" class="text-center">Shortlisted Conversion</th>
                    <th colspan="2" class="text-center">Offer Letter Generated</th>
                    <th colspan="2" class="text-center">Offer Letter Softcopy</th>
                    <th colspan="3" class="text-center">Softcopy Verified</th>
                    <th colspan="3" class="text-center">Offer Letter Receipt Confirmed</th>
                    <th colspan="4" class="text-center">Candidate Joined</th>
                </tr>
                <tr>
                    <th>Selected</th>
                    <th>RTD Jobs</th>
                    <th>Rejected</th>
                    <th>Onhold</th>
                    <th>Yet to be contacted</th>
                    <th>Review in progress</th>
                    <th>Selected for next round</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Received</th>
                    <th>Not Received</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Yes</th>
                    <th>No</th>
                    <th>Pending</th>
                    <th>Future Date</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($shortlistedRows === []): ?>
                    <tr><td colspan="23" class="text-center text-muted">No data available.</td></tr>
                <?php endif; ?>
                <?php foreach ($shortlistedRows as $row): ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_metric_link((int) $row['total_shortlisted_onhold_candidate'], 'shortlisted', 'total_shortlisted_onhold_candidate', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_selected'], 'shortlisted', 'shortlist_status_selected', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_rtd_jobs'], 'shortlisted', 'shortlist_status_rtd_jobs', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_rejected'], 'shortlisted', 'shortlist_status_rejected', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_onhold_only'], 'shortlisted', 'shortlist_status_onhold_only', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_yet_to_be_contacted'], 'shortlisted', 'shortlist_status_yet_to_be_contacted', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_review_in_progress'], 'shortlisted', 'shortlist_status_review_in_progress', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_selected_for_next_round_net'], 'shortlisted', 'shortlist_status_selected_for_next_round_net', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_generated_yes'], 'shortlisted', 'offer_generated_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_generated_no'], 'shortlisted', 'offer_generated_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_link_with_link'], 'shortlisted', 'offer_link_with_link', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['offer_link_blank'], 'shortlisted', 'offer_link_blank', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['link_verified_yes'], 'shortlisted', 'link_verified_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['link_verified_no'], 'shortlisted', 'link_verified_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['link_verified_pending'], 'shortlisted', 'link_verified_pending', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['receipt_confirmed_yes'], 'shortlisted', 'receipt_confirmed_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['receipt_confirmed_no'], 'shortlisted', 'receipt_confirmed_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['receipt_confirmed_pending'], 'shortlisted', 'receipt_confirmed_pending', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['joined_yes'], 'shortlisted', 'joined_yes', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['joined_no'], 'shortlisted', 'joined_no', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['joined_pending'], 'shortlisted', 'joined_pending', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) ($row['joined_future_date'] ?? 0), 'shortlisted', 'joined_future_date', (string) $row['job_fair_no'], $filters) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($shortlistedRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total</td>
                        <td><?= render_metric_link($shortlistedTotals['total_shortlisted_onhold_candidate'], 'shortlisted', 'total_shortlisted_onhold_candidate', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_selected'], 'shortlisted', 'shortlist_status_selected', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_rtd_jobs'], 'shortlisted', 'shortlist_status_rtd_jobs', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_rejected'], 'shortlisted', 'shortlist_status_rejected', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_onhold_only'], 'shortlisted', 'shortlist_status_onhold_only', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_yet_to_be_contacted'], 'shortlisted', 'shortlist_status_yet_to_be_contacted', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_review_in_progress'], 'shortlisted', 'shortlist_status_review_in_progress', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_selected_for_next_round_net'], 'shortlisted', 'shortlist_status_selected_for_next_round_net', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['offer_generated_yes'], 'shortlisted', 'offer_generated_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['offer_generated_no'], 'shortlisted', 'offer_generated_no', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['offer_link_with_link'], 'shortlisted', 'offer_link_with_link', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['offer_link_blank'], 'shortlisted', 'offer_link_blank', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['link_verified_yes'], 'shortlisted', 'link_verified_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['link_verified_no'], 'shortlisted', 'link_verified_no', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['link_verified_pending'], 'shortlisted', 'link_verified_pending', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['receipt_confirmed_yes'], 'shortlisted', 'receipt_confirmed_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['receipt_confirmed_no'], 'shortlisted', 'receipt_confirmed_no', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['receipt_confirmed_pending'], 'shortlisted', 'receipt_confirmed_pending', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['joined_yes'], 'shortlisted', 'joined_yes', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['joined_no'], 'shortlisted', 'joined_no', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['joined_pending'], 'shortlisted', 'joined_pending', null, $filters) ?></td>
                        <td><?= render_metric_link((int) ($shortlistedTotals['joined_future_date'] ?? 0), 'shortlisted', 'joined_future_date', null, $filters) ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$districtJobstationRows = fetch_shortlisted_district_jobstation_joined_report($filters);
$districtJobstationTotals = [
    'job_station_count' => 0,
    'selected_joined_yes' => 0, 'selected_joined_no' => 0, 'selected_joined_pending' => 0,
    'shortlist_joined_yes' => 0, 'shortlist_joined_no' => 0, 'shortlist_joined_pending' => 0,
];
foreach ($districtJobstationRows as $r) {
    foreach (array_keys($districtJobstationTotals) as $k) {
        $districtJobstationTotals[$k] += (int) ($r[$k] ?? 0);
    }
}
?>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">District wise &middot; Job Station and Joined Status (Offer Letter Receipt = Yes)</h2>
        <p class="data-meta mb-3">
            <i class="bi bi-info-circle me-1"></i>
            District-wise count of distinct job stations affected, plus joining outcomes split into two cohorts whose <strong>Offer Letter Receipt Confirmed = Yes</strong>: directly Selected candidates, and Shortlisted/On hold candidates whose Final Status = Selected. Honours Aggregator / Job Fair / Category filters above.
        </p>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th rowspan="2">Sl No</th>
                        <th rowspan="2">Candidate District</th>
                        <th rowspan="2" class="text-end">Job Stations</th>
                        <th colspan="3" class="text-center">Selected &mdash; Candidate Joined</th>
                        <th colspan="3" class="text-center">Shortlist Final Selected &mdash; Candidate Joined</th>
                    </tr>
                    <tr>
                        <th class="text-end">Yes</th>
                        <th class="text-end">No</th>
                        <th class="text-end">Pending</th>
                        <th class="text-end">Yes</th>
                        <th class="text-end">No</th>
                        <th class="text-end">Pending</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($districtJobstationRows === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No data available for the selected filters.</div></td></tr>
                <?php endif; ?>
                <?php $djIdx = 1; foreach ($districtJobstationRows as $djRow): ?>
                    <tr>
                        <td><?= $djIdx++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $djRow['district']) ?></td>
                        <td class="text-end"><?= number_format((int) $djRow['job_station_count']) ?></td>
                        <td class="text-end"><?= number_format((int) $djRow['selected_joined_yes']) ?></td>
                        <td class="text-end"><?= number_format((int) $djRow['selected_joined_no']) ?></td>
                        <td class="text-end"><?= number_format((int) $djRow['selected_joined_pending']) ?></td>
                        <td class="text-end"><?= number_format((int) $djRow['shortlist_joined_yes']) ?></td>
                        <td class="text-end"><?= number_format((int) $djRow['shortlist_joined_no']) ?></td>
                        <td class="text-end"><?= number_format((int) $djRow['shortlist_joined_pending']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($districtJobstationRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= number_format($districtJobstationTotals['job_station_count']) ?></td>
                        <td class="text-end"><?= number_format($districtJobstationTotals['selected_joined_yes']) ?></td>
                        <td class="text-end"><?= number_format($districtJobstationTotals['selected_joined_no']) ?></td>
                        <td class="text-end"><?= number_format($districtJobstationTotals['selected_joined_pending']) ?></td>
                        <td class="text-end"><?= number_format($districtJobstationTotals['shortlist_joined_yes']) ?></td>
                        <td class="text-end"><?= number_format($districtJobstationTotals['shortlist_joined_no']) ?></td>
                        <td class="text-end"><?= number_format($districtJobstationTotals['shortlist_joined_pending']) ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$joinRemarksPivotRows = fetch_shortlisted_join_remarks_pivot($filters);
$joinRemarksPivotTotals = ['joined_yes' => 0, 'joined_no' => 0, 'joined_pending' => 0, 'joined_future_date' => 0, 'total' => 0];
foreach ($joinRemarksPivotRows as $r) {
    foreach (array_keys($joinRemarksPivotTotals) as $k) {
        $joinRemarksPivotTotals[$k] += (int) ($r[$k] ?? 0);
    }
}
?>
<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Candidate Join Remarks Type &times; Candidate Joined Status (Shortlisted / On hold)</h2>
        <p class="data-meta mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Pivot of <strong>Candidate Join Remarks Type</strong> by <strong>Candidate Joined Status</strong> across all Shortlisted / On hold candidates matching the filters above.
        </p>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>Candidate Join Remarks Type</th>
                        <th class="text-end">Joined: Yes</th>
                        <th class="text-end">Joined: No</th>
                        <th class="text-end">Joined: Pending</th>
                        <th class="text-end">Joined: Future Date</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($joinRemarksPivotRows === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No remarks data available.</div></td></tr>
                <?php endif; ?>
                <?php $jrIdx = 1; foreach ($joinRemarksPivotRows as $jrRow): ?>
                    <tr>
                        <td><?= $jrIdx++ ?></td>
                        <td class="fw-semibold"><?= esc((string) $jrRow['remark_type']) ?></td>
                        <td class="text-end"><?= number_format((int) $jrRow['joined_yes']) ?></td>
                        <td class="text-end"><?= number_format((int) $jrRow['joined_no']) ?></td>
                        <td class="text-end"><?= number_format((int) $jrRow['joined_pending']) ?></td>
                        <td class="text-end"><?= number_format((int) $jrRow['joined_future_date']) ?></td>
                        <td class="text-end"><strong><?= number_format((int) $jrRow['total']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($joinRemarksPivotRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= number_format($joinRemarksPivotTotals['joined_yes']) ?></td>
                        <td class="text-end"><?= number_format($joinRemarksPivotTotals['joined_no']) ?></td>
                        <td class="text-end"><?= number_format($joinRemarksPivotTotals['joined_pending']) ?></td>
                        <td class="text-end"><?= number_format($joinRemarksPivotTotals['joined_future_date']) ?></td>
                        <td class="text-end"><?= number_format($joinRemarksPivotTotals['total']) ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Third Section: List of Shortlisted/On hold Interview Rounds</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Job Fair No</th>
                    <th rowspan="2">Total Shortlisted/Onhold Candidate count</th>
                    <th rowspan="2">Shortlisted Conversion pending count</th>
                    <?php if ($roundPivotRounds === []): ?>
                        <th rowspan="2">Round based pivot report</th>
                    <?php else: ?>
                        <?php foreach ($roundPivotRounds as $roundNumber): ?>
                            <th colspan="<?= count($roundPivotStatusLabels) ?>" class="text-center">Round <?= esc($roundNumber) ?></th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
                <tr>
                    <?php foreach ($roundPivotRounds as $roundNumber): ?>
                        <?php foreach ($roundPivotStatusLabels as $statusLabel): ?>
                            <th><?= esc($statusLabel) ?></th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($roundPivotRows === []): ?>
                    <tr><td colspan="<?= $roundPivotRounds === [] ? 4 : (3 + (count($roundPivotRounds) * count($roundPivotStatusLabels))) ?>" class="text-center text-muted">No data available.</td></tr>
                <?php endif; ?>
                <?php
                $pivotTotals = [];
                foreach ($roundPivotRounds as $roundNumber) {
                    foreach ($roundPivotStatusLabels as $statusLabel) {
                        $pivotTotals[$roundNumber][$statusLabel] = 0;
                    }
                }
                $totalShortlisted = 0;
                $totalPending = 0;
                ?>
                <?php foreach ($roundPivotRows as $row): ?>
                    <?php
                    $totalShortlisted += (int) $row['total_shortlisted_onhold_candidate'];
                    $totalPending += (int) $row['shortlist_conversion_count'];
                    ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_metric_link((int) $row['total_shortlisted_onhold_candidate'], 'shortlisted_rounds_pending', 'total_shortlisted_onhold_candidate', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_conversion_count'], 'shortlisted_rounds_pending', 'shortlist_conversion_pending_count', (string) $row['job_fair_no'], $filters) ?></td>
                        <?php if ($roundPivotRounds === []): ?>
                            <td class="text-center text-muted">No rounds found</td>
                        <?php else: ?>
                            <?php foreach ($roundPivotRounds as $roundNumber): ?>
                                <?php foreach ($roundPivotStatusLabels as $statusLabel): ?>
                                    <?php $count = (int) ($row['pivot'][$roundNumber][$statusLabel] ?? 0); ?>
                                    <?php $pivotTotals[$roundNumber][$statusLabel] += $count; ?>
                                    <td><?= render_metric_link($count, 'shortlisted_rounds_pending', 'round_status_count', (string) $row['job_fair_no'], $filters, ['round_number' => $roundNumber, 'round_selection_status' => $statusLabel]) ?></td>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($roundPivotRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total</td>
                        <td><?= render_metric_link($totalShortlisted, 'shortlisted_rounds_pending', 'total_shortlisted_onhold_candidate', null, $filters) ?></td>
                        <td><?= render_metric_link($totalPending, 'shortlisted_rounds_pending', 'shortlist_conversion_pending_count', null, $filters) ?></td>
                        <?php foreach ($roundPivotRounds as $roundNumber): ?>
                            <?php foreach ($roundPivotStatusLabels as $statusLabel): ?>
                                <td><?= render_metric_link((int) $pivotTotals[$roundNumber][$statusLabel], 'shortlisted_rounds_pending', 'round_status_count', null, $filters, ['round_number' => $roundNumber, 'round_selection_status' => $statusLabel]) ?></td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Fourth Section: List of Shortlisted/On hold Interview rounds of Selected Candidates</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Job Fair No</th>
                    <th rowspan="2">Total Shortlisted/Onhold Candidate count</th>
                    <th rowspan="2">Shortlisted Conversion Selected count</th>
                    <?php if ($roundPivotSelectedRounds === []): ?>
                        <th rowspan="2">Round based pivot report</th>
                    <?php else: ?>
                        <?php foreach ($roundPivotSelectedRounds as $roundNumber): ?>
                            <th colspan="<?= count($roundPivotSelectedStatusLabels) ?>" class="text-center">Round <?= esc($roundNumber) ?></th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
                <tr>
                    <?php foreach ($roundPivotSelectedRounds as $roundNumber): ?>
                        <?php foreach ($roundPivotSelectedStatusLabels as $statusLabel): ?>
                            <th><?= esc($statusLabel) ?></th>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($roundPivotSelectedRows === []): ?>
                    <tr><td colspan="<?= $roundPivotSelectedRounds === [] ? 4 : (3 + (count($roundPivotSelectedRounds) * count($roundPivotSelectedStatusLabels))) ?>" class="text-center text-muted">No data available.</td></tr>
                <?php endif; ?>
                <?php
                $pivotSelectedTotals = [];
                foreach ($roundPivotSelectedRounds as $roundNumber) {
                    foreach ($roundPivotSelectedStatusLabels as $statusLabel) {
                        $pivotSelectedTotals[$roundNumber][$statusLabel] = 0;
                    }
                }
                $totalSelectedShortlisted = 0;
                $totalSelectedConversion = 0;
                ?>
                <?php foreach ($roundPivotSelectedRows as $row): ?>
                    <?php
                    $totalSelectedShortlisted += (int) $row['total_shortlisted_onhold_candidate'];
                    $totalSelectedConversion += (int) $row['shortlist_conversion_count'];
                    ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_metric_link((int) $row['total_shortlisted_onhold_candidate'], 'shortlisted_rounds_selected', 'total_shortlisted_onhold_candidate', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_conversion_count'], 'shortlisted_rounds_selected', 'shortlist_conversion_selected_count', (string) $row['job_fair_no'], $filters) ?></td>
                        <?php if ($roundPivotSelectedRounds === []): ?>
                            <td class="text-center text-muted">No rounds found</td>
                        <?php else: ?>
                            <?php foreach ($roundPivotSelectedRounds as $roundNumber): ?>
                                <?php foreach ($roundPivotSelectedStatusLabels as $statusLabel): ?>
                                    <?php $count = (int) ($row['pivot'][$roundNumber][$statusLabel] ?? 0); ?>
                                    <?php $pivotSelectedTotals[$roundNumber][$statusLabel] += $count; ?>
                                    <td><?= render_metric_link($count, 'shortlisted_rounds_selected', 'round_status_count', (string) $row['job_fair_no'], $filters, ['round_number' => $roundNumber, 'round_selection_status' => $statusLabel]) ?></td>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($roundPivotSelectedRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total</td>
                        <td><?= render_metric_link($totalSelectedShortlisted, 'shortlisted_rounds_selected', 'total_shortlisted_onhold_candidate', null, $filters) ?></td>
                        <td><?= render_metric_link($totalSelectedConversion, 'shortlisted_rounds_selected', 'shortlist_conversion_selected_count', null, $filters) ?></td>
                        <?php foreach ($roundPivotSelectedRounds as $roundNumber): ?>
                            <?php foreach ($roundPivotSelectedStatusLabels as $statusLabel): ?>
                                <td><?= render_metric_link((int) $pivotSelectedTotals[$roundNumber][$statusLabel], 'shortlisted_rounds_selected', 'round_status_count', null, $filters, ['round_number' => $roundNumber, 'round_selection_status' => $statusLabel]) ?></td>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /#tab-shortlisted -->

<div class="tab-pane fade" id="tab-crm" role="tabpanel">

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Fifth Section: CRM Call count based on Shortlisted/On hold candidates</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Job Fair No</th>
                    <th rowspan="2">Total Shortlisted/Onhold Candidate count</th>
                    <th rowspan="2">Shortlisted Conversion pending count</th>
                    <?php if ($callStagePivotStages === []): ?>
                        <th rowspan="2">Call History Stage based pivot report</th>
                    <?php else: ?>
                        <th colspan="<?= count($callStagePivotStages) ?>" class="text-center">Call History Stage based pivot report</th>
                    <?php endif; ?>
                </tr>
                <tr>
                    <?php foreach ($callStagePivotStages as $stageLabel): ?>
                        <th><?= esc($stageLabel) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($callStagePivotRows === []): ?>
                    <tr><td colspan="<?= $callStagePivotStages === [] ? 4 : (3 + count($callStagePivotStages)) ?>" class="text-center text-muted">No data available.</td></tr>
                <?php endif; ?>
                <?php
                $callStageTotals = array_fill_keys($callStagePivotStages, 0);
                $crmTotalShortlisted = 0;
                $crmTotalPending = 0;
                ?>
                <?php foreach ($callStagePivotRows as $row): ?>
                    <?php
                    $crmTotalShortlisted += (int) $row['total_shortlisted_onhold_candidate'];
                    $crmTotalPending += (int) $row['shortlist_conversion_count'];
                    ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_metric_link((int) $row['total_shortlisted_onhold_candidate'], 'crm_call_count_pending', 'total_shortlisted_onhold_candidate', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_conversion_count'], 'crm_call_count_pending', 'shortlist_conversion_pending_count', (string) $row['job_fair_no'], $filters) ?></td>
                        <?php if ($callStagePivotStages === []): ?>
                            <td class="text-center text-muted">No call history found</td>
                        <?php else: ?>
                            <?php foreach ($callStagePivotStages as $stageLabel): ?>
                                <?php $count = (int) ($row['pivot'][$stageLabel] ?? 0); ?>
                                <?php $callStageTotals[$stageLabel] += $count; ?>
                                <td><?= render_metric_link($count, 'crm_call_count_pending', 'call_stage_count', (string) $row['job_fair_no'], $filters, ['call_stage' => $stageLabel]) ?></td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($callStagePivotRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total</td>
                        <td><?= render_metric_link($crmTotalShortlisted, 'crm_call_count_pending', 'total_shortlisted_onhold_candidate', null, $filters) ?></td>
                        <td><?= render_metric_link($crmTotalPending, 'crm_call_count_pending', 'shortlist_conversion_pending_count', null, $filters) ?></td>
                        <?php foreach ($callStagePivotStages as $stageLabel): ?>
                            <td><?= render_metric_link((int) $callStageTotals[$stageLabel], 'crm_call_count_pending', 'call_stage_count', null, $filters, ['call_stage' => $stageLabel]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Sixth Section: CRM Call count based on Shortlisted/On hold candidates (based on Candidate Joined Status)</h2>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Job Fair No</th>
                    <th rowspan="2">Selected count</th>
                    <th rowspan="2">Shortlisted/Onhold converted Selected count</th>
                    <th rowspan="2">Candidate Joined Status count (other than Yes/No)</th>
                    <?php if ($joinedStatusCallStagePivotStages === []): ?>
                        <th rowspan="2">Call History Stage based pivot report</th>
                    <?php else: ?>
                        <th colspan="<?= count($joinedStatusCallStagePivotStages) ?>" class="text-center">Call History Stage based pivot report</th>
                    <?php endif; ?>
                </tr>
                <tr>
                    <?php foreach ($joinedStatusCallStagePivotStages as $stageLabel): ?>
                        <th><?= esc($stageLabel) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($joinedStatusCallStagePivotRows === []): ?>
                    <tr><td colspan="<?= $joinedStatusCallStagePivotStages === [] ? 5 : (4 + count($joinedStatusCallStagePivotStages)) ?>" class="text-center text-muted">No data available.</td></tr>
                <?php endif; ?>
                <?php
                $joinedStatusCallStageTotals = array_fill_keys($joinedStatusCallStagePivotStages, 0);
                $joinedStatusSelectedCount = 0;
                $joinedStatusConvertedSelectedCount = 0;
                $joinedStatusOtherCount = 0;
                ?>
                <?php foreach ($joinedStatusCallStagePivotRows as $row): ?>
                    <?php
                    $joinedStatusSelectedCount += (int) $row['selected_candidate_count'];
                    $joinedStatusConvertedSelectedCount += (int) $row['shortlist_converted_selected_count'];
                    $joinedStatusOtherCount += (int) $row['candidate_joined_status_other_count'];
                    ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_metric_link((int) $row['selected_candidate_count'], 'crm_call_count_joined_status', 'selected_candidate_count', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_converted_selected_count'], 'crm_call_count_joined_status', 'shortlist_converted_selected_count', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['candidate_joined_status_other_count'], 'crm_call_count_joined_status', 'candidate_joined_status_other_count', (string) $row['job_fair_no'], $filters) ?></td>
                        <?php if ($joinedStatusCallStagePivotStages === []): ?>
                            <td class="text-center text-muted">No call history found</td>
                        <?php else: ?>
                            <?php foreach ($joinedStatusCallStagePivotStages as $stageLabel): ?>
                                <?php $count = (int) ($row['pivot'][$stageLabel] ?? 0); ?>
                                <?php $joinedStatusCallStageTotals[$stageLabel] += $count; ?>
                                <td><?= render_metric_link($count, 'crm_call_count_joined_status', 'call_stage_count_joined_other', (string) $row['job_fair_no'], $filters, ['call_stage' => $stageLabel]) ?></td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($joinedStatusCallStagePivotRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total</td>
                        <td><?= render_metric_link($joinedStatusSelectedCount, 'crm_call_count_joined_status', 'selected_candidate_count', null, $filters) ?></td>
                        <td><?= render_metric_link($joinedStatusConvertedSelectedCount, 'crm_call_count_joined_status', 'shortlist_converted_selected_count', null, $filters) ?></td>
                        <td><?= render_metric_link($joinedStatusOtherCount, 'crm_call_count_joined_status', 'candidate_joined_status_other_count', null, $filters) ?></td>
                        <?php foreach ($joinedStatusCallStagePivotStages as $stageLabel): ?>
                            <td><?= render_metric_link((int) $joinedStatusCallStageTotals[$stageLabel], 'crm_call_count_joined_status', 'call_stage_count_joined_other', null, $filters, ['call_stage' => $stageLabel]) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">CRM Team Activity Review</h2>
        <p class="data-meta mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Per-CRM-member breakdown of updates and calls logged. <strong>First part</strong> covers fields up to <em>Confirm Offer Letter Receipt by Candidate</em> (First Call Done, Offer Letter Generated, Link to Offer Letter, Receipt Confirmed). <strong>Field level</strong> covers the joining workflow that follows (Willing to Join, Challenge, Candidate Joined Status / Date, Join Remarks, Employer Response). Honours the Aggregator, Job Fair No and Category filters above.
        </p>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Sl No</th>
                    <th rowspan="2">CRM Member</th>
                    <th rowspan="2">Mobile</th>
                    <th rowspan="2">Calls Logged</th>
                    <th rowspan="2">Candidates Touched</th>
                    <th rowspan="2">Total Updates</th>
                    <th rowspan="2">Shortlist Updates</th>
                    <th colspan="3" class="text-center">First Part (up to Offer Letter Receipt)</th>
                    <th colspan="3" class="text-center">Field Level (post Receipt)</th>
                    <th rowspan="2">Last Activity</th>
                </tr>
                <tr>
                    <th>Total</th>
                    <th>Offer Letter Generated</th>
                    <th>Receipt Confirmed</th>
                    <th>Total</th>
                    <th>Joined Status</th>
                    <th>% Reached Field Level</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($crmUserActivityRows === []): ?>
                    <tr><td colspan="13"><div class="empty-state"><i class="bi bi-inbox"></i>No CRM activity recorded yet for the selected filters.</div></td></tr>
                <?php endif; ?>
                <?php $crmIndex = 1; ?>
                <?php foreach ($crmUserActivityRows as $crmRow): ?>
                    <?php
                        $crmTotal = (int) ($crmRow['total_updates'] ?? 0);
                        $crmShortlist = (int) ($crmRow['shortlist_updates'] ?? 0);
                        $crmFirstPart = (int) ($crmRow['first_part_updates'] ?? 0);
                        $crmOfferGen = (int) ($crmRow['offer_generated_updates'] ?? 0);
                        $crmReceipt = (int) ($crmRow['receipt_confirm_updates'] ?? 0);
                        $crmFieldLevel = (int) ($crmRow['field_level_updates'] ?? 0);
                        $crmJoinedStatus = (int) ($crmRow['joined_status_updates'] ?? 0);
                        $crmCalls = (int) ($crmRow['calls_count'] ?? 0);
                        $crmDistinctCandidates = (int) ($crmRow['distinct_candidates'] ?? 0);
                        $crmLast = $crmRow['last_activity'] ?? null;
                        $fieldLevelPct = $crmTotal > 0 ? round(($crmFieldLevel / $crmTotal) * 100, 1) : 0;

                        $crmUserActivityTotals['shortlist_updates'] += $crmShortlist;
                        $crmUserActivityTotals['first_part_updates'] += $crmFirstPart;
                        $crmUserActivityTotals['offer_generated_updates'] += $crmOfferGen;
                        $crmUserActivityTotals['receipt_confirm_updates'] += $crmReceipt;
                        $crmUserActivityTotals['field_level_updates'] += $crmFieldLevel;
                        $crmUserActivityTotals['joined_status_updates'] += $crmJoinedStatus;
                        $crmUserActivityTotals['distinct_candidates'] += $crmDistinctCandidates;
                    ?>
                    <tr>
                        <td><?= $crmIndex++ ?></td>
                        <td class="fw-semibold"><?= esc($crmRow['user_name']) ?></td>
                        <td><?= esc($crmRow['mobile_number']) ?></td>
                        <td><?= number_format($crmCalls) ?></td>
                        <td><?= number_format($crmDistinctCandidates) ?></td>
                        <td><?= number_format($crmTotal) ?></td>
                        <td><?= number_format($crmShortlist) ?></td>
                        <td><?= number_format($crmFirstPart) ?></td>
                        <td><?= number_format($crmOfferGen) ?></td>
                        <td><?= number_format($crmReceipt) ?></td>
                        <td><?= number_format($crmFieldLevel) ?></td>
                        <td><?= number_format($crmJoinedStatus) ?></td>
                        <td><?php if ($crmFieldLevel > 0): ?><span class="status-chip status-yes"><?= $fieldLevelPct ?>%</span><?php else: ?><span class="status-chip status-neutral">0%</span><?php endif; ?></td>
                        <td><?= $crmLast ? esc(date('d M Y, H:i', strtotime((string) $crmLast))) : '<span class="text-muted">&mdash;</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($crmUserActivityRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="3">Total</td>
                        <td><?= number_format($crmUserActivityTotals['calls_count']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['distinct_candidates']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['total_updates']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['shortlist_updates']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['first_part_updates']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['offer_generated_updates']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['receipt_confirm_updates']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['field_level_updates']) ?></td>
                        <td><?= number_format($crmUserActivityTotals['joined_status_updates']) ?></td>
                        <td>&mdash;</td>
                        <td>&mdash;</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div><!-- /#tab-crm -->

<div class="tab-pane fade" id="tab-district" role="tabpanel">

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Seventh Section: District User Activity Review</h2>
        <p class="data-meta mb-3">
            <i class="bi bi-info-circle me-1"></i>
            District wise summary of updates made by <strong>District Users</strong> on candidate records &mdash; useful to review their activity on offer letter receipt confirmation, candidate joined status and related fields. Honours the Aggregator, Job Fair No and Category filters above (Selection Status filter does not apply here). Counts reflect save events, not individual field changes.
        </p>
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th rowspan="2">Sl No</th>
                    <th rowspan="2">Candidate District</th>
                    <th rowspan="2">Distinct District Users</th>
                    <th rowspan="2">Candidates Touched</th>
                    <th rowspan="2">Total Updates</th>
                    <th colspan="5" class="text-center">Updates by Field</th>
                    <th rowspan="2">Last Activity</th>
                </tr>
                <tr>
                    <th>Offer Letter Receipt Confirmed</th>
                    <th>Candidate Joined Status</th>
                    <th>Willing to Join</th>
                    <th>Challenge</th>
                    <th>Join Remarks</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($districtUserActivityRows === []): ?>
                    <tr><td colspan="11"><div class="empty-state"><i class="bi bi-inbox"></i>No district user activity recorded yet for the selected filters.</div></td></tr>
                <?php endif; ?>
                <?php $duIndex = 1; ?>
                <?php foreach ($districtUserActivityRows as $duRow): ?>
                    <?php
                        $totalUpdates = (int) ($duRow['total_updates'] ?? 0);
                        $receiptConfirm = (int) ($duRow['receipt_confirm_updates'] ?? 0);
                        $joinedStatus = (int) ($duRow['joined_status_updates'] ?? 0);
                        $willingToJoin = (int) ($duRow['willing_to_join_updates'] ?? 0);
                        $challenge = (int) ($duRow['challenge_updates'] ?? 0);
                        $joinRemarks = (int) ($duRow['join_remarks_updates'] ?? 0);
                        $distinctCandidates = (int) ($duRow['distinct_candidates'] ?? 0);
                        $distinctUsers = (int) ($duRow['distinct_users'] ?? 0);
                        $lastActivity = $duRow['last_activity'] ?? null;

                        $districtUserActivityTotals['total_updates'] += $totalUpdates;
                        $districtUserActivityTotals['receipt_confirm_updates'] += $receiptConfirm;
                        $districtUserActivityTotals['joined_status_updates'] += $joinedStatus;
                        $districtUserActivityTotals['willing_to_join_updates'] += $willingToJoin;
                        $districtUserActivityTotals['challenge_updates'] += $challenge;
                        $districtUserActivityTotals['join_remarks_updates'] += $joinRemarks;
                        $districtUserActivityTotals['distinct_candidates'] += $distinctCandidates;
                        $districtUserActivityTotals['distinct_users'] += $distinctUsers;
                    ?>
                    <?php
                        $usersUrl = $districtDrillUrl((string) $duRow['district'], 'users');
                        $challengeUrl = $districtDrillUrl((string) $duRow['district'], 'challenge');
                        $remarksUrl = $districtDrillUrl((string) $duRow['district'], 'join_remarks');
                    ?>
                    <tr>
                        <td><?= $duIndex ?></td>
                        <td class="fw-semibold"><?= esc($duRow['district']) ?></td>
                        <td><?php if ($distinctUsers > 0): ?><a href="<?= esc($usersUrl) ?>"><?= number_format($distinctUsers) ?></a><?php else: ?>0<?php endif; ?></td>
                        <td><?= number_format($distinctCandidates) ?></td>
                        <td><?= number_format($totalUpdates) ?></td>
                        <td><?= number_format($receiptConfirm) ?></td>
                        <td><?= number_format($joinedStatus) ?></td>
                        <td><?= number_format($willingToJoin) ?></td>
                        <td><?php if ($challenge > 0): ?><a href="<?= esc($challengeUrl) ?>"><?= number_format($challenge) ?></a><?php else: ?>0<?php endif; ?></td>
                        <td><?php if ($joinRemarks > 0): ?><a href="<?= esc($remarksUrl) ?>"><?= number_format($joinRemarks) ?></a><?php else: ?>0<?php endif; ?></td>
                        <td><?= $lastActivity ? esc(date('d M Y, H:i', strtotime((string) $lastActivity))) : '<span class="text-muted">&mdash;</span>' ?></td>
                    </tr>
                    <?php $duIndex++; ?>
                <?php endforeach; ?>
                <?php if ($districtUserActivityRows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td><?= number_format($districtUserActivityTotals['distinct_users']) ?></td>
                        <td><?= number_format($districtUserActivityTotals['distinct_candidates']) ?></td>
                        <td><?= number_format($districtUserActivityTotals['total_updates']) ?></td>
                        <td><?= number_format($districtUserActivityTotals['receipt_confirm_updates']) ?></td>
                        <td><?= number_format($districtUserActivityTotals['joined_status_updates']) ?></td>
                        <td><?= number_format($districtUserActivityTotals['willing_to_join_updates']) ?></td>
                        <td><?= number_format($districtUserActivityTotals['challenge_updates']) ?></td>
                        <td><?= number_format($districtUserActivityTotals['join_remarks_updates']) ?></td>
                        <td>&mdash;</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="data-meta mt-2 mb-0">
            <i class="bi bi-info-circle me-1"></i>
            <em>Note:</em> Click on the <strong>Distinct District Users</strong>, <strong>Challenge</strong> or <strong>Join Remarks</strong> values above to drill into the underlying records for that district. District users do not log phone calls in this system; their activity is captured as updates on candidate records via the District Candidate Data page.
        </p>
    </div>
</div>

</div><!-- /#tab-district -->
</div><!-- /.tab-content -->

<script>
(function () {
    const tabIds = ['#tab-overall', '#tab-selected', '#tab-shortlisted', '#tab-crm', '#tab-district'];

    function activateFromHash() {
        const hash = window.location.hash;
        if (hash && tabIds.includes(hash)) {
            const trigger = document.querySelector('[data-bs-target="' + hash + '"]');
            if (trigger) {
                bootstrap.Tab.getOrCreateInstance(trigger).show();
            }
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        activateFromHash();

        document.querySelectorAll('#reportTabs [data-bs-toggle="tab"]').forEach((btn) => {
            btn.addEventListener('shown.bs.tab', (event) => {
                const target = event.target.getAttribute('data-bs-target');
                if (target && window.history.replaceState) {
                    window.history.replaceState(null, '', window.location.pathname + window.location.search + target);
                }
            });
        });

        // Preserve active tab when applying / resetting filters via GET form.
        const filterForm = document.querySelector('form[method="get"]');
        if (filterForm) {
            filterForm.addEventListener('submit', () => {
                const active = document.querySelector('#reportTabs .nav-link.active');
                if (active) {
                    const target = active.getAttribute('data-bs-target');
                    if (target) {
                        filterForm.action = filterForm.action ? filterForm.action.split('#')[0] + target : window.location.pathname + target;
                    }
                }
            });
        }
    });

    window.addEventListener('hashchange', activateFromHash);
})();
</script>

<?php render_footer(); ?>
