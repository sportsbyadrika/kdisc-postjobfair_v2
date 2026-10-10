<?php
/**
 * Administration · Dashboard settings.
 *
 * Toggle which card groups render on the main /dashboard.php page.
 * Admin-only. CSRF checked on save. Unknown card codes stay visible
 * by default — the toggle only records explicit overrides.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/dashboard_helpers.php';
require_auth();

$viewer = current_user() ?? [];
if (!is_manage_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only Administrator / DSM Admin can change dashboard settings.</div>';
    render_footer();
    exit;
}
$viewerId = (int) $viewer['id'];
dashboard_cards_bootstrap();
dashboard_menus_bootstrap();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['dash_settings_flash']) && is_array($_SESSION['dash_settings_flash'])) {
    $flashMessage = (string) ($_SESSION['dash_settings_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['dash_settings_flash']['type'] ?? 'success');
    unset($_SESSION['dash_settings_flash']);
}
$flashAndBack = static function (string $msg, string $type = 'success'): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['dash_settings_flash'] = ['msg' => $msg, 'type' => $type];
    header('Location: /dashboard_settings.php');
    exit;
};

if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $submitted      = (array) ($_POST['visible']      ?? []); // card_code => '1'
    $order          = (array) ($_POST['order']        ?? []); // ordered card_codes
    $submittedMenus = (array) ($_POST['menu_visible'] ?? []); // menu_code => '1'
    try {
        // Cards — visibility + sort order (position in posted list).
        $up = db()->prepare('UPDATE dashboard_card_visibility SET is_visible = ?, sort_order = ?, updated_at = NOW(), updated_by = ? WHERE card_code = ?');
        $rank = [];
        $step = 10;
        foreach ($order as $i => $code) $rank[(string) $code] = ($i + 1) * $step;
        foreach (dashboard_all_cards() as $c) {
            $on = isset($submitted[$c['code']]) ? 1 : 0;
            $sortOrder = $rank[$c['code']] ?? 10000;
            $up->execute([$on, $sortOrder, $viewerId, $c['code']]);
        }
        // Menu groups — visibility only (order lives in the layout).
        $upm = db()->prepare('UPDATE dashboard_menu_visibility SET is_visible = ?, updated_at = NOW(), updated_by = ? WHERE menu_code = ?');
        foreach (dashboard_all_menus() as $m) {
            $on = isset($submittedMenus[$m['code']]) ? 1 : 0;
            $upm->execute([$on, $viewerId, $m['code']]);
        }
        $flashAndBack('Dashboard settings updated.');
    } catch (Throwable $e) {
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}

$ordered = dashboard_ordered_cards();
$menus   = dashboard_menus_decorated();

render_header('Administration · Dashboard settings', ['main_container_class' => 'container-xl']);
render_page_header('Administration · Dashboard settings', [
    'icon'     => 'bi-sliders2',
    'subtitle' => 'Toggle which card groups render on the main dashboard. Applies to every viewer immediately.',
    'actions'  => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Open Dashboard</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<form method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save">

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-toggles text-primary me-1"></i>Card groups on the dashboard</span>
            <span class="small text-muted">Tick to show · untick to hide · drag the <i class="bi bi-grip-vertical"></i> handle to reorder</span>
        </div>
        <div class="card-body">
            <div id="cardRowsWrap">
                <?php foreach ($ordered as $c): ?>
                    <div class="ds-row d-flex align-items-start gap-2 py-2 border-bottom" data-code="<?= esc($c['code']) ?>">
                        <div class="ds-handle text-muted" style="cursor: grab; padding-top: 2px;" title="Drag to reorder">
                            <i class="bi bi-grip-vertical fs-5"></i>
                        </div>
                        <div class="form-check form-switch flex-grow-1">
                            <input class="form-check-input" type="checkbox" role="switch"
                                   id="card_<?= esc($c['code']) ?>"
                                   name="visible[<?= esc($c['code']) ?>]" value="1"
                                   <?= $c['is_visible'] ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="card_<?= esc($c['code']) ?>">
                                <?= esc((string) $c['label']) ?>
                            </label>
                            <div class="small text-muted"><?= esc((string) ($c['description'] ?? '')) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div id="orderHiddenWrap"></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-menu-button-wide text-primary me-1"></i>Top-nav menu groups</span>
            <span class="small text-muted">Tick to show · untick to hide the whole module from the top navigation</span>
        </div>
        <div class="card-body">
            <?php foreach ($menus as $m): ?>
                <div class="d-flex align-items-start gap-2 py-2 border-bottom">
                    <div class="form-check form-switch flex-grow-1">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="menu_<?= esc($m['code']) ?>"
                               name="menu_visible[<?= esc($m['code']) ?>]" value="1"
                               <?= $m['is_visible'] ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="menu_<?= esc($m['code']) ?>">
                            <?= esc((string) $m['label']) ?>
                        </label>
                        <div class="small text-muted"><?= esc((string) ($m['description'] ?? '')) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="small text-muted mt-2"><i class="bi bi-info-circle me-1"></i>Hiding a group here does <strong>not</strong> change role-based access — users without the module role still won't see it. Administration is intentionally not listed so you can't accidentally lock yourself out of these settings.</div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2">
        <a class="btn btn-light" href="/dashboard.php">Cancel</a>
        <button class="btn btn-primary" type="submit" id="dsSave"><i class="bi bi-check2-circle me-1"></i>Save</button>
    </div>
</form>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const wrap = document.getElementById('cardRowsWrap');
    const hidden = document.getElementById('orderHiddenWrap');
    if (!wrap || typeof Sortable === 'undefined') return;

    const rebuildHidden = () => {
        hidden.innerHTML = '';
        wrap.querySelectorAll('.ds-row').forEach(row => {
            const code = row.getAttribute('data-code');
            const h = document.createElement('input');
            h.type = 'hidden'; h.name = 'order[]'; h.value = code;
            hidden.appendChild(h);
        });
    };
    new Sortable(wrap, {
        animation: 150,
        handle: '.ds-handle',
        ghostClass: 'ds-ghost',
        chosenClass: 'ds-chosen',
        forceFallback: true,
        onEnd: rebuildHidden,
    });
    rebuildHidden(); // seed on load so save works even without dragging
});
</script>

<style>
.ds-row { user-select: none; }
.ds-row.ds-chosen { background: #f0f6ff; }
.ds-row.ds-ghost  { opacity: .5; }
</style>

<?php render_footer(); ?>
