-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.7
-- ไฟล์: update_v1.7.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.6 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: สมัครสมาชิก / เข้าสู่ระบบด้วยบัญชี Google (OAuth 2.0)
--   เปิดใช้ที่ ตั้งค่าระบบ → เข้าสู่ระบบด้วย Google (ต้องสร้าง OAuth Client ใน Google Cloud Console ก่อน)
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `google_sub`     VARCHAR(64) NULL COMMENT 'รหัสบัญชี Google ที่เชื่อมไว้ (เข้าสู่ระบบด้วย Google)' AFTER `must_change_password`,
  ADD COLUMN `password_login` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = สมัครด้วย Google ยังไม่เคยตั้งรหัสผ่าน' AFTER `google_sub`,
  ADD UNIQUE KEY `uq_users_google` (`google_sub`);

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('google_enabled',        '0', 'เปิดเข้าสู่ระบบ/สมัครสมาชิกด้วยบัญชี Google (1=เปิด)'),
('google_client_id',      '',  'Google OAuth Client ID'),
('google_client_secret',  '',  'Google OAuth Client Secret'),
('google_allowed_domain', '',  'จำกัดอีเมล Google เฉพาะโดเมน เช่น pticec.ac.th (ว่าง = ทุกโดเมน)');
