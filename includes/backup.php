<?php
/**
 * ไฟล์: includes/backup.php
 * ระบบสำรองข้อมูล / กู้คืนข้อมูล (สำหรับผู้ดูแลระบบ)
 *
 * ไฟล์สำรอง = ZIP 1 ไฟล์ ประกอบด้วย
 *   manifest.json   ข้อมูลไฟล์สำรอง (เวอร์ชันระบบ วันเวลา จำนวนตาราง/แถว รวมไฟล์แนบหรือไม่)
 *   database.sql    โครงสร้าง + ข้อมูลทุกตาราง (1 บรรทัด = 1 คำสั่ง SQL — กู้คืนทีละคำสั่ง ไม่ติดขนาด max_allowed_packet)
 *   uploads/...     ไฟล์แนบทั้งหมด (ถ้าเลือก "รวมไฟล์แนบ")
 * เก็บที่ uploads/backups/ (ห้ามเข้าถึงผ่านเว็บโดยตรง ดาวน์โหลดผ่านหน้า admin เท่านั้น)
 */

const BACKUP_SQL_MARKER = '-- TeachHub PT backup';
const BACKUP_NAME_PATTERN = '/^(teachhub|auto|prerestore|upload)_\d{8}_\d{6}_[a-f0-9]{6}\.zip$/';

/** โฟลเดอร์เก็บไฟล์สำรอง */
function backup_dir(): string
{
    $dir = UPLOAD_PATH . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_file($dir . DIRECTORY_SEPARATOR . 'index.html')) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    return $dir;
}

/** ตรวจชื่อไฟล์สำรอง (กัน path traversal) แล้วคืน path เต็ม หรือ null */
function backup_path(string $name): ?string
{
    if (!preg_match(BACKUP_NAME_PATTERN, $name)) {
        return null;
    }
    $path = backup_dir() . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? $path : null;
}

/** ชื่อไฟล์ใหม่ */
function backup_new_name(string $prefix): string
{
    return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.zip';
}

/** ชนิดไฟล์สำรองจากคำนำหน้าชื่อ */
function backup_kind_label(string $name): string
{
    $map = ['teachhub' => 'สำรองด้วยตนเอง', 'auto' => 'สำรองอัตโนมัติ', 'prerestore' => 'ก่อนกู้คืน', 'upload' => 'อัปโหลดเข้ามา'];
    return $map[strstr($name, '_', true)] ?? '-';
}

/** อ่าน manifest.json จากไฟล์ ZIP */
function backup_manifest(string $path): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return null;
    }
    $json = $zip->getFromName('manifest.json');
    $zip->close();
    $m = $json !== false ? json_decode($json, true) : null;
    return is_array($m) && ($m['app'] ?? '') === 'TeachHub PT' ? $m : null;
}

/** รายการไฟล์สำรอง (ใหม่สุดก่อน) */
function backup_list(): array
{
    $rows = [];
    foreach (glob(backup_dir() . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match(BACKUP_NAME_PATTERN, $name)) {
            continue;
        }
        $rows[] = [
            'name'     => $name,
            'size'     => (int)filesize($path),
            'mtime'    => (int)filemtime($path),
            'kind'     => backup_kind_label($name),
            'manifest' => backup_manifest($path),
        ];
    }
    usort($rows, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    return $rows;
}

/** ตารางทั้งหมดของฐานข้อมูลปัจจุบัน */
function backup_tables(): array
{
    return array_column(db_all("SELECT TABLE_NAME AS t FROM information_schema.TABLES
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"), 't');
}

/**
 * เขียนไฟล์ SQL ของทั้งฐานข้อมูล (1 บรรทัด = 1 คำสั่ง)
 * @return array ['tables' => n, 'rows' => n]
 */
function backup_dump_sql(string $file): array
{
    $db = db();
    $fh = fopen($file, 'wb');
    $stats = ['tables' => 0, 'rows' => 0];
    fwrite($fh, BACKUP_SQL_MARKER . ' ' . APP_VERSION . ' — ' . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = '+07:00';\n");
    foreach (backup_tables() as $table) {
        $q = '`' . str_replace('`', '``', $table) . '`';
        $create = $db->query("SHOW CREATE TABLE $q")->fetch_row()[1];
        fwrite($fh, "DROP TABLE IF EXISTS $q;\n");
        fwrite($fh, preg_replace('/\s*\R\s*/', ' ', $create) . ";\n");
        $stats['tables']++;

        $res = $db->query("SELECT * FROM $q", MYSQLI_USE_RESULT);
        $batch = [];
        $len = 0;
        $cols = null;
        while ($row = $res->fetch_assoc()) {
            if ($cols === null) {
                $cols = '(' . implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row))) . ')';
            }
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $v === null ? 'NULL' : "'" . $db->real_escape_string((string)$v) . "'";
            }
            $tuple = '(' . implode(',', $vals) . ')';
            $batch[] = $tuple;
            $len += strlen($tuple);
            $stats['rows']++;
            // แบ่งชุด INSERT ไม่เกิน ~400KB ต่อคำสั่ง (รองรับ max_allowed_packet ขั้นต่ำ 1MB)
            if ($len > 400000) {
                fwrite($fh, "INSERT INTO $q $cols VALUES " . implode(',', $batch) . ";\n");
                $batch = [];
                $len = 0;
            }
        }
        $res->free();
        if ($batch) {
            fwrite($fh, "INSERT INTO $q $cols VALUES " . implode(',', $batch) . ";\n");
        }
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fclose($fh);
    return $stats;
}

/** ไฟล์แนบที่สำรอง (ไม่รวมโฟลเดอร์ backups) */
function backup_upload_files(): array
{
    $files = [];
    $base = realpath(UPLOAD_PATH);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        if (strpos($rel, 'backups/') === 0) {
            continue;
        }
        $files[$rel] = $f->getPathname();
    }
    return $files;
}

