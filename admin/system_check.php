<?php
/**
 * ไฟล์: admin/system_check.php
 * ตรวจสอบความพร้อมของเซิร์ฟเวอร์และความปลอดภัยหลังติดตั้ง (ใช้ทั้งบน XAMPP และโฮสติ้ง)
 * ผลการตรวจ: ok = ผ่าน, warn = ควรแก้ไข, fail = ต้องแก้ไข
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');

$checks = []; // [กลุ่ม => [[สถานะ, หัวข้อ, รายละเอียด], ...]]
$add = function (string $group, string $status, string $title, string $detail) use (&$checks) {
    $checks[$group][] = [$status, $title, $detail];
};

// ---------- 1) PHP ----------
$add('PHP', version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'fail', 'เวอร์ชัน PHP', PHP_VERSION . ' (ต้องการ 8.0 ขึ้นไป)');
foreach (['mysqli' => 'เชื่อมต่อฐานข้อมูล', 'mbstring' => 'จัดการข้อความภาษาไทย', 'fileinfo' => 'ตรวจชนิดไฟล์ (MIME)', 'json' => 'JSON'] as $ext => $why) {
    $add('PHP', extension_loaded($ext) ? 'ok' : 'fail', 'ส่วนขยาย ' . $ext, $why . (extension_loaded($ext) ? '' : ' — กรุณาเปิดใน php.ini'));
}
$add('PHP', function_exists('mysqli_stmt_get_result') ? 'ok' : 'fail', 'mysqlnd (get_result)',
    function_exists('mysqli_stmt_get_result') ? 'พร้อมใช้งาน' : 'โฮสติ้งต้องเปิดใช้ mysqlnd (ส่วนใหญ่เปิดไว้แล้ว)');
$add('PHP', date_default_timezone_get() === 'Asia/Bangkok' ? 'ok' : 'warn', 'เขตเวลา PHP', date_default_timezone_get() . ' · ตอนนี้ ' . thai_datetime(date('Y-m-d H:i:s')));

// ---------- 2) อัปโหลดไฟล์ ----------
$toBytes = function (string $v): int {
    $n = (int)$v;
    switch (strtolower(substr(trim($v), -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n;
};
$phpMax = min($toBytes((string)ini_get('upload_max_filesize')), $toBytes((string)ini_get('post_max_size')));
$add('การอัปโหลด', ini_get('file_uploads') ? 'ok' : 'fail', 'file_uploads', ini_get('file_uploads') ? 'เปิด' : 'ปิด — อัปโหลดไม่ได้');
$add('การอัปโหลด', $phpMax >= max_upload_bytes() ? 'ok' : 'warn', 'ขนาดไฟล์สูงสุด',
    'ตั้งค่าในระบบ ' . format_bytes(max_upload_bytes()) . ' · PHP รับได้ ' . format_bytes($phpMax)
    . ' (upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . ')');
foreach (['lesson_plans', 'pa', 'research', 'supervision', 'advisor', 'home_visit', 'avatars', 'downloads', 'system'] as $d) {
    $path = UPLOAD_PATH . DIRECTORY_SEPARATOR . $d;
    $ok = is_dir($path) && is_writable($path);
    $add('การอัปโหลด', $ok ? 'ok' : 'fail', 'โฟลเดอร์ uploads/' . $d, $ok ? 'เขียนไฟล์ได้' : 'ไม่มีโฟลเดอร์หรือเขียนไม่ได้ — สร้างโฟลเดอร์และตั้งสิทธิ์ 755');
}
$free = @disk_free_space(UPLOAD_PATH);
if ($free !== false) {
    $add('การอัปโหลด', $free > 1024 ** 3 ? 'ok' : 'warn', 'พื้นที่ว่างบนดิสก์', format_bytes((int)min($free, PHP_INT_MAX)));
}

// ---------- 3) ฐานข้อมูล ----------
$dbVer = (string)db_value('SELECT VERSION()');
$add('ฐานข้อมูล', 'ok', 'เวอร์ชัน MySQL/MariaDB', $dbVer);
$charset = (string)db_value('SELECT DEFAULT_CHARACTER_SET_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()');
$add('ฐานข้อมูล', $charset === 'utf8mb4' ? 'ok' : 'warn', 'Charset ของฐานข้อมูล', $charset . ($charset === 'utf8mb4' ? '' : ' (แนะนำ utf8mb4)'));
$tables = (int)db_value('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
$add('ฐานข้อมูล', $tables >= 38 ? 'ok' : 'fail', 'จำนวนตาราง', $tables . ' ตาราง (ต้องมี 38'
    . ($tables === 35 ? ' — ยังไม่ได้นำเข้า update_v1.15.sql' : '')
    . ($tables === 34 ? ' — ยังไม่ได้นำเข้า update_v1.14.sql' : '')
    . ($tables === 32 ? ' — ยังไม่ได้นำเข้า update_v1.13.sql' : '')
    . ($tables === 28 ? ' — ยังไม่ได้นำเข้า update_v1.12.sql' : '')
    . ($tables === 26 ? ' — ยังไม่ได้นำเข้า update_v1.11.sql' : '')
    . ($tables === 20 ? ' — ยังไม่ได้นำเข้า update_v1.1.sql และ update_v1.2.sql' : '')
    . ($tables === 23 ? ' — ยังไม่ได้นำเข้า update_v1.2.sql' : '')
    . ($tables === 25 ? ' — ยังไม่ได้นำเข้า update_v1.6.sql' : '') . ')');
// v1.16: เยี่ยมบ้านนักเรียนคนเดิมได้หลายครั้งต่อปี — ต้องไม่มี UNIQUE (school_year, student_code) แล้ว
$hvOldUnique = (bool)db_value("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'home_visits'
                               AND INDEX_NAME = 'uq_home_visit_student_year' LIMIT 1");
$add('ฐานข้อมูล', $hvOldUnique ? 'fail' : 'ok', 'โครงสร้างการเยี่ยมบ้าน (v1.16)',
    $hvOldUnique ? 'ยังไม่ได้นำเข้า update_v1.16.sql — บันทึกเยี่ยมบ้านนักเรียนคนเดิมครั้งที่ 2 จะไม่สำเร็จ' : 'เยี่ยมบ้านนักเรียนคนเดิมได้หลายครั้งต่อปี');
$add('ข้อมูลพื้นฐาน', report_chain_ready() ? 'ok' : 'warn', 'ผู้พิจารณารายงานการนิเทศ (3 ขั้น)',
    report_chain_ready() ? implode(' → ', array_map(fn($s) => $s['title'] . ' (' . $s['name'] . ')', report_approval_chain()))
        : 'ยังกำหนดไม่ครบ — หัวหน้าแผนกจะเสนอรายงานไม่ได้ (ตั้งค่าระบบ → ผู้พิจารณารายงานการนิเทศ)');
$diff = abs(strtotime((string)db_value('SELECT NOW()')) - time());
$add('ฐานข้อมูล', $diff < 120 ? 'ok' : 'warn', 'เวลาฐานข้อมูลตรงกับ PHP', $diff < 120 ? 'ตรงกัน' : 'ต่างกัน ' . $diff . ' วินาที');

// ---------- 4) ความปลอดภัย ----------
$add('ความปลอดภัย', APP_DEBUG ? 'warn' : 'ok', 'โหมดแสดงข้อผิดพลาด (APP_DEBUG)',
    APP_DEBUG ? 'เปิดอยู่ — ใช้งานจริงให้ตั้ง define(\'APP_DEBUG\', false) ใน config/config.php' : 'ปิดแล้ว');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
$isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);
$add('ความปลอดภัย', $https ? 'ok' : ($isLocal ? 'ok' : 'warn'), 'HTTPS', $https ? 'ใช้งาน HTTPS' : ($isLocal ? 'เครื่อง localhost ไม่จำเป็น' : 'ยังไม่ใช้ HTTPS — แนะนำเปิด SSL (Let\'s Encrypt) บนโฮสติ้ง'));
foreach (['uploads/.htaccess', 'config/.htaccess', 'includes/.htaccess', '.htaccess'] as $f) {
    $add('ความปลอดภัย', is_file(APP_ROOT . '/' . $f) ? 'ok' : 'fail', 'ไฟล์ ' . $f, is_file(APP_ROOT . '/' . $f) ? 'มีอยู่' : 'ไม่พบ — ไฟล์อาจหายระหว่างอัปโหลด (ไฟล์ที่ขึ้นต้นด้วยจุดถูกซ่อน)');
}

// ทดสอบการเข้าถึงไฟล์ใน uploads โดยตรงผ่าน HTTP (ต้องได้ 403/404)
$scheme = $https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$probe = function (string $path) use ($scheme, $host): ?int {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $res = @file_get_contents($scheme . '://' . $host . url($path), false, $ctx);
    if ($res === false && empty($http_response_header)) {
        return null;
    }
    return preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0] ?? '', $m) ? (int)$m[1] : null;
};
foreach (['uploads/index.html' => 'โฟลเดอร์ uploads', 'config/db.php' => 'ไฟล์ config/db.php', 'database.sql' => 'ไฟล์ database.sql'] as $p => $label) {
    $code = $probe($p);
    if ($code === null) {
        $add('ความปลอดภัย', 'warn', 'เข้าถึง ' . $label . ' จากเว็บ', 'ตรวจอัตโนมัติไม่ได้ (เซิร์ฟเวอร์เรียกตัวเองไม่ได้) — ลองเปิด ' . url($p) . ' ด้วยเบราว์เซอร์ ต้องขึ้น 403 Forbidden');
    } else {
        $add('ความปลอดภัย', in_array($code, [403, 404], true) ? 'ok' : 'fail', 'เข้าถึง ' . $label . ' จากเว็บ',
            'HTTP ' . $code . (in_array($code, [403, 404], true) ? ' (ถูกบล็อกแล้ว)' : ' — เปิดได้! ตรวจว่าเซิร์ฟเวอร์รองรับ .htaccess (AllowOverride All)'));
    }
}

// รหัสผ่านเริ่มต้น / บัญชีทดลอง
$weak = [];
foreach (db_all("SELECT username, password_hash FROM users WHERE status = 'active'") as $u) {
    foreach (['admin1234', 'teach1234'] as $pw) {
        if (password_verify($pw, $u['password_hash'])) {
            $weak[] = $u['username'];
        }
    }
}
$add('ความปลอดภัย', $weak ? 'fail' : 'ok', 'รหัสผ่านเริ่มต้น/บัญชีทดลอง',
    $weak ? 'บัญชีที่ยังใช้รหัสผ่านเริ่มต้น: ' . implode(', ', $weak) . ' — เปลี่ยนรหัสหรือระงับบัญชีทดลองก่อนเปิดใช้งานจริง' : 'ไม่พบรหัสผ่านเริ่มต้น');
$activeAdmins = (int)db_value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
$add('ความปลอดภัย', $activeAdmins >= 1 ? 'ok' : 'fail', 'ผู้ดูแลระบบที่ใช้งานได้', $activeAdmins . ' บัญชี');

// ---------- 5) ข้อมูลพื้นฐาน ----------
$ay = current_academic_year();
$add('ข้อมูลพื้นฐาน', $ay && $ay['is_current'] ? 'ok' : 'warn', 'ภาคเรียนปัจจุบัน', $ay ? academic_label($ay) : 'ยังไม่ได้กำหนด');
$deptNoHead = db_all("SELECT d.name FROM departments d WHERE d.is_active = 1 AND NOT EXISTS
    (SELECT 1 FROM users u WHERE u.department_id = d.id AND u.role = 'supervisor' AND u.status = 'active')");
$add('ข้อมูลพื้นฐาน', $deptNoHead ? 'warn' : 'ok', 'หัวหน้าแผนก (ผู้ตรวจงาน)',
    $deptNoHead ? 'แผนกที่ยังไม่มีหัวหน้าแผนก ' . count($deptNoHead) . ' แผนก (งานของแผนกเหล่านี้ admin ต้องตรวจเอง): '
        . implode(', ', array_map(fn($d) => preg_replace('/^แผนกวิชา/u', '', $d['name']), $deptNoHead)) : 'ครบทุกแผนก');
$critSup = count(supervision_criteria_active('supervisor'));
$critStu = count(supervision_criteria_active('student'));
$add('ข้อมูลพื้นฐาน', $critSup > 0 && $critStu > 0 ? 'ok' : 'warn', 'หัวข้อแบบประเมินนิเทศ',
    'โดยหัวหน้าแผนก ' . $critSup . ' ข้อ · โดยนักเรียน ' . $critStu . ' ข้อ');
$add('ข้อมูลพื้นฐาน', site_url_is_local() ? ($isLocal ? 'warn' : 'fail') : 'ok', 'URL สำหรับ QR Code ให้นักเรียนประเมิน',
    site_base_url() . (site_url_is_local() ? ' — โทรศัพท์นักเรียนเปิดไม่ได้ ตั้ง “URL หลักของระบบ” ในหน้าตั้งค่าระบบ' : ''));

// สรุปผล
$sum = ['ok' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $items) {
    foreach ($items as [$s]) {
        $sum[$s]++;
    }
}
$statusIcon = ['ok' => '<i class="bi bi-check-circle-fill text-success"></i>', 'warn' => '<i class="bi bi-exclamation-triangle-fill text-warning"></i>', 'fail' => '<i class="bi bi-x-circle-fill text-danger"></i>'];

$pageTitle = 'ตรวจสอบระบบ';
$pageIcon = 'bi-heart-pulse';
$breadcrumbs = ['ตรวจสอบระบบ' => null];
$pageActions = '<a href="' . e(url('admin/system_check.php')) . '" class="btn btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>ตรวจอีกครั้ง</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= $sum['ok'] ?></div><div class="stat-label">ผ่าน</div></div></div></div>
    <div class="col-4"><div class="stat-card"><div class="stat-icon ic-yellow"><i class="bi bi-exclamation-triangle"></i></div><div><div class="stat-value"><?= $sum['warn'] ?></div><div class="stat-label">ควรแก้ไข</div></div></div></div>
    <div class="col-4"><div class="stat-card"><div class="stat-icon ic-red"><i class="bi bi-x-circle"></i></div><div><div class="stat-value"><?= $sum['fail'] ?></div><div class="stat-label">ต้องแก้ไข</div></div></div></div>
</div>

<?php foreach ($checks as $group => $items): ?>
    <div class="card mb-4">
        <div class="card-header"><?= e($group) ?></div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <tbody>
                <?php foreach ($items as [$s, $title, $detail]): ?>
                    <tr class="<?= $s === 'fail' ? 'table-danger' : ($s === 'warn' ? 'table-warning' : '') ?>">
                        <td style="width:40px" class="text-center fs-5"><?= $statusIcon[$s] ?></td>
                        <td style="width:30%" class="fw-semibold"><?= e($title) ?></td>
                        <td class="small"><?= e($detail) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
<p class="small text-muted">ระบบ <?= e(system_name()) ?> v<?= e(APP_VERSION) ?> · PHP <?= e(PHP_VERSION) ?> · <?= e($_SERVER['SERVER_SOFTWARE'] ?? '') ?></p>
<?php require APP_ROOT . '/includes/footer.php'; ?>
