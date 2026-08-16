<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();

$currentUser = current_user();
$userId = (int) ($currentUser['id'] ?? 0);
// Upload and delete are Administrator-only within the demand-side module —
// State DSM can view / edit but not import or wipe data.
if (($currentUser['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'Only the Administrator role can upload or delete demand-side data.']);
    echo '<div class="alert alert-danger">Your role can view and edit demand-side data, but only the Administrator role can upload or delete records here.</div>';
    echo '<a class="btn btn-primary" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>';
    render_footer();
    exit;
}

demand_side_bootstrap();

$rawType = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
// 'posted_on' is kept as a legacy alias for the third tab; the canonical
// name is now 'bulk_fields' since the tab updates more than posted_on.
if ($rawType === 'jobs') {
    $type = 'jobs';
} elseif ($rawType === 'bulk_fields' || $rawType === 'posted_on') {
    $type = 'bulk_fields';
} elseif ($rawType === 'applicant_counts') {
    $type = 'applicant_counts';
} else {
    $type = 'employer';
}
$flashMessage = null;
$flashType = 'success';
$previewRows = [];
$previewSkips = [];

if ($type === 'employer') {
    $aliasSet = demand_employer_upload_columns();
    $requiredCols = demand_employer_upload_required();
} elseif ($type === 'jobs') {
    $aliasSet = demand_employer_job_upload_columns();
    $requiredCols = demand_employer_job_upload_required();
} elseif ($type === 'applicant_counts') {
    // Applicant funnel counts, keyed by job_id. Every count column is
    // optional; blank cells are skipped so the existing value is preserved.
    $aliasSet = [
        'job_id'      => ['job_id', 'jobid'],
        'selected'    => ['selected', 'selectedcount', 'selected_count'],
        'shortlisted' => ['shortlisted', 'shortlistedcount', 'shortlisted_count', 'shortlist'],
        'onhold'      => ['onhold', 'on_hold', 'holdcount', 'hold', 'onholdcount'],
        'rejected'    => ['rejected', 'rejectedcount', 'rejected_count', 'rejection', 'rejections'],
    ];
    $requiredCols = ['job_id'];
} else {
    // Bulk update of a handful of Job fields, keyed by job_id. Every column
    // other than job_id is optional; blank cells are skipped so the existing
    // value is preserved.
    $aliasSet = [
        'job_id'          => ['job_id', 'jobid'],
        'expiry_date'     => ['expiry_date', 'expirydate', 'expired_date', 'expireddate'],
        'job_status_data' => ['job_status_data', 'jobstatusdata', 'job_status', 'jobstatus'],
        'created_on'      => ['created_on', 'createdon', 'posted_on', 'postedon', 'created_date', 'createddate', 'date'],
        'salary'          => ['salary', 'salary_slab', 'salaryslab'],
        'posted_by'       => ['posted_by', 'postedby'],
    ];
    $requiredCols = ['job_id'];
}

function demand_map_headers(array $header, array $aliasSet): array
{
    $normalized = array_map('demand_normalise_header', $header);
    $map = [];
    foreach ($aliasSet as $canonical => $accepted) {
        $found = -1;
        foreach ($normalized as $i => $h) {
            if (in_array($h, $accepted, true)) { $found = $i; break; }
        }
        $map[$canonical] = $found;
    }
    return $map;
}

/* ------------------------------------------------------------------------- *
 * Delete handlers. Only administrator / state_dsm (require_admin at top of
 * the file) can reach these. Each destructive action is guarded on the
 * client with a JS confirm() and on the server here by an explicit
 * action= handler. Employer deletes are blocked whenever jobs exist for
 * that employer_id because of the ON DELETE RESTRICT foreign key.
 * ------------------------------------------------------------------------- */
if (is_post() && ($_POST['action'] ?? '') === 'delete_all_jobs') {
    $affected = 0;
    try {
        $stmt = db()->prepare('DELETE FROM demand_employer_jobs');
        $stmt->execute();
        $affected = $stmt->affectedRows();
        $flashMessage = "Deleted $affected Employer Jobs row(s).";
    } catch (Throwable $e) {
        $flashMessage = 'Could not delete jobs: ' . $e->getMessage();
        $flashType = 'danger';
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'delete_all_employers') {
    try {
        $stmt = db()->prepare('DELETE FROM demand_employers');
        $stmt->execute();
        $flashMessage = 'Deleted ' . $stmt->affectedRows() . ' Employer row(s).';
    } catch (Throwable $e) {
        $flashMessage = 'Could not delete employers (jobs may still reference them — delete jobs first): ' . $e->getMessage();
        $flashType = 'danger';
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'delete_employer_by_id') {
    $eid = demand_parse_int((string) ($_POST['employer_id'] ?? ''));
    if ($eid === null || $eid <= 0) {
        $flashMessage = 'Enter a valid numeric employer_id.';
        $flashType = 'danger';
    } else {
        $chk = db()->prepare('SELECT COUNT(*) FROM demand_employer_jobs WHERE emp_id = ?');
        $chk->execute([$eid]);
        $jobsExist = (int) $chk->fetchColumn();
        if ($jobsExist > 0) {
            $flashMessage = "Employer $eid still has $jobsExist job(s). Delete those first or use the per-row delete after cascading them.";
            $flashType = 'warning';
        } else {
            $del = db()->prepare('DELETE FROM demand_employers WHERE employer_id = ?');
            $del->execute([$eid]);
            $affected = $del->affectedRows();
            if ($affected > 0) {
                $flashMessage = "Deleted employer $eid.";
            } else {
                $flashMessage = "No employer with employer_id $eid.";
                $flashType = 'warning';
            }
        }
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'delete_job_by_id') {
    $jid = demand_parse_int((string) ($_POST['job_id'] ?? ''));
    if ($jid === null || $jid <= 0) {
        $flashMessage = 'Enter a valid numeric job_id.';
        $flashType = 'danger';
    } else {
        $del = db()->prepare('DELETE FROM demand_employer_jobs WHERE job_id = ?');
        $del->execute([$jid]);
        $affected = $del->affectedRows();
        if ($affected > 0) {
            $flashMessage = "Deleted job $jid.";
        } else {
            $flashMessage = "No job with job_id $jid.";
            $flashType = 'warning';
        }
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'delete_jobs_by_employer') {
    $eid = demand_parse_int((string) ($_POST['employer_id'] ?? ''));
    if ($eid === null || $eid <= 0) {
        $flashMessage = 'Enter a valid numeric employer_id.';
        $flashType = 'danger';
    } else {
        $del = db()->prepare('DELETE FROM demand_employer_jobs WHERE emp_id = ?');
        $del->execute([$eid]);
        $flashMessage = 'Deleted ' . $del->affectedRows() . " job(s) for employer $eid.";
    }
}

// Current counts for the delete panel summary.
$employerCount = (int) db()->query('SELECT COUNT(*) FROM demand_employers')->fetchColumn();
$jobCount = (int) db()->query('SELECT COUNT(*) FROM demand_employer_jobs')->fetchColumn();

/* ------------------------------------------------------------------------- *
 * Step 2: Commit the import from a previously staged file (session-based).
 * ------------------------------------------------------------------------- */
if (is_post() && ($_POST['action'] ?? '') === 'commit') {
    session_status() === PHP_SESSION_ACTIVE || session_start();
    $stagedPath = (string) ($_SESSION['demand_upload_path'] ?? '');
    $stagedType = (string) ($_SESSION['demand_upload_type'] ?? '');
    if (!$stagedPath || !is_readable($stagedPath) || $stagedType !== $type) {
        $flashMessage = 'Upload session expired. Please choose the CSV again.';
        $flashType = 'danger';
    } else {
        $fh = fopen($stagedPath, 'r');
        $header = $fh ? fgetcsv($fh) : null;
        if (!$fh || !$header) {
            $flashMessage = 'Could not read the staged CSV.';
            $flashType = 'danger';
        } else {
            $colIndex = demand_map_headers($header, $aliasSet);
            $processed = 0; $inserted = 0; $updated = 0; $skipped = 0; $skipReasons = [];

            if ($type === 'employer') {
                $upsert = db()->prepare("INSERT INTO demand_employers (
                        clustered_employer_id, employer_id, created_datetime, employer_name, website, company_address,
                        job_agency_id, jobagency, jobfair_flag, vk_flag, clusteremployername,
                        nic_section_code, nic_section_name, nic_division_code, nic_division_name,
                        nic_group_code, nic_group_name, nic_class_code, nic_class_name,
                        nic_sub_class_code, nic_sub_class_name, reason_for_classification, type_of_company,
                        final_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        clustered_employer_id = VALUES(clustered_employer_id),
                        created_datetime = VALUES(created_datetime),
                        employer_name = VALUES(employer_name),
                        website = VALUES(website),
                        company_address = VALUES(company_address),
                        job_agency_id = VALUES(job_agency_id),
                        jobagency = VALUES(jobagency),
                        jobfair_flag = VALUES(jobfair_flag),
                        vk_flag = VALUES(vk_flag),
                        clusteremployername = VALUES(clusteremployername),
                        nic_section_code = VALUES(nic_section_code),
                        nic_section_name = VALUES(nic_section_name),
                        nic_division_code = VALUES(nic_division_code),
                        nic_division_name = VALUES(nic_division_name),
                        nic_group_code = VALUES(nic_group_code),
                        nic_group_name = VALUES(nic_group_name),
                        nic_class_code = VALUES(nic_class_code),
                        nic_class_name = VALUES(nic_class_name),
                        nic_sub_class_code = VALUES(nic_sub_class_code),
                        nic_sub_class_name = VALUES(nic_sub_class_name),
                        reason_for_classification = VALUES(reason_for_classification),
                        type_of_company = VALUES(type_of_company),
                        final_status = VALUES(final_status)");
                while (($row = fgetcsv($fh)) !== false) {
                    $processed++;
                    $get = static fn(string $c): string => $colIndex[$c] < 0 ? '' : trim((string) ($row[$colIndex[$c]] ?? ''));
                    $eid = demand_parse_int($get('employer_id'));
                    if ($eid === null || $eid <= 0) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: missing/invalid employer_id";
                        continue;
                    }
                    $upsert->execute([
                        demand_parse_int($get('clustered_employer_id')),
                        $eid,
                        demand_parse_datetime($get('created_datetime')),
                        $get('employer_name') ?: null,
                        $get('website') ?: null,
                        $get('company_address') ?: null,
                        $get('job_agency_id') ?: null,
                        $get('jobagency') ?: null,
                        $get('jobfair_flag') ?: null,
                        $get('vk_flag') ?: null,
                        $get('clusteremployername') ?: null,
                        $get('nic_section_code') ?: null,
                        $get('nic_section_name') ?: null,
                        $get('nic_division_code') ?: null,
                        $get('nic_division_name') ?: null,
                        $get('nic_group_code') ?: null,
                        $get('nic_group_name') ?: null,
                        $get('nic_class_code') ?: null,
                        $get('nic_class_name') ?: null,
                        $get('nic_sub_class_code') ?: null,
                        $get('nic_sub_class_name') ?: null,
                        $get('reason_for_classification') ?: null,
                        $get('type_of_company') ?: null,
                        $get('final_status') ?: null,
                    ]);
                    // affectedRows: 1 = insert, 2 = update, 0 = no change
                    $affected = $upsert->affectedRows();
                    if ($affected === 1) $inserted++;
                    elseif ($affected >= 2) $updated++;
                }
            } elseif ($type === 'jobs') {
                // Employer Jobs. emp_id is a foreign key to
                // demand_employers.employer_id — pre-load the valid set once
                // so we can reject orphan rows with a clear message even when
                // the DB-level FK isn't in place yet.
                $validEmpIds = [];
                foreach (db()->query('SELECT employer_id FROM demand_employers')->fetchAll() as $er) {
                    $validEmpIds[(int) $er['employer_id']] = true;
                }
                $upsert = db()->prepare("INSERT INTO demand_employer_jobs (
                        job_id, jobtitle, emp_id, emp_name, open_positions,
                        salary_type, salary_slab, qualificationcategory, vk_flag,
                        min_experience, max_experience, academic_preference, job_sector, location,
                        domain_skills, soft_skills, job_agency, specialization, courses,
                        location_type, employment_mode, age_preference, gender_preference,
                        job_category, job_sub_category, jobfair_only_job, posted_in_job_fair,
                        number_of_applications
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?,
                              ?, ?, ?, ?, ?,
                              ?, ?, ?, ?, ?,
                              ?, ?, ?, ?,
                              ?, ?, ?, ?,
                              ?)
                    ON DUPLICATE KEY UPDATE
                        jobtitle = VALUES(jobtitle),
                        emp_id = VALUES(emp_id),
                        emp_name = VALUES(emp_name),
                        open_positions = VALUES(open_positions),
                        salary_type = VALUES(salary_type),
                        salary_slab = VALUES(salary_slab),
                        qualificationcategory = VALUES(qualificationcategory),
                        vk_flag = VALUES(vk_flag),
                        min_experience = VALUES(min_experience),
                        max_experience = VALUES(max_experience),
                        academic_preference = VALUES(academic_preference),
                        job_sector = VALUES(job_sector),
                        location = VALUES(location),
                        domain_skills = VALUES(domain_skills),
                        soft_skills = VALUES(soft_skills),
                        job_agency = VALUES(job_agency),
                        specialization = VALUES(specialization),
                        courses = VALUES(courses),
                        location_type = VALUES(location_type),
                        employment_mode = VALUES(employment_mode),
                        age_preference = VALUES(age_preference),
                        gender_preference = VALUES(gender_preference),
                        job_category = VALUES(job_category),
                        job_sub_category = VALUES(job_sub_category),
                        jobfair_only_job = VALUES(jobfair_only_job),
                        posted_in_job_fair = VALUES(posted_in_job_fair),
                        number_of_applications = VALUES(number_of_applications)");
                while (($row = fgetcsv($fh)) !== false) {
                    $processed++;
                    $get = static fn(string $c): string => $colIndex[$c] < 0 ? '' : trim((string) ($row[$colIndex[$c]] ?? ''));
                    $jid = demand_parse_int($get('job_id'));
                    $empId = demand_parse_int($get('emp_id'));
                    if ($jid === null || $jid <= 0 || $empId === null || $empId <= 0) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: missing/invalid job_id or emp_id";
                        continue;
                    }
                    if (!isset($validEmpIds[$empId])) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: emp_id $empId not found in Employers (upload Employer first)";
                        continue;
                    }
                    // Small helper: numeric string preserving decimals (returns null for empty).
                    $numOrNull = static function (string $v): ?string {
                        $v = trim($v);
                        if ($v === '') return null;
                        if (!preg_match('/^-?\d+(\.\d+)?$/', $v)) return null;
                        return $v;
                    };
                    $upsert->execute([
                        $jid,
                        $get('jobtitle') ?: null,
                        $empId,
                        $get('emp_name') ?: null,
                        demand_parse_int($get('open_positions')),
                        $get('salary_type') ?: null,
                        $get('salary_slab') ?: null,
                        $get('qualificationcategory') ?: null,
                        $get('vk_flag') ?: null,
                        $numOrNull($get('min_experience')),
                        $numOrNull($get('max_experience')),
                        $get('academic_preference') ?: null,
                        $get('job_sector') ?: null,
                        $get('location') ?: null,
                        $get('domain_skills') ?: null,
                        $get('soft_skills') ?: null,
                        $get('job_agency') ?: null,
                        $get('specialization') ?: null,
                        $get('courses') ?: null,
                        $get('location_type') ?: null,
                        $get('employment_mode') ?: null,
                        $get('age_preference') ?: null,
                        $get('gender_preference') ?: null,
                        $get('job_category') ?: null,
                        $get('job_sub_category') ?: null,
                        $get('jobfair_only_job') ?: null,
                        $get('posted_in_job_fair') ?: null,
                        demand_parse_int($get('number_of_applications')),
                    ]);
                    $affected = $upsert->affectedRows();
                    if ($affected === 1) $inserted++;
                    elseif ($affected >= 2) $updated++;
                }
            } elseif ($type === 'applicant_counts') {
                // Bulk update the four applicant-funnel count columns
                // (selected / shortlisted / onhold / rejected) keyed by
                // job_id. Blank CSV cells are skipped so the existing value
                // is preserved. Non-numeric cells produce a per-row skip
                // with a reason. Real changes are written to the job edit
                // log and the row's task_owner_id is set to the uploader.
                $countFields = ['selected', 'shortlisted', 'onhold', 'rejected'];
                $lookup = db()->prepare("SELECT id, selected, shortlisted, onhold, rejected FROM demand_employer_jobs WHERE job_id = ?");
                @set_time_limit(0);
                ignore_user_abort(true);
                while (($row = fgetcsv($fh)) !== false) {
                    $processed++;
                    $get = static fn(string $c): string => $colIndex[$c] < 0 ? '' : trim((string) ($row[$colIndex[$c]] ?? ''));
                    $jid = demand_parse_int($get('job_id'));
                    if ($jid === null || $jid <= 0) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: missing/invalid job_id";
                        continue;
                    }
                    $lookup->execute([$jid]);
                    $existing = $lookup->fetch();
                    if (!$existing) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: job_id $jid not found";
                        continue;
                    }
                    $rowJobId    = (int) $existing['id'];
                    $updateSets  = [];
                    $updateParms = [];
                    $logEntries  = [];
                    $rowError    = null;
                    foreach ($countFields as $cf) {
                        $raw = $get($cf);
                        if ($raw === '') continue;
                        // Accept plain non-negative integers only; comma
                        // thousand-separators tolerated.
                        $stripped = str_replace(',', '', $raw);
                        if (!ctype_digit($stripped)) {
                            $rowError = "$cf value '$raw' is not a non-negative integer";
                            break;
                        }
                        $newVal = (int) $stripped;
                        $oldVal = $existing[$cf] === null ? null : (int) $existing[$cf];
                        if ($oldVal === $newVal) continue;
                        $updateSets[]  = "$cf = ?";
                        $updateParms[] = $newVal;
                        $logEntries[]  = [$cf, $oldVal === null ? '' : (string) $oldVal, (string) $newVal];
                    }
                    if ($rowError !== null) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: $rowError";
                        continue;
                    }
                    if ($updateSets === []) {
                        continue; // nothing changed
                    }
                    $updateSets[]  = 'task_owner_id = ?'; $updateParms[] = $userId;
                    $updateSets[]  = 'updated_by = ?';    $updateParms[] = $userId;
                    $updateSets[]  = 'updated_at = NOW()';
                    $updateParms[] = $jid;
                    $upd = db()->prepare('UPDATE demand_employer_jobs SET ' . implode(', ', $updateSets) . ' WHERE job_id = ?');
                    $upd->execute($updateParms);
                    foreach ($logEntries as [$field, $oldStr, $newStr]) {
                        demand_write_edit_log('job', $rowJobId, $field, $oldStr, $newStr, $userId);
                    }
                    $updated++;
                }
            } elseif ($type === 'bulk_fields') {
                // Bulk update of a small set of Job fields keyed by job_id:
                //   Expiry Date       -> expired_date
                //   Job Status data   -> job_status_data
                //   Created On        -> posted_on
                //   Salary            -> salary_slab
                //   Posted by         -> posted_by
                // Blank CSV cells are skipped (existing value preserved). Each
                // real change is written to the job edit log and the row's
                // task_owner_id is set to the uploading user.
                $lookup = db()->prepare('SELECT id, expired_date, job_status_data, posted_on, salary_slab, posted_by
                    FROM demand_employer_jobs WHERE job_id = ?');
                while (($row = fgetcsv($fh)) !== false) {
                    $processed++;
                    $get = static fn(string $c): string => $colIndex[$c] < 0 ? '' : trim((string) ($row[$colIndex[$c]] ?? ''));
                    $jid = demand_parse_int($get('job_id'));
                    if ($jid === null || $jid <= 0) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: missing/invalid job_id";
                        continue;
                    }
                    $lookup->execute([$jid]);
                    $existing = $lookup->fetch();
                    if (!$existing) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: job_id $jid not found";
                        continue;
                    }

                    // Resolve each incoming field. If the CSV cell is blank
                    // the field is left alone. Dates that can't be parsed
                    // count as a row-level skip so the operator knows.
                    $rowJobId = (int) $existing['id'];
                    $updateFields = [];
                    $updateParams = [];
                    $logEntries = [];
                    $rowError = null;

                    // Expiry Date
                    $rawExp = $get('expiry_date');
                    if ($rawExp !== '') {
                        $newExp = demand_parse_date($rawExp);
                        if ($newExp === null) {
                            $rowError = "expiry_date '$rawExp' could not be parsed";
                        } else {
                            $oldExp = substr((string) ($existing['expired_date'] ?? ''), 0, 10);
                            if ($oldExp !== $newExp) {
                                $updateFields[] = 'expired_date = ?';
                                $updateParams[] = $newExp;
                                $logEntries[] = ['expired_date', $oldExp, $newExp];
                            }
                        }
                    }
                    // Created On -> posted_on
                    if ($rowError === null) {
                        $rawPost = $get('created_on');
                        if ($rawPost !== '') {
                            $newPost = demand_parse_date($rawPost);
                            if ($newPost === null) {
                                $rowError = "created_on '$rawPost' could not be parsed";
                            } else {
                                $oldPost = substr((string) ($existing['posted_on'] ?? ''), 0, 10);
                                if ($oldPost !== $newPost) {
                                    $updateFields[] = 'posted_on = ?';
                                    $updateParams[] = $newPost;
                                    $logEntries[] = ['posted_on', $oldPost, $newPost];
                                }
                            }
                        }
                    }
                    // Job Status data (label = Job Status)
                    if ($rowError === null) {
                        $newStatusData = $get('job_status_data');
                        if ($newStatusData !== '') {
                            $oldStatusData = (string) ($existing['job_status_data'] ?? '');
                            if ($oldStatusData !== $newStatusData) {
                                $updateFields[] = 'job_status_data = ?';
                                $updateParams[] = $newStatusData;
                                $logEntries[] = ['job_status_data', $oldStatusData, $newStatusData];
                            }
                        }
                    }
                    // Salary -> salary_slab
                    if ($rowError === null) {
                        $newSalary = $get('salary');
                        if ($newSalary !== '') {
                            $oldSalary = (string) ($existing['salary_slab'] ?? '');
                            if ($oldSalary !== $newSalary) {
                                $updateFields[] = 'salary_slab = ?';
                                $updateParams[] = $newSalary;
                                $logEntries[] = ['salary_slab', $oldSalary, $newSalary];
                            }
                        }
                    }
                    // Posted by
                    if ($rowError === null) {
                        $newPostedBy = $get('posted_by');
                        if ($newPostedBy !== '') {
                            $oldPostedBy = (string) ($existing['posted_by'] ?? '');
                            if ($oldPostedBy !== $newPostedBy) {
                                $updateFields[] = 'posted_by = ?';
                                $updateParams[] = $newPostedBy;
                                $logEntries[] = ['posted_by', $oldPostedBy, $newPostedBy];
                            }
                        }
                    }

                    if ($rowError !== null) {
                        $skipped++;
                        if (count($skipReasons) < 5) $skipReasons[] = "Row $processed: $rowError";
                        continue;
                    }
                    if ($updateFields === []) {
                        // Nothing to change for this row — counts as processed but not updated.
                        continue;
                    }
                    $updateFields[] = 'task_owner_id = ?';
                    $updateParams[] = $userId;
                    $updateFields[] = 'updated_by = ?';
                    $updateParams[] = $userId;
                    $updateFields[] = 'updated_at = NOW()';
                    $updateParams[] = $jid;
                    $upd = db()->prepare('UPDATE demand_employer_jobs SET ' . implode(', ', $updateFields) . ' WHERE job_id = ?');
                    $upd->execute($updateParams);
                    foreach ($logEntries as [$field, $oldVal, $newVal]) {
                        demand_write_edit_log('job', $rowJobId, $field, $oldVal, $newVal, $userId);
                    }
                    $updated++;
                }
            }
            fclose($fh);
            @unlink($stagedPath);
            unset($_SESSION['demand_upload_path'], $_SESSION['demand_upload_type']);
            $flashMessage = sprintf(
                'Import complete: %d row(s) processed, %d inserted, %d updated, %d skipped.%s',
                $processed, $inserted, $updated, $skipped,
                $skipReasons === [] ? '' : ' First skips: ' . implode('; ', $skipReasons)
            );
            $flashType = ($skipped > 0 && ($inserted + $updated) === 0) ? 'warning' : 'success';
        }
    }
}

