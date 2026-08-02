<?php
include '../../auth/auth.php';
require_role('admin');
include '../../config/database.php';
include '../../includes/admin_ui.php';
include '../../includes/academic_term.php';

$flash = get_flash();
$years_result = mysqli_query(
    $conn,
    "SELECT ay.*, COUNT(ca.id) AS class_count
     FROM academic_years ay
     LEFT JOIN class_assignments ca ON ca.academic_year_id = ay.id
     GROUP BY ay.id, ay.school_year, ay.status, ay.created_at
     ORDER BY ay.school_year DESC, ay.id DESC"
);
$years = $years_result ? mysqli_fetch_all($years_result, MYSQLI_ASSOC) : [];

$semesters_result = mysqli_query(
    $conn,
    "SELECT sem.*, COUNT(ca.id) AS class_count
     FROM semesters sem
     LEFT JOIN class_assignments ca ON ca.semester_id = sem.id
     GROUP BY sem.id, sem.semester_name, sem.status, sem.created_at
     ORDER BY sem.id"
);
$semesters = $semesters_result ? mysqli_fetch_all($semesters_result, MYSQLI_ASSOC) : [];
$current_term = studylink_current_term($conn);
$term_records = studylink_academic_term_records($conn);

$term_class_count = 0;
if ($current_term) {
    $count_statement = mysqli_prepare(
        $conn,
        'SELECT COUNT(*) AS total
         FROM class_assignments
         WHERE academic_year_id = ? AND semester_id = ?'
    );
    if ($count_statement) {
        mysqli_stmt_bind_param(
            $count_statement,
            'ii',
            $current_term['academic_year_id'],
            $current_term['semester_id']
        );
        mysqli_stmt_execute($count_statement);
        $count_row = mysqli_fetch_assoc(mysqli_stmt_get_result($count_statement));
        $term_class_count = intval($count_row['total'] ?? 0);
        mysqli_stmt_close($count_statement);
    }
}

$archived_term_count = 0;
$available_term_count = 0;
foreach ($term_records as $term_record) {
    if ($term_record['status'] === 'archived') {
        $archived_term_count++;
    } elseif ($term_record['status'] === 'available') {
        $available_term_count++;
    }
}

render_admin_page_start(
    'academic_year',
    'Academic Term',
    'Academic Term',
    'Control which Academic Year and Semester the whole StudyLink system currently uses.',
    'Academic Setup'
);
render_admin_flash($flash);
?>

