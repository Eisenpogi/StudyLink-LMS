<?php
include('../../auth/auth.php');
include('../../config/database.php');
include('../../includes/ui_icons.php');

require_role('faculty');

$question_id = intval($_GET['id']);

$question_query = mysqli_query($conn,"
    SELECT qq.*, q.title AS quiz_title, q.class_assignment_id
    FROM quiz_questions qq
    INNER JOIN quizzes q ON qq.quiz_id = q.id
    INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
    INNER JOIN faculty f ON f.id = ca.faculty_id
    WHERE qq.id='$question_id'
    AND f.user_id='" . intval($_SESSION['user_id']) . "'
");

$question = mysqli_fetch_assoc($question_query);

if(!$question){
    die("Question not found.");
}

$quiz_id = intval($question['quiz_id']);

$choices = mysqli_query($conn,"
    SELECT *
    FROM quiz_choices
    WHERE question_id='$question_id'
    ORDER BY choice_order ASC, id ASC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Question - StudyLink</title>

    <style>
        body{
            margin:0;
            font-family: Arial, sans-serif;
            background:#f5f6fa;
            color:#222;
        }

        .builder-wrapper{
            max-width:900px;
            margin:30px auto;
            padding:0 20px;
        }

        .builder-header{
            background:#fff;
            border-radius:14px;
            padding:22px 26px;
            border-top:8px solid #4f46e5;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
            margin-bottom:20px;
        }

        .builder-header h2{
            margin:0;
            font-size:26px;
        }

        .builder-header p{
            margin:8px 0 0;
            color:#666;
        }

        .question-card{
            background:#fff;
            border-radius:14px;
            padding:24px;
            box-shadow:0 2px 10px rgba(0,0,0,0.08);
            border-left:6px solid #4f46e5;
        }

        .form-row{
            margin-bottom:18px;
        }

        label{
            display:block;
            font-weight:bold;
            margin-bottom:8px;
        }

        input[type="text"],
        input[type="number"],
        textarea,
        select{
            width:100%;
            padding:12px;
            border:1px solid #ddd;
            border-radius:10px;
            font-size:15px;
            box-sizing:border-box;
        }

        textarea{
            min-height:110px;
            resize:vertical;
        }

        .inline-grid{
            display:grid;
            grid-template-columns: 1fr 160px;
            gap:15px;
        }

        .choices-box{
            margin-top:10px;
            background:#fafafa;
            border:1px solid #eee;
            border-radius:12px;
            padding:15px;
        }

        .choice-row{
            display:flex;
            gap:10px;
            align-items:center;
            margin-bottom:10px;
        }

        .choice-row input[type="text"]{
            flex:1;
        }

        .choice-row button{
            border:none;
            background:#ef4444;
            color:white;
            padding:10px 13px;
            border-radius:8px;
            cursor:pointer;
        }

        .add-btn{
            border:none;
            background:#eef2ff;
            color:#3730a3;
            padding:10px 14px;
            border-radius:8px;
            cursor:pointer;
            font-weight:bold;
        }

        .action-bar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-top:24px;
        }

        .save-btn{
            background:#4f46e5;
            color:white;
            border:none;
            padding:12px 22px;
            border-radius:10px;
            font-size:15px;
            cursor:pointer;
            font-weight:bold;
        }

        .cancel-link{
            color:#555;
            text-decoration:none;
        }

        .answer-note{
            font-size:13px;
            color:#666;
            margin-top:6px;
        }
    </style>
    <link rel="stylesheet" href="/studyLink/assets/css/quiz-interface.css">
</head>
<body class="quiz-editor">

<div class="quiz-editor-shell">

    <div class="quiz-editor-head">
        <a class="quiz-back" href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=questions"><?php echo study_icon('arrow-left'); ?> Back to Quiz Builder</a>
        <div class="quiz-eyebrow">Question Editor</div>
        <h1>Edit Question</h1>
        <p><?php echo htmlspecialchars($question['quiz_title']); ?></p>
    </div>

    <form action="update_question.php" method="POST" class="quiz-editor-card">
        <?php echo csrf_field(); ?>

        <input type="hidden" name="question_id" value="<?php echo $question['id']; ?>">
        <input type="hidden" name="quiz_id" value="<?php echo $question['quiz_id']; ?>">

        <div class="inline-grid">
            <div class="form-row">
                <label>Question Type</label>
                <select name="question_type" id="question_type" onchange="toggleQuestionFields()">
                    <option value="multiple_choice" <?php if($question['question_type']=='multiple_choice') echo 'selected'; ?>>Multiple Choice</option>
                    <option value="true_false" <?php if($question['question_type']=='true_false') echo 'selected'; ?>>True / False</option>
                    <option value="identification" <?php if($question['question_type']=='identification') echo 'selected'; ?>>Identification</option>
                    <option value="enumeration" <?php if($question['question_type']=='enumeration') echo 'selected'; ?>>Enumeration</option>
                    <option value="essay" <?php if($question['question_type']=='essay') echo 'selected'; ?>>Essay</option>
                </select>
            </div>

            <div class="form-row">
                <label>Points</label>
                <input type="number" name="points" min="1" value="<?php echo htmlspecialchars($question['points']); ?>" required>
            </div>
        </div>

        <div class="form-row">
            <label>Question</label>
            <textarea name="question_text" required><?php echo htmlspecialchars($question['question_text']); ?></textarea>
        </div>

        <div class="form-row" id="choices_section">
            <label>Choices</label>

            <div class="choices-box" id="choices_container">
                <?php if(mysqli_num_rows($choices) > 0){ ?>
                    <?php while($choice = mysqli_fetch_assoc($choices)){ ?>
                        <div class="choice-row">
                            <input type="text" name="choices[]" value="<?php echo htmlspecialchars($choice['choice_text']); ?>">
                            <input type="radio" name="correct_choice" value="<?php echo htmlspecialchars($choice['choice_text']); ?>" <?php if($choice['is_correct'] == 1) echo 'checked'; ?>>
                                <button type="button" onclick="removeChoice(this)" aria-label="Delete choice"><?php echo study_icon('trash3'); ?></button>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <div class="choice-row">
                        <input type="text" name="choices[]" placeholder="Option 1">
                        <input type="radio" name="correct_choice" value="">
                        <button type="button" onclick="removeChoice(this)" aria-label="Delete choice"><?php echo study_icon('trash3'); ?></button>
                    </div>
                    <div class="choice-row">
                        <input type="text" name="choices[]" placeholder="Option 2">
                        <input type="radio" name="correct_choice" value="">
                        <button type="button" onclick="removeChoice(this)" aria-label="Delete choice"><?php echo study_icon('trash3'); ?></button>
                    </div>
                <?php } ?>
            </div>

            <button type="button" class="add-btn" onclick="addChoice()"><?php echo study_icon('plus-lg'); ?> Add Choice</button>
        </div>

        <div class="form-row" id="true_false_section">
            <label>Correct Answer</label>
            <select name="true_false_answer">
                <option value="True" <?php if($question['correct_answer']=='True') echo 'selected'; ?>>True</option>
                <option value="False" <?php if($question['correct_answer']=='False') echo 'selected'; ?>>False</option>
            </select>
        </div>

        <div class="form-row" id="answer_section">
            <label>Correct Answer</label>
            <input type="text" name="correct_answer" value="<?php echo htmlspecialchars($question['correct_answer']); ?>">
            <div class="answer-note">
                For enumeration, separate answers using comma. Example: HTML, CSS, JavaScript
            </div>
        </div>

        <div class="form-row" id="essay_section">
            <label>Guide Answer / Rubric</label>
            <textarea name="essay_answer"><?php echo htmlspecialchars($question['correct_answer']); ?></textarea>
            <div class="answer-note">
                Essay questions can use this as checking guide or rubric.
            </div>
        </div>

        <div class="action-bar">
           <?php if(isset($_GET['new']) && $_GET['new'] == 1){ ?>
                <button
                    type="submit"
                    class="cancel-link"
                    formaction="cancel_new_question.php"
                    formnovalidate
                    onclick="return confirm('Cancel and remove this new question?');"
                >
                    <?php echo study_icon('x-lg'); ?> Cancel
                </button>
            <?php } else { ?>
                <a class="cancel-link"
                href="quiz_builder.php?quiz_id=<?php echo $quiz_id; ?>&tab=questions">
                            <?php echo study_icon('x-lg'); ?> Cancel
                </a>
            <?php } ?>
            <button type="submit" class="save-btn"><?php echo study_icon('check2-circle'); ?> Save Changes</button>
        </div>

    </form>

</div>

<script>
function toggleQuestionFields(){
    let type = document.getElementById('question_type').value;

    document.getElementById('choices_section').style.display = 'none';
    document.getElementById('true_false_section').style.display = 'none';
    document.getElementById('answer_section').style.display = 'none';
    document.getElementById('essay_section').style.display = 'none';

    if(type === 'multiple_choice'){
        document.getElementById('choices_section').style.display = 'block';
    }

    if(type === 'true_false'){
        document.getElementById('true_false_section').style.display = 'block';
    }

    if(type === 'identification' || type === 'enumeration'){
        document.getElementById('answer_section').style.display = 'block';
    }

    if(type === 'essay'){
        document.getElementById('essay_section').style.display = 'block';
    }
}

function addChoice(){
    let container = document.getElementById('choices_container');

    let row = document.createElement('div');
    row.className = 'choice-row';

    row.innerHTML = `
        <input type="text" name="choices[]" placeholder="New option">
        <input type="radio" name="correct_choice" value="">
        <button type="button" onclick="removeChoice(this)" aria-label="Delete choice"><svg class="study-icon" aria-hidden="true"><use href="/studyLink/assets/icons/bootstrap-icons.svg#trash3"></use></svg></button>
    `;

    container.appendChild(row);
    syncRadioValues();
}

function removeChoice(button){
    button.parentElement.remove();
}

function syncRadioValues(){
    document.querySelectorAll('.choice-row').forEach(function(row){
        let textInput = row.querySelector('input[type="text"]');
        let radio = row.querySelector('input[type="radio"]');

        textInput.addEventListener('input', function(){
            radio.value = textInput.value;
        });

        radio.value = textInput.value;
    });
}

document.addEventListener('DOMContentLoaded', function(){
    toggleQuestionFields();
    syncRadioValues();
});
</script>

</body>
</html>
