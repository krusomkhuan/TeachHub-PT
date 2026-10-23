<?php
/**
 * ไฟล์: admin/drive.php
 * ที่เก็บไฟล์ Google Drive (v1.18) — เชื่อมต่อบัญชี Google ของผู้ดูแล, เปิด/ปิดการเก็บไฟล์ใหม่ใน Drive,
 * ย้ายไฟล์เดิมขึ้น Drive / ดึงไฟล์กลับโฮสต์ทีละชุด (กันหมดเวลา) — รายละเอียดการทำงานดู includes/drive.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$self = 'admin/drive.php';
const DRIVE_BATCH_FILES = 10;
const DRIVE_BATCH_SECONDS = 12; // เผื่อโฮสต์ที่ max_execution_time = 30 และปิด set_time_limit

/**
 * ย้ายไฟล์ 1 ชุด
 * @param string $dir push = โฮสต์ → Drive, pull = Drive → โฮสต์
 * @return array ['done'=>n, 'failed'=>n, 'remaining'=>n, 'error'=>ข้อผิดพลาดล่าสุด]
 */
function drive_batch(string $dir): array
{
    @set_time_limit(DRIVE_BATCH_SECONDS + 300);
    $start = time();
    $done = 0;
    $failed = 0;
    $error = '';
    $items = $dir === 'push'
        ? array_slice(drive_local_files(), 0, DRIVE_BATCH_FILES)
        : db_all("SELECT * FROM drive_files WHERE subdir <> 'backups' ORDER BY id LIMIT " . DRIVE_BATCH_FILES);
    foreach ($items as $it) {
        $res = $dir === 'push' ? drive_push_local($it[0], $it[1]) : drive_pull_local($it);
        if ($res['ok']) {
            $done++;
        } else {
            $failed++;
            $error = ($dir === 'push' ? $it[0] . '/' . $it[1] : $it['subdir'] . '/' . $it['stored_name']) . ': ' . $res['error'];
        }
        if (time() - $start >= DRIVE_BATCH_SECONDS) {
            break;
        }
    }
    $remaining = $dir === 'push' ? count(drive_local_files()) : (int)db_value("SELECT COUNT(*) FROM drive_files WHERE subdir <> 'backups'");
    if ($done > 0) {
        log_activity($dir === 'push' ? 'drive_migrate' : 'drive_pull',
            ($dir === 'push' ? 'ย้ายไฟล์ขึ้น Google Drive ' : 'ดึงไฟล์จาก Google Drive กลับโฮสต์ ') . $done . ' ไฟล์');
    }
    return ['done' => $done, 'failed' => $failed, 'remaining' => $remaining, 'error' => $error];
}

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $ajax = post('ajax') === '1';

    if (!drive_table_ready()) {
        flash('error', 'กรุณานำเข้าไฟล์ update_v1.18.sql ก่อน');
        redirect($self);
    }

    // ---------- เชื่อมต่อ ----------
    if ($action === 'connect') {
        if (!drive_client_ready()) {
            flash('error', 'กรุณาตั้งค่า Google Client ID และ Client Secret ในหน้าตั้งค่าระบบก่อน');
            redirect($self);
        }
        header('Location: ' . drive_connect_url());
        exit;
    }

    // ---------- ยกเลิกการเชื่อมต่อ ----------
    if ($action === 'disconnect') {
        $email = (string)(drive_token()['email'] ?? '');
        drive_disconnect();
        log_activity('drive_disconnect', 'ยกเลิกการเชื่อมต่อ Google Drive ' . $email);
        flash('success', 'ยกเลิกการเชื่อมต่อ Google Drive แล้ว — ไฟล์ใหม่จะเก็บบนโฮสต์');
        redirect($self);
    }

    // ---------- ตั้งค่า ----------
    if ($action === 'settings') {
        $enabled = isset($_POST['drive_enabled']) ? '1' : '0';
        $rootName = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/u', '', post('drive_root_name')), 0, 100));
        if ($rootName === '') {
            $rootName = 'TeachHub PT';
        }
        $parentIn = trim(post('drive_parent'));
        $parent = $parentIn === '' ? '' : drive_parse_folder_id($parentIn);
        if ($parentIn !== '' && $parent === '') {
            flash('error', 'ลิงก์หรือ ID ของโฟลเดอร์ Google Drive ไม่ถูกต้อง (ตัวอย่าง https://drive.google.com/drive/folders/xxxx)');
            redirect($self);
        }
        if ($enabled === '1' && !drive_connected()) {
            flash('error', 'ต้องเชื่อมต่อ Google Drive ก่อนเปิดใช้งาน');
            redirect($self);
        }
        if ($parent !== drive_parent_id()) {
            // เปลี่ยนโฟลเดอร์ปลายทาง → ไฟล์ใหม่ไปโฟลเดอร์ย่อยชุดใหม่ (ไฟล์เดิมใน Drive อยู่ที่เดิมและเปิดได้ตามปกติ)
            setting_save('drive_parent_id', $parent);
            setting_save('drive_folders', '{}');
            log_activity('settings_update', 'เปลี่ยนโฟลเดอร์ปลายทาง Google Drive เป็น ' . ($parent !== '' ? $parent : 'โฟลเดอร์ที่ระบบสร้างเอง'));
        }
        setting_save('drive_backup', isset($_POST['drive_backup']) ? '1' : '0');
        if ($parent !== '' && drive_token() && drive_scope_ok()) {
            $info = drive_folder_info($parent);
            if (!$info['ok']) {
                setting_save('drive_enabled', '0');
                flash('error', 'ใช้โฟลเดอร์ปลายทางไม่ได้: ' . $info['error'] . ' — ปิดการเก็บไฟล์ใหม่ใน Drive ไว้ก่อน');
                redirect($self);
            }
        }
        // เปลี่ยนชื่อโฟลเดอร์หลักใน Drive ด้วย (ถ้าสร้างไว้แล้ว)
        $folders = json_decode((string)setting('drive_folders', '{}'), true) ?: [];
        if ($parent === '' && $rootName !== setting('drive_root_name', 'TeachHub PT') && !empty($folders['_root']) && drive_connected()) {
            $r = drive_api('PATCH', google_endpoint('drive') . '/files/' . rawurlencode($folders['_root']) . '?fields=id', ['json' => ['name' => $rootName], 'timeout' => 20]);
            if ($r['code'] !== 200) {
                flash('warning', 'เปลี่ยนชื่อโฟลเดอร์ใน Google Drive ไม่สำเร็จ: ' . drive_error_text($r));
            }
        }
        setting_save('drive_enabled', $enabled);
        setting_save('drive_root_name', $rootName);
        log_activity('settings_update', 'ตั้งค่าที่เก็บไฟล์ Google Drive (เก็บไฟล์ใหม่ใน Drive: ' . ($enabled === '1' ? 'เปิด' : 'ปิด') . ')');
        if (drive_token() && !drive_scope_ok()) {
            flash('warning', 'บันทึกแล้ว — การใช้โฟลเดอร์ที่กำหนดเองต้องให้สิทธิ์เพิ่ม กรุณากด “เชื่อมต่อใหม่” อีกครั้ง');
        } else {
            flash('success', 'บันทึกการตั้งค่าแล้ว');
        }
        redirect($self);
    }

    // ---------- ย้ายไฟล์ทีละชุด ----------
    if ($action === 'push' || $action === 'pull') {
        if (!drive_connected()) {
            $res = ['done' => 0, 'failed' => 0, 'remaining' => 0, 'error' => 'ยังไม่ได้เชื่อมต่อ Google Drive'];
        } else {
            $res = drive_batch($action);
        }
        if ($ajax) {
            while (ob_get_level()) {
                ob_end_clean(); // ข้อความเตือน PHP ที่ค้างอยู่จะทำให้ JSON เสีย
            }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($res, JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash($res['failed'] || $res['error'] !== '' ? 'warning' : 'success',
            'ดำเนินการแล้ว ' . $res['done'] . ' ไฟล์ · ไม่สำเร็จ ' . $res['failed'] . ' ไฟล์ · คงเหลือ ' . $res['remaining'] . ' ไฟล์'
            . ($res['error'] !== '' ? ' — ' . $res['error'] : ''));
        redirect($self);
    }
    redirect($self);
}

$ready = drive_table_ready();
$token = drive_token();
$about = $ready && $token && drive_client_ready() ? drive_about() : null;
$summary = drive_summary();
$totLocal = array_sum(array_column($summary, 'local'));
$totDrive = array_sum(array_column($summary, 'drive'));
$enabled = setting('drive_enabled', '0') === '1';
$lastError = (string)setting('drive_last_error');
$parentId = drive_parent_id();
$scopeOk = drive_scope_ok();
$parentInfo = $parentId !== '' && $about && $about['ok'] && $scopeOk ? drive_folder_info($parentId) : null;
$rootLabel = $parentId !== '' ? ($parentInfo && $parentInfo['name'] !== '' ? $parentInfo['name'] : 'โฟลเดอร์ปลายทางที่กำหนด') : setting('drive_root_name', 'TeachHub PT');
$rootUrl = 'https://drive.google.com/drive/folders/' . rawurlencode($parentId !== '' ? $parentId : (string)((json_decode((string)setting('drive_folders', '{}'), true) ?: [])['_root'] ?? ''));
$backupCopies = $ready ? (int)db_value("SELECT COUNT(*) FROM drive_files WHERE subdir = 'backups'") : 0;

$pageTitle = 'ที่เก็บไฟล์ Google Drive';
$pageIcon = 'bi-google';
$breadcrumbs = ['ที่เก็บไฟล์ Google Drive' => null];
$pageScripts = <<<'JS'
// ย้ายไฟล์ทีละชุดจนครบ (แต่ละชุดไม่เกิน 10 ไฟล์ / ~12 วินาที กันหมดเวลาบนโฮสติ้ง)
document.querySelectorAll('form[data-batch]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var btn = form.querySelector('button');
        var out = document.getElementById('batchStatus');
        var total = 0;
        btn.disabled = true;
        out.className = 'alert alert-info small';
        out.textContent = 'กำลังดำเนินการ...';
        var step = function () {
            var fd = new FormData(form);
            fd.append('ajax', '1');
            // ใช้ getAttribute: form.action ถูก <input name="action"> บังไว้
            fetch(form.getAttribute('action') || location.href, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}})
                .then(function (r) {
                    return r.text().then(function (t) {
                        try { return JSON.parse(t); } catch (e) {
                            throw new Error('HTTP ' + r.status + (r.status === 200 ? ' (เซิร์ฟเวอร์ตอบกลับไม่ใช่ JSON)' : '')
                                + (r.status >= 500 || r.status === 0 ? ' — อาจหมดเวลาประมวลผลบนโฮสต์ ลองกดอีกครั้ง' : ''));
                        }
                    });
                })
                .then(function (d) {
                    total += d.done;
                    out.textContent = 'ดำเนินการแล้ว ' + total + ' ไฟล์ · คงเหลือ ' + d.remaining + ' ไฟล์' + (d.error ? ' — ล่าสุด: ' + d.error : '');
                    if (d.remaining > 0 && d.done > 0) {
                        step();
                    } else {
                        out.className = 'alert small ' + (d.remaining > 0 ? 'alert-warning' : 'alert-success');
                        out.textContent += d.remaining > 0 ? ' — หยุดเพราะมีไฟล์ที่ทำไม่สำเร็จ' : ' — เสร็จสิ้น';
                        setTimeout(function () { location.reload(); }, 2500);
                    }
                })
                .catch(function (err) {
                    out.className = 'alert alert-danger small';
                    out.textContent = 'เกิดข้อผิดพลาด (' + (err && err.message ? err.message : 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้') + ') ดำเนินการแล้ว '
                        + total + ' ไฟล์ — กดอีกครั้งเพื่อทำต่อ';
                    btn.disabled = false;
                });
        };
        Swal.fire({icon: 'question', text: form.dataset.batch, showCancelButton: true, confirmButtonText: 'ดำเนินการ', cancelButtonText: 'ยกเลิก'})
            .then(function (r) { if (r.isConfirmed) { step(); } else { btn.disabled = false; out.className = 'd-none'; } });
    });
});
JS;
require APP_ROOT . '/includes/header.php';
?>
<?php if (!$ready): ?>
    <div class="alert alert-danger"><i class="bi bi-x-circle me-1"></i> ยังไม่ได้นำเข้าไฟล์ <strong>update_v1.18.sql</strong> — นำเข้าผ่าน phpMyAdmin ก่อนใช้งานหน้านี้</div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-link-45deg me-2"></i>การเชื่อมต่อ</div>
            <div class="card-body">
                <?php if (!$token): ?>
                    <p class="mb-2"><span class="badge bg-secondary">ยังไม่ได้เชื่อมต่อ</span> ไฟล์งานทั้งหมดเก็บบนโฮสต์</p>
                    <?php if ($totDrive > 0): ?>
                        <div class="alert alert-danger small">มีไฟล์เก็บใน Google Drive <?= number_format($totDrive) ?> ไฟล์ ผู้ใช้จะเปิดไฟล์เหล่านี้ไม่ได้จนกว่าจะเชื่อมต่อด้วยบัญชีเดิม</div>
                    <?php endif; ?>
                    <?php if (!drive_client_ready()): ?>
                        <div class="alert alert-warning small mb-0">ต้องตั้งค่า Google Client ID / Client Secret ที่ <a href="<?= e(url('admin/settings.php')) ?>">ตั้งค่าระบบ</a> ก่อน (ใช้ร่วมกับการเข้าสู่ระบบด้วย Google)</div>
                    <?php else: ?>
                        <form method="post">
                            <?= csrf_field() ?><input type="hidden" name="action" value="connect">
                            <button class="btn btn-primary" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-google me-1"></i> เชื่อมต่อ Google Drive</button>
                        </form>
                        <div class="form-text">เลือกบัญชี Google ที่จะใช้เก็บไฟล์ (แนะนำบัญชีของหน่วยงาน ไม่ใช่บัญชีส่วนตัว) แล้วอนุญาตสิทธิ์ Google Drive
                            · <?= $parentId !== '' ? 'บัญชีนี้ต้องเป็นเจ้าของหรือผู้แก้ไขของโฟลเดอร์ปลายทาง' : 'ระบบเข้าถึงได้เฉพาะไฟล์ที่ระบบสร้างเอง ไม่เห็นไฟล์อื่นใน Drive' ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($about && $about['ok']): ?>
                        <p class="mb-2"><span class="badge bg-success">เชื่อมต่อแล้ว</span>
                            <strong><?= e($about['user']['emailAddress'] ?? $token['email']) ?></strong>
                            <span class="text-muted small"><?= e($about['user']['displayName'] ?? '') ?></span></p>
                        <?php $q = $about['quota']; ?>
                        <p class="small text-muted mb-2">
                            พื้นที่ Drive: ใช้ไป <?= e(format_bytes((int)($q['usage'] ?? 0))) ?>
                            <?= isset($q['limit']) ? 'จาก ' . e(format_bytes((int)$q['limit'])) : '(ไม่จำกัด)' ?>
                            · เก็บที่โฟลเดอร์ <a href="<?= e($rootUrl) ?>" target="_blank" rel="noopener">“<?= e($rootLabel) ?>”</a> แยกโฟลเดอร์ย่อยตามงาน
                            · เชื่อมต่อเมื่อ <?= e(thai_datetime((string)$token['connected_at'])) ?>
                        </p>
                    <?php else: ?>
                        <div class="alert alert-danger small">
                            <i class="bi bi-x-circle me-1"></i> เชื่อมต่อบัญชี <?= e($token['email'] ?? '') ?> ไม่ได้:
                            <?= e((string)($token['last_error'] ?? ($about['error'] ?? 'ไม่ทราบสาเหตุ'))) ?>
                        </div>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="connect">
                            <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> เชื่อมต่อใหม่ (บัญชีเดิม)</button>
                        </form>
                    <?php endif; ?>
                    <?php if (!$scopeOk): ?>
                        <div class="alert alert-warning small">
                            <i class="bi bi-key me-1"></i> การใช้โฟลเดอร์ปลายทางที่กำหนดเองต้องให้สิทธิ์ Google Drive เพิ่ม — กดเชื่อมต่อใหม่ด้วยบัญชีเดิม
                            (ระหว่างนี้ไฟล์ใหม่จะเก็บบนโฮสต์)
                        </div>
                        <form method="post" class="d-inline">
                            <?= csrf_field() ?><input type="hidden" name="action" value="connect">
                            <button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i> เชื่อมต่อใหม่</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($parentInfo && !$parentInfo['ok']): ?>
                        <div class="alert alert-danger small"><i class="bi bi-folder-x me-1"></i> ใช้โฟลเดอร์ปลายทางไม่ได้: <?= e($parentInfo['error']) ?></div>
                    <?php endif; ?>
                    <form method="post" class="d-inline" data-confirm="ยกเลิกการเชื่อมต่อ Google Drive?<?= $totDrive > 0 ? ' มีไฟล์ใน Drive ' . $totDrive . ' ไฟล์ ซึ่งผู้ใช้จะเปิดไม่ได้จนกว่าจะเชื่อมต่อใหม่ด้วยบัญชีเดิม (แนะนำดึงไฟล์กลับโฮสต์ก่อน)' : '' ?>" data-confirm-icon="warning">
                        <?= csrf_field() ?><input type="hidden" name="action" value="disconnect">
                        <button class="btn btn-outline-danger btn-sm"><i class="bi bi-x-lg me-1"></i> ยกเลิกการเชื่อมต่อ</button>
                    </form>
                <?php endif; ?>
                <?php if ($about && $about['ok'] && drive_root_trashed()): ?>
                    <div class="alert alert-danger small mt-3 mb-0"><i class="bi bi-trash me-1"></i>โฟลเดอร์ “<?= e($rootLabel) ?>” อยู่ในถังขยะของ Google Drive
                        — กรุณากู้คืน (Restore) จากถังขยะใน Drive ไม่เช่นนั้นไฟล์งานทั้งหมดจะถูกลบถาวรภายใน 30 วัน</div>
                <?php endif; ?>
                <?php if ($lastError !== '' && $token): ?>
                    <div class="small text-danger mt-3"><i class="bi bi-exclamation-triangle me-1"></i>ส่งไฟล์ขึ้น Drive ไม่สำเร็จครั้งล่าสุด: <?= e($lastError) ?> (ไฟล์นั้นยังเก็บบนโฮสต์ ย้ายภายหลังได้)</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-sliders me-2"></i>ตั้งค่า</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?><input type="hidden" name="action" value="settings">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="drive_enabled" id="drive_enabled" value="1"
                            <?= $enabled ? 'checked' : '' ?> <?= $token ? '' : 'disabled' ?>>
                        <label class="form-check-label" for="drive_enabled">เก็บไฟล์งานที่อัปโหลดใหม่ใน Google Drive</label>
                    </div>
                    <label class="form-label small" for="drive_parent">โฟลเดอร์ปลายทางใน Google Drive (ลิงก์หรือ ID)</label>
                    <input class="form-control" name="drive_parent" id="drive_parent" maxlength="300" autocomplete="off"
                           value="<?= e($parentId !== '' ? 'https://drive.google.com/drive/folders/' . $parentId : '') ?>" placeholder="https://drive.google.com/drive/folders/...">
                    <div class="form-text mb-3">ว่าง = ระบบสร้างโฟลเดอร์ชื่อด้านล่างใน My Drive เอง · ใส่ลิงก์โฟลเดอร์ = ระบบสร้างโฟลเดอร์ย่อยแยกตามงานไว้ในโฟลเดอร์นั้น
                        (ต้องกดเชื่อมต่อใหม่หลังตั้งครั้งแรก)</div>
                    <?php if ($parentId === ''): ?>
                        <label class="form-label small" for="drive_root_name">ชื่อโฟลเดอร์หลักใน My Drive</label>
                        <input class="form-control mb-3" name="drive_root_name" id="drive_root_name" maxlength="100" value="<?= e(setting('drive_root_name', 'TeachHub PT')) ?>">
                    <?php else: ?>
                        <input type="hidden" name="drive_root_name" value="<?= e(setting('drive_root_name', 'TeachHub PT')) ?>">
                    <?php endif; ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" name="drive_backup" id="drive_backup" value="1"
                            <?= setting('drive_backup', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="drive_backup">ส่งสำเนาไฟล์สำรองข้อมูลไปโฟลเดอร์ “สำรองข้อมูล” ด้วย</label>
                    </div>
                    <button class="btn btn-primary" <?= $ready ? '' : 'disabled' ?>><i class="bi bi-save me-1"></i> บันทึก</button>
                </form>
                <div class="form-text mt-2">ปิดแล้วไฟล์ใหม่จะเก็บบนโฮสต์ ไฟล์ที่อยู่ใน Drive แล้วยังเปิดได้ตามปกติ · รูปโปรไฟล์และโลโก้เก็บบนโฮสต์เสมอ</div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-folder2-open me-2"></i>ไฟล์งานแยกตามประเภท</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>ประเภท</th><th>โฟลเดอร์ใน Drive</th><th class="text-end">บนโฮสต์</th><th class="text-end">ใน Google Drive</th></tr></thead>
                <tbody>
                <?php foreach ($summary as $subdir => $s): ?>
                    <tr>
                        <td><?= e($s['label']) ?></td>
                        <td class="small text-muted"><?= e(drive_folder_names()[$subdir]) ?></td>
                        <td class="text-end"><?= number_format($s['local']) ?> ไฟล์ <span class="small text-muted">(<?= e(format_bytes($s['local_bytes'])) ?>)</span></td>
                        <td class="text-end"><?= number_format($s['drive']) ?> ไฟล์ <span class="small text-muted">(<?= e(format_bytes($s['drive_bytes'])) ?>)</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr class="fw-semibold"><td colspan="2">รวม</td>
                    <td class="text-end"><?= number_format($totLocal) ?> ไฟล์</td><td class="text-end"><?= number_format($totDrive) ?> ไฟล์</td></tr></tfoot>
            </table>
        </div>
        <div id="batchStatus" class="d-none"></div>
        <div class="d-flex flex-wrap gap-2">
            <form method="post" data-batch="ย้ายไฟล์บนโฮสต์ <?= $totLocal ?> ไฟล์ขึ้น Google Drive? (ย้ายสำเร็จแล้วจะลบไฟล์บนโฮสต์)">
                <?= csrf_field() ?><input type="hidden" name="action" value="push">
                <button class="btn btn-primary" <?= $about && $about['ok'] && $totLocal > 0 ? '' : 'disabled' ?>><i class="bi bi-cloud-upload me-1"></i> ย้ายไฟล์บนโฮสต์ขึ้น Drive</button>
            </form>
            <form method="post" data-batch="ดึงไฟล์ใน Google Drive <?= $totDrive ?> ไฟล์กลับมาเก็บบนโฮสต์? (ไฟล์ใน Drive จะถูกย้ายไปถังขยะ)">
                <?= csrf_field() ?><input type="hidden" name="action" value="pull">
                <button class="btn btn-outline-secondary" <?= $about && $about['ok'] && $totDrive > 0 ? '' : 'disabled' ?>><i class="bi bi-cloud-download me-1"></i> ดึงไฟล์กลับโฮสต์</button>
            </form>
        </div>
        <div class="form-text mt-2">ทำทีละชุด (ไม่เกิน <?= DRIVE_BATCH_FILES ?> ไฟล์ต่อครั้ง) จนครบอัตโนมัติ · ผู้ใช้เปิดไฟล์ผ่านระบบได้ตามปกติทั้งระหว่างและหลังย้าย
            · ไฟล์ใน Drive ไม่รวมในไฟล์สำรองข้อมูล (หน้า สำรอง / กู้คืนข้อมูล)
            <?php if ($backupCopies > 0): ?>· สำเนาไฟล์สำรองในโฟลเดอร์ “สำรองข้อมูล” <?= number_format($backupCopies) ?> ไฟล์<?php endif; ?></div>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-info-circle me-2"></i>การตั้งค่าใน Google Cloud Console (ครั้งแรก)</div>
    <div class="card-body small">
        <ol class="mb-0">
            <li>เปิดโปรเจกต์เดียวกับ OAuth Client ที่ใช้เข้าสู่ระบบด้วย Google → <strong>APIs &amp; Services → Library</strong> → ค้นหา <strong>Google Drive API</strong> → Enable</li>
            <li><strong>Google Auth Platform → Data access</strong> → Add scope <code>.../auth/drive.file</code>
                และถ้าใช้ “โฟลเดอร์ปลายทาง” ที่สร้างเอง ให้เพิ่ม <code>.../auth/drive</code> ด้วย
                (สิทธิ์นี้ Google จะขึ้นหน้า “Google hasn’t verified this app” ตอนเชื่อมต่อ ให้กด Advanced → Go to … เพราะผู้ดูแลเชื่อมต่อเพียงบัญชีเดียว)</li>
            <li>ที่ Audience ตั้ง Publishing status เป็น <strong>In production</strong> (หรือ User type = Internal ถ้าเป็นบัญชี Google Workspace ของวิทยาลัย)
                — ถ้ายังเป็น Testing สิทธิ์จะหมดอายุทุก 7 วัน ต้องกดเชื่อมต่อใหม่</li>
            <li>Redirect URI ใช้ของเดิม: <code><?= e(google_redirect_uri()) ?></code></li>
            <li>กลับมาหน้านี้ กด <strong>เชื่อมต่อ Google Drive</strong> → เปิด “เก็บไฟล์งานที่อัปโหลดใหม่ใน Google Drive” → กดย้ายไฟล์เดิมขึ้น Drive</li>
        </ol>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
