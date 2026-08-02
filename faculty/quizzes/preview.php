<?php

include('../../auth/auth.php');
include('../../config/database.php');
include('../../includes/ui_icons.php');

require_role('faculty');

if(!isset($_GET['quiz_id'])){
    die("Quiz ID is missing.");
}

$quiz_id = intval($_GET['quiz_id']);
$faculty_user_id = intval($_SESSION['user_id']);

$quiz_query = mysqli_query($conn,"
    SELECT q.*, ca.id AS class_assignment_id, s.subject_name, sec.section_name
    FROM quizzes q
    INNER JOIN class_assignments ca ON q.class_assignment_id = ca.id
    INNER JOIN subjects s ON ca.subject_id = s.id
    INNER JOIN sections sec ON ca.section_id = sec.id
    INNER JOIN faculty f ON ca.faculty_id = f.id
    WHERE q.id='$quiz_id'
    AND f.user_id='$faculty_user_id'
");

$quiz = mysqli_fetch_assoc($quiz_query);

if(!$quiz){
    die("Quiz not found or unauthorized access.");
}

$questions = mysqli_query($conn,"
    SELECT *
    FROM quiz_questions
    WHERE quiz_id='$quiz_id'
    ORDER BY order_no ASC, id ASC
");

$total_questions = mysqli_num_rows($questions);
$points_query = mysqli_query($conn,"
    SELECT COALESCE(SUM(points), 0) AS total_points
    FROM quiz_questions
    WHERE quiz_id='$quiz_id'
");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = floatval($points_row['total_points']);
$total_points_label = rtrim(rtrim(number_format($total_points, 2, '.', ''), '0'), '.');
?>

<!DOCTYPE html>
<html>
<head>
    <title>Quiz Preview - StudyLink</title>

    <style>
        body{
            margin:0;
            font-family:Arial, sans-serif;
            background:#f5f6fa;
            color:#222;
        }

        .preview-wrapper{
            max-width:850px;
            margin:30px auto;
            padding:0 20px 40px;
        }

        .top-bar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:12px;
            margin-bottom:18px;
        }

        .btn{
            display:inline-block;
            padding:10px 15px;
            border-radius:9px;
            text-decoration:none;
            font-size:14px;
            border:none;
            cursor:pointer;
        }

        .btn-light{
            background:#eef2ff;
            color:#3730a3;
        }

        .preview-label{
            background:#f3f4f6;
            color:#555;
            padding:7px 11px;
            border-radius:20px;
            font-size:13px;
        }

        .preview-header,
        .question-card,
        .empty-state{
            background:#fff;
            border-radius:14px;
            padding:24px;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
            margin-bottom:18px;
        }

        .preview-header{
            border-top:8px solid #4f46e5;
        }

        .preview-header h1{
            margin:0;
            font-size:30px;
            line-height:1.2;
        }

        .meta{
            margin-top:10px;
            color:#666;
            font-size:14px;
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }

        .instructions{
            margin-top:20px;
            padding-top:18px;
            border-top:1px solid #eee;
            line-height:1.6;
        }

        .question-header{
            display:flex;
            justify-content:space-between;
            gap:15px;
            align-items:flex-start;
            margin-bottom:16px;
        }

        .question-title{
            font-weight:bold;
            font-size:17px;
            line-height:1.5;
        }

        .points{
            white-space:nowrap;
            color:#666;
            font-size:13px;
            background:#f3f4f6;
            padding:6px 9px;
            border-radius:20px;
        }

        .option{
            display:flex;
            align-items:center;
            gap:10px;
            padding:12px 14px;
            border:1px solid #ddd;
            border-radius:10px;
            margin-bottom:10px;
            background:#fafafa;
        }

        .answer-input,
        textarea{
            width:100%;
            padding:12px;
            border:1px solid #ddd;
            border-radius:10px;
            box-sizing:border-box;
            background:#fafafa;
            font-family:Arial, sans-serif;
            font-size:14px;
        }

        textarea{
            min-height:130px;
            resize:vertical;
        }

        .enumeration-list{
            display:flex;
            flex-direction:column;
            gap:10px;
        }

        .empty-state{
            text-align:center;
            color:#666;
            padding:45px 24px;
        }

        .empty-state h3{
            margin-top:0;
            color:#333;
        }

        .preview-note{
            margin-top:20px;
            text-align:center;
            color:#777;
            font-size:13px;
        }
    </style>
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
</head>
<body class="quiz-editor">

<div class="preview-wrapper quiz-editor-shell">

    <div class="top-bar">
        <a class="quiz-back"
           href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=questions">
            <?php echo study_icon('arrow-left'); ?> Back to Builder
        </a>

        <span class="quiz-save-state" style="background:var(--quiz-blue-soft);color:var(--quiz-navy)">
            <?php echo study_icon('eye'); ?> Faculty Preview
        </span>
    </div>

    <div class="preview-header quiz-editor-head">
        <div class="quiz-eyebrow">Student view simulation</div>
        <h1><?php echo htmlspecialchars($quiz['title']); ?></h1>

        <div class="meta">
            <span><?php echo study_icon('book'); ?> <?php echo htmlspecialchars($quiz['subject_name']); ?></span>
            <span><?php echo study_icon('people'); ?> <?php echo htmlspecialchars($quiz['section_name']); ?></span>
            <span><?php echo study_icon('list-ol'); ?> <?php echo $total_questions; ?> question(s)</span>
            <span><?php echo study_icon('award'); ?> <?php echo $total_points_label; ?> total point(s)</span>

            <?php if(intval($quiz['time_limit']) > 0){ ?>
                <span><?php echo study_icon('stopwatch'); ?> <?php echo intval($quiz['time_limit']); ?> minutes</span>
            <?php } ?>
        </div>

        <?php if(trim($quiz['instructions']) != ''){ ?>
            <div class="instructions">
                <?php echo nl2br(htmlspecialchars($quiz['instructions'])); ?>
            </div>
        <?php } ?>
    </div>

    <?php if($total_questions == 0){ ?>

        <div class="empty-state">
            <h3>No questions yet</h3>
            <p>Add questions in the Quiz Builder before previewing the quiz.</p>
        </div>

    <?php } else { ?>

        <?php
        $counter = 1;

        while($question = mysqli_fetch_assoc($questions)){
            $question_id = intval($question['id']);

            $choices = mysqli_query($conn,"
                SELECT *
                FROM quiz_choices
                WHERE question_id='$question_id'
                ORDER BY choice_order ASC, id ASC
            ");
        ?>

            <div class="question-card">

                <div class="question-header">
                    <div class="question-title">
                        <span class="question-number">Question <?php echo $counter; ?></span><br>
                        <?php echo htmlspecialchars($question['question_text']); ?>
                    </div>

                    <div class="points">
                        <?php echo intval($question['points']); ?> point(s)
                    </div>
                </div>

                <?php if($question['question_type'] == 'multiple_choice'){ ?>

                    <?php while($choice = mysqli_fetch_assoc($choices)){ ?>
                        <label class="option">
                           <input type="radio"
                            name="q_<?php echo $question_id; ?>">

                            <span><?php echo htmlspecialchars($choice['choice_text']); ?></span>
                        </label>
                    <?php } ?>

                <?php } elseif($question['question_type'] == 'true_false'){ ?>

                    <label class="option">
                        <input type="radio"
                               name="q_<?php echo $question_id; ?>">
                        <span>True</span>
                    </label>

                    <label class="option">
                        <input type="radio"
                               name="q_<?php echo $question_id; ?>">
                        <span>False</span>
                    </label>

                <?php } elseif($question['question_type'] == 'identification'){ ?>

                    <input class="answer-input"
                           type="text"
                           placeholder="Your answer">

                <?php } elseif($question['question_type'] == 'enumeration'){ ?>

                    <div class="enumeration-list">

                        <?php

                       $answers = array();

                        $stored_answers = trim($question['correct_answer']);

                        if($stored_answers != ''){
                            // Supports answers separated by |, comma, or new line.
                            $answers = preg_split('/\s*(?:\||,|\r\n|\r|\n)\s*/', $stored_answers);

                            $answers = array_values(array_filter($answers, function($answer){
                                return trim($answer) !== '';
                            }));
                        }

                        if(count($answers) == 0){
                            $answers[] = "";
                        }

                        foreach($answers as $index => $answer){
                        ?>

                            <input
                                class="answer-input"
                                type="text"
                                placeholder="Answer <?php echo $index + 1; ?>">

                        <?php } ?>

                    </div>

                <?php } elseif($question['question_type'] == 'essay'){ ?>

                    <textarea placeholder="Write your answer here"></textarea>

                <?php } ?>

            </div>

        <?php
            $counter++;
        }
        ?>

    <?php } ?>

    <div class="preview-note">
        Preview mode only. Answers cannot be submitted from this page.
    </div>

</div>

</body>
</html>
