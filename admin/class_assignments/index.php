<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';
include '../../includes/academic_term.php';

$flash = get_flash();

function admin_fetch_rows($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    return $result ? mysqli_fetch_all($result, MYSQLI_ASSOC) : [];
}

$faculty = admin_fetch_rows($conn, "SELECT f.id, f.faculty_id, u.fullname FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE u.status = 'active' ORDER BY u.fullname");
$subjects = admin_fetch_rows($conn, 'SELECT id, subject_code, subject_name FROM subjects ORDER BY subject_code');
$sections = admin_fetch_rows($conn, 'SELECT id, section_name FROM sections ORDER BY section_name');
$years = admin_fetch_rows($conn, "SELECT id, school_year, status FROM academic_years ORDER BY status = 'active' DESC, id DESC");
$semesters = admin_fetch_rows($conn, "SELECT id, semester_name, status FROM semesters ORDER BY status = 'active' DESC, id DESC");
$current_term = studylink_current_term($conn);
$classes = admin_fetch_rows(
    $conn,
    "SELECT ca.id, ca.created_at, u.fullname AS faculty_name, f.faculty_id,
            s.subject_code, s.subject_name, sec.section_name, ca.section_id,
            ay.school_year, ca.academic_year_id, sem.semester_name, ca.semester_id,
            (SELECT COUNT(*) FROM students st WHERE st.section_id = ca.section_id) AS student_count,
            (
                (SELECT COUNT(*) FROM learning_materials lm WHERE lm.class_assignment_id = ca.id) +
                (SELECT COUNT(*) FROM assignments a WHERE a.class_assignment_id = ca.id) +
                (SELECT COUNT(*) FROM quizzes q WHERE q.class_assignment_id = ca.id) +
                (SELECT COUNT(*) FROM attendance_sessions ats WHERE ats.class_assignment_id = ca.id)
            ) AS content_count
     FROM class_assignments ca
     INNER JOIN faculty f ON f.id = ca.faculty_id
     INNER JOIN users u ON u.id = f.user_id
     INNER JOIN subjects s ON s.id = ca.subject_id
     INNER JOIN sections sec ON sec.id = ca.section_id
     INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
     INNER JOIN semesters sem ON sem.id = ca.semester_id
     ORDER BY ay.school_year DESC, sem.id DESC, s.subject_code, sec.section_name"
);

$can_create = $faculty && $subjects && $sections && $current_term;
$year_filter = intval($_GET['academic_year'] ?? 0);
$semester_filter = intval($_GET['semester'] ?? 0);
$section_filter = intval($_GET['section'] ?? 0);
$visible_classes = array_values(array_filter($classes, function ($row) use ($year_filter, $semester_filter, $section_filter) {
    return ($year_filter === 0 || intval($row['academic_year_id']) === $year_filter)
        && ($semester_filter === 0 || intval($row['semester_id']) === $semester_filter)
        && ($section_filter === 0 || intval($row['section_id']) === $section_filter);
}));

