<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_admin();

/**
 * Hierarchy: Aggregator -> Employer (Employer_Name + Employer_ID) ->
 * Job Title (Job_Title_Name + Job_Id) -> Job Fair (Job_Fair_No) -> Candidate.
 * The first four levels are pre-aggregated and rendered up front; the
 * Candidate level is loaded on demand via AJAX when a Job Fair row is
 * expanded, so the initial page load stays fast even for large datasets.
 */

function fetch_job_titles_distinct(string $column): array
{
    $sql = "SELECT DISTINCT COALESCE(NULLIF(TRIM($column), ''), 'Unknown') AS value
        FROM job_fair_result
        ORDER BY value ASC";
    return array_map(static fn(array $r): string => (string) $r['value'], db()->query($sql)->fetchAll());
}

function build_filter_conditions(string $aggregatorFilter, string $employerFilter, array &$params): array
{
    $conditions = [];
    if ($aggregatorFilter !== '') {
        $conditions[] = "COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') = ?";
        $params[] = $aggregatorFilter;
    }
    if ($employerFilter !== '') {
        $conditions[] = "Employer_Name LIKE ?";
        $params[] = '%' . $employerFilter . '%';
    }
    return $conditions;
}

$aggregatorFilter = trim((string) ($_GET['aggregator'] ?? ''));
$employerFilter = trim((string) ($_GET['employer'] ?? ''));

/* ------------------------------------------------------------------------- *
 * AJAX endpoint: candidate rows under a specific Job Fair node.
 * Returns ready-to-inject <tr> HTML so the client just splices it into the
 * existing tree table.
 * ------------------------------------------------------------------------- */
if (($_GET['ajax'] ?? '') === 'candidates') {
    $jfMap = [
        'Aggregator' => trim((string) ($_GET['aggregator'] ?? '')),
        'Employer_Name' => trim((string) ($_GET['employer_name'] ?? '')),
        'Employer_ID' => trim((string) ($_GET['employer_code'] ?? '')),
        'Job_Title_Name' => trim((string) ($_GET['job_title'] ?? '')),
        'Job_Id' => trim((string) ($_GET['job_id'] ?? '')),
        'Job_Fair_No' => trim((string) ($_GET['job_fair_no'] ?? '')),
    ];
    $parentId = trim((string) ($_GET['parent_id'] ?? ''));
    $level = max(0, (int) ($_GET['level'] ?? 4));

    $conds = [];
    $bind = [];
    foreach ($jfMap as $col => $val) {
        if ($val === '' || $val === 'Unknown') {
            $conds[] = "($col IS NULL OR TRIM($col) = '')";
        } else {
            $conds[] = "TRIM(COALESCE($col, '')) = ?";
            $bind[] = $val;
        }
    }
    $where = 'WHERE ' . implode(' AND ', $conds);
    $sql = "SELECT id, DWMS_ID, Candidate_Name, Selection_Status, Shortlist_Candidate_Status, Candidate_Joined_Status
        FROM job_fair_result
        $where
        ORDER BY Candidate_Name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($bind);
    $candidates = $stmt->fetchAll();

    $indent = ($level * 1.6) . 'rem';
    header('Content-Type: text/html; charset=utf-8');

    if ($candidates === []) {
        ?>
        <tr class="tree-row tree-row-level-<?= $level ?>" data-parent="<?= esc($parentId) ?>" data-level="<?= $level ?>">
            <td colspan="7">
                <div class="ms-3 small text-muted py-2" style="padding-left: <?= esc($indent) ?>;">
                    <i class="bi bi-inbox me-1"></i>No candidates found for this Job Fair group.
                </div>
            </td>
        </tr>
        <?php
        exit;
    }

    foreach ($candidates as $cRow) {
        $selKey = strtolower((string) (preg_replace('/[^a-z0-9]+/i', '', (string) ($cRow['Selection_Status'] ?? '')) ?? ''));
        $shortKey = strtolower((string) (preg_replace('/[^a-z0-9]+/i', '', (string) ($cRow['Shortlist_Candidate_Status'] ?? '')) ?? ''));
        $joinKey = strtolower(trim((string) ($cRow['Candidate_Joined_Status'] ?? '')));
        $selected = $selKey === 'selected' ? 1 : 0;
        $sl_oh = in_array($selKey, ['shortlisted', 'onhold'], true) ? 1 : 0;
        $totalSelected = ($selKey === 'selected'
            || (in_array($selKey, ['shortlisted', 'onhold'], true) && $shortKey === 'selected')) ? 1 : 0;
        $joined = $joinKey === 'yes' ? 1 : 0;
        $dwms = (string) ($cRow['DWMS_ID'] ?? '');
        ?>
        <tr class="tree-row tree-row-level-<?= $level ?>" data-parent="<?= esc($parentId) ?>" data-level="<?= $level ?>">
            <td>
                <div class="d-flex align-items-center flex-wrap" style="padding-left: <?= esc($indent) ?>;">
                    <span class="me-2" style="width: 1rem; display:inline-block;"></span>
                    <i class="bi bi-person-circle me-2 text-muted"></i>
                    <span class="small text-muted me-1">Candidate:</span>
                    <span class="fw-semibold"><?= esc((string) ($cRow['Candidate_Name'] ?? '(unnamed)')) ?></span>
                    <?php if ($dwms !== ''): ?>
                        <span class="status-chip status-neutral ms-2">DWMS: <?= esc($dwms) ?></span>
                    <?php endif; ?>
                    <?php if (($cRow['Selection_Status'] ?? '') !== ''): ?>
                        <span class="ms-2"><?= render_status_chip($cRow['Selection_Status']) ?></span>
                    <?php endif; ?>
                    <?php if (($cRow['Candidate_Joined_Status'] ?? '') !== ''): ?>
                        <span class="ms-1"><?= render_status_chip($cRow['Candidate_Joined_Status'], 'Joined:') ?></span>
                    <?php endif; ?>
                </div>
            </td>
            <td class="text-end">1</td>
            <td class="text-end"><?= $selected ?></td>
            <td class="text-end"><?= $sl_oh ?></td>
            <td class="text-end"><?= $totalSelected ?></td>
            <td class="text-end"><?= $joined ?></td>
            <td class="text-end">
                <a class="btn btn-sm btn-outline-primary" href="/manage_candidate.php?candidate_id=<?= (int) $cRow['id'] ?>"><i class="bi bi-pencil-square"></i> Manage</a>
            </td>
        </tr>
        <?php
    }
    exit;
}

