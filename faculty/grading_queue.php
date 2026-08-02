<?php

include '../auth/auth.php';
require_role('faculty');
include '../config/database.php';
include '../includes/faculty_ui.php';

$user_id = intval($_SESSION['user_id']);
$faculty = faculty_account_record($conn, $user_id);
if (!$faculty) {
    http_response_code(403);
    exit('Faculty profile not found.');
}

$faculty_id = intval($faculty['id']);
$items = [];
$statement = mysqli_prepare($conn, '
    SELECT
        asm.id AS record_id,
        a.id AS parent_id,
        a.title,
        u.fullname AS student_name,
        st.student_no,
        sub.subject_code,
        sub.subject_name,
        sec.section_name,
        asm.submitted_at,
        asm.submission_status AS state_label,
        a.due_date AS deadline,
        "assignment" AS item_type
    FROM assignment_submissions asm
    INNER JOIN assignments a ON a.id = asm.assignment_id
    INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN students st ON st.id = asm.student_id
    INNER JOIN users u ON u.id = st.user_id
    WHERE ca.faculty_id = ? AND ay.status = "active" AND sem.status = "active" AND asm.score IS NULL

    UNION ALL

    SELECT
        qa.id AS record_id,
        q.id AS parent_id,
        q.title,
        u.fullname AS student_name,
        st.student_no,
        sub.subject_code,
        sub.subject_name,
        sec.section_name,
        COALESCE(qa.submitted_at, qa.started_at) AS submitted_at,
        qa.status AS state_label,
        q.available_until AS deadline,
        "quiz" AS item_type
    FROM quiz_attempts qa
    INNER JOIN quizzes q ON q.id = qa.quiz_id
    INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
    INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
    INNER JOIN semesters sem ON sem.id = ca.semester_id
    INNER JOIN subjects sub ON sub.id = ca.subject_id
    INNER JOIN sections sec ON sec.id = ca.section_id
    INNER JOIN students st ON st.id = qa.student_id
    INNER JOIN users u ON u.id = st.user_id
    WHERE ca.faculty_id = ? AND ay.status = "active" AND sem.status = "active" AND qa.status IN ("needs_review", "submitted")
');

mysqli_stmt_bind_param($statement, 'ii', $faculty_id, $faculty_id);
mysqli_stmt_execute($statement);
$result = mysqli_stmt_get_result($statement);
while ($row = mysqli_fetch_assoc($result)) {
    $items[] = $row;
}
mysqli_stmt_close($statement);

function faculty_queue_priority($item)
{
    if (($item['state_label'] ?? '') === 'late') {
        return 'late';
    }

    $deadline = strtotime((string) ($item['deadline'] ?? ''));
    if (!$deadline) {
        return 'standard';
    }

    if ($deadline < time()) {
        return 'overdue';
    }

    return $deadline <= strtotime('+24 hours') ? 'due-soon' : 'standard';
}

function faculty_queue_priority_label($priority, $item_type)
{
    $labels = [
        'late' => 'Late submission',
        'overdue' => 'Past deadline',
        'due-soon' => 'Due within 24h'
    ];

    return $labels[$priority] ?? ($item_type === 'quiz' ? 'Needs review' : 'Awaiting grade');
}

function faculty_queue_initials($name)
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

$assignment_count = 0;
$quiz_count = 0;
$attention_count = 0;
$late_count = 0;
$courses = [];

foreach ($items as &$item) {
    $item['priority'] = faculty_queue_priority($item);
    $item['course_key'] = $item['subject_code'] . '|' . $item['section_name'];
    $courses[$item['course_key']] = $item['subject_code'] . ' · ' . $item['section_name'];

    if ($item['item_type'] === 'assignment') {
        $assignment_count++;
    } else {
        $quiz_count++;
    }
    if ($item['priority'] !== 'standard') {
        $attention_count++;
    }
    if ($item['priority'] === 'late') {
        $late_count++;
    }
}
unset($item);

$priority_order = ['late' => 0, 'overdue' => 1, 'due-soon' => 2, 'standard' => 3];
usort($items, function ($left, $right) use ($priority_order) {
    $priority_compare = $priority_order[$left['priority']] <=> $priority_order[$right['priority']];
    if ($priority_compare !== 0) {
        return $priority_compare;
    }
    return strtotime((string) $left['submitted_at']) <=> strtotime((string) $right['submitted_at']);
});

ksort($courses);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/faculty-interface.css">
    <title>Grading Queue - StudyLink</title>
</head>
<body class="faculty-interface">
<?php render_faculty_sidebar('grading', $faculty['faculty_id']); ?>
<?php render_faculty_topbar('Grading Queue', 'Review and grade submitted work'); ?>

<main class="faculty-main">
    <div class="faculty-shell faculty-queue-shell">
        <section class="faculty-page-heading faculty-queue-heading">
            <div>
                <div class="faculty-eyebrow">Academic Evaluation</div>
                <h1>Grading Queue</h1>
                <p>Review student work in one organized queue for the Current Academic Term.</p>
            </div>
        </section>

        <section class="faculty-card faculty-queue-summary" aria-label="Queue summary">
            <div class="pending">
                <span class="faculty-queue-summary-icon"><?php echo study_icon('inbox'); ?></span>
                <span class="faculty-queue-summary-copy">
                    <strong><?php echo count($items); ?></strong>
                    <small>Pending</small>
                    <em>Ready to review</em>
                </span>
            </div>
            <div class="assignments">
                <span class="faculty-queue-summary-icon"><?php echo study_icon('file-earmark-text'); ?></span>
                <span class="faculty-queue-summary-copy">
                    <strong><?php echo $assignment_count; ?></strong>
                    <small>Assignments</small>
                    <em>File submissions</em>
                </span>
            </div>
            <div class="quizzes">
                <span class="faculty-queue-summary-icon"><?php echo study_icon('patch-question'); ?></span>
                <span class="faculty-queue-summary-copy">
                    <strong><?php echo $quiz_count; ?></strong>
                    <small>Quiz reviews</small>
                    <em>Manual checking</em>
                </span>
            </div>
            <div class="<?php echo $attention_count > 0 ? 'attention' : 'attention clear'; ?>">
                <span class="faculty-queue-summary-icon"><?php echo study_icon($attention_count > 0 ? 'exclamation-circle' : 'check2-circle'); ?></span>
                <span class="faculty-queue-summary-copy">
                    <strong><?php echo $attention_count; ?></strong>
                    <small>Needs attention</small>
                    <em><?php echo $late_count > 0
                        ? $late_count . ' late submission' . ($late_count === 1 ? '' : 's')
                        : 'No urgent items'; ?></em>
                </span>
            </div>
        </section>

        <section class="faculty-card faculty-queue-toolbar" aria-label="Queue filters">
            <label class="faculty-queue-search">
                <?php echo study_icon('search'); ?>
                <input id="queueSearch" type="search" placeholder="Search student, ID, course, or assessment">
            </label>
            <select id="queueCourse" aria-label="Filter by course">
                <option value="">All courses</option>
                <?php foreach ($courses as $key => $label): ?>
                    <option value="<?php echo e($key); ?>"><?php echo e($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select id="queueType" aria-label="Filter by assessment type">
                <option value="">All types</option>
                <option value="assignment">Assignments</option>
                <option value="quiz">Quizzes</option>
            </select>
            <select id="queuePriority" aria-label="Filter by priority">
                <option value="">All priorities</option>
                <option value="attention">Needs attention</option>
                <option value="standard">Standard</option>
            </select>
            <select id="queueSort" aria-label="Sort grading queue">
                <option value="priority">Priority first</option>
                <option value="oldest">Oldest submission</option>
                <option value="newest">Newest submission</option>
                <option value="student">Student name</option>
            </select>
        </section>

        <section class="faculty-card faculty-queue-table-card">
            <header class="faculty-queue-table-head">
                <div>
                    <h2>Student submissions</h2>
                    <p id="queueResultCount"><?php echo count($items); ?> item<?php echo count($items) === 1 ? '' : 's'; ?> shown</p>
                </div>
                <span>Open a submission to review the file, score, and feedback.</span>
            </header>

            <div class="faculty-table-wrap">
                <table class="faculty-table faculty-queue-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Assessment</th>
                            <th>Submitted</th>
                            <th>Priority</th>
                            <th><span class="visually-hidden">Action</span></th>
                        </tr>
                    </thead>
                    <tbody id="queueRows">
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="faculty-queue-empty">
                                    <?php echo study_icon('check2-circle'); ?>
                                    <h2>Your queue is clear</h2>
                                    <p>New assignment submissions and quizzes that need review will appear here.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($items as $item): ?>
                        <?php
                        $submitted_timestamp = strtotime((string) $item['submitted_at']) ?: 0;
                        $search_text = strtolower(
                            $item['student_name'] . ' ' .
                            $item['student_no'] . ' ' .
                            $item['subject_code'] . ' ' .
                            $item['subject_name'] . ' ' .
                            $item['section_name'] . ' ' .
                            $item['title']
                        );
                        $needs_attention = $item['priority'] !== 'standard';
                        ?>
                        <tr
                            data-queue-row
                            data-queue-type="<?php echo e($item['item_type']); ?>"
                            data-queue-course="<?php echo e($item['course_key']); ?>"
                            data-queue-priority="<?php echo e($item['priority']); ?>"
                            data-queue-attention="<?php echo $needs_attention ? 'attention' : 'standard'; ?>"
                            data-queue-search="<?php echo e($search_text); ?>"
                            data-queue-submitted="<?php echo $submitted_timestamp; ?>"
                            data-queue-student="<?php echo e(strtolower($item['student_name'])); ?>"
                            data-queue-rank="<?php echo intval($priority_order[$item['priority']]); ?>"
                        >
                            <td>
                                <div class="faculty-queue-student">
                                    <span><?php echo e(faculty_queue_initials($item['student_name'])); ?></span>
                                    <div>
                                        <strong><?php echo e($item['student_name']); ?></strong>
                                        <small><?php echo e($item['student_no']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <strong class="faculty-queue-course"><?php echo e($item['subject_code']); ?></strong>
                                <small><?php echo e($item['section_name']); ?></small>
                            </td>
                            <td>
                                <div class="faculty-queue-assessment">
                                    <span><?php echo study_icon($item['item_type'] === 'assignment' ? 'file-earmark-text' : 'patch-question'); ?></span>
                                    <div>
                                        <strong><?php echo e($item['title']); ?></strong>
                                        <small><?php echo e(ucfirst($item['item_type'])); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <time datetime="<?php echo e($item['submitted_at']); ?>">
                                    <strong><?php echo $submitted_timestamp ? date('M j, Y', $submitted_timestamp) : '—'; ?></strong>
                                    <small><?php echo $submitted_timestamp ? date('g:i A', $submitted_timestamp) : ''; ?></small>
                                </time>
                            </td>
                            <td>
                                <span class="faculty-queue-priority <?php echo e($item['priority']); ?>">
                                    <?php echo e(faculty_queue_priority_label($item['priority'], $item['item_type'])); ?>
                                </span>
                            </td>
                            <td class="faculty-queue-action">
                                <?php if ($item['item_type'] === 'assignment'): ?>
                                    <a class="faculty-button small" href="/studyLink/faculty/assignments/submissions.php?id=<?php echo intval($item['parent_id']); ?>&submission_id=<?php echo intval($item['record_id']); ?>">
                                        Grade submission <?php echo study_icon('arrow-right'); ?>
                                    </a>
                                <?php else: ?>
                                    <a class="faculty-button small light" href="/studyLink/faculty/quizzes/grade_attempt.php?attempt_id=<?php echo intval($item['record_id']); ?>">
                                        Review quiz <?php echo study_icon('arrow-right'); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="queueNoResults" class="faculty-queue-empty" hidden>
                <?php echo study_icon('search'); ?>
                <h2>No matching submissions</h2>
                <p>Adjust your search or filters to show more items.</p>
                <button id="queueClearFilters" class="faculty-button small light" type="button">Clear filters</button>
            </div>
        </section>
    </div>
</main>

<script>
(function () {
    var search = document.getElementById('queueSearch');
    var course = document.getElementById('queueCourse');
    var type = document.getElementById('queueType');
    var priority = document.getElementById('queuePriority');
    var sort = document.getElementById('queueSort');
    var rowsContainer = document.getElementById('queueRows');
    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-queue-row]'));
    var resultCount = document.getElementById('queueResultCount');
    var noResults = document.getElementById('queueNoResults');
    var clearFilters = document.getElementById('queueClearFilters');

    if (!rows.length) return;

    function compareRows(left, right) {
        if (sort.value === 'oldest') {
            return Number(left.dataset.queueSubmitted) - Number(right.dataset.queueSubmitted);
        }
        if (sort.value === 'newest') {
            return Number(right.dataset.queueSubmitted) - Number(left.dataset.queueSubmitted);
        }
        if (sort.value === 'student') {
            return left.dataset.queueStudent.localeCompare(right.dataset.queueStudent);
        }

        var rankDifference = Number(left.dataset.queueRank) - Number(right.dataset.queueRank);
        return rankDifference || Number(left.dataset.queueSubmitted) - Number(right.dataset.queueSubmitted);
    }

    function updateQueue() {
        var query = search.value.toLowerCase().trim();
        var visible = 0;

        rows.sort(compareRows).forEach(function (row) {
            rowsContainer.appendChild(row);
            var matches = (!query || row.dataset.queueSearch.indexOf(query) !== -1) &&
                (!course.value || row.dataset.queueCourse === course.value) &&
                (!type.value || row.dataset.queueType === type.value) &&
                (!priority.value || row.dataset.queueAttention === priority.value);

            row.hidden = !matches;
            if (matches) visible++;
        });

        resultCount.textContent = visible + (visible === 1 ? ' item shown' : ' items shown');
        noResults.hidden = visible !== 0;
    }

    [search, course, type, priority].forEach(function (field) {
        field.addEventListener(field.tagName === 'INPUT' ? 'input' : 'change', updateQueue);
    });
    sort.addEventListener('change', updateQueue);
    clearFilters.addEventListener('click', function () {
        search.value = '';
        course.value = '';
        type.value = '';
        priority.value = '';
        sort.value = 'priority';
        updateQueue();
        search.focus();
    });
}());
</script>
</body>
</html>
