-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 28, 2026 at 04:58 PM
-- Server version: 10.4.27-MariaDB
-- PHP Version: 8.2.0

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `studylink_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL,
  `school_year` varchar(20) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'inactive',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `academic_years`
--

INSERT INTO `academic_years` (`id`, `school_year`, `status`, `created_at`) VALUES
(1, '2028-2029', 'inactive', '2026-06-02 05:26:26'),
(2, '2027-2028', 'inactive', '2026-06-02 05:52:27'),
(3, '2026-2027', 'active', '2026-06-02 05:53:53');

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `id` int(11) NOT NULL,
  `class_assignment_id` int(11) NOT NULL,
  `faculty_drive_item_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `instructions` text DEFAULT NULL,
  `due_date` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`id`, `class_assignment_id`, `faculty_drive_item_id`, `title`, `instructions`, `due_date`, `created_at`) VALUES
(10, 5, 5, 'sample assignment', '', '2026-07-30 02:44:00', '2026-07-27 18:44:49'),
(11, 5, 13, 'sample 2 assignment', '', '2026-07-31 02:44:00', '2026-07-27 18:45:55');

-- --------------------------------------------------------

--
-- Table structure for table `assignment_submissions`
--

CREATE TABLE `assignment_submissions` (
  `id` int(11) NOT NULL,
  `assignment_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT NULL,
  `mime_type` varchar(150) DEFAULT NULL,
  `student_comment` varchar(1000) DEFAULT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `submission_status` enum('on_time','late') NOT NULL DEFAULT 'on_time',
  `score` decimal(5,2) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment_submissions`
--

INSERT INTO `assignment_submissions` (`id`, `assignment_id`, `student_id`, `file_path`, `file_name`, `file_size`, `mime_type`, `student_comment`, `submitted_at`, `submission_status`, `score`, `remarks`, `graded_at`, `graded_by`) VALUES
(2, 10, 4, 'assets/uploads/submissions/10/4/6a52540e1abacb0431f95eb6162507d5.docx', 'sample assignment.docx', 12323, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', '', '2026-07-28 14:53:35', 'on_time', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `attendance_session_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('present','late','absent','excused') NOT NULL DEFAULT 'present',
  `remarks` varchar(255) DEFAULT NULL,
  `marked_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_sessions`
--

CREATE TABLE `attendance_sessions` (
  `id` int(11) NOT NULL,
  `class_assignment_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `session_title` varchar(120) NOT NULL DEFAULT 'Class Session',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `class_assignments`
--

CREATE TABLE `class_assignments` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester_id` int(11) NOT NULL,
  `cover_image` varchar(500) DEFAULT NULL,
  `cover_color` varchar(7) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class_assignments`
--

INSERT INTO `class_assignments` (`id`, `faculty_id`, `subject_id`, `section_id`, `academic_year_id`, `semester_id`, `cover_image`, `cover_color`, `created_at`) VALUES
(5, 1, 1, 2, 1, 1, NULL, '#4f627c', '2026-06-02 07:19:26'),
(6, 2, 2, 2, 1, 1, NULL, NULL, '2026-07-08 13:51:27');

-- --------------------------------------------------------

--
-- Table structure for table `faculty`
--

CREATE TABLE `faculty` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `faculty_id` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty`
--

INSERT INTO `faculty` (`id`, `user_id`, `faculty_id`, `created_at`) VALUES
(1, 2, 'F-001', '2026-06-02 06:36:02'),
(2, 5, 'F-002', '2026-06-22 15:15:17');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_drive_folders`
--

CREATE TABLE `faculty_drive_folders` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `folder_name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty_drive_folders`
--

INSERT INTO `faculty_drive_folders` (`id`, `faculty_id`, `parent_id`, `folder_name`, `created_at`, `updated_at`) VALUES
(1, 1, NULL, 'eisen', '2026-07-22 17:43:32', '2026-07-22 17:43:32'),
(2, 1, 1, 'gege', '2026-07-22 17:43:43', '2026-07-22 17:43:43');

-- --------------------------------------------------------

--
-- Table structure for table `faculty_drive_items`
--

CREATE TABLE `faculty_drive_items` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `folder_id` int(11) DEFAULT NULL,
  `item_type` enum('quiz','file','material','assignment') NOT NULL,
  `storage_scope` enum('library','module') NOT NULL DEFAULT 'library',
  `reference_id` int(11) DEFAULT NULL,
  `item_name` varchar(255) NOT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `original_filename` varchar(255) DEFAULT NULL,
  `mime_type` varchar(150) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `faculty_drive_items`
--

INSERT INTO `faculty_drive_items` (`id`, `faculty_id`, `folder_id`, `item_type`, `storage_scope`, `reference_id`, `item_name`, `file_path`, `original_filename`, `mime_type`, `file_size`, `created_at`, `updated_at`) VALUES
(5, 1, 2, 'file', 'library', NULL, 'OLSS.docx', '/studyLink/assets/uploads/faculty_drive/1/029a64cac97a967956c068bfe2d148c1.docx', 'OLSS.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 15265, '2026-07-23 16:47:17', '2026-07-23 16:47:17'),
(12, 1, NULL, 'file', 'module', NULL, 'Upload materials2.pdf', '/studyLink/assets/uploads/faculty_drive/1/81cdcc2583bd38ba67b2ab13770634ee.pdf', 'Upload materials2.pdf', 'application/pdf', 15903, '2026-07-27 18:44:04', '2026-07-27 18:44:04'),
(13, 1, NULL, 'file', 'module', NULL, 'sample assignment.pdf', '/studyLink/assets/uploads/faculty_drive/1/800cfdacfec025301c8e7acfd1adf694.pdf', 'sample assignment.pdf', 'application/pdf', 15903, '2026-07-27 18:45:55', '2026-07-27 18:45:55');

-- --------------------------------------------------------

--
-- Table structure for table `learning_materials`
--

CREATE TABLE `learning_materials` (
  `id` int(11) NOT NULL,
  `class_assignment_id` int(11) NOT NULL,
  `faculty_drive_item_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `learning_materials`
--

INSERT INTO `learning_materials` (`id`, `class_assignment_id`, `faculty_drive_item_id`, `title`, `description`, `file_name`, `created_at`) VALUES
(5, 5, 12, 'sample learning material', '', 'Upload materials2.pdf', '2026-07-27 18:44:04');

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `subject` varchar(150) NOT NULL,
  `message_body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_key` varchar(190) NOT NULL,
  `notification_type` enum('assignment','quiz','material','grade','deadline','system') NOT NULL DEFAULT 'system',
  `title` varchar(255) NOT NULL,
  `message` varchar(500) NOT NULL,
  `target_url` varchar(500) DEFAULT NULL,
  `priority` enum('normal','important') NOT NULL DEFAULT 'normal',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `event_key`, `notification_type`, `title`, `message`, `target_url`, `priority`, `is_read`, `created_at`, `read_at`) VALUES
(1, 7, 'assignment:new:10', 'assignment', 'New assignment: sample assignment', 'IT101 · Due Jul 30, 2026 at 2:44 AM', '/studyLink/student/class_view.php?id=5&tab=assignments', 'important', 1, '2026-07-28 02:44:49', '2026-07-28 22:51:17'),
(2, 7, 'assignment:new:11', 'assignment', 'New assignment: sample 2 assignment', 'IT101 · Due Jul 31, 2026 at 2:44 AM', '/studyLink/student/class_view.php?id=5&tab=assignments', 'important', 0, '2026-07-28 02:45:55', NULL),
(4, 7, 'material:new:5', 'material', 'New learning material: sample learning material', 'IT101 · Added by your faculty', '/studyLink/student/class_view.php?id=5&tab=materials', 'normal', 0, '2026-07-28 02:44:04', NULL),
(5, 7, 'quiz:published:13', 'quiz', 'Quiz available: Sample Quiz #1', 'IT101 · Closes Jul 31 at 1:59 AM', '/studyLink/student/quizzes.php', 'normal', 1, '2026-07-28 01:59:16', '2026-07-28 22:51:02'),
(6, 7, 'assignment:due:11', 'deadline', 'Assignment due soon: sample 2 assignment', 'IT101 · Due Jul 31 at 2:44 AM', '/studyLink/student/class_view.php?id=5&tab=assignments', 'important', 1, '2026-07-28 20:38:07', '2026-07-28 20:39:00');

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `class_assignment_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `instructions` text DEFAULT NULL,
  `time_limit` int(11) DEFAULT 0,
  `available_from` datetime DEFAULT NULL,
  `available_until` datetime DEFAULT NULL,
  `passing_score` int(11) DEFAULT 0,
  `max_attempts` int(11) DEFAULT 1,
  `shuffle_questions` tinyint(1) DEFAULT 0,
  `status` enum('draft','published') DEFAULT 'draft',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quizzes`
--

INSERT INTO `quizzes` (`id`, `class_assignment_id`, `title`, `instructions`, `time_limit`, `available_from`, `available_until`, `passing_score`, `max_attempts`, `shuffle_questions`, `status`, `created_at`) VALUES
(13, 5, 'Sample Quiz #1', '', 10, '2026-07-28 01:59:00', '2026-07-31 01:59:00', 70, 1, 1, 'published', '2026-07-27 17:59:16');

-- --------------------------------------------------------

--
-- Table structure for table `quiz_answers`
--

CREATE TABLE `quiz_answers` (
  `id` int(11) NOT NULL,
  `attempt_id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_choice_id` int(11) DEFAULT NULL,
  `answer_text` text DEFAULT NULL,
  `auto_score` decimal(8,2) DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `ai_score` decimal(5,2) DEFAULT NULL,
  `ai_feedback` text DEFAULT NULL,
  `final_score` decimal(5,2) DEFAULT NULL,
  `faculty_feedback` text DEFAULT NULL,
  `checked_by_faculty` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_attempts`
--

CREATE TABLE `quiz_attempts` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `attempt_no` int(11) NOT NULL DEFAULT 1,
  `started_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `question_order` longtext DEFAULT NULL,
  `status` enum('in_progress','submitted','needs_review','graded') NOT NULL DEFAULT 'submitted',
  `score` decimal(5,2) DEFAULT 0.00,
  `total_points` decimal(8,2) NOT NULL DEFAULT 0.00,
  `percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `is_passed` tinyint(1) NOT NULL DEFAULT 0,
  `submitted_at` datetime DEFAULT NULL,
  `graded_at` datetime DEFAULT NULL,
  `graded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_choices`
--

CREATE TABLE `quiz_choices` (
  `id` int(11) NOT NULL,
  `question_id` int(11) NOT NULL,
  `choice_text` varchar(255) NOT NULL,
  `choice_order` int(11) DEFAULT 1,
  `is_correct` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quiz_choices`
--

INSERT INTO `quiz_choices` (`id`, `question_id`, `choice_text`, `choice_order`, `is_correct`) VALUES
(110, 64, 'HAPPYBYTE', 1, 0),
(111, 64, 'HYPER TEXT MARKUP LANGUAGE', 2, 1),
(112, 64, 'HATDOG', 3, 0),
(113, 64, 'HATAMALA', 4, 0),
(126, 67, 'True', 1, 1),
(127, 67, 'False', 2, 0);

-- --------------------------------------------------------

--
-- Table structure for table `quiz_library_choices`
--

CREATE TABLE `quiz_library_choices` (
  `id` int(11) NOT NULL,
  `library_question_id` int(11) NOT NULL,
  `choice_text` text NOT NULL,
  `choice_order` int(11) DEFAULT 1,
  `is_correct` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_library_questions`
--

CREATE TABLE `quiz_library_questions` (
  `id` int(11) NOT NULL,
  `library_quiz_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('multiple_choice','true_false','identification','enumeration','essay') NOT NULL,
  `points` decimal(5,2) DEFAULT 1.00,
  `correct_answer` text DEFAULT NULL,
  `order_no` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_library_quizzes`
--

CREATE TABLE `quiz_library_quizzes` (
  `id` int(11) NOT NULL,
  `faculty_id` int(11) NOT NULL,
  `source_quiz_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `instructions` text DEFAULT NULL,
  `time_limit` int(11) DEFAULT 0,
  `max_attempts` int(11) DEFAULT 1,
  `passing_score` decimal(5,2) DEFAULT 0.00,
  `available_from` datetime DEFAULT NULL,
  `available_until` datetime DEFAULT NULL,
  `shuffle_questions` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quiz_questions`
--

CREATE TABLE `quiz_questions` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('multiple_choice','true_false','identification','enumeration','essay') NOT NULL,
  `points` decimal(5,2) DEFAULT 1.00,
  `correct_answer` text DEFAULT NULL,
  `order_no` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `quiz_questions`
--

INSERT INTO `quiz_questions` (`id`, `quiz_id`, `question_text`, `question_type`, `points`, `correct_answer`, `order_no`) VALUES
(64, 13, 'HTML stands for?', 'multiple_choice', '1.00', 'HYPER TEXT MARKUP LANGUAGE', 1),
(66, 13, 'explain programming ', 'essay', '5.00', '', 3),
(67, 13, 'POGI BA SI EISEN?', 'true_false', '1.00', 'True', 4),
(68, 13, 'CPU means?', 'identification', '1.00', 'Central Processing unit ', 5),
(69, 13, 'two major programming languages', 'enumeration', '2.00', 'JAVA, C++', 6);

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `section_name` varchar(50) NOT NULL,
  `course` varchar(50) NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `section_name`, `course`, `year_level`, `created_at`) VALUES
(2, 'BSIT 1A', 'BSIT', '3', '2026-06-02 06:25:35');

-- --------------------------------------------------------

--
-- Table structure for table `semesters`
--

CREATE TABLE `semesters` (
  `id` int(11) NOT NULL,
  `semester_name` varchar(50) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'inactive',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `semesters`
--

INSERT INTO `semesters` (`id`, `semester_name`, `status`, `created_at`) VALUES
(1, '1st semester', 'inactive', '2026-06-02 05:51:19'),
(2, '2nd semester', 'active', '2026-06-02 05:51:29'),
(3, 'summer', 'inactive', '2026-06-02 05:51:39');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `student_no` varchar(50) NOT NULL,
  `section_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `user_id`, `student_no`, `section_id`, `created_at`) VALUES
(2, 4, 'S21-0380', 2, '2026-06-02 06:52:16'),
(3, 6, 'S21-0381', 2, '2026-06-22 15:16:02'),
(4, 7, 's23-7957', 2, '2026-07-24 09:04:17');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `subject_code`, `subject_name`, `description`, `created_at`) VALUES
(1, 'IT101', 'Introduction to Computing ', 'Basic Concepts of computing', '2026-06-02 06:21:19'),
(2, 'IT102', 'Math ', '...', '2026-06-02 06:58:52');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','faculty','student') NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `fullname`, `username`, `password`, `role`, `status`, `created_at`) VALUES
(1, 'System Administrator', 'admin', '$2y$10$.ahc4mN/hb4bUkKCzyBSx.o9RtRlYFt0unUMtZMPVDOy9tTiHJAca', 'admin', 'active', '2026-06-02 05:16:25'),
(2, 'ron dela cruz', 'ronald', '$2y$10$8n5q8QjqAMuv4D.q0vdwxuvwCXOIX6129XoVAQddHGTjb.U1AMv9e', 'faculty', 'active', '2026-06-02 06:36:02'),
(4, 'eisen hower longcop', 'eisen', 'Bakadinaman#1', 'student', 'active', '2026-06-02 06:52:16'),
(5, 'jharold ', 'jha ', '12345', 'faculty', 'active', '2026-06-22 15:15:17'),
(6, 'james', 'james ', '$2y$10$70q2952DUqK5Z3zXLiXzhuFv2O6a8xu0nvT3OpTZcZGmoTD.o0pQq', 'student', 'active', '2026-06-22 15:16:02'),
(7, 'kc', 'kc', '$2y$10$6FnH49JSjfUI72w7AIJPyOB6EW1ZapMd8E3xajMQgV4PBAS9sSRSG', 'student', 'active', '2026-07-24 09:04:17');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_assignment_id` (`class_assignment_id`),
  ADD KEY `fk_assignment_drive_item` (`faculty_drive_item_id`);

--
-- Indexes for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_assignment_student` (`assignment_id`,`student_id`),
  ADD KEY `assignment_id` (`assignment_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_submission_student` (`student_id`),
  ADD KEY `idx_submission_status` (`submission_status`),
  ADD KEY `idx_submission_graded_by` (`graded_by`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attendance_session_student` (`attendance_session_id`,`student_id`),
  ADD KEY `idx_attendance_student` (`student_id`),
  ADD KEY `idx_attendance_status` (`status`);

--
-- Indexes for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attendance_class_date` (`class_assignment_id`,`attendance_date`),
  ADD KEY `idx_attendance_created_by` (`created_by`);

--
-- Indexes for table `class_assignments`
--
ALTER TABLE `class_assignments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `subject_id` (`subject_id`),
  ADD KEY `section_id` (`section_id`),
  ADD KEY `academic_year_id` (`academic_year_id`),
  ADD KEY `semester_id` (`semester_id`);

--
-- Indexes for table `faculty`
--
ALTER TABLE `faculty`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `faculty_drive_folders`
--
ALTER TABLE `faculty_drive_folders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `faculty_drive_items`
--
ALTER TABLE `faculty_drive_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_faculty_drive_reference` (`faculty_id`,`item_type`,`reference_id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `folder_id` (`folder_id`),
  ADD KEY `item_type` (`item_type`),
  ADD KEY `reference_id` (`reference_id`);

--
-- Indexes for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_assignment_id` (`class_assignment_id`),
  ADD KEY `fk_learning_material_drive_item` (`faculty_drive_item_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_messages_sender` (`sender_id`,`created_at`),
  ADD KEY `idx_messages_recipient` (`recipient_id`,`is_read`,`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_notification_event` (`user_id`,`event_key`),
  ADD KEY `idx_notification_inbox` (`user_id`,`is_read`,`created_at`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `class_assignment_id` (`class_assignment_id`);

--
-- Indexes for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_attempt_question` (`attempt_id`,`question_id`),
  ADD KEY `attempt_id` (`attempt_id`),
  ADD KEY `question_id` (`question_id`),
  ADD KEY `idx_selected_choice` (`selected_choice_id`);

--
-- Indexes for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_quiz_student_attempt` (`quiz_id`,`student_id`,`attempt_no`),
  ADD KEY `quiz_id` (`quiz_id`),
  ADD KEY `student_id` (`student_id`),
  ADD KEY `idx_quiz_attempt_status` (`quiz_id`,`status`),
  ADD KEY `idx_student_attempt_status` (`student_id`,`status`),
  ADD KEY `idx_quiz_attempt_graded_by` (`graded_by`);

--
-- Indexes for table `quiz_choices`
--
ALTER TABLE `quiz_choices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `question_id` (`question_id`);

--
-- Indexes for table `quiz_library_choices`
--
ALTER TABLE `quiz_library_choices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `library_question_id` (`library_question_id`);

--
-- Indexes for table `quiz_library_questions`
--
ALTER TABLE `quiz_library_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `library_quiz_id` (`library_quiz_id`);

--
-- Indexes for table `quiz_library_quizzes`
--
ALTER TABLE `quiz_library_quizzes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `faculty_id` (`faculty_id`),
  ADD KEY `source_quiz_id` (`source_quiz_id`);

--
-- Indexes for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `quiz_id` (`quiz_id`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `semesters`
--
ALTER TABLE `semesters`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `section_id` (`section_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `class_assignments`
--
ALTER TABLE `class_assignments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `faculty`
--
ALTER TABLE `faculty`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `faculty_drive_folders`
--
ALTER TABLE `faculty_drive_folders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `faculty_drive_items`
--
ALTER TABLE `faculty_drive_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `learning_materials`
--
ALTER TABLE `learning_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=135;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_choices`
--
ALTER TABLE `quiz_choices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=132;

--
-- AUTO_INCREMENT for table `quiz_library_choices`
--
ALTER TABLE `quiz_library_choices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_library_questions`
--
ALTER TABLE `quiz_library_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_library_quizzes`
--
ALTER TABLE `quiz_library_quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `semesters`
--
ALTER TABLE `semesters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `assignments_ibfk_1` FOREIGN KEY (`class_assignment_id`) REFERENCES `class_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_assignment_drive_item` FOREIGN KEY (`faculty_drive_item_id`) REFERENCES `faculty_drive_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `assignment_submissions`
--
ALTER TABLE `assignment_submissions`
  ADD CONSTRAINT `assignment_submissions_ibfk_1` FOREIGN KEY (`assignment_id`) REFERENCES `assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `assignment_submissions_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_submission_graded_by` FOREIGN KEY (`graded_by`) REFERENCES `faculty` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `attendance_records_fk_session` FOREIGN KEY (`attendance_session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_records_fk_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `attendance_sessions`
--
ALTER TABLE `attendance_sessions`
  ADD CONSTRAINT `attendance_sessions_fk_class` FOREIGN KEY (`class_assignment_id`) REFERENCES `class_assignments` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `attendance_sessions_fk_faculty` FOREIGN KEY (`created_by`) REFERENCES `faculty` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `class_assignments`
--
ALTER TABLE `class_assignments`
  ADD CONSTRAINT `class_assignments_ibfk_1` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`),
  ADD CONSTRAINT `class_assignments_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`),
  ADD CONSTRAINT `class_assignments_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`),
  ADD CONSTRAINT `class_assignments_ibfk_4` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`),
  ADD CONSTRAINT `class_assignments_ibfk_5` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`);

--
-- Constraints for table `faculty`
--
ALTER TABLE `faculty`
  ADD CONSTRAINT `faculty_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `faculty_drive_folders`
--
ALTER TABLE `faculty_drive_folders`
  ADD CONSTRAINT `faculty_drive_folders_fk_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `faculty_drive_folders_fk_parent` FOREIGN KEY (`parent_id`) REFERENCES `faculty_drive_folders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `faculty_drive_items`
--
ALTER TABLE `faculty_drive_items`
  ADD CONSTRAINT `faculty_drive_items_fk_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `faculty_drive_items_fk_folder` FOREIGN KEY (`folder_id`) REFERENCES `faculty_drive_folders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `learning_materials`
--
ALTER TABLE `learning_materials`
  ADD CONSTRAINT `fk_learning_material_drive_item` FOREIGN KEY (`faculty_drive_item_id`) REFERENCES `faculty_drive_items` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `learning_materials_ibfk_1` FOREIGN KEY (`class_assignment_id`) REFERENCES `class_assignments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_fk_recipient` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `messages_fk_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_fk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD CONSTRAINT `quizzes_ibfk_1` FOREIGN KEY (`class_assignment_id`) REFERENCES `class_assignments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_answers`
--
ALTER TABLE `quiz_answers`
  ADD CONSTRAINT `quiz_answers_fk_selected_choice` FOREIGN KEY (`selected_choice_id`) REFERENCES `quiz_choices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `quiz_answers_ibfk_1` FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quiz_answers_ibfk_2` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_attempts`
--
ALTER TABLE `quiz_attempts`
  ADD CONSTRAINT `quiz_attempts_fk_graded_by` FOREIGN KEY (`graded_by`) REFERENCES `faculty` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `quiz_attempts_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quiz_attempts_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_choices`
--
ALTER TABLE `quiz_choices`
  ADD CONSTRAINT `quiz_choices_ibfk_1` FOREIGN KEY (`question_id`) REFERENCES `quiz_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_library_choices`
--
ALTER TABLE `quiz_library_choices`
  ADD CONSTRAINT `quiz_library_choices_fk_question` FOREIGN KEY (`library_question_id`) REFERENCES `quiz_library_questions` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_library_questions`
--
ALTER TABLE `quiz_library_questions`
  ADD CONSTRAINT `quiz_library_questions_fk_quiz` FOREIGN KEY (`library_quiz_id`) REFERENCES `quiz_library_quizzes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quiz_library_quizzes`
--
ALTER TABLE `quiz_library_quizzes`
  ADD CONSTRAINT `quiz_library_quizzes_fk_faculty` FOREIGN KEY (`faculty_id`) REFERENCES `faculty` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `quiz_library_quizzes_fk_source` FOREIGN KEY (`source_quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `quiz_questions`
--
ALTER TABLE `quiz_questions`
  ADD CONSTRAINT `quiz_questions_ibfk_1` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
