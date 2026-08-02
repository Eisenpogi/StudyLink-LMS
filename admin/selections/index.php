<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';

$flash = get_flash();
$query = mysqli_query(
    $conn,
    "SELECT sec.*, COUNT(DISTINCT st.id) AS student_count, COUNT(DISTINCT ca.id) AS class_count
     FROM sections sec
     LEFT JOIN students st ON st.section_id = sec.id
     LEFT JOIN class_assignments ca ON ca.section_id = sec.id
     GROUP BY sec.id, sec.section_name, sec.course, sec.year_level, sec.created_at
     ORDER BY sec.course, sec.year_level, sec.section_name"
);
$sections = $query ? mysqli_fetch_all($query, MYSQLI_ASSOC) : [];

render_admin_page_start(
    'sections',
    'Sections',
    'Section management',
    'Organize students by course, year level, and official section name.',
    'Academic Structure'
);
render_admin_flash($flash);
render_admin_workflow('structure');
render_admin_explainer(
    'Why sections are important',
    'A student automatically receives access to all classes assigned to the student’s section. Students do not manually join classes.'
);
?>
<div class="admin-content-grid">
    <section class="admin-card admin-form-card">
        <div class="admin-section-head">
            <div><h2>Add section</h2><span>Create an official student grouping</span></div>
        </div>
        <form class="admin-form" action="add.php" method="POST">
            <?= csrf_field(); ?>
            <div class="admin-field">
                <label for="section_name">Section name</label>
                <input id="section_name" type="text" name="section_name" placeholder="BSCS 1A" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="course">Course</label>
                <input id="course" type="text" name="course" placeholder="BSCS" maxlength="50" required>
            </div>
            <div class="admin-field">
                <label for="year_level">Year level</label>
                <select id="year_level" name="year_level" required>
                    <option value="">Select year level</option>
                    <?php for ($year = 1; $year <= 5; $year++): ?>
                        <option value="<?= $year; ?>"><?= $year; ?><?= $year === 1 ? 'st' : ($year === 2 ? 'nd' : ($year === 3 ? 'rd' : 'th')); ?> Year</option>
                    <?php endfor; ?>
                </select>
            </div>
            <button class="admin-button primary" type="submit"><?= study_icon('plus-lg'); ?> Add section</button>
        </form>
    </section>

    <section class="admin-card admin-table-card">
        <div class="admin-section-head">
            <div><h2>Configured sections</h2><span><?= number_format(count($sections)); ?> total</span></div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-data-table">
                <thead><tr><th>Section</th><th>Course</th><th>Year level</th><th>Students</th><th>Classes</th><th class="admin-table-action">Actions</th></tr></thead>
                <tbody>
                <?php if (!$sections): ?>
                    <tr><td class="admin-empty-cell" colspan="6">No sections configured yet. Create a section before adding students.</td></tr>
                <?php endif; ?>
                <?php foreach ($sections as $row): ?>
                    <tr>
                        <td><strong><?= e($row['section_name']); ?></strong><small>Record #<?= e($row['id']); ?></small></td>
                        <td><span class="admin-code-pill"><?= e($row['course']); ?></span></td>
                        <td><?= e($row['year_level']); ?><?= intval($row['year_level']) === 1 ? 'st' : (intval($row['year_level']) === 2 ? 'nd' : (intval($row['year_level']) === 3 ? 'rd' : 'th')); ?> Year</td>
                        <td><span class="admin-count-badge"><?= number_format($row['student_count']); ?></span></td>
                        <td><span class="admin-count-badge"><?= number_format($row['class_count']); ?></span></td>
                        <td class="admin-table-action">
                            <div class="admin-row-actions">
                                <a class="admin-button secondary small" href="edit.php?id=<?= e($row['id']); ?>"><?= study_icon('pencil'); ?> Edit</a>
                                <?php if (intval($row['student_count']) === 0 && intval($row['class_count']) === 0): ?>
                                    <form action="delete.php" method="POST" onsubmit="return confirm('Delete this unused section?')">
                                        <?= csrf_field(); ?>
                                        <input type="hidden" name="id" value="<?= e($row['id']); ?>">
                                        <button class="admin-button danger small" type="submit"><?= study_icon('trash3'); ?> Delete</button>
                                    </form>
                                <?php else: ?>
                                    <span class="admin-protected-label" title="Move students and remove class assignments before deleting."><?= study_icon('lock'); ?> In use</span>
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
