<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();
if (is_district_user($user) && false) {
    // District users can also view notifications targeted to them; gate left
    // open so the visibility filter below handles scoping.
}

// Self-migrations for the new notification fields on the activities table.
$activitiesCols = array_column(db()->query("SHOW COLUMNS FROM activities")->fetchAll(), 'Field');
if (!in_array('to_whom', $activitiesCols, true)) {
    db()->query("ALTER TABLE activities ADD COLUMN to_whom ENUM('State CRM','State DSM','District User') NULL AFTER owner_user_id");
}
if (!in_array('read_by', $activitiesCols, true)) {
    db()->query("ALTER TABLE activities ADD COLUMN read_by ENUM('any','specific') NOT NULL DEFAULT 'any' AFTER to_whom");
}
if (!in_array('target_user_id', $activitiesCols, true)) {
    db()->query("ALTER TABLE activities ADD COLUMN target_user_id INT NULL AFTER read_by");
}

// Self-migrations for the Seen + Reply feature. CREATE TABLE IF NOT EXISTS is
// idempotent, so this is safe on repeated requests.
db()->query("CREATE TABLE IF NOT EXISTS activity_replies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activity_id INT NOT NULL,
    user_id INT NOT NULL,
    reply_text TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_replies_activity (activity_id),
    INDEX idx_activity_replies_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
db()->query("CREATE TABLE IF NOT EXISTS activity_seen (
    activity_id INT NOT NULL,
    user_id INT NOT NULL,
    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id, user_id),
    INDEX idx_activity_seen_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$rolesByToWhom = [
    'State CRM' => 'crm_member',
    'State DSM' => 'state_dsm',
    'District User' => 'district_user',
];

function notifications_normalise_district_list(?string $value): array
{
    $value = trim((string) $value);
    if ($value === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn($v) => $v !== ''));
}

function notifications_user_in_target_audience(array $user, ?string $toWhom, ?int $targetUserId, string $readBy, array $rolesByToWhom): bool
{
    if ($toWhom === null || $toWhom === '') return true;
    $targetRole = $rolesByToWhom[$toWhom] ?? null;
    if ($targetRole === null) return true;
    if (($user['role'] ?? '') !== $targetRole) return false;
    if ($readBy === 'specific') {
        return $targetUserId === (int) ($user['id'] ?? 0);
    }
    return true; // "any" - any user in that role.
}

function notifications_can_view(int $activityId, array $user, array $rolesByToWhom): bool
{
    $s = db()->prepare('SELECT owner_user_id, to_whom, read_by, target_user_id FROM activities WHERE id = ?');
    $s->execute([$activityId]);
    $row = $s->fetch();
    if (!$row) return false;
    if ((int) $row['owner_user_id'] === (int) ($user['id'] ?? 0)) return true;
    $roleToWhom = array_search($user['role'] ?? '', $rolesByToWhom, true);
    if ($roleToWhom === false) return false;
    if ((string) $row['to_whom'] !== (string) $roleToWhom) return false;
    if ($row['read_by'] === 'any') return true;
    return (int) $row['target_user_id'] === (int) ($user['id'] ?? 0);
}

