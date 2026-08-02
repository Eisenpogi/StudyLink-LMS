<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/academic_term.php';

require_post('index.php');
require_csrf('index.php');

$faculty_id = intval($_POST['faculty_id'] ?? 0);
$subject_id = intval($_POST['subject_id'] ?? 0);
$section_id = intval($_POST['section_id'] ?? 0);
$academic_year_id = intval($_POST['academic_year_id'] ?? 0);
$semester_id = intval($_POST['semester_id'] ?? 0);

if (
    $faculty_id <= 0 ||
    $subject_id <= 0 ||
    $section_id <= 0 ||
    $academic_year_id <= 0 ||
    $semester_id <= 0
) {
    redirect_with_flash('index.php', 'error', 'Complete all class assignment fields.');
}

$current_term = studylink_current_term($conn);
if (
    !$current_term ||
    intval($current_term['academic_year_id']) !== $academic_year_id ||
    intval($current_term['semester_id']) !== $semester_id
) {
    redirect_with_flash(
        'index.php',
        'error',
        'The Current Academic Term changed or is not configured. Refresh the page before creating the class.'
    );
}

$reference_check = mysqli_prepare(
    $conn,
    "SELECT
        EXISTS(SELECT 1 FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE f.id = ? AND u.status = 'active') AS valid_faculty,
        EXISTS(SELECT 1 FROM subjects WHERE id = ?) AS valid_subject,
        EXISTS(SELECT 1 FROM sections WHERE id = ?) AS valid_section,
        EXISTS(SELECT 1 FROM academic_years WHERE id = ?) AS valid_year,
        EXISTS(SELECT 1 FROM semesters WHERE id = ?) AS valid_semester"
);
if (!$reference_check) {
    redirect_with_flash('index.php', 'error', 'Unable to validate the selected class records.');
}
mysqli_stmt_bind_param($reference_check, 'iiiii', $faculty_id, $subject_id, $section_id, $academic_year_id, $semester_id);
mysqli_stmt_execute($reference_check);
$references = mysqli_fetch_assoc(mysqli_stmt_get_result($reference_check));
mysqli_stmt_close($reference_check);

if (
    !$references ||
    !intval($references['valid_faculty']) ||
    !intval($references['valid_subject']) ||
    !intval($references['valid_section']) ||
    !intval($references['valid_year']) ||
    !intval($references['valid_semester'])
) {
    redirect_with_flash('index.php', 'error', 'One or more selected records are unavailable. Refresh the page and select valid options.');
}

$duplicate = mysqli_prepare(
    $conn,
    'SELECT id FROM class_assignments
     WHERE subject_id = ? AND section_id = ? AND academic_year_id = ? AND semester_id = ?
     LIMIT 1'
);
if (!$duplicate) {
    redirect_with_flash('index.php', 'error', 'Unable to check for a duplicate class assignment.');
}
mysqli_stmt_bind_param($duplicate, 'iiii', $subject_id, $section_id, $academic_year_id, $semester_id);
mysqli_stmt_execute($duplicate);
$already_exists = mysqli_fetch_assoc(mysqli_stmt_get_result($duplicate));
mysqli_stmt_close($duplicate);

if ($already_exists) {
    redirect_with_flash('index.php', 'error', 'This subject is already assigned to that section for the selected academic term.');
}

$statement = mysqli_prepare(
    $conn,
    'INSERT INTO class_assignments (
        faculty_id,
        subject_id,
        section_id,
        academic_year_id,
        semester_id
    ) VALUES (?, ?, ?, ?, ?)'
);

if (!$statement) {
    error_log('Class assignment prepare failed: ' . mysqli_error($conn));
    redirect_with_flash('index.php', 'error', 'Unable to create the class assignment.');
}

mysqli_stmt_bind_param(
    $statement,
    'iiiii',
    $faculty_id,
    $subject_id,
    $section_id,
    $academic_year_id,
    $semester_id
);
$saved = mysqli_stmt_execute($statement);

if (!$saved) {
    error_log(
        'Class assignment insert failed: ' .
        mysqli_stmt_error($statement)
    );
}

mysqli_stmt_close($statement);

redirect_with_flash(
    'index.php',
    $saved ? 'success' : 'error',
    $saved
        ? 'Class assignment created successfully.'
        : 'Unable to create the class assignment.'
);
