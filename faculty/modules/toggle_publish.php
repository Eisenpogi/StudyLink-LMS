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
if (!$module) { http_response_code(403); exit('Module not found.'); }

$class_ids = studylink_module_class_ids($conn, $module_id);
if (!$class_ids || !array_filter($class_ids, function ($class_id) use ($conn) { return studylink_class_is_writable($conn, $class_id); })) {
    studylink_module_redirect($module_id, 'error', 'This module belongs only to read-only Academic Terms.');
}
if ($module['status'] === 'draft' && intval($module['item_count']) === 0) {
    studylink_module_redirect($module_id, 'error', 'Add at least one activity, quiz, or exam before publishing.');
}
if ($module['status'] === 'draft') {
    $stmt = mysqli_prepare($conn, '
        SELECT COUNT(*) AS missing_classes
        FROM course_module_classes cmc
        WHERE cmc.module_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM course_module_resources cmr
              INNER JOIN course_module_items cmi ON cmi.id = cmr.module_item_id
              WHERE cmi.module_id = cmc.module_id
                AND cmr.class_assignment_id = cmc.class_assignment_id
          )
    ');
    mysqli_stmt_bind_param($stmt, 'i', $module_id);
    mysqli_stmt_execute($stmt);
    $coverage = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (intval($coverage['missing_classes'] ?? 0) > 0) {
        studylink_module_redirect($module_id, 'error', 'Every assigned class needs at least one module item before publishing.');
    }

    $stmt = mysqli_prepare($conn, '
        SELECT COUNT(*) AS unpublished_assessments
        FROM course_module_items cmi
        INNER JOIN course_module_resources cmr ON cmr.module_item_id = cmi.id
        INNER JOIN quizzes q ON q.id = cmr.quiz_id
        WHERE cmi.module_id = ? AND q.status <> "published"
    ');
    mysqli_stmt_bind_param($stmt, 'i', $module_id);
    mysqli_stmt_execute($stmt);
    $assessment_status = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (intval($assessment_status['unpublished_assessments'] ?? 0) > 0) {
        studylink_module_redirect($module_id, 'error', 'Publish every quiz and exam in the Quiz Builder before publishing the module.');
    }
}

$new_status = $module['status'] === 'published' ? 'draft' : 'published';
$stmt = mysqli_prepare($conn, 'UPDATE course_modules SET status = ? WHERE id = ? AND faculty_id = ?');
mysqli_stmt_bind_param($stmt, 'sii', $new_status, $module_id, $faculty_id);
$saved = mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);
studylink_module_redirect($module_id, $saved ? 'success' : 'error', $saved ? 'Module status updated to ' . $new_status . '.' : 'The module status could not be updated.');
