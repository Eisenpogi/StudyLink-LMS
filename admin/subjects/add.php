<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$subject_code = trim($_POST['subject_code'] ?? '');
$subject_name = trim($_POST['subject_name'] ?? '');
$description = trim($_POST['description'] ?? '');

if ($subject_code === '' || $subject_name === '') {
    redirect_with_flash('index.php', 'error', 'Subject code and name are required.');
}

$subject_code = strtoupper($subject_code);

$duplicate = mysqli_prepare($conn, 'SELECT id FROM subjects WHERE LOWER(subject_code) = LOWER(?) LIMIT 1');
if (!$duplicate) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the subject.');
}
mysqli_stmt_bind_param($duplicate, 's', $subject_code);
mysqli_stmt_execute($duplicate);
$already_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
mysqli_stmt_close($duplicate);

if ($already_exists) {
    redirect_with_flash('index.php', 'error', 'That subject code already exists. Edit the existing subject instead.');
}

$statement = mysqli_prepare(
    $conn,
    'INSERT INTO subjects (subject_code, subject_name, description)
     VALUES (?, ?, ?)'
);

if (!$statement) {
    error_log('Subject prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to add the subject.');
}

mysqli_stmt_bind_param(
    $statement,
    'sss',
    $subject_code,
    $subject_name,
    $description
);
$saved = mysqli_stmt_execute($statement);

if (!$saved) {
    error_log('Subject insert failed: ' . mysqli_stmt_error($statement));
}

mysqli_stmt_close($statement);

redirect_with_flash(
    'index.php',
    $saved ? 'success' : 'error',
    $saved ? 'Subject added successfully.' : 'Unable to add the subject.'
);
