<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$flash = get_flash();
$section_query = mysqli_query($conn, 'SELECT id, section_name, course, year_level FROM sections ORDER BY course, year_level, section_name');
$sections = $section_query ? mysqli_fetch_all($section_query, MYSQLI_ASSOC) : [];

$query = mysqli_query(
    $conn,
    "SELECT st.id, st.student_no, st.section_id, st.created_at, u.fullname, u.username, u.status,
            s.section_name, s.course, s.year_level
     FROM students st
     INNER JOIN users u ON u.id = st.user_id
     INNER JOIN sections s ON s.id = st.section_id
     ORDER BY u.fullname"
);
$students = $query ? mysqli_fetch_all($query, MYSQLI_ASSOC) : [];
$status_filter = $_GET['status'] ?? 'all';
$section_filter = intval($_GET['section'] ?? 0);
if (!in_array($status_filter, ['all', 'active', 'inactive'], true)) {
    $status_filter = 'all';
}
$visible_students = array_values(array_filter($students, function ($row) use ($status_filter, $section_filter) {
    $matches_status = $status_filter === 'all' || $row['status'] === $status_filter;
    $matches_section = $section_filter === 0 || intval($row['section_id']) === $section_filter;
    return $matches_status && $matches_section;
}));
$active_students = count(array_filter($students, function ($row) {
    return $row['status'] === 'active';
}));

render_admin_page_start('students', 'Students', 'Student directory', 'Create student accounts, assign their official section, and review account status.', 'User Management');
render_admin_flash($flash);
render_admin_workflow('accounts');
render_admin_explainer(
    'Section controls class access',
    'Students automatically see classes assigned to their section. Use Edit to move a student to the correct section; do not create a duplicate account.'
);
?>
<div class="admin-content-grid">
    <section class="admin-card admin-form-card">
        <div class="admin-section-head"><div><h2>Add student account</h2><span>Creates login and student profile</span></div></div>
        <?php if (!$sections): ?>
            <div class="admin-inline-warning"><?= study_icon('exclamation-triangle'); ?><span>Create a section before adding students.</span></div>
        <?php endif; ?>
        <form class="admin-form" action="add.php" method="POST" autocomplete="off">
            <?= csrf_field(); ?>
            <div class="admin-field">
                <label for="student_no">Student number</label>
                <input id="student_no" type="text" name="student_no" placeholder="S26-0001" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="fullname">Full name</label>
                <input id="fullname" type="text" name="fullname" placeholder="Juan Dela Cruz" maxlength="100" required>
            </div>
            <div class="admin-field">
                <label for="section_id">Section</label>
                <select id="section_id" name="section_id" required <?= !$sections ? 'disabled' : ''; ?>>
                    <option value="">Select section</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= e($section['id']); ?>"><?= e($section['section_name']); ?> · <?= e($section['course']); ?> Year <?= e($section['year_level']); ?></option>
                    <?php endforeach; ?>
                </select>
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
            <button class="admin-button primary" type="submit" <?= !$sections ? 'disabled' : ''; ?>><?= study_icon('person-plus'); ?> Create student</button>
        </form>
    </section>

    <section class="admin-card admin-table-card">
        <div class="admin-section-head">
            <div><h2>Student accounts</h2><span><?= number_format($active_students); ?> active · <?= number_format(count($students) - $active_students); ?> inactive</span></div>
            <form class="admin-filter-row" method="GET">
                <select name="status" aria-label="Filter by status">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : ''; ?>>All statuses</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <select name="section" aria-label="Filter by section">
                    <option value="0">All sections</option>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= e($section['id']); ?>" <?= $section_filter === intval($section['id']) ? 'selected' : ''; ?>><?= e($section['section_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="admin-button secondary small" type="submit"><?= study_icon('funnel'); ?> Apply</button>
            </form>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-data-table">
                <thead><tr><th>Student</th><th>Section</th><th>Login</th><th>Status</th><th class="admin-table-action">Action</th></tr></thead>
                <tbody>
                <?php if (!$visible_students): ?>
                    <tr><td class="admin-empty-cell" colspan="5">No student accounts match the selected filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($visible_students as $row): ?>
                    <tr>
                        <td>
                            <div class="admin-person">
                                <span><?= e(strtoupper(substr($row['fullname'], 0, 1))); ?></span>
                                <span><strong><?= e($row['fullname']); ?></strong><small><?= e($row['student_no']); ?></small></span>
                            </div>
                        </td>
                        <td><strong><?= e($row['section_name']); ?></strong><small><?= e($row['course']); ?> · Year <?= e($row['year_level']); ?></small></td>
                        <td><strong><?= e($row['username']); ?></strong><small>Created <?= e(date('M d, Y', strtotime($row['created_at']))); ?></small></td>
                        <td><span class="admin-status-pill <?= e($row['status']); ?>"><?= e(ucfirst($row['status'])); ?></span></td>
                        <td class="admin-table-action">
                            <div class="admin-row-actions">
                                <a class="admin-button secondary small" href="edit.php?id=<?= e($row['id']); ?>"><?= study_icon('pencil'); ?> Edit</a>
                                <form action="status.php" method="POST" onsubmit="return confirm('<?= $row['status'] === 'active' ? 'Deactivate this student account? Login access will be blocked, but submissions and grades will be preserved.' : 'Reactivate this student account and restore login access?'; ?>')">
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
