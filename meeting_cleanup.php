<?php
/**
 * Administration · Meeting cleanup.
 *
 * Lets an Administrator permanently delete meetings that have not been
 * chairperson-approved — the "draft" row from create_draft, a half-
 * filled scheduled meeting, a cancelled one, anything with
 * approved_at IS NULL. Approved meetings never show up here (they're
 * part of the formal record; deactivate the status manually if you
 * really need to drop one).
 *
 * The delete wipes every sub-collection in a transaction and
 * deactivates any Own-Tasks that were spawned from the meeting's
 * decision points so a stale task doesn't linger in My Work.
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

// Admin-only: Administrator / DSM Admin / administration-module admin.
if (!is_manage_admin($viewer) && !user_can_admin_module($viewerId, 'administration')) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only an Administrator can clean up meetings.</div>';
    render_footer(); exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['meeting_cleanup_flash']) && is_array($_SESSION['meeting_cleanup_flash'])) {
    $flashMessage = (string) ($_SESSION['meeting_cleanup_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['meeting_cleanup_flash']['type'] ?? 'success');
    unset($_SESSION['meeting_cleanup_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['meeting_cleanup_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /meeting_cleanup.php');
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    csrf_check_or_die();
    $mid = (int) ($_POST['id'] ?? 0);
    if ($mid <= 0) $flashAndBack('Missing meeting id.', 'danger');

    // Re-check: refuse delete on an approved meeting even if someone
    // pokes at the form.
    $st = db()->prepare('SELECT reference_no, title, approved_at FROM meeting WHERE id = ? LIMIT 1');
    $st->execute([$mid]);
    $meeting = $st->fetch();
    if ($meeting === false) $flashAndBack('Meeting not found.', 'danger');
    if (!empty($meeting['approved_at'])) {
        $flashAndBack('Meeting ' . (string) $meeting['reference_no'] . ' is approved — cannot be deleted from this page.', 'danger');
    }

    $db = db();
    $db->query('START TRANSACTION');
    try {
        // Collect decision ids first so we can clean the N:M sub-table +
        // deactivate linked tasks.
        $decIds = [];
        $ds = $db->prepare('SELECT id FROM meeting_decision WHERE meeting_id = ?');
        $ds->execute([$mid]);
        foreach ($ds->fetchAll() as $r) $decIds[] = (int) $r['id'];

        // Deactivate any Own-Tasks tied to this meeting's decisions
        // BEFORE deleting the decision rows so the FK-less link isn't
        // left dangling.
        if ($decIds !== []) {
            $ph = implode(',', array_fill(0, count($decIds), '?'));
            try {
                $db->prepare("UPDATE task SET is_active = 0, updated_at = NOW(), updated_by = ?
                    WHERE meeting_decision_id IN ($ph)")
                    ->execute(array_merge([$viewerId], $decIds));
            } catch (Throwable $e) { /* task table or link column absent */ }
            // Decision responsibles.
            $db->prepare("DELETE FROM meeting_decision_responsible WHERE decision_id IN ($ph)")->execute($decIds);
        }

        // Agenda leads keyed on agenda_id — grab agenda ids too.
        $agIds = [];
        $as = $db->prepare('SELECT id FROM meeting_agenda WHERE meeting_id = ?');
        $as->execute([$mid]);
        foreach ($as->fetchAll() as $r) $agIds[] = (int) $r['id'];
        if ($agIds !== []) {
            $ph = implode(',', array_fill(0, count($agIds), '?'));
            try { $db->prepare("DELETE FROM meeting_agenda_lead WHERE agenda_id IN ($ph)")->execute($agIds); } catch (Throwable $e) { /* table absent */ }
        }

        // Finally the top-level sub-collections + the meeting row.
        foreach (['meeting_participant', 'meeting_agenda', 'meeting_decision', 'meeting_next_agenda', 'meeting_url'] as $t) {
            try { $db->prepare("DELETE FROM $t WHERE meeting_id = ?")->execute([$mid]); } catch (Throwable $e) { /* table absent */ }
        }
        $db->prepare('DELETE FROM meeting WHERE id = ?')->execute([$mid]);

        $db->query('COMMIT');
        $flashAndBack('Deleted meeting ' . (string) $meeting['reference_no'] . ' — ' . (string) $meeting['title'] . '.');
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
        $flashAndBack('Delete failed: ' . $e->getMessage(), 'danger');
    }
}

