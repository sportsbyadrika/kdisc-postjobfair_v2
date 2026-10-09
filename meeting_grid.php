<?php
/**
 * Meeting · bulk-entry spreadsheet.
 *
 *   ?id=<meeting_id>&type=agenda|decision|next_agenda
 *
 * One editable row per existing sub-record plus a blank new-row at the
 * bottom. Every text input debounces for 500 ms (or saves immediately
 * on blur) and POSTs to the matching upsert endpoint in meeting_ajax.
 * No form Save button — the grid IS the save. The user closes the tab
 * when done.
 *
 * Columns per type:
 *   agenda       Title · Description
 *   decision     Heading · Description · Due date · Remarks
 *   next_agenda  Title · Description
 *
 * Access: meeting creator or Meetings admin (same gate as meeting_edit
 * and meeting_bulk_import).
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/rbac.php';
require_once __DIR__ . '/includes/meetings_helpers.php';
require_auth();

$viewer   = current_user() ?? [];
$viewerId = (int) ($viewer['id'] ?? 0);
meetings_bootstrap();

$isAdminAll = is_manage_admin($viewer) || user_can_admin_module($viewerId, 'meetings');

$meetingId = (int) ($_GET['id'] ?? 0);
$type      = (string) ($_GET['type'] ?? 'agenda');
if (!in_array($type, ['agenda', 'decision', 'next_agenda'], true)) $type = 'agenda';
if ($meetingId <= 0) { header('Location: /meetings.php'); exit; }

$st = db()->prepare('SELECT * FROM meeting WHERE id = ? LIMIT 1');
$st->execute([$meetingId]);
$meeting = $st->fetch();
if ($meeting === false) { header('Location: /meetings.php'); exit; }
if (!meetings_can_edit($meeting, $viewer)) {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock']);
    echo '<div class="alert alert-danger">Only the meeting creator, the chairperson, or a Meetings admin can bulk-edit this meeting.</div>';
    render_footer(); exit;
}

// Pull current rows for the selected type.
$rows = [];
try {
    if ($type === 'agenda') {
        $st = db()->prepare('SELECT id, title, description FROM meeting_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
        $st->execute([$meetingId]);
        $rows = $st->fetchAll();
    } elseif ($type === 'decision') {
        $hasRemarks = meetings_column_exists('meeting_decision', 'remarks');
        $cols = 'id, heading, description, due_date' . ($hasRemarks ? ', remarks' : ', NULL AS remarks');
        $st = db()->prepare("SELECT $cols FROM meeting_decision WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC");
        $st->execute([$meetingId]);
        $rows = $st->fetchAll();
    } else {
        $st = db()->prepare('SELECT id, title, description FROM meeting_next_agenda WHERE meeting_id = ? ORDER BY sort_order ASC, id ASC');
        $st->execute([$meetingId]);
        $rows = $st->fetchAll();
    }
} catch (Throwable $e) { /* empty */ }

$labels = [
    'agenda'      => ['Agenda · Bulk entry',             'bi-list-ol'],
    'decision'    => ['Decision points · Bulk entry',    'bi-check2-square'],
    'next_agenda' => ['Next-meeting agenda · Bulk entry', 'bi-calendar-plus'],
];
[$title, $icon] = $labels[$type];

render_header('Meetings · ' . $title, ['main_container_class' => 'container-xl']);
render_page_header($title . ' · ' . $meeting['reference_no'], [
    'icon'     => $icon,
    'subtitle' => 'Rows auto-save as you type. Use <kbd>Tab</kbd> / <kbd>Shift+Tab</kbd> to move between cells. The last blank row grows automatically — type something and the next blank row appears below it.',
    'actions'  => '<span class="small me-3" id="savedStatus"><i class="bi bi-check2-circle text-success me-1"></i>All changes saved</span>'
        . '<a class="btn btn-light" href="/meeting_edit.php?id=' . $meetingId . '"><i class="bi bi-arrow-left me-1"></i>Back to meeting</a>',
]);
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi <?= esc($icon) ?> text-primary me-1"></i><?= esc($meeting['title']) ?></span>
        <span class="small text-muted"><span id="rowCount">0</span> rows</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-top mb-0 grid-tbl" id="gridTbl">
            <thead class="table-light">
                <tr id="gridHead"></tr>
            </thead>
            <tbody id="gridBody"></tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <div class="small text-muted">Delete a row with the trash icon on the right. Deleting is permanent.</div>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addBlankBtn"><i class="bi bi-plus-lg me-1"></i>Add row</button>
    </div>
</div>

