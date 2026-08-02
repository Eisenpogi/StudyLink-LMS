<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$id = intval($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
    redirect_with_flash('index.php', 'error', 'Invalid student status request.');
}

$statement = mysqli_prepare(
    $conn,
    "UPDATE users u
     INNER JOIN students st ON st.user_id = u.id
     SET u.status = ?
     WHERE st.id = ? AND u.role = 'student'"
);

if (!$statement) {
    error_log('Student status prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to update the student account.');
}

mysqli_stmt_bind_param($statement, 'si', $status, $id);
$updated = mysqli_stmt_execute($statement);
$affected = mysqli_stmt_affected_rows($statement);
mysqli_stmt_close($statement);

if (!$updated || $affected !== 1) {
    redirect_with_flash('index.php', 'error', 'Student status was not changed. The account may already have that status.');
}

redirect_with_flash(
    'index.php',
    'success',
    $status === 'active'
        ? 'Student account reactivated. Login access is restored.'
        : 'Student account deactivated. Submissions and grades were preserved.'
);