/* ------------------------------------------------------------------------- *
 * Step 1: Upload file, parse header, show preview & mapping.
 * ------------------------------------------------------------------------- */
$previewMap = [];
$stagedForCommit = false;
if (is_post() && ($_POST['action'] ?? '') === 'preview') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $flashMessage = 'No CSV file uploaded or upload failed.';
        $flashType = 'danger';
    } else {
        session_status() === PHP_SESSION_ACTIVE || session_start();
        $stagedDir = sys_get_temp_dir();
        $stagedPath = $stagedDir . '/demand_upload_' . bin2hex(random_bytes(8)) . '.csv';
        if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $stagedPath)) {
            $flashMessage = 'Could not stage the uploaded CSV.';
            $flashType = 'danger';
        } else {
            $_SESSION['demand_upload_path'] = $stagedPath;
            $_SESSION['demand_upload_type'] = $type;
            $fh = fopen($stagedPath, 'r');
            $header = $fh ? fgetcsv($fh) : null;
            if (!$header) {
                $flashMessage = 'CSV appears to be empty.';
                $flashType = 'danger';
            } else {
                $previewMap = demand_map_headers($header, $aliasSet);
                $missing = array_values(array_filter(
                    $requiredCols,
                    static fn(string $col): bool => ($previewMap[$col] ?? -1) < 0
                ));
                if ($missing !== []) {
                    $flashMessage = 'Required column(s) missing in CSV: ' . implode(', ', $missing);
                    $flashType = 'danger';
                    unset($_SESSION['demand_upload_path'], $_SESSION['demand_upload_type']);
                    @unlink($stagedPath);
                } else {
                    // Read up to 5 rows for preview.
                    $count = 0;
                    while ($count < 5 && ($row = fgetcsv($fh)) !== false) {
                        $previewRows[] = $row;
                        $count++;
                    }
                    // Count total lines (approx)
                    $totalRows = $count;
                    while (fgetcsv($fh) !== false) $totalRows++;
                    $stagedForCommit = true;
                    $flashMessage = 'Preview ready. ' . $totalRows . ' data row(s) detected. Review the first ' . count($previewRows) . ' below and click Confirm to import.';
                }
            }
            if ($fh) fclose($fh);
        }
    }
}

