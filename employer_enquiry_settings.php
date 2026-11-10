<?php
/**
 * Employer Enquiry · public form settings.
 *
 * Admin-only page that edits the configurable text on the public
 * form /employers/index.php:
 *   - header_text  : Malayalam header shown above the form.
 *   - thanks_text  : Malayalam thank-you shown on the reference-
 *                    number card after a successful submit.
 *   - contact_email: optional email shown as a "contact us" line.
 *
 * Nothing about the schema or validation moves here — the form
 * fields themselves are fixed. We only configure the surrounding
 * copy, which is what Operations asked to own.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/employer_enquiry_helpers.php';

require_module_role('employer_enquiry', 'admin');
employer_enquiry_bootstrap();

$viewer    = current_user();
$viewerId  = (int) ($viewer['id'] ?? 0);

$flash = null;
if (is_post()) {
    csrf_check_or_die();
    $header = trim((string) ($_POST['header_text'] ?? ''));
    $thanks = trim((string) ($_POST['thanks_text'] ?? ''));
    $contact = trim((string) ($_POST['contact_email'] ?? ''));

    if ($header === '') $header = EMPLOYER_ENQUIRY_DEFAULT_HEADER;
    if ($thanks === '') $thanks = EMPLOYER_ENQUIRY_DEFAULT_THANKS;

    employer_enquiry_setting_write('header_text',   $header,  $viewerId);
    employer_enquiry_setting_write('thanks_text',   $thanks,  $viewerId);
    employer_enquiry_setting_write('contact_email', $contact, $viewerId);

    $flash = ['msg' => 'Public form text updated. The /employers/ page already reflects the change.', 'type' => 'success'];
}

$header  = employer_enquiry_setting('header_text',   EMPLOYER_ENQUIRY_DEFAULT_HEADER);
$thanks  = employer_enquiry_setting('thanks_text',   EMPLOYER_ENQUIRY_DEFAULT_THANKS);
$contact = employer_enquiry_setting('contact_email', '');

render_header('Employer Enquiry · Settings');
render_page_header('Public form settings', [
    'icon' => 'bi-sliders2',
    'subtitle' => 'Text shown on the public /employers/ form. Edits land in real time — reload the public page to confirm.',
    'actions' => '<a class="btn btn-light" href="/employer_enquiry_list.php"><i class="bi bi-arrow-left me-1"></i>Back to enquiries</a>',
]);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= esc($flash['type']) ?> alert-dismissible fade show">
        <?= esc($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="post" class="card">
    <?php csrf_field(); ?>
    <div class="card-body">
        <div class="mb-4">
            <label class="form-label fw-semibold">Header text (Malayalam / mixed)</label>
            <div class="small text-muted mb-2">Shown as the headline block above the form fields. Line breaks are preserved.</div>
            <textarea name="header_text" class="form-control" rows="6" required style="font-family: 'Noto Sans Malayalam', system-ui, sans-serif; line-height: 1.7;"><?= esc($header) ?></textarea>
            <div class="small text-muted mt-1">Leaving this blank restores the KKEM default copy.</div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">Thank-you message</label>
            <div class="small text-muted mb-2">Shown on the confirmation page after a successful submit, above the reference number pill.</div>
            <textarea name="thanks_text" class="form-control" rows="3" required style="font-family: 'Noto Sans Malayalam', system-ui, sans-serif; line-height: 1.7;"><?= esc($thanks) ?></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label fw-semibold">Contact email <span class="small text-muted">(optional)</span></label>
            <input type="email" name="contact_email" class="form-control" value="<?= esc($contact) ?>" placeholder="kkem@keralaknowledge.org">
            <div class="small text-muted mt-1">If set, future updates to the form may use this for a "need help?" link. Not shown yet.</div>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="small text-muted">
            <i class="bi bi-eye me-1"></i>Preview the live form at
            <a href="/employers/" target="_blank" rel="noopener"><code>/employers/</code> <i class="bi bi-box-arrow-up-right ms-1"></i></a>
        </div>
        <button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save settings</button>
    </div>
</form>

<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Malayalam:wght@400;500;700&display=swap" rel="stylesheet">

<?php render_footer(); ?>
