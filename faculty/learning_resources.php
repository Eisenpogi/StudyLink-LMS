<?php
include('../auth/auth.php');
require_role('faculty');
include('../config/database.php');
include('../includes/faculty_ui.php');

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
if (!$faculty) {
    http_response_code(403);
    exit('Faculty profile not found.');
}
$faculty_id = intval($faculty['id']);

$resources = [];
$stmt = mysqli_prepare($conn, '
    SELECT
        lm.id,
        lm.title,
        lm.description,
        lm.file_name,
        lm.created_at,
        sub.subject_code,
        sub.subject_name,
        sec.section_name,
        ca.id AS class_id
    FROM learning_materials lm
    INNER JOIN class_assignments ca ON ca.id = lm.class_assignment_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    WHERE ca.faculty_id = ? AND ay.status = "active" AND sem.status = "active"
    ORDER BY lm.created_at DESC
');
mysqli_stmt_bind_param($stmt, 'i', $faculty_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $resources[] = $row;
}
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <title>Learning Resources - StudyLink</title>
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('resources', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Learning Resources', 'Materials from your current-term courses'); ?>
<main class="faculty-main">
    <div class="faculty-shell">
        <section class="faculty-page-heading">
            <div><div class="faculty-eyebrow">Academic Resources</div><h1>Learning Resources</h1><p>Review current-term class materials and open the correct course to add or manage files.</p></div>
            <a class="faculty-button" href="/studyLink/faculty/drive/index.php">Open Faculty Drive</a>
        </section>
        <div class="faculty-card faculty-filterbar"><label class="grow"><input id="resourceSearch" type="search" placeholder="Search title, subject, or section..."></label></div>
        <section class="faculty-card faculty-list">
            <?php if (empty($resources)): ?><div class="faculty-empty">No learning materials have been uploaded yet.</div><?php endif; ?>
            <?php foreach ($resources as $resource): ?>
                <div class="faculty-list-row" data-resource-search="<?php echo e(strtolower($resource['title'] . ' ' . $resource['subject_code'] . ' ' . $resource['subject_name'] . ' ' . $resource['section_name'])); ?>">
                    <span class="faculty-list-icon">□</span>
                    <span class="faculty-list-copy"><strong><?php echo e($resource['title']); ?></strong><span><?php echo e($resource['subject_code'] . ' · ' . $resource['section_name'] . ' · Uploaded ' . date('M d, Y', strtotime($resource['created_at']))); ?></span></span>
                    <a class="faculty-button small light" href="/studyLink/faculty/class_view.php?id=<?php echo intval($resource['class_id']); ?>&tab=materials">Manage</a>
                </div>
            <?php endforeach; ?>
        </section>
    </div>
</main>
<script>
document.getElementById('resourceSearch').addEventListener('input', function () {
    var query = this.value.toLowerCase().trim();
    document.querySelectorAll('[data-resource-search]').forEach(function (row) {
        row.hidden = query && row.dataset.resourceSearch.indexOf(query) === -1;
    });
});
</script>
</body>
</html>
