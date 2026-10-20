-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.13
-- ไฟล์: update_v1.13.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.12 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: รายงานการเยี่ยมบ้านนักเรียนของครูที่ปรึกษา (นักเรียน 1 คน เยี่ยม 1 ครั้งต่อปีการศึกษา)
--   - home_visits        รายงานการเยี่ยมบ้าน 1 ครั้ง (อ้างอิงกลุ่มที่ปรึกษา advisor_groups)
--   - home_visit_images  ภาพถ่ายประกอบ
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `home_visits` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `school_year`         SMALLINT UNSIGNED NOT NULL COMMENT 'ปีการศึกษา (พ.ศ.)',
  `group_id`            INT UNSIGNED NOT NULL COMMENT 'กลุ่มที่ปรึกษา',
  `teacher_id`          INT UNSIGNED NOT NULL,
  `student_code`        VARCHAR(20)  NOT NULL,
  `student_name`        VARCHAR(150) NOT NULL,
  `visit_date`          DATE NOT NULL,
  `informant_name`      VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ผู้ให้ข้อมูล',
  `informant_relation`  VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'ความเกี่ยวข้องกับนักเรียน',
  `address`             VARCHAR(500) NOT NULL DEFAULT '',
  `living_with`         VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'นักเรียนอาศัยอยู่กับ',
  `economic_status`     ENUM('sufficient','insufficient','poor') NOT NULL DEFAULT 'sufficient' COMMENT 'พอเพียง/ไม่พอเพียง/ขัดสน',
  `family_info`         TEXT NULL COMMENT 'สภาพครอบครัวและที่อยู่อาศัย',
  `learning_behavior`   TEXT NULL COMMENT 'ด้านการเรียนและพฤติกรรม',
  `risk_level`          ENUM('normal','risk','problem') NOT NULL DEFAULT 'normal' COMMENT 'ปกติ/เสี่ยง/มีปัญหา',
  `assistance`          TEXT NULL COMMENT 'การช่วยเหลือ/ข้อเสนอแนะ',
  `latitude`            DECIMAL(10,7) NULL,
  `longitude`           DECIMAL(10,7) NULL,
  `status`              ENUM('pending','approved','revision') NOT NULL DEFAULT 'pending',
  `review_comment`      TEXT NULL,
  `reviewed_by`         INT UNSIGNED NULL,
  `reviewed_at`         DATETIME NULL,
  `submitted_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_home_visit_student_year` (`school_year`, `student_code`),
  KEY `idx_home_visit_teacher` (`teacher_id`, `school_year`),
  KEY `idx_home_visit_group` (`group_id`),
  KEY `idx_home_visit_status` (`status`),
  CONSTRAINT `fk_home_visit_group` FOREIGN KEY (`group_id`)
    REFERENCES `advisor_groups` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_home_visit_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_home_visit_reviewer` FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `home_visit_images` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `visit_id`       INT UNSIGNED NOT NULL,
  `original_name`  VARCHAR(255) NOT NULL,
  `stored_name`    VARCHAR(255) NOT NULL,
  `mime_type`      VARCHAR(100) NOT NULL,
  `file_size`      INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_home_visit_images_stored` (`stored_name`),
  KEY `idx_home_visit_images_visit` (`visit_id`),
  CONSTRAINT `fk_home_visit_images_visit` FOREIGN KEY (`visit_id`)
    REFERENCES `home_visits` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
