<?php
/**
 * ไฟล์: includes/functions.php
 * ฟังก์ชันกลางที่ใช้ทั้งระบบ: ฐานข้อมูล, ความปลอดภัย, วันที่ภาษาไทย, อัปโหลดไฟล์, ป้ายสถานะ ฯลฯ
 */

// =====================================================================
// 1) ฟังก์ชันฐานข้อมูล (ใช้ Prepared Statements ทุกคำสั่ง)
// =====================================================================

/**
 * เตรียมและรันคำสั่ง SQL พร้อมผูกค่าพารามิเตอร์
 * @param string $sql    คำสั่ง SQL ที่ใช้ ? แทนค่า
 * @param string $types  ชนิดข้อมูล เช่น 'isd' (i=int, s=string, d=double, b=blob)
 * @param array  $params ค่าที่จะผูก
 */
function db_query(string $sql, string $types = '', array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

/** ดึงข้อมูลหลายแถว (array ของ associative array) */
function db_all(string $sql, string $types = '', array $params = []): array
{
    $stmt = db_query($sql, $types, $params);
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** ดึงข้อมูลแถวเดียว (ไม่พบคืน null) */
function db_one(string $sql, string $types = '', array $params = []): ?array
{
    $stmt = db_query($sql, $types, $params);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** ดึงค่าคอลัมน์แรกของแถวแรก */
function db_value(string $sql, string $types = '', array $params = [])
{
    $row = db_one($sql, $types, $params);
    return $row ? reset($row) : null;
}

/** รันคำสั่ง INSERT/UPDATE/DELETE คืนจำนวนแถวที่ได้รับผล */
function db_exec(string $sql, string $types = '', array $params = []): int
{
    $stmt = db_query($sql, $types, $params);
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected;
}

/** รันคำสั่ง INSERT คืน id ล่าสุด */
function db_insert(string $sql, string $types = '', array $params = []): int
{
    $stmt = db_query($sql, $types, $params);
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

// =====================================================================
// 2) ค่าตั้งค่าระบบ (ตาราง settings)
// =====================================================================

/** โหลดค่าตั้งค่าทั้งหมด (cache ไว้ใน request) */
function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return $cache;
}

/** อ่านค่าตั้งค่าตาม key */
function setting(string $key, $default = '')
{
    $all = settings_all();
    return (isset($all[$key]) && $all[$key] !== '') ? $all[$key] : $default;
}

/** บันทึกค่าตั้งค่า (เพิ่มหรือแก้ไข) */
function setting_save(string $key, string $value): void
{
    db_exec(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        'ss',
        [$key, $value]
    );
    settings_all(true);
}

/** ชื่อวิทยาลัย / ระบบ ที่ใช้บ่อย */
function college_name(): string
{
    return setting('college_name', 'วิทยาลัยการอาชีพโพนทอง');
}

function system_name(): string
{
    return setting('system_name', APP_NAME);
}

/** URL โลโก้วิทยาลัย (มีเลขเวอร์ชันตามไฟล์ เพื่อไม่ให้ติด cache เมื่อเปลี่ยนโลโก้) */
function logo_url(): string
{
    $logo = (string)setting('logo');
    return $logo !== '' ? url('public/logo.php') . '?v=' . substr(md5($logo), 0, 8) : asset('img/logo.svg');
}

/** สีหลัก/สีรอง ของธีม (ตรวจรูปแบบ #RRGGBB ก่อนนำไปใช้ใน CSS) */
function theme_color(string $key, string $default): string
{
    $c = setting($key, $default);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : $default;
}

// =====================================================================
// 3) ความปลอดภัย: XSS / CSRF / Redirect
// =====================================================================

/** ป้องกัน XSS — ใช้ทุกจุดที่แสดงผลข้อมูล */
function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** สร้าง/คืนค่า CSRF Token ของ session */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** สร้าง input hidden สำหรับใส่ในทุกฟอร์ม */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/** ตรวจสอบ CSRF Token (จากฟอร์มหรือ header X-CSRF-Token) */
function csrf_check(): bool
{
    $token = $_POST['_csrf'] ?? $_GET['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($token) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $token);
}

/** ตรวจ CSRF ถ้าไม่ผ่านหยุดการทำงานทันที */
function csrf_verify(): void
{
    // ไฟล์ใหญ่เกิน post_max_size ของ PHP: $_POST จะว่างทั้งหมด แจ้งเหตุที่แท้จริงให้ผู้ใช้ทราบ
    if (is_post() && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        abort_page(413, 'ไฟล์มีขนาดใหญ่เกินกว่าที่เซิร์ฟเวอร์รับได้',
            'ขนาดสูงสุดที่เซิร์ฟเวอร์รับได้คือ ' . ini_get('post_max_size') . ' กรุณาลดขนาดไฟล์แล้วส่งใหม่');
    }
    if (!csrf_check()) {
        log_activity('csrf_failed', 'CSRF token ไม่ถูกต้อง: ' . ($_SERVER['REQUEST_URI'] ?? ''));
        abort_page(419, 'แบบฟอร์มหมดอายุหรือไม่ถูกต้อง', 'กรุณากลับไปหน้าเดิม รีเฟรช แล้วลองใหม่อีกครั้ง');
    }
}

/** สร้าง URL ภายในระบบ */
function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return BASE_URL . '/' . ltrim($path, '/');
}

/** URL ของไฟล์ใน assets พร้อมเลขเวอร์ชันกัน cache */
function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    $ver = is_file($file) ? filemtime($file) : APP_VERSION;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $ver;
}

/** เปลี่ยนหน้าแล้วหยุดทำงาน */
function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/** ตรวจว่า URL ปลายทาง (next) เป็นลิงก์ภายในระบบ ป้องกัน Open Redirect */
function safe_next(?string $next, string $fallback): string
{
    $next = (string)$next;
    $prefix = BASE_URL . '/';
    if ($next !== '' && strpos($next, $prefix) === 0 && strpos($next, '//') === false
        && strpos($next, '\\') === false && !preg_match('/[\r\n]/', $next)) {
        return $next;
    }
    return url($fallback);
}

/** แสดงหน้าข้อผิดพลาดแบบง่าย แล้วหยุดทำงาน */
function abort_page(int $code, string $title, string $message = ''): void
{
    http_response_code($code);
    echo '<!DOCTYPE html><html lang="th"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . '<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;600&display=swap" rel="stylesheet">'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '</head><body style="font-family:Sarabun,sans-serif;background:#f4f6fb">'
        . '<div class="container py-5 text-center"><div class="display-1 fw-bold" style="color:#1b2a4e">' . $code . '</div>'
        . '<h3 class="mb-3">' . e($title) . '</h3><p class="text-muted">' . e($message) . '</p>'
        . '<a href="' . e(url('index.php')) . '" class="btn btn-warning mt-2">กลับหน้าแรก</a>'
        . ' <a href="javascript:history.back()" class="btn btn-outline-secondary mt-2">ย้อนกลับ</a></div></body></html>';
    exit;
}

/** IP ของผู้ใช้ (ใช้ REMOTE_ADDR เพื่อป้องกันการปลอม header) */
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// =====================================================================
// 4) Request helper / Flash message
// =====================================================================

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** อ่านค่าจาก $_POST แบบตัดช่องว่าง */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** อ่านค่าจาก $_GET แบบตัดช่องว่าง */
function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** อ่านค่าจาก $_GET เป็นตัวเลขจำนวนเต็ม */
function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key]) ? (int)$_GET[$key] : $default;
}

