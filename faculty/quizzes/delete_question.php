<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$question_id = intval($_POST['question_id'] ?? 0);
$faculty_user_id = intval($_SESSION['user_id']);

$query = mysqli_query($conn,"
    SELECT qq.*
    FROM quiz_questions qq
    INNER JOIN quizzes q ON q.id = qq.quiz_id
    INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
    INNER JOIN faculty f ON f.id = ca.faculty_id
    WHERE qq.id='$question_id'
    AND f.user_id='$faculty_user_id'
");

$question = mysqli_fetch_assoc($query);

if(!$question){
    die("Question not found.");
}

$quiz_id = $question['quiz_id'];

if (!studylink_class_is_writable($conn, studylink_quiz_class_id($conn, $quiz_id))) {
    redirect_with_flash(
        'quiz_builder.php?quiz_id=' . intval($quiz_id) . '&tab=questions',
        'error',
        'This Academic Term is read-only. Quiz questions can no longer be changed.'
    );
}

$delete = mysqli_query($conn,"
    DELETE FROM quiz_questions
    WHERE id='$question_id'
");

if(!$delete){
    die("Database Error: " . mysqli_error($conn));
}

header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=questions");
exit();
?>
