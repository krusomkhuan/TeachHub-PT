<?php
/**
 * ไฟล์: admin/pa.php
 * ตรวจแบบเสนอข้อตกลงในการพัฒนางาน (PA) — ผู้ดูแลระบบ (ทุกแผนก)
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$type = 'pa';
require APP_ROOT . '/includes/partials/review_page.php';
