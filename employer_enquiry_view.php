<?php
/**
 * Employer Enquiry · one-row detail + status / notes update.
 *
 * Any user on the module sees it; only 'reviewer' and 'admin' can
 * change the status / notes (user_can_review_module()).
 *
 * The record itself is read-only — the public submitter sent it
 * from /employers/, so re-editing the business fields would undo
 * the audit trail. We only let staff annotate: status + notes +
 * processed_at / processed_by.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/employer_enquiry_helpers.php';

require_module_role('employer_enquiry', 'user');
employer_enquiry_bootstrap();

$viewer    = current_user();
$viewerId  = (int) ($viewer['id'] ?? 0);
$canReview = user_can_review_module($viewerId, 'employer_enquiry') || is_manage_admin($viewer);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /employer_enquiry_list.php');
    exit;
}

$st = db()->prepare('SELECT ee.*, u.name AS processed_by_name
    FROM employer_enquiry ee
    LEFT JOIN users u ON u.id = ee.processed_by
    WHERE ee.id = ? LIMIT 1');
$st->execute([$id]);
$row = $st->fetch();

if (!$row) {
    render_header('Enquiry not found');
    render_page_header('Enquiry not found', ['icon' => 'bi-exclamation-triangle',
        'actions' => '<a class="btn btn-light" href="/employer_enquiry_list.php"><i class="bi bi-arrow-left me-1"></i>Back to list</a>']);
    echo '<div class="alert alert-warning">That enquiry could not be found. It may have been removed.</div>';
    render_footer();
    exit;
}

$flash = null;
if (is_post() && $canReview) {
    csrf_check_or_die();
    $newStatus = (string) ($_POST['status'] ?? $row['status']);
    $newNotes  = trim((string) ($_POST['notes'] ?? ''));
    $allowed = ['new','contacted','onboarded','declined','spam'];
    if (!in_array($newStatus, $allowed, true)) $newStatus = $row['status'];

    // Mark processed_at on first move away from 'new'; keep as-is
    // when the status later drifts between 'contacted'/'onboarded'.
    $processedAtSet = '';
    $params = [$newStatus, $newNotes === '' ? null : $newNotes, $viewerId, $id];
    if ((string) $row['status'] === 'new' && $newStatus !== 'new') {
        $processedAtSet = ', processed_at = NOW()';
    }
    db()->prepare('UPDATE employer_enquiry
        SET status = ?, notes = ?, processed_by = ?, updated_at = NOW()' . $processedAtSet . '
        WHERE id = ?')->execute($params);

    // Re-read.
    $st->execute([$id]);
    $row = $st->fetch();
    $flash = ['msg' => 'Enquiry updated.', 'type' => 'success'];
}

$badge = employer_enquiry_status_badge((string) $row['status']);
render_header('Enquiry · ' . $row['reference_number']);
render_page_header($row['reference_number'] . ' · ' . $row['company_name'], [
    'icon' => 'bi-building-check',
    'subtitle' => 'Submitted ' . date('d/m/Y H:i', strtotime((string) $row['created_at'])),
    'actions' => '<a class="btn btn-light" href="/employer_enquiry_list.php"><i class="bi bi-arrow-left me-1"></i>Back to list</a>',
]);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= esc($flash['type']) ?> alert-dismissible fade show">
        <?= esc($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <span class="font-monospace small text-muted"><?= esc($row['reference_number']) ?></span>
                </div>
                <span class="badge rounded-pill text-bg-<?= esc($badge['tone']) ?>-subtle text-<?= esc($badge['tone']) ?> fs-6"><?= esc($badge['label']) ?></span>
            </div>
            <div class="card-body">
                <h5 class="mb-3"><i class="bi bi-building me-2"></i>Company</h5>
                <dl class="row small mb-0">
                    <dt class="col-sm-4 text-muted">Name</dt><dd class="col-sm-8"><?= esc($row['company_name']) ?></dd>
                    <dt class="col-sm-4 text-muted">Type</dt><dd class="col-sm-8"><?= esc($row['company_type']) ?></dd>
                    <?php if (!empty($row['website'])): ?>
                        <dt class="col-sm-4 text-muted">Website</dt><dd class="col-sm-8"><a target="_blank" rel="noopener" href="<?= esc($row['website']) ?>"><?= esc($row['website']) ?></a></dd>
                    <?php endif; ?>
                    <?php if (!empty($row['pan_number'])): ?>
                        <dt class="col-sm-4 text-muted">PAN</dt><dd class="col-sm-8 font-monospace"><?= esc($row['pan_number']) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($row['tin_cin_number'])): ?>
                        <dt class="col-sm-4 text-muted">TIN / CIN</dt><dd class="col-sm-8 font-monospace"><?= esc($row['tin_cin_number']) ?></dd>
                    <?php endif; ?>
                </dl>

                <hr>
                <h5 class="mb-3"><i class="bi bi-person-badge me-2"></i>HR / Contact</h5>
                <dl class="row small mb-0">
                    <dt class="col-sm-4 text-muted">Contact person</dt><dd class="col-sm-8"><?= esc($row['contact_person_name']) ?></dd>
                    <dt class="col-sm-4 text-muted">HR email</dt>
                    <dd class="col-sm-8"><a href="mailto:<?= esc($row['hr_email']) ?>"><?= esc($row['hr_email']) ?></a></dd>
                    <dt class="col-sm-4 text-muted">HR phone</dt>
                    <dd class="col-sm-8"><a href="tel:<?= esc($row['hr_phone']) ?>"><?= esc($row['hr_phone']) ?></a></dd>
                </dl>

                <hr>
                <h5 class="mb-3"><i class="bi bi-geo-alt me-2"></i>Address</h5>
                <p class="mb-2" style="white-space: pre-wrap;"><?= esc($row['address']) ?></p>
                <div class="small text-muted">
                    <?= esc($row['country']) ?>
                    <?php if (!empty($row['state'])): ?> · <?= esc($row['state']) ?><?php endif; ?>
                    <?php if (!empty($row['district'])): ?> · <?= esc($row['district']) ?><?php endif; ?>
                </div>

                <hr>
                <h5 class="mb-3"><i class="bi bi-briefcase me-2"></i>Vacancies &amp; onboarding</h5>
                <dl class="row small mb-0">
                    <dt class="col-sm-4 text-muted">Interested in DWMS?</dt>
                    <dd class="col-sm-8"><?= ((int) $row['interested_dwms']) ? '<span class="badge rounded-pill text-bg-success-subtle text-success">Yes</span>' : '<span class="text-muted">No</span>' ?></dd>
                    <dt class="col-sm-4 text-muted">Posts available</dt>
                    <dd class="col-sm-8"><?= $row['vacancies_posts'] === null ? '—' : (int) $row['vacancies_posts'] ?></dd>
                    <dt class="col-sm-4 text-muted">Open positions</dt>
                    <dd class="col-sm-8"><?= $row['vacancies_open_positions'] === null ? '—' : (int) $row['vacancies_open_positions'] ?></dd>
                </dl>

                <hr>
                <h5 class="mb-3"><i class="bi bi-mortarboard me-2"></i>Internships</h5>
                <dl class="row small mb-0">
                    <dt class="col-sm-4 text-muted">Internships available?</dt>
                    <dd class="col-sm-8"><?= ((int) $row['internship_available']) ? '<span class="badge rounded-pill text-bg-info-subtle text-info">Yes</span>' : '<span class="text-muted">No</span>' ?></dd>
                    <?php if ((int) $row['internship_available']): ?>
                        <dt class="col-sm-4 text-muted">With stipend?</dt>
                        <dd class="col-sm-8"><?= ((int) $row['internship_with_stipend']) ? 'Yes' : 'No' ?></dd>
                        <dt class="col-sm-4 text-muted">Open positions</dt>
                        <dd class="col-sm-8"><?= $row['internship_open_positions'] === null ? '—' : (int) $row['internship_open_positions'] ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <?php if ($canReview): ?>
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-check2-square me-1"></i>Update status</div>
                <div class="card-body">
                    <form method="post">
                        <?php csrf_field(); ?>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['new' => 'New','contacted' => 'Contacted','onboarded' => 'Onboarded','declined' => 'Declined','spam' => 'Spam'] as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= $row['status'] === $k ? 'selected' : '' ?>><?= esc($v) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small text-muted">Internal notes</label>
                            <textarea name="notes" class="form-control" rows="4" placeholder="Context, call summary, next action…"><?= esc($row['notes'] ?? '') ?></textarea>
                        </div>
                        <button class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card mb-3">
                <div class="card-body small text-muted">
                    Need to update this enquiry's status? Ask an admin to grant you at least <strong>Reviewer</strong> access on the Employer Enquiry module.
                </div>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history me-1"></i>Trail</div>
            <div class="card-body small">
                <div class="mb-2"><span class="text-muted">Submitted:</span> <strong><?= esc(date('d/m/Y H:i', strtotime((string) $row['created_at']))) ?></strong></div>
                <?php if (!empty($row['processed_at'])): ?>
                    <div class="mb-2"><span class="text-muted">First processed:</span> <strong><?= esc(date('d/m/Y H:i', strtotime((string) $row['processed_at']))) ?></strong></div>
                <?php endif; ?>
                <?php if (!empty($row['processed_by_name'])): ?>
                    <div class="mb-2"><span class="text-muted">Last touched by:</span> <strong><?= esc($row['processed_by_name']) ?></strong></div>
                <?php endif; ?>
                <div class="mb-2"><span class="text-muted">Updated:</span> <strong><?= esc(date('d/m/Y H:i', strtotime((string) $row['updated_at']))) ?></strong></div>
                <hr class="my-2">
                <div class="text-muted" title="<?= esc($row['submitter_ua'] ?? '') ?>">
                    From IP <code class="small"><?= esc($row['submitter_ip'] ?? '—') ?></code>
                </div>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>
