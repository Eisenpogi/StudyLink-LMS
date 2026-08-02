<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_with_flash('index.php', 'error', 'Invalid student account.');
}

$sections_result = mysqli_query($conn, 'SELECT id, section_name, course, year_level FROM sections ORDER BY course, year_level, section_name');
$sections = $sections_result ? mysqli_fetch_all($sections_result, MYSQLI_ASSOC) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('index.php');

    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $student_no = trim($_POST['student_no'] ?? '');
    $section_id = intval($_POST['section_id'] ?? 0);

    if ($fullname === '' || $username === '' || $student_no === '' || $section_id <= 0) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Complete all student account fields.');
    }

    $section_check = mysqli_prepare($conn, 'SELECT id FROM sections WHERE id = ? LIMIT 1');
    if (!$section_check) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Unable to validate the selected section.');
    }
    mysqli_stmt_bind_param($section_check, 'i', $section_id);
    mysqli_stmt_execute($section_check);
    $valid_section = mysqli_fetch_assoc(mysqli_stmt_get_result($section_check));
    mysqli_stmt_close($section_check);
    if (!$valid_section) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'The selected section does not exist.');
    }

    $duplicate = mysqli_prepare(
        $conn,
        'SELECT st.id
         FROM students st
         INNER JOIN users u ON u.id = st.user_id
         WHERE st.id <> ? AND (LOWER(st.student_no) = LOWER(?) OR LOWER(u.username) = LOWER(?))
         LIMIT 1'
    );
    if (!$duplicate) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Unable to validate the student account.');
    }
    mysqli_stmt_bind_param($duplicate, 'iss', $id, $student_no, $username);
    mysqli_stmt_execute($duplicate);
    $already_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
    mysqli_stmt_close($duplicate);
    if ($already_exists) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'The student number or username is already used by another account.');
    }

    mysqli_begin_transaction($conn);
    $student_update = mysqli_prepare($conn, 'UPDATE students SET student_no = ?, section_id = ? WHERE id = ?');
    $user_update = mysqli_prepare(
        $conn,
        "UPDATE users u
         INNER JOIN students st ON st.user_id = u.id
         SET u.fullname = ?, u.username = ?
         WHERE st.id = ? AND u.role = 'student'"
    );

    $updated = false;
    if ($student_update && $user_update) {
        mysqli_stmt_bind_param($student_update, 'sii', $student_no, $section_id, $id);
        $student_saved = mysqli_stmt_execute($student_update);
        mysqli_stmt_bind_param($user_update, 'ssi', $fullname, $username, $id);
        $user_saved = mysqli_stmt_execute($user_update);
        $updated = $student_saved && $user_saved;
    }
    if ($student_update) {
        mysqli_stmt_close($student_update);
    }
    if ($user_update) {
        mysqli_stmt_close($user_update);
    }

    if (!$updated) {
        mysqli_rollback($conn);
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Unable to update the student account.');
    }

    mysqli_commit($conn);
    redirect_with_flash('index.php', 'success', 'Student account and section updated successfully.');
}

$statement = mysqli_prepare(
    $conn,
    "SELECT st.student_no, st.section_id, u.fullname, u.username
     FROM students st
     INNER JOIN users u ON u.id = st.user_id
     WHERE st.id = ? AND u.role = 'student'"
);
if (!$statement) {
    redirect_with_flash('index.php', 'error', 'Unable to load the student account.');
}
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$row) {
    redirect_with_flash('index.php', 'error', 'Student account not found.');
}

render_admin_page_start('students', 'Edit Student', 'Edit student account', 'Correct account details or move the student to another official section.', 'User Management');
render_admin_workflow('accounts');
render_admin_explainer(
    'Moving a student',
    'Changing the section changes which section-based classes the student can access. Existing submissions and grades are preserved.'
);
?>
<section class="admin-card admin-edit-card">
    <div class="admin-section-head">
        <div><h2><?= e($row['fullname']); ?></h2><span>Student record #<?= e($id); ?></span></div>
        <a class="admin-button secondary small" href="index.php"><?= study_icon('arrow-left'); ?> Back to students</a>
    </div>
    <form class="admin-form admin-form-wide" method="POST">
        <?= csrf_field(); ?>
        <input type="hidden" name="id" value="<?= e($id); ?>">
        <div class="admin-form-grid two">
            <div class="admin-field">
                <label for="student_no">Student number</label>
                <input id="student_no" name="student_no" value="<?= e($row['student_no']); ?>" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="fullname">Full name</label>
                <input id="fullname" name="fullname" value="<?= e($row['fullname']); ?>" maxlength="100" required>
            </div>
            <div class="admin-field">
                <label for="username">Username</label>
                <input id="username" name="username" value="<?= e($row['username']); ?>" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="section_id">Official section</label>
                <select id="section_id" name="section_id" required>
                    <?php foreach ($sections as $section): ?>
                        <option value="<?= e($section['id']); ?>" <?= intval($row['section_id']) === intval($section['id']) ? 'selected' : ''; ?>>
                            <?= e($section['section_name']); ?> · <?= e($section['course']); ?> Year <?= e($section['year_level']); ?>
                        </option>
                    <?php endforeach; ?>
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