// AJAX: return notification details + seen list + replies as JSON. Marks the
// notification as seen for the current user (unless they own it).
if (($_GET['details_id'] ?? '') !== '') {
    $activityId = (int) $_GET['details_id'];
    if ($activityId <= 0 || !notifications_can_view($activityId, $user, $rolesByToWhom)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    $s = db()->prepare('SELECT a.*, u.name AS owner_name, t.name AS target_user_name FROM activities a JOIN users u ON u.id = a.owner_user_id LEFT JOIN users t ON t.id = a.target_user_id WHERE a.id = ?');
    $s->execute([$activityId]);
    $note = $s->fetch();
    // Insert seen for non-owner viewers (or refresh seen_at to track "last
    // viewed" so new replies after that point can be detected).
    if ($note && (int) $note['owner_user_id'] !== (int) $user['id']) {
        $ins = db()->prepare('INSERT INTO activity_seen (activity_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE seen_at = CURRENT_TIMESTAMP');
        $ins->execute([$activityId, (int) $user['id']]);
    }
    $seenStmt = db()->prepare('SELECT s.user_id, s.seen_at, u.name FROM activity_seen s JOIN users u ON u.id = s.user_id WHERE s.activity_id = ? ORDER BY s.seen_at ASC');
    $seenStmt->execute([$activityId]);
    $seen = $seenStmt->fetchAll();
    $repStmt = db()->prepare('SELECT r.id, r.user_id, r.reply_text, r.created_at, u.name FROM activity_replies r JOIN users u ON u.id = r.user_id WHERE r.activity_id = ? ORDER BY r.created_at ASC, r.id ASC');
    $repStmt->execute([$activityId]);
    $replies = $repStmt->fetchAll();
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'notification' => $note,
        'seen' => $seen,
        'replies' => $replies,
        'current_user_id' => (int) $user['id'],
    ]);
    exit;
}

// POST: add a reply to a notification.
if (is_post() && ($_POST['action'] ?? '') === 'add_reply') {
    $activityId = (int) ($_POST['activity_id'] ?? 0);
    $replyText = trim((string) ($_POST['reply_text'] ?? ''));
    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    if ($activityId <= 0 || $replyText === '' || !notifications_can_view($activityId, $user, $rolesByToWhom)) {
        if ($isAjax) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid reply']);
            exit;
        }
    } else {
        $ins = db()->prepare('INSERT INTO activity_replies (activity_id, user_id, reply_text) VALUES (?, ?, ?)');
        $ins->execute([$activityId, (int) $user['id'], $replyText]);
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    }
}

$flash = null;

if (is_post()) {
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);
    $module = $_POST['module_name'] ?? 'crm';
    $title = trim($_POST['title'] ?? '');
    $details = trim($_POST['details'] ?? '');
    $status = $_POST['status'] ?? 'open';
    $active = isset($_POST['active_status']) ? 1 : 0;
    $toWhom = trim((string) ($_POST['to_whom'] ?? ''));
    if ($toWhom !== '' && !array_key_exists($toWhom, $rolesByToWhom)) {
        $toWhom = '';
    }
    $readBy = in_array($_POST['read_by'] ?? 'any', ['any', 'specific'], true) ? $_POST['read_by'] : 'any';
    $targetUserId = (int) ($_POST['target_user_id'] ?? 0);
    if ($readBy !== 'specific' || $targetUserId <= 0) {
        $targetUserId = null;
    }

    if ($action === 'add') {
        $stmt = db()->prepare('INSERT INTO activities (module_name, title, details, status, owner_user_id, to_whom, read_by, target_user_id, active_status, created_at, updated_at, modified_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)');
        $stmt->execute([$module, $title, $details, $status, $user['id'], $toWhom !== '' ? $toWhom : null, $readBy, $targetUserId, $active, $user['id']]);
        $flash = 'Notification added.';
    }
    if ($action === 'edit') {
        $stmt = db()->prepare('UPDATE activities SET module_name=?, title=?, details=?, status=?, to_whom=?, read_by=?, target_user_id=?, active_status=?, updated_at=NOW(), modified_by=? WHERE id=?');
        $stmt->execute([$module, $title, $details, $status, $toWhom !== '' ? $toWhom : null, $readBy, $targetUserId, $active, $user['id'], $id]);
        $flash = 'Notification updated.';
    }
    if ($action === 'deactivate') {
        $stmt = db()->prepare('UPDATE activities SET active_status=0, updated_at=NOW(), modified_by=? WHERE id=?');
        $stmt->execute([$user['id'], $id]);
        $flash = 'Notification deactivated.';
    }
}

$filterModule = $_GET['module_name'] ?? '';
$filterStatus = $_GET['status'] ?? '';