<section class="admin-term-overview">
    <div class="admin-term-current <?= $current_term ? '' : 'is-empty'; ?>">
        <div class="admin-term-current-head">
            <span class="admin-term-current-icon"><?= study_icon('calendar-range'); ?></span>
            <span class="admin-status-pill <?= $current_term ? 'active' : 'inactive'; ?>">
                <?= $current_term ? 'Current term' : 'Setup required'; ?>
            </span>
        </div>
        <div>
            <span class="admin-eyebrow">System-wide academic term</span>
            <h2>
                <?= $current_term
                    ? e($current_term['semester_name'])
                    : 'No current term'; ?>
            </h2>
            <p>
                <?= $current_term
                    ? 'Academic Year ' . e($current_term['school_year'])
                    : 'Choose an Academic Year and Semester to begin.'; ?>
            </p>
        </div>
        <div class="admin-term-current-stats">
            <span><strong><?= number_format($term_class_count); ?></strong><small>Current classes</small></span>
            <span><strong><?= number_format(count($term_records)); ?></strong><small>Recorded terms</small></span>
            <span><strong><?= number_format($archived_term_count); ?></strong><small>Archived</small></span>
        </div>
    </div>

    <section class="admin-card admin-term-setter">
        <div class="admin-section-head">
            <div>
                <span class="admin-eyebrow"><?= $current_term ? 'Change current term' : 'Initial setup'; ?></span>
                <h2>Select Academic Year and Semester</h2>
                <span>This pair becomes the default for Admin, Faculty, and Student.</span>
            </div>
        </div>

        <?php if (!$years || !$semesters): ?>
            <div class="admin-inline-warning">
                <?= study_icon('exclamation-triangle'); ?>
                <span>Add at least one Academic Year and one Semester under Term Options first.</span>
            </div>
        <?php endif; ?>

        <form class="admin-form" action="activate.php" method="POST"
              onsubmit="return confirm('Set the selected Academic Year and Semester as the current term? Historical class records will remain unchanged.');">
            <?= csrf_field(); ?>
            <div class="admin-form-grid two">
                <div class="admin-field">
                    <label for="academic_year_id">Academic Year</label>
                    <select id="academic_year_id" name="academic_year_id" required>
                        <option value="">Select academic year</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= e($year['id']); ?>"
                                <?= $current_term && intval($current_term['academic_year_id']) === intval($year['id']) ? 'selected' : ''; ?>>
                                <?= e($year['school_year']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="admin-field">
                    <label for="semester_id">Semester</label>
                    <select id="semester_id" name="semester_id" required>
                        <option value="">Select semester</option>
                        <?php foreach ($semesters as $semester): ?>
                            <option value="<?= e($semester['id']); ?>"
                                <?= $current_term && intval($current_term['semester_id']) === intval($semester['id']) ? 'selected' : ''; ?>>
                                <?= e($semester['semester_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="admin-term-setter-footer">
                <p><?= study_icon('info-circle'); ?> Existing classes and historical records will not be moved or deleted.</p>
                <button class="admin-button primary" type="submit" <?= !$years || !$semesters ? 'disabled' : ''; ?>>
                    <?= study_icon('check2-circle'); ?> Set as Current Term
                </button>
            </div>
        </form>
    </section>
</section>

<section class="admin-card admin-table-card admin-term-records" id="term-history">
    <div class="admin-section-head">
        <div>
            <span class="admin-eyebrow">Academic records</span>
            <h2>Term Records</h2>
            <span>Previous terms remain accessible. Archive a completed term to make it read-only.</span>
        </div>
        <div class="admin-term-summary-badges">
            <span class="admin-status-pill <?= $current_term ? 'active' : 'inactive'; ?>"><?= $current_term ? '1' : '0'; ?> current</span>
            <span class="admin-status-pill inactive"><?= number_format($available_term_count); ?> available</span>
            <span class="admin-status-pill archived"><?= number_format($archived_term_count); ?> archived</span>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-data-table">
            <thead>
                <tr>
                    <th>Academic term</th>
                    <th>Classes</th>
                    <th>Last used</th>
                    <th>Access</th>
                    <th class="admin-table-action">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$term_records): ?>
                <tr><td class="admin-empty-cell" colspan="5">No Academic Term has been recorded yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($term_records as $term_record): ?>
                <?php
                $term_status = $term_record['status'];
                $status_class = $term_status === 'current'
                    ? 'active'
                    : ($term_status === 'archived' ? 'archived' : 'inactive');
                $status_label = $term_status === 'current'
                    ? 'Current · editable'
                    : ($term_status === 'archived' ? 'Archived · read-only' : 'Available · read-only');
                ?>
                <tr class="<?= $term_status === 'current' ? 'admin-current-term-row' : ''; ?>">
                    <td>
                        <strong><?= e($term_record['semester_name']); ?></strong>
                        <small>Academic Year <?= e($term_record['school_year']); ?></small>
                    </td>
                    <td><span class="admin-count-badge"><?= number_format($term_record['class_count']); ?></span></td>
                    <td>
                        <?= $term_record['last_activated_at']
                            ? e(date('M j, Y', strtotime($term_record['last_activated_at'])))
                            : 'Not activated'; ?>
                        <?php if ($term_record['archived_at']): ?>
                            <small>Archived <?= e(date('M j, Y', strtotime($term_record['archived_at']))); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><span class="admin-status-pill <?= e($status_class); ?>"><?= e($status_label); ?></span></td>
                    <td class="admin-table-action">
                        <?php if ($term_status === 'current'): ?>
                            <span class="admin-protected-label"><?= study_icon('lock'); ?> In use</span>
                        <?php else: ?>
                            <form action="archive.php" method="POST"
                                  onsubmit="return confirm('<?= $term_status === 'archived'
                                      ? 'Restore this term to Available status? It will remain read-only until set as current.'
                                      : 'Archive this completed term? Faculty and Student will keep view access, but all academic changes will be blocked.'; ?>');">
                                <?= csrf_field(); ?>
                                <input type="hidden" name="academic_year_id" value="<?= e($term_record['academic_year_id']); ?>">
                                <input type="hidden" name="semester_id" value="<?= e($term_record['semester_id']); ?>">
                                <input type="hidden" name="term_action" value="<?= $term_status === 'archived' ? 'restore' : 'archive'; ?>">
                                <button class="admin-button <?= $term_status === 'archived' ? 'secondary' : 'danger'; ?> small" type="submit">
                                    <?= study_icon($term_status === 'archived' ? 'arrow-counterclockwise' : 'archive'); ?>
                                    <?= $term_status === 'archived' ? 'Restore' : 'Archive'; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="admin-term-readonly-note">
        <?= study_icon('shield-lock'); ?>
        <span><strong>Archived means view-only.</strong> Faculty and students can still view and download records, but cannot upload, submit, edit, grade, or mark attendance.</span>
    </div>
</section>

<section class="admin-term-options-section">
    <div class="admin-term-options-heading">
        <div>
            <span class="admin-eyebrow">Reusable setup</span>
            <h2>Term Options</h2>
            <p>Add a new Academic Year or Semester only when it does not exist yet.</p>
        </div>
    </div>

    <div class="admin-term-options-grid">
        <details class="admin-card admin-term-option" <?= !$years ? 'open' : ''; ?>>
            <summary>
                <span class="admin-term-option-icon"><?= study_icon('calendar3'); ?></span>
                <span><strong>Academic Years</strong><small><?= number_format(count($years)); ?> configured</small></span>
                <span class="admin-term-option-action">Manage</span>
            </summary>
            <div class="admin-term-option-body">
                <div class="admin-term-chip-list">
                    <?php if (!$years): ?><span class="admin-muted">No Academic Years configured.</span><?php endif; ?>
                    <?php foreach ($years as $year): ?>
                        <?php $is_current = $current_term && intval($current_term['academic_year_id']) === intval($year['id']); ?>
                        <span class="admin-term-chip <?= $is_current ? 'is-current' : ''; ?>">
                            <?= e($year['school_year']); ?>
                            <small><?= number_format($year['class_count']); ?> classes<?= $is_current ? ' · current' : ''; ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
                <form class="admin-form admin-term-add-form" action="add.php" method="POST">
                    <?= csrf_field(); ?>
                    <div class="admin-field">
                        <label for="school_year">Add Academic Year</label>
                        <input id="school_year" type="text" name="school_year" placeholder="Example: 2026-2027"
                               pattern="[0-9]{4}-[0-9]{4}" maxlength="9" required>
                        <small>The ending year must be exactly one year after the starting year.</small>
                    </div>
                    <button class="admin-button secondary" type="submit">
                        <?= study_icon('plus-lg'); ?> Add Year
                    </button>
                </form>
            </div>
        </details>

        <details class="admin-card admin-term-option" <?= !$semesters ? 'open' : ''; ?>>
            <summary>
                <span class="admin-term-option-icon"><?= study_icon('calendar2-week'); ?></span>
                <span><strong>Semesters</strong><small><?= number_format(count($semesters)); ?> configured</small></span>
                <span class="admin-term-option-action">Manage</span>
            </summary>
            <div class="admin-term-option-body">
                <div class="admin-term-chip-list">
                    <?php if (!$semesters): ?><span class="admin-muted">No Semesters configured.</span><?php endif; ?>
                    <?php foreach ($semesters as $semester): ?>
                        <?php $is_current = $current_term && intval($current_term['semester_id']) === intval($semester['id']); ?>
                        <span class="admin-term-chip <?= $is_current ? 'is-current' : ''; ?>">
                            <?= e($semester['semester_name']); ?>
                            <small><?= number_format($semester['class_count']); ?> classes<?= $is_current ? ' · current' : ''; ?></small>
                        </span>
                    <?php endforeach; ?>
                </div>
                <form class="admin-form admin-term-add-form" action="../semester/add.php" method="POST">
                    <?= csrf_field(); ?>
                    <div class="admin-field">
                        <label for="semester_name">Add Semester</label>
                        <select id="semester_name" name="semester_name" required>
                            <option value="">Select semester</option>
                            <option value="1st Semester">1st Semester</option>
                            <option value="2nd Semester">2nd Semester</option>
                            <option value="Summer">Summer</option>
                        </select>
                        <small>Each Semester option is created once and reused across Academic Years.</small>
                    </div>
                    <button class="admin-button secondary" type="submit">
                        <?= study_icon('plus-lg'); ?> Add Semester
                    </button>
                </form>
            </div>
        </details>
    </div>
</section>

<?php render_admin_page_end(); ?>
