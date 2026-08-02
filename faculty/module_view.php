<?php
include('../auth/auth.php');
require_role('faculty');
include('../config/database.php');
include('../includes/faculty_ui.php');
include('../includes/academic_term.php');
include('../includes/module_workflow.php');

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
$faculty_id = $faculty ? intval($faculty['id']) : 0;
$module_id = intval($_GET['id'] ?? 0);
if (!studylink_modules_ready($conn)) redirect_with_flash('/studyLink/faculty/modules.php', 'error', 'Import sql/module_workflow.sql first.');
studylink_ensure_academic_terms($conn);
studylink_sync_legacy_coursework($conn, $faculty_id, 0);
$module = studylink_faculty_module($conn, $module_id, $faculty_id);
if (!$module) { http_response_code(403); exit('Module not found or unauthorized access.'); }

$classes = [];
$stmt = mysqli_prepare($conn, '
    SELECT ca.id, s.subject_code, s.subject_name, sec.section_name, ay.school_year, sem.semester_name,
           CASE WHEN term.status = "current" THEN 1 ELSE 0 END AS is_writable
    FROM course_module_classes cmc
    INNER JOIN class_assignments ca ON ca.id = cmc.class_assignment_id
    INNER JOIN subjects s ON s.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN academic_terms term ON term.academic_year_id = ca.academic_year_id AND term.semester_id = ca.semester_id
    WHERE cmc.module_id = ?
    ORDER BY cmc.class_order, cmc.id
');
mysqli_stmt_bind_param($stmt, 'i', $module_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) $classes[] = $row;
mysqli_stmt_close($stmt);

$existing_content = [];
$stmt = mysqli_prepare($conn, '
    SELECT "quiz" AS resource_type, q.id AS resource_id, q.title,
           s.subject_code, sec.section_name, cmi.module_id AS current_module_id,
           cm.title AS current_module_title
    FROM course_module_classes target_class
    INNER JOIN quizzes q ON q.class_assignment_id = target_class.class_assignment_id
    INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
    INNER JOIN subjects s ON s.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    LEFT JOIN course_module_resources cmr ON cmr.quiz_id = q.id
    LEFT JOIN course_module_items cmi ON cmi.id = cmr.module_item_id
    LEFT JOIN course_modules cm ON cm.id = cmi.module_id
    WHERE target_class.module_id = ? AND (cmi.module_id IS NULL OR cmi.module_id <> ?)
    ORDER BY resource_type, title
');
mysqli_stmt_bind_param($stmt, 'ii', $module_id, $module_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) $existing_content[] = $row;
mysqli_stmt_close($stmt);

$items = [];
$stmt = mysqli_prepare($conn, '
    SELECT cmi.*, cmr.class_assignment_id, cmr.assignment_id, cmr.quiz_id,
           s.subject_code, sec.section_name,
           a.due_date, q.status AS quiz_status, q.available_from, q.available_until,
           (SELECT COUNT(*) FROM assignment_submissions asm WHERE asm.assignment_id = a.id) AS submission_count,
           (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.status <> "in_progress") AS attempt_count
    FROM course_module_items cmi
    LEFT JOIN course_module_resources cmr ON cmr.module_item_id = cmi.id
    LEFT JOIN class_assignments ca ON ca.id = cmr.class_assignment_id
    LEFT JOIN subjects s ON s.id = ca.subject_id
    LEFT JOIN sections sec ON sec.id = ca.section_id
    LEFT JOIN assignments a ON a.id = cmr.assignment_id
    LEFT JOIN quizzes q ON q.id = cmr.quiz_id
    WHERE cmi.module_id = ?
    ORDER BY cmi.display_order, cmi.id, cmr.id
');
mysqli_stmt_bind_param($stmt, 'i', $module_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $item_id = intval($row['id']);
    if (!isset($items[$item_id])) {
        $items[$item_id] = [
            'id' => $item_id, 'item_type' => $row['item_type'], 'title' => $row['title'],
            'instructions' => $row['instructions'], 'resources' => []
        ];
    }
    if ($row['class_assignment_id']) $items[$item_id]['resources'][] = $row;
}
mysqli_stmt_close($stmt);

$drive_files = [];
$stmt = mysqli_prepare($conn, 'SELECT id, item_name, original_filename FROM faculty_drive_items WHERE faculty_id = ? AND item_type = "file" AND storage_scope = "library" AND file_path IS NOT NULL ORDER BY item_name');
mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) $drive_files[] = $row;
mysqli_stmt_close($stmt);

$writable_classes = array_values(array_filter($classes, function ($class) { return intval($class['is_writable']) === 1; }));
$flash = get_flash();
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($module['title']); ?> - StudyLink</title>
<link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
<link rel="stylesheet" href="/studyLink/assets/css/module-interface.css">
</head><body class="faculty-interface module-page">
<?php render_faculty_sidebar('modules', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Module Builder', count($classes) . ' assigned class' . (count($classes) === 1 ? '' : 'es')); ?>
<main class="faculty-main"><div class="module-shell">
    <a class="faculty-text-link" href="/studyLink/faculty/modules.php">← Back to Modules</a>
    <header class="module-heading" style="margin-top:17px">
        <div><div class="module-eyebrow"><?php echo e($module['status']); ?> module</div><h1><?php echo e($module['title']); ?></h1><p><?php echo e($module['description'] ?: 'No module description.'); ?></p><div class="module-class-list"><?php foreach ($classes as $class): ?><span class="module-class-chip"><?php echo e($class['subject_code'] . ' · ' . $class['section_name']); ?></span><?php endforeach; ?></div></div>
        <?php if ($writable_classes): ?><div class="module-heading-actions">
            <button class="module-button light" type="button" id="openContentCreator"><?php echo study_icon('plus-lg'); ?> Add content</button>
            <form action="/studyLink/faculty/modules/toggle_publish.php" method="post"><?php echo csrf_field(); ?><input type="hidden" name="module_id" value="<?php echo $module_id; ?>"><button class="module-button <?php echo $module['status'] === 'draft' ? 'gold' : 'light'; ?>" type="submit"><?php echo study_icon($module['status'] === 'draft' ? 'send-check' : 'pause-circle'); ?> <?php echo $module['status'] === 'draft' ? 'Publish Module' : 'Return to Draft'; ?></button></form>
            <?php if (strpos($module['title'], 'Existing Coursework · Class ') !== 0): ?><button class="module-button danger" type="button" id="openDeleteModule"><?php echo study_icon('trash3'); ?> Delete module</button><?php else: ?><span class="module-protected" title="Imported quiz records are protected to preserve attempts and grades."><?php echo study_icon('lock'); ?> Protected</span><?php endif; ?>
        </div><?php endif; ?>
    </header>
    <?php if ($flash): ?><div class="module-notice <?php echo $flash['type'] === 'success' ? '' : 'error'; ?>"><?php echo e($flash['message']); ?></div><?php endif; ?>
    <?php if (!$writable_classes): ?><div class="module-notice error">All assigned classes belong to previous or archived terms. This module is read-only.</div><?php endif; ?>
    <div class="module-layout module-layout-single">
        <section class="module-panel"><div class="module-panel-head"><h2>Module content</h2><span class="module-status <?php echo $module['status'] === 'published' ? 'published' : ''; ?>"><?php echo count($items); ?> items</span></div><div class="module-panel-body">
            <div class="module-item-list">
            <?php if (!$items): ?><div class="module-empty"><h2>This module is empty</h2><p>Add an activity, quiz, or exam using the builder.</p></div><?php endif; ?>
            <?php foreach ($items as $item): ?><article class="module-item <?php echo e($item['item_type']); ?>">
                <span class="module-item-type"><?php echo study_icon($item['item_type'] === 'activity' ? 'clipboard2-check' : ($item['item_type'] === 'exam' ? 'journal-check' : 'patch-question')); ?></span>
                <div><div class="module-eyebrow"><?php echo e($item['item_type']); ?></div><h3><?php echo e($item['title']); ?></h3><?php if ($item['instructions']): ?><p><?php echo nl2br(e($item['instructions'])); ?></p><?php endif; ?>
                    <div class="module-class-list"><?php foreach ($item['resources'] as $resource): ?><span class="module-class-chip"><?php echo e($resource['subject_code'] . ' · ' . $resource['section_name']); ?><?php echo $resource['quiz_id'] ? ' · ' . e($resource['quiz_status']) : ''; ?></span><?php endforeach; ?></div>
                </div>
                <div class="module-item-actions">
                    <?php foreach ($item['resources'] as $resource): ?>
                        <?php if ($resource['assignment_id']): ?><a class="module-button light" href="/studyLink/faculty/assignments/submissions.php?id=<?php echo intval($resource['assignment_id']); ?>"><?php echo e($resource['section_name']); ?> submissions</a><?php endif; ?>
                        <?php if ($resource['quiz_id']): ?><a class="module-button light" href="/studyLink/faculty/quizzes/quiz_builder.php?quiz_id=<?php echo intval($resource['quiz_id']); ?>&tab=questions"><?php echo e($resource['section_name']); ?> builder</a><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </article><?php endforeach; ?>
            </div>
        </div></section>

        <dialog class="module-dialog module-content-dialog" id="contentCreator"><section class="module-panel"><div class="module-panel-head"><div><div class="module-eyebrow">Module builder</div><h2>Add content</h2><span>Add one learning item at a time. Assignments remain in the separate Assignments page.</span></div><button class="module-dialog-close" type="button" data-close-content aria-label="Close content creator"><?php echo study_icon('x-lg'); ?></button></div><div class="module-panel-body">
        <?php if (!$writable_classes): ?><p style="color:var(--module-muted);font-size:13px">Content creation is disabled because this Academic Term is read-only.</p>
        <?php else: ?>
            <?php if ($existing_content): ?>
            <form class="module-form module-existing-form" action="/studyLink/faculty/modules/attach_existing.php" method="post">
                <?php echo csrf_field(); ?><input type="hidden" name="module_id" value="<?php echo $module_id; ?>">
                <label class="module-field"><span>Use an existing quiz</span><select name="existing_resource" required><option value="">Select a quiz</option><?php foreach ($existing_content as $existing): ?><option value="<?php echo e($existing['resource_type'] . ':' . intval($existing['resource_id'])); ?>"><?php echo e($existing['title'] . ' · ' . $existing['subject_code'] . ' ' . $existing['section_name']); ?></option><?php endforeach; ?></select></label>
                <button class="module-button light" type="submit"><?php echo study_icon('plus-lg'); ?> Add existing quiz</button>
                <p class="module-help">The quiz ID, questions, attempts, scores, and results are preserved.</p>
            </form>
            <div class="module-divider"><span>or create new content</span></div>
            <?php endif; ?>
            <div class="module-tabs module-type-picker"><button class="active" type="button" data-module-type="activity"><?php echo study_icon('clipboard2-check'); ?><span><strong>Activity</strong><small>Student task inside this module</small></span></button><button type="button" data-module-type="quiz"><?php echo study_icon('patch-question'); ?><span><strong>Quiz</strong><small>Short assessment</small></span></button><button type="button" data-module-type="exam"><?php echo study_icon('journal-check'); ?><span><strong>Exam</strong><small>Formal timed assessment</small></span></button></div>
            <?php foreach (['activity','quiz','exam'] as $type): ?><form class="module-form module-form-panel" action="/studyLink/faculty/modules/create_item.php" method="post" data-module-panel="<?php echo $type; ?>" <?php echo $type !== 'activity' ? 'hidden' : ''; ?>>
                <?php echo csrf_field(); ?><input type="hidden" name="module_id" value="<?php echo $module_id; ?>"><input type="hidden" name="item_type" value="<?php echo $type; ?>">
                <label class="module-field"><span><?php echo ucfirst($type); ?> title</span><input name="title" maxlength="255" required></label>
                <label class="module-field"><span>Instructions</span><textarea name="instructions"></textarea></label>
                <?php if ($type === 'activity'): ?>
                    <label class="module-field"><span>Due date and time</span><input type="datetime-local" name="due_date" required></label>
                    <label class="module-field"><span>Optional Faculty Drive reference</span><select name="faculty_drive_item_id"><option value="">No attachment</option><?php foreach ($drive_files as $file): ?><option value="<?php echo intval($file['id']); ?>"><?php echo e($file['original_filename'] ?: $file['item_name']); ?></option><?php endforeach; ?></select></label>
                <?php else: ?>
                    <label class="module-field"><span>Time limit (minutes)</span><input type="number" name="time_limit" min="0" value="<?php echo $type === 'exam' ? '60' : '15'; ?>"></label>
                    <label class="module-field"><span>Available from</span><input type="datetime-local" name="available_from"></label>
                    <label class="module-field"><span>Available until</span><input type="datetime-local" name="available_until"></label>
                <?php endif; ?>
                <div class="module-field"><span>Create for classes</span><div class="module-checks"><?php foreach ($writable_classes as $class): ?><label class="module-check"><input type="checkbox" name="class_ids[]" value="<?php echo intval($class['id']); ?>" checked><span><strong><?php echo e($class['subject_code'] . ' · ' . $class['section_name']); ?></strong><small><?php echo e($class['semester_name'] . ' · ' . $class['school_year']); ?></small></span></label><?php endforeach; ?></div></div>
                <button class="module-button gold" type="submit">Create <?php echo $type; ?></button>
            </form><?php endforeach; ?>
        <?php endif; ?>
        </div></section></dialog>
    </div>
</div></main>

<?php if (strpos($module['title'], 'Existing Coursework · Class ') !== 0): ?>
<dialog class="module-dialog module-delete-dialog" id="moduleDeleteDialog">
    <form action="/studyLink/faculty/modules/delete.php" method="post">
        <?php echo csrf_field(); ?><input type="hidden" name="module_id" value="<?php echo $module_id; ?>">
        <div class="module-dialog-head"><div><div class="module-eyebrow danger-text">Delete module</div><h2>Delete this module?</h2><p>Items without student work will be removed. Existing coursework, submissions, quiz attempts, grades, and feedback will be preserved.</p></div><button type="button" data-close-delete aria-label="Close delete confirmation"><?php echo study_icon('x-lg'); ?></button></div>
        <div class="module-dialog-body"><p class="module-delete-name"><?php echo e($module['title']); ?></p><div class="module-dialog-actions"><button class="module-button light" type="button" data-close-delete>Cancel</button><button class="module-button danger solid" type="submit"><?php echo study_icon('trash3'); ?> Delete module</button></div></div>
    </form>
</dialog>
<?php endif; ?>
<script>
(function(){
    var buttons=document.querySelectorAll('[data-module-type]'),panels=document.querySelectorAll('[data-module-panel]');
    var contentDialog=document.getElementById('contentCreator'),deleteDialog=document.getElementById('moduleDeleteDialog');
    buttons.forEach(function(button){button.addEventListener('click',function(){buttons.forEach(function(x){x.classList.remove('active')});panels.forEach(function(x){x.hidden=x.dataset.modulePanel!==button.dataset.moduleType});button.classList.add('active')})});
    document.getElementById('openContentCreator')?.addEventListener('click',function(){contentDialog.showModal()});
    document.querySelectorAll('[data-close-content]').forEach(function(button){button.addEventListener('click',function(){contentDialog.close()})});
    contentDialog?.addEventListener('click',function(event){if(event.target===contentDialog)contentDialog.close()});
    document.getElementById('openDeleteModule')?.addEventListener('click',function(){deleteDialog.showModal()});
    document.querySelectorAll('[data-close-delete]').forEach(function(button){button.addEventListener('click',function(){deleteDialog.close()})});
    deleteDialog?.addEventListener('click',function(event){if(event.target===deleteDialog)deleteDialog.close()});
})();
</script>
</body></html>
