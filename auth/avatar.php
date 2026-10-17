<?php
/**
 * ไฟล์: auth/avatar.php?id=xx
 * ส่งรูปโปรไฟล์ของผู้ใช้ (ต้องล็อกอินก่อน) — รูปเก็บใน uploads/avatars ซึ่งเข้าถึงตรงไม่ได้
 */
require_once __DIR__ . '/../config/config.php';

require_login();

$row = db_one('SELECT avatar FROM users WHERE id = ?', 'i', [get_int('id')]);
if (!$row || !$row['avatar']) {
    redirect('assets/img/avatar.svg');
}
send_file('avatars', $row['avatar'], $row['avatar'], true);
