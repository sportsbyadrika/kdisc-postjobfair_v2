<?php
/**
 * LOI brochure generator — schema + helpers.
 *
 * Data model:
 *   loi_brochure_template   named reusable layout
 *   loi_brochure_page       one row per page of a template; config_json
 *                           carries the free-form label definitions
 *                           {id, text, x, y, width?, font_size, color,
 *                            weight, align}
 *                           background_image_path points at the A4
 *                           image uploaded under uploads/loi_brochure/
 *   loi_brochure            a user-created brochure instance
 *                           (title, template_id, created_by)
 *   loi_brochure_job        M:N bridge, picks which jobs go in it
 *
 * Any LOI user sees all brochures (per the user's spec); templates are
 * admin-managed. PDF rendering uses Dompdf when available (fallback is
 * a browser-printable HTML page with the same layout).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/loi_jobs_helpers.php';

const LOI_BROCHURE_UPLOAD_DIR = __DIR__ . '/../uploads/loi_brochure';
// A4 at 96 DPI (standard CSS px) — editor canvas size.
const LOI_BROCHURE_A4_WIDTH_PX  = 794;
const LOI_BROCHURE_A4_HEIGHT_PX = 1123;

function loi_brochure_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();

    try {
        $db->query("CREATE TABLE IF NOT EXISTS loi_brochure_template (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            created_by INT NULL, updated_by INT NULL,
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_brochure_page (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            page_number INT NOT NULL DEFAULT 1,
            name VARCHAR(120) NULL,
            background_image_path VARCHAR(400) NULL,
            config_json MEDIUMTEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY unique_pair (template_id, page_number),
            KEY idx_template (template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_brochure (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(300) NOT NULL,
            template_id INT NULL,
            notes TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            created_by INT NULL, updated_by INT NULL,
            KEY idx_template (template_id),
            KEY idx_active (is_active),
            KEY idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS loi_brochure_job (
            id INT AUTO_INCREMENT PRIMARY KEY,
            brochure_id INT NOT NULL,
            job_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY unique_pair (brochure_id, job_id),
            KEY idx_brochure (brochure_id),
            KEY idx_job (job_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Ensure upload directory exists; the writer will create per-
        // template subfolders at upload time.
        if (!is_dir(LOI_BROCHURE_UPLOAD_DIR)) {
            @mkdir(LOI_BROCHURE_UPLOAD_DIR, 0775, true);
        }
    } catch (Throwable $e) { /* CREATE refused — module reads/writes degrade silently */ }
}

/**
 * Decode a page's config_json into a labels array. Defaults the list
 * to empty so templates with no labels yet still render.
 */
function loi_brochure_page_labels(array $page): array
{
    $raw = (string) ($page['config_json'] ?? '');
    if ($raw === '') return [];
    try {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['labels']) && is_array($decoded['labels'])) return $decoded['labels'];
    } catch (Throwable $e) { /* ignore */ }
    return [];
}

/**
 * Aggregate stats across a job id list. Returns the full set of counts
 * that the placeholder resolver + summary helpers consume.
 */
