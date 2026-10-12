<?php
/**
 * Platform Requests · Chat view for a single request.
 *
 * WhatsApp-style thread. The request's reason + priority + status
 * sit in a sidebar card; messages render as bubbles aligned left
 * (others) / right (viewer) with a muted centered style for system
 * messages (status changes).
 *
 * Posting a reply is handled inline (standard POST that redirects
 * back to this page). Status change is a tiny inline form that also
 * writes a system message so the activity is logged in the thread.
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
    echo '<div class="alert alert-danger">You do not have access to Platform Requests.</div>';
    render_footer(); exit;
}
$isPlatformAdmin = loi_requests_is_platform_admin($viewer);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /platform_requests.php'); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_request_view_flash']) && is_array($_SESSION['loi_request_view_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_request_view_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_request_view_flash']['type'] ?? 'success');
    unset($_SESSION['loi_request_view_flash']);
}
$flashAndBack = static function (string $msg, string $type, int $rid): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_request_view_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /loi_request_view.php?id=' . $rid);
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'post_message') {
    csrf_check_or_die();
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') $flashAndBack('Type a message before sending.', 'danger', $id);
    $newId = loi_request_post_message($id, $viewerId, $body, 'comment');
    if ($newId === 0) $flashAndBack('Could not post message.', 'danger', $id);
    $flashAndBack('Reply sent.', 'success', $id);
}
if (is_post() && ($_POST['action'] ?? '') === 'change_status') {
    csrf_check_or_die();
    if (!$isPlatformAdmin) $flashAndBack('Only platform admin can change the status.', 'danger', $id);
    $newStatus = (string) ($_POST['status'] ?? '');
    if (!in_array($newStatus, LOI_REQUEST_STATUSES, true)) $flashAndBack('Invalid status.', 'danger', $id);
    $cs = db()->prepare('SELECT status FROM loi_request WHERE id = ? LIMIT 1');
    $cs->execute([$id]);
    $old = (string) ($cs->fetchColumn() ?: '');
    if ($old === '') $flashAndBack('Request not found.', 'danger', $id);
    if ($old === $newStatus) $flashAndBack('Status unchanged.', 'info', $id);
    $closedAt = ($newStatus === 'resolved' || $newStatus === 'closed') ? date('Y-m-d H:i:s') : null;
    db()->prepare('UPDATE loi_request SET status = ?, closed_at = ?, closed_by = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$newStatus, $closedAt, $closedAt !== null ? $viewerId : null, $id]);
    // System message so the chat shows the status change inline.
    $actorName = (string) ($viewer['name'] ?? 'Someone');
    loi_request_post_message(
        $id,
        $viewerId,
        $actorName . ' changed status: ' . loi_request_status_label($old) . ' → ' . loi_request_status_label($newStatus),
        'system'
    );
    $flashAndBack('Status updated.', 'success', $id);
}

$st = db()->prepare('SELECT r.*, e.name AS employer_name, e.sbu_id, e.state, e.district,
        rr.name AS reason_name, rr.description AS reason_description,
        u.name AS raised_by_name
    FROM loi_request r
    INNER JOIN loi_employer e ON e.id = r.employer_id
    LEFT JOIN loi_request_reason rr ON rr.id = r.reason_id
    LEFT JOIN users u ON u.id = r.created_by
    WHERE r.id = ? LIMIT 1');
$st->execute([$id]);
$req = $st->fetch();
if ($req === false) { header('Location: /platform_requests.php'); exit; }

$msgs = [];
try {
    $mst = db()->prepare('SELECT m.*, u.name AS author_name, u.avatar_colour
        FROM loi_request_message m
        LEFT JOIN users u ON u.id = m.author_id
        WHERE m.request_id = ?
        ORDER BY m.created_at ASC, m.id ASC');
    $mst->execute([$id]);
    $msgs = $mst->fetchAll();
} catch (Throwable $e) { /* empty */ }

$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $parts = array_values(array_filter($parts));
    if ($parts === []) return 'U';
    if (count($parts) === 1) return strtoupper(mb_substr($parts[0], 0, 2));
    return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
};

