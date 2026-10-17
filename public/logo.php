<?php
/**
 * ไฟล์: public/logo.php
 * ส่งไฟล์โลโก้วิทยาลัยที่อัปโหลดไว้ (uploads/system) — ถ้าไม่มีใช้โลโก้เริ่มต้น
 */
require_once __DIR__ . '/../config/config.php';

$logo = basename((string)setting('logo'));
$path = UPLOAD_PATH . '/system/' . $logo;
$ext = strtolower(pathinfo($logo, PATHINFO_EXTENSION));

if ($logo === '' || !is_file($path) || !in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
    redirect('assets/img/logo.svg');
}

header('Content-Type: ' . mime_for_ext($ext));
header('Content-Length: ' . filesize($path));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
