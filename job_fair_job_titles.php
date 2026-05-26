<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

/**
 * Hierarchy: Aggregator -> Employer (Employer_Name + Employer_ID) ->
 * Job Title (Job_Title_Name + Job_Id) -> Job Fair (Job_Fair_No).
 * Each level rolls up Candidates, Selected, Shortlisted/On hold, Total
 * Selected (direct Selected + Shortlist Final Selected) and Total Joined.
 */

function fetch_job_titles_distinct(string $column): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM($column), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";
    return array_map(static fn(array $r): string => (string) $r['value'], db()->query($sql)->fetchAll());
}

$aggregatorFilter = trim((string) ($_GET['aggregator'] ?? ''));
$employerFilter = trim((string) ($_GET['employer'] ?? ''));

$conditions = [];
$params = [];
if ($aggregatorFilter !== '') {
    $conditions[] = "COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') = ?";
    $params[] = $aggregatorFilter;
}
if ($employerFilter !== '') {
    $conditions[] = "Employer_Name LIKE ?";
    $params[] = '%' . $employerFilter . '%';
}
$whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT
        id,
        COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') AS aggregator,
        COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown') AS employer_name,
        COALESCE(NULLIF(TRIM(Employer_ID), ''), 'Unknown') AS employer_code,
        COALESCE(NULLIF(TRIM(Job_Title_Name), ''), 'Unknown') AS job_title,
        COALESCE(NULLIF(TRIM(Job_Id), ''), 'Unknown') AS job_id,
        COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
        DWMS_ID, Candidate_Name,
        Selection_Status, Shortlist_Candidate_Status, Candidate_Joined_Status
    FROM job_fair_result
    $whereClause
    ORDER BY aggregator ASC, employer_name ASC, job_title ASC, job_fair_no ASC, Candidate_Name ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$candidateRows = $stmt->fetchAll();

