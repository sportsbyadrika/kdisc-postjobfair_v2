# CLAUDE.md — Standing context for this repo

This file is loaded automatically at the start of every Claude Code session
in this project. It captures the ground rules the codebase actually runs on,
plus the decisions we've locked in for the Task Tracker build.

## Stack

- **PHP 8+** procedural. No frameworks. No Composer, no npm, no build step.
- **MySQL / MariaDB via `mysqli`**, wrapped by our own `Database` /
  `DatabaseStatement` classes in `includes/db.php`. Positional `?` placeholders,
  parameterised queries, no string concatenation for user data.
- **Bootstrap 5.3 + Bootstrap Icons**, loaded from CDN in `includes/layout.php`.
- **Sessions** started by `includes/auth.php` — the current user lives in
  `$_SESSION['user']`, read via `current_user()`.
- **Self-migrating schema** — every module has a `*_bootstrap()` function
  called at the top of its pages. `CREATE TABLE IF NOT EXISTS` for new tables,
  `SHOW COLUMNS` + `ALTER TABLE ADD COLUMN` guarded by try/catch for idempotent
  column adds. **Never write a migrations/* directory**; the bootstrap functions
  are the migrations.

## Folder shape

```
/                                 <- every page is a top-level PHP file
includes/                         <- shared helpers, layout, auth, db
uploads/                          <- runtime file uploads (has .htaccess)
uploads/.htaccess                 <- blocks PHP / CGI execution inside uploads
config/database.php               <- DB connection config
```

- A page's PHP + HTML live in one file. Long ones use `<?php ?>` interleaved
  with template markup.
- Small AJAX endpoints live at the repo root as `<something>_ajax_<thing>.php`.
- Includes end with `_helpers.php` when they hold module-specific helpers.

## Auth + role model

Role helpers in `includes/auth.php`:
- `is_admin()` — administrator / state_dsm / dsm_admin.
- `is_manage_admin()` — administrator / dsm_admin only.
- `is_state_dsm()`, `is_dsm_admin()`, `is_district_user()`, `is_district_pmu()`,
  `is_state_pmu()`, `is_edms()`, `is_pmu_user()`.
- `require_auth()`, `require_admin()`, `require_pmu_user()`, `require_edms()`,
  `require_district_pmu()`.

The `users.role` column is an ENUM, migrated by `ensure_user_role_support()`
in `includes/db.php`. **Add new roles by extending that ALTER, never a
separate one-shot script.**

## Design system

We lean on Bootstrap 5.3's variables — no separate `variables.css` file.
Custom accents:
- `card-stat` + `accent-<tone>` — used everywhere for stat cards.
- `status-chip` + `status-<tone>` — small badges.
- `stat-icon-box`, `stat-value`, `stat-label` — inside stat cards.
- Standard Bootstrap classes for buttons, forms, tables, modals — always.

Tone tokens we already use: `primary`, `success`, `danger`, `warning`, `info`,
`neutral`, `slate`. Add new tokens only when a colour reuse breaks semantically.

## Drag-and-drop

**No DnD exists in this repo yet.** The Task Tracker will introduce it via
[SortableJS from cdnjs.cloudflare.com](https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.0/Sortable.min.js).
Single script tag in the page that needs it. **Do not write a second DnD
implementation for later modules — reuse the same SortableJS wiring.**

The wire format when a card moves is:
`{task_id, new_status_id, prev_task_id | null, next_task_id | null}`.
Server computes `board_order = (prev.board_order + next.board_order) / 2`,
handles the null endpoints, and returns updated column counts. One row updated
per drop, never rewriting the whole column.

## CSRF

Historically the app didn't use CSRF tokens. The Task Tracker is the first
module to introduce them. `includes/csrf.php` exposes:
- `csrf_token()` — returns the current token (creates one if none).
- `csrf_field()` — echoes `<input type="hidden" name="csrf_token" value="…">`.
- `csrf_check()` — returns true iff `$_POST['csrf_token']` matches; call it at
  the top of every state-changing POST handler in Task Tracker pages.

Older modules are not required to add CSRF retroactively — but any new state-
changing handler in the Task Tracker MUST call `csrf_check()`.

## Task Tracker — decisions locked in

- **Office spine is `office_hierarchy_nodes`.** The Task Tracker reads seats
  from that table (`level_type = 'seat'`), reads section from
  `parent_id → level_type='section'`, and so on up. We do **NOT** duplicate
  into four separate tables.
- **Officer transfer history is `office_hierarchy_officer_history`.** No new
  `seat_assignment` table. Add columns idempotently if we need
  `is_additional_charge`.
- **Seat "level" is stored on the seat row.** Add
  `office_hierarchy_nodes.responsibility_level ENUM('staff','section_head',
  'division_head','office_head') NULL` via idempotent ALTER; only meaningful
  when `level_type = 'seat'`. `get_user_scope()` reads it directly, no
  inference from tree shape.
- **`board_order` DECIMAL(18,6)** for kanban positioning. `SELECT … FOR
  UPDATE` inside the same transaction as `task_number` allocation.
- **All Task Tracker forms use `csrf_field()` + `csrf_check()`.**
- **Every task field change writes a `task_history` row in the same
  transaction as the change.**
- **Dates render as `DD/MM/YYYY`** in the UI. Stored as `DATE`.
- **Deactivate, never delete** — status changes only.

## Non-negotiables

- Parameterised queries everywhere.
- `esc()` on every user-controlled string emitted into HTML.
- `password_hash()` / `password_verify()` — never store plain passwords.
- Every write-side handler is guarded by an auth gate at the top of the file.
- **Ask before pushing** if you're not sure the change is finished.
- **Give complete files, not fragments.** State which file each block goes in
  and whether it's new or a replacement.

## Style of work

Users prefer:
- Tight, honest commit messages that explain the WHY, not the WHAT.
- Reversible changes (bootstrap migrations, no destructive ALTERs).
- One phase at a time, one working feature per push.
