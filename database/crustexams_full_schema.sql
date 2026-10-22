-- ============================================================
-- CrustExams — Full Database Schema
-- ============================================================
-- This is the ONE file you need to set up the database from scratch.
-- It already includes everything that was built up across development
-- (courses/branches, teacher profiles, the CET/subject restructure,
-- student attempts, and student profiles) — you do NOT need to run
-- any of the old migration_*.sql files separately.
--
-- HOW TO USE (XAMPP + phpMyAdmin):
--   1. Start Apache and MySQL in XAMPP.
--   2. Open http://localhost/phpmyadmin
--   3. Click "Import" → choose this file → Go.
--      (This creates the `crustexams` database and every table, empty.)
--   4. Update db.php with your MySQL credentials if they differ from
--      the XAMPP defaults (user "root", no password).
-- ============================================================

CREATE DATABASE IF NOT EXISTS crustexams
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE crustexams;

-- ------------------------------------------------------------
-- Colleges — created when a College Admin registers
-- ------------------------------------------------------------
CREATE TABLE colleges (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_name VARCHAR(150) NOT NULL,
  college_code VARCHAR(50) DEFAULT NULL,
  phone VARCHAR(20) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- College Admins — one per college, created at registration
-- ------------------------------------------------------------
CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Courses — e.g. B.C.A, B.Tech, B.Sc — created by the College Admin
-- ------------------------------------------------------------
CREATE TABLE courses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  course_name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Branches — e.g. "AI & DS" or "Automobile" under B.Tech
-- ------------------------------------------------------------
CREATE TABLE branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  course_id INT NOT NULL,
  branch_name VARCHAR(100) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Teachers — added by a College Admin (not self-registered)
-- ------------------------------------------------------------
CREATE TABLE teachers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  added_by_admin_id INT DEFAULT NULL,
  full_name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  profile_pic VARCHAR(255) DEFAULT NULL,
  banner_image VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Students — added by a College Admin, optionally assigned a
-- course + branch, which decides which CETs they can see.
-- ------------------------------------------------------------
CREATE TABLE students (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  course_id INT DEFAULT NULL,
  branch_id INT DEFAULT NULL,
  added_by_admin_id INT DEFAULT NULL,
  full_name VARCHAR(100) NOT NULL,
  roll_number VARCHAR(50) NOT NULL,
  email VARCHAR(150) DEFAULT NULL,
  profile_pic VARCHAR(255) DEFAULT NULL,
  banner_image VARCHAR(255) DEFAULT NULL,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Subjects — created by a teacher, scoped to a course (+ optional
-- branch). The same subject name can exist separately per branch,
-- since each row is tied to one course+branch combination.
-- ------------------------------------------------------------
CREATE TABLE subjects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  course_id INT NOT NULL,
  branch_id INT DEFAULT NULL,
  subject_name VARCHAR(100) NOT NULL,
  created_by_teacher_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_teacher_id) REFERENCES teachers(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- CETs — one exam, tied to a course + branch (the batch of students
-- it's for). Its questions can each belong to a different subject,
-- so one CET can mix Maths + English + Chemistry + etc.
-- status: 'draft' while being built, 'assigned' once live for students.
-- ------------------------------------------------------------
CREATE TABLE cets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  college_id INT NOT NULL,
  teacher_id INT NOT NULL,
  course_id INT NOT NULL,
  branch_id INT DEFAULT NULL,
  title VARCHAR(150) NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 30,
  status ENUM('draft','assigned') NOT NULL DEFAULT 'draft',
  assigned_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE CASCADE,
  FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
  FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Questions — MCQs belonging to a CET. Each carries its OWN
-- subject, an optional image, and the correct answer (never sent
-- to the student's browser while they're taking the exam).
-- ------------------------------------------------------------
CREATE TABLE questions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cet_id INT NOT NULL,
  subject_id INT NOT NULL,
  question_text TEXT NOT NULL,
  option_a VARCHAR(255) NOT NULL,
  option_b VARCHAR(255) NOT NULL,
  option_c VARCHAR(255) NOT NULL,
  option_d VARCHAR(255) NOT NULL,
  correct_option ENUM('a','b','c','d') NOT NULL,
  marks INT NOT NULL DEFAULT 1,
  image_path VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cet_id) REFERENCES cets(id) ON DELETE CASCADE,
  FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Attempts — one row per student per CET they've started/finished.
-- The score stays hidden from the student until a teacher declares
-- results for that CET (all students at once).
-- ------------------------------------------------------------
CREATE TABLE attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cet_id INT NOT NULL,
  student_id INT NOT NULL,
  status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
  score INT DEFAULT NULL,
  total_marks INT DEFAULT NULL,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  submitted_at TIMESTAMP NULL DEFAULT NULL,
  declared TINYINT(1) NOT NULL DEFAULT 0,
  declared_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uniq_attempt (cet_id, student_id),
  FOREIGN KEY (cet_id) REFERENCES cets(id) ON DELETE CASCADE,
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Attempt Answers — exactly what a student selected per question,
-- and whether it was correct, graded server-side at submit time.
-- ------------------------------------------------------------
CREATE TABLE attempt_answers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  attempt_id INT NOT NULL,
  question_id INT NOT NULL,
  selected_option ENUM('a','b','c','d') DEFAULT NULL,
  is_correct TINYINT(1) NOT NULL DEFAULT 0,
  FOREIGN KEY (attempt_id) REFERENCES attempts(id) ON DELETE CASCADE,
  FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
) ENGINE=InnoDB;