<style>
.grid-tbl th { font-size: .78rem; text-transform: uppercase; letter-spacing: .02em; }
.grid-tbl td { padding: 2px; vertical-align: top; }
.grid-tbl td.grid-num { padding: 6px 8px; width: 44px; color: #94a3b8; font-variant-numeric: tabular-nums; text-align: right; }
.grid-tbl td.grid-act { padding: 4px 6px; width: 44px; text-align: center; }
.grid-tbl .grid-cell { width: 100%; border: none; padding: 6px 8px; background: transparent; font-size: .9rem; }
.grid-tbl .grid-cell:focus { outline: 2px solid #3b82f6; outline-offset: -2px; background: #f0f9ff; }
.grid-tbl textarea.grid-cell { resize: vertical; min-height: 32px; }
.grid-tbl tr.saving td { background: #fef9c3; }
.grid-tbl tr.saved  td { transition: background .3s ease; }
.grid-tbl tr.error  td { background: #fee2e2; }
</style>

<script>
(function () {
    const G = {
        meetingId: <?= (int) $meetingId ?>,
        type: <?= json_encode($type) ?>,
        csrf: <?= json_encode(csrf_token()) ?>,
        rows: <?= json_encode($rows, JSON_UNESCAPED_UNICODE) ?>,
    };

    // Column specs per type.
    const SPECS = {
        agenda: {
            action: 'upsert_agenda',
            del:    'delete_agenda',
            cols: [
                {key: 'title',       label: 'Title',       type: 'text',     wide: 3},
                {key: 'description', label: 'Description', type: 'textarea', wide: 6},
            ],
        },
        decision: {
            action: 'upsert_decision',
            del:    'delete_decision',
            cols: [
                {key: 'heading',     label: 'Heading',     type: 'text',     wide: 3},
                {key: 'description', label: 'Description', type: 'textarea', wide: 4},
                {key: 'due_date',    label: 'Due date',    type: 'date',     wide: 1},
                {key: 'remarks',     label: 'Remarks',     type: 'textarea', wide: 2},
            ],
        },
        next_agenda: {
            action: 'upsert_next_agenda',
            del:    'delete_next_agenda',
            cols: [
                {key: 'title',       label: 'Title',       type: 'text',     wide: 3},
                {key: 'description', label: 'Description', type: 'textarea', wide: 6},
            ],
        },
    };
    const SPEC = SPECS[G.type];
    const esc  = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    // Build state. Each row is {id, data: {col: value}, dirty: false, timer: null, el: tr}.
    const state = { rows: [] };
    const toLocalRow = (r) => {
        const data = {};
        SPEC.cols.forEach(c => data[c.key] = (r[c.key] ?? '').toString());
        return { id: Number(r.id || 0), data, dirty: false, timer: null, el: null };
    };
    (G.rows || []).forEach(r => state.rows.push(toLocalRow(r)));

    // ---------- rendering ----------
    const head = document.getElementById('gridHead');
    head.innerHTML = '<th class="grid-num">#</th>'
        + SPEC.cols.map(c => `<th style="width:${c.wide * 10}%;">${esc(c.label)}</th>`).join('')
        + '<th class="grid-act">&nbsp;</th>';

    const buildRow = (row, idx) => {
        const tr = document.createElement('tr');
        tr.dataset.idx = String(idx);
        const num = document.createElement('td');
        num.className = 'grid-num';
        num.textContent = String(idx + 1);
        tr.appendChild(num);
        SPEC.cols.forEach(c => {
            const td = document.createElement('td');
            let el;
            if (c.type === 'textarea') {
                el = document.createElement('textarea');
                el.rows = 1;
            } else {
                el = document.createElement('input');
                el.type = c.type;
            }
            el.className = 'grid-cell';
            el.dataset.key = c.key;
            el.value = row.data[c.key] ?? '';
            el.addEventListener('input', onCellInput);
            el.addEventListener('change', onCellChange);
            el.addEventListener('blur', onCellBlur);
            el.addEventListener('keydown', onCellKey);
            td.appendChild(el);
            tr.appendChild(td);
        });
        const act = document.createElement('td');
        act.className = 'grid-act';
        act.innerHTML = `<button type="button" class="btn btn-sm btn-link text-danger p-0" title="Delete row" data-del="1"><i class="bi bi-trash"></i></button>`;
        act.querySelector('button').addEventListener('click', () => deleteRow(idx));
        tr.appendChild(act);
        row.el = tr;
        return tr;
    };

    const render = () => {
        const body = document.getElementById('gridBody');
        body.innerHTML = '';
        state.rows.forEach((r, i) => body.appendChild(buildRow(r, i)));
        document.getElementById('rowCount').textContent = state.rows.length;
    };

    const ensureTrailingBlank = () => {
        const last = state.rows[state.rows.length - 1];
        const isLastEmpty = last && last.id === 0 && SPEC.cols.every(c => !(last.data[c.key] || '').trim());
        if (!last || !isLastEmpty) {
            const empty = { id: 0, data: {}, dirty: false, timer: null, el: null };
            SPEC.cols.forEach(c => empty.data[c.key] = '');
            state.rows.push(empty);
            document.getElementById('gridBody').appendChild(buildRow(empty, state.rows.length - 1));
            document.getElementById('rowCount').textContent = state.rows.length;
        }
    };

    // ---------- cell events ----------
    function onCellInput(ev) {
        const tr  = ev.target.closest('tr');
        const idx = Number(tr.dataset.idx);
        const row = state.rows[idx];
        const key = ev.target.dataset.key;
        row.data[key] = ev.target.value;
        row.dirty = true;
        if (row.timer) clearTimeout(row.timer);
        row.timer = setTimeout(() => saveRow(idx), 500);
        ensureTrailingBlank();
    }
    function onCellChange(ev) { // date pickers also commit on change
        onCellInput(ev);
    }
    function onCellBlur(ev) {
        const tr  = ev.target.closest('tr');
        const idx = Number(tr.dataset.idx);
        const row = state.rows[idx];
        if (row.timer) { clearTimeout(row.timer); row.timer = null; }
        if (row.dirty) saveRow(idx);
    }
    function onCellKey(ev) {
        if (ev.key === 'Enter' && !ev.shiftKey) {
            // Move to the same column on the next row
            ev.preventDefault();
            const tr  = ev.target.closest('tr');
            const idx = Number(tr.dataset.idx);
            const key = ev.target.dataset.key;
            if (idx < state.rows.length - 1) {
                const next = state.rows[idx + 1].el?.querySelector(`[data-key="${key}"]`);
                next?.focus();
            } else {
                ensureTrailingBlank();
                state.rows[idx + 1].el?.querySelector(`[data-key="${key}"]`)?.focus();
            }
        }
    }

    // ---------- save ----------
    const setStatus = (text, tone) => {
        const el = document.getElementById('savedStatus');
        el.innerHTML = tone === 'saving' ? `<i class="bi bi-arrow-repeat text-primary me-1"></i>${esc(text)}`
                     : tone === 'error'  ? `<i class="bi bi-exclamation-triangle text-danger me-1"></i>${esc(text)}`
                     :                      `<i class="bi bi-check2-circle text-success me-1"></i>${esc(text)}`;
    };

    async function saveRow(idx) {
        const row = state.rows[idx];
        if (!row) return;
        // Skip entirely empty new rows.
        const empty = SPEC.cols.every(c => !(row.data[c.key] || '').trim());
        if (row.id === 0 && empty) { row.dirty = false; return; }
        // Decision + agenda require a non-empty title/heading before save.
        const titleKey = (G.type === 'decision') ? 'heading' : 'title';
        if (!(row.data[titleKey] || '').trim()) { row.dirty = false; return; }

        row.el?.classList.add('saving');
        row.el?.classList.remove('error', 'saved');
        setStatus('Saving…', 'saving');
        const fd = new FormData();
        fd.append('action', SPEC.action);
        fd.append('meeting_id', String(G.meetingId));
        fd.append('csrf_token', G.csrf);
        fd.append('id', String(row.id || 0));
        fd.append('grid', '1');
        SPEC.cols.forEach(c => {
            const v = (row.data[c.key] || '').toString();
            // upsert_agenda expects 'title'; upsert_decision expects 'heading'; etc.
            // Keys already match the server-side names.
            fd.append(c.key, v);
        });
        // Decision upsert needs the extra flag fields to avoid NULL
        // defaults clobbering user-set values. We ship the current
        // defaults — the full modal is where you flip them.
        if (G.type === 'decision') {
            fd.append('create_own_tasks', '1');
            fd.append('status_private',   '0');
            fd.append('fan_out_teams',    '0');
        }
        try {
            const r = await fetch('/meeting_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || 'Save failed');
            if (!row.id) row.id = Number(j.item_id || 0);
            row.dirty = false;
            row.el?.classList.remove('saving');
            row.el?.classList.add('saved');
            setStatus('All changes saved', 'ok');
        } catch (e) {
            row.el?.classList.remove('saving');
            row.el?.classList.add('error');
            setStatus('Save failed — ' + e.message, 'error');
        }
    }

    async function deleteRow(idx) {
        const row = state.rows[idx];
        if (!row) return;
        if (!confirm('Delete this row?')) return;
        if (row.id > 0) {
            const fd = new FormData();
            fd.append('action', SPEC.del);
            fd.append('meeting_id', String(G.meetingId));
            fd.append('csrf_token', G.csrf);
            fd.append('id', String(row.id));
            try {
                const r = await fetch('/meeting_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const j = await r.json();
                if (!j.ok) throw new Error(j.error || 'Delete failed');
            } catch (e) { alert(e.message); return; }
        }
        state.rows.splice(idx, 1);
        render();
        ensureTrailingBlank();
    }

    document.getElementById('addBlankBtn').addEventListener('click', () => {
        ensureTrailingBlank();
        state.rows[state.rows.length - 1].el?.querySelector('.grid-cell')?.focus();
    });

    // Warn on close if any row is still dirty.
    window.addEventListener('beforeunload', (e) => {
        if (state.rows.some(r => r.dirty)) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    render();
    ensureTrailingBlank();
})();
</script>

<?php render_footer(); ?>