function loi_brochure_stats(array $jobIds): array
{
    $out = [
        'employers_count'   => 0,
        'jobs_count'        => count($jobIds),
        'openings_count'    => 0,
        'applications_count'=> 0,
        'by_salary'         => [], // name => count
        'by_location'       => [], // name => count
        'by_qualification'  => [], // name => count
        'by_gender'         => [], // label => count
        'employer_names'    => [],
    ];
    if ($jobIds === []) return $out;
    $ph = implode(',', array_fill(0, count($jobIds), '?'));
    try {
        // Core job rows + joined master names.
        $st = db()->prepare("SELECT j.id, j.vacancy_count, j.applications_count, j.gender,
                e.id AS employer_id, e.name AS employer_name,
                qc.name AS qualification_category_name,
                sr.name AS salary_range_name
            FROM loi_job j
            INNER JOIN loi_employer e              ON e.id = j.employer_id
            LEFT  JOIN loi_qualification_category qc ON qc.id = j.qualification_category_id
            LEFT  JOIN loi_salary_range sr         ON sr.id = j.salary_range_id
            WHERE j.id IN ($ph)");
        $st->execute($jobIds);
        $empSet = [];
        foreach ($st->fetchAll() as $row) {
            $out['openings_count']     += (int) ($row['vacancy_count']      ?? 0);
            $out['applications_count'] += (int) ($row['applications_count'] ?? 0);
            if (!empty($row['employer_id']) && !isset($empSet[(int) $row['employer_id']])) {
                $empSet[(int) $row['employer_id']] = (string) ($row['employer_name'] ?? '');
            }
            $sal = trim((string) ($row['salary_range_name'] ?? ''));     if ($sal === '') $sal = 'Not specified';
            $qc  = trim((string) ($row['qualification_category_name'] ?? '')); if ($qc  === '') $qc  = 'Not specified';
            $gl  = loi_gender_label($row['gender'] ?? null);             if ($gl  === '—')   $gl  = 'Any';
            $out['by_salary'][$sal]        = ($out['by_salary'][$sal]        ?? 0) + 1;
            $out['by_qualification'][$qc]  = ($out['by_qualification'][$qc]  ?? 0) + 1;
            $out['by_gender'][$gl]         = ($out['by_gender'][$gl]         ?? 0) + 1;
        }
        $out['employers_count'] = count($empSet);
        $out['employer_names']  = array_values($empSet);
        // Locations are via the N:M bridge — one job can have many.
        $lst = db()->prepare("SELECT l.name
            FROM loi_job_location jl
            INNER JOIN loi_location l ON l.id = jl.location_id
            WHERE jl.job_id IN ($ph)");
        $lst->execute($jobIds);
        foreach ($lst->fetchAll() as $row) {
            $nm = trim((string) ($row['name'] ?? ''));
            if ($nm === '') continue;
            $out['by_location'][$nm] = ($out['by_location'][$nm] ?? 0) + 1;
        }
    } catch (Throwable $e) { /* ignore */ }
    return $out;
}

/**
 * Fetch jobs ordered by brochure sort_order, each decorated with the
 * employer name + joined master names + locations. Powers the
 * "jobs_list" / "jobs_by_qualification" placeholders.
 */
function loi_brochure_jobs(array $jobIds): array
{
    if ($jobIds === []) return [];
    $ph = implode(',', array_fill(0, count($jobIds), '?'));
    $rows = [];
    try {
        $st = db()->prepare("SELECT j.*, e.name AS employer_name, e.district AS employer_district,
                e.state AS employer_state,
                qc.name AS qualification_category_name,
                sr.name AS salary_range_name
            FROM loi_job j
            INNER JOIN loi_employer e              ON e.id = j.employer_id
            LEFT  JOIN loi_qualification_category qc ON qc.id = j.qualification_category_id
            LEFT  JOIN loi_salary_range sr         ON sr.id = j.salary_range_id
            WHERE j.id IN ($ph)");
        $st->execute($jobIds);
        $rows = $st->fetchAll();
        // Attach locations to each row.
        $locByJob = [];
        $lst = db()->prepare("SELECT jl.job_id, l.name
            FROM loi_job_location jl INNER JOIN loi_location l ON l.id = jl.location_id
            WHERE jl.job_id IN ($ph)");
        $lst->execute($jobIds);
        foreach ($lst->fetchAll() as $r) $locByJob[(int) $r['job_id']][] = (string) $r['name'];
        foreach ($rows as &$r) $r['_locations'] = $locByJob[(int) $r['id']] ?? [];
        unset($r);
    } catch (Throwable $e) { /* ignore */ }
    return $rows;
}

/**
 * Return the ordered job id list for a brochure.
 */
function loi_brochure_job_ids(int $brochureId): array
{
    try {
        $st = db()->prepare('SELECT job_id FROM loi_brochure_job WHERE brochure_id = ? ORDER BY sort_order ASC, job_id ASC');
        $st->execute([$brochureId]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[] = (int) $r['job_id'];
        return $out;
    } catch (Throwable $e) { return []; }
}

/**
 * Load the ordered pages for a template.
 */
function loi_brochure_template_pages(int $templateId): array
{
    try {
        $st = db()->prepare('SELECT * FROM loi_brochure_page WHERE template_id = ? ORDER BY page_number ASC, id ASC');
        $st->execute([$templateId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Resolve the placeholder set that labels can embed. Returns a flat
 * map {name => rendered string|html}. Values are already HTML-escaped
 * where appropriate for the HTML/Dompdf renderer; the editor preview
 * runs the resolver too so what-you-see-is-what-you-get.
 */
function loi_brochure_placeholders(string $brochureTitle, array $jobIds): array
{
    $stats = loi_brochure_stats($jobIds);
    $jobs  = loi_brochure_jobs($jobIds);

    $kv = static function (array $counts): string {
        if ($counts === []) return '';
        arsort($counts);
        $out = [];
        foreach ($counts as $k => $v) $out[] = esc((string) $k) . ': <strong>' . (int) $v . '</strong>';
        return implode('<br>', $out);
    };

    // Simple HTML list of all jobs.
    $jobsList = '';
    if ($jobs !== []) {
        $jobsList .= '<ol style="padding-left:16px; margin:0;">';
        foreach ($jobs as $j) {
            $jobsList .= '<li style="margin-bottom:4px;"><strong>' . esc((string) $j['job_role']) . '</strong>';
            $jobsList .= ' <span style="color:#555;">(' . esc((string) $j['employer_name']) . ')</span>';
            if (!empty($j['vacancy_count'])) $jobsList .= ' · ' . (int) $j['vacancy_count'] . ' openings';
            if (!empty($j['salary_range_name'])) $jobsList .= ' · ' . esc((string) $j['salary_range_name']);
            $jobsList .= '</li>';
        }
        $jobsList .= '</ol>';
    }

    // Jobs grouped by qualification category.
    $byQual = [];
    foreach ($jobs as $j) {
        $qc = trim((string) ($j['qualification_category_name'] ?? '')) ?: 'Not specified';
        $byQual[$qc][] = $j;
    }
    ksort($byQual);
    $jobsByQual = '';
    foreach ($byQual as $qc => $items) {
        $jobsByQual .= '<div style="margin-bottom:8px;"><div style="font-weight:bold; border-bottom:1px solid #999; padding-bottom:2px; margin-bottom:4px;">' . esc($qc) . ' <span style="color:#555; font-weight:normal;">(' . count($items) . ' jobs)</span></div>';
        $jobsByQual .= '<ul style="padding-left:16px; margin:0;">';
        foreach ($items as $j) {
            $jobsByQual .= '<li>' . esc((string) $j['job_role']) . ' — ' . esc((string) $j['employer_name']);
            if (!empty($j['vacancy_count'])) $jobsByQual .= ' · ' . (int) $j['vacancy_count'] . ' openings';
            $jobsByQual .= '</li>';
        }
        $jobsByQual .= '</ul></div>';
    }

    return [
        'brochure_title'      => esc($brochureTitle),
        'today'               => date('d/m/Y'),
        'employers_count'     => (string) $stats['employers_count'],
        'jobs_count'          => (string) $stats['jobs_count'],
        'openings_count'      => (string) $stats['openings_count'],
        'applications_count'  => (string) $stats['applications_count'],
        'employer_names'      => esc(implode(', ', $stats['employer_names'])),
        'salary_summary'      => $kv($stats['by_salary']),
        'location_summary'    => $kv($stats['by_location']),
        'qualification_summary'=> $kv($stats['by_qualification']),
        'gender_summary'      => $kv($stats['by_gender']),
        'jobs_list'           => $jobsList,
        'jobs_by_qualification'=> $jobsByQual,
    ];
}

/**
 * Replace every `{placeholder}` inside the given text with its
 * resolved value. Unknown placeholders are left verbatim.
 */
function loi_brochure_apply_placeholders(string $text, array $placeholders): string
{
    return preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/i', static function ($m) use ($placeholders) {
        $key = (string) $m[1];
        return array_key_exists($key, $placeholders) ? (string) $placeholders[$key] : $m[0];
    }, $text);
}

/**
 * Return the full set of known placeholder keys (used by the editor
 * palette to help the admin discover what's available).
 */
function loi_brochure_known_placeholders(): array
{
    return [
        'brochure_title', 'today',
        'employers_count', 'jobs_count', 'openings_count', 'applications_count',
        'employer_names',
        'salary_summary', 'location_summary', 'qualification_summary', 'gender_summary',
        'jobs_list', 'jobs_by_qualification',
    ];
}

/**
 * Try to boot Dompdf from any of the usual places it can be installed
 * without Composer. Returns true if Dompdf\Dompdf is now available.
 */
function loi_brochure_dompdf_available(): bool
{
    if (class_exists('Dompdf\\Dompdf')) return true;
    $candidates = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../vendor/dompdf/autoload.inc.php',
        __DIR__ . '/../vendor/dompdf/dompdf/autoload.inc.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) {
            require_once $f;
            if (class_exists('Dompdf\\Dompdf')) return true;
        }
    }
    return false;
}
