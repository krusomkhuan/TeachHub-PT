-- =====================================================================
-- TeachHub PT — ข้อมูลทดลองใช้งาน (Demo)
-- ไฟล์: sample_data.sql
--
-- ใช้สำหรับ "ทดลอง/ฝึกใช้งาน" เท่านั้น — นำเข้าหลัง database.sql
-- ** ห้ามนำเข้าบนเครื่องที่ใช้งานจริง ** เพราะบัญชีทดลองใช้รหัสผ่านเดียวกันที่ทุกคนรู้ (teach1234)
--
-- บัญชีทดลอง (รหัสผ่าน teach1234 ทุกบัญชี):
--   supervisor  หัวหน้าแผนกช่างไฟฟ้ากำลัง/ผู้นิเทศ
--   teacher1    ครู แผนกช่างไฟฟ้ากำลัง
--   teacher2    ครู แผนกช่างยนต์
--   teacher3    ครู แผนกการบัญชี
--   executive   ผู้อำนวยการ (ผู้พิจารณาขั้นที่ 3)
--   curriculum  หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้ (สิทธิ์ admin, ผู้พิจารณาขั้นที่ 1)
--   staff       เจ้าหน้าที่งานพัฒนาหลักสูตรและการจัดการเรียนรู้ (สิทธิ์ admin ดูแลระบบประจำวัน)
--   deputy      รองผู้อำนวยการฝ่ายวิชาการ (ผู้บริหาร, ผู้พิจารณาขั้นที่ 2)
--   newteacher  ครูที่สมัครแล้วรออนุมัติ (ยังเข้าระบบไม่ได้)
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

INSERT INTO `users`
(`id`, `username`, `password_hash`, `prefix`, `first_name`, `last_name`, `department_id`, `position`, `academic_standing`, `email`, `phone`, `role`, `status`, `must_change_password`) VALUES
(2, 'supervisor', '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นาย',    'วิชัย',    'ใจดี',     2,    'ครู',                 'ครูชำนาญการพิเศษ', 'supervisor@teachhub.local', '0810000001', 'supervisor', 'active',  0),
(3, 'teacher1',   '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นางสาว', 'สุดา',     'รักเรียน',  2,    'ครู',                 'ครูชำนาญการ',      'teacher1@teachhub.local',   '0810000002', 'teacher',    'active',  0),
(4, 'teacher2',   '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นาย',    'ประเสริฐ', 'ช่างคิด',   1,    'ครู',                 '',                 'teacher2@teachhub.local',   '0810000003', 'teacher',    'active',  0),
(5, 'teacher3',   '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นาง',    'มาลี',     'ศรีสอน',   6,    'พนักงานราชการ (ครู)', '',                 'teacher3@teachhub.local',   '0810000004', 'teacher',    'active',  0),
(6, 'executive',  '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นาย',    'สมชาย',    'บริหารดี',  NULL, 'ผู้อำนวยการ',          '',                 'director@teachhub.local',   '0810000005', 'executive',  'active',  0),
(8, 'curriculum', '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นาง',    'วิไล',     'พัฒนาการ',  NULL, 'หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้', 'ครูชำนาญการพิเศษ', 'curriculum@teachhub.local', '0810000007', 'admin',      'active',  0),
(10, 'staff',     '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นางสาว', 'กานดา',    'ใจงาม',     NULL, 'เจ้าหน้าที่งานพัฒนาหลักสูตรและการจัดการเรียนรู้', '',        'staff@teachhub.local',      '0810000009', 'admin',      'active',  0),
(9, 'deputy',     '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นาย',    'ประยุทธ',  'วิชาการดี', NULL, 'รองผู้อำนวยการฝ่ายวิชาการ', '',             'deputy@teachhub.local',     '0810000008', 'executive',  'active',  0),
(7, 'newteacher', '$2y$10$1xwGUtsH5NG8vVqo2yR8buumq3dxXHxyJEj6ntqh.6AI7bVJBkO2q', 'นาย',    'ธนา',      'มาใหม่',    3,    'ครูผู้ช่วย',           '',                 'newteacher@teachhub.local', '0810000006', 'teacher',    'pending', 0);

