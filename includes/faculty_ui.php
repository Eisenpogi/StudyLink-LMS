<?php

require_once __DIR__ . '/ui_icons.php';
require_once __DIR__ . '/faculty_notifications.php';

function faculty_account_record($conn, $user_id)
{
    $stmt = mysqli_prepare($conn, 'SELECT id, faculty_id FROM faculty WHERE user_id = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 'i', $user_id);
    mysqli_stmt_execute($stmt);
    $record = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    return $record ?: null;
}

function faculty_cover_column_ready($conn)
{
    static $ready = null;
    if ($ready === null) {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM class_assignments LIKE 'cover_image'");
        $ready = $result && mysqli_num_rows($result) > 0;
    }
    return $ready;
}

function faculty_color_column_ready($conn)
{
    static $ready = null;
    if ($ready === null) {
        $result = mysqli_query($conn, "SHOW COLUMNS FROM class_assignments LIKE 'cover_color'");
        $ready = $result && mysqli_num_rows($result) > 0;
    }
    return $ready;
}

function faculty_cover_expression($conn, $alias = 'ca')
{
    return faculty_cover_column_ready($conn) ? $alias . '.cover_image' : 'NULL';
}

function faculty_color_expression($conn, $alias = 'ca')
{
    return faculty_color_column_ready($conn) ? $alias . '.cover_color' : 'NULL';
}

function faculty_course_accent($class_id)
{
    $colors = ['#0b2b5b', '#5c6fa3', '#8a6d00', '#355f62', '#72566f', '#415e85'];
    return $colors[abs(intval($class_id)) % count($colors)];
}

function faculty_sanitize_course_color($color)
{
    $color = strtolower(trim((string) $color));
    return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '';
}

function faculty_course_color($class_id, $saved_color = '')
{
    $saved_color = faculty_sanitize_course_color($saved_color);
    return $saved_color !== '' ? $saved_color : faculty_course_accent($class_id);
}

function faculty_adjust_course_color($hex, $amount)
{
    $hex = ltrim(faculty_sanitize_course_color($hex), '#');
    if (strlen($hex) !== 6) {
        return '#071a38';
    }

    $parts = [];
    foreach ([0, 2, 4] as $offset) {
        $value = hexdec(substr($hex, $offset, 2));
        $value = max(0, min(255, $value + $amount));
        $parts[] = str_pad(dechex($value), 2, '0', STR_PAD_LEFT);
    }
    return '#' . implode('', $parts);
}

function faculty_course_gradient_style($class_id, $saved_color = '')
{
    $base = faculty_course_color($class_id, $saved_color);
    $light = faculty_adjust_course_color($base, 46);
    $dark = faculty_adjust_course_color($base, -58);
    return '--course-accent:' . $base . ';--course-accent-light:' . $light . ';--course-accent-dark:' . $dark . ';';
}

function faculty_course_cover_url($path)
{
    $path = ltrim(str_replace('\\', '/', trim((string) $path)), '/');
    if (
        $path === '' ||
        strpos($path, 'assets/uploads/course_covers/') !== 0 ||
        strpos($path, '..') !== false
    ) {
        return '';
    }
    return '/studyLink/' . $path;
}

function render_faculty_sidebar($active, $faculty_id = '')
{
    $items = [
        'dashboard' => ['/studyLink/faculty/dashboard.php', 'grid', 'Dashboard'],
        'courses' => ['/studyLink/faculty/classes.php', 'book', 'My Courses'],
        'modules' => ['/studyLink/faculty/modules.php', 'collection', 'Modules'],
        'grading' => ['/studyLink/faculty/grading_queue.php', 'clipboard-check', 'Grading Queue'],
        'progress' => ['/studyLink/faculty/student_progress.php', 'graph-up-arrow', 'Student Progress'],
        'resources' => ['/studyLink/faculty/learning_resources.php', 'folder2-open', 'Learning Resources'],
        'messages' => ['/studyLink/faculty/messages.php', 'envelope', 'Messages'],
        'drive' => ['/studyLink/faculty/drive/index.php', 'cloud-arrow-up', 'Faculty Drive']
    ];
    ?>
    <aside class="faculty-sidebar">
        <a class="faculty-brand" href="/studyLink/faculty/dashboard.php"><span>S</span>StudyLink</a>
        <nav class="faculty-nav">
            <?php foreach ($items as $key => $item): ?>
                <a class="<?php echo $active === $key ? 'active' : ''; ?>" href="<?php echo e($item[0]); ?>">
                    <i><?php echo study_icon($item[1]); ?></i><span><?php echo e($item[2]); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="faculty-account">
            <div class="faculty-avatar"><?php echo e(strtoupper(substr($_SESSION['fullname'], 0, 1))); ?></div>
            <div><strong><?php echo e($_SESSION['fullname']); ?></strong><small><?php echo $faculty_id ? 'Faculty ID: ' . e($faculty_id) : 'Faculty account'; ?></small></div>
        </div>
    </aside>
    <?php
}

function render_faculty_topbar($title, $subtitle = '')
{
    global $conn;

    $user_id = intval($_SESSION['user_id'] ?? 0);
    ?>
    <header class="faculty-topbar">
        <div class="faculty-topbar-title"><strong><?php echo e($title); ?></strong><?php if ($subtitle !== ''): ?><span><?php echo e($subtitle); ?></span><?php endif; ?></div>
        <div class="faculty-topbar-actions">
            <?php render_faculty_notification_button($conn, $user_id); ?>
            <button class="account-logout faculty-logout" type="button" data-faculty-logout-trigger aria-haspopup="dialog">
                <span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span>
                <span class="account-logout-label">Log out</span>
            </button>
        </div>
    </header>

    <div class="faculty-confirm-overlay" data-faculty-logout-modal hidden>
        <section class="faculty-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="facultyLogoutTitle" aria-describedby="facultyLogoutDescription">
            <div class="faculty-confirm-icon"><?php echo study_icon('box-arrow-right'); ?></div>
            <div>
                <h2 id="facultyLogoutTitle">Log out of StudyLink?</h2>
                <p id="facultyLogoutDescription">You will need to sign in again to continue managing your courses.</p>
            </div>
            <div class="faculty-confirm-actions">
                <button class="faculty-confirm-cancel" type="button" data-faculty-logout-cancel>Cancel</button>
                <a class="faculty-confirm-submit" href="/studyLink/auth/logout.php?portal=faculty">Yes, log out</a>
            </div>
        </section>
    </div>

    <script>
    (function () {
        var trigger = document.querySelector('[data-faculty-logout-trigger]');
        var modal = document.querySelector('[data-faculty-logout-modal]');
        var cancel = document.querySelector('[data-faculty-logout-cancel]');
        if (!trigger || !modal || !cancel || modal.dataset.ready === '1') return;

        modal.dataset.ready = '1';

        function openModal() {
            modal.hidden = false;
            document.body.classList.add('faculty-modal-open');
            cancel.focus();
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('faculty-modal-open');
            trigger.focus();
        }

        trigger.addEventListener('click', openModal);
        cancel.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
    }());
    </script>
    <?php
}
