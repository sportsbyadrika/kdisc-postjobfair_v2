<?php
/**
 * Office Hierarchy master — schema bootstrap + shared helpers.
 *
 * Represents an administrative office structure Office → Division →
 * Section → Seat, with a responsible-officer assignment on every node
 * and a full transfer history so we can answer "who ran this office in
 * March 2024?" or "how many times has this seat changed hands?".
 */

require_once __DIR__ . '/db.php';

function office_hierarchy_bootstrap(): void
{
    $db = db();

    $db->query("CREATE TABLE IF NOT EXISTS office_hierarchy_nodes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_id INT NULL,
        level_type ENUM('office', 'division', 'section', 'sub_section', 'seat') NOT NULL,
        name VARCHAR(255) NOT NULL,
        details TEXT NULL,
        location VARCHAR(500) NULL,
        seat_number VARCHAR(120) NULL,
        responsible_officer_id INT NULL,
        designation VARCHAR(255) NULL,
        active_status TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NULL,
        updated_at DATETIME NULL,
        created_by INT NULL,
        updated_by INT NULL,
        KEY idx_parent (parent_id),
        KEY idx_level (level_type),
        KEY idx_officer (responsible_officer_id),
        KEY idx_active (active_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Idempotent ENUM extension so pre-existing installs that never
    // saw 'sub_section' get the new value silently added. If it's
    // already there, MODIFY COLUMN is a no-op; try/catch guards
    // hosting where the DB user lacks ALTER privilege.
    try {
        $db->query("ALTER TABLE office_hierarchy_nodes
            MODIFY COLUMN level_type ENUM('office','division','section','sub_section','seat') NOT NULL");
    } catch (Throwable $e) { /* ignore — page still works without sub_section */ }

    $db->query("CREATE TABLE IF NOT EXISTS office_hierarchy_officer_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        node_id INT NOT NULL,
        officer_id INT NOT NULL,
        officer_name_snapshot VARCHAR(255) NULL,
        designation VARCHAR(255) NULL,
        assigned_at DATETIME NOT NULL,
        unassigned_at DATETIME NULL,
        assigned_by INT NULL,
        unassigned_by INT NULL,
        assign_reason VARCHAR(500) NULL,
        unassign_reason VARCHAR(500) NULL,
        KEY idx_node (node_id),
        KEY idx_officer (officer_id),
        KEY idx_current (node_id, unassigned_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Default child level for a given parent — used when only one child
 * kind is valid. Section still defaults to Seat so existing three-
 * level hierarchies (Office → Division → Section → Seat) keep the
 * same one-click flow; Sub Section is an OPTIONAL insertion offered
 * as a second button on Section rows. See office_hierarchy_allowed_children().
 */
function office_hierarchy_child_level(?string $parentLevel): ?string
{
    return match ($parentLevel) {
        null          => 'office',
        'office'      => 'division',
        'division'    => 'section',
        'section'     => 'seat',
        'sub_section' => 'seat',
        default       => null,
    };
}

/**
 * Every level that can validly sit under this parent, in the order
 * they should appear as buttons on the UI. Section allows both seats
 * (the historical default) and an optional intermediate Sub Section.
 */
function office_hierarchy_allowed_children(?string $parentLevel): array
{
    return match ($parentLevel) {
        null          => ['office'],
        'office'      => ['division'],
        'division'    => ['section'],
        'section'     => ['seat', 'sub_section'],
        'sub_section' => ['seat'],
        default       => [],
    };
}

/** Human-readable label for a level. */
function office_hierarchy_level_label(string $level): string
{
    return match ($level) {
        'office'      => 'Office',
        'division'    => 'Division',
        'section'     => 'Section',
        'sub_section' => 'Sub Section',
        'seat'        => 'Seat',
        default       => ucfirst($level),
    };
}

/** Bootstrap-icon glyph for a level (used on the tree + list). */
function office_hierarchy_level_icon(string $level): string
{
    return match ($level) {
        'office'      => 'bi-building',
        'division'    => 'bi-diagram-3',
        'section'    => 'bi-diagram-2',
        'sub_section' => 'bi-diagram-2-fill',
        'seat'        => 'bi-person-workspace',
        default       => 'bi-dot',
    };
}

/** Fetch the node row (or null). */
function office_hierarchy_get_node(int $id): ?array
{
    if ($id <= 0) return null;
    $stmt = db()->prepare('SELECT n.*, u.name AS officer_name
        FROM office_hierarchy_nodes n
        LEFT JOIN users u ON u.id = n.responsible_officer_id
        WHERE n.id = ?');
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r === false ? null : $r;
}

/**
 * Walk up the ancestor chain — returns [root, …, direct parent] in
 * top-down order. Empty array for a root-level node.
 */
function office_hierarchy_get_ancestors(int $nodeId): array
{
    $chain = [];
    $current = office_hierarchy_get_node($nodeId);
    while ($current && !empty($current['parent_id'])) {
        $parent = office_hierarchy_get_node((int) $current['parent_id']);
        if ($parent === null) break;
        array_unshift($chain, $parent);
        $current = $parent;
    }
    return $chain;
}

/**
 * Immediate children of a node (or root-level rows when $parentId is
 * null). By default returns active + inactive rows so the operator can
 * see and re-activate deactivated ones; pass $activeOnly = true to
 * hide inactive rows for a cleaner tree.
 */
function office_hierarchy_get_children(?int $parentId, bool $activeOnly = false): array
{
    // We match BOTH IS NULL and = 0 for root-level lookups because our
    // Database wrapper stringifies null on bind, which MariaDB coerces
    // to 0 for INT NULL columns. Existing rows may be either.
    $sql = 'SELECT n.*, u.name AS officer_name
        FROM office_hierarchy_nodes n
        LEFT JOIN users u ON u.id = n.responsible_officer_id
        WHERE ' . ($parentId === null ? '(n.parent_id IS NULL OR n.parent_id = 0)' : 'n.parent_id = ?')
        . ($activeOnly ? ' AND n.active_status = 1' : '')
        . ' ORDER BY n.sort_order ASC, n.name ASC';
    $stmt = db()->prepare($sql);
    if ($parentId !== null) {
        $stmt->execute([$parentId]);
    } else {
        $stmt->execute([]);
    }
    return $stmt->fetchAll();
}

/**
 * Current + past officer assignments for a node, newest first. Rows
 * with unassigned_at IS NULL are still active.
 */
function office_hierarchy_officer_history(int $nodeId): array
{
    $stmt = db()->prepare('SELECT h.*, ua.name AS assigned_by_name, ur.name AS unassigned_by_name
        FROM office_hierarchy_officer_history h
        LEFT JOIN users ua ON ua.id = h.assigned_by
        LEFT JOIN users ur ON ur.id = h.unassigned_by
        WHERE h.node_id = ?
        ORDER BY h.assigned_at DESC, h.id DESC');
    $stmt->execute([$nodeId]);
    return $stmt->fetchAll();
}
