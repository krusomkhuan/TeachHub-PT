<?php
/**
 * ไฟล์: supervisor/supervision.php
 * นิเทศการสอน (หัวหน้าแผนก/ผู้นิเทศ): สร้างตารางนิเทศให้ครูในแผนก และบันทึกผลการนิเทศที่ตนเองเป็นผู้นิเทศ
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/supervision_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('supervisor');
require APP_ROOT . '/includes/partials/supervision_page.php';