render_admin_page_start('classes', 'Class Assignments', 'Class assignment management', 'Connect one faculty member, subject, section, academic year, and semester into an active class.', 'Class Management');
render_admin_flash($flash);
render_admin_workflow('classes');
render_admin_explainer(
    'This is the actual class',
    'One class assignment equals one faculty + one subject + one section + one academic year + one semester. Students are included automatically through the selected section.'
);
?>
<section class="admin-card admin-class-form-card">
    <div class="admin-section-head">
        <div><h2>Create class assignment</h2><span>One faculty + one subject + one section + one academic term</span></div>
    </div>
    <?php if (!$can_create): ?>
        <div class="admin-inline-warning"><?= study_icon('exclamation-triangle'); ?><span>Complete Faculty, Subjects, Sections, and set the Current Academic Term before creating a class.</span></div>
    <?php endif; ?>
    <form class="admin-form admin-form-wide" action="add.php" method="POST">
        <?= csrf_field(); ?>
        <div class="admin-form-grid class-fields">
            <div class="admin-field">
                <label for="faculty_id">Faculty</label>
                <select id="faculty_id" name="faculty_id" required>
                    <option value="">Select faculty</option>
                    <?php foreach ($faculty as $row): ?><option value="<?= e($row['id']); ?>"><?= e($row['fullname']); ?> · <?= e($row['faculty_id']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="admin-field">
                <label for="subject_id">Subject</label>
                <select id="subject_id" name="subject_id" required>
                    <option value="">Select subject</option>
                    <?php foreach ($subjects as $row): ?><option value="<?= e($row['id']); ?>"><?= e($row['subject_code']); ?> · <?= e($row['subject_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="admin-field">
                <label for="section_id">Section</label>
                <select id="section_id" name="section_id" required>
                    <option value="">Select section</option>
                    <?php foreach ($sections as $row): ?><option value="<?= e($row['id']); ?>"><?= e($row['section_name']); ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="admin-field">
                <label>Current Academic Term</label>
                <div class="admin-inline-warning">
                    <?= study_icon('calendar-range'); ?>
                    <span>
                        <strong><?= $current_term ? e($current_term['semester_name'] . ', AY ' . $current_term['school_year']) : 'Not configured'; ?></strong><br>
                        New classes are always assigned to the system-wide current term.
                    </span>
                </div>
                <?php if ($current_term): ?>
                    <input type="hidden" name="academic_year_id" value="<?= e($current_term['academic_year_id']); ?>">
                    <input type="hidden" name="semester_id" value="<?= e($current_term['semester_id']); ?>">
                <?php endif; ?>
            </div>
        </div>
        <button class="admin-button primary" type="submit" <?= !$can_create ? 'disabled' : ''; ?>><?= study_icon('plus-lg'); ?> Create class assignment</button>
        <a class="admin-button secondary" href="../academic_year/index.php"><?= study_icon('calendar-range'); ?> Change Current Term</a>
    </form>
</section>

<section class="admin-card admin-table-card admin-spaced-card">
    <div class="admin-section-head">
        <div><h2>Class assignments</h2><span><?= number_format(count($visible_classes)); ?> shown · <?= number_format(count($classes)); ?> total</span></div>
        <form class="admin-filter-row" method="GET">
            <select name="academic_year" aria-label="Filter by academic year">
                <option value="0">All academic years</option>
                <?php foreach ($years as $row): ?>
                    <option value="<?= e($row['id']); ?>" <?= $year_filter === intval($row['id']) ? 'selected' : ''; ?>><?= e($row['school_year']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="semester" aria-label="Filter by semester">
                <option value="0">All semesters</option>
                <?php foreach ($semesters as $row): ?>
                    <option value="<?= e($row['id']); ?>" <?= $semester_filter === intval($row['id']) ? 'selected' : ''; ?>><?= e($row['semester_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="section" aria-label="Filter by section">
                <option value="0">All sections</option>
                <?php foreach ($sections as $row): ?>
                    <option value="<?= e($row['id']); ?>" <?= $section_filter === intval($row['id']) ? 'selected' : ''; ?>><?= e($row['section_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="admin-button secondary small" type="submit"><?= study_icon('funnel'); ?> Apply</button>
        </form>
    </div>
    <div class="admin-table-wrap">
        <table class="admin-data-table">
            <thead><tr><th>Subject & section</th><th>Faculty</th><th>Academic term</th><th>Students</th><th>Content</th><th class="admin-table-action">Action</th></tr></thead>
            <tbody>
            <?php if (!$visible_classes): ?>
                <tr><td class="admin-empty-cell" colspan="6">No class assignments match the selected filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($visible_classes as $row): ?>
                <tr>
                    <td><strong><?= e($row['subject_code']); ?> · <?= e($row['subject_name']); ?></strong><small><?= e($row['section_name']); ?></small></td>
                    <td><strong><?= e($row['faculty_name']); ?></strong><small><?= e($row['faculty_id']); ?></small></td>
                    <td><strong><?= e($row['school_year']); ?></strong><small><?= e($row['semester_name']); ?></small></td>
                    <td><span class="admin-count-badge"><?= number_format($row['student_count']); ?></span></td>
                    <td><span class="admin-count-badge"><?= number_format($row['content_count']); ?></span></td>
                    <td class="admin-table-action">
                        <?php if (intval($row['content_count']) === 0): ?>
                            <form action="delete.php" method="POST" onsubmit="return confirm('Delete this empty class assignment? No student account will be deleted.')">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= e($row['id']); ?>">
                                <button class="admin-button danger small" type="submit"><?= study_icon('trash3'); ?> Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="admin-protected-label" title="This class has materials, assignments, quizzes, or attendance records."><?= study_icon('lock'); ?> Has content</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php render_admin_page_end(); ?>
