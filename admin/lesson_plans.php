<?php
/**
 * ไฟล์: admin/lesson_plans.php
 * ตรวจแผนการจัดการเรียนรู้ (ผู้ดูแลระบบ — ทุกแผนก)
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$type = 'lesson_plan';
require APP_ROOT . '/includes/partials/review_page.php';
