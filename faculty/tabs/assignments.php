<?php
$faculty_id = intval($faculty['id']);
$class_assignment_id = intval($class['id']);
$drive_files = [];
$assignments = [];

$drive_statement = mysqli_prepare($conn, '
    SELECT id, item_name, original_filename
    FROM faculty_drive_items
    WHERE faculty_id = ? AND item_type = "file" AND storage_scope = "library" AND file_path IS NOT NULL
    ORDER BY item_name ASC
');
mysqli_stmt_bind_param($drive_statement, 'i', $faculty_id);
mysqli_stmt_execute($drive_statement);
$result = mysqli_stmt_get_result($drive_statement);
while ($row = mysqli_fetch_assoc($result)) $drive_files[] = $row;
mysqli_stmt_close($drive_statement);

$assignment_statement = mysqli_prepare($conn, '
    SELECT a.*, fdi.file_path AS drive_file_path, fdi.original_filename AS drive_original_filename,
           (SELECT COUNT(*) FROM assignment_submissions asm WHERE asm.assignment_id = a.id) AS submission_count,
           (SELECT COUNT(*) FROM assignment_submissions asm WHERE asm.assignment_id = a.id AND asm.score IS NOT NULL) AS graded_count
    FROM assignments a
    LEFT JOIN faculty_drive_items fdi ON fdi.id = a.faculty_drive_item_id AND fdi.faculty_id = ?
    WHERE a.class_assignment_id = ?
      AND NOT EXISTS (
          SELECT 1
          FROM course_module_resources cmr_activity
          INNER JOIN course_module_items cmi_activity ON cmi_activity.id = cmr_activity.module_item_id
          INNER JOIN course_modules cm_activity ON cm_activity.id = cmi_activity.module_id
          WHERE cmr_activity.assignment_id = a.id
            AND cm_activity.title NOT LIKE "Existing Coursework%"
      )
    ORDER BY a.due_date DESC
');
mysqli_stmt_bind_param($assignment_statement, 'ii', $faculty_id, $class_assignment_id);
mysqli_stmt_execute($assignment_statement);
$result = mysqli_stmt_get_result($assignment_statement);
while ($row = mysqli_fetch_assoc($result)) $assignments[] = $row;
mysqli_stmt_close($assignment_statement);

$error_messages = [
    'required' => 'Please complete all required fields.',
    'invalid_due_date' => 'The due date is invalid.',
    'no_file' => 'Please select a file.',
    'upload_failed' => 'File upload failed.',
    'file_too_large' => 'Maximum file size is 100 MB.',
    'invalid_type' => 'This file type is not allowed.',
    'storage_limit' => 'You have reached the 1 GB storage limit.',
    'folder_failed' => 'Unable to prepare the upload folder.',
    'invalid_drive_file' => 'The selected Faculty Drive file is invalid.',
    'delete_failed' => 'Unable to delete the assignment attachment.',
    'database' => 'Unable to save the assignment.'
];
?>

<header class="faculty-module-head">
    <div><div class="faculty-eyebrow">Course assessment</div><h2>Assignments</h2><p>Create tasks, set deadlines, and review student submissions.</p></div>
    <span class="faculty-pill"><?php echo count($assignments); ?> assignment<?php echo count($assignments) === 1 ? '' : 's'; ?></span>
</header>

<?php if (($_GET['success'] ?? '') === 'created'): ?><div class="faculty-notice">Assignment created successfully.</div><?php endif; ?>
<?php if (($_GET['success'] ?? '') === 'deleted'): ?><div class="faculty-notice">Assignment deleted.</div><?php endif; ?>
<?php if (isset($_GET['error'], $error_messages[$_GET['error']])): ?><div class="faculty-notice error"><?php echo e($error_messages[$_GET['error']]); ?></div><?php endif; ?>

<div class="faculty-module-grid">
    <section class="faculty-module-panel">
        <div class="faculty-panel-heading"><h3>Assignment list</h3><span>Submission tracking</span></div>
        <div class="faculty-record-list" style="padding:16px;">
            <?php if (!$assignments): ?><div class="faculty-empty">No assignments have been created yet.</div><?php endif; ?>
            <?php foreach ($assignments as $assignment): ?>
                <?php $overdue = strtotime($assignment['due_date']) < time(); ?>
                <article class="faculty-record-card">
                    <div class="faculty-record-top">
                        <div>
                            <div class="faculty-record-meta">
                                <span class="faculty-pill <?php echo $overdue ? 'danger' : 'warning'; ?>"><?php echo $overdue ? 'Closed' : 'Open'; ?></span>
                                <span>Due <?php echo e(date('M d, Y · g:i A', strtotime($assignment['due_date']))); ?></span>
                            </div>
                            <h3><?php echo e($assignment['title']); ?></h3>
                            <?php if (!empty($assignment['instructions'])): ?><p><?php echo nl2br(e($assignment['instructions'])); ?></p><?php endif; ?>
                            <div class="faculty-record-meta" style="margin-top:10px;"><span><?php echo intval($assignment['submission_count']); ?> submissions</span><span><?php echo intval($assignment['graded_count']); ?> graded</span></div>
                        </div>
                        <div class="faculty-record-actions">
                            <?php if (!empty($assignment['drive_file_path'])): ?><a class="faculty-button small light" href="<?php echo e($assignment['drive_file_path']); ?>" target="_blank" rel="noopener">Reference</a><?php endif; ?>
                            <a class="faculty-button small" href="/studyLink/faculty/assignments/submissions.php?id=<?php echo intval($assignment['id']); ?>">Submissions</a>
                            <?php if ($term_writable): ?>
                                <form action="/studyLink/faculty/assignments/delete.php" method="post" onsubmit="return confirm('Delete this assignment?');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo intval($assignment['id']); ?>">
                                    <button class="faculty-button small danger" type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="faculty-module-panel">
        <?php if (!$term_writable): ?>
        <div class="faculty-panel-heading"><h3>Read-only term</h3><span>Creation disabled</span></div>
        <div class="faculty-empty">Assignments, submissions, grades, and feedback are preserved for viewing. Only the current Academic Term accepts changes.</div>
        <?php else: ?>
        <div class="faculty-panel-heading"><h3>Create assignment</h3><span>New student task</span></div>
        <form action="assignments/create.php" method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?><input type="hidden" name="class_assignment_id" value="<?php echo $class_assignment_id; ?>">
            <div class="faculty-form-grid">
                <label class="faculty-form-field full"><span>Title</span><input type="text" name="title" maxlength="255" placeholder="e.g. Chapter 3 Reflection" required></label>
                <label class="faculty-form-field full"><span>Instructions</span><textarea name="instructions" placeholder="Explain what students need to submit."></textarea></label>
                <label class="faculty-form-field full"><span>Due date and time</span><input type="datetime-local" name="due_date" required></label>
                <div class="faculty-form-field full">
                    <span>Attachment source</span>
                    <div class="faculty-source-switch">
                        <label><input type="radio" name="attachment_source" value="upload" checked><strong>Upload new file</strong><small>Add a reference document</small></label>
                        <label><input type="radio" name="attachment_source" value="drive"><strong>Faculty Drive</strong><small>Reuse an existing file</small></label>
                    </div>
                </div>
                <label class="faculty-form-field full" id="assignmentUploadSource"><span>Select file</span><input type="file" name="assignment_file" id="assignmentFile" required></label>
                <label class="faculty-form-field full" id="assignmentDriveSource" hidden><span>Drive file</span><select name="faculty_drive_item_id" id="assignmentDriveItem"><option value="">Select a file</option><?php foreach ($drive_files as $drive_file): ?><option value="<?php echo intval($drive_file['id']); ?>"><?php echo e($drive_file['original_filename'] ?: $drive_file['item_name']); ?></option><?php endforeach; ?></select><?php if (!$drive_files): ?><small>No reusable file is available in Faculty Drive.</small><?php endif; ?></label>
                <div class="faculty-form-actions"><button class="faculty-button" type="submit">Create assignment</button></div>
            </div>
        </form>
        <?php endif; ?>
    </aside>
</div>

<?php if ($term_writable): ?>
<script>
(function () {
    var radios = document.querySelectorAll('input[name="attachment_source"]');
    var uploadSource = document.getElementById('assignmentUploadSource');
    var driveSource = document.getElementById('assignmentDriveSource');
    var uploadInput = document.getElementById('assignmentFile');
    var driveInput = document.getElementById('assignmentDriveItem');

    function syncAssignmentSource() {
        var selected = document.querySelector('input[name="attachment_source"]:checked');
        var upload = !selected || selected.value === 'upload';

        uploadSource.hidden = !upload;
        driveSource.hidden = upload;
        uploadInput.disabled = !upload;
        uploadInput.required = upload;
        driveInput.disabled = upload;
        driveInput.required = !upload;
    }

    radios.forEach(function (radio) {
        radio.addEventListener('change', syncAssignmentSource);
    });

    syncAssignmentSource();
})();
</script>
<?php endif; ?>
