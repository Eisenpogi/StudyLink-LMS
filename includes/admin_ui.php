<?php

require_once __DIR__ . '/ui_icons.php';

function admin_navigation_items()
{
    return [
        'Overview' => [
            'dashboard' => ['/studyLink/admin/dashboard.php', 'grid', 'Dashboard']
        ],
        'Academic Setup' => [
            'academic_year' => ['/studyLink/admin/academic_year/index.php', 'calendar-range', 'Academic Term'],
            'sections' => ['/studyLink/admin/selections/index.php', 'diagram-3', 'Sections'],
            'subjects' => ['/studyLink/admin/subjects/index.php', 'journal-text', 'Subjects']
        ],
        'User Management' => [
            'faculty' => ['/studyLink/admin/faculty/index.php', 'person-badge', 'Faculty'],
            'students' => ['/studyLink/admin/students/index.php', 'people', 'Students']
        ],
        'Class Management' => [
            'classes' => ['/studyLink/admin/class_assignments/index.php', 'collection', 'Class Assignments']
        ],
        'Communication' => [
            'messages' => ['/studyLink/admin/messages.php', 'envelope', 'Messages']
        ]
    ];
}

function render_admin_sidebar($active)
{
    $navigation = admin_navigation_items();
    ?>
    <button class="portal-menu-button admin-menu-button" type="button" data-portal-menu aria-label="Open navigation">
        <?php echo study_icon('list'); ?>
    </button>
    <div class="portal-sidebar-backdrop" data-portal-backdrop hidden></div>
    <aside class="admin-sidebar" data-portal-sidebar>
        <a class="admin-brand" href="/studyLink/admin/dashboard.php">
            <span class="admin-brand-mark">S</span>
            <span><strong>StudyLink</strong><small>Admin Console</small></span>
        </a>
        <nav class="admin-nav" aria-label="Admin navigation">
            <?php foreach ($navigation['Overview'] as $key => $item): ?>
                <a class="admin-nav-direct <?php echo $active === $key ? 'active' : ''; ?>" href="<?php echo e($item[0]); ?>">
                    <span><?php echo study_icon($item[1]); ?></span>
                    <?php echo e($item[2]); ?>
                </a>
            <?php endforeach; ?>
            <?php foreach ($navigation as $group => $items): ?>
                <?php
                if ($group === 'Overview') {
                    continue;
                }

                $group_id = 'admin-nav-' . strtolower(str_replace(' ', '-', $group));
                $group_active = array_key_exists($active, $items);
                ?>
                <section class="admin-nav-group <?php echo $group_active ? 'is-open' : ''; ?>" data-admin-nav-group>
                    <button
                        class="admin-nav-toggle"
                        type="button"
                        data-admin-nav-toggle
                        aria-expanded="<?php echo $group_active ? 'true' : 'false'; ?>"
                        aria-controls="<?php echo e($group_id); ?>"
                    >
                        <span><?php echo e($group); ?></span>
                    </button>
                    <div class="admin-nav-items" id="<?php echo e($group_id); ?>" data-admin-nav-items <?php echo $group_active ? '' : 'hidden'; ?>>
                        <?php foreach ($items as $key => $item): ?>
                            <a class="<?php echo $active === $key ? 'active' : ''; ?>" href="<?php echo e($item[0]); ?>">
                                <span><?php echo study_icon($item[1]); ?></span>
                                <?php echo e($item[2]); ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </nav>
        <div class="admin-sidebar-footer">
            <span class="admin-status-dot"></span>
            <span><strong>System online</strong><small>StudyLink management</small></span>
        </div>
    </aside>
    <?php
}

function render_admin_topbar($title, $breadcrumb = 'Admin Console')
{
    ?>
    <header class="admin-topbar">
        <div>
            <span class="admin-breadcrumb"><?php echo e($breadcrumb); ?></span>
            <strong><?php echo e($title); ?></strong>
        </div>
        <div class="admin-topbar-actions">
            <span class="admin-role-badge"><?php echo study_icon('shield-lock'); ?> Administrator</span>
            <button class="admin-profile-button" type="button" data-portal-logout-trigger>
                <span><?php echo e(strtoupper(substr($_SESSION['fullname'] ?? 'A', 0, 1))); ?></span>
                <span><strong><?php echo e($_SESSION['fullname'] ?? 'Administrator'); ?></strong><small>Secure account</small></span>
                <?php echo study_icon('chevron-down'); ?>
            </button>
        </div>
    </header>
    <div class="portal-dialog-backdrop" data-portal-logout-dialog hidden>
        <section class="portal-dialog" role="dialog" aria-modal="true" aria-labelledby="adminLogoutTitle">
            <div class="portal-dialog-icon"><?php echo study_icon('shield-lock'); ?></div>
            <h2 id="adminLogoutTitle">End admin session?</h2>
            <p>For security, sign in again when you return to the Admin Console.</p>
            <div class="portal-dialog-actions">
                <button type="button" class="portal-button secondary" data-portal-logout-cancel>Cancel</button>
                <a class="portal-button danger" href="/studyLink/auth/logout.php?portal=admin">End session</a>
            </div>
        </section>
    </div>
    <?php
}

