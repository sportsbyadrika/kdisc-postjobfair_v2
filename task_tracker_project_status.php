<?php
/**
 * Task Tracker · Project Status.
 *
 * Two views over the same task tree:
 *
 *   1. "Tree table" tab — expandable/collapsible rows. Project rows
 *      show budget + balance summary. Clicking + on a project
 *      reveals its top-level Activities; clicking + on an Activity
 *      reveals its Sub-activities. Each row shows share amount,
 *      projected / target / actual expenditure, balance and
 *      progress %.
 *
 *   2. "Gantt" tab — a Frappe-Gantt chart of the selected project's
 *      tasks over their planned_start → planned_end range. Dropped
 *      into a sandboxed iframe so the library's CSS doesn't leak
 *      into the rest of the app.
 *
 * Access:
 *   - require_task_tracker_access() — every seat holder or admin.
 *   - The read scope mirrors the projects list: all tasks in each
 *     project the viewer can see are shown. The Gantt is per-project
 *     via ?project=<id>.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_task_tracker_access();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];

$hasBudgetCol   = task_tracker_column_exists('project', 'budget_amount');
$hasFin         = task_tracker_column_exists('task',    'share_amount');
$hasProgressCol = task_tracker_column_exists('task',    'progress_pct');

$budgetSelect = $hasBudgetCol ? 'p.budget_amount' : 'NULL AS budget_amount';
$projects = db()->query("SELECT p.id, p.code, p.name, p.start_date, p.end_date, p.is_active, $budgetSelect
    FROM project p
    WHERE p.office_id = " . (int) TASK_TRACKER_OFFICE_ID . "
    ORDER BY p.is_active DESC, p.name ASC")->fetchAll();

// Read the tab up-front — the tree table's cross-project task fetch +
// PHP aggregation is expensive (O(P × T)), so skip it entirely when
// we're rendering the Gantt tab. Gantt data lazy-loads via AJAX from
// /task_tracker_ajax_gantt.php.
$tab = (string) ($_GET['tab'] ?? 'tree');
if (!in_array($tab, ['tree', 'gantt'], true)) $tab = 'tree';

$finSelect = $hasFin
    ? 't.share_amount, t.projected_amount, t.target_expenditure, t.actual_expenditure'
    : 'NULL AS share_amount, NULL AS projected_amount, NULL AS target_expenditure, NULL AS actual_expenditure';
$progressSelect = $hasProgressCol ? 't.progress_pct' : 'NULL AS progress_pct';

$tasks = [];
$byProject = [];
$projectSummary = [];

if ($tab === 'tree') {
    $tasks = db()->query("SELECT t.id, t.project_id, t.parent_id, t.task_number, t.title,
            t.status_id, t.planned_start, t.planned_end, t.actual_start, t.actual_end,
            $finSelect, $progressSelect,
            s.name AS status_name, s.colour_token AS status_colour, s.is_terminal, s.category AS status_category
        FROM task t
        LEFT JOIN task_status s ON s.id = t.status_id
        WHERE t.is_active = 1
        ORDER BY t.project_id, COALESCE(t.parent_id, t.id) ASC, t.parent_id IS NULL DESC, t.task_number ASC")->fetchAll();

    // Group tasks per project → per parent activity (null parent = top-
    // level activity, non-null = sub-activity of that parent).
    foreach ($tasks as $t) {
        $pid = (int) $t['project_id'];
        $parent = empty($t['parent_id']) ? 0 : (int) $t['parent_id'];
        $byProject[$pid][$parent][] = $t;
    }
}

// Aggregate money / progress totals per project (across all active
// tasks) — used on the project row summary. Only needed on the tree
// tab.
if ($tab === 'tree'):
foreach ($projects as $p) {
    $pid = (int) $p['id'];
    $projectTasks = [];
    foreach (($byProject[$pid] ?? []) as $group) foreach ($group as $t) $projectTasks[] = $t;
    $sumShare = 0.0; $sumProjected = 0.0; $sumTarget = 0.0; $sumActual = 0.0;
    $totalTasks = 0; $completedTasks = 0; $weightedProgress = 0.0;
    foreach ($projectTasks as $t) {
        $totalTasks++;
        $sumShare     += (float) ($t['share_amount']       ?? 0);
        $sumProjected += (float) ($t['projected_amount']   ?? 0);
        $sumTarget    += (float) ($t['target_expenditure'] ?? 0);
        $sumActual    += (float) ($t['actual_expenditure'] ?? 0);
        if ((int) ($t['is_terminal'] ?? 0) === 1) $completedTasks++;
        // Weight progress by 1 per task — either explicit progress_pct
        // or 100 for terminal statuses / 0 for the rest.
        $pct = $t['progress_pct'] !== null ? (int) $t['progress_pct']
            : ((int) ($t['is_terminal'] ?? 0) === 1 ? 100 : 0);
        $weightedProgress += $pct;
    }
    $projectSummary[$pid] = [
        'budget'            => $p['budget_amount'] !== null ? (float) $p['budget_amount'] : null,
        'share_total'       => $sumShare,
        'projected_total'   => $sumProjected,
        'target_total'      => $sumTarget,
        'actual_total'      => $sumActual,
        'balance'           => $p['budget_amount'] !== null ? ((float) $p['budget_amount'] - $sumActual) : null,
        'total_tasks'       => $totalTasks,
        'completed_tasks'   => $completedTasks,
        'progress_avg'      => $totalTasks > 0 ? $weightedProgress / $totalTasks : 0.0,
    ];
}
endif; // $tab === 'tree'

$fmtMoney = static fn($v) => ($v === null || $v === '') ? '—' : '₹' . number_format((float) $v, 2);
$fmtDate  = static fn(?string $s) => substr((string) $s, 0, 10) === '' ? '—' : date('d/m/Y', strtotime((string) $s));

$ganttProject = (int) ($_GET['project'] ?? 0);
if ($ganttProject === 0 && $tab === 'gantt' && $projects !== []) $ganttProject = (int) $projects[0]['id'];

render_header('Task Tracker · Project Status', ['main_container_class' => 'container-fluid']);
render_page_header('Task Tracker · Project Status', [
    'icon' => 'bi-diagram-2',
    'subtitle' => 'Nested Project → Activity → Sub-activity view with budget, expenditure and progress on every row.',
    'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>All projects</a>',
]);
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 'tree'  ? 'active' : '' ?>" href="?tab=tree"><i class="bi bi-list-nested me-1"></i>Tree table</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'gantt' ? 'active' : '' ?>" href="?tab=gantt<?= $ganttProject > 0 ? '&project=' . $ganttProject : '' ?>"><i class="bi bi-bar-chart-steps me-1"></i>Gantt chart</a></li>
</ul>

<?php if ($tab === 'tree'): ?>

<?php if ($projects === []): ?>
    <div class="empty-state"><i class="bi bi-inbox"></i>No projects yet.</div>
<?php else: ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-diagram-2 text-primary me-1"></i>Projects</span>
        <span class="small text-muted">Click <i class="bi bi-plus-square"></i> on a project to expand its activities. Click again on an activity to reveal sub-activities.</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 tt-status-tree">
            <thead>
                <tr class="table-light">
                    <th style="min-width:280px;">Project · Activity · Sub-activity</th>
                    <th>Status</th>
                    <th class="text-end">Allotted (₹)</th>
                    <th class="text-end">Projected (₹)</th>
                    <th class="text-end">Target (₹)</th>
                    <th class="text-end">Actual (₹)</th>
                    <th class="text-end">Balance (₹)</th>
                    <th class="text-end" style="min-width:180px;">Progress</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $p):
                    $pid = (int) $p['id'];
                    $sum = $projectSummary[$pid];
                    $balance = $sum['balance'];
                    $balTone = ($balance !== null && $balance < 0) ? 'text-danger fw-bold' : '';
                    $pct = $sum['progress_avg'];
                    $barTone = $pct >= 80 ? 'success' : ($pct >= 50 ? 'info' : ($pct >= 25 ? 'warning' : 'danger'));
                    if ($sum['total_tasks'] === 0) $barTone = 'secondary';
                ?>
                    <tr class="tt-row-project" data-level="project" data-project-id="<?= $pid ?>">
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 me-2 tt-toggle" data-target="project-<?= $pid ?>" aria-expanded="false">
                                <i class="bi bi-plus-square"></i>
                            </button>
                            <span class="badge text-bg-light border font-monospace me-2"><?= esc((string) $p['code']) ?></span>
                            <a href="/task_tracker_project_view.php?id=<?= $pid ?>" class="fw-semibold text-decoration-none text-body"><?= esc((string) $p['name']) ?></a>
                            <div class="small text-muted mt-1"><?= (int) $sum['total_tasks'] ?> task<?= $sum['total_tasks'] === 1 ? '' : 's' ?> · <?= (int) $sum['completed_tasks'] ?> completed</div>
                        </td>
                        <td><span class="badge text-bg-<?= ((int) $p['is_active']) === 1 ? 'success' : 'secondary' ?>"><?= ((int) $p['is_active']) === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td class="text-end small font-monospace"><?= esc($fmtMoney($sum['share_total'])) ?></td>
                        <td class="text-end small font-monospace"><?= esc($fmtMoney($sum['projected_total'])) ?></td>
                        <td class="text-end small font-monospace"><?= esc($fmtMoney($sum['target_total'])) ?></td>
                        <td class="text-end small font-monospace"><?= esc($fmtMoney($sum['actual_total'])) ?></td>
                        <td class="text-end small font-monospace <?= $balTone ?>">
                            <?= esc($balance === null ? '—' : $fmtMoney($balance)) ?>
                            <?php if ($sum['budget'] !== null): ?>
                                <div class="small text-muted">budget <?= esc($fmtMoney($sum['budget'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end" style="min-width:180px;">
                            <div class="d-flex align-items-center justify-content-end gap-2">
                                <div class="progress flex-grow-1" style="height:8px; max-width:120px;">
                                    <div class="progress-bar bg-<?= esc($barTone) ?>" role="progressbar" style="width: <?= (float) $pct ?>%;" aria-valuenow="<?= (float) $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <span class="fw-bold"><?= $sum['total_tasks'] > 0 ? number_format($pct, 0) . '%' : '—' ?></span>
                            </div>
                        </td>
                    </tr>
                    <?php
                    // Activities under this project (parent_id null / 0).
                    $activities = $byProject[$pid][0] ?? [];
                    foreach ($activities as $a):
                        $aid = (int) $a['id'];
                        $aShare     = $a['share_amount']       !== null ? (float) $a['share_amount']       : null;
                        $aProjected = $a['projected_amount']   !== null ? (float) $a['projected_amount']   : null;
                        $aTarget    = $a['target_expenditure'] !== null ? (float) $a['target_expenditure'] : null;
                        $aActual    = $a['actual_expenditure'] !== null ? (float) $a['actual_expenditure'] : null;
                        $aBalance   = ($aTarget === null && $aActual === null) ? null : (($aTarget ?? 0) - ($aActual ?? 0));
                        $aTone      = (string) ($a['status_colour'] ?? 'secondary'); if ($aTone === 'neutral') $aTone = 'secondary';
                        $aPct       = $a['progress_pct'] !== null ? (int) $a['progress_pct']
                            : ((int) ($a['is_terminal'] ?? 0) === 1 ? 100 : 0);
                        $aBarTone   = $aPct >= 80 ? 'success' : ($aPct >= 50 ? 'info' : ($aPct >= 25 ? 'warning' : 'danger'));
                        $subCount   = count($byProject[$pid][$aid] ?? []);
                    ?>
                        <tr class="tt-row-activity d-none" data-level="activity" data-project-id="<?= $pid ?>" data-activity-id="<?= $aid ?>" data-parent-toggle="project-<?= $pid ?>">
                            <td>
                                <span style="display:inline-block; width:24px;"></span>
                                <?php if ($subCount > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 me-2 tt-toggle" data-target="activity-<?= $aid ?>" aria-expanded="false">
                                        <i class="bi bi-plus-square"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="d-inline-block" style="width:36px;"></span>
                                <?php endif; ?>
                                <span class="badge text-bg-light border font-monospace me-1"><?= esc((string) $p['code']) ?>-<?= (int) $a['task_number'] ?></span>
                                <a href="/task_tracker_task_view.php?id=<?= $aid ?>" class="text-decoration-none text-body"><?= esc((string) $a['title']) ?></a>
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-calendar-event me-1"></i><?= esc($fmtDate($a['planned_start'] ?? '')) ?> → <?= esc($fmtDate($a['planned_end'] ?? '')) ?>
                                    <?php if ($subCount > 0): ?> · <span class="badge text-bg-secondary"><?= $subCount ?> sub-activit<?= $subCount === 1 ? 'y' : 'ies' ?></span><?php endif; ?>
                                </div>
                            </td>
                            <td><span class="badge text-bg-<?= esc($aTone) ?>"><?= esc((string) ($a['status_name'] ?? '—')) ?></span></td>
                            <td class="text-end small font-monospace"><?= esc($fmtMoney($aShare)) ?></td>
                            <td class="text-end small font-monospace"><?= esc($fmtMoney($aProjected)) ?></td>
                            <td class="text-end small font-monospace"><?= esc($fmtMoney($aTarget)) ?></td>
                            <td class="text-end small font-monospace"><?= esc($fmtMoney($aActual)) ?></td>
                            <td class="text-end small font-monospace <?= $aBalance !== null && $aBalance < 0 ? 'text-danger fw-bold' : '' ?>"><?= esc($aBalance === null ? '—' : $fmtMoney($aBalance)) ?></td>
                            <td class="text-end" style="min-width:180px;">
                                <div class="d-flex align-items-center justify-content-end gap-2">
                                    <div class="progress flex-grow-1" style="height:8px; max-width:120px;">
                                        <div class="progress-bar bg-<?= esc($aBarTone) ?>" role="progressbar" style="width: <?= (int) $aPct ?>%;" aria-valuenow="<?= (int) $aPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <span class="fw-bold"><?= $aPct ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php
                        // Sub-activities under this activity.
                        $subs = $byProject[$pid][$aid] ?? [];
                        foreach ($subs as $s):
                            $sid = (int) $s['id'];
                            $sShare     = $s['share_amount']       !== null ? (float) $s['share_amount']       : null;
                            $sProjected = $s['projected_amount']   !== null ? (float) $s['projected_amount']   : null;
                            $sTarget    = $s['target_expenditure'] !== null ? (float) $s['target_expenditure'] : null;
                            $sActual    = $s['actual_expenditure'] !== null ? (float) $s['actual_expenditure'] : null;
                            $sBalance   = ($sTarget === null && $sActual === null) ? null : (($sTarget ?? 0) - ($sActual ?? 0));
                            $sTone      = (string) ($s['status_colour'] ?? 'secondary'); if ($sTone === 'neutral') $sTone = 'secondary';
                            $sPct       = $s['progress_pct'] !== null ? (int) $s['progress_pct']
                                : ((int) ($s['is_terminal'] ?? 0) === 1 ? 100 : 0);
                            $sBarTone   = $sPct >= 80 ? 'success' : ($sPct >= 50 ? 'info' : ($sPct >= 25 ? 'warning' : 'danger'));
                        ?>
                            <tr class="tt-row-sub d-none" data-level="sub" data-project-id="<?= $pid ?>" data-activity-id="<?= $aid ?>" data-parent-toggle="activity-<?= $aid ?>" data-project-toggle="project-<?= $pid ?>">
                                <td>
                                    <span style="display:inline-block; width:60px;"></span>
                                    <span class="text-muted me-1">↳</span>
                                    <span class="badge text-bg-light border font-monospace me-1"><?= esc((string) $p['code']) ?>-<?= (int) $s['task_number'] ?></span>
                                    <a href="/task_tracker_task_view.php?id=<?= $sid ?>" class="text-decoration-none text-body"><?= esc((string) $s['title']) ?></a>
                                    <div class="small text-muted mt-1">
                                        <i class="bi bi-calendar-event me-1"></i><?= esc($fmtDate($s['planned_start'] ?? '')) ?> → <?= esc($fmtDate($s['planned_end'] ?? '')) ?>
                                    </div>
                                </td>
                                <td><span class="badge text-bg-<?= esc($sTone) ?>"><?= esc((string) ($s['status_name'] ?? '—')) ?></span></td>
                                <td class="text-end small font-monospace"><?= esc($fmtMoney($sShare)) ?></td>
                                <td class="text-end small font-monospace"><?= esc($fmtMoney($sProjected)) ?></td>
                                <td class="text-end small font-monospace"><?= esc($fmtMoney($sTarget)) ?></td>
                                <td class="text-end small font-monospace"><?= esc($fmtMoney($sActual)) ?></td>
                                <td class="text-end small font-monospace <?= $sBalance !== null && $sBalance < 0 ? 'text-danger fw-bold' : '' ?>"><?= esc($sBalance === null ? '—' : $fmtMoney($sBalance)) ?></td>
                                <td class="text-end" style="min-width:180px;">
                                    <div class="d-flex align-items-center justify-content-end gap-2">
                                        <div class="progress flex-grow-1" style="height:8px; max-width:120px;">
                                            <div class="progress-bar bg-<?= esc($sBarTone) ?>" role="progressbar" style="width: <?= (int) $sPct ?>%;" aria-valuenow="<?= (int) $sPct ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                        <span class="fw-bold"><?= $sPct ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('click', (ev) => {
    const btn = ev.target.closest('.tt-toggle');
    if (!btn) return;
    ev.preventDefault();
    const target = btn.getAttribute('data-target');
    const icon = btn.querySelector('i');
    const expanded = btn.getAttribute('aria-expanded') === 'true';
    if (expanded) {
        // Collapse: hide any row whose parent-toggle chain leads back to this button.
        document.querySelectorAll(`tr[data-parent-toggle="${target}"]`).forEach(row => {
            row.classList.add('d-none');
            // Any sub-rows nested one level deeper also collapse.
            const nested = row.querySelector('.tt-toggle');
            if (nested) {
                nested.setAttribute('aria-expanded', 'false');
                nested.querySelector('i')?.classList.remove('bi-dash-square');
                nested.querySelector('i')?.classList.add('bi-plus-square');
                const inner = nested.getAttribute('data-target');
                document.querySelectorAll(`tr[data-parent-toggle="${inner}"]`).forEach(r2 => r2.classList.add('d-none'));
            }
        });
        btn.setAttribute('aria-expanded', 'false');
        icon?.classList.remove('bi-dash-square');
        icon?.classList.add('bi-plus-square');
    } else {
        document.querySelectorAll(`tr[data-parent-toggle="${target}"]`).forEach(row => row.classList.remove('d-none'));
        btn.setAttribute('aria-expanded', 'true');
        icon?.classList.remove('bi-plus-square');
        icon?.classList.add('bi-dash-square');
    }
});
</script>

<?php endif; ?>

<?php else: /* Gantt tab */ ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <form method="get" class="d-flex align-items-center gap-2 mb-0">
            <input type="hidden" name="tab" value="gantt">
            <label class="form-label mb-0 me-1" for="ganttProj">Project</label>
            <select name="project" id="ganttProj" class="form-select form-select-sm" onchange="this.form.submit()">
                <?php foreach ($projects as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= $ganttProject === (int) $p['id'] ? 'selected' : '' ?>>
                        <?= esc((string) $p['code']) ?> · <?= esc((string) $p['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-success d-none" id="ganttSaveBtn"
                title="Save the date ranges you dragged on the Gantt back to the tasks.">
                <i class="bi bi-check2-circle me-1"></i>Update dates
                <span class="badge text-bg-light border ms-1" id="ganttPendingCount">0</span>
            </button>
            <div class="btn-group btn-group-sm" role="group" aria-label="Gantt mode">
                <button type="button" class="btn btn-outline-secondary active" data-gantt-mode="Day">Day</button>
                <button type="button" class="btn btn-outline-secondary"       data-gantt-mode="Week">Week</button>
                <button type="button" class="btn btn-outline-secondary"       data-gantt-mode="Month">Month</button>
                <button type="button" class="btn btn-outline-secondary"       data-gantt-mode="Quarter Day">Quarter Day</button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php /* Gantt data lazy-loads via /task_tracker_ajax_gantt.php so
                 the page renders instantly and the heavy SQL + Frappe
                 render happens off the initial paint. On a big project
                 (50+ sub-activities) this drops perceived load from
                 several seconds to <200ms. */ ?>
        <div id="ganttEmpty" class="empty-state d-none"><i class="bi bi-bar-chart"></i>No tasks with planned dates in this project. Add planned start / planned end to see them here.</div>
        <div id="ganttLoading" class="text-center text-muted py-4">
            <span class="spinner-border spinner-border-sm me-1"></span>Loading chart&hellip;
        </div>
        <div class="tt-gantt-scroll d-none" id="ganttWrap">
            <svg id="ganttChart"></svg>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center small text-muted">
        <span>Bars run from <code>planned_start</code> to <code>planned_end</code>. Sub-activities show as dependencies of their parent activity. Use the buttons to change the time scale.</span>
        <span id="ganttMeta" class="text-muted"></span>
    </div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/0.6.1/frappe-gantt.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/frappe-gantt/0.6.1/frappe-gantt.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const svg      = document.getElementById('ganttChart');
    const wrap     = document.getElementById('ganttWrap');
    const loading  = document.getElementById('ganttLoading');
    const emptyEl  = document.getElementById('ganttEmpty');
    const meta     = document.getElementById('ganttMeta');
    if (!svg || typeof Gantt === 'undefined') { if (loading) loading.classList.add('d-none'); return; }

    const projectId = <?= (int) $ganttProject ?>;
    if (projectId <= 0) { loading.classList.add('d-none'); return; }

    const csrfToken = <?= json_encode(csrf_token()) ?>;
    const saveBtn   = document.getElementById('ganttSaveBtn');
    const countPill = document.getElementById('ganttPendingCount');
    const pending   = new Map();
    const ymd = (d) => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dd}`;
    };
    const refreshBadge = () => {
        countPill.textContent = String(pending.size);
        if (pending.size > 0) saveBtn.classList.remove('d-none');
        else                  saveBtn.classList.add('d-none');
    };

    // Client-side cache: swapping between view modes doesn't re-fetch,
    // and the tasks array survives page-internal state changes.
    let gantt = null;
    let cachedTasks = null;
    let cachedView  = 'Day';

    const renderMode = (mode) => {
        if (!gantt) return;
        document.querySelectorAll('[data-gantt-mode]').forEach(b => b.classList.toggle('active', b.getAttribute('data-gantt-mode') === mode));
        gantt.change_view_mode(mode);
    };

    fetch('/task_tracker_ajax_gantt.php?project=' + projectId, {credentials: 'same-origin'})
        .then(r => r.json())
        .then(json => {
            loading.classList.add('d-none');
            if (!json.ok) { emptyEl.classList.remove('d-none'); emptyEl.textContent = 'Gantt load failed: ' + (json.error || 'unknown'); return; }
            if (!json.tasks || json.tasks.length === 0) { emptyEl.classList.remove('d-none'); return; }
            cachedTasks = json.tasks;
            cachedView  = json.suggested_view || 'Day';
            meta.textContent = json.count + ' task' + (json.count === 1 ? '' : 's')
                + (json.span_days ? ' · span ' + json.span_days + ' day' + (json.span_days === 1 ? '' : 's') : '')
                + ' · view ' + cachedView;
            wrap.classList.remove('d-none');
            gantt = new Gantt(svg, cachedTasks, {
                view_mode: cachedView, bar_height: 20, padding: 18,
                on_date_change: function (task, start, end) {
                    const inclEnd = new Date(end.getTime() - 24 * 3600 * 1000);
                    const id = String(task.id || '').replace(/^t/, '');
                    const numId = parseInt(id, 10);
                    if (!Number.isFinite(numId) || numId <= 0) return;
                    pending.set(numId, { task_id: numId, planned_start: ymd(start), planned_end: ymd(inclEnd) });
                    refreshBadge();
                },
            });
            // Reflect the chosen default in the button row.
            renderMode(cachedView);
        })
        .catch(err => {
            loading.classList.add('d-none');
            emptyEl.classList.remove('d-none');
            emptyEl.innerHTML = '<i class="bi bi-exclamation-triangle"></i>Gantt load failed: ' + (err && err.message ? err.message : String(err));
        });

    document.querySelectorAll('[data-gantt-mode]').forEach(btn => {
        btn.addEventListener('click', () => renderMode(btn.getAttribute('data-gantt-mode')));
    });

    saveBtn.addEventListener('click', async () => {
        if (pending.size === 0) return;
        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        let i = 0;
        pending.forEach(row => {
            body.set(`updates[${i}][task_id]`,       String(row.task_id));
            body.set(`updates[${i}][planned_start]`, row.planned_start);
            body.set(`updates[${i}][planned_end]`,   row.planned_end);
            i++;
        });
        saveBtn.disabled = true;
        try {
            const res  = await fetch('/task_tracker_ajax_dates.php', { method: 'POST', body });
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'Update failed.');
            const parts = [`Saved ${json.updated} task${json.updated === 1 ? '' : 's'}`];
            if (json.skipped) parts.push(`${json.skipped} skipped`);
            if (Array.isArray(json.errors) && json.errors.length) parts.push('with warnings:\n' + json.errors.join('\n'));
            alert(parts.join(' · '));
            pending.clear();
            refreshBadge();
        } catch (e) {
            alert('Save failed: ' + (e.message || e));
        } finally {
            saveBtn.disabled = false;
        }
    });
});
</script>

<?php endif; ?>

<style>
.tt-row-project    td { background: #f8fafc; }
.tt-row-activity   td { background: #ffffff; }
.tt-row-sub        td { background: #fbfcfe; }
.tt-status-tree .tt-toggle { line-height: 1; }

/* Persistent horizontal scrollbar under the Gantt chart, matching the
   marked spot in the screenshot. overflow-x:scroll (not auto) forces
   the track to stay visible even on macOS where auto scrollbars hide
   until hover — otherwise the chart's overflowing right edge would be
   invisible to the operator. */
.tt-gantt-scroll {
    overflow-x: scroll;
    overflow-y: hidden;
    max-width: 100%;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 4px;
}
.tt-gantt-scroll::-webkit-scrollbar { height: 12px; }
.tt-gantt-scroll::-webkit-scrollbar-thumb {
    background: #cbd5e1; border-radius: 6px;
}
.tt-gantt-scroll::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
.tt-gantt-scroll::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 6px; }
/* Let the SVG take its natural width so it can actually overflow the
   scroll container — Frappe-Gantt sets an explicit width attribute
   based on the timeline length. */
.tt-gantt-scroll > svg { display: block; }
</style>

<?php render_footer(); ?>
