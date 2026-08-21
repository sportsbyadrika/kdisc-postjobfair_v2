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
     * Office profile — one row per user. Photos are stored on disk under
     * uploads/district_pmu/{district}/... and only the relative path is
     * stored in the DB. Keeping photos in a district-wise directory rather
     * than user-wise means the artefacts survive user turnover — the
     * district itself is the stable owning entity.
     * -------------------------------------------------------------------- */
    $db->query("CREATE TABLE IF NOT EXISTS district_pmu_office_profile (
        user_id INT NOT NULL PRIMARY KEY,
        district VARCHAR(120) NULL,
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
        KEY idx_district (district)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
        KEY idx_type (asset_type_id),
        KEY idx_subtype (subtype_id),
        KEY idx_authority (owning_authority_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
 * Convenience: fetch the profile row for the given user (or null).
 * The row is bootstrapped implicitly by an UPSERT elsewhere; this
 * helper is just a read-only accessor.
 */
function district_pmu_get_profile(int $userId): ?array
{
    if ($userId <= 0) return null;
    $stmt = db()->prepare('SELECT * FROM district_pmu_office_profile WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}
