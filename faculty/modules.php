<?php
include('../auth/auth.php');
require_role('faculty');
include('../config/database.php');
include('../includes/faculty_ui.php');
include('../includes/academic_term.php');
include('../includes/module_workflow.php');

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
if (!$faculty) { http_response_code(403); exit('Faculty profile not found.'); }
$faculty_id = intval($faculty['id']);
$modules = [];
$classes = [];

if (studylink_modules_ready($conn)) {
    studylink_ensure_academic_terms($conn);
    studylink_sync_legacy_coursework($conn, $faculty_id, 0);
    $stmt = mysqli_prepare($conn, '
        SELECT cm.*,
               COUNT(DISTINCT cmc.class_assignment_id) AS class_count,
               COUNT(DISTINCT cmi.id) AS item_count,
               GROUP_CONCAT(DISTINCT CONCAT(s.subject_code, " · ", sec.section_name) ORDER BY cmc.class_order SEPARATOR "|||") AS class_labels
        FROM course_modules cm
        LEFT JOIN course_module_classes cmc ON cmc.module_id = cm.id
        LEFT JOIN class_assignments ca ON ca.id = cmc.class_assignment_id
        LEFT JOIN subjects s ON s.id = ca.subject_id
        LEFT JOIN sections sec ON sec.id = ca.section_id
        LEFT JOIN course_module_items cmi ON cmi.module_id = cm.id
        WHERE cm.faculty_id = ?
        GROUP BY cm.id
        ORDER BY cm.updated_at DESC
    ');
    mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $modules[] = $row;
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, '
        SELECT ca.id, s.subject_code, s.subject_name, sec.section_name,
               ay.school_year, sem.semester_name
        FROM class_assignments ca
        INNER JOIN subjects s ON s.id = ca.subject_id
        INNER JOIN sections sec ON sec.id = ca.section_id
        INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
        INNER JOIN semesters sem ON sem.id = ca.semester_id
        INNER JOIN academic_terms term ON term.academic_year_id = ca.academic_year_id AND term.semester_id = ca.semester_id
        WHERE ca.faculty_id = ? AND term.status = "current"
        ORDER BY s.subject_code, sec.section_name
    ');
    mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $classes[] = $row;
    mysqli_stmt_close($stmt);
}

$published = count(array_filter($modules, function ($module) { return $module['status'] === 'published'; }));
$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modules - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/module-interface.css">
</head>
<body class="faculty-interface module-page">
<?php render_faculty_sidebar('modules', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Modules', 'Build once and assign to one or more classes'); ?>
<main class="faculty-main"><div class="module-shell">
    <header class="module-heading">
        <div><div class="module-eyebrow">Course organization</div><h1>Learning Modules</h1><p>Group activities, quizzes, and exams into an ordered learning unit. One module can be assigned to several classes at the same time.</p></div>
        <?php if ($classes && studylink_modules_ready($conn)): ?><button class="module-button gold" type="button" id="openModuleCreator"><?php echo study_icon('plus-lg'); ?> New Module</button><?php endif; ?>
    </header>
    <?php if ($flash): ?><div class="module-notice <?php echo $flash['type'] === 'success' ? '' : 'error'; ?>"><?php echo e($flash['message']); ?></div><?php endif; ?>
    <?php if (!studylink_modules_ready($conn)): ?>
        <div class="module-notice error">Modules are not installed yet. Import <strong>sql/module_workflow.sql</strong> once, then reload this page.</div>
    <?php endif; ?>
    <section class="module-summary">
        <article><strong><?php echo count($modules); ?></strong><span>Total modules</span></article>
        <article><strong><?php echo $published; ?></strong><span>Published modules</span></article>
        <article><strong><?php echo count($classes); ?></strong><span>Active classes available</span></article>
    </section>
    <section class="module-grid">
        <?php if (!$modules): ?><div class="module-empty"><h2>No modules yet</h2><p>Create a module, choose the classes that should receive it, then add activities, quizzes, or exams.</p></div><?php endif; ?>
        <?php foreach ($modules as $module): ?>
            <?php $is_system_module = strpos($module['title'], 'Existing Coursework · Class ') === 0; ?>
            <article class="module-card">
                <div class="module-card-top"><span class="module-icon"><?php echo study_icon('collection'); ?></span><span class="module-status <?php echo $module['status'] === 'published' ? 'published' : ''; ?>"><?php echo e($module['status']); ?></span></div>
                <h2><?php echo e($module['title']); ?></h2>
                <p><?php echo e($module['description'] ?: 'No module description.'); ?></p>
                <?php $labels = array_filter(explode('|||', (string) $module['class_labels'])); ?>
                <div class="module-class-list"><?php foreach (array_slice($labels, 0, 3) as $label): ?><span class="module-class-chip"><?php echo e($label); ?></span><?php endforeach; ?><?php if (count($labels) > 3): ?><span class="module-class-chip">+<?php echo count($labels) - 3; ?> more</span><?php endif; ?></div>
                <div class="module-card-meta"><span><?php echo intval($module['class_count']); ?> class<?php echo intval($module['class_count']) === 1 ? '' : 'es'; ?></span><span><?php echo intval($module['item_count']); ?> item<?php echo intval($module['item_count']) === 1 ? '' : 's'; ?></span></div>
                <div class="module-card-actions">
                    <a class="module-button" href="/studyLink/faculty/module_view.php?id=<?php echo intval($module['id']); ?>"><?php echo study_icon('arrow-right'); ?> Open module</a>
                    <?php if ($is_system_module): ?><span class="module-protected" title="This module preserves quizzes, attempts, and grades from before the Modules update."><?php echo study_icon('lock'); ?> Protected</span><?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</div></main>

<?php if ($classes && studylink_modules_ready($conn)): ?>
<dialog class="module-dialog" id="moduleCreator">
    <form action="/studyLink/faculty/modules/create.php" method="post">
        <?php echo csrf_field(); ?>
        <div class="module-dialog-head"><div><div class="module-eyebrow">New learning unit</div><h2>Create Module</h2><p>Add the basic details first. You can add activities and assessments after creation.</p></div><button type="button" data-close-module aria-label="Close module creator"><?php echo study_icon('x-lg'); ?></button></div>
        <div class="module-dialog-body"><div class="module-form">
            <label class="module-field"><span>Module name</span><input name="title" maxlength="255" placeholder="e.g. Module 1: Introduction to Computing" required></label>
            <label class="module-field"><span>Description</span><textarea name="description" placeholder="Explain the learning goals or coverage."></textarea></label>
            <div class="module-field"><span>Assign to classes</span><div class="module-checks">
                <?php foreach ($classes as $class): ?><label class="module-check"><input type="checkbox" name="class_ids[]" value="<?php echo intval($class['id']); ?>"><span><strong><?php echo e($class['subject_code'] . ' · ' . $class['section_name']); ?></strong><small><?php echo e($class['subject_name'] . ' · ' . $class['semester_name'] . ' · ' . $class['school_year']); ?></small></span></label><?php endforeach; ?>
            </div></div>
            <button class="module-button gold" type="submit">Create module</button>
        </div></div>
    </form>
</dialog>
<?php endif; ?>

<script>
(function(){
    var creator=document.getElementById('moduleCreator');
    document.getElementById('openModuleCreator')?.addEventListener('click',function(){creator.showModal()});
    document.querySelectorAll('[data-close-module]').forEach(function(button){button.addEventListener('click',function(){creator.close()})});
    creator?.addEventListener('click',function(event){if(event.target===creator)creator.close()});
})();
</script>
</body></html>
