<?php
/**
 * Meeting detail (read-only summary).
 * Every viewer with access rights sees this page.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();
$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: /meetings.php'); exit; }
$stmt = db()->prepare('SELECT m.*, u.name AS created_by_name,
        cu.name AS chair_user_name, cc.name AS chair_contact_name, cc.institution AS chair_contact_inst,
        pm.reference_no AS prev_ref, pm.title AS prev_title
    FROM meeting m
    LEFT JOIN users u ON u.id = m.created_by
    LEFT JOIN users cu ON cu.id = m.chair_user_id
    LEFT JOIN contact cc ON cc.id = m.chair_contact_id
    LEFT JOIN meeting pm ON pm.id = m.previous_meeting_id
    WHERE m.id = ? LIMIT 1');
$stmt->execute([$id]);
$meeting = $stmt->fetch();
if ($meeting === false) { header('Location: /meetings.php'); exit; }

$isCreator = $viewerId === (int) $meeting['created_by'];
$canEdit   = $isAdminAll || $isCreator;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$flashMessage = null; $flashType = 'success';
if (!empty($_SESSION['meeting_view_flash']) && is_array($_SESSION['meeting_view_flash'])) {
    $flashMessage = (string) ($_SESSION['meeting_view_flash']['msg']  ?? '');
    $flashType    = (string) ($_SESSION['meeting_view_flash']['type'] ?? 'success');
    unset($_SESSION['meeting_view_flash']);
}

$fmtDate = static fn($s) => $s === null || $s === '' ? '—' : date('d/m/Y', strtotime((string) $s));
$fmtTime = static fn($s) => $s === null || $s === '' ? '' : substr((string) $s, 0, 5);

$participants = db()->prepare('SELECT mp.*, u.name AS user_name, c.name AS contact_name, c.institution
    FROM meeting_participant mp
    LEFT JOIN users u ON u.id = mp.user_id
    LEFT JOIN contact c ON c.id = mp.contact_id
    WHERE mp.meeting_id = ? ORDER BY mp.id ASC');
$participants->execute([$id]); $participants = $participants->fetchAll();

$agenda = db()->prepare('SELECT a.*, u.name AS lead_user_name, c.name AS lead_contact_name, n.name AS lead_seat_name
    FROM meeting_agenda a
    LEFT JOIN users u ON u.id = a.lead_user_id
    LEFT JOIN contact c ON c.id = a.lead_contact_id
    LEFT JOIN office_hierarchy_nodes n ON n.id = a.lead_seat_id
    WHERE a.meeting_id = ? ORDER BY a.sort_order ASC, a.id ASC');
$agenda->execute([$id]); $agenda = $agenda->fetchAll();

// Multi-lead rows for every agenda item.
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
    } catch (Throwable $e) { /* table may not exist yet */ }
}

