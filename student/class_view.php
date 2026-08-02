<?php

include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/faculty_ui.php';
include '../includes/student_notifications.php';
include '../includes/academic_term.php';
include '../includes/module_workflow.php';

$user_id = intval($_SESSION['user_id']);
$class_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$allowed_tabs = ['overview', 'materials', 'modules', 'assignments', 'quizzes'];
$tab = $_GET['tab'] ?? 'overview';

if (!in_array($tab, $allowed_tabs, true)) {
    $tab = 'overview';
}

if ($class_id <= 0) {
    http_response_code(400);
    exit('Invalid class.');
}

$cover_expression = faculty_cover_expression($conn, 'ca');
$color_expression = faculty_color_expression($conn, 'ca');
$class_statement = mysqli_prepare($conn, "
    SELECT ca.id, ca.section_id, {$cover_expression} AS cover_image, {$color_expression} AS cover_color,
           sub.subject_code, sub.subject_name, sub.description,
           sec.section_name, ay.school_year, sem.semester_name,
           u.fullname AS faculty_name, s.id AS student_id
    FROM students s
    INNER JOIN class_assignments ca ON ca.section_id = s.section_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN faculty f ON f.id = ca.faculty_id
    INNER JOIN users u ON u.id = f.user_id
    WHERE s.user_id = ? AND ca.id = ?
    LIMIT 1
");

mysqli_stmt_bind_param($class_statement, 'ii', $user_id, $class_id);
mysqli_stmt_execute($class_statement);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($class_statement));
mysqli_stmt_close($class_statement);

if (!$class) {
    http_response_code(403);
    exit('Class not found or you are not authorized to view it.');
}
$class_term = studylink_class_term($conn, $class_id);
$term_writable = $class_term && $class_term['term_status'] === 'current';
$term_archived = $class_term && $class_term['term_status'] === 'archived';
$class_cover_url = faculty_course_cover_url($class['cover_image']);

$materials = [];
$assignments = [];
$quizzes = [];