$sql = 'SELECT a.*, u.name AS owner_name, t.name AS target_user_name,
    COALESCE(s.seen_count, 0) AS seen_count,
    COALESCE(r.reply_count, 0) AS reply_count
    FROM activities a
    JOIN users u ON u.id = a.owner_user_id
    LEFT JOIN users t ON t.id = a.target_user_id
    LEFT JOIN (SELECT activity_id, COUNT(*) AS seen_count FROM activity_seen GROUP BY activity_id) s ON s.activity_id = a.id
    LEFT JOIN (SELECT activity_id, COUNT(*) AS reply_count FROM activity_replies GROUP BY activity_id) r ON r.activity_id = a.id
    WHERE 1=1';
$params = [];

// Strict audience visibility. Everyone (including administrators and State
// DSM) sees only:
//   - notifications they own (so the sender always sees their own thread)
//   - notifications addressed to their role group with read_by = 'any'
//   - notifications specifically targeted at their user id
// Anything outside this is hidden, so specific notifications stay private to
// the named recipient and group messages stay inside that group.
$role = (string) ($user['role'] ?? '');
$uid = (int) ($user['id'] ?? 0);
$roleToWhom = array_search($role, $rolesByToWhom, true) ?: '';
$sql .= ' AND (a.owner_user_id = ? OR (a.to_whom = ? AND (a.read_by = ' . "'any'" . ' OR a.target_user_id = ?)))';
$params[] = $uid;
$params[] = $roleToWhom;
$params[] = $uid;
if ($filterModule !== '') {
    $sql .= ' AND a.module_name = ?';
    $params[] = $filterModule;
}
if ($filterStatus !== '') {
    $sql .= ' AND a.status = ?';
    $params[] = $filterStatus;
}
$sql .= ' ORDER BY a.id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Build user picker grouped by target role.
$usersByRoleStmt = db()->query("SELECT id, name, role, assigned_districts FROM users WHERE active_status = 1 ORDER BY name ASC");
$usersByRole = ['State CRM' => [], 'State DSM' => [], 'District User' => []];
foreach ($usersByRoleStmt->fetchAll() as $u) {
    foreach ($rolesByToWhom as $label => $r) {
        if ($u['role'] === $r) {
            $usersByRole[$label][] = $u;
        }
    }
}

render_header('Notifications');
render_page_header('Notifications', [
    'icon' => 'bi-bell',
    'subtitle' => 'Escalation and Notifications.',
    'actions' => '<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#activityModal" onclick="openAddModal()"><i class="bi bi-plus-lg me-1"></i>Add Notification</button>',
]);
?>
<?php if ($flash): ?><div class="alert alert-success d-flex align-items-center gap-2"><i class="bi bi-check-circle-fill"></i><span><?= esc($flash) ?></span></div><?php endif; ?>
<form class="filter-bar">
    <div class="row g-3 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label">Module</label>
            <select class="form-select" name="module_name">
                <option value="">All Modules</option>
                <option value="crm" <?= $filterModule==='crm'?'selected':'' ?>>CRM</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label">Status</label>
            <select class="form-select" name="status"><option value="">All Status</option><option value="open" <?= $filterStatus==='open'?'selected':'' ?>>Open</option><option value="in_progress" <?= $filterStatus==='in_progress'?'selected':'' ?>>In Progress</option><option value="closed" <?= $filterStatus==='closed'?'selected':'' ?>>Closed</option></select>
        </div>
        <div class="col-12 col-md-3">
            <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply Filters</button>
            <a class="btn btn-light" href="/notifications.php">Reset</a>
        </div>
    </div>
