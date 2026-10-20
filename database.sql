-- =====================================================================
-- TeachHub PT — ระบบการส่งงานและรายงานกิจกรรม
-- วิทยาลัยการอาชีพโพนทอง
-- ไฟล์: database.sql (โครงสร้างฐานข้อมูล + ข้อมูลเริ่มต้นที่จำเป็น)
-- ผู้พัฒนาระบบ: ครูสมควร สากำ
-- รองรับ MySQL 5.7+ / MariaDB 10.3+ , charset utf8mb4
--
-- วิธีใช้: สร้างฐานข้อมูล (utf8mb4_unicode_ci) แล้ว "เลือกฐานข้อมูลนั้น" ก่อนนำเข้าไฟล์นี้
--   phpMyAdmin : เลือกฐานข้อมูล > Import > เลือกไฟล์ database.sql
--   Command    : mysql -u root -p --default-character-set=utf8mb4 teachhub_pt < database.sql
-- ข้อมูลทดลอง (ครู/หัวหน้าแผนก/ผู้บริหาร/รอบการส่งตัวอย่าง) อยู่ในไฟล์ sample_data.sql แยกต่างหาก
-- ** คำเตือน: ไฟล์นี้ลบตารางเดิมทั้งหมดก่อนสร้างใหม่ **
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ลบตารางเดิม (กรณีติดตั้งใหม่)
DROP TABLE IF EXISTS `activity_logs`, `password_reset_requests`, `board_replies`, `board_topics`, `settings`, `downloads`,
  `home_visit_images`, `home_visits`, `advisor_report_images`, `advisor_reports`, `advisor_skip_dates`, `advisor_groups`,
  `supervision_report_actions`, `supervision_reports`,
  `student_eval_scores`, `student_eval_responses`, `student_eval_sessions`,
  `announcements`, `supervision_images`, `supervision_scores`, `supervision_results`,
  `supervision_criteria`, `supervision_schedules`, `reviews`, `submission_files`,
  `research_submissions`, `pa_submissions`, `lesson_plans`, `teaching_subjects`, `submission_rounds`,
  `subjects`, `academic_years`, `users`, `departments`;

-- ---------------------------------------------------------------------
-- 1) แผนกวิชา
-- ---------------------------------------------------------------------
CREATE TABLE `departments` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(20)  NOT NULL,
  `name`        VARCHAR(150) NOT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) ผู้ใช้งาน (ครู / หัวหน้าแผนก / admin / ผู้บริหาร)
-- ---------------------------------------------------------------------
CREATE TABLE `users` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`              VARCHAR(50)  NOT NULL,
  `password_hash`         VARCHAR(255) NOT NULL,
  `prefix`                VARCHAR(30)  NOT NULL DEFAULT '',
  `first_name`            VARCHAR(100) NOT NULL,
  `last_name`             VARCHAR(100) NOT NULL,
  `department_id`         INT UNSIGNED NULL,
  `position`              VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'ตำแหน่ง เช่น ครู, ครูผู้ช่วย, พนักงานราชการ',
  `academic_standing`     VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'วิทยฐานะ',
  `email`                 VARCHAR(150) NOT NULL,
  `phone`                 VARCHAR(20)  NOT NULL DEFAULT '',
  `avatar`                VARCHAR(255) NULL,
  `role`                  ENUM('admin','supervisor','teacher','executive') NOT NULL DEFAULT 'teacher',
  `status`                ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  `must_change_password`  TINYINT(1)   NOT NULL DEFAULT 0,
  `google_sub`            VARCHAR(64)  NULL COMMENT 'รหัสบัญชี Google ที่เชื่อมไว้ (เข้าสู่ระบบด้วย Google)',
  `password_login`        TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = สมัครด้วย Google ยังไม่เคยตั้งรหัสผ่าน',
  `pdpa_version`          VARCHAR(20)  NULL COMMENT 'ฉบับของข้อตกลง PDPA ที่ยอมรับล่าสุด',
  `pdpa_accepted_at`      DATETIME     NULL COMMENT 'วันเวลาที่ยอมรับข้อตกลง PDPA',
  `last_login_at`         DATETIME     NULL,
  `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_google` (`google_sub`),
  KEY `idx_users_role_status` (`role`, `status`),
  KEY `idx_users_department` (`department_id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3) ปีการศึกษา / ภาคเรียน (1 แถว = 1 ภาคเรียน)
-- ---------------------------------------------------------------------
CREATE TABLE `academic_years` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `year`        SMALLINT UNSIGNED NOT NULL COMMENT 'ปีการศึกษา (พ.ศ.)',
  `semester`    TINYINT UNSIGNED  NOT NULL COMMENT '1, 2 หรือ 3 (ฤดูร้อน)',
  `start_date`  DATE NULL,
  `end_date`    DATE NULL,
  `is_current`  TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_academic_year_semester` (`year`, `semester`),
  KEY `idx_academic_current` (`is_current`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4) รายวิชา
-- ---------------------------------------------------------------------
CREATE TABLE `subjects` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(20)  NOT NULL,
  `name`          VARCHAR(200) NOT NULL,
  `credit`        VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'ท-ป-น เช่น 1-3-2',
  `level`         ENUM('ปวช.','ปวส.') NOT NULL DEFAULT 'ปวช.',
  `department_id` INT UNSIGNED NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_subjects_code` (`code`),
  KEY `idx_subjects_department` (`department_id`),
  CONSTRAINT `fk_subjects_department` FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5) รอบการส่งงาน (กำหนดเปิด-ปิด)
