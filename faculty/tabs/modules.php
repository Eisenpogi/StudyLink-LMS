<?php
$class_modules = [];
if (studylink_modules_ready($conn)) {
    $stmt = mysqli_prepare($conn, '
        SELECT cm.id, cm.title, cm.description, cm.status, cm.updated_at,
               COUNT(DISTINCT cmi.id) AS item_count,
               COUNT(DISTINCT cmc.class_assignment_id) AS class_count
        FROM course_modules cm
        INNER JOIN course_module_classes current_class ON current_class.module_id = cm.id AND current_class.class_assignment_id = ?
        LEFT JOIN course_module_classes cmc ON cmc.module_id = cm.id
        LEFT JOIN course_module_items cmi ON cmi.module_id = cm.id
        WHERE cm.faculty_id = ?
        GROUP BY cm.id
        ORDER BY cm.updated_at DESC
    ');
    $faculty_id_for_modules = intval($faculty['id']);
    mysqli_stmt_bind_param($stmt, 'ii', $class_id, $faculty_id_for_modules);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $class_modules[] = $row;
    mysqli_stmt_close($stmt);
}
?>
<link rel="stylesheet" href="/studyLink/assets/css/module-interface.css">
<header class="faculty-module-head">
    <div><div class="faculty-eyebrow">Learning organization</div><h2>Modules</h2><p>Activities, quizzes, and exams for this class are organized inside modules.</p></div>
    <?php if ($term_writable): ?><a class="faculty-button small" href="/studyLink/faculty/modules.php"><?php echo study_icon('plus-lg'); ?> Manage Modules</a><?php endif; ?>
</header>
<?php if (!studylink_modules_ready($conn)): ?><div class="faculty-notice error">Import <strong>sql/module_workflow.sql</strong> to enable Modules.</div><?php endif; ?>
<div class="module-grid">
    <?php if (studylink_modules_ready($conn) && !$class_modules): ?><div class="module-empty"><h2>No modules for this class</h2><p>Create a module and select this class from the module assignment list.</p></div><?php endif; ?>
    <?php foreach ($class_modules as $module): ?><article class="module-card">
        <div class="module-card-top"><span class="module-icon"><?php echo study_icon('collection'); ?></span><span class="module-status <?php echo $module['status'] === 'published' ? 'published' : ''; ?>"><?php echo e($module['status']); ?></span></div>
        <h2><?php echo e($module['title']); ?></h2><p><?php echo e($module['description'] ?: 'No module description.'); ?></p>
        <div class="module-card-meta"><span><?php echo intval($module['item_count']); ?> items</span><span>Shared with <?php echo intval($module['class_count']); ?> class<?php echo intval($module['class_count']) === 1 ? '' : 'es'; ?></span></div>
        <div class="module-card-actions"><a class="module-button" href="/studyLink/faculty/module_view.php?id=<?php echo intval($module['id']); ?>">Open module</a></div>
    </article><?php endforeach; ?>
</div>
