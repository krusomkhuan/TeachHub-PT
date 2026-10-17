<?php
/**
 * ไฟล์: review/lesson_plans.php
 * ตรวจโครงการสอน / แผนการจัดการเรียนรู้ — ครูที่เป็นผู้ตรวจตามตำแหน่ง
 * (หัวหน้างาน / เจ้าหน้าที่งานพัฒนาหลักสูตรและการจัดการเรียนรู้) ตรวจได้ทุกแผนก
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$type = 'lesson_plan';
if (!is_designated_reviewer($user, $type) || in_array($user['role'], ['admin', 'supervisor'], true)) {
    // admin/หัวหน้าแผนก ใช้หน้าตรวจของบทบาทตนเอง
    if (in_array($user['role'], ['admin', 'supervisor'], true)) {
        redirect(review_page($user['role'], $type));
    }
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ตรวจตามตำแหน่ง: ' . implode(' / ', reviewer_positions()[$type]));
}
require APP_ROOT . '/includes/partials/review_page.php';
