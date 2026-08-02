<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../config/quiz_engine.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$quiz_id = intval($_POST['quiz_id'] ?? 0);
$class_id = intval($_POST['class_id'] ?? 0);
$faculty_user_id = intval($_SESSION['user_id']);

$quiz = quiz_faculty_access($conn, $faculty_user_id, $quiz_id);
if (!$quiz || intval($quiz['class_assignment_id']) !== $class_id) {
    die("Unauthorized access.");
}

if (!studylink_class_is_writable($conn, $class_id)) {
    redirect_with_flash(
        '/studyLink/faculty/class_view.php?id=' . $class_id . '&tab=quizzes',
        'error',
        'This Academic Term is read-only. Existing quizzes and attempts are preserved.'
    );
}

$delete = mysqli_prepare($conn, 'DELETE FROM quizzes WHERE id = ?');
mysqli_stmt_bind_param($delete, 'i', $quiz_id);
$deleted = mysqli_stmt_execute($delete);
mysqli_stmt_close($delete);

if(!$deleted){
    die("The quiz could not be deleted.");
}

header("Location: /studyLink/faculty/class_view.php?id=".$class_id."&tab=quizzes");
exit();
