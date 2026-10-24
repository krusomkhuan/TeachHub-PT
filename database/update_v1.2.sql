-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลจากเวอร์ชัน 1.1 เป็น 1.2
-- ไฟล์: update_v1.2.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.1 ไว้แล้ว (ถ้ายังเป็น 1.0 ให้นำเข้า update_v1.1.sql ก่อน)
-- ติดตั้งใหม่ไม่ต้องใช้ไฟล์นี้ (database.sql มีครบแล้ว)
-- วิธีใช้: phpMyAdmin > เลือกฐานข้อมูล > Import > update_v1.2.sql   ** สำรองฐานข้อมูลก่อนทุกครั้ง **
--
-- สิ่งที่เพิ่ม: รายงานการนิเทศครูผู้สอนโดยหัวหน้าแผนกวิชา
--   เสนอผ่าน หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้ → รองผู้อำนวยการฝ่ายวิชาการ → ผู้อำนวยการ
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+07:00';

CREATE TABLE IF NOT EXISTS `supervision_reports` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id`    INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `author_id`        INT UNSIGNED NOT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `period_start`     DATE NULL,
  `period_end`       DATE NULL,
  `summary_overall`  TEXT NULL,
  `strengths`        TEXT NULL,
  `problems`         TEXT NULL,
  `suggestions`      TEXT NULL,
  `status`           ENUM('draft','pending','returned','approved') NOT NULL DEFAULT 'draft',
  `current_step`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `snapshot`         LONGTEXT NULL,
  `submitted_at`     DATETIME NULL,
  `approved_at`      DATETIME NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sup_report_dept` (`department_id`, `academic_year_id`),
  KEY `idx_sup_report_status` (`status`, `current_step`),
  KEY `idx_sup_report_author` (`author_id`),
  KEY `idx_sup_report_academic` (`academic_year_id`),
  CONSTRAINT `fk_sup_report_dept` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_sup_report_academic` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_sup_report_author` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supervision_report_actions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`   INT UNSIGNED NOT NULL,
  `step`        TINYINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `position`    VARCHAR(150) NOT NULL DEFAULT '',
  `action`      ENUM('submit','forward','approve','return') NOT NULL,
  `comment`     TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sup_report_action_report` (`report_id`),
  KEY `idx_sup_report_action_user` (`user_id`),
  CONSTRAINT `fk_sup_report_action_report` FOREIGN KEY (`report_id`) REFERENCES `supervision_reports` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_sup_report_action_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('approver_1_user',  '', 'ผู้พิจารณาขั้นที่ 1 (user id)'),
('approver_1_title', 'หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้', 'ตำแหน่งผู้พิจารณาขั้นที่ 1'),
('approver_2_user',  '', 'ผู้พิจารณาขั้นที่ 2 (user id)'),
('approver_2_title', 'รองผู้อำนวยการฝ่ายวิชาการ', 'ตำแหน่งผู้พิจารณาขั้นที่ 2'),
('approver_3_user',  '', 'ผู้พิจารณาขั้นที่ 3 (user id)'),
('approver_3_title', 'ผู้อำนวยการวิทยาลัยการอาชีพโพนทอง', 'ตำแหน่งผู้พิจารณาขั้นที่ 3');
