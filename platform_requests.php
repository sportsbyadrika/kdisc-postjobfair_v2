<?php
/**
 * Platform Requests · Inbox.
 *
 * Flat list of every request raised by the LOI team. Filterable by
 * status, priority and reason. Each row links through to
 * loi_request_view.php for the WhatsApp-style chat + status update.
 *
 * Access: any user with the LOI module role (sees their own + any
 * they can act on) or the platform_requests role. The status-change
 * dropdown is gated separately on the chat page.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_requests_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_requests_bootstrap();

if (!loi_requests_can_participate($viewerId, $viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">You do not have access to the Platform Requests inbox.</div>';
    render_footer(); exit;
}
$isPlatformAdmin = loi_requests_is_platform_admin($viewer);

$fStatus   = (string) ($_GET['status']   ?? 'open');
$fPriority = (string) ($_GET['priority'] ?? '');
$fReason   = (int)    ($_GET['reason']   ?? 0);
$fScope    = (string) ($_GET['scope']    ?? ($isPlatformAdmin ? 'all' : 'mine'));

$sql = "SELECT r.*, e.name AS employer_name, rr.name AS reason_name,
        u.name AS raised_by_name,
        (SELECT COUNT(*) FROM loi_request_message WHERE request_id = r.id) AS msg_count,
        (SELECT MAX(created_at) FROM loi_request_message WHERE request_id = r.id) AS last_msg_at
    FROM loi_request r
    INNER JOIN loi_employer e ON e.id = r.employer_id
    LEFT JOIN loi_request_reason rr ON rr.id = r.reason_id
    LEFT JOIN users u ON u.id = r.created_by
    WHERE 1=1";
$params = [];
if ($fStatus === 'open')         { $sql .= " AND r.status IN ('open','in_progress')"; }
elseif ($fStatus === 'resolved') { $sql .= " AND r.status = 'resolved'"; }
elseif ($fStatus === 'closed')   { $sql .= " AND r.status = 'closed'"; }
if (in_array($fPriority, LOI_REQUEST_PRIORITIES, true)) { $sql .= ' AND r.priority = ?'; $params[] = $fPriority; }
if ($fReason > 0) { $sql .= ' AND r.reason_id = ?'; $params[] = $fReason; }
if ($fScope === 'mine') { $sql .= ' AND r.created_by = ?'; $params[] = $viewerId; }

$sql .= " ORDER BY FIELD(r.priority, 'urgent','critical','medium','low'),
         FIELD(r.status, 'open','in_progress','resolved','closed'),
         r.updated_at DESC";
$rows = [];
try {
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (Throwable $e) { /* empty */ }

$reasons = loi_request_reason_list(false);

render_header('Platform Requests · Inbox', ['main_container_class' => 'container-xl']);
render_page_header('Platform Requests · Inbox', [
    'icon'     => 'bi-inbox',
    'subtitle' => $isPlatformAdmin
        ? 'Every request raised by the LOI team. Click a row to open the chat and update the status.'
        : 'Requests you raised or can act on. Click a row to open the chat.',
    'actions'  => ($isPlatformAdmin ? '<a class="btn btn-light" href="/loi_request_reasons.php"><i class="bi bi-tags me-1"></i>Reason master</a>' : ''),
]);
?>

<form method="get" class="filter-bar">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <?php foreach (['open' => 'Open (open + in-progress)', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'] as $k => $v): ?>
                    <option value="<?= esc($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Priority</label>
            <select class="form-select" name="priority">
                <option value="">All</option>
                <?php foreach (LOI_REQUEST_PRIORITIES as $p): ?>
                    <option value="<?= esc($p) ?>" <?= $fPriority === $p ? 'selected' : '' ?>><?= esc(ucfirst($p)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Reason</label>
            <select class="form-select" name="reason">
                <option value="0">All</option>
                <?php foreach ($reasons as $r): if (!$r['is_active'] && $fReason !== (int) $r['id']) continue; ?>
                    <option value="<?= (int) $r['id'] ?>" <?= $fReason === (int) $r['id'] ? 'selected' : '' ?>><?= esc((string) $r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Scope</label>
            <select class="form-select" name="scope">
                <option value="all"  <?= $fScope === 'all'  ? 'selected' : '' ?>>Everyone</option>
                <option value="mine" <?= $fScope === 'mine' ? 'selected' : '' ?>>Raised by me</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            <a class="btn btn-light" href="/platform_requests.php">Reset</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-inbox text-primary me-1"></i>Requests</span>
        <span class="status-chip status-info"><?= number_format(count($rows)) ?> row<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>Employer</th>
                <th>Reason</th>
                <th>Priority</th>
                <th>Status</th>
                <th>Raised by</th>
                <th>Last activity</th>
                <th class="text-center">Msgs</th>
                <th class="text-end">Open</th>
            </tr></thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No requests match the current filters.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="fw-semibold">
                            <a href="/loi_employer_view.php?id=<?= (int) $r['employer_id'] ?>" class="text-decoration-none"><?= esc((string) $r['employer_name']) ?></a>
                        </td>
                        <td class="small"><?= esc((string) ($r['reason_name'] ?? '—')) ?></td>
                        <td><span class="badge text-bg-<?= esc(loi_request_priority_tone((string) $r['priority'])) ?>"><?= esc(ucfirst((string) $r['priority'])) ?></span></td>
                        <td><span class="badge text-bg-<?= esc(loi_request_status_tone((string) $r['status'])) ?>"><?= esc(loi_request_status_label((string) $r['status'])) ?></span></td>
                        <td class="small"><?= esc((string) ($r['raised_by_name'] ?? '—')) ?><div class="text-muted"><?= esc(date('d/m/Y H:i', strtotime((string) $r['created_at']))) ?></div></td>
                        <td class="small text-muted"><?= $r['last_msg_at'] ? esc(date('d/m/Y H:i', strtotime((string) $r['last_msg_at']))) : '—' ?></td>
                        <td class="text-center"><?= (int) ($r['msg_count'] ?? 0) ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/loi_request_view.php?id=<?= (int) $r['id'] ?>"><i class="bi bi-chat-left-text me-1"></i>Open</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
