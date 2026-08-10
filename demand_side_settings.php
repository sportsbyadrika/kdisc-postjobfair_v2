<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/demand_side_helpers.php';
require_admin();

$currentUser = current_user();
if (($currentUser['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    render_header('Access denied');
    render_page_header('Access denied', ['icon' => 'bi-shield-lock', 'subtitle' => 'Only Administrator can edit Demand Side settings.']);
    echo '<div class="alert alert-danger">Only Administrator can access Demand Side Settings.</div>';
    echo '<a class="btn btn-primary" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>';
    render_footer();
    exit;
}

demand_side_bootstrap();

$flashMessage = null;
$flashType = 'success';

if (is_post() && ($_POST['action'] ?? '') === 'save_category') {
    $catId = (int) ($_POST['category_id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $min = (int) ($_POST['min_positions'] ?? 0);
    $max = (int) ($_POST['max_positions'] ?? 0);
    if ($name === '' || $max < $min) {
        $flashMessage = 'Enter a Category name and ensure Max ≥ Min.';
        $flashType = 'danger';
    } elseif ($catId > 0) {
        db()->prepare('UPDATE demand_employer_categories SET name = ?, min_positions = ?, max_positions = ? WHERE id = ?')
           ->execute([$name, $min, $max, $catId]);
        $flashMessage = "Updated $name (range $min – $max).";
    } else {
        try {
            $ord = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM demand_employer_categories')->fetchColumn();
            db()->prepare('INSERT INTO demand_employer_categories (name, min_positions, max_positions, sort_order) VALUES (?, ?, ?, ?)')
               ->execute([$name, $min, $max, $ord]);
            $flashMessage = "Added $name (range $min – $max).";
        } catch (Throwable $e) {
            $flashMessage = 'Could not add: ' . $e->getMessage();
            $flashType = 'danger';
        }
    }
} elseif (is_post() && ($_POST['action'] ?? '') === 'delete_category') {
    $catId = (int) ($_POST['category_id'] ?? 0);
    if ($catId > 0) {
        db()->prepare('DELETE FROM demand_employer_categories WHERE id = ?')->execute([$catId]);
        $flashMessage = 'Category removed.';
    }
}

$categories = demand_get_categories();

render_header('Demand Side Settings', ['main_container_class' => 'container-fluid']);
render_page_header('Demand Side · Settings', [
    'icon' => 'bi-gear',
    'subtitle' => 'Admin-tunable settings for the Demand Side module. Employer Category is derived from an employer\'s total Open Positions and refreshes automatically whenever these ranges are edited.',
    'actions' => '<a class="btn btn-light" href="/demand_side_employers.php"><i class="bi bi-arrow-left me-1"></i>Back to Employers</a>',
]);

if ($flashMessage !== null): ?>
    <div class="alert alert-<?= esc($flashType) ?>"><?= esc($flashMessage) ?></div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-tags text-primary me-1"></i>Employer Category ranges</span>
        <span class="status-chip status-info"><?= number_format(count($categories)) ?> categories</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>Category name</th>
                        <th class="text-end">Min positions</th>
                        <th class="text-end">Max positions</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($categories === []): ?>
                        <tr><td colspan="4"><div class="empty-state"><i class="bi bi-inbox"></i>No categories defined yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <form method="post">
                                <input type="hidden" name="action" value="save_category">
                                <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                                <td><input type="text" class="form-control form-control-sm" name="name" value="<?= esc((string) $c['name']) ?>" required></td>
                                <td><input type="number" min="0" class="form-control form-control-sm text-end" name="min_positions" value="<?= (int) $c['min_positions'] ?>" required></td>
                                <td><input type="number" min="0" class="form-control form-control-sm text-end" name="max_positions" value="<?= (int) $c['max_positions'] ?>" required></td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-save"></i></button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Remove this category?');">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                            </form>
                                </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <form method="post">
                            <input type="hidden" name="action" value="save_category">
                            <input type="hidden" name="category_id" value="0">
                            <td><input type="text" class="form-control form-control-sm" name="name" placeholder="e.g. Category 5" required></td>
                            <td><input type="number" min="0" class="form-control form-control-sm text-end" name="min_positions" value="0" required></td>
                            <td><input type="number" min="0" class="form-control form-control-sm text-end" name="max_positions" value="100" required></td>
                            <td class="text-end"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-plus-lg me-1"></i>Add</button></td>
                        </form>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <div class="card-footer small text-muted">
        Categories are matched against an employer's <code>SUM(open_positions)</code> — first row whose range contains the value wins (order is sort_order asc, min_positions desc). Ranges are inclusive on both ends.
    </div>
</div>

<?php render_footer(); ?>