/** คืนค่าเดิมของฟอร์ม (กรณีส่งไม่ผ่านให้กรอกค่าเดิมกลับ) */
function old(string $key, string $default = ''): string
{
    return e(isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : $default);
}

/**
 * ตั้งข้อความแจ้งเตือนแสดงครั้งเดียว
 * @param string $type success | error | warning | info
 */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** ดึงข้อความแจ้งเตือนทั้งหมดแล้วล้างทิ้ง */
function flash_get(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

// =====================================================================
// 5) วันที่ภาษาไทย (พ.ศ.)
// =====================================================================

function thai_months(bool $short = true): array
{
    return $short
        ? [1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.']
        : [1 => 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
            'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
}

/**
 * แปลงวันที่เป็นรูปแบบไทย พ.ศ. เช่น 16 ต.ค. 2569 14:30 น.
 * @param string|null $datetime ค่าจากฐานข้อมูล (Y-m-d หรือ Y-m-d H:i:s)
 */
function thai_date(?string $datetime, bool $withTime = false, bool $longMonth = false): string
{
    if (empty($datetime) || strpos($datetime, '0000-00-00') === 0) {
        return '-';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '-';
    }
    $months = thai_months(!$longMonth);
    $text = (int)date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . ((int)date('Y', $ts) + 543);
    if ($withTime) {
        $text .= ' ' . date('H:i', $ts) . ' น.';
    }
    return $text;
}

/** วันที่และเวลาแบบไทย */
function thai_datetime(?string $datetime, bool $longMonth = false): string
{
    return thai_date($datetime, true, $longMonth);
}

/** ปี พ.ศ. ปัจจุบัน */
function thai_year(?int $ts = null): int
{
    return (int)date('Y', $ts ?? time()) + 543;
}

/** ปีงบประมาณปัจจุบัน (เริ่ม 1 ต.ค.) แบบ พ.ศ. */
function current_fiscal_year(): int
{
    $y = thai_year();
    return ((int)date('n') >= 10) ? $y + 1 : $y;
}

/** แปลงวินาทีที่เหลือเป็นข้อความ เช่น "2 วัน 5 ชั่วโมง" */
function remaining_text(int $seconds): string
{
    if ($seconds <= 0) {
        return 'หมดเวลาแล้ว';
    }
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($d > 0) {
        return $d . ' วัน ' . $h . ' ชั่วโมง';
    }
    if ($h > 0) {
        return $h . ' ชั่วโมง ' . $m . ' นาที';
    }
    return max(1, $m) . ' นาที';
}

// =====================================================================
// 6) ป้ายกำกับ / ข้อความภาษาไทย
// =====================================================================

function role_labels(): array
{
    return [
        'admin'      => 'เจ้าหน้าที่งานพัฒนาหลักสูตรและการจัดการเรียนรู้ (ผู้ดูแลระบบ)',
        'supervisor' => 'หัวหน้าแผนก/ผู้นิเทศ',
        'teacher'    => 'ครูผู้สอน',
        'executive'  => 'ผู้บริหาร',
    ];
}

function role_label(string $role): string
{
    return role_labels()[$role] ?? $role;
}

function user_status_labels(): array
{
    return ['pending' => 'รออนุมัติ', 'active' => 'ใช้งาน', 'suspended' => 'ระงับ'];
}

function user_status_badge(string $status): string
{
    $map = ['pending' => 'bg-warning text-dark', 'active' => 'bg-success', 'suspended' => 'bg-secondary'];
    $label = user_status_labels()[$status] ?? $status;
    return '<span class="badge ' . ($map[$status] ?? 'bg-light text-dark') . '">' . e($label) . '</span>';
}

/** คำนำหน้าชื่อที่ให้เลือก */
function name_prefixes(): array
{
    return ['นาย', 'นาง', 'นางสาว', 'ว่าที่ร้อยตรี', 'ว่าที่ร้อยตรีหญิง', 'ว่าที่ร้อยโท', 'ว่าที่ร้อยเอก', 'ดร.', 'ผศ.', 'รศ.'];
}

/** ตำแหน่งที่ให้เลือกตอนสมัคร (รวมรองผู้อำนวยการ 4 ฝ่าย) */
function position_options(): array
{
    return [
        'ครู', 'ครูผู้ช่วย', 'พนักงานราชการ (ครู)', 'ครูอัตราจ้าง', 'ครูพิเศษสอน',
        'รองผู้อำนวยการฝ่ายบริหารทรัพยากร',
        'รองผู้อำนวยการฝ่ายยุทธศาสตร์และแผนงาน',
        'รองผู้อำนวยการฝ่ายกิจการนักเรียน นักศึกษา',
        'รองผู้อำนวยการฝ่ายวิชาการ',
    ];
}

/** ตำแหน่งผู้บริหาร (ไม่สังกัดแผนกวิชา) */
function is_executive_position(string $position): bool
{
    return mb_strpos($position, 'รองผู้อำนวยการ') === 0 || mb_strpos($position, 'ผู้อำนวยการ') === 0;
}

/** วิทยฐานะ */
function academic_standing_options(): array
{
    return ['', 'ครูชำนาญการ', 'ครูชำนาญการพิเศษ', 'ครูเชี่ยวชาญ', 'ครูเชี่ยวชาญพิเศษ'];
}

/** ชื่อเต็มของผู้ใช้ */
function full_name(array $u): string
{
    return trim(($u['prefix'] ?? '') . ($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
}

/** ชื่อประเภทงาน */
function submission_type_labels(): array
{
    return [
        'lesson_plan' => 'โครงการสอน / แผนการจัดการเรียนรู้',
        'pa'          => 'ข้อตกลงในการพัฒนางาน (PA)',
        'research'    => 'วิจัยในชั้นเรียน',
        'supervision' => 'นิเทศโดยหัวหน้าแผนก',
        'student_eval' => 'นิเทศโดยนักเรียน',
    ];
}

function submission_type_label(string $type): string
{
    return submission_type_labels()[$type] ?? $type;
}

/** ชื่อขั้นตอน/รอบย่อย */
function phase_label(string $phase): string
{
    $map = [
        'main'      => 'โครงการสอน / แผนการจัดการเรียนรู้',
        'outline'   => 'โครงการสอน / แผนการจัดการเรียนรู้', // รอบแบบเดิม (ก่อน 1.8) ใช้งานเหมือน main
        'agreement' => 'ข้อตกลงต้นปี',
        'report'    => 'รายงานผลปลายปี',
        'proposal'  => 'เค้าโครงวิจัย',
        'final'     => 'รายงานวิจัยฉบับสมบูรณ์',
    ];
    return $map[$phase] ?? $phase;
}

/** สถานะงานที่ส่ง */
function status_labels(): array
{
    return [
        'pending'       => 'รอตรวจ',
        'approved'      => 'ผ่าน',
        'revision'      => 'ให้แก้ไข',
        'late'          => 'ส่งช้า',
        'not_submitted' => 'ยังไม่ส่ง',
    ];
}

/**
 * ป้ายสถานะสี: รอตรวจ=เหลือง, ผ่าน=เขียว, ให้แก้ไข=แดง, ส่งช้า=ส้ม
 * @param bool $isLate ถ้า true จะต่อท้ายด้วยป้าย "ส่งช้า"
 */
function status_badge(string $status, bool $isLate = false): string
{
    $map = [
        'pending'       => 'badge-status badge-pending',
        'approved'      => 'badge-status badge-approved',
        'revision'      => 'badge-status badge-revision',
        'late'          => 'badge-status badge-late',
        'not_submitted' => 'badge-status badge-none',
    ];
    $icons = [
        'pending' => 'bi-hourglass-split', 'approved' => 'bi-check-circle',
        'revision' => 'bi-pencil-square', 'late' => 'bi-clock-history', 'not_submitted' => 'bi-dash-circle',
    ];
    $label = status_labels()[$status] ?? $status;
    $html = '<span class="badge ' . ($map[$status] ?? 'bg-secondary') . '"><i class="bi '
        . ($icons[$status] ?? 'bi-circle') . ' me-1"></i>' . e($label) . '</span>';
    if ($isLate && $status !== 'late') {
        $html .= ' ' . status_badge('late');
    }
    return $html;
}

// =====================================================================
// 7) รอบการส่งงาน — ตรวจสอบเวลาเปิด-ปิดฝั่งเซิร์ฟเวอร์
// =====================================================================

/**
 * สถานะของรอบการส่ง ณ เวลาปัจจุบัน
 * @return string inactive | upcoming | open | late | closed
 */
function round_state(array $round, ?int $now = null): string
{
    $now = $now ?? time();
    if (empty($round['is_active'])) {
        return 'inactive';
    }
    $open = strtotime($round['open_at']);
    $close = strtotime($round['close_at']);
    if ($now < $open) {
        return 'upcoming';
    }
    if ($now <= $close) {
        return 'open';
    }
    return !empty($round['allow_late']) ? 'late' : 'closed';
}

/** อนุญาตให้ส่ง/อัปโหลดหรือไม่ */
function round_can_submit(array $round): bool
{
    return in_array(round_state($round), ['open', 'late'], true);
}

/** ส่ง ณ ตอนนี้ถือว่าส่งช้าหรือไม่ */
function round_is_late_now(array $round): bool
{
    return round_state($round) === 'late';
}

/** ป้ายสถานะของรอบ */
function round_state_badge(array $round): string
{
    $state = round_state($round);
    $map = [
        'inactive' => ['bg-secondary', 'ปิดใช้งาน'],
        'upcoming' => ['bg-info text-dark', 'ยังไม่เปิดรับ'],
        'open'     => ['bg-success', 'เปิดรับ'],
        'late'     => ['badge-late', 'เปิดรับ (ส่งช้า)'],
        'closed'   => ['bg-dark', 'ปิดรับแล้ว'],
    ];
    [$cls, $label] = $map[$state];
    // เปิดรับแต่เหลือน้อยกว่า 3 วัน แสดงเป็นสีแดง
    if ($state === 'open' && (strtotime($round['close_at']) - time()) < 3 * 86400) {
        $cls = 'bg-danger';
        $label = 'ใกล้ปิดรับ';
    }
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/**
 * การ์ดแสดงรอบการส่งงานพร้อมนับถอยหลัง (ใช้ในหน้าแรกและปฏิทิน)
 * @param array $r แถวจาก submission_rounds (+ academic_year/semester ถ้ามี)
 */
function round_card_html(array $r): string
{
    $state = round_state($r);
    $closeTs = strtotime($r['close_at']);
    $openTs = strtotime($r['open_at']);
    $urgent = $state === 'open' && ($closeTs - time()) < 3 * 86400;
    $cls = $urgent ? 'urgent' : ($state === 'upcoming' ? 'upcoming' : (in_array($state, ['closed', 'late'], true) ? 'closed' : ''));

    if ($state === 'upcoming') {
        $countLabel = 'เปิดรับในอีก';
        $target = $openTs;
    } elseif ($state === 'open') {
        $countLabel = 'เหลือเวลาส่ง';
        $target = $closeTs;
    } else {
        $countLabel = '';
        $target = 0;
    }

    $html = '<div class="card round-card h-100 ' . $cls . '"><div class="card-body">'
        . '<div class="d-flex justify-content-between align-items-start gap-2 mb-2">'
        . '<span class="badge bg-light text-dark border"><i class="bi ' . e(submission_type_icon($r['type'])) . ' me-1"></i>'
        . e(submission_type_label($r['type'])) . '</span>' . round_state_badge($r) . '</div>'
        . '<h6 class="fw-bold mb-1">' . e($r['name']) . '</h6>'
        . '<div class="small text-muted mb-2">' . e(phase_label($r['phase'])) . '</div>'
        . '<div class="small"><i class="bi bi-calendar-check text-success me-1"></i>เปิด: ' . e(thai_datetime($r['open_at'])) . '</div>'
        . '<div class="small"><i class="bi bi-calendar-x text-danger me-1"></i>ปิด: ' . e(thai_datetime($r['close_at'])) . '</div>';
    if ($target > 0) {
        $html .= '<div class="mt-3 p-2 rounded text-center ' . ($urgent ? 'bg-danger bg-opacity-10' : 'bg-light') . '">'
            . '<div class="small text-muted">' . e($countLabel) . '</div>'
            . '<div class="fs-5 fw-semibold countdown-box" data-countdown="' . ($target * 1000) . '">'
            . e(remaining_text($target - time())) . '</div></div>';
    } elseif ($state === 'late') {
        $html .= '<div class="mt-3 p-2 rounded text-center bg-warning bg-opacity-10 small">'
            . 'เลยกำหนดแล้ว ยังส่งได้แต่จะติดป้าย <span class="badge badge-late">ส่งช้า</span></div>';
    }
    $html .= '</div></div>';
    return $html;
}

/** ภาคเรียนปัจจุบัน */
function current_academic_year(): ?array
{
    static $cache = false;
    if ($cache === false) {
        $cache = db_one('SELECT * FROM academic_years WHERE is_current = 1 ORDER BY year DESC, semester DESC LIMIT 1')
            ?? db_one('SELECT * FROM academic_years ORDER BY year DESC, semester DESC LIMIT 1');
    }
    return $cache;
}

/** ข้อความ ภาคเรียน/ปีการศึกษา เช่น 2/2569 */
function academic_label(?array $ay): string
{
    return $ay ? ($ay['semester'] . '/' . $ay['year']) : '-';
}

// =====================================================================
// 8) นิเทศการสอน — ระดับคุณภาพจากคะแนนเฉลี่ย (มาตราส่วน 5 ระดับ)
// =====================================================================

function quality_level(float $avg): string
{
    if ($avg >= 4.51) return 'ดีมาก';
    if ($avg >= 3.51) return 'ดี';
    if ($avg >= 2.51) return 'ปานกลาง';
    if ($avg >= 1.51) return 'พอใช้';
    if ($avg > 0)     return 'ควรปรับปรุง';
    return '-';
}

// =====================================================================
// 9) การอัปโหลดไฟล์อย่างปลอดภัย
// =====================================================================

/** MIME type ที่ยอมรับของแต่ละนามสกุล */
function allowed_mime_map(): array
{
    $zip = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
    $ole = ['application/msword', 'application/vnd.ms-office', 'application/vnd.ms-excel',
        'application/vnd.ms-powerpoint', 'application/x-ole-storage', 'application/CDFV2', 'application/octet-stream'];
    return [
        'pdf'  => ['application/pdf', 'application/x-pdf'],
        'doc'  => $ole,
        'docx' => array_merge(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], $zip),
        'xls'  => $ole,
        'xlsx' => array_merge(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], $zip),
        'ppt'  => $ole,
        'pptx' => array_merge(['application/vnd.openxmlformats-officedocument.presentationml.presentation'], $zip),
        'jpg'  => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
    ];
}

/** MIME type สำหรับส่งไฟล์กลับให้ browser */
function mime_for_ext(string $ext): string
{
    $map = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'zip' => 'application/zip',
    ];
    return $map[strtolower($ext)] ?? 'application/octet-stream';
}

/** นามสกุลไฟล์งานที่อนุญาต (จากหน้าตั้งค่า) */
function allowed_upload_extensions(): array
{
    $list = array_filter(array_map(
        fn($x) => strtolower(trim($x, " .\t")),
        explode(',', (string)setting('allowed_extensions', 'pdf,docx'))
    ));
    // อนุญาตเฉพาะนามสกุลที่ระบบรู้จัก MIME เท่านั้น
    return array_values(array_intersect($list, array_keys(allowed_mime_map())));
}

/** นามสกุลรูปภาพที่อนุญาต */
function image_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
}

/** ขนาดไฟล์สูงสุด (byte) ตามค่าตั้งค่า */
function max_upload_bytes(): int
{
    $mb = (int)setting('max_upload_mb', '30');
    return max(1, $mb) * 1024 * 1024;
}

/** แปลงขนาดไฟล์เป็นข้อความ */
function format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)    return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/**
 * ตรวจสอบและบันทึกไฟล์อัปโหลด
 * - ตรวจนามสกุล + MIME type จริงของไฟล์ (finfo)
 * - เปลี่ยนชื่อเป็นชื่อสุ่ม
 * - เก็บในโฟลเดอร์ uploads/{subdir}
 *
 * @param array  $file       ข้อมูลจาก $_FILES['xxx']
 * @param string $subdir     โฟลเดอร์ย่อย เช่น lesson_plans, pa, research, supervision
 * @param array  $allowedExt นามสกุลที่อนุญาต
 * @param int    $maxBytes   ขนาดสูงสุด
 * @return array ['ok'=>bool, 'error'=>string, 'stored_name', 'original_name', 'mime', 'size', 'ext']
 */
function handle_upload(array $file, string $subdir, array $allowedExt, int $maxBytes): array
{
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg];

    if (!isset($file['error']) || is_array($file['error'])) {
        return $fail('ข้อมูลไฟล์ไม่ถูกต้อง');
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return $fail('กรุณาเลือกไฟล์');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return $fail('ไฟล์มีขนาดใหญ่เกินกว่าที่เซิร์ฟเวอร์อนุญาต');
        case UPLOAD_ERR_PARTIAL:
            return $fail('อัปโหลดไฟล์ไม่สมบูรณ์ กรุณาลองใหม่');
        default:
            return $fail('เกิดข้อผิดพลาดในการอัปโหลด (รหัส ' . (int)$file['error'] . ')');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return $fail('ไฟล์ไม่ได้มาจากการอัปโหลด');
    }
    if ($file['size'] <= 0) {
        return $fail('ไฟล์ว่างเปล่า');
    }
    if ($file['size'] > $maxBytes) {
        return $fail('ไฟล์มีขนาดเกิน ' . format_bytes($maxBytes));
    }

    // ตรวจนามสกุล
    $original = basename(str_replace('\\', '/', (string)$file['name']));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedExt = array_map('strtolower', $allowedExt);
    if ($ext === '' || !in_array($ext, $allowedExt, true)) {
        return $fail('อนุญาตเฉพาะไฟล์นามสกุล: ' . implode(', ', $allowedExt));
    }

    // ตรวจ MIME type จากเนื้อไฟล์จริง
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $mimeMap = allowed_mime_map();
    if (!isset($mimeMap[$ext]) || !in_array($mime, $mimeMap[$ext], true)) {
        return $fail('ชนิดไฟล์ไม่ตรงกับนามสกุล (' . $mime . ')');
    }

    // ตรวจลายเซ็นไฟล์เพิ่มเติม
    $head = (string)file_get_contents($file['tmp_name'], false, null, 0, 8);
    if ($ext === 'pdf' && strpos($head, '%PDF') !== 0) {
        return $fail('ไฟล์ PDF ไม่ถูกต้อง');
    }
    if (in_array($ext, ['docx', 'xlsx', 'pptx', 'zip'], true) && strpos($head, "PK\x03\x04") !== 0) {
        return $fail('ไฟล์ ' . strtoupper($ext) . ' ไม่ถูกต้อง');
    }
    if (in_array($ext, image_extensions(), true) && @getimagesize($file['tmp_name']) === false) {
        return $fail('ไฟล์รูปภาพไม่ถูกต้อง');
    }

    // เตรียมโฟลเดอร์ปลายทาง
    $subdir = preg_replace('/[^a-z0-9_]/', '', strtolower($subdir));
    $dir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return $fail('ไม่สามารถสร้างโฟลเดอร์เก็บไฟล์ได้');
    }

    // ตั้งชื่อไฟล์ใหม่แบบสุ่ม
    $stored = date('Ymd') . '_' . bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $stored)) {
        return $fail('ไม่สามารถบันทึกไฟล์ได้');
    }
    @chmod($dir . DIRECTORY_SEPARATOR . $stored, 0644);

    // ตัดชื่อไฟล์ต้นฉบับให้สั้นพอเก็บในฐานข้อมูล
    $original = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $original), 0, 200);

    return [
        'ok'            => true,
        'error'         => '',
        'stored_name'   => $stored,
        'original_name' => $original,
        'mime'          => $mime,
        'size'          => (int)$file['size'],
        'ext'           => $ext,
        'subdir'        => $subdir,
    ];
}

