<?php
include '../auth/auth.php';
require_role('admin');
include '../config/database.php';
include '../includes/admin_ui.php';
include '../includes/login_appearance.php';
include '../includes/academic_term.php';

function admin_dashboard_count($conn, $sql)
{
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        error_log('Admin dashboard count failed: ' . mysqli_error($conn));
        return 0;
    }
    $row = mysqli_fetch_row($result);
    return intval($row[0] ?? 0);
}

$student_count = admin_dashboard_count($conn, "SELECT COUNT(*) FROM students s INNER JOIN users u ON u.id = s.user_id WHERE u.status = 'active'");
$faculty_count = admin_dashboard_count($conn, "SELECT COUNT(*) FROM faculty f INNER JOIN users u ON u.id = f.user_id WHERE u.status = 'active'");
$class_count = admin_dashboard_count($conn, 'SELECT COUNT(*) FROM class_assignments');
$subject_count = admin_dashboard_count($conn, 'SELECT COUNT(*) FROM subjects');
$section_count = admin_dashboard_count($conn, 'SELECT COUNT(*) FROM sections');
$inactive_count = admin_dashboard_count($conn, "SELECT COUNT(*) FROM users WHERE status = 'inactive'");

$current_term = studylink_current_term($conn);
$active_year = $current_term ? [
    'id' => $current_term['academic_year_id'],
    'school_year' => $current_term['school_year']
] : null;
$active_semester = $current_term ? [
    'id' => $current_term['semester_id'],
    'semester_name' => $current_term['semester_name']
] : null;

$login_visual_url = studylink_login_visual_url();
$flash = get_flash();

$modules = [
    ['students/index.php', 'people', 'Student Directory', $student_count . ' active accounts'],
    ['faculty/index.php', 'person-badge', 'Faculty Directory', $faculty_count . ' active accounts'],
    ['selections/index.php', 'diagram-3', 'Sections', $section_count . ' configured sections'],
    ['subjects/index.php', 'journal-text', 'Subjects', $subject_count . ' available subjects'],
    ['class_assignments/index.php', 'collection', 'Class Assignments', $class_count . ' faculty-class links'],
    ['academic_year/index.php', 'calendar-range', 'Academic Term', 'Set year and semester together'],
    ['#login-appearance', 'image', 'Login Page Appearance', 'Shared visual for all login portals']
];

$setup_checks = [
    ['Current academic term', (bool) $current_term, 'academic_year/index.php'],
    ['Faculty accounts', $faculty_count > 0, 'faculty/index.php'],
    ['Sections and subjects', $section_count > 0 && $subject_count > 0, 'selections/index.php'],
    ['Class assignments', $class_count > 0, 'class_assignments/index.php']
];
$ready_count = count(array_filter($setup_checks, function ($item) {
    return $item[1];
}));
$readiness = intval(round(($ready_count / count($setup_checks)) * 100));

if (!$current_term) {
    $next_action = ['academic_year/index.php', 'Set the current academic term', 'Select the Academic Year and Semester together before creating current classes.'];
} elseif ($section_count === 0 || $subject_count === 0) {
    $next_action = ['selections/index.php', 'Complete the school structure', 'Create at least one section and one subject before adding classes.'];
} elseif ($faculty_count === 0) {
    $next_action = ['faculty/index.php', 'Create a faculty account', 'A faculty account is required before a class can be assigned.'];
} elseif ($student_count === 0) {
    $next_action = ['students/index.php', 'Create student accounts', 'Students receive their classes automatically through their assigned section.'];
} elseif ($class_count === 0) {
    $next_action = ['class_assignments/index.php', 'Create the first class assignment', 'Connect one faculty, subject, section, academic year, and semester.'];
} else {
    $next_action = ['class_assignments/index.php', 'Review current class assignments', 'Confirm that every subject and section has the correct faculty and academic term.'];
}

render_admin_page_start(
    'dashboard',
    'Overview',
    'StudyLink administration',
    'Manage academic structure, user accounts, and faculty-class assignments from one secure workspace.',
    'System Administration'
);
?>
<?php render_admin_flash($flash); ?>
<?php render_admin_workflow(''); ?>
<section class="admin-card admin-next-action">
    <span class="admin-next-action-icon"><?= study_icon('arrow-right-circle'); ?></span>
    <div>
        <span class="admin-eyebrow">Recommended next action</span>
        <h2><?= e($next_action[1]); ?></h2>
        <p><?= e($next_action[2]); ?></p>
    </div>
    <a class="admin-button primary" href="<?= e($next_action[0]); ?>">Open module <?= study_icon('arrow-right'); ?></a>
