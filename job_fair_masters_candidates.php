<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

/**
 * The eight columns that together identify a single row in the Job Fair
 * Masters table. The displayed value "Unknown" maps back to NULL/empty in
 * the underlying job_fair_result data.
 */
const MASTERS_SIGNATURE_COLUMNS = [
    'aggregator' => 'Aggregator',
    'employer_code' => 'Employer_ID',
    'employer_name' => 'Employer_Name',
    'employer_spoc_name' => 'Employer_SPOC_Name',
    'employer_spoc_mobile' => 'Employer_SPOC_Mobile',
    'aggregator_spoc_name' => 'Aggregator_SPOC_Name',
    'aggregator_spoc_mobile' => 'Aggregator_SPOC_Mobile',
    'crm_member' => 'CRM_Member',
];

function masters_signature_from_request(array $source): array
{
    $signature = [];
    foreach (array_keys(MASTERS_SIGNATURE_COLUMNS) as $key) {
        $signature[$key] = trim((string) ($source[$key] ?? ''));
    }
    return $signature;
}

function masters_row_conditions(array $signature, array &$params): array
{
    $conditions = [];
    foreach (MASTERS_SIGNATURE_COLUMNS as $key => $column) {
        $value = (string) ($signature[$key] ?? '');
        if ($value === '' || $value === 'Unknown') {
            $conditions[] = "($column IS NULL OR TRIM($column) = '')";
        } else {
            $conditions[] = "TRIM(COALESCE($column, '')) = ?";
            $params[] = $value;
        }
    }
    return $conditions;
}

$signature = masters_signature_from_request($_GET);

$params = [];
$conditions = masters_row_conditions($signature, $params);
$whereClause = 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT id, Job_Id, Job_Title_Name, DWMS_ID, Candidate_Name, Mobile_Number,
        Selection_Status, Category, Candidate_Joined_Status, Shortlist_Candidate_Status,
        Job_Fair_No, Job_Fair_Date
    FROM job_fair_result
    $whereClause
    ORDER BY Job_Fair_Date DESC, Candidate_Name ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$signatureChips = [
    'Aggregator' => $signature['aggregator'],
    'Employer Code' => $signature['employer_code'],
    'Employer' => $signature['employer_name'],
    'Employer SPOC' => $signature['employer_spoc_name'] . ($signature['employer_spoc_mobile'] !== '' ? ' (' . $signature['employer_spoc_mobile'] . ')' : ''),
    'Aggregator SPOC' => $signature['aggregator_spoc_name'] . ($signature['aggregator_spoc_mobile'] !== '' ? ' (' . $signature['aggregator_spoc_mobile'] . ')' : ''),
    'CRM Member' => $signature['crm_member'],
];

$statusChipClass = static function (string $status): string {
    $key = strtolower(str_replace(' ', '', trim($status)));
    return match ($key) {
        'selected' => 'status-selected',
        'shortlisted' => 'status-shortlisted',
        'onhold' => 'status-onhold',
        'rejected' => 'status-rejected',
        'yes' => 'status-yes',
        'no' => 'status-no',
        'pending' => 'status-pending',
        default => 'status-neutral',
    };
};

render_header('Candidates · Job Fair Masters', ['main_container_class' => 'container-fluid']);
render_page_header('Candidates for Selected Mapping', [
    'icon' => 'bi-people',
    'subtitle' => 'All candidate records matching this Employer / SPOC / CRM combination.',
    'actions' => '<a class="btn btn-light" href="/job_fair_masters.php"><i class="bi bi-arrow-left me-1"></i>Back to Masters</a>',
]);
?>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap align-items-center gap-2">
        <span class="form-label mb-0 me-1">Mapping:</span>
        <?php foreach ($signatureChips as $label => $value): ?>
            <span class="status-chip status-neutral"><strong><?= esc($label) ?>:</strong>&nbsp;<?= esc($value !== '' ? $value : 'Unknown') ?></span>
        <?php endforeach; ?>
        <span class="status-chip status-info ms-auto"><?= number_format(count($rows)) ?> records</span>
    </div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Job ID</th>
                    <th>Job Title</th>
                    <th>DWMS ID</th>
                    <th>Candidate Name</th>
                    <th>Mobile</th>
                    <th>Job Fair No</th>
                    <th>Selection Status</th>
                    <th>Category</th>
                    <th>Candidate Joined Status</th>
                    <th>Final (Shortlist) Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="12"><div class="empty-state"><i class="bi bi-inbox"></i>No candidate records match this mapping.</div></td></tr>
                <?php endif; ?>
                <?php $idx = 1; foreach ($rows as $row): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><?= esc((string) ($row['Job_Id'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Job_Title_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['DWMS_ID'] ?? '')) ?></td>
                        <td class="fw-semibold"><?= esc((string) ($row['Candidate_Name'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Mobile_Number'] ?? '')) ?></td>
                        <td><?= esc((string) ($row['Job_Fair_No'] ?? '')) ?></td>
                        <td><?php $s = (string) ($row['Selection_Status'] ?? ''); ?><?php if ($s !== ''): ?><span class="status-chip <?= esc($statusChipClass($s)) ?>"><?= esc($s) ?></span><?php endif; ?></td>
                        <td><?= esc((string) ($row['Category'] ?? '')) ?></td>
                        <td><?php $j = (string) ($row['Candidate_Joined_Status'] ?? ''); ?><?php if ($j !== ''): ?><span class="status-chip <?= esc($statusChipClass($j)) ?>"><?= esc($j) ?></span><?php endif; ?></td>
                        <td><?php $f = (string) ($row['Shortlist_Candidate_Status'] ?? ''); ?><?php if ($f !== ''): ?><span class="status-chip <?= esc($statusChipClass($f)) ?>"><?= esc($f) ?></span><?php endif; ?></td>
                        <td><a class="btn btn-sm btn-outline-primary" href="/manage_candidate.php?candidate_id=<?= (int) $row['id'] ?>" target="_blank" rel="noopener noreferrer"><i class="bi bi-pencil-square"></i> Manage</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
