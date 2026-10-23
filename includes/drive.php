<?php
/**
 * ไฟล์: includes/drive.php
 * ที่เก็บไฟล์งานใน Google Drive (v1.18) — เรียก Drive REST API v3 ด้วย curl โดยตรง (ไม่ใช้ composer)
 *
 * หลักการ
 *  - ผู้ดูแลระบบกด "เชื่อมต่อ Google Drive" ที่ admin/drive.php ครั้งเดียว (OAuth ด้วย Client ID/Secret เดียวกับ Google login)
 *    ขอสิทธิ์ drive.file = เห็น/จัดการได้เฉพาะไฟล์ที่ระบบนี้สร้างเท่านั้น (ระบบสร้างโฟลเดอร์ "TeachHub PT" ใน My Drive เอง)
 *    ถ้ากำหนด "โฟลเดอร์ปลายทาง" ที่ผู้ใช้สร้างไว้เอง ต้องขอสิทธิ์ drive เต็ม เพราะ drive.file เข้าถึงโฟลเดอร์ที่ระบบไม่ได้สร้างไม่ได้
 *  - ใต้โฟลเดอร์หลัก ระบบสร้างโฟลเดอร์ย่อยแยกตามงานให้อัตโนมัติ (drive_folder_names)
 *  - refresh token เก็บในไฟล์ config/drive_token.php (ไม่อยู่ในฐานข้อมูล/ไฟล์สำรอง/git, config/ มี .htaccess กันไว้
 *    และเป็นไฟล์ PHP เปิดผ่านเว็บก็ไม่แสดงเนื้อหา)
 *  - handle_upload() บันทึกไฟล์ลงโฮสต์ก่อน แล้วถ้าเปิดใช้ Drive จะส่งขึ้น Drive → บันทึกตำแหน่งในตาราง drive_files → ลบไฟล์บนโฮสต์
 *    ถ้าส่งขึ้น Drive ไม่สำเร็จ ไฟล์ยังอยู่บนโฮสต์และใช้งานได้ตามปกติ (ย้ายภายหลังได้ที่หน้า admin/drive.php)
 *  - send_file() ส่งไฟล์บนโฮสต์ก่อน ถ้าไม่มีจึงดึงจาก Drive (ผู้ใช้ไม่เห็นลิงก์ Drive ระบบยังตรวจสิทธิ์ทุกครั้งเหมือนเดิม)
 *  - delete_upload() ลบไฟล์บนโฮสต์ และย้ายไฟล์บน Drive ไปถังขยะ (กู้คืนได้ใน 30 วัน)
 * รูปโปรไฟล์ (avatars) และโลโก้ (system) ยังเก็บบนโฮสต์เสมอ เพราะเปิดบ่อยและไฟล์เล็ก
 */

const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
const DRIVE_SCOPE_FULL = 'https://www.googleapis.com/auth/drive';
const DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';

/** โฟลเดอร์ใน uploads/ ที่เก็บใน Drive => ชื่อที่แสดง */
function drive_subdirs(): array
{
    return [
        'lesson_plans' => 'โครงการสอน/แผนการสอน',
        'pa'           => 'ข้อตกลง PA',
        'research'     => 'วิจัยในชั้นเรียน',
        'supervision'  => 'รูปหลักฐานการนิเทศ',
        'advisor'      => 'รูปกิจกรรมโฮมรูม',
        'home_visit'   => 'รูปการเยี่ยมบ้าน',
        'downloads'    => 'เอกสารดาวน์โหลด',
    ];
}

/** ชื่อโฟลเดอร์ย่อยใน Drive แยกตามงาน (backups = สำเนาไฟล์สำรองข้อมูล ถ้าเปิดใช้) */
function drive_folder_names(): array
{
    return [
        'lesson_plans' => 'โครงการสอน-แผนการสอน',
        'pa'           => 'ข้อตกลง PA',
        'research'     => 'วิจัยในชั้นเรียน',
        'supervision'  => 'นิเทศการสอน',
        'advisor'      => 'รายงานกิจกรรมโฮมรูม',
        'home_visit'   => 'เยี่ยมบ้าน',
        'downloads'    => 'เอกสารดาวน์โหลด',
        'backups'      => 'สำรองข้อมูล',
    ];
}

