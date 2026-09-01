<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_auth();
$viewer = current_user() ?? [];
if (!is_edms($viewer) && !is_admin($viewer)) {
    http_response_code(403);
    echo 'Access denied — EDMS or admin role required.';
    exit;
}
district_pmu_bootstrap();

$edms   = $viewer;
$edmsId = (int) ($viewer['id'] ?? 0);
// Only EDMS can approve / reject / return. Admin viewers get the same
// detail page but the action form is hidden and any tampered POST is
// silently rejected by this flag.
$canReview = is_edms($viewer);

$submissionId = (int) ($_GET['submission'] ?? $_POST['submission'] ?? 0);
if ($submissionId <= 0) {
    http_response_code(400);
    echo 'Missing submission id.';
    exit;
}

$flashMessage = null;
$flashType    = 'success';

/* -------------------------------------------------------------------- *
 * Approval action handler — Approve / Reject / Return with remarks.
 *
 *  - Approve  → status = approved,  assets stay locked (submission is
 *               final and audit-ready).
 *  - Reject   → status = rejected,  assets stay locked (bad data
 *               retained for audit; district user should not silently
 *               re-edit and re-submit).
 *  - Return   → status = returned,  UNLOCK the assets (submission_id =
 *               NULL) so the district user can edit and re-submit as a
 *               new submission. The original submission row is kept
 *               with reviewer, timestamp and remarks for audit.
 * -------------------------------------------------------------------- */
