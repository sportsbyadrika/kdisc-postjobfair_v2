<div class="card mt-2">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs px-2 pt-2" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabComments" type="button">Comments · <?= count($remarkRows) ?></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabHistory" type="button">History · <?= count($histRows) ?></button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAll" type="button">All · <?= count($mergedAll) ?></button></li>
        </ul>
    </div>
    <div class="card-body">
        <div class="tab-content">
            <div class="tab-pane fade show active" id="tabComments">
                <?php if ($canEdit): ?>
                    <form method="post" class="mb-3">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="add_remark">
                        <div class="input-group">
                            <textarea class="form-control" name="remark" rows="2" placeholder="Add a comment…" required></textarea>
                            <button class="btn btn-primary"><i class="bi bi-send me-1"></i>Post</button>
                        </div>
                        <div class="small text-muted mt-1">Comments are permanent. Every post writes a history row.</div>
                    </form>
                <?php endif; ?>
                <?php if ($remarkRows === []): ?>
                    <div class="empty-state"><i class="bi bi-chat"></i>No comments yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($remarkRows as $r):
                            $by = (string) ($r['by_name'] ?? '—');
                            $avRow = ['id' => (int) ($r['created_by'] ?? 0), 'name' => $by,
                                      'photo_path' => (string) ($r['photo_path'] ?? ''),
                                      'avatar_colour' => (string) ($r['avatar_colour'] ?? ''),
                                      'mobile_number' => (string) ($r['mobile_number'] ?? '')];
                        ?>
                            <li class="d-flex gap-2 mb-3">
                                <?= user_avatar_html($avRow, 32) ?>
                                <div class="flex-grow-1">
                                    <div class="d-flex justify-content-between">
                                        <strong><?= esc($by) ?></strong>
                                        <span class="small text-muted"><?= esc($fmtDT((string) $r['created_at'])) ?></span>
                                    </div>
                                    <div style="white-space:pre-wrap;"><?= esc((string) $r['remark']) ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="tabHistory">
                <?php if ($histRows === []): ?>
                    <div class="empty-state"><i class="bi bi-clock-history"></i>No changes recorded yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($histRows as $h): ?>
                            <li class="mb-2">
                                <span class="badge text-bg-light border font-monospace me-1"><?= esc((string) $h['field_name']) ?></span>
                                <span class="text-muted small"><?= esc($fmtDT((string) $h['changed_at'])) ?> · by <?= esc((string) ($h['by_name'] ?? '—')) ?></span>
                                <div class="small">
                                    <?php if ((string) ($h['old_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">from</span> <span class="text-decoration-line-through"><?= esc((string) $h['old_value']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((string) ($h['new_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">to</span> <strong><?= esc((string) $h['new_value']) ?></strong>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="tabAll">
                <?php if ($mergedAll === []): ?>
                    <div class="empty-state"><i class="bi bi-list-nested"></i>No activity yet.</div>
                <?php else: ?>
                    <ul class="list-unstyled">
                        <?php foreach ($mergedAll as $entry):
                            $row = $entry['row'];
                            $when = $entry['when'];
                            if ($entry['kind'] === 'comment') {
                                $by = (string) ($row['by_name'] ?? '—');
                                $avRow = ['id' => (int) ($row['created_by'] ?? 0), 'name' => $by,
                                          'photo_path' => (string) ($row['photo_path'] ?? ''),
                                          'avatar_colour' => (string) ($row['avatar_colour'] ?? ''),
                                          'mobile_number' => (string) ($row['mobile_number'] ?? '')];
                        ?>
                            <li class="d-flex gap-2 mb-3">
                                <?= user_avatar_html($avRow, 32) ?>
                                <div>
                                    <div><strong><?= esc($by) ?></strong> <span class="small text-muted">commented · <?= esc($fmtDT($when)) ?></span></div>
                                    <div style="white-space:pre-wrap;"><?= esc((string) $row['remark']) ?></div>
                                </div>
                            </li>
                        <?php } else { ?>
                            <li class="mb-2">
                                <span class="badge text-bg-light border font-monospace me-1"><?= esc((string) $row['field_name']) ?></span>
                                <span class="text-muted small"><?= esc($fmtDT($when)) ?> · by <?= esc((string) ($row['by_name'] ?? '—')) ?></span>
                                <div class="small">
                                    <?php if ((string) ($row['old_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">from</span> <span class="text-decoration-line-through"><?= esc((string) $row['old_value']) ?></span>
                                    <?php endif; ?>
                                    <?php if ((string) ($row['new_value'] ?? '') !== ''): ?>
                                        <span class="text-muted">to</span> <strong><?= esc((string) $row['new_value']) ?></strong>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php } endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
