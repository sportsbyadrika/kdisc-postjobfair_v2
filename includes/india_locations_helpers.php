<?php
/**
 * India locations master.
 *
 * Two tables:
 *   india_state     — all 28 states + 8 UTs
 *   india_district  — every district under each state
 *
 * The LOI employer form uses these to populate the State dropdown and,
 * via AJAX, the District dropdown dependent on the chosen state. Any
 * future module that needs an India state/district picker can
 * ride the same pair + the same AJAX endpoint without re-seeding.
 *
 * We store the free-text name of state + district on the employer
 * (not the FK), so an admin renaming a district in this master won't
 * silently rewrite historic rows. The dropdown's selected option is
 * matched by name — that's intentional.
 *
 * District lists reflect the current official count as of 2024/25.
 * Where a state has recently reorganised (notably AP), the seed uses
 * the newest set. Admins can add rows via the seed-plus-ALTER pattern
 * if a new district is notified; INSERT IGNORE on the seed makes
 * re-runs cheap.
 */

require_once __DIR__ . '/db.php';

function india_locations_bootstrap(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = db();

    try {
        $db->query("CREATE TABLE IF NOT EXISTS india_state (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            code VARCHAR(10) NULL,
            kind ENUM('state','ut') NOT NULL DEFAULT 'state',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 100,
            UNIQUE KEY unique_name (name),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS india_district (
            id INT AUTO_INCREMENT PRIMARY KEY,
            state_id INT NOT NULL,
            name VARCHAR(160) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY unique_state_name (state_id, name),
            KEY idx_state (state_id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $seed = require __DIR__ . '/india_locations_seed.php';

        $insState    = $db->prepare('INSERT IGNORE INTO india_state (name, code, kind, is_active, sort_order) VALUES (?, ?, ?, 1, ?)');
        $findState   = $db->prepare('SELECT id FROM india_state WHERE name = ? LIMIT 1');
        $insDistrict = $db->prepare('INSERT IGNORE INTO india_district (state_id, name, is_active) VALUES (?, ?, 1)');

        $ord = 10;
        foreach ($seed['states'] as $s) {
            $insState->execute([$s['name'], $s['code'], $s['kind'], $ord]);
            $ord += 10;
        }
        foreach ($seed['districts'] as $stateName => $districts) {
            $findState->execute([$stateName]);
            $sid = (int) ($findState->fetchColumn() ?: 0);
            if ($sid <= 0) continue;
            foreach ($districts as $d) {
                $insDistrict->execute([$sid, $d]);
            }
        }
    } catch (Throwable $e) { /* CREATE refused — helpers below return empty arrays */ }
}

/**
 * Active states ordered as seeded (states first, UTs below), falling
 * back to name order within each bucket.
 */
function india_states_list(): array
{
    try {
        return db()->query("SELECT id, name, code, kind FROM india_state
            WHERE is_active = 1
            ORDER BY kind ASC, sort_order ASC, name ASC")->fetchAll();
    } catch (Throwable $e) { return []; }
}

/**
 * Districts for a state, matched by state name. Returns a plain list
 * of district name strings (what the AJAX endpoint and dropdowns use).
 */
function india_districts_for_state(string $stateName): array
{
    $stateName = trim($stateName);
    if ($stateName === '') return [];
    try {
        $st = db()->prepare('SELECT d.name FROM india_district d
            INNER JOIN india_state s ON s.id = d.state_id
            WHERE s.name = ? AND d.is_active = 1
            ORDER BY d.name ASC');
        $st->execute([$stateName]);
        $out = [];
        foreach ($st->fetchAll() as $r) $out[] = (string) $r['name'];
        return $out;
    } catch (Throwable $e) { return []; }
}
