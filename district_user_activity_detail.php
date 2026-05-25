<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/consolidated_report_helpers.php';
require_auth();

$filters = build_consolidated_filters();
$district = trim((string) ($_GET['district'] ?? ''));
$view = trim((string) ($_GET['view'] ?? 'users'));

if ($district === '') {
    http_response_code(400);
    render_header('District User Activity Drill-down', ['show_navigation' => false]);
    echo '<div class="alert alert-danger">Missing required parameter: <code>district</code>.</div>';
    render_footer();
    exit;
}

$allowedViews = ['users', 'challenge', 'join_remarks'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'users';
}

$titleByView = [
    'users' => 'District Users in ' . $district,
    'challenge' => 'Challenge Breakdown · ' . $district,
    'join_remarks' => 'Join Remarks Breakdown · ' . $district,
];
$subtitleByView = [
    'users' => 'District user accounts that have updated candidate records in this district. Filtered by the same scope as the Consolidated Report.',
    'challenge' => 'Distribution of recorded Challenge Type values for candidates in this district touched by district users.',
    'join_remarks' => 'Distribution of Candidate Join Remarks Type values for candidates in this district touched by district users.',
];
$iconByView = [
    'users' => 'bi-people-fill',
    'challenge' => 'bi-exclamation-triangle',
    'join_remarks' => 'bi-chat-left-text',
];

$activeFilterChips = [];
if (($filters['aggregator'] ?? '') !== '') {
    $activeFilterChips[] = ['label' => 'Aggregator', 'value' => $filters['aggregator']];
}
if (($filters['job_fair'] ?? '') !== '') {
    $activeFilterChips[] = ['label' => 'Job Fair No', 'value' => $filters['job_fair']];
}
if (($filters['category'] ?? '') !== '') {
    $activeFilterChips[] = ['label' => 'Category', 'value' => $filters['category']];
}

$backUrl = '/consolidated_report.php?' . http_build_query(array_filter([
    'aggregator' => $filters['aggregator'] ?? '',
    'job_fair' => $filters['job_fair'] ?? '',
    'category' => $filters['category'] ?? '',
    'selection_status' => $filters['selection_status'] ?? '',
], static fn ($value): bool => $value !== ''));

$buildViewUrl = static function (string $newView) use ($filters, $district): string {
    return '/district_user_activity_detail.php?' . http_build_query(array_filter([
        'district' => $district,
        'view' => $newView,
        'aggregator' => $filters['aggregator'] ?? '',
        'job_fair' => $filters['job_fair'] ?? '',
        'category' => $filters['category'] ?? '',
    ], static fn ($value): bool => $value !== ''));
};

render_header($titleByView[$view] ?? 'District User Drill-down', [
    'main_container_class' => 'container-fluid',
]);
render_page_header($titleByView[$view], [
    'icon' => $iconByView[$view],
    'subtitle' => $subtitleByView[$view],
    'actions' => '<a class="btn btn-light" href="' . esc($backUrl) . '#tab-district"><i class="bi bi-arrow-left me-1"></i>Back to Consolidated Report</a>',
]);
?>

<?php if ($activeFilterChips !== []): ?>
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-center gap-2">
            <span class="form-label mb-0 me-1">Active filters:</span>
            <span class="status-chip status-info">District: <?= esc($district) ?></span>
            <?php foreach ($activeFilterChips as $chip): ?>
                <span class="status-chip status-neutral"><?= esc($chip['label']) ?>: <?= esc($chip['value']) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $view === 'users' ? 'active' : '' ?>" href="<?= esc($buildViewUrl('users')) ?>"><i class="bi bi-people me-1"></i>Users</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'challenge' ? 'active' : '' ?>" href="<?= esc($buildViewUrl('challenge')) ?>"><i class="bi bi-exclamation-triangle me-1"></i>Challenge</a></li>
    <li class="nav-item"><a class="nav-link <?= $view === 'join_remarks' ? 'active' : '' ?>" href="<?= esc($buildViewUrl('join_remarks')) ?>"><i class="bi bi-chat-left-text me-1"></i>Join Remarks</a></li>
</ul>

