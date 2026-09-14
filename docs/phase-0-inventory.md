# Phase 0 — Codebase Inventory for the Task Tracker build

Written before Phase 1, so the standing context in `CLAUDE.md` and the four
locked-in decisions from the pre-build Q&A are backed by concrete
observations of what already lives in this repo. Every file mentioned here
is real and worth reading before you touch its neighbourhood.

## 1 · Stack and versions

| What                | How                                                                        |
|---------------------|----------------------------------------------------------------------------|
| Language            | **PHP 8+** procedural. `match`, enums, arrow fns, `??=`, `?->` used freely |
| Framework           | None. Pages are top-level `.php` files with inline HTML                    |
| Database            | **MySQL / MariaDB via `mysqli`**, wrapped in `Database` / `DatabaseStatement` (`includes/db.php`) |
| Templating          | PHP interleaved with markup. Shared header/footer + page header in `includes/layout.php` |
| CSS                 | **Bootstrap 5.3** from cdnjs, small overrides in the `<style>` block emitted by `render_header()` |
| JS                  | **No build step.** Inline `<script>` blocks or per-page vanilla JS.        |
| Composer            | Not used                                                                   |
| npm                 | Not used                                                                   |
| Package manager     | None — every third-party asset comes via CDN or is vendored                |

## 2 · Folder structure and naming conventions

```
/                                 <- every page lives at the repo root
CLAUDE.md                         <- standing project context (this doc's sibling)
includes/
  auth.php                        <- session, role helpers, login_user()
  csrf.php                        <- csrf_token() / csrf_check() (new in Phase 0)
  db.php                          <- Database + DatabaseStatement + ensure_user_role_support()
  layout.php                      <- render_header(), render_page_header(), render_footer()
  <module>_helpers.php            <- module bootstrap + helper functions
config/database.php               <- $HOST / $PORT / $USER / $PASS / $DB
uploads/                          <- runtime uploads with .htaccess PHP-execute deny
uploads/district_pmu/{district}/  <- per-district office photos
docs/                             <- documentation (you're reading a file here)
```

**Naming**
- Pages: `<module>_<page>.php` at repo root, e.g. `demand_side_employers.php`,
  `district_pmu_dashboard.php`, `office_hierarchy.php`.
- AJAX endpoints: `<module>_ajax_<thing>.php` at repo root, e.g.
  `demand_side_ajax_jobs_by_status.php`, `office_hierarchy_ajax_users.php`.
- Includes: `includes/<module>_helpers.php`.
- Task Tracker will follow the same pattern —
  `task_tracker_board.php`, `task_tracker_ajax_board_move.php`,
  `includes/task_tracker_helpers.php`.

### A representative existing page, top to bottom

`office_hierarchy.php` (the master we just shipped) has the anatomy you should
follow for every new page:

1. `require_once` for auth, layout, module helpers.
2. Guard: `require_auth()` + role check → 403 template render → `exit`.
3. Bootstrap the module's schema (idempotent).
4. Read the current user + inputs.
5. POST handlers (each in its own `elseif`). Every successful mutation
   `header('Location: …'); exit;` (Post-Redirect-Get). Flash message survives
   the redirect via `$_SESSION[...]`.
6. Read the display data.
7. `render_header('Title')`, `render_page_header('Title', [...])`.
8. HTML template.
9. `render_footer();`.

## 3 · Database layer

**`Database` class** (`includes/db.php:3`)

- `prepare(string $sql): DatabaseStatement` — the primary entry point.
- `query(string $sql): DatabaseResult` — for statements without parameters
  (bootstrap DDL, `SELECT COUNT(*)` etc). **Never build a `query()` call from
  user input.**
- `lastInsertId(): int` — after an INSERT.

**`DatabaseStatement` class** (`includes/db.php:37`)

- `execute(array $params = []): void` — positional `?` binding. Types
  inferred from PHP types (`int`/`string`/`double`/`bool`/`null`).
- `fetch(): array|false` — single row. Returns `false` at EOF (fixed earlier
  in this project — was returning `null` from mysqli originally).
- `fetchAll(): array` — all rows as an array of assoc arrays.
- `fetchColumn(): mixed` — first column of the first row.
- `affectedRows(): int`.

**Transactions** — plain SQL:

```php
db()->query('START TRANSACTION');
try {
    // multiple prepare/execute
    db()->query('COMMIT');
} catch (Throwable $e) {
    db()->query('ROLLBACK');
    throw $e;
}
```

**Idempotent migrations** — always follow this pattern in a `*_bootstrap()`:

