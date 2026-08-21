<?php
/**
 * District PMU module — schema bootstrap + shared helpers.
 *
 * Everything in this module is scoped to the `district_pmu` role.
 * `district_pmu_bootstrap()` is idempotent and safe to call at the top
 * of every District PMU page.
 */

require_once __DIR__ . '/db.php';

function district_pmu_bootstrap(): void
{
    $db = db();

    /* -------------------------------------------------------------------- *
     * Office profile — one row per DISTRICT (not per user), because the
     * district is the stable owning entity. Users may be reassigned but
     * the district's office details (photos, SPOC etc) stay with the
     * district. Photos live on disk under uploads/district_pmu/{district}/.
     * -------------------------------------------------------------------- */
    $db->query("CREATE TABLE IF NOT EXISTS district_pmu_office_profile (
        id INT AUTO_INCREMENT PRIMARY KEY,
        district VARCHAR(120) NOT NULL,
        office_name VARCHAR(255) NULL,
        address TEXT NULL,
        pincode VARCHAR(10) NULL,
        spoc_name VARCHAR(255) NULL,
        spoc_contact VARCHAR(50) NULL,
        latitude DECIMAL(10,7) NULL,
        longitude DECIMAL(10,7) NULL,
        building_photo_path VARCHAR(500) NULL,
        room_photo_path VARCHAR(500) NULL,
        updated_by INT NULL,
        updated_at DATETIME NULL,
        UNIQUE KEY unique_district (district)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Idempotent migration for the Phase-2 shape (user_id was PK, no id
    // surrogate, no UNIQUE(district)). Safe to re-run — every ALTER is
    // wrapped in a try/catch so an already-migrated table just skips it.
    $officeCols = [];
    foreach ($db->query('SHOW COLUMNS FROM district_pmu_office_profile')->fetchAll() as $col) {
        $officeCols[strtolower((string) $col['Field'])] = $col;
    }
    if (!isset($officeCols['id'])) {
        try { $db->query('ALTER TABLE district_pmu_office_profile DROP PRIMARY KEY'); } catch (Throwable $e) { /* ignore */ }
        try { $db->query('ALTER TABLE district_pmu_office_profile ADD COLUMN id INT AUTO_INCREMENT PRIMARY KEY FIRST'); } catch (Throwable $e) { /* ignore */ }
        try { $db->query('ALTER TABLE district_pmu_office_profile MODIFY user_id INT NULL'); } catch (Throwable $e) { /* ignore */ }
    }
    $hasDistrictUnique = false;
    foreach ($db->query('SHOW INDEX FROM district_pmu_office_profile')->fetchAll() as $idx) {
        if (strtolower((string) $idx['Column_name']) === 'district' && (int) $idx['Non_unique'] === 0) {
            $hasDistrictUnique = true; break;
        }
    }
    if (!$hasDistrictUnique) {
        try { $db->query('ALTER TABLE district_pmu_office_profile ADD UNIQUE KEY unique_district (district)'); } catch (Throwable $e) { /* ignore */ }
    }

    /* -------------------------------------------------------------------- *
     * Asset type + subtype masters (admin-editable). Seeded with the list
     * from the requirements when empty; new rows can be added later
     * without affecting existing asset rows because the ids are stable.
     * -------------------------------------------------------------------- */
    $db->query("CREATE TABLE IF NOT EXISTS district_pmu_asset_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY unique_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seededTypes = (int) $db->query('SELECT COUNT(*) FROM district_pmu_asset_types')->fetchColumn();
    if ($seededTypes === 0) {
        $ins = $db->prepare('INSERT INTO district_pmu_asset_types (name, sort_order) VALUES (?, ?)');
        foreach ([['IT Asset', 1], ['Non-IT Asset', 2]] as [$n, $so]) {
            try { $ins->execute([$n, $so]); } catch (Throwable $e) { /* ignore */ }
        }
    }

    $db->query("CREATE TABLE IF NOT EXISTS district_pmu_asset_subtypes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_type_id INT NOT NULL,
        name VARCHAR(160) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY unique_type_name (asset_type_id, name),
        KEY idx_type (asset_type_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seededSubtypes = (int) $db->query('SELECT COUNT(*) FROM district_pmu_asset_subtypes')->fetchColumn();
    if ($seededSubtypes === 0) {
        $typeIdByName = [];
        foreach ($db->query('SELECT id, name FROM district_pmu_asset_types')->fetchAll() as $r) {
            $typeIdByName[(string) $r['name']] = (int) $r['id'];
        }
        $ins = $db->prepare('INSERT INTO district_pmu_asset_subtypes (asset_type_id, name, sort_order) VALUES (?, ?, ?)');
        $itSeeds = [
            'Laptop'            => 10,
            'Charger'           => 20,
            'Mouse'             => 30,
            'Wifi Dongle (SIM)' => 40,
            'Bag'               => 50,
            'Printer'           => 60,
        ];
        $nonItSeeds = [
            'Furniture'         => 10,
            'Registers'         => 20,
            'Files'             => 30,
            'Stationaries'      => 40,
            'Bills / Vouchers'  => 50,
            'Documents'         => 60,
            'Records'           => 70,
        ];
        if (isset($typeIdByName['IT Asset'])) {
            foreach ($itSeeds as $name => $so) {
                try { $ins->execute([$typeIdByName['IT Asset'], $name, $so]); } catch (Throwable $e) { /* ignore */ }
            }
        }
        if (isset($typeIdByName['Non-IT Asset'])) {
            foreach ($nonItSeeds as $name => $so) {
                try { $ins->execute([$typeIdByName['Non-IT Asset'], $name, $so]); } catch (Throwable $e) { /* ignore */ }
            }
        }
    }

    /* -------------------------------------------------------------------- *
     * Owning-authority master (admin-editable). Seeded with the three
     * authorities from the requirements when empty.
     * -------------------------------------------------------------------- */
    $db->query("CREATE TABLE IF NOT EXISTS district_pmu_owning_authorities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(200) NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY unique_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $seededAuthorities = (int) $db->query('SELECT COUNT(*) FROM district_pmu_owning_authorities')->fetchColumn();
    if ($seededAuthorities === 0) {
        $ins = $db->prepare('INSERT INTO district_pmu_owning_authorities (name, sort_order) VALUES (?, ?)');
        foreach ([
            ['State Mission (K-DISC Fund)', 10],
            ['DMC Vijnana Keralam',         20],
            ['K-DISC',                      30],
        ] as [$n, $so]) {
            try { $ins->execute([$n, $so]); } catch (Throwable $e) { /* ignore */ }
        }
    }

    /* -------------------------------------------------------------------- *
     * Asset register — one row per (user, asset). No FK to the masters so
     * an admin can rename a subtype without breaking history; we resolve
     * names on read via LEFT JOIN. Deleting a subtype is disallowed by the
     * app layer while any asset references it.
     * -------------------------------------------------------------------- */
    $db->query("CREATE TABLE IF NOT EXISTS district_pmu_assets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        district VARCHAR(120) NULL,
        asset_type_id INT NOT NULL,
        subtype_id INT NOT NULL,
        description TEXT NULL,
        owning_authority_id INT NULL,
        quantity INT NOT NULL DEFAULT 0,
        remarks TEXT NULL,
        concerned_person VARCHAR(255) NULL,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        KEY idx_user (user_id),
        KEY idx_district (district),
        KEY idx_type (asset_type_id),
        KEY idx_subtype (subtype_id),
        KEY idx_authority (owning_authority_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Idempotent: add the `district` column (and its index) on Phase-2
    // installs that already had the assets table without it.
    $assetCols = [];
    foreach ($db->query('SHOW COLUMNS FROM district_pmu_assets')->fetchAll() as $col) {
        $assetCols[strtolower((string) $col['Field'])] = $col;
    }
    if (!isset($assetCols['district'])) {
        try { $db->query('ALTER TABLE district_pmu_assets ADD COLUMN district VARCHAR(120) NULL AFTER user_id'); } catch (Throwable $e) { /* ignore */ }
        try { $db->query('ALTER TABLE district_pmu_assets ADD KEY idx_district (district)'); } catch (Throwable $e) { /* ignore */ }
    }
}

/**
 * Parse a user's assigned_districts column into a clean, order-preserving
 * array of trimmed district names. Empty / NULL input returns [] which
 * the calling page renders as "ask an Administrator to assign a district".
 */
function district_pmu_user_districts(array $user): array
{
    $raw = trim((string) ($user['assigned_districts'] ?? ''));
    if ($raw === '') return [];
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $p = trim($part);
        if ($p !== '' && !in_array($p, $out, true)) $out[] = $p;
    }
    return $out;
}

/**
 * The currently-active district for this user. Honours ?district= /
 * form's district field when it names one of the user's assigned
 * districts; otherwise defaults to the first assigned district.
 * Returns '' when the user has none assigned.
 */
function district_pmu_current_district(array $user): string
{
    $districts = district_pmu_user_districts($user);
    if ($districts === []) return '';
    $requested = trim((string) ($_GET['district'] ?? $_POST['district'] ?? ''));
    if ($requested !== '' && in_array($requested, $districts, true)) return $requested;
    return $districts[0];
}

/**
 * Reusable HTML for the district switcher shown on District-PMU pages.
 * Single-district users see a plain read-only chip; multi-district users
 * get a GET-form dropdown that reloads the current page with ?district=.
 * `$extraHidden` lets a caller preserve any other query params (like the
 * active Assets filters) across the switch.
 */
function district_pmu_render_district_switcher(array $user, string $currentDistrict, array $extraHidden = []): string
{
    $districts = district_pmu_user_districts($user);
    if ($districts === []) {
        return '<span class="badge text-bg-warning" title="Ask an Administrator to set your assigned districts">No district assigned</span>';
    }
    if (count($districts) === 1) {
        return '<span class="badge text-bg-light border" title="Your assigned district"><i class="bi bi-geo-alt me-1"></i>' . htmlspecialchars($districts[0], ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html  = '<form method="get" class="d-inline-flex align-items-center gap-2">';
    foreach ($extraHidden as $k => $v) {
        if ($k === 'district' || $v === '' || $v === null) continue;
        $html .= '<input type="hidden" name="' . htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') . '">';
    }
    $html .= '<label class="small text-muted mb-0"><i class="bi bi-geo-alt me-1"></i>District</label>';
    $html .= '<select class="form-select form-select-sm" name="district" onchange="this.form.submit()" style="width:auto;">';
    foreach ($districts as $d) {
        $sel = ($d === $currentDistrict) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    $html .= '</select></form>';
    return $html;
}

/**
 * Absolute path to the district-wise upload directory. Callers are
 * responsible for mkdir'ing it before saving files.
 */
function district_pmu_upload_dir(string $district): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($district));
    if ($slug === '' || $slug === false) { $slug = 'unknown'; }
    return __DIR__ . '/../uploads/district_pmu/' . $slug;
}

/** Public web path used inside <img src> for the same file. */
function district_pmu_upload_url(string $district, string $filename): string
{
    $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim($district));
    if ($slug === '' || $slug === false) { $slug = 'unknown'; }
    return '/uploads/district_pmu/' . rawurlencode($slug) . '/' . rawurlencode($filename);
}

/**
 * Convenience: fetch the profile row for the given district (or null).
 * The row is UPSERTed by the office profile page; this helper is just
 * a read-only accessor.
 */
function district_pmu_get_profile_by_district(string $district): ?array
{
    if (trim($district) === '') return null;
    $stmt = db()->prepare('SELECT * FROM district_pmu_office_profile WHERE district = ?');
    $stmt->execute([$district]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}
