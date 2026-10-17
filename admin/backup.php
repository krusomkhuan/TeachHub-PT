<?php
/**
 * ไฟล์: admin/backup.php
 * สำรองข้อมูล / กู้คืนข้อมูล (เฉพาะผู้ดูแลระบบ)
 *   - สร้างไฟล์สำรอง (ฐานข้อมูล + เลือกรวมไฟล์แนบได้) เก็บบนเซิร์ฟเวอร์ ดาวน์โหลดเก็บไว้นอกเครื่องได้
 *   - กู้คืนจากไฟล์บนเซิร์ฟเวอร์ หรืออัปโหลดไฟล์สำรอง (.zip) — ต้องยืนยันรหัสผ่าน และระบบสำรองข้อมูลปัจจุบันให้ก่อนเสมอ
 *   - สำรองอัตโนมัติวันละครั้ง (ทำงานเมื่อผู้ดูแลระบบเปิดแดชบอร์ด) เก็บไว้ตามจำนวนที่กำหนด
 *   ?download=ชื่อไฟล์  ดาวน์โหลดไฟล์สำรอง
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$self = 'admin/backup.php';
$errors = [];

// ---------- ดาวน์โหลด ----------
if (get('download') !== '') {
    $path = backup_path(get('download'));
    if (!$path) {
        abort_page(404, 'ไม่พบไฟล์สำรอง');
    }
    log_activity('backup_download', 'ดาวน์โหลดไฟล์สำรอง ' . basename($path));
    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

/** ยืนยันตัวตนก่อนทำรายการสำคัญ: พิมพ์คำยืนยัน + รหัสผ่าน (ถ้าบัญชีมีรหัสผ่าน) */
function backup_confirm_ok(array $user, string $word, array &$errors): bool
{
    if (post('confirm_text') !== $word) {
        $errors['confirm'] = 'กรุณาพิมพ์คำว่า “' . $word . '” เพื่อยืนยัน';
        return false;
    }
    if ((int)$user['password_login'] === 1) {
        $hash = (string)db_value('SELECT password_hash FROM users WHERE id = ?', 'i', [(int)$user['id']]);
        if (!password_verify((string)($_POST['password'] ?? ''), $hash)) {
            $errors['confirm'] = 'รหัสผ่านไม่ถูกต้อง';
            return false;
        }
    }
    return true;
}

