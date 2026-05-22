<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

const EXCEPTION_FILTER_COLUMNS = [
    'aggregator' => 'Aggregator',
    'job_fair_no' => 'Job_Fair_No',
    'selection_status' => 'Selection_Status',
    'category' => 'Category',
];

const EXCEPTION_METRIC_LABELS = [
    'total_count' => 'Total',
    'offer_generated_yes' => 'Offer Letter Generated: Yes',
    'offer_generated_no' => 'Offer Letter Generated: No',
    'offer_generated_pending' => 'Offer Letter Generated: Pending',
    'offer_link_with' => 'Link to Offer Letter: With Link',
    'offer_link_blank' => 'Link to Offer Letter: Blank',
    'link_verified_yes' => 'Link Verified: Yes',
    'link_verified_no' => 'Link Verified: No',
    'link_verified_pending' => 'Link Verified: Pending',
    'receipt_confirmed_yes' => 'Offer Letter Receipt Confirmed: Yes',
    'receipt_confirmed_no' => 'Offer Letter Receipt Confirmed: No',
    'receipt_confirmed_pending' => 'Offer Letter Receipt Confirmed: Pending',
    'candidate_joined_yes' => 'Candidate Joined: Yes',
    'candidate_joined_no' => 'Candidate Joined: No',
    'candidate_joined_pending' => 'Candidate Joined: Pending',
    'shortlist_candidate_shortlisted' => 'Shortlist Candidate Status: Shortlisted',
    'shortlist_candidate_selected' => 'Shortlist Candidate Status: Selected',
    'shortlist_candidate_rejected' => 'Shortlist Candidate Status: Rejected',
    'shortlist_candidate_onhold' => 'Shortlist Candidate Status: Onhold',
    'shortlist_candidate_not_interested' => 'Shortlist Candidate Status: Candidate Not Interested',
];

function build_filters(): array
{
    $filters = [];
    foreach (array_keys(EXCEPTION_FILTER_COLUMNS) as $key) {
        $value = $_GET[$key] ?? '';

        if (in_array($key, ['aggregator', 'job_fair_no', 'selection_status', 'category'], true)) {
            if (!is_array($value)) {
                $value = $value === '' ? [] : [$value];
            }

            $filters[$key] = array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $value), static fn(string $item): bool => $item !== ''));
            continue;
        }

        $filters[$key] = trim((string) $value);
    }

    return $filters;
}

function apply_metric_clause(string $metric, array &$conditions): void
{
    switch ($metric) {
        case 'offer_generated_yes':
            $conditions[] = "LOWER(TRIM(Offer_Letter_Generated)) = 'yes'";
            break;
        case 'offer_generated_no':
            $conditions[] = "LOWER(TRIM(Offer_Letter_Generated)) = 'no'";
            break;
        case 'offer_generated_pending':
            $conditions[] = "(TRIM(COALESCE(Offer_Letter_Generated, '')) = '' OR LOWER(TRIM(Offer_Letter_Generated)) = 'pending')";
            break;
        case 'offer_link_with':
            $conditions[] = "TRIM(COALESCE(Link_to_Offer_letter, '')) <> ''";
            break;
        case 'offer_link_blank':
            $conditions[] = "TRIM(COALESCE(Link_to_Offer_letter, '')) = ''";
            break;
        case 'link_verified_yes':
            $conditions[] = "LOWER(TRIM(Link_to_Offer_letter_verified)) = 'yes'";
            break;
        case 'link_verified_no':
            $conditions[] = "LOWER(TRIM(Link_to_Offer_letter_verified)) = 'no'";
            break;
        case 'link_verified_pending':
            $conditions[] = "(TRIM(COALESCE(Link_to_Offer_letter_verified, '')) = '' OR LOWER(TRIM(Link_to_Offer_letter_verified)) = 'pending')";
            break;
        case 'receipt_confirmed_yes':
            $conditions[] = "LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'yes'";
            break;
        case 'receipt_confirmed_no':
            $conditions[] = "LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'no'";
            break;
        case 'receipt_confirmed_pending':
            $conditions[] = "(TRIM(COALESCE(Confirm_Offer_Letter_Receipt_by_Candidate, '')) = '' OR LOWER(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate)) = 'pending')";
            break;
        case 'candidate_joined_yes':
            $conditions[] = "LOWER(TRIM(Candidate_Joined_Status)) = 'yes'";
            break;
        case 'candidate_joined_no':
            $conditions[] = "LOWER(TRIM(Candidate_Joined_Status)) = 'no'";
            break;
        case 'candidate_joined_pending':
            $conditions[] = "(TRIM(COALESCE(Candidate_Joined_Status, '')) = '' OR LOWER(TRIM(Candidate_Joined_Status)) = 'pending')";
            break;
        case 'shortlist_candidate_shortlisted':
            $conditions[] = "LOWER(TRIM(Shortlist_Candidate_Status)) = 'shortlisted'";
            break;
        case 'shortlist_candidate_selected':
            $conditions[] = "LOWER(TRIM(Shortlist_Candidate_Status)) = 'selected'";
            break;
        case 'shortlist_candidate_rejected':
            $conditions[] = "LOWER(TRIM(Shortlist_Candidate_Status)) = 'rejected'";
            break;
        case 'shortlist_candidate_onhold':
            $conditions[] = "LOWER(TRIM(Shortlist_Candidate_Status)) = 'onhold'";
            break;
        case 'shortlist_candidate_not_interested':
            $conditions[] = "LOWER(TRIM(Shortlist_Candidate_Status)) = 'candidate not interested'";
            break;
        case 'total_count':
        default:
            break;
    }
}