/** ID โฟลเดอร์ปลายทางที่ผู้ดูแลกำหนด ('' = ระบบสร้างโฟลเดอร์หลักเองใน My Drive) */
function drive_parent_id(): string
{
    return (string)setting('drive_parent_id');
}

/** แยก folder ID จากลิงก์ Google Drive หรือ ID ที่วางมา ('' = รูปแบบไม่ถูกต้อง) */
function drive_parse_folder_id(string $in): string
{
    $in = trim($in);
    if (preg_match('#/folders/([A-Za-z0-9_-]{10,})#', $in, $m) || preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $in, $m)) {
        return $m[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{10,}$/', $in) ? $in : '';
}

/** สิทธิ์ที่ต้องขอจาก Google ตามการตั้งค่าปัจจุบัน */
function drive_scope(): string
{
    return drive_parent_id() !== '' ? DRIVE_SCOPE_FULL : DRIVE_SCOPE;
}

/** token ที่เชื่อมต่อไว้มีสิทธิ์พอสำหรับการตั้งค่าปัจจุบันหรือไม่ (เปลี่ยนไปใช้โฟลเดอร์ที่กำหนดเอง → ต้องเชื่อมต่อใหม่) */
function drive_scope_ok(): bool
{
    $t = drive_token();
    if (!$t) {
        return false;
    }
    $granted = explode(' ', (string)($t['scope'] ?? DRIVE_SCOPE));
    return in_array(DRIVE_SCOPE_FULL, $granted, true) || in_array(drive_scope(), $granted, true);
}

/** ไฟล์เก็บ token (นอก git — ดู .gitignore) */
function drive_token_file(): string
{
    return APP_ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'drive_token.php';
}

/** อ่าน token ที่บันทึกไว้ (null = ยังไม่เชื่อมต่อ) */
function drive_token(bool $reload = false): ?array
{
    static $cache = false;
    if ($cache === false || $reload) {
        $file = drive_token_file();
        $data = is_file($file) ? (include $file) : null;
        $cache = is_array($data) && !empty($data['refresh_token']) ? $data : null;
    }
    return $cache;
}

/** บันทึก token ลงไฟล์ (null = ลบไฟล์) */
function drive_token_save(?array $data): bool
{
    $file = drive_token_file();
    if ($data === null) {
        $ok = !is_file($file) || @unlink($file);
    } else {
        $php = "<?php\n// Google Drive token ของ TeachHub PT — สร้างโดยระบบ ห้ามแก้ไข/เผยแพร่ ห้ามนำขึ้น git\nreturn "
            . var_export($data, true) . ";\n";
        $ok = @file_put_contents($file, $php, LOCK_EX) !== false;
        if ($ok) {
            @chmod($file, 0600);
        }
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($file, true);
    }
    drive_token(true);
    return $ok;
}

/** ตาราง drive_files พร้อมใช้หรือยัง (นำเข้า update_v1.18.sql) */
function drive_table_ready(): bool
{
    static $ready = null;
    if ($ready === null) {
        $ready = (bool)db_value("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                 AND TABLE_NAME = 'drive_files' LIMIT 1");
    }
    return $ready;
}

/** ตั้งค่า OAuth (Client ID/Secret) ไว้แล้วหรือยัง — ใช้ร่วมกับ Google login */
function drive_client_ready(): bool
{
    return trim((string)setting('google_client_id')) !== '' && trim((string)setting('google_client_secret')) !== '';
}

/** เชื่อมต่อ Drive แล้ว (มี refresh token และตารางพร้อม) */
function drive_connected(): bool
{
    return drive_token() !== null && drive_table_ready() && drive_client_ready();
}

/** ไฟล์อัปโหลดใหม่ของโฟลเดอร์นี้ต้องส่งขึ้น Drive หรือไม่ */
function drive_active_for(string $subdir): bool
{
    return setting('drive_enabled', '0') === '1' && isset(drive_subdirs()[$subdir]) && drive_connected() && drive_scope_ok();
}

/**
 * เรียก Google API
 * @param array $opt json (array → ส่งเป็น JSON), form (array → x-www-form-urlencoded), headers, infile (path ไฟล์ที่ส่งเป็น body), bearer
 * @return array ['code'=>int, 'body'=>string, 'json'=>?array, 'headers'=>[ชื่อพิมพ์เล็ก=>ค่า], 'error'=>string]
 */
function drive_http(string $method, string $url, array $opt = []): array
{
    $ch = curl_init($url);
    $headers = $opt['headers'] ?? [];
    if (!empty($opt['bearer'])) {
        $headers[] = 'Authorization: Bearer ' . $opt['bearer'];
    }
    $respHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => $opt['timeout'] ?? 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $p = strpos($line, ':');
            if ($p !== false) {
                $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            }
            return strlen($line);
        },
    ]);
    $fh = null;
    if (isset($opt['json'])) {
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'], JSON_UNESCAPED_UNICODE));
    } elseif (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    } elseif (isset($opt['infile'])) {
        $fh = fopen($opt['infile'], 'rb');
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($opt['infile'])]);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '');
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) {
        fclose($fh);
    }
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['code' => $code, 'body' => (string)$body, 'json' => is_array($json) ? $json : null, 'headers' => $respHeaders, 'error' => $err];
}

