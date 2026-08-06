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
        UNIQUE KEY unique_job_id (job_id),
        KEY idx_emp_id (emp_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