-- รอบการส่งงานตัวอย่าง (อ้างอิงเวลาปัจจุบัน เพื่อให้เห็นสถานะต่าง ๆ ทันที)
--   1) แผนการสอน: เปิดอยู่ เหลือ 2 วัน (ใกล้ปิด = สีแดง) อนุญาตส่งช้า
--   2) PA ต้นปี: เปิดอยู่ เหลือ 20 วัน
--   3) เค้าโครงวิจัย: ยังไม่เปิด (เปิดอีก 7 วัน)
--   4) โครงการสอนรายวิชา (รอบก่อนเปิดภาคเรียน): ปิดรับแล้ว
--   5) แผนการจัดการเรียนรู้ รอบแรก: ปิดรับแล้ว (มีแผนที่ผ่านแล้ว ใช้เลือกวิชาในการนิเทศ/ประเมิน)
--   (ภาคเรียน: 1 = 2/2569 ปัจจุบัน, 2 = 1/2570, 3 = 2/2570)
INSERT INTO `submission_rounds` (`id`, `type`, `phase`, `name`, `academic_year_id`, `fiscal_year`, `description`, `open_at`, `close_at`, `allow_late`, `is_active`, `created_by`) VALUES
(1, 'lesson_plan', 'main',      'ส่งแผนการจัดการเรียนรู้ ภาคเรียนที่ 2/2569',     1, NULL, 'ส่งแผนการจัดการเรียนรู้ทุกรายวิชาที่สอนในภาคเรียน',     NOW() - INTERVAL 10 DAY,  NOW() + INTERVAL 2 DAY,    1, 1, 1),
(2, 'pa',          'agreement', 'ส่งข้อตกลง PA ปีงบประมาณ 2570 (ต้นปี)',          1, 2570, 'ส่งแบบเสนอข้อตกลงในการพัฒนางาน (PA) รอบต้นปีงบประมาณ', NOW() - INTERVAL 5 DAY,   NOW() + INTERVAL 20 DAY,   0, 1, 1),
(3, 'research',    'proposal',  'ส่งเค้าโครงวิจัยในชั้นเรียน ภาคเรียนที่ 2/2569', 1, NULL, 'ส่งเค้าโครงวิจัยในชั้นเรียน 1 เรื่องต่อภาคเรียน',         NOW() + INTERVAL 7 DAY,   NOW() + INTERVAL 37 DAY,   0, 1, 1),
(4, 'lesson_plan', 'main',      'ส่งโครงการสอนรายวิชา ภาคเรียนที่ 2/2569 (ก่อนเปิดภาคเรียน)', 1, NULL, 'รอบที่ปิดรับแล้ว',                          NOW() - INTERVAL 40 DAY,  NOW() - INTERVAL 20 DAY,   0, 1, 1),
(5, 'lesson_plan', 'main',      'ส่งแผนการจัดการเรียนรู้ ภาคเรียนที่ 2/2569 (รอบแรก)', 1, NULL, 'รอบที่ปิดรับแล้ว',                                NOW() - INTERVAL 30 DAY,  NOW() - INTERVAL 15 DAY,   0, 1, 1);

-- รายวิชาที่สอน ภาคเรียน 2/2569 (ครูบันทึกเองที่เมนู รายวิชาที่สอน)
INSERT INTO `teaching_subjects` (`teacher_id`, `academic_year_id`, `subject_code`, `subject_name`, `level`) VALUES
(3, 1, '20104-2101', 'วงจรไฟฟ้ากระแสตรง',             'ปวช.'),
(3, 1, '20104-2106', 'การติดตั้งไฟฟ้าในอาคาร',          'ปวช.'),
(4, 1, '20104-2003', 'เครื่องยนต์แก๊สโซลีนและดีเซล',    'ปวช.'),
(5, 1, '20201-2001', 'การบัญชีเบื้องต้น 1',               'ปวช.'),
(2, 1, '30104-2001', 'การออกแบบระบบไฟฟ้า',             'ปวส.');

-- โครงการสอน (รอบ 4) และแผนการจัดการเรียนรู้ที่ผ่านแล้ว (รอบ 5) — teacher1 และ teacher2 ครบตามเกณฑ์, teacher3 ยังไม่ส่ง
INSERT INTO `lesson_plans` (`round_id`, `doc_type`, `teacher_id`, `academic_year_id`, `subject_code`, `subject_name`, `level`, `units`, `status`, `submitted_at`) VALUES
(4, 'outline', 3, 1, '20104-2101', 'วงจรไฟฟ้ากระแสตรง',          'ปวช.', 1, 'approved', NOW() - INTERVAL 30 DAY),
(4, 'outline', 3, 1, '20104-2106', 'การติดตั้งไฟฟ้าในอาคาร',       'ปวช.', 1, 'approved', NOW() - INTERVAL 30 DAY),
(4, 'outline', 4, 1, '20104-2003', 'เครื่องยนต์แก๊สโซลีนและดีเซล', 'ปวช.', 1, 'approved', NOW() - INTERVAL 30 DAY),
(5, 'plan',    3, 1, '20104-2101', 'วงจรไฟฟ้ากระแสตรง',          'ปวช.', 1, 'approved', NOW() - INTERVAL 20 DAY),
(5, 'plan',    3, 1, '20104-2106', 'การติดตั้งไฟฟ้าในอาคาร',       'ปวช.', 1, 'approved', NOW() - INTERVAL 20 DAY),
(5, 'plan',    4, 1, '20104-2003', 'เครื่องยนต์แก๊สโซลีนและดีเซล', 'ปวช.', 1, 'approved', NOW() - INTERVAL 20 DAY);

