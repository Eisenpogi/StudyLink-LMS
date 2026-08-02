<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$question_id = intval($_POST['question_id']);
$quiz_id = intval($_POST['quiz_id']);

$question_type = mysqli_real_escape_string($conn, $_POST['question_type']);
$question_text = mysqli_real_escape_string($conn, $_POST['question_text']);
$points = intval($_POST['points']);

$allowed_types = [
    'multiple_choice',
    'true_false',
    'identification',
    'enumeration',
    'essay'
];

if(!in_array($question_type, $allowed_types)){
    die("Invalid question type.");
}

/*
|--------------------------------------------------------------------------
| Security Check
| Make sure this question belongs to the logged-in faculty
|--------------------------------------------------------------------------
*/

$faculty_user_id = intval($_SESSION['user_id']);

$check = mysqli_query($conn,"
    SELECT qq.id
    FROM quiz_questions qq
    INNER JOIN quizzes q ON qq.quiz_id = q.id
    INNER JOIN class_assignments ca ON q.class_assignment_id = ca.id
    INNER JOIN faculty f ON ca.faculty_id = f.id
    WHERE qq.id='$question_id'
    AND q.id='$quiz_id'
    AND f.user_id='$faculty_user_id'
");

if(mysqli_num_rows($check) == 0){
    die("Unauthorized access.");
}

if (!studylink_class_is_writable($conn, studylink_quiz_class_id($conn, $quiz_id))) {
    redirect_with_flash(
        'quiz_builder.php?quiz_id=' . $quiz_id . '&tab=questions',
        'error',
        'This Academic Term is read-only. Quiz questions can no longer be changed.'
    );
}

/*
|--------------------------------------------------------------------------
| Determine correct answer
|--------------------------------------------------------------------------
*/

$correct_answer = "";

if($question_type == 'multiple_choice'){
    $correct_answer = isset($_POST['correct_choice']) 
        ? mysqli_real_escape_string($conn, $_POST['correct_choice']) 
        : "";
}

if($question_type == 'true_false'){
    $correct_answer = mysqli_real_escape_string($conn, $_POST['true_false_answer']);
}

if($question_type == 'identification' || $question_type == 'enumeration'){
    $correct_answer = mysqli_real_escape_string($conn, $_POST['correct_answer']);
}

if($question_type == 'essay'){
    $correct_answer = mysqli_real_escape_string($conn, $_POST['essay_answer']);
}

/*
|--------------------------------------------------------------------------
| Update main question
|--------------------------------------------------------------------------
*/

mysqli_query($conn,"
    UPDATE quiz_questions SET
        question_type='$question_type',
        question_text='$question_text',
        correct_answer='$correct_answer',
        points='$points'
    WHERE id='$question_id'
");

/*
|--------------------------------------------------------------------------
| Rebuild choices
| This keeps choice_order clean for future drag-and-drop support
|--------------------------------------------------------------------------
*/

mysqli_query($conn,"
    DELETE FROM quiz_choices
    WHERE question_id='$question_id'
");

if($question_type == 'multiple_choice' && isset($_POST['choices'])){
    $choice_order = 1;

    foreach($_POST['choices'] as $choice_text){
        $choice_text = trim($choice_text);

        if($choice_text == ''){
            continue;
        }

        $safe_choice = mysqli_real_escape_string($conn, $choice_text);

        $is_correct = 0;

        if($choice_text == $correct_answer){
            $is_correct = 1;
        }

        mysqli_query($conn,"
            INSERT INTO quiz_choices
            (
                question_id,
                choice_text,
                is_correct,
                choice_order
            )
            VALUES
            (
                '$question_id',
                '$safe_choice',
                '$is_correct',
                '$choice_order'
            )
        ");

        $choice_order++;
    }
}

if($question_type == 'true_false'){

    $tf_choices = array("True", "False");
    $choice_order = 1;

    foreach($tf_choices as $choice_text){

        $safe_choice = mysqli_real_escape_string($conn, $choice_text);

        $is_correct = ($choice_text == $correct_answer) ? 1 : 0;

        mysqli_query($conn,"
            INSERT INTO quiz_choices
            (
                question_id,
                choice_text,
                is_correct,
                choice_order
            )
            VALUES
            (
                '$question_id',
                '$safe_choice',
                '$is_correct',
                '$choice_order'
            )
        ");

        $choice_order++;
    }
}

header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=questions");
exit();

?>
