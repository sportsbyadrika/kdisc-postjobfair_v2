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

// Minutes carry the decision points — hidden until the chair approves.
// Editors see them regardless.
$viewer = current_user() ?? [];
if (!meetings_outcome_visible($meeting, $viewer)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>Pending approval</title>'
       . '<div style="font-family: system-ui, sans-serif; padding: 2rem; max-width: 640px;">'
       . '<h2 style="margin-top: 0;">Pending chairperson approval</h2>'
       . '<p>Minutes of Meeting are hidden until the chairperson approves this meeting. Please check back after approval.</p>'
       . '<p><a href="/meeting_view.php?id=' . (int) $id . '">Back to meeting</a></p>'
       . '</div>';
    exit;
}

$fmtDate = static fn($s) => $s === null || $s === '' ? '' : date('d/m/Y', strtotime((string) $s));
$fmtTime = static fn($s) => $s === null || $s === '' ? '' : substr((string) $s, 0, 5);
$esc     = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
// For injecting a string into a CSS content: "" literal — CSS doesn't
// decode HTML entities, so plain text needs backslash-escaping of
// quotes/backslashes and no newlines.
$cssEsc  = static fn($s) => addslashes(preg_replace('/[\r\n]+/', ' ', (string) $s));

$parts = db()->prepare('SELECT mp.*, u.name AS user_name, c.name AS contact_name, c.institution
    FROM meeting_participant mp
    LEFT JOIN users u ON u.id = mp.user_id
    LEFT JOIN contact c ON c.id = mp.contact_id
    WHERE mp.meeting_id = ? ORDER BY mp.id ASC');
$parts->execute([$id]); $parts = $parts->fetchAll();

$agenda = db()->prepare('SELECT a.*, u.name AS lead_user_name, c.name AS lead_contact_name, n.name AS lead_seat_name
    FROM meeting_agenda a
    LEFT JOIN users u ON u.id = a.lead_user_id
    LEFT JOIN contact c ON c.id = a.lead_contact_id
    LEFT JOIN office_hierarchy_nodes n ON n.id = a.lead_seat_id
    WHERE a.meeting_id = ? ORDER BY a.sort_order ASC, a.id ASC');
$agenda->execute([$id]); $agenda = $agenda->fetchAll();

// Multi-lead rows per agenda item (same shape the view page uses).
$agendaLeads = [];
if ($agenda !== []) {
    $aids = array_map(static fn($a) => (int) $a['id'], $agenda);
    try {
        $ph = implode(',', array_fill(0, count($aids), '?'));
        $st = db()->prepare("SELECT mal.*, u.name AS user_name, c.name AS contact_name, n.name AS seat_name
            FROM meeting_agenda_lead mal
            LEFT JOIN users u   ON u.id = mal.user_id
            LEFT JOIN contact c ON c.id = mal.contact_id
            LEFT JOIN office_hierarchy_nodes n ON n.id = mal.seat_id
            WHERE mal.agenda_id IN ($ph)");
        $st->execute($aids);
        foreach ($st->fetchAll() as $r) $agendaLeads[(int) $r['agenda_id']][] = $r;
    } catch (Throwable $e) { /* table may be absent on legacy installs */ }
}

$decisions = db()->prepare('SELECT * FROM meeting_decision WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
$decisions->execute([$id]); $decisions = $decisions->fetchAll();

// Responsibilities + team metadata when the column exists (mirrors the
// view page's query so teams, team heads and fan-out markers survive
// in the MoM too).
$decResp = [];
if ($decisions !== []) {
    $ids = array_map(static fn($d) => (int) $d['id'], $decisions);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $hasTeamCol = false;
    try {
        foreach (db()->query('SHOW COLUMNS FROM meeting_decision_responsible')->fetchAll() as $c) {
            if (strtolower((string) $c['Field']) === 'team_id') { $hasTeamCol = true; break; }
        }
    } catch (Throwable $e) { /* ignore */ }
    $teamSelect = $hasTeamCol ? ', t.name AS team_name, uh.name AS team_head_name' : ', NULL AS team_name, NULL AS team_head_name';
    $teamJoin   = $hasTeamCol ? ' LEFT JOIN team t ON t.id = mdr.team_id LEFT JOIN users uh ON uh.id = t.head_user_id' : '';
    $r = db()->prepare("SELECT mdr.*,
            u.name AS user_name, c.name AS contact_name, n.name AS seat_name$teamSelect
        FROM meeting_decision_responsible mdr
        LEFT JOIN users u ON u.id = mdr.user_id
        LEFT JOIN contact c ON c.id = mdr.contact_id
        LEFT JOIN office_hierarchy_nodes n ON n.id = mdr.seat_id$teamJoin
        WHERE mdr.decision_id IN ($ph)");
    $r->execute($ids);
    foreach ($r->fetchAll() as $x) $decResp[(int) $x['decision_id']][] = $x;
}

// Linked tasks per decision — same shape the view page uses, so the
// MoM reflects whatever each Own-Tasks row has advanced to.
$decisionTasks = [];
if ($decisions !== []) {
    $dids = array_map(static fn($d) => (int) $d['id'], $decisions);
    try {
        $ph = implode(',', array_fill(0, count($dids), '?'));
        $st = db()->prepare("SELECT t.id AS task_id, t.meeting_decision_id, t.task_number, t.title,
                s.name AS status_name, s.colour_token,
                oh.officer_id, u.name AS officer_name,
                p.code AS project_code
            FROM task t
            LEFT JOIN task_status s ON s.id = t.status_id
            LEFT JOIN task_assignment ta ON ta.task_id = t.id AND ta.role = 'primary'
            LEFT JOIN office_hierarchy_officer_history oh ON oh.node_id = ta.seat_id AND oh.unassigned_at IS NULL
            LEFT JOIN users u ON u.id = oh.officer_id
            LEFT JOIN project p ON p.id = t.project_id
            WHERE t.meeting_decision_id IN ($ph) AND t.is_active = 1");
        $st->execute($dids);
        foreach ($st->fetchAll() as $t) $decisionTasks[(int) $t['meeting_decision_id']][] = $t;
    } catch (Throwable $e) { /* link column may not exist on legacy installs */ }
}

// Group decisions by their mapped agenda item — mirrors the on-screen
// view page. Keyed by agenda position (1, 2, 3, …); unmapped go to
// "Other" at the bottom.
$grouped = [];
foreach ($agenda as $ai => $a) $grouped[$ai + 1] = ['label' => '#' . ($ai + 1) . ' — ' . (string) $a['title'], 'items' => []];
$grouped[0] = ['label' => 'Other', 'items' => []];
$agendaPosById = [];
foreach ($agenda as $ai => $a) $agendaPosById[(int) $a['id']] = $ai + 1;
foreach ($decisions as $d) {
    $aid = (int) ($d['agenda_id'] ?? 0);
    $pos = $agendaPosById[$aid] ?? 0;
    $grouped[$pos]['items'][] = ['decision' => $d, 'responsibles' => $decResp[(int) $d['id']] ?? []];
}

$nextAg = db()->prepare('SELECT * FROM meeting_next_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
$nextAg->execute([$id]); $nextAg = $nextAg->fetchAll();
$urls   = db()->prepare('SELECT * FROM meeting_url WHERE meeting_id = ? ORDER BY id ASC');
$urls->execute([$id]); $urls = $urls->fetchAll();

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
    /* Table head repeats on every printed page break. */
    thead { display: table-header-group; }
    tr    { page-break-inside: avoid; break-inside: avoid; }
    ol { margin: 0 0 0 20px; padding: 0; font-size: 13px; }
    .decision { border: 1px solid #ccc; border-radius: 4px; padding: 8px; margin-bottom: 8px; font-size: 12px; page-break-inside: avoid; break-inside: avoid; }
    .decision-title { font-weight: bold; margin-bottom: 4px; }
    .decision .meta-row { color: #555; font-size: 11px; margin-top: 4px; }
    .group-h { background: #f0f0f0; padding: 4px 8px; font-weight: bold; font-size: 13px; margin-top: 12px; page-break-after: avoid; break-after: avoid; }
    .sig { margin-top: 40px; page-break-inside: avoid; break-inside: avoid; }
    .sig .line { border-top: 1px solid #111; width: 240px; margin-top: 40px; padding-top: 4px; font-size: 12px; }
    .footer { margin-top: 30px; font-size: 11px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; text-align: center; }
    .print-toolbar { position: sticky; top: 0; background: #fff; padding: 8px 0; border-bottom: 1px dashed #ccc; margin-bottom: 12px; z-index: 10; }

    /* --- Paged-print setup ---------------------------------------
       @page margin boxes carry a running header + footer with meeting
       reference, title and auto page numbers via counter(page) /
       counter(pages). Chrome, Firefox and Edge all honour these in
       print-to-PDF. The on-screen view keeps the ordinary flow — the
       header/footer only appear when printing. */
    @page {
        size: A4;
        margin: 22mm 14mm 22mm 14mm;
        @top-left {
            content: "<?= $cssEsc($heading !== '' ? $heading : 'Minutes of Meeting') ?>";
            font-family: 'Times New Roman', serif;
            font-size: 10pt;
            color: #333;
            padding-bottom: 4mm;
            border-bottom: 1px solid #999;
            vertical-align: bottom;
        }
        @top-right {
            content: "<?= $cssEsc((string) $meeting['reference_no']) ?>";
            font-family: 'Times New Roman', serif;
            font-size: 10pt;
            color: #333;
            padding-bottom: 4mm;
            border-bottom: 1px solid #999;
            vertical-align: bottom;
        }
        @bottom-left {
            content: "<?= $cssEsc((string) $meeting['title']) ?>";
            font-family: 'Times New Roman', serif;
            font-size: 9pt;
            color: #666;
            padding-top: 3mm;
            border-top: 1px solid #ccc;
        }
        @bottom-center {
            content: "Generated <?= date('d/m/Y H:i') ?>";
            font-family: 'Times New Roman', serif;
            font-size: 9pt;
            color: #666;
            padding-top: 3mm;
            border-top: 1px solid #ccc;
        }
        @bottom-right {
            content: "Page " counter(page) " of " counter(pages);
            font-family: 'Times New Roman', serif;
            font-size: 9pt;
            color: #333;
            padding-top: 3mm;
            border-top: 1px solid #ccc;
        }
    }
    @media print {
        .print-toolbar { display: none; }
        body { margin: 0; }
        /* First-page MoM banner (logo + heading) still prints at the top of page 1. */
        .footer { display: none; } /* We have an auto footer via @page now. */
    }
</style>
</head><body>

<div class="print-toolbar">
    <button onclick="window.print()" style="padding:6px 12px; background:#0d6efd; color:#fff; border:none; border-radius:4px; cursor:pointer;">Print / Save as PDF</button>
    <a href="/meeting_view.php?id=<?= $id ?>" style="margin-left:12px;">Back to meeting</a>
    <span style="margin-left:16px; color:#555; font-size:12px;">Use your browser's print dialog and choose "Save as PDF" for a real PDF. The page header (title + reference) and footer (page X of Y + generated time) are added automatically. In the print dialog, un-tick <strong>Headers and footers</strong> under "More settings" to avoid the browser adding its own.</span>
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
        <?php foreach ($agenda as $a):
            // Collect lead names: prefer the multi-lead table, fall back
            // to the legacy single-id columns on older rows.
            $leads = $agendaLeads[(int) $a['id']] ?? [];
            $leadNames = [];
            foreach ($leads as $l) $leadNames[] = (string) ($l['user_name'] ?: ($l['contact_name'] ?: $l['seat_name']));
            $leadNames = array_values(array_filter($leadNames));
            if ($leadNames === []) {
                $single = $a['lead_user_name'] ?: ($a['lead_contact_name'] ?: $a['lead_seat_name']);
                if ($single) $leadNames[] = (string) $single;
            }
        ?>
            <li style="margin-bottom:6px;">
                <strong><?= $esc((string) $a['title']) ?></strong>
                <?php if (!empty($a['description'])): ?><div style="color:#444; font-size:12px; white-space:pre-wrap;"><?= $esc((string) $a['description']) ?></div><?php endif; ?>
                <?php if ($leadNames !== []): ?><div style="color:#555; font-size:11px;"><em>Lead:</em> <?= $esc(implode(', ', $leadNames)) ?></div><?php endif; ?>
                <?php if (!empty($a['remarks'])): ?><div style="color:#8a6d3b; font-size:11px; white-space:pre-wrap;"><em>Remarks:</em> <?= $esc((string) $a['remarks']) ?></div><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php if (!empty($meeting['minutes_body'])): ?>
    <h3>Discussion / Minutes</h3>
    <div style="white-space:pre-wrap; font-size:13px;"><?= $esc((string) $meeting['minutes_body']) ?></div>
<?php endif; ?>

<h3>Decision points (grouped by Agenda)</h3>
<?php if ($decisions === []): ?>
    <div style="color:#666; font-size:13px;">— none —</div>
<?php endif; ?>
<?php foreach ($grouped as $pos => $group): if ($group['items'] === []) continue; ?>
    <div class="group-h"><?= $esc($group['label']) ?></div>
    <?php foreach ($group['items'] as $it): $d = $it['decision']; $rs = $it['responsibles']; ?>
        <div class="decision">
            <div class="decision-title"><?= $esc((string) $d['heading']) ?></div>
            <?php if (!empty($d['description'])): ?><div style="white-space:pre-wrap;"><?= $esc((string) $d['description']) ?></div><?php endif; ?>
            <?php if (!empty($d['remarks'])): ?><div style="color:#555; font-size:11px; margin-top:4px; white-space:pre-wrap;"><em>Remarks:</em> <?= $esc((string) $d['remarks']) ?></div><?php endif; ?>
            <div class="meta-row">
                <?php
                    $names = [];
                    foreach ($rs as $r) {
                        if (!empty($r['team_name'])) {
                            $suffix = !empty($r['team_head_name']) ? ' (head: ' . $r['team_head_name'] . ')' : '';
                            $names[] = 'Team: ' . $r['team_name'] . $suffix;
                            continue;
                        }
                        $label = $r['user_name'] ?: ($r['contact_name'] ?: $r['seat_name']);
                        if ($label !== null && $label !== '') $names[] = (string) $label;
                    }
                ?>
                <?php if ($names !== []): ?><strong>Responsible:</strong> <?= $esc(implode(', ', $names)) ?><?php endif; ?>
                <?php if ((int) ($d['fan_out_teams'] ?? 0) === 1): ?> &nbsp; · &nbsp; <em>fanned out (one task per team member)</em><?php endif; ?>
                <?php if (!empty($d['due_date'])): ?> &nbsp; · &nbsp; <strong>Due:</strong> <?= $esc($fmtDate((string) $d['due_date'])) ?><?php endif; ?>
            </div>
            <?php $ts = $decisionTasks[(int) $d['id']] ?? []; if ($ts !== []): ?>
                <div class="meta-row" style="margin-top:4px;">
                    <strong>Linked tasks:</strong>
                    <?php foreach ($ts as $t):
                        $pfx = (string) ($t['project_code'] ?? 'OWN') . '-' . (int) $t['task_number'];
                        $owner = (string) ($t['officer_name'] ?? '');
                        $status = (string) ($t['status_name'] ?? '—');
                    ?>
                        <span style="background:#f1f5f9; border:1px solid #cbd5e1; border-radius:3px; padding:1px 5px; margin-right:4px; display:inline-block;">
                            <?= $esc($pfx) ?> · <?= $esc($status) ?><?= $owner !== '' ? ' · ' . $esc($owner) : '' ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
                <li style="margin-bottom:4px;">
                    <strong><?= $esc((string) $n['title']) ?></strong>
                    <?php if (!empty($n['description'])): ?><div style="color:#444; font-size:12px; white-space:pre-wrap;"><?= $esc((string) $n['description']) ?></div><?php endif; ?>
                    <?php if (!empty($n['remarks'])): ?><div style="color:#8a6d3b; font-size:11px; white-space:pre-wrap;"><em>Remarks:</em> <?= $esc((string) $n['remarks']) ?></div><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
<?php endif; ?>

<?php if ($urls !== []): ?>
    <h3>Attachment URLs</h3>
    <ul style="font-size:13px; margin:0 0 0 20px; padding:0;">
        <?php foreach ($urls as $u): ?>
            <li><?= $esc((string) ($u['label'] ?? '')) ?: $esc((string) $u['url']) ?> — <a href="<?= $esc((string) $u['url']) ?>" style="color:#0d6efd;"><?= $esc((string) $u['url']) ?></a></li>
        <?php endforeach; ?>
    </ul>
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
