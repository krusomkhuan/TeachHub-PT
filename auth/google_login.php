<?php
/**
 * ไฟล์: auth/google_login.php
 * เริ่มเข้าสู่ระบบ / สมัครสมาชิกด้วย Google  (?mode=link = เชื่อมบัญชี Google จากหน้าโปรไฟล์)
 */
require_once __DIR__ . '/../config/config.php';

if (!google_enabled()) {
    flash('warning', 'ระบบยังไม่เปิดใช้การเข้าสู่ระบบด้วย Google');
    redirect('auth/login.php');
}

$mode = get('mode') === 'link' ? 'link' : 'login';
if ($mode === 'link') {
    require_login(); // ต้องล็อกอินก่อนจึงเชื่อมบัญชีได้
} else {
    redirect_if_logged_in();
}
$next = get('next');

header('Location: ' . google_auth_url($mode, is_string($next) ? $next : ''));
exit;
