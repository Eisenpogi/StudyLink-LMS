<?php
include('../auth/auth.php');
require_role('faculty');
include('../config/database.php');
include('../includes/faculty_ui.php');
include('../includes/academic_term.php');
include('../includes/module_workflow.php');

$class_id = intval($_GET['id'] ?? 0);
$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
if (!$faculty) {
    http_response_code(403);
    exit('Faculty profile not found.');
}

$cover_expression = faculty_cover_expression($conn, 'ca');
$color_expression = faculty_color_expression($conn, 'ca');
$query = mysqli_prepare($conn, "
    SELECT
        ca.*,
        {$cover_expression} AS cover_image,
        {$color_expression} AS cover_color,
        s.subject_code,
        s.subject_name,
        sec.section_name,
        ay.school_year,
        sem.semester_name
    FROM class_assignments ca
    INNER JOIN subjects s ON ca.subject_id = s.id
    INNER JOIN sections sec ON ca.section_id = sec.id
    INNER JOIN academic_years ay ON ca.academic_year_id = ay.id
    INNER JOIN semesters sem ON ca.semester_id = sem.id
    INNER JOIN faculty f ON ca.faculty_id = f.id
    WHERE ca.id = ? AND f.user_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($query, 'ii', $class_id, $user_id);
mysqli_stmt_execute($query);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($query));
mysqli_stmt_close($query);

if (!$class) {
    http_response_code(403);
    exit('Class not found or unauthorized access.');
}

$allowed_tabs = ['overview', 'students', 'materials', 'assignments', 'quizzes', 'attendance'];
$tab = $_GET['tab'] ?? 'overview';
if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'overview';
}

