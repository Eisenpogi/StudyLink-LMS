<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/academic_term.php';

require_post('index.php');
require_csrf('index.php');

$academic_year_id = intval($_POST['academic_year_id'] ?? $_POST['id'] ?? 0);
$semester_id = intval($_POST['semester_id'] ?? 0);

if ($semester_id <= 0) {
    $current_semester = mysqli_query(
        $conn,
        "SELECT id FROM semesters WHERE status = 'active' ORDER BY id DESC LIMIT 1"
    );
    if ($current_semester) {
        $row = mysqli_fetch_assoc($current_semester);
        $semester_id = intval($row['id'] ?? 0);
    }
}

if ($academic_year_id <= 0 || $semester_id <= 0) {
    redirect_with_flash(
        'index.php',
        'error',
        'Select both an academic year and a semester before setting the current term.'
    );
}

try {
    $term = studylink_activate_academic_term(
        $conn,
        $academic_year_id,
        $semester_id,
        intval($_SESSION['user_id'] ?? 0)
    );
    $class_message = $term['class_count'] === 1
        ? '1 existing class is assigned to this term.'
        : number_format($term['class_count']) . ' existing classes are assigned to this term.';

    redirect_with_flash(
        'index.php',
        'success',
        $term['semester_name'] . ', AY ' . $term['school_year'] .
        ' is now the current academic term. ' . $class_message
    );
} catch (Throwable $exception) {
    error_log('Academic term activation failed: ' . $exception->getMessage());
    redirect_with_flash(
        'index.php',
        'error',
        'Unable to set the current academic term. No term changes were saved.'
    );
}