if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- สร้างไฟล์สำรอง ----------
    if ($action === 'create') {
        $res = backup_create(isset($_POST['with_files']), post('note'));
        if ($res['ok']) {
            log_activity('backup_create', 'สร้างไฟล์สำรอง ' . $res['name']);
            flash('success', 'สร้างไฟล์สำรอง ' . $res['name'] . ' เรียบร้อยแล้ว — แนะนำให้ดาวน์โหลดเก็บไว้นอกเซิร์ฟเวอร์ด้วย');
        } else {
            flash('error', $res['error']);
        }
        redirect($self);
    }

    // ---------- ตั้งค่าสำรองอัตโนมัติ ----------
    if ($action === 'settings') {
        $keep = max(1, min(60, (int)post('backup_keep')));
        setting_save('backup_auto', isset($_POST['backup_auto']) ? '1' : '0');
        setting_save('backup_auto_files', isset($_POST['backup_auto_files']) ? '1' : '0');
        setting_save('backup_keep', (string)$keep);
        log_activity('settings_update', 'ตั้งค่าสำรองข้อมูลอัตโนมัติ');
        flash('success', 'บันทึกการตั้งค่าสำรองอัตโนมัติแล้ว');
        redirect($self);
    }

    // ---------- ลบไฟล์สำรอง ----------
    if ($action === 'delete') {
        $path = backup_path(post('name'));
        if ($path) {
            @unlink($path);
            log_activity('backup_delete', 'ลบไฟล์สำรอง ' . basename($path));
            flash('success', 'ลบไฟล์สำรอง ' . basename($path) . ' แล้ว');
        }
        redirect($self);
    }

    // ---------- กู้คืน ----------
    if ($action === 'restore') {
        $source = post('source');
        $path = null;
        if ($source === 'upload') {
            $f = $_FILES['backup_file'] ?? null;
            if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
                $errors['restore'] = 'กรุณาเลือกไฟล์สำรอง (.zip)';
            } elseif ($f['error'] !== UPLOAD_ERR_OK) {
                $errors['restore'] = in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (' . ini_get('upload_max_filesize') . ') — ใช้ไฟล์สำรองแบบไม่รวมไฟล์แนบ หรือคัดลอกไฟล์ไปไว้ที่ uploads/backups'
                    : 'อัปโหลดไฟล์ไม่สำเร็จ';
            } elseif (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'zip'
                || (string)file_get_contents($f['tmp_name'], false, null, 0, 4) !== "PK\x03\x04") {
                $errors['restore'] = 'ไฟล์ต้องเป็นไฟล์สำรอง .zip ของระบบ';
            } else {
                $v = backup_validate($f['tmp_name']);
                if (!$v['ok']) {
                    $errors['restore'] = $v['error'];
                } elseif (backup_confirm_ok($user, 'กู้คืน', $errors)) {
                    // เก็บไฟล์ที่อัปโหลดไว้ในรายการไฟล์สำรองด้วย
                    $path = backup_dir() . DIRECTORY_SEPARATOR . backup_new_name('upload');
                    if (!move_uploaded_file($f['tmp_name'], $path)) {
                        $errors['restore'] = 'บันทึกไฟล์ที่อัปโหลดไม่สำเร็จ';
                        $path = null;
                    }
                }
            }
        } else {
            $path = backup_path(post('name'));
            if (!$path) {
                $errors['restore'] = 'กรุณาเลือกไฟล์สำรองที่จะกู้คืน';
            } elseif (!backup_confirm_ok($user, 'กู้คืน', $errors)) {
                $path = null;
            }
        }

        if ($path && !$errors) {
            $name = basename($path);
            $res = backup_restore($path, isset($_POST['restore_files']));
            if ($res['ok']) {
                log_activity('backup_restore', 'กู้คืนข้อมูลจาก ' . $name . ' (' . $res['statements'] . ' คำสั่ง, ' . $res['files']
                    . ' ไฟล์) สำรองก่อนกู้คืน: ' . $res['safety'], null);
                // ข้อมูลผู้ใช้อาจเปลี่ยน — ให้เข้าสู่ระบบใหม่
                logout_user();
                flash('success', 'กู้คืนข้อมูลจาก ' . $name . ' เรียบร้อยแล้ว (ไฟล์แนบ ' . $res['files'] . ' ไฟล์) กรุณาเข้าสู่ระบบใหม่'
                    . ' · ข้อมูลก่อนกู้คืนถูกสำรองไว้ที่ ' . $res['safety']);
                redirect('auth/login.php');
            }
            $errors['restore'] = $res['error'];
        }
    }
}

