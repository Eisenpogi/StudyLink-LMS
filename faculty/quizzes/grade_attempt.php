<?php

include '../../auth/auth.php';
require_role('faculty');
include '../../config/database.php';
include '../../config/quiz_engine.php';
include '../../includes/ui_icons.php';
include '../../includes/academic_term.php';

$user_id = intval($_SESSION['user_id']);
$attempt_id = intval($_GET['attempt_id'] ?? $_POST['attempt_id'] ?? 0);
$attempt = quiz_attempt_access($conn, $user_id, $attempt_id, 'faculty');

if (!$attempt || $attempt['status'] === 'in_progress') {
    http_response_code(403);
    exit('Quiz attempt not found or unauthorized access.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (!studylink_class_is_writable($conn, studylink_attempt_class_id($conn, $attempt_id))) {
        redirect_with_flash(
            '/studyLink/faculty/quizzes/grade_attempt.php?attempt_id=' . $attempt_id,
            'error',
            'This Academic Term is read-only. Recorded quiz grades can no longer be changed.'
        );
    }
    $scores = isset($_POST['scores']) && is_array($_POST['scores']) ? $_POST['scores'] : [];
    $feedback = isset($_POST['feedback']) && is_array($_POST['feedback']) ? $_POST['feedback'] : [];

    $essay_statement = mysqli_prepare($conn, '
        SELECT qa.id, qq.points
        FROM quiz_answers qa
        INNER JOIN quiz_questions qq ON qq.id = qa.question_id
        WHERE qa.attempt_id = ? AND qq.question_type = "essay"
    ');
    mysqli_stmt_bind_param($essay_statement, 'i', $attempt_id);
    mysqli_stmt_execute($essay_statement);
    $essays = mysqli_stmt_get_result($essay_statement);

    $update_answer = mysqli_prepare($conn, '
        UPDATE quiz_answers
        SET final_score = ?, faculty_feedback = ?, checked_by_faculty = 1
        WHERE id = ? AND attempt_id = ?
    ');

    mysqli_begin_transaction($conn);
    try {
        while ($essay = mysqli_fetch_assoc($essays)) {
            $answer_id = intval($essay['id']);
            if (!array_key_exists($answer_id, $scores) || $scores[$answer_id] === '') {
                throw new Exception('All essay scores are required.');
            }

            $score = floatval($scores[$answer_id]);
            $max_points = floatval($essay['points']);
            if ($score < 0 || $score > $max_points) {
                throw new Exception('Essay score must be between 0 and its maximum points.');
            }

            $answer_feedback = trim((string) ($feedback[$answer_id] ?? ''));
            mysqli_stmt_bind_param(
                $update_answer,
                'dsii',
                $score,
                $answer_feedback,
                $answer_id,
                $attempt_id
            );
            if (!mysqli_stmt_execute($update_answer)) {
                throw new Exception('Could not save an essay grade.');
            }
        }

        $total_statement = mysqli_prepare($conn, '
            SELECT COALESCE(SUM(qa.final_score), 0) AS score,
                   COALESCE(SUM(qq.points), 0) AS total_points,
                   SUM(qq.question_type = "essay" AND qa.checked_by_faculty = 0) AS unchecked
            FROM quiz_questions qq
            LEFT JOIN quiz_answers qa
              ON qa.question_id = qq.id AND qa.attempt_id = ?
            WHERE qq.quiz_id = ?
        ');
        mysqli_stmt_bind_param($total_statement, 'ii', $attempt_id, $attempt['quiz_id']);
        mysqli_stmt_execute($total_statement);
        $totals = mysqli_fetch_assoc(mysqli_stmt_get_result($total_statement));
        mysqli_stmt_close($total_statement);

        if (intval($totals['unchecked']) > 0) {
            throw new Exception('All essay answers must be graded.');
        }

        $score = floatval($totals['score']);
        $total_points = floatval($totals['total_points']);
        $percentage = $total_points > 0 ? ($score / $total_points) * 100 : 0;
        $is_passed = $percentage >= floatval($attempt['passing_score']) ? 1 : 0;
        $faculty_id = intval($attempt['graded_by'] ?? 0);

        $faculty_statement = mysqli_prepare($conn, 'SELECT id FROM faculty WHERE user_id = ? LIMIT 1');
        mysqli_stmt_bind_param($faculty_statement, 'i', $user_id);
        mysqli_stmt_execute($faculty_statement);
        $faculty = mysqli_fetch_assoc(mysqli_stmt_get_result($faculty_statement));
        mysqli_stmt_close($faculty_statement);
        $faculty_id = intval($faculty['id']);

        $update_attempt = mysqli_prepare($conn, '
            UPDATE quiz_attempts
            SET score = ?, total_points = ?, percentage = ?, is_passed = ?,
                status = "graded", graded_at = NOW(), graded_by = ?
            WHERE id = ?
        ');
        mysqli_stmt_bind_param(
            $update_attempt,
            'dddiii',
            $score,
            $total_points,
            $percentage,
            $is_passed,
            $faculty_id,
            $attempt_id
        );
        if (!mysqli_stmt_execute($update_attempt)) {
            throw new Exception('Could not finalize the quiz grade.');
        }
        mysqli_stmt_close($update_attempt);
        mysqli_commit($conn);
        redirect_to('grade_attempt.php?attempt_id=' . $attempt_id . '&saved=1');
    } catch (Throwable $error) {
        mysqli_rollback($conn);
        $grade_error = $error->getMessage();
    }

    mysqli_stmt_close($essay_statement);
    mysqli_stmt_close($update_answer);
    $attempt = quiz_attempt_access($conn, $user_id, $attempt_id, 'faculty');
}

$answer_statement = mysqli_prepare($conn, '
    SELECT qa.id AS answer_id, qa.answer_text, qa.final_score,
           qa.faculty_feedback, qa.checked_by_faculty,
           qc.choice_text AS selected_choice,
           qq.question_text, qq.question_type, qq.points
    FROM quiz_questions qq
    LEFT JOIN quiz_answers qa
      ON qa.question_id = qq.id AND qa.attempt_id = ?
    LEFT JOIN quiz_choices qc ON qc.id = qa.selected_choice_id
    WHERE qq.quiz_id = ?
    ORDER BY qq.order_no ASC, qq.id ASC
');
mysqli_stmt_bind_param($answer_statement, 'ii', $attempt_id, $attempt['quiz_id']);
mysqli_stmt_execute($answer_statement);
$answers = mysqli_stmt_get_result($answer_statement);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Quiz Attempt - StudyLink</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f5f6fa;margin:0;color:#222}
        .page{max-width:900px;margin:28px auto;padding:0 18px 50px}
        .card{background:#fff;border-radius:14px;padding:22px;margin-bottom:16px;box-shadow:0 2px 9px rgba(0,0,0,.07)}
        .header{border-top:7px solid #4f46e5}.essay{border-left:5px solid #f59e0b}
        textarea{width:100%;box-sizing:border-box;min-height:85px;padding:10px}
        input[type=number]{padding:9px;width:110px}.btn{background:#4f46e5;color:#fff;border:0;border-radius:9px;padding:12px 18px;cursor:pointer}
        .success{color:#166534}.error{color:#991b1b}.muted{color:#666}
    </style>
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
</head>
<body class="grade-page">
<div class="page grade-shell">
    <div class="card header grade-hero">
        <a class="quiz-back" href="quiz_builder.php?quiz_id=<?php echo intval($attempt['quiz_id']); ?>&tab=results"><?php echo study_icon('arrow-left'); ?> Back to Results</a>
        <div class="quiz-eyebrow">Attempt Review</div>
        <h1><?php echo e($attempt['title']); ?></h1>
        <p>
            <?php echo e($attempt['student_name']); ?> (<?php echo e($attempt['student_no']); ?>) —
            Attempt <?php echo intval($attempt['attempt_no']); ?>
        </p>
        <div class="grade-score"><strong><?php echo number_format(floatval($attempt['percentage']), 1); ?>%</strong><span><?php echo number_format(floatval($attempt['score']), 2); ?> / <?php echo number_format(floatval($attempt['total_points']), 2); ?> points · <?php echo e(ucwords(str_replace('_', ' ', $attempt['status']))); ?></span></div>
    </div>
    <?php if (isset($_GET['saved'])): ?><div class="quiz-alert success"><?php echo study_icon('check-circle'); ?> Grades saved successfully.</div><?php endif; ?>
    <?php if (!empty($grade_error)): ?><div class="quiz-alert error"><?php echo study_icon('exclamation-circle'); ?> <?php echo e($grade_error); ?></div><?php endif; ?>

    <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">
        <?php $number = 1; $has_essay = false; while ($answer = mysqli_fetch_assoc($answers)): ?>
            <?php $is_essay = $answer['question_type'] === 'essay'; $has_essay = $has_essay || $is_essay; ?>
            <div class="card grade-answer <?php echo $is_essay ? 'essay' : ''; ?>">
                <div class="question-number">Question <?php echo $number++; ?> · <?php echo e(ucwords(str_replace('_', ' ', $answer['question_type']))); ?></div>
                <h3><?php echo e($answer['question_text']); ?></h3>
                <div class="student-answer"><strong>Student answer</strong><p>
                    <?php
                    $display_answer = $answer['selected_choice'] ?: $answer['answer_text'];
                    echo $display_answer === null || $display_answer === ''
                        ? 'No answer'
                        : nl2br(e($display_answer));
                    ?>
                </p></div>
                <?php if ($is_essay): ?>
                    <div class="grade-fields">
                        <label>Score:
                            <input
                                type="number"
                                name="scores[<?php echo intval($answer['answer_id']); ?>]"
                                min="0"
                                max="<?php echo e($answer['points']); ?>"
                                step="0.01"
                                value="<?php echo $answer['final_score'] === null ? '' : e($answer['final_score']); ?>"
                                required
                            >
                            / <?php echo e($answer['points']); ?>
                        </label>
                        <label>Feedback:
                            <textarea name="feedback[<?php echo intval($answer['answer_id']); ?>]" placeholder="Give clear and useful feedback to the student."><?php echo e($answer['faculty_feedback'] ?? ''); ?></textarea>
                        </label>
                    </div>
                <?php else: ?>
                    <p><strong><?php echo study_icon('cpu'); ?> Automatic score:</strong>
                        <?php echo number_format(floatval($answer['final_score']), 2); ?> /
                        <?php echo number_format(floatval($answer['points']), 2); ?>
                    </p>
                <?php endif; ?>
            </div>
        <?php endwhile; ?>

        <?php if ($has_essay): ?>
            <div class="grade-submit"><button type="submit" class="btn btn-primary"><?php echo study_icon('check2-circle'); ?> Save and Finalize Grade</button></div>
        <?php endif; ?>
    </form>
</div>
</body>
</html>
<?php mysqli_stmt_close($answer_statement); ?>
