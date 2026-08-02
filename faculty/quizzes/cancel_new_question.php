<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$question_id = intval($_POST['question_id'] ?? 0);
$quiz_id = intval($_POST['quiz_id'] ?? 0);
$faculty_user_id = intval($_SESSION['user_id']);

$check = mysqli_prepare($conn, '
    SELECT qq.id
    FROM quiz_questions qq
    INNER JOIN quizzes q ON q.id = qq.quiz_id
    INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
    INNER JOIN faculty f ON f.id = ca.faculty_id
    WHERE qq.id = ? AND qq.quiz_id = ? AND f.user_id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($check, 'iii', $question_id, $quiz_id, $faculty_user_id);
mysqli_stmt_execute($check);
$owned_question = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
mysqli_stmt_close($check);

if (!$owned_question) {
    http_response_code(403);
    exit('Question not found or unauthorized access.');
}

if (!studylink_class_is_writable($conn, studylink_quiz_class_id($conn, $quiz_id))) {
    redirect_with_flash(
        'quiz_builder.php?quiz_id=' . $quiz_id . '&tab=questions',
        'error',
        'This Academic Term is read-only. Quiz questions can no longer be changed.'
    );
}

$delete = mysqli_prepare($conn, '
    DELETE FROM quiz_questions
    WHERE id = ? AND quiz_id = ?
');
mysqli_stmt_bind_param($delete, 'ii', $question_id, $quiz_id);
$deleted = mysqli_stmt_execute($delete);
mysqli_stmt_close($delete);

if (!$deleted) {
    http_response_code(500);
    exit('The new question could not be cancelled.');
}

redirect_to('quiz_builder.php?quiz_id=' . $quiz_id . '&tab=questions');
