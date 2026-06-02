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

function consolidated_detail_back_to_report_uri(string $section): string
{
    $drillKeys = ['section', 'metric', 'job_fair_row', 'group_row', 'group_field', 'download'];
    $params = $_GET;
    foreach ($drillKeys as $key) {
        unset($params[$key]);
    }

    $tabBySection = [
        'selected' => 'tab-selected',
        'shortlisted' => 'tab-shortlisted',
        'shortlisted_rounds_pending' => 'tab-shortlisted',
        'shortlisted_rounds_selected' => 'tab-shortlisted',
        'crm_call_count_pending' => 'tab-crm',
        'crm_call_count_joined_status' => 'tab-crm',
    ];
    $hash = '#' . ($tabBySection[$section] ?? 'tab-overall');

    $query = http_build_query($params);

    return 'consolidated_report.php' . ($query !== '' ? '?' . $query : '') . $hash;
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
<?php render_page_header('Consolidated Report Candidates', [
    'icon' => 'bi-people',
    'subtitle' => 'Candidate-level breakdown for the selected consolidated metric.',
    'actions' => '<a class="btn btn-light me-2" href="' . esc(consolidated_detail_back_to_report_uri($section)) . '"><i class="bi bi-arrow-left me-1"></i>Back to Report</a>'
        . '<a class="btn btn-primary" href="' . esc(consolidated_detail_request_uri_with_download()) . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]); ?>
<div class="card mb-4"><div class="card-body d-flex flex-wrap gap-4">
    <div><p class="form-label mb-1">Section</p><span class="status-chip status-neutral"><?= esc($sectionLabel) ?></span></div>
    <div><p class="form-label mb-1">Metric</p><span class="status-chip status-info"><?= esc($metricLabel) ?></span></div>
    <div><p class="form-label mb-1"><?= esc($groupTitle) ?></p><span class="status-chip status-neutral"><?= esc($groupFilter ?? ('All filtered ' . $groupLabel)) ?></span></div>
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
