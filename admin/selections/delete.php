<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect_with_flash('index.php', 'error', 'Invalid section.');
}

$statement = mysqli_prepare($conn, 'DELETE FROM sections WHERE id = ?');

if (!$statement) {
    error_log('Section delete prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to delete the section.');
}

mysqli_stmt_bind_param($statement, 'i', $id);
$deleted = mysqli_stmt_execute($statement);
$affected = mysqli_stmt_affected_rows($statement);
mysqli_stmt_close($statement);

redirect_with_flash(
    'index.php',
    ($deleted && $affected === 1) ? 'success' : 'error',
    ($deleted && $affected === 1)
        ? 'Section deleted successfully.'
        : 'Section was not deleted. It may still be used by a class or student.'
);