/**
 * สร้างไฟล์สำรอง
 * @param string $prefix teachhub | auto | prerestore
 * @return array ['ok'=>bool, 'error'=>string, 'name'=>string]
 */
function backup_create(bool $withFiles, string $note = '', string $prefix = 'teachhub'): array
{
    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'เซิร์ฟเวอร์ไม่มีส่วนขยาย PHP zip (ZipArchive)', 'name' => ''];
    }
    @set_time_limit(600);
    $name = backup_new_name($prefix);
    $path = backup_dir() . DIRECTORY_SEPARATOR . $name;
    $sqlFile = tempnam(sys_get_temp_dir(), 'thb');
    try {
        $stats = backup_dump_sql($sqlFile);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('สร้างไฟล์ ZIP ไม่ได้ (ตรวจสิทธิ์เขียนโฟลเดอร์ uploads/backups)');
        }
        $zip->addFile($sqlFile, 'database.sql');
        $fileCount = 0;
        $fileBytes = 0;
        if ($withFiles) {
            foreach (backup_upload_files() as $rel => $full) {
                $zip->addFile($full, 'uploads/' . $rel);
                $fileCount++;
                $fileBytes += (int)filesize($full);
            }
        }
        $zip->addFromString('manifest.json', json_encode([
            'app'         => 'TeachHub PT',
            'version'     => APP_VERSION,
            'created_at'  => date('Y-m-d H:i:s'),
            'created_by'  => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            'note'        => mb_substr($note, 0, 200),
            'tables'      => $stats['tables'],
            'rows'        => $stats['rows'],
            'with_files'  => $withFiles,
            'files'       => $fileCount,
            'files_bytes' => $fileBytes,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();
    } catch (Throwable $ex) {
        @unlink($path);
        error_log('backup_create: ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'สำรองข้อมูลไม่สำเร็จ: ' . $ex->getMessage(), 'name' => ''];
    } finally {
        @unlink($sqlFile);
    }
    return ['ok' => true, 'error' => '', 'name' => $name];
}

/** ตรวจว่าเป็นไฟล์สำรองของระบบที่ใช้กู้คืนได้ */
function backup_validate(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['ok' => false, 'error' => 'ไฟล์ไม่ใช่ ZIP หรือไฟล์เสียหาย'];
    }
    $sqlHead = (string)$zip->getFromName('database.sql', 200);
    $zip->close();
    $m = backup_manifest($path);
    if (!$m || strpos($sqlHead, BACKUP_SQL_MARKER) !== 0) {
        return ['ok' => false, 'error' => 'ไม่ใช่ไฟล์สำรองของระบบ TeachHub PT'];
    }
    return ['ok' => true, 'error' => '', 'manifest' => $m];
}

/** ชื่อไฟล์แนบใน ZIP ปลอดภัยสำหรับแตกไฟล์หรือไม่ (กัน zip slip) */
function backup_safe_entry(string $entry): ?string
{
    if (strpos($entry, 'uploads/') !== 0 || substr($entry, -1) === '/') {
        return null;
    }
    $rel = substr($entry, 8);
    if ($rel === '' || strpos($rel, 'backups/') === 0 || strpos($rel, '..') !== false || strpos($rel, '\\') !== false
        || !preg_match('#^[A-Za-z0-9_./-]+$#', $rel) || preg_match('#(^|/)\.(?!htaccess$)#', $rel)) {
        return null;
    }
    return $rel;
}

/**
 * กู้คืนจากไฟล์สำรอง (สร้างไฟล์สำรอง "ก่อนกู้คืน" อัตโนมัติก่อนเสมอ)
 * @return array ['ok'=>bool, 'error'=>string, 'statements'=>n, 'files'=>n, 'safety'=>ชื่อไฟล์สำรองก่อนกู้คืน]
 */
function backup_restore(string $path, bool $restoreFiles = true): array
{
    @set_time_limit(900);
    $v = backup_validate($path);
    if (!$v['ok']) {
        return $v + ['statements' => 0, 'files' => 0, 'safety' => ''];
    }
    $safety = backup_create(true, 'สำรองอัตโนมัติก่อนกู้คืน', 'prerestore');
    if (!$safety['ok']) {
        return ['ok' => false, 'error' => 'ยกเลิกการกู้คืน เพราะสำรองข้อมูลปัจจุบันก่อนกู้คืนไม่สำเร็จ: ' . $safety['error'], 'statements' => 0, 'files' => 0, 'safety' => ''];
    }

    // 1) ฐานข้อมูล: แตก database.sql ไปไฟล์ชั่วคราว แล้วรันทีละบรรทัด
    $tmp = tempnam(sys_get_temp_dir(), 'thr');
    $zip = new ZipArchive();
    $zip->open($path);
    $in = $zip->getStream('database.sql');
    $out = fopen($tmp, 'wb');
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    $db = db();
    $count = 0;
    $fh = fopen($tmp, 'rb');
    try {
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '' || strpos($line, '--') === 0) {
                continue;
            }
            $db->query(rtrim($line, ';'));
            $count++;
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ex) {
        fclose($fh);
        @unlink($tmp);
        $zip->close();
        error_log('backup_restore SQL: ' . $ex->getMessage());
        return ['ok' => false, 'error' => 'กู้คืนฐานข้อมูลไม่สำเร็จที่คำสั่งที่ ' . ($count + 1) . ': ' . $ex->getMessage()
            . ' — กู้คืนสภาพเดิมได้จากไฟล์ ' . $safety['name'], 'statements' => $count, 'files' => 0, 'safety' => $safety['name']];
    }
    fclose($fh);
    @unlink($tmp);

    // 2) ไฟล์แนบ (ถ้ามีในไฟล์สำรอง): ลบไฟล์งานเดิมแล้วแตกไฟล์จากไฟล์สำรอง
    $files = 0;
    $manifest = $v['manifest'];
    if ($restoreFiles && !empty($manifest['with_files'])) {
        foreach (backup_upload_files() as $rel => $full) {
            if (basename($rel) !== 'index.html' && basename($rel) !== '.htaccess') {
                @unlink($full);
            }
        }
        $base = realpath(UPLOAD_PATH);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            $rel = backup_safe_entry($entry);
            if ($rel === null) {
                continue;
            }
            $dest = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            $src = $zip->getStream($entry);
            $dst = fopen($dest, 'wb');
            if ($src && $dst) {
                stream_copy_to_stream($src, $dst);
                $files++;
            }
            if ($src) fclose($src);
            if ($dst) fclose($dst);
        }
    }
    $zip->close();
    settings_all(true);
    return ['ok' => true, 'error' => '', 'statements' => $count, 'files' => $files, 'safety' => $safety['name']];
}