/* ------------------------------------------------------------------------- *
 * CSV download: per-candidate flat export (slow but on-demand).
 * ------------------------------------------------------------------------- */
if (($_GET['download'] ?? '') === 'csv') {
    $csvParams = [];
    $csvConditions = build_filter_conditions($aggregatorFilter, $employerFilter, $csvParams);
    $csvWhere = $csvConditions === [] ? '' : 'WHERE ' . implode(' AND ', $csvConditions);
    $csvSql = "SELECT
            COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') AS aggregator,
            COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown') AS employer_name,
            COALESCE(NULLIF(TRIM(Employer_ID), ''), 'Unknown') AS employer_code,
            COALESCE(NULLIF(TRIM(Job_Title_Name), ''), 'Unknown') AS job_title,
            COALESCE(NULLIF(TRIM(Job_Id), ''), 'Unknown') AS job_id,
            COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
            DWMS_ID, Candidate_Name, Selection_Status, Shortlist_Candidate_Status, Candidate_Joined_Status
        FROM job_fair_result
        $csvWhere
        ORDER BY aggregator ASC, employer_name ASC, job_title ASC, job_fair_no ASC, Candidate_Name ASC";
    $csvStmt = db()->prepare($csvSql);
    $csvStmt->execute($csvParams);
    $csvRows = $csvStmt->fetchAll();

    $filename = 'job_titles_tree_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [
        'Aggregator', 'Employer Name', 'Employer Code', 'Job Title', 'Job ID', 'Job Fair No',
        'DWMS ID', 'Candidate Name', 'Selection Status', 'Final (Shortlist) Status', 'Candidate Joined Status',
    ]);
    foreach ($csvRows as $row) {
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

/* ------------------------------------------------------------------------- *
 * Initial render: 4-level aggregated tree (Aggregator -> Employer -> Job
 * Title -> Job Fair). Candidate rows are added on demand via AJAX.
 * ------------------------------------------------------------------------- */
$params = [];
$conditions = build_filter_conditions($aggregatorFilter, $employerFilter, $params);
$whereClause = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT
        COALESCE(NULLIF(TRIM(Aggregator), ''), 'Unknown') AS aggregator,
        COALESCE(NULLIF(TRIM(Employer_Name), ''), 'Unknown') AS employer_name,
        COALESCE(NULLIF(TRIM(Employer_ID), ''), 'Unknown') AS employer_code,
        COALESCE(NULLIF(TRIM(Job_Title_Name), ''), 'Unknown') AS job_title,
        COALESCE(NULLIF(TRIM(Job_Id), ''), 'Unknown') AS job_id,
        COALESCE(NULLIF(TRIM(Job_Fair_No), ''), 'Unknown') AS job_fair_no,
        COUNT(*) AS candidates,
        SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS selected,
        SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted','onhold') THEN 1 ELSE 0 END) AS shortlisted_onhold,
        SUM(CASE WHEN LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) = 'selected'
                 OR (LOWER(REPLACE(TRIM(COALESCE(Selection_Status, '')), ' ', '')) IN ('shortlisted','onhold')
                     AND LOWER(REPLACE(TRIM(COALESCE(Shortlist_Candidate_Status, '')), ' ', '')) = 'selected')
            THEN 1 ELSE 0 END) AS total_selected,
        SUM(CASE WHEN LOWER(TRIM(COALESCE(Candidate_Joined_Status, ''))) = 'yes' THEN 1 ELSE 0 END) AS joined
    FROM job_fair_result
    $whereClause
    GROUP BY aggregator, employer_name, employer_code, job_title, job_id, job_fair_no
    ORDER BY aggregator ASC, employer_name ASC, job_title ASC, job_fair_no ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$leafRows = $stmt->fetchAll();