/** ข้อความผิดพลาดจากผลการเรียก API */
function drive_error_text(array $r): string
{
    if ($r['code'] === 0) {
        return 'เชื่อมต่อ Google ไม่ได้ (' . $r['error'] . ')';
    }
    $msg = $r['json']['error']['message'] ?? ($r['json']['error_description'] ?? ($r['json']['error'] ?? ''));
    return 'HTTP ' . $r['code'] . (is_string($msg) && $msg !== '' ? ' — ' . mb_substr($msg, 0, 200) : '');
}

/**
 * access token ที่ใช้ได้ (ต่ออายุด้วย refresh token อัตโนมัติ)
 * @return string '' = ใช้ไม่ได้ (ดูสาเหตุที่ drive_token()['last_error'])
 */
function drive_access_token(bool $force = false): string
{
    $t = drive_token();
    if (!$t) {
        return '';
    }
    if (!$force && !empty($t['access_token']) && (int)($t['expires_at'] ?? 0) > time() + 60) {
        return (string)$t['access_token'];
    }
    $r = drive_http('POST', google_endpoint('token'), ['timeout' => 20, 'form' => [
        'client_id'     => trim((string)setting('google_client_id')),
        'client_secret' => trim((string)setting('google_client_secret')),
        'refresh_token' => $t['refresh_token'],
        'grant_type'    => 'refresh_token',
    ]]);
    if ($r['code'] !== 200 || empty($r['json']['access_token'])) {
        $t['last_error'] = ($r['json']['error'] ?? '') === 'invalid_grant'
            ? 'สิทธิ์เข้าถึง Google Drive หมดอายุหรือถูกยกเลิก — กรุณากดเชื่อมต่อใหม่ด้วยบัญชีเดิม'
            : 'ต่ออายุสิทธิ์ Google Drive ไม่สำเร็จ: ' . drive_error_text($r);
        $t['last_error_at'] = date('Y-m-d H:i:s');
        drive_token_save($t);
        error_log('Drive token refresh: ' . drive_error_text($r));
        return '';
    }
    $t['access_token'] = (string)$r['json']['access_token'];
    $t['expires_at'] = time() + (int)($r['json']['expires_in'] ?? 3600);
    unset($t['last_error'], $t['last_error_at']);
    drive_token_save($t);
    return $t['access_token'];
}

/** เรียก Drive API พร้อม access token (ถ้าได้ 401 จะต่ออายุ token แล้วลองใหม่ 1 ครั้ง) */
function drive_api(string $method, string $url, array $opt = []): array
{
    $token = drive_access_token();
    if ($token === '') {
        return ['code' => 0, 'body' => '', 'json' => null, 'headers' => [], 'error' => (string)(drive_token()['last_error'] ?? 'ยังไม่ได้เชื่อมต่อ Google Drive')];
    }
    $r = drive_http($method, $url, $opt + ['bearer' => $token]);
    if ($r['code'] === 401 && ($token = drive_access_token(true)) !== '') {
        $r = drive_http($method, $url, ['bearer' => $token] + $opt);
    }
    return $r;
}

/** ข้อมูลบัญชีและพื้นที่ของ Drive ที่เชื่อมต่อ */
function drive_about(): array
{
    $r = drive_api('GET', google_endpoint('drive') . '/about?fields=' . rawurlencode('user(displayName,emailAddress),storageQuota'), ['timeout' => 15]);
    if ($r['code'] !== 200 || !$r['json']) {
        return ['ok' => false, 'error' => drive_error_text($r)];
    }
    return ['ok' => true, 'error' => '', 'user' => $r['json']['user'] ?? [], 'quota' => $r['json']['storageQuota'] ?? []];
}

