<?php
/**
 * Employer Enquiry · list + filter + XLSX export.
 *
 * Reads submissions landed by /employers/index.php (the public
 * form). Access is gated by the RBAC module 'employer_enquiry' —
 * anyone with at least 'user' role sees the list; 'admin' also
 * sees the Settings page from the nav dropdown.
 *
 * Filters:
 *   q, status, country, state, district, company_type,
 *   dwms_interested (0/1/empty), internship (0/1/empty), from, to.
 *
 * ?export=xlsx on the same URL streams an XLSX of every matching
 * row (not just the current page) via includes/xlsx_writer.php.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/india_locations_helpers.php';
require_once __DIR__ . '/includes/employer_enquiry_helpers.php';
require_once __DIR__ . '/includes/xlsx_writer.php';

require_module_role('employer_enquiry', 'user');
employer_enquiry_bootstrap();
india_locations_bootstrap();

$viewer   = current_user();
$viewerId = (int) ($viewer['id'] ?? 0);

$filters = [
    'q'               => trim((string) ($_GET['q'] ?? '')),
    'status'          => (string) ($_GET['status'] ?? ''),
    'country'         => (string) ($_GET['country'] ?? ''),
    'state'           => (string) ($_GET['state'] ?? ''),
    'district'        => (string) ($_GET['district'] ?? ''),
    'company_type'    => (string) ($_GET['company_type'] ?? ''),
    'dwms_interested' => (string) ($_GET['dwms_interested'] ?? ''),
    'internship'      => (string) ($_GET['internship'] ?? ''),
    'from'            => trim((string) ($_GET['from'] ?? '')),
    'to'              => trim((string) ($_GET['to']   ?? '')),
];

$where  = ['1=1'];
$params = [];
if ($filters['q'] !== '') {
    $where[] = '(company_name LIKE ? OR hr_email LIKE ? OR hr_phone LIKE ? OR contact_person_name LIKE ? OR reference_number LIKE ?)';
    $q = '%' . $filters['q'] . '%';
    array_push($params, $q, $q, $q, $q, $q);
}
if ($filters['status'] !== '')          { $where[] = 'status = ?';        $params[] = $filters['status']; }
if ($filters['country'] !== '')         { $where[] = 'country = ?';       $params[] = $filters['country']; }
if ($filters['state'] !== '')           { $where[] = 'state = ?';         $params[] = $filters['state']; }
if ($filters['district'] !== '')        { $where[] = 'district = ?';      $params[] = $filters['district']; }
if ($filters['company_type'] !== '')    { $where[] = 'company_type = ?';  $params[] = $filters['company_type']; }
if ($filters['dwms_interested'] !== '') { $where[] = 'interested_dwms = ?'; $params[] = (int) $filters['dwms_interested']; }
if ($filters['internship'] !== '')      { $where[] = 'internship_available = ?'; $params[] = (int) $filters['internship']; }
if ($filters['from'] !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
    $where[] = 'created_at >= ?'; $params[] = $filters['from'] . ' 00:00:00';
}
if ($filters['to']   !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
    $where[] = 'created_at <= ?'; $params[] = $filters['to']   . ' 23:59:59';
}

$sql = 'SELECT * FROM employer_enquiry WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC';

// ----- Export path. Streams XLSX and exits before render. --------
if (($_GET['export'] ?? '') === 'xlsx') {
    $st = db()->prepare($sql);
    $st->execute($params);
    $header = [
        'Reference', 'Submitted At', 'Status',
        'Company', 'Type', 'Website',
        'Contact Person', 'HR Email', 'HR Phone',
        'Address', 'Country', 'State', 'District',
        'PAN', 'TIN/CIN',
        'DWMS Interest',
        'Vacancy Posts', 'Vacancy Open Positions',
        'Internship Available', 'With Stipend', 'Internship Positions',
        'Processed At', 'Notes',
    ];
    $gen = (function () use ($st) {
        while (($r = $st->fetch()) !== false) {
            yield [
                (string) $r['reference_number'],
                (string) $r['created_at'],
                ucfirst((string) $r['status']),
                (string) $r['company_name'],
                (string) $r['company_type'],
                (string) ($r['website'] ?? ''),
                (string) $r['contact_person_name'],
                (string) $r['hr_email'],
                (string) $r['hr_phone'],
                (string) $r['address'],
                (string) $r['country'],
                (string) ($r['state'] ?? ''),
                (string) ($r['district'] ?? ''),
                (string) ($r['pan_number'] ?? ''),
                (string) ($r['tin_cin_number'] ?? ''),
                ((int) $r['interested_dwms']) ? 'Yes' : 'No',
                (string) ($r['vacancies_posts'] ?? ''),
                (string) ($r['vacancies_open_positions'] ?? ''),
                ((int) $r['internship_available']) ? 'Yes' : 'No',
                ((int) $r['internship_with_stipend']) ? 'Yes' : 'No',
                (string) ($r['internship_open_positions'] ?? ''),
                (string) ($r['processed_at'] ?? ''),
                (string) ($r['notes'] ?? ''),
            ];
        }
    })();
    $fname = 'employer_enquiries_' . date('Ymd_His') . '.xlsx';
    xlsx_send($fname, 'Employer Enquiries', $header, $gen);
    exit;
}

// ----- Page render (preview the first 300 rows). ----------------
$previewSt = db()->prepare($sql . ' LIMIT 300');
$previewSt->execute($params);
$rows = $previewSt->fetchAll();

$countSt = db()->prepare('SELECT COUNT(*) FROM employer_enquiry WHERE ' . implode(' AND ', $where));
$countSt->execute($params);
$total = (int) $countSt->fetchColumn();

$countByStatusSt = db()->prepare('SELECT status, COUNT(*) AS n FROM employer_enquiry GROUP BY status');
$countByStatusSt->execute();
$counts = ['new' => 0, 'contacted' => 0, 'onboarded' => 0, 'declined' => 0, 'spam' => 0];
foreach ($countByStatusSt->fetchAll() as $r) $counts[(string) $r['status']] = (int) $r['n'];
$totalAll = array_sum($counts);

$states = india_states_list();

render_header('Employer Enquiries');
render_page_header('Employer Enquiries', [
    'icon' => 'bi-building-check',
    'subtitle' => 'Submissions landed from the public enrol-your-company form at /employers/.',
]);

$exportQs = http_build_query(array_merge($filters, ['export' => 'xlsx']));
?>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-lg">
        <div class="card-stat accent-primary h-100">
            <div class="stat-icon-box bg-primary-subtle text-primary"><i class="bi bi-inboxes"></i></div>
            <div class="stat-value"><?= number_format($totalAll) ?></div>
            <div class="stat-label">Total enquiries</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg">
        <div class="card-stat accent-warning h-100">
            <div class="stat-icon-box bg-warning-subtle text-warning"><i class="bi bi-envelope-open"></i></div>
            <div class="stat-value"><?= number_format($counts['new']) ?></div>
            <div class="stat-label">New (unread)</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg">
        <div class="card-stat accent-info h-100">
            <div class="stat-icon-box bg-info-subtle text-info"><i class="bi bi-chat-dots"></i></div>
            <div class="stat-value"><?= number_format($counts['contacted']) ?></div>
            <div class="stat-label">Contacted</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg">
        <div class="card-stat accent-success h-100">
            <div class="stat-icon-box bg-success-subtle text-success"><i class="bi bi-check-circle"></i></div>
            <div class="stat-value"><?= number_format($counts['onboarded']) ?></div>
            <div class="stat-label">Onboarded</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-lg">
        <div class="card-stat accent-danger h-100">
            <div class="stat-icon-box bg-danger-subtle text-danger"><i class="bi bi-slash-circle"></i></div>
            <div class="stat-value"><?= number_format($counts['declined'] + $counts['spam']) ?></div>
            <div class="stat-label">Declined / Spam</div>
        </div>
    </div>
</div>

<form method="get" class="card mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label small text-muted">Search</label>
                <input type="search" name="q" class="form-control" placeholder="Company, email, phone, reference" value="<?= esc($filters['q']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Status</label>
                <select name="status" class="form-select">
                    <option value="">All</option>
                    <?php foreach (['new','contacted','onboarded','declined','spam'] as $s): ?>
                        <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Country</label>
                <select name="country" class="form-select">
                    <option value="">All</option>
                    <option value="India" <?= $filters['country'] === 'India' ? 'selected' : '' ?>>India</option>
                    <option value="Outside India" <?= $filters['country'] === 'Outside India' ? 'selected' : '' ?>>Outside India</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">State</label>
                <select name="state" id="stateFilter" class="form-select">
                    <option value="">All</option>
                    <?php foreach ($states as $s): ?>
                        <option value="<?= esc($s['name']) ?>" <?= $filters['state'] === $s['name'] ? 'selected' : '' ?>><?= esc($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted">District</label>
                <select name="district" id="districtFilter" class="form-select">
                    <option value="">All</option>
                    <?php if ($filters['district'] !== ''): ?>
                        <option value="<?= esc($filters['district']) ?>" selected><?= esc($filters['district']) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Company type</label>
                <select name="company_type" class="form-select">
                    <option value="">All</option>
                    <?php foreach (EMPLOYER_ENQUIRY_COMPANY_TYPES as $t): ?>
                        <option value="<?= esc($t) ?>" <?= $filters['company_type'] === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">DWMS interest</label>
                <select name="dwms_interested" class="form-select">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['dwms_interested'] === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $filters['dwms_interested'] === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Internship</label>
                <select name="internship" class="form-select">
                    <option value="">Any</option>
                    <option value="1" <?= $filters['internship'] === '1' ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $filters['internship'] === '0' ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Submitted from</label>
                <input type="date" name="from" class="form-control" value="<?= esc($filters['from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Submitted to</label>
                <input type="date" name="to" class="form-control" value="<?= esc($filters['to']) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <div class="d-flex gap-2 w-100">
                    <button class="btn btn-primary flex-fill"><i class="bi bi-filter me-1"></i>Filter</button>
                    <a class="btn btn-light" href="/employer_enquiry_list.php" title="Reset filters"><i class="bi bi-x-lg"></i></a>
                </div>
            </div>
        </div>
    </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <div class="small text-muted">
        Showing <strong><?= number_format(min(count($rows), 300)) ?></strong> of <strong><?= number_format($total) ?></strong> matching enquiries
        <?php if ($total > 300): ?><span class="text-warning ms-2"><i class="bi bi-info-circle"></i> preview capped at 300 — download XLSX for the full set.</span><?php endif; ?>
    </div>
    <a class="btn btn-success" href="?<?= esc($exportQs) ?>"><i class="bi bi-file-earmark-excel me-1"></i>Download .xlsx (<?= number_format($total) ?> rows)</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Reference</th>
                    <th>Company</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th class="text-end">Vacancies</th>
                    <th>DWMS</th>
                    <th>Internship</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                        No enquiries match the current filters.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $badge = employer_enquiry_status_badge((string) $r['status']);
                ?>
                    <tr>
                        <td class="font-monospace small"><?= esc($r['reference_number']) ?></td>
                        <td>
                            <div class="fw-semibold"><?= esc($r['company_name']) ?></div>
                            <div class="small text-muted"><?= esc($r['company_type']) ?><?= $r['website'] ? ' · <a class="text-decoration-none" target="_blank" rel="noopener" href="' . esc($r['website']) . '">website</a>' : '' ?></div>
                        </td>
                        <td>
                            <div><?= esc($r['contact_person_name']) ?></div>
                            <div class="small text-muted"><?= esc($r['hr_email']) ?> · <?= esc($r['hr_phone']) ?></div>
                        </td>
                        <td>
                            <?php if ((string) $r['country'] === 'India'): ?>
                                <div><?= esc($r['district'] ?? '—') ?></div>
                                <div class="small text-muted"><?= esc($r['state'] ?? '') ?></div>
                            <?php else: ?>
                                <div><?= esc($r['country']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <?php if ($r['vacancies_posts'] !== null || $r['vacancies_open_positions'] !== null): ?>
                                <span class="fw-semibold"><?= (int) ($r['vacancies_open_positions'] ?? 0) ?></span>
                                <span class="text-muted small">/ <?= (int) ($r['vacancies_posts'] ?? 0) ?> posts</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $r['interested_dwms']): ?>
                                <span class="badge rounded-pill text-bg-success-subtle text-success">Yes</span>
                            <?php else: ?>
                                <span class="text-muted small">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $r['internship_available']): ?>
                                <span class="badge rounded-pill text-bg-info-subtle text-info">
                                    <?= (int) $r['internship_with_stipend'] ? 'Stipend' : 'Yes' ?>
                                    <?php if ($r['internship_open_positions']): ?> · <?= (int) $r['internship_open_positions'] ?><?php endif; ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted small">No</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge rounded-pill text-bg-<?= esc($badge['tone']) ?>-subtle text-<?= esc($badge['tone']) ?>"><?= esc($badge['label']) ?></span>
                        </td>
                        <td>
                            <div class="small"><?= esc(date('d/m/Y', strtotime((string) $r['created_at']))) ?></div>
                            <div class="small text-muted"><?= esc(date('H:i', strtotime((string) $r['created_at']))) ?></div>
                        </td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="/employer_enquiry_view.php?id=<?= (int) $r['id'] ?>"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    const stateSel = document.getElementById('stateFilter');
    const distSel  = document.getElementById('districtFilter');
    const currentDist = <?= json_encode($filters['district'], JSON_UNESCAPED_UNICODE) ?>;
    const loadDistricts = (state, keep) => {
        distSel.innerHTML = '<option value="">All</option>';
        if (!state) return;
        fetch('/employers/locations_ajax.php?action=districts&state=' + encodeURIComponent(state))
            .then(r => r.json())
            .then(data => {
                (data.districts || []).forEach(d => {
                    const o = document.createElement('option');
                    o.value = d; o.textContent = d;
                    if (d === keep) o.selected = true;
                    distSel.appendChild(o);
                });
            });
    };
    stateSel.addEventListener('change', () => loadDistricts(stateSel.value, ''));
    if (stateSel.value) loadDistricts(stateSel.value, currentDist);
})();
</script>

<?php render_footer(); ?>
