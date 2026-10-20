<?php
/**
 * ไฟล์: home_visit_image.php?id=xx
 * แสดงภาพถ่ายรายงานการเยี่ยมบ้าน (uploads/home_visit) — ตรวจสิทธิ์ทุกครั้ง
 */
require_once __DIR__ . '/config/config.php';

$user = require_login();

$img = db_one(
    'SELECT i.*, v.teacher_id FROM home_visit_images i JOIN home_visits v ON v.id = i.visit_id WHERE i.id = ?',
    'i',
    [get_int('id')]
);
if (!$img) {
    abort_page(404, 'ไม่พบรูปภาพ');
}
if (!can_view_advisor_teacher($user, (int)$img['teacher_id'])) {
    abort_page(403, 'ไม่มีสิทธิ์ดูรูปภาพนี้');
}
send_file('home_visit', $img['stored_name'], $img['original_name'], true);