/** โฟลเดอร์หลักอยู่ในถังขยะของ Drive หรือไม่ (ไฟล์ข้างในจะถูกลบถาวรใน 30 วัน) */
function drive_root_trashed(): bool
{
    $folders = json_decode((string)setting('drive_folders', '{}'), true) ?: [];
    if (empty($folders['_root'])) {
        return false;
    }
    $r = drive_api('GET', google_endpoint('drive') . '/files/' . rawurlencode($folders['_root']) . '?fields=trashed&supportsAllDrives=true', ['timeout' => 15]);
    return $r['code'] === 200 && !empty($r['json']['trashed']);
}

/** ตรวจโฟลเดอร์ปลายทาง: ['ok'=>bool, 'name'=>ชื่อโฟลเดอร์, 'error'=>ข้อความ] */
function drive_folder_info(string $id): array
{
    $r = drive_api('GET', google_endpoint('drive') . '/files/' . rawurlencode($id)
        . '?supportsAllDrives=true&fields=' . rawurlencode('id,name,mimeType,trashed,capabilities(canAddChildren)'), ['timeout' => 15]);
    if ($r['code'] === 404) {
        return ['ok' => false, 'name' => '', 'error' => 'ไม่พบโฟลเดอร์ หรือบัญชีที่เชื่อมต่อไม่มีสิทธิ์เข้าถึงโฟลเดอร์นี้'];
    }
    if ($r['code'] !== 200 || !$r['json']) {
        return ['ok' => false, 'name' => '', 'error' => 'ตรวจโฟลเดอร์ไม่สำเร็จ: ' . drive_error_text($r)];
    }
    $f = $r['json'];
    $name = (string)($f['name'] ?? '');
    if (($f['mimeType'] ?? '') !== DRIVE_FOLDER_MIME) {
        return ['ok' => false, 'name' => $name, 'error' => 'ลิงก์นี้ไม่ใช่โฟลเดอร์'];
    }
    if (!empty($f['trashed'])) {
        return ['ok' => false, 'name' => $name, 'error' => 'โฟลเดอร์นี้อยู่ในถังขยะ'];
    }
    if (isset($f['capabilities']['canAddChildren']) && !$f['capabilities']['canAddChildren']) {
        return ['ok' => false, 'name' => $name, 'error' => 'บัญชีที่เชื่อมต่อไม่มีสิทธิ์เพิ่มไฟล์ในโฟลเดอร์นี้ (ต้องเป็นเจ้าของหรือผู้แก้ไข)'];
    }
    return ['ok' => true, 'name' => $name, 'error' => ''];
}

/** สร้างโฟลเดอร์ใน Drive คืน id หรือ '' */
function drive_create_folder(string $name, string $parent = ''): string
{
    $meta = ['name' => $name, 'mimeType' => DRIVE_FOLDER_MIME];
    if ($parent !== '') {
        $meta['parents'] = [$parent];
    }
    $r = drive_api('POST', google_endpoint('drive') . '/files?fields=id&supportsAllDrives=true', ['json' => $meta, 'timeout' => 20]);
    return $r['code'] === 200 ? (string)($r['json']['id'] ?? '') : '';
}

/**
 * id ของโฟลเดอร์ใน Drive สำหรับ subdir (สร้างให้อัตโนมัติ: "TeachHub PT/{subdir}") — '' = ไม่สำเร็จ
 * @param bool $reset โฟลเดอร์ย่อยเดิมใช้ไม่ได้ (ถูกลบ) → สร้างใหม่ ถ้าโฟลเดอร์หลักก็ถูกลบด้วยจะสร้างโฟลเดอร์หลักใหม่
 */
