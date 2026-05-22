<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$user = current_user();

$districtRows = db()->query("SELECT
    COALESCE(NULLIF(TRIM(SDPK_District), ''), 'Unknown') AS district,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS selected_count,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'shortlisted' THEN 1 ELSE 0 END) AS shortlisted_count,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Selection_Status), ' ', '')) = 'onhold' THEN 1 ELSE 0 END) AS on_hold_count,
    SUM(CASE WHEN LOWER(REPLACE(TRIM(Shortlist_Candidate_Status), ' ', '')) = 'selected' THEN 1 ELSE 0 END) AS shortlisted_selected_count,
    SUM(CASE WHEN LOWER(TRIM(Offer_Letter_Generated)) = 'yes' THEN 1 ELSE 0 END) AS offer_letter_generated_count,
    COUNT(*) AS total_count
FROM job_fair_result
GROUP BY COALESCE(NULLIF(TRIM(SDPK_District), ''), 'Unknown')
ORDER BY district")->fetchAll();

$districtTotals = [
    'selected_count' => 0,
    'shortlisted_count' => 0,
    'on_hold_count' => 0,
    'shortlisted_selected_count' => 0,
    'offer_letter_generated_count' => 0,
    'total_count' => 0,
    'total_selected_count' => 0,
];

render_header('District Data');
render_page_header('District Overview', [
    'icon' => 'bi-bar-chart-line',
    'subtitle' => 'District wise record count across selection, shortlist and offer letter stages.',
    'actions' => '<a class="btn btn-light" href="/dashboard.php"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>',
]);
?>

<div class="card table-card">
    <div class="card-header"><i class="bi bi-geo-alt-fill text-primary me-1"></i>District wise Record Count</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>District</th>
                        <th>Selected</th>
                        <th>Shortlisted</th>
                        <th>On Hold</th>
                        <th>Total</th>
                        <th>Shortlisted Selected</th>
                        <th>Total Selected</th>
                        <th>Offer Letter Generated</th>
                        <th>% of Offer Letter Generated</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($districtRows === []): ?>
                    <tr><td colspan="10" class="text-center text-muted">No district-wise data available.</td></tr>
                <?php endif; ?>
                <?php $districtIndex = 1; ?>
                <?php foreach ($districtRows as $districtRow): ?>
                    <?php
                    $selected = (int) ($districtRow['selected_count'] ?? 0);
                    $shortlisted = (int) ($districtRow['shortlisted_count'] ?? 0);
                    $onHold = (int) ($districtRow['on_hold_count'] ?? 0);
                    $total = (int) ($districtRow['total_count'] ?? 0);
                    $shortlistedSelected = (int) ($districtRow['shortlisted_selected_count'] ?? 0);
                    $totalSelected = $selected + $shortlistedSelected;
                    $offerLetterGenerated = (int) ($districtRow['offer_letter_generated_count'] ?? 0);
                    $offerLetterPercentage = $totalSelected > 0 ? round(($offerLetterGenerated / $totalSelected) * 100, 2) : 0;

                    $districtTotals['selected_count'] += $selected;
                    $districtTotals['shortlisted_count'] += $shortlisted;
                    $districtTotals['on_hold_count'] += $onHold;
                    $districtTotals['total_count'] += $total;
                    $districtTotals['shortlisted_selected_count'] += $shortlistedSelected;
                    $districtTotals['total_selected_count'] += $totalSelected;
                    $districtTotals['offer_letter_generated_count'] += $offerLetterGenerated;
                    ?>
                    <tr>
                        <td><?= $districtIndex ?></td>
                        <td><?= esc($districtRow['district']) ?></td>
                        <td><?= $selected ?></td>
                        <td><?= $shortlisted ?></td>
                        <td><?= $onHold ?></td>
                        <td><strong><?= $total ?></strong></td>
                        <td><?= $shortlistedSelected ?></td>
                        <td><?= $totalSelected ?></td>
                        <td><?= $offerLetterGenerated ?></td>
                        <td><?= number_format($offerLetterPercentage, 2) ?>%</td>
                    </tr>
                    <?php $districtIndex++; ?>
                <?php endforeach; ?>
                <?php if ($districtRows !== []): ?>
                    <?php
                    $grandOfferLetterPercentage = $districtTotals['total_selected_count'] > 0
                        ? round(($districtTotals['offer_letter_generated_count'] / $districtTotals['total_selected_count']) * 100, 2)
                        : 0;
                    ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td><strong><?= $districtTotals['selected_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['shortlisted_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['on_hold_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['total_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['shortlisted_selected_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['total_selected_count'] ?></strong></td>
                        <td><strong><?= $districtTotals['offer_letter_generated_count'] ?></strong></td>
                        <td><strong><?= number_format($grandOfferLetterPercentage, 2) ?>%</strong></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php render_footer(); ?>
