<?php

$attempt_statement = mysqli_prepare($conn, '
    SELECT qa.id, qa.attempt_no, qa.score, qa.total_points, qa.percentage,
           qa.status, qa.is_passed, qa.started_at, qa.submitted_at,
           u.fullname AS student_name, s.student_no
    FROM quiz_attempts qa
    INNER JOIN students s ON s.id = qa.student_id
    INNER JOIN users u ON u.id = s.user_id
    WHERE qa.quiz_id = ?
    ORDER BY qa.submitted_at DESC, qa.started_at DESC
');
mysqli_stmt_bind_param($attempt_statement, 'i', $quiz_id);
mysqli_stmt_execute($attempt_statement);
$attempts = mysqli_stmt_get_result($attempt_statement);

$summary_statement = mysqli_prepare($conn, '
    SELECT COUNT(*) AS total_attempts,
           COUNT(DISTINCT student_id) AS students_attempted,
           SUM(status = "needs_review") AS pending_review,
           AVG(CASE WHEN status = "graded" THEN percentage END) AS average_score
    FROM quiz_attempts
    WHERE quiz_id = ? AND status <> "in_progress"
');
mysqli_stmt_bind_param($summary_statement, 'i', $quiz_id);
mysqli_stmt_execute($summary_statement);
$summary = mysqli_fetch_assoc(mysqli_stmt_get_result($summary_statement));
mysqli_stmt_close($summary_statement);
?>
<style>
.result-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px}
.result-card,.result-table{background:#fff;border-radius:14px;padding:18px;box-shadow:0 2px 9px rgba(0,0,0,.07)}
.result-card strong{display:block;font-size:24px;margin-top:7px}
.result-table{overflow-x:auto}.result-table table{width:100%;border-collapse:collapse}
.result-table th,.result-table td{text-align:left;padding:11px;border-bottom:1px solid #eee}
.result-status{display:inline-block;padding:6px 9px;border-radius:14px;background:#eef2ff;color:#3730a3;font-size:12px}
.result-status.pending{background:#fef3c7;color:#92400e}
</style>

<div class="result-summary">
    <div class="result-card"><?php echo study_icon('clipboard2-check'); ?> Submitted Attempts<strong><?php echo intval($summary['total_attempts']); ?></strong></div>
    <div class="result-card"><?php echo study_icon('people'); ?> Students Attempted<strong><?php echo intval($summary['students_attempted']); ?></strong></div>
    <div class="result-card"><?php echo study_icon('hourglass-split'); ?> Pending Essays<strong><?php echo intval($summary['pending_review']); ?></strong></div>
    <div class="result-card"><?php echo study_icon('bar-chart'); ?> Average<strong><?php echo $summary['average_score'] === null ? '—' : number_format(floatval($summary['average_score']), 2) . '%'; ?></strong></div>
</div>

<div class="result-table">
    <h3>Student Attempts</h3>
    <?php if (mysqli_num_rows($attempts) === 0): ?>
        <p>No student has attempted this quiz yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Student</th><th>Attempt</th><th>Score</th>
                    <th>Status</th><th>Submitted</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php while ($attempt = mysqli_fetch_assoc($attempts)): ?>
                <tr>
                    <td><?php echo e($attempt['student_name']); ?><br><small><?php echo e($attempt['student_no']); ?></small></td>
                    <td><?php echo intval($attempt['attempt_no']); ?></td>
                    <td>
                        <?php echo number_format(floatval($attempt['score']), 2); ?> /
                        <?php echo number_format(floatval($attempt['total_points']), 2); ?>
                        (<?php echo number_format(floatval($attempt['percentage']), 2); ?>%)
                    </td>
                    <td>
                        <span class="result-status <?php echo $attempt['status'] === 'needs_review' ? 'pending' : ''; ?>">
                            <?php echo e(ucwords(str_replace('_', ' ', $attempt['status']))); ?>
                        </span>
                    </td>
                    <td><?php echo e($attempt['submitted_at'] ?? 'In progress'); ?></td>
                    <td>
                        <?php if ($attempt['status'] !== 'in_progress'): ?>
                            <a class="btn btn-light" href="grade_attempt.php?attempt_id=<?php echo intval($attempt['id']); ?>"><?php echo study_icon('pencil-square'); ?> View / Grade</a>
                        <?php else: ?>
                            Active
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php mysqli_stmt_close($attempt_statement); ?>
