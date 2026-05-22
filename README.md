# Project Activity CRM Tracker (PHP + MySQL + Bootstrap)

A lightweight responsive web app for managing users and tracking project/CRM/report activities with role-based dashboards.

## Features
- Login using **mobile number** (username) + password.
- Roles:
  - `administrator`
  - `crm_member`
- Responsive layout for desktop, tablet, mobile.
- Top horizontal navbar with custom icon, profile info, logout.
- Footer across modules.
- Dashboard cards with role-aware insights and quick links.
- User management (admin only):
  - List + filter
  - Add/Edit/Deactivate actions via Bootstrap modal
- Phone directory:
  - View + filter for all logged-in users (Team, Name, Mobile Number, Location)
  - Add/Edit/Deactivate entries for administrators
- Activity management (both roles):
  - List + filter
  - Add/Edit/Deactivate actions via Bootstrap modal
  - Supports modules: `project`, `crm`, `report`
- Login/logout audit with IP logging.
- Admin login analytics report:
  - User-wise login counts
  - Drill-down detailed logs
- Full CRUD audit metadata:
  - `created_at`, `updated_at`, `modified_by`

## Tech Stack
- PHP 8+
- MySQL 8+
- Bootstrap 5
- MySQLi

## Setup
1. Create database and run:
   ```sql
   source schema.sql;
   ```
2. Configure database credentials (either option below):
   - Edit `config/database.php`, or
   - Set environment variables: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET`.
3. Serve app:
   ```bash
   php -S 0.0.0.0:8000
   ```
4. Open `http://localhost:8000`.

## Default Admin
- Mobile: `9999999999`
- Password: `Admin@123`

> Change default credentials immediately in production.


## Troubleshooting
- If you see `Access denied for user`, your DB username/password or host is incorrect for that server.
- Double-check the credentials in `config/database.php` (or env vars) and ensure that MySQL user has privileges on the target database.
- The app now logs detailed DB connection errors to server logs and shows a safe message in the browser instead of a fatal stack trace.
