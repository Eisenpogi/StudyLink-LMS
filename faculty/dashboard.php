<?php
include('../auth/auth.php');
require_role('faculty');
include('../config/database.php');
include('../includes/faculty_ui.php');

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
if (!$faculty) {
    http_response_code(403);
    exit('Faculty profile not found.');
}

$faculty_id = intval($faculty['id']);
$cover_expression = faculty_cover_expression($conn, 'ca');
$color_expression = faculty_color_expression($conn, 'ca');

$summary_stmt = mysqli_prepare($conn, '
    SELECT
        (SELECT COUNT(*) FROM class_assignments ca INNER JOIN academic_years ay ON ay.id = ca.academic_year_id AND ay.status = "active" INNER JOIN semesters sem ON sem.id = ca.semester_id AND sem.status = "active" WHERE ca.faculty_id = ?) AS course_count,
        (SELECT COUNT(DISTINCT s.id) FROM class_assignments ca INNER JOIN academic_years ay ON ay.id = ca.academic_year_id AND ay.status = "active" INNER JOIN semesters sem ON sem.id = ca.semester_id AND sem.status = "active" INNER JOIN students s ON s.section_id = ca.section_id WHERE ca.faculty_id = ?) AS student_count,
        (SELECT COUNT(*) FROM assignment_submissions asm INNER JOIN assignments a ON a.id = asm.assignment_id INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id INNER JOIN academic_years ay ON ay.id = ca.academic_year_id AND ay.status = "active" INNER JOIN semesters sem ON sem.id = ca.semester_id AND sem.status = "active" WHERE ca.faculty_id = ? AND asm.score IS NULL) AS pending_assignments,
        (SELECT COUNT(*) FROM quiz_attempts qa INNER JOIN quizzes q ON q.id = qa.quiz_id INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id INNER JOIN academic_years ay ON ay.id = ca.academic_year_id AND ay.status = "active" INNER JOIN semesters sem ON sem.id = ca.semester_id AND sem.status = "active" WHERE ca.faculty_id = ? AND qa.status IN ("needs_review", "submitted")) AS pending_quizzes,
        (SELECT AVG(asm.score) FROM assignment_submissions asm INNER JOIN assignments a ON a.id = asm.assignment_id INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id INNER JOIN academic_years ay ON ay.id = ca.academic_year_id AND ay.status = "active" INNER JOIN semesters sem ON sem.id = ca.semester_id AND sem.status = "active" WHERE ca.faculty_id = ? AND asm.score IS NOT NULL) AS assignment_average
');
mysqli_stmt_bind_param($summary_stmt, 'iiiii', $faculty_id, $faculty_id, $faculty_id, $faculty_id, $faculty_id);
mysqli_stmt_execute($summary_stmt);
$summary = mysqli_fetch_assoc(mysqli_stmt_get_result($summary_stmt));
mysqli_stmt_close($summary_stmt);

$courses = [];
$course_sql = "
    SELECT
        ca.id,
        {$cover_expression} AS cover_image,
        {$color_expression} AS cover_color,
        sub.subject_code,
        sub.subject_name,
        sec.section_name,
        sem.semester_name,
        ay.school_year,
        COUNT(DISTINCT st.id) AS student_count,
        COUNT(DISTINCT lm.id) AS material_count,
        COUNT(DISTINCT CASE WHEN asm.score IS NULL THEN asm.id END) AS pending_count
    FROM class_assignments ca
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    LEFT JOIN students st ON st.section_id = ca.section_id
    LEFT JOIN learning_materials lm ON lm.class_assignment_id = ca.id
    LEFT JOIN assignments a ON a.class_assignment_id = ca.id
    LEFT JOIN assignment_submissions asm ON asm.assignment_id = a.id
    WHERE ca.faculty_id = ? AND ay.status = 'active' AND sem.status = 'active'
    GROUP BY ca.id, sub.subject_code, sub.subject_name, sec.section_name, sem.semester_name, ay.school_year
    ORDER BY ay.id DESC, sem.id DESC, sub.subject_name
    LIMIT 4
";
$stmt = mysqli_prepare($conn, $course_sql);
mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $courses[] = $row;
}
mysqli_stmt_close($stmt);

