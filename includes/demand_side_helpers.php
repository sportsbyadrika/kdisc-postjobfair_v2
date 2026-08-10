<?php
/**
 * Demand Side helpers: schema bootstrap, editable-field allowlists, edit
 * log writer and shared field metadata for the demand_employers /
 * demand_employer_jobs domain.
 *
 * All demand-side pages require_admin(), which admits both
 * 'administrator' and 'state_dsm' roles.
 */

require_once __DIR__ . '/db.php';

function demand_side_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $db = db();

    $db->query("CREATE TABLE IF NOT EXISTS demand_employers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        clustered_employer_id INT NULL,
        employer_id INT NOT NULL,
        created_datetime DATETIME NULL,
        employer_name VARCHAR(500) NULL,
        website VARCHAR(500) NULL,
        company_address TEXT NULL,
        job_agency_id VARCHAR(100) NULL,
        jobagency VARCHAR(255) NULL,
        jobfair_flag VARCHAR(50) NULL,
        vk_flag VARCHAR(50) NULL,
        clusteremployername VARCHAR(500) NULL,
        nic_section_code VARCHAR(50) NULL,
        nic_section_name VARCHAR(255) NULL,
        nic_division_code VARCHAR(50) NULL,
        nic_division_name VARCHAR(255) NULL,
        nic_group_code VARCHAR(50) NULL,
        nic_group_name VARCHAR(255) NULL,
        nic_class_code VARCHAR(50) NULL,
        nic_class_name VARCHAR(255) NULL,
        nic_sub_class_code VARCHAR(50) NULL,
        nic_sub_class_name VARCHAR(255) NULL,
        reason_for_classification TEXT NULL,
        type_of_company VARCHAR(100) NULL,
        active_status VARCHAR(50) NULL,
        final_status VARCHAR(50) NULL,
        remarks TEXT NULL,
        task_owner_id INT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY unique_employer_id (employer_id),
        KEY idx_active_status (active_status),
        KEY idx_employer_name (employer_name(100))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS demand_employer_jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        jobtitle VARCHAR(500) NULL,
        emp_id INT NOT NULL,
        emp_name VARCHAR(500) NULL,
        open_positions INT NULL,
        salary_type VARCHAR(100) NULL,
        salary_slab VARCHAR(100) NULL,
        qualificationcategory VARCHAR(255) NULL,
        vk_flag VARCHAR(50) NULL,
        posted_on DATE NULL,
        posted_by VARCHAR(255) NULL,
        expired_date DATE NULL,
        corrected_open_position INT NULL,
        status ENUM('Valid','Invalid','Corrected') NULL,
        remarks TEXT NULL,
        remarks_group_id INT NULL,
        task_owner_id INT NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        min_experience DECIMAL(4,1) NULL,
        max_experience DECIMAL(4,1) NULL,
        academic_preference VARCHAR(500) NULL,
        job_sector VARCHAR(255) NULL,
        location VARCHAR(500) NULL,
        domain_skills TEXT NULL,
        soft_skills TEXT NULL,
        job_agency VARCHAR(255) NULL,
        specialization VARCHAR(500) NULL,
        courses TEXT NULL,
        location_type VARCHAR(100) NULL,
        employment_mode VARCHAR(100) NULL,
        age_preference VARCHAR(100) NULL,
        gender_preference VARCHAR(100) NULL,
        job_category VARCHAR(255) NULL,
        job_sub_category VARCHAR(255) NULL,
        jobfair_only_job VARCHAR(20) NULL,
        posted_in_job_fair VARCHAR(20) NULL,
        number_of_applications INT NULL,
        job_status_data VARCHAR(100) NULL,
        UNIQUE KEY unique_job_id (job_id),
        KEY idx_emp_id (emp_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Backfill columns on existing installs. SHOW COLUMNS + ALTER TABLE is
    // idempotent-safe: each column is added only when absent.
    $existing = [];
    foreach ($db->query('SHOW COLUMNS FROM demand_employer_jobs')->fetchAll() as $col) {
        $existing[(string) $col['Field']] = true;
    }
    $extraCols = [
        'min_experience'         => 'DECIMAL(4,1) NULL',
        'max_experience'         => 'DECIMAL(4,1) NULL',
        'academic_preference'    => 'VARCHAR(500) NULL',
        'job_sector'             => 'VARCHAR(255) NULL',
        'location'               => 'VARCHAR(500) NULL',
        'domain_skills'          => 'TEXT NULL',
        'soft_skills'            => 'TEXT NULL',
        'job_agency'             => 'VARCHAR(255) NULL',
        'specialization'         => 'VARCHAR(500) NULL',
        'courses'                => 'TEXT NULL',
        'location_type'          => 'VARCHAR(100) NULL',
        'employment_mode'        => 'VARCHAR(100) NULL',
        'age_preference'         => 'VARCHAR(100) NULL',
        'gender_preference'      => 'VARCHAR(100) NULL',
        'job_category'           => 'VARCHAR(255) NULL',
        'job_sub_category'       => 'VARCHAR(255) NULL',
        'jobfair_only_job'       => 'VARCHAR(20) NULL',
        'posted_in_job_fair'     => 'VARCHAR(20) NULL',
        'number_of_applications' => 'INT NULL',
        'job_status_data'        => 'VARCHAR(100) NULL',
    ];
    foreach ($extraCols as $col => $def) {
        if (!isset($existing[$col])) {
            try { $db->query("ALTER TABLE demand_employer_jobs ADD COLUMN $col $def"); } catch (Throwable $e) { /* ignore */ }
        }
    }

    $db->query("CREATE TABLE IF NOT EXISTS demand_employer_edit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employer_row_id INT NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        edited_by INT NOT NULL,
        edited_at DATETIME NOT NULL,
        KEY idx_employer_row_id (employer_row_id),
        KEY idx_edited_by (edited_by),
        KEY idx_edited_at (edited_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS demand_employer_job_edit_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_row_id INT NOT NULL,
        field_name VARCHAR(64) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        edited_by INT NOT NULL,
        edited_at DATETIME NOT NULL,
        KEY idx_job_row_id (job_row_id),
        KEY idx_edited_by (edited_by),
        KEY idx_edited_at (edited_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->query("CREATE TABLE IF NOT EXISTS demand_remarks_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY unique_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Per-user employer scope. When a non-administrator user has one or more
    // rows here, the Demand Side Employer listing is filtered down to those
    // employer_ids only.
    $db->query("CREATE TABLE IF NOT EXISTS demand_user_employer_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        employer_id INT NOT NULL,
        assigned_by INT NULL,
        assigned_at DATETIME NOT NULL,
        UNIQUE KEY unique_user_employer (user_id, employer_id),
        KEY idx_user_id (user_id),
        KEY idx_employer_id (employer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Foreign key: demand_employer_jobs.emp_id -> demand_employers.employer_id.
    // Older installations that were created before the FK existed will pick it
    // up here; the ALTER is skipped silently if orphaned rows or a non-InnoDB
    // engine would block it, so bootstrap never fails mid-page-load.
    try {
        $fkExists = (int) $db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'demand_employer_jobs'
              AND CONSTRAINT_NAME = 'fk_demand_employer_jobs_emp'")->fetchColumn();
        if ($fkExists === 0) {
            $db->query("ALTER TABLE demand_employer_jobs
                ADD CONSTRAINT fk_demand_employer_jobs_emp
                FOREIGN KEY (emp_id) REFERENCES demand_employers (employer_id)
                ON UPDATE CASCADE ON DELETE RESTRICT");
        }
    } catch (Throwable $e) {
        // Orphaned emp_id rows or engine mismatch — leave the app-level
        // guard in the upload wizard as the source of truth.
    }

    // Seed defaults if empty.
    $seedCount = (int) $db->query('SELECT COUNT(*) FROM demand_remarks_groups')->fetchColumn();
    if ($seedCount === 0) {
        $seedStmt = $db->prepare('INSERT INTO demand_remarks_groups (name, active) VALUES (?, 1)');
        foreach ([
            'Data Entry Error',
            'Duplicate Posting',
            'Position Filled',
            'Employer Requested Withdrawal',
            'Invalid Contact Details',
            'Salary Mismatch',
            'Other',
        ] as $seed) {
            try { $seedStmt->execute([$seed]); } catch (Throwable $e) { /* ignore */ }
        }
    }
}

/** Editable fields for demand_employers (per spec). */
function demand_employer_editable_fields(): array
{
    return ['active_status', 'remarks'];
}

/** Editable fields for demand_employer_jobs (per spec). */
function demand_employer_job_editable_fields(): array
{
    return [
        'posted_on', 'posted_by', 'expired_date', 'corrected_open_position',
        'status', 'remarks', 'remarks_group_id',
    ];
}

function demand_employer_active_status_options(): array
{
    return ['Valid', 'Invalid', 'Need Correction'];
}

function demand_employer_job_status_options(): array
{
    return ['Valid', 'Invalid', 'Corrected'];
}

/**
 * Log a per-field change to demand_employer_edit_log or
 * demand_employer_job_edit_log. Skips no-op writes.
 */
function demand_write_edit_log(string $target, int $rowId, string $field, ?string $oldValue, ?string $newValue, int $editedBy): void
{
    if ((string) ($oldValue ?? '') === (string) ($newValue ?? '')) {
        return;
    }
    $table = $target === 'employer' ? 'demand_employer_edit_log' : 'demand_employer_job_edit_log';
    $col = $target === 'employer' ? 'employer_row_id' : 'job_row_id';
    $stmt = db()->prepare("INSERT INTO $table ($col, field_name, old_value, new_value, edited_by, edited_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$rowId, $field, $oldValue, $newValue, $editedBy]);
}

/**
 * Normalise a CSV header cell to a lookup key (lowercase, non-alnum -> _).
 */
function demand_normalise_header(string $raw): string
{
    return strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($raw)));
}

/**
 * Header aliases for the Employer CSV upload.
 */
function demand_employer_upload_columns(): array
{
    return [
        'clustered_employer_id'      => ['clustered_employer_id', 'clusteredemployerid'],
        'employer_id'                => ['employer_id', 'employerid'],
        'created_datetime'           => ['created_datetime', 'createddatetime', 'created_on'],
        'employer_name'              => ['employer_name', 'employername'],
        'website'                    => ['website'],
        'company_address'            => ['company_address', 'address'],
        'job_agency_id'              => ['job_agency_id', 'jobagencyid'],
        'jobagency'                  => ['jobagency', 'job_agency'],
        'jobfair_flag'               => ['jobfair_flag', 'jobfairflag'],
        'vk_flag'                    => ['vk_flag', 'vkflag'],
        'clusteremployername'        => ['clusteremployername', 'cluster_employer_name'],
        'nic_section_code'           => ['nic_section_code'],
        'nic_section_name'           => ['nic_section_name'],
        'nic_division_code'          => ['nic_division_code'],
        'nic_division_name'          => ['nic_division_name'],
        'nic_group_code'             => ['nic_group_code'],
        'nic_group_name'             => ['nic_group_name'],
        'nic_class_code'             => ['nic_class_code'],
        'nic_class_name'             => ['nic_class_name'],
        'nic_sub_class_code'         => ['nic_sub_class_code', 'nic_subclass_code'],
        'nic_sub_class_name'         => ['nic_sub_class_name', 'nic_subclass_name'],
        'reason_for_classification'  => ['reason_for_classification'],
        'type_of_company'            => ['type_of_company', 'typeofcompany'],
        'active_status'              => ['active_status', 'activestatus'],
        'final_status'               => ['final_status', 'finalstatus'],
        'remarks'                    => ['remarks'],
    ];
}

/**
 * Header aliases for the Employer Jobs CSV upload. Only the fields that come
 * from the source system are accepted — the manually edited fields
 * (posted_on, posted_by, expired_date, corrected_open_position, status,
 * remarks, remarks_group) live only in the app.
 */
function demand_employer_job_upload_columns(): array
{
    return [
        'job_id'                  => ['job_id', 'jobid'],
        'jobtitle'                => ['jobtitle', 'job_title'],
        'emp_id'                  => ['emp_id', 'empid', 'employer_id', 'employerid'],
        'emp_name'                => ['emp_name', 'empname', 'employer_name'],
        'open_positions'          => ['open_positions', 'openpositions', 'positions'],
        'salary_type'             => ['salary_type'],
        'salary_slab'             => ['salary_slab'],
        'qualificationcategory'   => ['qualificationcategory', 'qualification_category', 'qualification'],
        'vk_flag'                 => ['vk_flag', 'vkflag'],
        'min_experience'          => ['min_experience', 'minexperience', 'min_exp'],
        'max_experience'          => ['max_experience', 'maxexperience', 'max_exp'],
        'academic_preference'     => ['academic_preference', 'academicpreference'],
        'job_sector'              => ['job_sector', 'jobsector'],
        'location'                => ['location'],
        'domain_skills'           => ['domain_skills', 'domainskills'],
        'soft_skills'             => ['soft_skills', 'softskills'],
        'job_agency'              => ['job_agency', 'jobagency'],
        'specialization'          => ['specialization', 'specialisation'],
        'courses'                 => ['courses'],
        'location_type'           => ['location_type', 'locationtype'],
        'employment_mode'         => ['employment_mode', 'employmentmode'],
        'age_preference'          => ['age_preference', 'agepreference'],
        'gender_preference'       => ['gender_preference', 'genderpreference'],
        'job_category'            => ['job_category', 'jobcategory'],
        'job_sub_category'        => ['job_sub_category', 'jobsubcategory', 'jobsub_category'],
        'jobfair_only_job'        => ['jobfair_only_job', 'jobfaironlyjob', 'job_fair_only_job'],
        'posted_in_job_fair'      => ['posted_in_job_fair', 'postedinjobfair'],
        'number_of_applications'  => ['number_of_applications', 'numberofapplications', 'applications'],
    ];
}

/**
 * Only a small subset of upload columns are actually required for the file
 * to be accepted; the rest are optional and default to NULL when absent.
 * Employer needs employer_id (business key). Jobs need job_id + emp_id.
 */
function demand_employer_upload_required(): array
{
    return ['employer_id'];
}

function demand_employer_job_upload_required(): array
{
    return ['job_id', 'emp_id'];
}

/**
 * Attempt to parse a date value into Y-m-d, tolerating "yyyy-mm-dd",
 * "dd/mm/yyyy", "dd-mm-yyyy", "dd Mon yyyy" etc. Returns null if the input
 * is blank or unparseable.
 */
function demand_parse_date(?string $raw): ?string
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '' || str_starts_with($v, '0000-00-00')) return null;
    // Y-m-d fast path
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    // dd/mm/yyyy or dd-mm-yyyy
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    $ts = strtotime($v);
    if ($ts !== false && $ts > 0) {
        return date('Y-m-d', $ts);
    }
    return null;
}

function demand_parse_datetime(?string $raw): ?string
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '' || str_starts_with($v, '0000-00-00')) return null;
    $ts = strtotime($v);
    if ($ts !== false && $ts > 0) return date('Y-m-d H:i:s', $ts);
    // Try dd/mm/yyyy [HH:MM[:SS]]
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $v, $m)) {
        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            (int) $m[3], (int) $m[2], (int) $m[1],
            (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)
        );
    }
    return null;
}

function demand_parse_int(?string $raw): ?int
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '') return null;
    if (!preg_match('/^-?\d+$/', $v)) return null;
    return (int) $v;
}

/**
 * Parse a free-form list of employer IDs (commas, whitespace or newlines
 * separated) into a de-duplicated array of positive ints. Returns an empty
 * array when nothing valid is found.
 */
function demand_parse_employer_id_list(?string $raw): array
{
    if ($raw === null || trim($raw) === '') return [];
    $parts = preg_split('/[\s,;]+/', $raw) ?: [];
    $ids = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (!ctype_digit($part)) continue;
        $ids[(int) $part] = true;
    }
    return array_keys($ids);
}

/**
 * Employer IDs assigned to a specific user. Returns an empty array when the
 * user has no assignments (i.e. "no scope override"). Administrator users
 * should NOT be scoped through this — callers decide whether to apply.
 */
function demand_get_assigned_employer_ids(int $userId): array
{
    if ($userId <= 0) return [];
    $stmt = db()->prepare('SELECT employer_id FROM demand_user_employer_assignments WHERE user_id = ? ORDER BY employer_id ASC');
    $stmt->execute([$userId]);
    return array_map(static fn(array $r): int => (int) $r['employer_id'], $stmt->fetchAll());
}
