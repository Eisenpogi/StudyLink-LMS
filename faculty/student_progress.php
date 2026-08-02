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

$students = [];
$stmt = mysqli_prepare($conn, '
    SELECT
        st.id,
        st.student_no,
        u.fullname,
        sec.section_name,
        COUNT(DISTINCT ca.id) AS course_count,
        COUNT(DISTINCT asm.id) AS submitted_assignments,
        COUNT(DISTINCT a.id) AS total_assignments,
        AVG(asm.score) AS assignment_average,
        AVG(qa.percentage) AS quiz_average
    FROM class_assignments ca
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN students st ON st.section_id = ca.section_id
    INNER JOIN users u ON u.id = st.user_id
    LEFT JOIN assignments a ON a.class_assignment_id = ca.id
    LEFT JOIN assignment_submissions asm ON asm.assignment_id = a.id AND asm.student_id = st.id
    LEFT JOIN quizzes q ON q.class_assignment_id = ca.id
    LEFT JOIN quiz_attempts qa ON qa.quiz_id = q.id AND qa.student_id = st.id AND qa.status = "graded"
    WHERE ca.faculty_id = ? AND ay.status = "active" AND sem.status = "active"
    GROUP BY st.id, st.student_no, u.fullname, sec.section_name
    ORDER BY sec.section_name, u.fullname
');
mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $students[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <title>Student Progress - StudyLink</title>
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('progress', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Student Progress', 'Current-term performance across your assigned sections'); ?>
<main class="faculty-main">
    <div class="faculty-shell">
        <section class="faculty-page-heading"><div><div class="faculty-eyebrow">Learning Analytics</div><h1>Student Progress</h1><p>Use current-term submission and quiz scores to identify learners who may need support.</p></div></section>
        <div class="faculty-card faculty-filterbar"><label class="grow"><input id="studentSearch" type="search" placeholder="Search student, ID, or section..."></label></div>
        <section class="faculty-card faculty-table-wrap">
            <table class="faculty-table">
                <thead><tr><th>Student</th><th>Section</th><th>Submission Progress</th><th>Assignment Avg.</th><th>Quiz Avg.</th><th>Standing</th></tr></thead>
                <tbody>
                <?php if (empty($students)): ?><tr><td colspan="6"><div class="faculty-empty">No students are assigned to your current sections.</div></td></tr><?php endif; ?>
                <?php foreach ($students as $student): ?>
                    <?php
                    $total = intval($student['total_assignments']);
                    $submitted = intval($student['submitted_assignments']);
                    $completion = $total > 0 ? min(100, round(($submitted / $total) * 100)) : 0;
                    $scores = array_filter([$student['assignment_average'], $student['quiz_average']], fn($value) => $value !== null);
                    $standing = empty($scores) ? null : array_sum($scores) / count($scores);
                    ?>
                    <tr data-student-search="<?php echo e(strtolower($student['fullname'] . ' ' . $student['student_no'] . ' ' . $student['section_name'])); ?>">
                        <td><strong><?php echo e($student['fullname']); ?></strong><br><small><?php echo e($student['student_no']); ?></small></td>
                        <td><?php echo e($student['section_name']); ?><br><small><?php echo intval($student['course_count']); ?> course(s)</small></td>
                        <td style="min-width:180px;"><div style="display:flex;justify-content:space-between;margin-bottom:6px;"><small><?php echo $submitted; ?>/<?php echo $total; ?></small><small><?php echo $completion; ?>%</small></div><div class="faculty-progress-bar"><span style="width:<?php echo $completion; ?>%;"></span></div></td>
                        <td><?php echo $student['assignment_average'] !== null ? round(floatval($student['assignment_average']), 1) . '%' : '—'; ?></td>
                        <td><?php echo $student['quiz_average'] !== null ? round(floatval($student['quiz_average']), 1) . '%' : '—'; ?></td>
                        <td><span class="faculty-status <?php echo $standing !== null && $standing < 75 ? 'urgent' : ($standing !== null ? 'success' : ''); ?>"><?php echo $standing === null ? 'No grades yet' : ($standing < 75 ? 'Needs support' : 'On track'); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    </div>
</main>
<script>
document.getElementById('studentSearch').addEventListener('input', function () {
    var query = this.value.toLowerCase().trim();
    document.querySelectorAll('[data-student-search]').forEach(function (row) {
        row.hidden = query && row.dataset.studentSearch.indexOf(query) === -1;
    });
});
</script>
</body>
</html>