// Filters.
$fStatus = (string) ($_GET['status'] ?? '');
$fQuery  = trim((string) ($_GET['q'] ?? ''));
$fDays   = (int)    ($_GET['days'] ?? 0); // 0 = any age

$sql = 'SELECT m.*, u.name AS created_by_name, d.name AS division_name,
        (SELECT COUNT(*) FROM meeting_participant  WHERE meeting_id = m.id) AS p_count,
        (SELECT COUNT(*) FROM meeting_agenda       WHERE meeting_id = m.id) AS a_count,
        (SELECT COUNT(*) FROM meeting_decision     WHERE meeting_id = m.id) AS d_count,
        (SELECT COUNT(*) FROM meeting_next_agenda  WHERE meeting_id = m.id) AS n_count
    FROM meeting m
    LEFT JOIN users u ON u.id = m.created_by
    LEFT JOIN office_hierarchy_nodes d ON d.id = m.division_id
    WHERE m.approved_at IS NULL';
$params = [];
if ($fStatus !== '' && in_array($fStatus, ['scheduled','inprogress','completed','cancelled'], true)) {
    $sql .= ' AND m.status = ?'; $params[] = $fStatus;
}
if ($fDays > 0) {
    $sql .= ' AND m.created_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
    $params[] = $fDays;
}
if ($fQuery !== '') {
    $sql .= ' AND (m.title LIKE ? OR m.reference_no LIKE ?)';
    array_push($params, '%' . $fQuery . '%', '%' . $fQuery . '%');
}
$sql .= ' ORDER BY m.created_at DESC';
$rows = [];
try {
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $e) { /* empty */ }

$countsByStatus = ['scheduled' => 0, 'inprogress' => 0, 'completed' => 0, 'cancelled' => 0];
try {
    foreach (db()->query("SELECT status, COUNT(*) AS n FROM meeting WHERE approved_at IS NULL GROUP BY status")->fetchAll() as $r) {
        $countsByStatus[(string) $r['status']] = (int) $r['n'];
    }
} catch (Throwable $e) { /* empty */ }

$draftCount = 0;
try {
    $draftCount = (int) db()->query("SELECT COUNT(*) FROM meeting WHERE approved_at IS NULL AND title = 'Untitled meeting'")->fetchColumn();
} catch (Throwable $e) { /* ignore */ }

$fmtDate = static fn($s) => $s === null || $s === '' ? '—' : date('d/m/Y', strtotime((string) $s));
$fmtDT   = static fn($s) => $s === null || $s === '' ? '—' : date('d/m/Y H:i', strtotime((string) $s));

