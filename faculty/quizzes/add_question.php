<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../config/quiz_engine.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$quiz_id = intval($_POST['quiz_id']);
$faculty_user_id = intval($_SESSION['user_id']);
if (!quiz_faculty_access($conn, $faculty_user_id, $quiz_id)) {
    http_response_code(403);
    exit('Quiz not found or unauthorized access.');
}
if (!studylink_class_is_writable($conn, studylink_quiz_class_id($conn, $quiz_id))) {
    redirect_with_flash(
        'quiz_builder.php?quiz_id=' . $quiz_id . '&tab=questions',
        'error',
        'This Academic Term is read-only. Quiz questions can no longer be changed.'
    );
}
$question_type = isset($_POST['question_type']) ? mysqli_real_escape_string($conn, $_POST['question_type']) : 'multiple_choice';
$question_text = isset($_POST['question_text']) ? mysqli_real_escape_string($conn, $_POST['question_text']) : 'Untitled Question';
$points = isset($_POST['points']) ? intval($_POST['points']) : 1;

$correct_answer = "";

$order_query = mysqli_query($conn,"
    SELECT MAX(order_no) AS max_order
    FROM quiz_questions
    WHERE quiz_id='$quiz_id'
");

$order_row = mysqli_fetch_assoc($order_query);
$order_no = intval($order_row['max_order']) + 1;

$insert_question = mysqli_query($conn,"
    INSERT INTO quiz_questions
    (
        quiz_id,
        question_text,
        question_type,
        points,
        correct_answer,
        order_no
    )
    VALUES
    (
        '$quiz_id',
        '$question_text',
        '$question_type',
        '$points',
        '$correct_answer',
        '$order_no'
    )
");

if(!$insert_question){
    die("Question Error: " . mysqli_error($conn));
}

$question_id = mysqli_insert_id($conn);

if($question_type == "multiple_choice"){
    mysqli_query($conn,"
        INSERT INTO quiz_choices (question_id, choice_text, choice_order, is_correct)
        VALUES
        ('$question_id', 'Option 1', 1, 1),
        ('$question_id', 'Option 2', 2, 0)
    ");
}

if($question_type == "true_false"){
    mysqli_query($conn,"
        INSERT INTO quiz_choices (question_id, choice_text, choice_order, is_correct)
        VALUES
        ('$question_id', 'True', 1, 1),
        ('$question_id', 'False', 2, 0)
    ");
}

header("Location: edit_question.php?id=".$question_id."&new=1");
exit();

?>