if ($tab === 'materials') {
    $statement = mysqli_prepare($conn, '
        SELECT lm.id, lm.title, lm.description, lm.file_name, lm.created_at,
               fdi.file_path, fdi.original_filename, fdi.mime_type, fdi.file_size
        FROM learning_materials lm
        LEFT JOIN faculty_drive_items fdi ON fdi.id = lm.faculty_drive_item_id
        WHERE lm.class_assignment_id = ?
        ORDER BY lm.created_at DESC
    ');
    mysqli_stmt_bind_param($statement, 'i', $class_id);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    while ($row = mysqli_fetch_assoc($result)) {
        $materials[] = $row;
    }
    mysqli_stmt_close($statement);
}

if ($tab === 'assignments') {
    $student_id = intval($class['student_id']);
    $statement = mysqli_prepare($conn, '
        SELECT a.id, a.title, a.instructions, a.due_date, a.created_at,
               fdi.file_path, fdi.original_filename,
               asm.id AS submission_id, asm.file_name AS submission_file,
               asm.file_size AS submission_size, asm.student_comment,
               asm.submitted_at, asm.submission_status, asm.score, asm.remarks,
               asm.graded_at
        FROM assignments a
        LEFT JOIN faculty_drive_items fdi ON fdi.id = a.faculty_drive_item_id
        LEFT JOIN assignment_submissions asm
          ON asm.assignment_id = a.id AND asm.student_id = ?
        WHERE a.class_assignment_id = ?
          AND NOT EXISTS (
              SELECT 1
              FROM course_module_resources cmr_activity
              INNER JOIN course_module_items cmi_activity ON cmi_activity.id = cmr_activity.module_item_id
              INNER JOIN course_modules cm_activity ON cm_activity.id = cmi_activity.module_id
              WHERE cmr_activity.assignment_id = a.id
                AND cm_activity.title NOT LIKE "Existing Coursework%"
          )
        ORDER BY a.due_date ASC
    ');
    mysqli_stmt_bind_param($statement, 'ii', $student_id, $class_id);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    while ($row = mysqli_fetch_assoc($result)) {
        $assignments[] = $row;
    }
    mysqli_stmt_close($statement);
}

if ($tab === 'quizzes') {
    $statement = mysqli_prepare($conn, '
        SELECT q.id, q.title, q.instructions, q.time_limit,
               q.available_from, q.available_until, q.passing_score,
               q.max_attempts, COUNT(qq.id) AS question_count,
               COALESCE(SUM(qq.points), 0) AS total_points,
               (
                   SELECT COUNT(*)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status <> "in_progress"
               ) AS used_attempts,
               (
                   SELECT MAX(qa.id)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status = "in_progress"
               ) AS active_attempt_id,
               (
                   SELECT MAX(qa.id)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status <> "in_progress"
               ) AS latest_attempt_id,
               (
                   SELECT MAX(qa.percentage)
                   FROM quiz_attempts qa
                   WHERE qa.quiz_id = q.id
                     AND qa.student_id = ?
                     AND qa.status IN ("graded", "needs_review")
               ) AS best_percentage
        FROM quizzes q
        LEFT JOIN quiz_questions qq ON qq.quiz_id = q.id
        WHERE q.class_assignment_id = ? AND q.status = "published"
        GROUP BY q.id
        ORDER BY q.created_at DESC
    ');
    $student_id = intval($class['student_id']);
    mysqli_stmt_bind_param(
        $statement,
        'iiiii',
        $student_id,
        $student_id,
        $student_id,
        $student_id,
        $class_id
    );
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    while ($row = mysqli_fetch_assoc($result)) {
        $quizzes[] = $row;
    }
    mysqli_stmt_close($statement);
}

function format_file_size($bytes)
{
    $bytes = intval($bytes);
    if ($bytes <= 0) return '';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    return number_format($bytes / 1024, 2) . ' KB';
}

function student_file_extension($file_name)
{
    return strtolower(pathinfo($file_name ?? '', PATHINFO_EXTENSION));
}

function student_file_kind($file_name, $mime_type = '')
{
    $extension = student_file_extension($file_name);
    if ($mime_type === 'application/pdf' || $extension === 'pdf') return 'pdf';
    if (strpos($mime_type, 'image/') === 0 || in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) return 'image';
    if (in_array($extension, ['doc', 'docx', 'rtf'], true)) return 'word';
    if (in_array($extension, ['xls', 'xlsx', 'csv'], true)) return 'sheet';
    if (in_array($extension, ['ppt', 'pptx'], true)) return 'slides';
    if (in_array($extension, ['zip', 'rar', '7z'], true)) return 'archive';
    if ($extension === 'txt') return 'text';
    return 'file';
}

function student_file_previewable($file_name, $mime_type = '')
{
    return in_array(student_file_kind($file_name, $mime_type), ['pdf', 'image', 'text'], true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($class['subject_name']); ?> - StudyLink</title>
        <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
        <link rel="stylesheet" href="/studyLink/assets/css/module-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
    <style>
        :root{--navy:#071d49;--gold:#f3c815;--ink:#0d1f36;--muted:#666d79;--line:#e4e8ef;--bg:#f7f8fd;--blue:#e6efff;--danger:#cc2f38;--success:#19734e}
        *{box-sizing:border-box}html,body{margin:0;min-height:100%;background:var(--bg);color:var(--ink);font-family:Inter,"Segoe UI",Arial,sans-serif;line-height:1.45}a{color:inherit}.sidebar{position:fixed;inset:0 auto 0 0;width:272px;background:#fff;border-right:1px solid var(--line);display:flex;flex-direction:column;z-index:30}.brand{height:78px;display:flex;align-items:center;gap:11px;padding:0 34px;color:var(--navy);font-size:20px;font-weight:850;text-decoration:none}.brand-mark{width:29px;height:29px;border-radius:9px;background:var(--navy);color:#fff;display:grid;place-items:center;font-size:13px;box-shadow:inset 0 -4px 0 var(--gold)}.side-nav{display:grid;gap:2px;margin-top:12px}.side-nav a{height:56px;padding:0 34px;display:flex;align-items:center;gap:16px;color:#525965;text-decoration:none;font-size:13px;font-weight:700;border-left:4px solid transparent}.side-nav a:hover{background:#f5f7fc}.side-nav a.active{color:var(--navy);background:#eaf0fc;border-left-color:var(--gold)}.nav-icon{width:22px;text-align:center;font-size:18px}.student-card{margin-top:auto;border-top:1px solid var(--line);padding:28px 30px;display:flex;align-items:center;gap:11px}.avatar{width:39px;height:39px;border-radius:50%;display:grid;place-items:center;background:var(--navy);color:#fff;font-weight:850}.student-name{font-size:12px;font-weight:850}.student-role{font-size:9px;color:#8a909b;text-transform:uppercase;letter-spacing:.07em}
        .topbar{position:fixed;left:272px;right:0;top:0;height:78px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 32px;z-index:20}.search{width:min(580px,60%);height:42px;background:#eef3fd;border:1px solid #e0e6f0;border-radius:999px;display:flex;align-items:center;gap:12px;padding:0 18px;color:#6d7480}.search input{border:0;outline:0;background:transparent;width:100%;font:inherit;font-size:14px;color:var(--ink)}.top-icons{display:flex;gap:16px}.top-icon{width:38px;height:38px;border:0;background:transparent;border-radius:50%;font-size:18px;color:#4c535e}.top-icon:hover{background:#f1f4f9}
        .main{margin-left:272px;padding-top:78px;min-height:100vh}.shell{padding:25px 32px 54px;max-width:1240px;margin:auto}.back{font-size:12px;color:#687080;text-decoration:none;font-weight:700}.hero{position:relative;display:flex;align-items:end;justify-content:space-between;gap:22px;margin:15px 0 22px;padding:32px;border-radius:16px;min-height:180px;background:linear-gradient(135deg,var(--course-accent-light),var(--course-accent) 52%,var(--course-accent-dark));background-size:cover;background-position:center;color:#fff;overflow:hidden}.hero:before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(4,18,40,.08),rgba(4,18,40,.44))}.hero.has-course-image:before{background:linear-gradient(180deg,rgba(4,18,40,.22),rgba(4,18,40,.9))}.hero>*{position:relative;z-index:1}.hero .code,.hero .meta{color:#e3eaf6}.hero .term{background:rgba(255,255,255,.16);color:#fff;backdrop-filter:blur(8px)}.code{font-size:11px;color:#686e79;font-weight:850;letter-spacing:.20em;text-transform:uppercase}.hero h1{margin:7px 0 4px;font-size:46px;line-height:1.06}.meta{margin:0;color:var(--muted);font-size:13px}.term{background:#edf2fc;color:var(--navy);padding:10px 14px;border-radius:12px;font-size:12px;font-weight:700}.tabs{display:flex;align-items:center;gap:5px;overflow:auto;background:#fff;border:1px solid #eef0f4;padding:8px;border-radius:999px;margin:0 0 22px;box-shadow:0 5px 16px rgba(23,33,56,.05)}.tabs a{padding:10px 22px;border-radius:999px;text-decoration:none;font-size:12px;font-weight:750;color:#555c68;white-space:nowrap}.tabs a.active{background:#000;color:#fff}.section-title{font-size:23px;margin:0}.flash{padding:13px 15px;border-radius:12px;margin:0 0 16px;font-size:13px;font-weight:700}.success{background:#e9f8f1;color:#11613f}.error{background:#fff0f1;color:#a5262d}
        .assignment-layout{display:grid;grid-template-columns:minmax(0,2.15fr) minmax(250px,1fr);gap:22px;align-items:start}.assignment-list{display:grid;gap:14px}.assignment-card{background:#fff;border:1px solid #eff1f5;border-left:4px solid #7892dc;border-radius:13px;padding:20px 22px;display:grid;grid-template-columns:minmax(0,1fr) 155px;gap:18px;box-shadow:0 3px 11px rgba(16,33,58,.025)}.assignment-card.is-graded{border-left-color:#8b7100}.assignment-card.is-overdue{border-left-color:var(--danger)}.assignment-card h3{font-size:18px;line-height:1.28;margin:7px 0 5px}.assignment-top{display:flex;align-items:center;gap:9px;flex-wrap:wrap}.course-tag,.status{display:inline-flex;border-radius:999px;padding:4px 10px;font-size:9px;text-transform:uppercase;letter-spacing:.06em;font-weight:850}.course-tag{background:var(--blue);color:#59677d}.due{font-size:11px;color:#535b67;font-weight:700}.instructions{font-size:13px;color:#59606b;margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}.attachment{display:inline-flex;margin-top:10px;text-decoration:none;font-size:11px;font-weight:750;color:#31578f}.details{margin-top:11px;padding-top:10px;border-top:1px solid var(--line);font-size:11px;color:#596579}.assignment-side{display:flex;flex-direction:column;justify-content:center;align-items:flex-end;gap:8px}.status.pending{background:#fbe8ea;color:#c22d36}.status.submitted{background:#e9edf5;color:#667fc5}.status.graded{background:#fff1be;color:#725a00}.score{font-size:24px;font-weight:500;color:#846b00;line-height:1}.small{font-size:11px;color:var(--muted);text-align:right}.btn{display:inline-flex;border-radius:8px;padding:9px 13px;text-decoration:none;font-size:11px;font-weight:850;text-align:center}.btn-primary{background:#000;color:#fff}.btn-secondary{background:#eef1f6;color:#344054}.right-rail{display:grid;gap:20px}.rail-card{background:#fff;border:1px solid #eef0f4;border-radius:14px;padding:22px;box-shadow:0 4px 14px rgba(16,33,58,.03)}.rail-card.dark{background:#000;color:#fff;box-shadow:0 16px 30px rgba(0,0,0,.12)}.rail-card h3{font-size:18px;margin:0 0 17px}.rail-stat{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid rgba(120,125,136,.18)}.rail-stat:last-child{border-bottom:0}.rail-label{font-size:12px;color:#777e89}.dark .rail-label{color:#b8bbc2}.rail-value{font-size:22px;font-weight:850}.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.quick-link{background:#f6f8fd;border-radius:9px;padding:17px 8px;text-align:center;text-decoration:none;font-size:11px;font-weight:750}.quick-link span{display:block;font-size:20px;margin-bottom:5px}.plain{background:#fff;border:1px solid var(--line);border-radius:14px;padding:24px}.empty{background:#fff;border:1px dashed #c9d3e1;border-radius:14px;padding:40px;text-align:center;color:var(--muted)}
        .filter-row{display:flex;align-items:center;gap:4px;background:#fff;border:1px solid #eef0f4;padding:8px;border-radius:999px;margin-bottom:22px;box-shadow:0 5px 16px rgba(23,33,56,.05)}.filter-btn{border:0;background:transparent;padding:10px 22px;border-radius:999px;font:inherit;font-size:12px;font-weight:750;color:#555c68;cursor:pointer}.filter-btn.active{background:#000;color:#fff}.filter-spacer{flex:1}.sort-label{font-size:11px;color:#555c68;padding-right:13px}
        @media(max-width:980px){.sidebar{width:224px}.topbar{left:224px}.main{margin-left:224px}.assignment-layout{grid-template-columns:1fr}.right-rail{grid-template-columns:1fr 1fr}.hero h1{font-size:39px}}
        @media(max-width:720px){.sidebar{position:static;width:100%;height:auto;border-right:0}.brand{height:64px;padding:0 18px}.side-nav{display:flex;overflow:auto;margin:0;border-top:1px solid var(--line);border-bottom:1px solid var(--line)}.side-nav a{height:50px;padding:0 14px;border-left:0;border-bottom:3px solid transparent;white-space:nowrap}.side-nav a.active{border-bottom-color:var(--gold)}.student-card{display:none}.topbar{position:static;height:64px;padding:0 16px}.main{margin-left:0;padding-top:0}.search{width:78%}.shell{padding:18px 16px 40px}.hero{align-items:flex-start;flex-direction:column;padding-top:8px}.hero h1{font-size:34px}.assignment-card{grid-template-columns:1fr}.assignment-side{align-items:flex-start}.small{text-align:left}.right-rail{grid-template-columns:1fr}.filter-row{border-radius:14px;overflow:auto}.filter-spacer,.sort-label{display:none}}
    </style>
</head>
<body>
<aside class="sidebar">
<a class="brand" href="/studyLink/student/dashboard.php"><span class="brand-mark">S</span>StudyLink</a>
<nav class="side-nav">
<a href="/studyLink/student/dashboard.php"><span class="nav-icon"><?php echo study_icon('grid'); ?></span>Dashboard</a>
<a href="/studyLink/student/classes.php"><span class="nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Courses</a>
<a class="<?php echo $tab === 'modules' ? 'active' : ''; ?>" href="?id=<?php echo $class_id; ?>&tab=modules"><span class="nav-icon"><?php echo study_icon('collection'); ?></span>Modules</a>
<a href="/studyLink/student/calendar.php"><span class="nav-icon"><?php echo study_icon('calendar3'); ?></span>Calendar</a>
<a href="/studyLink/student/messages.php"><span class="nav-icon"><?php echo study_icon('envelope'); ?></span>Messages</a>
</nav>
<div class="student-card"><div class="avatar">S</div><div><div class="student-name">Student Account</div><div class="student-role"><?php echo htmlspecialchars($class['section_name']); ?></div></div></div>
</aside>
<header class="topbar"><label class="search"><span><?php echo study_icon('search'); ?></span><input id="assignmentSearch" type="search" placeholder="Search <?php echo $tab === 'materials' ? 'learning materials' : ($tab === 'modules' ? 'modules' : 'assignments'); ?>..." autocomplete="off"></label><div class="top-icons"><?php render_student_notification_button($conn, $user_id, 'top-icon'); ?><a class="account-logout" href="/studyLink/auth/logout.php?portal=student"><span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span><span class="account-logout-label">Log out</span></a></div></header>
<main class="main"><div class="shell">
<a class="back" href="/studyLink/student/classes.php"><?php echo study_icon('arrow-left'); ?> Back to My Classes</a>
<section class="hero <?php echo $class_cover_url ? 'has-course-image' : ''; ?>" style="<?php echo e(faculty_course_gradient_style($class_id, $class['cover_color'] ?? '')); ?><?php echo $class_cover_url ? 'background-image:url(' . e($class_cover_url) . ');' : ''; ?>"><div><div class="code"><?php echo $tab === 'materials' ? 'Academic Resources' : ($tab === 'modules' ? 'Learning Workspace' : ($tab === 'quizzes' ? 'Academic Assessment' : 'Academic Progress')); ?></div><h1><?php echo $tab === 'assignments' ? 'Assignments' : ($tab === 'materials' ? 'Learning Materials' : ($tab === 'modules' ? 'Modules' : ($tab === 'quizzes' ? 'Quiz Performance' : htmlspecialchars($class['subject_name'])))); ?></h1><p class="meta"><?php echo htmlspecialchars($class['subject_code']); ?> · <?php echo htmlspecialchars($class['section_name']); ?> · <?php echo htmlspecialchars($class['faculty_name']); ?></p></div><div class="term"><?php echo htmlspecialchars($class['school_year']); ?> · <?php echo htmlspecialchars($class['semester_name']); ?></div></section>

<?php $flash = get_flash(); ?>
<?php if ($flash): ?>
    <div class="flash <?php echo $flash['type'] === 'error' ? 'error' : 'success'; ?>" style="margin-top:18px">
        <?php echo e($flash['message']); ?>
    </div>
<?php endif; ?>
<?php if (!$term_writable): ?>
    <div class="flash success" style="margin-top:18px">
        <?php echo study_icon('lock'); ?>
        <strong><?php echo $term_archived ? 'Archived Academic Term' : 'Previous Academic Term'; ?></strong>
        — You can view and download your recorded materials, submissions, results, grades, and feedback. New submissions and quiz attempts are disabled.
    </div>
<?php endif; ?>
<nav class="tabs">
<a class="<?php echo $tab === 'overview' ? 'active' : ''; ?>" href="?id=<?php echo $class_id; ?>&tab=overview">Overview</a>
<a class="<?php echo $tab === 'materials' ? 'active' : ''; ?>" href="?id=<?php echo $class_id; ?>&tab=materials">Learning Materials</a>
<a class="<?php echo $tab === 'assignments' ? 'active' : ''; ?>" href="?id=<?php echo $class_id; ?>&tab=assignments">Assignments</a>
<a class="<?php echo $tab === 'modules' ? 'active' : ''; ?>" href="?id=<?php echo $class_id; ?>&tab=modules">Modules</a>
</nav>

<?php if ($tab === 'overview'): ?>
    <h3>Class Overview</h3>
    <p><?php echo !empty($class['description'])
        ? nl2br(htmlspecialchars($class['description']))
        : 'No class description has been added yet.'; ?></p>

<?php elseif ($tab === 'materials'): ?>
    <?php $total_material_bytes = array_sum(array_map(function ($item) { return intval($item['file_size'] ?? 0); }, $materials)); ?>
    <div class="material-layout"><div class="material-list">
    <?php if (!$materials): ?><div class="empty">No learning materials have been posted yet.</div><?php endif; ?>
    <?php foreach ($materials as $material): ?>
        <?php
        $material_name = $material['original_filename'] ?: $material['file_name'];
        $material_kind = student_file_kind($material_name, $material['mime_type'] ?? '');
        $material_extension = strtoupper(student_file_extension($material_name) ?: 'FILE');
        $material_preview_url = 'preview_file.php?type=material&id=' . intval($material['id']);
        ?>
        <article class="material-card material-preview-card rail-card searchable-item">
            <?php if (!empty($material['file_path'])): ?>
                <a class="material-thumbnail <?php echo htmlspecialchars($material_kind); ?>" href="<?php echo $material_preview_url; ?>" target="_blank" rel="noopener" aria-label="Preview <?php echo htmlspecialchars($material['title']); ?>">
                    <?php if ($material_kind === 'image'): ?>
                        <img src="<?php echo $material_preview_url; ?>&stream=1" alt="">
                    <?php elseif ($material_kind === 'pdf'): ?>
                        <iframe src="<?php echo $material_preview_url; ?>&stream=1#toolbar=0&navpanes=0&scrollbar=0" title="" tabindex="-1" loading="lazy"></iframe>
                        <span class="thumbnail-shield" aria-hidden="true"></span>
                    <?php else: ?>
                        <span class="thumbnail-file-icon"><?php echo study_icon($material_kind === 'archive' ? 'file-zip' : ($material_kind === 'image' ? 'file-image' : 'file-earmark-text')); ?></span>
                        <strong><?php echo htmlspecialchars($material_extension); ?></strong>
                    <?php endif; ?>
                    <span class="thumbnail-open"><?php echo study_icon('eye'); ?> Preview</span>
                </a>
            <?php else: ?>
                <div class="material-thumbnail file"><strong>FILE</strong></div>
            <?php endif; ?>
            <div class="material-content">
                <div class="material-type-row"><span class="file-kind-badge <?php echo htmlspecialchars($material_kind); ?>"><?php echo htmlspecialchars($material_extension); ?></span><span>Posted <?php echo htmlspecialchars(date('M d, Y', strtotime($material['created_at']))); ?></span></div>
                <h3><a href="<?php echo $material_preview_url; ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($material['title']); ?></a></h3>
                <div class="material-meta"><?php echo htmlspecialchars($class['subject_code']); ?><?php echo !empty($material['file_size']) ? ' · ' . format_file_size($material['file_size']) : ''; ?></div>
                <?php if (!empty($material['description'])): ?><p class="material-description"><?php echo nl2br(htmlspecialchars($material['description'])); ?></p><?php endif; ?>
            </div>
            <?php if (!empty($material['file_path'])): ?>
                <div class="material-actions">
                    <a class="button secondary" href="<?php echo $material_preview_url; ?>" target="_blank" rel="noopener"><?php echo study_icon('eye'); ?> Preview</a>
                    <a class="button" href="<?php echo $material_preview_url; ?>&download=1"><?php echo study_icon('download'); ?> Download</a>
                </div>
            <?php else: ?><span class="small">No file</span><?php endif; ?>
        </article>
    <?php endforeach; ?></div>
    <aside class="rail"><section class="rail-card"><h3>Material Summary</h3><div class="rail-row"><span>Total files</span><strong><?php echo count($materials); ?></strong></div><div class="rail-row"><span>Storage</span><strong><?php echo format_file_size($total_material_bytes) ?: '0 KB'; ?></strong></div></section><section class="rail-card dark"><div class="code" style="color:var(--gold)">Quick Access</div><h3 style="margin-top:10px">Keep your course files organized.</h3><p class="small" style="color:#c5c8cf;text-align:left">Download posted references and review them even outside StudyLink.</p></section></aside></div>

<?php elseif ($tab === 'modules'): ?>
    <?php include('tabs/modules.php'); ?>

<?php elseif ($tab === 'assignments'): ?>
    <?php
    $pending_count = 0;
    $submitted_count = 0;
    $graded_count = 0;
    $score_total = 0;
    foreach ($assignments as $summary_assignment) {
        if ($summary_assignment['score'] !== null) {
            $graded_count++;
            $score_total += floatval($summary_assignment['score']);
        } elseif (!empty($summary_assignment['submission_id'])) {
            $submitted_count++;
        } else {
            $pending_count++;
        }
    }
    $average_score = $graded_count > 0 ? $score_total / $graded_count : null;
    ?>
    <div class="filter-row" aria-label="Assignment filters">
        <button class="filter-btn active" type="button" data-filter="all">All Tasks</button>
        <button class="filter-btn" type="button" data-filter="pending">Pending</button>
        <button class="filter-btn" type="button" data-filter="submitted">Submitted</button>
        <button class="filter-btn" type="button" data-filter="graded">Graded</button>
        <span class="filter-spacer"></span><span class="sort-label">Sort by Due Date</span>
    </div>

    <?php if (isset($_GET['submission']) && $_GET['submission'] === 'saved'): ?>
        <div class="flash success">Your assignment was submitted successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['error'])): ?>
        <div class="flash error"><?php echo htmlspecialchars($_GET['error']); ?></div>
    <?php endif; ?>

    <?php if (!$assignments): ?>
        <div class="empty">No assignments have been posted yet.</div>
    <?php endif; ?>

    <div class="assignment-layout"><div class="assignment-list">
    <?php foreach ($assignments as $assignment): ?>
        <?php
        $deadline_passed = strtotime($assignment['due_date']) < time();
        $has_submission = !empty($assignment['submission_id']);
        $is_graded = $has_submission && $assignment['score'] !== null;
        $filter_status = $is_graded ? 'graded' : ($has_submission ? 'submitted' : 'pending');
        ?>
        <article class="assignment-card <?php echo $is_graded ? 'is-graded' : (($deadline_passed && !$has_submission) ? 'is-overdue' : ''); ?>" data-status="<?php echo $filter_status; ?>">
        <div><div class="assignment-top"><span class="course-tag"><?php echo htmlspecialchars($class['subject_code']); ?></span><span class="due">Due <?php echo htmlspecialchars(date('M d, Y · g:i A', strtotime($assignment['due_date']))); ?></span></div>
        <h3><?php echo htmlspecialchars($assignment['title']); ?></h3>
        <p class="instructions"><?php echo nl2br(htmlspecialchars($assignment['instructions'] ?? '')); ?></p>

        <?php if (!empty($assignment['file_path'])): ?>
                <span class="attachment-actions">
                <a class="attachment" href="preview_file.php?type=assignment&id=<?php echo intval($assignment['id']); ?>" target="_blank" rel="noopener">
                    <?php echo study_icon('eye'); ?> Preview attachment
                    <?php if (!empty($assignment['original_filename'])): ?>
                        - <?php echo htmlspecialchars($assignment['original_filename']); ?>
                    <?php endif; ?>
                </a>
                <a class="attachment attachment-download" href="preview_file.php?type=assignment&id=<?php echo intval($assignment['id']); ?>&download=1"><?php echo study_icon('download'); ?> Download</a>
                </span>
        <?php endif; ?>

        <?php if ($has_submission): ?>
            <div class="details">
                <strong>File:</strong>
                <a href="preview_file.php?type=submission&id=<?php echo intval($assignment['submission_id']); ?>" target="_blank" rel="noopener">
                    <?php echo htmlspecialchars($assignment['submission_file']); ?>
                </a>
                <?php if (!empty($assignment['submission_size'])): ?>
                    (<?php echo format_file_size($assignment['submission_size']); ?>)
                <?php endif; ?><br>
                <strong>Your comment:</strong>
                <?php echo empty($assignment['student_comment'])
                    ? 'None'
                    : nl2br(htmlspecialchars($assignment['student_comment'])); ?><br>
                <strong>Faculty remarks:</strong>
                <?php echo empty($assignment['remarks'])
                    ? 'None'
                    : nl2br(htmlspecialchars($assignment['remarks'])); ?>
            </div>
        <?php endif; ?>
        </div><div class="assignment-side">
        <?php if ($is_graded): ?><div class="score"><?php echo htmlspecialchars($assignment['score']); ?></div><span class="status graded">Graded</span>
        <?php elseif ($has_submission): ?><span class="status submitted"><?php echo htmlspecialchars(str_replace('_', ' ', $assignment['submission_status'])); ?></span><span class="small">Submitted <?php echo htmlspecialchars(date('M d, g:i A', strtotime($assignment['submitted_at']))); ?></span>
        <?php else: ?><span class="status pending">Not submitted</span><?php endif; ?>
        <?php if ($term_writable && (!$has_submission || !$deadline_passed)): ?>
            <a class="btn btn-primary" href="submit_assignment.php?id=<?php echo intval($assignment['id']); ?>">
                <?php echo $has_submission ? 'Replace / Resubmit' : 'Submit Assignment'; ?>
            </a>
        <?php elseif (!$term_writable): ?>
            <span class="small">Read-only term</span>
        <?php elseif ($deadline_passed): ?>
            <span class="small">Resubmission closed</span>
        <?php endif; ?>
        <?php if ($has_submission): ?>
            <a class="btn btn-secondary" href="preview_file.php?type=submission&id=<?php echo intval($assignment['submission_id']); ?>" target="_blank" rel="noopener"><?php echo study_icon('eye'); ?> Preview File</a>
        <?php endif; ?>
        </div></article>
    <?php endforeach; ?>
    </div>
    <aside class="right-rail">
        <section class="rail-card dark">
            <h3>Assignment Summary</h3>
            <div class="rail-stat"><span class="rail-label">Pending</span><span class="rail-value"><?php echo $pending_count; ?></span></div>
            <div class="rail-stat"><span class="rail-label">Submitted</span><span class="rail-value"><?php echo $submitted_count; ?></span></div>
            <div class="rail-stat"><span class="rail-label">Graded</span><span class="rail-value"><?php echo $graded_count; ?></span></div>
        </section>
        <section class="rail-card">
            <h3>Class Progress</h3>
            <div class="rail-stat"><span class="rail-label">Average score</span><span class="rail-value"><?php echo $average_score === null ? '—' : number_format($average_score, 1); ?></span></div>
            <div class="rail-stat"><span class="rail-label">Total tasks</span><span class="rail-value"><?php echo count($assignments); ?></span></div>
        </section>
        <section class="rail-card">
            <h3>Quick Resources</h3>
            <div class="quick-grid">
                <a class="quick-link" href="?id=<?php echo $class_id; ?>&tab=materials"><span><?php echo study_icon('folder2-open'); ?></span>Materials</a>
                <a class="quick-link" href="?id=<?php echo $class_id; ?>&tab=assignments"><span><?php echo study_icon('clipboard2-check'); ?></span>Assignments</a>
                <a class="quick-link" href="/studyLink/student/classes.php"><span><?php echo study_icon('mortarboard'); ?></span>Courses</a>
                <a class="quick-link" href="/studyLink/student/dashboard.php"><span><?php echo study_icon('grid'); ?></span>Dashboard</a>
            </div>
        </section>
    </aside></div>

<?php elseif ($tab === 'quizzes'): ?>
    <?php
    $completed_quizzes = 0;
    $score_sum = 0;
    $score_items = 0;
    $available_quizzes = 0;
    $next_quiz = null;
    $quiz_now = time();
    foreach ($quizzes as $summary_quiz) {
        if (intval($summary_quiz['used_attempts']) > 0) $completed_quizzes++;
        if ($summary_quiz['best_percentage'] !== null) {
            $score_sum += floatval($summary_quiz['best_percentage']);
            $score_items++;
        }
        $summary_not_started = !empty($summary_quiz['available_from']) && strtotime($summary_quiz['available_from']) > $quiz_now;
        $summary_closed = !empty($summary_quiz['available_until']) && strtotime($summary_quiz['available_until']) <= $quiz_now;
        $summary_attempts_left = max(0, intval($summary_quiz['max_attempts']) - intval($summary_quiz['used_attempts']));
        if (!$summary_not_started && !$summary_closed &&
            (!empty($summary_quiz['active_attempt_id']) || $summary_attempts_left > 0)) {
            $available_quizzes++;
            if ($next_quiz === null) $next_quiz = $summary_quiz;
        }
    }
    $quiz_average = $score_items ? $score_sum / $score_items : 0;
    ?>
    <section class="quiz-summary">
        <article class="score-card rail-card"><div class="score-ring"><?php echo number_format($quiz_average, 0); ?>%</div><h3>Average Score</h3><div class="small" style="text-align:center">Based on graded attempts</div></article>
        <article class="metric-card rail-card"><div class="eyebrow">Completed</div><div class="metric-value"><?php echo $completed_quizzes; ?></div><div class="small" style="text-align:left">Quizzes attempted</div></article>
        <article class="metric-card rail-card"><div class="eyebrow">Available</div><div class="metric-value"><?php echo $available_quizzes; ?></div><div class="small" style="text-align:left">Ready to take now</div></article>
    </section>
    <?php if ($next_quiz): ?>
        <section class="quiz-next-up">
            <div>
                <span class="next-up-label">Next up</span>
                <h2><?php echo htmlspecialchars($next_quiz['title']); ?></h2>
                <p>
                    <?php echo intval($next_quiz['question_count']); ?> questions ·
                    <?php echo intval($next_quiz['time_limit']) > 0 ? intval($next_quiz['time_limit']) . ' minutes' : 'No time limit'; ?>
                </p>
            </div>
            <?php if (!$term_writable): ?>
                <span class="next-up-action"><?php echo study_icon('lock'); ?><span>Read only</span></span>
            <?php elseif (!empty($next_quiz['active_attempt_id'])): ?>
                <a class="next-up-action" href="take_quiz.php?attempt_id=<?php echo intval($next_quiz['active_attempt_id']); ?>">
                    <?php echo study_icon('play-fill'); ?><span>Continue Quiz</span>
                </a>
            <?php else: ?>
                <form action="start_quiz.php" method="post" onsubmit="return confirm('Start this quiz attempt now? The timer starts immediately.');">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="quiz_id" value="<?php echo intval($next_quiz['id']); ?>">
                    <button class="next-up-action" type="submit"><?php echo study_icon('play-fill'); ?><span>Take Quiz</span></button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <div class="section-head"><h2>Assessments</h2></div><div class="quiz-list">
    <?php if (!$quizzes): ?><div class="empty">No published quizzes are available yet.</div><?php endif; ?>
    <?php foreach ($quizzes as $quiz): ?>
        <?php
        $now = time();
        $not_started = !empty($quiz['available_from']) && strtotime($quiz['available_from']) > $now;
        $closed = !empty($quiz['available_until']) && strtotime($quiz['available_until']) <= $now;
        $attempts_left = max(0, intval($quiz['max_attempts']) - intval($quiz['used_attempts']));
        ?>
        <article class="quiz-card rail-card searchable-item">
        <div><span class="course-tag"><?php echo htmlspecialchars($class['subject_code']); ?></span><h3><?php echo htmlspecialchars($quiz['title']); ?></h3><p class="instructions"><?php echo nl2br(htmlspecialchars($quiz['instructions'] ?? '')); ?></p><div class="quiz-meta"><span><?php echo study_icon('list-ol'); ?> <?php echo intval($quiz['question_count']); ?> questions</span><span><?php echo study_icon('award'); ?> <?php echo htmlspecialchars($quiz['total_points']); ?> points</span><span><?php echo study_icon('stopwatch'); ?> <?php echo intval($quiz['time_limit']) > 0 ? intval($quiz['time_limit']) . ' minutes' : 'No time limit'; ?></span><span><?php echo study_icon('arrow-repeat'); ?> <?php echo intval($quiz['used_attempts']); ?>/<?php echo intval($quiz['max_attempts']); ?> attempts used</span><span><?php echo study_icon('bar-chart'); ?> Best: <?php echo $quiz['best_percentage'] === null ? '—' : number_format(floatval($quiz['best_percentage']), 2) . '%'; ?></span></div></div>
        <div class="quiz-actions">
        <?php if (!$term_writable): ?>
            <span class="status submitted">Read-only term</span>
        <?php elseif ($not_started): ?>
            <span class="status submitted">Starts <?php echo e($quiz['available_from']); ?></span>
        <?php elseif ($closed): ?>
            <span class="status pending">Closed</span>
        <?php elseif (!empty($quiz['active_attempt_id'])): ?>
            <a class="button" href="take_quiz.php?attempt_id=<?php echo intval($quiz['active_attempt_id']); ?>"><?php echo study_icon('play-circle'); ?> Continue Quiz</a>
        <?php elseif ($attempts_left > 0): ?>
            <form action="start_quiz.php" method="post" onsubmit="return confirm('Start this quiz attempt now? The timer starts immediately.');">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="quiz_id" value="<?php echo intval($quiz['id']); ?>">
                <button class="button quiz-take-button" type="submit"><?php echo study_icon(intval($quiz['used_attempts']) > 0 ? 'arrow-repeat' : 'play-circle'); ?> <?php echo intval($quiz['used_attempts']) > 0 ? 'Try Again' : 'Take Quiz'; ?></button>
            </form>
        <?php else: ?>
            <span class="status pending">No attempts left</span>
        <?php endif; ?>

        <?php if (!empty($quiz['latest_attempt_id'])): ?>
            <a class="button secondary" href="quiz_result.php?attempt_id=<?php echo intval($quiz['latest_attempt_id']); ?>"><?php echo study_icon('bar-chart'); ?> View Result</a>
        <?php endif; ?>
        </div></article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div></main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var cards = Array.from(document.querySelectorAll('.assignment-card'));
    var searchableItems = Array.from(document.querySelectorAll('.searchable-item'));
    var buttons = document.querySelectorAll('.filter-btn');
    var search = document.getElementById('assignmentSearch');
    var activeFilter = 'all';
    function applyFilters() {
        var query = search ? search.value.trim().toLowerCase() : '';
        cards.forEach(function (card) {
            var matchesStatus = activeFilter === 'all' || card.dataset.status === activeFilter;
            var matchesSearch = !query || card.textContent.toLowerCase().indexOf(query) !== -1;
            card.style.display = matchesStatus && matchesSearch ? 'grid' : 'none';
        });
        searchableItems.forEach(function (item) {
            item.style.display = !query || item.textContent.toLowerCase().indexOf(query) !== -1 ? 'grid' : 'none';
        });
    }
    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            buttons.forEach(function (item) { item.classList.remove('active'); });
            button.classList.add('active');
            activeFilter = button.dataset.filter;
            applyFilters();
        });
    });
    if (search) search.addEventListener('input', applyFilters);
});
</script>
</body>
</html>