/** ลบไฟล์สำรองอัตโนมัติที่เก่ากว่าจำนวนที่กำหนด */
function backup_prune_auto(int $keep): void
{
    $auto = array_values(array_filter(backup_list(), fn($b) => strpos($b['name'], 'auto_') === 0));
    foreach (array_slice($auto, max(1, $keep)) as $b) {
        @unlink(backup_dir() . DIRECTORY_SEPARATOR . $b['name']);
        drive_delete('backups', $b['name']); // สำเนาใน Google Drive (ถ้ามี) เก็บจำนวนเท่ากัน
    }
}

/**
 * สำรองอัตโนมัติวันละครั้ง (เรียกเมื่อผู้ดูแลระบบเปิดแดชบอร์ด — ไม่ต้องตั้ง cron)
 * @return string ชื่อไฟล์ที่สร้าง หรือ ''
 */
function backup_auto_maybe(): string
{
    if (setting('backup_auto', '0') !== '1') {
        return '';
    }
    $last = (int)setting('backup_auto_last', '0');
    if (time() - $last < 86400) {
        return '';
    }
    setting_save('backup_auto_last', (string)time()); // กันสร้างซ้อนกัน
    $res = backup_create(setting('backup_auto_files', '0') === '1', 'สำรองอัตโนมัติรายวัน', 'auto');
    if (!$res['ok']) {
        return '';
    }
    drive_backup_copy($res['name']);
    backup_prune_auto((int)setting('backup_keep', '7'));
    log_activity('backup_auto', 'สำรองข้อมูลอัตโนมัติ ' . $res['name']);
    return $res['name'];
}
