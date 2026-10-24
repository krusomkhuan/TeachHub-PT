-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.12
-- ไฟล์: update_v1.12.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.11 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: รายงานการพบนักเรียนของครูที่ปรึกษา (ส่งทุกวันพุธ)
--   - advisor_groups        กลุ่มเรียนที่ปรึกษารายภาคเรียน (งานครูที่ปรึกษาฯ เป็นผู้กำหนด)
--   - advisor_reports       รายงานการพบนักเรียน 1 กลุ่ม ต่อ 1 วันพุธ
--   - advisor_report_images ภาพถ่ายประกอบรายงาน
--   - advisor_skip_dates    วันพุธที่งดพบ (วันหยุด/สอบ) ไม่นับเป็นขาดส่ง
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `advisor_groups` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `academic_year_id`  INT UNSIGNED NOT NULL,
  `teacher_id`        INT UNSIGNED NOT NULL COMMENT 'ครูที่ปรึกษา',
  `department_id`     INT UNSIGNED NULL COMMENT 'แผนกของกลุ่มเรียน',
  `group_name`        VARCHAR(100) NOT NULL COMMENT 'ชื่อกลุ่มเรียน เช่น ปวช.1/1 ช่างยนต์',
  `student_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`        INT UNSIGNED NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_group` (`academic_year_id`, `teacher_id`, `group_name`),
  KEY `idx_advisor_group_teacher` (`teacher_id`),
  KEY `idx_advisor_group_dept` (`department_id`),
  CONSTRAINT `fk_advisor_group_ay` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_group_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_group_dept` FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_advisor_group_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `advisor_reports` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`        INT UNSIGNED NOT NULL,
  `teacher_id`      INT UNSIGNED NOT NULL,
  `meet_date`       DATE NOT NULL COMMENT 'วันพุธที่พบนักเรียน',
  `total_students`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `present_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'มา',
  `late_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'มาสาย',
  `leave_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ลา',
  `absent_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ขาด',
  `topics`          TEXT NOT NULL COMMENT 'เรื่องที่พูดคุย/แจ้งนักเรียน',
  `problems`        TEXT NULL COMMENT 'นักเรียนที่มีปัญหาและการช่วยเหลือ',
  `status`          ENUM('pending','approved','revision') NOT NULL DEFAULT 'pending',
  `is_late`         TINYINT(1) NOT NULL DEFAULT 0,
  `review_comment`  TEXT NULL,
  `reviewed_by`     INT UNSIGNED NULL,
  `reviewed_at`     DATETIME NULL,
  `submitted_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_report_week` (`group_id`, `meet_date`),
  KEY `idx_advisor_report_teacher` (`teacher_id`, `meet_date`),
  KEY `idx_advisor_report_status` (`status`),
  KEY `idx_advisor_report_date` (`meet_date`),
  CONSTRAINT `fk_advisor_report_group` FOREIGN KEY (`group_id`)
    REFERENCES `advisor_groups` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_report_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_report_reviewer` FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `advisor_report_images` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`      INT UNSIGNED NOT NULL,
  `original_name`  VARCHAR(255) NOT NULL,
  `stored_name`    VARCHAR(255) NOT NULL,
  `mime_type`      VARCHAR(100) NOT NULL,
  `file_size`      INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_images_stored` (`stored_name`),
  KEY `idx_advisor_images_report` (`report_id`),
  CONSTRAINT `fk_advisor_images_report` FOREIGN KEY (`report_id`)
    REFERENCES `advisor_reports` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `advisor_skip_dates` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skip_date`   DATE NOT NULL,
  `reason`      VARCHAR(200) NOT NULL DEFAULT '',
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_skip_date` (`skip_date`),
  CONSTRAINT `fk_advisor_skip_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('advisor_late_days', '2', 'รายงานครูที่ปรึกษา: ส่งได้ภายในกี่วันหลังวันพุธโดยไม่นับว่าส่งช้า');

-- เปลี่ยนชื่อเต็มของระบบ (เฉพาะเครื่องที่ยังใช้ชื่อเดิม ถ้า admin ตั้งชื่ออื่นไว้จะไม่เปลี่ยน)
UPDATE `settings` SET `setting_value` = 'ระบบการส่งงานและรายงานกิจกรรม'
 WHERE `setting_key` = 'system_fullname' AND `setting_value` = 'ระบบงานพัฒนาหลักสูตรและการจัดการเรียนรู้';
