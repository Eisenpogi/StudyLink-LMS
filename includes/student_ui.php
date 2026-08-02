<?php

require_once __DIR__ . '/ui_icons.php';
require_once __DIR__ . '/student_notifications.php';

function student_navigation_items()
{
    return [
        'dashboard' => ['/studyLink/student/dashboard.php', 'grid', 'Dashboard'],
        'courses' => ['/studyLink/student/classes.php', 'mortarboard', 'My Courses'],
        'modules' => ['/studyLink/student/modules.php', 'collection', 'Modules'],
        'calendar' => ['/studyLink/student/calendar.php', 'calendar3', 'Calendar'],
        'messages' => ['/studyLink/student/messages.php', 'envelope', 'Messages']
    ];
}

function render_student_sidebar($active, $student_number = '')
{
    ?>
    <button class="portal-menu-button" type="button" data-portal-menu aria-label="Open navigation">
        <?php echo study_icon('list'); ?>
    </button>
    <div class="portal-sidebar-backdrop" data-portal-backdrop hidden></div>
    <aside class="student-sidebar" data-portal-sidebar>
        <a class="student-brand" href="/studyLink/student/dashboard.php">
            <span class="student-brand-mark">S</span>
            <span>StudyLink</span>
        </a>
        <nav class="student-nav" aria-label="Student navigation">
            <?php foreach (student_navigation_items() as $key => $item): ?>
                <a class="<?php echo $active === $key ? 'active' : ''; ?>" href="<?php echo e($item[0]); ?>">
                    <span class="student-nav-icon"><?php echo study_icon($item[1]); ?></span>
                    <span><?php echo e($item[2]); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="student-account">
            <div class="student-avatar"><?php echo e(strtoupper(substr($_SESSION['fullname'] ?? 'S', 0, 1))); ?></div>
            <div>
                <div class="student-name"><?php echo e($_SESSION['fullname'] ?? 'Student'); ?></div>
                <div class="student-id"><?php echo $student_number !== '' ? 'Student ID: ' . e($student_number) : 'Student account'; ?></div>
            </div>
        </div>
    </aside>
    <?php
}

function render_portal_logout_dialog($portal)
{
    ?>
    <div class="portal-dialog-backdrop" data-portal-logout-dialog hidden>
        <section class="portal-dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo e($portal); ?>LogoutTitle">
            <div class="portal-dialog-icon"><?php echo study_icon('box-arrow-right'); ?></div>
            <h2 id="<?php echo e($portal); ?>LogoutTitle">Log out of StudyLink?</h2>
            <p>You will need to sign in again to continue.</p>
            <div class="portal-dialog-actions">
                <button type="button" class="portal-button secondary" data-portal-logout-cancel>Cancel</button>
                <a class="portal-button danger" href="/studyLink/auth/logout.php?portal=<?php echo e($portal); ?>">Yes, log out</a>
            </div>
        </section>
    </div>
    <?php
}

function render_student_topbar($conn, $title, $search_id = '', $search_placeholder = '')
{
    $user_id = intval($_SESSION['user_id'] ?? 0);
    ?>
    <header class="student-topbar">
        <div class="portal-topbar-title">
            <strong><?php echo e($title); ?></strong>
        </div>
        <?php if ($search_id !== ''): ?>
            <label class="student-search">
                <span><?php echo study_icon('search'); ?></span>
                <input id="<?php echo e($search_id); ?>" type="search" placeholder="<?php echo e($search_placeholder); ?>" autocomplete="off">
            </label>
        <?php endif; ?>
        <div class="student-tools">
            <?php render_student_notification_button($conn, $user_id); ?>
            <button class="account-logout" type="button" data-portal-logout-trigger>
                <span class="account-logout-icon"><?php echo study_icon('box-arrow-right'); ?></span>
                <span class="account-logout-label">Log out</span>
            </button>
        </div>
    </header>
    <?php
    render_portal_logout_dialog('student');
}
