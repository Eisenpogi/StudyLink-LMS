<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../config/quiz_engine.php';
include '../includes/academic_term.php';
include '../includes/module_workflow.php';
include '../includes/ui_icons.php';

$user_id = intval($_SESSION['user_id']);
$attempt_id = intval($_GET['attempt_id'] ?? 0);
$attempt = quiz_attempt_access($conn, $user_id, $attempt_id, 'student');

if (!$attempt) {
    http_response_code(403);
    exit('Quiz attempt not found or unauthorized access.');
}
$module_id = intval($_GET['module_id'] ?? 0);
$module_class_id = intval($_GET['class_id'] ?? 0);
$has_module_context = studylink_student_quiz_module_context(
    $conn,
    $module_id,
    $module_class_id,
    intval($attempt['student_id']),
    intval($attempt['quiz_id'])
);
if (!$has_module_context) {
    $module_context = studylink_student_quiz_module_lookup($conn, intval($attempt['student_id']), intval($attempt['quiz_id']));
    $module_id = intval($module_context['module_id'] ?? 0);
    $module_class_id = intval($module_context['class_id'] ?? 0);
}
$module_query = $module_id > 0 ? '&module_id=' . $module_id . '&class_id=' . $module_class_id : '';
$result_url = '/studyLink/student/quiz_result.php?attempt_id=' . $attempt_id . $module_query;

