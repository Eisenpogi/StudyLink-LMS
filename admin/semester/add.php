<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$semester_name = trim($_POST['semester_name'] ?? '');

$allowed_semesters = ['1st Semester', '2nd Semester', 'Summer'];

if (!in_array($semester_name, $allowed_semesters, true)) {
    redirect_with_flash('index.php', 'error', 'Select a valid semester option.');
}

$duplicate = mysqli_prepare($conn, 'SELECT id FROM semesters WHERE LOWER(semester_name) = LOWER(?) LIMIT 1');
if (!$duplicate) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the semester.');
}
mysqli_stmt_bind_param($duplicate, 's', $semester_name);
mysqli_stmt_execute($duplicate);
$duplicate_result = mysqli_stmt_get_result($duplicate);
$already_exists = mysqli_fetch_assoc($duplicate_result);
mysqli_stmt_close($duplicate);

if ($already_exists) {
    redirect_with_flash('index.php', 'error', 'That semester already exists. Use Set active instead of adding it again.');
}

$active_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM semesters WHERE status = 'active'");
$active_row = $active_result ? mysqli_fetch_assoc($active_result) : ['total' => 0];
$status = intval($active_row['total'] ?? 0) === 0 ? 'active' : 'inactive';

$statement = mysqli_prepare(
    $conn,
    'INSERT INTO semesters (semester_name, status) VALUES (?, ?)'
);

if (!$statement) {
    error_log('Semester prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to add the semester.');
}

mysqli_stmt_bind_param($statement, 'ss', $semester_name, $status);
$saved = mysqli_stmt_execute($statement);

if (!$saved) {
    error_log('Semester insert failed: ' . mysqli_stmt_error($statement));
}

mysqli_stmt_close($statement);

redirect_with_flash(
    'index.php',
    $saved ? 'success' : 'error',
    $saved
        ? ($status === 'active' ? 'Semester added and set as current.' : 'Semester added. Use Set active when it becomes the current semester.')
        : 'Unable to add the semester.'
);