/** ลบไฟล์ใน uploads อย่างปลอดภัย (ป้องกัน path traversal) */
function delete_upload(string $subdir, ?string $stored): void
{
    if (!$stored) {
        return;
    }
    $subdir = preg_replace('/[^a-z0-9_]/', '', strtolower($subdir));
    $path = UPLOAD_PATH . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . basename($stored);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * ส่งไฟล์ให้ผู้ใช้ดาวน์โหลด/เปิดดู (ใช้ในสคริปต์ดาวน์โหลดที่ตรวจสิทธิ์แล้ว)
 */
function send_file(string $subdir, string $stored, string $downloadName, bool $inline = false): void
{
    $subdir = preg_replace('/[^a-z0-9_]/', '', strtolower($subdir));
    $path = UPLOAD_PATH . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . basename($stored);
    if (!is_file($path)) {
        abort_page(404, 'ไม่พบไฟล์', 'ไฟล์อาจถูกลบหรือย้ายไปแล้ว');
    }
    $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    if (ob_get_level()) {
        ob_end_clean();
    }
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName);
    header('Content-Type: ' . mime_for_ext($ext));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
        . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($path);
    exit;
}

// =====================================================================
// 10) ตรวจสอบข้อมูลที่กรอก
// =====================================================================

function valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** ชื่อผู้ใช้: a-z 0-9 _ . - ยาว 4-30 ตัว */
function valid_username(string $u): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_.-]{4,30}$/', $u);
}

