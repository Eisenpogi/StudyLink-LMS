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
$current_order = intval($question['order_no']);
$new_order = $current_order + 1;

/*
Move down all questions below the duplicated question
para may space yung duplicate.
*/
mysqli_query($conn,"
    UPDATE quiz_questions
    SET order_no = order_no + 1
    WHERE quiz_id='$quiz_id'
    AND order_no > '$current_order'
");

/*
Create duplicated question directly below original.
*/
$question_text = mysqli_real_escape_string($conn, $question['question_text']);
$question_type = mysqli_real_escape_string($conn, $question['question_type']);
$correct_answer = mysqli_real_escape_string($conn, $question['correct_answer']);
$points = $question['points'];

$insert = mysqli_query($conn,"
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
        '$new_order'
    )
");

if(!$insert){
    die("Duplicate Error: " . mysqli_error($conn));
}

$new_question_id = mysqli_insert_id($conn);

/*
Copy choices if question has choices.
*/
$choices = mysqli_query($conn,"
    SELECT *
    FROM quiz_choices
    WHERE question_id='$question_id'
    ORDER BY choice_order ASC, id ASC
");

while($choice = mysqli_fetch_assoc($choices)){

    $choice_text = mysqli_real_escape_string($conn, $choice['choice_text']);
    $choice_order = intval($choice['choice_order']);
    $is_correct = intval($choice['is_correct']);

    mysqli_query($conn,"
        INSERT INTO quiz_choices
        (
            question_id,
            choice_text,
            choice_order,
            is_correct
        )
        VALUES
        (
            '$new_question_id',
            '$choice_text',
            '$choice_order',
            '$is_correct'
        )
    ");
}

header("Location: edit_question.php?id=".$new_question_id);
exit();
?>