--    type  : lesson_plan / pa / research
--    phase : PA -> agreement (ข้อตกลงต้นปี) / report (รายงานผลปลายปี)
--            วิจัย -> proposal (เค้าโครง) / final (ฉบับสมบูรณ์)
--            แผนการสอน -> main (รอบเดียวรับทั้งโครงการสอนและแผนการจัดการเรียนรู้ ครูเลือกประเภทในฟอร์ม)
--                         outline = ค่าเดิมก่อนเวอร์ชัน 1.8 (ไม่ใช้แล้ว)
-- ---------------------------------------------------------------------
CREATE TABLE `submission_rounds` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`             ENUM('lesson_plan','pa','research') NOT NULL,
  `phase`            ENUM('main','outline','agreement','report','proposal','final') NOT NULL DEFAULT 'main',
  `name`             VARCHAR(200) NOT NULL,
  `academic_year_id` INT UNSIGNED NULL,
  `fiscal_year`      SMALLINT UNSIGNED NULL COMMENT 'ปีงบประมาณ (ใช้กับ PA)',
  `description`      TEXT NULL,
  `open_at`          DATETIME NOT NULL,
  `close_at`         DATETIME NOT NULL,
  `allow_late`       TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`       INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rounds_type_active` (`type`, `is_active`),
  KEY `idx_rounds_dates` (`open_at`, `close_at`),
  KEY `idx_rounds_academic` (`academic_year_id`),
  KEY `idx_rounds_created_by` (`created_by`),
  CONSTRAINT `fk_rounds_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_rounds_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5.1) รายวิชาที่ครูสอนในแต่ละภาคเรียน (ครูบันทึกเอง)
--      ใช้ตรวจเกณฑ์: ส่งโครงการสอนครบทุกรายวิชา + แผนการจัดการเรียนรู้อย่างน้อย 1 วิชา
-- ---------------------------------------------------------------------
CREATE TABLE `teaching_subjects` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `teacher_id`       INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `subject_code`     VARCHAR(20)  NOT NULL,
  `subject_name`     VARCHAR(200) NOT NULL,
  `level`            ENUM('ปวช.','ปวส.') NOT NULL DEFAULT 'ปวช.',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teaching_subject` (`teacher_id`, `academic_year_id`, `subject_code`),
  KEY `idx_teaching_ay` (`academic_year_id`),
  CONSTRAINT `fk_teaching_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_teaching_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6) แผนการจัดการเรียนรู้ และโครงการสอนรายวิชา
--    doc_type : plan (แผนการจัดการเรียนรู้) / outline (โครงการสอน) — ครูเลือกจาก list ในฟอร์มส่ง
--               รอบเดียวกันส่งได้ทั้ง 2 ประเภทของวิชาเดียวกัน (อย่างละ 1 รายการ)
--    status : pending (รอตรวจ) / approved (ผ่าน) / revision (ให้แก้ไข)
-- ---------------------------------------------------------------------
CREATE TABLE `lesson_plans` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`         INT UNSIGNED NOT NULL,
  `doc_type`         ENUM('plan','outline') NOT NULL DEFAULT 'plan',
  `teacher_id`       INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NULL,
  `subject_id`       INT UNSIGNED NULL,
  `subject_code`     VARCHAR(20)  NOT NULL,
  `subject_name`     VARCHAR(200) NOT NULL,
  `level`            ENUM('ปวช.','ปวส.') NOT NULL DEFAULT 'ปวช.',
  `units`            SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'จำนวนรายวิชา',
  `status`           ENUM('pending','approved','revision') NOT NULL DEFAULT 'pending',
  `is_late`          TINYINT(1) NOT NULL DEFAULT 0,
  `current_version`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `submitted_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lesson_round_teacher_subject` (`round_id`, `teacher_id`, `doc_type`, `subject_code`),
  KEY `idx_lesson_teacher` (`teacher_id`, `academic_year_id`, `doc_type`),
  KEY `idx_lesson_status` (`status`),
  KEY `idx_lesson_academic` (`academic_year_id`),
  KEY `idx_lesson_subject` (`subject_id`),
  CONSTRAINT `fk_lesson_round` FOREIGN KEY (`round_id`)
    REFERENCES `submission_rounds` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_lesson_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_lesson_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_lesson_subject` FOREIGN KEY (`subject_id`)
    REFERENCES `subjects` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7) แบบเสนอข้อตกลงในการพัฒนางาน (PA)
