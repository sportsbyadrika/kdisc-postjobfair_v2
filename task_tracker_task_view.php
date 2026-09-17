<?php
/**
 * Task Tracker · Task detail view.
 *
 * Two-column layout: description / target / attachments / sub-tasks
 * on the left, status lozenge + assignees + dates on the right,
 * tabbed Comments / History / All below.
 *
 * Cards on the Kanban board link here; this page has an Edit button
 * that jumps to task_tracker_task.php for structural changes.
 *
 * Write endpoints on this page (each guarded by csrf_check_or_die):
 *   - add_remark   → INSERT task_remark
 *   - upload_file  → moves the upload into uploads/task_tracker/<pid>/
 *                    and INSERTs task_file; disallows executable extensions
 *   - delete_file  → deletes the file on disk and its row
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_task_tracker_access();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];
$canManage = is_manage_admin($viewer);

$taskId = (int) ($_GET['id'] ?? 0);
if ($taskId <= 0) { header('Location: /task_tracker_projects.php'); exit; }

$stmt = db()->prepare('SELECT t.*, p.name AS project_name, p.code AS project_code, p.id AS project_id_of_task,
        s.name AS status_name, s.colour_token AS status_colour, s.category AS status_category, s.is_terminal,
        pt.title AS parent_title, pt.task_number AS parent_task_number
    FROM task t
    INNER JOIN project p ON p.id = t.project_id
    LEFT JOIN task_status s ON s.id = t.status_id
    LEFT JOIN task pt ON pt.id = t.parent_id
    WHERE t.id = ? LIMIT 1');
$stmt->execute([$taskId]);
$task = $stmt->fetch();
if ($task === false) {
    render_header('Task not found');
    render_page_header('Task not found', ['icon' => 'bi-exclamation-triangle',
        'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>Back to Projects</a>']);
    echo '<div class="alert alert-warning">This task does not exist or was deactivated.</div>';
    render_footer();
    exit;
}

$projectId = (int) $task['project_id'];

// NOTE: `div` is a reserved word in MariaDB (integer-division
// operator), so we use `divn` as the alias.
$astmt = db()->prepare("SELECT ta.role, ta.seat_id, n.name AS seat_name, n.seat_number,
        n.parent_id AS section_id, sec.name AS section_name,
        sec.parent_id AS division_id, divn.name AS division_name,
        u.id AS officer_id, u.name AS officer_name, u.avatar_colour
    FROM task_assignment ta
    INNER JOIN office_hierarchy_nodes n    ON n.id    = ta.seat_id
    LEFT JOIN office_hierarchy_nodes sec   ON sec.id  = n.parent_id
    LEFT JOIN office_hierarchy_nodes divn  ON divn.id = sec.parent_id
    LEFT JOIN office_hierarchy_officer_history h ON h.node_id = n.id AND h.unassigned_at IS NULL
    LEFT JOIN users u ON u.id = h.officer_id
    WHERE ta.task_id = ?
    ORDER BY (ta.role = 'primary') DESC, ta.id ASC");
$astmt->execute([$taskId]);
$assignments = $astmt->fetchAll();
$primary = null; $secondaries = [];
foreach ($assignments as $a) {
    if ($a['role'] === 'primary') $primary = $a;
    else $secondaries[] = $a;
}
$permTask = array_merge((array) $task, [
    'primary_seat_id'     => (int) ($primary['seat_id']     ?? 0),
    'primary_section_id'  => (int) ($primary['section_id']  ?? 0),
    'primary_division_id' => (int) ($primary['division_id'] ?? 0),
]);
$canEdit = can_edit_task($viewerId, $permTask);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashAndReturn = static function (string $msg, string $type, int $taskId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['task_tracker_task_view_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /task_tracker_task_view.php?id=' . $taskId);
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'add_remark') {
    csrf_check_or_die();
    if (!$canEdit) $flashAndReturn('You do not have permission to comment.', 'danger', $taskId);
    $body = trim((string) ($_POST['remark'] ?? ''));
    if ($body === '') $flashAndReturn('Comment cannot be empty.', 'danger', $taskId);
    if (mb_strlen($body) > 5000) $flashAndReturn('Comment is too long (5000 char limit).', 'danger', $taskId);
    try {
        db()->prepare('INSERT INTO task_remark (task_id, remark, created_by, created_at) VALUES (?, ?, ?, NOW())')
            ->execute([$taskId, $body, $viewerId]);
        db()->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at) VALUES (?, ?, NULL, ?, ?, NOW())')
            ->execute([$taskId, 'comment', 'Comment added', $viewerId]);
        $flashAndReturn('Comment added.', 'success', $taskId);
    } catch (Throwable $e) {
        $flashAndReturn('Save failed: ' . $e->getMessage(), 'danger', $taskId);
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'upload_file') {
    csrf_check_or_die();
    if (!$canEdit) $flashAndReturn('You do not have permission to upload files.', 'danger', $taskId);
    if (empty($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashAndReturn('No file uploaded or upload failed.', 'danger', $taskId);
    }
    $original = (string) $_FILES['file']['name'];
    $tmp      = (string) $_FILES['file']['tmp_name'];
    $size     = (int) $_FILES['file']['size'];
    $mime     = (string) ($_FILES['file']['type'] ?? '');
    if ($size <= 0 || $size > 20 * 1024 * 1024) $flashAndReturn('File must be between 1 byte and 20 MB.', 'danger', $taskId);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (in_array($ext, ['php','phtml','phar','phps','cgi','pl','py','rb','sh','htaccess','exe','bat','com','msi'], true)) {
        $flashAndReturn('That file type is not allowed.', 'danger', $taskId);
    }
    $dir = __DIR__ . '/uploads/task_tracker/' . $projectId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir)) $flashAndReturn('Uploads directory could not be created.', 'danger', $taskId);
    $safe = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $original);
    $dest = $dir . '/' . $safe;
    if (!move_uploaded_file($tmp, $dest)) $flashAndReturn('Could not save the uploaded file.', 'danger', $taskId);
    try {
        db()->prepare('INSERT INTO task_file
            (task_id, original_name, stored_path, mime_type, size_bytes, uploaded_by, uploaded_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$taskId, $original,
                'uploads/task_tracker/' . $projectId . '/' . $safe,
                $mime === '' ? null : $mime,
                $size, $viewerId]);
        db()->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at) VALUES (?, ?, NULL, ?, ?, NOW())')
            ->execute([$taskId, 'attachment', 'Uploaded ' . $original, $viewerId]);
        $flashAndReturn('File uploaded.', 'success', $taskId);
    } catch (Throwable $e) {
        @unlink($dest);
        $flashAndReturn('Save failed: ' . $e->getMessage(), 'danger', $taskId);
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'delete_file') {
    csrf_check_or_die();
    if (!$canEdit) $flashAndReturn('You do not have permission to delete files.', 'danger', $taskId);
    $fid = (int) ($_POST['file_id'] ?? 0);
    if ($fid <= 0) $flashAndReturn('Missing file id.', 'danger', $taskId);
    $f = db()->prepare('SELECT * FROM task_file WHERE id = ? AND task_id = ? LIMIT 1');
    $f->execute([$fid, $taskId]);
    $file = $f->fetch();
    if ($file === false) $flashAndReturn('File not found.', 'danger', $taskId);
    $abs = __DIR__ . '/' . ltrim((string) $file['stored_path'], '/');
    if (is_file($abs)) @unlink($abs);
    db()->prepare('DELETE FROM task_file WHERE id = ?')->execute([$fid]);
    db()->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at) VALUES (?, ?, ?, NULL, ?, NOW())')
        ->execute([$taskId, 'attachment', 'Deleted ' . $file['original_name'], $viewerId]);
    $flashAndReturn('File deleted.', 'success', $taskId);
}

$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['task_tracker_task_view_flash']) && is_array($_SESSION['task_tracker_task_view_flash'])) {
    $flashMessage = (string) ($_SESSION['task_tracker_task_view_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['task_tracker_task_view_flash']['type'] ?? 'success');
    unset($_SESSION['task_tracker_task_view_flash']);
}

$subtasks = db()->prepare('SELECT t.*, s.name AS status_name, s.colour_token AS status_colour
    FROM task t LEFT JOIN task_status s ON s.id = t.status_id
    WHERE t.parent_id = ? AND t.is_active = 1
    ORDER BY t.task_number ASC');
$subtasks->execute([$taskId]);
$subs = $subtasks->fetchAll();

$files = db()->prepare('SELECT f.*, u.name AS uploaded_by_name
    FROM task_file f LEFT JOIN users u ON u.id = f.uploaded_by
    WHERE f.task_id = ? ORDER BY f.uploaded_at DESC, f.id DESC');
$files->execute([$taskId]);
$fileRows = $files->fetchAll();

$remarks = db()->prepare('SELECT r.*, u.name AS by_name, u.avatar_colour
    FROM task_remark r LEFT JOIN users u ON u.id = r.created_by
    WHERE r.task_id = ? ORDER BY r.created_at ASC, r.id ASC');
$remarks->execute([$taskId]);
$remarkRows = $remarks->fetchAll();

$hist = db()->prepare('SELECT h.*, u.name AS by_name
    FROM task_history h LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.task_id = ? ORDER BY h.changed_at DESC, h.id DESC');
$hist->execute([$taskId]);
$histRows = $hist->fetchAll();

$mergedAll = [];
foreach ($remarkRows as $r) {
    $mergedAll[] = ['kind' => 'comment', 'when' => (string) $r['created_at'], 'row' => $r];
}
foreach ($histRows as $h) {
    $mergedAll[] = ['kind' => 'history', 'when' => (string) $h['changed_at'], 'row' => $h];
}
usort($mergedAll, static fn($a, $b) => strcmp($b['when'], $a['when']));

$tone = (string) ($task['status_colour'] ?? 'secondary');
$bootstrapTone = $tone === 'neutral' ? 'secondary' : $tone;
$priorityTones  = ['lowest' => 'secondary', 'low' => 'info', 'medium' => 'primary', 'high' => 'warning', 'highest' => 'danger'];
$priorityLabels = ['lowest' => 'Lowest', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'highest' => 'Highest'];
$priTone  = $priorityTones[(string) $task['priority']]  ?? 'secondary';
$priLabel = $priorityLabels[(string) $task['priority']] ?? ucfirst((string) $task['priority']);

$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    if (!$parts) return '?';
    $first = mb_substr($parts[0] ?? '', 0, 1);
    $last  = count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '';
    return strtoupper(($first . $last) ?: '?');
};
$fmtDate = static function (?string $s): string {
    $s = substr((string) $s, 0, 10);
    return $s === '' ? '—' : date('d/m/Y', strtotime($s));
};
$fmtDT = static function (?string $s): string {
    $s = (string) $s;
    return $s === '' ? '' : date('d/m/Y H:i', strtotime($s));
};
$fmtSize = static function (int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
    return number_format($bytes / (1024 * 1024), 2) . ' MB';
};

$key = (string) $task['project_code'] . '-' . (int) $task['task_number'];
render_header('Task Tracker · ' . $key . ' · ' . $task['title'], ['main_container_class' => 'container-xl']);
render_page_header($key . ' · ' . $task['title'], [
    'icon' => 'bi-card-checklist',
    'subtitle' => 'Project: ' . htmlspecialchars((string) $task['project_name'], ENT_QUOTES, 'UTF-8'),
    'actions' => ($canEdit
            ? '<a class="btn btn-primary" href="/task_tracker_task.php?id=' . $taskId . '"><i class="bi bi-pencil me-1"></i>Edit</a>'
            : '')
        . '<a class="btn btn-light ms-2" href="/task_tracker_project_view.php?id=' . (int) $task['project_id'] . '"><i class="bi bi-arrow-left me-1"></i>Back to board</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="row g-3">
    <!-- LEFT: description, target, attachments, sub-tasks -->
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-file-text text-primary me-1"></i>Description</div>
            <div class="card-body">
                <?php if (!empty($task['description'])): ?>
                    <div style="white-space:pre-wrap;"><?= esc((string) $task['description']) ?></div>
                <?php else: ?>
                    <span class="text-muted">No description provided.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-bullseye text-primary me-1"></i>Target / acceptance criteria</div>
            <div class="card-body">
                <?php if (!empty($task['target'])): ?>
                    <div style="white-space:pre-wrap;"><?= esc((string) $task['target']) ?></div>
                <?php else: ?>
                    <span class="text-muted">No target set.</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-paperclip text-primary me-1"></i>Attachments</span>
                <span class="badge text-bg-light border"><?= count($fileRows) ?></span>
            </div>
            <div class="card-body">
                <?php if ($canEdit): ?>
                    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-center mb-3">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="upload_file">
                        <div class="col">
                            <input type="file" class="form-control" name="file" required>
                        </div>
                        <div class="col-auto">
                            <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
                        </div>
                        <div class="col-12 small text-muted">Max 20 MB. Executable file types are rejected.</div>
                    </form>
                <?php endif; ?>
                <?php if ($fileRows === []): ?>
                    <div class="text-muted small">No attachments yet.</div>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($fileRows as $f): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="/<?= esc((string) $f['stored_path']) ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-file-earmark me-1"></i><?= esc((string) $f['original_name']) ?>
                                    </a>
                                    <div class="small text-muted">
                                        <?= esc($fmtSize((int) $f['size_bytes'])) ?> · uploaded by <?= esc((string) ($f['uploaded_by_name'] ?? '—')) ?>
                                        · <?= esc($fmtDT((string) $f['uploaded_at'])) ?>
                                    </div>
                                </div>
                                <?php if ($canEdit): ?>
                                    <form method="post" onsubmit="return confirm('Delete this attachment?');">
                                        <?php csrf_field(); ?>
                                        <input type="hidden" name="action" value="delete_file">
                                        <input type="hidden" name="file_id" value="<?= (int) $f['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-diagram-3 text-primary me-1"></i>Sub-activities</span>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-light border"><?= count($subs) ?></span>
                    <?php if ($canEdit && empty($task['parent_id'])): ?>
                        <a class="btn btn-sm btn-outline-primary" href="/task_tracker_task.php?project=<?= (int) $task['project_id'] ?>&parent=<?= (int) $task['id'] ?>">
                            <i class="bi bi-plus-lg"></i> Add sub-activity
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if ($subs === []): ?>
                    <div class="text-muted small"><?= empty($task['parent_id']) ? 'No sub-activities yet.' : 'This is a sub-activity itself.' ?></div>
                <?php else: ?>
                    <ul class="list-group">
                        <?php foreach ($subs as $sub):
                            $st = (string) ($sub['status_colour'] ?? 'secondary');
                            $stTone = $st === 'neutral' ? 'secondary' : $st;
                        ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <a class="text-decoration-none text-body" href="/task_tracker_task_view.php?id=<?= (int) $sub['id'] ?>">
                                    <span class="badge text-bg-light border font-monospace me-2"><?= esc((string) $task['project_code']) ?>-<?= (int) $sub['task_number'] ?></span>
                                    <?= esc((string) $sub['title']) ?>
                                </a>
                                <span class="badge text-bg-<?= esc($stTone) ?>"><?= esc((string) ($sub['status_name'] ?? '—')) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: status + assignees + dates -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-body">
                <div class="mb-3">
                    <div class="text-uppercase small text-muted mb-1">Status</div>
                    <span class="badge text-bg-<?= esc($bootstrapTone) ?> fs-6"><?= esc((string) ($task['status_name'] ?? '—')) ?></span>
                    <?php if ((int) $task['is_terminal'] === 1): ?><span class="badge text-bg-secondary ms-1">Terminal</span><?php endif; ?>
                </div>
                <div class="mb-3">
                    <div class="text-uppercase small text-muted mb-1">Priority</div>
                    <span class="badge text-bg-<?= esc($priTone) ?>"><?= esc($priLabel) ?></span>
                </div>
                <div class="mb-3">
                    <div class="text-uppercase small text-muted mb-1">Type</div>
                    <?php if (empty($task['parent_id'])): ?>
                        <span class="badge text-bg-primary">Activity</span>
                    <?php else: ?>
                        <span class="badge text-bg-secondary">Sub-activity</span>
                        <div class="small text-muted mt-1">under
                            <a href="/task_tracker_task_view.php?id=<?= (int) $task['parent_id'] ?>">
                                <?= esc((string) $task['project_code']) ?>-<?= (int) $task['parent_task_number'] ?> · <?= esc((string) $task['parent_title']) ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="text-uppercase small text-muted mb-1">Planned start</div>
                        <div><?= esc($fmtDate($task['planned_start'] ?? null)) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-uppercase small text-muted mb-1">Planned end</div>
                        <div><?= esc($fmtDate($task['planned_end'] ?? null)) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-uppercase small text-muted mb-1">Actual start</div>
                        <div><?= esc($fmtDate($task['actual_start'] ?? null)) ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-uppercase small text-muted mb-1">Actual end</div>
                        <div><?= esc($fmtDate($task['actual_end'] ?? null)) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-person-check text-primary me-1"></i>Primary responsibility</div>
            <div class="card-body">
                <?php if ($primary === null): ?>
                    <div class="text-muted small">No primary seat assigned.</div>
                <?php else:
                    $col = (string) ($primary['avatar_colour'] ?? 'secondary');
                    if ($col === 'neutral') $col = 'secondary';
                    $offName = (string) ($primary['officer_name'] ?? '');
                ?>
                    <div class="d-flex align-items-center gap-3">
                        <span class="tt-avatar-lg text-bg-<?= esc($col) ?>">
                            <?= $offName !== '' ? esc($initials($offName)) : '<i class="bi bi-person-workspace"></i>' ?>
                        </span>
                        <div>
                            <div class="fw-semibold"><?= esc($offName !== '' ? $offName : ($primary['seat_name'] ?? '—')) ?></div>
                            <div class="small text-muted">Seat: <?= esc((string) ($primary['seat_name'] ?? '—')) ?></div>
                            <?php if (!empty($primary['section_name'])): ?><div class="small text-muted">Section: <?= esc((string) $primary['section_name']) ?></div><?php endif; ?>
                            <?php if (!empty($primary['division_name'])): ?><div class="small text-muted">Division: <?= esc((string) $primary['division_name']) ?></div><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($secondaries !== []): ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-people text-primary me-1"></i>Secondary responsibility</span>
                    <span class="badge text-bg-light border"><?= count($secondaries) ?></span>
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($secondaries as $s):
                            $col = (string) ($s['avatar_colour'] ?? 'secondary');
                            if ($col === 'neutral') $col = 'secondary';
                            $offName = (string) ($s['officer_name'] ?? '');
                        ?>
                            <li class="d-flex align-items-center gap-2 mb-2">
                                <span class="tt-avatar text-bg-<?= esc($col) ?>">
                                    <?= $offName !== '' ? esc($initials($offName)) : '<i class="bi bi-person-workspace"></i>' ?>
                                </span>
                                <div>
                                    <div><?= esc($offName !== '' ? $offName : ($s['seat_name'] ?? '—')) ?></div>
                                    <div class="small text-muted"><?= esc((string) ($s['seat_name'] ?? '—')) ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs px-2 pt-2" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabComments" type="button">Comments · <?= count($remarkRows) ?></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabHistory" type="button">History · <?= count($histRows) ?></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAll" type="button">All · <?= count($mergedAll) ?></button></li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="tabComments">
                <?php if ($canEdit): ?>
                    <form method="post" class="mb-3">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="add_remark">
                        <div class="input-group">
                            <textarea class="form-control" name="remark" rows="2" placeholder="Add a comment…" required></textarea>
                            <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Post</button>
                        </div>
                        <div class="small text-muted mt-1">Comments are permanent. Every post writes a history row.</div>
                    </form>
                <?php endif; ?>
                <?php if ($remarkRows === []): ?>
                    <div class="empty-state"><i class="bi bi-chat"></i>No comments yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($remarkRows as $r):
                            $col = (string) ($r['avatar_colour'] ?? 'secondary');
                            if ($col === 'neutral') $col = 'secondary';
                            $by = (string) ($r['by_name'] ?? '—');
                        ?>
                            <li class="d-flex gap-2 mb-3">
                                <span class="tt-avatar text-bg-<?= esc($col) ?>"><?= esc($initials($by)) ?></span>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <strong><?= esc($by) ?></strong>
                                        <span class="small text-muted"><?= esc($fmtDT((string) $r['created_at'])) ?></span>
                                    </div>
                                    <div style="white-space:pre-wrap;"><?= esc((string) $r['remark']) ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="tabHistory">
                <?php if ($histRows === []): ?>
                    <div class="empty-state"><i class="bi bi-clock-history"></i>No changes recorded yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($histRows as $h): ?>
                            <li class="mb-2">
                                <span class="badge text-bg-light border font-monospace me-1"><?= esc((string) $h['field_name']) ?></span>
                                <span class="text-muted small"><?= esc($fmtDT((string) $h['changed_at'])) ?> · by <?= esc((string) ($h['by_name'] ?? '—')) ?></span>
                                <div class="small">
                                    <?php if ((string) ($h['old_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">from</span> <span class="text-decoration-line-through"><?= esc((string) $h['old_value']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((string) ($h['new_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">to</span> <strong><?= esc((string) $h['new_value']) ?></strong>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="tabAll">
                <?php if ($mergedAll === []): ?>
                    <div class="empty-state"><i class="bi bi-list-nested"></i>No activity yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($mergedAll as $entry):
                            $row = $entry['row'];
                            $when = $entry['when'];
                            if ($entry['kind'] === 'comment') {
                                $col = (string) ($row['avatar_colour'] ?? 'secondary');
                                if ($col === 'neutral') $col = 'secondary';
                                $by = (string) ($row['by_name'] ?? '—');
                        ?>
                            <li class="d-flex gap-2 mb-3">
                                <span class="tt-avatar text-bg-<?= esc($col) ?>"><?= esc($initials($by)) ?></span>
                                <div>
                                    <div><strong><?= esc($by) ?></strong> <span class="small text-muted">commented · <?= esc($fmtDT($when)) ?></span></div>
                                    <div style="white-space:pre-wrap;"><?= esc((string) $row['remark']) ?></div>
                                </div>
                            </li>
                        <?php } else { ?>
                            <li class="mb-2">
                                <span class="badge text-bg-light border font-monospace me-1"><?= esc((string) $row['field_name']) ?></span>
                                <span class="text-muted small"><?= esc($fmtDT($when)) ?> · by <?= esc((string) ($row['by_name'] ?? '—')) ?></span>
                                <div class="small">
                                    <?php if ((string) ($row['old_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">from</span> <span class="text-decoration-line-through"><?= esc((string) $row['old_value']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((string) ($row['new_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">to</span> <strong><?= esc((string) $row['new_value']) ?></strong>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php } endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.tt-avatar { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:50%; font-size:12px; font-weight:700; }
.tt-avatar-lg { display:inline-flex; align-items:center; justify-content:center; width:56px; height:56px; border-radius:50%; font-size:18px; font-weight:700; }
</style>

<?php render_footer(); ?>
