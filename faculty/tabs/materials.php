<?php
$faculty_id = intval($faculty['id']);
$class_id = intval($class['id']);
$drive_files = [];
$materials = [];

$drive_statement = mysqli_prepare($conn, '
    SELECT id, item_name, original_filename
    FROM faculty_drive_items
    WHERE faculty_id = ? AND item_type = "file" AND storage_scope = "library" AND file_path IS NOT NULL
    ORDER BY item_name ASC
');
mysqli_stmt_bind_param($drive_statement, 'i', $faculty_id);
mysqli_stmt_execute($drive_statement);
$result = mysqli_stmt_get_result($drive_statement);
while ($row = mysqli_fetch_assoc($result)) {
    $drive_files[] = $row;
}
mysqli_stmt_close($drive_statement);

$material_statement = mysqli_prepare($conn, '
    SELECT lm.*, fdi.file_path AS drive_file_path, fdi.original_filename AS drive_original_filename
    FROM learning_materials lm
    LEFT JOIN faculty_drive_items fdi ON fdi.id = lm.faculty_drive_item_id AND fdi.faculty_id = ?
    WHERE lm.class_assignment_id = ?
    ORDER BY lm.id DESC
');
mysqli_stmt_bind_param($material_statement, 'ii', $faculty_id, $class_id);
mysqli_stmt_execute($material_statement);
$result = mysqli_stmt_get_result($material_statement);
while ($row = mysqli_fetch_assoc($result)) {
    $materials[] = $row;
}
mysqli_stmt_close($material_statement);

$error_messages = [
    'required' => 'Title is required.',
    'no_file' => 'Please select a file.',
    'upload_failed' => 'File upload failed.',
    'file_too_large' => 'Maximum file size is 100 MB.',
    'invalid_type' => 'This file type is not allowed.',
    'storage_limit' => 'You have reached the 1 GB storage limit.',
    'folder_failed' => 'Unable to prepare the upload folder.',
    'invalid_drive_file' => 'The selected Faculty Drive file is invalid.',
    'delete_failed' => 'Unable to delete the learning material attachment.',
    'database' => 'Unable to save the learning material.'
];
?>

<header class="faculty-module-head">
    <div><div class="faculty-eyebrow">Course resources</div><h2>Learning Materials</h2><p>Publish references using a new upload or an existing Faculty Drive file.</p></div>
    <span class="faculty-pill"><?php echo count($materials); ?> material<?php echo count($materials) === 1 ? '' : 's'; ?></span>
</header>

<?php if (($_GET['success'] ?? '') === 'created'): ?><div class="faculty-notice">Learning material published successfully.</div><?php endif; ?>
<?php if (($_GET['success'] ?? '') === 'deleted'): ?><div class="faculty-notice">Learning material deleted.</div><?php endif; ?>
<?php if (isset($_GET['error'], $error_messages[$_GET['error']])): ?><div class="faculty-notice error"><?php echo e($error_messages[$_GET['error']]); ?></div><?php endif; ?>

<div class="faculty-module-grid">
    <section class="faculty-module-panel">
        <div class="faculty-panel-heading"><h3>Published materials</h3><span>Newest first</span></div>
        <div class="faculty-record-list" style="padding:16px;">
            <?php if (!$materials): ?><div class="faculty-empty">No learning materials have been published yet.</div><?php endif; ?>
            <?php foreach ($materials as $material): ?>
                <?php
                $file_url = $material['drive_file_path'];
                if (!$file_url && !empty($material['file_name'])) {
                    $file_url = '/studyLink/assets/uploads/materials/' . rawurlencode($material['file_name']);
                }
                ?>
                <article class="faculty-record-card">
                    <div class="faculty-record-top">
                        <div>
                            <div class="faculty-record-meta"><span><?php echo e($class['subject_code']); ?></span><span>Published material</span></div>
                            <h3><?php echo e($material['title']); ?></h3>
                            <?php if (!empty($material['description'])): ?><p><?php echo nl2br(e($material['description'])); ?></p><?php endif; ?>
                        </div>
                        <div class="faculty-record-actions">
                            <?php if ($file_url): ?><a class="faculty-button small light" href="<?php echo e($file_url); ?>" target="_blank" rel="noopener">Open file</a><?php endif; ?>
                            <?php if ($term_writable): ?>
                                <form action="/studyLink/faculty/materials/delete.php" method="post" onsubmit="return confirm('Delete this learning material?');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo intval($material['id']); ?>">
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
            <div class="faculty-panel-heading"><h3>Read-only term</h3><span>Uploads disabled</span></div>
            <div class="faculty-empty">Existing learning materials remain available. Only the current Academic Term accepts new uploads.</div>
        <?php else: ?>
        <div class="faculty-panel-heading"><h3>Add material</h3><span>Students see it immediately</span></div>
        <form action="materials/upload.php" method="post" enctype="multipart/form-data">
            <?php echo csrf_field(); ?><input type="hidden" name="class_assignment_id" value="<?php echo $class_id; ?>">
            <div class="faculty-form-grid">
                <label class="faculty-form-field full"><span>Title</span><input type="text" name="title" maxlength="255" placeholder="e.g. Week 4 Lecture Notes" required></label>
                <label class="faculty-form-field full"><span>Description</span><textarea name="description" placeholder="Add a short explanation for students."></textarea></label>
                <div class="faculty-form-field full">
                    <span>Attachment source</span>
                    <div class="faculty-source-switch">
                        <label><input type="radio" name="attachment_source" value="upload" checked><strong>Upload new file</strong><small>Add a new course resource</small></label>
                        <label><input type="radio" name="attachment_source" value="drive"><strong>Faculty Drive</strong><small>Reuse an existing file</small></label>
                    </div>
                </div>
                <label class="faculty-form-field full" id="materialUploadSource"><span>Select file</span><input type="file" name="material_file" id="materialFile" required></label>
                <label class="faculty-form-field full" id="materialDriveSource" hidden><span>Drive file</span><select name="faculty_drive_item_id" id="materialDriveItem"><option value="">Select a file</option><?php foreach ($drive_files as $drive_file): ?><option value="<?php echo intval($drive_file['id']); ?>"><?php echo e($drive_file['original_filename'] ?: $drive_file['item_name']); ?></option><?php endforeach; ?></select><?php if (!$drive_files): ?><small>No reusable file is available in Faculty Drive.</small><?php endif; ?></label>
                <div class="faculty-form-actions"><button class="faculty-button" type="submit">Publish material</button></div>
            </div>
        </form>
        <?php endif; ?>
    </aside>
</div>

<?php if ($term_writable): ?>
<script>
(function () {
    var radios = document.querySelectorAll('input[name="attachment_source"]');
    var uploadSource = document.getElementById('materialUploadSource');
    var driveSource = document.getElementById('materialDriveSource');
    var uploadInput = document.getElementById('materialFile');
    var driveInput = document.getElementById('materialDriveItem');

    function syncMaterialSource() {
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
        radio.addEventListener('change', syncMaterialSource);
    });

    syncMaterialSource();
})();
</script>
<?php endif; ?>
