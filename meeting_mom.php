<?php
/**
 * Minutes of Meeting — print-friendly HTML view.
 *
 * Renders with page-level print CSS so the operator can save it as
 * a real PDF via the browser's print dialog. This replaces a
 * true-dompdf install so the app stays Composer-free per CLAUDE.md;
 * the render function is a drop-in swap when server-side dompdf is
 * bundled later.
 *
 * Sections (per the requirements):
 *   Logo · Heading · Sub-heading
 *   Meeting reference, date, time, venue
 *   Participants table (present / absent / apologies)
 *   Agenda list
 *   Discussion / minutes body
 *   Decision points, grouped by responsible person's Division
 *   Next-meeting date + agenda
 *   Chairperson signature line
 *   Footer
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_auth();
meetings_bootstrap();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /meetings.php'); exit; }

$stmt = db()->prepare('SELECT m.*, u.name AS created_by_name,
        cu.name AS chair_user_name, cc.name AS chair_contact_name, cc.institution AS chair_contact_inst
    FROM meeting m
    LEFT JOIN users u ON u.id = m.created_by
    LEFT JOIN users cu ON cu.id = m.chair_user_id
    LEFT JOIN contact cc ON cc.id = m.chair_contact_id
    WHERE m.id = ? LIMIT 1');
$stmt->execute([$id]);
$meeting = $stmt->fetch();
if ($meeting === false) { http_response_code(404); echo 'Not found'; exit; }

$fmtDate = static fn($s) => $s === null || $s === '' ? '' : date('d/m/Y', strtotime((string) $s));
$fmtTime = static fn($s) => $s === null || $s === '' ? '' : substr((string) $s, 0, 5);
$esc     = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$parts = db()->prepare('SELECT mp.*, u.name AS user_name, c.name AS contact_name, c.institution
    FROM meeting_participant mp
    LEFT JOIN users u ON u.id = mp.user_id
    LEFT JOIN contact c ON c.id = mp.contact_id
    WHERE mp.meeting_id = ? ORDER BY mp.id ASC');
$parts->execute([$id]); $parts = $parts->fetchAll();

$agenda = db()->prepare('SELECT * FROM meeting_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
$agenda->execute([$id]); $agenda = $agenda->fetchAll();

$decisions = db()->prepare('SELECT * FROM meeting_decision WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
$decisions->execute([$id]); $decisions = $decisions->fetchAll();

// Group decisions by Division of the responsible seat / user. Users
// without a seat land in "General". Look up each responsibility's
// division by walking the seat's parents.
$decResp = [];
if ($decisions !== []) {
    $ids = array_map(static fn($d) => (int) $d['id'], $decisions);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $r = db()->prepare("SELECT mdr.*,
            u.name AS user_name, c.name AS contact_name, n.name AS seat_name,
            usr_seat.node_id AS user_seat_id
        FROM meeting_decision_responsible mdr
        LEFT JOIN users u ON u.id = mdr.user_id
        LEFT JOIN contact c ON c.id = mdr.contact_id
        LEFT JOIN office_hierarchy_nodes n ON n.id = mdr.seat_id
        LEFT JOIN office_hierarchy_officer_history usr_seat ON usr_seat.officer_id = mdr.user_id AND usr_seat.unassigned_at IS NULL
        WHERE mdr.decision_id IN ($ph)");
    $r->execute($ids);
    foreach ($r->fetchAll() as $x) $decResp[(int) $x['decision_id']][] = $x;
}
// Node cache for parent-chain walks.
$nodeCache = [];
$fetchNode = static function (int $nid) use (&$nodeCache): ?array {
    if ($nid <= 0) return null;
    if (isset($nodeCache[$nid])) return $nodeCache[$nid];
    $s = db()->prepare('SELECT id, name, parent_id, level_type FROM office_hierarchy_nodes WHERE id = ?');
    $s->execute([$nid]);
    return $nodeCache[$nid] = ($s->fetch() ?: null);
};
$divisionOfSeat = static function (int $seatId) use ($fetchNode): string {
    $cursor = $seatId;
    for ($g = 0; $g < 10 && $cursor > 0; $g++) {
        $n = $fetchNode($cursor);
        if ($n === null) break;
        if ((string) $n['level_type'] === 'division') return (string) $n['name'];
        $cursor = (int) ($n['parent_id'] ?? 0);
    }
    return 'General';
};

$grouped = [];
foreach ($decisions as $d) {
    $rs = $decResp[(int) $d['id']] ?? [];
    if ($rs === []) { $grouped['General'][] = ['decision' => $d, 'responsibles' => []]; continue; }
    // A decision may have responsibilities in multiple divisions. To
    // avoid duplicating the same decision, group under the first
    // responsible's division (per spec — "division of the seat").
    $div = 'General';
    foreach ($rs as $r) {
        $seatId = (int) ($r['seat_id'] ?? 0);
        if ($seatId <= 0) $seatId = (int) ($r['user_seat_id'] ?? 0);
        if ($seatId > 0) { $div = $divisionOfSeat($seatId); break; }
    }
    $grouped[$div][] = ['decision' => $d, 'responsibles' => $rs];
}
ksort($grouped);

$nextAg = db()->prepare('SELECT * FROM meeting_next_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
$nextAg->execute([$id]); $nextAg = $nextAg->fetchAll();

$logo    = meetings_setting('mom_logo_url');
$heading = meetings_setting('mom_heading', 'Minutes of Meeting');
$sub     = meetings_setting('mom_sub_heading');
$footer  = meetings_setting('mom_footer');

$chairName = $meeting['chair_user_name'] ?: $meeting['chair_contact_name'];
$chairInst = $meeting['chair_contact_inst'];

// Participant partitioning: apologies (not attended, mandatory), absent (not attended, optional).
// We keep a simple table: name · institution · type · status.
?><!doctype html>
<html><head>
<meta charset="utf-8">
<title>MoM · <?= $esc((string) $meeting['reference_no']) ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: 'Times New Roman', serif; color: #111; margin: 24px; }
    .mom-head { display: flex; align-items: center; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 12px; margin-bottom: 20px; }
    .mom-head img { max-height: 72px; }
    .mom-head .titles { flex: 1; }
    .mom-head h1 { font-size: 22px; margin: 0 0 4px 0; }
    .mom-head h2 { font-size: 14px; margin: 0; color: #444; font-weight: normal; }
    .meta { display: grid; grid-template-columns: 130px 1fr 130px 1fr; gap: 6px 16px; font-size: 13px; margin: 12px 0 18px 0; }
    .meta .k { color: #555; }
    h3 { border-bottom: 1px solid #999; padding-bottom: 4px; font-size: 15px; margin: 22px 0 10px 0; }
    table { border-collapse: collapse; width: 100%; font-size: 12px; }
    th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; vertical-align: top; }
    th { background: #eee; }
    ol { margin: 0 0 0 20px; padding: 0; font-size: 13px; }
    .decision { border: 1px solid #ccc; border-radius: 4px; padding: 8px; margin-bottom: 8px; font-size: 12px; }
    .decision-title { font-weight: bold; margin-bottom: 4px; }
    .decision .meta-row { color: #555; font-size: 11px; margin-top: 4px; }
    .group-h { background: #f0f0f0; padding: 4px 8px; font-weight: bold; font-size: 13px; margin-top: 12px; }
    .sig { margin-top: 40px; }
    .sig .line { border-top: 1px solid #111; width: 240px; margin-top: 40px; padding-top: 4px; font-size: 12px; }
    .footer { margin-top: 30px; font-size: 11px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; text-align: center; }
    .print-toolbar { position: sticky; top: 0; background: #fff; padding: 8px 0; border-bottom: 1px dashed #ccc; margin-bottom: 12px; }
    @media print {
        .print-toolbar { display: none; }
        body { margin: 8mm; }
    }
</style>
</head><body>

<div class="print-toolbar">
    <button onclick="window.print()" style="padding:6px 12px; background:#0d6efd; color:#fff; border:none; border-radius:4px; cursor:pointer;">Print / Save as PDF</button>
    <a href="/meeting_view.php?id=<?= $id ?>" style="margin-left:12px;">Back to meeting</a>
    <span style="margin-left:16px; color:#555; font-size:12px;">Use your browser's print dialog and choose "Save as PDF" for a real PDF.</span>
</div>

<div class="mom-head">
    <?php if ($logo !== ''): ?><img src="<?= $esc($logo) ?>" alt="Logo"><?php endif; ?>
    <div class="titles">
        <h1><?= $esc($heading) ?></h1>
        <?php if ($sub !== ''): ?><h2><?= $esc($sub) ?></h2><?php endif; ?>
    </div>
</div>

<div class="meta">
    <div class="k">Reference:</div>       <div><strong><?= $esc((string) $meeting['reference_no']) ?></strong></div>
    <div class="k">Date:</div>            <div><?= $esc($fmtDate((string) $meeting['meeting_date'])) ?></div>
    <div class="k">Time:</div>            <div><?= $esc($fmtTime((string) $meeting['from_time'])) ?><?= empty($meeting['to_time']) ? '' : ' – ' . $esc($fmtTime((string) $meeting['to_time'])) ?></div>
    <div class="k">Chair:</div>           <div><?= $esc((string) ($chairName ?: '')) ?><?php if ($chairInst): ?> <span style="color:#666;">(<?= $esc((string) $chairInst) ?>)</span><?php endif; ?></div>
    <div class="k">Location:</div>        <div><?= $esc((string) ($meeting['location'] ?? '')) ?></div>
    <div class="k">Virtual link:</div>    <div><?= empty($meeting['virtual_link']) ? '' : $esc((string) $meeting['virtual_link']) ?></div>
    <div class="k">Title:</div>           <div colspan="3" style="grid-column: span 3;"><strong><?= $esc((string) $meeting['title']) ?></strong></div>
</div>

<?php if (!empty($meeting['purpose'])): ?>
    <h3>Purpose</h3>
    <div style="white-space:pre-wrap; font-size:13px;"><?= $esc((string) $meeting['purpose']) ?></div>
<?php endif; ?>

<h3>Participants</h3>
<table>
    <thead>
        <tr><th style="width:6%;">Sl</th><th>Name</th><th>Institution</th><th>Designation</th><th style="width:12%;">Type</th><th style="width:12%;">Status</th></tr>
    </thead>
    <tbody>
        <?php if ($parts === []): ?>
            <tr><td colspan="6" style="text-align:center; color:#666;">— none —</td></tr>
        <?php endif; ?>
        <?php $i = 1; foreach ($parts as $p):
            $name = (string) ($p['user_name'] ?: $p['contact_name']);
            $inst = (string) ($p['institution'] ?? '');
            $status = $p['attended'] === null || $p['attended'] === '' ? '—' : ((int) $p['attended'] === 1 ? 'Present' : ((int) $p['is_mandatory'] === 1 ? 'Apologies' : 'Absent'));
        ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= $esc($name) ?></td>
                <td><?= $esc($inst) ?></td>
                <td><?= $esc((string) ($p['role_label'] ?? '')) ?></td>
                <td><?= (int) $p['is_mandatory'] === 1 ? 'Mandatory' : 'Optional' ?></td>
                <td><?= $esc($status) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h3>Agenda</h3>
<?php if ($agenda === []): ?>
    <div style="color:#666; font-size:13px;">— none —</div>
<?php else: ?>
    <ol>
        <?php foreach ($agenda as $a): ?>
            <li>
                <strong><?= $esc((string) $a['title']) ?></strong>
                <?php if (!empty($a['description'])): ?><div style="color:#444; font-size:12px; white-space:pre-wrap;"><?= $esc((string) $a['description']) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if (!empty($meeting['minutes_body'])): ?>
    <h3>Discussion / Minutes</h3>
    <div style="white-space:pre-wrap; font-size:13px;"><?= $esc((string) $meeting['minutes_body']) ?></div>
<?php endif; ?>

<h3>Decision points (grouped by Division)</h3>
<?php if ($grouped === []): ?>
    <div style="color:#666; font-size:13px;">— none —</div>
<?php endif; ?>
<?php foreach ($grouped as $divName => $items): ?>
    <div class="group-h">Division: <?= $esc($divName) ?></div>
    <?php foreach ($items as $it): $d = $it['decision']; $rs = $it['responsibles']; ?>
        <div class="decision">
            <div class="decision-title"><?= $esc((string) $d['heading']) ?></div>
            <?php if (!empty($d['description'])): ?><div style="white-space:pre-wrap;"><?= $esc((string) $d['description']) ?></div><?php endif; ?>
            <div class="meta-row">
                <?php $names = []; foreach ($rs as $r) $names[] = (string) ($r['user_name'] ?: ($r['contact_name'] ?: $r['seat_name'])); ?>
                <?php if ($names !== []): ?><strong>Responsible:</strong> <?= $esc(implode(', ', $names)) ?><?php endif; ?>
                <?php if (!empty($d['due_date'])): ?> &nbsp; · &nbsp; <strong>Due:</strong> <?= $esc($fmtDate((string) $d['due_date'])) ?><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<?php if (!empty($meeting['next_meeting_date']) || $nextAg !== []): ?>
    <h3>Next meeting</h3>
    <?php if (!empty($meeting['next_meeting_date'])): ?>
        <div style="font-size:13px;"><strong>Scheduled:</strong> <?= $esc($fmtDate((string) $meeting['next_meeting_date'])) ?><?php if (!empty($meeting['next_meeting_time'])): ?> at <?= $esc($fmtTime((string) $meeting['next_meeting_time'])) ?><?php endif; ?></div>
    <?php endif; ?>
    <?php if ($nextAg !== []): ?>
        <div style="font-size:13px; margin-top:6px;"><strong>Agenda:</strong></div>
        <ol>
            <?php foreach ($nextAg as $n): ?>
                <li><strong><?= $esc((string) $n['title']) ?></strong><?php if (!empty($n['description'])): ?> — <span style="color:#444;"><?= $esc((string) $n['description']) ?></span><?php endif; ?></li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
<?php endif; ?>

<div class="sig">
    <div class="line">
        Chairperson<br>
        <?= $esc((string) ($chairName ?: '')) ?><?php if ($chairInst): ?><br><span style="color:#555;"><?= $esc((string) $chairInst) ?></span><?php endif; ?>
    </div>
</div>

<div class="footer">
    <?= $esc($footer) ?><br>
    <?= $esc((string) $meeting['reference_no']) ?> · generated <?= date('d/m/Y H:i') ?>
</div>

</body></html>
