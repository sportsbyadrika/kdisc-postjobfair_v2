<?php
/**
 * Task Tracker · Bulk import (CSV).
 *
 * Two-step flow:
 *   Step 1  (upload)  — user picks a project, uploads a CSV. The file is
 *                        parsed row-by-row into an in-memory preview.
 *                        Each row is validated: seat-name matches, status
 *                        matches, date parsing, activity/sub-activity link.
 *                        The preview is stored in $_SESSION so the user
 *                        can (optionally) resolve unmatched seats via a
 *                        per-row dropdown before committing.
 *   Step 2  (commit)  — the (possibly-overridden) preview is inserted in
 *                        one transaction. Rows with errors are skipped;
 *                        the commit report says how many landed.
 *
 * CSV columns (header row required, in this exact order):
 *   Sl.No | Activity | Sub activity | Target | Primary | Secondary
 *         | Commencement | Completion | Status
 *
 * Sub-activity rows point to an activity by title within the SAME import;
 * if the activity name has already been imported (in this file) it is
 * used as parent, otherwise the row is flagged as an error.
 *
 * No spreadsheet library is bundled with this app (per CLAUDE.md: no
 * Composer). We use PHP's fgetcsv which accepts Excel-exported CSVs.
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/task_tracker_helpers.php';
require_task_tracker_admin();
task_tracker_bootstrap();

$viewer   = current_user();
$viewerId = (int) $viewer['id'];

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (($_GET['clear'] ?? '') === '1') { unset($_SESSION['task_tracker_import']); header('Location: /task_tracker_import.php'); exit; }
$flashMessage = null; $flashType = 'success';

$projects = db()->query('SELECT id, code, name, is_active, next_task_number
    FROM project WHERE office_id = ' . (int) TASK_TRACKER_OFFICE_ID . '
    AND is_active = 1 ORDER BY name ASC')->fetchAll();

$statuses = db()->query('SELECT id, name, is_active FROM task_status WHERE is_active = 1 ORDER BY sort_order ASC')->fetchAll();
$statusByName = [];
foreach ($statuses as $s) $statusByName[strtolower(trim((string) $s['name']))] = (int) $s['id'];

$seatRows = db()->query("SELECT id, name, seat_number, parent_id
    FROM office_hierarchy_nodes
    WHERE level_type = 'seat' AND active_status = 1
    ORDER BY name ASC")->fetchAll();
$seatByLowerName = [];
foreach ($seatRows as $r) {
    $key = strtolower(trim((string) $r['name']));
    if (!isset($seatByLowerName[$key])) $seatByLowerName[$key] = [];
    $seatByLowerName[$key][] = (int) $r['id'];
}
$seatById = [];
foreach ($seatRows as $r) $seatById[(int) $r['id']] = (string) $r['name'] . (empty($r['seat_number']) ? '' : ' (' . $r['seat_number'] . ')');

$parseDate = static function (string $s): ?string {
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
    return false; // signals "unparseable" — the caller distinguishes false from null
};

$resolveSeat = static function (string $name) use ($seatByLowerName): array {
    $key = strtolower(trim($name));
    if ($key === '') return ['ok' => true, 'ids' => []];
    if (!isset($seatByLowerName[$key])) return ['ok' => false, 'ids' => [], 'error' => 'Seat "' . $name . '" not found.'];
    $ids = $seatByLowerName[$key];
    if (count($ids) > 1) return ['ok' => false, 'ids' => $ids, 'error' => 'Seat "' . $name . '" matches ' . count($ids) . ' seats — pick one manually.'];
    return ['ok' => true, 'ids' => [$ids[0]]];
};

if (is_post() && ($_POST['action'] ?? '') === 'upload') {
    csrf_check_or_die();
    $projectId = (int) ($_POST['project_id'] ?? 0);
    $projectOk = false; foreach ($projects as $p) if ((int) $p['id'] === $projectId) { $projectOk = true; break; }
    if (!$projectOk) { $flashMessage = 'Select an active project.'; $flashType = 'danger'; }
    elseif (empty($_FILES['file']) || (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $flashMessage = 'Please upload a CSV file exported from Excel.'; $flashType = 'danger';
    } else {
        $fh = @fopen((string) $_FILES['file']['tmp_name'], 'r');
        if ($fh === false) { $flashMessage = 'Could not open uploaded file.'; $flashType = 'danger'; }
        else {
            $header = fgetcsv($fh);
            if (!is_array($header) || count($header) < 9) {
                $flashMessage = 'Header row must have 9 columns: Sl.No, Activity, Sub activity, Target, Primary, Secondary, Commencement, Completion, Status.';
                $flashType = 'danger';
                fclose($fh);
            } else {
                $preview = [];
                $activityKeys = []; // lowercased title → temp preview index of the activity row
                $rowNo = 1;
                while (($cells = fgetcsv($fh)) !== false) {
                    $rowNo++;
                    if (count(array_filter($cells, static fn($c) => trim((string) $c) !== '')) === 0) continue;
                    $cells = array_pad($cells, 9, '');
                    $sl        = (int) trim((string) $cells[0]);
                    $activity  = trim((string) $cells[1]);
                    $subAct    = trim((string) $cells[2]);
                    $target    = trim((string) $cells[3]);
                    $primary   = trim((string) $cells[4]);
                    $secondary = trim((string) $cells[5]);
                    $comm      = trim((string) $cells[6]);
                    $comp      = trim((string) $cells[7]);
                    $statusN   = trim((string) $cells[8]);

                    $errors = [];
                    if ($activity === '') $errors[] = 'Activity is required.';

                    $isSub = $subAct !== '';
                    $title = $isSub ? $subAct : $activity;
                    $parentPreviewIndex = null;
                    if ($isSub) {
                        $activityKey = strtolower($activity);
                        if (isset($activityKeys[$activityKey])) $parentPreviewIndex = $activityKeys[$activityKey];
                        else $errors[] = 'Parent activity "' . $activity . '" was not seen earlier in this file.';
                    }

                    $primaryResolved  = $resolveSeat($primary);
                    if (!$primaryResolved['ok']) $errors[] = $primaryResolved['error'];
                    $primarySeatId = $primaryResolved['ids'][0] ?? 0;
                    $primaryCandidates = $primaryResolved['ids'];

                    $secondaryCandidates = [];
                    $secondaryIds = [];
                    if ($secondary !== '') {
                        foreach (preg_split('/[,;]/', $secondary) as $name) {
                            $name = trim($name);
                            if ($name === '') continue;
                            $rr = $resolveSeat($name);
                            if (!$rr['ok']) { $errors[] = $rr['error']; $secondaryCandidates[$name] = $rr['ids']; }
                            else { $secondaryIds[] = $rr['ids'][0]; $secondaryCandidates[$name] = $rr['ids']; }
                        }
                    }

                    $commDate = $parseDate($comm);
                    if ($commDate === false) $errors[] = 'Commencement "' . $comm . '" is not a valid date.';
                    $compDate = $parseDate($comp);
                    if ($compDate === false) $errors[] = 'Completion "' . $comp . '" is not a valid date.';
                    if ($commDate && $compDate && $commDate > $compDate) $errors[] = 'Commencement is after completion.';

                    $statusId = $statusN === '' ? 0 : ($statusByName[strtolower($statusN)] ?? 0);
                    if ($statusN !== '' && $statusId === 0) $errors[] = 'Status "' . $statusN . '" not found.';
                    if ($statusId === 0 && $statuses !== []) $statusId = (int) $statuses[0]['id'];

                    $entry = [
                        'row_no'        => $rowNo,
                        'sl'            => $sl,
                        'activity'      => $activity,
                        'sub_activity'  => $subAct,
                        'title'         => $title,
                        'target'        => $target,
                        'is_sub'        => $isSub,
                        'parent_idx'    => $parentPreviewIndex,
                        'primary'       => $primary,
                        'primary_seat'  => $primarySeatId,
                        'primary_cands' => $primaryCandidates,
                        'secondary_raw' => $secondary,
                        'secondary_ids' => $secondaryIds,
                        'secondary_cands' => $secondaryCandidates,
                        'commencement'  => $comm,
                        'completion'    => $comp,
                        'planned_start' => $commDate === false ? null : $commDate,
                        'planned_end'   => $compDate === false ? null : $compDate,
                        'status_name'   => $statusN,
                        'status_id'     => $statusId,
                        'errors'        => $errors,
                    ];
                    $preview[] = $entry;
                    if (!$isSub) $activityKeys[strtolower($activity)] = count($preview) - 1;
                }
                fclose($fh);
                $_SESSION['task_tracker_import'] = [
                    'project_id' => $projectId,
                    'preview'    => $preview,
                ];
                header('Location: /task_tracker_import.php');
                exit;
            }
        }
    }
}

if (is_post() && ($_POST['action'] ?? '') === 'commit') {
    csrf_check_or_die();
    $store = $_SESSION['task_tracker_import'] ?? null;
    if (!is_array($store) || empty($store['preview'])) { $flashMessage = 'No preview to commit — upload again.'; $flashType = 'danger'; }
    else {
        $projectId = (int) $store['project_id'];
        $preview   = $store['preview'];
        $overrides = (array) ($_POST['override_seat'] ?? []);
        foreach ($overrides as $rowNo => $sid) {
            foreach ($preview as $i => $row) {
                if ((int) $row['row_no'] === (int) $rowNo) {
                    $preview[$i]['primary_seat'] = (int) $sid;
                    // Any row where we've now got a primary seat: remove the seat-related error, keep others.
                    $preview[$i]['errors'] = array_values(array_filter($preview[$i]['errors'],
                        static fn($e) => strpos($e, 'Seat "') !== 0 || strpos($e, $row['primary']) === false));
                }
            }
        }

        $inserted = 0; $skipped = 0; $errors = [];
        $db = db();
        $db->query('START TRANSACTION');
        try {
            $projRow = $db->prepare('SELECT next_task_number FROM project WHERE id = ? FOR UPDATE');
            $projRow->execute([$projectId]);
            $r = $projRow->fetch();
            $nextNum = (int) ($r['next_task_number'] ?? 1);

            $tempIndexToDbId = [];
            $statusFallback = (int) ($statuses[0]['id'] ?? 0);

            foreach ($preview as $idx => $row) {
                if (!empty($row['errors'])) { $skipped++; continue; }

                $orderStmt = $db->prepare('SELECT COALESCE(MAX(board_order), 0) AS m FROM task WHERE project_id = ? AND status_id = ?');
                $orderStmt->execute([$projectId, $row['status_id'] ?: $statusFallback]);
                $newOrder = ((float) ($orderStmt->fetch()['m'] ?? 0)) + 1000;

                $parentDbId = null;
                if ($row['is_sub'] && $row['parent_idx'] !== null && isset($tempIndexToDbId[$row['parent_idx']])) {
                    $parentDbId = $tempIndexToDbId[$row['parent_idx']];
                }

                $ins = $db->prepare('INSERT INTO task
                    (project_id, task_number, parent_id, title, description, target, status_id, priority,
                     planned_start, planned_end, board_order, is_active,
                     created_at, updated_at, created_by, updated_by)
                    VALUES (?, ?, ?, ?, NULL, ?, ?, "medium", ?, ?, ?, 1, NOW(), NOW(), ?, ?)');
                $ins->execute([
                    $projectId, $nextNum, $parentDbId,
                    $row['title'],
                    $row['target'] === '' ? null : $row['target'],
                    $row['status_id'] ?: $statusFallback,
                    $row['planned_start'], $row['planned_end'],
                    $newOrder, $viewerId, $viewerId,
                ]);
                $newTaskId = $db->lastInsertId();
                $tempIndexToDbId[$idx] = $newTaskId;

                $assignIns = $db->prepare('INSERT INTO task_assignment (task_id, seat_id, role, created_at, created_by) VALUES (?, ?, ?, NOW(), ?)');
                if ((int) $row['primary_seat'] > 0) {
                    $assignIns->execute([$newTaskId, (int) $row['primary_seat'], 'primary', $viewerId]);
                }
                foreach ((array) $row['secondary_ids'] as $sid) {
                    try { $assignIns->execute([$newTaskId, (int) $sid, 'secondary', $viewerId]); }
                    catch (Throwable $e) { /* dupe ignored */ }
                }

                $db->prepare('INSERT INTO task_history (task_id, field_name, old_value, new_value, changed_by, changed_at) VALUES (?, ?, NULL, ?, ?, NOW())')
                   ->execute([$newTaskId, 'created', 'Imported from CSV row ' . $row['row_no'], $viewerId]);

                $nextNum++;
                $inserted++;
            }

            $db->prepare('UPDATE project SET next_task_number = ?, updated_at = NOW(), updated_by = ? WHERE id = ?')
               ->execute([$nextNum, $viewerId, $projectId]);
            $db->query('COMMIT');
            unset($_SESSION['task_tracker_import']);
            $flashMessage = 'Imported ' . $inserted . ' task(s); skipped ' . $skipped . ' row(s) with errors.';
            $flashType = $inserted === 0 ? 'warning' : 'success';
        } catch (Throwable $e) {
            try { $db->query('ROLLBACK'); } catch (Throwable $r) { /* ignore */ }
            $flashMessage = 'Commit failed: ' . $e->getMessage(); $flashType = 'danger';
        }
    }
}

