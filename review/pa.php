<?php
/**
 * ไฟล์: review/pa.php
 * ตรวจข้อตกลงในการพัฒนางาน (PA) — ครูที่เป็นผู้ตรวจตามตำแหน่ง (หัวหน้างานบุคลากร) ตรวจได้ทุกแผนก
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$type = 'pa';
if (!is_designated_reviewer($user, $type) || in_array($user['role'], ['admin', 'supervisor'], true)) {
    if (in_array($user['role'], ['admin', 'supervisor'], true)) {
        redirect(review_page($user['role'], $type));
    }
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ตรวจตามตำแหน่ง: ' . implode(' / ', reviewer_positions()[$type]));
}
require APP_ROOT . '/includes/partials/review_page.php';
