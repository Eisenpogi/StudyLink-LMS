<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../config/quiz_engine.php';
include '../includes/academic_term.php';
include '../includes/module_workflow.php';

require_post('/studyLink/student/classes.php');
require_csrf('/studyLink/student/classes.php');

$user_id = intval($_SESSION['user_id']);
$attempt_id = intval($_POST['attempt_id'] ?? 0);
$attempt = quiz_attempt_access($conn, $user_id, $attempt_id, 'student');

if (!$attempt) {
    http_response_code(403);
    exit('Quiz attempt not found or unauthorized access.');
}
$module_id = intval($_POST['module_id'] ?? 0);
$module_class_id = intval($_POST['class_id'] ?? 0);
$has_module_context = studylink_student_quiz_module_context($conn, $module_id, $module_class_id, intval($attempt['student_id']), intval($attempt['quiz_id']));
if (!$has_module_context) {
    $module_context = studylink_student_quiz_module_lookup($conn, intval($attempt['student_id']), intval($attempt['quiz_id']));
    $module_id = intval($module_context['module_id'] ?? 0);
    $module_class_id = intval($module_context['class_id'] ?? 0);
}
$module_query = $module_id > 0 ? '&module_id=' . $module_id . '&class_id=' . $module_class_id : '';
$result_url = '/studyLink/student/quiz_result.php?attempt_id=' . $attempt_id . $module_query;
if (!studylink_class_is_writable($conn, studylink_attempt_class_id($conn, $attempt_id))) {
    redirect_with_flash(
        $result_url,
        'error',
        'This Academic Term is read-only. The quiz attempt can no longer be submitted or changed.'
    );
}
if ($attempt['status'] !== 'in_progress') {
    redirect_to($result_url);
}

$choice_answers = isset($_POST['choices']) && is_array($_POST['choices'])
    ? $_POST['choices']
    : [];
$text_answers = isset($_POST['answers']) && is_array($_POST['answers'])
    ? $_POST['answers']
    : [];

$question_statement = mysqli_prepare($conn, '
    SELECT id, question_type
    FROM quiz_questions
    WHERE quiz_id = ?
');
mysqli_stmt_bind_param($question_statement, 'i', $attempt['quiz_id']);
mysqli_stmt_execute($question_statement);
$questions = mysqli_stmt_get_result($question_statement);

$choice_check = mysqli_prepare($conn, '
    SELECT id FROM quiz_choices WHERE id = ? AND question_id = ? LIMIT 1
');
$save = mysqli_prepare($conn, '
    INSERT INTO quiz_answers
        (attempt_id, question_id, selected_choice_id, answer_text)
    VALUES (?, ?, NULLIF(?, 0), ?)
    ON DUPLICATE KEY UPDATE
        selected_choice_id = VALUES(selected_choice_id),
        answer_text = VALUES(answer_text)
');

mysqli_begin_transaction($conn);
try {
    while ($question = mysqli_fetch_assoc($questions)) {
        $question_id = intval($question['id']);
        $selected_choice_id = 0;
        $answer_text = '';

        if (in_array($question['question_type'], ['multiple_choice', 'true_false'], true)) {
            $candidate = intval($choice_answers[$question_id] ?? 0);
            if ($candidate > 0) {
                mysqli_stmt_bind_param($choice_check, 'ii', $candidate, $question_id);
                mysqli_stmt_execute($choice_check);
                if (mysqli_fetch_assoc(mysqli_stmt_get_result($choice_check))) {
                    $selected_choice_id = $candidate;
                }
            }
        } elseif ($question['question_type'] === 'enumeration') {
            $submitted_items = $text_answers[$question_id] ?? [];
            if (!is_array($submitted_items)) {
                $submitted_items = preg_split(
                    '/\s*(?:\||,|;|\r\n|\r|\n)\s*/',
                    (string) $submitted_items
                );
            }
            $submitted_items = array_map(function ($item) {
                return trim((string) $item);
            }, $submitted_items);
            $answer_text = implode('|', $submitted_items);
        } else {
            $answer_text = trim((string) ($text_answers[$question_id] ?? ''));
        }

        mysqli_stmt_bind_param(
            $save,
            'iiis',
            $attempt_id,
            $question_id,
            $selected_choice_id,
            $answer_text
        );
        if (!mysqli_stmt_execute($save)) {
            throw new Exception('Could not save an answer.');
        }
    }

    if (!quiz_finalize_attempt($conn, $attempt_id)) {
        throw new Exception('Could not submit the quiz attempt.');
    }
    mysqli_commit($conn);
} catch (Throwable $error) {
    mysqli_rollback($conn);
    mysqli_stmt_close($question_statement);
    mysqli_stmt_close($choice_check);
    mysqli_stmt_close($save);
    http_response_code(500);
    exit('Quiz submission failed. Please try again.');
}

mysqli_stmt_close($question_statement);
mysqli_stmt_close($choice_check);
mysqli_stmt_close($save);
redirect_to($result_url);
