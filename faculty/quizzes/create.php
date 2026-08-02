<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../config/quiz_engine.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post('/studyLink/faculty/classes.php');
require_csrf('/studyLink/faculty/classes.php');

$class_assignment_id = intval($_POST['class_assignment_id'] ?? 0);
$title = trim((string) ($_POST['title'] ?? ''));
$instructions = trim((string) ($_POST['instructions'] ?? ''));
$time_limit = max(0, intval($_POST['time_limit'] ?? 0));
$available_from_input = trim((string) ($_POST['available_from'] ?? ''));
$available_until_input = trim((string) ($_POST['available_until'] ?? ''));
$faculty_user_id = intval($_SESSION['user_id']);

$ownership = mysqli_prepare($conn, '
    SELECT ca.id
    FROM class_assignments ca
    INNER JOIN faculty f ON f.id = ca.faculty_id
    WHERE ca.id = ? AND f.user_id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($ownership, 'ii', $class_assignment_id, $faculty_user_id);
mysqli_stmt_execute($ownership);
$owned_class = mysqli_fetch_assoc(mysqli_stmt_get_result($ownership));
mysqli_stmt_close($ownership);

if (!$owned_class || $title === '') {
    http_response_code(403);
    exit('Invalid class or quiz title.');
}

if (!studylink_class_is_writable($conn, $class_assignment_id)) {
    redirect_with_flash(
        '/studyLink/faculty/class_view.php?id=' . $class_assignment_id . '&tab=quizzes',
        'error',
        'This Academic Term is read-only. Quizzes can only be created in the current term.'
    );
}

$available_from = null;
$available_until = null;
if ($available_from_input !== '') {
    $timestamp = strtotime($available_from_input);
    if ($timestamp === false) {
        exit('Invalid quiz opening date.');
    }
    $available_from = date('Y-m-d H:i:s', $timestamp);
}
if ($available_until_input !== '') {
    $timestamp = strtotime($available_until_input);
    if ($timestamp === false) {
        exit('Invalid quiz deadline.');
    }
    $available_until = date('Y-m-d H:i:s', $timestamp);
}
if ($available_from && $available_until && strtotime($available_from) >= strtotime($available_until)) {
    exit('Quiz deadline must be later than the opening date.');
}

$insert = mysqli_prepare($conn, '
    INSERT INTO quizzes (class_assignment_id, title, instructions, time_limit, available_from, available_until)
    VALUES (?, ?, ?, ?, ?, ?)
');
mysqli_stmt_bind_param($insert, 'ississ', $class_assignment_id, $title, $instructions, $time_limit, $available_from, $available_until);
if (!mysqli_stmt_execute($insert)) {
    mysqli_stmt_close($insert);
    exit('The quiz could not be created.');
}
$quiz_id = mysqli_insert_id($conn);
mysqli_stmt_close($insert);

header("Location: /studyLink/faculty/quizzes/quiz_builder.php?quiz_id=".$quiz_id."&tab=questions");
exit();
