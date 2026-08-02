<?php

include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/academic_term.php';

require_post('../academic_year/index.php');
require_csrf('../academic_year/index.php');

$semester_id = intval($_POST['semester_id'] ?? $_POST['id'] ?? 0);
$academic_year_id = intval($_POST['academic_year_id'] ?? 0);

if ($academic_year_id <= 0) {
    $current_year = mysqli_query(
        $conn,
        "SELECT id FROM academic_years WHERE status = 'active' ORDER BY id DESC LIMIT 1"
    );
    if ($current_year) {
        $row = mysqli_fetch_assoc($current_year);
        $academic_year_id = intval($row['id'] ?? 0);
    }
}

if ($academic_year_id <= 0 || $semester_id <= 0) {
    redirect_with_flash(
        '../academic_year/index.php',
        'error',
        'Select both an academic year and a semester before setting the current term.'
    );
}

try {
    $term = studylink_activate_academic_term($conn, $academic_year_id, $semester_id);
    redirect_with_flash(
        '../academic_year/index.php',
        'success',
        $term['semester_name'] . ', AY ' . $term['school_year'] .
        ' is now the current academic term.'
    );
} catch (Throwable $exception) {
    error_log('Academic term compatibility activation failed: ' . $exception->getMessage());
    redirect_with_flash(
        '../academic_year/index.php',
        'error',
        'Unable to set the current academic term. No term changes were saved.'
    );
}