$backups = backup_list();
$lastAuto = (int)setting('backup_auto_last', '0');
$uploadMax = ini_get('upload_max_filesize');
$dbSize = (int)db_value('SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
$filesSize = array_sum(array_map('filesize', backup_upload_files()));
$err = fn(string $k) => isset($errors[$k]) ? '<div class="alert alert-danger small mt-2 mb-0">' . e($errors[$k]) . '</div>' : '';
$selName = post('name') ?: get('restore');

$pageTitle = 'สำรอง / กู้คืนข้อมูล';
$pageIcon = 'bi-database-gear';
$breadcrumbs = ['สำรอง / กู้คืนข้อมูล' => null];
$activeMenu = $self;
require APP_ROOT . '/includes/header.php';
?>
<?php if (!class_exists('ZipArchive')): ?>
    <div class="alert alert-danger">เซิร์ฟเวอร์ไม่มีส่วนขยาย PHP <strong>zip</strong> — เปิดใช้ extension=zip ใน php.ini ก่อนใช้งานหน้านี้</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- สร้างไฟล์สำรอง -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-cloud-arrow-down me-2"></i>สร้างไฟล์สำรองตอนนี้</div>
            <div class="card-body">
                <p class="small text-muted mb-3">ขนาดปัจจุบัน: ฐานข้อมูลประมาณ <?= e(format_bytes($dbSize)) ?> · ไฟล์แนบ <?= e(format_bytes((int)$filesSize)) ?></p>
                <form method="post" data-confirm="สร้างไฟล์สำรองข้อมูลตอนนี้?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="with_files" id="with_files" value="1" checked>
                        <label class="form-check-label" for="with_files">รวมไฟล์แนบ (แผน/PA/วิจัย/รูปนิเทศ/รูปโปรไฟล์/เอกสารดาวน์โหลด)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small" for="note">บันทึกช่วยจำ (ไม่บังคับ)</label>
                        <input class="form-control" name="note" id="note" maxlength="200" placeholder="เช่น ก่อนเปิดภาคเรียน 1/2570">
                    </div>
                    <button class="btn btn-accent" data-loading-text="กำลังสำรองข้อมูล..."><i class="bi bi-cloud-arrow-down me-1"></i> สำรองข้อมูล</button>
                </form>
            </div>
        </div>
    </div>

    <!-- สำรองอัตโนมัติ -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-clock-history me-2"></i>สำรองอัตโนมัติ</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="settings">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="backup_auto" id="backup_auto" value="1" <?= setting('backup_auto', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="backup_auto">สำรองฐานข้อมูลวันละครั้งโดยอัตโนมัติ</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="backup_auto_files" id="backup_auto_files" value="1" <?= setting('backup_auto_files', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="backup_auto_files">รวมไฟล์แนบด้วย (ใช้พื้นที่มากขึ้น)</label>
                    </div>
                    <div class="input-group mb-2" style="max-width:320px">
                        <span class="input-group-text">เก็บไว้ล่าสุด</span>
                        <input type="number" class="form-control" name="backup_keep" min="1" max="60" value="<?= e((string)setting('backup_keep', '7')) ?>">
                        <span class="input-group-text">ไฟล์</span>
                    </div>
                    <div class="form-text mb-3">ทำงานเมื่อผู้ดูแลระบบเปิดหน้าแดชบอร์ด (ไม่ต้องตั้ง cron) · ครั้งล่าสุด:
                        <?= $lastAuto ? e(thai_datetime(date('Y-m-d H:i:s', $lastAuto))) : 'ยังไม่เคย' ?></div>
                    <button class="btn btn-primary"><i class="bi bi-save me-1"></i> บันทึก</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- กู้คืน -->
<div class="card mb-4 border-top border-4 border-danger" id="restore">
    <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2"></i>กู้คืนข้อมูล</div>
    <div class="card-body">
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            การกู้คืนจะ <strong>แทนที่ข้อมูลทั้งหมดในระบบ</strong> ด้วยข้อมูลในไฟล์สำรอง (ผู้ใช้ งานที่ส่ง ผลนิเทศ การตั้งค่า ฯลฯ)
            · ระบบจะสำรองข้อมูลปัจจุบันเป็นไฟล์ “ก่อนกู้คืน” ให้อัตโนมัติก่อนเสมอ · หลังกู้คืนทุกคนต้องเข้าสู่ระบบใหม่
        </div>
        <form method="post" enctype="multipart/form-data" novalidate data-confirm="ยืนยันกู้คืนข้อมูล? ข้อมูลปัจจุบันจะถูกแทนที่ทั้งหมด" data-confirm-icon="warning">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restore">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="source" id="srcServer" value="server" <?= post('source') !== 'upload' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="srcServer">จากไฟล์สำรองบนเซิร์ฟเวอร์</label>
                    </div>
                    <select class="form-select mt-2" name="name" id="restoreName">
                        <option value="">-- เลือกไฟล์สำรอง --</option>
                        <?php foreach ($backups as $b): ?>
                            <option value="<?= e($b['name']) ?>" <?= $selName === $b['name'] ? 'selected' : '' ?>>
                                <?= e(thai_datetime(date('Y-m-d H:i:s', $b['mtime']))) ?> · <?= e($b['kind']) ?> · <?= e(format_bytes($b['size'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="source" id="srcUpload" value="upload" <?= post('source') === 'upload' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="srcUpload">อัปโหลดไฟล์สำรอง (.zip) จากเครื่อง</label>
                    </div>
                    <input type="file" class="form-control mt-2" name="backup_file" accept=".zip,application/zip">
                    <div class="form-text">ไม่เกิน <?= e($uploadMax) ?> (ไฟล์ใหญ่กว่านี้ให้คัดลอกไปไว้ที่โฟลเดอร์ uploads/backups แล้วเลือกจากรายการ)</div>
                </div>
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="restore_files" id="restore_files" value="1" checked>
                        <label class="form-check-label" for="restore_files">กู้คืนไฟล์แนบด้วย (ถ้าไฟล์สำรองมีไฟล์แนบ)</label>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small" for="confirm_text">พิมพ์คำว่า <strong>กู้คืน</strong> เพื่อยืนยัน</label>
                    <input class="form-control" name="confirm_text" id="confirm_text" autocomplete="off">
                </div>
                <?php if ((int)$user['password_login'] === 1): ?>
                    <div class="col-md-4">
                        <label class="form-label small" for="password">รหัสผ่านของท่าน</label>
                        <input type="password" class="form-control" name="password" id="password" autocomplete="current-password">
                    </div>
                <?php endif; ?>
                <div class="col-md-4 d-flex align-items-end">
                    <button class="btn btn-danger w-100" data-loading-text="กำลังกู้คืน..."><i class="bi bi-arrow-counterclockwise me-1"></i> กู้คืนข้อมูล</button>
                </div>
            </div>
            <?= $err('restore') ?><?= $err('confirm') ?>
        </form>
    </div>
</div>

<!-- รายการไฟล์สำรอง -->
<div class="card">
    <div class="card-header"><i class="bi bi-archive me-2"></i>ไฟล์สำรองบนเซิร์ฟเวอร์ (<?= count($backups) ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable" data-order="[]">
                <thead><tr><th>วันเวลา</th><th>ชนิด</th><th>เวอร์ชัน</th><th>ข้อมูล</th><th>ไฟล์แนบ</th><th>ขนาด</th><th>บันทึก</th><th class="no-sort text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($backups as $b): $m = $b['manifest']; ?>
                    <tr>
                        <td class="small" data-order="<?= $b['mtime'] ?>"><?= e(thai_datetime(date('Y-m-d H:i:s', $b['mtime']))) ?><br><span class="text-muted font-monospace" style="font-size:.75rem"><?= e($b['name']) ?></span></td>
                        <td class="small"><?= e($b['kind']) ?></td>
                        <td class="small"><?= $m ? e($m['version']) . ($m['version'] !== APP_VERSION ? ' <span class="badge bg-warning text-dark">ต่างจากปัจจุบัน</span>' : '') : '<span class="text-danger">ไฟล์ไม่สมบูรณ์</span>' ?></td>
                        <td class="small"><?= $m ? (int)$m['tables'] . ' ตาราง · ' . number_format((int)$m['rows']) . ' แถว' : '-' ?></td>
                        <td class="small"><?= $m && !empty($m['with_files']) ? number_format((int)$m['files']) . ' ไฟล์' : '<span class="text-muted">ไม่รวม</span>' ?></td>
                        <td class="small" data-order="<?= $b['size'] ?>"><?= e(format_bytes($b['size'])) ?></td>
                        <td class="small"><?= e($m['note'] ?? '') ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url($self . '?download=' . rawurlencode($b['name']))) ?>" class="btn btn-sm btn-outline-primary" title="ดาวน์โหลด"><i class="bi bi-download"></i></a>
                            <?php if ($m): ?>
                                <a href="<?= e(url($self . '?restore=' . rawurlencode($b['name']) . '#restore')) ?>" class="btn btn-sm btn-outline-danger" title="กู้คืนจากไฟล์นี้"><i class="bi bi-arrow-counterclockwise"></i></a>
                            <?php endif; ?>
                            <form method="post" class="d-inline" data-confirm="ลบไฟล์สำรอง <?= e($b['name']) ?>? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>">
                                <button class="btn btn-sm btn-outline-secondary" title="ลบ"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="form-text">ไฟล์สำรองเก็บที่ uploads/backups (เข้าถึงผ่านเว็บโดยตรงไม่ได้) · ควรดาวน์โหลดเก็บไว้นอกเซิร์ฟเวอร์อย่างสม่ำเสมอ เช่น Google Drive ของงาน</div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
