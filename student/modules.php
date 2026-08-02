<?php
include('../auth/auth.php');
require_role('student');
include('../config/database.php');
include('../includes/student_ui.php');
include('../includes/academic_term.php');
include('../includes/module_workflow.php');

$user_id = intval($_SESSION['user_id']);
$stmt = mysqli_prepare($conn, 'SELECT id, student_no, section_id FROM students WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$student) { http_response_code(403); exit('Student profile not found.'); }

$modules = [];
if (studylink_modules_ready($conn)) {
    $student_id = intval($student['id']);
    $section_id = intval($student['section_id']);
    studylink_ensure_academic_terms($conn);
    studylink_sync_legacy_coursework($conn, 0, $section_id);
    $stmt = mysqli_prepare($conn, '
        SELECT cm.id, cm.title, cm.description, cm.updated_at,
               ca.id AS class_assignment_id, s.subject_code, s.subject_name, sec.section_name,
               ay.school_year, sem.semester_name,
               COALESCE(term.status, "available") AS term_status,
               COUNT(DISTINCT cmr.id) AS item_count,
               COUNT(DISTINCT CASE
                   WHEN cmr.assignment_id IS NOT NULL AND asm.id IS NOT NULL THEN cmr.id
                   WHEN cmr.quiz_id IS NOT NULL AND qa.id IS NOT NULL THEN cmr.id
               END) AS completed_count
        FROM course_modules cm
        INNER JOIN course_module_classes cmc ON cmc.module_id = cm.id
        INNER JOIN class_assignments ca ON ca.id = cmc.class_assignment_id
        INNER JOIN subjects s ON s.id = ca.subject_id
        INNER JOIN sections sec ON sec.id = ca.section_id
        INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
        INNER JOIN semesters sem ON sem.id = ca.semester_id
        LEFT JOIN academic_terms term ON term.academic_year_id = ca.academic_year_id AND term.semester_id = ca.semester_id
        LEFT JOIN course_module_items cmi ON cmi.module_id = cm.id
        LEFT JOIN course_module_resources cmr ON cmr.module_item_id = cmi.id AND cmr.class_assignment_id = ca.id
        LEFT JOIN assignment_submissions asm ON asm.assignment_id = cmr.assignment_id AND asm.student_id = ?
        LEFT JOIN quiz_attempts qa ON qa.quiz_id = cmr.quiz_id AND qa.student_id = ? AND qa.status <> "in_progress"
        WHERE ca.section_id = ? AND cm.status = "published"
        GROUP BY cm.id, ca.id
        ORDER BY (term.status = "current") DESC, ay.id DESC, sem.id DESC, cm.updated_at DESC
    ');
    mysqli_stmt_bind_param($stmt, 'iii', $student_id, $student_id, $section_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $modules[] = $row;
    mysqli_stmt_close($stmt);
}

$current_count = count(array_filter($modules, function ($module) { return $module['term_status'] === 'current'; }));
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Modules - StudyLink</title>
<link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
<link rel="stylesheet" href="/studyLink/assets/css/module-interface.css">
</head><body class="student-interface module-page">
<?php render_student_sidebar('modules', $student['student_no']); ?>
<?php render_student_topbar($conn, 'Modules', 'moduleSearch', 'Search modules or courses...'); ?>
<main class="student-main"><div class="module-shell">
    <header class="module-heading"><div><div class="module-eyebrow">Learning workspace</div><h1>Course Modules</h1><p>Open a module to access its activities, quizzes, and exams in the order prepared by your teacher.</p></div></header>
    <?php if (!studylink_modules_ready($conn)): ?><div class="module-notice error">The Modules feature has not been installed yet.</div><?php endif; ?>
    <section class="module-summary">
        <article><strong><?php echo count($modules); ?></strong><span>Available modules</span></article>
        <article><strong><?php echo $current_count; ?></strong><span>Current-term modules</span></article>
        <article><strong><?php echo array_sum(array_map(function ($module) { return intval($module['item_count']); }, $modules)); ?></strong><span>Total learning items</span></article>
    </section>
    <section class="student-module-list" id="moduleList">
        <?php if (!$modules): ?><div class="module-empty"><h2>No modules available</h2><p>Published modules from your teachers will appear here.</p></div><?php endif; ?>
        <?php foreach ($modules as $module): ?>
            <a class="student-module-card" data-module-search="<?php echo e(strtolower($module['title'] . ' ' . $module['subject_code'] . ' ' . $module['subject_name'])); ?>" href="/studyLink/student/module_view.php?id=<?php echo intval($module['id']); ?>&class_id=<?php echo intval($module['class_assignment_id']); ?>">
                <span class="module-icon"><?php echo study_icon('collection'); ?></span>
                <div><div class="module-eyebrow"><?php echo e($module['subject_code'] . ' · ' . $module['section_name']); ?> · <?php echo e($module['term_status']); ?></div><h2><?php echo e($module['title']); ?></h2><p><?php echo e($module['description'] ?: $module['subject_name']); ?></p><div class="module-class-list"><span class="module-class-chip"><?php echo e($module['semester_name'] . ' · ' . $module['school_year']); ?></span></div></div>
                <div class="student-module-count"><strong><?php echo intval($module['completed_count']); ?>/<?php echo intval($module['item_count']); ?></strong><br>completed</div>
            </a>
        <?php endforeach; ?>
    </section>
</div></main>
<script>
(function(){var input=document.getElementById('moduleSearch');input?.addEventListener('input',function(){var q=input.value.trim().toLowerCase();document.querySelectorAll('[data-module-search]').forEach(function(card){card.hidden=q!==''&&!card.dataset.moduleSearch.includes(q)})})})();
</script>
<script src="/studyLink/assets/js/app-shell.js"></script>
</body></html>