<?php if ($view === 'users'): ?>
    <?php $rows = fetch_district_user_activity_users($district, $filters); ?>
    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>User</th>
                        <th>Mobile</th>
                        <th>Email</th>
                        <th class="text-end">Candidates Touched</th>
                        <th class="text-end">Total Updates</th>
                        <th class="text-end">Receipt Confirmed</th>
                        <th class="text-end">Joined Status</th>
                        <th class="text-end">Willing to Join</th>
                        <th class="text-end">Challenge</th>
                        <th class="text-end">Join Remarks</th>
                        <th>Last Activity</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="12"><div class="empty-state"><i class="bi bi-inbox"></i>No district users have logged activity in this district yet.</div></td></tr>
                <?php endif; ?>
                <?php $idx = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td class="fw-semibold"><?= esc($r['user_name']) ?></td>
                        <td><?= esc($r['mobile_number']) ?></td>
                        <td><?= esc($r['email']) ?></td>
                        <td class="text-end"><?= number_format((int) $r['distinct_candidates']) ?></td>
                        <td class="text-end"><?= number_format((int) $r['total_updates']) ?></td>
                        <td class="text-end"><?= number_format((int) $r['receipt_confirm_updates']) ?></td>
                        <td class="text-end"><?= number_format((int) $r['joined_status_updates']) ?></td>
                        <td class="text-end"><?= number_format((int) $r['willing_to_join_updates']) ?></td>
                        <td class="text-end"><?= number_format((int) $r['challenge_updates']) ?></td>
                        <td class="text-end"><?= number_format((int) $r['join_remarks_updates']) ?></td>
                        <td><?= $r['last_activity'] ? esc(date('d M Y, H:i', strtotime((string) $r['last_activity']))) : '<span class="text-muted">&mdash;</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($view === 'challenge'): ?>
    <?php $rows = fetch_district_user_activity_challenges($district, $filters); ?>
    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>Challenge Type</th>
                        <th class="text-end">Candidate Count</th>
                        <th class="text-end">Joined: Yes</th>
                        <th class="text-end">Joined: No</th>
                        <th>Last Update</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6"><div class="empty-state"><i class="bi bi-inbox"></i>No challenge data available for this district.</div></td></tr>
                <?php endif; ?>
                <?php
                    $totalCandidates = 0; $totalYes = 0; $totalNo = 0;
                    $idx = 1;
                ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $c = (int) $r['candidate_count']; $y = (int) $r['joined_yes']; $n = (int) $r['joined_no'];
                        $totalCandidates += $c; $totalYes += $y; $totalNo += $n;
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td class="fw-semibold"><?= esc($r['challenge_type']) ?></td>
                        <td class="text-end"><?= number_format($c) ?></td>
                        <td class="text-end"><?= number_format($y) ?></td>
                        <td class="text-end"><?= number_format($n) ?></td>
                        <td><?= $r['last_update'] ? esc(date('d M Y, H:i', strtotime((string) $r['last_update']))) : '<span class="text-muted">&mdash;</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= number_format($totalCandidates) ?></td>
                        <td class="text-end"><?= number_format($totalYes) ?></td>
                        <td class="text-end"><?= number_format($totalNo) ?></td>
                        <td>&mdash;</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: /* join_remarks */ ?>
    <?php $rows = fetch_district_user_activity_join_remarks($district, $filters); ?>
    <div class="card table-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Sl No</th>
                        <th>Candidate Join Remarks Type</th>
                        <th class="text-end">Candidate Count</th>
                        <th class="text-end">Joined: Yes</th>
                        <th class="text-end">Joined: No</th>
                        <th class="text-end">Joined: Pending</th>
                        <th>Last Update</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7"><div class="empty-state"><i class="bi bi-inbox"></i>No join remark data available for this district.</div></td></tr>
                <?php endif; ?>
                <?php
                    $totalCandidates = 0; $totalYes = 0; $totalNo = 0; $totalPending = 0;
                    $idx = 1;
                ?>
                <?php foreach ($rows as $r): ?>
                    <?php
                        $c = (int) $r['candidate_count'];
                        $y = (int) $r['joined_yes'];
                        $n = (int) $r['joined_no'];
                        $p = (int) $r['joined_pending'];
                        $totalCandidates += $c; $totalYes += $y; $totalNo += $n; $totalPending += $p;
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td class="fw-semibold"><?= esc($r['remark_type']) ?></td>
                        <td class="text-end"><?= number_format($c) ?></td>
                        <td class="text-end"><?= number_format($y) ?></td>
                        <td class="text-end"><?= number_format($n) ?></td>
                        <td class="text-end"><?= number_format($p) ?></td>
                        <td><?= $r['last_update'] ? esc(date('d M Y, H:i', strtotime((string) $r['last_update']))) : '<span class="text-muted">&mdash;</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows !== []): ?>
                    <tr class="table-secondary fw-semibold">
                        <td colspan="2">Total</td>
                        <td class="text-end"><?= number_format($totalCandidates) ?></td>
                        <td class="text-end"><?= number_format($totalYes) ?></td>
                        <td class="text-end"><?= number_format($totalNo) ?></td>
                        <td class="text-end"><?= number_format($totalPending) ?></td>
                        <td>&mdash;</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
