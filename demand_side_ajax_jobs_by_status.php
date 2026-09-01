<?php
/**
 * Small AJAX endpoint used by the admin / EDMS dashboard's Verification
 * Status cards. Returns an HTML fragment listing the jobs in a given
 * in-app verification bucket (Valid / Invalid / Corrected / '' meaning
 * Not Yet Started). Callers set the fragment into a Bootstrap modal
 * body on `show.bs.modal`.
 *
 * Query params:
 *   verification  — one of Valid / Invalid / Corrected / NotYetStarted
 *   limit         — optional row cap (default 500, hard max 5000)
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_auth();
$viewer = current_user() ?? [];
if (!is_admin($viewer) && !is_edms($viewer)) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-0">Access denied.</div>';
    exit;
}

$verification = trim((string) ($_GET['verification'] ?? ''));
$limit        = (int) ($_GET['limit'] ?? 500);
$limit = max(1, min($limit, 5000));

$esc = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$where  = '1=0';
$params = [];
$title  = '';
switch (strtolower($verification)) {
    case 'valid':
        $where = "j.status = 'Valid'";
        $title = 'Valid jobs';
        break;
    case 'invalid':
        $where = "j.status = 'Invalid'";
        $title = 'Invalid jobs';
        break;
    case 'corrected':
        $where = "j.status = 'Corrected'";
        $title = 'Corrected jobs';
        break;
    case 'notyetstarted':
    case 'not_yet_started':
    case 'notstarted':
    case 'none':
        $where = "(j.status IS NULL OR TRIM(j.status) = '')";
        $title = 'Not Yet Started jobs';
        break;
    default:
        http_response_code(400);
        echo '<div class="alert alert-danger m-0">Unknown verification bucket.</div>';
        exit;
}

$countStmt = db()->prepare("SELECT COUNT(*) FROM demand_employer_jobs j WHERE $where");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$sql = "SELECT e.employer_id, e.employer_name,
        j.job_id, j.jobtitle, j.open_positions
    FROM demand_employer_jobs j
    LEFT JOIN demand_employers e ON e.employer_id = j.emp_id
    WHERE $where
    ORDER BY e.employer_id ASC, j.job_id ASC
    LIMIT $limit";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalVacancies = 0;
foreach ($rows as $r) { $totalVacancies += (int) ($r['open_positions'] ?? 0); }
?>
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <div class="text-muted small">
        Showing <strong><?= number_format(count($rows)) ?></strong>
        <?= $total > count($rows) ? 'of <strong>' . number_format($total) . '</strong>' : '' ?>
        row(s) &middot; <strong><?= number_format($totalVacancies) ?></strong> vacancies in the visible rows
    </div>
    <?php if ($total > count($rows)): ?>
        <div class="text-muted small"><i class="bi bi-info-circle me-1"></i>Row cap is <?= number_format($limit) ?>. Use "Download Jobs Status CSV" on the Statistics page for the complete list.</div>
    <?php endif; ?>
</div>
<div class="table-responsive" style="max-height:60vh; overflow-y:auto;">
    <table class="table table-hover align-middle table-sm mb-0">
        <thead class="table-light" style="position:sticky; top:0;">
            <tr>
                <th>Sl No</th>
                <th>Employer ID</th>
                <th>Employer Name</th>
                <th>Job ID</th>
                <th>Job Title</th>
                <th class="text-end">Number of Vacancies</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6"><div class="text-muted text-center py-3"><i class="bi bi-inbox me-1"></i>No jobs in this bucket.</div></td></tr>
            <?php endif; ?>
            <?php $i = 1; foreach ($rows as $r): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= $esc((string) ($r['employer_id'] ?? '')) ?></td>
                    <td class="fw-semibold"><?= $esc((string) ($r['employer_name'] ?? '')) ?></td>
                    <td><?= $esc((string) ($r['job_id'] ?? '')) ?></td>
                    <td><?= $esc((string) ($r['jobtitle'] ?? '')) ?></td>
                    <td class="text-end fw-bold"><?= number_format((int) ($r['open_positions'] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
