<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../config/quiz_engine.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$quiz_id = intval($_POST['quiz_id'] ?? 0);
$faculty_user_id = intval($_SESSION['user_id']);

$quiz = quiz_faculty_access($conn, $faculty_user_id, $quiz_id);
if (!$quiz) {
    die("Quiz not found or unauthorized access.");
}
if (!studylink_class_is_writable($conn, intval($quiz['class_assignment_id']))) {
    redirect_with_flash(
        'quiz_builder.php?quiz_id=' . $quiz_id . '&tab=questions',
        'error',
        'This Academic Term is read-only. The quiz can no longer be unpublished or changed.'
    );
}

$update = mysqli_prepare($conn, 'UPDATE quizzes SET status = "draft" WHERE id = ?');
mysqli_stmt_bind_param($update, 'i', $quiz_id);
$updated = mysqli_stmt_execute($update);
mysqli_stmt_close($update);

if(!$updated){
    die("The quiz could not be unpublished.");
}

header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=questions&unpublished=1");
exit();
