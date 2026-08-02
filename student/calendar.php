<?php
include '../auth/auth.php';
require_role('student');
include '../config/database.php';
include '../includes/student_notifications.php';

$user_id = intval($_SESSION['user_id']);
$student = null;
$events = [];

$stmt = mysqli_prepare($conn, 'SELECT id, student_no, section_id FROM students WHERE user_id = ? LIMIT 1');
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$student = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if ($student) {
    $section_id = intval($student['section_id']);
    $sql = '
        SELECT
            a.id AS item_id,
            a.title,
            a.instructions AS details,
            a.due_date AS event_date,
            sub.subject_code,
            sub.subject_name,
            ca.id AS class_id,
            "assignment" AS event_type,
            u.fullname AS faculty_name,
            sem.semester_name,
            ay.school_year
        FROM assignments a
        INNER JOIN class_assignments ca ON ca.id = a.class_assignment_id
        INNER JOIN subjects sub ON sub.id = ca.subject_id
        INNER JOIN faculty f ON f.id = ca.faculty_id
        INNER JOIN users u ON u.id = f.user_id
        INNER JOIN semesters sem ON sem.id = ca.semester_id
        INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
        WHERE ca.section_id = ?
          AND ay.status = "active"
          AND sem.status = "active"

        UNION ALL

        SELECT
            q.id AS item_id,
            CONCAT(q.title, " · Opens") AS title,
            q.instructions AS details,
            q.available_from AS event_date,
            sub.subject_code,
            sub.subject_name,
            ca.id AS class_id,
            "quiz" AS event_type,
            u.fullname AS faculty_name,
            sem.semester_name,
            ay.school_year
        FROM quizzes q
        INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
        INNER JOIN subjects sub ON sub.id = ca.subject_id
        INNER JOIN faculty f ON f.id = ca.faculty_id
        INNER JOIN users u ON u.id = f.user_id
        INNER JOIN semesters sem ON sem.id = ca.semester_id
        INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
        WHERE ca.section_id = ?
          AND ay.status = "active"
          AND sem.status = "active"
          AND q.status = "published"
          AND q.available_from IS NOT NULL

        UNION ALL

        SELECT
            q.id AS item_id,
            CONCAT(q.title, " · Deadline") AS title,
            q.instructions AS details,
            q.available_until AS event_date,
            sub.subject_code,
            sub.subject_name,
            ca.id AS class_id,
            "quiz" AS event_type,
            u.fullname AS faculty_name,
            sem.semester_name,
            ay.school_year
        FROM quizzes q
        INNER JOIN class_assignments ca ON ca.id = q.class_assignment_id
        INNER JOIN subjects sub ON sub.id = ca.subject_id
        INNER JOIN faculty f ON f.id = ca.faculty_id
        INNER JOIN users u ON u.id = f.user_id
        INNER JOIN semesters sem ON sem.id = ca.semester_id
        INNER JOIN academic_years ay ON ay.id = ca.academic_year_id
        WHERE ca.section_id = ?
          AND ay.status = "active"
          AND sem.status = "active"
          AND q.status = "published"
          AND q.available_until IS NOT NULL
        ORDER BY event_date
    ';
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iii', $section_id, $section_id, $section_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $events[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$requested_month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested_month)) {
    $requested_month = date('Y-m');
}

$month = DateTime::createFromFormat('!Y-m', $requested_month);
$month_key = $month->format('Y-m');
$days_in_month = intval($month->format('t'));
$first_weekday = intval($month->format('N'));
$previous_month = (clone $month)->modify('-1 month');
$next_month = (clone $month)->modify('+1 month');
$today_key = date('Y-m-d');
$today_start = strtotime('today');
$week_end = strtotime('+7 days', $today_start);

$event_map = [];
$event_lookup = [];
$upcoming = [];
$month_count = 0;
$week_count = 0;

foreach ($events as $event) {
    if (empty($event['event_date'])) {
        continue;
    }

    $timestamp = strtotime($event['event_date']);
    $date_key = date('Y-m-d', $timestamp);
    $event_key = $event['event_type'] . '-' . intval($event['item_id']) . '-' . $timestamp;
    $event['event_key'] = $event_key;
    $event['timestamp'] = $timestamp;

    $event_map[$date_key][] = $event;
    $event_lookup[$event_key] = $event;

    if (substr($date_key, 0, 7) === $month_key) {
        $month_count++;
    }
    if ($timestamp >= $today_start) {
        $upcoming[] = $event;
    }
    if ($timestamp >= $today_start && $timestamp <= $week_end) {
        $week_count++;
    }
}

usort($upcoming, function ($a, $b) {
    return $a['timestamp'] <=> $b['timestamp'];
});

