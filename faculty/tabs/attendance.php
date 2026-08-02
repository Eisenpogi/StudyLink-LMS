<?php
$class_id = intval($class['id']);
$section_id = intval($class['section_id']);
$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date)) {
    $selected_date = date('Y-m-d');
}

$attendance_ready = false;
$session_check = mysqli_query($conn, "SHOW TABLES LIKE 'attendance_sessions'");
$record_check = mysqli_query($conn, "SHOW TABLES LIKE 'attendance_records'");
if ($session_check && mysqli_num_rows($session_check) > 0 && $record_check && mysqli_num_rows($record_check) > 0) {
    $attendance_ready = true;
}

$students = [];
$student_statement = mysqli_prepare($conn, '
    SELECT s.id, s.student_no, u.fullname
    FROM students s
    INNER JOIN users u ON u.id = s.user_id
    WHERE s.section_id = ?
    ORDER BY u.fullname ASC
');
mysqli_stmt_bind_param($student_statement, 'i', $section_id);
mysqli_stmt_execute($student_statement);
$result = mysqli_stmt_get_result($student_statement);
while ($row = mysqli_fetch_assoc($result)) $students[] = $row;
mysqli_stmt_close($student_statement);

$session = null;
$records = [];
$history = [];
$counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'excused' => 0];