</form>
<div class="card table-card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Module</th><th>Title</th><th>To Whom</th><th>Read By</th><th>Status</th><th>Owner</th><th class="text-end">Seen / Replies</th><th class="text-end">Actions</th></tr></thead>
<tbody>
<?php if ($rows === []): ?>
<tr><td colspan="8"><div class="empty-state"><i class="bi bi-bell-slash"></i>No notifications found.</div></td></tr>
<?php endif; ?>
<?php foreach ($rows as $r): ?>
<?php
$statusChip = ['open' => 'status-pending', 'in_progress' => 'status-info', 'closed' => 'status-yes'][$r['status']] ?? 'status-neutral';
$statusText = ucwords(str_replace('_', ' ', (string) $r['status']));
$readByDisplay = ($r['read_by'] === 'specific' && !empty($r['target_user_name'])) ? ('Specific: ' . $r['target_user_name']) : ucfirst((string) ($r['read_by'] ?? 'any'));
$seenCount = (int) ($r['seen_count'] ?? 0);
$replyCount = (int) ($r['reply_count'] ?? 0);
?>
<tr>
    <td><span class="status-chip status-neutral"><?= esc(strtoupper((string) $r['module_name'])) ?></span></td>
    <td class="fw-semibold"><?= esc($r['title']) ?><?php if (!empty($r['details'])): ?><div class="small text-muted"><?= esc($r['details']) ?></div><?php endif; ?></td>
    <td><?= $r['to_whom'] ? '<span class="status-chip status-info">' . esc((string) $r['to_whom']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
    <td><?= esc($readByDisplay) ?></td>
    <td><span class="status-chip <?= $statusChip ?>"><?= esc($statusText) ?></span></td>
    <td><?= esc($r['owner_name']) ?></td>
    <td class="text-end">
        <span class="status-chip status-info" title="Seen by"><i class="bi bi-eye me-1"></i><?= $seenCount ?></span>
        <span class="status-chip status-neutral ms-1" title="Replies"><i class="bi bi-chat-left-text me-1"></i><?= $replyCount ?></span>
    </td>
    <td>
        <div class="d-flex gap-1 justify-content-end">
            <button class="btn btn-sm btn-outline-primary" onclick="openViewModal(<?= (int) $r['id'] ?>)"><i class="bi bi-eye"></i> View</button>
            <?php if ((int) $r['owner_user_id'] === (int) $user['id']): ?>
                <button class="btn btn-sm btn-outline-secondary" onclick='openEditModal(<?= json_encode($r, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="bi bi-pencil"></i> Edit</button>
                <?php if ($r['active_status']): ?>
                    <form method="post" class="d-inline"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i> Close</button></form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody></table></div></div>

<div class="modal fade" id="activityModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg"><div class="modal-content">
<form method="post" id="activityForm">
<div class="modal-header"><h5 class="modal-title" id="activityModalTitle">Add Notification</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">
<input type="hidden" name="action" id="formAction" value="add"><input type="hidden" name="id" id="activityId">
<div class="row g-2">
    <div class="col-md-4"><label class="form-label">Module</label><select class="form-select" name="module_name" id="module_name"><option value="crm">CRM</option></select></div>
    <div class="col-md-8"><label class="form-label">Title</label><input class="form-control" name="title" id="title" required></div>
    <div class="col-md-12"><label class="form-label">Details</label><textarea class="form-control" name="details" id="details"></textarea></div>
    <div class="col-md-4"><label class="form-label">To Whom</label>
        <select class="form-select" name="to_whom" id="to_whom">
            <option value="">All / Open</option>
            <option value="State CRM">State CRM</option>
            <option value="State DSM">State DSM</option>
            <option value="District User">District User</option>
        </select>
    </div>
    <div class="col-md-4"><label class="form-label">Read By</label>
        <select class="form-select" name="read_by" id="read_by">
            <option value="any">Any (anyone in that role)</option>
            <option value="specific">Specific user</option>
        </select>
    </div>
    <div class="col-md-4" id="targetUserCol" style="display:none;"><label class="form-label">Target User</label>
        <select class="form-select" name="target_user_id" id="target_user_id"><option value="">Select</option></select>
    </div>
    <div class="col-md-6"><label class="form-label">Status</label><select class="form-select" name="status" id="status"><option value="open">Open</option><option value="in_progress">In Progress</option><option value="closed">Closed</option></select></div>
    <div class="col-md-6 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="active_status" id="active" checked><label class="form-check-label" for="active">Active</label></div></div>
</div>
</div>
<div class="modal-footer"><button class="btn btn-light" data-bs-dismiss="modal" type="button">Cancel</button><button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save</button></div>
</form>
</div></div></div>

<!-- View Notification modal: details + seen list + conversation thread -->
<div class="modal fade" id="viewNotificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bell me-1"></i>Notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="vnBody">
                <div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</div>
            </div>
        </div>
    </div>
</div>

<script>
const CURRENT_USER_ID = <?= json_encode((int) $user['id']) ?>;
let vnActiveActivityId = null;

function escapeHtmlText(s) {
    return String(s ?? '').replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');
}
function fmtDateTime(s) {
    if (!s) return '';
    const d = new Date(String(s).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(s);
    return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' }).format(d);
}

function renderViewNotification(data) {
    const n = data.notification || {};
    const seen = data.seen || [];
    const replies = data.replies || [];
    const isOwner = (parseInt(n.owner_user_id, 10) === CURRENT_USER_ID);
    const moduleLabel = String(n.module_name || '').toUpperCase();
    const statusText = String(n.status || 'open').replace('_',' ').replace(/\b\w/g, (c) => c.toUpperCase());
    const toWhom = n.to_whom ? `<span class="status-chip status-info">${escapeHtmlText(n.to_whom)}</span>` : '<span class="text-muted">All</span>';
    const readBy = n.read_by === 'specific' ? `Specific: ${escapeHtmlText(n.target_user_name || '')}` : 'Any';
    const seenHtml = seen.length === 0
        ? '<span class="text-muted small">Not seen by anyone yet.</span>'
        : seen.map((s) => `<span class="status-chip status-yes me-1 mb-1"><i class="bi bi-eye me-1"></i>${escapeHtmlText(s.name)} <span class="small text-muted ms-1">${fmtDateTime(s.seen_at)}</span></span>`).join('');
    const repliesHtml = replies.length === 0
        ? '<div class="text-muted small text-center py-3"><i class="bi bi-chat-left me-1"></i>No replies yet. Be the first to respond.</div>'
        : replies.map((r) => {
            const isMine = parseInt(r.user_id, 10) === CURRENT_USER_ID;
            const align = isMine ? 'text-end' : '';
            const bubbleCls = isMine ? 'bg-primary text-white' : 'bg-light';
            return `<div class="${align} mb-2"><div class="d-inline-block ${bubbleCls} rounded p-2" style="max-width: 80%;"><div class="small fw-semibold ${isMine ? 'opacity-75' : 'text-primary'}">${escapeHtmlText(r.name)}</div><div>${escapeHtmlText(r.reply_text).replaceAll('\n','<br>')}</div><div class="x-small mt-1 ${isMine ? 'opacity-75' : 'text-muted'}">${fmtDateTime(r.created_at)}</div></div></div>`;
        }).join('');

    document.getElementById('vnBody').innerHTML = `
        <div class="card mb-3"><div class="card-body py-2">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <div>
                    <span class="status-chip status-neutral me-1">${escapeHtmlText(moduleLabel)}</span>
                    <span class="status-chip status-info">${escapeHtmlText(statusText)}</span>
                </div>
                <div class="small text-muted">By <strong>${escapeHtmlText(n.owner_name)}</strong> &middot; ${fmtDateTime(n.created_at)}</div>
            </div>
            <div class="fw-semibold">${escapeHtmlText(n.title)}</div>
            ${n.details ? `<div class="small text-muted mt-1">${escapeHtmlText(n.details).replaceAll('\n','<br>')}</div>` : ''}
            <div class="d-flex gap-2 mt-2 flex-wrap small">
                <div><span class="text-muted">To Whom:</span> ${toWhom}</div>
                <div><span class="text-muted">Read By:</span> ${escapeHtmlText(readBy)}</div>
            </div>
        </div></div>

        <div class="mb-3">
            <div class="small text-muted mb-1"><i class="bi bi-eye me-1"></i>Seen by (${seen.length})</div>
            <div class="d-flex flex-wrap">${seenHtml}</div>
        </div>

        <hr class="my-2">

        <div class="mb-3">
            <div class="small text-muted mb-2"><i class="bi bi-chat-left-text me-1"></i>Conversation (${replies.length})</div>
            <div id="vnRepliesList" style="max-height: 32vh; overflow-y: auto;">${repliesHtml}</div>
        </div>

        <form id="vnReplyForm">
            <input type="hidden" name="action" value="add_reply">
            <input type="hidden" name="activity_id" value="${parseInt(n.id, 10) || 0}">
            <label class="form-label small mb-1">Your reply</label>
            <textarea class="form-control" name="reply_text" rows="2" placeholder="Type a reply..." required></textarea>
            <div class="d-flex justify-content-end mt-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-send me-1"></i>Send Reply</button>
            </div>
        </form>
    `;

    const replyForm = document.getElementById('vnReplyForm');
    replyForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const fd = new FormData(replyForm);
        fetch('/notifications.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        }).then((r) => r.json()).then((res) => {
            if (res && res.ok) {
                // Reload modal to show the new reply.
                openViewModal(vnActiveActivityId, true);
            } else {
                alert('Failed to send reply: ' + (res?.error || 'unknown error'));
            }
        }).catch((err) => alert('Network error: ' + err.message));
    });
}