if (($_GET['download'] ?? '') === 'csv') {
    $filename = 'job_titles_tree_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Aggregator', 'Employer Name', 'Employer Code', 'Job Title', 'Job ID', 'Job Fair No',
        'DWMS ID', 'Candidate Name', 'Selection Status', 'Final (Shortlist) Status', 'Candidate Joined Status',
    ]);
    foreach ($candidateRows as $row) {
        fputcsv($out, [
            (string) $row['aggregator'],
            (string) $row['employer_name'],
            (string) $row['employer_code'],
            (string) $row['job_title'],
            (string) $row['job_id'],
            (string) $row['job_fair_no'],
            (string) ($row['DWMS_ID'] ?? ''),
            (string) ($row['Candidate_Name'] ?? ''),
            (string) ($row['Selection_Status'] ?? ''),
            (string) ($row['Shortlist_Candidate_Status'] ?? ''),
            (string) ($row['Candidate_Joined_Status'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// Build the nested tree from individual candidate rows and roll up metrics.
$metricKeys = ['candidates', 'selected', 'shortlisted_onhold', 'total_selected', 'joined'];
$zero = array_fill_keys($metricKeys, 0);
$tree = [];
foreach ($candidateRows as $row) {
    $aKey = (string) $row['aggregator'];
    $eKey = (string) $row['employer_name'] . '|' . (string) $row['employer_code'];
    $jKey = (string) $row['job_title'] . '|' . (string) $row['job_id'];
    $fKey = (string) $row['job_fair_no'];
    $cKey = (int) $row['id'];

    $selKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($row['Selection_Status'] ?? '')) ?? '');
    $shortKey = strtolower(preg_replace('/[^a-z0-9]+/i', '', (string) ($row['Shortlist_Candidate_Status'] ?? '')) ?? '');
    $joinKey = strtolower(trim((string) ($row['Candidate_Joined_Status'] ?? '')));

    $leafMetrics = [
        'candidates' => 1,
        'selected' => $selKey === 'selected' ? 1 : 0,
        'shortlisted_onhold' => in_array($selKey, ['shortlisted', 'onhold'], true) ? 1 : 0,
        'total_selected' => ($selKey === 'selected'
            || (in_array($selKey, ['shortlisted', 'onhold'], true) && $shortKey === 'selected')) ? 1 : 0,
        'joined' => $joinKey === 'yes' ? 1 : 0,
    ];

    if (!isset($tree[$aKey])) {
        $tree[$aKey] = ['label' => $aKey, 'metrics' => $zero, 'children' => []];
    }
    if (!isset($tree[$aKey]['children'][$eKey])) {
        $tree[$aKey]['children'][$eKey] = [
            'label' => (string) $row['employer_name'],
            'code' => (string) $row['employer_code'],
            'metrics' => $zero,
            'children' => [],
        ];
    }
    if (!isset($tree[$aKey]['children'][$eKey]['children'][$jKey])) {
        $tree[$aKey]['children'][$eKey]['children'][$jKey] = [
            'label' => (string) $row['job_title'],
            'job_id' => (string) $row['job_id'],
            'metrics' => $zero,
            'children' => [],
        ];
    }
    if (!isset($tree[$aKey]['children'][$eKey]['children'][$jKey]['children'][$fKey])) {
        $tree[$aKey]['children'][$eKey]['children'][$jKey]['children'][$fKey] = [
            'label' => $fKey,
            'metrics' => $zero,
            'children' => [],
        ];
    }
    $tree[$aKey]['children'][$eKey]['children'][$jKey]['children'][$fKey]['children'][$cKey] = [
        'label' => (string) ($row['Candidate_Name'] ?? '(unnamed)'),
        'candidate_id' => $cKey,
        'dwms_id' => (string) ($row['DWMS_ID'] ?? ''),
        'selection_status' => (string) ($row['Selection_Status'] ?? ''),
        'shortlist_status' => (string) ($row['Shortlist_Candidate_Status'] ?? ''),
        'joined_status' => (string) ($row['Candidate_Joined_Status'] ?? ''),
        'metrics' => $leafMetrics,
    ];

    foreach ($metricKeys as $k) {
        $tree[$aKey]['metrics'][$k] += $leafMetrics[$k];
        $tree[$aKey]['children'][$eKey]['metrics'][$k] += $leafMetrics[$k];
        $tree[$aKey]['children'][$eKey]['children'][$jKey]['metrics'][$k] += $leafMetrics[$k];
        $tree[$aKey]['children'][$eKey]['children'][$jKey]['children'][$fKey]['metrics'][$k] += $leafMetrics[$k];
    }
}

// Pre-compute grand totals so the table has a Total row.
$grandTotals = $zero;
foreach ($tree as $agg) {
    foreach ($metricKeys as $k) {
        $grandTotals[$k] += $agg['metrics'][$k];
    }
}

$aggregatorOptions = fetch_job_titles_distinct('Aggregator');

$downloadUrl = '/job_fair_job_titles.php?' . http_build_query(array_filter([
    'download' => 'csv',
    'aggregator' => $aggregatorFilter,
    'employer' => $employerFilter,
], static fn($v): bool => $v !== ''));

render_header('Job Titles', ['main_container_class' => 'container-fluid']);
render_page_header('Job Titles', [
    'icon' => 'bi-diagram-2',
    'subtitle' => 'Hierarchical view: Aggregator → Employer → Job Title → Job Fair, with candidate / selection / joining counts at each level.',
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
                        <option value="<?= esc($option) ?>" <?= $aggregatorFilter === $option ? 'selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label for="employer" class="form-label">Employer</label>
                <input type="text" class="form-control" id="employer" name="employer" value="<?= esc($employerFilter) ?>" placeholder="Search employer name">
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
                <a class="btn btn-light" href="/job_fair_job_titles.php">Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="card table-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-diagram-2 text-primary me-1"></i>Job Titles &mdash; Tree View</span>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light" id="treeExpandAll"><i class="bi bi-arrows-expand me-1"></i>Expand All</button>
            <button type="button" class="btn btn-sm btn-light" id="treeCollapseAll"><i class="bi bi-arrows-collapse me-1"></i>Collapse All</button>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="treeTable">
            <thead>
                <tr>
                    <th>Hierarchy</th>
                    <th class="text-end">Candidates</th>
                    <th class="text-end">Selected</th>
                    <th class="text-end">SL / OH</th>
                    <th class="text-end">Total Selected</th>
                    <th class="text-end">Joined</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($tree === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No data available for the selected filters.</div></td></tr>
                <?php else: ?>
                    <?php
                    $nodeCounter = 0;
                    $renderNode = static function (
                        array $node, int $level, string $parentId, string $kind, ?string $code, ?string $jobId
                    ) use (&$renderNode, &$nodeCounter, $metricKeys): void {
                        $nodeCounter++;
                        $nodeId = 'n' . $nodeCounter;
                        $hasChildren = !empty($node['children']);
                        $indent = ($level * 1.6) . 'rem';
                        $iconMap = [
                            'aggregator' => 'bi-building',
                            'employer' => 'bi-shop',
                            'job_title' => 'bi-briefcase',
                            'job_fair' => 'bi-calendar-event',
                            'candidate' => 'bi-person-circle',
                        ];
                        $kindLabelMap = [
                            'aggregator' => 'Aggregator',
                            'employer' => 'Employer',
                            'job_title' => 'Job Title',
                            'job_fair' => 'Job Fair',
                            'candidate' => 'Candidate',
                        ];
                        $rowClasses = 'tree-row tree-row-level-' . $level;
                        $isHidden = $level > 0;
                        ?>
                        <tr class="<?= $rowClasses ?>" data-node-id="<?= esc($nodeId) ?>" data-parent="<?= esc($parentId) ?>" data-level="<?= $level ?>" <?= $isHidden ? 'style="display:none;"' : '' ?>>
                            <td>
                                <div class="d-flex align-items-center flex-wrap" style="padding-left: <?= esc($indent) ?>;">
                                    <?php if ($hasChildren): ?>
                                        <button type="button" class="btn btn-link p-0 me-2 tree-toggle text-decoration-none" data-target="<?= esc($nodeId) ?>" data-expanded="false" aria-label="Toggle">
                                            <i class="bi bi-caret-right-fill"></i>
                                        </button>
                                    <?php else: ?>
                                        <span class="me-2" style="width: 1rem; display:inline-block;"></span>
                                    <?php endif; ?>
                                    <i class="bi <?= esc($iconMap[$kind]) ?> me-2 text-muted"></i>
                                    <span class="small text-muted me-1"><?= esc($kindLabelMap[$kind]) ?>:</span>
                                    <span class="fw-semibold"><?= esc((string) $node['label']) ?></span>
                                    <?php if ($kind === 'employer' && $code !== null && $code !== ''): ?>
                                        <span class="status-chip status-neutral ms-2">Code: <?= esc($code) ?></span>
                                    <?php endif; ?>
                                    <?php if ($kind === 'job_title' && $jobId !== null && $jobId !== ''): ?>
                                        <span class="status-chip status-neutral ms-2">Job ID: <?= esc($jobId) ?></span>
                                    <?php endif; ?>
                                    <?php if ($kind === 'candidate'): ?>
                                        <?php $dwms = (string) ($node['dwms_id'] ?? ''); ?>
                                        <?php if ($dwms !== ''): ?>
                                            <span class="status-chip status-neutral ms-2">DWMS: <?= esc($dwms) ?></span>
                                        <?php endif; ?>
                                        <?php if (($node['selection_status'] ?? '') !== ''): ?>
                                            <span class="ms-2"><?= render_status_chip($node['selection_status']) ?></span>
                                        <?php endif; ?>
                                        <?php if (($node['joined_status'] ?? '') !== ''): ?>
                                            <span class="ms-1"><?= render_status_chip($node['joined_status'], 'Joined:') ?></span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <?php foreach ($metricKeys as $k): ?>
                                <td class="text-end"><?= number_format((int) ($node['metrics'][$k] ?? 0)) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end">
                                <?php if ($kind === 'candidate' && !empty($node['candidate_id'])): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="/manage_candidate.php?candidate_id=<?= (int) $node['candidate_id'] ?>"><i class="bi bi-pencil-square"></i> Manage</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($hasChildren) {
                            foreach ($node['children'] as $child) {
                                $childKind = match ($kind) {
                                    'aggregator' => 'employer',
                                    'employer' => 'job_title',
                                    'job_title' => 'job_fair',
                                    'job_fair' => 'candidate',
                                    default => 'candidate',
                                };
                                $childCode = $childKind === 'employer' ? ($child['code'] ?? null) : null;
                                $childJobId = $childKind === 'job_title' ? ($child['job_id'] ?? null) : null;
                                $renderNode($child, $level + 1, $nodeId, $childKind, $childCode, $childJobId);
                            }
                        }
                    };
                    foreach ($tree as $aggNode) {
                        $renderNode($aggNode, 0, '', 'aggregator', null, null);
                    }
                    ?>
                    <tr class="table-secondary fw-semibold">
                        <td>Total (all levels rolled up)</td>
                        <?php foreach ($metricKeys as $k): ?>
                            <td class="text-end"><?= number_format($grandTotals[$k]) ?></td>
                        <?php endforeach; ?>
                        <td></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const table = document.getElementById('treeTable');
    if (!table) return;

    function setToggleState(button, expanded) {
        button.setAttribute('data-expanded', expanded ? 'true' : 'false');
        const icon = button.querySelector('i');
        if (icon) {
            icon.className = expanded ? 'bi bi-caret-down-fill' : 'bi bi-caret-right-fill';
        }
    }

    function hideDescendants(parentId) {
        table.querySelectorAll('tr[data-parent="' + parentId + '"]').forEach((row) => {
            row.style.display = 'none';
            const t = row.querySelector('.tree-toggle');
            if (t) setToggleState(t, false);
            hideDescendants(row.getAttribute('data-node-id'));
        });
    }

    table.addEventListener('click', (event) => {
        const btn = event.target.closest('.tree-toggle');
        if (!btn) return;
        event.preventDefault();
        const targetId = btn.getAttribute('data-target');
        const expanded = btn.getAttribute('data-expanded') === 'true';
        if (expanded) {
            hideDescendants(targetId);
        } else {
            table.querySelectorAll('tr[data-parent="' + targetId + '"]').forEach((row) => {
                row.style.display = '';
            });
        }
        setToggleState(btn, !expanded);
    });

    document.getElementById('treeExpandAll').addEventListener('click', () => {
        table.querySelectorAll('tr.tree-row').forEach((row) => {
            row.style.display = '';
        });
        table.querySelectorAll('.tree-toggle').forEach((btn) => setToggleState(btn, true));
    });

    document.getElementById('treeCollapseAll').addEventListener('click', () => {
        table.querySelectorAll('tr.tree-row').forEach((row) => {
            const level = parseInt(row.getAttribute('data-level') || '0', 10);
            row.style.display = level === 0 ? '' : 'none';
        });
        table.querySelectorAll('.tree-toggle').forEach((btn) => setToggleState(btn, false));
    });
})();
</script>

<?php render_footer(); ?>
