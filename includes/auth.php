<?php
/**
 * ไฟล์: includes/auth.php
 * ระบบยืนยันตัวตน: ล็อกอิน/ล็อกเอาต์, ตรวจสิทธิ์ตาม role, หมดเวลา session
 *
 * การใช้งานในหน้าหลังบ้าน:
 *   require_once __DIR__ . '/../config/config.php';
 *   $user = require_role('admin');                 // เฉพาะ admin
 *   $user = require_role('admin', 'supervisor');   // หลาย role
 *   $user = require_login();                       // ทุก role ที่ล็อกอินแล้ว
 */

/** หน้าแดชบอร์ดของแต่ละ role */
function dashboard_url(string $role): string
{
    $map = [
        'admin'      => 'admin/index.php',
        'supervisor' => 'supervisor/index.php',
        'teacher'    => 'teacher/index.php',
        'executive'  => 'executive/index.php',
    ];
    return $map[$role] ?? 'public/index.php';
}

/** ระยะเวลาหมดอายุ session (วินาที) */
function session_timeout_seconds(): int
{
    $min = (int)setting('session_timeout_min', '30');
    return max(5, $min) * 60;
}

/** ล็อกอินอยู่หรือไม่ */
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * ข้อมูลผู้ใช้ที่ล็อกอินอยู่ (โหลดจากฐานข้อมูลทุก request เพื่อให้สิทธิ์เป็นปัจจุบัน)
 * ถ้าผู้ใช้ถูกระงับ/ลบ ระหว่างใช้งาน จะถูกออกจากระบบทันที
 */
function current_user(bool $refresh = false): ?array
{
    static $user = null;
    if (!is_logged_in()) {
        return null;
    }
    if ($user === null || $refresh) {
        $user = db_one(
            'SELECT u.*, d.name AS department_name
             FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.id = ? LIMIT 1',
            'i',
            [(int)$_SESSION['user_id']]
        );
        if (!$user || $user['status'] !== 'active') {
            $user = null;
            logout_user();
            return null;
        }
        // อัปเดต role ใน session ให้ตรงกับฐานข้อมูลเสมอ
        $_SESSION['role'] = $user['role'];
    }
    return $user;
}

/** ตรวจว่าผู้ใช้ปัจจุบันมี role ตามที่ระบุหรือไม่ */
function has_role(string ...$roles): bool
{
    $u = current_user();
    return $u !== null && in_array($u['role'], $roles, true);
}

/**
 * ตั้งค่า session หลังตรวจรหัสผ่านถูกต้อง
 * - สร้าง session id ใหม่ ป้องกัน Session Fixation
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['login_at'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32)); // สร้าง CSRF token ใหม่

    db_exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', 'i', [(int)$user['id']]);
    log_activity('login', 'เข้าสู่ระบบสำเร็จ', (int)$user['id']);
    log_purge_maybe(); // ลบ Log เก่ากว่าระยะเวลาเก็บ วันละครั้ง
}

/** ออกจากระบบ ล้าง session ทั้งหมด */
function logout_user(): void
{
    if (!empty($_SESSION['user_id'])) {
        presence_clear((int)$_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    // เริ่ม session ใหม่ (ว่าง) เพื่อใช้แสดง flash message ต่อได้
    session_start();
    session_regenerate_id(true);
}

/**
 * ตรวจการหมดเวลา session (ไม่มีการใช้งานเกินเวลาที่ตั้งไว้)
 * @return bool true = หมดเวลาแล้ว (และออกจากระบบให้แล้ว)
 */
function check_session_timeout(): bool
{
    if (!is_logged_in()) {
        return false;
    }
    $last = (int)($_SESSION['last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) > session_timeout_seconds()) {
        $uid = (int)$_SESSION['user_id'];
        log_activity('session_timeout', 'หมดเวลาใช้งานอัตโนมัติ', $uid);
        logout_user();
        return true;
    }
    $_SESSION['last_activity'] = time();
    return false;
}

/**
 * บังคับให้ต้องล็อกอิน — ถ้ายังไม่ล็อกอินจะพาไปหน้าเข้าสู่ระบบ
 * และถ้าต้องเปลี่ยนรหัสผ่าน (เช่น admin เริ่มต้น / ถูกรีเซ็ต) จะบังคับไปหน้าเปลี่ยนรหัสผ่านก่อน
 */
function require_login(): array
{
    if (check_session_timeout()) {
        flash('warning', 'หมดเวลาการใช้งาน กรุณาเข้าสู่ระบบอีกครั้ง');
        redirect('auth/login.php?timeout=1');
    }

    $user = current_user();
    if (!$user) {
        $next = $_SERVER['REQUEST_URI'] ?? '';
        redirect('auth/login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
    }
    presence_touch($user); // ผู้ใช้ออนไลน์ (เขียนฐานข้อมูลไม่เกิน 1 ครั้งต่อนาที)

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ((int)$user['must_change_password'] === 1 && !in_array($script, ['change_password.php', 'logout.php'], true)) {
        redirect('auth/change_password.php');
    }
    // ยังไม่ยอมรับข้อตกลง PDPA ฉบับปัจจุบัน → ไปหน้ายอมรับก่อน
    if (pdpa_needs_consent($user) && !in_array($script, ['change_password.php', 'logout.php', 'pdpa_consent.php'], true)) {
        redirect('auth/pdpa_consent.php');
    }
    // เจ้าหน้าที่ดูแลระบบ: เปิดได้เฉพาะหน้าที่ได้รับสิทธิ์ (ตรวจฝั่งเซิร์ฟเวอร์ทุกหน้า ไม่ใช่แค่ซ่อนเมนู)
    admin_perm_enforce($user);
    // ระบบงานบุคลากร: ปิดอยู่ = เปิดหน้า hr/ ได้เฉพาะผู้ดูแลระบบเต็มสิทธิ์
    hr_enforce_enabled($user);

    return $user;
}

/**
 * บังคับสิทธิ์ตาม role — ใช้ทุกหน้าหลังบ้าน
 */
function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        log_activity('access_denied', 'พยายามเข้าหน้า ' . ($_SERVER['REQUEST_URI'] ?? ''));
        abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับ: ' . implode(', ', array_map('role_label', $roles)));
    }
    return $user;
}

/**
 * ตรวจว่าผู้ใช้ปัจจุบันดูข้อมูลของครูคนนี้ได้หรือไม่
 * - admin / executive : ดูได้ทั้งหมด
 * - supervisor        : ดูได้เฉพาะครูในแผนกตนเอง (และของตนเอง)
 * - teacher           : ดูได้เฉพาะของตนเอง
 */
function can_view_teacher(array $user, int $teacherId): bool
{
    if (in_array($user['role'], ['admin', 'executive'], true)) {
        return true;
    }
    if ((int)$user['id'] === $teacherId) {
        return true;
    }
    if ($user['role'] === 'supervisor') {
        $dept = db_value('SELECT department_id FROM users WHERE id = ?', 'i', [$teacherId]);
        return $dept !== null && (int)$dept === (int)$user['department_id'];
    }
    return false;
}

/** ถ้าล็อกอินอยู่แล้ว ให้ไปหน้าแดชบอร์ดของตนเอง (ใช้ในหน้า login/register) */
function redirect_if_logged_in(): void
{
    if (check_session_timeout()) {
        return;
    }
    $u = current_user();
    if ($u) {
        redirect(dashboard_url($u['role']));
    }
}
