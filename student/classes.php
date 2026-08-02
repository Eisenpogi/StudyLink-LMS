<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/faculty_ui.php';
include '../includes/student_notifications.php';

$user_id = intval($_SESSION['user_id']);
$student = null;
$classes = [];
$semesters = [];
$term_key = trim((string) ($_GET['term'] ?? 'current'));
$cover_expression = faculty_cover_expression($conn, 'ca');
$color_expression = faculty_color_expression($conn, 'ca');

$student_statement = mysqli_prepare(
    $conn,
    'SELECT s.id, s.section_id, s.student_no,
            sec.section_name, sec.course, sec.year_level
     FROM students s
     INNER JOIN sections sec ON sec.id = s.section_id
     WHERE s.user_id = ?
     LIMIT 1'
);

if ($student_statement) {
    mysqli_stmt_bind_param($student_statement, 'i', $user_id);
    mysqli_stmt_execute($student_statement);
    $student = mysqli_fetch_assoc(mysqli_stmt_get_result($student_statement));
    mysqli_stmt_close($student_statement);
}

if ($student) {
    $section_id = intval($student['section_id']);
    $semester_statement = mysqli_prepare(
        $conn,
        'SELECT DISTINCT sem.id, sem.semester_name, sem.status,
                ay.id AS academic_year_id, ay.school_year, ay.status AS academic_year_status
         FROM class_assignments ca
         INNER JOIN semesters sem ON sem.id = ca.semester_id
         INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
         WHERE ca.section_id = ?
         ORDER BY ay.status DESC, sem.status DESC, ay.school_year DESC, sem.semester_name ASC'
    );
    if ($semester_statement) {
        mysqli_stmt_bind_param($semester_statement, 'i', $section_id);
        mysqli_stmt_execute($semester_statement);
        $semester_result = mysqli_stmt_get_result($semester_statement);
        while ($semester = mysqli_fetch_assoc($semester_result)) {
            $semesters[] = $semester;
        }
        mysqli_stmt_close($semester_statement);
    }

    $where = 'ca.section_id = ?';
    $types = 'i';
    $params = [$section_id];
    if ($term_key === 'current') {
        $where .= ' AND ay.status = "active" AND sem.status = "active"';
    } elseif (preg_match('/^(\d+):(\d+)$/', $term_key, $matches)) {
        $where .= ' AND ca.academic_year_id = ? AND ca.semester_id = ?';
        $types .= 'ii';
        $params[] = intval($matches[1]);
        $params[] = intval($matches[2]);
    } else {
        $term_key = 'all';
    }

    $statement = mysqli_prepare(
        $conn,
        "SELECT ca.id, {$cover_expression} AS cover_image, {$color_expression} AS cover_color, sub.subject_code, sub.subject_name, sub.description,
                ay.school_year, ay.status AS academic_year_status,
                sem.semester_name, sem.status AS semester_status,
                u.fullname AS faculty_name
         FROM class_assignments ca
         INNER JOIN subjects sub ON sub.id = ca.subject_id
         INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
         INNER JOIN semesters sem ON sem.id = ca.semester_id
         INNER JOIN faculty f ON f.id = ca.faculty_id
         INNER JOIN users u ON u.id = f.user_id
         WHERE {$where}
         ORDER BY ay.status DESC, sem.status DESC, sub.subject_name ASC"
    );

    if ($statement) {
        mysqli_stmt_bind_param($statement, $types, ...$params);
        mysqli_stmt_execute($statement);
        $result = mysqli_stmt_get_result($statement);

        while ($row = mysqli_fetch_assoc($result)) {
            $classes[] = $row;
        }

        mysqli_stmt_close($statement);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Classes - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
</head>
<body>
<aside class="student-sidebar"><a class="student-brand" href="/studyLink/student/dashboard.php"><span class="student-brand-mark">S</span>StudyLink</a><nav class="student-nav"><a href="/studyLink/student/dashboard.php"><span class="student-nav-icon"><?php echo study_icon('grid'); ?></span>Dashboard</a><a class="active" href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Courses</a><a href="/studyLink/student/calendar.php"><span class="student-nav-icon"><?php echo study_icon('calendar3'); ?></span>Calendar</a><a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('clipboard2-check'); ?></span>Assignments</a><a href="/studyLink/student/quizzes.php"><span class="student-nav-icon"><?php echo study_icon('patch-question'); ?></span>Quizzes</a><a href="/studyLink/student/messages.php"><span class="student-nav-icon"><?php echo study_icon('envelope'); ?></span>Messages</a></nav><div class="student-account"><div class="student-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 1)); ?></div><div><div class="student-name"><?php echo htmlspecialchars($_SESSION['fullname']); ?></div><div class="student-id"><?php echo $student ? 'Student ID: ' . htmlspecialchars($student['student_no']) : 'Student account'; ?></div></div></div></aside>
<header class="student-topbar"><label class="student-search"><span><?php echo study_icon('search'); ?></span><input id="courseSearch" type="search" placeholder="Search your courses..." autocomplete="off"></label><div class="student-tools"><?php render_student_notification_button($conn, $user_id); ?><a class="account-logout" href="/studyLink/auth/logout.php"><span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span><span class="account-logout-label">Log out</span></a></div></header>
<main class="student-main"><div class="student-shell">
<section class="page-heading"><div><div class="eyebrow">Academic Courses</div><h1>My Courses</h1><p class="lead"><?php echo $student ? htmlspecialchars($student['section_name'] . ' · ' . $student['course'] . ' ' . $student['year_level']) : 'Student workspace'; ?></p></div><div class="course-heading-tools"><form class="semester-filter" method="get"><label for="termSelect">Academic Term</label><select id="termSelect" name="term" onchange="this.form.submit()"><option value="current" <?php echo $term_key === 'current' ? 'selected' : ''; ?>>Current Academic Term</option><option value="all" <?php echo $term_key === 'all' ? 'selected' : ''; ?>>All Terms</option><?php foreach ($semesters as $semester): ?><?php $option_key = intval($semester['academic_year_id'] ?? 0) . ':' . intval($semester['id']); ?><option value="<?php echo htmlspecialchars($option_key); ?>" <?php echo $term_key === $option_key ? 'selected' : ''; ?>><?php echo htmlspecialchars($semester['school_year'] . ' · ' . $semester['semester_name'] . (($semester['status'] === 'active' && $semester['academic_year_status'] === 'active') ? ' (Current)' : '')); ?></option><?php endforeach; ?></select></form><span class="term-pill"><?php echo count($classes); ?> course<?php echo count($classes) === 1 ? '' : 's'; ?></span></div></section>
<?php if (!$student): ?><div class="empty-state">Your student profile is incomplete. Please contact the administrator.</div>
<?php elseif (!$classes): ?><div class="empty-state">No classes are assigned to your section yet.</div>
<?php else: ?><div class="class-grid">
<?php foreach ($classes as $class): ?>
    <?php $cover_url = faculty_course_cover_url($class['cover_image']); ?>
    <a class="card class-card searchable-course" href="/studyLink/student/class_view.php?id=<?php echo intval($class['id']); ?>">
        <div class="class-card-cover <?php echo $cover_url ? 'has-image' : ''; ?>" style="<?php echo e(faculty_course_gradient_style($class['id'], $class['cover_color'] ?? '')); ?><?php echo $cover_url ? 'background-image:url(' . e($cover_url) . ');' : ''; ?>"><span class="badge"><?php echo htmlspecialchars($class['subject_code']); ?></span><h3><?php echo htmlspecialchars($class['subject_name']); ?></h3></div>
        <div class="class-card-body">
            <?php if (!empty($class['description'])): ?><p><?php echo nl2br(htmlspecialchars($class['description'])); ?></p><?php endif; ?>
            <p><strong>Faculty:</strong> <?php echo htmlspecialchars($class['faculty_name']); ?></p>
            <p><?php echo htmlspecialchars($class['school_year']); ?> · <?php echo htmlspecialchars($class['semester_name']); ?><?php echo $class['academic_year_status'] === 'active' && $class['semester_status'] === 'active' ? ' · Current' : ''; ?></p>
            <span class="button">Open Course <?php echo study_icon('arrow-right'); ?></span>
        </div>
    </a>
<?php endforeach; ?>
</div><?php endif; ?>
</div></main>
<script>document.getElementById('courseSearch')?.addEventListener('input',function(){var q=this.value.toLowerCase().trim();document.querySelectorAll('.searchable-course').forEach(function(c){c.style.display=!q||c.textContent.toLowerCase().includes(q)?'':'none'})});</script>
</body>
</html>
