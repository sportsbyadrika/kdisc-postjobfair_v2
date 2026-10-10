<?php
/**
 * LOI & Jobs module — schema bootstrap + shared helpers.
 *
 * Entities:
 *   loi_sbu                 SBU master (International / National / HQ ...)
 *   loi_employer            one row per employer
 *   loi_job                 one job role per employer
 *   loi_job_history         audit of every field change on a job
 *   loi_interview           one interview event (may cover multiple jobs)
 *   loi_interview_job       N:M bridge with per-job counts
 *   loi_interview_candidate roster for one interview-job pair
 *
 * Mobilisation + interview checkmarks are stored as nullable DATETIME
 * columns (not booleans) so the UI's "Y/N" view is a derivation, and
 * we get reporting-grade "how long has mobilisation been running"
 * answers for free.
 */

require_once __DIR__ . '/db.php';

function loi_jobs_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();

    try {
        $db->query("CREATE TABLE IF NOT EXISTS loi_sbu (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            UNIQUE KEY unique_name (name),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed the three SBUs the user named. INSERT IGNORE keeps a
        // later admin rename stable — the seed only runs on a brand-
        // new install.
        $seed = $db->prepare('INSERT IGNORE INTO loi_sbu (name, is_active, sort_order, created_at, updated_at) VALUES (?, 1, ?, NOW(), NOW())');
        $ord = 10;
        foreach (['International', 'National', 'HQ'] as $n) {
            $seed->execute([$n, $ord]);
            $ord += 10;
        }

        $db->query("CREATE TABLE IF NOT EXISTS loi_employer (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sbu_id INT NULL,
            name VARCHAR(200) NOT NULL,
            district VARCHAR(120) NULL,
            state VARCHAR(80) NULL,
            contact_number VARCHAR(40) NULL,
            contact_email VARCHAR(200) NULL,
            hr_manager_name VARCHAR(200) NULL,
            hr_manager_mobile VARCHAR(40) NULL,
            hr_manager_email VARCHAR(200) NULL,
            sector VARCHAR(120) NULL,
            loi_received TINYINT(1) NOT NULL DEFAULT 0,
            loi_received_date DATE NULL,
            loi_file_path VARCHAR(500) NULL,
            registered_in_dwms TINYINT(1) NOT NULL DEFAULT 0,
            jobs_added_in_dwms TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            KEY idx_sbu (sbu_id),
            KEY idx_active (is_active),
            KEY idx_name (name),
            KEY idx_loi (loi_received)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_job (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employer_id INT NOT NULL,
            job_role VARCHAR(200) NOT NULL,
            vacancy_count INT NULL,
            expiry_date DATE NULL,
            qualification VARCHAR(300) NULL,
            experience_from_years DECIMAL(4,1) NULL,
            experience_to_years DECIMAL(4,1) NULL,
            experience_valid_till DATE NULL,
            preferred_candidates ENUM('fresher','experienced','either') NOT NULL DEFAULT 'either',
            status ENUM('active','closed') NOT NULL DEFAULT 'active',
            mobilisation_commenced_at DATETIME NULL,
            mobilisation_completed_at DATETIME NULL,
            applications_count INT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            KEY idx_employer (employer_id),
            KEY idx_status (status),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_job_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id INT NOT NULL,
            field_name VARCHAR(64) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            changed_by INT NULL,
            changed_at DATETIME NOT NULL,
            KEY idx_job (job_id),
            KEY idx_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_interview (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employer_id INT NOT NULL,
            scheduled_at DATETIME NULL,
            location VARCHAR(300) NULL,
            mode ENUM('in_person','online','hybrid') NOT NULL DEFAULT 'in_person',
            status ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
            completed_at DATETIME NULL,
            remarks TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            KEY idx_employer (employer_id),
            KEY idx_status (status),
            KEY idx_scheduled (scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_interview_job (
            id INT AUTO_INCREMENT PRIMARY KEY,
            interview_id INT NOT NULL,
            job_id INT NOT NULL,
            participants_count INT NOT NULL DEFAULT 0,
            selected_count INT NOT NULL DEFAULT 0,
            shortlisted_count INT NOT NULL DEFAULT 0,
            rejected_count INT NOT NULL DEFAULT 0,
            UNIQUE KEY unique_pair (interview_id, job_id),
            KEY idx_interview (interview_id),
            KEY idx_job (job_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_interview_candidate (
            id INT AUTO_INCREMENT PRIMARY KEY,
            interview_id INT NOT NULL,
            job_id INT NULL,
            candidate_name VARCHAR(200) NOT NULL,
            mobile VARCHAR(40) NULL,
            email VARCHAR(200) NULL,
            qualification VARCHAR(300) NULL,
            experience_years DECIMAL(4,1) NULL,
            outcome ENUM('pending','selected','shortlisted','rejected','no_show') NOT NULL DEFAULT 'pending',
            remarks TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            created_by INT NULL,
            updated_by INT NULL,
            KEY idx_interview (interview_id),
            KEY idx_job (job_id),
            KEY idx_outcome (outcome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* CREATE refused on hosted DB — module reads/writes degrade silently */ }
}

/**
 * Active SBUs for dropdowns. Ordered by sort_order then name.
 */
function loi_sbu_list(bool $onlyActive = true): array
{
    try {
        $sql = 'SELECT id, name, is_active, sort_order FROM loi_sbu';
        if ($onlyActive) $sql .= ' WHERE is_active = 1';
        $sql .= ' ORDER BY sort_order ASC, name ASC';
        return db()->query($sql)->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Write a loi_job_history row when a field changed. $old / $new may
 * be any scalar — null is persisted as SQL NULL and renders as "—"
 * on the audit tab.
 */
function loi_job_history_write(int $jobId, string $field, $old, $new, int $viewerId): void
{
    if ((string) $old === (string) $new) return;
    try {
        db()->prepare('INSERT INTO loi_job_history (job_id, field_name, old_value, new_value, changed_by, changed_at)
            VALUES (?, ?, ?, ?, ?, NOW())')
            ->execute([
                $jobId,
                $field,
                $old === null ? null : (string) $old,
                $new === null ? null : (string) $new,
                $viewerId > 0 ? $viewerId : null,
            ]);
    } catch (Throwable $e) { /* history table missing — best-effort */ }
}

/**
 * Dashboard-card metrics for the LOI & Jobs module. Returns zeros when
 * the schema isn't present so the card still renders on a brand-new
 * install.
 */
function loi_dashboard_counts(): array
{
    $out = [
        'active_employers'      => 0,
        'active_jobs'           => 0,
        'mobilisation_in_prog'  => 0,
        'interviews_this_week'  => 0,
    ];
    try {
        $out['active_employers'] = (int) db()->query('SELECT COUNT(*) FROM loi_employer WHERE is_active = 1')->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        $out['active_jobs'] = (int) db()->query("SELECT COUNT(*) FROM loi_job WHERE is_active = 1 AND status = 'active'")->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        $out['mobilisation_in_prog'] = (int) db()->query('SELECT COUNT(*) FROM loi_job
            WHERE is_active = 1 AND mobilisation_commenced_at IS NOT NULL AND mobilisation_completed_at IS NULL')->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    try {
        $out['interviews_this_week'] = (int) db()->query("SELECT COUNT(*) FROM loi_interview
            WHERE is_active = 1 AND status = 'scheduled'
              AND scheduled_at >= CURDATE()
              AND scheduled_at <  DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
    return $out;
}

/**
 * Convenience labels for ENUM values. Pure UI helper.
 */
function loi_pref_label(string $v): string
{
    return match ($v) {
        'fresher'      => 'Freshers only',
        'experienced'  => 'Experienced only',
        default        => 'Either',
    };
}

function loi_job_status_tone(string $s): string
{
    return match ($s) {
        'active' => 'success',
        'closed' => 'secondary',
        default  => 'info',
    };
}

function loi_interview_mode_label(string $v): string
{
    return match ($v) {
        'online'  => 'Online',
        'hybrid'  => 'Hybrid',
        default   => 'In-person',
    };
}

function loi_interview_status_tone(string $v): string
{
    return match ($v) {
        'completed' => 'success',
        'cancelled' => 'secondary',
        default     => 'primary',
    };
}

function loi_outcome_tone(string $v): string
{
    return match ($v) {
        'selected'     => 'success',
        'shortlisted'  => 'info',
        'rejected'     => 'danger',
        'no_show'      => 'secondary',
        default        => 'warning',
    };
}
