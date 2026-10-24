-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.18
-- ไฟล์: update_v1.18.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.17 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เปลี่ยน: ที่เก็บไฟล์งานใน Google Drive
--   - ตารางใหม่ drive_files เก็บตำแหน่งไฟล์ที่ย้ายขึ้น Google Drive (รวมเป็น 39 ตาราง)
--   - ค่าตั้งค่าใหม่ drive_enabled, drive_root_name, drive_folders, drive_parent_id, drive_backup
-- token ของ Google Drive ไม่อยู่ในฐานข้อมูล (ระบบสร้างไฟล์ config/drive_token.php เองเมื่อกดเชื่อมต่อ)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE `drive_files` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subdir`       VARCHAR(30) NOT NULL COMMENT 'โฟลเดอร์ใน uploads/ เช่น lesson_plans, supervision',
  `stored_name`  VARCHAR(100) NOT NULL COMMENT 'ชื่อไฟล์ที่ระบบตั้ง (ตรงกับคอลัมน์ stored_name ของตารางเจ้าของไฟล์)',
  `drive_id`     VARCHAR(128) NOT NULL COMMENT 'File ID ใน Google Drive',
  `size`         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_drive_file` (`subdir`, `stored_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('drive_enabled',   '0',           'เก็บไฟล์งานที่อัปโหลดใหม่ใน Google Drive (1=เปิด)'),
('drive_root_name', 'TeachHub PT', 'ชื่อโฟลเดอร์หลักใน Google Drive'),
('drive_folders',   '{}',          'รหัสโฟลเดอร์ใน Google Drive (ระบบจัดการเอง)'),
('drive_parent_id', '',            'ID โฟลเดอร์ปลายทางใน Google Drive (ว่าง = ระบบสร้างโฟลเดอร์ TeachHub PT ใน My Drive)'),
('drive_backup',    '0',           'ส่งสำเนาไฟล์สำรองข้อมูลขึ้น Google Drive (1=เปิด)');
