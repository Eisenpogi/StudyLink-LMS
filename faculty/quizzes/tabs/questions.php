<?php

$questions = mysqli_query($conn,"
    SELECT *
    FROM quiz_questions
    WHERE quiz_id='$quiz_id'
    ORDER BY order_no ASC, id ASC
");

?>

<style>
    .top-actions{
        margin-bottom:18px;
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        justify-content:space-between;
        align-items:center;
    }

    .points-summary{
        background:#fff;
        color:#3730a3;
        border:1px solid #c7d2fe;
        border-radius:10px;
        padding:10px 14px;
        font-size:14px;
    }

    .points-summary strong{
        font-size:18px;
    }

    .btn-primary{
        background:#4f46e5;
        color:#fff;
    }

    .btn-danger{
        background:#fee2e2;
        color:#b91c1c;
    }

    .question-card{
        background:#fff;
        border-radius:14px;
        padding:20px 22px;
        margin-bottom:16px;
        box-shadow:0 2px 10px rgba(0,0,0,0.07);
        border-left:6px solid #4f46e5;
    }

    .question-card.sortable-ghost{
        opacity:0.4;
    }

    .question-top{
        display:flex;
        justify-content:space-between;
        gap:15px;
        align-items:flex-start;
    }

    .question-number{
        font-size:13px;
        color:#666;
        margin-bottom:8px;
    }

    .question-text{
        font-size:18px;
        font-weight:bold;
        margin-bottom:12px;
    }

    .question-details{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
        margin-bottom:12px;
    }

    .badge{
        background:#f3f4f6;
        color:#444;
        padding:6px 10px;
        border-radius:20px;
        font-size:12px;
    }

    .choice-list{
        margin:12px 0 0;
        padding:0;
        list-style:none;
    }

    .choice-list li{
        padding:9px 12px;
        background:#fafafa;
        border:1px solid #eee;
        border-radius:8px;
        margin-bottom:7px;
        font-size:14px;
    }

    .correct{
        border-color:#86efac !important;
        background:#f0fdf4 !important;
    }

    .card-actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:15px;
        padding-top:15px;
        border-top:1px solid #eee;
    }

    .empty-state{
        background:#fff;
        border-radius:14px;
        padding:40px 25px;
        text-align:center;
        color:#666;
        box-shadow:0 2px 10px rgba(0,0,0,0.07);
    }

    .drag-placeholder{
        font-size:22px;
        color:#999;
        cursor:grab;
        user-select:none;
        padding:6px 10px;
        border-radius:8px;
    }

    .drag-placeholder:hover{
        background:#f3f4f6;
        color:#4f46e5;
    }

    .save-status{
        margin-bottom:14px;
        font-size:13px;
        color:#666;
    }
</style>

<div class="top-actions">
    <form action="add_question.php" method="POST" style="display:inline;">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
    <input type="hidden" name="question_type" value="multiple_choice">
    <input type="hidden" name="question_text" value="Untitled Question">
    <input type="hidden" name="points" value="1">

    <button type="submit" class="btn btn-primary">
        <?php echo study_icon('plus-lg'); ?> Add Question
    </button>
    </form>

    <div class="points-summary">
        Total Score: <strong id="question_total_points"><?php echo $total_points_label; ?></strong>
        <?php echo ($total_points == 1.0) ? 'point' : 'points'; ?>
    </div>
</div>

<div class="save-status" id="save_status">
    <?php echo study_icon('grip-vertical'); ?> Drag questions by the handle to reorder them. Changes save automatically.
</div>

<?php if(mysqli_num_rows($questions) > 0){ ?>

    <div id="question_list">

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

            <div
                class="question-card"
                data-question-id="<?php echo $question_id; ?>"
                data-points="<?php echo floatval($question['points']); ?>"
            >

                <div class="question-top">
                    <div>
                        <div class="question-number">
                            Question <?php echo $counter; ?>
                        </div>

                        <div class="question-text">
                            <?php echo htmlspecialchars($question['question_text']); ?>
                        </div>
                    </div>

                    <div class="drag-placeholder" title="Drag to reorder"><?php echo study_icon('grip-vertical'); ?></div>
                </div>

                <div class="question-details">
                    <span class="badge">
                        <?php echo ucwords(str_replace('_', ' ', $question['question_type'])); ?>
                    </span>

                    <span class="badge">
                        <?php echo intval($question['points']); ?> pts
                    </span>
                </div>

                <?php if($question['question_type'] == 'multiple_choice'){ ?>

                    <ul class="choice-list">
                        <?php while($choice = mysqli_fetch_assoc($choices)){ ?>
                            <li class="<?php echo ($choice['is_correct'] == 1) ? 'correct' : ''; ?>">
                                <?php echo htmlspecialchars($choice['choice_text']); ?>

                                <?php if($choice['is_correct'] == 1){ ?>
                                    <strong><?php echo study_icon('check-circle-fill'); ?></strong>
                                <?php } ?>
                            </li>
                        <?php } ?>
                    </ul>

                <?php } else if($question['question_type'] != 'essay'){ ?>

                    <ul class="choice-list">
                        <li class="correct">
                            Answer: <?php echo htmlspecialchars($question['correct_answer']); ?>
                        </li>
                    </ul>

                <?php } else { ?>

                    <ul class="choice-list">
                        <li>
                            Essay / Rubric:
                            <?php echo htmlspecialchars($question['correct_answer']); ?>
                        </li>
                    </ul>

                <?php } ?>

                <div class="card-actions">
                    <a class="btn btn-light" href="edit_question.php?id=<?php echo $question_id; ?>">
                        <?php echo study_icon('pencil'); ?> Edit
                    </a>

                    <form action="duplicate_question.php" method="post" style="display:inline;">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="question_id" value="<?php echo $question_id; ?>">
                        <button type="submit" class="btn btn-light"><?php echo study_icon('copy'); ?> Duplicate</button>
                    </form>
                    <a class="btn btn-light"
                        href="save_to_question_bank.php?id=<?php echo $question_id; ?>">
                            <?php echo study_icon('archive'); ?> Save to Bank
                        </a>

                    <form action="delete_question.php" method="post" style="display:inline;" onsubmit="return confirm('Delete this question?');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="question_id" value="<?php echo $question_id; ?>">
                        <button type="submit" class="btn btn-danger"><?php echo study_icon('trash3'); ?> Delete</button>
                    </form>
                </div>

            </div>

        <?php
            $counter++;
        }
        ?>

    </div>

