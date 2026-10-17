<?php
/**
 * ไฟล์: public/download.php?id=xx
 * ส่งไฟล์เอกสารดาวน์โหลด (เอกสารสาธารณะ — เฉพาะที่เปิดใช้งาน) พร้อมนับจำนวนครั้ง
 * ไฟล์จริงเก็บใน uploads/downloads ซึ่งเข้าถึงตรงไม่ได้
 */
require_once __DIR__ . '/../config/config.php';

$id = get_int('id');
$doc = db_one(
    'SELECT id, title, original_name, stored_name FROM downloads WHERE id = ? AND is_active = 1 AND stored_name IS NOT NULL',
    'i',
    [$id]
);
if (!$doc) {
    abort_page(404, 'ไม่พบเอกสาร', 'เอกสารนี้อาจถูกลบหรือปิดการเผยแพร่แล้ว');
}

db_exec('UPDATE downloads SET download_count = download_count + 1 WHERE id = ?', 'i', [$id]);
if (is_logged_in()) {
    log_activity('download_file', 'ดาวน์โหลดเอกสาร: ' . $doc['title']);
}

send_file('downloads', $doc['stored_name'], $doc['original_name'] ?: ($doc['title'] . '.' . pathinfo($doc['stored_name'], PATHINFO_EXTENSION)));