$queue = [];
$stmt = mysqli_prepare($conn, '
    SELECT
        asm.id,
        asm.submitted_at,
        asm.submission_status,
        a.id AS assignment_id,
        a.title,
        u.fullname AS student_name,
        sub.subject_code,
        "assignment" AS item_type
    FROM assignment_submissions asm
    INNER JOIN assignments a ON a.id = asm.assignment_id
    INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN students st ON st.id = asm.student_id
    INNER JOIN users u ON u.id = st.user_id
    WHERE ca.faculty_id = ? AND ay.status = "active" AND sem.status = "active" AND asm.score IS NULL
    ORDER BY (asm.submission_status = "late") DESC, asm.submitted_at ASC
    LIMIT 6
');
mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $queue[] = $row;
}
mysqli_stmt_close($stmt);

$upcoming = [];
$stmt = mysqli_prepare($conn, '
    SELECT title, due_date AS event_date, subject_code, "Assignment" AS event_type
    FROM assignments a
    INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    WHERE ca.faculty_id = ? AND ay.status = "active" AND sem.status = "active" AND a.due_date >= NOW()
    UNION ALL
    SELECT q.title, q.available_until AS event_date, sub.subject_code, "Quiz" AS event_type
    FROM quizzes q
    INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    WHERE ca.faculty_id = ? AND ay.status = "active" AND sem.status = "active" AND q.status = "published" AND q.available_until >= NOW()
    ORDER BY event_date
    LIMIT 5
');
mysqli_stmt_bind_param($stmt, 'ii', $faculty_id, $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $upcoming[] = $row;
}
mysqli_stmt_close($stmt);

$pending_total = intval($summary['pending_assignments'] ?? 0) + intval($summary['pending_quizzes'] ?? 0);
$average = $summary['assignment_average'] !== null ? round(floatval($summary['assignment_average']), 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <title>Faculty Dashboard - StudyLink</title>
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('dashboard', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Faculty Dashboard', 'Academic oversight and teaching workspace'); ?>

<main class="faculty-main">
    <div class="faculty-shell">
        <section class="faculty-page-heading">
            <div>
                <div class="faculty-eyebrow">Academic Oversight</div>
                <h1>Good <?php echo date('H') < 12 ? 'morning' : (date('H') < 18 ? 'afternoon' : 'evening'); ?>, <?php echo e($_SESSION['fullname']); ?>.</h1>
                <p>Manage your courses, review submissions, and follow student progress from one place.</p>
            </div>
            <a class="faculty-button" href="/studyLink/faculty/classes.php">Open My Courses →</a>
        </section>

        <section class="faculty-stats">
            <article class="faculty-card faculty-stat primary"><div class="faculty-stat-icon">↗</div><div><strong><?php echo $average ? $average . '%' : '—'; ?></strong><span>Average graded score</span></div></article>
            <article class="faculty-card faculty-stat"><div class="faculty-stat-icon">✓</div><div><strong><?php echo $pending_total; ?></strong><span>Submissions pending</span></div></article>
            <article class="faculty-card faculty-stat"><div class="faculty-stat-icon">▤</div><div><strong><?php echo intval($summary['course_count'] ?? 0); ?></strong><span>Assigned courses</span></div></article>
            <article class="faculty-card faculty-stat"><div class="faculty-stat-icon">♙</div><div><strong><?php echo intval($summary['student_count'] ?? 0); ?></strong><span>Students reached</span></div></article>
        </section>

        <div class="faculty-dashboard-grid">
            <div>
                <section class="faculty-section">
                    <div class="faculty-section-head"><h2>Active Courses</h2><a class="faculty-text-link" href="/studyLink/faculty/classes.php">See all courses</a></div>
                    <?php if (empty($courses)): ?>
                        <div class="faculty-card faculty-empty">No course has been assigned to your faculty account yet.</div>
                    <?php else: ?>
                        <div class="faculty-course-grid">
                            <?php foreach ($courses as $course): ?>
                                <?php $cover_url = faculty_course_cover_url($course['cover_image']); ?>
                                <article class="faculty-card faculty-course-card">
                                    <div class="faculty-course-cover <?php echo $cover_url ? 'has-image' : ''; ?>" style="<?php echo e(faculty_course_gradient_style($course['id'], $course['cover_color'] ?? '')); ?><?php echo $cover_url ? 'background-image:url(' . e($cover_url) . ');' : ''; ?>">
                                        <span class="faculty-course-code"><?php echo e($course['subject_code']); ?></span>
                                        <h3><?php echo e($course['subject_name']); ?></h3>
                                        <p><?php echo e($course['section_name'] . ' · ' . $course['semester_name']); ?></p>
                                    </div>
                                    <div class="faculty-course-body">
                                        <div class="faculty-course-metrics">
                                            <div><strong><?php echo intval($course['student_count']); ?></strong><span>Students</span></div>
                                            <div><strong><?php echo intval($course['material_count']); ?></strong><span>Resources</span></div>
                                            <div><strong><?php echo intval($course['pending_count']); ?></strong><span>To grade</span></div>
                                        </div>
                                        <div class="faculty-course-actions"><small><?php echo e($course['school_year']); ?></small><a class="faculty-button small" href="/studyLink/faculty/class_view.php?id=<?php echo intval($course['id']); ?>">Open Class</a></div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="faculty-section">
                    <div class="faculty-section-head"><h2>Priority Grading Queue</h2><a class="faculty-text-link" href="/studyLink/faculty/grading_queue.php">View full queue</a></div>
                    <div class="faculty-card faculty-list">
                        <?php if (empty($queue)): ?><div class="faculty-empty">Your assignment grading queue is clear.</div><?php endif; ?>
                        <?php foreach ($queue as $item): ?>
                            <div class="faculty-list-row">
                                <span class="faculty-list-icon">▤</span>
                                <span class="faculty-list-copy"><strong><?php echo e($item['title']); ?></strong><span><?php echo e($item['student_name'] . ' · ' . $item['subject_code']); ?></span></span>
                                <a class="faculty-button small light" href="/studyLink/faculty/assignments/submissions.php?id=<?php echo intval($item['assignment_id']); ?>">Review</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <aside class="faculty-rail">
                <section class="faculty-card faculty-rail-card">
                    <h3>Upcoming Deadlines</h3>
                    <?php if (empty($upcoming)): ?><p>No scheduled assignment or quiz deadlines.</p><?php endif; ?>
                    <?php foreach ($upcoming as $event): ?>
                        <div class="faculty-rail-line"><span><strong><?php echo e($event['title']); ?></strong><br><small><?php echo e($event['subject_code'] . ' · ' . $event['event_type']); ?></small></span><time><?php echo date('M d', strtotime($event['event_date'])); ?></time></div>
                    <?php endforeach; ?>
                </section>
                <section class="faculty-card faculty-rail-card navy">
                    <div class="faculty-eyebrow">Quick Action</div>
                    <h3>Review pending work</h3>
                    <p>Open the unified queue for assignment files and quiz answers that need faculty review.</p>
                    <a class="faculty-button gold" href="/studyLink/faculty/grading_queue.php">Open Grading Queue</a>
                </section>
                <section class="faculty-card faculty-rail-card">
                    <h3>Communication</h3>
                    <p>Message students in your assigned sections or contact an administrator.</p>
                    <a class="faculty-button light" href="/studyLink/faculty/messages.php">Open Messages</a>
                </section>
            </aside>
        </div>
    </div>
</main>
</body>
</html>