</section>
<section class="admin-stats" aria-label="System totals">
    <a class="admin-card admin-stat" href="students/index.php">
        <div class="admin-stat-top"><span>Students</span><span class="admin-stat-icon"><?= study_icon('people'); ?></span></div>
        <strong><?= number_format($student_count); ?></strong>
        <span>Active student accounts</span>
    </a>
    <a class="admin-card admin-stat" href="faculty/index.php">
        <div class="admin-stat-top"><span>Faculty</span><span class="admin-stat-icon"><?= study_icon('person-badge'); ?></span></div>
        <strong><?= number_format($faculty_count); ?></strong>
        <span>Active faculty accounts</span>
    </a>
    <a class="admin-card admin-stat" href="class_assignments/index.php">
        <div class="admin-stat-top"><span>Classes</span><span class="admin-stat-icon"><?= study_icon('collection'); ?></span></div>
        <strong><?= number_format($class_count); ?></strong>
        <span>Class assignments</span>
    </a>
    <a class="admin-card admin-stat" href="subjects/index.php">
        <div class="admin-stat-top"><span>Subjects</span><span class="admin-stat-icon"><?= study_icon('journal-text'); ?></span></div>
        <strong><?= number_format($subject_count); ?></strong>
        <span><?= number_format($inactive_count); ?> inactive user account<?= $inactive_count === 1 ? '' : 's'; ?></span>
    </a>
</section>

<div class="admin-dashboard-grid">
    <section class="admin-card admin-section-card">
        <div class="admin-section-head">
            <div><h2>Management modules</h2><span>Live system records</span></div>
        </div>
        <div class="admin-module-grid">
            <?php foreach ($modules as $module): ?>
                <a class="admin-module-link" href="<?= e($module[0]); ?>">
                    <span><?= study_icon($module[1]); ?></span>
                    <span><strong><?= e($module[2]); ?></strong><small><?= e($module[3]); ?></small></span>
                    <?= study_icon('chevron-right'); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="admin-card admin-term-panel">
        <div class="admin-eyebrow">Current Academic Term</div>
        <h2><?= e($current_term['school_year'] ?? 'No current term'); ?></h2>
        <p><?= e($current_term['semester_name'] ?? 'Select an Academic Year and Semester'); ?></p>
        <div class="admin-term-row">
            <span>System status</span>
            <strong><?= $current_term ? 'Ready' : 'Setup required'; ?></strong>
        </div>
        <div class="admin-term-row">
            <span>Administrator</span>
            <strong><?= e($_SESSION['fullname']); ?></strong>
        </div>
    </aside>
</div>

<section class="admin-card admin-section-card admin-access-card" id="login-appearance">
    <div class="admin-section-head">
        <div><h2>Login Page Appearance</h2><span>System-wide login visual</span></div>
        <strong><?= $login_visual_url !== '' ? 'Custom image active' : 'Color fallback active'; ?></strong>
    </div>
    <div class="admin-access-layout">
        <div class="admin-login-preview">
            <?php if ($login_visual_url !== ''): ?>
                <img src="<?= e($login_visual_url); ?>" alt="Current login page visual">
                <span>Current login image</span>
            <?php else: ?>
                <span>Navy fallback color</span>
            <?php endif; ?>
        </div>
        <div class="admin-access-form">
            <h3>Login page image</h3>
            <p>This image is applied to the Admin, Faculty, and Student login pages. If no image is uploaded, StudyLink automatically uses its navy brand color.</p>
            <form action="update_login_appearance.php" method="POST" enctype="multipart/form-data">
                <?= csrf_field(); ?>
                <input type="hidden" name="action" value="upload">
                <label class="admin-file-field">
                    <span>Upload or replace image</span>
                    <input type="file" name="login_visual" accept="image/jpeg,image/png,image/webp" required>
                    <small>JPG, PNG, or WebP up to 5 MB. A wide landscape image is recommended.</small>
                </label>
                <div class="admin-access-actions">
                    <button class="admin-access-button" type="submit">
                        <?= study_icon('upload'); ?> Save login image
                    </button>
                </div>
            </form>
            <?php if ($login_visual_url !== ''): ?>
                <form action="update_login_appearance.php" method="POST">
                    <?= csrf_field(); ?>
                    <input type="hidden" name="action" value="remove">
                    <div class="admin-access-actions">
                        <button class="admin-access-button secondary" type="submit">
                            <?= study_icon('trash'); ?> Remove image and use color
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="admin-card admin-readiness-card">
    <div class="admin-section-head">
        <div><h2>Academic setup readiness</h2><span><?= $ready_count; ?> of <?= count($setup_checks); ?> requirements complete</span></div>
        <strong><?= $readiness; ?>%</strong>
    </div>
    <div class="admin-readiness-bar"><span style="width: <?= $readiness; ?>%"></span></div>
    <div class="admin-check-grid">
        <?php foreach ($setup_checks as $check): ?>
            <a href="<?= e($check[2]); ?>" class="<?= $check[1] ? 'complete' : 'required'; ?>">
                <?= study_icon($check[1] ? 'check-circle' : 'exclamation-circle'); ?>
                <span><strong><?= e($check[0]); ?></strong><small><?= $check[1] ? 'Configured' : 'Action required'; ?></small></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php render_admin_page_end(); ?>
