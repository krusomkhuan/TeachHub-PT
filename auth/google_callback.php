<?php
/**
 * ไฟล์: auth/google_callback.php
 * Google ส่งผู้ใช้กลับมาที่นี่หลังเลือกบัญชี (Redirect URI ที่ต้องลงทะเบียนใน Google Cloud Console)
 */
require_once __DIR__ . '/../config/config.php';

if (!google_enabled()) {
    redirect('auth/login.php');
}
check_session_timeout();

$res = google_handle_callback($_GET);
$mode = $res['mode'];

// ---------- เชื่อมบัญชี Google จากหน้าโปรไฟล์ ----------
if ($mode === 'link') {
    $user = current_user();
    if (!$user) {
        redirect('auth/login.php');
    }
    if (!$res['ok']) {
        flash('error', $res['error']);
        redirect('auth/profile.php');
    }
    $p = $res['profile'];
    $owner = db_value('SELECT id FROM users WHERE google_sub = ?', 's', [$p['sub']]);
    if ($owner && (int)$owner !== (int)$user['id']) {
        flash('error', 'บัญชี Google นี้เชื่อมกับผู้ใช้อื่นแล้ว');
    } else {
        db_exec('UPDATE users SET google_sub = ? WHERE id = ?', 'si', [$p['sub'], (int)$user['id']]);
        log_activity('google_link', 'เชื่อมบัญชี Google ' . $p['email']);
        flash('success', 'เชื่อมบัญชี Google (' . $p['email'] . ') เรียบร้อยแล้ว ครั้งต่อไปกด “เข้าสู่ระบบด้วย Google” ได้ทันที');
    }
    redirect('auth/profile.php');
}

// ---------- เข้าสู่ระบบ / สมัครสมาชิก ----------
if (!$res['ok']) {
    flash('error', $res['error']);
    redirect('auth/login.php');
}
$p = $res['profile'];

$user = db_one('SELECT * FROM users WHERE google_sub = ? LIMIT 1', 's', [$p['sub']]);
if (!$user) {
    // อีเมลตรงกับบัญชีเดิม (Google ยืนยันอีเมลแล้ว) → เชื่อมให้อัตโนมัติ
    $user = db_one('SELECT * FROM users WHERE email = ? LIMIT 1', 's', [$p['email']]);
    if ($user && empty($user['google_sub'])) {
        db_exec('UPDATE users SET google_sub = ? WHERE id = ?', 'si', [$p['sub'], (int)$user['id']]);
        log_activity('google_link', 'เชื่อมบัญชี Google อัตโนมัติจากอีเมล ' . $p['email'], (int)$user['id']);
    } elseif ($user) {
        // อีเมลนี้ผูกกับ Google บัญชีอื่นอยู่แล้ว
        flash('error', 'อีเมลนี้เชื่อมกับบัญชี Google อื่นแล้ว กรุณาติดต่องานพัฒนาหลักสูตรฯ');
        redirect('auth/login.php');
    }
}

if ($user) {
    if ($user['status'] === 'pending') {
        flash('warning', 'บัญชีของท่านอยู่ระหว่างรออนุมัติจากผู้ดูแลระบบ');
        redirect('auth/login.php');
    }
    if ($user['status'] === 'suspended') {
        flash('error', 'บัญชีของท่านถูกระงับการใช้งาน กรุณาติดต่องานพัฒนาหลักสูตรฯ');
        redirect('auth/login.php');
    }
    login_user($user);
    log_activity('login_google', 'เข้าสู่ระบบด้วย Google ' . $p['email'], (int)$user['id']);
    if ((int)$user['must_change_password'] === 1) {
        redirect('auth/change_password.php');
    }
    flash('success', 'ยินดีต้อนรับ ' . full_name($user));
    header('Location: ' . safe_next($res['next'], dashboard_url($user['role'])));
    exit;
}

// ยังไม่มีบัญชี → ไปกรอกข้อมูลสมัครสมาชิก
if (setting('allow_register', '1') !== '1') {
    flash('warning', 'ไม่พบบัญชีที่ใช้อีเมล ' . $p['email'] . ' และขณะนี้ระบบปิดรับสมัครสมาชิก กรุณาติดต่องานพัฒนาหลักสูตรฯ');
    redirect('auth/login.php');
}
$_SESSION['google_signup'] = $p + ['at' => time()];
flash('info', 'ยืนยันบัญชี Google แล้ว กรุณากรอกข้อมูลเพิ่มเติมเพื่อสมัครสมาชิก');
redirect('auth/register.php');
