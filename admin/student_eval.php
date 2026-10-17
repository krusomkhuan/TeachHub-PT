<?php
/**
 * ไฟล์: admin/student_eval.php
 * นิเทศการสอนโดยนักเรียน นักศึกษา (ผู้ดูแลระบบ): สร้างแบบประเมิน + QR Code, ดูผลทุกแผนก
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/student_eval_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
require APP_ROOT . '/includes/partials/student_eval_page.php';