function fetch_exception_candidates(array $filters, string $jobFairRow, string $metric): array
{
    $params = [];
    $conditions = [];

    foreach (EXCEPTION_FILTER_COLUMNS as $filterKey => $column) {
        $value = $filters[$filterKey];
        if (is_array($value)) {
            if ($value === []) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($value), '?'));
            $conditions[] = "$column IN ($placeholders)";
            foreach ($value as $item) {
                $params[] = $item;
            }

            continue;
        }

        if ($value === '') {
            continue;
        }

        $conditions[] = "$column = ?";
        $params[] = $value;
    }

    if ($jobFairRow === 'Unknown') {
        $conditions[] = "(TRIM(COALESCE(Job_Fair_No, '')) = '')";
    } else {
        $conditions[] = "COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') = ?";
        $params[] = $jobFairRow;
    }

    apply_metric_clause($metric, $conditions);

    $whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

    $sql = "SELECT
            COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            DATE_FORMAT(Job_Fair_Date, '%Y-%m-%d') AS job_fair_date,
            COALESCE(TRIM(Aggregator), '') AS aggregator,
            COALESCE(TRIM(Employer_ID), '') AS employer_id,
            COALESCE(TRIM(Employer_Name), '') AS employer_name,
            COALESCE(TRIM(Job_Id), '') AS job_id,
            COALESCE(TRIM(Job_Title_Name), '') AS job_title,
            COALESCE(TRIM(DWMS_ID), '') AS dwms_id,
            COALESCE(TRIM(Candidate_Name), '') AS candidate_name,
            COALESCE(TRIM(Category), '') AS category,
            COALESCE(TRIM(Selection_Status), '') AS selection_status,
            COALESCE(TRIM(Shortlist_Candidate_Status), '') AS shortlist_candidate_status,
            COALESCE(TRIM(Shortlist_Current_Call_Status), '') AS shortlist_current_call_status,
            COALESCE(TRIM(Offer_Letter_Generated), '') AS offer_letter_generated,
            COALESCE(TRIM(Link_to_Offer_letter_verified), '') AS link_to_offer_letter_verified,
            COALESCE(TRIM(Confirm_Offer_Letter_Receipt_by_Candidate), '') AS confirm_offer_letter_receipt_by_candidate,
            COALESCE(TRIM(Candidate_Joined_Status), '') AS candidate_joined_status
        FROM job_fair_result
        $whereClause
        ORDER BY Job_Fair_Date DESC, Employer_Name ASC, Candidate_Name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}


