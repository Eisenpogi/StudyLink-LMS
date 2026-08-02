<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$id = intval($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';

if ($id <= 0 || !in_array($status, ['active', 'inactive'], true)) {
    redirect_with_flash('index.php', 'error', 'Invalid faculty status request.');
}

$statement = mysqli_prepare(
    $conn,
    "UPDATE users u
     INNER JOIN faculty f ON f.user_id = u.id
     SET u.status = ?
     WHERE f.id = ? AND u.role = 'faculty'"
);

if (!$statement) {
    error_log('Faculty status prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to update the faculty account.');
}

mysqli_stmt_bind_param($statement, 'si', $status, $id);
$updated = mysqli_stmt_execute($statement);
$affected = mysqli_stmt_affected_rows($statement);
mysqli_stmt_close($statement);

if (!$updated || $affected !== 1) {
    redirect_with_flash('index.php', 'error', 'Faculty status was not changed. The account may already have that status.');
}

redirect_with_flash(
    'index.php',
    'success',
    $status === 'active'
        ? 'Faculty account reactivated. Login access is restored.'
        : 'Faculty account deactivated. Records were preserved and login access is blocked.'
);