-- ตารางนิเทศตัวอย่าง
INSERT INTO `supervision_schedules` (`academic_year_id`, `teacher_id`, `supervisor_id`, `subject_id`, `subject_name`, `classroom`, `supervise_date`, `start_time`, `end_time`, `status`, `created_by`) VALUES
(1, 3, 2, 3, '20104-2101 วงจรไฟฟ้ากระแสตรง', 'ชฟ.1/1 ห้อง 2301', CURDATE() + INTERVAL 14 DAY, '09:00:00', '11:00:00', 'scheduled', 1);

-- แบบประเมินการสอนโดยนักเรียนตัวอย่าง (เปิดอยู่ 7 วัน) — ลิงก์: /public/evaluate.php?t=de000000000000000000000000000001
INSERT INTO `student_eval_sessions` (`token`, `academic_year_id`, `teacher_id`, `subject_id`, `subject_name`, `level`, `grade_year`, `group_no`, `classroom`, `open_at`, `close_at`, `max_responses`, `is_active`, `created_by`) VALUES
('de000000000000000000000000000001', 1, 3, 3, '20104-2101 วงจรไฟฟ้ากระแสตรง', 'ปวช.', 1, '1', 'ปวช. 1/1', NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 7 DAY, 30, 1, 1);

-- ผู้พิจารณารายงานการนิเทศ 3 ขั้น
UPDATE `settings` SET `setting_value` = '8' WHERE `setting_key` = 'approver_1_user';
UPDATE `settings` SET `setting_value` = '9' WHERE `setting_key` = 'approver_2_user';
UPDATE `settings` SET `setting_value` = '6' WHERE `setting_key` = 'approver_3_user';

-- บัญชีทดลองทุกบัญชียอมรับข้อตกลง PDPA ฉบับที่ 1 แล้ว (เครื่องจริงผู้ใช้ต้องกดยอมรับเอง)
UPDATE `users` SET `pdpa_version` = '1', `pdpa_accepted_at` = NOW();

-- กระดานข่าวตัวอย่าง
INSERT INTO `board_topics` (`id`, `user_id`, `category`, `title`, `body`, `status`, `is_pinned`, `views`, `reply_count`, `last_reply_at`, `created_at`) VALUES
(1, 8, 'general', 'ยินดีต้อนรับสู่กระดานข่าว TeachHub PT', 'ใช้กระดานนี้สอบถามการใช้งาน แจ้งปัญหา หรือเสนอความสามารถที่อยากให้ระบบมีเพิ่มเติมได้ครับ\nงานพัฒนาหลักสูตรฯ จะตอบภายใน 1 วันทำการ', 'answered', 1, 12, 1, NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 3 DAY),
(2, 3, 'feature', 'อยากให้มีแจ้งเตือนทางอีเมลเมื่อใกล้ปิดรอบการส่ง', 'บางครั้งลืมเข้าระบบ ถ้ามีอีเมลแจ้งเตือนก่อนปิดรอบ 3 วันจะช่วยได้มากค่ะ', 'planned', 0, 8, 1, NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 2 DAY),
(3, 4, 'question', 'ส่งแผนแล้วแก้ไขไฟล์ได้ไหม', 'ส่งแผนการจัดการเรียนรู้ไปแล้ว แต่แนบไฟล์ผิด ต้องทำอย่างไรครับ', 'open', 0, 3, 0, NULL, NOW() - INTERVAL 5 HOUR);

INSERT INTO `board_replies` (`topic_id`, `user_id`, `body`, `created_at`) VALUES
(1, 3, 'ขอบคุณค่ะ', NOW() - INTERVAL 2 DAY),
(2, 8, 'รับข้อเสนอไว้พัฒนาในเวอร์ชันถัดไปครับ ขอบคุณสำหรับข้อเสนอแนะ', NOW() - INTERVAL 1 DAY);

-- ประกาศตัวอย่าง
INSERT INTO `announcements` (`title`, `content`, `is_pinned`, `created_by`) VALUES
('กำหนดส่งแผนการจัดการเรียนรู้ ภาคเรียนที่ 2/2569', 'ขอให้ครูผู้สอนส่งแผนการจัดการเรียนรู้ทุกรายวิชาภายในกำหนด หากเลยกำหนดระบบจะบันทึกสถานะ "ส่งช้า"', 0, 1);