/** เบอร์โทร: ตัวเลข 9-10 หลัก (อนุญาตขีด/ช่องว่าง) */
function valid_phone(string $p): bool
{
    return (bool)preg_match('/^0[0-9]{8,9}$/', preg_replace('/[\s-]/', '', $p));
}

/**
 * นโยบายรหัสผ่าน: อย่างน้อย 8 ตัว มีทั้งตัวอักษรและตัวเลข
 * @return string ข้อความผิดพลาด (ว่าง = ผ่าน)
 */
function password_policy_error(string $pw): string
{
    if (strlen($pw) < 8) {
        return 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    }
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/[0-9]/', $pw)) {
        return 'รหัสผ่านต้องมีทั้งตัวอักษรภาษาอังกฤษและตัวเลข';
    }
    return '';
}

// =====================================================================
// 11) บันทึกการใช้งาน (Activity Log)
// =====================================================================

/**
 * บันทึกว่า ใคร ทำอะไร เมื่อไร จาก IP ใด
 * @param string   $action  รหัสการกระทำ เช่น login, logout, submit_lesson_plan
 * @param string   $detail  รายละเอียด
 * @param int|null $userId  ถ้าไม่ระบุจะใช้ผู้ใช้ที่ล็อกอินอยู่
 */
function log_activity(string $action, string $detail = '', ?int $userId = null): void
{
    try {
        $uid = $userId ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);
        db_exec(
            'INSERT INTO activity_logs (user_id, action, detail, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)',
            'issss',
            [$uid, mb_substr($action, 0, 100), mb_substr($detail, 0, 1000), client_ip(), $ua]
        );
    } catch (Throwable $ex) {
        // ไม่ให้การบันทึก log ล้มเหลวทำให้ระบบหยุดทำงาน
        error_log('log_activity error: ' . $ex->getMessage());
    }
}

