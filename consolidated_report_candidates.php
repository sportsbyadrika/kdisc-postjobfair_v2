<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/consolidated_report_helpers.php';
require_auth();

function consolidated_detail_request_uri_with_download(): string
{
    $params = $_GET;
    $params['download'] = 'csv';

    return 'consolidated_report_candidates.php?' . http_build_query($params);
}

function output_consolidated_candidates_csv(array $rows, string $sectionLabel, string $metricLabel, ?string $groupRow, string $groupLabel, array $columns): void
{
    $safeSection = preg_replace('/[^a-z0-9]+/i', '_', strtolower($sectionLabel));
    $safeMetric = preg_replace('/[^a-z0-9]+/i', '_', strtolower($metricLabel));
    $safeGroup = preg_replace('/[^a-z0-9]+/i', '_', strtolower($groupRow ?? ('all_' . $groupLabel)));

    $filename = sprintf(
        'consolidated_report_candidates_%s_%s_%s.csv',
        trim((string) $safeSection, '_') ?: 'section',
        trim((string) $safeMetric, '_') ?: 'metric',
        trim((string) $safeGroup, '_') ?: 'all'
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        return;
    }

    fputcsv($output, array_keys($columns));

    foreach ($rows as $row) {
        $csvRow = [];
        foreach ($columns as $key) {
            $csvRow[] = (string) ($row[$key] ?? '');
        }

        fputcsv($output, $csvRow);
    }

    fclose($output);
}

$filters = build_consolidated_filters();
$section = trim((string) ($_GET['section'] ?? 'selected'));
$metric = trim((string) ($_GET['metric'] ?? 'total_selected_candidate'));
$jobFairRow = trim((string) ($_GET['job_fair_row'] ?? ''));
$groupRow = trim((string) ($_GET['group_row'] ?? ''));
$groupField = trim((string) ($_GET['group_field'] ?? 'job_fair_no'));
$downloadCsv = (($_GET['download'] ?? '') === 'csv');

if (!array_key_exists($section, CONSOLIDATED_SECTION_LABELS)) {
    $section = 'selected';
}

$sectionMetrics = CONSOLIDATED_METRIC_LABELS[$section];
$defaultMetric = 'total_shortlisted_onhold_candidate';
if ($section === 'selected') {
    $defaultMetric = 'total_selected_candidate';
}
if (!array_key_exists($metric, $sectionMetrics)) {
    $metric = $defaultMetric;
}

$validGroupField = $groupField === 'candidate_job_station' ? 'candidate_job_station' : 'job_fair_no';
$groupFilterValue = $groupRow !== '' ? $groupRow : $jobFairRow;
$groupFilter = $groupFilterValue !== '' ? $groupFilterValue : null;
$groupLabel = $validGroupField === 'candidate_job_station' ? 'candidate job stations' : 'job fairs';
$groupTitle = $validGroupField === 'candidate_job_station' ? 'Candidate Job Station' : 'Job Fair No';

$rows = fetch_consolidated_detail_rows($section, $metric, $filters, $groupFilter, $validGroupField);
$sectionLabel = CONSOLIDATED_SECTION_LABELS[$section];
$metricLabel = $sectionMetrics[$metric];
$columns = consolidated_detail_columns($section);

if ($downloadCsv) {
    output_consolidated_candidates_csv($rows, $sectionLabel, $metricLabel, $groupFilter, $groupLabel, $columns);
    exit;
}

render_header('Consolidated Report Candidates', ['show_navigation' => false, 'main_container_class' => 'container-fluid']);
?>
<h1 class="h3 mb-3">Consolidated Report Candidates</h1>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <p class="text-muted mb-1"><strong>Section:</strong> <?= esc($sectionLabel) ?></p>
        <p class="text-muted mb-1"><strong>Metric:</strong> <?= esc($metricLabel) ?></p>
        <p class="text-muted mb-0"><strong><?= esc($groupTitle) ?>:</strong> <?= esc($groupFilter ?? ('All filtered ' . $groupLabel)) ?></p>
    </div>
    <a class="btn btn-success" href="<?= esc(consolidated_detail_request_uri_with_download()) ?>">Download CSV</a>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <?php foreach (array_keys($columns) as $columnLabel): ?>
                        <th><?= esc($columnLabel) ?></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="<?= count($columns) ?>" class="text-center text-muted">No candidates found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <?php foreach ($columns as $key): ?>
                            <td><?= esc((string) ($row[$key] ?? '')) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php render_footer(false); ?>
