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

$rows = fetch_shortlisted_district_jobstation_joined_report($filters);

render_header('District wise Candidate Joined Status', ['main_container_class' => 'container-fluid']);
render_page_header('District wise Candidate Joined Status', [
    'icon' => 'bi-geo-alt',
    'subtitle' => 'District-wise count of distinct job stations and local bodies affected, plus joining outcomes for the Selected and Shortlist Final Selected cohorts.',
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
                <a href="district_candidate_joined_status_report.php" class="btn btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5">District wise &middot; Job Station and Joined Status</h2>
        <p class="data-meta mb-3">
            <i class="bi bi-info-circle me-1"></i>
            District-wise count of distinct job stations and local bodies affected, plus joining outcomes split into two cohorts &mdash; directly Selected candidates (Selection Status = Selected) and Shortlisted/On hold candidates whose Final Status = Selected. The combined Total Selected and Shortlisted group sums the two cohorts per joined status.
        </p>
        <?php render_district_jobstation_joined_table($rows, $filters); ?>
    </div>
</div>

<?php render_footer(); ?>
