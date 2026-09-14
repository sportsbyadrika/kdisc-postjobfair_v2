<?php
/**
 * Officer transfer history for one node — HTML fragment used inside the
 * Office Hierarchy View modal. Loaded on demand so the main page doesn't
 * carry history for every row.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/office_hierarchy_helpers.php';
require_auth();
$viewer = current_user() ?? [];
if (!is_manage_admin($viewer)) {
    http_response_code(403);
    echo '<div class="alert alert-danger m-0">Access denied.</div>';
    exit;
}
office_hierarchy_bootstrap();

$nodeId = (int) ($_GET['node'] ?? 0);
if ($nodeId <= 0) {
    echo '<div class="text-muted">No node selected.</div>';
    exit;
}
$history = office_hierarchy_officer_history($nodeId);
$esc = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

if ($history === []) {
    echo '<div class="text-muted small"><i class="bi bi-clock me-1"></i>No transfer events yet.</div>';
    exit;
}
?>
<div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
        <thead>
            <tr>
                <th>Sl No</th>
                <th>Officer</th>
                <th>Designation</th>
                <th>Charge</th>
                <th>From</th>
                <th>To / Ended</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; foreach ($history as $h): ?>
                <?php $current = $h['unassigned_at'] === null; ?>
                <tr class="<?= $current ? 'table-primary' : '' ?>">
                    <td><?= $i++ ?></td>
                    <td class="fw-semibold">
                        <?= $esc((string) ($h['officer_name_snapshot'] ?? '')) ?>
                        <?php if ($current): ?><span class="badge text-bg-primary ms-1">Current</span><?php endif; ?>
                    </td>
                    <td><?= $esc((string) ($h['designation'] ?? '')) ?></td>
                    <td class="small">
                        <?php if ((int) ($h['is_additional_charge'] ?? 0) === 1): ?>
                            <span class="badge text-bg-warning">Additional charge</span>
                        <?php else: ?>
                            <span class="text-muted">Regular</span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?= $esc(substr((string) ($h['from_date'] ?? $h['assigned_at']), 0, 10)) ?>
                        <?php if (!empty($h['assigned_by_name'])): ?><div class="small text-muted">by <?= $esc((string) $h['assigned_by_name']) ?></div><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($current): ?>
                            <?php if (!empty($h['to_date'])): ?>
                                <?= $esc(substr((string) $h['to_date'], 0, 10)) ?>
                                <div class="small text-muted">planned end</div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= $esc(substr((string) ($h['to_date'] ?? $h['unassigned_at']), 0, 10)) ?>
                            <?php if (!empty($h['unassigned_by_name'])): ?><div class="small text-muted">by <?= $esc((string) $h['unassigned_by_name']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?php if (!empty($h['assign_reason'])): ?><div><em>Assign:</em> <?= $esc((string) $h['assign_reason']) ?></div><?php endif; ?>
                        <?php if (!empty($h['unassign_reason'])): ?><div><em>Unassign:</em> <?= $esc((string) $h['unassign_reason']) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
