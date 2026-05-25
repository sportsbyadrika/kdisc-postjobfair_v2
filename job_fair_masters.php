<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

function fetch_master_distinct_values(string $column): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM($column), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";

    return array_map(static fn(array $row): string => (string) $row['value'], db()->query($sql)->fetchAll());
}

function build_master_filters(): array
{
    return [
        'aggregator' => trim((string) ($_GET['aggregator'] ?? '')),
        'employer' => trim((string) ($_GET['employer'] ?? '')),
        'crm_member' => trim((string) ($_GET['crm_member'] ?? '')),
    ];
}

function fetch_master_rows(array $filters): array
{
    $conditions = [];
    $params = [];

    if ($filters['aggregator'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') = ?";
        $params[] = $filters['aggregator'];
    }

    if ($filters['employer'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown') = ?";
        $params[] = $filters['employer'];
    }

    if ($filters['crm_member'] !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(CRM_Member), ''), 'Unknown') = ?";
        $params[] = $filters['crm_member'];
    }

    $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') AS aggregator,
            COALESCE(NULLIF(TRIM(Employer_ID), ''), 'Unknown') AS employer_code,
            COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown') AS employer_name,
            COALESCE(NULLIF(TRIM(Employer_SPOC_Name), ''), 'Unknown') AS employer_spoc_name,
            COALESCE(NULLIF(TRIM(Employer_SPOC_Mobile), ''), 'Unknown') AS employer_spoc_mobile,
            COALESCE(NULLIF(TRIM(Aggregator_SPOC_Name), ''), 'Unknown') AS aggregator_spoc_name,
            COALESCE(NULLIF(TRIM(Aggregator_SPOC_Mobile), ''), 'Unknown') AS aggregator_spoc_mobile,
            COALESCE(NULLIF(TRIM(CRM_Member), ''), 'Unknown') AS crm_member
        FROM job_fair_result
        $whereClause
        GROUP BY
            aggregator,
            employer_code,
            employer_name,
            employer_spoc_name,
            employer_spoc_mobile,
            aggregator_spoc_name,
            aggregator_spoc_mobile,
            crm_member
        ORDER BY aggregator ASC, employer_name ASC, employer_spoc_name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

$filters = build_master_filters();

if (($_GET['download'] ?? '') === 'csv') {
    $rows = fetch_master_rows($filters);
    $filename = 'job_fair_masters_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel renders non-ASCII correctly
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Aggregator',
        'Employer Code',
        'Employer Name',
        'Employer SPOC Name',
        'Employer SPOC Mobile',
        'Aggregator SPOC Name',
        'Aggregator SPOC Mobile',
        'CRM Member',
    ]);
    foreach ($rows as $row) {
        fputcsv($out, [
            (string) $row['aggregator'],
            (string) $row['employer_code'],
            (string) $row['employer_name'],
            (string) $row['employer_spoc_name'],
            (string) $row['employer_spoc_mobile'],
            (string) $row['aggregator_spoc_name'],
            (string) $row['aggregator_spoc_mobile'],
            (string) $row['crm_member'],
        ]);
    }
    fclose($out);
    exit;
}

$aggregatorOptions = fetch_master_distinct_values('Aggregator');
$employerOptions = fetch_master_distinct_values('Employer_Name');
$crmMemberOptions = fetch_master_distinct_values('CRM_Member');
$rows = fetch_master_rows($filters);

$downloadUrl = '/job_fair_masters.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'aggregator' => $filters['aggregator'],
    'employer' => $filters['employer'],
    'crm_member' => $filters['crm_member'],
], static fn($value): bool => $value !== ''));

render_header('Job fair masters');
render_page_header('Job Fair Masters', [
    'icon' => 'bi-sliders',
    'subtitle' => 'Employer and SPOC mapping derived from job fair result data.',
    'actions' => '<a class="btn btn-primary" href="' . esc($downloadUrl) . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]);
?>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3"><i class="bi bi-funnel text-primary me-1"></i>Filters</h2>
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="aggregator" class="form-label">Aggregator</label>
                <select class="form-select" id="aggregator" name="aggregator">
                    <option value="">All Aggregators</option>
                    <?php foreach ($aggregatorOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['aggregator'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="employer" class="form-label">Employer</label>
                <select class="form-select" id="employer" name="employer">
                    <option value="">All Employers</option>
                    <?php foreach ($employerOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['employer'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="crm_member" class="form-label">CRM Member</label>
                <select class="form-select" id="crm_member" name="crm_member">
                    <option value="">All CRM Members</option>
                    <?php foreach ($crmMemberOptions as $option): ?>
                        <option value="<?= esc($option) ?>" <?= $filters['crm_member'] === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a href="job_fair_masters.php" class="btn btn-light">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-3 text-primary me-1"></i>Employer and SPOC Mapping</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> rows</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
            <tr>
                <th>Aggregator</th>
                <th>Employer Code</th>
                <th>Employer Name</th>
                <th>Employer SPOC Name</th>
                <th>Employer SPOC Mobile</th>
                <th>Aggregator SPOC Name</th>
                <th>Aggregator SPOC Mobile</th>
                <th>CRM Member</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No records found for selected filters.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="fw-semibold"><?= esc((string) $row['aggregator']) ?></td>
                    <td><?= esc((string) $row['employer_code']) ?></td>
                    <td><?= esc((string) $row['employer_name']) ?></td>
                    <td><?= esc((string) $row['employer_spoc_name']) ?></td>
                    <td><?= esc((string) $row['employer_spoc_mobile']) ?></td>
                    <td><?= esc((string) $row['aggregator_spoc_name']) ?></td>
                    <td><?= esc((string) $row['aggregator_spoc_mobile']) ?></td>
                    <td><?= esc((string) $row['crm_member']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer();
