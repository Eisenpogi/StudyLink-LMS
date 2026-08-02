<?php
$section_id = intval($class['section_id']);
$class_id = intval($class['id']);
$students = [];

$statement = mysqli_prepare($conn, '
    SELECT
        s.id,
        s.student_no,
        u.fullname,
        u.username,
        (SELECT COUNT(*) FROM assignment_submissions asm
         INNER JOIN assignments a ON a.id = asm.assignment_id
         WHERE asm.student_id = s.id AND a.class_assignment_id = ?) AS assignment_submissions,
        (SELECT COUNT(*) FROM quiz_attempts qa
         INNER JOIN quizzes q ON q.id = qa.quiz_id
         WHERE qa.student_id = s.id AND q.class_assignment_id = ? AND qa.status <> "in_progress") AS quiz_attempts
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.section_id = ?
    ORDER BY u.fullname ASC
');
mysqli_stmt_bind_param($statement, 'iii', $class_id, $class_id, $section_id);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
while ($row = mysqli_fetch_assoc($result)) {
    $students[] = $row;
}
mysqli_stmt_close($statement);
?>

<header class="faculty-module-head">
    <div>
        <div class="faculty-eyebrow">Class roster</div>
        <h2>Students</h2>
        <p><?php echo count($students); ?> enrolled student<?php echo count($students) === 1 ? '' : 's'; ?> in <?php echo e($class['section_name']); ?>.</p>
    </div>
    <input class="faculty-table-search" id="studentRosterSearch" type="search" placeholder="Search name or student number..." autocomplete="off">
</header>

<section class="faculty-module-panel">
    <div class="faculty-table-wrap">
        <table class="faculty-table">
            <thead><tr><th>Student</th><th>Student No.</th><th>Assignment Activity</th><th>Quiz Activity</th></tr></thead>
            <tbody>
            <?php if (!$students): ?>
                <tr><td colspan="4"><div class="faculty-empty">No students are assigned to this section.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($students as $student): ?>
                <tr data-student-row data-search="<?php echo e(strtolower($student['fullname'] . ' ' . $student['student_no'] . ' ' . $student['username'])); ?>">
                    <td><span class="faculty-student-avatar"><?php echo e(strtoupper(substr($student['fullname'], 0, 1))); ?></span><strong><?php echo e($student['fullname']); ?></strong></td>
                    <td><?php echo e($student['student_no']); ?></td>
                    <td><span class="faculty-pill"><?php echo intval($student['assignment_submissions']); ?> submitted</span></td>
                    <td><span class="faculty-pill success"><?php echo intval($student['quiz_attempts']); ?> completed</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
document.getElementById('studentRosterSearch')?.addEventListener('input', function () {
    var query = this.value.toLowerCase().trim();
    document.querySelectorAll('[data-student-row]').forEach(function (row) {
        row.hidden = query !== '' && row.dataset.search.indexOf(query) === -1;
    });
});
</script>