/** ป้ายชื่อการกระทำใน Log */
function log_action_label(string $action): string
{
    $map = [
        'install' => 'ติดตั้งระบบ', 'login' => 'เข้าสู่ระบบ', 'login_failed' => 'เข้าสู่ระบบไม่สำเร็จ',
        'logout' => 'ออกจากระบบ', 'register' => 'สมัครสมาชิก', 'change_password' => 'เปลี่ยนรหัสผ่าน',
        'forgot_password' => 'ขอรีเซ็ตรหัสผ่าน', 'session_timeout' => 'หมดเวลาใช้งาน',
        'csrf_failed' => 'CSRF ไม่ถูกต้อง', 'update_profile' => 'แก้ไขโปรไฟล์',
        'access_denied' => 'เข้าถึงหน้าที่ไม่มีสิทธิ์', 'user_approve' => 'อนุมัติผู้ใช้',
        'user_suspend' => 'ระงับผู้ใช้', 'user_activate' => 'เปิดใช้งานผู้ใช้', 'user_delete' => 'ลบผู้ใช้',
        'user_create' => 'เพิ่มผู้ใช้', 'user_update' => 'แก้ไขผู้ใช้', 'user_reset_password' => 'รีเซ็ตรหัสผ่านผู้ใช้',
        'reset_request_reject' => 'ปฏิเสธคำขอรีเซ็ตรหัสผ่าน', 'download_file' => 'ดาวน์โหลดเอกสาร',
        'round_create' => 'สร้างรอบการส่ง', 'round_update' => 'แก้ไขรอบการส่ง', 'round_toggle' => 'เปิด/ปิดรอบการส่ง',
        'round_delete' => 'ลบรอบการส่ง', 'submit_lesson_plan' => 'ส่งแผนการสอน', 'resubmit_lesson_plan' => 'ส่งแผนการสอนฉบับแก้ไข',
        'delete_lesson_plan' => 'ลบแผนการสอน', 'review_lesson_plan' => 'ตรวจแผนการสอน',
        'review_pa' => 'ตรวจ PA', 'review_research' => 'ตรวจวิจัย',
        'submit_pa' => 'ส่ง PA', 'resubmit_pa' => 'ส่ง PA ฉบับแก้ไข', 'delete_pa' => 'ลบ PA',
        'schedule_create' => 'สร้างตารางนิเทศ', 'schedule_update' => 'แก้ไขตารางนิเทศ', 'schedule_cancel' => 'ยกเลิกตารางนิเทศ',
        'schedule_delete' => 'ลบตารางนิเทศ', 'supervision_record' => 'บันทึกผลนิเทศ', 'supervision_ack' => 'รับทราบผลนิเทศ',
        'supervision_print' => 'พิมพ์แบบบันทึกนิเทศ', 'criteria_create' => 'เพิ่มหัวข้อประเมิน', 'criteria_update' => 'แก้ไขหัวข้อประเมิน',
        'criteria_delete' => 'ลบหัวข้อประเมิน', 'report_export' => 'ส่งออกรายงาน', 'settings_update' => 'แก้ไขตั้งค่าระบบ',
        'logs_purge' => 'ล้างบันทึกการใช้งาน', 'department_save' => 'บันทึกแผนกวิชา', 'department_delete' => 'ลบแผนกวิชา',
        'academic_year_save' => 'บันทึกภาคเรียน', 'academic_year_delete' => 'ลบภาคเรียน', 'subject_save' => 'บันทึกรายวิชา',
        'subject_delete' => 'ลบรายวิชา', 'announcement_save' => 'บันทึกประกาศ', 'announcement_delete' => 'ลบประกาศ',
        'download_save' => 'บันทึกเอกสารดาวน์โหลด', 'download_delete' => 'ลบเอกสารดาวน์โหลด',
        'student_eval_create' => 'สร้างแบบประเมินโดยนักเรียน', 'student_eval_update' => 'แก้ไขแบบประเมินโดยนักเรียน',
        'student_eval_delete' => 'ลบแบบประเมินโดยนักเรียน',
        'report_create' => 'จัดทำรายงานการนิเทศ', 'report_update' => 'แก้ไขรายงานการนิเทศ', 'report_delete' => 'ลบร่างรายงานการนิเทศ',
        'report_submit' => 'เสนอรายงานการนิเทศ', 'report_forward' => 'เห็นควรเสนอรายงานการนิเทศ', 'report_approve' => 'ทราบ/อนุมัติรายงานการนิเทศ',
        'report_return' => 'ส่งกลับรายงานการนิเทศ', 'report_print' => 'พิมพ์รายงานการนิเทศ',
        'submit_research' => 'ส่งวิจัย', 'resubmit_research' => 'ส่งวิจัยฉบับแก้ไข', 'delete_research' => 'ลบวิจัย',
        'advisor_report_submit' => 'ส่งรายงานครูที่ปรึกษา', 'advisor_report_update' => 'แก้ไขรายงานครูที่ปรึกษา',
        'advisor_report_delete' => 'ลบรายงานครูที่ปรึกษา', 'advisor_report_review' => 'ตรวจรายงานครูที่ปรึกษา',
        'advisor_group_save' => 'บันทึกกลุ่มที่ปรึกษา', 'advisor_group_delete' => 'ลบกลุ่มที่ปรึกษา',
        'advisor_skip_save' => 'กำหนดวันงดพบ', 'advisor_skip_delete' => 'ยกเลิกวันงดพบ',
        'home_visit_submit' => 'ส่งรายงานเยี่ยมบ้าน', 'home_visit_update' => 'แก้ไขรายงานเยี่ยมบ้าน',
        'home_visit_delete' => 'ลบรายงานเยี่ยมบ้าน', 'home_visit_review' => 'ตรวจรายงานเยี่ยมบ้าน',
    ];
    return $map[$action] ?? $action;
}

