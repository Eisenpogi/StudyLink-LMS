<?php
$class_assignment_id = intval($class['id']);
$quizzes = [];

$statement = mysqli_prepare($conn, '
    SELECT q.*,
           (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS total_questions,
           (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status <> "in_progress") AS attempt_count,
           (SELECT AVG(qa.percentage) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status IN ("graded", "needs_review")) AS average_score
    FROM quizzes q
    WHERE q.class_assignment_id = ?
    ORDER BY q.created_at DESC
');
mysqli_stmt_bind_param($statement, 'i', $class_assignment_id);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
while ($row = mysqli_fetch_assoc($result)) $quizzes[] = $row;
mysqli_stmt_close($statement);

$published_count = 0;
$draft_count = 0;
$attempt_count = 0;
$pending_review_count = 0;
$average_scores = [];
foreach ($quizzes as $quiz_row) {
    if ($quiz_row['status'] === 'published') {
        $published_count++;
    } else {
        $draft_count++;
    }
    $attempt_count += intval($quiz_row['attempt_count']);
    if ($quiz_row['average_score'] !== null) {
        $average_scores[] = floatval($quiz_row['average_score']);
    }
}
$course_average = $average_scores
    ? array_sum($average_scores) / count($average_scores)
    : null;
?>

<link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">

<header class="faculty-module-head">
    <div>
        <div class="faculty-eyebrow">Academic assessment</div>
        <h2>Manage Quizzes</h2>
        <p>Create structured assessments, manage availability, and review student performance.</p>
    </div>
    <?php if ($term_writable): ?>
        <button class="faculty-button" type="button" id="openCreateQuiz">
            <?php echo study_icon('plus-lg'); ?> Create Quiz
        </button>
    <?php else: ?>
        <span class="faculty-pill"><?php echo study_icon('lock'); ?> Read only</span>
    <?php endif; ?>
</header>

<section class="quiz-summary-grid">
    <article class="quiz-summary-card">
        <span class="summary-icon"><?php echo study_icon('collection'); ?></span>
        <strong><?php echo count($quizzes); ?></strong><span>Total quizzes</span>
    </article>
    <article class="quiz-summary-card">
        <span class="summary-icon"><?php echo study_icon('broadcast'); ?></span>
        <strong><?php echo $published_count; ?></strong><span>Published</span>
    </article>
    <article class="quiz-summary-card">
        <span class="summary-icon"><?php echo study_icon('people'); ?></span>
        <strong><?php echo $attempt_count; ?></strong><span>Completed attempts</span>
    </article>
    <article class="quiz-summary-card">
        <span class="summary-icon"><?php echo study_icon('bar-chart'); ?></span>
        <strong><?php echo $course_average === null ? '—' : number_format($course_average, 1) . '%'; ?></strong><span>Average score</span>
    </article>
</section>

<section class="quiz-manager-grid">
    <div>
        <div class="quiz-list-head">
            <div><h2>Course Quizzes</h2><p><?php echo $published_count; ?> published and <?php echo $draft_count; ?> draft</p></div>
        </div>
        <div class="quiz-manager-list">
        <?php if (!$quizzes): ?>
            <div class="quiz-empty">
                <span class="empty-icon"><?php echo study_icon('clipboard2-plus'); ?></span>
                <h3>No quizzes yet</h3>
                <p>Create your first quiz, add questions, configure its schedule, then publish it for students.</p>
            </div>
        <?php endif; ?>
        <?php foreach ($quizzes as $quiz): ?>
            <article class="quiz-manager-card">
                <div class="quiz-manager-icon"><?php echo study_icon($quiz['status'] === 'published' ? 'clipboard2-check' : 'clipboard2'); ?></div>
                <div>
                    <div class="quiz-card-meta">
                        <span class="faculty-pill <?php echo $quiz['status'] === 'published' ? 'success' : 'warning'; ?>"><?php echo e(ucfirst($quiz['status'])); ?></span>
                        <span><?php echo study_icon('list-ol'); ?> <?php echo intval($quiz['total_questions']); ?> questions</span>
                        <span><?php echo study_icon('arrow-repeat'); ?> <?php echo intval($quiz['max_attempts']); ?> attempt<?php echo intval($quiz['max_attempts']) === 1 ? '' : 's'; ?></span>
                        <span><?php echo study_icon('people'); ?> <?php echo intval($quiz['attempt_count']); ?> completed</span>
                    </div>
                    <h3><?php echo e($quiz['title']); ?></h3>
                    <?php if (!empty($quiz['instructions'])): ?><p><?php echo nl2br(e($quiz['instructions'])); ?></p><?php endif; ?>
                    <div class="quiz-card-meta" style="margin-top:9px">
                        <span><?php echo study_icon('calendar-event'); ?> <?php echo $quiz['available_from'] ? 'Opens ' . e(date('M d, Y · g:i A', strtotime($quiz['available_from']))) : 'Available immediately'; ?></span>
                        <span><?php echo study_icon('calendar-x'); ?> <?php echo $quiz['available_until'] ? 'Due ' . e(date('M d, Y · g:i A', strtotime($quiz['available_until']))) : 'No deadline'; ?></span>
                        <span><?php echo study_icon('graph-up'); ?> <?php echo $quiz['average_score'] !== null ? number_format(floatval($quiz['average_score']), 1) . '% average' : 'No score data'; ?></span>
                    </div>
                </div>
                <div class="quiz-card-actions">
                    <a class="quiz-action small primary" href="quizzes/quiz_builder.php?quiz_id=<?php echo intval($quiz['id']); ?>&tab=questions">
                        <?php echo study_icon($term_writable ? 'pencil-square' : 'eye'); ?> <?php echo $term_writable ? 'Manage' : 'View'; ?>
                    </a>
                    <a class="quiz-action small secondary" href="quizzes/preview.php?quiz_id=<?php echo intval($quiz['id']); ?>">
                        <?php echo study_icon('eye'); ?> Preview
                    </a>
                    <?php if ($term_writable): ?><form action="quizzes/delete.php" method="post" onsubmit="return confirm('Delete this quiz and all related attempts?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="quiz_id" value="<?php echo intval($quiz['id']); ?>">
                        <input type="hidden" name="class_id" value="<?php echo $class_assignment_id; ?>">
                        <button class="quiz-action small danger" type="submit" aria-label="Delete quiz">
                            <?php echo study_icon('trash3'); ?>
                        </button>
                    </form><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        </div>
    </div>

    <aside class="quiz-side-panel">
        <div class="quiz-eyebrow">Assessment workflow</div>
        <h3>Quiz checklist</h3>
        <div class="quiz-stat-row"><span>1. Build quiz details</span><?php echo study_icon('check2-circle'); ?></div>
        <div class="quiz-stat-row"><span>2. Add and review questions</span><?php echo study_icon('list-check'); ?></div>
        <div class="quiz-stat-row"><span>3. Set schedule and rules</span><?php echo study_icon('sliders'); ?></div>
        <div class="quiz-stat-row"><span>4. Preview and publish</span><?php echo study_icon('send-check'); ?></div>
        <p style="margin:15px 0 0;color:var(--quiz-muted);font-size:12px;line-height:1.55">Students only see published quizzes. Opening dates and deadlines also appear automatically in their Calendar.</p>
    </aside>
</section>

<?php if ($term_writable): ?>
<dialog class="faculty-quiz-dialog" id="createQuizDialog">
    <form action="quizzes/create.php" method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="class_assignment_id" value="<?php echo $class_assignment_id; ?>">
        <input type="hidden" name="time_limit" value="0">
        <div class="quiz-dialog-head">
            <div>
                <div class="quiz-eyebrow">New assessment</div>
                <h2>Create Quiz</h2>
                <p>Start with the quiz details. Questions, rules, and publishing are managed in the builder.</p>
            </div>
            <button class="quiz-icon-button" type="button" data-close-quiz aria-label="Close"><?php echo study_icon('x-lg'); ?></button>
        </div>
        <div class="quiz-dialog-body">
            <div class="quiz-form-grid">
                <label class="quiz-field full"><span>Quiz title</span><input type="text" name="title" maxlength="255" placeholder="e.g. Unit 4 Knowledge Check" required></label>
                <label class="quiz-field full"><span>Instructions</span><textarea name="instructions" placeholder="Optional instructions for students."></textarea></label>
                <label class="quiz-field"><span>Available from</span><input type="datetime-local" name="available_from"></label>
                <label class="quiz-field"><span>Quiz deadline</span><input type="datetime-local" name="available_until"></label>
                <div class="quiz-dialog-actions">
                    <button class="quiz-action secondary" type="button" data-close-quiz>Cancel</button>
                    <button class="quiz-action primary" type="submit"><?php echo study_icon('arrow-right-circle'); ?> Create and Continue</button>
                </div>
            </div>
        </div>
    </form>
</dialog>

<script>
(function () {
    var dialog = document.getElementById('createQuizDialog');
    document.getElementById('openCreateQuiz')?.addEventListener('click', function () { dialog.showModal(); });
    document.querySelectorAll('[data-close-quiz]').forEach(function (button) {
        button.addEventListener('click', function () { dialog.close(); });
    });
    dialog?.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
})();
</script>
<?php endif; ?>