$selected_key = $_GET['event'] ?? '';
$selected_event = $event_lookup[$selected_key] ?? ($upcoming[0] ?? null);
$leading_cells = $first_weekday - 1;
$total_cells = (int) ceil(($leading_cells + $days_in_month) / 7) * 7;
$previous_days = intval($previous_month->format('t'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/studyLink/assets/css/student-interface.css">
    <link rel="stylesheet" href="/studyLink/assets/css/account-actions.css">
    <link rel="stylesheet" href="/studyLink/assets/css/calendar-messages.css">
    <title>Calendar - StudyLink</title>
</head>
<body>
<aside class="student-sidebar">
    <a class="student-brand" href="/studyLink/student/dashboard.php"><span class="student-brand-mark">S</span>StudyLink</a>
    <nav class="student-nav">
        <a href="/studyLink/student/dashboard.php"><span class="student-nav-icon"><?php echo study_icon('grid'); ?></span>Dashboard</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('mortarboard'); ?></span>My Courses</a>
        <a class="active" href="/studyLink/student/calendar.php"><span class="student-nav-icon"><?php echo study_icon('calendar3'); ?></span>Calendar</a>
        <a href="/studyLink/student/classes.php"><span class="student-nav-icon"><?php echo study_icon('clipboard2-check'); ?></span>Assignments</a>
        <a href="/studyLink/student/quizzes.php"><span class="student-nav-icon"><?php echo study_icon('patch-question'); ?></span>Quizzes</a>
        <a href="/studyLink/student/messages.php"><span class="student-nav-icon"><?php echo study_icon('envelope'); ?></span>Messages</a>
    </nav>
    <div class="student-account">
        <div class="student-avatar"><?php echo strtoupper(substr($_SESSION['fullname'], 0, 1)); ?></div>
        <div>
            <div class="student-name"><?php echo e($_SESSION['fullname']); ?></div>
            <div class="student-id"><?php echo $student ? 'Student ID: ' . e($student['student_no']) : 'Student account'; ?></div>
        </div>
    </div>
</aside>

<header class="student-topbar">
    <div class="student-search static-search"><span><?php echo study_icon('search'); ?></span><span>Search your academic schedule</span></div>
    <div class="student-tools">
        <?php render_student_notification_button($conn, $user_id); ?>
        <a class="account-logout" href="/studyLink/auth/logout.php"><span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span><span class="account-logout-label">Log out</span></a>
    </div>
</header>

<main class="student-main">
    <div class="student-shell calendar-page">
        <section class="calendar-stats">
            <article class="card calendar-stat">
                <div class="calendar-stat-top"><span class="calendar-stat-icon"><?php echo study_icon('calendar-check'); ?></span><span>This Month</span></div>
                <strong><?php echo $month_count; ?></strong>
                <p>Scheduled academic events</p>
            </article>
            <article class="card calendar-stat">
                <div class="calendar-stat-top"><span class="calendar-stat-icon warning"><?php echo study_icon('exclamation-circle'); ?></span><span>Upcoming</span></div>
                <strong><?php echo $week_count; ?></strong>
                <p>Deadlines in the next 7 days</p>
            </article>
            <article class="calendar-banner">
                <div class="eyebrow">Next Major Event</div>
                <?php if (!empty($upcoming)): ?>
                    <h2><?php echo e($upcoming[0]['title']); ?></h2>
                    <p><?php echo e($upcoming[0]['subject_code']); ?> · <?php echo date('F j, Y · g:i A', $upcoming[0]['timestamp']); ?></p>
                    <a class="button gold" href="?month=<?php echo date('Y-m', $upcoming[0]['timestamp']); ?>&event=<?php echo e($upcoming[0]['event_key']); ?>">View Event</a>
                <?php else: ?>
                    <h2>Your schedule is clear</h2>
                    <p>No upcoming assignment or quiz deadline has been posted.</p>
                <?php endif; ?>
            </article>
        </section>

        <div class="calendar-layout">
            <section class="card calendar-card">
                <div class="calendar-head">
                    <div class="calendar-title-group">
                        <h1><?php echo $month->format('F Y'); ?></h1>
                        <div class="calendar-arrows">
                            <a href="?month=<?php echo $previous_month->format('Y-m'); ?>" aria-label="Previous month"><?php echo study_icon('chevron-left'); ?></a>
                            <a href="?month=<?php echo $next_month->format('Y-m'); ?>" aria-label="Next month"><?php echo study_icon('chevron-right'); ?></a>
                        </div>
                    </div>
                    <div class="calendar-view-switch"><span class="active">Month</span><span>Week</span><span>Day</span></div>
                </div>

                <div class="calendar-weekdays">
                    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $label): ?>
                        <span><?php echo $label; ?></span>
                    <?php endforeach; ?>
                </div>

                <div class="calendar-grid">
                    <?php for ($cell = 0; $cell < $total_cells; $cell++): ?>
                        <?php
                        $day_number = $cell - $leading_cells + 1;
                        $is_current_month = $day_number >= 1 && $day_number <= $days_in_month;
                        if ($is_current_month) {
                            $cell_date = $month_key . '-' . str_pad($day_number, 2, '0', STR_PAD_LEFT);
                            $display_day = $day_number;
                        } elseif ($day_number < 1) {
                            $display_day = $previous_days + $day_number;
                            $cell_date = $previous_month->format('Y-m') . '-' . str_pad($display_day, 2, '0', STR_PAD_LEFT);
                        } else {
                            $display_day = $day_number - $days_in_month;
                            $cell_date = $next_month->format('Y-m') . '-' . str_pad($display_day, 2, '0', STR_PAD_LEFT);
                        }
                        ?>
                        <div class="calendar-day <?php echo !$is_current_month ? 'other-month' : ''; ?> <?php echo $cell_date === $today_key ? 'today' : ''; ?>">
                            <div class="calendar-day-top">
                                <span class="day-number"><?php echo $display_day; ?></span>
                                <?php if ($cell_date === $today_key && !empty($event_map[$cell_date])): ?><i></i><?php endif; ?>
                            </div>
                            <?php if ($is_current_month): ?>
                                <?php foreach ($event_map[$cell_date] ?? [] as $event): ?>
                                    <a
                                        class="calendar-event <?php echo e($event['event_type']); ?> <?php echo $selected_event && $selected_event['event_key'] === $event['event_key'] ? 'selected' : ''; ?>"
                                        href="?month=<?php echo e($month_key); ?>&event=<?php echo e($event['event_key']); ?>"
                                        title="<?php echo e($event['title']); ?>"
                                    >
                                        <strong><?php echo e($event['subject_code']); ?></strong>
                                        <span><?php echo e($event['title']); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </section>

            <aside class="calendar-rail">
                <section class="card active-event-card">
                    <?php if ($selected_event): ?>
                        <div class="active-event-top">
                            <span class="event-type-badge <?php echo e($selected_event['event_type']); ?>"><?php echo ucfirst(e($selected_event['event_type'])); ?></span>
                            <span class="event-date-badge"><?php echo date('M d', $selected_event['timestamp']); ?></span>
                        </div>
                        <h2><?php echo e($selected_event['title']); ?></h2>
                        <p class="event-time"><?php echo study_icon('clock'); ?> <?php echo date('F j, Y · g:i A', $selected_event['timestamp']); ?></p>
                        <div class="event-detail-block">
                            <span>Course</span>
                            <strong><?php echo e($selected_event['subject_code'] . ' · ' . $selected_event['subject_name']); ?></strong>
                        </div>
                        <div class="event-detail-block">
                            <span>Faculty</span>
                            <strong><?php echo e($selected_event['faculty_name']); ?></strong>
                        </div>
                        <div class="event-detail-block">
                            <span>Details</span>
                            <p><?php echo e($selected_event['details'] ?: 'No additional instructions were provided.'); ?></p>
                        </div>
                        <a class="button full-button" href="/studyLink/student/class_view.php?id=<?php echo intval($selected_event['class_id']); ?>&tab=<?php echo $selected_event['event_type'] === 'quiz' ? 'quizzes' : 'assignments'; ?>">Open <?php echo ucfirst(e($selected_event['event_type'])); ?></a>
                    <?php else: ?>
                        <div class="calendar-empty-detail">
                            <span><?php echo study_icon('calendar-event'); ?></span>
                            <h2>No event selected</h2>
                            <p>Select an assignment or quiz from the calendar to see its details.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="card rail-card">
                    <h3>Upcoming Schedule</h3>
                    <?php if (empty($upcoming)): ?><p class="lead">No upcoming events.</p><?php endif; ?>
                    <?php foreach (array_slice($upcoming, 0, 5) as $event): ?>
                        <a class="upcoming-row" href="?month=<?php echo date('Y-m', $event['timestamp']); ?>&event=<?php echo e($event['event_key']); ?>">
                            <span class="upcoming-date"><?php echo date('M', $event['timestamp']); ?><b><?php echo date('d', $event['timestamp']); ?></b></span>
                            <span><strong><?php echo e($event['title']); ?></strong><small><?php echo e($event['subject_code']); ?> · <?php echo date('g:i A', $event['timestamp']); ?></small></span>
                        </a>
                    <?php endforeach; ?>
                </section>

                <section class="card rail-card">
                    <h3>Event Categories</h3>
                    <div class="calendar-filters">
                        <label><input type="checkbox" checked data-event-filter="assignment"><i class="assignment-dot"></i>Assignments</label>
                        <label><input type="checkbox" checked data-event-filter="quiz"><i class="quiz-dot"></i>Quizzes</label>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</main>

<script>
document.querySelectorAll('[data-event-filter]').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
        document.querySelectorAll('.calendar-event.' + this.dataset.eventFilter).forEach(function (event) {
            event.hidden = !checkbox.checked;
        });
    });
});
</script>
</body>
</html>
