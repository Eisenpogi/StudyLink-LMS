<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    redirect_with_flash('index.php', 'error', 'Invalid faculty account.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('index.php');

    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $faculty_id = trim($_POST['faculty_id'] ?? '');

    if ($fullname === '' || $username === '' || $faculty_id === '') {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Complete all faculty account fields.');
    }

    $duplicate = mysqli_prepare(
        $conn,
        'SELECT f.id
         FROM faculty f
         INNER JOIN users u ON u.id = f.user_id
         WHERE f.id <> ? AND (LOWER(f.faculty_id) = LOWER(?) OR LOWER(u.username) = LOWER(?))
         LIMIT 1'
    );
    if (!$duplicate) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Unable to validate the faculty account.');
    }
    mysqli_stmt_bind_param($duplicate, 'iss', $id, $faculty_id, $username);
    mysqli_stmt_execute($duplicate);
    $already_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
    mysqli_stmt_close($duplicate);
    if ($already_exists) {
        redirect_with_flash('edit.php?id=' . $id, 'error', 'The faculty ID or username is already used by another account.');
    }

    mysqli_begin_transaction($conn);
    $faculty_update = mysqli_prepare($conn, 'UPDATE faculty SET faculty_id = ? WHERE id = ?');
    $user_update = mysqli_prepare(
        $conn,
        "UPDATE users u
         INNER JOIN faculty f ON f.user_id = u.id
         SET u.fullname = ?, u.username = ?
         WHERE f.id = ? AND u.role = 'faculty'"
    );

    $updated = false;
    if ($faculty_update && $user_update) {
        mysqli_stmt_bind_param($faculty_update, 'si', $faculty_id, $id);
        $faculty_saved = mysqli_stmt_execute($faculty_update);
        mysqli_stmt_bind_param($user_update, 'ssi', $fullname, $username, $id);
        $user_saved = mysqli_stmt_execute($user_update);
        $updated = $faculty_saved && $user_saved;
    }
    if ($faculty_update) {
        mysqli_stmt_close($faculty_update);
    }
    if ($user_update) {
        mysqli_stmt_close($user_update);
    }

    if (!$updated) {
        mysqli_rollback($conn);
        redirect_with_flash('edit.php?id=' . $id, 'error', 'Unable to update the faculty account.');
    }

    mysqli_commit($conn);
    redirect_with_flash('index.php', 'success', 'Faculty account updated successfully.');
}

$statement = mysqli_prepare(
    $conn,
    "SELECT f.faculty_id, u.fullname, u.username
     FROM faculty f
     INNER JOIN users u ON u.id = f.user_id
     WHERE f.id = ? AND u.role = 'faculty'"
);
if (!$statement) {
    redirect_with_flash('index.php', 'error', 'Unable to load the faculty account.');
}
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$row) {
    redirect_with_flash('index.php', 'error', 'Faculty account not found.');
}

render_admin_page_start('faculty', 'Edit Faculty', 'Edit faculty account', 'Correct faculty profile and login details without affecting assigned classes.', 'User Management');
render_admin_workflow('accounts');
render_admin_explainer(
    'Existing classes are preserved',
    'Updating the faculty name, ID, or username does not change class assignments, materials, grading records, or stored files.'
);
?>
<section class="admin-card admin-edit-card">
    <div class="admin-section-head">
        <div><h2><?= e($row['fullname']); ?></h2><span>Faculty record #<?= e($id); ?></span></div>
        <a class="admin-button secondary small" href="index.php"><?= study_icon('arrow-left'); ?> Back to faculty</a>
    </div>
    <form class="admin-form admin-form-wide" method="POST">
        <?= csrf_field(); ?>
        <input type="hidden" name="id" value="<?= e($id); ?>">
        <div class="admin-form-grid">
            <div class="admin-field">
                <label for="faculty_id">Faculty ID</label>
                <input id="faculty_id" name="faculty_id" value="<?= e($row['faculty_id']); ?>" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="fullname">Full name</label>
                <input id="fullname" name="fullname" value="<?= e($row['fullname']); ?>" maxlength="100" required>
            </div>
            <div class="admin-field">
                <label for="username">Username</label>
                <input id="username" name="username" value="<?= e($row['username']); ?>" maxlength="50" required>
            </div>
        </div>
        <div class="admin-form-actions">
            <a class="admin-button secondary" href="index.php">Cancel</a>
            <button class="admin-button primary" type="submit"><?= study_icon('check2'); ?> Save changes</button>
        </div>
    </form>
</section>
<?php render_admin_page_end(); ?>
