-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.20
-- ไฟล์: update_v1.20.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.19 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เปลี่ยน: ผู้ใช้ออนไลน์ ตัวนับผู้เข้าชม และข้อความชื่นชมผู้ใช้งานสม่ำเสมอ
--   - คอลัมน์ใหม่ users.last_seen_at (ใช้งานล่าสุด — ออนไลน์ = ภายใน 5 นาที)
--   - user_active_days : วันที่ผู้ใช้เข้าใช้งาน (นับวันต่อเนื่อง/จำนวนวัน เพื่อแสดงข้อความชื่นชมส่วนตัว)
--   - site_visit_daily : จำนวนผู้เข้าชมรายวัน
--   - site_visitors    : ค่า hash ของผู้เข้าชมวันนี้ ใช้กันนับซ้ำ (ไม่เก็บ IP — ลบทิ้งอัตโนมัติหลัง 2 วัน)
--   ตารางใหม่ 3 ตาราง (รวมเป็น 54 ตาราง)
-- =====================================================================
SET NAMES utf8mb4;

ALTER TABLE `users`
  ADD COLUMN `last_seen_at` DATETIME NULL COMMENT 'ใช้งานล่าสุด (อัปเดตไม่เกิน 1 ครั้งต่อนาที)' AFTER `last_login_at`,
  ADD KEY `idx_users_last_seen` (`last_seen_at`);

CREATE TABLE `user_active_days` (
  `user_id`     INT UNSIGNED NOT NULL,
  `active_date` DATE NOT NULL,
  `cheered`     TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = แจ้งข้อความชื่นชมของวันนี้แล้ว',
  PRIMARY KEY (`user_id`, `active_date`),
  CONSTRAINT `fk_active_days_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='วันที่ผู้ใช้เข้าใช้งาน';

CREATE TABLE `site_visit_daily` (
  `visit_date` DATE NOT NULL,
  `visits`     INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='จำนวนผู้เข้าชมรายวัน';

CREATE TABLE `site_visitors` (
  `visit_date`   DATE NOT NULL,
  `visitor_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 ของ IP+เบราว์เซอร์+วันที่ (ย้อนกลับเป็น IP ไม่ได้)',
  PRIMARY KEY (`visit_date`, `visitor_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กันนับผู้เข้าชมซ้ำภายในวัน';

-- เติมวันที่เคยเข้าใช้งานจากประวัติการเข้าสู่ระบบเดิม (ข้อความชื่นชมจะนับต่อจากประวัติ)
INSERT IGNORE INTO `user_active_days` (`user_id`, `active_date`, `cheered`)
SELECT DISTINCT l.user_id, DATE(l.created_at), 1
FROM `activity_logs` l JOIN `users` u ON u.id = l.user_id
WHERE l.action = 'login';
