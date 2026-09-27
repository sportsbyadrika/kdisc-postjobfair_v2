<?php
/**
 * Administration → Settings → MoM branding.
 *
 * Configures the logo URL, heading, sub-heading and footer that
 * render at the top and bottom of every Minutes-of-Meeting print
 * view. Admin-only.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_auth();

$viewer = current_user() ?? [];
if (!is_manage_admin($viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only Administrator / DSM Admin can change MoM settings.</div>';
    render_footer();
    exit;
}
$viewerId = (int) $viewer['id'];
meetings_bootstrap();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flash = null; $flashType = 'success';
if (!empty($_SESSION['mom_flash']) && is_array($_SESSION['mom_flash'])) {
    $flash     = (string) ($_SESSION['mom_flash']['msg']  ?? '');
    $flashType = (string) ($_SESSION['mom_flash']['type'] ?? 'success');
    unset($_SESSION['mom_flash']);
}
if (is_post() && ($_POST['action'] ?? '') === 'save') {
    csrf_check_or_die();
    $keys = ['mom_logo_url', 'mom_heading', 'mom_sub_heading', 'mom_footer'];
    try {
        $up = db()->prepare('UPDATE meeting_setting SET setting_value = ?, updated_at = NOW(), updated_by = ? WHERE setting_key = ?');
        foreach ($keys as $k) $up->execute([trim((string) ($_POST[$k] ?? '')), $viewerId, $k]);
        $_SESSION['mom_flash'] = ['msg' => 'Saved.', 'type' => 'success'];
    } catch (Throwable $e) {
        $_SESSION['mom_flash'] = ['msg' => 'Save failed: ' . $e->getMessage(), 'type' => 'danger'];
    }
    header('Location: /meeting_settings.php'); exit;
}

render_header('MoM report settings', ['main_container_class' => 'container-md']);
render_page_header('MoM report settings', [
    'icon'     => 'bi-file-earmark-text',
    'subtitle' => 'Configure the logo, heading, sub-heading and footer that render on every Minutes-of-Meeting print view.',
    'actions'  => '<a class="btn btn-light" href="/meetings.php"><i class="bi bi-arrow-left me-1"></i>Back to Meetings</a>',
]);
?>

<?php if ($flash !== null): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flash) ?></div><?php endif; ?>

<form method="post" class="card">
    <?php csrf_field(); ?>
    <input type="hidden" name="action" value="save">
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label" for="mom_logo_url">Logo URL</label>
            <input type="url" class="form-control" id="mom_logo_url" name="mom_logo_url" value="<?= esc(meetings_setting('mom_logo_url')) ?>" placeholder="https://example.com/logo.png">
            <div class="small text-muted mt-1">External URL. Loads at the top-left of every MoM. Leave blank to skip.</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="mom_heading">Heading</label>
            <input type="text" class="form-control" id="mom_heading" name="mom_heading" value="<?= esc(meetings_setting('mom_heading')) ?>" maxlength="300">
        </div>
        <div class="mb-3">
            <label class="form-label" for="mom_sub_heading">Sub-heading</label>
            <input type="text" class="form-control" id="mom_sub_heading" name="mom_sub_heading" value="<?= esc(meetings_setting('mom_sub_heading')) ?>" maxlength="300">
        </div>
        <div class="mb-3">
            <label class="form-label" for="mom_footer">Footer</label>
            <textarea class="form-control" id="mom_footer" name="mom_footer" rows="2" maxlength="500"><?= esc(meetings_setting('mom_footer')) ?></textarea>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-end gap-2">
        <a class="btn btn-light" href="/meetings.php">Cancel</a>
        <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save</button>
    </div>
</form>

<?php render_footer(); ?>
