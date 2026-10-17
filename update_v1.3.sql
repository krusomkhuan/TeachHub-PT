-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.3
-- ไฟล์: update_v1.3.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.2.x ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: แบบประเมินโดยนักเรียนระบุกลุ่มนักเรียนเป็น ระดับ (ปวช./ปวส.) + ชั้นปี + กลุ่ม
-- แบบประเมินเดิมที่ยังไม่ได้ระบุระดับ ให้ admin เข้าไปแก้ไขเพื่อเลือกระดับ
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `student_eval_sessions`
  ADD COLUMN `level`      ENUM('ปวช.','ปวส.') NULL COMMENT 'ระดับของกลุ่มนักเรียน' AFTER `subject_name`,
  ADD COLUMN `grade_year` TINYINT UNSIGNED NULL COMMENT 'ชั้นปี (ปวช. 1-3, ปวส. 1-2)' AFTER `level`,
  ADD COLUMN `group_no`   VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'กลุ่ม/ห้อง เช่น 1, 2, ชฟ.1' AFTER `grade_year`,
  ADD KEY `idx_student_eval_level` (`level`, `grade_year`);

-- เดาระดับจากชื่อห้องเดิมที่ขึ้นต้นด้วย ปวช./ปวส. (ถ้ามี)
UPDATE `student_eval_sessions` SET `level` = 'ปวส.' WHERE `level` IS NULL AND `classroom` LIKE 'ปวส%';
UPDATE `student_eval_sessions` SET `level` = 'ปวช.' WHERE `level` IS NULL AND `classroom` LIKE 'ปวช%';
