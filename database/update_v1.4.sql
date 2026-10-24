-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.4
-- ไฟล์: update_v1.4.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.3 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: ข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล (PDPA)
--   ผู้ใช้เดิมทุกคนจะต้องกดยอมรับข้อตกลงเมื่อเข้าสู่ระบบครั้งถัดไป
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `pdpa_version`     VARCHAR(20) NULL COMMENT 'ฉบับของข้อตกลง PDPA ที่ยอมรับล่าสุด' AFTER `must_change_password`,
  ADD COLUMN `pdpa_accepted_at` DATETIME    NULL COMMENT 'วันเวลาที่ยอมรับข้อตกลง PDPA' AFTER `pdpa_version`;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('pdpa_version',  '1', 'ฉบับปัจจุบันของข้อตกลง PDPA'),
('pdpa_required', '1', 'บังคับยอมรับข้อตกลง PDPA ก่อนใช้งาน (1=บังคับ)'),
('pdpa_updated',  DATE_FORMAT(CURDATE(), '%Y-%m-%d'), 'วันที่ประกาศใช้ข้อตกลง PDPA ฉบับปัจจุบัน'),
('pdpa_content',  '',  'เนื้อหาข้อตกลง PDPA (ว่าง = ใช้ข้อความเริ่มต้น)');
