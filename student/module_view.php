<?php
include('../auth/auth.php');
require_role('student');
include('../config/database.php');
include('../includes/student_ui.php');
include('../includes/academic_term.php');
include('../includes/module_workflow.php');

$user_id = intval($_SESSION['user_id']);
$module_id = intval($_GET['id'] ?? 0);
$class_id = intval($_GET['class_id'] ?? 0);
$stmt = mysqli_prepare($conn, 'SELECT id, student_no, section_id FROM students WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$student) { http_response_code(403); exit('Student profile not found.'); }
$student_id = intval($student['id']);
studylink_ensure_academic_terms($conn);
studylink_sync_legacy_coursework($conn, 0, intval($student['section_id']));
$module = studylink_student_module_access($conn, $module_id, $class_id, $student_id);
if (!$module) { http_response_code(403); exit('Module not found or unavailable.'); }

$stmt = mysqli_prepare($conn, '
    SELECT ca.id, s.subject_code, s.subject_name, sec.section_name, ay.school_year, sem.semester_name
    FROM class_assignments ca
    INNER JOIN subjects s ON s.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    WHERE ca.id = ? LIMIT 1
');
mysqli_stmt_bind_param($stmt, 'i', $class_id);
mysqli_stmt_execute($stmt);
$class = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
$term = studylink_class_term($conn, $class_id);
$term_writable = $term && $term['term_status'] === 'current';
$flash = get_flash();

$items = [];
$stmt = mysqli_prepare($conn, '
    SELECT cmi.id, cmi.item_type, cmi.title, cmi.instructions, cmi.display_order,
           cmr.assignment_id, cmr.quiz_id,
           a.due_date, asm.id AS submission_id, asm.submitted_at, asm.score AS assignment_score,
           q.status AS quiz_status, q.time_limit, q.available_from, q.available_until, q.max_attempts,
           (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count,
           (SELECT COUNT(*) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ? AND qa.status <> "in_progress") AS used_attempts,
           (SELECT MAX(qa.id) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ? AND qa.status = "in_progress") AS active_attempt_id,
           (SELECT MAX(qa.id) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ? AND qa.status <> "in_progress") AS latest_attempt_id,
           (SELECT MAX(qa.percentage) FROM quiz_attempts qa WHERE qa.quiz_id = q.id AND qa.student_id = ? AND qa.status IN ("graded","needs_review")) AS best_percentage
    FROM course_module_items cmi
    INNER JOIN course_module_resources cmr ON cmr.module_item_id = cmi.id AND cmr.class_assignment_id = ?
    LEFT JOIN assignments a ON a.id = cmr.assignment_id
    LEFT JOIN assignment_submissions asm ON asm.assignment_id = a.id AND asm.student_id = ?
    LEFT JOIN quizzes q ON q.id = cmr.quiz_id
    WHERE cmi.module_id = ?
    ORDER BY cmi.display_order, cmi.id
');
mysqli_stmt_bind_param($stmt, 'iiiiiii', $student_id, $student_id, $student_id, $student_id, $class_id, $student_id, $module_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) $items[] = $row;
mysqli_stmt_close($stmt);
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo e($module['title']); ?> - StudyLink</title>
<link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
<link rel="stylesheet" href="/studyLink/assets/css/module-interface.css">
</head><body class="student-interface module-page">
<?php render_student_sidebar('modules', $student['student_no']); ?>
<?php render_student_topbar($conn, $class['subject_code']); ?>
<main class="student-main"><div class="module-shell">
    <a class="student-text-link" href="/studyLink/student/modules.php">← Back to Modules</a>
    <section class="student-module-hero">
        <div class="module-eyebrow" style="color:#f3c815"><?php echo e($class['subject_code'] . ' · ' . $class['section_name']); ?></div>
        <h1><?php echo e($module['title']); ?></h1>
        <p><?php echo e($module['description'] ?: $class['subject_name']); ?></p>
        <div class="module-class-list"><span class="module-class-chip"><?php echo e($class['semester_name'] . ' · ' . $class['school_year']); ?></span><span class="module-class-chip"><?php echo count($items); ?> learning items</span></div>
    </section>
    <?php if ($flash): ?><div class="module-notice <?php echo $flash['type'] === 'success' ? '' : 'error'; ?>"><?php echo e($flash['message']); ?></div><?php endif; ?>
    <?php if (!$term_writable): ?><div class="module-notice error">This Academic Term is read-only. You may review module content and previous results, but new submissions and attempts are disabled.</div><?php endif; ?>
    <section class="module-panel"><div class="module-panel-head"><h2>Module content</h2><span>Follow the order below</span></div><div class="module-panel-body"><div class="module-item-list">
        <?php if (!$items): ?><div class="module-empty"><h2>No content available</h2><p>Your teacher has not added content for this class yet.</p></div><?php endif; ?>
        <?php foreach ($items as $index => $item): ?>
            <?php
            $type = $item['item_type'];
            $now = time();
            $quiz_open = $item['quiz_status'] === 'published'
                && (!$item['available_from'] || strtotime($item['available_from']) <= $now)
                && (!$item['available_until'] || strtotime($item['available_until']) > $now);
            ?>
            <article class="module-item <?php echo e($type); ?>">
                <span class="module-item-type"><?php echo study_icon($type === 'activity' ? 'clipboard2-check' : ($type === 'exam' ? 'journal-check' : 'patch-question')); ?></span>
                <div><div class="module-eyebrow">Item <?php echo $index + 1; ?> · <?php echo e($type); ?></div><h3><?php echo e($item['title']); ?></h3><?php if ($item['instructions']): ?><p><?php echo nl2br(e($item['instructions'])); ?></p><?php endif; ?>
                    <div class="module-class-list">
                        <?php if ($item['assignment_id']): ?><span class="module-class-chip">Due <?php echo e(date('M d, Y · g:i A', strtotime($item['due_date']))); ?></span><span class="module-class-chip"><?php echo $item['submission_id'] ? 'Submitted' : 'Not submitted'; ?></span><?php endif; ?>
                        <?php if ($item['quiz_id']): ?><span class="module-class-chip"><?php echo intval($item['question_count']); ?> questions</span><span class="module-class-chip"><?php echo intval($item['time_limit']) > 0 ? intval($item['time_limit']) . ' minutes' : 'No time limit'; ?></span><span class="module-class-chip"><?php echo intval($item['used_attempts']); ?>/<?php echo intval($item['max_attempts']); ?> attempts</span><?php endif; ?>
                    </div>
                </div>
                <div class="module-item-actions">
                    <?php if ($item['assignment_id']): ?>
                        <?php if ($term_writable): ?><a class="module-button <?php echo $item['submission_id'] ? 'light' : ''; ?>" href="/studyLink/student/submit_assignment.php?id=<?php echo intval($item['assignment_id']); ?>&module_id=<?php echo $module_id; ?>&class_id=<?php echo $class_id; ?>"><?php echo $item['submission_id'] ? 'View submission' : 'Open activity'; ?></a>
                        <?php elseif ($item['submission_id']): ?><a class="module-button light" href="/studyLink/student/submit_assignment.php?id=<?php echo intval($item['assignment_id']); ?>&module_id=<?php echo $module_id; ?>&class_id=<?php echo $class_id; ?>">View submission</a><?php endif; ?>
                    <?php else: ?>
                        <?php if ($item['active_attempt_id'] && $term_writable): ?><a class="module-button" href="/studyLink/student/take_quiz.php?attempt_id=<?php echo intval($item['active_attempt_id']); ?>&module_id=<?php echo $module_id; ?>&class_id=<?php echo $class_id; ?>">Continue <?php echo e($type); ?></a>
                        <?php elseif ($item['latest_attempt_id']): ?><a class="module-button light" href="/studyLink/student/quiz_result.php?attempt_id=<?php echo intval($item['latest_attempt_id']); ?>&module_id=<?php echo $module_id; ?>&class_id=<?php echo $class_id; ?>">View result</a><?php endif; ?>
                        <?php if ($term_writable && $quiz_open && intval($item['used_attempts']) < intval($item['max_attempts']) && !$item['active_attempt_id']): ?><form action="/studyLink/student/start_quiz.php" method="post"><?php echo csrf_field(); ?><input type="hidden" name="quiz_id" value="<?php echo intval($item['quiz_id']); ?>"><input type="hidden" name="module_id" value="<?php echo $module_id; ?>"><input type="hidden" name="class_id" value="<?php echo $class_id; ?>"><button class="module-button" type="submit">Start <?php echo e($type); ?></button></form><?php elseif ($item['quiz_status'] !== 'published'): ?><span class="module-status">Not published</span><?php endif; ?>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div></div></section>
</div></main><script src="/studyLink/assets/js/app-shell.js"></script></body></html>