$tab_labels = [
    'overview' => 'Overview',
    'students' => 'Students',
    'materials' => 'Learning Materials',
    'assignments' => 'Assignments',
    'quizzes' => 'Quizzes',
    'attendance' => 'Attendance'
];
$flash = get_flash();
$cover_url = faculty_course_cover_url($class['cover_image']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <title><?php echo e($class['subject_name']); ?> - StudyLink</title>
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('courses', $faculty['faculty_id']); ?>
<?php render_faculty_topbar($class['subject_code'], $class['section_name'] . ' · ' . $class['semester_name']); ?>

<main class="faculty-main">
    <div class="faculty-shell">
        <a class="faculty-text-link" href="/studyLink/faculty/classes.php">← Back to My Courses</a>

        <section class="faculty-card faculty-class-hero" style="margin-top:16px;">
            <div class="faculty-class-cover <?php echo $cover_url ? 'has-image' : ''; ?>" style="<?php echo e(faculty_course_gradient_style($class_id, $class['cover_color'] ?? '')); ?><?php echo $cover_url ? 'background-image:url(' . e($cover_url) . ');' : ''; ?>">
                <div>
                    <div class="faculty-eyebrow" style="color:#f8d842;"><?php echo e($class['subject_code']); ?></div>
                    <h1><?php echo e($class['subject_name']); ?></h1>
                    <p><?php echo e($class['section_name'] . ' · ' . $class['semester_name'] . ' · ' . $class['school_year']); ?></p>
                </div>
                <?php if (faculty_cover_column_ready($conn) && faculty_color_column_ready($conn)): ?>
                    <button class="faculty-cover-settings-button" type="button" id="openCourseAppearance">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 0 18h1.5a1.5 1.5 0 0 0 0-3H12a2 2 0 0 1 0-4h3.5A5.5 5.5 0 0 0 21 8.5C21 5.5 17 3 12 3ZM7.5 9h.01M10 6.5h.01M15 6.5h.01M17.5 9h.01"/></svg>
                        Customize cover
                    </button>
                <?php else: ?>
                    <div class="faculty-cover-form"><strong>Course appearance</strong><small>Import <code>sql/add_course_style.sql</code> once to enable custom gradient colors and course images.</small></div>
                <?php endif; ?>
            </div>
            <nav class="faculty-class-tabs">
                <?php foreach ($tab_labels as $tab_key => $label): ?>
                    <a class="<?php echo $tab === $tab_key ? 'active' : ''; ?>" href="?id=<?php echo $class_id; ?>&tab=<?php echo e($tab_key); ?>"><?php echo e($label); ?></a>
                <?php endforeach; ?>
                <span class="faculty-tab-disabled" title="Not yet available">AI Generator <small>Coming soon</small></span>
            </nav>
        </section>

        <?php if ($flash): ?><div class="faculty-notice <?php echo $flash['type'] === 'success' ? '' : 'error'; ?>"><?php echo e($flash['message']); ?></div><?php endif; ?>
        <?php if (isset($_GET['cover'])): ?>
            <div class="faculty-notice <?php echo $_GET['cover'] === 'saved' || $_GET['cover'] === 'removed' ? '' : 'error'; ?>">
                <?php
                $cover_messages = [
                    'saved' => 'Course image updated.',
                    'removed' => 'Course image removed. The selected gradient is now active.',
                    'color_saved' => 'Course gradient updated. Students will see the same course color.',
                    'missing' => 'Choose an image before saving.',
                    'large' => 'The course image must be 3 MB or smaller.',
                    'type' => 'Only JPG, PNG, and WebP images are allowed.',
                    'failed' => 'The course image could not be saved.'
                ];
                echo e($cover_messages[$_GET['cover']] ?? 'The course image could not be updated.');
                ?>
            </div>
        <?php endif; ?>

        <section class="faculty-card faculty-tab-content">
            <?php
            switch ($tab) {
                case 'students':
                    include('tabs/students.php');
                    break;
                case 'materials':
                    include('tabs/materials.php');
                    break;
                case 'assignments':
                    include('tabs/assignments.php');
                    break;
                case 'quizzes':
                    include('tabs/quizzes.php');
                    break;
                case 'attendance':
                    include('tabs/attendance.php');
                    break;
                default:
                    include('tabs/overview.php');
                    break;
            }
            ?>
        </section>
    </div>
</main>

<?php if (faculty_cover_column_ready($conn) && faculty_color_column_ready($conn)): ?>
<dialog class="faculty-appearance-dialog" id="courseAppearanceDialog">
    <form action="/studyLink/faculty/update_course_cover.php" method="post" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
        <div class="faculty-dialog-heading">
            <div><span class="faculty-eyebrow">Course identity</span><h2>Customize course cover</h2><p>Use a clean gradient or upload an image. This cover also appears in the student interface.</p></div>
            <button type="button" data-close-appearance aria-label="Close">×</button>
        </div>

        <section class="faculty-appearance-section">
            <div class="faculty-appearance-title"><strong>Gradient color</strong><small>StudyLink automatically creates a light-to-dark gradient.</small></div>
            <div class="faculty-color-presets">
                <?php
                $presets = [
                    '#0b2b5b' => 'Navy',
                    '#476ca6' => 'Academic Blue',
                    '#8a6d00' => 'Gold',
                    '#2f6f73' => 'Teal',
                    '#72566f' => 'Plum',
                    '#4f627c' => 'Slate'
                ];
                $active_color = faculty_course_color($class_id, $class['cover_color'] ?? '');
                foreach ($presets as $hex => $name):
                ?>
                    <label class="faculty-color-option" title="<?php echo e($name); ?>">
                        <input type="radio" name="cover_color" value="<?php echo e($hex); ?>" <?php echo strtolower($active_color) === strtolower($hex) ? 'checked' : ''; ?>>
                        <span style="--swatch:<?php echo e($hex); ?>"></span>
                        <small><?php echo e($name); ?></small>
                    </label>
                <?php endforeach; ?>
            </div>
            <label class="faculty-custom-color"><span>Custom color</span><input id="customCourseColor" type="color" value="<?php echo e($active_color); ?>"><small id="customColorValue"><?php echo e(strtoupper($active_color)); ?></small></label>
            <button class="faculty-button gold" type="submit" name="action" value="save_color">Save gradient</button>
        </section>

        <section class="faculty-appearance-section">
            <div class="faculty-appearance-title"><strong>Optional image</strong><small>JPG, PNG, or WebP · maximum 3 MB</small></div>
            <label class="faculty-image-picker"><input type="file" name="cover_image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"><span>Choose course image</span></label>
            <div class="faculty-appearance-actions">
                <button class="faculty-button" type="submit" name="action" value="save_image">Upload image</button>
                <?php if ($cover_url): ?><button class="faculty-button light" type="submit" name="action" value="remove_image">Remove image</button><?php endif; ?>
            </div>
        </section>
    </form>
</dialog>
<script>
(function () {
    var dialog = document.getElementById('courseAppearanceDialog');
    var opener = document.getElementById('openCourseAppearance');
    var custom = document.getElementById('customCourseColor');
    
    var customValue = document.getElementById('customColorValue');
    if (opener && dialog) opener.addEventListener('click', function () { dialog.showModal(); });
    document.querySelector('[data-close-appearance]')?.addEventListener('click', function () { dialog.close(); });
    dialog?.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
    custom?.addEventListener('input', function () {
        var existing = document.getElementById('customColorRadio');
        if (!existing) {
            existing = document.createElement('input');
            existing.type = 'radio';
            existing.name = 'cover_color';
            existing.id = 'customColorRadio';
            existing.hidden = true;
            custom.parentNode.appendChild(existing);
        }
        existing.value = custom.value;
        existing.checked = true;
        customValue.textContent = custom.value.toUpperCase();
    });
    <?php if (isset($_GET['appearance'])): ?>dialog?.showModal();<?php endif; ?>
})();
</script>
<?php endif; ?>
</body>
</html>
