-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลจากเวอร์ชัน 1.0 เป็น 1.1
-- ไฟล์: update_v1.1.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.0 ไว้แล้วและมีข้อมูลจริง (ไม่ต้องการนำเข้า database.sql ใหม่)
-- ติดตั้งใหม่ไม่ต้องใช้ไฟล์นี้ (database.sql มีครบแล้ว)
-- วิธีใช้: phpMyAdmin > เลือกฐานข้อมูล > Import > update_v1.1.sql   ** สำรองฐานข้อมูลก่อนทุกครั้ง **
--
-- สิ่งที่เพิ่ม: การประเมินการสอนโดยนักเรียน นักศึกษา (ไม่ต้องเป็นสมาชิก) ผ่านลิงก์/QR Code
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- 1) แยกชุดหัวข้อประเมิน: หัวหน้าแผนก / นักเรียน
ALTER TABLE `supervision_criteria`
  ADD COLUMN `audience` ENUM('supervisor','student') NOT NULL DEFAULT 'supervisor'
      COMMENT 'ชุดหัวข้อ: หัวหน้าแผนกนิเทศ / นักเรียนประเมิน' AFTER `id`,
  DROP INDEX `idx_criteria_active_sort`,
  ADD KEY `idx_criteria_audience` (`audience`, `is_active`, `sort_order`);

-- 2) ตารางใหม่
CREATE TABLE IF NOT EXISTS `student_eval_sessions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`            CHAR(32) NOT NULL,
  `academic_year_id` INT UNSIGNED NULL,
  `teacher_id`       INT UNSIGNED NOT NULL,
  `subject_id`       INT UNSIGNED NULL,
  `subject_name`     VARCHAR(200) NOT NULL DEFAULT '',
  `classroom`        VARCHAR(100) NOT NULL DEFAULT '',
  `open_at`          DATETIME NOT NULL,
  `close_at`         DATETIME NOT NULL,
  `max_responses`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `note`             VARCHAR(500) NULL,
  `created_by`       INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_eval_token` (`token`),
  KEY `idx_student_eval_teacher` (`teacher_id`),
  KEY `idx_student_eval_academic` (`academic_year_id`),
  KEY `idx_student_eval_subject` (`subject_id`),
  KEY `idx_student_eval_created_by` (`created_by`),
  CONSTRAINT `fk_student_eval_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_student_eval_academic` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_student_eval_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_student_eval_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_eval_responses` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`  INT UNSIGNED NOT NULL,
  `avg_score`   DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  `comment`     TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_resp_session` (`session_id`),
  CONSTRAINT `fk_student_resp_session` FOREIGN KEY (`session_id`) REFERENCES `student_eval_sessions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `student_eval_scores` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `response_id`     INT UNSIGNED NOT NULL,
  `criteria_id`     INT UNSIGNED NULL,
  `category`        VARCHAR(150) NOT NULL DEFAULT '',
  `criteria_title`  VARCHAR(255) NOT NULL DEFAULT '',
  `score`           TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_student_score_response` (`response_id`),
  KEY `idx_student_score_criteria` (`criteria_id`),
  CONSTRAINT `fk_student_score_response` FOREIGN KEY (`response_id`) REFERENCES `student_eval_responses` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_student_score_criteria` FOREIGN KEY (`criteria_id`) REFERENCES `supervision_criteria` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) หัวข้อประเมินโดยนักเรียนเริ่มต้น
INSERT INTO `supervision_criteria` (`audience`, `category`, `title`, `sort_order`) VALUES
('student', 'ด้านการสอนของครู',              'ครูเตรียมการสอนและเข้าสอนตรงเวลา',                       1),
('student', 'ด้านการสอนของครู',              'ครูอธิบายเนื้อหาชัดเจน เข้าใจง่าย',                         2),
('student', 'ด้านการสอนของครู',              'ครูยกตัวอย่างหรือสาธิตการปฏิบัติงานให้เห็นจริง',              3),
('student', 'ด้านการสอนของครู',              'ครูเปิดโอกาสให้ซักถามและแสดงความคิดเห็น',                  4),
('student', 'ด้านกิจกรรมและสื่อการเรียนรู้',     'กิจกรรมการเรียนน่าสนใจ ได้ลงมือปฏิบัติจริง',                 5),
('student', 'ด้านกิจกรรมและสื่อการเรียนรู้',     'ครูใช้สื่อหรือเทคโนโลยีช่วยให้เรียนเข้าใจง่ายขึ้น',               6),
('student', 'ด้านการวัดและประเมินผล',         'ครูแจ้งเกณฑ์การให้คะแนนชัดเจน',                            7),
('student', 'ด้านการวัดและประเมินผล',         'ครูให้คำแนะนำเมื่อนักเรียนทำงานผิดพลาด',                     8),
('student', 'ด้านบุคลิกภาพและการดูแลผู้เรียน',  'ครูเป็นกันเองและให้ความเป็นธรรมกับนักเรียนทุกคน',              9),
('student', 'ด้านบุคลิกภาพและการดูแลผู้เรียน',  'ครูเน้นความปลอดภัยและสอดแทรกคุณธรรมจริยธรรม',               10);

-- 4) ค่าตั้งค่าใหม่
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('site_url', '', 'URL หลักของระบบ ใช้สร้าง QR Code (ว่าง = ตรวจหาอัตโนมัติ)');