function drive_folder_id(string $subdir, bool $reset = false): string
{
    $folders = json_decode((string)setting('drive_folders', '{}'), true) ?: [];
    $newRoot = function () {
        // กำหนดโฟลเดอร์ปลายทางไว้ → ใช้โฟลเดอร์นั้นเป็นโฟลเดอร์หลัก (ไม่สร้างใหม่)
        $parent = drive_parent_id();
        return ['_root' => $parent !== '' ? $parent : drive_create_folder((string)setting('drive_root_name', 'TeachHub PT'))];
    };
    if ($reset) {
        unset($folders[$subdir]);
    }
    if (empty($folders['_root'])) {
        $folders = $newRoot();
        if ($folders['_root'] === '') {
            return '';
        }
    }
    if ($subdir === '_root') {
        setting_save('drive_folders', json_encode($folders));
        return $folders['_root'];
    }
    if (empty($folders[$subdir])) {
        $name = drive_folder_names()[$subdir] ?? $subdir;
        $folders[$subdir] = drive_create_folder($name, $folders['_root']);
        if ($folders[$subdir] === '' && $reset) {
            $folders = $newRoot();
            $folders[$subdir] = $folders['_root'] !== '' ? drive_create_folder($name, $folders['_root']) : '';
        }
        if ($folders[$subdir] === '') {
            return '';
        }
    }
    setting_save('drive_folders', json_encode($folders));
    return $folders[$subdir];
}

/** ส่งไฟล์ 1 ไฟล์ขึ้น Drive (resumable upload — ไม่โหลดไฟล์ทั้งก้อนเข้าหน่วยความจำ) */
function drive_upload_file(string $path, string $subdir, string $name, string $mime): array
{
    $fail = fn(string $msg) => ['ok' => false, 'error' => $msg];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $folder = drive_folder_id($subdir, $attempt > 0);
        if ($folder === '') {
            return $fail('สร้างโฟลเดอร์ใน Google Drive ไม่สำเร็จ');
        }
        $size = (int)filesize($path);
        $init = drive_api('POST', google_endpoint('drive_upload') . '/files?uploadType=resumable&supportsAllDrives=true&fields=id,size', [
            'json'    => ['name' => $name, 'parents' => [$folder]],
            'headers' => ['X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . $size],
            'timeout' => 20,
        ]);
        if ($init['code'] === 404 && $attempt === 0) {
            continue; // โฟลเดอร์ถูกลบใน Drive → สร้างใหม่แล้วลองอีกครั้ง
        }
        $location = $init['headers']['location'] ?? '';
        if ($init['code'] !== 200 || $location === '') {
            return $fail('เริ่มส่งไฟล์ขึ้น Google Drive ไม่สำเร็จ: ' . drive_error_text($init));
        }
        $put = drive_http('PUT', $location, ['infile' => $path, 'headers' => ['Content-Type: ' . $mime], 'timeout' => 1800]);
        if (!in_array($put['code'], [200, 201], true) || empty($put['json']['id'])) {
            return $fail('ส่งไฟล์ขึ้น Google Drive ไม่สำเร็จ: ' . drive_error_text($put));
        }
        if (isset($put['json']['size']) && (int)$put['json']['size'] !== $size) {
            drive_trash((string)$put['json']['id']);
            return $fail('ขนาดไฟล์บน Google Drive ไม่ตรงกับต้นฉบับ');
        }
        return ['ok' => true, 'error' => '', 'id' => (string)$put['json']['id']];
    }
    return $fail('ส่งไฟล์ขึ้น Google Drive ไม่สำเร็จ');
}

/** ย้ายไฟล์บน Drive ไปถังขยะ */
function drive_trash(string $driveId): bool
{
    $r = drive_api('PATCH', google_endpoint('drive') . '/files/' . rawurlencode($driveId) . '?fields=id&supportsAllDrives=true',['json' => ['trashed' => true], 'timeout' => 20]);
    return $r['code'] === 200 || $r['code'] === 404;
}

/** แถวใน drive_files ของไฟล์นี้ */
function drive_file_row(string $subdir, string $stored): ?array
{
    if (!drive_table_ready()) {
        return null;
    }
    return db_one('SELECT * FROM drive_files WHERE subdir = ? AND stored_name = ?', 'ss', [$subdir, $stored]);
}

/**
 * ย้ายไฟล์บนโฮสต์ (uploads/{subdir}/{stored}) ขึ้น Drive แล้วลบไฟล์บนโฮสต์
 * @return array ['ok'=>bool, 'error'=>string]
 */
