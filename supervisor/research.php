<?php
/**
 * ไฟล์: supervisor/research.php
 * ตรวจวิจัยในชั้นเรียน (เค้าโครง / ฉบับสมบูรณ์) — หัวหน้าแผนก (เฉพาะครูในแผนกตนเอง)
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/review_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('supervisor');
$type = 'research';
require APP_ROOT . '/includes/partials/review_page.php';
