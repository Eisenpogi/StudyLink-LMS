<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../config/quiz_engine.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post();
require_csrf();

$faculty_user_id = intval($_SESSION['user_id']);
$quiz_id = intval($_POST['quiz_id'] ?? 0);

if (!quiz_faculty_access($conn, $faculty_user_id, $quiz_id)) {
    http_response_code(403);
    echo 'unauthorized';
    exit();
}
if (!studylink_class_is_writable($conn, studylink_quiz_class_id($conn, $quiz_id))) {
    http_response_code(409);
    echo 'term_read_only';
    exit();
}

if(!isset($_POST['order']) || !is_array($_POST['order']) || !$quiz_id){
    echo "no_order";
    exit();
}

$order = array_values(array_unique(array_map('intval', $_POST['order'])));
$order = array_values(array_filter($order, function ($id) {
    return $id > 0;
}));

$count_statement = mysqli_prepare($conn, '
    SELECT COUNT(*) AS total
    FROM quiz_questions
    WHERE quiz_id = ?
');
mysqli_stmt_bind_param($count_statement, 'i', $quiz_id);
mysqli_stmt_execute($count_statement);
$expected = intval(mysqli_fetch_assoc(mysqli_stmt_get_result($count_statement))['total']);
mysqli_stmt_close($count_statement);

if (count($order) !== $expected) {
    http_response_code(400);
    echo 'invalid_order';
    exit();
}

$update = mysqli_prepare($conn, '
    UPDATE quiz_questions
    SET order_no = ?
    WHERE id = ? AND quiz_id = ?
');

mysqli_begin_transaction($conn);
try {
    foreach ($order as $index => $question_id) {
        $position = $index + 1;
        mysqli_stmt_bind_param($update, 'iii', $position, $question_id, $quiz_id);
        if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) < 0) {
            throw new Exception('Could not update question order.');
        }
    }
    mysqli_commit($conn);
    echo 'success';
} catch (Throwable $error) {
    mysqli_rollback($conn);
    http_response_code(500);
    echo 'save_failed';
}

mysqli_stmt_close($update);