function drive_push_local(string $subdir, string $stored): array
{
    $path = UPLOAD_PATH . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . basename($stored);
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'ไม่พบไฟล์บนโฮสต์'];
    }
    $ext = strtolower(pathinfo($stored, PATHINFO_EXTENSION));
    $size = (int)filesize($path);
    $up = drive_upload_file($path, $subdir, $stored, mime_for_ext($ext));
    if (!$up['ok']) {
        return $up;
    }
    db_exec('INSERT INTO drive_files (subdir, stored_name, drive_id, size) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE drive_id = VALUES(drive_id), size = VALUES(size), created_at = NOW()',
        'sssi', [$subdir, $stored, $up['id'], $size]);
    @unlink($path);
    return ['ok' => true, 'error' => ''];
}

/**
 * ดึงไฟล์จาก Drive กลับมาเก็บบนโฮสต์ แล้วย้ายไฟล์บน Drive ไปถังขยะ
 * @return array ['ok'=>bool, 'error'=>string]
 */
function drive_pull_local(array $row): array
{
    $dir = UPLOAD_PATH . DIRECTORY_SEPARATOR . $row['subdir'];
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'error' => 'สร้างโฟลเดอร์ uploads/' . $row['subdir'] . ' ไม่ได้'];
    }
    $dest = $dir . DIRECTORY_SEPARATOR . basename($row['stored_name']);
    $tmp = $dest . '.part';
    $token = drive_access_token();
    if ($token === '') {
        return ['ok' => false, 'error' => (string)(drive_token()['last_error'] ?? 'ยังไม่ได้เชื่อมต่อ Google Drive')];
    }
    $fh = fopen($tmp, 'wb');
    $ch = curl_init(google_endpoint('drive') . '/files/' . rawurlencode($row['drive_id']) . '?alt=media&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($code !== 200 || (int)filesize($tmp) !== (int)$row['size']) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'ดาวน์โหลดจาก Google Drive ไม่สำเร็จ (HTTP ' . $code . ($err !== '' ? ' ' . $err : '') . ')'];
    }
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'บันทึกไฟล์บนโฮสต์ไม่ได้'];
    }
    @chmod($dest, 0644);
    db_exec('DELETE FROM drive_files WHERE id = ?', 'i', [(int)$row['id']]);
    drive_trash((string)$row['drive_id']);
    return ['ok' => true, 'error' => ''];
}

