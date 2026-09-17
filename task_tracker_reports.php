<?php
/**
 * Task Tracker · Reports.
 *
 * Filterable task export. The page presents:
 *   - A filter bar (project · status · type · primary officer ·
 *     planned-end date range).
 *   - A preview table showing up to the first 200 rows that would land
 *     in the file — the operator can eyeball the shape before
 *     committing to a download.
 *   - A "Download .xlsx" button that streams every matching task in
 *     the same order the preview shows.
 *
 * Access:
 *   - require_task_tracker_access() gates the whole page, so any user
 *     with a seat (or admin role) can see it.
 *   - Non-admins are silently scoped to seats they hold — the same
 *     rule My Work uses.
 *
 * The XLSX writer is in-repo (includes/xlsx_writer.php) so this
 * report has no Composer / native dependency.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_once __DIR__ . '/includes/xlsx_writer.php';
require_task_tracker_access();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];
$canManage = is_manage_admin($viewer);
$scope     = get_user_scope($viewerId);

$projects = db()->query('SELECT id, code, name FROM project
    WHERE office_id = ' . (int) TASK_TRACKER_OFFICE_ID . '
    ORDER BY name ASC')->fetchAll();
$statuses = db()->query('SELECT id, name FROM task_status WHERE is_active = 1 ORDER BY sort_order ASC')->fetchAll();
$officers = db()->query("SELECT DISTINCT u.id, u.name FROM users u
    INNER JOIN office_hierarchy_officer_history h ON h.officer_id = u.id AND h.unassigned_at IS NULL
    WHERE u.is_active = 1
    ORDER BY u.name ASC")->fetchAll();

$filterProject = (int) ($_GET['project_id'] ?? 0);
$filterStatus  = (int) ($_GET['status_id']  ?? 0);
$filterType    = (string) ($_GET['type']    ?? '');
$filterOfficer = (int) ($_GET['officer_id'] ?? 0);
$filterFrom    = trim((string) ($_GET['planned_from'] ?? ''));
$filterTo      = trim((string) ($_GET['planned_to']   ?? ''));

$where  = ['t.is_active = 1', 'p.office_id = ' . (int) TASK_TRACKER_OFFICE_ID];
$params = [];
if ($filterProject > 0) { $where[] = 't.project_id = ?';  $params[] = $filterProject; }
if ($filterStatus  > 0) { $where[] = 't.status_id  = ?';  $params[] = $filterStatus; }
if ($filterType === 'activity')      { $where[] = '(t.parent_id IS NULL OR t.parent_id = 0)'; }
elseif ($filterType === 'sub')       { $where[] = '(t.parent_id IS NOT NULL AND t.parent_id <> 0)'; }
if ($filterFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) { $where[] = 't.planned_end >= ?'; $params[] = $filterFrom; }
if ($filterTo   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo))   { $where[] = 't.planned_end <= ?'; $params[] = $filterTo; }

if ($filterOfficer > 0) {
    $where[] = "t.id IN (SELECT ta.task_id FROM task_assignment ta
        INNER JOIN office_hierarchy_officer_history h ON h.node_id = ta.seat_id AND h.unassigned_at IS NULL
        WHERE h.officer_id = ? AND ta.role = 'primary')";
    $params[] = $filterOfficer;
}

// Non-admin viewers see only tasks whose primary seat is inside
// their scope — the same rule My Work uses.
if (!$canManage && !in_array($scope['level'], ['admin', 'office_head'], true)) {
    $seatIds = $scope['seat_ids'];
    if ($seatIds === []) { $where[] = '1=0'; }
    else {
        $ph = implode(',', array_fill(0, count($seatIds), '?'));
        $where[] = "t.id IN (SELECT task_id FROM task_assignment WHERE seat_id IN ($ph))";
        foreach ($seatIds as $sid) $params[] = $sid;
    }
}

$sql = "SELECT
        t.id, t.task_number, t.title, t.description, t.target, t.priority,
        t.planned_start, t.planned_end, t.actual_start, t.actual_end,
        t.created_at, t.updated_at,
        t.parent_id,
        p.code AS project_code, p.name AS project_name,
        s.name AS status_name, s.is_terminal,
        pt.title AS parent_title, pt.task_number AS parent_task_number,
        (SELECT n.name FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            WHERE ta.task_id = t.id AND ta.role = 'primary' LIMIT 1) AS primary_seat,
        (SELECT u.name FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            LEFT JOIN office_hierarchy_officer_history h ON h.node_id = n.id AND h.unassigned_at IS NULL
            LEFT JOIN users u ON u.id = h.officer_id
            WHERE ta.task_id = t.id AND ta.role = 'primary' LIMIT 1) AS primary_officer,
        (SELECT GROUP_CONCAT(n.name ORDER BY n.name SEPARATOR ', ')
            FROM task_assignment ta INNER JOIN office_hierarchy_nodes n ON n.id = ta.seat_id
            WHERE ta.task_id = t.id AND ta.role = 'secondary') AS secondary_seats,
        (SELECT u.name FROM users u WHERE u.id = t.created_by) AS created_by_name
    FROM task t
    INNER JOIN project p ON p.id = t.project_id
    LEFT JOIN task_status s ON s.id = t.status_id
    LEFT JOIN task pt ON pt.id = t.parent_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.code ASC, t.task_number ASC";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$fmtDate = static function (?string $s): string {
    $s = substr((string) $s, 0, 10);
    return $s === '' ? '' : date('d/m/Y', strtotime($s));
};

$header = [
    'Task Key', 'Type', 'Parent', 'Title', 'Description', 'Target',
    'Project', 'Status', 'Priority',
    'Primary Seat', 'Primary Officer', 'Secondary Seats',
    'Planned Start', 'Planned End', 'Actual Start', 'Actual End',
    'Created By', 'Created At',
];

$build = static function () use ($rows, $fmtDate, $header) {
    $out = [];
    foreach ($rows as $r) {
        $isSub = !empty($r['parent_id']);
        $out[] = [
            (string) $r['project_code'] . '-' . (int) $r['task_number'],
            $isSub ? 'Sub-activity' : 'Activity',
            $isSub && !empty($r['parent_title'])
                ? $r['project_code'] . '-' . (int) $r['parent_task_number'] . ' · ' . (string) $r['parent_title']
                : '',
            (string) ($r['title'] ?? ''),
            (string) ($r['description'] ?? ''),
            (string) ($r['target'] ?? ''),
            (string) ($r['project_name'] ?? ''),
            (string) ($r['status_name'] ?? ''),
            ucfirst((string) $r['priority']),
            (string) ($r['primary_seat']    ?? ''),
            (string) ($r['primary_officer'] ?? ''),
            (string) ($r['secondary_seats'] ?? ''),
            $fmtDate($r['planned_start'] ?? null),
            $fmtDate($r['planned_end']   ?? null),
            $fmtDate($r['actual_start']  ?? null),
            $fmtDate($r['actual_end']    ?? null),
            (string) ($r['created_by_name'] ?? ''),
            substr((string) ($r['created_at'] ?? ''), 0, 19),
        ];
    }
    return $out;
};

if (($_GET['format'] ?? '') === 'xlsx') {
    $filename = 'task_tracker_report_' . date('Ymd_His') . '.xlsx';
    xlsx_send($filename, 'Tasks', $header, $build());
}

$data = $build();
$previewLimit = 200;
$previewRows  = array_slice($data, 0, $previewLimit);

// Preserve current filters in the download URL so the button exports
// exactly what the operator sees on the preview.
$exportQuery = http_build_query(array_filter([
    'project_id'   => $filterProject > 0 ? $filterProject : null,
    'status_id'    => $filterStatus  > 0 ? $filterStatus  : null,
    'type'         => $filterType !== '' ? $filterType    : null,
    'officer_id'   => $filterOfficer > 0 ? $filterOfficer : null,
    'planned_from' => $filterFrom  !== '' ? $filterFrom   : null,
    'planned_to'   => $filterTo    !== '' ? $filterTo     : null,
    'format'       => 'xlsx',
], static fn($v) => $v !== null));

render_header('Task Tracker · Reports', ['main_container_class' => 'container-fluid']);
render_page_header('Task Tracker · Reports', [
    'icon' => 'bi-file-earmark-spreadsheet',
    'subtitle' => 'Filter tasks and download the result as an Excel file. The preview shows the first ' . $previewLimit . ' rows; the download contains every match.',
    'actions' => '<a class="btn btn-primary" href="/task_tracker_reports.php?' . esc($exportQuery) . '">
            <i class="bi bi-download me-1"></i>Download .xlsx
        </a>
        <a class="btn btn-light ms-2" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>All projects</a>',
]);
?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label" for="fProject">Project</label>
                <select class="form-select" id="fProject" name="project_id">
                    <option value="0">All projects</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $filterProject === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $p['code']) ?> · <?= esc((string) $p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="fStatus">Status</label>
                <select class="form-select" id="fStatus" name="status_id">
                    <option value="0">All statuses</option>
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $filterStatus === (int) $s['id'] ? 'selected' : '' ?>><?= esc((string) $s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="fType">Type</label>
                <select class="form-select" id="fType" name="type">
                    <option value=""          <?= $filterType === ''         ? 'selected' : '' ?>>All</option>
                    <option value="activity"  <?= $filterType === 'activity' ? 'selected' : '' ?>>Activity only</option>
                    <option value="sub"       <?= $filterType === 'sub'      ? 'selected' : '' ?>>Sub-activity only</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="fOfficer">Primary officer</label>
                <select class="form-select" id="fOfficer" name="officer_id">
                    <option value="0">Anyone</option>
                    <?php foreach ($officers as $o): ?>
                        <option value="<?= (int) $o['id'] ?>" <?= $filterOfficer === (int) $o['id'] ? 'selected' : '' ?>><?= esc((string) $o['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label" for="fFrom">Planned end from</label>
                        <input type="date" class="form-control" id="fFrom" name="planned_from" value="<?= esc($filterFrom) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="fTo">Planned end to</label>
                        <input type="date" class="form-control" id="fTo" name="planned_to" value="<?= esc($filterTo) ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted"><strong><?= number_format(count($data)) ?></strong> task(s) match your filter.</span>
        <div>
            <a class="btn btn-light" href="/task_tracker_reports.php">Clear</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul text-primary me-1"></i>Preview<?= count($data) > $previewLimit ? ' — first ' . $previewLimit . ' rows' : '' ?></span>
        <a class="btn btn-sm btn-success" href="/task_tracker_reports.php?<?= esc($exportQuery) ?>"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Download .xlsx</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <?php foreach ($header as $h): ?><th class="small text-uppercase text-muted"><?= esc($h) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($previewRows === []): ?>
                    <tr><td colspan="<?= count($header) ?>"><div class="empty-state"><i class="bi bi-inbox"></i>No tasks match this filter.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($previewRows as $r): ?>
                    <tr>
                        <?php foreach ($r as $cell): ?>
                            <td class="small"><?= esc((string) $cell) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        The download contains every matching task (<?= number_format(count($data)) ?> row<?= count($data) === 1 ? '' : 's' ?>). Dates are formatted DD/MM/YYYY to match the rest of the app.
    </div>
</div>

<?php render_footer(); ?>
