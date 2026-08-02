<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$school_year = trim($_POST['school_year'] ?? '');

if (!preg_match('/^([0-9]{4})-([0-9]{4})$/', $school_year, $matches)) {
    redirect_with_flash('index.php', 'error', 'Use the academic year format YYYY-YYYY, for example 2026-2027.');
}

if (intval($matches[2]) !== intval($matches[1]) + 1) {
    redirect_with_flash('index.php', 'error', 'The ending year must be exactly one year after the starting year.');
}

$duplicate = mysqli_prepare($conn, 'SELECT id FROM academic_years WHERE LOWER(school_year) = LOWER(?) LIMIT 1');
if (!$duplicate) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the academic year.');
}
mysqli_stmt_bind_param($duplicate, 's', $school_year);
mysqli_stmt_execute($duplicate);
$duplicate_result = mysqli_stmt_get_result($duplicate);
$already_exists = mysqli_fetch_assoc($duplicate_result);
mysqli_stmt_close($duplicate);

if ($already_exists) {
    redirect_with_flash('index.php', 'error', 'That academic year already exists. Use Set active instead of adding it again.');
}

$active_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM academic_years WHERE status = 'active'");
$active_row = $active_result ? mysqli_fetch_assoc($active_result) : ['total' => 0];
$status = intval($active_row['total'] ?? 0) === 0 ? 'active' : 'inactive';

$statement = mysqli_prepare(
    $conn,
    'INSERT INTO academic_years (school_year, status) VALUES (?, ?)'
);

if (!$statement) {
    error_log('Academic year prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to add the academic year.');
}

mysqli_stmt_bind_param($statement, 'ss', $school_year, $status);
$saved = mysqli_stmt_execute($statement);

if (!$saved) {
    error_log('Academic year insert failed: ' . mysqli_stmt_error($statement));
}

mysqli_stmt_close($statement);

redirect_with_flash(
    'index.php',
    $saved ? 'success' : 'error',
    $saved
        ? ($status === 'active' ? 'Academic year added and set as current.' : 'Academic year added. Use Set active when it becomes the current year.')
        : 'Unable to add the academic year.'
);
