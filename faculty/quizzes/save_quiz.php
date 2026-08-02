<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$quiz_id = intval($_POST['quiz_id']);
$faculty_user_id = intval($_SESSION['user_id']);

$title = mysqli_real_escape_string($conn, $_POST['title']);
$instructions = mysqli_real_escape_string($conn, $_POST['instructions']);
$time_limit = intval($_POST['time_limit']);
$max_attempts = intval($_POST['max_attempts']);
$passing_score = intval($_POST['passing_score']);
$shuffle_questions = isset($_POST['shuffle_questions']) ? 1 : 0;

$available_from = !empty($_POST['available_from'])
    ? "'" . mysqli_real_escape_string($conn, $_POST['available_from']) . "'"
    : "NULL";

$available_until = !empty($_POST['available_until'])
    ? "'" . mysqli_real_escape_string($conn, $_POST['available_until']) . "'"
    : "NULL";

if($status != 'draft' && $status != 'published'){
    $status = 'draft';
}

$check = mysqli_query($conn,"
    SELECT q.id
    FROM quizzes q
    INNER JOIN class_assignments ca ON q.class_assignment_id = ca.id
    INNER JOIN faculty f ON ca.faculty_id = f.id
    WHERE q.id='$quiz_id'
    AND f.user_id='$faculty_user_id'
");

if(mysqli_num_rows($check) == 0){
    die("Unauthorized access.");
}

if (!studylink_class_is_writable($conn, studylink_quiz_class_id($conn, $quiz_id))) {
    redirect_with_flash(
        'quiz_builder.php?quiz_id=' . $quiz_id . '&tab=settings',
        'error',
        'This Academic Term is read-only. Quiz settings can no longer be changed.'
    );
}

mysqli_query($conn,"
    UPDATE quizzes SET
        title='$title',
        instructions='$instructions',
        time_limit='$time_limit',
        max_attempts='$max_attempts',
        passing_score='$passing_score',
        available_from=$available_from,
        available_until=$available_until,
        shuffle_questions='$shuffle_questions'
    WHERE id='$quiz_id'
");

header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=settings&saved=1");
exit();

?>