$preview = $_SESSION['task_tracker_import']['preview'] ?? [];
$storedProjectId = (int) ($_SESSION['task_tracker_import']['project_id'] ?? 0);

$projLookup = [];
foreach ($projects as $p) $projLookup[(int) $p['id']] = $p;

render_header('Task Tracker · Import', ['main_container_class' => 'container-xl']);
render_page_header('Task Tracker · Bulk import', [
    'icon' => 'bi-file-earmark-spreadsheet',
    'subtitle' => 'Upload a CSV exported from your worksheet. The preview shows what will land, row by row.',
    'actions' => '<a class="btn btn-light" href="/task_tracker_projects.php"><i class="bi bi-arrow-left me-1"></i>Back to Projects</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-1-circle text-primary me-1"></i>Upload</div>
    <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="upload">
            <div class="col-md-5">
                <label class="form-label" for="importProject">Project</label>
                <select class="form-select" id="importProject" name="project_id" required>
                    <option value="">— Select project —</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $storedProjectId === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= esc((string) $p['code']) ?> · <?= esc((string) $p['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="importFile">CSV file</label>
                <input type="file" class="form-control" id="importFile" name="file" accept=".csv,text/csv" required>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="bi bi-upload me-1"></i>Preview</button>
            </div>
            <div class="col-12 small text-muted">
                Header row required, columns in this order:
                <code>Sl.No, Activity, Sub activity, Target, Primary, Secondary, Commencement, Completion, Status</code>.
                Dates in <strong>DD/MM/YYYY</strong> or <strong>YYYY-MM-DD</strong>.
                Multiple secondary seats separated by <code>,</code> or <code>;</code>.
            </div>
        </form>
    </div>
</div>

<?php if ($preview !== []): ?>
    <?php $proj = $projLookup[$storedProjectId] ?? null; ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-2-circle text-primary me-1"></i>Preview <?= $proj ? '· ' . esc((string) $proj['code']) . ' — ' . esc((string) $proj['name']) : '' ?></span>
            <div>
                <?php $errRows = array_filter($preview, static fn($r) => !empty($r['errors'])); ?>
                <span class="badge text-bg-light border me-1"><?= count($preview) ?> rows</span>
                <?php if (count($errRows) > 0): ?><span class="badge text-bg-warning"><?= count($errRows) ?> with errors (will be skipped)</span><?php endif; ?>
            </div>
        </div>
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="commit">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Row</th>
                            <th>Type</th>
                            <th>Title</th>
                            <th>Primary</th>
                            <th>Secondary</th>
                            <th>Dates</th>
                            <th>Status</th>
                            <th>Errors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview as $r):
                            $hasErr = !empty($r['errors']);
                            $seatOptionsForRow = null;
                            $needsOverride = false;
                            foreach ($r['errors'] as $e) {
                                if (strpos($e, 'Seat "') === 0 && strpos($e, $r['primary']) !== false) { $needsOverride = true; break; }
                            }
                            if ($needsOverride) {
                                $seatOptionsForRow = $r['primary_cands'] !== [] ? $r['primary_cands'] : array_keys($seatById);
                            }
                        ?>
                            <tr class="<?= $hasErr ? 'table-warning' : '' ?>">
                                <td><?= (int) $r['row_no'] ?></td>
                                <td><?= $r['is_sub'] ? '<span class="badge text-bg-secondary">Sub</span>' : '<span class="badge text-bg-primary">Activity</span>' ?></td>
                                <td><?= esc((string) $r['title']) ?><?php if ($r['is_sub']): ?><div class="small text-muted">under: <?= esc((string) $r['activity']) ?></div><?php endif; ?></td>
                                <td>
                                    <?php if ($needsOverride && $seatOptionsForRow): ?>
                                        <select name="override_seat[<?= (int) $r['row_no'] ?>]" class="form-select form-select-sm">
                                            <option value="">Choose a seat…</option>
                                            <?php foreach ($seatOptionsForRow as $sid): ?>
                                                <option value="<?= (int) $sid ?>"><?= esc((string) ($seatById[(int) $sid] ?? '#' . (int) $sid)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ((int) $r['primary_seat'] > 0): ?>
                                        <?= esc((string) ($seatById[(int) $r['primary_seat']] ?? '#' . (int) $r['primary_seat'])) ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <?php if (!empty($r['secondary_ids'])): ?>
                                        <?php $names = array_map(static fn($sid) => $seatById[(int) $sid] ?? '#' . (int) $sid, $r['secondary_ids']); echo esc(implode(', ', $names)); ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?= esc((string) ($r['planned_start'] ?? '')) ?><br>
                                    <?= esc((string) ($r['planned_end']   ?? '')) ?>
                                </td>
                                <td class="small">
                                    <?php if ($r['status_id'] > 0): ?>
                                        <?php foreach ($statuses as $s) if ((int) $s['id'] === (int) $r['status_id']) echo esc((string) $s['name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-danger">
                                    <?php foreach ($r['errors'] as $e): ?><div><?= esc((string) $e) ?></div><?php endforeach; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <div class="small text-muted">
                    Rows with errors are skipped. Task numbers are allocated atomically from the project on commit.
                </div>
                <div>
                    <a class="btn btn-light" href="/task_tracker_import.php?clear=1">Discard preview</a>
                    <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Commit import</button>
                </div>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php render_footer(); ?>