/**
 * ตรวจว่า IP นี้ล็อกอินผิดเกินกำหนดหรือไม่ (ป้องกันการเดารหัสผ่าน)
 * ผิดเกิน 5 ครั้งใน 15 นาที จะถูกระงับชั่วคราว
 */
function login_locked(string $ip, int $maxAttempts = 5, int $minutes = 15): bool
{
    $count = (int)db_value(
        'SELECT COUNT(*) FROM activity_logs
         WHERE action = ? AND ip_address = ? AND created_at > (NOW() - INTERVAL ? MINUTE)',
        'ssi',
        ['login_failed', $ip, $minutes]
    );
    return $count >= $maxAttempts;
}

// =====================================================================
// 12) ตัวช่วยเกี่ยวกับผู้ใช้ / งานที่ส่ง (ใช้ในแดชบอร์ดและรายงาน)
// =====================================================================

/**
 * ชื่อตารางของงานแต่ละประเภท (whitelist — ปลอดภัยสำหรับต่อสตริง SQL)
 */
function submission_table(string $type): string
{
    $map = [
        'lesson_plan' => 'lesson_plans',
        'pa'          => 'pa_submissions',
        'research'    => 'research_submissions',
    ];
    if (!isset($map[$type])) {
        throw new InvalidArgumentException('ประเภทงานไม่ถูกต้อง');
    }
    return $map[$type];
}