/** ไฟล์ที่ยังอยู่บนโฮสต์ในโฟลเดอร์ที่เก็บใน Drive: [[subdir, stored_name, size], ...] */
function drive_local_files(?string $only = null): array
{
    $list = [];
    foreach (array_keys(drive_subdirs()) as $subdir) {
        if ($only !== null && $only !== $subdir) {
            continue;
        }
        foreach (glob(UPLOAD_PATH . DIRECTORY_SEPARATOR . $subdir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            $name = basename($path);
            if (is_file($path) && preg_match('/^[A-Za-z0-9_-]+\.[a-z0-9]{1,5}$/', $name) && $name !== 'index.html') {
                $list[] = [$subdir, $name, (int)filesize($path)];
            }
        }
    }
    return $list;
}

/** สรุปจำนวน/ขนาดไฟล์บนโฮสต์และบน Drive รายโฟลเดอร์ */
function drive_summary(): array
{
    $sum = [];
    foreach (drive_subdirs() as $subdir => $label) {
        $sum[$subdir] = ['label' => $label, 'local' => 0, 'local_bytes' => 0, 'drive' => 0, 'drive_bytes' => 0];
    }
    foreach (drive_local_files() as [$subdir, , $size]) {
        $sum[$subdir]['local']++;
        $sum[$subdir]['local_bytes'] += $size;
    }
    if (drive_table_ready()) {
        foreach (db_all("SELECT subdir, COUNT(*) AS n, COALESCE(SUM(size), 0) AS b FROM drive_files WHERE subdir <> 'backups' GROUP BY subdir") as $r) {
            if (isset($sum[$r['subdir']])) {
                $sum[$r['subdir']]['drive'] = (int)$r['n'];
                $sum[$r['subdir']]['drive_bytes'] = (int)$r['b'];
            }
        }
    }
    return $sum;
}

/**
 * เรียกจาก handle_upload(): ส่งไฟล์ที่เพิ่งบันทึกขึ้น Drive (ถ้าเปิดใช้) — ไม่สำเร็จก็เก็บบนโฮสต์ต่อไป
 */
function drive_after_upload(string $subdir, string $stored): void
{
    if (!drive_active_for($subdir)) {
        return;
    }
    $res = drive_push_local($subdir, $stored);
    if (!$res['ok']) {
        error_log('Drive upload ' . $subdir . '/' . $stored . ': ' . $res['error']);
        setting_save('drive_last_error', date('Y-m-d H:i:s') . ' ' . mb_substr($res['error'], 0, 230));
    }
}

/** เรียกจาก delete_upload(): ลบไฟล์บน Drive (ย้ายไปถังขยะ) และแถวใน drive_files */
function drive_delete(string $subdir, string $stored): void
{
    $row = drive_file_row($subdir, $stored);
    if (!$row) {
        return;
    }
    if (!drive_trash((string)$row['drive_id'])) {
        error_log('Drive trash failed: ' . $row['drive_id']);
    }
    db_exec('DELETE FROM drive_files WHERE id = ?', 'i', [(int)$row['id']]);
}

/**
 * เรียกจาก send_file() เมื่อไม่มีไฟล์บนโฮสต์: ส่งไฟล์จาก Drive ให้ผู้ใช้ (stream ทีละส่วน)
 * @param array $headers header ที่ต้องส่ง (ยกเว้น Content-Length ซึ่งใช้ขนาดจาก drive_files)
 * @return bool false = ไม่มีไฟล์นี้บน Drive
 */
function drive_send(string $subdir, string $stored, array $headers): bool
{
    $row = drive_file_row($subdir, $stored);
    if (!$row) {
        return false;
    }
    // ไฟล์ชื่อสุ่มไม่เปลี่ยนเนื้อหา — ให้เบราว์เซอร์ใช้สำเนาเดิมได้ (ตรวจสิทธิ์แล้วก่อนถึงตรงนี้) ลดการดึงจาก Drive ซ้ำ
    $etag = '"' . substr(hash('sha256', $row['drive_id']), 0, 32) . '"';
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        header('ETag: ' . $etag);
        http_response_code(304);
        exit;
    }
    $token = drive_access_token();
    if ($token === '') {
        abort_page(503, 'เปิดไฟล์ไม่ได้ชั่วคราว', 'ระบบเชื่อมต่อที่เก็บไฟล์ Google Drive ไม่ได้ กรุณาแจ้งผู้ดูแลระบบ');
    }
    $started = false;
    $status = 0;
    $ch = curl_init(google_endpoint('drive') . '/files/' . rawurlencode($row['drive_id']) . '?alt=media&supportsAllDrives=true');
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$status) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int)$m[1];
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$started, &$status, $headers, $row, $etag) {
            if ($status !== 200) {
                return strlen($chunk); // เนื้อหาข้อผิดพลาดจาก Google ไม่ส่งต่อให้ผู้ใช้
            }
            if (!$started) {
                $started = true;
                if (ob_get_level()) {
                    ob_end_clean();
                }
                foreach ($headers as $h) {
                    header($h);
                }
                header('Content-Length: ' . (int)$row['size']);
                header('ETag: ' . $etag);
                header('Cache-Control: private, max-age=86400');
                header_remove('Pragma');
            }
            echo $chunk;
            flush();
            return strlen($chunk);
        },
    ]);
    curl_exec($ch);
    curl_close($ch);
    if (!$started) {
        error_log('Drive download ' . $row['drive_id'] . ' HTTP ' . $status);
        if ($status === 404) {
            abort_page(404, 'ไม่พบไฟล์', 'ไฟล์ถูกลบออกจาก Google Drive แล้ว');
        }
        abort_page(502, 'เปิดไฟล์ไม่ได้ชั่วคราว', 'ดึงไฟล์จาก Google Drive ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');
    }
    exit;
}

/** เริ่มเชื่อมต่อ: URL ไปหน้าขอสิทธิ์ของ Google (state/PKCE เก็บใน session — กลับมาที่ auth/google_callback.php) */
function drive_connect_url(): string
{
    return google_auth_url('drive', 'admin/drive.php');
}