render_header('Administration · Meeting cleanup', ['main_container_class' => 'container-xl']);
render_page_header('Administration · Meeting cleanup', [
    'icon'     => 'bi-trash',
    'subtitle' => 'Permanently delete meetings that have not been approved by the chairperson. Drafts, half-filled scheduled meetings, cancelled meetings — everything with no approval timestamp is listed below. Approved meetings never appear here.',
    'actions'  => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="row g-2 mb-3">
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-warning h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100"><p class="stat-label">Draft stubs</p><p class="stat-value"><?= number_format((int) $draftCount) ?></p></div>
                <span class="stat-icon-box tone-warning"><i class="bi bi-file-earmark"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-info h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100"><p class="stat-label">Scheduled (unapproved)</p><p class="stat-value"><?= number_format((int) $countsByStatus['scheduled']) ?></p></div>
                <span class="stat-icon-box tone-info"><i class="bi bi-calendar-event"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-primary h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100"><p class="stat-label">Completed (unapproved)</p><p class="stat-value"><?= number_format((int) $countsByStatus['completed']) ?></p></div>
                <span class="stat-icon-box tone-primary"><i class="bi bi-hourglass-split"></i></span>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-stat accent-secondary h-100">
            <div class="card-body d-flex align-items-start justify-content-between gap-2">
                <div class="w-100"><p class="stat-label">Cancelled</p><p class="stat-value"><?= number_format((int) $countsByStatus['cancelled']) ?></p></div>
                <span class="stat-icon-box tone-secondary"><i class="bi bi-x-circle"></i></span>
            </div>
        </div>
    </div>
</div>

<form method="get" class="filter-bar">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input class="form-control" name="q" value="<?= esc($fQuery) ?>" placeholder="Title or reference">
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <?php foreach (['scheduled' => 'Scheduled', 'inprogress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'] as $k => $v): ?>
                    <option value="<?= esc($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Created more than ... days ago</label>
            <select class="form-select" name="days">
                <?php foreach ([0 => 'Any age', 1 => '1 day', 7 => '7 days', 14 => '14 days', 30 => '30 days', 90 => '90 days'] as $k => $v): ?>
                    <option value="<?= (int) $k ?>" <?= $fDays === (int) $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a class="btn btn-light" href="/meeting_cleanup.php">Reset</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-trash text-danger me-1"></i>Unapproved meetings</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Title</th>
                    <th>Division</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Created by</th>
                    <th>Created</th>
                    <th class="text-center" title="Participants / Agenda / Decision / Next-agenda">P/A/D/N</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9"><div class="empty-state"><i class="bi bi-check2-circle"></i>No unapproved meetings match the current filters — nothing to clean up.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $status = meetings_effective_status($r);
                    $tone   = meetings_status_tone($status);
                    $isDraft = ((string) $r['title']) === 'Untitled meeting';
                ?>
                    <tr>
                        <td class="font-monospace small"><?= esc((string) $r['reference_no']) ?></td>
                        <td>
                            <a href="/meeting_view.php?id=<?= (int) $r['id'] ?>" class="text-decoration-none fw-semibold"><?= esc((string) $r['title']) ?></a>
                            <?php if ($isDraft): ?><span class="badge text-bg-warning ms-1">Draft</span><?php endif; ?>
                        </td>
                        <td class="small"><?= esc((string) ($r['division_name'] ?? '—')) ?></td>
                        <td><span class="badge text-bg-<?= esc($tone) ?>"><?= esc(ucfirst($status)) ?></span></td>
                        <td class="small"><?= esc($fmtDate($r['meeting_date'] ?? '')) ?></td>
                        <td class="small"><?= esc((string) ($r['created_by_name'] ?? '—')) ?></td>
                        <td class="small text-muted"><?= esc($fmtDT($r['created_at'] ?? '')) ?></td>
                        <td class="text-center small"><?= (int) $r['p_count'] ?> / <?= (int) $r['a_count'] ?> / <?= (int) $r['d_count'] ?> / <?= (int) $r['n_count'] ?></td>
                        <td class="text-end">
                            <div class="d-inline-flex gap-1">
                                <a class="btn btn-sm btn-outline-primary" href="/meeting_view.php?id=<?= (int) $r['id'] ?>" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
                                <a class="btn btn-sm btn-outline-primary" href="/meeting_edit.php?id=<?= (int) $r['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Permanently delete meeting <?= esc((string) $r['reference_no']) ?> — this wipes participants, agenda, decisions, next-agenda, URLs and any Own-Tasks linked to its decisions. Cannot be undone.');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="Delete permanently"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="small text-muted mt-3">
    <i class="bi bi-info-circle me-1"></i>
    Delete is permanent — it drops the meeting row, all its participants, agenda, decisions and attachments, and deactivates any Own-Tasks tied to its decision points. Approved meetings are intentionally not deletable from this page; they're part of the formal record. If you really need to drop one, remove its approval directly in the database first.
</div>

<?php render_footer(); ?>
