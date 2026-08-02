<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/student_notifications.php';

$user_id = intval($_SESSION['user_id']);
$student = null;
$quizzes = [];

$student_statement = mysqli_prepare($conn, '
    SELECT s.id, s.student_no, s.section_id, sec.section_name
    FROM students s
    INNER JOIN sections sec ON sec.id = s.section_id
    WHERE s.user_id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($student_statement, 'i', $user_id);
mysqli_stmt_execute($student_statement);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($student_statement));
mysqli_stmt_close($student_statement);

if ($student) {
    $student_id = intval($student['id']);
    $section_id = intval($student['section_id']);
    $statement = mysqli_prepare($conn, '
        SELECT q.id, q.class_assignment_id, q.title, q.instructions,
               q.time_limit, q.available_from, q.available_until,
               q.max_attempts, q.passing_score, q.created_at,
               sub.subject_code, sub.subject_name, u.fullname AS faculty_name,
               COUNT(DISTINCT qq.id) AS question_count,
               COALESCE(SUM(qq.points), 0) AS total_points,
               (
                   SELECT COUNT(*)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status <> "in_progress"
               ) AS used_attempts,
               (
                   SELECT MAX(qa.id)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status = "in_progress"
               ) AS active_attempt_id,
               (
                   SELECT MAX(qa.id)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status <> "in_progress"
               ) AS latest_attempt_id,
               (
                   SELECT MAX(qa.percentage)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status IN ("graded", "needs_review")
               ) AS best_percentage
        FROM quizzes q
        INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
        INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
        INNER JOIN semesters sem ON sem.id = ca.semester_id
        INNER JOIN subjects sub ON sub.id = ca.subject_id
        INNER JOIN faculty f ON f.id = ca.faculty_id
        INNER JOIN users u ON u.id = f.user_id
        LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id
        WHERE ca.section_id = ?
          AND ay.status = "active"
          AND sem.status = "active"
          AND q.status = "published"
        GROUP BY q.id
        ORDER BY
            CASE
                WHEN q.available_until IS NOT NULL AND q.available_until <= NOW() THEN 3
                WHEN q.available_from IS NOT NULL AND q.available_from > NOW() THEN 2
                ELSE 1
            END,
            COALESCE(q.available_until, "9999-12-31 23:59:59") ASC,
            q.created_at DESC
    ');
    mysqli_stmt_bind_param(
        $statement,
        'iiiii',
        $student_id,
        $student_id,
        $student_id,
        $student_id,
        $section_id
    );
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    while ($quiz = mysqli_fetch_assoc($result)) {
        $now = time();
        $max_attempts = max(1, intval($quiz['max_attempts']));
        $used_attempts = intval($quiz['used_attempts']);
        $quiz['attempts_left'] = max(0, $max_attempts - $used_attempts);
        $quiz['state'] = 'available';
        if (!empty($quiz['active_attempt_id'])) {
            $quiz['state'] = 'in_progress';
        } elseif (!empty($quiz['available_from']) && strtotime($quiz['available_from']) > $now) {
            $quiz['state'] = 'upcoming';
        } elseif (!empty($quiz['available_until']) && strtotime($quiz['available_until']) <= $now) {
            $quiz['state'] = 'closed';
        } elseif ($quiz['attempts_left'] <= 0) {
            $quiz['state'] = 'completed';
        }
        $quizzes[] = $quiz;
    }
    mysqli_stmt_close($statement);
}

$counts = ['available' => 0, 'in_progress' => 0, 'completed' => 0, 'upcoming' => 0, 'closed' => 0];
$score_total = 0;
$score_count = 0;
foreach ($quizzes as $quiz) {
    $counts[$quiz['state']]++;
    if ($quiz['best_percentage'] !== null) {
        $score_total += floatval($quiz['best_percentage']);
        $score_count++;
    }
}
$average_score = $score_count ? $score_total / $score_count : 0;

function quiz_date_label($value)
{
    return empty($value) ? 'No date set' : date('M j, Y · g:i A', strtotime($value));
}

function quiz_state_label($state)
{
    $labels = [
        'available' => 'Available now',
        'in_progress' => 'In progress',
        'completed' => 'Attempt limit reached',
        'upcoming' => 'Upcoming',
        'closed' => 'Closed'
    ];
    return $labels[$state] ?? ucfirst($state);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quizzes - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
</head>
<body>
<aside class="student-sidebar">
    <a class="student-brand" href="/studyLink/student/dashboard.php"><span class="student-brand-mark">S</span>StudyLink</a>
    <nav class="student-nav">
        <a href="/studyLink/student/dashboard.php"><span class="student-nav-icon"><?php echo study_icon('grid'); ?></span>Dashboard</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Courses</a>
        <a href="/studyLink/student/calendar.php"><span class="student-nav-icon"><?php echo study_icon('calendar3'); ?></span>Calendar</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('clipboard2-check'); ?></span>Assignments</a>
        <a class="active" href="/studyLink/student/quizzes.php"><span class="student-nav-icon"><?php echo study_icon('patch-question'); ?></span>Quizzes</a>
        <a href="/studyLink/student/messages.php"><span class="student-nav-icon"><?php echo study_icon('envelope'); ?></span>Messages</a>
    </nav>
    <div class="student-account">
        <div class="student-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 1)); ?></div>
        <div><div class="student-name"><?php echo htmlspecialchars($_SESSION['fullname']); ?></div><div class="student-id"><?php echo $student ? 'Student ID: ' . htmlspecialchars($student['student_no']) : 'Student account'; ?></div></div>
    </div>
</aside>
<header class="student-topbar">
    <label class="student-search"><span><?php echo study_icon('search'); ?></span><input id="quizSearch" type="search" placeholder="Search quizzes or subjects..." autocomplete="off"></label>
    <div class="student-tools"><?php render_student_notification_button($conn, $user_id); ?><a class="account-logout" href="/studyLink/auth/logout.php"><span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span><span class="account-logout-label">Log out</span></a></div>
</header>
<main class="student-main"><div class="student-shell">
    <section class="page-heading">
        <div><div class="eyebrow">Academic Assessment</div><h1>Quizzes</h1><p class="lead">Take available quizzes for the Current Academic Term and review your attempt results.</p></div>
        <span class="term-pill"><?php echo $student ? htmlspecialchars($student['section_name']) : 'Student'; ?></span>
    </section>

    <?php if (!$student): ?>
        <div class="empty-state">Your student profile is incomplete. Please contact the administrator.</div>
    <?php else: ?>
        <section class="quiz-hub-stats">
            <article class="card quiz-hub-stat"><span class="stat-icon"><?php echo study_icon('play-circle'); ?></span><div><small>Ready to take</small><strong><?php echo $counts['available']; ?></strong></div></article>
            <article class="card quiz-hub-stat"><span class="stat-icon"><?php echo study_icon('clock-history'); ?></span><div><small>In progress</small><strong><?php echo $counts['in_progress']; ?></strong></div></article>
            <article class="card quiz-hub-stat"><span class="stat-icon"><?php echo study_icon('check-circle'); ?></span><div><small>Completed</small><strong><?php echo $counts['completed'] + $counts['closed']; ?></strong></div></article>
            <article class="card quiz-hub-stat"><span class="stat-icon"><?php echo study_icon('bar-chart'); ?></span><div><small>Average score</small><strong><?php echo number_format($average_score, 0); ?>%</strong></div></article>
        </section>

        <div class="quiz-hub-toolbar">
            <button class="quiz-filter active" type="button" data-filter="all">All</button>
            <button class="quiz-filter" type="button" data-filter="available">Available</button>
            <button class="quiz-filter" type="button" data-filter="in_progress">In Progress</button>
            <button class="quiz-filter" type="button" data-filter="completed">Completed</button>
            <button class="quiz-filter" type="button" data-filter="upcoming">Upcoming</button>
        </div>

        <section class="quiz-hub-list" id="quizHubList">
        <?php if (!$quizzes): ?>
            <div class="empty-state">No published quizzes are available yet.</div>
        <?php endif; ?>
        <?php foreach ($quizzes as $quiz): ?>
            <?php $filter_state = in_array($quiz['state'], ['closed', 'completed'], true) ? 'completed' : $quiz['state']; ?>
            <article class="card quiz-hub-card" data-state="<?php echo htmlspecialchars($filter_state); ?>">
                <div class="quiz-hub-main">
                    <div class="quiz-hub-title-row"><span class="badge"><?php echo htmlspecialchars($quiz['subject_code']); ?></span><span class="quiz-state <?php echo htmlspecialchars($quiz['state']); ?>"><?php echo htmlspecialchars(quiz_state_label($quiz['state'])); ?></span></div>
                    <h2><?php echo htmlspecialchars($quiz['title']); ?></h2>
                    <?php if (!empty($quiz['instructions'])): ?><p><?php echo nl2br(htmlspecialchars($quiz['instructions'])); ?></p><?php endif; ?>
                    <div class="quiz-hub-meta">
                        <span><?php echo study_icon('list-ol'); ?> <?php echo intval($quiz['question_count']); ?> questions</span>
                        <span><?php echo study_icon('award'); ?> <?php echo number_format(floatval($quiz['total_points']), 2); ?> points</span>
                        <span><?php echo study_icon('stopwatch'); ?> <?php echo intval($quiz['time_limit']) > 0 ? intval($quiz['time_limit']) . ' minutes' : 'No time limit'; ?></span>
                        <span><?php echo study_icon('arrow-repeat'); ?> <?php echo intval($quiz['used_attempts']); ?>/<?php echo max(1, intval($quiz['max_attempts'])); ?> attempts used</span>
                    </div>
                    <div class="quiz-hub-schedule"><span>Opens: <?php echo htmlspecialchars(quiz_date_label($quiz['available_from'])); ?></span><span>Closes: <?php echo htmlspecialchars(quiz_date_label($quiz['available_until'])); ?></span></div>
                </div>
                <div class="quiz-hub-actions">
                    <?php if ($quiz['best_percentage'] !== null): ?><div class="quiz-best"><small>Best score</small><strong><?php echo number_format(floatval($quiz['best_percentage']), 2); ?>%</strong></div><?php endif; ?>
                    <?php if ($quiz['state'] === 'in_progress'): ?>
                        <a class="button" href="take_quiz.php?attempt_id=<?php echo intval($quiz['active_attempt_id']); ?>"><?php echo study_icon('play-circle'); ?> Continue Quiz</a>
                    <?php elseif ($quiz['state'] === 'available'): ?>
                        <form action="start_quiz.php" method="post" onsubmit="return confirm('Start this quiz attempt now? The timer starts immediately.');">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="quiz_id" value="<?php echo intval($quiz['id']); ?>">
                            <button class="button" type="submit"><?php echo study_icon(intval($quiz['used_attempts']) > 0 ? 'arrow-repeat' : 'play-circle'); ?> <?php echo intval($quiz['used_attempts']) > 0 ? 'Try Again' : 'Take Quiz'; ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($quiz['latest_attempt_id'])): ?><a class="button secondary" href="quiz_result.php?attempt_id=<?php echo intval($quiz['latest_attempt_id']); ?>"><?php echo study_icon('bar-chart'); ?> View Result</a><?php endif; ?>
                    <a class="quiz-course-link" href="class_view.php?id=<?php echo intval($quiz['class_assignment_id']); ?>&tab=quizzes">Open <?php echo htmlspecialchars($quiz['subject_code']); ?></a>
                </div>
            </article>
        <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div></main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var search = document.getElementById('quizSearch');
    var buttons = Array.from(document.querySelectorAll('.quiz-filter'));
    var cards = Array.from(document.querySelectorAll('.quiz-hub-card'));
    var activeFilter = 'all';

    function applyFilters() {
        var query = search ? search.value.trim().toLowerCase() : '';
        cards.forEach(function (card) {
            var stateMatches = activeFilter === 'all' || card.dataset.state === activeFilter;
            var searchMatches = !query || card.textContent.toLowerCase().indexOf(query) !== -1;
            card.hidden = !(stateMatches && searchMatches);
        });
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            buttons.forEach(function (item) { item.classList.remove('active'); });
            button.classList.add('active');
            activeFilter = button.dataset.filter;
            applyFilters();
        });
    });
    if (search) search.addEventListener('input', applyFilters);
});
</script>
</body>
</html>
