<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/consolidated_report_helpers.php';
require_auth();

$filters = build_consolidated_filters();
$candidateDistrictOptions = fetch_consolidated_distinct_values('Candidate_District');
$jobFairOptions = fetch_consolidated_distinct_values('Job_Fair_No');
$categoryOptions = array_values(array_filter(
    fetch_consolidated_distinct_values('Category'),
    static fn(string $value): bool => strtolower($value) !== 'unknown'
));

$districtRows = fetch_future_date_district_report($filters);
$jobfairRows = fetch_future_date_jobfair_report($filters);

render_header('District wise Future Date Join Status', ['main_container_class' => 'container-fluid']);
render_page_header('District wise Future Date Join Status', [
    'icon' => 'bi-calendar-event',
    'subtitle' => 'Candidates whose Candidate Joined Status = "Future Date", split by Candidate Joining Future Date into Before Today and Today Onwards.',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
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
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Apply filters</button>
                <a href="district_future_date_joined_status_report.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">District wise &middot; Future Date Joining Status</h2>
        <p class="data-meta mb-3">
            <i class="bi bi-info-circle me-1"></i>
            For candidates whose <strong>Candidate Joined Status = Future Date</strong>, this table groups by Candidate District and splits each cohort (Selected; Shortlisted/On hold with Final = Selected) by whether the Candidate Joining Future Date falls <strong>Before Today</strong> or <strong>Today Onwards</strong>. The Future Dates Count includes rows with a blank future date too, so Before&nbsp;Today + Today&nbsp;Onwards may be less than the Count.
        </p>
        <?php render_future_date_joined_table($districtRows, $filters, 'district'); ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">Job Fair wise &middot; Future Date Joining Status</h2>
        <p class="data-meta mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Same metric set as the District wise table above but grouped by <strong>Job Fair No</strong>.
        </p>
        <?php render_future_date_joined_table($jobfairRows, $filters, 'job_fair'); ?>
    </div>
</div>

<?php render_footer(); ?>
