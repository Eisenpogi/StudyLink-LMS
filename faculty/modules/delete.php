<?php
include('../../auth/auth.php');
require_role('faculty');
include('../../config/database.php');
include('../../includes/academic_term.php');
include('../../includes/module_workflow.php');

require_post('/studyLink/faculty/modules.php');
require_csrf('/studyLink/faculty/modules.php');

$faculty_id = studylink_faculty_id($conn, intval($_SESSION['user_id']));
$module_id = intval($_POST['module_id'] ?? 0);
$module = studylink_faculty_module($conn, $module_id, $faculty_id);

if (!$module) {
    http_response_code(403);
    exit('Module not found or unauthorized access.');
}

if (strpos($module['title'], 'Existing Coursework · Class ') === 0) {
    redirect_with_flash('/studyLink/faculty/modules.php', 'error', 'The system Existing Coursework module cannot be deleted. Move its items into another module instead.');
}

$stmt = mysqli_prepare($conn, '
    SELECT COUNT(*) AS writable_classes
    FROM course_module_classes cmc
    INNER JOIN class_assignments ca ON ca.id = cmc.class_assignment_id
    INNER JOIN academic_terms term
        ON term.academic_year_id = ca.academic_year_id
       AND term.semester_id = ca.semester_id
    WHERE cmc.module_id = ? AND term.status = "current"
');
mysqli_stmt_bind_param($stmt, 'i', $module_id);
mysqli_stmt_execute($stmt);
$term_row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (intval($term_row['writable_classes'] ?? 0) === 0) {
    redirect_with_flash('/studyLink/faculty/module_view.php?id=' . $module_id, 'error', 'Modules from previous or archived Academic Terms are view-only and cannot be deleted.');
}

$stmt = mysqli_prepare($conn, 'DELETE FROM course_modules WHERE id = ? AND faculty_id = ?');
mysqli_stmt_bind_param($stmt, 'ii', $module_id, $faculty_id);
$deleted = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
mysqli_stmt_close($stmt);

if (!$deleted) {
    redirect_with_flash('/studyLink/faculty/module_view.php?id=' . $module_id, 'error', 'The module could not be deleted.');
}

redirect_with_flash(
    '/studyLink/faculty/modules.php',
    'success',
    'Module deleted. Existing activities, quizzes, submissions, attempts, grades, and feedback were preserved.'
);
