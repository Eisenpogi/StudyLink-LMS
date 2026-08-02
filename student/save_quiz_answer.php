<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../config/quiz_engine.php';
include '../includes/academic_term.php';

header('Content-Type: application/json');
require_post();
require_csrf();

$user_id = intval($_SESSION['user_id']);
$attempt_id = intval($_POST['attempt_id'] ?? 0);
$question_id = intval($_POST['question_id'] ?? 0);
$selected_choice_id = intval($_POST['selected_choice_id'] ?? 0);
$answer_text = trim((string) ($_POST['answer_text'] ?? ''));

$attempt = quiz_attempt_access($conn, $user_id, $attempt_id, 'student');
if (!$attempt || $attempt['status'] !== 'in_progress') {
    http_response_code(403);
    echo json_encode(['saved' => false, 'message' => 'Attempt is not active.']);
    exit();
}
if (!studylink_class_is_writable($conn, studylink_attempt_class_id($conn, $attempt_id))) {
    http_response_code(409);
    echo json_encode([
        'saved' => false,
        'message' => 'This Academic Term is read-only. Quiz answers can no longer be changed.'
    ]);
    exit();
}
if (quiz_attempt_expired($attempt)) {
    quiz_finalize_attempt($conn, $attempt_id);
    http_response_code(409);
    echo json_encode(['saved' => false, 'expired' => true]);
    exit();
}

$question_statement = mysqli_prepare($conn, '
    SELECT question_type
    FROM quiz_questions
    WHERE id = ? AND quiz_id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($question_statement, 'ii', $question_id, $attempt['quiz_id']);
mysqli_stmt_execute($question_statement);
$question = mysqli_fetch_assoc(mysqli_stmt_get_result($question_statement));
mysqli_stmt_close($question_statement);

if (!$question) {
    http_response_code(400);
    echo json_encode(['saved' => false, 'message' => 'Invalid question.']);
    exit();
}

if (in_array($question['question_type'], ['multiple_choice', 'true_false'], true)) {
    $choice_statement = mysqli_prepare($conn, '
        SELECT id
        FROM quiz_choices
        WHERE id = ? AND question_id = ?
        LIMIT 1
    ');
    mysqli_stmt_bind_param($choice_statement, 'ii', $selected_choice_id, $question_id);
    mysqli_stmt_execute($choice_statement);
    $valid_choice = mysqli_fetch_assoc(mysqli_stmt_get_result($choice_statement));
    mysqli_stmt_close($choice_statement);

    if (!$valid_choice) {
        http_response_code(400);
        echo json_encode(['saved' => false, 'message' => 'Invalid choice.']);
        exit();
    }
} else {
    $selected_choice_id = 0;
}

$save = mysqli_prepare($conn, '
    INSERT INTO quiz_answers
        (attempt_id, question_id, selected_choice_id, answer_text)
    VALUES (?, ?, NULLIF(?, 0), ?)
    ON DUPLICATE KEY UPDATE
        selected_choice_id = VALUES(selected_choice_id),
        answer_text = VALUES(answer_text)
');
mysqli_stmt_bind_param(
    $save,
    'iiis',
    $attempt_id,
    $question_id,
    $selected_choice_id,
    $answer_text
);
$saved = mysqli_stmt_execute($save);
mysqli_stmt_close($save);

echo json_encode(['saved' => $saved]);
