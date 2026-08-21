<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/district_pmu_helpers.php';
require_pmu_user();
district_pmu_bootstrap();

$user       = current_user();
$userId     = (int) $user['id'];
$districts  = district_pmu_user_districts($user);
$submissionId = (int) ($_GET['submission'] ?? 0);
if ($submissionId <= 0) {
    http_response_code(400);
    echo 'Missing submission id.';
    exit;
}

$stmt = db()->prepare('SELECT s.*, u.name AS submitted_by_name, u.mobile_number AS submitted_by_mobile
    FROM district_pmu_asset_submissions s
    LEFT JOIN users u ON u.id = s.submitted_by
    WHERE s.id = ?');
$stmt->execute([$submissionId]);
$submission = $stmt->fetch();
if (!$submission) {
    http_response_code(404);
    echo 'Submission not found.';
    exit;
}
// Access guard — a district_pmu user can only open reports for their
// own assigned districts.
if (!in_array((string) $submission['district'], $districts, true)) {
    http_response_code(403);
    echo 'Access denied — this submission belongs to a district that is not on your assignment list.';
    exit;
}

// Sort by Type then Sub type on the printed report so like items group
// together. sort_order (admin-configured priority) wins so IT Asset lists
// before Non-IT Asset as seeded; name breaks the tie so a subtype added
// later with the default sort_order = 0 still lands somewhere predictable
// instead of at the top. Row id is the final deterministic fallback.
$asStmt = db()->prepare('SELECT a.*, t.name AS type_name, s.name AS subtype_name, auth.name AS authority_name
    FROM district_pmu_assets a
    LEFT JOIN district_pmu_asset_types t ON t.id = a.asset_type_id
    LEFT JOIN district_pmu_asset_subtypes s ON s.id = a.subtype_id
    LEFT JOIN district_pmu_owning_authorities auth ON auth.id = a.owning_authority_id
    WHERE a.submission_id = ?
    ORDER BY t.sort_order ASC, t.name ASC,
             s.sort_order ASC, s.name ASC,
             a.id ASC');
$asStmt->execute([$submissionId]);
$assets = $asStmt->fetchAll();

$totalQty = 0;
foreach ($assets as $a) { $totalQty += (int) ($a['quantity'] ?? 0); }

$profile = district_pmu_get_profile_by_district((string) $submission['district']);
$officeName    = trim((string) ($profile['office_name'] ?? ''));
$officeAddress = trim((string) ($profile['address'] ?? ''));
$officePincode = trim((string) ($profile['pincode'] ?? ''));

$titleSuffix = $submission['submission_number'] ?? ('SUB-' . $submissionId);
$printedAt   = date('Y-m-d H:i');

$esc = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Approved Asset Register &middot; <?= $esc($titleSuffix) ?></title>
<style>
    /* Paged Media — A4 landscape with modest margins so headers and
       footers don't crowd the table. Chrome / Edge / Firefox render
       @page counters correctly for the page-number footer. */
    @page {
        size: A4 landscape;
        margin: 16mm 12mm 22mm 12mm;
        @bottom-left  { content: 'DPMU Approved Asset Register · ' string(subnum); font-size: 9pt; color: #555; }
        @bottom-right { content: 'Page ' counter(page) ' of ' counter(pages); font-size: 9pt; color: #555; }
    }
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
        font-family: 'Segoe UI', Roboto, Arial, sans-serif;
        color: #212529;
        margin: 0;
        font-size: 10pt;
        background: #fff;
    }
    .no-print { padding: 12px 16px; background: #f1f3f5; border-bottom: 1px solid #dee2e6; }
    @media print { .no-print { display: none; } }
    .report-header {
        display: flex; justify-content: space-between; align-items: flex-start;
        border-bottom: 2px solid #212529; padding-bottom: 8px; margin-bottom: 10px;
        string-set: subnum var(--subnum, '');
    }
    .report-header h1 { margin: 0 0 4px 0; font-size: 14pt; }
    .report-header .meta { font-size: 9pt; color: #495057; }
    .report-header .meta strong { color: #212529; }
    .info-grid {
        display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px 16px;
        margin-bottom: 12px; font-size: 9.5pt;
    }
    .info-grid .lbl { color: #6c757d; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; }
    .info-grid .val { font-weight: 600; }
    table.assets {
        width: 100%; border-collapse: collapse; font-size: 9pt;
    }
    table.assets thead th {
        background: #212529; color: #fff; padding: 6px 6px; text-align: left;
        border: 1px solid #212529;
    }
    table.assets tbody td {
        padding: 5px 6px; border: 1px solid #adb5bd; vertical-align: top;
    }
    table.assets tbody tr:nth-child(even) td { background: #f8f9fa; }
    /* Repeat the header on each printed page. */
    table.assets thead { display: table-header-group; }
    table.assets tfoot { display: table-footer-group; }
    table.assets tfoot td { padding: 6px; font-weight: 700; background: #e9ecef; border: 1px solid #adb5bd; }

    .signatures {
        margin-top: 24px;
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 16px;
        page-break-inside: avoid;
    }
    .signatures .box {
        border: 1px solid #adb5bd;
        border-radius: 6px;
        min-height: 130px;
        padding: 8px 10px;
        position: relative;
        background: #fff;
    }
    .signatures .box .title { font-weight: 700; font-size: 10pt; margin-bottom: 6px; color: #212529; }
    .signatures .box .row { display: flex; gap: 4px; font-size: 9pt; margin-top: 6px; }
    .signatures .box .row .lbl { color: #6c757d; min-width: 80px; }
    .signatures .box .row .line { flex: 1; border-bottom: 1px solid #6c757d; min-height: 14px; }
    .signatures .box .fixed-val { font-weight: 600; }
    .signatures .box .sig-space { margin-top: 14px; border-bottom: 1px solid #6c757d; height: 40px; }
    .signatures .box .sig-space + .caption { font-size: 8.5pt; color: #6c757d; text-align: center; padding-top: 2px; }
    .seal-box {
        display: flex; align-items: center; justify-content: center;
    }
    .seal-ring {
        width: 130px; height: 130px; border-radius: 50%;
        border: 2px dashed #6c757d; color: #6c757d;
        display: flex; align-items: center; justify-content: center;
        font-size: 9pt; text-align: center; padding: 8px;
    }
    .small-muted { font-size: 8.5pt; color: #6c757d; }
</style>
<style>
    /* Give the CSS `string(subnum)` its value by injecting the actual
       submission number via a body attribute + JS. Some browsers need
       the string-set to be evaluated after DOM ready. */
</style>
</head>
<body data-subnum="<?= $esc($titleSuffix) ?>">
    <div class="no-print">
        <button type="button" onclick="window.print()" style="padding:6px 12px; border:1px solid #0d6efd; background:#0d6efd; color:#fff; border-radius:.25rem; cursor:pointer;">🖨️ Print / Save as PDF</button>
        <span class="small-muted" style="margin-left:12px;">A4 landscape · print settings should be "Default" margins.</span>
    </div>

    <div class="report-header" style="--subnum: '<?= $esc($titleSuffix) ?>';">
        <div>
            <h1>APPROVED ASSET REGISTER</h1>
            <div class="meta">District PMU &middot; K-DISC</div>
        </div>
        <div style="text-align:right;">
            <div class="meta"><strong>Submission #</strong>&nbsp;<?= $esc($titleSuffix) ?></div>
            <div class="meta"><strong>Printed at</strong>&nbsp;<?= $esc($printedAt) ?></div>
        </div>
    </div>

    <div class="info-grid">
        <div><div class="lbl">District</div><div class="val"><?= $esc((string) $submission['district']) ?></div></div>
        <div><div class="lbl">Office</div><div class="val"><?= $officeName !== '' ? $esc($officeName) : '<span class="small-muted">Not set</span>' ?></div></div>
        <div><div class="lbl">Submitted by</div><div class="val"><?= $esc((string) ($submission['submitted_by_name'] ?? '')) ?></div></div>
        <div><div class="lbl">Submitted at</div><div class="val"><?= $esc((string) ($submission['submitted_at'] ?? '')) ?></div></div>
        <div><div class="lbl">Address</div><div class="val"><?= $officeAddress !== '' ? $esc($officeAddress) : '<span class="small-muted">—</span>' ?></div></div>
        <div><div class="lbl">Pincode</div><div class="val"><?= $officePincode !== '' ? $esc($officePincode) : '<span class="small-muted">—</span>' ?></div></div>
        <div><div class="lbl">Total items</div><div class="val"><?= number_format(count($assets)) ?> row(s) · <?= number_format($totalQty) ?> qty</div></div>
        <div><div class="lbl">Submission Number</div><div class="val"><?= $esc($titleSuffix) ?></div></div>
    </div>

    <table class="assets">
        <thead>
            <tr>
                <th style="width:34px;">Sl No</th>
                <th style="width:12%;">Type</th>
                <th style="width:16%;">Sub type</th>
                <th>Description</th>
                <th style="width:16%;">Owning Authority</th>
                <th style="width:8%; text-align:right;">Quantity</th>
                <th style="width:12%;">Concerned Person</th>
                <th style="width:16%;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($assets === []): ?>
                <tr><td colspan="8" style="text-align:center; padding:16px;">No asset rows in this submission.</td></tr>
            <?php endif; ?>
            <?php $i = 1; foreach ($assets as $a): ?>
                <tr>
                    <td style="text-align:right;"><?= $i++ ?></td>
                    <td><?= $esc((string) ($a['type_name'] ?? '')) ?></td>
                    <td><?= $esc((string) ($a['subtype_name'] ?? '')) ?></td>
                    <td><?= nl2br($esc((string) ($a['description'] ?? ''))) ?></td>
                    <td><?= $esc((string) ($a['authority_name'] ?? '')) ?></td>
                    <td style="text-align:right;"><?= number_format((int) ($a['quantity'] ?? 0)) ?></td>
                    <td><?= $esc((string) ($a['concerned_person'] ?? '')) ?></td>
                    <td><?= nl2br($esc((string) ($a['remarks'] ?? ''))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if ($assets !== []): ?>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right;">Total</td>
                <td style="text-align:right;"><?= number_format($totalQty) ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>

    <div class="signatures">
        <div class="box">
            <div class="title">Submitted By</div>
            <div class="row"><span class="lbl">Name</span><span class="fixed-val"><?= $esc((string) ($submission['submitted_by_name'] ?? '')) ?></span></div>
            <div class="row"><span class="lbl">Mobile</span><span class="fixed-val"><?= $esc((string) ($submission['submitted_by_mobile'] ?? '')) ?></span></div>
            <div class="row"><span class="lbl">District</span><span class="fixed-val"><?= $esc((string) $submission['district']) ?></span></div>
            <div class="row"><span class="lbl">Date</span><span class="fixed-val"><?= $esc(substr((string) ($submission['submitted_at'] ?? ''), 0, 10)) ?></span></div>
            <div class="sig-space"></div>
            <div class="caption">Signature</div>
        </div>
        <div class="box seal-box">
            <div class="seal-ring">Office / Approval<br>Seal</div>
        </div>
        <div class="box">
            <div class="title">Approved By</div>
            <div class="row"><span class="lbl">Name</span><span class="line"></span></div>
            <div class="row"><span class="lbl">Designation</span><span class="line"></span></div>
            <div class="row"><span class="lbl">Signing Date</span><span class="line"></span></div>
            <div class="sig-space"></div>
            <div class="caption">Signature</div>
        </div>
    </div>

    <script>
        // Kick print automatically when the caller adds ?auto=1 — handy
        // when opening the report from an admin dashboard that expects
        // an immediate print dialog. Manual button always works.
        if (new URLSearchParams(location.search).get('auto') === '1') {
            window.addEventListener('load', () => setTimeout(() => window.print(), 400));
        }
    </script>
</body>
</html>
