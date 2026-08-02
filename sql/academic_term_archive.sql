CREATE TABLE IF NOT EXISTS academic_terms (
    id INT NOT NULL AUTO_INCREMENT,
    academic_year_id INT NOT NULL,
    semester_id INT NOT NULL,
    status ENUM('available', 'current', 'archived') NOT NULL DEFAULT 'available',
    first_activated_at DATETIME NULL,
    last_activated_at DATETIME NULL,
    archived_at DATETIME NULL,
    activated_by INT NULL,
    archived_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY academic_terms_unique_pair (academic_year_id, semester_id),
    KEY academic_terms_status_index (status),
    CONSTRAINT academic_terms_year_fk
        FOREIGN KEY (academic_year_id) REFERENCES academic_years (id),
    CONSTRAINT academic_terms_semester_fk
        FOREIGN KEY (semester_id) REFERENCES semesters (id),
    CONSTRAINT academic_terms_activated_by_fk
        FOREIGN KEY (activated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT academic_terms_archived_by_fk
        FOREIGN KEY (archived_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO academic_terms (academic_year_id, semester_id, status)
SELECT DISTINCT academic_year_id, semester_id, 'available'
FROM class_assignments;

INSERT INTO academic_terms (
    academic_year_id,
    semester_id,
    status,
    first_activated_at,
    last_activated_at
)
SELECT ay.id, sem.id, 'current', NOW(), NOW()
FROM academic_years ay
CROSS JOIN semesters sem
WHERE ay.status = 'active' AND sem.status = 'active'
ORDER BY ay.id DESC, sem.id DESC
LIMIT 1
ON DUPLICATE KEY UPDATE
    status = 'current',
    first_activated_at = COALESCE(first_activated_at, NOW()),
    last_activated_at = NOW(),
    archived_at = NULL,
    archived_by = NULL;