render_header('Platform Request · ' . ($req['reason_name'] ?? 'Chat'), ['main_container_class' => 'container-xl']);
render_page_header('Platform Request · ' . ($req['employer_name'] ?? ''), [
    'icon'     => 'bi-chat-dots',
    'subtitle' => (string) ($req['reason_name'] ?? '—')
        . ' · raised by ' . (string) ($req['raised_by_name'] ?? '—')
        . ' on ' . date('d/m/Y H:i', strtotime((string) $req['created_at'])),
    'actions'  => '<a class="btn btn-light" href="/platform_requests.php"><i class="bi bi-arrow-left me-1"></i>Back to Inbox</a>
        <a class="btn btn-light ms-2" href="/loi_employer_view.php?id=' . (int) $req['employer_id'] . '&tab=requests"><i class="bi bi-building me-1"></i>Open employer</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="row g-3">
    <!-- Sidebar: request metadata + status control -->
    <div class="col-lg-4 order-lg-2">
        <div class="card mb-3">
            <div class="card-body">
                <div class="small text-muted">Employer</div>
                <div class="h5"><?= esc((string) $req['employer_name']) ?></div>
                <?php if (!empty($req['state']) || !empty($req['district'])): ?>
                    <div class="small text-muted"><?= esc((string) ($req['state'] ?? '')) ?><?= !empty($req['district']) ? ' · ' . esc((string) $req['district']) : '' ?></div>
                <?php endif; ?>
                <hr>
                <div class="small text-muted">Reason</div>
                <div class="fw-semibold"><?= esc((string) ($req['reason_name'] ?? '—')) ?></div>
                <?php if (!empty($req['reason_description'])): ?>
                    <div class="small text-muted mt-1"><?= esc((string) $req['reason_description']) ?></div>
                <?php endif; ?>
                <hr>
                <div class="small text-muted mb-1">Priority</div>
                <span class="badge text-bg-<?= esc(loi_request_priority_tone((string) $req['priority'])) ?>"><?= esc(ucfirst((string) $req['priority'])) ?></span>
                <div class="small text-muted mt-3 mb-1">Status</div>
                <span class="badge text-bg-<?= esc(loi_request_status_tone((string) $req['status'])) ?>"><?= esc(loi_request_status_label((string) $req['status'])) ?></span>
                <?php if (!empty($req['closed_at'])): ?>
                    <div class="small text-muted mt-1">Closed on <?= esc(date('d/m/Y H:i', strtotime((string) $req['closed_at']))) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($isPlatformAdmin): ?>
            <div class="card-footer">
                <form method="post" class="d-flex gap-2 align-items-end">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="change_status">
                    <div class="flex-grow-1">
                        <label class="form-label small mb-1">Change status</label>
                        <select class="form-select form-select-sm" name="status">
                            <?php foreach (LOI_REQUEST_STATUSES as $s): ?>
                                <option value="<?= esc($s) ?>" <?= $s === (string) $req['status'] ? 'selected' : '' ?>><?= esc(loi_request_status_label($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-warning"><i class="bi bi-check2 me-1"></i>Update</button>
                </form>
                <div class="small text-muted mt-2">The status change is also recorded as a system message in the chat.</div>
            </div>
            <?php else: ?>
            <div class="card-footer small text-muted">Only a Platform admin can change the status. You can still reply below.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Chat column -->
    <div class="col-lg-8 order-lg-1">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-chat-dots text-primary me-1"></i>Conversation</span>
                <span class="small text-muted"><?= count($msgs) ?> message<?= count($msgs) === 1 ? '' : 's' ?></span>
            </div>
            <div class="card-body chat-pane" id="chatPane">
                <?php if ($msgs === []): ?>
                    <div class="empty-state"><i class="bi bi-chat"></i>No messages yet.</div>
                <?php endif; ?>
                <?php foreach ($msgs as $m):
                    $isSystem = ((string) $m['kind']) === 'system';
                    $isMine   = ((int) ($m['author_id'] ?? 0)) === $viewerId;
                    $authorName = (string) ($m['author_name'] ?? 'System');
                    $av = (string) ($m['avatar_colour'] ?? '');
                    if ($av === '') $av = 'primary';
                    $when = esc(date('d/m/Y H:i', strtotime((string) $m['created_at'])));
                ?>
                    <?php if ($isSystem): ?>
                        <div class="chat-system text-center text-muted small my-3">
                            <i class="bi bi-info-circle me-1"></i><?= esc((string) $m['body']) ?>
                            <span class="ms-1 text-muted">· <?= $when ?></span>
                        </div>
                    <?php else: ?>
                        <div class="chat-row <?= $isMine ? 'chat-row-mine' : '' ?>">
                            <?php if (!$isMine): ?>
                                <span class="chat-avatar text-bg-<?= esc($av) ?>"><?= esc($initials($authorName)) ?></span>
                            <?php endif; ?>
                            <div class="chat-bubble <?= $isMine ? 'chat-bubble-mine' : '' ?>">
                                <?php if (!$isMine): ?>
                                    <div class="chat-author small fw-semibold"><?= esc($authorName) ?></div>
                                <?php endif; ?>
                                <div style="white-space:pre-wrap;"><?= esc((string) $m['body']) ?></div>
                                <div class="chat-meta small text-muted"><?= $when ?></div>
                            </div>
                            <?php if ($isMine): ?>
                                <span class="chat-avatar text-bg-<?= esc($av) ?>"><?= esc($initials($authorName)) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php if (!in_array((string) $req['status'], ['closed'], true) || $isPlatformAdmin): ?>
            <div class="card-footer">
                <form method="post">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="post_message">
                    <div class="d-flex gap-2 align-items-end">
                        <div class="flex-grow-1">
                            <textarea class="form-control" name="body" rows="2" placeholder="Type your reply…" required></textarea>
                        </div>
                        <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Send</button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="card-footer small text-muted"><i class="bi bi-lock me-1"></i>This request is closed. A platform admin can reopen it from the sidebar.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.chat-pane { background: #f3f4f6; max-height: 65vh; overflow-y: auto; }
.chat-row { display: flex; align-items: flex-end; gap: 8px; margin-bottom: 12px; }
.chat-row-mine { flex-direction: row-reverse; }
.chat-bubble { background: #ffffff; border-radius: 12px 12px 12px 2px; padding: 8px 12px; max-width: 70%; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
.chat-bubble-mine { background: #dcfce7; border-radius: 12px 12px 2px 12px; }
.chat-author { color: #475569; margin-bottom: 2px; }
.chat-meta { margin-top: 4px; }
.chat-avatar { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-weight: 600; font-size: .8rem; flex-shrink: 0; }
.chat-system { background: transparent; padding: 4px 8px; }
</style>

<script>
// Scroll the chat pane to the bottom on load so the latest message is
// visible, mimicking the natural WhatsApp open-conversation behaviour.
(function () {
    const el = document.getElementById('chatPane');
    if (el) el.scrollTop = el.scrollHeight;
})();
</script>

<?php render_footer(); ?>
