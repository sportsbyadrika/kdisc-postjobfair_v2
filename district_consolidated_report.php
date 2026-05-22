<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/consolidated_report_helpers.php';
require_auth();

function consolidated_metric_url(string $section, string $metric, ?string $jobFairRow, array $filters): string
{
    $params = array_filter([
        'section' => $section,
        'metric' => $metric,
        'job_fair_row' => $jobFairRow,
        'candidate_district' => $filters['candidate_district'],
        'job_fair' => $filters['job_fair'],
        'category' => $filters['category'],
        'selection_status' => $filters['selection_status'],
    ], static fn($value): bool => $value !== null && $value !== '');

    return 'consolidated_report_candidates.php?' . http_build_query($params);
}

function render_metric_link(int $value, string $section, string $metric, ?string $jobFairRow, array $filters): string
{
    $url = consolidated_metric_url($section, $metric, $jobFairRow, $filters);

    return sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer">%d</a>',
        esc($url),
        $value
    );
}

$filters = build_consolidated_filters();
$candidateDistrictOptions = fetch_consolidated_distinct_values('Candidate_District');
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
    'shortlist_status_rejected',
    'shortlist_status_onhold',
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

render_header('District Consolidated report', ['main_container_class' => 'container-fluid']);
?>
<h1 class="h3 mb-4">District Consolidated report</h1>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="candidate_district" class="form-label">Candidate District</label>
                <select class="form-select" id="candidate_district" name="candidate_district">
                    <option value="">All Candidate Districts</option>
                    <?php foreach ($candidateDistrictOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['candidate_district'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
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
                <a href="district_consolidated_report.php" class="btn btn-outline-secondary">Reset</a>
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
                    <th colspan="3" class="text-center">Shortlisted Conversion</th>
                    <th colspan="2" class="text-center">Offer Letter Generated</th>
                    <th colspan="2" class="text-center">Offer Letter Softcopy</th>
                    <th colspan="3" class="text-center">Softcopy Verified</th>
                    <th colspan="3" class="text-center">Offer Letter Receipt Confirmed</th>
                    <th colspan="3" class="text-center">Candidate Joined</th>
                </tr>
                <tr>
                    <th>Selected</th>
                    <th>Rejected</th>
                    <th>Pending</th>
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
                    <tr><td colspan="18" class="text-center text-muted">No data available.</td></tr>
                <?php endif; ?>
                <?php foreach ($shortlistedRows as $row): ?>
                    <tr>
                        <td><?= esc($row['job_fair_no']) ?></td>
                        <td><?= render_metric_link((int) $row['total_shortlisted_onhold_candidate'], 'shortlisted', 'total_shortlisted_onhold_candidate', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_selected'], 'shortlisted', 'shortlist_status_selected', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_rejected'], 'shortlisted', 'shortlist_status_rejected', (string) $row['job_fair_no'], $filters) ?></td>
                        <td><?= render_metric_link((int) $row['shortlist_status_onhold'], 'shortlisted', 'shortlist_status_onhold', (string) $row['job_fair_no'], $filters) ?></td>
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
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_rejected'], 'shortlisted', 'shortlist_status_rejected', null, $filters) ?></td>
                        <td><?= render_metric_link($shortlistedTotals['shortlist_status_onhold'], 'shortlisted', 'shortlist_status_onhold', null, $filters) ?></td>
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

<?php render_footer(); ?>
