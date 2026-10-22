-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.14
-- ไฟล์: update_v1.14.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.13 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: หมวดหมู่เอกสารดาวน์โหลดที่ admin เพิ่ม/แก้ไข/เรียงลำดับ/ปิดใช้งานได้เอง
--   - download_categories  หมวดหมู่เอกสารดาวน์โหลด (เริ่มต้น 5 หมวดเดิม)
--   - downloads.category   เปลี่ยนจาก ENUM เป็น VARCHAR (เก็บรหัสหมวดจาก download_categories.code)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `download_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(30)  NOT NULL COMMENT 'รหัสหมวด (อ้างอิงจาก downloads.category)',
  `name`        VARCHAR(100) NOT NULL,
  `sort_order`  SMALLINT     NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_download_categories_code` (`code`),
  KEY `idx_download_categories_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `download_categories` (`code`, `name`, `sort_order`) VALUES
('lesson_plan', 'แบบฟอร์มแผนการสอน', 10),
('pa',          'แบบ PA',             20),
('research',    'เค้าโครงวิจัย',       30),
('supervision', 'แบบนิเทศ',           40),
('other',       'เอกสารอื่น ๆ',        90);

ALTER TABLE `downloads` MODIFY `category` VARCHAR(30) NOT NULL DEFAULT 'other';