-- ---------------------------------------------------------------------
CREATE TABLE `pa_submissions` (
  `id`                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`           INT UNSIGNED NOT NULL,
  `teacher_id`         INT UNSIGNED NOT NULL,
  `phase`              ENUM('agreement','report') NOT NULL DEFAULT 'agreement',
  `fiscal_year`        SMALLINT UNSIGNED NOT NULL COMMENT 'ปีงบประมาณ (พ.ศ.)',
  `position`           VARCHAR(100) NOT NULL DEFAULT '',
  `academic_standing`  VARCHAR(100) NOT NULL DEFAULT '',
  `part1_workload`     TEXT NULL COMMENT 'ส่วนที่ 1 ข้อตกลงภาระงาน',
  `part2_challenge`    TEXT NULL COMMENT 'ส่วนที่ 2 ประเด็นท้าทาย',
  `status`             ENUM('pending','approved','revision') NOT NULL DEFAULT 'pending',
  `is_late`            TINYINT(1) NOT NULL DEFAULT 0,
  `current_version`    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `submitted_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pa_round_teacher` (`round_id`, `teacher_id`),
  KEY `idx_pa_teacher` (`teacher_id`),
  KEY `idx_pa_status` (`status`),
  KEY `idx_pa_fiscal` (`fiscal_year`, `phase`),
  CONSTRAINT `fk_pa_round` FOREIGN KEY (`round_id`)
    REFERENCES `submission_rounds` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_pa_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8) วิจัยในชั้นเรียน (stage: proposal = เค้าโครง, final = ฉบับสมบูรณ์)
--    parent_id : ฉบับสมบูรณ์อ้างอิงกลับไปยังเค้าโครงที่ผ่านแล้ว
-- ---------------------------------------------------------------------
CREATE TABLE `research_submissions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `round_id`         INT UNSIGNED NOT NULL,
  `teacher_id`       INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NULL,
  `parent_id`        INT UNSIGNED NULL,
  `stage`            ENUM('proposal','final') NOT NULL DEFAULT 'proposal',
  `title`            VARCHAR(255) NOT NULL,
  `problem`          TEXT NULL COMMENT 'ปัญหาการวิจัย',
  `target_group`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'กลุ่มเป้าหมาย',
  `subject_id`       INT UNSIGNED NULL,
  `subject_text`     VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'วิชาที่เกี่ยวข้อง',
  `status`           ENUM('pending','approved','revision') NOT NULL DEFAULT 'pending',
  `is_late`          TINYINT(1) NOT NULL DEFAULT 0,
  `current_version`  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `submitted_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_research_round_teacher` (`round_id`, `teacher_id`),
  KEY `idx_research_teacher` (`teacher_id`),
  KEY `idx_research_status` (`status`),
  KEY `idx_research_parent` (`parent_id`),
  KEY `idx_research_academic` (`academic_year_id`),
  KEY `idx_research_subject` (`subject_id`),
  CONSTRAINT `fk_research_round` FOREIGN KEY (`round_id`)
    REFERENCES `submission_rounds` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_research_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_research_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_research_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `research_submissions` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_research_subject` FOREIGN KEY (`subject_id`)
    REFERENCES `subjects` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9) ไฟล์แนบของงานทุกประเภท (เก็บทุกเวอร์ชัน)
--    submission_type + submission_id = อ้างอิงแบบ polymorphic
-- ---------------------------------------------------------------------
CREATE TABLE `submission_files` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_type`  ENUM('lesson_plan','pa','research') NOT NULL,
  `submission_id`    INT UNSIGNED NOT NULL,
  `version`          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `original_name`    VARCHAR(255) NOT NULL,
  `stored_name`      VARCHAR(255) NOT NULL COMMENT 'ชื่อไฟล์สุ่มบนเซิร์ฟเวอร์',
  `file_dir`         VARCHAR(50)  NOT NULL COMMENT 'โฟลเดอร์ย่อยใน uploads/',
  `mime_type`        VARCHAR(150) NOT NULL,
  `file_size`        INT UNSIGNED NOT NULL DEFAULT 0,
  `is_late`          TINYINT(1)   NOT NULL DEFAULT 0,
  `note`             VARCHAR(500) NULL COMMENT 'หมายเหตุการส่ง/แก้ไข',
  `uploaded_by`      INT UNSIGNED NULL,
  `uploaded_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_files_stored_name` (`stored_name`),
  KEY `idx_files_submission` (`submission_type`, `submission_id`, `version`),
  KEY `idx_files_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_files_uploaded_by` FOREIGN KEY (`uploaded_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10) ผลการตรวจและความเห็นผู้ตรวจ (ทุกประเภทงาน)
-- ---------------------------------------------------------------------
CREATE TABLE `reviews` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `submission_type`  ENUM('lesson_plan','pa','research') NOT NULL,
  `submission_id`    INT UNSIGNED NOT NULL,
  `version`          SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `reviewer_id`      INT UNSIGNED NULL,
  `result`           ENUM('approved','revision') NOT NULL,
  `comment`          TEXT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reviews_submission` (`submission_type`, `submission_id`),
  KEY `idx_reviews_reviewer` (`reviewer_id`),
  CONSTRAINT `fk_reviews_reviewer` FOREIGN KEY (`reviewer_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11) ตารางนิเทศการสอน
-- ---------------------------------------------------------------------
CREATE TABLE `supervision_schedules` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `academic_year_id` INT UNSIGNED NULL,
  `teacher_id`       INT UNSIGNED NOT NULL,
  `supervisor_id`    INT UNSIGNED NOT NULL,
  `subject_id`       INT UNSIGNED NULL,
  `subject_name`     VARCHAR(200) NOT NULL DEFAULT '',
  `classroom`        VARCHAR(100) NOT NULL DEFAULT '',
  `supervise_date`   DATE NOT NULL,
  `start_time`       TIME NOT NULL,
  `end_time`         TIME NOT NULL,
  `status`           ENUM('scheduled','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `note`             VARCHAR(500) NULL,
  `created_by`       INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sup_sched_teacher` (`teacher_id`),
  KEY `idx_sup_sched_supervisor` (`supervisor_id`),
  KEY `idx_sup_sched_date` (`supervise_date`),
  KEY `idx_sup_sched_academic` (`academic_year_id`),
  KEY `idx_sup_sched_subject` (`subject_id`),
  KEY `idx_sup_sched_created_by` (`created_by`),
  CONSTRAINT `fk_sup_sched_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_sup_sched_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_sup_sched_supervisor` FOREIGN KEY (`supervisor_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_sup_sched_subject` FOREIGN KEY (`subject_id`)
    REFERENCES `subjects` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_sup_sched_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12) หัวข้อแบบประเมินนิเทศ (มาตราส่วน 5 ระดับ) — admin เพิ่ม/แก้ได้
-- ---------------------------------------------------------------------
CREATE TABLE `supervision_criteria` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `audience`    ENUM('supervisor','student') NOT NULL DEFAULT 'supervisor' COMMENT 'ชุดหัวข้อ: หัวหน้าแผนกนิเทศ / นักเรียนประเมิน',
  `category`    VARCHAR(150) NOT NULL COMMENT 'ด้านการประเมิน',
  `title`       VARCHAR(255) NOT NULL COMMENT 'รายการประเมิน',
  `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_criteria_audience` (`audience`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13) ผลการนิเทศ (1 ตารางนิเทศ = 1 ผล)
-- ---------------------------------------------------------------------
CREATE TABLE `supervision_results` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_id`      INT UNSIGNED NOT NULL,
  `supervisor_id`    INT UNSIGNED NULL,
  `avg_score`        DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  `quality_level`    VARCHAR(50)  NOT NULL DEFAULT '' COMMENT 'ดีมาก/ดี/ปานกลาง/พอใช้/ปรับปรุง',
  `strengths`        TEXT NULL COMMENT 'จุดเด่น',
  `suggestions`      TEXT NULL COMMENT 'ข้อเสนอแนะ',
  `acknowledged_at`  DATETIME NULL COMMENT 'วันเวลาที่ครูกดรับทราบ',
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sup_result_schedule` (`schedule_id`),
  KEY `idx_sup_result_supervisor` (`supervisor_id`),
  CONSTRAINT `fk_sup_result_schedule` FOREIGN KEY (`schedule_id`)
    REFERENCES `supervision_schedules` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_sup_result_supervisor` FOREIGN KEY (`supervisor_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14) คะแนนรายข้อของการนิเทศ (เก็บสำเนาชื่อหัวข้อไว้ เผื่อหัวข้อถูกแก้ภายหลัง)
-- ---------------------------------------------------------------------
CREATE TABLE `supervision_scores` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `result_id`       INT UNSIGNED NOT NULL,
  `criteria_id`     INT UNSIGNED NULL,
  `category`        VARCHAR(150) NOT NULL DEFAULT '',
  `criteria_title`  VARCHAR(255) NOT NULL DEFAULT '',
  `score`           TINYINT UNSIGNED NOT NULL COMMENT '1-5',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sup_score_result_criteria` (`result_id`, `criteria_id`),
  KEY `idx_sup_score_criteria` (`criteria_id`),
  CONSTRAINT `fk_sup_score_result` FOREIGN KEY (`result_id`)
    REFERENCES `supervision_results` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_sup_score_criteria` FOREIGN KEY (`criteria_id`)
    REFERENCES `supervision_criteria` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15) รูปภาพหลักฐานการนิเทศ
-- ---------------------------------------------------------------------
CREATE TABLE `supervision_images` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `result_id`      INT UNSIGNED NOT NULL,
  `original_name`  VARCHAR(255) NOT NULL,
  `stored_name`    VARCHAR(255) NOT NULL,
  `mime_type`      VARCHAR(100) NOT NULL,
  `file_size`      INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sup_images_stored` (`stored_name`),
  KEY `idx_sup_images_result` (`result_id`),
  CONSTRAINT `fk_sup_images_result` FOREIGN KEY (`result_id`)
    REFERENCES `supervision_results` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15.1) แบบประเมินการสอนโดยนักเรียน นักศึกษา (ไม่ต้องเป็นสมาชิก)
--       admin สร้าง 1 รายการ = ครู 1 คน + วิชา/ห้อง 1 ชุด → ได้ลิงก์/QR Code (token)
-- ---------------------------------------------------------------------
CREATE TABLE `student_eval_sessions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token`            CHAR(32) NOT NULL COMMENT 'รหัสสุ่มในลิงก์/QR Code',
  `academic_year_id` INT UNSIGNED NULL,
  `teacher_id`       INT UNSIGNED NOT NULL,
  `subject_id`       INT UNSIGNED NULL,
  `subject_name`     VARCHAR(200) NOT NULL DEFAULT '',
  `level`            ENUM('ปวช.','ปวส.') NULL COMMENT 'ระดับของกลุ่มนักเรียน',
  `grade_year`       TINYINT UNSIGNED NULL COMMENT 'ชั้นปี (ปวช. 1-3, ปวส. 1-2)',
  `group_no`         VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'กลุ่ม/ห้อง เช่น 1, 2, ชฟ.1',
  `classroom`        VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'ชื่อกลุ่มสำหรับแสดงผล เช่น ปวช. 1/1',
  `open_at`          DATETIME NOT NULL,
  `close_at`         DATETIME NOT NULL,
  `max_responses`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = ไม่จำกัด',
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `note`             VARCHAR(500) NULL,
  `created_by`       INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_student_eval_token` (`token`),
  KEY `idx_student_eval_teacher` (`teacher_id`),
  KEY `idx_student_eval_level` (`level`, `grade_year`),
  KEY `idx_student_eval_academic` (`academic_year_id`),
  KEY `idx_student_eval_subject` (`subject_id`),
  KEY `idx_student_eval_created_by` (`created_by`),
  CONSTRAINT `fk_student_eval_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_student_eval_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_student_eval_subject` FOREIGN KEY (`subject_id`)
    REFERENCES `subjects` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_student_eval_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- คำตอบของนักเรียน 1 คน (ไม่เก็บชื่อ/IP เพื่อความเป็นนิรนาม)
CREATE TABLE `student_eval_responses` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`  INT UNSIGNED NOT NULL,
  `avg_score`   DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  `comment`     TEXT NULL COMMENT 'ข้อเสนอแนะถึงครู',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_student_resp_session` (`session_id`),
  CONSTRAINT `fk_student_resp_session` FOREIGN KEY (`session_id`)
    REFERENCES `student_eval_sessions` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- คะแนนรายข้อของนักเรียน (เก็บสำเนาชื่อหัวข้อไว้ เผื่อหัวข้อถูกแก้ภายหลัง)
CREATE TABLE `student_eval_scores` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `response_id`     INT UNSIGNED NOT NULL,
  `criteria_id`     INT UNSIGNED NULL,
  `category`        VARCHAR(150) NOT NULL DEFAULT '',
  `criteria_title`  VARCHAR(255) NOT NULL DEFAULT '',
  `score`           TINYINT UNSIGNED NOT NULL COMMENT '1-5',
  PRIMARY KEY (`id`),
  KEY `idx_student_score_response` (`response_id`),
  KEY `idx_student_score_criteria` (`criteria_id`),
  CONSTRAINT `fk_student_score_response` FOREIGN KEY (`response_id`)
    REFERENCES `student_eval_responses` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_student_score_criteria` FOREIGN KEY (`criteria_id`)
    REFERENCES `supervision_criteria` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15.2) รายงานการนิเทศครูผู้สอน (หัวหน้าแผนกวิชาจัดทำ)
--       เสนอตามลำดับ: 1) หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้ → 2) รองผู้อำนวยการฝ่ายวิชาการ → 3) ผู้อำนวยการ
--       status: draft (ร่าง) / pending (รอพิจารณาขั้น current_step) / returned (ส่งกลับแก้ไข) / approved (ผู้อำนวยการลงนามแล้ว)
--       snapshot: ข้อมูลผลการนิเทศ ณ เวลาที่เสนอ (JSON) เพื่อให้รายงานที่เสนอแล้วไม่เปลี่ยนตามข้อมูลภายหลัง
-- ---------------------------------------------------------------------
CREATE TABLE `supervision_reports` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_id`    INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED NOT NULL,
  `author_id`        INT UNSIGNED NOT NULL COMMENT 'หัวหน้าแผนกผู้จัดทำ',
  `title`            VARCHAR(255) NOT NULL,
  `period_start`     DATE NULL COMMENT 'ช่วงวันที่นิเทศที่นำมารายงาน (ว่าง = ทั้งภาคเรียน)',
  `period_end`       DATE NULL,
  `summary_overall`  TEXT NULL COMMENT 'สรุปผลการนิเทศโดยภาพรวม',
  `strengths`        TEXT NULL COMMENT 'จุดเด่น',
  `problems`         TEXT NULL COMMENT 'ปัญหา/อุปสรรค',
  `suggestions`      TEXT NULL COMMENT 'ข้อเสนอแนะ/แนวทางพัฒนา',
  `status`           ENUM('draft','pending','returned','approved') NOT NULL DEFAULT 'draft',
  `current_step`     TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1-3 = ขั้นที่รอพิจารณา',
  `snapshot`         LONGTEXT NULL,
  `submitted_at`     DATETIME NULL,
  `approved_at`      DATETIME NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sup_report_dept` (`department_id`, `academic_year_id`),
  KEY `idx_sup_report_status` (`status`, `current_step`),
  KEY `idx_sup_report_author` (`author_id`),
  KEY `idx_sup_report_academic` (`academic_year_id`),
  CONSTRAINT `fk_sup_report_dept` FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_sup_report_academic` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_sup_report_author` FOREIGN KEY (`author_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ประวัติการเสนอ/พิจารณา (step 0 = ผู้จัดทำเสนอ, 1-3 = ผู้พิจารณาแต่ละขั้น)
CREATE TABLE `supervision_report_actions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`   INT UNSIGNED NOT NULL,
  `step`        TINYINT UNSIGNED NOT NULL,
  `user_id`     INT UNSIGNED NULL,
  `position`    VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ตำแหน่งของผู้พิจารณา ณ เวลานั้น',
  `action`      ENUM('submit','forward','approve','return') NOT NULL,
  `comment`     TEXT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sup_report_action_report` (`report_id`),
  KEY `idx_sup_report_action_user` (`user_id`),
  CONSTRAINT `fk_sup_report_action_report` FOREIGN KEY (`report_id`)
    REFERENCES `supervision_reports` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_sup_report_action_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16) ประกาศข่าวสาร
-- ---------------------------------------------------------------------
CREATE TABLE `announcements` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`         VARCHAR(255) NOT NULL,
  `content`       TEXT NOT NULL,
  `is_pinned`     TINYINT(1) NOT NULL DEFAULT 0,
  `is_published`  TINYINT(1) NOT NULL DEFAULT 1,
  `views`         INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`    INT UNSIGNED NULL,
  `published_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_announce_published` (`is_published`, `is_pinned`, `published_at`),
  KEY `idx_announce_created_by` (`created_by`),
  CONSTRAINT `fk_announce_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17) เอกสารดาวน์โหลด / แบบฟอร์มต้นฉบับ
