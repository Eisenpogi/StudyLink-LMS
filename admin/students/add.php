<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$fullname = trim($_POST['fullname'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$student_no = trim($_POST['student_no'] ?? '');
$section_id = intval($_POST['section_id'] ?? 0);

if (
    $fullname === '' ||
    $username === '' ||
    strlen($password) < 8 ||
    $student_no === '' ||
    $section_id <= 0
) {
    redirect_with_flash('index.php', 'error', 'Complete all student fields and use a temporary password with at least 8 characters.');
}

$section_check = mysqli_prepare($conn, 'SELECT id FROM sections WHERE id = ? LIMIT 1');
if (!$section_check) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the selected section.');
}
mysqli_stmt_bind_param($section_check, 'i', $section_id);
mysqli_stmt_execute($section_check);
$valid_section = mysqli_fetch_assoc(mysqli_stmt_get_result($section_check));
mysqli_stmt_close($section_check);
if (!$valid_section) {
    redirect_with_flash('index.php', 'error', 'The selected section does not exist.');
}

$duplicate = mysqli_prepare($conn, 'SELECT id FROM students WHERE LOWER(student_no) = LOWER(?) LIMIT 1');
if (!$duplicate) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the student number.');
}
mysqli_stmt_bind_param($duplicate, 's', $student_no);
mysqli_stmt_execute($duplicate);
$student_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
mysqli_stmt_close($duplicate);
if ($student_exists) {
    redirect_with_flash('index.php', 'error', 'That student number already exists.');
}

$password_hash = password_hash($password, PASSWORD_DEFAULT);

mysqli_begin_transaction($conn);

$user_statement = mysqli_prepare(
    $conn,
    "INSERT INTO users (fullname, username, password, role, status)
     VALUES (?, ?, ?, 'student', 'active')"
);

if (!$user_statement) {
    error_log('Student user prepare failed: ' . mysqli_error($conn));
    mysqli_rollback($conn);
    redirect_with_flash('index.php', 'error', 'Unable to create the student account.');
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
    error_log('Student user insert failed: ' . mysqli_stmt_error($user_statement));
    mysqli_stmt_close($user_statement);
    mysqli_rollback($conn);
    redirect_with_flash(
        'index.php',
        'error',
        $error_code === 1062
            ? 'Username or student number already exists.'
            : 'Unable to create the student account.'
    );
}

$user_id = mysqli_insert_id($conn);
mysqli_stmt_close($user_statement);

$student_statement = mysqli_prepare(
    $conn,
    'INSERT INTO students (user_id, student_no, section_id)
     VALUES (?, ?, ?)'
);

if (!$student_statement) {
    error_log('Student profile prepare failed: ' . mysqli_error($conn));
    mysqli_rollback($conn);
    redirect_with_flash('index.php', 'error', 'Unable to create the student account.');
}

mysqli_stmt_bind_param(
    $student_statement,
    'isi',
    $user_id,
    $student_no,
    $section_id
);
$student_saved = mysqli_stmt_execute($student_statement);

if (!$student_saved) {
    error_log(
        'Student profile insert failed: ' .
        mysqli_stmt_error($student_statement)
    );
    mysqli_stmt_close($student_statement);
    mysqli_rollback($conn);
    redirect_with_flash('index.php', 'error', 'Unable to create the student account.');
}

mysqli_stmt_close($student_statement);
mysqli_commit($conn);

redirect_with_flash('index.php', 'success', 'Student account created successfully.');