function render_admin_page_start($active, $page_title, $heading, $description, $eyebrow = 'Administration')
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo e($page_title); ?> - StudyLink</title>
        <link rel="stylesheet" href="/studyLink/assets/css/app-shell.css">
        <link rel="stylesheet" href="/studyLink/assets/css/admin-interface.css">
        <link rel="stylesheet" href="/studyLink/assets/css/admin-access-control.css">
        <style>
            .admin-nav {
                gap: 6px;
            }

            .admin-nav-direct {
                margin-bottom: 4px;
            }

            .admin-nav-group {
                display: block;
            }

            .admin-nav-toggle {
                display: flex;
                align-items: center;
                justify-content: space-between;
                width: 100%;
                min-height: 38px;
                padding: 0 12px;
                border: 0;
                border-radius: 8px;
                background: transparent;
                color: #91a2bc;
                font: inherit;
                font-size: .69rem;
                font-weight: 800;
                letter-spacing: .08em;
                text-align: left;
                text-transform: uppercase;
                cursor: pointer;
            }

            .admin-nav-toggle:hover,
            .admin-nav-toggle:focus-visible,
            .admin-nav-group.is-open > .admin-nav-toggle {
                background: rgba(255, 255, 255, .06);
                color: #fff;
                outline: none;
            }

            .admin-nav-items {
                display: grid;
                gap: 4px;
                padding: 4px 0 2px 8px;
            }

            .admin-nav-items[hidden] {
                display: none;
            }

        </style>
    </head>
    <body class="admin-interface">
    <?php render_admin_sidebar($active); ?>
    <?php render_admin_topbar($page_title); ?>
    <main class="admin-main">
        <div class="admin-shell">
            <section class="admin-page-heading">
                <div>
                    <div class="admin-eyebrow"><?php echo e($eyebrow); ?></div>
                    <h1><?php echo e($heading); ?></h1>
                    <p><?php echo e($description); ?></p>
                </div>
                <span class="admin-security-note">
                    <?php echo study_icon('shield-check'); ?>
                    Protected admin action
                </span>
            </section>
    <?php
}

function render_admin_flash($flash)
{
    if (!$flash) {
        return;
    }

    $type = ($flash['type'] ?? '') === 'success' ? 'success' : 'error';
    ?>
    <div class="admin-alert <?php echo e($type); ?>" role="status">
        <?php echo study_icon($type === 'success' ? 'check-circle' : 'exclamation-circle'); ?>
        <span><?php echo e($flash['message'] ?? ''); ?></span>
    </div>
    <?php
}

function render_admin_workflow($current)
{
    $steps = [
        'term' => [
            '1',
            'Academic term',
            'Set the current academic year and semester.',
            '/studyLink/admin/academic_year/index.php'
        ],
        'structure' => [
            '2',
            'School structure',
            'Create sections and reusable subjects.',
            '/studyLink/admin/selections/index.php'
        ],
        'accounts' => [
            '3',
            'User accounts',
            'Create faculty and student accounts.',
            '/studyLink/admin/faculty/index.php'
        ],
        'classes' => [
            '4',
            'Class assignments',
            'Connect faculty, subject, section, and term.',
            '/studyLink/admin/class_assignments/index.php'
        ]
    ];
    ?>
    <section class="admin-workflow" aria-label="Recommended admin setup order">
        <div class="admin-workflow-heading">
            <span><?php echo study_icon('signpost-split'); ?></span>
            <span>
                <strong>Recommended setup order</strong>
                <small>Follow these steps when preparing a new school term.</small>
            </span>
        </div>
        <div class="admin-workflow-steps">
            <?php foreach ($steps as $key => $step): ?>
                <a href="<?php echo e($step[3]); ?>" class="<?php echo $current === $key ? 'current' : ''; ?>">
                    <span><?php echo e($step[0]); ?></span>
                    <span><strong><?php echo e($step[1]); ?></strong><small><?php echo e($step[2]); ?></small></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

function render_admin_explainer($title, $message, $icon = 'info-circle')
{
    ?>
    <div class="admin-explainer">
        <span><?php echo study_icon($icon); ?></span>
        <span><strong><?php echo e($title); ?></strong><small><?php echo e($message); ?></small></span>
    </div>
    <?php
}

function render_admin_page_end()
{
    ?>
        </div>
    </main>
    <script src="/studyLink/assets/js/app-shell.js"></script>
    </body>
    </html>
    <?php
}