/** บันทึกผลการเชื่อมต่อจาก callback */
function drive_finish_connect(array $res, array $user): array
{
    $tok = $res['token'] ?? [];
    if (empty($tok['refresh_token'])) {
        return ['ok' => false, 'error' => 'Google ไม่ได้ส่ง refresh token กลับมา — ลองกดเชื่อมต่อใหม่อีกครั้ง'];
    }
    $scopes = explode(' ', (string)($tok['scope'] ?? drive_scope()));
    if (!in_array(drive_scope(), $scopes, true) && !in_array(DRIVE_SCOPE_FULL, $scopes, true)) {
        return ['ok' => false, 'error' => 'ไม่ได้รับสิทธิ์จัดการไฟล์ใน Google Drive — กรุณาติ๊กอนุญาตสิทธิ์ Google Drive ในหน้ายืนยันของ Google'];
    }
    $old = drive_token();
    if ($old && !empty($old['email']) && $old['email'] !== $res['profile']['email'] && drive_table_ready()
        && (int)db_value('SELECT COUNT(*) FROM drive_files') > 0) {
        return ['ok' => false, 'error' => 'มีไฟล์เก็บใน Google Drive ของบัญชี ' . $old['email']
            . ' อยู่แล้ว ต้องเชื่อมต่อด้วยบัญชีเดิม (หรือดึงไฟล์กลับโฮสต์ก่อน แล้วยกเลิกการเชื่อมต่อ)'];
    }
    $data = [
        'refresh_token' => (string)$tok['refresh_token'],
        'access_token'  => (string)($tok['access_token'] ?? ''),
        'expires_at'    => time() + (int)($tok['expires_in'] ?? 3600),
        'email'         => $res['profile']['email'],
        'scope'         => implode(' ', array_values(array_intersect([DRIVE_SCOPE, DRIVE_SCOPE_FULL], $scopes))),
        'connected_at'  => date('Y-m-d H:i:s'),
        'connected_by'  => (int)$user['id'],
    ];
    if (!drive_token_save($data)) {
        return ['ok' => false, 'error' => 'บันทึกไฟล์ config/drive_token.php ไม่ได้ — ตรวจสิทธิ์เขียนโฟลเดอร์ config'];
    }
    if ($old && ($old['email'] ?? '') !== $data['email']) {
        setting_save('drive_folders', '{}'); // บัญชีใหม่ → สร้างโฟลเดอร์ใหม่
    }
    if (drive_parent_id() !== '') {
        $info = drive_folder_info(drive_parent_id());
        if (!$info['ok']) {
            return ['ok' => true, 'error' => 'เชื่อมต่อแล้ว แต่ใช้โฟลเดอร์ปลายทางไม่ได้: ' . $info['error']];
        }
    } elseif (drive_folder_id('_root') === '') {
        return ['ok' => true, 'error' => 'เชื่อมต่อแล้ว แต่สร้างโฟลเดอร์ใน Google Drive ไม่สำเร็จ — ตรวจว่าเปิดใช้ Google Drive API ในโปรเจกต์แล้ว'];
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * ส่งสำเนาไฟล์สำรองข้อมูลขึ้นโฟลเดอร์ "สำรองข้อมูล" ใน Drive (ไฟล์บนโฮสต์ยังอยู่ เพื่อใช้กู้คืน)
 * @return string ข้อผิดพลาด ('' = สำเร็จหรือไม่ได้เปิดใช้)
 */
function drive_backup_copy(string $name): string
{
    if (setting('drive_backup', '0') !== '1' || !drive_connected() || !drive_scope_ok()) {
        return '';
    }
    $path = backup_dir() . DIRECTORY_SEPARATOR . $name;
    $up = drive_upload_file($path, 'backups', $name, 'application/zip');
    if (!$up['ok']) {
        error_log('Drive backup copy ' . $name . ': ' . $up['error']);
        setting_save('drive_last_error', date('Y-m-d H:i:s') . ' ไฟล์สำรอง ' . $name . ': ' . mb_substr($up['error'], 0, 200));
        return $up['error'];
    }
    db_exec('INSERT INTO drive_files (subdir, stored_name, drive_id, size) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE drive_id = VALUES(drive_id), size = VALUES(size), created_at = NOW()',
        'sssi', ['backups', $name, $up['id'], (int)filesize($path)]);
    return '';
}

/** ยกเลิกการเชื่อมต่อ (เพิกถอน token ที่ Google + ลบไฟล์ token) */
function drive_disconnect(): void
{
    $t = drive_token();
    if ($t) {
        drive_http('POST', google_endpoint('revoke'), ['form' => ['token' => $t['refresh_token']], 'timeout' => 10]);
    }
    drive_token_save(null);
    setting_save('drive_enabled', '0');
}
