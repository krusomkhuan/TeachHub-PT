-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.19
-- ไฟล์: update_v1.19.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.18 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เปลี่ยน: ระบบงานบุคลากร
--   - ทะเบียนประวัติบุคลากร: hr_profiles, hr_educations, hr_service_records
--   - การลา: hr_leaves, hr_leave_actions, hr_leave_quotas
--   - การพัฒนาตนเอง/อบรม: hr_trainings
--   - คำสั่ง: hr_orders, hr_order_users
--   - การเข้าร่วมกิจกรรมวันสำคัญ: hr_events, hr_event_attendances
--   ตารางใหม่ 12 ตาราง (รวมเป็น 51 ตาราง) — hr_event_participants กำหนดผู้เข้าร่วมรายกิจกรรม + ค่าตั้งค่าใหม่ hr_vacation_days, hr_deputy_position
-- ไฟล์แนบของงานบุคลากรเก็บที่ uploads/hr (ระบบสร้างโฟลเดอร์ให้เองเมื่ออัปโหลดครั้งแรก)
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE `hr_profiles` (
  `user_id`            INT UNSIGNED NOT NULL,
  `staff_type`         VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'ประเภทบุคลากร เช่น ข้าราชการครู พนักงานราชการ',
  `citizen_id`         CHAR(13)     DEFAULT NULL COMMENT 'เลขประจำตัวประชาชน (เห็นเฉพาะเจ้าของและงานบุคลากร)',
  `birth_date`         DATE         DEFAULT NULL,
  `gender`             VARCHAR(10)  NOT NULL DEFAULT '',
  `blood_type`         VARCHAR(5)   NOT NULL DEFAULT '',
  `religion`           VARCHAR(50)  NOT NULL DEFAULT '',
  `nationality`        VARCHAR(50)  NOT NULL DEFAULT '',
  `address`            VARCHAR(500) NOT NULL DEFAULT '',
  `emergency_name`     VARCHAR(150) NOT NULL DEFAULT '',
  `emergency_relation` VARCHAR(50)  NOT NULL DEFAULT '',
  `emergency_phone`    VARCHAR(20)  NOT NULL DEFAULT '',
  `position_no`        VARCHAR(30)  NOT NULL DEFAULT '' COMMENT 'เลขที่ตำแหน่ง',
  `service_start_date` DATE         DEFAULT NULL COMMENT 'วันที่บรรจุ/เริ่มรับราชการ',
  `college_start_date` DATE         DEFAULT NULL COMMENT 'วันที่เริ่มปฏิบัติงานที่วิทยาลัย',
  `standing_date`      DATE         DEFAULT NULL COMMENT 'วันที่ได้รับวิทยฐานะปัจจุบัน',
  `duties`             TEXT         DEFAULT NULL COMMENT 'งาน/หน้าที่ที่ได้รับมอบหมาย',
  `verified_by`        INT UNSIGNED DEFAULT NULL,
  `verified_at`        DATETIME     DEFAULT NULL COMMENT 'งานบุคลากรตรวจสอบแล้ว (เจ้าของแก้ไขข้อมูล = ล้างค่า)',
  `updated_by`         INT UNSIGNED DEFAULT NULL,
  `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME     DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_hr_profiles_type` (`staff_type`),
  CONSTRAINT `fk_hr_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ทะเบียนประวัติบุคลากร';

CREATE TABLE `hr_educations` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `level`       VARCHAR(50)  NOT NULL COMMENT 'ระดับการศึกษา',
  `degree`      VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ชื่อปริญญา/วุฒิ เช่น ค.อ.บ.',
  `major`       VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'สาขาวิชา',
  `institution` VARCHAR(200) NOT NULL DEFAULT '',
  `grad_year`   SMALLINT UNSIGNED DEFAULT NULL COMMENT 'ปีที่สำเร็จ (พ.ศ.)',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_educations_user` (`user_id`),
  CONSTRAINT `fk_hr_educations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='วุฒิการศึกษา';

CREATE TABLE `hr_service_records` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `record_date` DATE         NOT NULL COMMENT 'วันที่มีผล',
  `position`    VARCHAR(150) NOT NULL COMMENT 'ตำแหน่ง',
  `standing`    VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'วิทยฐานะ/ระดับ',
  `unit`        VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'สังกัด/หน่วยงาน',
  `order_ref`   VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'เอกสารอ้างอิง/คำสั่ง',
  `note`        VARCHAR(300) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_service_user` (`user_id`, `record_date`),
  CONSTRAINT `fk_hr_service_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ประวัติการรับราชการ/การดำรงตำแหน่ง';

CREATE TABLE `hr_leaves` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED NOT NULL,
  `fiscal_year`     SMALLINT UNSIGNED NOT NULL COMMENT 'ปีงบประมาณ (พ.ศ.) ของวันเริ่มลา',
  `leave_type`      VARCHAR(20)  NOT NULL COMMENT 'sick personal vacation maternity paternity ordination other',
  `start_date`      DATE         NOT NULL,
  `end_date`        DATE         NOT NULL,
  `half_day`        VARCHAR(10)  NOT NULL DEFAULT '' COMMENT 'ลาครึ่งวัน (ลา 1 วัน): morning / afternoon',
  `days`            DECIMAL(5,1) NOT NULL COMMENT 'จำนวนวันลา (งานบุคลากรปรับได้ตอนตรวจสอบ)',
  `reason`          VARCHAR(1000) NOT NULL DEFAULT '',
  `contact`         VARCHAR(300) NOT NULL DEFAULT '' COMMENT 'ระหว่างลาติดต่อได้ที่',
  `substitute`      VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ผู้ปฏิบัติงาน/สอนแทน',
  `attach_stored`   VARCHAR(100) DEFAULT NULL COMMENT 'ไฟล์แนบ เช่น ใบรับรองแพทย์ (uploads/hr)',
  `attach_original` VARCHAR(200) DEFAULT NULL,
  `status`          VARCHAR(20)  NOT NULL DEFAULT 'pending_head'
                    COMMENT 'pending_head pending_hr pending_deputy pending_director approved rejected cancelled',
  `recorded_by`     INT UNSIGNED DEFAULT NULL COMMENT 'งานบุคลากรบันทึกแทน (ใบลากระดาษ/ย้อนหลัง)',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_leaves_user` (`user_id`, `fiscal_year`),
  KEY `idx_hr_leaves_status` (`status`),
  CONSTRAINT `fk_hr_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ใบลา';

CREATE TABLE `hr_leave_actions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `leave_id`   INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED DEFAULT NULL,
  `step`       VARCHAR(20)  NOT NULL COMMENT 'submit head hr deputy director cancel record',
  `decision`   VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'approve reject (ความเห็น/ผลพิจารณา)',
  `comment`    VARCHAR(1000) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_leave_actions_leave` (`leave_id`),
  CONSTRAINT `fk_hr_leave_actions_leave` FOREIGN KEY (`leave_id`) REFERENCES `hr_leaves` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hr_leave_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ลำดับการพิจารณาใบลา';

CREATE TABLE `hr_leave_quotas` (
  `user_id`       INT UNSIGNED NOT NULL,
  `fiscal_year`   SMALLINT UNSIGNED NOT NULL,
  `vacation_days` DECIMAL(4,1) NOT NULL COMMENT 'สิทธิ์ลาพักผ่อนปีนี้ รวมวันสะสม',
  PRIMARY KEY (`user_id`, `fiscal_year`),
  CONSTRAINT `fk_hr_leave_quotas_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='สิทธิ์วันลาพักผ่อนรายปีงบประมาณ';

CREATE TABLE `hr_trainings` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `fiscal_year`    SMALLINT UNSIGNED NOT NULL,
  `category`       VARCHAR(50)  NOT NULL COMMENT 'อบรม สัมมนา ประชุมวิชาการ ศึกษาดูงาน อบรมออนไลน์ อื่น ๆ',
  `title`          VARCHAR(300) NOT NULL,
  `organizer`      VARCHAR(200) NOT NULL DEFAULT '',
  `location`       VARCHAR(200) NOT NULL DEFAULT '',
  `start_date`     DATE         NOT NULL,
  `end_date`       DATE         NOT NULL,
  `hours`          DECIMAL(6,1) NOT NULL DEFAULT 0,
  `detail`         VARCHAR(2000) NOT NULL DEFAULT '' COMMENT 'สิ่งที่ได้รับ/การนำไปใช้',
  `cert_stored`    VARCHAR(100) DEFAULT NULL COMMENT 'เกียรติบัตร (uploads/hr)',
  `cert_original`  VARCHAR(200) DEFAULT NULL,
  `status`         ENUM('pending','approved','revision') NOT NULL DEFAULT 'pending',
  `review_comment` VARCHAR(1000) NOT NULL DEFAULT '',
  `reviewed_by`    INT UNSIGNED DEFAULT NULL,
  `reviewed_at`    DATETIME DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_trainings_user` (`user_id`, `fiscal_year`),
  KEY `idx_hr_trainings_status` (`status`),
  CONSTRAINT `fk_hr_trainings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การพัฒนาตนเอง/อบรม';

CREATE TABLE `hr_orders` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_no`      VARCHAR(50)  NOT NULL COMMENT 'เลขที่คำสั่ง เช่น 123/2569',
  `order_date`    DATE         NOT NULL,
  `category`      VARCHAR(50)  NOT NULL,
  `title`         VARCHAR(300) NOT NULL,
  `detail`        VARCHAR(2000) NOT NULL DEFAULT '',
  `file_stored`   VARCHAR(100) DEFAULT NULL COMMENT 'ไฟล์คำสั่ง (uploads/hr)',
  `file_original` VARCHAR(200) DEFAULT NULL,
  `is_public`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = บุคลากรทุกคนเห็น / 0 = เฉพาะผู้มีชื่อในคำสั่ง',
  `created_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_orders_date` (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='คำสั่ง/หนังสือราชการ';

CREATE TABLE `hr_order_users` (
  `order_id` INT UNSIGNED NOT NULL,
  `user_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`order_id`, `user_id`),
  KEY `idx_hr_order_users_user` (`user_id`),
  CONSTRAINT `fk_hr_order_users_order` FOREIGN KEY (`order_id`) REFERENCES `hr_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hr_order_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='บุคลากรที่มีชื่อในคำสั่ง';

CREATE TABLE `hr_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fiscal_year` SMALLINT UNSIGNED NOT NULL,
  `title`       VARCHAR(300) NOT NULL,
  `event_date`  DATE         NOT NULL,
  `start_time`  TIME         DEFAULT NULL,
  `location`    VARCHAR(200) NOT NULL DEFAULT '',
  `dress_code`  VARCHAR(200) NOT NULL DEFAULT '' COMMENT 'การแต่งกาย',
  `detail`      VARCHAR(2000) NOT NULL DEFAULT '',
  `is_required` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = บุคลากรทุกคนต้องเข้าร่วม (นับในสรุป)',
  `self_report` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1 = บุคลากรรายงานการเข้าร่วมเองได้',
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_hr_events_date` (`fiscal_year`, `event_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='กิจกรรมวันสำคัญ';

CREATE TABLE `hr_event_attendances` (
  `event_id`       INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `status`         ENUM('attended','leave','absent') NOT NULL DEFAULT 'attended' COMMENT 'เข้าร่วม / ลา-ไปราชการ / ไม่เข้าร่วม',
  `note`           VARCHAR(300) NOT NULL DEFAULT '',
  `photo_stored`   VARCHAR(100) DEFAULT NULL COMMENT 'ภาพหลักฐาน (uploads/hr)',
  `photo_original` VARCHAR(200) DEFAULT NULL,
  `verified`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = งานบุคลากรยืนยันแล้ว',
  `recorded_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`, `user_id`),
  KEY `idx_hr_attend_user` (`user_id`),
  CONSTRAINT `fk_hr_attend_event` FOREIGN KEY (`event_id`) REFERENCES `hr_events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hr_attend_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='การเข้าร่วมกิจกรรมวันสำคัญ';

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('hr_vacation_days',   '10', 'สิทธิ์ลาพักผ่อนต่อปีงบประมาณ (ค่าเริ่มต้น เมื่อยังไม่กำหนดรายบุคคล)'),
('hr_deputy_position', 'รองผู้อำนวยการฝ่ายบริหารทรัพยากร', 'ตำแหน่งรองผู้อำนวยการที่พิจารณาใบลา (ไม่มีผู้ใช้ตำแหน่งนี้ = รองผู้อำนวยการทุกฝ่าย)');

-- ---------------------------------------------------------------------
-- กิจกรรมวันสำคัญ: กำหนดผู้เข้าร่วมรายกิจกรรม (ตามคำสั่งของกิจกรรมนั้น)
--   participant_mode = all → บุคลากรทุกคน (ค่าเดิม) / selected → เฉพาะรายชื่อใน hr_event_participants
--   (ใช้ IF NOT EXISTS — นำเข้าซ้ำส่วนนี้ได้ ถ้าเคยนำเข้า update_v1.19.sql ฉบับก่อนหน้าแล้ว)
-- ---------------------------------------------------------------------
ALTER TABLE `hr_events`
  ADD COLUMN IF NOT EXISTS `participant_mode` VARCHAR(10) NOT NULL DEFAULT 'all'
    COMMENT 'all = บุคลากรทุกคน / selected = เฉพาะผู้ที่กำหนด' AFTER `self_report`,
  ADD COLUMN IF NOT EXISTS `order_id` INT UNSIGNED DEFAULT NULL COMMENT 'คำสั่งที่อ้างอิง (hr_orders)' AFTER `participant_mode`;

CREATE TABLE IF NOT EXISTS `hr_event_participants` (
  `event_id` INT UNSIGNED NOT NULL,
  `user_id`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`event_id`, `user_id`),
  KEY `idx_hr_event_part_user` (`user_id`),
  CONSTRAINT `fk_hr_event_part_event` FOREIGN KEY (`event_id`) REFERENCES `hr_events` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_hr_event_part_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='ผู้ที่ต้องเข้าร่วมกิจกรรม (เมื่อ participant_mode = selected)';