render_header('Demand Side · Upload Data', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Upload Data', [
    'icon' => 'bi-upload',
    'subtitle' => 'Two-step CSV wizard: preview headers and rows, then confirm to import. Existing rows are updated by their business key (Employer ID / Job ID); manually edited fields are preserved. A third tab bulk-updates Posted On from a job_id + created_date CSV.',
    'actions' => '<a class="btn btn-light" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);
?>

<?php if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $type === 'employer' ? 'active' : '' ?>" href="/demand_side_upload.php?type=employer"><i class="bi bi-building me-1"></i>Upload Employer</a></li>
    <li class="nav-item"><a class="nav-link <?= $type === 'jobs' ? 'active' : '' ?>" href="/demand_side_upload.php?type=jobs"><i class="bi bi-briefcase me-1"></i>Upload Employer Jobs</a></li>
    <li class="nav-item"><a class="nav-link <?= $type === 'bulk_fields' ? 'active' : '' ?>" href="/demand_side_upload.php?type=bulk_fields"><i class="bi bi-pencil-square me-1"></i>Bulk update Job fields</a></li>
    <li class="nav-item"><a class="nav-link <?= $type === 'applicant_counts' ? 'active' : '' ?>" href="/demand_side_upload.php?type=applicant_counts"><i class="bi bi-people-fill me-1"></i>Bulk update Applicant Counts</a></li>
</ul>

<div class="card mb-3">
    <div class="card-body">
        <h2 class="h5 mb-3">
            <i class="bi bi-1-circle text-primary me-1"></i>Step 1 · Upload &amp; Preview
        </h2>
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="type" value="<?= esc($type) ?>">
            <div class="col-md-6">
                <?php $csvLabel = ['employer' => 'Employer', 'jobs' => 'Employer Jobs', 'bulk_fields' => 'Bulk Job field update', 'applicant_counts' => 'Applicant Counts']; ?>
                <label class="form-label">CSV file (<?= esc($csvLabel[$type]) ?>)</label>
                <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary"><i class="bi bi-eye me-1"></i>Preview</button>
            </div>
        </form>
        <?php $optionalCols = array_values(array_diff(array_keys($aliasSet), $requiredCols)); ?>
        <div class="mt-3 small text-muted">
            Accepted columns (case / underscore insensitive) — <strong>required</strong> ones are highlighted, others are optional:
            <div class="d-flex flex-wrap gap-1 mt-1">
                <?php foreach ($requiredCols as $col): ?>
                    <span class="status-chip status-danger"><?= esc($col) ?> *</span>
                <?php endforeach; ?>
                <?php foreach ($optionalCols as $col): ?>
                    <span class="status-chip status-neutral"><?= esc($col) ?></span>
                <?php endforeach; ?>
            </div>
            <?php if ($type === 'jobs'): ?>
                <div class="mt-2"><em>Note:</em> <code>emp_id</code> is a foreign key to <code>Employers.employer_id</code>&nbsp;— upload Employer first, then Employer Jobs. Rows whose <code>emp_id</code> isn't found in Employers are skipped and reported.</div>
                <div class="mt-1"><em>Also:</em> <code>status</code>, <code>remarks</code>, <code>remarks_group</code>, <code>posted_on</code>, <code>posted_by</code>, <code>expired_date</code> and <code>corrected_open_position</code> are managed inside the app on the Edit screen, so they are not part of the upload.</div>
            <?php elseif ($type === 'applicant_counts'): ?>
                <div class="mt-2"><em>What it does:</em> for every CSV row (keyed by <code>job_id</code>), the four applicant-funnel count columns are refreshed on <code>demand_employer_jobs</code>:
                    <ul class="mb-1 mt-1">
                        <li><code>selected</code></li>
                        <li><code>shortlisted</code></li>
                        <li><code>onhold</code> (also accepts <code>on_hold</code>, <code>hold</code>)</li>
                        <li><code>rejected</code></li>
                    </ul>
                    Blank cells are skipped (existing value preserved). Non-numeric cells fail the row with a reason. Values must be non-negative integers (commas as thousand-separators are tolerated). Missing jobs are skipped and reported. Each real change is logged in the Job Edit History and the row's Task Owner is set to you. The four counts show up on the Jobs table inside the Employer view.
                </div>
            <?php elseif ($type === 'bulk_fields'): ?>
                <div class="mt-2"><em>What it does:</em> for every CSV row (keyed by <code>job_id</code>), matching Job Fields are refreshed:
                    <ul class="mb-1 mt-1">
                        <li><code>Expiry Date</code> &rarr; <code>expired_date</code></li>
                        <li><code>Job Status data</code> &rarr; <code>job_status_data</code> (labelled <strong>Job Status</strong> in the view; never editable by users)</li>
                        <li><code>Created On</code> &rarr; <code>posted_on</code></li>
                        <li><code>Salary</code> &rarr; <code>salary_slab</code></li>
                        <li><code>Posted by</code> &rarr; <code>posted_by</code></li>
                    </ul>
                    Blank CSV cells are skipped, so existing values are preserved. Missing jobs are skipped and reported. Each real change is logged in the Job Edit History and the row's Task Owner is set to you.
                </div>
                <div class="mt-1"><em>Accepted date formats:</em> <code>YYYY-MM-DD</code>, <code>DD/MM/YYYY</code>, <code>DD-MM-YYYY</code> or any string PHP's strtotime can parse.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($stagedForCommit && $previewRows !== []): ?>
    <?php
        // Fetch header for preview render
        $fh2 = fopen($_SESSION['demand_upload_path'], 'r');
        $headerCells = $fh2 ? fgetcsv($fh2) : [];
        if ($fh2) fclose($fh2);
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5 mb-3">
                <i class="bi bi-2-circle text-primary me-1"></i>Step 2 · Preview &amp; Confirm
            </h2>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <?php foreach ($headerCells as $h): ?>
                                <th class="small"><?= esc((string) $h) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $r): ?>
                            <tr>
                                <?php foreach ($headerCells as $i => $_): ?>
                                    <td class="small"><?= esc((string) ($r[$i] ?? '')) ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="commit">
                <input type="hidden" name="type" value="<?= esc($type) ?>">
                <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Confirm import</button>
                <a href="/demand_side_upload.php?type=<?= esc($type) ?>" class="btn btn-light">Discard</a>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card border-danger mb-4">
    <div class="card-header bg-danger-subtle text-danger-emphasis d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span class="fw-semibold">Danger zone · Delete data</span>
        <span class="status-chip status-danger ms-auto"><?= number_format($employerCount) ?> employers · <?= number_format($jobCount) ?> jobs</span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Destructive actions. Because <code>demand_employer_jobs.emp_id</code> is a foreign key to
            <code>demand_employers.employer_id</code> with <em>ON&nbsp;DELETE&nbsp;RESTRICT</em>, an Employer cannot be deleted while its Jobs still exist &mdash; delete the Jobs first, or use the <em>Delete Jobs for one employer</em> helper below.
        </p>

        <div class="row g-3">
            <div class="col-lg-6">
                <div class="border rounded p-3 h-100">
                    <h3 class="h6 mb-3"><i class="bi bi-trash me-1"></i>Delete one</h3>
                    <form method="post" class="row g-2 align-items-end mb-3" onsubmit="return confirm('Delete employer with employer_id=' + this.employer_id.value + '? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_employer_by_id">
                        <div class="col">
                            <label class="form-label small">Employer employer_id</label>
                            <input class="form-control form-control-sm" type="number" min="1" name="employer_id" required>
                        </div>
                        <div class="col-auto"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete employer</button></div>
                    </form>
                    <form method="post" class="row g-2 align-items-end mb-3" onsubmit="return confirm('Delete ALL jobs whose emp_id = ' + this.employer_id.value + '? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_jobs_by_employer">
                        <div class="col">
                            <label class="form-label small">All jobs for emp_id</label>
                            <input class="form-control form-control-sm" type="number" min="1" name="employer_id" required>
                        </div>
                        <div class="col-auto"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete jobs</button></div>
                    </form>
                    <form method="post" class="row g-2 align-items-end" onsubmit="return confirm('Delete job with job_id=' + this.job_id.value + '?');">
                        <input type="hidden" name="action" value="delete_job_by_id">
                        <div class="col">
                            <label class="form-label small">Job job_id</label>
                            <input class="form-control form-control-sm" type="number" min="1" name="job_id" required>
                        </div>
                        <div class="col-auto"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Delete job</button></div>
                    </form>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="border rounded p-3 h-100 bg-danger-subtle">
                    <h3 class="h6 mb-3 text-danger-emphasis"><i class="bi bi-fire me-1"></i>Wipe everything</h3>
                    <p class="small text-muted">Use these before importing a fresh dataset. Jobs first, then Employers.</p>
                    <form method="post" class="mb-2" onsubmit="return confirm('Delete ALL <?= (int) $jobCount ?> Employer Jobs? This cannot be undone.');">
                        <input type="hidden" name="action" value="delete_all_jobs">
                        <button class="btn btn-danger btn-sm w-100" <?= $jobCount === 0 ? 'disabled' : '' ?>><i class="bi bi-trash-fill me-1"></i>Delete ALL Employer Jobs (<?= number_format($jobCount) ?>)</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Delete ALL <?= (int) $employerCount ?> Employers? Any jobs still present will block this.');">
                        <input type="hidden" name="action" value="delete_all_employers">
                        <button class="btn btn-danger btn-sm w-100" <?= $employerCount === 0 ? 'disabled' : '' ?>><i class="bi bi-trash-fill me-1"></i>Delete ALL Employers (<?= number_format($employerCount) ?>)</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php render_footer(); ?>
