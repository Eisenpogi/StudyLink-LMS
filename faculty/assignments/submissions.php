<?php

include '../../auth/auth.php';
require_role('faculty');
include '../../config/database.php';
include '../../includes/faculty_ui.php';
include '../../includes/academic_term.php';

$user_id = intval($_SESSION['user_id']);
$maximum_score = 100;
$assignment_id = isset($_GET['id']) ? intval($_GET['id']) : intval($_POST['assignment_id'] ?? 0);
$selected_submission_id = isset($_GET['submission_id'])
    ? intval($_GET['submission_id'])
    : intval($_POST['submission_id'] ?? 0);

$assignment_statement = mysqli_prepare($conn, '
    SELECT a.id, a.title, a.instructions, a.due_date, a.class_assignment_id,
           sub.subject_code, sub.subject_name, sec.section_name,
           f.id AS faculty_id, f.faculty_id AS faculty_code
    FROM assignments a
    INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
    INNER JOIN faculty f ON f.id = ca.faculty_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    WHERE a.id = ? AND f.user_id = ?
    LIMIT 1
');
mysqli_stmt_bind_param($assignment_statement, 'ii', $assignment_id, $user_id);
mysqli_stmt_execute($assignment_statement);
$assignment = mysqli_fetch_assoc(mysqli_stmt_get_result($assignment_statement));
mysqli_stmt_close($assignment_statement);

if (!$assignment) {
    http_response_code(403);
    exit('Assignment not found or you are not authorized to view its submissions.');
}

$term_writable = studylink_class_is_writable(
    $conn,
    intval($assignment['class_assignment_id'])
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf('/studyLink/faculty/assignments/submissions.php?id=' . $assignment_id);
    if (!studylink_class_is_writable($conn, intval($assignment['class_assignment_id']))) {
        redirect_with_flash(
            '/studyLink/faculty/assignments/submissions.php?id=' . $assignment_id .
            '&submission_id=' . $selected_submission_id,
            'error',
            'This Academic Term is read-only. Recorded grades and feedback can no longer be changed.'
        );
    }

    $intent = $_POST['intent'] ?? 'save';
    $score_input = trim((string) ($_POST['score'] ?? ''));
    $remarks = trim((string) ($_POST['remarks'] ?? ''));

    if ($selected_submission_id <= 0) {
        $error = 'Select a student submission before saving a grade.';
    } elseif ($score_input === '' || !is_numeric($score_input)) {
        $error = 'Enter a valid numeric score.';
    } elseif (floatval($score_input) < 0 || floatval($score_input) > $maximum_score) {
        $error = 'Score must be between 0 and ' . $maximum_score . '.';
    } elseif (strlen($remarks) > 2000) {
        $error = 'Feedback must not exceed 2,000 characters.';
    } else {
        $score = floatval($score_input);
        $grade_statement = mysqli_prepare($conn, '
            UPDATE assignment_submissions asm
            INNER JOIN assignments a ON a.id = asm.assignment_id
            INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
            INNER JOIN faculty f ON f.id = ca.faculty_id
            SET asm.score = ?, asm.remarks = ?, asm.graded_at = NOW(),
                asm.graded_by = ?
            WHERE asm.id = ? AND asm.assignment_id = ? AND f.user_id = ?
        ');
        mysqli_stmt_bind_param(
            $grade_statement,
            'dsiiii',
            $score,
            $remarks,
            $assignment['faculty_id'],
            $selected_submission_id,
            $assignment_id,
            $user_id
        );
        mysqli_stmt_execute($grade_statement);

        if (mysqli_stmt_affected_rows($grade_statement) < 1) {
            $error = 'The grade was not saved. Check the submission and try again.';
        } else {
            if (faculty_notifications_ready($conn)) {
                $event_key = 'faculty:assignment-submission:' . $selected_submission_id;
                $notification_statement = mysqli_prepare($conn, '
                    UPDATE notifications
                    SET is_read = 1, read_at = COALESCE(read_at, NOW())
                    WHERE user_id = ? AND event_key = ?
                ');
                mysqli_stmt_bind_param($notification_statement, 'is', $user_id, $event_key);
                mysqli_stmt_execute($notification_statement);
                mysqli_stmt_close($notification_statement);
            }

            if ($intent === 'save_next') {
                $next_statement = mysqli_prepare($conn, '
                    SELECT id
                    FROM assignment_submissions
                    WHERE assignment_id = ? AND score IS NULL AND id <> ?
                    ORDER BY submitted_at ASC
                    LIMIT 1
                ');
                mysqli_stmt_bind_param($next_statement, 'ii', $assignment_id, $selected_submission_id);
                mysqli_stmt_execute($next_statement);
                $next_submission = mysqli_fetch_assoc(mysqli_stmt_get_result($next_statement));
                mysqli_stmt_close($next_statement);

                if ($next_submission) {
                    redirect_to(
                        '/studyLink/faculty/assignments/submissions.php?id=' . $assignment_id .
                        '&submission_id=' . intval($next_submission['id']) .
                        '&saved=1'
                    );
                }
            }

            redirect_to(
                '/studyLink/faculty/assignments/submissions.php?id=' . $assignment_id .
                '&submission_id=' . $selected_submission_id .
                '&saved=1'
            );
        }
        mysqli_stmt_close($grade_statement);
    }
}

$submissions = [];
$submission_statement = mysqli_prepare($conn, '
    SELECT asm.id, asm.file_name, asm.file_size, asm.mime_type, asm.student_comment,
           asm.submitted_at, asm.submission_status, asm.score,
           asm.remarks, asm.graded_at,
           s.student_no, u.fullname
    FROM assignment_submissions asm
    INNER JOIN students s ON s.id = asm.student_id
    INNER JOIN users u ON u.id = s.user_id
    WHERE asm.assignment_id = ?
    ORDER BY (asm.score IS NOT NULL) ASC, asm.submitted_at ASC
');
mysqli_stmt_bind_param($submission_statement, 'i', $assignment_id);
mysqli_stmt_execute($submission_statement);
$result = mysqli_stmt_get_result($submission_statement);
while ($row = mysqli_fetch_assoc($result)) {
    $submissions[] = $row;
}
mysqli_stmt_close($submission_statement);

$selected_submission = null;
$selected_index = -1;
foreach ($submissions as $index => $submission) {
    if ($selected_submission_id > 0 && intval($submission['id']) === $selected_submission_id) {
        $selected_submission = $submission;
        $selected_index = $index;
        break;
    }
}

if (!$selected_submission && $submissions) {
    $selected_submission = $submissions[0];
    $selected_submission_id = intval($selected_submission['id']);
    $selected_index = 0;
}

$pending_count = 0;
$graded_count = 0;
foreach ($submissions as $submission) {
    if ($submission['score'] === null) {
        $pending_count++;
    } else {
        $graded_count++;
    }
}

$previous_submission_id = $selected_index > 0 ? intval($submissions[$selected_index - 1]['id']) : 0;
$next_submission_id = $selected_index >= 0 && $selected_index < count($submissions) - 1
    ? intval($submissions[$selected_index + 1]['id'])
    : 0;
$is_selected_graded = $selected_submission && $selected_submission['score'] !== null;
$is_editing_grade = !$is_selected_graded || isset($_GET['edit']) || !empty($error);

function faculty_submission_file_size($bytes)
{
    $bytes = intval($bytes);
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }
    return number_format($bytes / 1024, 2) . ' KB';
}

function faculty_submission_initials($name)
{
    $initials = '';
    foreach (preg_split('/\s+/', trim((string) $name)) as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($initials) === 2) {
            break;
        }
    }
    return $initials !== '' ? $initials : 'ST';
}

function faculty_submission_file_label($mime_type)
{
    $mime_type = strtolower((string) $mime_type);
    if (strpos($mime_type, 'pdf') !== false) return 'PDF document';
    if (strpos($mime_type, 'word') !== false || strpos($mime_type, 'document') !== false) return 'Word document';
    if (strpos($mime_type, 'presentation') !== false || strpos($mime_type, 'powerpoint') !== false) return 'Presentation';
    if (strpos($mime_type, 'image') === 0) return 'Image';
    return 'Submitted file';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grade Assignment - StudyLink</title>
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('grading', $assignment['faculty_code']); ?>
<?php render_faculty_topbar('Grade Assignment', $assignment['subject_code'] . ' · ' . $assignment['section_name']); ?>

<main class="faculty-main">
    <div class="faculty-shell faculty-grading-shell">
        <nav class="faculty-grading-breadcrumb" aria-label="Breadcrumb">
            <a href="/studyLink/faculty/grading_queue.php"><?php echo study_icon('arrow-left'); ?> Grading Queue</a>
            <span>/</span>
            <span><?php echo e($assignment['title']); ?></span>
        </nav>

        <section class="faculty-card faculty-grading-header">
            <div>
                <div class="faculty-eyebrow">Assignment Review</div>
                <h1><?php echo e($assignment['title']); ?></h1>
                <p><?php echo e($assignment['subject_code']); ?> · <?php echo e($assignment['subject_name']); ?> · <?php echo e($assignment['section_name']); ?></p>
            </div>
            <dl>
                <div><dt>Due date</dt><dd><?php echo date('M j, Y · g:i A', strtotime($assignment['due_date'])); ?></dd></div>
                <div><dt>Submitted</dt><dd><?php echo count($submissions); ?></dd></div>
                <div><dt>Pending</dt><dd><?php echo $pending_count; ?></dd></div>
            </dl>
        </section>

        <?php if (isset($_GET['saved'])): ?>
            <div class="faculty-notice faculty-grading-notice" role="status">
                <?php echo study_icon('check2-circle'); ?>
                <span>Grade and feedback saved.</span>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="faculty-notice error"><?php echo study_icon('exclamation-circle'); ?> <?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if (!$submissions): ?>
            <section class="faculty-card faculty-grading-empty">
                <?php echo study_icon('inbox'); ?>
                <h2>No submissions yet</h2>
                <p>Student submissions for this assignment will appear here.</p>
                <a class="faculty-button light" href="/studyLink/faculty/class_view.php?id=<?php echo intval($assignment['class_assignment_id']); ?>&tab=assignments">Back to Assignments</a>
            </section>
        <?php else: ?>
            <section class="faculty-grading-workspace">
                <aside class="faculty-card faculty-grading-roster">
                    <header>
                        <div>
                            <h2>Students</h2>
                            <p><?php echo $pending_count; ?> pending · <?php echo $graded_count; ?> graded</p>
                        </div>
                    </header>
                    <label class="faculty-grading-search">
                        <?php echo study_icon('search'); ?>
                        <input id="submissionSearch" type="search" placeholder="Search student or ID">
                    </label>
                    <nav id="submissionRoster" aria-label="Student submissions">
                        <?php foreach ($submissions as $submission): ?>
                            <?php
                            $is_selected = intval($submission['id']) === $selected_submission_id;
                            $is_graded = $submission['score'] !== null;
                            ?>
                            <a
                                class="<?php echo $is_selected ? 'active' : ''; ?>"
                                href="?id=<?php echo $assignment_id; ?>&submission_id=<?php echo intval($submission['id']); ?>"
                                data-student-search="<?php echo e(strtolower($submission['fullname'] . ' ' . $submission['student_no'])); ?>"
                            >
                                <span class="faculty-grading-avatar"><?php echo e(faculty_submission_initials($submission['fullname'])); ?></span>
                                <span class="faculty-grading-student-copy">
                                    <strong><?php echo e($submission['fullname']); ?></strong>
                                    <small><?php echo e($submission['student_no']); ?></small>
                                </span>
                                <span class="faculty-grading-state <?php echo $is_graded ? 'graded' : 'pending'; ?>" title="<?php echo $is_graded ? 'Graded' : 'Pending'; ?>"></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <p id="submissionNoResults" class="faculty-grading-roster-empty" hidden>No matching student.</p>
                </aside>

                <section class="faculty-card faculty-grading-review">
                    <header class="faculty-grading-student-head">
                        <div class="faculty-grading-student">
                            <span class="faculty-grading-avatar large"><?php echo e(faculty_submission_initials($selected_submission['fullname'])); ?></span>
                            <div>
                                <h2><?php echo e($selected_submission['fullname']); ?></h2>
                                <p><?php echo e($selected_submission['student_no']); ?></p>
                            </div>
                        </div>
                        <?php if (count($submissions) > 1): ?>
                            <div class="faculty-grading-nav" aria-label="Submission navigation">
                                <?php if ($previous_submission_id): ?>
                                    <a href="?id=<?php echo $assignment_id; ?>&submission_id=<?php echo $previous_submission_id; ?>" aria-label="Previous student"><?php echo study_icon('chevron-left'); ?></a>
                                <?php else: ?>
                                    <span aria-hidden="true"><?php echo study_icon('chevron-left'); ?></span>
                                <?php endif; ?>
                                <small><?php echo $selected_index + 1; ?> of <?php echo count($submissions); ?></small>
                                <?php if ($next_submission_id): ?>
                                    <a href="?id=<?php echo $assignment_id; ?>&submission_id=<?php echo $next_submission_id; ?>" aria-label="Next student"><?php echo study_icon('chevron-right'); ?></a>
                                <?php else: ?>
                                    <span aria-hidden="true"><?php echo study_icon('chevron-right'); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </header>

                    <section class="faculty-grading-meta">
                        <div>
                            <span>Submitted</span>
                            <strong><?php echo date('M j, Y · g:i A', strtotime($selected_submission['submitted_at'])); ?></strong>
                        </div>
                        <div>
                            <span>Submission status</span>
                            <strong class="<?php echo $selected_submission['submission_status'] === 'late' ? 'late' : ''; ?>">
                                <?php echo $selected_submission['submission_status'] === 'late' ? 'Submitted late' : 'On time'; ?>
                            </strong>
                        </div>
                        <div>
                            <span>Grading status</span>
                            <strong><?php echo $selected_submission['score'] === null ? 'Not graded' : 'Graded'; ?></strong>
                        </div>
                    </section>

                    <section class="faculty-grading-file">
                        <span class="faculty-grading-file-icon"><?php echo study_icon('file-earmark-text'); ?></span>
                        <div>
                            <strong><?php echo e($selected_submission['file_name']); ?></strong>
                            <small><?php echo e(faculty_submission_file_label($selected_submission['mime_type'])); ?> · <?php echo e(faculty_submission_file_size($selected_submission['file_size'])); ?></small>
                        </div>
                        <a class="faculty-button small light" href="download_submission.php?id=<?php echo $selected_submission_id; ?>">
                            <?php echo study_icon('download'); ?> Download file
                        </a>
                    </section>

                    <section class="faculty-grading-comment">
                        <h3>Student comment</h3>
                        <p><?php echo trim((string) $selected_submission['student_comment']) !== ''
                            ? nl2br(e($selected_submission['student_comment']))
                            : 'No comment was included with this submission.'; ?></p>
                    </section>

                    <?php if (!$term_writable): ?>
                        <div class="faculty-notice">
                            <?php echo study_icon('lock'); ?>
                            This Academic Term is read-only. The recorded submission, score, and feedback are preserved and cannot be changed.
                        </div>
                        <?php if ($is_selected_graded): ?>
                            <section class="faculty-grading-saved" aria-label="Recorded grade and feedback">
                                <div class="faculty-grading-saved-content">
                                    <div class="faculty-grading-saved-score">
                                        <span>Final score</span>
                                        <strong><?php echo e($selected_submission['score']); ?><small>/<?php echo $maximum_score; ?></small></strong>
                                    </div>
                                    <div class="faculty-grading-saved-feedback">
                                        <span>Faculty feedback</span>
                                        <p><?php echo trim((string) $selected_submission['remarks']) !== ''
                                            ? nl2br(e($selected_submission['remarks']))
                                            : 'No feedback was added.'; ?></p>
                                    </div>
                                </div>
                            </section>
                        <?php endif; ?>
                    <?php elseif ($is_selected_graded && !$is_editing_grade): ?>
                        <section class="faculty-grading-saved" aria-labelledby="savedGradeTitle">
                            <div class="faculty-grading-saved-head">
                                <span class="faculty-grading-saved-icon"><?php echo study_icon('check2-circle'); ?></span>
                                <div>
                                    <h3 id="savedGradeTitle">Grade and feedback</h3>
                                    <p>Last updated <?php echo date('M j, Y · g:i A', strtotime($selected_submission['graded_at'])); ?></p>
                                </div>
                            </div>
                            <div class="faculty-grading-saved-content">
                                <div class="faculty-grading-saved-score">
                                    <span>Final score</span>
                                    <strong><?php echo e($selected_submission['score']); ?><small>/<?php echo $maximum_score; ?></small></strong>
                                </div>
                                <div class="faculty-grading-saved-feedback">
                                    <span>Faculty feedback</span>
                                    <p><?php echo trim((string) $selected_submission['remarks']) !== ''
                                        ? nl2br(e($selected_submission['remarks']))
                                        : 'No feedback was added.'; ?></p>
                                </div>
                            </div>
                            <footer class="faculty-grading-saved-actions">
                                <a href="/studyLink/faculty/grading_queue.php">Return to queue</a>
                                <a class="faculty-button small" href="?id=<?php echo $assignment_id; ?>&submission_id=<?php echo $selected_submission_id; ?>&edit=1">
                                    <?php echo study_icon('pencil'); ?> Edit grade &amp; feedback
                                </a>
                            </footer>
                        </section>
                    <?php else: ?>
                    <form class="faculty-grading-form" method="post">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                        <input type="hidden" name="submission_id" value="<?php echo $selected_submission_id; ?>">

                        <div class="faculty-grading-form-head">
                            <div>
                                <h3><?php echo $is_selected_graded ? 'Edit grade and feedback' : 'Grade and feedback'; ?></h3>
                                <p><?php echo $is_selected_graded
                                    ? 'Update the existing score or feedback, then save your changes.'
                                    : 'Review the requirements, score each criterion, then apply the suggested total.'; ?></p>
                            </div>
                            <?php if ($selected_submission['graded_at']): ?>
                                <small>Last saved <?php echo date('M j, Y · g:i A', strtotime($selected_submission['graded_at'])); ?></small>
                            <?php endif; ?>
                        </div>

                        <section class="faculty-grading-basis" aria-labelledby="gradingBasisTitle">
                            <div class="faculty-grading-reference">
                                <div class="faculty-grading-reference-head">
                                    <div>
                                        <span class="faculty-grading-reference-icon"><?php echo study_icon('clipboard-check'); ?></span>
                                        <div>
                                            <h4 id="gradingBasisTitle">Grading reference</h4>
                                            <p>Use the assignment instructions and the submitted output as your primary basis.</p>
                                        </div>
                                    </div>
                                    <span class="faculty-score-total"><?php echo $maximum_score; ?> points</span>
                                </div>
                                <div class="faculty-grading-instructions">
                                    <?php echo trim((string) $assignment['instructions']) !== ''
                                        ? nl2br(e($assignment['instructions']))
                                        : 'No additional instructions were provided for this assignment.'; ?>
                                </div>
                            </div>

                            <div class="faculty-rubric">
                                <div class="faculty-rubric-head">
                                    <div>
                                        <h4>Quick scoring guide</h4>
                                        <p>Score all four criteria to keep grading consistent across students.</p>
                                    </div>
                                    <strong id="rubricTotal">0 / <?php echo $maximum_score; ?></strong>
                                </div>

                                <div class="faculty-rubric-grid">
                                    <label>
                                        <span><strong>Content &amp; accuracy</strong><small>Correct, relevant, and well-supported work</small></span>
                                        <span class="faculty-rubric-input"><input class="rubric-score" type="number" min="0" max="40" step="0.5" inputmode="decimal" aria-label="Content and accuracy score out of 40"><em>/ 40</em></span>
                                    </label>
                                    <label>
                                        <span><strong>Completeness</strong><small>All required parts and questions are addressed</small></span>
                                        <span class="faculty-rubric-input"><input class="rubric-score" type="number" min="0" max="30" step="0.5" inputmode="decimal" aria-label="Completeness score out of 30"><em>/ 30</em></span>
                                    </label>
                                    <label>
                                        <span><strong>Organization &amp; clarity</strong><small>Ideas are understandable and properly arranged</small></span>
                                        <span class="faculty-rubric-input"><input class="rubric-score" type="number" min="0" max="20" step="0.5" inputmode="decimal" aria-label="Organization and clarity score out of 20"><em>/ 20</em></span>
                                    </label>
                                    <label>
                                        <span><strong>Requirements &amp; compliance</strong><small>Format, file, and submission requirements are followed</small></span>
                                        <span class="faculty-rubric-input"><input class="rubric-score" type="number" min="0" max="10" step="0.5" inputmode="decimal" aria-label="Requirements and compliance score out of 10"><em>/ 10</em></span>
                                    </label>
                                </div>

                                <div class="faculty-rubric-footer">
                                    <small>This guide suggests a score only. Review the complete work before saving the final grade.</small>
                                    <button id="applyRubricScore" class="faculty-button small light" type="button" disabled>Use suggested score</button>
                                </div>
                            </div>
                        </section>

                        <div class="faculty-grading-fields">
                            <label class="score">
                                <span>Final score <small>Required</small></span>
                                <span class="faculty-final-score-input">
                                    <input
                                        id="finalScore"
                                        type="number"
                                        name="score"
                                        min="0"
                                        max="<?php echo $maximum_score; ?>"
                                        step="0.5"
                                        value="<?php echo isset($error)
                                            ? e($_POST['score'] ?? '')
                                            : ($selected_submission['score'] === null ? '' : e($selected_submission['score'])); ?>"
                                        placeholder="0"
                                        required
                                    >
                                    <em>/ <?php echo $maximum_score; ?></em>
                                </span>
                                <small id="scoreBand" class="faculty-score-band">Enter or apply a score from the guide.</small>
                            </label>
                            <label class="feedback">
                                <span>Feedback <small>Optional</small></span>
                                <textarea id="gradingFeedback" name="remarks" maxlength="2000" placeholder="Give specific, helpful feedback about the submitted work."><?php
                                    echo isset($error)
                                        ? e($_POST['remarks'] ?? '')
                                        : e($selected_submission['remarks'] ?? '');
                                ?></textarea>
                                <small><span id="feedbackCount">0</span>/2000 characters</small>
                            </label>
                        </div>

                        <footer class="faculty-grading-actions">
                            <a href="<?php echo $is_selected_graded
                                ? '?id=' . $assignment_id . '&submission_id=' . $selected_submission_id
                                : '/studyLink/faculty/grading_queue.php'; ?>">
                                <?php echo $is_selected_graded ? 'Cancel editing' : 'Return to queue'; ?>
                            </a>
                            <div>
                                <?php if ($is_selected_graded): ?>
                                    <button class="faculty-button small" type="submit" name="intent" value="save">
                                        <?php echo study_icon('check2-circle'); ?> Save changes
                                    </button>
                                <?php else: ?>
                                    <button class="faculty-button small light" type="submit" name="intent" value="save">
                                        <?php echo study_icon('check2-circle'); ?> Save grade
                                    </button>
                                    <?php if ($pending_count > 1): ?>
                                        <button class="faculty-button small" type="submit" name="intent" value="save_next">
                                            Save &amp; next <?php echo study_icon('arrow-right'); ?>
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </footer>
                    </form>
                    <?php endif; ?>
                </section>
            </section>
        <?php endif; ?>
    </div>
</main>

<script>
(function () {
    var search = document.getElementById('submissionSearch');
    var roster = document.getElementById('submissionRoster');
    var noResults = document.getElementById('submissionNoResults');
    var feedback = document.getElementById('gradingFeedback');
    var feedbackCount = document.getElementById('feedbackCount');
    var rubricScores = Array.prototype.slice.call(document.querySelectorAll('.rubric-score'));
    var rubricTotal = document.getElementById('rubricTotal');
    var applyRubricScore = document.getElementById('applyRubricScore');
    var finalScore = document.getElementById('finalScore');
    var scoreBand = document.getElementById('scoreBand');

    if (search && roster && noResults) {
        search.addEventListener('input', function () {
            var query = search.value.toLowerCase().trim();
            var visible = 0;
            roster.querySelectorAll('[data-student-search]').forEach(function (item) {
                item.hidden = query !== '' && item.dataset.studentSearch.indexOf(query) === -1;
                if (!item.hidden) visible++;
            });
            noResults.hidden = visible !== 0;
        });
    }

    function updateFeedbackCount() {
        if (feedback && feedbackCount) feedbackCount.textContent = feedback.value.length;
    }
    if (feedback) {
        feedback.addEventListener('input', updateFeedbackCount);
        updateFeedbackCount();
    }

    function scoreLabel(score) {
        if (score === null || Number.isNaN(score)) return 'Enter or apply a score from the guide.';
        if (score >= 90) return 'Outstanding — requirements were met at a high level.';
        if (score >= 80) return 'Proficient — strong work with minor improvements needed.';
        if (score >= 75) return 'Satisfactory — minimum expectations were met.';
        return 'Needs improvement — explain the missing or weak requirements in feedback.';
    }

    function updateScoreBand() {
        if (!finalScore || !scoreBand) return;
        var value = finalScore.value.trim() === '' ? null : Number(finalScore.value);
        scoreBand.textContent = scoreLabel(value);
        scoreBand.classList.toggle('needs-improvement', value !== null && value < 75);
    }

    function updateRubricTotal() {
        var total = 0;
        var complete = rubricScores.length > 0;

        rubricScores.forEach(function (input) {
            var value = input.value.trim();
            if (value === '') {
                complete = false;
                return;
            }

            var number = Number(value);
            var maximum = Number(input.max);
            if (Number.isNaN(number)) {
                complete = false;
                return;
            }

            number = Math.max(0, Math.min(maximum, number));
            if (number !== Number(value)) input.value = number;
            total += number;
        });

        if (rubricTotal) {
            rubricTotal.textContent = total.toFixed(total % 1 === 0 ? 0 : 1) + ' / <?php echo $maximum_score; ?>';
        }
        if (applyRubricScore) {
            applyRubricScore.disabled = !complete;
            applyRubricScore.dataset.score = total;
        }
    }

    rubricScores.forEach(function (input) {
        input.addEventListener('input', updateRubricTotal);
    });

    if (applyRubricScore && finalScore) {
        applyRubricScore.addEventListener('click', function () {
            if (applyRubricScore.disabled) return;
            var suggested = Number(applyRubricScore.dataset.score || 0);
            finalScore.value = suggested.toFixed(suggested % 1 === 0 ? 0 : 1);
            updateScoreBand();
            finalScore.focus();
        });
    }

    if (finalScore) finalScore.addEventListener('input', updateScoreBand);
    updateRubricTotal();
    updateScoreBand();
}());
</script>
</body>
</html>
