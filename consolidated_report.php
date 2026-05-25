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
        '<a href="%s" target="_blank" rel="noopener noreferrer">%d</a>',
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
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <a href="consolidated_report.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

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
                    <th colspan="3" class="text-center">Candidate Joined</th>
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
                </tr>
                </thead>
                <tbody>
                <?php if ($shortlistedRows === []): ?>
                    <tr><td colspan="22" class="text-center text-muted">No data available.</td></tr>
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
                    <tr>
                        <td><?= $duIndex ?></td>
                        <td class="fw-semibold"><?= esc($duRow['district']) ?></td>
                        <td><?= number_format($distinctUsers) ?></td>
                        <td><?= number_format($distinctCandidates) ?></td>
                        <td><?= number_format($totalUpdates) ?></td>
                        <td><?= number_format($receiptConfirm) ?></td>
                        <td><?= number_format($joinedStatus) ?></td>
                        <td><?= number_format($willingToJoin) ?></td>
                        <td><?= number_format($challenge) ?></td>
                        <td><?= number_format($joinRemarks) ?></td>
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
            <em>Note:</em> District users do not log phone calls in this system; their activity is captured as updates on candidate records via the District Candidate Data page. Distinct user totals across districts can exceed the platform user count if a user has worked on candidates in multiple districts.
        </p>
    </div>
</div>

<?php render_footer(); ?>
