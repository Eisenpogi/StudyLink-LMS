<style>
.settings-card{
    background:#fff;
    border-radius:14px;
    padding:24px;
    box-shadow:0 2px 10px rgba(0,0,0,0.07);
    border-left:6px solid #4f46e5;
}

.settings-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:20px;
}

.autosave-status{
    font-size:13px;
    color:#666;
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
input[type="datetime-local"],
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
    min-height:100px;
    resize:vertical;
}

.grid-2{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:16px;
}
</style>

<div class="settings-card">

   <div class="settings-header">
        <div><h3>Quiz Settings</h3><p style="margin:6px 0 0;color:var(--quiz-muted);font-size:12px">Configure availability, scoring rules, and the student quiz experience.</p></div>
        <span class="quiz-save-state" style="background:var(--quiz-green-soft);color:var(--quiz-green)"><?php echo study_icon('cloud-check'); ?> Autosave active</span>
    </div>

    <input type="hidden" id="quiz_id" value="<?php echo $quiz_id; ?>">

    <div class="form-row">
        <label>Quiz Title</label>
        <input type="text" class="autosave-field" data-field="title"
               value="<?php echo htmlspecialchars($quiz['title']); ?>">
    </div>

    <div class="form-row">
        <label>Instructions</label>
        <textarea class="autosave-field" data-field="instructions"><?php echo htmlspecialchars($quiz['instructions']); ?></textarea>
    </div>

    <div class="grid-2">

        <div class="form-row">
        <label>Time Limit <small>(minutes; use 0 for no limit)</small></label>
            <input type="number" min="0" class="autosave-field" data-field="time_limit"
                   value="<?php echo intval($quiz['time_limit']); ?>">
        </div>
    </div>

    <div class="grid-2">
        <div class="form-row">
            <label>Max Attempts</label>
            <input type="number" min="1" class="autosave-field" data-field="max_attempts"
                   value="<?php echo intval($quiz['max_attempts']); ?>">
        </div>

        <div class="form-row">
            <label>Passing Score (%)</label>
            <input type="number" min="0" max="100" class="autosave-field" data-field="passing_score"
                   value="<?php echo intval($quiz['passing_score']); ?>">
        </div>
    </div>

    <div class="grid-2">
        <div class="form-row">
            <label>Available From <small>(calendar opening event)</small></label>
            <input type="datetime-local" class="autosave-field" data-field="available_from"
                   value="<?php echo ($quiz['available_from']) ? date('Y-m-d\TH:i', strtotime($quiz['available_from'])) : ''; ?>">
        </div>

        <div class="form-row">
            <label>Available Until <small>(calendar deadline)</small></label>
            <input type="datetime-local" class="autosave-field" data-field="available_until"
                   value="<?php echo ($quiz['available_until']) ? date('Y-m-d\TH:i', strtotime($quiz['available_until'])) : ''; ?>">
        </div>
    </div>

    <div class="form-row">
        <label>
            <input type="checkbox" class="autosave-checkbox" data-field="shuffle_questions"
                   <?php if($quiz['shuffle_questions'] == 1) echo 'checked'; ?>>
            Shuffle Questions
        </label>
    </div>

</div>

<script>
let autosaveTimer = null;

function saveQuizField(field, value){
    let status = document.getElementById('global_autosave_status');
    if(!status){
    return;
    }
    let quizId = document.getElementById('quiz_id').value;

    status.innerHTML = 'Saving...';

    let formData = new FormData();
    formData.append('quiz_id', quizId);
    formData.append('field', field);
    formData.append('value', value);
    formData.append('csrf_token', <?php echo json_encode(csrf_token()); ?>);

    fetch('autosave_quiz.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === 'saved'){
            status.textContent = 'Saved';
        } else {
            status.innerHTML = 'Save failed';
            console.log(data);
        }
    })
    .catch(error => {
        status.innerHTML = 'Save error';
        console.log(error);
    });
}

document.querySelectorAll('.autosave-field').forEach(function(field){
    field.addEventListener('input', function(){
        clearTimeout(autosaveTimer);

        let fieldName = this.getAttribute('data-field');
        let fieldValue = this.value;

        autosaveTimer = setTimeout(function(){
            saveQuizField(fieldName, fieldValue);
        }, 600);
    });

    field.addEventListener('change', function(){
        let fieldName = this.getAttribute('data-field');
        let fieldValue = this.value;

        saveQuizField(fieldName, fieldValue);
    });
});

document.querySelectorAll('.autosave-checkbox').forEach(function(field){
    field.addEventListener('change', function(){
        let fieldName = this.getAttribute('data-field');
        let fieldValue = this.checked ? 1 : 0;

        saveQuizField(fieldName, fieldValue);
    });
});
</script>