$metricKeys = ['candidates', 'selected', 'shortlisted_onhold', 'total_selected', 'joined'];
$zero = array_fill_keys($metricKeys, 0);
$tree = [];
foreach ($leafRows as $row) {
    $aKey = (string) $row['aggregator'];
    $eKey = (string) $row['employer_name'] . '|' . (string) $row['employer_code'];
    $jKey = (string) $row['job_title'] . '|' . (string) $row['job_id'];
    $fKey = (string) $row['job_fair_no'];

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
    $leafMetrics = [];
    foreach ($metricKeys as $k) {
        $leafMetrics[$k] = (int) ($row[$k] ?? 0);
    }
    // Stash the signature needed by the AJAX endpoint so the Job Fair row
    // knows exactly which candidates to ask for.
    $tree[$aKey]['children'][$eKey]['children'][$jKey]['children'][$fKey] = [
        'label' => $fKey,
        'metrics' => $leafMetrics,
        'ajax_signature' => [
            'aggregator' => (string) $row['aggregator'],
            'employer_name' => (string) $row['employer_name'],
            'employer_code' => (string) $row['employer_code'],
            'job_title' => (string) $row['job_title'],
            'job_id' => (string) $row['job_id'],
            'job_fair_no' => $fKey,
        ],
    ];
    foreach ($metricKeys as $k) {
        $tree[$aKey]['metrics'][$k] += $leafMetrics[$k];
        $tree[$aKey]['children'][$eKey]['metrics'][$k] += $leafMetrics[$k];
        $tree[$aKey]['children'][$eKey]['children'][$jKey]['metrics'][$k] += $leafMetrics[$k];
    }
}

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
    'subtitle' => 'Hierarchical view: Aggregator → Employer → Job Title → Job Fair → Candidate. Candidates load on demand when you expand a Job Fair row.',
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
                        $hasPrerenderedChildren = !empty($node['children']);
                        // Job Fair nodes always show a toggle even when they have
                        // no pre-rendered children, because Candidate rows are
                        // loaded under them via AJAX.
                        $showToggle = $hasPrerenderedChildren || $kind === 'job_fair';
                        $indent = ($level * 1.6) . 'rem';
                        $iconMap = [
                            'aggregator' => 'bi-building',
                            'employer' => 'bi-shop',
                            'job_title' => 'bi-briefcase',
                            'job_fair' => 'bi-calendar-event',
                        ];
                        $kindLabelMap = [
                            'aggregator' => 'Aggregator',
                            'employer' => 'Employer',
                            'job_title' => 'Job Title',
                            'job_fair' => 'Job Fair',
                        ];
                        $rowClasses = 'tree-row tree-row-level-' . $level;
                        $isHidden = $level > 0;
                        $ajaxUrl = '';
                        if ($kind === 'job_fair' && !empty($node['ajax_signature'])) {
                            $ajaxUrl = '/job_fair_job_titles.php?' . http_build_query(array_merge(
                                ['ajax' => 'candidates'],
                                $node['ajax_signature']
                            ));
                        }
                        $extraAttrs = '';
                        if ($ajaxUrl !== '') {
                            $extraAttrs = ' data-ajax-load="' . esc($ajaxUrl) . '"';
                        }
                        ?>
                        <tr class="<?= $rowClasses ?>" data-node-id="<?= esc($nodeId) ?>" data-parent="<?= esc($parentId) ?>" data-level="<?= $level ?>"<?= $extraAttrs ?> <?= $isHidden ? 'style="display:none;"' : '' ?>>
                            <td>
                                <div class="d-flex align-items-center flex-wrap" style="padding-left: <?= esc($indent) ?>;">
                                    <?php if ($showToggle): ?>
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
                                </div>
                            </td>
                            <?php foreach ($metricKeys as $k): ?>
                                <td class="text-end"><?= number_format((int) ($node['metrics'][$k] ?? 0)) ?></td>
                            <?php endforeach; ?>
                            <td class="text-end"></td>
                        </tr>
                        <?php if ($hasPrerenderedChildren) {
                            foreach ($node['children'] as $child) {
                                $childKind = match ($kind) {
                                    'aggregator' => 'employer',
                                    'employer' => 'job_title',
                                    'job_title' => 'job_fair',
                                    default => 'job_fair',
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

    function setToggleState(button, expanded, busy = false) {
        button.setAttribute('data-expanded', expanded ? 'true' : 'false');
        const icon = button.querySelector('i');
        if (icon) {
            if (busy) {
                icon.className = 'bi bi-arrow-clockwise';
            } else {
                icon.className = expanded ? 'bi bi-caret-down-fill' : 'bi bi-caret-right-fill';
            }
        }
    }

    function hideDescendants(parentId) {
        table.querySelectorAll('tr[data-parent="' + parentId + '"]').forEach((row) => {
            row.style.display = 'none';
            const t = row.querySelector('.tree-toggle');
            if (t) setToggleState(t, false);
            const childId = row.getAttribute('data-node-id');
            if (childId) hideDescendants(childId);
        });
    }

    function showDirectChildren(parentId) {
        table.querySelectorAll('tr[data-parent="' + parentId + '"]').forEach((row) => {
            row.style.display = '';
        });
    }

    table.addEventListener('click', (event) => {
        const btn = event.target.closest('.tree-toggle');
        if (!btn) return;
        event.preventDefault();

        const targetId = btn.getAttribute('data-target');
        const row = btn.closest('tr');
        if (!row) return;
        const expanded = btn.getAttribute('data-expanded') === 'true';

        if (expanded) {
            hideDescendants(targetId);
            setToggleState(btn, false);
            return;
        }

        const ajaxLoad = row.getAttribute('data-ajax-load');
        const alreadyLoaded = row.getAttribute('data-ajax-loaded') === 'true';

        if (ajaxLoad && !alreadyLoaded) {
            btn.disabled = true;
            setToggleState(btn, false, true);

            const childLevel = parseInt(row.getAttribute('data-level') || '0', 10) + 1;
            const url = ajaxLoad
                + '&parent_id=' + encodeURIComponent(targetId)
                + '&level=' + childLevel;

            fetch(url)
                .then((r) => {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.text();
                })
                .then((html) => {
                    const tpl = document.createElement('template');
                    tpl.innerHTML = html.trim();
                    let anchor = row;
                    Array.from(tpl.content.children).forEach((node) => {
                        anchor.insertAdjacentElement('afterend', node);
                        anchor = node;
                    });
                    row.setAttribute('data-ajax-loaded', 'true');
                    btn.disabled = false;
                    setToggleState(btn, true);
                })
                .catch((err) => {
                    btn.disabled = false;
                    setToggleState(btn, false);
                    window.alert('Failed to load candidates: ' + err.message);
                });
        } else {
            showDirectChildren(targetId);
            setToggleState(btn, true);
        }
    });

    document.getElementById('treeExpandAll').addEventListener('click', () => {
        // Only expands already-loaded rows. Job Fair rows still need an
        // individual click to AJAX-load their candidates - otherwise an
        // "expand all" could fire dozens of parallel requests.
        table.querySelectorAll('tr.tree-row').forEach((row) => {
            const isAjaxJobFair = row.hasAttribute('data-ajax-load') && row.getAttribute('data-ajax-loaded') !== 'true';
            if (!isAjaxJobFair) {
                row.style.display = '';
            }
        });
        table.querySelectorAll('.tree-toggle').forEach((btn) => {
            const row = btn.closest('tr');
            const isPendingJobFair = row && row.hasAttribute('data-ajax-load') && row.getAttribute('data-ajax-loaded') !== 'true';
            if (!isPendingJobFair) {
                setToggleState(btn, true);
            }
        });
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