$decisions = db()->prepare('SELECT * FROM meeting_decision WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
$decisions->execute([$id]); $decisions = $decisions->fetchAll();

// Live task status per decision — each decision may have several
// tasks (one per internal user responsible with an active seat).
$decisionTasks = [];
if ($decisions !== []) {
    $dids = array_map(static fn($d) => (int) $d['id'], $decisions);
    try {
        $ph = implode(',', array_fill(0, count($dids), '?'));
        $st = db()->prepare("SELECT t.id AS task_id, t.meeting_decision_id, t.task_number, t.title, t.is_active,
                s.name AS status_name, s.colour_token, s.is_terminal, s.category,
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
$decRespRows = [];
if ($decisions !== []) {
    $ids = array_map(static fn($d) => (int) $d['id'], $decisions);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $r = db()->prepare("SELECT mdr.*, u.name AS user_name, c.name AS contact_name, n.name AS seat_name
        FROM meeting_decision_responsible mdr
        LEFT JOIN users u ON u.id = mdr.user_id
        LEFT JOIN contact c ON c.id = mdr.contact_id
        LEFT JOIN office_hierarchy_nodes n ON n.id = mdr.seat_id
        WHERE mdr.decision_id IN ($ph)");
    $r->execute($ids);
    foreach ($r->fetchAll() as $x) $decRespRows[(int) $x['decision_id']][] = $x;
}
$nextAg = db()->prepare('SELECT * FROM meeting_next_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
$nextAg->execute([$id]); $nextAg = $nextAg->fetchAll();
$urls   = db()->prepare('SELECT * FROM meeting_url WHERE meeting_id = ? ORDER BY id ASC');
$urls->execute([$id]); $urls = $urls->fetchAll();

$status = meetings_effective_status($meeting);
$tone   = meetings_status_tone($status);

render_header('Meeting · ' . $meeting['reference_no'], ['main_container_class' => 'container-xl']);
render_page_header($meeting['reference_no'] . ' · ' . $meeting['title'], [
    'icon'     => 'bi-calendar2-week',
    'subtitle' => 'Meeting details, participants, agenda, decisions.',
    'actions'  => ($canEdit ? '<a class="btn btn-primary" href="/meeting_edit.php?id=' . $id . '"><i class="bi bi-pencil me-1"></i>Edit</a>' : '')
        . '<a class="btn btn-success ms-2" href="/meeting_mom.php?id=' . $id . '" target="_blank"><i class="bi bi-file-earmark-text me-1"></i>MoM (printable)</a>'
        . '<a class="btn btn-light ms-2" href="/meetings.php"><i class="bi bi-arrow-left me-1"></i>Back</a>',
]);
?>

<?php if ($flashMessage): ?><div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><div class="small text-muted">Date</div><div class="fw-semibold"><?= esc($fmtDate($meeting['meeting_date'])) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Time</div><div class="fw-semibold"><?= esc($fmtTime($meeting['from_time'])) ?><?= empty($meeting['to_time']) ? '' : ' – ' . esc($fmtTime($meeting['to_time'])) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">Status</div><span class="badge text-bg-<?= esc($tone) ?>"><?= esc(ucfirst($status)) ?></span></div>
                    <div class="col-md-6"><div class="small text-muted">Location</div><div><?= esc((string) ($meeting['location'] ?? '—')) ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Virtual link</div><div><?php if (!empty($meeting['virtual_link'])): ?><a href="<?= esc((string) $meeting['virtual_link']) ?>" target="_blank"><?= esc((string) $meeting['virtual_link']) ?></a><?php else: ?>—<?php endif; ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Reference</div><div class="font-monospace"><?= esc((string) $meeting['reference_no']) ?></div></div>
                    <div class="col-md-6"><div class="small text-muted">Created by</div><div><?= esc((string) ($meeting['created_by_name'] ?? '—')) ?></div></div>
                    <?php
                        $chairName = trim((string) ($meeting['chair_user_name'] ?? '')) !== ''
                            ? (string) $meeting['chair_user_name'] : (string) ($meeting['chair_contact_name'] ?? '');
                        $chairType = trim((string) ($meeting['chair_user_name'] ?? '')) !== '' ? 'internal' : ((int) ($meeting['chair_contact_id'] ?? 0) > 0 ? 'external' : '');
                        $chairInstitution = (string) ($meeting['chair_contact_inst'] ?? '');
                    ?>
                    <div class="col-md-6">
                        <div class="small text-muted">Chairperson (<?= $chairType === 'external' ? 'External User' : ($chairType ?: '—') ?>)</div>
                        <div>
                            <?= $chairName !== '' ? esc($chairName) : '<span class="text-muted">—</span>' ?>
                            <?php if ($chairInstitution !== ''): ?><span class="small text-muted">· <?= esc($chairInstitution) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="small text-muted">Previous meeting</div>
                        <div>
                            <?php if (!empty($meeting['previous_meeting_id'])): ?>
                                <a href="/meeting_view.php?id=<?= (int) $meeting['previous_meeting_id'] ?>">
                                    <span class="font-monospace small"><?= esc((string) ($meeting['prev_ref'] ?? '')) ?></span>
                                    <?php if (!empty($meeting['prev_title'])): ?> · <?= esc((string) $meeting['prev_title']) ?><?php endif; ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-file-text me-1"></i>Purpose</div>
            <div class="card-body"><div style="white-space:pre-wrap;"><?= esc((string) ($meeting['purpose'] ?? '')) ?: '<span class="text-muted">—</span>' ?></div></div>
        </div>

        <?php if (!empty($meeting['minutes_body'])): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-journal-text me-1"></i>Minutes / notes</div>
            <div class="card-body"><div style="white-space:pre-wrap;"><?= esc((string) $meeting['minutes_body']) ?></div></div>
        </div>
        <?php endif; ?>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-list-ol me-1"></i>Agenda</div>
            <div class="card-body">
                <?php if ($agenda === []): ?><span class="text-muted">— none —</span><?php else: ?>
                <ol class="mb-0">
                    <?php foreach ($agenda as $a): ?>
                        <li class="mb-2">
                            <strong><?= esc((string) $a['title']) ?></strong>
                            <?php if (!empty($a['description'])): ?><div class="small text-muted" style="white-space:pre-wrap;"><?= esc((string) $a['description']) ?></div><?php endif; ?>
                            <?php
                                $leads = $agendaLeads[(int) $a['id']] ?? [];
                                $leadNames = [];
                                foreach ($leads as $l) {
                                    $leadNames[] = (string) ($l['user_name'] ?: ($l['contact_name'] ?: $l['seat_name']));
                                }
                                if ($leadNames === []) {
                                    // Fallback for pre-multi-lead rows.
                                    $single = $a['lead_user_name'] ?: ($a['lead_contact_name'] ?: $a['lead_seat_name']);
                                    if ($single) $leadNames[] = (string) $single;
                                }
                                $leadNames = array_filter($leadNames);
                                if ($leadNames !== []) echo '<div class="small text-muted"><i class="bi bi-person-check me-1"></i>Lead: ' . esc(implode(', ', $leadNames)) . '</div>';
                            ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-check2-square me-1"></i>Decision points</div>
            <div class="card-body">
                <?php if ($decisions === []): ?><span class="text-muted">— none —</span><?php else: ?>
                <?php foreach ($decisions as $d): ?>
                    <div class="border-bottom pb-2 mb-2">
                        <strong><?= esc((string) $d['heading']) ?></strong>
                        <?php if (!empty($d['description'])): ?><div class="small" style="white-space:pre-wrap;"><?= esc((string) $d['description']) ?></div><?php endif; ?>
                        <?php if (!empty($d['due_date'])): ?><div class="small text-muted">Due: <?= esc($fmtDate($d['due_date'])) ?></div><?php endif; ?>
                        <?php $rs = $decRespRows[(int) $d['id']] ?? []; if ($rs !== []): ?>
                            <div class="small mt-1">Responsible:
                                <?php foreach ($rs as $r): $label = $r['user_name'] ?: ($r['contact_name'] ?: $r['seat_name']); ?>
                                    <span class="badge text-bg-light border me-1"><?= esc((string) $label) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php
                            $ts = $decisionTasks[(int) $d['id']] ?? [];
                            // Privacy: when status_private = 1 on the decision, only
                            // the task's own owner (and admins) see its status chip.
                            $isPrivate = (int) ($d['status_private'] ?? 0) === 1;
                            if ($isPrivate && !$canEdit) {
                                $ts = array_values(array_filter($ts, static fn($t) => (int) ($t['officer_id'] ?? 0) === $viewerId));
                            }
                        ?>
                        <?php if ($ts !== []): ?>
                            <div class="small mt-2">
                                <span class="text-muted">Linked tasks:</span>
                                <?php if ($isPrivate): ?><span class="badge text-bg-dark me-1" title="Status is hidden from others"><i class="bi bi-lock-fill"></i> private</span><?php endif; ?>
                                <?php foreach ($ts as $t):
                                    $tone = (string) ($t['colour_token'] ?? 'secondary'); if ($tone === 'neutral') $tone = 'secondary';
                                    $pfx  = (string) ($t['project_code'] ?? 'OWN') . '-' . (int) $t['task_number'];
                                ?>
                                    <a class="badge text-bg-<?= esc($tone) ?> text-decoration-none me-1" href="/task_tracker_task_view.php?id=<?= (int) $t['task_id'] ?>"
                                       title="<?= esc((string) ($t['officer_name'] ?? '')) ?>">
                                        <?= esc($pfx) ?> · <?= esc((string) ($t['status_name'] ?? '—')) ?><?= !empty($t['officer_name']) ? ' · ' . esc((string) $t['officer_name']) : '' ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($isPrivate && (int) ($d['create_own_tasks'] ?? 1) === 1): ?>
                            <div class="small mt-2 text-muted"><i class="bi bi-lock-fill me-1"></i>Task status is private — only the owner can see it.</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($meeting['next_meeting_date']) || $nextAg !== []): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-calendar-plus me-1"></i>Next meeting</div>
            <div class="card-body">
                <?php if (!empty($meeting['next_meeting_date'])): ?>
                    <div class="mb-2"><strong>Date:</strong> <?= esc($fmtDate($meeting['next_meeting_date'])) ?><?php if (!empty($meeting['next_meeting_time'])): ?> · <?= esc($fmtTime($meeting['next_meeting_time'])) ?><?php endif; ?></div>
                <?php endif; ?>
                <?php if ($nextAg !== []): ?>
                    <div class="small text-muted mb-1">Agenda</div>
                    <ul class="mb-0">
                        <?php foreach ($nextAg as $n): ?>
                            <li><strong><?= esc((string) $n['title']) ?></strong><?php if (!empty($n['description'])): ?><div class="small text-muted"><?= esc((string) $n['description']) ?></div><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people me-1"></i>Participants</span>
                <span class="badge text-bg-light border"><?= count($participants) ?></span>
            </div>
            <div class="card-body p-2">
                <?php if ($participants === []): ?><div class="text-muted small">— none —</div><?php endif; ?>
                <?php foreach ($participants as $p):
                    $label = $p['user_name'] ?: $p['contact_name'];
                    $sub = $p['contact_name'] ? (string) ($p['institution'] ?? '') : '';
                ?>
                    <div class="border-bottom py-2 small">
                        <strong><?= esc((string) $label) ?></strong>
                        <?php if ($sub !== ''): ?><div class="text-muted"><?= esc($sub) ?></div><?php endif; ?>
                        <span class="badge text-bg-<?= (int) $p['is_mandatory'] === 1 ? 'danger' : 'secondary' ?>"><?= (int) $p['is_mandatory'] === 1 ? 'Mandatory' : 'Optional' ?></span>
                        <?php if ($p['attended'] === null || $p['attended'] === ''): ?>
                            <span class="badge text-bg-light border">Attendance not recorded</span>
                        <?php elseif ((int) $p['attended'] === 1): ?>
                            <span class="badge text-bg-success">Present</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Absent</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($urls !== []): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="bi bi-link-45deg me-1"></i>Attachment URLs</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($urls as $u): ?>
                    <li class="list-group-item small"><a href="<?= esc((string) $u['url']) ?>" target="_blank" rel="noopener"><?= esc((string) ($u['label'] ?? '') ?: (string) $u['url']) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php render_footer(); ?>