```php
$db->query("CREATE TABLE IF NOT EXISTS foo (...) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cols = [];
foreach ($db->query('SHOW COLUMNS FROM foo')->fetchAll() as $c) {
    $cols[strtolower((string) $c['Field'])] = $c;
}
if (!isset($cols['new_column'])) {
    try { $db->query("ALTER TABLE foo ADD COLUMN new_column INT NULL"); } catch (Throwable $e) { /* ignore */ }
    try { $db->query("ALTER TABLE foo ADD KEY idx_new_column (new_column)"); } catch (Throwable $e) { /* ignore */ }
}
```

`ensure_user_role_support()` in `includes/db.php:130` shows the same pattern
for widening an ENUM idempotently.

## 4 · Authentication and session handling

**`includes/auth.php`** — small and complete.

- `login_user(mobile, password)` (`auth.php:103`) reads `users` by
  `mobile_number`, verifies via `password_verify()`, stashes a subset of the
  row into `$_SESSION['user']` (id, name, mobile_number, role, email,
  assigned_districts).
- `current_user(): ?array` — reads `$_SESSION['user']`.
- `require_auth()` — redirects to `/index.php` if not logged in.
- `require_admin()` — plus 403 if not `is_admin()`.
- `require_pmu_user()`, `require_edms()`, `require_district_pmu()` — narrow
  gates for those modules.
- `is_post()` — `$_SERVER['REQUEST_METHOD'] === 'POST'`.
- `esc($value): string` — wraps `htmlspecialchars`. **Use this everywhere.**

**Role helpers** at the top of `auth.php`:

| Helper                    | True for                                                                    |
|---------------------------|-----------------------------------------------------------------------------|
| `is_admin($u)`            | administrator / state_dsm / dsm_admin                                       |
| `is_manage_admin($u)`     | administrator / dsm_admin only                                              |
| `is_state_dsm($u)`        | state_dsm                                                                   |
| `is_dsm_admin($u)`        | dsm_admin                                                                   |
| `is_district_user($u)`    | district_user                                                               |
| `is_district_pmu($u)`     | district_pmu                                                                |
| `is_state_pmu($u)`        | state_pmu                                                                   |
| `is_pmu_user($u)`         | district_pmu OR state_pmu                                                   |
| `is_edms($u)`             | edms                                                                        |

**Users table role enum**, migrated by `ensure_user_role_support()`:
`administrator, crm_member, district_user, state_dsm, dsm_admin, district_pmu,
state_pmu, edms`.

**The Task Tracker introduces no new role.** It computes `level` per-user from
the seats they hold today (using `office_hierarchy_officer_history` for
currency + `office_hierarchy_nodes.responsibility_level` per seat, to be
added in Phase 1). `is_manage_admin()` still gates access to Task Tracker
admin features — same pattern as District PMU Masters.

## 5 · Design system

There is **no `variables.css` file**. We depend on Bootstrap 5.3's own CSS
custom properties (`--bs-primary`, `--bs-body-bg`, `--bs-border-color`, etc)
and add a small amount of module-specific inline CSS in the `<head>` output
of `render_header()`.

**Palette in use** (all resolved through Bootstrap tone tokens):

| Token       | Where                                                          |
|-------------|----------------------------------------------------------------|
| `primary`   | Default action buttons, headlines, stat card accents           |
| `success`   | "Green" states — Active, Verified, Valid, ≥90%                 |
| `warning`   | Yellow states — Pending / Corrected / 50-89%                   |
| `danger`    | Red states — Deactivate, Invalid, Overdue                      |
| `info`      | Cyan chips — informational tallies                             |
| `neutral`   | Grey chips — role labels                                       |
| `slate`     | KPI icon-box on non-status metrics                             |
| `light`     | Table backgrounds, borders                                     |

**Custom classes** live inline in `render_header()`:

- `.card-stat.accent-<tone>` — the shadowed card with a coloured top edge.
- `.stat-icon-box.tone-<tone>` — the circular icon block on the right of a
  card.
- `.stat-value`, `.stat-label`, `.stat-link` — inside stat cards.
- `.status-chip.status-<tone>` — pill badges.
- `.empty-state` — centred inbox icon + message inside empty tables/cards.
- `.table-card` — subtle wrapper around a `.table-responsive` card.

**Task Tracker should reuse these classes** for stat tiles (My open work,
Overdue etc). New Kanban-only classes go in a small
`assets/css/task_tracker.css` OR inline in `task_tracker_board.php`.

**Typography scale** — inherited from Bootstrap; use `.h5` / `.h6` /
`.small` / `.fw-semibold` / `.fw-bold` directly.

**Radii + shadows** — Bootstrap defaults + `.card-stat` uses its own shadow
override.

## 6 · Drag-and-drop

**Grepped `dragstart`, `sortable`, `Sortable`, `dragover` across every `.php`
file — no matches.** Nothing in this repo currently ships drag-and-drop.

**Decision (locked in the Q&A):** the Task Tracker will introduce
[SortableJS](https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js)
from cdnjs (an already-approved CDN for this project — we load Leaflet and
Chart.js style libs from cdnjs elsewhere).

