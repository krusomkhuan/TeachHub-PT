-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.6
-- ไฟล์: update_v1.6.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.5 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม:
--   1) ตาราง teaching_subjects — รายวิชาที่ครูสอนในแต่ละภาคเรียน (ครูบันทึกเอง)
--   2) รอบการส่งแบบ "โครงการสอนรายวิชา" (phase = outline) และคอลัมน์ lesson_plans.doc_type
--   เกณฑ์: แต่ละภาคเรียน ครูส่งโครงการสอนครบทุกรายวิชาที่สอน + แผนการจัดการเรียนรู้อย่างน้อย 1 วิชา
--   รายวิชาที่หัวหน้าแผนก/นักเรียนประเมิน ต้องเป็นวิชาที่แผนการจัดการเรียนรู้ "ผ่าน" แล้ว
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `teaching_subjects` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id`       INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `subject_code`     VARCHAR(20)  NOT NULL,
  `subject_name`     VARCHAR(200) NOT NULL,
  `level`            ENUM('ปวช.','ปวส.') NOT NULL DEFAULT 'ปวช.',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teaching_subject` (`teacher_id`, `academic_year_id`, `subject_code`),
  KEY `idx_teaching_ay` (`academic_year_id`),
  CONSTRAINT `fk_teaching_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_teaching_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `submission_rounds`
  MODIFY `phase` ENUM('main','outline','agreement','report','proposal','final') NOT NULL DEFAULT 'main';

ALTER TABLE `lesson_plans`
  ADD COLUMN `doc_type` ENUM('plan','outline') NOT NULL DEFAULT 'plan' AFTER `round_id`,
  DROP INDEX `idx_lesson_teacher`,
  ADD KEY `idx_lesson_teacher` (`teacher_id`, `academic_year_id`, `doc_type`);

-- รอบเดิมที่ตั้งชื่อว่า "โครงการสอน" ให้เป็นรอบโครงการสอน
UPDATE `submission_rounds` SET `phase` = 'outline' WHERE `type` = 'lesson_plan' AND `name` LIKE '%โครงการสอน%';
UPDATE `lesson_plans` lp JOIN `submission_rounds` r ON r.id = lp.round_id
   SET lp.doc_type = 'outline' WHERE r.phase = 'outline';

-- รายวิชาที่สอน: นำรายวิชาจากงานที่ส่งไว้แล้วมาตั้งต้นให้
INSERT IGNORE INTO `teaching_subjects` (`teacher_id`, `academic_year_id`, `subject_code`, `subject_name`, `level`)
SELECT lp.teacher_id, lp.academic_year_id, lp.subject_code, lp.subject_name, lp.level
FROM `lesson_plans` lp WHERE lp.academic_year_id IS NOT NULL;
