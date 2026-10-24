-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.8
-- ไฟล์: update_v1.8.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.7 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เปลี่ยน: ยุบรวมการส่งโครงการสอนและแผนการจัดการเรียนรู้ไว้ที่จุดเดียว
--   - รอบการส่งแบบ "โครงการสอนรายวิชา" (phase = outline) เปลี่ยนเป็นรอบปกติ (main)
--   - ครูเลือกประเภทเอกสารจาก list ในฟอร์มส่ง และส่งได้ทั้ง 2 ประเภทของวิชาเดียวกันในรอบเดียว
-- =====================================================================
SET NAMES utf8mb4;

UPDATE `submission_rounds` SET `phase` = 'main' WHERE `type` = 'lesson_plan' AND `phase` = 'outline';

ALTER TABLE `lesson_plans`
  DROP INDEX `uq_lesson_round_teacher_subject`,
  ADD UNIQUE KEY `uq_lesson_round_teacher_subject` (`round_id`, `teacher_id`, `doc_type`, `subject_code`);
