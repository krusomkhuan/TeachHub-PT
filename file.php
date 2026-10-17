<?php
/**
 * ไฟล์: file.php?id=xx[&inline=1]
 * ดาวน์โหลด/เปิดดูไฟล์งานที่ส่ง (submission_files) — ตรวจสิทธิ์ทุกครั้ง
 *  - ครูเปิดได้เฉพาะไฟล์งานของตนเอง
 *  - หัวหน้าแผนกเปิดได้เฉพาะงานของครูในแผนก
 *  - admin / ผู้บริหาร เปิดได้ทุกไฟล์
 */
require_once __DIR__ . '/config/config.php';

$user = require_login();

$file = db_one('SELECT * FROM submission_files WHERE id = ?', 'i', [get_int('id')]);
if (!$file) {
    abort_page(404, 'ไม่พบไฟล์');
}

$sub = submission_fetch($file['submission_type'], (int)$file['submission_id']);
if (!$sub || !can_view_submission($user, $sub)) {
    log_activity('access_denied', 'พยายามเปิดไฟล์ #' . (int)$file['id'] . ' โดยไม่มีสิทธิ์');
    abort_page(403, 'ไม่มีสิทธิ์เปิดไฟล์นี้');
}

// เปิดดูในเบราว์เซอร์ได้เฉพาะ PDF นอกนั้นให้ดาวน์โหลด
$ext = strtolower(pathinfo($file['stored_name'], PATHINFO_EXTENSION));
$inline = get('inline') === '1' && $ext === 'pdf';

log_activity('download_file', 'เปิดไฟล์ ' . submission_type_label($file['submission_type'])
    . ' #' . (int)$file['submission_id'] . ' v' . (int)$file['version']);
send_file($file['file_dir'], $file['stored_name'], $file['original_name'], $inline);
