<?php
/**
 * ไฟล์: admin/research.php
 * ตรวจวิจัยในชั้นเรียน (เค้าโครง / ฉบับสมบูรณ์) — ผู้ดูแลระบบ (ทุกแผนก)
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$type = 'research';
require APP_ROOT . '/includes/partials/review_page.php';
