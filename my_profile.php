<?php
/**
 * My Profile — self-service page.
 *
 * Any logged-in user can edit the basic fields on their own row and
 * upload / crop their passport photo. Admin-only fields (role, office,
 * assigned districts, module grants, active status, super-admin flag)
 * are displayed read-only — those still live on /users.php. Password
 * changes stay on /change_password.php, linked from the sidebar below.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/user_photo_helpers.php';
require_auth();

$viewer = current_user();
$uid    = (int) $viewer['id'];
$flash = null; $flashType = 'success';

/* ---------- POST handlers ---------- */
if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_profile') {
        $name        = trim((string) ($_POST['name'] ?? ''));
        $mobile      = trim((string) ($_POST['mobile_number'] ?? ''));
        $email       = trim((string) ($_POST['email'] ?? ''));
        $address     = trim((string) ($_POST['address'] ?? ''));
        $designation = trim((string) ($_POST['designation'] ?? ''));
        $photoData   = (string) ($_POST['photo_cropped_data'] ?? '');

        if ($name === '')   { $flash = 'Name is required.';   $flashType = 'danger'; }
        elseif ($mobile === '') { $flash = 'Mobile number is required.'; $flashType = 'danger'; }
        else {
            db()->prepare('UPDATE users SET name = ?, mobile_number = ?, email = ?, address = ?, designation = ?, updated_at = NOW(), modified_by = ? WHERE id = ?')
                ->execute([
                    $name, $mobile,
                    $email === '' ? null : $email,
                    $address === '' ? null : $address,
                    $designation === '' ? null : $designation,
                    $uid, $uid,
                ]);

            if ($photoData !== '') {
                $stored = save_cropped_photo($photoData, $uid);
                if ($stored !== null) {
                    $prev = db()->prepare('SELECT photo_path FROM users WHERE id = ?');
                    $prev->execute([$uid]);
                    $prevPath = (string) ($prev->fetchColumn() ?: '');
                    if ($prevPath !== '' && $prevPath !== $stored && is_file(__DIR__ . '/' . ltrim($prevPath, '/'))) {
                        @unlink(__DIR__ . '/' . ltrim($prevPath, '/'));
                    }
                    db()->prepare('UPDATE users SET photo_path = ?, updated_at = NOW() WHERE id = ?')->execute([$stored, $uid]);
                } else {
                    $flash = 'Profile saved, but the photo could not be stored. Please try again.';
                    $flashType = 'warning';
                }
            }

            // Refresh in-session user so the top-right avatar updates immediately.
            $fresh = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
            $fresh->execute([$uid]);
            $r = $fresh->fetch();
            if ($r) $_SESSION['user'] = $r;

            if ($flash === null) $flash = 'Profile updated.';
        }
    }

    if ($action === 'remove_photo') {
        $prev = db()->prepare('SELECT photo_path FROM users WHERE id = ?');
        $prev->execute([$uid]);
        $prevPath = (string) ($prev->fetchColumn() ?: '');
        if ($prevPath !== '' && is_file(__DIR__ . '/' . ltrim($prevPath, '/'))) {
            @unlink(__DIR__ . '/' . ltrim($prevPath, '/'));
        }
        db()->prepare('UPDATE users SET photo_path = NULL, updated_at = NOW() WHERE id = ?')->execute([$uid]);
        $fresh = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $fresh->execute([$uid]);
        $r = $fresh->fetch();
        if ($r) $_SESSION['user'] = $r;
        $flash = 'Photo removed. The initials avatar will be shown again until you upload a new photo.';
    }
}

/* ---------- Load row for display ---------- */
$meStmt = db()->prepare("SELECT u.*, o.name AS office_name
    FROM users u
    LEFT JOIN office_hierarchy_nodes o ON o.id = u.office_id AND o.level_type = 'office'
    WHERE u.id = ? LIMIT 1");
$meStmt->execute([$uid]);
$me = $meStmt->fetch() ?: [];

$assigned = (string) ($me['assigned_districts'] ?? '');
$districtList = $assigned === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $assigned))));

render_header('My Profile');
render_page_header('My Profile', [
    'icon' => 'bi-person-circle',
    'subtitle' => 'Update your basic details and passport photo. Role, office, districts, and module access are managed by an administrator.',
    'actions' => '<a class="btn btn-light" href="/change_password.php"><i class="bi bi-key me-1"></i>Change Password</a>',
]);
?>

