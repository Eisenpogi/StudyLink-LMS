<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../config/quiz_engine.php';
include '../includes/student_ui.php';
include '../includes/module_workflow.php';

$user_id = intval($_SESSION['user_id']);
$attempt_id = intval($_GET['attempt_id'] ?? 0);
$attempt = quiz_attempt_access($conn, $user_id, $attempt_id, 'student');

if (!$attempt) {
    http_response_code(403);
    exit('Quiz result not found or unauthorized access.');
}
$student_number = '';
$stmt = mysqli_prepare($conn, 'SELECT student_no FROM students WHERE id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $attempt['student_id']);
mysqli_stmt_execute($stmt);
$student_profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$student_number = (string) ($student_profile['student_no'] ?? '');
$module_id = intval($_GET['module_id'] ?? 0);
$module_class_id = intval($_GET['class_id'] ?? 0);
$has_module_context = studylink_student_quiz_module_context($conn, $module_id, $module_class_id, intval($attempt['student_id']), intval($attempt['quiz_id']));
if (!$has_module_context) {
    $module_context = studylink_student_quiz_module_lookup($conn, intval($attempt['student_id']), intval($attempt['quiz_id']));
    $module_id = intval($module_context['module_id'] ?? 0);
    $module_class_id = intval($module_context['class_id'] ?? 0);
}
$module_query = $module_id > 0 ? '&module_id=' . $module_id . '&class_id=' . $module_class_id : '';
$module_url = $module_id > 0 ? '/studyLink/student/module_view.php?id=' . $module_id . '&class_id=' . $module_class_id : '';

if ($attempt['status'] === 'in_progress') {
    redirect_to('/studyLink/student/take_quiz.php?attempt_id=' . $attempt_id . $module_query);
}

$detail_statement = mysqli_prepare(
    $conn,
    'SELECT
        qq.id AS question_id,
        qq.question_text,
        qq.question_type,
        qq.points,
        qq.correct_answer,
        qa.answer_text,
        qa.final_score,
        qa.faculty_feedback,
        qa.is_correct,
        qa.checked_by_faculty,
        qc.choice_text AS selected_choice,
        (
            SELECT GROUP_CONCAT(correct_choice.choice_text ORDER BY correct_choice.choice_order SEPARATOR "|||")
            FROM quiz_choices correct_choice
            WHERE correct_choice.question_id = qq.id
              AND correct_choice.is_correct = 1
        ) AS correct_choices
     FROM quiz_questions qq
     LEFT JOIN quiz_answers qa
            ON qa.question_id = qq.id
           AND qa.attempt_id = ?
     LEFT JOIN quiz_choices qc ON qc.id = qa.selected_choice_id
     WHERE qq.quiz_id = ?
     ORDER BY qq.order_no ASC, qq.id ASC'
);
mysqli_stmt_bind_param($detail_statement, 'ii', $attempt_id, $attempt['quiz_id']);
mysqli_stmt_execute($detail_statement);
$details = [];
$result = mysqli_stmt_get_result($detail_statement);
while ($row = mysqli_fetch_assoc($result)) {
    $details[] = $row;
}

$history_statement = mysqli_prepare(
    $conn,
    'SELECT id, attempt_no, score, total_points, percentage, status, is_passed, submitted_at
     FROM quiz_attempts
     WHERE quiz_id = ? AND student_id = ? AND status <> "in_progress"
     ORDER BY attempt_no DESC'
);
mysqli_stmt_bind_param($history_statement, 'ii', $attempt['quiz_id'], $attempt['student_id']);
mysqli_stmt_execute($history_statement);
$history = [];
$result = mysqli_stmt_get_result($history_statement);
while ($row = mysqli_fetch_assoc($result)) {
    $history[] = $row;
}

$percentage = min(100, max(0, floatval($attempt['percentage'])));
$passed = intval($attempt['is_passed']) === 1;
$pending = $attempt['status'] === 'needs_review';
$question_counts = [
    'correct' => 0,
    'partial' => 0,
    'incorrect' => 0,
    'pending' => 0,
    'unanswered' => 0
];

foreach ($details as $index => $detail) {
    $display_answer = $detail['selected_choice'] ?? '';
    if ($display_answer === '' || $display_answer === null) {
        $display_answer = trim((string) ($detail['answer_text'] ?? ''));
    }

    $points = floatval($detail['points']);
    $score = $detail['final_score'] === null ? null : floatval($detail['final_score']);
    $review_state = 'incorrect';

    if ($display_answer === '') {
        $review_state = 'unanswered';
    } elseif ($score === null) {
        $review_state = 'pending';
    } elseif ($points > 0 && $score >= $points - 0.001) {
        $review_state = 'correct';
    } elseif ($score > 0) {
        $review_state = 'partial';
    }

    $details[$index]['display_answer'] = $display_answer;
    $details[$index]['review_state'] = $review_state;
    $question_counts[$review_state]++;

    $correct_display = trim((string) ($detail['correct_choices'] ?? ''));
    if ($correct_display !== '') {
        $correct_display = str_replace('|||', ', ', $correct_display);
    } else {
        $correct_display = trim((string) ($detail['correct_answer'] ?? ''));
        if ($detail['question_type'] === 'enumeration') {
            $correct_display = preg_replace('/\s*(?:\||;|\r\n|\r|\n)\s*/', ', ', $correct_display);
        }
    }
    $details[$index]['correct_display'] = $correct_display;
}

$answered_count = count($details) - $question_counts['unanswered'];
$reviewed_count = count($details) - $question_counts['pending'];
$submitted_label = !empty($attempt['submitted_at'])
    ? date('M j, Y · g:i A', strtotime($attempt['submitted_at']))
    : 'Not yet submitted';
$time_spent = 'Not available';

if (!empty($attempt['started_at']) && !empty($attempt['submitted_at'])) {
    $elapsed_seconds = max(0, strtotime($attempt['submitted_at']) - strtotime($attempt['started_at']));
    $elapsed_minutes = intval(floor($elapsed_seconds / 60));
    $time_spent = $elapsed_minutes > 0
        ? $elapsed_minutes . ' min ' . ($elapsed_seconds % 60) . ' sec'
        : $elapsed_seconds . ' sec';
}

$result_title = $pending
    ? 'Submitted for faculty review'
    : ($passed ? 'Assessment completed successfully' : 'Assessment completed');
$result_description = $pending
    ? 'Automatically checked answers are included. Your final score will update after the remaining responses are reviewed.'
    : 'Review the score breakdown, your responses, correct answers, and any feedback from your faculty.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
    <title>Quiz Result - StudyLink</title>
</head>
<body class="student-result-page">
<?php render_student_sidebar('modules', $student_number); ?>
<?php render_student_topbar($conn, 'Assessment Result'); ?>
<main class="student-main">
<div class="student-shell result-shell">
    <div class="result-page-actions">
        <a class="result-back" href="<?php echo e($module_url ?: '/studyLink/student/modules.php'); ?>"><?php echo study_icon('arrow-left'); ?> <?php echo $module_url ? 'Back to Module' : 'Back to Modules'; ?></a>
        <a class="result-course-link" href="class_view.php?id=<?php echo intval($attempt['class_assignment_id']); ?>&tab=modules">Open Course Modules <?php echo study_icon('arrow-right'); ?></a>
    </div>

    <section class="page-heading result-heading">
        <div>
            <div class="eyebrow">Academic Assessment</div>
            <h1><?php echo e($attempt['title']); ?></h1>
            <p class="lead">Attempt <?php echo intval($attempt['attempt_no']); ?> · Submitted <?php echo e($submitted_label); ?></p>
        </div>
        <span class="student-result-status <?php echo $pending ? 'pending' : ($passed ? 'passed' : 'failed'); ?>">
            <?php echo study_icon($pending ? 'hourglass-split' : ($passed ? 'check-circle' : 'x-circle')); ?>
            <?php echo $pending ? 'Pending Review' : ($passed ? 'Passed' : 'Not Passed'); ?>
        </span>
    </section>

    <section class="card result-overview">
        <div class="result-score-panel">
            <div class="result-ring <?php echo $pending ? 'pending-ring' : ($passed ? 'pass-ring' : 'fail-ring'); ?>" style="--score:<?php echo $percentage; ?>">
                <div><strong><?php echo number_format($percentage, 0); ?>%</strong><span>Overall Score</span></div>
            </div>
            <div class="result-score-caption">
                <strong><?php echo number_format(floatval($attempt['score']), 2); ?> / <?php echo number_format(floatval($attempt['total_points']), 2); ?></strong>
                <span>points earned</span>
            </div>
        </div>
        <div class="result-overview-content">
            <div class="result-overview-copy">
                <span class="result-kicker"><?php echo $pending ? 'Review in progress' : 'Final result'; ?></span>
                <h2><?php echo e($result_title); ?></h2>
                <p><?php echo e($result_description); ?></p>
            </div>
            <div class="result-stat-grid">
                <div><span><?php echo study_icon('check2-circle'); ?></span><strong><?php echo $question_counts['correct']; ?></strong><small>Correct</small></div>
                <div><span><?php echo study_icon('pie-chart'); ?></span><strong><?php echo $question_counts['partial']; ?></strong><small>Partial Credit</small></div>
                <div><span><?php echo study_icon('list-check'); ?></span><strong><?php echo $answered_count; ?>/<?php echo count($details); ?></strong><small>Answered</small></div>
                <div><span><?php echo study_icon('stopwatch'); ?></span><strong><?php echo e($time_spent); ?></strong><small>Time Used</small></div>
            </div>
        </div>
    </section>

    <section class="card result-breakdown">
        <div class="result-breakdown-heading">
            <div><h2>Question Breakdown</h2><p><?php echo $reviewed_count; ?> of <?php echo count($details); ?> questions currently graded</p></div>
            <div class="result-legend">
                <span class="correct"><i></i>Correct <?php echo $question_counts['correct']; ?></span>
                <span class="partial"><i></i>Partial <?php echo $question_counts['partial']; ?></span>
                <span class="incorrect"><i></i>Incorrect <?php echo $question_counts['incorrect']; ?></span>
                <?php if ($question_counts['pending'] > 0): ?><span class="pending"><i></i>Pending <?php echo $question_counts['pending']; ?></span><?php endif; ?>
            </div>
        </div>
        <div class="result-breakdown-bar" aria-label="Question result breakdown">
            <?php foreach (['correct', 'partial', 'incorrect', 'pending', 'unanswered'] as $state): ?>
                <?php if ($question_counts[$state] > 0): ?>
                    <span class="<?php echo $state; ?>" style="width:<?php echo count($details) ? ($question_counts[$state] / count($details)) * 100 : 0; ?>%"></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="result-layout">
        <section class="result-review-section">
            <div class="section-head result-review-head">
                <div><h2>Answer Review</h2><p>Compare your responses with the answer key and faculty feedback.</p></div>
                <span class="result-count"><?php echo count($details); ?> questions</span>
            </div>
            <div class="result-review-filters" role="group" aria-label="Filter answer review">
                <button class="active" type="button" data-result-filter="all">All</button>
                <button type="button" data-result-filter="correct">Correct</button>
                <button type="button" data-result-filter="partial">Partial</button>
                <button type="button" data-result-filter="incorrect">Incorrect</button>
                <?php if ($question_counts['pending'] > 0): ?><button type="button" data-result-filter="pending">Pending</button><?php endif; ?>
            </div>

            <div class="answer-review-list">
            <?php foreach ($details as $index => $detail): ?>
                <?php
                $review_state = $detail['review_state'];
                $state_labels = [
                    'correct' => 'Correct',
                    'partial' => 'Partial Credit',
                    'incorrect' => 'Incorrect',
                    'pending' => 'Pending Review',
                    'unanswered' => 'Not Answered'
                ];
                $state_icons = [
                    'correct' => 'check-circle-fill',
                    'partial' => 'pie-chart-fill',
                    'incorrect' => 'x-circle-fill',
                    'pending' => 'hourglass-split',
                    'unanswered' => 'dash-circle'
                ];
                ?>
                <article class="card answer-review <?php echo e($review_state); ?>" data-review-state="<?php echo e($review_state); ?>">
                    <div class="answer-review-header">
                        <span class="answer-number"><?php echo $index + 1; ?></span>
                        <div class="answer-question-copy">
                            <span class="answer-type"><?php echo e(ucwords(str_replace('_', ' ', $detail['question_type']))); ?></span>
                            <h3><?php echo e($detail['question_text']); ?></h3>
                        </div>
                        <div class="answer-score">
                            <span class="answer-state"><?php echo study_icon($state_icons[$review_state]); ?> <?php echo e($state_labels[$review_state]); ?></span>
                            <strong><?php echo $detail['final_score'] === null ? 'Pending' : number_format(floatval($detail['final_score']), 2) . ' / ' . number_format(floatval($detail['points']), 2); ?></strong>
                        </div>
                    </div>

                    <div class="answer-comparison">
                        <div class="answer-panel student-answer">
                            <span>Your Answer</span>
                            <p><?php echo $detail['display_answer'] === '' ? '<em>No answer provided</em>' : nl2br(e($detail['display_answer'])); ?></p>
                        </div>
                        <?php if ($detail['correct_display'] !== '' && $detail['question_type'] !== 'essay'): ?>
                            <div class="answer-panel correct-answer">
                                <span>Correct Answer</span>
                                <p><?php echo nl2br(e($detail['correct_display'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($detail['faculty_feedback'])): ?>
                        <div class="faculty-feedback">
                            <span class="faculty-feedback-icon"><?php echo study_icon('chat-left-text'); ?></span>
                            <div><strong>Faculty Feedback</strong><p><?php echo nl2br(e($detail['faculty_feedback'])); ?></p></div>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            </div>
            <div class="empty-state result-filter-empty" hidden>No questions match this filter.</div>
        </section>

        <aside class="rail result-rail">
            <section class="card rail-card result-details-card">
                <div class="rail-title-icon"><?php echo study_icon('clipboard-data'); ?></div>
                <h3>Attempt Details</h3>
                <div class="rail-row"><span>Attempt</span><strong><?php echo intval($attempt['attempt_no']); ?></strong></div>
                <div class="rail-row"><span>Status</span><strong><?php echo e(ucwords(str_replace('_', ' ', $attempt['status']))); ?></strong></div>
                <div class="rail-row"><span>Submitted</span><strong><?php echo e($submitted_label); ?></strong></div>
                <div class="rail-row"><span>Time Used</span><strong><?php echo e($time_spent); ?></strong></div>
                <a class="button gold full-button" href="<?php echo e($module_url ?: '/studyLink/student/modules.php'); ?>"><?php echo study_icon('collection'); ?> <?php echo $module_url ? 'Return to Module' : 'View Modules'; ?></a>
            </section>

            <section class="card rail-card result-history-card">
                <div class="section-head"><h3>Attempt History</h3><span><?php echo count($history); ?></span></div>
                <?php foreach ($history as $row): ?>
                    <a class="history-row <?php echo intval($row['id']) === $attempt_id ? 'current' : ''; ?>" href="?attempt_id=<?php echo intval($row['id']); ?><?php echo e($module_query); ?>">
                        <span><strong>Attempt <?php echo intval($row['attempt_no']); ?></strong><small><?php echo !empty($row['submitted_at']) ? date('M j, g:i A', strtotime($row['submitted_at'])) : 'No date'; ?></small></span>
                        <b><?php echo number_format(floatval($row['percentage']), 0); ?>%</b>
                    </a>
                <?php endforeach; ?>
            </section>
        </aside>
    </div>
</div>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var filterButtons = Array.from(document.querySelectorAll('[data-result-filter]'));
    var reviewCards = Array.from(document.querySelectorAll('[data-review-state]'));
    var emptyState = document.querySelector('.result-filter-empty');

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var filter = button.dataset.resultFilter;
            var visibleCount = 0;
            filterButtons.forEach(function (item) { item.classList.remove('active'); });
            button.classList.add('active');

            reviewCards.forEach(function (card) {
                var visible = filter === 'all' || card.dataset.reviewState === filter;
                card.hidden = !visible;
                if (visible) visibleCount++;
            });

            if (emptyState) emptyState.hidden = visibleCount !== 0;
        });
    });
});
</script>
<script src="/studyLink/assets/js/app-shell.js"></script>
</body>
</html>
<?php
mysqli_stmt_close($detail_statement);
mysqli_stmt_close($history_statement);
?>
