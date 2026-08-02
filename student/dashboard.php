<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/faculty_ui.php';
include '../includes/student_notifications.php';

$user_id = intval($_SESSION['user_id']);
$student = null;

$student_statement = mysqli_prepare(
    $conn,
    'SELECT s.id, s.student_no, s.section_id,
            sec.section_name, sec.course, sec.year_level
     FROM students s
     INNER JOIN sections sec ON sec.id = s.section_id
     WHERE s.user_id = ?
     LIMIT 1'
);

if ($student_statement) {
    mysqli_stmt_bind_param($student_statement, 'i', $user_id);
    mysqli_stmt_execute($student_statement);
    $student_result = mysqli_stmt_get_result($student_statement);
    $student = mysqli_fetch_assoc($student_result);
    mysqli_stmt_close($student_statement);
}

$classes = [];
$class_count = 0;
$assignment_count = 0;
$published_quiz_count = 0;
$cover_expression = faculty_cover_expression($conn, 'ca');
$color_expression = faculty_color_expression($conn, 'ca');

if ($student) {
    $section_id = intval($student['section_id']);

    $classes_statement = mysqli_prepare(
        $conn,
        "SELECT ca.id, {$cover_expression} AS cover_image, {$color_expression} AS cover_color, sub.subject_code, sub.subject_name,
                ay.school_year, sem.semester_name, u.fullname AS faculty_name
         FROM class_assignments ca
         INNER JOIN subjects sub ON sub.id = ca.subject_id
         INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
         INNER JOIN semesters sem ON sem.id = ca.semester_id
         INNER JOIN faculty f ON f.id = ca.faculty_id
         INNER JOIN users u ON u.id = f.user_id
         WHERE ca.section_id = ? AND ay.status = 'active' AND sem.status = 'active'
         ORDER BY ay.status DESC, sem.status DESC, sub.subject_name ASC"
    );

    if ($classes_statement) {
        mysqli_stmt_bind_param($classes_statement, 'i', $section_id);
        mysqli_stmt_execute($classes_statement);
        $classes_result = mysqli_stmt_get_result($classes_statement);

        while ($row = mysqli_fetch_assoc($classes_result)) {
            $classes[] = $row;
        }

        mysqli_stmt_close($classes_statement);
    }

    $class_count = count($classes);

    $summary_statement = mysqli_prepare(
        $conn,
        'SELECT
            COUNT(DISTINCT a.id) AS assignment_count,
            COUNT(DISTINCT q.id) AS published_quiz_count
         FROM class_assignments ca
         INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
         INNER JOIN semesters sem ON sem.id = ca.semester_id
         LEFT JOIN assignments a
            ON a.class_assignment_id = ca.id
            AND a.due_date >= NOW()
         LEFT JOIN quizzes q
            ON q.class_assignment_id = ca.id
            AND q.status = "published"
            AND (q.available_from IS NULL OR q.available_from <= NOW())
            AND (q.available_until IS NULL OR q.available_until >= NOW())
            AND (
                EXISTS (
                    SELECT 1 FROM quiz_attempts qa_active
                    WHERE qa_active.quiz_id = q.id
                      AND qa_active.student_id = ?
                      AND qa_active.status = "in_progress"
                )
                OR (
                    SELECT COUNT(*) FROM quiz_attempts qa_used
                    WHERE qa_used.quiz_id = q.id
                      AND qa_used.student_id = ?
                      AND qa_used.status <> "in_progress"
                ) < q.max_attempts
            )
         WHERE ca.section_id = ? AND ay.status = "active" AND sem.status = "active"'
    );

    if ($summary_statement) {
        $student_id = intval($student['id']);
        mysqli_stmt_bind_param($summary_statement, 'iii', $student_id, $student_id, $section_id);
        mysqli_stmt_execute($summary_statement);
        $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($summary_statement));
        $assignment_count = intval($summary['assignment_count'] ?? 0);
        $published_quiz_count = intval($summary['published_quiz_count'] ?? 0);
        mysqli_stmt_close($summary_statement);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
</head>
<body>
<aside class="student-sidebar">
    <a class="student-brand" href="/studyLink/student/dashboard.php"><span class="student-brand-mark">S</span>StudyLink</a>
    <nav class="student-nav">
        <a class="active" href="/studyLink/student/dashboard.php"><span class="student-nav-icon"><?php echo study_icon('grid'); ?></span>Dashboard</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Courses</a>
        <a href="/studyLink/student/calendar.php"><span class="student-nav-icon"><?php echo study_icon('calendar3'); ?></span>Calendar</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('clipboard2-check'); ?></span>Assignments</a>
        <a href="/studyLink/student/quizzes.php"><span class="student-nav-icon"><?php echo study_icon('patch-question'); ?></span>Quizzes</a>
        <a href="/studyLink/student/messages.php"><span class="student-nav-icon"><?php echo study_icon('envelope'); ?></span>Messages</a>
    </nav>
    <div class="student-account"><div class="student-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 1)); ?></div><div><div class="student-name"><?php echo htmlspecialchars($_SESSION['fullname']); ?></div><div class="student-id"><?php echo $student ? 'Student ID: ' . htmlspecialchars($student['student_no']) : 'Student account'; ?></div></div></div>
</aside>
<header class="student-topbar">
    <label class="student-search"><span><?php echo study_icon('search'); ?></span><input id="dashboardSearch" type="search" placeholder="Search courses, lessons, or grades..." autocomplete="off"></label>
    <div class="student-tools"><?php render_student_notification_button($conn, $user_id); ?><a class="account-logout" href="/studyLink/auth/logout.php"><span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span><span class="account-logout-label">Log out</span></a></div>
</header>
<main class="student-main"><div class="student-shell">
<?php if (!$student): ?>
    <div class="empty-state">Your student profile is incomplete. Please contact the administrator.</div>
<?php else: ?>
    <section class="page-heading"><div><div class="eyebrow"><?php echo htmlspecialchars($student['section_name']); ?> · <?php echo htmlspecialchars($student['course'] . ' ' . $student['year_level']); ?></div><h1>Good day, <?php echo htmlspecialchars(explode(' ', trim($_SESSION['fullname']))[0]); ?>.</h1><p class="lead">Here is your current StudyLink academic overview.</p></div><a class="button" href="/studyLink/student/classes.php">View My Courses <?php echo study_icon('arrow-right'); ?></a></section>
    <section class="stats-grid">
        <article class="card stat-card"><div class="stat-icon"><?php echo study_icon('mortarboard'); ?></div><div class="stat-label">My Classes</div><div class="stat-value"><?php echo $class_count; ?></div></article>
        <article class="card stat-card"><div class="stat-icon"><?php echo study_icon('clipboard2-check'); ?></div><div class="stat-label">Upcoming Assignments</div><div class="stat-value"><?php echo $assignment_count; ?></div></article>
        <a class="card stat-card dashboard-stat-link" href="/studyLink/student/quizzes.php"><div class="stat-icon"><?php echo study_icon('patch-question'); ?></div><div class="stat-label">Available Quizzes</div><div class="stat-value"><?php echo $published_quiz_count; ?></div></a>
    </section>
    <section><div class="section-head"><h2>Current Courses</h2><a class="text-link" href="/studyLink/student/classes.php">View All Courses</a></div>
    <?php if (!$classes): ?><div class="empty-state">No classes are assigned to your section yet.</div>
    <?php else: ?><div class="course-grid">
        <?php foreach ($classes as $index => $class): ?>
        <?php $cover_url = faculty_course_cover_url($class['cover_image']); ?>
        <a class="card course-card dashboard-course" href="/studyLink/student/class_view.php?id=<?php echo intval($class['id']); ?>">
            <div class="course-cover <?php echo $cover_url ? 'has-image' : ''; ?>" style="<?php echo e(faculty_course_gradient_style($class['id'], $class['cover_color'] ?? '')); ?><?php echo $cover_url ? 'background-image:url(' . e($cover_url) . ');' : ''; ?>"><span class="course-code"><?php echo htmlspecialchars($class['subject_code']); ?></span><span class="course-title"><?php echo htmlspecialchars($class['subject_name']); ?></span></div>
            <div class="course-body"><div class="course-meta">Faculty: <?php echo htmlspecialchars($class['faculty_name']); ?></div><div class="course-meta"><?php echo htmlspecialchars($class['school_year']); ?> · <?php echo htmlspecialchars($class['semester_name']); ?></div><div class="progress"><span style="width:<?php echo 45 + (($index * 17) % 40); ?>%"></span></div><div class="text-link">Open Course <?php echo study_icon('arrow-right'); ?></div></div>
        </a>
        <?php endforeach; ?>
    </div><?php endif; ?></section>
<?php endif; ?>
</div></main>
<script>
document.getElementById('dashboardSearch')?.addEventListener('input', function () {
    var query = this.value.toLowerCase().trim();
    document.querySelectorAll('.dashboard-course').forEach(function (card) {
        card.style.display = !query || card.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
});
</script>
</body>
</html>
