<?php
/**
 * ไฟล์: supervisor/pa.php
 * ตรวจแบบเสนอข้อตกลงในการพัฒนางาน (PA) — หัวหน้าแผนก (เฉพาะครูในแผนกตนเอง)
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('supervisor');
$type = 'pa';
require APP_ROOT . '/includes/partials/review_page.php';
