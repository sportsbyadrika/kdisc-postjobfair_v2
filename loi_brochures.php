<?php
/**
 * LOI brochure · List + create/edit.
 *
 * Any LOI user can see every brochure (per the user's spec). Create /
 * edit is open to any LOI user too — the brochure records its author
 * but doesn't gate editing on it. Admins + the author can delete.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/loi_brochure_helpers.php';
require_auth();

$viewer = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
loi_brochure_bootstrap();
loi_jobs_bootstrap();

if (!user_can_access_module($viewerId, 'loi_jobs') && !is_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">You do not have access to the LOI & Jobs module.</div>';
    render_footer(); exit;
}
$isAdmin = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'loi_jobs');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_br_flash']) && is_array($_SESSION['loi_br_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_br_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_br_flash']['type'] ?? 'success');
    unset($_SESSION['loi_br_flash']);
}
$flashAndBack = static function (string $msg, string $type, int $openId = 0): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_br_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: ' . ($openId > 0 ? '/loi_brochures.php?open=' . $openId : '/loi_brochures.php'));
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $id = (int) ($_POST['id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $templateId = (int) ($_POST['template_id'] ?? 0);
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $jobIds = array_values(array_unique(array_map('intval', (array) ($_POST['job_ids'] ?? []))));
    $jobIds = array_values(array_filter($jobIds, static fn($x) => $x > 0));
    if ($title === '') $flashAndBack('Title is required.', 'danger');

    $db = db();
    $db->query('START TRANSACTION');
    try {
        if ($id > 0) {
            $db->prepare('UPDATE loi_brochure SET title = ?, template_id = ?, notes = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
                ->execute([$title, $templateId > 0 ? $templateId : null, $notes === '' ? null : $notes, $viewerId, $id]);
        } else {
            $db->prepare('INSERT INTO loi_brochure (title, template_id, notes, is_active, created_at, updated_at, created_by, updated_by) VALUES (?, ?, ?, 1, NOW(), NOW(), ?, ?)')
                ->execute([$title, $templateId > 0 ? $templateId : null, $notes === '' ? null : $notes, $viewerId, $viewerId]);
            $id = (int) $db->lastInsertId();
        }
        $db->prepare('DELETE FROM loi_brochure_job WHERE brochure_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT INTO loi_brochure_job (brochure_id, job_id, sort_order) VALUES (?, ?, ?)');
        $o = 0; foreach ($jobIds as $jid) { $o += 10; $ins->execute([$id, $jid, $o]); }
        $db->query('COMMIT');
        $flashAndBack('Brochure saved.', 'success', $id);
    } catch (Throwable $e) {
        try { $db->query('ROLLBACK'); } catch (Throwable $r) {}
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}
if (is_post() && ($_POST['action'] ?? '') === 'delete') {
    csrf_check_or_die();
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $own = db()->prepare('SELECT created_by FROM loi_brochure WHERE id = ?');
        $own->execute([$id]);
        $createdBy = (int) ($own->fetchColumn() ?: 0);
        if (!$isAdmin && $createdBy !== $viewerId) $flashAndBack('Only the brochure creator or admin can delete.', 'danger');
        try {
            db()->prepare('DELETE FROM loi_brochure_job WHERE brochure_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM loi_brochure WHERE id = ?')->execute([$id]);
        } catch (Throwable $e) { $flashAndBack('Delete failed: ' . $e->getMessage(), 'danger'); }
        $flashAndBack('Brochure deleted.');
    }
    $flashAndBack('Missing id.', 'danger');
}

$rows = [];
try {
    $rows = db()->query("SELECT b.*, t.name AS template_name, u.name AS created_by_name,
            (SELECT COUNT(*) FROM loi_brochure_job WHERE brochure_id = b.id) AS job_count
        FROM loi_brochure b
        LEFT JOIN loi_brochure_template t ON t.id = b.template_id
        LEFT JOIN users u ON u.id = b.created_by
        WHERE b.is_active = 1
        ORDER BY b.updated_at DESC")->fetchAll();
} catch (Throwable $e) { /* empty */ }
$templates = [];
try { $templates = db()->query("SELECT id, name, is_default FROM loi_brochure_template WHERE is_active = 1 ORDER BY is_default DESC, name ASC")->fetchAll(); } catch (Throwable $e) { /* empty */ }
$jobs = [];
try {
    $jobs = db()->query("SELECT j.id, j.job_role, j.vacancy_count, e.name AS employer_name
        FROM loi_job j
        INNER JOIN loi_employer e ON e.id = j.employer_id
        WHERE j.is_active = 1
        ORDER BY e.name ASC, j.job_role ASC")->fetchAll();
} catch (Throwable $e) { /* empty */ }

// Row to edit (?open=X)
$openId = (int) ($_GET['open'] ?? 0);
$editBrochure = null; $editJobIds = [];
if ($openId > 0) {
    $st = db()->prepare('SELECT * FROM loi_brochure WHERE id = ? LIMIT 1');
    $st->execute([$openId]);
    $editBrochure = $st->fetch() ?: null;
    if ($editBrochure !== null) $editJobIds = loi_brochure_job_ids($openId);
}

render_header('LOI & Jobs · Brochures', ['main_container_class' => 'container-xl']);
render_page_header('LOI & Jobs · Brochures', [
    'icon' => 'bi-file-earmark-pdf',
    'subtitle' => 'Create a titled brochure, pick a template + a set of jobs, and generate a PDF. Any LOI user can see and generate; the author + admin can delete.',
    'actions' => '<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#brModal" onclick="window.__brNew && window.__brNew()"><i class="bi bi-plus-lg me-1"></i>New brochure</button>'
        . ($isAdmin ? '<a class="btn btn-light ms-2" href="/loi_brochure_templates.php"><i class="bi bi-file-earmark-image me-1"></i>Templates</a>' : ''),
]);
?>

<?php if ($flashMessage !== null): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div><?php endif; ?>
<?php if (!loi_brochure_dompdf_available()): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i><strong>Dompdf is not installed.</strong> Generate will open an A4-styled page that you can save as PDF through the browser's print dialog. For one-click PDF, drop Dompdf into <code>vendor/dompdf/</code>.</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="bi bi-file-earmark-pdf text-primary me-1"></i>Brochures</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Title</th><th>Template</th><th>Jobs</th><th>Author</th><th>Updated</th><th class="text-end">Action</th></tr></thead>
            <tbody>
                <?php if ($rows === []): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No brochures yet.</div></td></tr><?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-semibold"><a href="/loi_brochures.php?open=<?= (int) $r['id'] ?>" class="text-decoration-none"><?= esc((string) $r['title']) ?></a></td>
                    <td class="small"><?= esc((string) ($r['template_name'] ?? '—')) ?></td>
                    <td class="small"><?= (int) $r['job_count'] ?></td>
                    <td class="small"><?= esc((string) ($r['created_by_name'] ?? '—')) ?></td>
                    <td class="small text-muted"><?= esc(date('d/m/Y H:i', strtotime((string) ($r['updated_at'] ?? $r['created_at'] ?? 'now')))) ?></td>
                    <td class="text-end">
                        <div class="d-inline-flex gap-1">
                            <a class="btn btn-sm btn-success" target="_blank" href="/loi_brochure_pdf.php?id=<?= (int) $r['id'] ?>" title="Generate PDF"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                            <a class="btn btn-sm btn-outline-primary" href="/loi_brochures.php?open=<?= (int) $r['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                            <?php if ($isAdmin || (int) ($r['created_by'] ?? 0) === $viewerId): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this brochure? The template and the jobs stay — only this brochure is dropped.');">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="brModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl"><div class="modal-content">
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="brId" value="<?= $editBrochure ? (int) $editBrochure['id'] : 0 ?>">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-file-earmark-pdf me-1"></i><?= $editBrochure ? 'Edit brochure' : 'New brochure' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-7"><label class="form-label">Title <span class="text-danger">*</span></label><input class="form-control" id="brTitle" name="title" required maxlength="300" value="<?= esc($editBrochure ? (string) $editBrochure['title'] : '') ?>"></div>
                    <div class="col-md-5"><label class="form-label">Template</label>
                        <select class="form-select" id="brTemplate" name="template_id">
                            <option value="0">— pick a template —</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int) $t['id'] ?>" <?= $editBrochure && (int) ($editBrochure['template_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= esc((string) $t['name']) ?><?= (int) $t['is_default'] === 1 ? ' (default)' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Notes <span class="small text-muted">(internal, not printed)</span></label><textarea class="form-control" id="brNotes" name="notes" rows="2"><?= esc($editBrochure ? (string) ($editBrochure['notes'] ?? '') : '') ?></textarea></div>
                    <div class="col-12">
                        <label class="form-label d-flex justify-content-between align-items-center">
                            <span>Jobs to include</span>
                            <input type="text" class="form-control form-control-sm" id="brJobSearch" placeholder="Search by role or employer…" style="width: 280px;">
                        </label>
                        <div class="border rounded p-2" style="max-height: 320px; overflow-y: auto;">
                            <?php if ($jobs === []): ?><div class="text-muted small">No active jobs yet — create jobs under Employers first.</div><?php endif; ?>
                            <?php foreach ($jobs as $j):
                                $checked = in_array((int) $j['id'], $editJobIds, true);
                                $label   = (string) $j['job_role'] . ' · ' . (string) $j['employer_name'];
                                if (!empty($j['vacancy_count'])) $label .= ' · ' . (int) $j['vacancy_count'] . ' openings';
                            ?>
                                <div class="form-check js-br-job-row" data-search="<?= esc(strtolower($label)) ?>">
                                    <input class="form-check-input" type="checkbox" id="brJob<?= (int) $j['id'] ?>" name="job_ids[]" value="<?= (int) $j['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="brJob<?= (int) $j['id'] ?>"><?= esc($label) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save brochure</button></div>
        </form>
    </div></div>
</div>

<script>
(function () {
    const m = document.getElementById('brModal');
    window.__brNew = () => {
        document.getElementById('brId').value = '0';
        document.getElementById('brTitle').value = '';
        document.getElementById('brNotes').value = '';
        document.getElementById('brTemplate').value = '<?= (int) ($templates[0]['id'] ?? 0) ?>';
        document.querySelectorAll('input[name="job_ids[]"]').forEach(cb => cb.checked = false);
    };
    document.getElementById('brJobSearch')?.addEventListener('input', (e) => {
        const q = e.target.value.toLowerCase().trim();
        document.querySelectorAll('.js-br-job-row').forEach(r => {
            r.style.display = (q === '' || r.dataset.search.includes(q)) ? '' : 'none';
        });
    });
    <?php if ($editBrochure !== null): ?>
    // Auto-open on ?open=
    const modal = new bootstrap.Modal(m);
    modal.show();
    <?php endif; ?>
})();
</script>

<?php render_footer(); ?>
