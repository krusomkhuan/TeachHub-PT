<?php
/**
 * ไฟล์: advisor_image.php?id=xx
 * แสดงภาพถ่ายรายงานการพบนักเรียนของครูที่ปรึกษา (uploads/advisor) — ตรวจสิทธิ์ทุกครั้ง
 */
require_once __DIR__ . '/config/config.php';

$user = require_login();

$img = db_one(
    'SELECT i.*, r.teacher_id FROM advisor_report_images i JOIN advisor_reports r ON r.id = i.report_id WHERE i.id = ?',
    'i',
    [get_int('id')]
);
if (!$img) {
    abort_page(404, 'ไม่พบรูปภาพ');
}
if (!can_view_advisor_teacher($user, (int)$img['teacher_id'])) {
    abort_page(403, 'ไม่มีสิทธิ์ดูรูปภาพนี้');
}
send_file('advisor', $img['stored_name'], $img['original_name'], true);
