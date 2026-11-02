<?php
/**
 * Meetings list — every viewer sees the meetings they can access.
 *
 * View rights (any one applies):
 *   1. Creator of the meeting
 *   2. On the participant list (mandatory or optional)
 *   3. Named as responsible on any decision point (by user id)
 *   4. Holds a seat with a head-level responsibility whose scope
 *      covers any participant's seat
 *   5. Meetings-module admin, or admin-group role
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();

$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

// "Mine only" vs "Show all": the list defaults to meetings the viewer
// created, participates in, or is a co-owner of. A Meetings admin may
// flip the switch to see every meeting (?all=1), same shape as the
// Kanban's My/All toggle.
$showAll = $isAdminAll && ((string) ($_GET['all'] ?? '') === '1');

$filterQ    = trim((string) ($_GET['q']    ?? ''));
$filterDate = trim((string) ($_GET['date'] ?? ''));

$sql = 'SELECT m.*, u.name AS created_by_name FROM meeting m
    LEFT JOIN users u ON u.id = m.created_by
    WHERE m.is_active = 1';
$params = [];
if (!$showAll) {
    $vis = meetings_visible_filter_sql($viewerId);
    $sql .= ' AND ' . $vis['sql'];
    foreach ($vis['params'] as $p) $params[] = $p;
}
if ($filterQ !== '') {
    $sql .= ' AND (m.title LIKE ? OR m.purpose LIKE ? OR m.reference_no LIKE ?)';
    $like = '%' . $filterQ . '%';
    array_push($params, $like, $like, $like);
}
if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $sql .= ' AND m.meeting_date = ?';
    $params[] = $filterDate;
}
$sql .= ' ORDER BY m.meeting_date DESC, m.from_time DESC, m.id DESC LIMIT 500';

try {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $meetings = $stmt->fetchAll();
} catch (Throwable $e) {
    $meetings = [];
}

$totalToday = 0;
foreach ($meetings as $m) if ((string) $m['meeting_date'] === date('Y-m-d')) $totalToday++;

render_header('Meetings', ['main_container_class' => 'container-xl']);
render_page_header('Meetings', [
    'icon'     => 'bi-calendar2-week',
    'subtitle' => 'Meetings you created, are invited to, are responsible on, or have oversight over.',
    'actions'  => '<a class="btn btn-primary" href="/meeting_edit.php"><i class="bi bi-plus-lg me-1"></i>New meeting</a>
        <a class="btn btn-light ms-2" href="/contacts.php"><i class="bi bi-person-vcard me-1"></i>Contacts</a>'
        . ($isAdminAll ? '<a class="btn btn-light ms-2" href="/meeting_settings.php"><i class="bi bi-file-earmark-text me-1"></i>MoM settings</a>' : ''),
]);
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-primary h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100"><p class="stat-label">Meetings visible</p><p class="stat-value"><?= number_format(count($meetings)) ?></p></div>
                <span class="stat-icon-box tone-primary"><i class="bi bi-calendar2-week"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-info h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100"><p class="stat-label">Today</p><p class="stat-value"><?= number_format($totalToday) ?></p></div>
                <span class="stat-icon-box tone-info"><i class="bi bi-calendar-day"></i></span>
            </div>
        </div>
    </div>
</div>

<form method="get" class="filter-bar">
    <?php if ($showAll): ?><input type="hidden" name="all" value="1"><?php endif; ?>
    <div class="row g-3 align-items-end">
        <div class="col-6 col-md-4">
            <label class="form-label">Search</label>
            <input class="form-control" name="q" value="<?= esc($filterQ) ?>" placeholder="Title, reference or purpose">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Date</label>
            <input class="form-control" type="date" name="date" value="<?= esc($filterDate) ?>">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a class="btn btn-light" href="/meetings.php<?= $showAll ? '?all=1' : '' ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
            <i class="bi bi-calendar2-week text-primary me-1"></i>Meetings
            <?php if ($showAll): ?>
                <span class="badge text-bg-light border ms-2"><i class="bi bi-people me-1"></i>All meetings</span>
            <?php else: ?>
                <span class="badge text-bg-primary-subtle text-primary border ms-2"><i class="bi bi-person me-1"></i>My meetings only</span>
            <?php endif; ?>
        </span>
        <div class="d-flex align-items-center gap-3">
            <?php if ($isAdminAll): ?>
                <?php
                    // Preserve existing q/date filters when toggling.
                    $toggleQS = [];
                    if ($filterQ !== '')    $toggleQS['q']    = $filterQ;
                    if ($filterDate !== '') $toggleQS['date'] = $filterDate;
                    $onBase = '/meetings.php?' . http_build_query($toggleQS);
                    $onAll  = $onBase . ($toggleQS === [] ? '' : '&') . 'all=1';
                ?>
                <div class="form-check form-switch mb-0" title="Toggle between showing only your own meetings (created, participating, or co-owner) and every meeting.">
                    <input class="form-check-input" type="checkbox" role="switch" id="showAllMeetings" <?= $showAll ? 'checked' : '' ?>
                           onchange="window.location = this.checked ? <?= json_encode($onAll) ?> : <?= json_encode($onBase === '/meetings.php?' ? '/meetings.php' : $onBase) ?>;">
                    <label class="form-check-label small" for="showAllMeetings">Show all meetings</label>
                </div>
            <?php endif; ?>
            <span class="status-chip status-info"><?= number_format(count($meetings)) ?> row<?= count($meetings) === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Sl No</th>
                    <th>Reference</th>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Created by</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($meetings === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-inbox"></i>No meetings to show. Click "New meeting" to create one.</div></td></tr>
                <?php endif; ?>
                <?php $i = 1; foreach ($meetings as $m):
                    $status = meetings_effective_status($m);
                    $tone   = meetings_status_tone($status);
                    $isCreator = $viewerId === (int) $m['created_by'];
                    $canEditRow = $isAdminAll || $isCreator;
                ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td class="font-monospace small"><?= esc((string) $m['reference_no']) ?></td>
                        <td class="fw-semibold">
                            <a href="/meeting_view.php?id=<?= (int) $m['id'] ?>" class="text-decoration-none text-body"><?= esc((string) $m['title']) ?></a>
                        </td>
                        <td class="small"><?= esc(date('d/m/Y', strtotime((string) $m['meeting_date']))) ?></td>
                        <td class="small text-muted">
                            <?= esc(substr((string) $m['from_time'], 0, 5)) ?><?= empty($m['to_time']) ? '' : ' – ' . esc(substr((string) $m['to_time'], 0, 5)) ?>
                        </td>
                        <td class="small">
                            <?php if (!empty($m['location'])): ?><i class="bi bi-geo-alt me-1"></i><?= esc((string) $m['location']) ?><?php endif; ?>
                            <?php if (!empty($m['virtual_link'])): ?><div><i class="bi bi-camera-video me-1"></i><a href="<?= esc((string) $m['virtual_link']) ?>" target="_blank" rel="noopener">Virtual link</a></div><?php endif; ?>
                        </td>
                        <td><span class="badge text-bg-<?= esc($tone) ?>"><?= esc(ucfirst($status)) ?></span></td>
                        <td class="small"><?= esc((string) ($m['created_by_name'] ?? '')) ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-primary" href="/meeting_view.php?id=<?= (int) $m['id'] ?>" title="View"><i class="bi bi-eye"></i></a>
                                <a class="btn btn-sm btn-outline-success" href="/meeting_mom.php?id=<?= (int) $m['id'] ?>" target="_blank" title="MoM (printable)"><i class="bi bi-file-earmark-text"></i></a>
                                <?php if ($canEditRow): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="/meeting_edit.php?id=<?= (int) $m['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        Reference numbers follow <code>DIVISION/YEAR/NNN</code> and are allocated at create-time. Effective status auto-flips to "Completed" once the end time has passed unless someone marks it cancelled.
    </div>
</div>

<?php render_footer(); ?>