/** ประเภทงานที่ต้องส่งไฟล์ */
function submission_types(): array
{
    return ['lesson_plan', 'pa', 'research'];
}

/** ไอคอน Bootstrap Icons ของแต่ละประเภทงาน */
function submission_type_icon(string $type): string
{
    $map = [
        'lesson_plan' => 'bi-journal-text',
        'pa'          => 'bi-clipboard-check',
        'research'    => 'bi-search',
        'supervision' => 'bi-person-video3',
        'student_eval' => 'bi-qr-code',
    ];
    return $map[$type] ?? 'bi-file-earmark';
}

/**
 * role ที่ถือเป็น "ครูผู้ต้องส่งงาน" (หัวหน้าแผนกก็ต้องส่งงานสอนของตนเองด้วย)
 */
function teacher_roles(): array
{
    return ['teacher', 'supervisor'];
}

/** URL รูปโปรไฟล์ (ไม่มีรูปใช้รูปเริ่มต้น) */
function avatar_url(array $u): string
{
    if (!empty($u['avatar'])) {
        return url('auth/avatar.php?id=' . (int)$u['id'] . '&v=' . substr(md5($u['avatar']), 0, 8));
    }
    return asset('img/avatar.svg');
}

/** ตัดข้อความยาวให้สั้นลง */
function str_limit(string $text, int $limit = 120): string
{
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
}

/** ร้อยละแบบปลอดภัย (กันหารด้วยศูนย์) */
function percent(float $part, float $total, int $decimals = 1): float
{
    return $total > 0 ? round($part * 100 / $total, $decimals) : 0.0;
}

/** สร้างรหัสผ่านชั่วคราวแบบสุ่ม (อ่านง่าย ไม่มีตัวที่สับสน เช่น 0/O, 1/l) */
function random_password(int $length = 10): string
{
    $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
    $digits = '23456789';
    $all = $letters . $digits;
    $pw = $letters[random_int(0, strlen($letters) - 1)] . $digits[random_int(0, strlen($digits) - 1)];
    for ($i = 2; $i < $length; $i++) {
        $pw .= $all[random_int(0, strlen($all) - 1)];
    }
    return str_shuffle($pw);
}

/** ชื่อหมวดเอกสารดาวน์โหลด */
function download_categories(): array
{
    return [
        'lesson_plan' => 'แบบฟอร์มแผนการสอน',
        'pa'          => 'แบบ PA',
        'research'    => 'เค้าโครงวิจัย',
        'supervision' => 'แบบนิเทศ',
        'other'       => 'เอกสารอื่น ๆ',
    ];
}

// =====================================================================
// 13) สถิติสำหรับแดชบอร์ด/รายงาน
//     $ayId = 0 หมายถึงทุกภาคเรียน, $deptId = 0 หมายถึงทุกแผนก
// =====================================================================

/**
 * สรุปจำนวนงานตามสถานะของแต่ละประเภท
 * @return array ['lesson_plan' => ['total'=>, 'pending'=>, 'approved'=>, 'revision'=>, 'late'=>], ...]
 */
function stat_status_summary(int $ayId = 0, int $deptId = 0): array
{
    $out = [];
    foreach (submission_types() as $t) {
        $table = submission_table($t);
        $row = db_one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(s.status = 'pending'), 0)  AS pending,
                    COALESCE(SUM(s.status = 'approved'), 0) AS approved,
                    COALESCE(SUM(s.status = 'revision'), 0) AS revision,
                    COALESCE(SUM(s.is_late = 1), 0)         AS late
             FROM `$table` s
             JOIN submission_rounds r ON r.id = s.round_id
             JOIN users u ON u.id = s.teacher_id
             WHERE (? = 0 OR r.academic_year_id = ?) AND (? = 0 OR u.department_id = ?)",
            'iiii',
            [$ayId, $ayId, $deptId, $deptId]
        );
        $out[$t] = array_map('intval', $row);
    }
    return $out;
}