$attempt_class_id = studylink_attempt_class_id($conn, $attempt_id);
if (!studylink_class_is_writable($conn, $attempt_class_id)) {
    redirect_with_flash(
        ($module_id > 0 ? '/studyLink/student/module_view.php?id=' . $module_id . '&class_id=' . $module_class_id : '/studyLink/student/class_view.php?id=' . $attempt_class_id . '&tab=modules'),
        'error',
        'This Academic Term is read-only. Quiz attempts can no longer be continued or changed.'
    );
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'restart_after_tab_change'
) {
    header('Content-Type: application/json; charset=UTF-8');
    require_csrf();

    if ($attempt['status'] !== 'in_progress') {
        echo json_encode(['restarted' => false, 'completed' => true]);
        exit();
    }

    $started_at = date('Y-m-d H:i:s');
    $expires_at = intval($attempt['time_limit']) > 0
        ? date('Y-m-d H:i:s', time() + (intval($attempt['time_limit']) * 60))
        : null;

    mysqli_begin_transaction($conn);

    $delete_answers = mysqli_prepare($conn, '
        DELETE FROM quiz_answers
        WHERE attempt_id = ?
    ');
    mysqli_stmt_bind_param($delete_answers, 'i', $attempt_id);
    $answers_deleted = mysqli_stmt_execute($delete_answers);
    mysqli_stmt_close($delete_answers);

    $restart_attempt = mysqli_prepare($conn, '
        UPDATE quiz_attempts
        SET started_at = ?,
            expires_at = ?,
            submitted_at = NULL,
            status = "in_progress",
            score = 0,
            total_points = 0,
            percentage = 0,
            is_passed = 0
        WHERE id = ? AND student_id = ? AND status = "in_progress"
    ');
    $student_id = intval($attempt['student_id']);
    mysqli_stmt_bind_param(
        $restart_attempt,
        'ssii',
        $started_at,
        $expires_at,
        $attempt_id,
        $student_id
    );
    $attempt_restarted = mysqli_stmt_execute($restart_attempt);
    mysqli_stmt_close($restart_attempt);

    if ($answers_deleted && $attempt_restarted) {
        mysqli_commit($conn);
        echo json_encode(['restarted' => true]);
        exit();
    }

    mysqli_rollback($conn);
    http_response_code(500);
    echo json_encode(['restarted' => false]);
    exit();
}

if ($attempt['status'] !== 'in_progress') {
    redirect_to($result_url);
}
if (quiz_attempt_expired($attempt)) {
    quiz_finalize_attempt($conn, $attempt_id);
    redirect_to($result_url . '&expired=1');
}

$question_result = mysqli_query($conn, '
    SELECT id, question_text, question_type, points, correct_answer, order_no
    FROM quiz_questions
    WHERE quiz_id = ' . intval($attempt['quiz_id']) . '
    ORDER BY order_no ASC, id ASC
');
$question_map = [];
while ($question = mysqli_fetch_assoc($question_result)) {
    $question['choices'] = [];
    $question_map[intval($question['id'])] = $question;
}

if ($question_map) {
    $choice_result = mysqli_query($conn, '
        SELECT qc.id, qc.question_id, qc.choice_text
        FROM quiz_choices qc
        INNER JOIN quiz_questions qq ON qq.id = qc.question_id
        WHERE qq.quiz_id = ' . intval($attempt['quiz_id']) . '
        ORDER BY qc.choice_order ASC, qc.id ASC
    ');
    while ($choice = mysqli_fetch_assoc($choice_result)) {
        $question_id = intval($choice['question_id']);
        if (isset($question_map[$question_id])) {
            $question_map[$question_id]['choices'][] = $choice;
        }
    }
}

$ordered_ids = json_decode((string) $attempt['question_order'], true);
if (!is_array($ordered_ids) || !$ordered_ids) {
    $ordered_ids = array_keys($question_map);
}
$questions = [];
foreach ($ordered_ids as $question_id) {
    $question_id = intval($question_id);
    if (isset($question_map[$question_id])) {
        $questions[] = $question_map[$question_id];
    }
}

$answer_statement = mysqli_prepare($conn, '
    SELECT question_id, selected_choice_id, answer_text
    FROM quiz_answers
    WHERE attempt_id = ?
');
mysqli_stmt_bind_param($answer_statement, 'i', $attempt_id);
mysqli_stmt_execute($answer_statement);
$answer_result = mysqli_stmt_get_result($answer_statement);
$saved_answers = [];
while ($answer = mysqli_fetch_assoc($answer_result)) {
    $saved_answers[intval($answer['question_id'])] = $answer;
}
mysqli_stmt_close($answer_statement);

$remaining_seconds = null;
if (!empty($attempt['expires_at'])) {
    $remaining_seconds = max(0, strtotime($attempt['expires_at']) - time());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
    <title><?php echo e($attempt['title']); ?> - StudyLink</title>
</head>
<body class="student-quiz-page">
<header class="quiz-taking-top">
    <div class="quiz-taking-top-inner">
        <div class="quiz-timer">
            <span class="quiz-timer-icon"><?php echo study_icon('stopwatch'); ?></span>
            <div>
                <span><?php echo $remaining_seconds !== null ? 'Time remaining' : 'Assessment in progress'; ?></span>
                <strong id="quizTimer"><?php echo $remaining_seconds !== null ? '--:--' : 'No time limit'; ?></strong>
            </div>
        </div>
        <div class="quiz-progress-meta"><span id="answeredCount">0</span> of <?php echo count($questions); ?> answered · Attempt <?php echo intval($attempt['attempt_no']); ?></div>
    </div>
</header>
<div class="quiz-progress-track"><span id="answerProgress" style="width:0"></span></div>

<main class="student-quiz-shell">
    <?php if (isset($_GET['restarted'])): ?>
        <div class="quiz-integrity-notice" role="alert">
            <?php echo study_icon('shield-exclamation'); ?>
            <div>
                <strong>Quiz restarted for assessment integrity</strong>
                <span>You left the quiz tab, so your answers and timer were reset. This remains the same attempt.</span>
            </div>
        </div>
    <?php endif; ?>
    <div class="quiz-taking-layout">
        <section class="quiz-taking-main">
            <div class="quiz-taking-hero">
                <div class="quiz-eyebrow">Academic Assessment</div>
                <h1><?php echo e($attempt['title']); ?></h1>
                <p><?php echo trim((string)($attempt['instructions'] ?? '')) !== '' ? nl2br(e($attempt['instructions'])) : 'Answer every question carefully. Your work is saved automatically.'; ?></p>
            </div>

            <form id="quizForm" action="submit_quiz.php" method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">
        <input type="hidden" name="module_id" value="<?php echo $module_id; ?>">
        <input type="hidden" name="class_id" value="<?php echo $module_class_id; ?>">

        <?php foreach ($questions as $index => $question): ?>
            <?php
            $question_id = intval($question['id']);
            $saved = $saved_answers[$question_id] ?? [];
            ?>
            <article
                class="student-question"
                data-question-id="<?php echo $question_id; ?>"
                data-question-type="<?php echo e($question['question_type']); ?>"
            >
                <div class="student-question-head">
                    <div>
                        <div class="student-question-number">Question <?php echo $index + 1; ?> of <?php echo count($questions); ?></div>
                        <h3><?php echo e($question['question_text']); ?></h3>
                    </div>
                    <span class="student-question-points"><?php echo e($question['points']); ?> point<?php echo floatval($question['points']) == 1.0 ? '' : 's'; ?></span>
                </div>

                <?php if (in_array($question['question_type'], ['multiple_choice', 'true_false'], true)): ?>
                    <?php foreach ($question['choices'] as $choice): ?>
                        <label class="student-choice">
                            <input
                                type="radio"
                                name="choices[<?php echo $question_id; ?>]"
                                value="<?php echo intval($choice['id']); ?>"
                                data-autosave-choice
                                <?php echo intval($saved['selected_choice_id'] ?? 0) === intval($choice['id']) ? 'checked' : ''; ?>
                            >
                            <?php echo e($choice['choice_text']); ?>
                        </label>
                    <?php endforeach; ?>
                <?php elseif ($question['question_type'] === 'essay'): ?>
                    <textarea
                        name="answers[<?php echo $question_id; ?>]"
                        data-autosave-text
                        placeholder="Write your answer here"
                    ><?php echo e($saved['answer_text'] ?? ''); ?></textarea>
                <?php elseif ($question['question_type'] === 'enumeration'): ?>
                    <?php
                    $correct_items = preg_split(
                        '/\s*(?:\||,|;|\r\n|\r|\n)\s*/',
                        trim((string) $question['correct_answer'])
                    );
                    $correct_items = array_values(array_filter($correct_items, function ($item) {
                        return trim((string) $item) !== '';
                    }));
                    $expected_answer_count = max(1, count($correct_items));

                    $saved_items = preg_split(
                        '/\s*(?:\||,|;|\r\n|\r|\n)\s*/',
                        trim((string) ($saved['answer_text'] ?? ''))
                    );
                    $saved_items = array_map(function ($item) {
                        return trim((string) $item);
                    }, $saved_items);
                    ?>
                    <div class="enumeration-list">
                        <?php for ($answer_index = 0; $answer_index < $expected_answer_count; $answer_index++): ?>
                            <label class="enumeration-item">
                                <span class="enumeration-number"><?php echo $answer_index + 1; ?>.</span>
                                <input
                                    type="text"
                                    name="answers[<?php echo $question_id; ?>][]"
                                    value="<?php echo e($saved_items[$answer_index] ?? ''); ?>"
                                    data-autosave-text
                                    data-enumeration-input
                                    placeholder="Answer <?php echo $answer_index + 1; ?>"
                                    autocomplete="off"
                                >
                            </label>
                        <?php endfor; ?>
                    </div>
                <?php else: ?>
                    <input
                        type="text"
                        name="answers[<?php echo $question_id; ?>]"
                        value="<?php echo e($saved['answer_text'] ?? ''); ?>"
                        data-autosave-text
                        autocomplete="off"
                    >
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

                <div class="quiz-submit-bar">
                    <span><?php echo study_icon('shield-check'); ?> Review your answers before final submission.</span>
                    <button type="submit" class="quiz-submit-button" id="submitButton">Submit Quiz <?php echo study_icon('arrow-right'); ?></button>
                </div>
            </form>
        </section>

        <aside class="quiz-taking-rail">
            <section class="quiz-rail-card">
                <h3><?php echo study_icon('cloud-check'); ?> Autosave</h3>
                <div id="saveState" class="quiz-save-indicator"><?php echo study_icon('check-circle'); ?> Answers saved automatically</div>
                <p>You can continue answering while StudyLink saves each response in the background.</p>
            </section>
            <section class="quiz-rail-card">
                <h3><?php echo study_icon('list-check'); ?> Quiz Summary</h3>
                <div class="quiz-stat-row"><span>Questions</span><strong><?php echo count($questions); ?></strong></div>
                <div class="quiz-stat-row"><span>Attempt</span><strong><?php echo intval($attempt['attempt_no']); ?></strong></div>
                <div class="quiz-stat-row"><span>Answered</span><strong id="answeredRail">0</strong></div>
            </section>
            <section class="quiz-rail-card">
                <h3><?php echo study_icon('shield-lock'); ?> Secure submission</h3>
                <p>Your responses are linked only to this attempt. After final submission, they can no longer be edited.</p>
            </section>
        </aside>
    </div>
</main>

<script>
const attemptId = <?php echo $attempt_id; ?>;
const moduleQuery = <?php echo json_encode($module_query); ?>;
const csrfToken = <?php echo json_encode(csrf_token()); ?>;
const saveState = document.getElementById('saveState');
const timers = new Map();
let quizNavigationAllowed = false;
let integrityRestart = null;

function updateAnswerProgress() {
    const questionCards = Array.from(document.querySelectorAll('.student-question'));
    let answered = 0;

    questionCards.forEach(card => {
        const type = card.dataset.questionType;
        if (type === 'multiple_choice' || type === 'true_false') {
            if (card.querySelector('input[type="radio"]:checked')) answered++;
            return;
        }
        const fields = Array.from(card.querySelectorAll('textarea, input[type="text"]'));
        if (fields.some(field => field.value.trim() !== '')) answered++;
    });

    const percent = questionCards.length ? (answered / questionCards.length) * 100 : 0;
    document.getElementById('answeredCount').textContent = answered;
    document.getElementById('answeredRail').textContent = answered;
    document.getElementById('answerProgress').style.width = percent + '%';
}

function saveAnswer(container, field) {
    const questionId = container.dataset.questionId;
    const data = new FormData();
    data.append('csrf_token', csrfToken);
    data.append('attempt_id', attemptId);
    data.append('question_id', questionId);

    if (field.matches('[data-autosave-choice]')) {
        data.append('selected_choice_id', field.value);
    } else if (container.dataset.questionType === 'enumeration') {
        const values = Array.from(container.querySelectorAll('[data-enumeration-input]'))
            .map(input => input.value.trim());
        data.append('answer_text', values.join('|'));
    } else {
        data.append('answer_text', field.value);
    }

    saveState.textContent = 'Saving answer...';
    fetch('save_quiz_answer.php', {method: 'POST', body: data})
        .then(response => response.json())
        .then(result => {
            if (result.expired) {
                document.getElementById('quizForm').submit();
                return;
            }
            saveState.textContent = result.saved ? 'Answer saved' : 'Save failed';
            updateAnswerProgress();
        })
        .catch(() => { saveState.textContent = 'Save failed'; });
}

document.querySelectorAll('[data-autosave-choice]').forEach(field => {
    field.addEventListener('change', () => saveAnswer(field.closest('.student-question'), field));
});
document.querySelectorAll('[data-autosave-text]').forEach(field => {
    field.addEventListener('input', () => {
        const questionId = field.closest('.student-question').dataset.questionId;
        clearTimeout(timers.get(questionId));
        updateAnswerProgress();
        timers.set(questionId, setTimeout(() => saveAnswer(field.closest('.student-question'), field), 700));
    });
});
updateAnswerProgress();

document.getElementById('quizForm').addEventListener('submit', function(event) {
    if (!this.dataset.forceSubmit && !confirm('Submit this quiz now? You cannot change this attempt after submission.')) {
        event.preventDefault();
        return;
    }

    quizNavigationAllowed = true;
});

function restartQuizAfterTabChange() {
    if (quizNavigationAllowed || integrityRestart) {
        return;
    }

    timers.forEach(timer => clearTimeout(timer));
    saveState.textContent = 'Quiz restart in progress...';

    const data = new FormData();
    data.append('csrf_token', csrfToken);
    data.append('action', 'restart_after_tab_change');

    integrityRestart = fetch(window.location.href, {
        method: 'POST',
        body: data,
        keepalive: true,
        credentials: 'same-origin'
    }).then(response => {
        if (!response.ok) {
            throw new Error('Quiz restart failed');
        }
        return response.json();
    }).then(result => {
        if (!result.restarted && !result.completed) {
            throw new Error('Quiz restart failed');
        }
        return result;
    }).catch(() => null).finally(() => {
        if (!document.hidden) {
            window.location.replace('take_quiz.php?attempt_id=' + attemptId + moduleQuery + '&restarted=1');
        }
    });
}

document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
        restartQuizAfterTabChange();
        return;
    }

    if (integrityRestart) {
        integrityRestart.finally(() => {
            window.location.replace('take_quiz.php?attempt_id=' + attemptId + moduleQuery + '&restarted=1');
        });
    }
});

<?php if ($remaining_seconds !== null): ?>
let remaining = <?php echo intval($remaining_seconds); ?>;
const timerElement = document.getElementById('quizTimer');
function renderTimer() {
    const minutes = Math.floor(remaining / 60);
    const seconds = remaining % 60;
    timerElement.textContent = minutes + ':' + String(seconds).padStart(2, '0');
    if (remaining <= 0) {
        const form = document.getElementById('quizForm');
        form.dataset.forceSubmit = '1';
        quizNavigationAllowed = true;
        form.submit();
        return;
    }
    remaining--;
    setTimeout(renderTimer, 1000);
}
renderTimer();
<?php endif; ?>
</script>
</body>
</html>
