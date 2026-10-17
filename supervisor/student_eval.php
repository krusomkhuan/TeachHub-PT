<?php
/**
 * ไฟล์: supervisor/student_eval.php
 * นิเทศการสอนโดยนักเรียน นักศึกษา (หัวหน้าแผนก): ดูผลการประเมินของครูในแผนกตนเอง (ไม่แสดง QR/ลิงก์)
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/student_eval_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('supervisor');
require APP_ROOT . '/includes/partials/student_eval_page.php';
