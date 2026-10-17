-- =====================================================================
-- TeachHub PT — อัปเดตฐานข้อมูลเป็นเวอร์ชัน 1.5
-- ไฟล์: update_v1.5.sql
--
-- ใช้เฉพาะเครื่องที่ติดตั้งเวอร์ชัน 1.4 ไว้แล้ว (ติดตั้งใหม่ไม่ต้องใช้)
-- สิ่งที่เพิ่ม: รายการประเมินการนิเทศโดยหัวหน้าแผนก จาก 8 ข้อ เป็น 15 ข้อ
--   - รายการเดิม 8 ข้อคงไว้ (ผลการนิเทศเดิมไม่เปลี่ยน) ปรับลำดับให้เรียงตามด้าน
--   - เพิ่มรายการใหม่ 7 ข้อ (นำเข้าซ้ำได้ ไม่เพิ่มซ้ำ)
-- =====================================================================
SET NAMES utf8mb4;

-- 1) ปรับลำดับรายการเดิม
UPDATE `supervision_criteria` SET `sort_order` = 4  WHERE `audience` = 'supervisor' AND `title` = 'แจ้งจุดประสงค์การเรียนรู้และเชื่อมโยงความรู้เดิมของผู้เรียน';
UPDATE `supervision_criteria` SET `sort_order` = 6  WHERE `audience` = 'supervisor' AND `title` = 'จัดกิจกรรมที่เน้นผู้เรียนเป็นสำคัญและฝึกปฏิบัติจริง';
UPDATE `supervision_criteria` SET `sort_order` = 8  WHERE `audience` = 'supervisor' AND `title` = 'สอดแทรกคุณธรรม จริยธรรม และความปลอดภัยในการปฏิบัติงาน';
UPDATE `supervision_criteria` SET `sort_order` = 10 WHERE `audience` = 'supervisor' AND `title` = 'ใช้สื่อและเทคโนโลยีเหมาะสมกับเนื้อหาและผู้เรียน';
UPDATE `supervision_criteria` SET `sort_order` = 12 WHERE `audience` = 'supervisor' AND `title` = 'ควบคุมชั้นเรียนได้ดี บรรยากาศเอื้อต่อการเรียนรู้';
UPDATE `supervision_criteria` SET `sort_order` = 14 WHERE `audience` = 'supervisor' AND `title` = 'วัดและประเมินผลสอดคล้องกับจุดประสงค์ด้วยวิธีที่หลากหลาย';

-- 2) เพิ่มรายการใหม่ 7 ข้อ (ข้ามถ้ามีอยู่แล้ว)
INSERT INTO `supervision_criteria` (`audience`, `category`, `title`, `sort_order`)
SELECT n.audience, n.category, n.title, n.sort_order FROM (
  SELECT 'supervisor' AS audience, 'ด้านการเตรียมการสอน' AS category, 'เข้าสอนตรงเวลาและใช้เวลาสอนครบตามที่กำหนด' AS title, 3 AS sort_order
  UNION ALL SELECT 'supervisor', 'ด้านการจัดการเรียนรู้', 'อธิบายเนื้อหาและสาธิตการปฏิบัติงานได้ชัดเจน ถูกต้องตามหลักวิชา', 5
  UNION ALL SELECT 'supervisor', 'ด้านการจัดการเรียนรู้', 'ใช้คำถามกระตุ้นการคิด และเปิดโอกาสให้ผู้เรียนซักถามแสดงความคิดเห็น', 7
  UNION ALL SELECT 'supervisor', 'ด้านการจัดการเรียนรู้', 'สรุปบทเรียนร่วมกับผู้เรียน และเชื่อมโยงสู่การนำไปใช้ในงานอาชีพ', 9
  UNION ALL SELECT 'supervisor', 'ด้านการใช้สื่อและเทคโนโลยี', 'ใช้แหล่งเรียนรู้หรือเทคโนโลยีดิจิทัลส่งเสริมการเรียนรู้ด้วยตนเอง', 11
  UNION ALL SELECT 'supervisor', 'ด้านการบริหารจัดการชั้นเรียน', 'ดูแลเอาใจใส่ผู้เรียนอย่างทั่วถึง และช่วยเหลือผู้เรียนที่มีปัญหา', 13
  UNION ALL SELECT 'supervisor', 'ด้านการวัดและประเมินผล', 'แจ้งผลการประเมินและให้ข้อมูลย้อนกลับเพื่อพัฒนาผู้เรียน', 15
) n
WHERE NOT EXISTS (
  SELECT 1 FROM `supervision_criteria` c WHERE c.`audience` = n.audience AND c.`title` = n.title
);
