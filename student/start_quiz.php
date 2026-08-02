<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../config/quiz_engine.php';
include '../includes/academic_term.php';
include '../includes/module_workflow.php';

require_post('/studyLink/student/classes.php');
require_csrf('/studyLink/student/classes.php');

$quiz_id = intval($_POST['quiz_id'] ?? 0);
$user_id = intval($_SESSION['user_id']);
$quiz = quiz_student_access($conn, $user_id, $quiz_id, true);

if (!$quiz) {
    http_response_code(403);
    exit('Quiz not found or you are not authorized to take it.');
}

$module_id = intval($_POST['module_id'] ?? 0);
$module_class_id = intval($_POST['class_id'] ?? 0);
$return_url = '/studyLink/student/class_view.php?id=' . intval($quiz['class_assignment_id']) . '&tab=modules';
if (
    $module_class_id === intval($quiz['class_assignment_id']) &&
    studylink_student_quiz_module_context($conn, $module_id, $module_class_id, intval($quiz['student_id']), $quiz_id)
) {
    $return_url = '/studyLink/student/module_view.php?id=' . $module_id . '&class_id=' . $module_class_id;
} else {
    $module_context = studylink_student_quiz_module_lookup($conn, intval($quiz['student_id']), $quiz_id);
    $module_id = intval($module_context['module_id'] ?? 0);
    $module_class_id = intval($module_context['class_id'] ?? 0);
    if ($module_id > 0) {
        $return_url = '/studyLink/student/module_view.php?id=' . $module_id . '&class_id=' . $module_class_id;
    }
}
$module_query = $module_id > 0 ? '&module_id=' . $module_id . '&class_id=' . $module_class_id : '';

if (!studylink_class_is_writable($conn, intval($quiz['class_assignment_id']))) {
    redirect_with_flash(
        $return_url,
        'error',
        'This Academic Term is read-only. New quiz attempts are disabled.'
    );
}

$now = time();
if (!empty($quiz['available_from']) && strtotime($quiz['available_from']) > $now) {
    redirect_with_flash(
        $return_url,
        'error',
        'This quiz is not available yet.'
    );
}
if (!empty($quiz['available_until']) && strtotime($quiz['available_until']) <= $now) {
    redirect_with_flash(
        $return_url,
        'error',
        'This quiz is already closed.'
    );
}

$student_id = intval($quiz['student_id']);
$active_statement = mysqli_prepare($conn, '
    SELECT id
    FROM quiz_attempts
    WHERE quiz_id = ? AND student_id = ? AND status = "in_progress"
    ORDER BY id DESC
    LIMIT 1
');
mysqli_stmt_bind_param($active_statement, 'ii', $quiz_id, $student_id);
mysqli_stmt_execute($active_statement);
$active = mysqli_fetch_assoc(mysqli_stmt_get_result($active_statement));
mysqli_stmt_close($active_statement);

if ($active) {
    redirect_to('/studyLink/student/take_quiz.php?attempt_id=' . intval($active['id']) . $module_query);
}

$count_statement = mysqli_prepare($conn, '
    SELECT COUNT(*) AS used_attempts, COALESCE(MAX(attempt_no), 0) AS last_attempt
    FROM quiz_attempts
    WHERE quiz_id = ? AND student_id = ? AND status <> "in_progress"
');
mysqli_stmt_bind_param($count_statement, 'ii', $quiz_id, $student_id);
mysqli_stmt_execute($count_statement);
$attempt_count = mysqli_fetch_assoc(mysqli_stmt_get_result($count_statement));
mysqli_stmt_close($count_statement);

if (intval($attempt_count['used_attempts']) >= intval($quiz['max_attempts'])) {
    redirect_with_flash(
        $return_url,
        'error',
        'You have already used all allowed attempts for this quiz.'
    );
}

$question_ids = quiz_question_ids(
    $conn,
    $quiz_id,
    intval($quiz['shuffle_questions']) === 1
);
if (!$question_ids) {
    redirect_with_flash(
        $return_url,
        'error',
        'This quiz has no questions.'
    );
}

$attempt_no = intval($attempt_count['last_attempt']) + 1;
$started_at = date('Y-m-d H:i:s');
$expires_at = intval($quiz['time_limit']) > 0
    ? date('Y-m-d H:i:s', time() + (intval($quiz['time_limit']) * 60))
    : null;
$question_order = json_encode($question_ids);

$insert = mysqli_prepare($conn, '
    INSERT INTO quiz_attempts
        (quiz_id, student_id, attempt_no, started_at, expires_at,
         question_order, status, score, total_points, percentage, is_passed, submitted_at)
    VALUES (?, ?, ?, ?, ?, ?, "in_progress", 0, 0, 0, 0, NULL)
');
mysqli_stmt_bind_param(
    $insert,
    'iiisss',
    $quiz_id,
    $student_id,
    $attempt_no,
    $started_at,
    $expires_at,
    $question_order
);

if (!mysqli_stmt_execute($insert)) {
    mysqli_stmt_close($insert);
    redirect_with_flash(
        $return_url,
        'error',
        'The quiz attempt could not be started. Please try again.'
    );
}

$attempt_id = mysqli_insert_id($conn);
mysqli_stmt_close($insert);
redirect_to('/studyLink/student/take_quiz.php?attempt_id=' . $attempt_id . $module_query);
