<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_with_flash('index.php', 'error', 'Invalid subject.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('index.php');
    $subject_code = trim($_POST['subject_code'] ?? '');
    $subject_name = trim($_POST['subject_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if ($subject_code === '' || $subject_name === '') {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Subject code and name are required.');
    }
    $subject_code = strtoupper($subject_code);
    $duplicate = mysqli_prepare($conn, 'SELECT id FROM subjects WHERE LOWER(subject_code) = LOWER(?) AND id <> ? LIMIT 1');
    if (!$duplicate) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Unable to validate the subject.');
    }
    mysqli_stmt_bind_param($duplicate, 'si', $subject_code, $id);
    mysqli_stmt_execute($duplicate);
    $already_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
    mysqli_stmt_close($duplicate);
    if ($already_exists) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Another subject already uses that subject code.');
    }
    $statement = mysqli_prepare($conn, 'UPDATE subjects SET subject_code = ?, subject_name = ?, description = ? WHERE id = ?');
    if (!$statement) {
        error_log('Subject update prepare failed: ' . mysqli_error($conn));
        redirect_with_flash('index.php', 'error', 'Unable to update the subject.');
    }
    mysqli_stmt_bind_param($statement, 'sssi', $subject_code, $subject_name, $description, $id);
    $updated = mysqli_stmt_execute($statement);
    mysqli_stmt_close($statement);
    redirect_with_flash('index.php', $updated ? 'success' : 'error', $updated ? 'Subject updated successfully.' : 'Unable to update the subject.');
}

$statement = mysqli_prepare($conn, 'SELECT subject_code, subject_name, description FROM subjects WHERE id = ?');
if (!$statement) {
    redirect_with_flash('index.php', 'error', 'Unable to load the subject.');
}
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
$row = mysqli_fetch_assoc($result);
mysqli_stmt_close($statement);
if (!$row) {
    redirect_with_flash('index.php', 'error', 'Subject not found.');
}

render_admin_page_start('subjects', 'Edit Subject', 'Edit subject', 'Update this catalog entry without changing its existing class relationships.', 'Academic Structure');
render_admin_workflow('structure');
render_admin_explainer(
    'Existing classes remain connected',
    'Changing the code, name, or description updates the subject label without removing existing class assignments.'
);
?>
<section class="admin-card admin-edit-card">
    <div class="admin-section-head">
        <div><h2><?= e($row['subject_code']); ?> · <?= e($row['subject_name']); ?></h2><span>Subject record #<?= e($id); ?></span></div>
        <a class="admin-button secondary small" href="index.php"><?= study_icon('arrow-left'); ?> Back to subjects</a>
    </div>
    <form class="admin-form admin-form-wide" method="POST">
        <?= csrf_field(); ?>
        <input type="hidden" name="id" value="<?= e($id); ?>">
        <div class="admin-form-grid two">
            <div class="admin-field">
                <label for="subject_code">Subject code</label>
                <input id="subject_code" type="text" name="subject_code" value="<?= e($row['subject_code']); ?>" maxlength="20" required>
            </div>
            <div class="admin-field">
                <label for="subject_name">Subject name</label>
                <input id="subject_name" type="text" name="subject_name" value="<?= e($row['subject_name']); ?>" maxlength="100" required>
            </div>
        </div>
        <div class="admin-field">
            <label for="description">Description <span>Optional</span></label>
            <textarea id="description" name="description" rows="5"><?= e($row['description']); ?></textarea>
        </div>
        <div class="admin-form-actions">
            <a class="admin-button secondary" href="index.php">Cancel</a>
            <button class="admin-button primary" type="submit"><?= study_icon('check2'); ?> Save changes</button>
        </div>
    </form>
</section>
<?php render_admin_page_end(); ?>