Wire format when a card moves is settled: server receives
`{task_id, new_status_id, prev_task_id | null, next_task_id | null}` and
computes `board_order = midpoint of neighbours`. One UPDATE per drop, and one
`task_history` row in the same transaction.

Subsequent modules that want DnD reuse the same SortableJS bootstrap. Do not
add a second implementation.

## 7 · Other things Task Tracker plugs into

- **Layout**: `render_header(string $title, array $opts = [])` +
  `render_page_header(string $title, array $opts = [])` +
  `render_footer()`. Options include `main_container_class`, `icon`,
  `subtitle`, `actions` (raw HTML string).
- **Navigation registry**: `includes/layout.php` renders every top-nav link
  inline. Task Tracker adds a new nav-item block gated behind a new helper
  `is_task_tracker_user($u)` — added in Phase 1 alongside the module.
- **Notifications**: none currently. Task Tracker's bell + digest are new; a
  small `task_tracker_notifications` table + a shared "notifications for the
  current user" query.
- **PDF / print**: no server-side PDF library. Print-ready pages use CSS
  `@page` + browser print-to-PDF (as we do on the Approved Asset Register).
  Task Tracker reports follow the same pattern.
- **File upload helper**: none. `district_pmu_office_profile.php` shows the
  pattern for a single-file upload with a MIME allowlist + size cap into
  `uploads/…/`. Task Tracker attachments reuse it.
- **CSV export**: pattern is `while (ob_get_level() > 0) { ob_end_clean(); }
  header('Content-Type: text/csv; charset=utf-8'); …; fputcsv();` — used in
  `demand_side_stats.php` and elsewhere. Task Tracker's exports follow it.
- **Excel import**: no xlsx parser exists. Phase 2 spec calls for `.xlsx`
  import; simplest path is to vendor `phpoffice/phpspreadsheet` under
  `vendor/` (no composer). Alternative: require `.csv` and skip xlsx.
  **Decide before Phase 2**; Phase 1 doesn't need it.

## 8 · Gaps vs. the Task Tracker standing context

| Assumed by CLAUDE.md                       | Reality                                                        | Fix in                    |
|--------------------------------------------|----------------------------------------------------------------|---------------------------|
| `csrf_token()` / `csrf_check()`            | Now shipped in `includes/csrf.php`                             | ✅ Phase 0                |
| Office / Division / Section / Seat tables  | Unified `office_hierarchy_nodes` + `office_hierarchy_officer_history` (KEEP) | ✅ Reuse            |
| `seat_assignment` table                    | Use `office_hierarchy_officer_history` (rename fields conceptually — from_date ↔ assigned_at, to_date ↔ unassigned_at) | Phase 1: add `is_additional_charge` col idempotently |
| `users.avatar_colour`                      | Missing                                                        | Phase 1: idempotent ALTER + seed |
| `seat.level` (staff / section_head / …)    | Missing                                                        | Phase 1: `office_hierarchy_nodes.responsibility_level` |
| Drag-and-drop module                       | None                                                           | Phase 1 / 2: SortableJS   |
| `task_status`, `task`, `task_history`, `task_remark`, `task_file`, `task_assignment`, `project` | None | Phase 1 / 2 |
| Notifications system                       | None                                                           | Phase 5                   |
| xlsx parser                                | None (CSV works)                                              | Phase 2 decision          |
| Chart library                              | None                                                           | Phase 4: Chart.js from cdnjs |

## 9 · How to hand off to Phase 1

Phase 1 should:
1. Create `includes/task_tracker_helpers.php` with `task_tracker_bootstrap()`
   that creates `project`, `task`, `task_status`, `task_assignment`,
   `task_history`, `task_remark`, `task_file`, and adds the columns above
   idempotently.
2. Add `get_user_scope($user_id)` reading `office_hierarchy_officer_history`
   for currency (`unassigned_at IS NULL`) + `office_hierarchy_nodes.
   responsibility_level` for the level per seat.
3. Ship the seat-assignment transfer screen (using the existing
   `office_hierarchy_officer_history` table + new `is_additional_charge`
   column). Do **NOT** rebuild the Office Hierarchy tree screen — reuse the
   existing `office_hierarchy.php`; only add a "responsibility level" picker
   on the seat form there.
4. Ship the users master (list + create + reset password + assign
   avatar_colour). Extend `users.php` — don't fork it.
5. Ship the task_status master with SortableJS drag-to-reorder (first use of
   SortableJS in the repo).
6. Ship the Task Tracker shell (left sidebar + breadcrumb + title row),
   reusing `render_header` / `render_page_header` for the header and adding a
   thin `<aside>` sidebar via an include.

Nothing user-facing beyond the masters ships in Phase 1. The Kanban board
lives in Phase 2.
