<?php
/**
 * ไฟล์: admin/supervision.php
 * นิเทศการสอน (ผู้ดูแลระบบ): สร้าง/จัดการตารางนิเทศทุกแผนก ดูและบันทึกผลการนิเทศ
 * ส่วนการทำงานทั้งหมดอยู่ใน includes/partials/supervision_page.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
require APP_ROOT . '/includes/partials/supervision_page.php';
