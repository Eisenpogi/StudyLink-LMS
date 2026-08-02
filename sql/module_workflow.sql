-- StudyLink Module Workflow
-- Additive migration: existing assignments, quizzes, attempts, submissions, and grades are preserved.

CREATE TABLE IF NOT EXISTS course_modules (
  id INT NOT NULL AUTO_INCREMENT,
  faculty_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_course_modules_faculty (faculty_id),
  CONSTRAINT fk_course_modules_faculty FOREIGN KEY (faculty_id) REFERENCES faculty (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS course_module_classes (
  id INT NOT NULL AUTO_INCREMENT,
  module_id INT NOT NULL,
  class_assignment_id INT NOT NULL,
  class_order INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_module_class (module_id, class_assignment_id),
  KEY idx_module_classes_class (class_assignment_id),
  CONSTRAINT fk_module_classes_module FOREIGN KEY (module_id) REFERENCES course_modules (id) ON DELETE CASCADE,
  CONSTRAINT fk_module_classes_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS course_module_items (
  id INT NOT NULL AUTO_INCREMENT,
  module_id INT NOT NULL,
  item_type ENUM('activity','quiz','exam') NOT NULL,
  title VARCHAR(255) NOT NULL,
  instructions TEXT DEFAULT NULL,
  legacy_assignment_id INT DEFAULT NULL,
  legacy_quiz_id INT DEFAULT NULL,
  display_order INT NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_module_item_legacy_assignment (legacy_assignment_id),
  UNIQUE KEY uq_module_item_legacy_quiz (legacy_quiz_id),
  KEY idx_module_items_module_order (module_id, display_order),
  CONSTRAINT fk_module_items_module FOREIGN KEY (module_id) REFERENCES course_modules (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS course_module_resources (
  id INT NOT NULL AUTO_INCREMENT,
  module_item_id INT NOT NULL,
  class_assignment_id INT NOT NULL,
  assignment_id INT DEFAULT NULL,
  quiz_id INT DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_module_item_class (module_item_id, class_assignment_id),
  UNIQUE KEY uq_module_assignment (assignment_id),
  UNIQUE KEY uq_module_quiz (quiz_id),
  KEY idx_module_resources_class (class_assignment_id),
  CONSTRAINT fk_module_resources_item FOREIGN KEY (module_item_id) REFERENCES course_module_items (id) ON DELETE CASCADE,
  CONSTRAINT fk_module_resources_class FOREIGN KEY (class_assignment_id) REFERENCES class_assignments (id) ON DELETE CASCADE,
  CONSTRAINT fk_module_resources_assignment FOREIGN KEY (assignment_id) REFERENCES assignments (id) ON DELETE CASCADE,
  CONSTRAINT fk_module_resources_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes (id) ON DELETE CASCADE,
  CONSTRAINT chk_module_resource CHECK (
    (assignment_id IS NOT NULL AND quiz_id IS NULL) OR
    (assignment_id IS NULL AND quiz_id IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Put pre-existing coursework into one published compatibility module per class.
INSERT INTO course_modules (faculty_id, title, description, status)
SELECT ca.faculty_id, CONCAT('Existing Coursework · Class ', ca.id),
       'Assignments and quizzes created before the Modules update.', 'published'
FROM class_assignments ca
WHERE (EXISTS (SELECT 1 FROM assignments a WHERE a.class_assignment_id = ca.id)
    OR EXISTS (SELECT 1 FROM quizzes q WHERE q.class_assignment_id = ca.id))
  AND NOT EXISTS (
    SELECT 1 FROM course_module_classes cmc
    INNER JOIN course_modules cm ON cm.id = cmc.module_id
    WHERE cmc.class_assignment_id = ca.id
      AND cm.title = CONCAT('Existing Coursework · Class ', ca.id)
  );

INSERT IGNORE INTO course_module_classes (module_id, class_assignment_id, class_order)
SELECT cm.id, ca.id, 1
FROM class_assignments ca
INNER JOIN course_modules cm ON cm.faculty_id = ca.faculty_id
 AND cm.title = CONCAT('Existing Coursework · Class ', ca.id);

INSERT INTO course_module_items (module_id, item_type, title, instructions, legacy_assignment_id, display_order)
SELECT cmc.module_id, 'activity', a.title, a.instructions, a.id,
       1000 + ROW_NUMBER() OVER (PARTITION BY cmc.module_id ORDER BY a.created_at, a.id)
FROM assignments a
INNER JOIN course_module_classes cmc ON cmc.class_assignment_id = a.class_assignment_id
INNER JOIN course_modules cm ON cm.id = cmc.module_id
WHERE cm.title = CONCAT('Existing Coursework · Class ', a.class_assignment_id)
  AND NOT EXISTS (SELECT 1 FROM course_module_resources cmr WHERE cmr.assignment_id = a.id);

INSERT INTO course_module_resources (module_item_id, class_assignment_id, assignment_id, quiz_id)
SELECT cmi.id, a.class_assignment_id, a.id, NULL
FROM assignments a
INNER JOIN course_module_classes cmc ON cmc.class_assignment_id = a.class_assignment_id
INNER JOIN course_modules cm ON cm.id = cmc.module_id
INNER JOIN course_module_items cmi ON cmi.legacy_assignment_id = a.id
WHERE cm.title = CONCAT('Existing Coursework · Class ', a.class_assignment_id)
  AND NOT EXISTS (SELECT 1 FROM course_module_resources cmr WHERE cmr.assignment_id = a.id);

INSERT INTO course_module_items (module_id, item_type, title, instructions, legacy_quiz_id, display_order)
SELECT cmc.module_id, 'quiz', q.title, q.instructions, q.id,
       2000 + ROW_NUMBER() OVER (PARTITION BY cmc.module_id ORDER BY q.created_at, q.id)
FROM quizzes q
INNER JOIN course_module_classes cmc ON cmc.class_assignment_id = q.class_assignment_id
INNER JOIN course_modules cm ON cm.id = cmc.module_id
WHERE cm.title = CONCAT('Existing Coursework · Class ', q.class_assignment_id)
  AND NOT EXISTS (SELECT 1 FROM course_module_resources cmr WHERE cmr.quiz_id = q.id);

INSERT INTO course_module_resources (module_item_id, class_assignment_id, assignment_id, quiz_id)
SELECT cmi.id, q.class_assignment_id, NULL, q.id
FROM quizzes q
INNER JOIN course_module_classes cmc ON cmc.class_assignment_id = q.class_assignment_id
INNER JOIN course_modules cm ON cm.id = cmc.module_id
INNER JOIN course_module_items cmi ON cmi.legacy_quiz_id = q.id
WHERE cm.title = CONCAT('Existing Coursework · Class ', q.class_assignment_id)
  AND NOT EXISTS (SELECT 1 FROM course_module_resources cmr WHERE cmr.quiz_id = q.id);