if (is_post() && $canReview) {
    $action  = (string) ($_POST['action']  ?? '');
    $remarks = trim((string) ($_POST['remarks'] ?? ''));
    if (!in_array($action, ['approve', 'reject', 'return'], true)) {
        $flashMessage = 'Unknown action.';
        $flashType = 'danger';
    } elseif ($action !== 'approve' && $remarks === '') {
        // Reject / Return should carry a reason for the district user.
        $flashMessage = 'Remarks are required to Reject or Return a submission.';
        $flashType = 'danger';
    } else {
        $newStatus = ($action === 'approve') ? 'approved'
                   : (($action === 'reject')  ? 'rejected' : 'returned');
        db()->query('START TRANSACTION');
        try {
            $u = db()->prepare('UPDATE district_pmu_asset_submissions
                SET approval_status = ?, reviewed_by = ?, reviewed_at = NOW(), review_remarks = ?
                WHERE id = ? AND approval_status = "pending"');
            $u->execute([$newStatus, $edmsId, $remarks === '' ? null : $remarks, $submissionId]);
            $touched = $u->affectedRows();
            if ($touched === 0) {
                // Already reviewed by someone else, or bad id.
                db()->query('ROLLBACK');
                $flashMessage = 'This submission was already reviewed by someone else. Refresh to see the current status.';
                $flashType = 'warning';
            } else {
                if ($action === 'return') {
                    // Unlock the asset rows so the operator can edit + re-submit.
                    db()->prepare('UPDATE district_pmu_assets
                        SET submission_id = NULL, submitted_at = NULL
                        WHERE submission_id = ?')->execute([$submissionId]);
                }
                db()->query('COMMIT');
                $flashMessage = match ($action) {
                    'approve' => 'Submission approved.',
                    'reject'  => 'Submission rejected. Asset rows remain locked.',
                    'return'  => 'Submission returned. Asset rows unlocked so the district user can revise and re-submit.',
                };
            }
        } catch (Throwable $e) {
            db()->query('ROLLBACK');
            $flashMessage = 'Action failed: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
}

$stmt = db()->prepare('SELECT s.*, u.name AS submitted_by_name, u.mobile_number AS submitted_by_mobile,
        ru.name AS reviewed_by_name
    FROM district_pmu_asset_submissions s
    LEFT JOIN users u  ON u.id  = s.submitted_by
    LEFT JOIN users ru ON ru.id = s.reviewed_by
    WHERE s.id = ?');
$stmt->execute([$submissionId]);
$submission = $stmt->fetch();
if (!$submission) {
    http_response_code(404);
    render_header('Not found');
    render_page_header('Submission not found', ['icon' => 'bi-exclamation-triangle']);
    echo '<div class="alert alert-danger">Submission not found.</div>';
    render_footer();
    exit;
}

// If the submission was RETURNED, its assets were unlocked and no longer
// reference this submission_id — pull them from the DB directly by id
// history instead. We do that by keeping a copy in the JOIN before
// unlock; simplest is: query by submission_id which will return zero
// rows for returned submissions, which is the intended read.
$asStmt = db()->prepare('SELECT a.*, t.name AS type_name, s.name AS subtype_name, auth.name AS authority_name
    FROM district_pmu_assets a
    LEFT JOIN district_pmu_asset_types t ON t.id = a.asset_type_id
    LEFT JOIN district_pmu_asset_subtypes s ON s.id = a.subtype_id
    LEFT JOIN district_pmu_owning_authorities auth ON auth.id = a.owning_authority_id
    WHERE a.submission_id = ?
    ORDER BY t.sort_order ASC, t.name ASC, s.sort_order ASC, s.name ASC, a.id ASC');
$asStmt->execute([$submissionId]);
$assets = $asStmt->fetchAll();

$status = (string) ($submission['approval_status'] ?? 'pending');
$statusTone = match ($status) {
    'approved' => 'success',
    'rejected' => 'danger',
    'returned' => 'warning',
    default    => 'secondary',
};

render_header('PMU · Submission detail', ['main_container_class' => 'container-fluid']);
render_page_header('Submission · ' . esc((string) $submission['submission_number']), [
    'icon' => 'bi-clipboard-check',
    'subtitle' => 'Approve, reject or return this submission with remarks. Return unlocks the asset rows so the district user can revise and re-submit.',
    'actions' => '<a class="btn btn-light me-1" href="/district_pmu_report_asset.php?submission=' . (int) $submissionId . '" target="_blank"><i class="bi bi-printer me-1"></i>Print Report</a>'
        . '<a class="btn btn-light" href="/edms_submissions.php"><i class="bi bi-arrow-left me-1"></i>Back to Submissions</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-info-circle text-primary me-1"></i>Submission details</span>
                <span class="badge text-bg-<?= esc($statusTone) ?> text-uppercase"><?= esc($status) ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><div class="small text-muted">Submission Number</div><div class="fw-semibold"><?= esc((string) $submission['submission_number']) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">District</div><div class="fw-semibold"><?= esc((string) $submission['district']) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Assets in submission</div><div class="fw-semibold"><?= number_format((int) $submission['asset_count']) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Submitted by</div><div class="fw-semibold"><?= esc((string) ($submission['submitted_by_name'] ?? '')) ?></div><div class="small text-muted"><?= esc((string) ($submission['submitted_by_mobile'] ?? '')) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Submitted at</div><div class="fw-semibold"><?= esc((string) $submission['submitted_at']) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Reviewed by</div>
                        <?php if (!empty($submission['reviewed_by_name'])): ?>
                            <div class="fw-semibold"><?= esc((string) $submission['reviewed_by_name']) ?></div>
                            <div class="small text-muted"><?= esc((string) ($submission['reviewed_at'] ?? '')) ?></div>
                        <?php else: ?>
                            <div class="text-muted">—</div>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($submission['review_remarks'])): ?>
                        <div class="col-12">
                            <div class="small text-muted">Review remarks</div>
                            <div class="alert alert-<?= esc($statusTone) ?>-subtle border border-<?= esc($statusTone) ?>-subtle mb-0"><?= nl2br(esc((string) $submission['review_remarks'])) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-box-seam text-primary me-1"></i>Assets in this submission</span>
                <span class="status-chip status-info"><?= number_format(count($assets)) ?> row(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Sl No</th>
                            <th>Type</th>
                            <th>Sub type</th>
                            <th>Description</th>
                            <th>Owning Authority</th>
                            <th class="text-end">Quantity</th>
                            <th>Concerned Person</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($assets === []): ?>
                            <tr><td colspan="8"><div class="empty-state"><i class="bi bi-inbox"></i>No asset rows attached — the submission may have been returned (assets unlocked and now editable in the district register).</div></td></tr>
                        <?php endif; ?>
                        <?php $i = 1; foreach ($assets as $a): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= esc((string) ($a['type_name'] ?? '')) ?></td>
                                <td class="fw-semibold"><?= esc((string) ($a['subtype_name'] ?? '')) ?></td>
                                <td class="small text-muted"><?= nl2br(esc((string) ($a['description'] ?? ''))) ?></td>
                                <td class="small"><?= esc((string) ($a['authority_name'] ?? '')) ?></td>
                                <td class="text-end fw-bold"><?= number_format((int) ($a['quantity'] ?? 0)) ?></td>
                                <td><?= esc((string) ($a['concerned_person'] ?? '')) ?></td>
                                <td class="small text-muted"><?= nl2br(esc((string) ($a['remarks'] ?? ''))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-shield-check text-primary me-1"></i><?= $canReview ? 'Approval action' : 'Approval status' ?></div>
            <div class="card-body">
                <?php if (!$canReview): ?>
                    <?php /* Admin-group viewers get read-only access — the
                             approve/reject/return controls are EDMS-only. */ ?>
                    <div class="alert alert-<?= esc($statusTone) ?> mb-2">
                        Current status: <strong class="text-uppercase"><?= esc($status) ?></strong>
                        <?php if (!empty($submission['reviewed_by_name'])): ?>
                            <div class="small mt-1">Reviewed by <?= esc((string) $submission['reviewed_by_name']) ?><?= !empty($submission['reviewed_at']) ? ' on ' . esc((string) $submission['reviewed_at']) : '' ?>.</div>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted"><i class="bi bi-eye me-1"></i>Read-only view. Only EDMS can approve, reject or return a submission.</div>
                <?php elseif ($status !== 'pending'): ?>
                    <div class="alert alert-<?= esc($statusTone) ?>">
                        This submission was already <strong><?= esc($status) ?></strong>
                        <?php if (!empty($submission['reviewed_by_name'])): ?>
                            by <?= esc((string) $submission['reviewed_by_name']) ?>
                        <?php endif; ?>
                        <?php if (!empty($submission['reviewed_at'])): ?>
                            on <?= esc((string) $submission['reviewed_at']) ?>
                        <?php endif; ?>.
                    </div>
                    <p class="small text-muted mb-0">A submission can be actioned only once. If it needs to be reopened, ask an Administrator.</p>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="submission" value="<?= (int) $submissionId ?>">
                        <div class="mb-3">
                            <label class="form-label" for="remarks">Remarks
                                <span class="small text-muted">(mandatory for Reject / Return)</span>
                            </label>
                            <textarea class="form-control" id="remarks" name="remarks" rows="4" placeholder="What was checked? What needs to change on a return?"></textarea>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="action" value="approve" class="btn btn-success"
                                    onclick="return confirm('Approve this submission? Asset rows stay locked and the submission is final.');">
                                <i class="bi bi-check2-circle me-1"></i>Approve
                            </button>
                            <button type="submit" name="action" value="return"  class="btn btn-warning"
                                    onclick="return confirm('Return this submission with remarks? Asset rows will be UNLOCKED so the district user can edit and re-submit.');">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Return with remarks
                            </button>
                            <button type="submit" name="action" value="reject"  class="btn btn-outline-danger"
                                    onclick="return confirm('Reject this submission with remarks? Asset rows stay locked for audit.');">
                                <i class="bi bi-x-circle me-1"></i>Reject with remarks
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($canReview): ?>
                <div class="card-footer small text-muted">
                    <strong>Approve</strong> — assets stay locked, submission final. <br>
                    <strong>Return</strong> — assets UNLOCK; the district user revises and re-submits as a new submission. Remarks are visible to them. <br>
                    <strong>Reject</strong> — assets stay locked. Remarks are visible for audit.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php render_footer(); ?>
