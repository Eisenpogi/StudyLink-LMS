<?php
$class_id = intval($class['id']);
$section_id = intval($class['section_id']);

$overview = [
    'students' => 0,
    'materials' => 0,
    'assignments' => 0,
    'quizzes' => 0,
    'attendance' => 0
];

$summary = mysqli_prepare($conn, '
    SELECT
        (SELECT COUNT(*) FROM students WHERE section_id = ?) AS students,
        (SELECT COUNT(*) FROM learning_materials WHERE class_assignment_id = ?) AS materials,
        (SELECT COUNT(*) FROM assignments WHERE class_assignment_id = ?) AS assignments,
        (SELECT COUNT(*) FROM quizzes WHERE class_assignment_id = ?) AS quizzes
');
mysqli_stmt_bind_param($summary, 'iiii', $section_id, $class_id, $class_id, $class_id);
mysqli_stmt_execute($summary);
$summary_row = mysqli_fetch_assoc(mysqli_stmt_get_result($summary));
mysqli_stmt_close($summary);
if ($summary_row) {
    $overview = array_merge($overview, $summary_row);
}

$attendance_ready = false;
$attendance_check = mysqli_query($conn, "SHOW TABLES LIKE 'attendance_sessions'");
if ($attendance_check && mysqli_num_rows($attendance_check) > 0) {
    $attendance_ready = true;
    $attendance_statement = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM attendance_sessions WHERE class_assignment_id = ?');
    mysqli_stmt_bind_param($attendance_statement, 'i', $class_id);
    mysqli_stmt_execute($attendance_statement);
    $attendance_row = mysqli_fetch_assoc(mysqli_stmt_get_result($attendance_statement));
    $overview['attendance'] = intval($attendance_row['total'] ?? 0);
    mysqli_stmt_close($attendance_statement);
}

$upcoming = [];
$assignment_statement = mysqli_prepare($conn, '
    SELECT id, title, due_date
    FROM assignments
    WHERE class_assignment_id = ? AND due_date >= NOW()
    ORDER BY due_date ASC
    LIMIT 4
');
mysqli_stmt_bind_param($assignment_statement, 'i', $class_id);
mysqli_stmt_execute($assignment_statement);
$result = mysqli_stmt_get_result($assignment_statement);
while ($row = mysqli_fetch_assoc($result)) {
    $upcoming[] = [
        'type' => 'Assignment',
        'title' => $row['title'],
        'date' => $row['due_date'],
        'url' => '?id=' . $class_id . '&tab=assignments'
    ];
}
mysqli_stmt_close($assignment_statement);

$quiz_statement = mysqli_prepare($conn, '
    SELECT id, title, available_until
    FROM quizzes
    WHERE class_assignment_id = ? AND status = "published" AND available_until IS NOT NULL AND available_until >= NOW()
    ORDER BY available_until ASC
    LIMIT 4
');
mysqli_stmt_bind_param($quiz_statement, 'i', $class_id);
mysqli_stmt_execute($quiz_statement);
$result = mysqli_stmt_get_result($quiz_statement);
while ($row = mysqli_fetch_assoc($result)) {
    $upcoming[] = [
        'type' => 'Quiz',
        'title' => $row['title'],
        'date' => $row['available_until'],
        'url' => '?id=' . $class_id . '&tab=quizzes'
    ];
}
mysqli_stmt_close($quiz_statement);

usort($upcoming, function ($left, $right) {
    return strtotime($left['date']) <=> strtotime($right['date']);
});
$upcoming = array_slice($upcoming, 0, 5);
?>

<header class="faculty-module-head">
    <div>
        <div class="faculty-eyebrow">Course command center</div>
        <h2>Class Overview</h2>
        <p>Monitor content, assessments, students, and attendance from one workspace.</p>
    </div>
</header>

<section class="faculty-compact-stats">
    <article class="faculty-compact-stat"><span>Students</span><strong><?php echo intval($overview['students']); ?></strong></article>
    <article class="faculty-compact-stat"><span>Materials</span><strong><?php echo intval($overview['materials']); ?></strong></article>
    <article class="faculty-compact-stat"><span>Assignments</span><strong><?php echo intval($overview['assignments']); ?></strong></article>
    <article class="faculty-compact-stat"><span>Quizzes</span><strong><?php echo intval($overview['quizzes']); ?></strong></article>
    <article class="faculty-compact-stat"><span>Attendance</span><strong><?php echo intval($overview['attendance']); ?></strong></article>
</section>

<div class="faculty-overview-layout">
    <section class="faculty-module-panel">
        <div class="faculty-panel-heading"><h3>Upcoming work</h3><span>Nearest deadlines</span></div>
        <?php if (!$upcoming): ?>
            <div class="faculty-empty">No upcoming assignment or quiz deadlines.</div>
        <?php else: ?>
            <div class="faculty-list">
                <?php foreach ($upcoming as $item): ?>
                    <a class="faculty-list-row" href="<?php echo e($item['url']); ?>">
                        <span class="faculty-list-icon"><?php echo $item['type'] === 'Quiz' ? '✓' : '▤'; ?></span>
                        <span class="faculty-list-copy"><strong><?php echo e($item['title']); ?></strong><span><?php echo e($item['type']); ?> · <?php echo e(date('M d, Y · g:i A', strtotime($item['date']))); ?></span></span>
                        <span class="faculty-status"><?php echo e($item['type']); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <aside class="faculty-module-panel">
        <div class="faculty-panel-heading"><h3>Quick actions</h3><span>Course tools</span></div>
        <div class="faculty-quick-actions">
            <a href="?id=<?php echo $class_id; ?>&tab=materials">Add material</a>
            <a href="?id=<?php echo $class_id; ?>&tab=assignments">Create assignment</a>
            <a href="?id=<?php echo $class_id; ?>&tab=quizzes">Create quiz</a>
            <a href="?id=<?php echo $class_id; ?>&tab=attendance">Take attendance</a>
            <a href="?id=<?php echo $class_id; ?>&tab=students">View students</a>
            <a href="/studyLink/faculty/drive/index.php">Open Faculty Drive</a>
        </div>
        <?php if (!$attendance_ready): ?>
            <div class="faculty-notice error" style="margin:0 17px 17px;">Import <code>sql/add_attendance.sql</code> once to activate Attendance.</div>
        <?php endif; ?>
    </aside>
</div>
