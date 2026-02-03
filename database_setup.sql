CREATE DATABASE IF NOT EXISTS `book_distribution_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `book_distribution_system`;

CREATE TABLE IF NOT EXISTS `admins` (
  `admin_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(30) NOT NULL,
  `class_name` VARCHAR(30) NULL,
  `role` ENUM('super_admin','rep') NOT NULL DEFAULT 'rep',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `access_code` VARCHAR(4) NULL,
  `access_code_expires` DATETIME NULL,
  `momo_number` VARCHAR(10) NULL,
  `bank_name` VARCHAR(30) NULL,
  `account_name` VARCHAR(30) NULL,
  `account_number` VARCHAR(20) NULL,
  `first_time_code` VARCHAR(4) NULL,
  `first_time_code_expires` DATETIME NULL,
  `requires_password_reset` TINYINT(1) NOT NULL DEFAULT 0,
  `approved_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `semesters` (
  `semester_id` INT NOT NULL AUTO_INCREMENT,
  `semester_name` VARCHAR(30) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`semester_id`),
  UNIQUE KEY `uq_semester_name` (`semester_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `students` (
  `student_id` INT NOT NULL AUTO_INCREMENT,
  `index_number` VARCHAR(10) NOT NULL,
  `full_name` VARCHAR(30) NOT NULL,
  `phone` VARCHAR(10) NULL,
  `credit_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `admin_id` INT NULL,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `uq_students_index_number` (`index_number`),
  KEY `idx_students_admin_id` (`admin_id`),
  CONSTRAINT `fk_students_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `books` (
  `book_id` INT NOT NULL AUTO_INCREMENT,
  `book_title` VARCHAR(30) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `stock_quantity` INT NOT NULL DEFAULT 0,
  `availability` ENUM('available','out_of_stock') NOT NULL DEFAULT 'available',
  `admin_id` INT NULL,
  PRIMARY KEY (`book_id`),
  KEY `idx_books_admin_id` (`admin_id`),
  CONSTRAINT `fk_books_admin_id` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `requests` (
  `request_id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `amount_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` ENUM('paid','unpaid') NOT NULL DEFAULT 'unpaid',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `semester_id` INT NULL,
  `admin_id` INT NULL,
  PRIMARY KEY (`request_id`),
  KEY `idx_requests_student` (`student_id`),
  KEY `idx_requests_status` (`payment_status`),
  KEY `idx_requests_date` (`created_at`),
  KEY `idx_requests_semester` (`semester_id`),
  KEY `idx_requests_admin` (`admin_id`),
  CONSTRAINT `fk_requests_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_requests_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_requests_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `request_items` (
  `item_id` INT NOT NULL AUTO_INCREMENT,
  `request_id` INT NOT NULL,
  `book_id` INT NOT NULL,
  `is_collected` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`item_id`),
  KEY `idx_request_items_request` (`request_id`),
  KEY `idx_request_items_book` (`book_id`),
  CONSTRAINT `fk_request_items_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`request_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_request_items_book` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `lecturer_payments` (
  `payment_id` INT NOT NULL AUTO_INCREMENT,
  `book_id` INT NOT NULL,
  `copies_paid` INT NOT NULL,
  `amount_paid` DECIMAL(10,2) NOT NULL,
  `payment_date` DATE NOT NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `semester_id` INT NULL,
  `admin_id` INT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `idx_lecturer_payments_book` (`book_id`),
  KEY `idx_lecturer_payments_date` (`payment_date`),
  KEY `idx_lecturer_payments_semester` (`semester_id`),
  KEY `idx_lecturer_payments_admin` (`admin_id`),
  CONSTRAINT `fk_lecturer_payments_book` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lecturer_payments_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_lecturer_payments_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `books_received` (
  `receive_id` INT NOT NULL AUTO_INCREMENT,
  `book_id` INT NOT NULL,
  `copies_received` INT NOT NULL,
  `receive_date` DATE NOT NULL,
  `lecturer_name` VARCHAR(100) NULL,
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `semester_id` INT NULL,
  `admin_id` INT NULL,
  PRIMARY KEY (`receive_id`),
  KEY `idx_books_received_book` (`book_id`),
  KEY `idx_books_received_semester` (`semester_id`),
  KEY `idx_books_received_admin` (`admin_id`),
  CONSTRAINT `fk_books_received_book` FOREIGN KEY (`book_id`) REFERENCES `books` (`book_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_books_received_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`semester_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_books_received_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `class_students` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `admin_id` INT NOT NULL,
  `index_number` VARCHAR(10) NOT NULL,
  `student_name` VARCHAR(30) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_index` (`admin_id`, `index_number`),
  KEY `idx_class_students_admin` (`admin_id`),
  CONSTRAINT `fk_class_students_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `balance_returns` (
  `return_id` INT NOT NULL AUTO_INCREMENT,
  `student_id` INT NOT NULL,
  `request_id` INT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `return_date` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` VARCHAR(255) NULL,
  PRIMARY KEY (`return_id`),
  KEY `idx_balance_returns_student` (`student_id`),
  KEY `idx_balance_returns_request` (`request_id`),
  CONSTRAINT `fk_balance_returns_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_balance_returns_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`request_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `system_state` (
  `id` TINYINT NOT NULL,
  `last_db_change_at` DATETIME NULL,
  `last_backup_at` DATETIME NULL,
  `last_backup_file` VARCHAR(255) NULL,
  `backup_in_progress` TINYINT(1) NOT NULL DEFAULT 0,
  `backup_started_at` DATETIME NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rep_signup_requests` (
  `signup_id` INT NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `full_name` VARCHAR(30) NOT NULL,
  `class_name` VARCHAR(30) NULL,
  `momo_number` VARCHAR(10) NULL,
  `bank_name` VARCHAR(30) NULL,
  `account_name` VARCHAR(30) NULL,
  `account_number` VARCHAR(20) NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` DATETIME NULL,
  `approved_by_admin_id` INT NULL,
  `created_admin_id` INT NULL,
  PRIMARY KEY (`signup_id`),
  UNIQUE KEY `uq_rep_signup_username` (`username`),
  KEY `idx_rep_signup_status` (`status`),
  KEY `idx_rep_signup_created_at` (`created_at`),
  CONSTRAINT `fk_rep_signup_approved_by` FOREIGN KEY (`approved_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_rep_signup_created_admin` FOREIGN KEY (`created_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `semesters` (`semester_name`, `is_active`) VALUES ('Default Semester', 1)
ON DUPLICATE KEY UPDATE `is_active` = VALUES(`is_active`);

INSERT INTO `admins` (`username`, `password_hash`, `full_name`, `role`, `is_active`) VALUES
('Roland', '$2y$10$Fp1W2g1SuXVVhF3fg5TFquPm2oeOu5dQvxPVfPg2.4WNz/14ruV.i', 'Roland Kitsi', 'super_admin', 1)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `full_name` = VALUES(`full_name`),
  `role` = VALUES(`role`),
  `is_active` = VALUES(`is_active`);

INSERT INTO `system_state` (`id`, `last_db_change_at`, `last_backup_at`, `last_backup_file`, `backup_in_progress`, `backup_started_at`) VALUES
(1, NOW(), NULL, NULL, 0, NULL)
ON DUPLICATE KEY UPDATE
  `id` = VALUES(`id`);
