<?php
/**
 * ไฟล์: supervisor/lesson_plans.php
 * ตรวจแผนการจัดการเรียนรู้ (หัวหน้าแผนก — เฉพาะครูในแผนกตนเอง)
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('supervisor');
$type = 'lesson_plan';
require APP_ROOT . '/includes/partials/review_page.php';
