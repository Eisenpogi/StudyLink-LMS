<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    redirect_with_flash('index.php', 'error', 'Invalid class assignment.');
}

$usage = mysqli_prepare(
    $conn,
    "SELECT
        (SELECT COUNT(*) FROM learning_materials WHERE class_assignment_id = ?) +
        (SELECT COUNT(*) FROM assignments WHERE class_assignment_id = ?) +
        (SELECT COUNT(*) FROM quizzes WHERE class_assignment_id = ?) +
        (SELECT COUNT(*) FROM attendance_sessions WHERE class_assignment_id = ?) AS related_count"
);
if (!$usage) {
    redirect_with_flash('index.php', 'error', 'Unable to verify whether the class can be deleted.');
}
mysqli_stmt_bind_param($usage, 'iiii', $id, $id, $id, $id);
mysqli_stmt_execute($usage);
$usage_row = mysqli_fetch_assoc(mysqli_stmt_get_result($usage));
mysqli_stmt_close($usage);

if (intval($usage_row['related_count'] ?? 0) > 0) {
    redirect_with_flash(
        'index.php',
        'error',
        'This class was not deleted because it already contains learning materials, assignments, quizzes, or attendance records.'
    );
}

$statement = mysqli_prepare(
    $conn,
    'DELETE FROM class_assignments WHERE id = ?'
);

if (!$statement) {
    error_log('Class assignment delete prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to delete the class assignment.');
}

mysqli_stmt_bind_param($statement, 'i', $id);
$deleted = mysqli_stmt_execute($statement);
$affected = mysqli_stmt_affected_rows($statement);
mysqli_stmt_close($statement);

redirect_with_flash(
    'index.php',
    ($deleted && $affected === 1) ? 'success' : 'error',
    ($deleted && $affected === 1)
        ? 'Class assignment deleted successfully.'
        : 'Class assignment was not deleted because it still has related records.'
);
