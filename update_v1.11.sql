-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.11
-- ไฟล์: update_v1.11.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.10 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: กระดานข่าว — ช่องทางติดต่อประสานงาน สอบถาม แจ้งปัญหา และเสนอพัฒนาระบบ (เฉพาะสมาชิก)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `board_topics` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NULL,
  `category`      ENUM('general','question','feature','bug') NOT NULL DEFAULT 'general',
  `title`         VARCHAR(200) NOT NULL,
  `body`          TEXT NOT NULL,
  `status`        ENUM('open','answered','planned','done','closed') NOT NULL DEFAULT 'open',
  `is_pinned`     TINYINT(1) NOT NULL DEFAULT 0,
  `views`         INT UNSIGNED NOT NULL DEFAULT 0,
  `reply_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `last_reply_at` DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_board_list` (`is_pinned`, `last_reply_at`),
  KEY `idx_board_category` (`category`, `status`),
  KEY `idx_board_user` (`user_id`),
  CONSTRAINT `fk_board_topic_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `board_replies` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `topic_id`    INT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `body`        TEXT NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_board_reply_topic` (`topic_id`, `id`),
  KEY `idx_board_reply_user` (`user_id`),
  CONSTRAINT `fk_board_reply_topic` FOREIGN KEY (`topic_id`)
    REFERENCES `board_topics` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_board_reply_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
