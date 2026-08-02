<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../config/quiz_engine.php');
include('../../includes/academic_term.php');

require_role('faculty');
require_post();
require_csrf();

$quiz_id = intval($_POST['quiz_id'] ?? 0);
$field = (string) ($_POST['field'] ?? '');
$value = (string) ($_POST['value'] ?? '');
$faculty_user_id = intval($_SESSION['user_id']);

$allowed_fields = [
    'title',
    'instructions',
    'time_limit',
    'max_attempts',
    'passing_score',
    'available_from',
    'available_until',
    'shuffle_questions'
];

if(!in_array($field, $allowed_fields, true)){
    echo "invalid_field";
    exit();
}

if (!quiz_faculty_access($conn, $faculty_user_id, $quiz_id)) {
    echo "unauthorized";
    exit();
}
if (!studylink_class_is_writable($conn, studylink_quiz_class_id($conn, $quiz_id))) {
    http_response_code(409);
    echo "term_read_only";
    exit();
}

if(in_array($field, ['time_limit', 'max_attempts', 'passing_score', 'shuffle_questions'], true)){
    $value = intval($value);
}

if ($field === 'time_limit') {
    $value = max(0, $value);
} elseif ($field === 'max_attempts') {
    $value = max(1, $value);
} elseif ($field === 'passing_score') {
    $value = max(0, min(100, $value));
} elseif ($field === 'shuffle_questions') {
    $value = $value === 1 ? 1 : 0;
}

if (in_array($field, ['available_from', 'available_until'], true)) {
    if (trim($value) === '') {
        $statement = mysqli_prepare($conn, "UPDATE quizzes SET $field = NULL WHERE id = ?");
        mysqli_stmt_bind_param($statement, 'i', $quiz_id);
        $saved = mysqli_stmt_execute($statement);
        mysqli_stmt_close($statement);
        echo $saved ? "saved" : "save_failed";
        exit();
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        echo "invalid_date";
        exit();
    }
    $value = date('Y-m-d H:i:s', $timestamp);

    $opposite_field = $field === 'available_from' ? 'available_until' : 'available_from';
    $statement = mysqli_prepare($conn, "SELECT $opposite_field FROM quizzes WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($statement, 'i', $quiz_id);
    mysqli_stmt_execute($statement);
    $opposite_value = mysqli_fetch_assoc(mysqli_stmt_get_result($statement))[$opposite_field] ?? null;
    mysqli_stmt_close($statement);

    if (
        $opposite_value &&
        (
            ($field === 'available_from' && strtotime($value) >= strtotime($opposite_value)) ||
            ($field === 'available_until' && strtotime($value) <= strtotime($opposite_value))
        )
    ) {
        echo "invalid_range";
        exit();
    }
}

$statement = mysqli_prepare($conn, "UPDATE quizzes SET $field = ? WHERE id = ?");
mysqli_stmt_bind_param($statement, 'si', $value, $quiz_id);
$saved = mysqli_stmt_execute($statement);
mysqli_stmt_close($statement);

echo $saved ? "saved" : "save_failed";
exit();
