<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_with_flash('index.php', 'error', 'Invalid section.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('index.php');
    $section_name = trim($_POST['section_name'] ?? '');
    $course = trim($_POST['course'] ?? '');
    $year_level = intval($_POST['year_level'] ?? 0);

    if ($section_name === '' || $course === '' || $year_level < 1 || $year_level > 5) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Complete all section fields correctly.');
    }

    $duplicate = mysqli_prepare($conn, 'SELECT id FROM sections WHERE LOWER(section_name) = LOWER(?) AND id <> ? LIMIT 1');
    if (!$duplicate) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Unable to validate the section.');
    }
    mysqli_stmt_bind_param($duplicate, 'si', $section_name, $id);
    mysqli_stmt_execute($duplicate);
    $already_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
    mysqli_stmt_close($duplicate);
    if ($already_exists) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Another section already uses that name.');
    }

    $statement = mysqli_prepare($conn, 'UPDATE sections SET section_name = ?, course = ?, year_level = ? WHERE id = ?');
    if (!$statement) {
        error_log('Section update prepare failed: ' . mysqli_error($conn));
        redirect_with_flash('index.php', 'error', 'Unable to update the section.');
    }
    mysqli_stmt_bind_param($statement, 'ssii', $section_name, $course, $year_level, $id);
    $updated = mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
    redirect_with_flash('index.php', $updated ? 'success' : 'error', $updated ? 'Section updated successfully.' : 'Unable to update the section.');
}

$statement = mysqli_prepare($conn, 'SELECT section_name, course, year_level FROM sections WHERE id = ?');
if (!$statement) {
    redirect_with_flash('index.php', 'error', 'Unable to load the section.');
}
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($statement);
if (!$row) {
    redirect_with_flash('index.php', 'error', 'Section not found.');
}

render_admin_page_start('sections', 'Edit Section', 'Edit section', 'Update the official section name, course, or year level.', 'Academic Structure');
render_admin_workflow('structure');
render_admin_explainer(
    'Changes apply immediately',
    'Students and class assignments remain connected to this section record. Only its displayed name, course, or year level changes.'
);
?>
<section class="admin-card admin-edit-card">
    <div class="admin-section-head">
        <div><h2><?= e($row['section_name']); ?></h2><span>Section record #<?= e($id); ?></span></div>
        <a class="admin-button secondary small" href="index.php"><?= study_icon('arrow-left'); ?> Back to sections</a>
    </div>
    <form class="admin-form admin-form-wide" method="POST">
        <?= csrf_field(); ?>
        <input type="hidden" name="id" value="<?= e($id); ?>">
        <div class="admin-form-grid">
            <div class="admin-field">
                <label for="section_name">Section name</label>
                <input id="section_name" type="text" name="section_name" value="<?= e($row['section_name']); ?>" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="course">Course</label>
                <input id="course" type="text" name="course" value="<?= e($row['course']); ?>" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="year_level">Year level</label>
                <select id="year_level" name="year_level" required>
                    <?php for ($year = 1; $year <= 5; $year++): ?>
                        <option value="<?= $year; ?>" <?= intval($row['year_level']) === $year ? 'selected' : ''; ?>>Year <?= $year; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        <div class="admin-form-actions">
            <a class="admin-button secondary" href="index.php">Cancel</a>
            <button class="admin-button primary" type="submit"><?= study_icon('check2'); ?> Save changes</button>
        </div>
    </form>
</section>
<?php render_admin_page_end(); ?>
