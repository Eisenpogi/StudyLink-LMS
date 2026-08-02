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
$term_key = trim((string) ($_GET['term'] ?? 'current'));

$semesters = [];
$stmt = mysqli_prepare($conn, '
    SELECT DISTINCT sem.id, sem.semester_name, sem.status,
           ay.school_year, ay.id AS academic_year_id, ay.status AS academic_year_status
    FROM class_assignments ca
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    WHERE ca.faculty_id = ?
    ORDER BY ay.id DESC, sem.id DESC
');
mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $semesters[] = $row;
}
mysqli_stmt_close($stmt);

$where = 'ca.faculty_id = ?';
$types = 'i';
$params = [$faculty_id];
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

$sql = "
    SELECT
        ca.id,
        {$cover_expression} AS cover_image,
        {$color_expression} AS cover_color,
        sub.subject_code,
        sub.subject_name,
        sec.section_name,
        ay.school_year,
        sem.semester_name,
        COUNT(DISTINCT st.id) AS student_count,
        COUNT(DISTINCT lm.id) AS material_count,
        COUNT(DISTINCT a.id) AS assignment_count,
        COUNT(DISTINCT q.id) AS quiz_count
    FROM class_assignments ca
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    LEFT JOIN students st ON st.section_id = ca.section_id
    LEFT JOIN learning_materials lm ON lm.class_assignment_id = ca.id
    LEFT JOIN assignments a ON a.class_assignment_id = ca.id
    LEFT JOIN quizzes q ON q.class_assignment_id = ca.id
    WHERE {$where}
    GROUP BY ca.id, sub.subject_code, sub.subject_name, sec.section_name, ay.school_year, sem.semester_name
    ORDER BY ay.id DESC, sem.id DESC, sub.subject_name, sec.section_name
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$courses = [];
while ($row = mysqli_fetch_assoc($result)) {
    $courses[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <title>My Courses - StudyLink</title>
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('courses', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('My Courses', 'Manage classes assigned to your faculty account'); ?>

<main class="faculty-main">
    <div class="faculty-shell">
        <section class="faculty-page-heading">
            <div><div class="faculty-eyebrow">Teaching Workspace</div><h1>My Courses</h1><p>Open a course to manage students, materials, assignments, quizzes, and attendance.</p></div>
        </section>

        <form class="faculty-card faculty-filterbar" method="get">
            <label class="grow"><input id="courseSearch" type="search" placeholder="Search subject code, course, or section..."></label>
            <select name="term" onchange="this.form.submit()">
                <option value="current" <?php echo $term_key === 'current' ? 'selected' : ''; ?>>Current Academic Term</option>
                <option value="all" <?php echo $term_key === 'all' ? 'selected' : ''; ?>>All Previous & Current Terms</option>
                <?php foreach ($semesters as $semester): ?>
                    <?php $option_key = intval($semester['academic_year_id']) . ':' . intval($semester['id']); ?>
                    <option value="<?php echo e($option_key); ?>" <?php echo $term_key === $option_key ? 'selected' : ''; ?>>
                        <?php echo e($semester['semester_name'] . ' · AY ' . $semester['school_year'] . (($semester['status'] === 'active' && $semester['academic_year_status'] === 'active') ? ' · Current' : '')); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <?php if (empty($courses)): ?>
            <div class="faculty-card faculty-empty">No courses match the selected semester.</div>
        <?php else: ?>
            <div class="faculty-course-grid" id="courseGrid">
                <?php foreach ($courses as $course): ?>
                    <?php $cover_url = faculty_course_cover_url($course['cover_image']); ?>
                    <article class="faculty-card faculty-course-card" data-course-search="<?php echo e(strtolower($course['subject_code'] . ' ' . $course['subject_name'] . ' ' . $course['section_name'])); ?>">
                        <div class="faculty-course-cover <?php echo $cover_url ? 'has-image' : ''; ?>" style="<?php echo e(faculty_course_gradient_style($course['id'], $course['cover_color'] ?? '')); ?><?php echo $cover_url ? 'background-image:url(' . e($cover_url) . ');' : ''; ?>">
                            <span class="faculty-course-code"><?php echo e($course['subject_code']); ?></span>
                            <h3><?php echo e($course['subject_name']); ?></h3>
                            <p><?php echo e($course['section_name'] . ' · ' . $course['semester_name']); ?></p>
                        </div>
                        <div class="faculty-course-body">
                            <div class="faculty-course-metrics">
                                <div><strong><?php echo intval($course['student_count']); ?></strong><span>Students</span></div>
                                <div><strong><?php echo intval($course['material_count']); ?></strong><span>Materials</span></div>
                                <div><strong><?php echo intval($course['assignment_count']) + intval($course['quiz_count']); ?></strong><span>Assessments</span></div>
                            </div>
                            <div class="faculty-course-actions">
                                <small><?php echo e($course['school_year']); ?></small>
                                <div>
                                    <a class="faculty-button small light" href="/studyLink/faculty/class_view.php?id=<?php echo intval($course['id']); ?>&appearance=1">Customize</a>
                                    <a class="faculty-button small" href="/studyLink/faculty/class_view.php?id=<?php echo intval($course['id']); ?>">Open Class →</a>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<script>
var courseSearch = document.getElementById('courseSearch');
if (courseSearch) {
    courseSearch.addEventListener('input', function () {
        var query = this.value.toLowerCase().trim();
        document.querySelectorAll('[data-course-search]').forEach(function (card) {
            card.hidden = query !== '' && card.dataset.courseSearch.indexOf(query) === -1;
        });
    });
}
</script>
</body>
</html>