<?php } else { ?>

    <div class="empty-state">
        <h3>No questions yet</h3>
        <p>Start building your quiz by adding your first question.</p>
        <br>
        <form action="add_question.php" method="POST" style="display:inline;">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="quiz_id" value="<?php echo $quiz_id; ?>">
        <input type="hidden" name="question_type" value="multiple_choice">
        <input type="hidden" name="question_text" value="Untitled Question">
        <input type="hidden" name="points" value="1">

        <button type="submit" class="btn btn-primary">
            + Add Question
        </button>
    </form>
    </div>

<?php } ?>

<script>
document.addEventListener('DOMContentLoaded', function(){

    let questionList = document.getElementById('question_list');
    let saveStatus = document.getElementById('save_status');
    let totalPoints = Array.from(document.querySelectorAll('.question-card')).reduce(function(total, card){
        return total + (parseFloat(card.getAttribute('data-points')) || 0);
    }, 0);
    let totalLabel = Number.isInteger(totalPoints) ? totalPoints.toString() : totalPoints.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
    let summaryTotal = document.getElementById('question_total_points');
    let headerTotal = document.getElementById('quiz_total_points');

    if(summaryTotal){
        summaryTotal.textContent = totalLabel;
    }
    if(headerTotal){
        headerTotal.textContent = totalLabel;
    }

    if(!questionList){
        return;
    }

    let draggedCard = null;

    function saveQuestionOrder(){
        let order = [];

        questionList.querySelectorAll('.question-card').forEach(function(card, index){
            order.push(card.getAttribute('data-question-id'));

            let numberLabel = card.querySelector('.question-number');
            if(numberLabel){
                numberLabel.textContent = 'Question ' + (index + 1);
            }
        });

        let formData = new FormData();
        formData.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);
        formData.append('quiz_id', <?php echo intval($quiz_id); ?>);
        order.forEach(function(id){
            formData.append('order[]', id);
        });

        saveStatus.textContent = 'Saving order...';

        fetch('update_question_order.php', {
            method: 'POST',
            body: formData
        })
        .then(function(response){
            return response.text();
        })
        .then(function(data){
            saveStatus.textContent = data.trim() === 'success'
                ? 'Question order saved.'
                : 'Order update failed: ' + data;
        })
        .catch(function(error){
            saveStatus.textContent = 'Order update error.';
            console.log(error);
        });
    }

    questionList.querySelectorAll('.question-card').forEach(function(card){
        let handle = card.querySelector('.drag-placeholder');
        if(!handle){
            return;
        }

        handle.addEventListener('mousedown', function(){
            card.setAttribute('draggable', 'true');
        });

        card.addEventListener('dragstart', function(event){
            if(card.getAttribute('draggable') !== 'true'){
                event.preventDefault();
                return;
            }
            draggedCard = card;
            card.classList.add('sortable-ghost');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.getAttribute('data-question-id'));
        });

        card.addEventListener('dragend', function(){
            card.classList.remove('sortable-ghost');
            card.removeAttribute('draggable');
            if(draggedCard){
                saveQuestionOrder();
            }
            draggedCard = null;
        });
    });

    questionList.addEventListener('dragover', function(event){
        if(!draggedCard){
            return;
        }
        event.preventDefault();

        let target = event.target.closest('.question-card');
        if(!target || target === draggedCard){
            return;
        }

        let box = target.getBoundingClientRect();
        let insertAfter = event.clientY > box.top + (box.height / 2);
        questionList.insertBefore(draggedCard, insertAfter ? target.nextSibling : target);
    });

});
</script>