<?php if ($flash !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?> d-flex align-items-center gap-2"><i class="bi bi-info-circle-fill"></i><span><?= esc($flash) ?></span></div>
<?php endif; ?>

<form method="post" id="profileForm">
    <input type="hidden" name="action" value="save_profile">
    <input type="hidden" name="photo_cropped_data" id="photoCroppedData" value="">

    <div class="row g-3">
        <!-- Photo panel -->
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body text-center">
                    <h6 class="text-muted text-uppercase small mb-3">Passport photo</h6>
                    <div class="mb-3 d-flex justify-content-center">
                        <div id="photoFrame" style="width: 180px; height: 232px; border: 1px dashed #ced4da; border-radius: 8px; overflow: hidden; background: #f8f9fa; display: flex; align-items: center; justify-content: center;">
                            <?php if (!empty($me['photo_path'])): ?>
                                <img id="photoPreviewImg" src="/<?= esc((string) $me['photo_path']) ?>" alt="" style="width: 100%; height: 100%; object-fit: cover;">
                                <span id="photoPreviewPlaceholder" style="display: none;" class="text-muted small">No photo</span>
                            <?php else: ?>
                                <img id="photoPreviewImg" src="" alt="" style="display: none; width: 100%; height: 100%; object-fit: cover;">
                                <span id="photoPreviewPlaceholder" class="text-muted small">No photo</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <label class="btn btn-outline-primary mb-0">
                            <i class="bi bi-upload me-1"></i>Choose &amp; crop photo
                            <input type="file" id="photoFile" accept="image/jpeg,image/png" hidden>
                        </label>
                        <?php if (!empty($me['photo_path'])): ?>
                            <button type="submit" form="removePhotoForm" class="btn btn-outline-danger" onclick="return confirm('Remove your profile photo? Your initials avatar will be shown until you upload a new one.');">
                                <i class="bi bi-trash me-1"></i>Remove photo
                            </button>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted mt-3">
                        JPEG or PNG, cropped to passport ratio (3.5 : 4.5). The server stores a 400 × 514 copy.
                    </div>
                </div>
            </div>
        </div>

        <!-- Basic info -->
        <div class="col-12 col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="text-muted text-uppercase small mb-3">Basic details</h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input class="form-control" name="name" value="<?= esc((string) ($me['name'] ?? '')) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Mobile number <span class="text-danger">*</span></label>
                            <input class="form-control" name="mobile_number" value="<?= esc((string) ($me['mobile_number'] ?? '')) ?>" inputmode="numeric" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input class="form-control" name="email" type="email" value="<?= esc((string) ($me['email'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Designation</label>
                            <input class="form-control" name="designation" value="<?= esc((string) ($me['designation'] ?? '')) ?>" placeholder="e.g. District Officer">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" name="address" rows="2"><?= esc((string) ($me['address'] ?? '')) ?></textarea>
                        </div>
                    </div>

                    <hr class="my-4">

                    <h6 class="text-muted text-uppercase small mb-3">Access &amp; assignment <span class="badge text-bg-light border ms-1">Admin-managed</span></h6>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Role</label>
                            <div class="form-control-plaintext"><?= esc(role_label((string) ($me['role'] ?? ''))) ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Office</label>
                            <div class="form-control-plaintext"><?= esc((string) ($me['office_name'] ?? '')) ?: '<span class="text-muted">Not assigned</span>' ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Status</label>
                            <div>
                                <?php if ((int) ($me['active_status'] ?? 0) === 1): ?>
                                    <span class="badge text-bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary">Inactive</span>
                                <?php endif; ?>
                                <?php if ((int) ($me['is_super_admin'] ?? 0) === 1): ?>
                                    <span class="badge text-bg-dark ms-1"><i class="bi bi-shield-lock me-1"></i>Super Admin</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-muted">Assigned districts</label>
                            <div>
                                <?php if ($assigned === ''): ?>
                                    <span class="badge text-bg-light border">All districts</span>
                                <?php else: ?>
                                    <?php foreach ($districtList as $d): ?>
                                        <span class="badge text-bg-light border me-1"><?= esc($d) ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end gap-2">
                    <a href="/dashboard.php" class="btn btn-light">Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save profile</button>
                </div>
            </div>
        </div>
    </div>
</form>

<form method="post" id="removePhotoForm" class="d-none">
    <input type="hidden" name="action" value="remove_photo">
</form>

<!-- Photo crop modal (Cropper.js) -->
<div class="modal fade" id="cropModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title"><i class="bi bi-crop me-1"></i>Crop photo</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="small text-muted mb-2">Drag the crop handle to frame your face — passport ratio (3.5 : 4.5) is enforced.</div>
            <div style="max-height: 60vh; overflow: hidden;">
                <img id="cropSource" src="" alt="" style="max-width: 100%; display: block;">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="cropApply"><i class="bi bi-check2 me-1"></i>Apply crop</button>
        </div>
    </div></div>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js"></script>
<script>
let cropperInstance = null;
document.getElementById('photoFile').addEventListener('change', (e) => {
    const file = e.target.files[0]; if (!file) return;
    const reader = new FileReader();
    reader.onload = (ev) => {
        document.getElementById('cropSource').src = ev.target.result;
        const modalEl = document.getElementById('cropModal');
        const modal = new bootstrap.Modal(modalEl);
        modal.show();
        modalEl.addEventListener('shown.bs.modal', () => {
            if (cropperInstance) { cropperInstance.destroy(); cropperInstance = null; }
            cropperInstance = new Cropper(document.getElementById('cropSource'), {
                aspectRatio: 3.5 / 4.5, viewMode: 1, autoCropArea: 0.9, background: false,
            });
        }, { once: true });
    };
    reader.readAsDataURL(file);
});
document.getElementById('cropApply').addEventListener('click', () => {
    if (!cropperInstance) return;
    const canvas = cropperInstance.getCroppedCanvas({ width: 400, height: 514, imageSmoothingQuality: 'high' });
    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
    document.getElementById('photoCroppedData').value = dataUrl;
    const img = document.getElementById('photoPreviewImg');
    img.src = dataUrl; img.style.display = '';
    document.getElementById('photoPreviewPlaceholder').style.display = 'none';
    bootstrap.Modal.getInstance(document.getElementById('cropModal')).hide();
    cropperInstance.destroy(); cropperInstance = null;
    document.getElementById('photoFile').value = '';
});
</script>

<?php render_footer(); ?>
