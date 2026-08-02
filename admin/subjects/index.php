<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$flash = get_flash();
$query = mysqli_query(
    $conn,
    "SELECT sub.*, COUNT(ca.id) AS class_count
     FROM subjects sub
     LEFT JOIN class_assignments ca ON ca.subject_id = sub.id
     GROUP BY sub.id, sub.subject_code, sub.subject_name, sub.description, sub.created_at
     ORDER BY sub.subject_code, sub.subject_name"
);
$subjects = $query ? mysqli_fetch_all($query, MYSQLI_ASSOC) : [];

render_admin_page_start('subjects', 'Subjects', 'Subject management', 'Maintain the official subject catalog used when assigning faculty and classes.', 'Academic Structure');
render_admin_flash($flash);
render_admin_workflow('structure');
render_admin_explainer(
    'Subjects are reusable',
    'Create a subject once, then assign it to different faculty, sections, academic years, and semesters through Class Assignments.'
);
?>
<div class="admin-content-grid">
    <section class="admin-card admin-form-card">
        <div class="admin-section-head"><div><h2>Add subject</h2><span>Create a reusable catalog entry</span></div></div>
        <form class="admin-form" action="add.php" method="POST">
            <?= csrf_field(); ?>
            <div class="admin-field">
                <label for="subject_code">Subject code</label>
                <input id="subject_code" type="text" name="subject_code" placeholder="CS101" maxlength="20" required>
            </div>
            <div class="admin-field">
                <label for="subject_name">Subject name</label>
                <input id="subject_name" type="text" name="subject_name" placeholder="Introduction to Computing" maxlength="100" required>
            </div>
            <div class="admin-field">
                <label for="description">Description <span>Optional</span></label>
                <textarea id="description" name="description" rows="4" placeholder="Short catalog description"></textarea>
            </div>
            <button class="admin-button primary" type="submit"><?= study_icon('plus-lg'); ?> Add subject</button>
        </form>
    </section>

    <section class="admin-card admin-table-card">
        <div class="admin-section-head"><div><h2>Subject catalog</h2><span><?= number_format(count($subjects)); ?> total</span></div></div>
        <div class="admin-table-wrap">
            <table class="admin-data-table">
                <thead><tr><th>Code</th><th>Subject</th><th>Description</th><th>Classes</th><th class="admin-table-action">Actions</th></tr></thead>
                <tbody>
                <?php if (!$subjects): ?>
                    <tr><td class="admin-empty-cell" colspan="5">No subjects configured yet. Add official catalog subjects before assigning classes.</td></tr>
                <?php endif; ?>
                <?php foreach ($subjects as $row): ?>
                    <tr>
                        <td><span class="admin-code-pill"><?= e($row['subject_code']); ?></span></td>
                        <td><strong><?= e($row['subject_name']); ?></strong><small>Record #<?= e($row['id']); ?></small></td>
                        <td class="admin-description-cell"><?= e($row['description'] ?: 'No description'); ?></td>
                        <td><span class="admin-count-badge"><?= number_format($row['class_count']); ?></span></td>
                        <td class="admin-table-action">
                            <div class="admin-row-actions">
                                <a class="admin-button secondary small" href="edit.php?id=<?= e($row['id']); ?>"><?= study_icon('pencil'); ?> Edit</a>
                                <?php if (intval($row['class_count']) === 0): ?>
                                    <form action="delete.php" method="POST" onsubmit="return confirm('Delete this unused subject?')">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?= e($row['id']); ?>">
                                        <button class="admin-button danger small" type="submit"><?= study_icon('trash3'); ?> Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="admin-protected-label" title="Remove its class assignments before deleting."><?= study_icon('lock'); ?> In use</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php render_admin_page_end(); ?>
