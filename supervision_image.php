<?php
/**
 * ไฟล์: supervision_image.php?id=xx
 * แสดงรูปหลักฐานการนิเทศ (uploads/supervision) — ตรวจสิทธิ์ตามตารางนิเทศทุกครั้ง
 */
require_once __DIR__ . '/config/config.php';

$user = require_login();

$img = db_one(
    'SELECT i.*, r.schedule_id FROM supervision_images i JOIN supervision_results r ON r.id = i.result_id WHERE i.id = ?',
    'i',
    [get_int('id')]
);
if (!$img) {
    abort_page(404, 'ไม่พบรูปภาพ');
}
$sc = schedule_fetch((int)$img['schedule_id']);
if (!$sc || !can_view_schedule($user, $sc)) {
    abort_page(403, 'ไม่มีสิทธิ์ดูรูปภาพนี้');
}
send_file('supervision', $img['stored_name'], $img['original_name'], true);
