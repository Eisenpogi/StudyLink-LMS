<?php
$class_modules = [];
if (studylink_modules_ready($conn)) {
    studylink_sync_legacy_coursework($conn, 0, intval($class['section_id'] ?? 0));
    $stmt = mysqli_prepare($conn, '
        SELECT cm.id, cm.title, cm.description, cm.updated_at,
               COUNT(DISTINCT cmr.id) AS item_count,
               COUNT(DISTINCT CASE
                   WHEN cmr.assignment_id IS NOT NULL AND asm.id IS NOT NULL THEN cmr.id
                   WHEN cmr.quiz_id IS NOT NULL AND qa.id IS NOT NULL THEN cmr.id
               END) AS completed_count
        FROM course_modules cm
        INNER JOIN course_module_classes cmc ON cmc.module_id = cm.id AND cmc.class_assignment_id = ?
        LEFT JOIN course_module_items cmi ON cmi.module_id = cm.id
        LEFT JOIN course_module_resources cmr ON cmr.module_item_id = cmi.id AND cmr.class_assignment_id = ?
        LEFT JOIN assignment_submissions asm ON asm.assignment_id = cmr.assignment_id AND asm.student_id = ?
        LEFT JOIN quiz_attempts qa ON qa.quiz_id = cmr.quiz_id AND qa.student_id = ? AND qa.status <> "in_progress"
        WHERE cm.status = "published"
        GROUP BY cm.id
        ORDER BY cm.updated_at DESC
    ');
    $student_id_for_modules = intval($class['student_id']);
    mysqli_stmt_bind_param($stmt, 'iiii', $class_id, $class_id, $student_id_for_modules, $student_id_for_modules);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) $class_modules[] = $row;
    mysqli_stmt_close($stmt);
}
?>
<div class="student-module-list">
    <?php if (!studylink_modules_ready($conn)): ?><div class="empty">The Modules feature has not been installed yet.</div>
    <?php elseif (!$class_modules): ?><div class="empty">No published modules are available for this class yet.</div><?php endif; ?>
    <?php foreach ($class_modules as $module): ?>
        <a class="student-module-card searchable-item" href="/studyLink/student/module_view.php?id=<?php echo intval($module['id']); ?>&class_id=<?php echo $class_id; ?>">
            <span class="module-icon"><?php echo study_icon('collection'); ?></span>
            <div><div class="module-eyebrow"><?php echo e($class['subject_code']); ?> module</div><h2><?php echo e($module['title']); ?></h2><p><?php echo e($module['description'] ?: 'Open this module to view its activities and assessments.'); ?></p></div>
            <div class="student-module-count"><strong><?php echo intval($module['completed_count']); ?>/<?php echo intval($module['item_count']); ?></strong><br>completed</div>
        </a>
    <?php endforeach; ?>
</div>
