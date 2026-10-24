-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.15
-- ไฟล์: update_v1.15.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.14 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: ระบบประเมินแผนการจัดการเรียนรู้ (ตามแบบประเมินแผนการจัดการเรียนรู้ วิทยาลัยการอาชีพโพนทอง)
--   - plan_eval_criteria  รายการประเมิน ตอนที่ 1 (ตรวจองค์ประกอบ มี/ไม่มี) และตอนที่ 2 (คุณภาพ 5 ระดับ) — admin แก้ไขได้
--   - plan_evaluations    ผลการประเมิน 1 แผน (lesson_plans ประเภทแผนฯ) ต่อ 1 ผล — บันทึกร่างได้ ครูเห็นผลเมื่อส่งผล (status = final)
--   - plan_eval_items     ผลรายข้อของแต่ละการประเมิน (เก็บสำเนาชื่อรายการไว้)
-- ผู้ประเมิน: หัวหน้างานพัฒนาหลักสูตรฯ (ตามตำแหน่ง ทุกแผนก) / หัวหน้าแผนก (เฉพาะแผนกตนเอง) / admin
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `plan_eval_criteria` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `part`         ENUM('check','quality') NOT NULL COMMENT 'check = ตอนที่ 1 ตรวจองค์ประกอบ, quality = ตอนที่ 2 ประเมินคุณภาพ',
  `category`     VARCHAR(150) NOT NULL COMMENT 'ส่วน (ตอนที่ 1) / ด้าน (ตอนที่ 2)',
  `title`        VARCHAR(500) NOT NULL COMMENT 'รายการ',
  `is_required`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ตอนที่ 1: รายการที่ต้องมีทุกรายวิชา (เลือก "ไม่เกี่ยวข้อง" ไม่ได้)',
  `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_plan_criteria_part` (`part`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_evaluations` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lesson_plan_id`      INT UNSIGNED NOT NULL COMMENT 'แผนการจัดการเรียนรู้ที่ครูส่ง (lesson_plans.doc_type = plan)',
  `teacher_id`          INT UNSIGNED NOT NULL,
  `department_id`       INT UNSIGNED NULL COMMENT 'แผนกของครู ณ วันที่ประเมิน (ใช้ทำรายงานรายแผนก)',
  `academic_year_id`    INT UNSIGNED NULL,
  `evaluator_id`        INT UNSIGNED NULL,
  `evaluator_position`  VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ตำแหน่งผู้ประเมิน ณ วันที่ประเมิน',
  `plan_version`        SMALLINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'ฉบับของแผนที่ประเมิน',
  `program`             ENUM('ปวช.','ปวส.') NOT NULL DEFAULT 'ปวช.' COMMENT 'หลักสูตร',
  `subject_category`    VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'ประเภทวิชา',
  `major`               VARCHAR(150) NOT NULL DEFAULT '' COMMENT 'สาขาวิชา',
  `credit`              VARCHAR(20)  NOT NULL DEFAULT '' COMMENT 'ท-ป-น',
  `unit_count`          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนหน่วยการเรียนรู้',
  `plan_count`          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนแผนการจัดการเรียนรู้',
  `has_standard`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'อ้างอิงมาตรฐานอาชีพ/หลักสูตรการฝึกยกระดับฝีมือ',
  `total_score`         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'คะแนนรวมตอนที่ 2',
  `max_score`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `item_count`          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'จำนวนข้อตอนที่ 2',
  `avg_score`           DECIMAL(4,2) NOT NULL DEFAULT 0.00,
  `quality_level`       VARCHAR(50) NOT NULL DEFAULT '',
  `check_passed`        TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ตอนที่ 1 รายการที่ต้องมี "มี" ครบ',
  `is_passed`           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'ผ่านเกณฑ์: ค่าเฉลี่ย >= 3.51 และตอนที่ 1 ครบ',
  `strengths`           TEXT NULL COMMENT 'จุดเด่นของแผนการจัดการเรียนรู้',
  `improvements`        TEXT NULL COMMENT 'สิ่งที่ควรปรับปรุง/พัฒนา',
  `decision`            ENUM('approve','approve_revise','resubmit') NULL COMMENT 'ผลการพิจารณา (ร่างยังไม่เลือกได้)',
  `resubmit_by`         DATE NULL COMMENT 'กำหนดนำเสนอใหม่ภายในวันที่',
  `status`              ENUM('draft','final') NOT NULL DEFAULT 'draft' COMMENT 'draft = ร่าง (ครูยังไม่เห็น), final = ประเมินเสร็จ ส่งผลให้ครูแล้ว',
  `finalized_at`        DATETIME NULL COMMENT 'วันเวลาที่ส่งผลให้ครู',
  `acknowledged_at`     DATETIME NULL COMMENT 'วันเวลาที่ครูกดรับทราบ',
  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_eval_plan` (`lesson_plan_id`),
  KEY `idx_plan_eval_teacher` (`teacher_id`),
  KEY `idx_plan_eval_dept` (`department_id`),
  KEY `idx_plan_eval_ay` (`academic_year_id`),
  KEY `idx_plan_eval_evaluator` (`evaluator_id`),
  KEY `idx_plan_eval_status` (`status`),
  CONSTRAINT `fk_plan_eval_plan` FOREIGN KEY (`lesson_plan_id`)
    REFERENCES `lesson_plans` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_plan_eval_teacher` FOREIGN KEY (`teacher_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_plan_eval_dept` FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_plan_eval_ay` FOREIGN KEY (`academic_year_id`)
    REFERENCES `academic_years` (`id`) ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT `fk_plan_eval_evaluator` FOREIGN KEY (`evaluator_id`)
    REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plan_eval_items` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evaluation_id`  INT UNSIGNED NOT NULL,
  `criteria_id`    INT UNSIGNED NULL,
  `part`           ENUM('check','quality') NOT NULL,
  `category`       VARCHAR(150) NOT NULL DEFAULT '',
  `title`          VARCHAR(500) NOT NULL DEFAULT '',
  `is_required`    TINYINT(1) NOT NULL DEFAULT 0,
  `check_result`   ENUM('yes','no','na') NULL COMMENT 'ตอนที่ 1: มี / ไม่มี / ไม่เกี่ยวข้อง',
  `score`          TINYINT UNSIGNED NULL COMMENT 'ตอนที่ 2: 1-5',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plan_item_eval_criteria` (`evaluation_id`, `criteria_id`),
  KEY `idx_plan_item_criteria` (`criteria_id`),
  CONSTRAINT `fk_plan_item_eval` FOREIGN KEY (`evaluation_id`)
    REFERENCES `plan_evaluations` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_plan_item_criteria` FOREIGN KEY (`criteria_id`)
    REFERENCES `plan_eval_criteria` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- รายการประเมินเริ่มต้นตามแบบฟอร์มต้นฉบับ (เพิ่มเฉพาะเมื่อยังไม่มีรายการ — นำเข้าซ้ำได้ไม่ซ้ำข้อมูล)
INSERT INTO `plan_eval_criteria` (`part`, `category`, `title`, `is_required`, `sort_order`)
SELECT v.part, v.category, v.title, v.is_required, v.sort_order FROM (
  SELECT 'check' AS part, 'ส่วนนำ' AS category, 'ปกแผนการจัดการเรียนรู้ (หลักสูตร สาขาวิชา กลุ่มอาชีพ ประเภทวิชา รหัสวิชา ชื่อวิชา วิทยาลัย)' AS title, 1 AS is_required, 1 AS sort_order
  UNION ALL SELECT 'check', 'ส่วนนำ', 'รายการตรวจสอบและอนุญาตให้ใช้ (ลงนามครบทุกระดับ)', 1, 2
  UNION ALL SELECT 'check', 'ส่วนนำ', 'คำนำ', 1, 3
  UNION ALL SELECT 'check', 'ส่วนนำ', 'สารบัญ (ระบุเลขหน้าถูกต้อง)', 1, 4
  UNION ALL SELECT 'check', 'ส่วนการวิเคราะห์หลักสูตร', 'หลักสูตรรายวิชา', 1, 5
  UNION ALL SELECT 'check', 'ส่วนการวิเคราะห์หลักสูตร', 'มาตรฐานอาชีพ / หลักสูตรการฝึกยกระดับฝีมือ (ถ้ามี) พร้อมลิงก์ที่มา', 0, 6
  UNION ALL SELECT 'check', 'ส่วนการวิเคราะห์หลักสูตร', 'ตารางวิเคราะห์หน่วยการเรียนรู้ (งานหลัก งานย่อย สมรรถนะย่อย ความรู้ ทักษะ)', 1, 7
  UNION ALL SELECT 'check', 'ส่วนการวิเคราะห์หลักสูตร', 'ตารางวิเคราะห์พฤติกรรมการเรียนรู้ (แบบ 1 หรือ แบบ 2)', 1, 8
  UNION ALL SELECT 'check', 'ส่วนการวิเคราะห์หลักสูตร', 'ตารางหน่วยการเรียนรู้ (เวลาเรียนทฤษฎี ปฏิบัติ)', 1, 9
  UNION ALL SELECT 'check', 'ส่วนแผนการจัดการเรียนรู้และใบช่วยสอน (ครบทุกหน่วย)', 'แผนการจัดการเรียนรู้ครบทุกหน่วย/ทุกครั้งที่สอน', 1, 10
  UNION ALL SELECT 'check', 'ส่วนแผนการจัดการเรียนรู้และใบช่วยสอน (ครบทุกหน่วย)', 'ใบความรู้', 1, 11
  UNION ALL SELECT 'check', 'ส่วนแผนการจัดการเรียนรู้และใบช่วยสอน (ครบทุกหน่วย)', 'ใบงาน / ใบปฏิบัติงาน', 0, 12
  UNION ALL SELECT 'check', 'ส่วนแผนการจัดการเรียนรู้และใบช่วยสอน (ครบทุกหน่วย)', 'ใบกิจกรรม', 0, 13
  UNION ALL SELECT 'check', 'ส่วนแผนการจัดการเรียนรู้และใบช่วยสอน (ครบทุกหน่วย)', 'ใบมอบหมายงาน', 0, 14
  UNION ALL SELECT 'check', 'ส่วนแผนการจัดการเรียนรู้และใบช่วยสอน (ครบทุกหน่วย)', 'แบบประเมินความสามารถในการปฏิบัติงาน/ผลลัพธ์การเรียนรู้/สมรรถนะ', 0, 15
  UNION ALL SELECT 'check', 'ส่วนแผนการจัดการเรียนรู้และใบช่วยสอน (ครบทุกหน่วย)', 'บันทึกผลหลังการจัดการเรียนรู้', 0, 16
  UNION ALL SELECT 'check', 'ส่วนท้าย', 'บรรณานุกรม', 1, 17
  UNION ALL SELECT 'check', 'ส่วนท้าย', 'ภาคผนวก', 1, 18
  UNION ALL SELECT 'quality', 'ด้านที่ 1 หลักสูตรรายวิชาและการวิเคราะห์หลักสูตร', 'หลักสูตรรายวิชา (รหัสวิชา ชื่อวิชา ท-ป-น อ้างอิงมาตรฐาน ผลลัพธ์การเรียนรู้ระดับรายวิชา จุดประสงค์รายวิชา สมรรถนะรายวิชา คำอธิบายรายวิชา) ถูกต้องตรงตามหลักสูตรที่ประกาศใช้', 0, 1
  UNION ALL SELECT 'quality', 'ด้านที่ 1 หลักสูตรรายวิชาและการวิเคราะห์หลักสูตร', 'การอ้างอิงมาตรฐานอาชีพ/หลักสูตรการฝึกยกระดับฝีมือ คัดลอกหน่วยสมรรถนะ สมรรถนะย่อย เกณฑ์การปฏิบัติงานและวิธีประเมินได้ถูกต้องตามต้นฉบับ', 0, 2
  UNION ALL SELECT 'quality', 'ด้านที่ 1 หลักสูตรรายวิชาและการวิเคราะห์หลักสูตร', 'ตารางวิเคราะห์หน่วยการเรียนรู้ วิเคราะห์งานหลัก (Duty) และงานย่อย (Task) สอดคล้องกับผลลัพธ์การเรียนรู้ระดับรายวิชาและมาตรฐานอาชีพ', 0, 3
  UNION ALL SELECT 'quality', 'ด้านที่ 1 หลักสูตรรายวิชาและการวิเคราะห์หลักสูตร', 'ความรู้และทักษะในการปฏิบัติงานในตารางวิเคราะห์หน่วยการเรียนรู้ครอบคลุมคำอธิบายรายวิชา', 0, 4
  UNION ALL SELECT 'quality', 'ด้านที่ 1 หลักสูตรรายวิชาและการวิเคราะห์หลักสูตร', 'ตารางวิเคราะห์พฤติกรรมการเรียนรู้ กำหนดระดับความสามารถที่คาดหวังครบทั้งด้านพุทธิพิสัย ทักษะพิสัย จิตพิสัย และประยุกต์ใช้ฯ และสอดคล้องกับจุดประสงค์รายวิชาหรือสูงกว่า', 0, 5
  UNION ALL SELECT 'quality', 'ด้านที่ 1 หลักสูตรรายวิชาและการวิเคราะห์หลักสูตร', 'ตารางหน่วยการเรียนรู้ กำหนดเวลาเรียนทฤษฎีและปฏิบัติของแต่ละหน่วยสัมพันธ์กัน และรวมทั้งรายวิชาเป็นไปตามเงื่อนไขของหลักสูตร (รวมการประเมินผลลัพธ์การเรียนรู้ระดับรายวิชา)', 0, 6
  UNION ALL SELECT 'quality', 'ด้านที่ 2 ผลลัพธ์การเรียนรู้ สมรรถนะ และจุดประสงค์', 'ผลลัพธ์การเรียนรู้ระดับหน่วยการเรียน แสดงถึงผลลัพธ์นอกห้องเรียนที่เกิดจากการนำความรู้ ทักษะ ไปใช้ และสอดคล้องกับผลลัพธ์การเรียนรู้ระดับรายวิชา', 0, 7
  UNION ALL SELECT 'quality', 'ด้านที่ 2 ผลลัพธ์การเรียนรู้ สมรรถนะ และจุดประสงค์', 'อ้างอิงมาตรฐาน/เชื่อมโยงกลุ่มอาชีพ ระบุสมรรถนะย่อย เกณฑ์การปฏิบัติงาน วิธีประเมิน หลักฐานการปฏิบัติงาน และหลักฐานความรู้ ได้ถูกต้องสอดคล้องกับหน่วยการเรียนรู้', 0, 8
  UNION ALL SELECT 'quality', 'ด้านที่ 2 ผลลัพธ์การเรียนรู้ สมรรถนะ และจุดประสงค์', 'สมรรถนะประจำหน่วยเขียนในรูปแบบ “กริยา + กรรม + เงื่อนไขหรือสถานการณ์” ครบทั้งสมรรถนะทางปัญญาและสมรรถนะการฝึกและการปฏิบัติงาน ถูกต้องตามระดับ ปวช./ปวส.', 0, 9
  UNION ALL SELECT 'quality', 'ด้านที่ 2 ผลลัพธ์การเรียนรู้ สมรรถนะ และจุดประสงค์', 'จุดประสงค์เชิงพฤติกรรมครบ 4 ด้าน (พุทธิพิสัย ทักษะพิสัย จิตพิสัย และความสามารถประยุกต์ใช้และรับผิดชอบ) ใช้คำกริยาที่วัดและสังเกตได้', 0, 10
  UNION ALL SELECT 'quality', 'ด้านที่ 2 ผลลัพธ์การเรียนรู้ สมรรถนะ และจุดประสงค์', 'จุดประสงค์เชิงพฤติกรรมสอดคล้องกับสมรรถนะประจำหน่วยและระดับความสามารถในตารางวิเคราะห์พฤติกรรมการเรียนรู้', 0, 11
  UNION ALL SELECT 'quality', 'ด้านที่ 3 สาระการเรียนรู้ กิจกรรมการเรียนรู้ และสื่อ', 'สาระการเรียนรู้ถูกต้อง ทันสมัย ครอบคลุมและเพียงพอต่อการบรรลุจุดประสงค์เชิงพฤติกรรม', 0, 12
  UNION ALL SELECT 'quality', 'ด้านที่ 3 สาระการเรียนรู้ กิจกรรมการเรียนรู้ และสื่อ', 'กิจกรรมการเรียนรู้มีขั้นตอนชัดเจน (ขั้นนำ ขั้นสอน/ปฏิบัติ ขั้นสรุป และการประเมิน) เหมาะสมกับเวลาที่กำหนด', 0, 13
  UNION ALL SELECT 'quality', 'ด้านที่ 3 สาระการเรียนรู้ กิจกรรมการเรียนรู้ และสื่อ', 'กิจกรรมการเรียนรู้เน้นผู้เรียนเป็นสำคัญ ผู้เรียนได้ลงมือปฏิบัติจริง และส่งเสริมการเกิดสมรรถนะตามที่กำหนด', 0, 14
  UNION ALL SELECT 'quality', 'ด้านที่ 3 สาระการเรียนรู้ กิจกรรมการเรียนรู้ และสื่อ', 'กิจกรรมการเรียนรู้บูรณาการกลุ่มอาชีพ คุณธรรม จริยธรรม และคุณลักษณะที่พึงประสงค์อย่างเหมาะสม', 0, 15
  UNION ALL SELECT 'quality', 'ด้านที่ 3 สาระการเรียนรู้ กิจกรรมการเรียนรู้ และสื่อ', 'สื่อและแหล่งการเรียนรู้ (วัสดุ อุปกรณ์ เครื่องมือ แหล่งวิทยาการ) เหมาะสม หลากหลาย และสอดคล้องกับกิจกรรมการเรียนรู้', 0, 16
  UNION ALL SELECT 'quality', 'ด้านที่ 4 หลักฐานการเรียนรู้และการวัดและประเมินผล', 'หลักฐานการเรียนรู้ระบุหลักฐานความรู้และหลักฐานการปฏิบัติงานชัดเจน สอดคล้องกับจุดประสงค์และมาตรฐานอาชีพ', 0, 17
  UNION ALL SELECT 'quality', 'ด้านที่ 4 หลักฐานการเรียนรู้และการวัดและประเมินผล', 'เกณฑ์การปฏิบัติงานชัดเจน วัดได้ และสอดคล้องกับสมรรถนะประจำหน่วย', 0, 18
  UNION ALL SELECT 'quality', 'ด้านที่ 4 หลักฐานการเรียนรู้และการวัดและประเมินผล', 'วิธีการประเมินหลากหลาย เหมาะสม และครอบคลุมจุดประสงค์เชิงพฤติกรรมทั้ง 4 ด้าน', 0, 19
  UNION ALL SELECT 'quality', 'ด้านที่ 4 หลักฐานการเรียนรู้และการวัดและประเมินผล', 'เครื่องมือประเมินมีครบถ้วน (แบบทดสอบ แบบประเมินการปฏิบัติงาน แบบสังเกตพฤติกรรม ฯลฯ) พร้อมเกณฑ์การให้คะแนนและเกณฑ์การผ่านที่ชัดเจน', 0, 20
  UNION ALL SELECT 'quality', 'ด้านที่ 4 หลักฐานการเรียนรู้และการวัดและประเมินผล', 'มีแบบบันทึกผลหลังการจัดการเรียนรู้ (ผลที่เกิดกับผู้เรียน ปัญหาอุปสรรค การแก้ไขปัญหา และแนวทางครั้งต่อไป) ที่นำไปใช้ได้จริง', 0, 21
  UNION ALL SELECT 'quality', 'ด้านที่ 5 ใบช่วยสอน', 'ใบความรู้ มีองค์ประกอบครบ (จุดประสงค์ เนื้อหาสาระ แบบฝึกหัด/แบบทดสอบ เอกสารอ้างอิง ภาคผนวกเฉลย) เนื้อหาถูกต้อง เรียบเรียงเข้าใจง่าย', 0, 22
  UNION ALL SELECT 'quality', 'ด้านที่ 5 ใบช่วยสอน', 'ใบงาน/ใบปฏิบัติงาน ระบุเครื่องมือ วัสดุ อุปกรณ์ คำแนะนำ/ข้อควรระวังด้านความปลอดภัย ขั้นตอนการปฏิบัติงาน และการประเมินผลชัดเจน', 0, 23
  UNION ALL SELECT 'quality', 'ด้านที่ 5 ใบช่วยสอน', 'ใบกิจกรรม ระบุผลลัพธ์การเรียนรู้ ขั้นตอนการทำกิจกรรม การสรุปอภิปราย และการประเมินผลชัดเจน', 0, 24
  UNION ALL SELECT 'quality', 'ด้านที่ 5 ใบช่วยสอน', 'ใบมอบหมายงาน ระบุผลงาน รายละเอียดของงาน กำหนดเวลาส่ง แนวทางการปฏิบัติงาน และแหล่งค้นคว้าเพิ่มเติมชัดเจน', 0, 25
  UNION ALL SELECT 'quality', 'ด้านที่ 5 ใบช่วยสอน', 'ใบช่วยสอนทุกประเภทสอดคล้องกับแผนการจัดการเรียนรู้และจุดประสงค์ของหน่วย', 0, 26
  UNION ALL SELECT 'quality', 'ด้านที่ 6 รูปแบบและความสมบูรณ์ของเอกสาร', 'จัดทำตามแบบฟอร์มแผนการจัดการเรียนรู้ที่กำหนด หัวตารางแผนและใบช่วยสอน (รหัสวิชา ชื่อวิชา หน่วยที่ สอนครั้งที่ ชั่วโมง) ครบถ้วน', 0, 27
  UNION ALL SELECT 'quality', 'ด้านที่ 6 รูปแบบและความสมบูรณ์ของเอกสาร', 'ความสอดคล้องเชื่อมโยงกันตลอดทั้งเล่ม ตั้งแต่หลักสูตรรายวิชา การวิเคราะห์ แผนการจัดการเรียนรู้ จนถึงการวัดและประเมินผล', 0, 28
  UNION ALL SELECT 'quality', 'ด้านที่ 6 รูปแบบและความสมบูรณ์ของเอกสาร', 'ใช้ภาษาถูกต้อง สื่อความหมายชัดเจน การพิมพ์ถูกต้องเรียบร้อย', 0, 29
  UNION ALL SELECT 'quality', 'ด้านที่ 6 รูปแบบและความสมบูรณ์ของเอกสาร', 'บรรณานุกรมและการอ้างอิงถูกต้องตามหลักการเขียน และภาคผนวกครบถ้วน', 0, 30
) v
WHERE NOT EXISTS (SELECT 1 FROM `plan_eval_criteria` x);
