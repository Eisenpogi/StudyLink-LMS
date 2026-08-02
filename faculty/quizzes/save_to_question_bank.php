<?php

include('../../auth/auth.php');
include('../../config/database.php');

if($_SESSION['role'] != 'faculty'){
    header("Location: ../index.php");
    exit();
}

if(!isset($_GET['id'])){
    die("Question ID is missing.");
}

$question_id = intval($_GET['id']);
$faculty_user_id = intval($_SESSION['user_id']);

$question_query = mysqli_query($conn,"
    SELECT qq.*, q.id AS quiz_id, f.id AS faculty_id
    FROM quiz_questions qq
    INNER JOIN quizzes q ON qq.quiz_id = q.id
    INNER JOIN class_assignments ca ON q.class_assignment_id = ca.id
    INNER JOIN faculty f ON ca.faculty_id = f.id
    WHERE qq.id='$question_id'
    AND f.user_id='$faculty_user_id'
");

$question = mysqli_fetch_assoc($question_query);

if(!$question){
    die("Question not found or unauthorized access.");
}

$quiz_id = intval($question['quiz_id']);
$faculty_id = intval($question['faculty_id']);

$question_text = mysqli_real_escape_string($conn, $question['question_text']);
$question_type = mysqli_real_escape_string($conn, $question['question_type']);
$points = floatval($question['points']);
$correct_answer = mysqli_real_escape_string($conn, $question['correct_answer']);

$insert_question = mysqli_query($conn,"
    INSERT INTO question_bank_questions
    (
        faculty_id,
        question_text,
        question_type,
        points,
        correct_answer
    )
    VALUES
    (
        '$faculty_id',
        '$question_text',
        '$question_type',
        '$points',
        '$correct_answer'
    )
");

if(!$insert_question){
    die("Question Bank Error: " . mysqli_error($conn));
}

$bank_question_id = mysqli_insert_id($conn);

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
        INSERT INTO question_bank_choices
        (
            bank_question_id,
            choice_text,
            choice_order,
            is_correct
        )
        VALUES
        (
            '$bank_question_id',
            '$choice_text',
            '$choice_order',
            '$is_correct'
        )
    ");
}

header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=questions&bank_saved=1");
exit();

?>