<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';

require_post('index.php');
require_csrf('index.php');

$section_name = trim($_POST['section_name'] ?? '');
$course = trim($_POST['course'] ?? '');
$year_level = intval($_POST['year_level'] ?? 0);

if (
    $section_name === '' ||
    $course === '' ||
    $year_level < 1 ||
    $year_level > 5
) {
    redirect_with_flash('index.php', 'error', 'Complete all section fields correctly.');
}

$duplicate = mysqli_prepare(
    $conn,
    'SELECT id FROM sections
     WHERE LOWER(section_name) = LOWER(?) OR (LOWER(course) = LOWER(?) AND year_level = ? AND LOWER(section_name) = LOWER(?))
     LIMIT 1'
);
if (!$duplicate) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the section.');
}
mysqli_stmt_bind_param($duplicate, 'ssis', $section_name, $course, $year_level, $section_name);
mysqli_stmt_execute($duplicate);
$already_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
mysqli_stmt_close($duplicate);

if ($already_exists) {
    redirect_with_flash('index.php', 'error', 'That section already exists. Edit the existing record instead.');
}

$statement = mysqli_prepare(
    $conn,
    'INSERT INTO sections (section_name, course, year_level)
     VALUES (?, ?, ?)'
);

if (!$statement) {
    error_log('Section prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to add the section.');
}

mysqli_stmt_bind_param(
    $statement,
    'ssi',
    $section_name,
    $course,
    $year_level
);
$saved = mysqli_stmt_execute($statement);

if (!$saved) {
    error_log('Section insert failed: ' . mysqli_stmt_error($statement));
}

mysqli_stmt_close($statement);

redirect_with_flash(
    'index.php',
    $saved ? 'success' : 'error',
    $saved ? 'Section added successfully.' : 'Unable to add the section.'
);
