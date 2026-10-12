<?php
/**
 * LOI brochure · Template editor.
 *
 * One page at a time:
 *   • upload an A4 background image
 *   • add free-form labels (text with {placeholders}, font size, colour,
 *     weight, align, optional width)
 *   • drag each label on the canvas to position (x/y)
 *   • save — persists the labels array into loi_brochure_page.config_json
 *
 * Admin-only. The brochure generator reads these pages verbatim.
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

if (!is_manage_admin($viewer) && !user_can_admin_module($viewerId, 'loi_jobs')) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only an LOI & Jobs admin can edit brochure templates.</div>';
    render_footer(); exit;
}

$templateId = (int) ($_GET['id'] ?? 0);
if ($templateId <= 0) { header('Location: /loi_brochure_templates.php'); exit; }

$t = db()->prepare('SELECT * FROM loi_brochure_template WHERE id = ? LIMIT 1');
$t->execute([$templateId]);
$template = $t->fetch();
if ($template === false) { header('Location: /loi_brochure_templates.php'); exit; }

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['loi_bte_flash']) && is_array($_SESSION['loi_bte_flash'])) {
    $flashMessage = (string) ($_SESSION['loi_bte_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['loi_bte_flash']['type'] ?? 'success');
    unset($_SESSION['loi_bte_flash']);
}
$flashAndBack = static function (string $msg, string $type, int $tplId, int $pageId = 0): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['loi_bte_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /loi_brochure_template_edit.php?id=' . $tplId . ($pageId > 0 ? '&page=' . $pageId : ''));
    exit;
};

/* ---------- Page CRUD ---------- */
if (is_post() && ($_POST['action'] ?? '') === 'add_page') {
    csrf_check_or_die();
    $maxSt = db()->prepare('SELECT COALESCE(MAX(page_number), 0) FROM loi_brochure_page WHERE template_id = ?');
    $maxSt->execute([$templateId]);
    $next = (int) ($maxSt->fetchColumn() ?: 0) + 1;
    $name = trim((string) ($_POST['name'] ?? '')) ?: ('Page ' . $next);
    db()->prepare('INSERT INTO loi_brochure_page (template_id, page_number, name, config_json, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
        ->execute([$templateId, $next, $name, json_encode(['labels' => []])]);
    $flashAndBack('Page added.', 'success', $templateId, (int) db()->lastInsertId());
}
if (is_post() && ($_POST['action'] ?? '') === 'delete_page') {
    csrf_check_or_die();
    $pid = (int) ($_POST['page_id'] ?? 0);
    if ($pid > 0) {
        $pg = db()->prepare('SELECT background_image_path FROM loi_brochure_page WHERE id = ? AND template_id = ?');
        $pg->execute([$pid, $templateId]);
        $path = (string) ($pg->fetchColumn() ?: '');
        db()->prepare('DELETE FROM loi_brochure_page WHERE id = ? AND template_id = ?')->execute([$pid, $templateId]);
        if ($path !== '' && is_file(__DIR__ . '/' . ltrim($path, '/'))) @unlink(__DIR__ . '/' . ltrim($path, '/'));
    }
    $flashAndBack('Page deleted.', 'success', $templateId);
}
if (is_post() && ($_POST['action'] ?? '') === 'upload_bg') {
    csrf_check_or_die();
    $pid = (int) ($_POST['page_id'] ?? 0);
    if ($pid <= 0 || empty($_FILES['bg']) || (int) ($_FILES['bg']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashAndBack('Pick a PNG / JPG background first.', 'danger', $templateId, $pid);
    }
    $ext = strtolower(pathinfo((string) $_FILES['bg']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png','jpg','jpeg','webp'], true)) $flashAndBack('Background must be PNG / JPG / WebP.', 'danger', $templateId, $pid);
    $dir  = LOI_BROCHURE_UPLOAD_DIR . '/tpl_' . $templateId;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = 'page_' . $pid . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    if (!move_uploaded_file((string) $_FILES['bg']['tmp_name'], $dest)) {
        $flashAndBack('Could not save the uploaded file.', 'danger', $templateId, $pid);
    }
    $rel = 'uploads/loi_brochure/tpl_' . $templateId . '/' . $name;
    // Drop the previous background on disk (keep history minimal).
    $old = db()->prepare('SELECT background_image_path FROM loi_brochure_page WHERE id = ? AND template_id = ?');
    $old->execute([$pid, $templateId]);
    $oldPath = (string) ($old->fetchColumn() ?: '');
    if ($oldPath !== '' && $oldPath !== $rel && is_file(__DIR__ . '/' . ltrim($oldPath, '/'))) @unlink(__DIR__ . '/' . ltrim($oldPath, '/'));
    db()->prepare('UPDATE loi_brochure_page SET background_image_path = ?, updated_at = NOW() WHERE id = ? AND template_id = ?')
        ->execute([$rel, $pid, $templateId]);
    $flashAndBack('Background uploaded.', 'success', $templateId, $pid);
}
if (is_post() && ($_POST['action'] ?? '') === 'save_labels') {
    csrf_check_or_die();
    $pid = (int) ($_POST['page_id'] ?? 0);
    $json = (string) ($_POST['config_json'] ?? '{"labels":[]}');
    // Validate shape — refuse anything that isn't a labels array.
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !isset($decoded['labels']) || !is_array($decoded['labels'])) {
        $flashAndBack('Invalid label data.', 'danger', $templateId, $pid);
    }
    db()->prepare('UPDATE loi_brochure_page SET config_json = ?, updated_at = NOW() WHERE id = ? AND template_id = ?')
        ->execute([json_encode($decoded, JSON_UNESCAPED_UNICODE), $pid, $templateId]);
    $flashAndBack('Labels saved.', 'success', $templateId, $pid);
}
if (is_post() && ($_POST['action'] ?? '') === 'rename_page') {
    csrf_check_or_die();
    $pid = (int) ($_POST['page_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($pid > 0 && $name !== '') {
        db()->prepare('UPDATE loi_brochure_page SET name = ?, updated_at = NOW() WHERE id = ? AND template_id = ?')
            ->execute([$name, $pid, $templateId]);
    }
    $flashAndBack('Page renamed.', 'success', $templateId, $pid);
}

/* ---------- Load state ---------- */
$pages = loi_brochure_template_pages($templateId);
$currentPageId = (int) ($_GET['page'] ?? 0);
if ($currentPageId === 0 && $pages !== []) $currentPageId = (int) $pages[0]['id'];
$currentPage = null;
foreach ($pages as $p) if ((int) $p['id'] === $currentPageId) { $currentPage = $p; break; }

$knownPlaceholders = loi_brochure_known_placeholders();
$labels = $currentPage ? loi_brochure_page_labels($currentPage) : [];

render_header('LOI brochure · ' . $template['name'], ['main_container_class' => 'container-xl']);
render_page_header('Brochure template · ' . $template['name'], [
    'icon' => 'bi-file-earmark-image',
    'subtitle' => 'Add pages, upload A4 backgrounds, drag free-text labels. Each label supports {placeholders} resolved at render time against the brochure\'s selected jobs.',
    'actions' => '<a class="btn btn-light" href="/loi_brochure_templates.php"><i class="bi bi-arrow-left me-1"></i>All templates</a>',
]);
?>

<?php if ($flashMessage !== null): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div><?php endif; ?>

<div class="row g-3">
    <!-- Left: page list -->
    <div class="col-lg-3">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-collection me-1"></i>Pages</span>
                <form method="post" class="d-inline">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="add_page">
                    <button class="btn btn-sm btn-outline-primary" title="Add page"><i class="bi bi-plus-lg"></i></button>
                </form>
            </div>
            <ul class="list-group list-group-flush">
                <?php if ($pages === []): ?><li class="list-group-item small text-muted">No pages yet — click <strong>+</strong> above.</li><?php endif; ?>
                <?php foreach ($pages as $p):
                    $active = (int) $p['id'] === $currentPageId;
                ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center <?= $active ? 'active' : '' ?>">
                        <a class="text-decoration-none <?= $active ? 'text-white' : '' ?>" href="?id=<?= $templateId ?>&page=<?= (int) $p['id'] ?>">
                            <span class="badge <?= $active ? 'bg-light text-dark' : 'bg-secondary' ?> me-2"><?= (int) $p['page_number'] ?></span>
                            <?= esc((string) ($p['name'] ?? '')) ?>
                        </a>
                        <?php if (count($pages) > 1): ?>
                        <form method="post" class="d-inline m-0 p-0" onsubmit="return confirm('Delete this page? The background image on disk is removed too.');">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="action" value="delete_page">
                            <input type="hidden" name="page_id" value="<?= (int) $p['id'] ?>">
                            <button class="btn btn-sm btn-link <?= $active ? 'text-white' : 'text-danger' ?>" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($currentPage !== null): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-image me-1"></i>Background (A4)</div>
            <div class="card-body">
                <?php if (!empty($currentPage['background_image_path'])): ?>
                    <img src="/<?= esc((string) $currentPage['background_image_path']) ?>" alt="Background" class="img-fluid border mb-2" style="max-height: 180px;">
                <?php else: ?>
                    <div class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>No background uploaded yet.</div>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="upload_bg">
                    <input type="hidden" name="page_id" value="<?= (int) $currentPage['id'] ?>">
                    <div class="input-group input-group-sm">
                        <input type="file" class="form-control" name="bg" accept=".png,.jpg,.jpeg,.webp" required>
                        <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload</button>
                    </div>
                    <div class="small text-muted mt-1">A4 recommended (210×297 mm → 794×1123 px @ 96dpi).</div>
                </form>

                <hr>
                <form method="post" class="mt-2">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="rename_page">
                    <input type="hidden" name="page_id" value="<?= (int) $currentPage['id'] ?>">
                    <label class="form-label small mb-1">Page name</label>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" name="name" value="<?= esc((string) ($currentPage['name'] ?? '')) ?>">
                        <button class="btn btn-outline-primary"><i class="bi bi-check2"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-braces me-1"></i>Placeholders</div>
            <div class="card-body small">
                <div class="text-muted mb-2">Click to copy — paste into any label's text.</div>
                <?php foreach ($knownPlaceholders as $k): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary me-1 mb-1 js-copy-ph" data-ph="{<?= esc($k) ?>}">{<?= esc($k) ?>}</button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: canvas + label editor -->
    <div class="col-lg-9">
        <?php if ($currentPage === null): ?>
            <div class="alert alert-info">Pick a page on the left to edit it.</div>
        <?php else: ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-aspect-ratio me-1"></i>Canvas · <?= esc((string) ($currentPage['name'] ?? '')) ?></span>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddLabel"><i class="bi bi-plus-lg me-1"></i>Add label</button>
                    <button type="button" class="btn btn-sm btn-primary" id="btnSaveLabels"><i class="bi bi-save me-1"></i>Save labels</button>
                </div>
            </div>
            <div class="card-body">
                <div class="text-muted small mb-2"><i class="bi bi-hand-index me-1"></i>Drag labels on the canvas. Click a label to edit its text / font / colour.</div>
                <div id="canvasWrap" style="position:relative; width: <?= (int) LOI_BROCHURE_A4_WIDTH_PX ?>px; height: <?= (int) LOI_BROCHURE_A4_HEIGHT_PX ?>px; border: 1px solid #ccc; background: #f8fafc; overflow: hidden;">
                    <?php if (!empty($currentPage['background_image_path'])): ?>
                        <img src="/<?= esc((string) $currentPage['background_image_path']) ?>" alt="" style="position:absolute; inset:0; width:100%; height:100%; object-fit: contain; user-select:none; pointer-events: none;">
                    <?php else: ?>
                        <div style="position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size: 14px;">No background uploaded — labels will still render on a blank page.</div>
                    <?php endif; ?>
                    <!-- labels get injected here as absolutely-positioned divs -->
                </div>
            </div>
        </div>

        <!-- Label editor popover (not a real popover — a simple side panel). -->
        <div class="card mt-3" id="labelPanel" style="display:none;">
            <div class="card-header"><i class="bi bi-pencil-square me-1"></i>Edit label</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-12"><label class="form-label small">Text (free form; supports {placeholders} and HTML)</label><textarea class="form-control" id="lblText" rows="2"></textarea></div>
                    <div class="col-md-2"><label class="form-label small">X (px)</label><input type="number" class="form-control" id="lblX"></div>
                    <div class="col-md-2"><label class="form-label small">Y (px)</label><input type="number" class="form-control" id="lblY"></div>
                    <div class="col-md-2"><label class="form-label small">Width (px, opt)</label><input type="number" class="form-control" id="lblW"></div>
                    <div class="col-md-2"><label class="form-label small">Font size (pt)</label><input type="number" class="form-control" id="lblSize" value="14"></div>
                    <div class="col-md-2"><label class="form-label small">Colour</label><input type="color" class="form-control form-control-color" id="lblColor" value="#000000"></div>
                    <div class="col-md-2"><label class="form-label small">Weight</label>
                        <select class="form-select" id="lblWeight"><option value="normal">Normal</option><option value="bold">Bold</option></select>
                    </div>
                    <div class="col-md-3"><label class="form-label small">Align</label>
                        <select class="form-select" id="lblAlign"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
                    </div>
                    <div class="col-md-9 d-flex align-items-end gap-2">
                        <button type="button" class="btn btn-sm btn-primary" id="lblApply"><i class="bi bi-check2 me-1"></i>Apply</button>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="lblDelete"><i class="bi bi-trash me-1"></i>Delete label</button>
                        <button type="button" class="btn btn-sm btn-light ms-auto" id="lblClose">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($currentPage !== null): ?>
<form method="post" id="saveForm" style="display:none;">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save_labels">
    <input type="hidden" name="page_id" value="<?= (int) $currentPage['id'] ?>">
    <input type="hidden" name="config_json" id="configJsonField" value="">
</form>

<script>
(function () {
    const PAGE_W = <?= (int) LOI_BROCHURE_A4_WIDTH_PX ?>;
    const PAGE_H = <?= (int) LOI_BROCHURE_A4_HEIGHT_PX ?>;
    const wrap   = document.getElementById('canvasWrap');
    const panel  = document.getElementById('labelPanel');

    // Initial state loaded from the server.
    let labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?> || [];
    labels = labels.map((l, i) => Object.assign({id: 'l' + Date.now() + '_' + i}, l));

    let activeId = null, dragging = null, dragOff = {x: 0, y: 0};

    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));

    const renderCanvas = () => {
        // Nuke existing labels, keep the <img> background.
        wrap.querySelectorAll('.cv-label').forEach(el => el.remove());
        labels.forEach(l => {
            const d = document.createElement('div');
            d.className = 'cv-label';
            d.dataset.id = l.id;
            d.style.cssText = `position:absolute; left:${l.x||0}px; top:${l.y||0}px; ` +
                (l.width ? `width:${l.width}px;` : '') +
                `font-size:${l.font_size||14}pt; color:${l.color||'#000'}; ` +
                `font-weight:${l.weight||'normal'}; text-align:${l.align||'left'}; ` +
                `cursor:move; padding:2px 4px; border:1px dashed transparent;`;
            d.innerHTML = l.text || '<em style="color:#999;">(empty)</em>';
            if (l.id === activeId) { d.style.border = '1px dashed #3b82f6'; d.style.background = 'rgba(59,130,246,.07)'; }
            d.addEventListener('mousedown', (e) => {
                dragging = l.id;
                const rect = wrap.getBoundingClientRect();
                dragOff.x = e.clientX - rect.left - (l.x || 0);
                dragOff.y = e.clientY - rect.top  - (l.y || 0);
                e.preventDefault();
            });
            d.addEventListener('click', (e) => { if (!moved) openLabel(l.id); });
            wrap.appendChild(d);
        });
    };

    let moved = false;
    document.addEventListener('mousemove', (e) => {
        if (dragging === null) return;
        moved = true;
        const rect = wrap.getBoundingClientRect();
        const nx = Math.max(0, Math.min(PAGE_W - 10, e.clientX - rect.left - dragOff.x));
        const ny = Math.max(0, Math.min(PAGE_H - 10, e.clientY - rect.top  - dragOff.y));
        const idx = labels.findIndex(x => x.id === dragging);
        if (idx === -1) return;
        labels[idx].x = Math.round(nx);
        labels[idx].y = Math.round(ny);
        const el = wrap.querySelector(`.cv-label[data-id="${dragging}"]`);
        if (el) { el.style.left = labels[idx].x + 'px'; el.style.top = labels[idx].y + 'px'; }
    });
    document.addEventListener('mouseup', () => {
        if (dragging !== null && activeId === dragging) { syncPanelFromActive(); }
        dragging = null;
        setTimeout(() => { moved = false; }, 50);
    });

    const openLabel = (id) => {
        activeId = id;
        panel.style.display = '';
        syncPanelFromActive();
        renderCanvas();
    };
    const syncPanelFromActive = () => {
        const l = labels.find(x => x.id === activeId); if (!l) return;
        document.getElementById('lblText').value   = l.text || '';
        document.getElementById('lblX').value      = Math.round(l.x || 0);
        document.getElementById('lblY').value      = Math.round(l.y || 0);
        document.getElementById('lblW').value      = l.width || '';
        document.getElementById('lblSize').value   = l.font_size || 14;
        document.getElementById('lblColor').value  = l.color || '#000000';
        document.getElementById('lblWeight').value = l.weight || 'normal';
        document.getElementById('lblAlign').value  = l.align || 'left';
    };

    document.getElementById('btnAddLabel').addEventListener('click', () => {
        const l = {id: 'l' + Date.now(), text: 'New label — click to edit', x: 60, y: 60, font_size: 14, color: '#000000', weight: 'normal', align: 'left'};
        labels.push(l); activeId = l.id; renderCanvas(); openLabel(l.id);
    });
    document.getElementById('lblApply').addEventListener('click', () => {
        const l = labels.find(x => x.id === activeId); if (!l) return;
        l.text      = document.getElementById('lblText').value;
        l.x         = parseInt(document.getElementById('lblX').value || '0', 10);
        l.y         = parseInt(document.getElementById('lblY').value || '0', 10);
        const w     = document.getElementById('lblW').value;
        l.width     = w === '' ? null : parseInt(w, 10);
        l.font_size = parseFloat(document.getElementById('lblSize').value || '14');
        l.color     = document.getElementById('lblColor').value;
        l.weight    = document.getElementById('lblWeight').value;
        l.align     = document.getElementById('lblAlign').value;
        renderCanvas();
    });
    document.getElementById('lblDelete').addEventListener('click', () => {
        if (!confirm('Delete this label?')) return;
        labels = labels.filter(x => x.id !== activeId);
        activeId = null; panel.style.display = 'none'; renderCanvas();
    });
    document.getElementById('lblClose').addEventListener('click', () => { activeId = null; panel.style.display = 'none'; renderCanvas(); });

    document.getElementById('btnSaveLabels').addEventListener('click', () => {
        // Strip the internal `id` field — it's regenerated on load.
        const out = labels.map(({id, ...rest}) => rest);
        document.getElementById('configJsonField').value = JSON.stringify({labels: out});
        document.getElementById('saveForm').submit();
    });

    document.querySelectorAll('.js-copy-ph').forEach(b => b.addEventListener('click', () => {
        const t = b.getAttribute('data-ph');
        try { navigator.clipboard.writeText(t); b.textContent = 'Copied ' + t; setTimeout(() => { b.textContent = t; }, 1200); } catch (e) {}
    }));

    renderCanvas();
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