function output_exception_candidates_csv(array $rows, string $jobFairRow, string $metricLabel): void
{
    $safeMetric = preg_replace('/[^a-z0-9]+/i', '_', strtolower($metricLabel));
    $safeMetric = trim((string) $safeMetric, '_');
    if ($safeMetric === '') {
        $safeMetric = 'metric';
    }

    $safeJobFair = preg_replace('/[^a-z0-9]+/i', '_', strtolower($jobFairRow));
    $safeJobFair = trim((string) $safeJobFair, '_');
    if ($safeJobFair === '') {
        $safeJobFair = 'unknown';
    }

    $filename = sprintf('job_fair_exception_candidates_%s_%s.csv', $safeJobFair, $safeMetric);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        return;
    }

    fputcsv($output, [
        'Job Fair No',
        'Job Fair Date',
        'Aggregator',
        'Employer Id',
        'Employer Name',
        'Job Id',
        'Job Title',
        'DWMS Id',
        'Candidate Name',
        'Category',
        'Selection Status',
        'Shortlist Candidate Status',
        'Shortlist Current Call Status',
        'Offer Letter Generated',
        'Link to Offer Letter Verified',
        'Confirm Offer Letter Receipt by Candidate',
        'Candidate Joined Status',
    ]);

    foreach ($rows as $row) {
        fputcsv($output, [
            (string) $row['job_fair_no'],
            (string) $row['job_fair_date'],
            (string) $row['aggregator'],
            (string) $row['employer_id'],
            (string) $row['employer_name'],
            (string) $row['job_id'],
            (string) $row['job_title'],
            (string) $row['dwms_id'],
            (string) $row['candidate_name'],
            (string) $row['category'],
            (string) $row['selection_status'],
            (string) $row['shortlist_candidate_status'],
            (string) $row['shortlist_current_call_status'],
            (string) $row['offer_letter_generated'],
            (string) $row['link_to_offer_letter_verified'],
            (string) $row['confirm_offer_letter_receipt_by_candidate'],
            (string) $row['candidate_joined_status'],
        ]);
    }

    fclose($output);
}

$filters = build_filters();
$jobFairRow = trim((string) ($_GET['job_fair_row'] ?? ''));
$metric = trim((string) ($_GET['metric'] ?? 'total_count'));
$downloadCsv = (($_GET['download'] ?? '') === 'csv');

if ($jobFairRow === '') {
    $jobFairRow = 'Unknown';
}

if (!array_key_exists($metric, EXCEPTION_METRIC_LABELS)) {
    $metric = 'total_count';
}

$rows = fetch_exception_candidates($filters, $jobFairRow, $metric);
$metricLabel = EXCEPTION_METRIC_LABELS[$metric];

if ($downloadCsv) {
    output_exception_candidates_csv($rows, $jobFairRow, $metricLabel);
    exit;
}

render_header('Exception Candidates', ['show_navigation' => false, 'main_container_class' => 'container-fluid']);
?>
<?php render_page_header('Exception Candidates', [
    'icon' => 'bi-exclamation-triangle',
    'subtitle' => 'Job Fair No ' . $jobFairRow . ' · Metric: ' . $metricLabel,
    'actions' => '<a class="btn btn-primary" href="' . esc($_SERVER['REQUEST_URI'] . (str_contains($_SERVER['REQUEST_URI'], '?') ? '&' : '?') . 'download=csv') . '"><i class="bi bi-download me-1"></i>Download CSV</a>',
]); ?>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th>Job fair No</th>
                    <th>Job fair date</th>
                    <th>Aggregator</th>
                    <th>Employer Id</th>
                    <th>Employer Name</th>
                    <th>Job Id</th>
                    <th>Job title</th>
                    <th>DWMS Id</th>
                    <th>Name of Candidate</th>
                    <th>Category</th>
                    <th>Selection_status</th>
                    <th>Shortlist_candidate_status</th>
                    <th>Shortlist_current_call_status</th>
                    <th>Offer_letter_Generated</th>
                    <th>Link_to_offer_letter_verified</th>
                    <th>confirm_offer_letter_receipt_by_candidate</th>
                    <th>Candidate_joined_status</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="17" class="text-center text-muted">No candidates found.</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc((string) $row['job_fair_no']) ?></td>
                        <td><?= esc((string) $row['job_fair_date']) ?></td>
                        <td><?= esc((string) $row['aggregator']) ?></td>
                        <td><?= esc((string) $row['employer_id']) ?></td>
                        <td><?= esc((string) $row['employer_name']) ?></td>
                        <td><?= esc((string) $row['job_id']) ?></td>
                        <td><?= esc((string) $row['job_title']) ?></td>
                        <td><?= esc((string) $row['dwms_id']) ?></td>
                        <td><?= esc((string) $row['candidate_name']) ?></td>
                        <td><?= esc((string) $row['category']) ?></td>
                        <td><?= esc((string) $row['selection_status']) ?></td>
                        <td><?= esc((string) $row['shortlist_candidate_status']) ?></td>
                        <td><?= esc((string) $row['shortlist_current_call_status']) ?></td>
                        <td><?= esc((string) $row['offer_letter_generated']) ?></td>
                        <td><?= esc((string) $row['link_to_offer_letter_verified']) ?></td>
                        <td><?= esc((string) $row['confirm_offer_letter_receipt_by_candidate']) ?></td>
                        <td><?= esc((string) $row['candidate_joined_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php render_footer(false); ?>