if ($attendance_ready) {
    $session_statement = mysqli_prepare($conn, 'SELECT id, session_title FROM attendance_sessions WHERE class_assignment_id = ? AND attendance_date = ? LIMIT 1');
    mysqli_stmt_bind_param($session_statement, 'is', $class_id, $selected_date);
    mysqli_stmt_execute($session_statement);
    $session = mysqli_fetch_assoc(mysqli_stmt_get_result($session_statement));
    mysqli_stmt_close($session_statement);

    if ($session) {
        $record_statement = mysqli_prepare($conn, 'SELECT student_id, status, remarks FROM attendance_records WHERE attendance_session_id = ?');
        $session_id = intval($session['id']);
        mysqli_stmt_bind_param($record_statement, 'i', $session_id);
        mysqli_stmt_execute($record_statement);
        $result = mysqli_stmt_get_result($record_statement);
        while ($row = mysqli_fetch_assoc($result)) {
            $records[intval($row['student_id'])] = $row;
            if (isset($counts[$row['status']])) $counts[$row['status']]++;
        }
        mysqli_stmt_close($record_statement);
    }

    $history_statement = mysqli_prepare($conn, '
        SELECT ats.attendance_date, ats.session_title,
               COUNT(ar.id) AS marked_count,
               SUM(ar.status = "present") AS present_count
        FROM attendance_sessions ats
        LEFT JOIN attendance_records ar ON ar.attendance_session_id = ats.id
        WHERE ats.class_assignment_id = ?
        GROUP BY ats.id
        ORDER BY ats.attendance_date DESC
        LIMIT 8
    ');
    mysqli_stmt_bind_param($history_statement, 'i', $class_id);
    mysqli_stmt_execute($history_statement);
    $result = mysqli_stmt_get_result($history_statement);
    while ($row = mysqli_fetch_assoc($result)) $history[] = $row;
    mysqli_stmt_close($history_statement);
}
?>

<header class="faculty-module-head">
    <div><div class="faculty-eyebrow">Class participation</div><h2>Attendance</h2><p>Record daily attendance and review previous class sessions.</p></div>
</header>

<?php if (!$attendance_ready): ?>
    <div class="faculty-notice error">Attendance is ready in the interface, but the database tables are not installed. Import <code>sql/add_attendance.sql</code> once.</div>
<?php else: ?>
    <?php if (!$term_writable): ?>
        <div class="faculty-notice"><?php echo study_icon('lock'); ?> Attendance records are read-only because this is not the current Academic Term.</div>
    <?php endif; ?>
    <section class="faculty-compact-stats" style="grid-template-columns:repeat(4,minmax(0,1fr));">
        <article class="faculty-compact-stat"><span>Present</span><strong><?php echo $counts['present']; ?></strong></article>
        <article class="faculty-compact-stat"><span>Late</span><strong><?php echo $counts['late']; ?></strong></article>
        <article class="faculty-compact-stat"><span>Absent</span><strong><?php echo $counts['absent']; ?></strong></article>
        <article class="faculty-compact-stat"><span>Excused</span><strong><?php echo $counts['excused']; ?></strong></article>
    </section>

    <div class="faculty-module-grid">
        <section class="faculty-module-panel">
            <div class="faculty-panel-heading"><h3><?php echo e(date('F j, Y', strtotime($selected_date))); ?></h3><span><?php echo $session ? 'Saved session' : 'New session'; ?></span></div>
            <form action="/studyLink/faculty/attendance.php" method="post">
                <fieldset <?php echo !$term_writable ? 'disabled' : ''; ?> style="border:0;padding:0;margin:0;min-width:0;">
                <?php echo csrf_field(); ?><input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                <div class="attendance-toolbar" style="padding:16px 18px 0;">
                    <label class="faculty-form-field"><span>Session title</span><input type="text" name="session_title" maxlength="120" value="<?php echo e($session['session_title'] ?? 'Class Session'); ?>"></label>
                    <label class="faculty-form-field"><span>Attendance date</span><input type="date" name="attendance_date" value="<?php echo e($selected_date); ?>" required></label>
                    <div class="faculty-record-actions">
                        <button class="faculty-button small light" type="button" data-mark-all="present">All present</button>
                        <button class="faculty-button small light" type="button" data-mark-all="absent">All absent</button>
                    </div>
                </div>
                <div class="faculty-table-wrap">
                    <table class="faculty-table">
                        <thead><tr><th>Student</th><th>Status</th><th>Remarks</th></tr></thead>
                        <tbody>
                        <?php if (!$students): ?><tr><td colspan="3"><div class="faculty-empty">No students are assigned to this section.</div></td></tr><?php endif; ?>
                        <?php foreach ($students as $student): ?>
                            <?php $record = $records[intval($student['id'])] ?? ['status' => 'present', 'remarks' => '']; ?>
                            <tr>
                                <td><span class="faculty-student-avatar"><?php echo e(strtoupper(substr($student['fullname'], 0, 1))); ?></span><strong><?php echo e($student['fullname']); ?></strong><br><small style="margin-left:50px;color:var(--faculty-muted);"><?php echo e($student['student_no']); ?></small></td>
                                <td>
                                    <div class="attendance-statuses">
                                        <?php foreach (['present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'excused' => 'Excused'] as $value => $label): ?>
                                            <label class="attendance-choice <?php echo e($value); ?>"><input type="radio" name="status[<?php echo intval($student['id']); ?>]" value="<?php echo e($value); ?>" <?php echo $record['status'] === $value ? 'checked' : ''; ?>><span><?php echo e($label); ?></span></label>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td><input class="attendance-remarks" type="text" name="remarks[<?php echo intval($student['id']); ?>]" maxlength="255" value="<?php echo e($record['remarks'] ?? ''); ?>" placeholder="Optional"></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="display:flex;justify-content:flex-end;padding:16px 18px;"><button class="faculty-button" type="submit" <?php echo !$students ? 'disabled' : ''; ?>>Save attendance</button></div>
                </fieldset>
            </form>
        </section>

        <aside class="faculty-module-panel">
            <div class="faculty-panel-heading"><h3>Session history</h3><span>Latest records</span></div>
            <div class="attendance-history">
                <?php if (!$history): ?><div class="faculty-empty">No attendance sessions saved yet.</div><?php endif; ?>
                <?php foreach ($history as $item): ?>
                    <a href="?id=<?php echo $class_id; ?>&tab=attendance&date=<?php echo e($item['attendance_date']); ?>"><span><strong><?php echo e(date('M d, Y', strtotime($item['attendance_date']))); ?></strong><br><?php echo e($item['session_title']); ?></span><span><?php echo intval($item['present_count']); ?>/<?php echo intval($item['marked_count']); ?> present</span></a>
                <?php endforeach; ?>
            </div>
        </aside>
    </div>

    <script>
    document.querySelectorAll('[data-mark-all]').forEach(function (button) {
        button.addEventListener('click', function () {
            document.querySelectorAll('input[type="radio"][value="' + button.dataset.markAll + '"]').forEach(function (radio) {
                radio.checked = true;
            });
        });
    });
    </script>
<?php endif; ?>
