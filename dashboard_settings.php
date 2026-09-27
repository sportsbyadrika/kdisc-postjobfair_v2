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
    $submitted = (array) ($_POST['visible'] ?? []); // card_code => '1' when checked
    try {
        $up = db()->prepare('UPDATE dashboard_card_visibility SET is_visible = ?, updated_at = NOW(), updated_by = ? WHERE card_code = ?');
        foreach (dashboard_all_cards() as $c) {
            $on = isset($submitted[$c['code']]) ? 1 : 0;
            $up->execute([$on, $viewerId, $c['code']]);
        }
        $flashAndBack('Dashboard settings updated.');
    } catch (Throwable $e) {
        $flashAndBack('Save failed: ' . $e->getMessage(), 'danger');
    }
}

$current = [];
try {
    foreach (db()->query('SELECT card_code, is_visible FROM dashboard_card_visibility')->fetchAll() as $r) {
        $current[(string) $r['card_code']] = ((int) $r['is_visible']) === 1;
    }
} catch (Throwable $e) { /* table missing */ }

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

<form method="post" class="card">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-toggles text-primary me-1"></i>Card groups on the dashboard</span>
        <span class="small text-muted">Tick to show · untick to hide</span>
    </div>
    <div class="card-body">
        <?php foreach (dashboard_all_cards() as $c):
            $on = array_key_exists($c['code'], $current) ? $current[$c['code']] : true;
        ?>
            <div class="form-check form-switch py-2 border-bottom">
                <input class="form-check-input" type="checkbox" role="switch"
                       id="card_<?= esc($c['code']) ?>"
                       name="visible[<?= esc($c['code']) ?>]" value="1"
                       <?= $on ? 'checked' : '' ?>>
                <label class="form-check-label fw-semibold" for="card_<?= esc($c['code']) ?>">
                    <?= esc((string) $c['label']) ?>
                </label>
                <div class="small text-muted"><?= esc((string) ($c['description'] ?? '')) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a class="btn btn-light" href="/dashboard.php">Cancel</a>
        <button class="btn btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button>
    </div>
</form>

<?php render_footer(); ?>