/**
 * จำนวนครูที่ส่งงานแล้ว (นับคน) แยกแผนก แยกประเภทงาน
 * @return array แถวละแผนก: id, name, teachers, lesson_plan, pa, research
 */
function stat_department_submission(int $ayId = 0): array
{
    $sub = function (string $table): string {
        return "(SELECT COUNT(DISTINCT s.teacher_id) FROM `$table` s
                 JOIN users u2 ON u2.id = s.teacher_id
                 JOIN submission_rounds r ON r.id = s.round_id
                 WHERE u2.department_id = d.id AND (? = 0 OR r.academic_year_id = ?))";
    };
    return db_all(
        "SELECT d.id, d.name,
            (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.status = 'active'
                AND u.role IN ('teacher','supervisor')) AS teachers,
            " . $sub('lesson_plans') . " AS lesson_plan,
            " . $sub('pa_submissions') . " AS pa,
            " . $sub('research_submissions') . " AS research
         FROM departments d WHERE d.is_active = 1 ORDER BY d.name",
        'iiiiii',
        [$ayId, $ayId, $ayId, $ayId, $ayId, $ayId]
    );
}

/**
 * ความคืบหน้าของรอบการส่ง: จำนวนครูทั้งหมด / ครูที่ส่งแล้ว / ส่งช้า
 */
function stat_round_progress(array $round, int $deptId = 0): array
{
    $table = submission_table($round['type']);
    $teachers = (int)db_value(
        "SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ('teacher','supervisor')
         AND (? = 0 OR department_id = ?)",
        'ii',
        [$deptId, $deptId]
    );
    $row = db_one(
        "SELECT COUNT(DISTINCT s.teacher_id) AS sent,
                COUNT(DISTINCT CASE WHEN s.is_late = 1 THEN s.teacher_id END) AS late,
                COALESCE(SUM(s.status = 'pending'), 0) AS pending
         FROM `$table` s JOIN users u ON u.id = s.teacher_id
         WHERE s.round_id = ? AND (? = 0 OR u.department_id = ?)",
        'iii',
        [(int)$round['id'], $deptId, $deptId]
    );
    return [
        'teachers' => $teachers,
        'sent'     => (int)$row['sent'],
        'late'     => (int)$row['late'],
        'pending'  => (int)$row['pending'],
        'percent'  => percent((int)$row['sent'], $teachers),
    ];
}

/**
 * คะแนนนิเทศเฉลี่ยแยกแผนก
 */
function stat_supervision_by_department(int $ayId = 0): array
{
    return db_all(
        "SELECT d.id, d.name, COUNT(res.id) AS times, ROUND(AVG(res.avg_score), 2) AS avg_score
         FROM departments d
         LEFT JOIN users u ON u.department_id = d.id
         LEFT JOIN supervision_schedules sc ON sc.teacher_id = u.id AND (? = 0 OR sc.academic_year_id = ?)
         LEFT JOIN supervision_results res ON res.schedule_id = sc.id
         WHERE d.is_active = 1
         GROUP BY d.id, d.name ORDER BY d.name",
        'ii',
        [$ayId, $ayId]
    );
}

/**
 * รอบที่ "ยังเกี่ยวข้อง" ตอนนี้: เปิดรับอยู่ หรือเลยกำหนดแต่ยังรับส่งช้า (ภายใน 30 วัน)
 */
function active_rounds(): array
{
    return db_all(
        "SELECT r.*, ay.year, ay.semester FROM submission_rounds r
         LEFT JOIN academic_years ay ON ay.id = r.academic_year_id
         WHERE r.is_active = 1 AND r.open_at <= NOW()
           AND (r.close_at >= NOW() OR (r.allow_late = 1 AND r.close_at >= NOW() - INTERVAL 30 DAY))
         ORDER BY r.close_at ASC"
    );
}

/** หน้าส่งงานของครูตามประเภท */
function teacher_submit_page(string $type): string
{
    $map = ['lesson_plan' => 'teacher/lesson_plans.php', 'pa' => 'teacher/pa.php', 'research' => 'teacher/research.php'];
    return $map[$type] ?? 'teacher/index.php';
}

/**
 * ส่งออกไฟล์ CSV ที่เปิดด้วย Excel แล้วภาษาไทยไม่เพี้ยน (ใส่ UTF-8 BOM) แล้วหยุดทำงาน
 * - ป้องกัน CSV/Formula Injection: ค่าที่ขึ้นต้นด้วย = + - @ จะถูกเติม ' นำหน้า
 */
function csv_download(string $filename, array $header, array $rows): void
{
    if (ob_get_level()) {
        ob_end_clean();
    }
    $ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Cache-Control: private, max-age=0');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = function ($v) {
        $v = (string)($v ?? '');
        return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !is_numeric($v)) ? "'" . $v : $v;
    };
    fputcsv($out, array_map($safe, $header));
    foreach ($rows as $r) {
        fputcsv($out, array_map($safe, array_values($r)));
    }
    fclose($out);
    exit;
}

/** ไอคอนตามนามสกุลไฟล์ */
function file_icon(?string $filename): string
{
    $ext = strtolower(pathinfo((string)$filename, PATHINFO_EXTENSION));
    $map = [
        'pdf' => 'bi-file-earmark-pdf text-danger', 'doc' => 'bi-file-earmark-word text-primary',
        'docx' => 'bi-file-earmark-word text-primary', 'xls' => 'bi-file-earmark-excel text-success',
        'xlsx' => 'bi-file-earmark-excel text-success', 'ppt' => 'bi-file-earmark-ppt text-warning',
        'pptx' => 'bi-file-earmark-ppt text-warning', 'zip' => 'bi-file-earmark-zip text-secondary',
        'jpg' => 'bi-file-earmark-image text-info', 'jpeg' => 'bi-file-earmark-image text-info',
        'png' => 'bi-file-earmark-image text-info',
    ];
    return $map[$ext] ?? 'bi-file-earmark text-secondary';
}