function openViewModal(activityId, skipShow) {
    vnActiveActivityId = activityId;
    if (!skipShow) {
        document.getElementById('vnBody').innerHTML = '<div class="text-center py-3 text-muted"><div class="spinner-border spinner-border-sm me-2"></div>Loading...</div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('viewNotificationModal')).show();
    }
    fetch('/notifications.php?details_id=' + encodeURIComponent(activityId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then((r) => r.json())
        .then((data) => {
            if (!data.ok) {
                document.getElementById('vnBody').innerHTML = '<div class="alert alert-danger">' + escapeHtmlText(data.error || 'Failed to load') + '</div>';
                return;
            }
            renderViewNotification(data);
        })
        .catch((err) => {
            document.getElementById('vnBody').innerHTML = '<div class="alert alert-danger">Network error: ' + escapeHtmlText(err.message) + '</div>';
        });
}
</script>

<script>
const usersByRole = <?= json_encode($usersByRole) ?>;

function refreshTargetUserOptions(selectedId) {
    const toWhom = document.getElementById('to_whom').value;
    const readBy = document.getElementById('read_by').value;
    const col = document.getElementById('targetUserCol');
    const sel = document.getElementById('target_user_id');
    if (readBy !== 'specific' || !toWhom) {
        col.style.display = 'none';
        sel.innerHTML = '<option value="">Select</option>';
        return;
    }
    const list = usersByRole[toWhom] || [];
    sel.innerHTML = '<option value="">Select</option>' + list.map((u) => {
        const districts = (u.assigned_districts || '').trim();
        const districtsHint = districts && toWhom === 'District User' ? ' (' + districts + ')' : '';
        return `<option value="${u.id}" ${String(u.id) === String(selectedId || '') ? 'selected' : ''}>${u.name}${districtsHint}</option>`;
    }).join('');
    col.style.display = '';
}
document.getElementById('to_whom').addEventListener('change', () => refreshTargetUserOptions());
document.getElementById('read_by').addEventListener('change', () => refreshTargetUserOptions());

function openAddModal() {
    document.getElementById('activityModalTitle').innerText = 'Add Notification';
    document.getElementById('formAction').value = 'add';
    document.getElementById('activityForm').reset();
    document.getElementById('active').checked = true;
    document.getElementById('module_name').value = 'crm';
    refreshTargetUserOptions();
}
function openEditModal(item) {
    document.getElementById('activityModalTitle').innerText = 'Edit Notification';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('activityId').value = item.id;
    document.getElementById('module_name').value = item.module_name || 'crm';
    document.getElementById('title').value = item.title;
    document.getElementById('details').value = item.details;
    document.getElementById('status').value = item.status;
    document.getElementById('active').checked = item.active_status == 1;
    document.getElementById('to_whom').value = item.to_whom || '';
    document.getElementById('read_by').value = item.read_by || 'any';
    refreshTargetUserOptions(item.target_user_id);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('activityModal')).show();
}
</script>
<?php render_footer(); ?>