-- ---------------------------------------------------------------------
CREATE TABLE `downloads` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`           VARCHAR(255) NOT NULL,
  `description`     VARCHAR(500) NULL,
  `category`        ENUM('lesson_plan','pa','research','supervision','other') NOT NULL DEFAULT 'other',
  `original_name`   VARCHAR(255) NULL,
  `stored_name`     VARCHAR(255) NULL,
  `mime_type`       VARCHAR(150) NULL,
  `file_size`       INT UNSIGNED NOT NULL DEFAULT 0,
  `download_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`      INT UNSIGNED NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_downloads_category` (`category`, `is_active`),
  KEY `idx_downloads_created_by` (`created_by`),
  CONSTRAINT `fk_downloads_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18) ตั้งค่าระบบ (key-value)
-- ---------------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key`    VARCHAR(100) NOT NULL,
  `setting_value`  TEXT NULL,
  `description`    VARCHAR(255) NULL,
  `updated_at`     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18.1) กระดานข่าว — ช่องทางติดต่อประสานงาน (โพสต์/อ่านได้เฉพาะสมาชิก)
--       category : general (ประสานงานทั่วไป) / question (สอบถามการใช้งาน) / feature (เสนอพัฒนาระบบ) / bug (แจ้งปัญหา)
--       status   : open (รอตอบ) / answered (ตอบแล้ว) / planned (รับไว้พัฒนา) / done (ดำเนินการแล้ว) / closed (ปิดกระทู้)
-- ---------------------------------------------------------------------
CREATE TABLE `board_topics` (
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

CREATE TABLE `board_replies` (
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

-- ---------------------------------------------------------------------
-- 18.2) รายงานการพบนักเรียนของครูที่ปรึกษา (ส่งทุกวันพุธ)
--       advisor_groups    : กลุ่มเรียนที่ปรึกษารายภาคเรียน (งานครูที่ปรึกษาและแนะแนวเป็นผู้กำหนด)
--       advisor_reports   : 1 กลุ่ม ต่อ 1 วันพุธ  status: pending (รอตรวจ) / approved (ผ่าน) / revision (ให้แก้ไข)
--       advisor_skip_dates: วันพุธที่งดพบ ไม่นับเป็นขาดส่ง
-- ---------------------------------------------------------------------
CREATE TABLE `advisor_groups` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `academic_year_id`  INT UNSIGNED NOT NULL,
  `teacher_id`        INT UNSIGNED NOT NULL COMMENT 'ครูที่ปรึกษา',
  `department_id`     INT UNSIGNED NULL COMMENT 'แผนกของกลุ่มเรียน',
  `group_name`        VARCHAR(100) NOT NULL COMMENT 'ชื่อกลุ่มเรียน เช่น ปวช.1/1 ช่างยนต์',
  `student_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`        INT UNSIGNED NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_group` (`academic_year_id`, `teacher_id`, `group_name`),
  KEY `idx_advisor_group_teacher` (`teacher_id`),
  KEY `idx_advisor_group_dept` (`department_id`),
  CONSTRAINT `fk_advisor_group_ay` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_group_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_group_dept` FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_advisor_group_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `advisor_reports` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`        INT UNSIGNED NOT NULL,
  `teacher_id`      INT UNSIGNED NOT NULL,
  `meet_date`       DATE NOT NULL COMMENT 'วันพุธที่พบนักเรียน',
  `total_students`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `present_count`   SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'มา',
  `late_count`      SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'มาสาย',
  `leave_count`     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ลา',
  `absent_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ขาด',
  `topics`          TEXT NOT NULL COMMENT 'เรื่องที่พูดคุย/แจ้งนักเรียน',
  `problems`        TEXT NULL COMMENT 'นักเรียนที่มีปัญหาและการช่วยเหลือ',
  `status`          ENUM('pending','approved','revision') NOT NULL DEFAULT 'pending',
  `is_late`         TINYINT(1) NOT NULL DEFAULT 0,
  `review_comment`  TEXT NULL,
  `reviewed_by`     INT UNSIGNED NULL,
  `reviewed_at`     DATETIME NULL,
  `submitted_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_report_week` (`group_id`, `meet_date`),
  KEY `idx_advisor_report_teacher` (`teacher_id`, `meet_date`),
  KEY `idx_advisor_report_status` (`status`),
  KEY `idx_advisor_report_date` (`meet_date`),
  CONSTRAINT `fk_advisor_report_group` FOREIGN KEY (`group_id`)
    REFERENCES `advisor_groups` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_report_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_advisor_report_reviewer` FOREIGN KEY (`reviewed_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `advisor_report_images` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id`      INT UNSIGNED NOT NULL,
  `original_name`  VARCHAR(255) NOT NULL,
  `stored_name`    VARCHAR(255) NOT NULL,
  `mime_type`      VARCHAR(100) NOT NULL,
  `file_size`      INT UNSIGNED NOT NULL DEFAULT 0,
  `uploaded_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_images_stored` (`stored_name`),
  KEY `idx_advisor_images_report` (`report_id`),
  CONSTRAINT `fk_advisor_images_report` FOREIGN KEY (`report_id`)
    REFERENCES `advisor_reports` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `advisor_skip_dates` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `skip_date`   DATE NOT NULL,
  `reason`      VARCHAR(200) NOT NULL DEFAULT '',
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_advisor_skip_date` (`skip_date`),
  CONSTRAINT `fk_advisor_skip_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18.3) รายงานการเยี่ยมบ้านนักเรียนของครูที่ปรึกษา (นักเรียน 1 คน เยี่ยม 1 ครั้งต่อปีการศึกษา)
--       risk_level: normal (ปกติ) / risk (เสี่ยง) / problem (มีปัญหา)
--       status    : pending (รอตรวจ) / approved (ผ่าน) / revision (ให้แก้ไข)
-- ---------------------------------------------------------------------
CREATE TABLE `home_visits` (
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

CREATE TABLE `home_visit_images` (
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

-- ---------------------------------------------------------------------
-- 19) คำขอรีเซ็ตรหัสผ่าน (ลืมรหัสผ่าน -> admin เป็นผู้รีเซ็ต)
-- ---------------------------------------------------------------------
CREATE TABLE `password_reset_requests` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `status`        ENUM('pending','done','rejected') NOT NULL DEFAULT 'pending',
  `request_note`  VARCHAR(255) NULL,
  `requested_ip`  VARCHAR(45) NOT NULL DEFAULT '',
  `handled_by`    INT UNSIGNED NULL,
  `handled_at`    DATETIME NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pwreset_status` (`status`),
  KEY `idx_pwreset_user` (`user_id`),
  KEY `idx_pwreset_handled_by` (`handled_by`),
  CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_pwreset_handled_by` FOREIGN KEY (`handled_by`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20) บันทึกการใช้งาน (Log)
-- ---------------------------------------------------------------------
CREATE TABLE `activity_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NULL,
  `action`      VARCHAR(100) NOT NULL,
  `detail`      VARCHAR(1000) NULL,
  `ip_address`  VARCHAR(45) NOT NULL DEFAULT '',
  `user_agent`  VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_user` (`user_id`),
  KEY `idx_logs_action` (`action`),
  KEY `idx_logs_created` (`created_at`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- ข้อมูลเริ่มต้นที่จำเป็น (ใช้งานจริงได้ทันที)
-- =====================================================================

-- แผนกวิชา
INSERT INTO `departments` (`id`, `code`, `name`) VALUES
(1, 'AUTO',  'แผนกวิชาช่างยนต์'),
(2, 'POWER', 'แผนกวิชาช่างไฟฟ้ากำลัง'),
(3, 'ELEC',  'แผนกวิชาช่างอิเล็กทรอนิกส์'),
(4, 'WELD',  'แผนกวิชาช่างเชื่อมโลหะ'),
(5, 'CONST', 'แผนกวิชาช่างก่อสร้าง-โยธา'),
(6, 'ACC',   'แผนกวิชาการบัญชี'),
(7, 'DBT',   'แผนกวิชาเทคโนโลยีธุรกิจดิจิทัล'),
(8, 'GEN',   'แผนกวิชาสามัญสัมพันธ์'),
(9, 'MECH',  'แผนกวิชาช่างกลโรงงาน'),
(10, 'MKT',  'แผนกวิชาการตลาด'),
(11, 'HOTEL','แผนกวิชาการโรงแรม');

-- บัญชีผู้ดูแลระบบเริ่มต้น: admin / admin1234
-- ** ระบบบังคับให้เปลี่ยนรหัสผ่านเมื่อเข้าสู่ระบบครั้งแรก **
INSERT INTO `users`
(`id`, `username`, `password_hash`, `prefix`, `first_name`, `last_name`, `department_id`, `position`, `academic_standing`, `email`, `phone`, `role`, `status`, `must_change_password`) VALUES
(1, 'admin', '$2y$10$w1Um.4L0jJWMyJwBo6yBH.BrI1yUhE6eei5wrWd5dA0WjeWmlPsw6', 'นาย', 'ผู้ดูแล', 'ระบบ', NULL, 'เจ้าหน้าที่งานพัฒนาหลักสูตรและการจัดการเรียนรู้', '', 'admin@teachhub.local', '', 'admin', 'active', 1);

-- ปีการศึกษา/ภาคเรียน (แก้ไข/เพิ่มได้ที่เมนู ปีการศึกษา/ภาคเรียน)
INSERT INTO `academic_years` (`id`, `year`, `semester`, `start_date`, `end_date`, `is_current`) VALUES
(1, 2569, 2, '2026-11-01', '2027-03-31', 1),
(2, 2570, 1, '2027-05-16', '2027-10-15', 0),
(3, 2570, 2, '2027-11-01', '2028-03-31', 0);

-- รายวิชาเริ่มต้น (เพิ่มได้ที่เมนู รายวิชา)
INSERT INTO `subjects` (`code`, `name`, `credit`, `level`, `department_id`) VALUES
('20104-2003', 'เครื่องยนต์แก๊สโซลีนและดีเซล',  '2-6-4', 'ปวช.', 1),
('20104-2005', 'งานส่งกำลังรถยนต์',            '1-6-3', 'ปวช.', 1),
('20104-2101', 'วงจรไฟฟ้ากระแสตรง',            '1-3-2', 'ปวช.', 2),
('20104-2106', 'การติดตั้งไฟฟ้าในอาคาร',         '1-6-3', 'ปวช.', 2),
('30104-2001', 'การออกแบบระบบไฟฟ้า',            '2-3-3', 'ปวส.', 2),
('20105-2001', 'อิเล็กทรอนิกส์อุตสาหกรรม',        '1-3-2', 'ปวช.', 3),
('20201-2001', 'การบัญชีเบื้องต้น 1',              '2-2-3', 'ปวช.', 6),
('20204-2001', 'คอมพิวเตอร์และการบำรุงรักษา',      '1-3-2', 'ปวช.', 7),
('20000-1101', 'ภาษาไทยพื้นฐาน',                 '1-2-2', 'ปวช.', 8);

-- หัวข้อแบบประเมินนิเทศการสอน (แก้ไขได้ที่เมนู หัวข้อแบบประเมิน)
INSERT INTO `supervision_criteria` (`category`, `title`, `sort_order`) VALUES
('ด้านการเตรียมการสอน',      'มีแผนการจัดการเรียนรู้ที่สอดคล้องกับหลักสูตรและคำอธิบายรายวิชา', 1),
('ด้านการเตรียมการสอน',      'เตรียมสื่อ วัสดุ อุปกรณ์ และสถานที่พร้อมก่อนสอน',                2),
('ด้านการจัดการเรียนรู้',      'แจ้งจุดประสงค์การเรียนรู้และเชื่อมโยงความรู้เดิมของผู้เรียน',       4),
('ด้านการจัดการเรียนรู้',      'จัดกิจกรรมที่เน้นผู้เรียนเป็นสำคัญและฝึกปฏิบัติจริง',               6),
('ด้านการจัดการเรียนรู้',      'สอดแทรกคุณธรรม จริยธรรม และความปลอดภัยในการปฏิบัติงาน',          8),
('ด้านการใช้สื่อและเทคโนโลยี', 'ใช้สื่อและเทคโนโลยีเหมาะสมกับเนื้อหาและผู้เรียน',                   10),
('ด้านการบริหารจัดการชั้นเรียน','ควบคุมชั้นเรียนได้ดี บรรยากาศเอื้อต่อการเรียนรู้',                    12),
('ด้านการวัดและประเมินผล',    'วัดและประเมินผลสอดคล้องกับจุดประสงค์ด้วยวิธีที่หลากหลาย',          14),
-- รายการที่เพิ่มในเวอร์ชัน 1.5 (รวม 15 ข้อ)
('ด้านการเตรียมการสอน',      'เข้าสอนตรงเวลาและใช้เวลาสอนครบตามที่กำหนด',                      3),
('ด้านการจัดการเรียนรู้',      'อธิบายเนื้อหาและสาธิตการปฏิบัติงานได้ชัดเจน ถูกต้องตามหลักวิชา',    5),
('ด้านการจัดการเรียนรู้',      'ใช้คำถามกระตุ้นการคิด และเปิดโอกาสให้ผู้เรียนซักถามแสดงความคิดเห็น', 7),
('ด้านการจัดการเรียนรู้',      'สรุปบทเรียนร่วมกับผู้เรียน และเชื่อมโยงสู่การนำไปใช้ในงานอาชีพ',       9),
('ด้านการใช้สื่อและเทคโนโลยี', 'ใช้แหล่งเรียนรู้หรือเทคโนโลยีดิจิทัลส่งเสริมการเรียนรู้ด้วยตนเอง',    11),
('ด้านการบริหารจัดการชั้นเรียน','ดูแลเอาใจใส่ผู้เรียนอย่างทั่วถึง และช่วยเหลือผู้เรียนที่มีปัญหา',        13),
('ด้านการวัดและประเมินผล',    'แจ้งผลการประเมินและให้ข้อมูลย้อนกลับเพื่อพัฒนาผู้เรียน',            15);

-- หัวข้อแบบประเมินการสอนโดยนักเรียน นักศึกษา (ชุดแยก ภาษาที่นักเรียนเข้าใจง่าย)
INSERT INTO `supervision_criteria` (`audience`, `category`, `title`, `sort_order`) VALUES
('student', 'ด้านการสอนของครู',              'ครูเตรียมการสอนและเข้าสอนตรงเวลา',                       1),
('student', 'ด้านการสอนของครู',              'ครูอธิบายเนื้อหาชัดเจน เข้าใจง่าย',                         2),
('student', 'ด้านการสอนของครู',              'ครูยกตัวอย่างหรือสาธิตการปฏิบัติงานให้เห็นจริง',              3),
('student', 'ด้านการสอนของครู',              'ครูเปิดโอกาสให้ซักถามและแสดงความคิดเห็น',                  4),
('student', 'ด้านกิจกรรมและสื่อการเรียนรู้',     'กิจกรรมการเรียนน่าสนใจ ได้ลงมือปฏิบัติจริง',                 5),
('student', 'ด้านกิจกรรมและสื่อการเรียนรู้',     'ครูใช้สื่อหรือเทคโนโลยีช่วยให้เรียนเข้าใจง่ายขึ้น',               6),
('student', 'ด้านการวัดและประเมินผล',         'ครูแจ้งเกณฑ์การให้คะแนนชัดเจน',                            7),
('student', 'ด้านการวัดและประเมินผล',         'ครูให้คำแนะนำเมื่อนักเรียนทำงานผิดพลาด',                     8),
('student', 'ด้านบุคลิกภาพและการดูแลผู้เรียน',  'ครูเป็นกันเองและให้ความเป็นธรรมกับนักเรียนทุกคน',              9),
('student', 'ด้านบุคลิกภาพและการดูแลผู้เรียน',  'ครูเน้นความปลอดภัยและสอดแทรกคุณธรรมจริยธรรม',               10);

-- ประกาศแรก
INSERT INTO `announcements` (`title`, `content`, `is_pinned`, `created_by`) VALUES
('เปิดใช้งานระบบ TeachHub PT', 'งานพัฒนาหลักสูตรและการจัดการเรียนรู้ ขอแจ้งให้ครูทุกท่านสมัครสมาชิกและส่งงานวิชาการผ่านระบบ TeachHub PT ตั้งแต่ภาคเรียนที่ 2/2569 เป็นต้นไป', 1, 1);

-- ตั้งค่าระบบ
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('college_name',        'วิทยาลัยการอาชีพโพนทอง',                         'ชื่อวิทยาลัย'),
('system_name',         'TeachHub PT',                                    'ชื่อระบบ'),
('system_fullname',     'ระบบการส่งงานและรายงานกิจกรรม',            'ชื่อเต็มของระบบ'),
('slogan',              'ทุกงานวิชาการครบ จบในที่เดียว',                      'สโลแกน'),
('department_name',     'งานพัฒนาหลักสูตรและการจัดการเรียนรู้',                    'ชื่อหน่วยงานที่ดูแลระบบ'),
('logo',                '',                                               'ไฟล์โลโก้ (ว่าง = ใช้โลโก้เริ่มต้น)'),
('max_upload_mb',       '30',                                             'ขนาดไฟล์สูงสุด (MB)'),
('allowed_extensions',  'pdf,docx',                                       'นามสกุลไฟล์งานที่อนุญาต (คั่นด้วย ,)'),
('allow_register',      '1',                                              'เปิดให้สมัครสมาชิก (1=เปิด, 0=ปิด)'),
('session_timeout_min', '30',                                             'หมดเวลา session (นาที)'),
('color_primary',       '#1b2a4e',                                        'สีหลัก (น้ำเงินกรมท่า)'),
('color_accent',        '#f47b20',                                        'สีรอง (ส้ม)'),
('site_url',            '',                                               'URL หลักของระบบ ใช้สร้าง QR Code (ว่าง = ตรวจหาอัตโนมัติ)'),
('approver_1_user',     '',                                               'ผู้พิจารณาขั้นที่ 1 (user id)'),
('approver_1_title',    'หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้',            'ตำแหน่งผู้พิจารณาขั้นที่ 1'),
('approver_2_user',     '',                                               'ผู้พิจารณาขั้นที่ 2 (user id)'),
('approver_2_title',    'รองผู้อำนวยการฝ่ายวิชาการ',                        'ตำแหน่งผู้พิจารณาขั้นที่ 2'),
('approver_3_user',     '',                                               'ผู้พิจารณาขั้นที่ 3 (user id)'),
('approver_3_title',    'ผู้อำนวยการวิทยาลัยการอาชีพโพนทอง',                'ตำแหน่งผู้พิจารณาขั้นที่ 3'),
('pdpa_version',        '1',                                              'ฉบับปัจจุบันของข้อตกลง PDPA'),
('pdpa_required',       '1',                                              'บังคับยอมรับข้อตกลง PDPA ก่อนใช้งาน (1=บังคับ)'),
('pdpa_updated',        DATE_FORMAT(CURDATE(), '%Y-%m-%d'),                'วันที่ประกาศใช้ข้อตกลง PDPA ฉบับปัจจุบัน'),
('pdpa_content',        '',                                               'เนื้อหาข้อตกลง PDPA (ว่าง = ใช้ข้อความเริ่มต้น)'),
('google_enabled',      '0',                                              'เปิดเข้าสู่ระบบ/สมัครสมาชิกด้วยบัญชี Google (1=เปิด)'),
('google_client_id',    '',                                               'Google OAuth Client ID'),
('google_client_secret','',                                               'Google OAuth Client Secret'),
('google_allowed_domain','',                                              'จำกัดอีเมล Google เฉพาะโดเมน เช่น pticec.ac.th (ว่าง = ทุกโดเมน)'),
('advisor_late_days',   '2',                                              'รายงานครูที่ปรึกษา: ส่งได้ภายในกี่วันหลังวันพุธโดยไม่นับว่าส่งช้า');

-- Log เริ่มต้น
INSERT INTO `activity_logs` (`user_id`, `action`, `detail`, `ip_address`) VALUES
(1, 'install', 'ติดตั้งระบบ TeachHub PT', '127.0.0.1');

