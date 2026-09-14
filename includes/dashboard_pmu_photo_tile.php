<?php
/**
 * Shared render for one District PMU office photo tile — used by
 * dashboard.php on the initial render (first 3 tiles) and by
 * dashboard_ajax_pmu_photos.php on the deferred load of the rest.
 *
 * Kept in its own file so the two callers can't drift apart.
 */

if (!function_exists('render_dpmu_photo_tile')) {
    function render_dpmu_photo_tile(array $pr): void
    {
        $esc = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="border rounded p-2 h-100">
                <div class="d-flex justify-content-between align-items-baseline mb-2">
                    <div>
                        <div class="fw-semibold"><?= $esc((string) ($pr['district'] ?? '')) ?></div>
                        <?php if (trim((string) ($pr['office_name'] ?? '')) !== ''): ?>
                            <div class="small text-muted"><?= $esc((string) $pr['office_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted"><?= $esc(substr((string) ($pr['updated_at'] ?? ''), 0, 10)) ?></div>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="small text-muted mb-1"><i class="bi bi-building me-1"></i>Building</div>
                        <?php if (!empty($pr['building_photo_path'])): ?>
                            <a href="<?= $esc((string) $pr['building_photo_path']) ?>" target="_blank" rel="noopener">
                                <img src="<?= $esc((string) $pr['building_photo_path']) ?>" alt="Building photo · <?= $esc((string) $pr['district']) ?>" loading="lazy"
                                     style="width:100%; aspect-ratio: 4/3; object-fit: cover; border-radius:.25rem; border:1px solid var(--bs-border-color);">
                            </a>
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-muted small border rounded"
                                 style="width:100%; aspect-ratio: 4/3; background: var(--bs-secondary-bg);">Not uploaded</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-6">
                        <div class="small text-muted mb-1"><i class="bi bi-door-open me-1"></i>Room</div>
                        <?php if (!empty($pr['room_photo_path'])): ?>
                            <a href="<?= $esc((string) $pr['room_photo_path']) ?>" target="_blank" rel="noopener">
                                <img src="<?= $esc((string) $pr['room_photo_path']) ?>" alt="Room photo · <?= $esc((string) $pr['district']) ?>" loading="lazy"
                                     style="width:100%; aspect-ratio: 4/3; object-fit: cover; border-radius:.25rem; border:1px solid var(--bs-border-color);">
                            </a>
                        <?php else: ?>
                            <div class="d-flex align-items-center justify-content-center text-muted small border rounded"
                                 style="width:100%; aspect-ratio: 4/3; background: var(--bs-secondary-bg);">Not uploaded</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
