<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../config/quiz_engine.php');
include('../../includes/academic_term.php');
include('../../includes/module_workflow.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$quiz_id = intval($_POST['quiz_id'] ?? 0);
$faculty_user_id = intval($_SESSION['user_id']);

$errors = array();

$quiz = quiz_faculty_access($conn, $faculty_user_id, $quiz_id);

if(!$quiz){
    die("Quiz not found or unauthorized access.");
}
if (!studylink_class_is_writable($conn, intval($quiz['class_assignment_id']))) {
    redirect_with_flash(
        'quiz_builder.php?quiz_id=' . $quiz_id . '&tab=questions',
        'error',
        'This Academic Term is read-only. The quiz can no longer be published or changed.'
    );
}

/* Rule 1: Title */
if(trim($quiz['title']) == ''){
    $errors[] = "Quiz title is required.";
}

/* Rule 2: Questions count */
$questions_query = mysqli_query($conn,"
    SELECT *
    FROM quiz_questions
    WHERE quiz_id='$quiz_id'
    ORDER BY order_no ASC, id ASC
");

if(mysqli_num_rows($questions_query) == 0){
    $errors[] = "Quiz must have at least one question.";
}

$question_number = 1;

while($question = mysqli_fetch_assoc($questions_query)){

    $question_id = intval($question['id']);
    $question_type = $question['question_type'];

    /* Rule 3: Question text */
    if(trim($question['question_text']) == ''){
        $errors[] = "Question ".$question_number." has no question text.";
    }

    /* Rule 4: Points */
    if(floatval($question['points']) <= 0){
        $errors[] = "Question ".$question_number." must have points greater than 0.";
    }

    /* Rule 5: Multiple Choice */
    if($question_type == 'multiple_choice'){

        $choice_count_query = mysqli_query($conn,"
            SELECT COUNT(*) AS total
            FROM quiz_choices
            WHERE question_id='$question_id'
        ");

        $choice_count = mysqli_fetch_assoc($choice_count_query);

        if(intval($choice_count['total']) < 2){
            $errors[] = "Question ".$question_number." must have at least 2 choices.";
        }

        $correct_query = mysqli_query($conn,"
            SELECT COUNT(*) AS total
            FROM quiz_choices
            WHERE question_id='$question_id'
            AND is_correct='1'
        ");

        $correct = mysqli_fetch_assoc($correct_query);

        if(intval($correct['total']) == 0){
            $errors[] = "Question ".$question_number." has no correct answer.";
        }
    }

    /* Rule 6: True/False */
    if($question_type == 'true_false'){

        $correct_query = mysqli_query($conn,"
            SELECT COUNT(*) AS total
            FROM quiz_choices
            WHERE question_id='$question_id'
            AND is_correct='1'
        ");

        $correct = mysqli_fetch_assoc($correct_query);

        if(intval($correct['total']) == 0){
            $errors[] = "Question ".$question_number." has no correct answer.";
        }
    }

    /* Rule 7: Identification / Enumeration */
    if($question_type == 'identification' || $question_type == 'enumeration'){
        if(trim($question['correct_answer']) == ''){
            $errors[] = "Question ".$question_number." needs a correct answer.";
        }
    }

    $question_number++;
}

/* Rule 8: Passing Score */
if(intval($quiz['passing_score']) < 0 || intval($quiz['passing_score']) > 100){
    $errors[] = "Passing score must be between 0 and 100.";
}

/* Rule 9: Attempt and timer settings */
if (intval($quiz['max_attempts']) < 1) {
    $errors[] = "Maximum attempts must be at least 1.";
}

if (intval($quiz['time_limit']) < 0) {
    $errors[] = "Time limit cannot be negative.";
}

/* Rule 10: Availability */
if(!empty($quiz['available_from']) && !empty($quiz['available_until'])){
    if(strtotime($quiz['available_from']) >= strtotime($quiz['available_until'])){
        $errors[] = "Available Until must be later than Available From.";
    }
}

/* If errors, send back to builder */
if(count($errors) > 0){

    $_SESSION['publish_errors'] = $errors;

    header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=questions&publish_failed=1");
    exit();
}

/* Passed validation */
$group_publish = studylink_publish_module_quiz_group($conn, $quiz_id);
if ($group_publish < 0) {
    $_SESSION['publish_errors'] = ['This multi-class quiz could not be synchronized because another class already has attempts or its copy could not be updated.'];
    header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=questions&publish_failed=1");
    exit();
}
$publish = mysqli_prepare($conn, 'UPDATE quizzes SET status = "published" WHERE id = ?');
mysqli_stmt_bind_param($publish, 'i', $quiz_id);
mysqli_stmt_execute($publish);
mysqli_stmt_close($publish);

header("Location: quiz_builder.php?quiz_id=".$quiz_id."&tab=questions&published=1");
exit();

?>
