<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$flash = get_flash();
$query = mysqli_query(
    $conn,
    "SELECT f.id, f.faculty_id, f.created_at, u.fullname, u.username, u.status,
            COUNT(ca.id) AS class_count
     FROM faculty f
     INNER JOIN users u ON u.id = f.user_id
     LEFT JOIN class_assignments ca ON ca.faculty_id = f.id
     GROUP BY f.id, f.faculty_id, f.created_at, u.fullname, u.username, u.status
     ORDER BY u.fullname"
);
$faculty = $query ? mysqli_fetch_all($query, MYSQLI_ASSOC) : [];
$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all', 'active', 'inactive'], true)) {
    $status_filter = 'all';
}
$visible_faculty = array_values(array_filter($faculty, function ($row) use ($status_filter) {
    return $status_filter === 'all' || $row['status'] === $status_filter;
}));
$active_faculty = count(array_filter($faculty, function ($row) {
    return $row['status'] === 'active';
}));

render_admin_page_start('faculty', 'Faculty', 'Faculty directory', 'Create faculty accounts and review their current class assignment coverage.', 'User Management');
render_admin_flash($flash);
render_admin_workflow('accounts');
render_admin_explainer(
    'Deactivate instead of deleting',
    'Deactivation immediately blocks login while preserving classes, materials, submissions, grades, and history. Reactivate the same account when needed.'
);
?>
<div class="admin-content-grid">
    <section class="admin-card admin-form-card">
        <div class="admin-section-head"><div><h2>Add faculty account</h2><span>Creates login and faculty profile</span></div></div>
        <form class="admin-form" action="add.php" method="POST" autocomplete="off">
            <?= csrf_field(); ?>
            <div class="admin-field">
                <label for="faculty_id">Faculty ID</label>
                <input id="faculty_id" type="text" name="faculty_id" placeholder="F-001" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="fullname">Full name</label>
                <input id="fullname" type="text" name="fullname" placeholder="Juan Dela Cruz" maxlength="100" required>
            </div>
            <div class="admin-field">
                <label for="username">Username</label>
                <input id="username" type="text" name="username" maxlength="50" required autocomplete="off">
            </div>
            <div class="admin-field">
                <label for="password">Temporary password</label>
                <input id="password" type="password" name="password" minlength="8" required autocomplete="new-password">
                <small>Use at least 8 characters.</small>
            </div>
            <button class="admin-button primary" type="submit"><?= study_icon('person-plus'); ?> Create faculty</button>
        </form>
    </section>

    <section class="admin-card admin-table-card">
        <div class="admin-section-head">
            <div><h2>Faculty accounts</h2><span><?= number_format($active_faculty); ?> active · <?= number_format(count($faculty) - $active_faculty); ?> inactive</span></div>
            <form class="admin-compact-filter" method="GET">
                <label for="faculty_status">Status</label>
                <select id="faculty_status" name="status" onchange="this.form.submit()">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : ''; ?>>All accounts</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </form>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-data-table">
                <thead><tr><th>Faculty</th><th>Login</th><th>Classes</th><th>Status</th><th class="admin-table-action">Action</th></tr></thead>
                <tbody>
                <?php if (!$visible_faculty): ?>
                    <tr><td class="admin-empty-cell" colspan="5">No faculty accounts created yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($visible_faculty as $row): ?>
                    <tr>
                        <td>
                            <div class="admin-person">
                                <span><?= e(strtoupper(substr($row['fullname'], 0, 1))); ?></span>
                                <span><strong><?= e($row['fullname']); ?></strong><small><?= e($row['faculty_id']); ?></small></span>
                            </div>
                        </td>
                        <td><strong><?= e($row['username']); ?></strong><small>Created <?= e(date('M d, Y', strtotime($row['created_at']))); ?></small></td>
                        <td><span class="admin-count-badge"><?= number_format($row['class_count']); ?></span></td>
                        <td><span class="admin-status-pill <?= e($row['status']); ?>"><?= e(ucfirst($row['status'])); ?></span></td>
                        <td class="admin-table-action">
                            <div class="admin-row-actions">
                                <a class="admin-button secondary small" href="edit.php?id=<?= e($row['id']); ?>"><?= study_icon('pencil'); ?> Edit</a>
                                <form action="status.php" method="POST" onsubmit="return confirm('<?= $row['status'] === 'active' ? 'Deactivate this faculty account? Login access will be blocked, but all records will be preserved.' : 'Reactivate this faculty account and restore login access?'; ?>')">
                                    <?= csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?= e($row['id']); ?>">
                                    <input type="hidden" name="status" value="<?= $row['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button class="admin-button <?= $row['status'] === 'active' ? 'warning' : 'success'; ?> small" type="submit">
                                        <?= study_icon($row['status'] === 'active' ? 'person-slash' : 'person-check'); ?>
                                        <?= $row['status'] === 'active' ? 'Deactivate' : 'Reactivate'; ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_admin_page_end(); ?>
