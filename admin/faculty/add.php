<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$fullname = trim($_POST['fullname'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$faculty_code = trim($_POST['faculty_id'] ?? '');

if (
    $fullname === '' ||
    $username === '' ||
    strlen($password) < 8 ||
    $faculty_code === ''
) {
    redirect_with_flash('index.php', 'error', 'Complete all faculty fields and use a temporary password with at least 8 characters.');
}

$duplicate = mysqli_prepare($conn, 'SELECT id FROM faculty WHERE LOWER(faculty_id) = LOWER(?) LIMIT 1');
if (!$duplicate) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the faculty ID.');
}
mysqli_stmt_bind_param($duplicate, 's', $faculty_code);
mysqli_stmt_execute($duplicate);
$faculty_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
mysqli_stmt_close($duplicate);
if ($faculty_exists) {
    redirect_with_flash('index.php', 'error', 'That faculty ID already exists.');
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);

mysqli_begin_transaction($conn);

$user_statement = mysqli_prepare(
    $conn,
    "INSERT INTO users (fullname, username, password, role, status)
     VALUES (?, ?, ?, 'faculty', 'active')"
);

if (!$user_statement) {
    error_log('Faculty user prepare failed: ' . mysqli_error($conn));
    mysqli_rollback($conn);
    redirect_with_flash('index.php', 'error', 'Unable to create the faculty account.');
}

mysqli_stmt_bind_param(
    $user_statement,
    'sss',
    $fullname,
    $username,
    $password_hash
);
$user_saved = mysqli_stmt_execute($user_statement);

if (!$user_saved) {
    $error_code = mysqli_stmt_errno($user_statement);
    error_log('Faculty user insert failed: ' . mysqli_stmt_error($user_statement));
    mysqli_stmt_close($user_statement);
    mysqli_rollback($conn);
    redirect_with_flash(
        'index.php',
        'error',
        $error_code === 1062
            ? 'Username or faculty ID already exists.'
            : 'Unable to create the faculty account.'
    );
}

$user_id = mysqli_insert_id($conn);
mysqli_stmt_close($user_statement);

$faculty_statement = mysqli_prepare(
    $conn,
    'INSERT INTO faculty (user_id, faculty_id) VALUES (?, ?)'
);

if (!$faculty_statement) {
    error_log('Faculty profile prepare failed: ' . mysqli_error($conn));
    mysqli_rollback($conn);
    redirect_with_flash('index.php', 'error', 'Unable to create the faculty account.');
}

mysqli_stmt_bind_param(
    $faculty_statement,
    'is',
    $user_id,
    $faculty_code
);
$faculty_saved = mysqli_stmt_execute($faculty_statement);

if (!$faculty_saved) {
    error_log(
        'Faculty profile insert failed: ' .
        mysqli_stmt_error($faculty_statement)
    );
    mysqli_stmt_close($faculty_statement);
    mysqli_rollback($conn);
    redirect_with_flash('index.php', 'error', 'Unable to create the faculty account.');
}

mysqli_stmt_close($faculty_statement);
mysqli_commit($conn);

redirect_with_flash('index.php', 'success', 'Faculty account created successfully.');
