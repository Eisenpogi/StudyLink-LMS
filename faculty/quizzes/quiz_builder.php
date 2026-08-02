<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../includes/ui_icons.php');
include('../../includes/academic_term.php');
include('../../includes/module_workflow.php');

require_role('faculty');

if(isset($_GET['quiz_id'])){
    $quiz_id = intval($_GET['quiz_id']);
} else if(isset($_GET['id'])){
    $quiz_id = intval($_GET['id']);
} else {
    die("Quiz ID is missing.");
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'questions';

$allowed_tabs = ['questions', 'settings', 'results', 'question_bank', 'ai_generator'];

if(!in_array($active_tab, $allowed_tabs, true)){
    $active_tab = 'questions';
}

$faculty_user_id = intval($_SESSION['user_id']);
$module_select = 'NULL AS module_id';
$module_joins = '';
if (studylink_modules_ready($conn)) {
    $module_select = 'cmi.module_id';
    $module_joins = '
        LEFT JOIN course_module_resources cmr ON cmr.quiz_id = q.id
        LEFT JOIN course_module_items cmi ON cmi.id = cmr.module_item_id
    ';
}

$quiz_query = mysqli_query($conn,"
    SELECT q.*, ca.id AS class_assignment_id, s.subject_name, sec.section_name,
           {$module_select}
    FROM quizzes q
    INNER JOIN class_assignments ca ON q.class_assignment_id = ca.id
    INNER JOIN subjects s ON ca.subject_id = s.id
    INNER JOIN sections sec ON ca.section_id = sec.id
    INNER JOIN faculty f ON ca.faculty_id = f.id
    {$module_joins}
    WHERE q.id='$quiz_id'
    AND f.user_id='$faculty_user_id'
");

$quiz = mysqli_fetch_assoc($quiz_query);

if(!$quiz){
    die("Quiz not found or unauthorized access.");
}
$quiz_back_url = !empty($quiz['module_id'])
    ? '../module_view.php?id=' . intval($quiz['module_id'])
    : '../class_view.php?id=' . intval($quiz['class_assignment_id']) . '&tab=quizzes';
$quiz_back_label = !empty($quiz['module_id']) ? 'Back to Module' : 'Back to Manage Quizzes';
$term_writable = studylink_class_is_writable(
    $conn,
    intval($quiz['class_assignment_id'])
);
$question_count_query = mysqli_query($conn,"
    SELECT COUNT(*) AS total_questions, COALESCE(SUM(points), 0) AS total_points
    FROM quiz_questions
    WHERE quiz_id='$quiz_id'
");

$question_count_row = mysqli_fetch_assoc($question_count_query);
$total_questions = intval($question_count_row['total_questions']);
$total_points = floatval($question_count_row['total_points']);
$total_points_label = rtrim(rtrim(number_format($total_points, 2, '.', ''), '0'), '.');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz Builder - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
</head>
<body class="quiz-page">

<div class="quiz-workspace">

    <a class="quiz-back" href="<?php echo htmlspecialchars($quiz_back_url); ?>">
        <?php echo study_icon('arrow-left'); ?> <?php echo htmlspecialchars($quiz_back_label); ?>
    </a>

    <header class="quiz-hero">
        <div class="quiz-hero-top">
            <div>
                <div class="quiz-eyebrow">Quiz Builder</div>
                <h1><?php echo htmlspecialchars($quiz['title']); ?></h1>
                <div class="quiz-hero-meta">
                    <span><?php echo study_icon('book'); ?> <?php echo htmlspecialchars($quiz['subject_name']); ?></span>
                    <span><?php echo study_icon('people'); ?> <?php echo htmlspecialchars($quiz['section_name']); ?></span>
                    <span><?php echo study_icon('list-ol'); ?> <?php echo $total_questions; ?> <?php echo $total_questions === 1 ? 'question' : 'questions'; ?></span>
                    <span><?php echo study_icon('award'); ?> <strong id="quiz_total_points"><?php echo $total_points_label; ?></strong> total <?php echo $total_points == 1.0 ? 'point' : 'points'; ?></span>
                    <span><?php echo study_icon('stopwatch'); ?> <?php echo intval($quiz['time_limit']) > 0 ? intval($quiz['time_limit']) . ' minutes' : 'No time limit'; ?></span>
                </div>
            </div>
            <div class="quiz-hero-actions">
                <a class="quiz-action ghost" href="preview.php?quiz_id=<?php echo $quiz_id; ?>">
                    <?php echo study_icon('eye'); ?> Preview
                </a>
                <?php if (!$term_writable) { ?>
                    <span class="quiz-action secondary"><?php echo study_icon('lock'); ?> Read only</span>
                <?php } elseif($quiz['status'] == 'published'){ ?>
                    <form action="unpublish_quiz.php" method="post" onsubmit="return confirm('Unpublish this quiz? Students will no longer be able to start it.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                        <button type="submit" class="quiz-action secondary"><?php echo study_icon('pause-circle'); ?> Unpublish</button>
                    </form>
                <?php } else { ?>
                    <form action="publish_quiz.php" method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
                        <button type="submit" class="quiz-action primary"><?php echo study_icon('send-check'); ?> Publish Quiz</button>
                    </form>
                <?php } ?>
            </div>
        </div>
        <div class="quiz-status-line">
            <span class="quiz-status <?php echo $quiz['status'] === 'published' ? 'published' : 'draft'; ?>">
                <?php echo study_icon($quiz['status'] === 'published' ? 'broadcast' : 'pencil'); ?>
                <?php echo ucfirst($quiz['status']); ?>
            </span>
            <span id="global_autosave_status" class="quiz-save-state">
                <?php echo study_icon('cloud-check'); ?> Saved
            </span>
        </div>
    </header>

    <?php if (!$term_writable): ?>
        <div class="quiz-alert warning">
            <?php echo study_icon('lock'); ?>
            This quiz belongs to a previous or archived Academic Term. Questions, settings, publishing, attempts, and grades are read-only.
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['bank_saved'])){ ?>
        <div class="quiz-alert success"><?php echo study_icon('check-circle'); ?> Question saved to Question Bank.</div>
    <?php } ?>
    <?php if(isset($_GET['published'])){ ?>
        <div class="quiz-alert success"><?php echo study_icon('check-circle'); ?> Quiz published successfully.</div>
    <?php } ?>
    <?php if(isset($_GET['unpublished'])){ ?>
        <div class="quiz-alert warning"><?php echo study_icon('info-circle'); ?> Quiz returned to draft.</div>
    <?php } ?>

    <?php if(isset($_GET['publish_failed']) && isset($_SESSION['publish_errors'])){ ?>
        <div class="quiz-alert error">
            <strong>Cannot publish quiz.</strong>
            <ul>
                <?php foreach($_SESSION['publish_errors'] as $error){ ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php } ?>
            </ul>
        </div>

        <?php unset($_SESSION['publish_errors']); ?>
    <?php } ?>

    <nav class="quiz-tabs" aria-label="Quiz builder sections">
        <a class="<?php echo ($active_tab == 'questions') ? 'active' : ''; ?>"
           href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=questions">
            <?php echo study_icon('list-ol'); ?> Questions
        </a>

        <a class="<?php echo ($active_tab == 'settings') ? 'active' : ''; ?>"
           href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=settings">
            <?php echo study_icon('sliders'); ?> Settings
        </a>

        <a class="<?php echo ($active_tab == 'results') ? 'active' : ''; ?>"
           href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=results">
            <?php echo study_icon('bar-chart'); ?> Results
        </a>

        <a class="<?php echo ($active_tab == 'question_bank') ? 'active' : ''; ?>"
           href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=question_bank">
            <?php echo study_icon('archive'); ?> Question Bank
        </a>

        <a class="<?php echo ($active_tab == 'ai_generator') ? 'active' : ''; ?>"
           href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=ai_generator">
            <?php echo study_icon('stars'); ?> AI Generator
        </a>
    </nav>

    <?php
        if($active_tab == 'questions'){
            include('tabs/questions.php');
        }

        if($active_tab == 'settings'){
            include('tabs/settings.php');
        }

        if($active_tab == 'results'){
            include('tabs/results.php');
        }

        if($active_tab == 'question_bank'){
            include('tabs/question_bank.php');
        }

        if($active_tab == 'ai_generator'){
            include('tabs/ai_generator.php');
        }
    ?>

</div>

</body>
</html>
