<?php
/**
 * ไฟล์: admin/settings.php
 * ตั้งค่าระบบ: ชื่อวิทยาลัย/ระบบ, โลโก้, ขนาดไฟล์สูงสุด, นามสกุลไฟล์ที่อนุญาต, การสมัครสมาชิก, หมดเวลา session, สีธีม
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];

/** แปลงค่า ini เช่น 40M เป็น byte */
function ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n;
}
// ขนาดอัปโหลดสูงสุดที่ PHP บนเซิร์ฟเวอร์ยอมรับ
$serverMaxMb = (int)floor(min(ini_bytes((string)ini_get('upload_max_filesize')), ini_bytes((string)ini_get('post_max_size'))) / 1048576);
$docExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];

if (is_post()) {
    csrf_verify();
    $action = post('action');

    if ($action === 'remove_logo') {
        delete_upload('system', setting('logo'));
        setting_save('logo', '');
        log_activity('settings_update', 'ลบโลโก้ (ใช้โลโก้เริ่มต้น)');
        flash('success', 'ลบโลโก้แล้ว ระบบกลับไปใช้โลโก้เริ่มต้น');
        redirect('admin/settings.php');
    }

    $d = [
        'college_name'        => mb_substr(post('college_name'), 0, 150),
        'system_name'         => mb_substr(post('system_name'), 0, 60),
        'system_fullname'     => mb_substr(post('system_fullname'), 0, 150),
        'slogan'              => mb_substr(post('slogan'), 0, 150),
        'department_name'     => mb_substr(post('department_name'), 0, 150),
        'max_upload_mb'       => (int)post('max_upload_mb'),
        'session_timeout_min' => (int)post('session_timeout_min'),
        'color_primary'       => post('color_primary'),
        'color_accent'        => post('color_accent'),
        'allow_register'      => isset($_POST['allow_register']) ? '1' : '0',
        'site_url'            => rtrim(mb_substr(post('site_url'), 0, 200), '/'),
        // เข้าสู่ระบบด้วย Google (Client Secret บันทึกแยก — เว้นว่าง = ใช้ค่าเดิม)
        'google_enabled'        => isset($_POST['google_enabled']) ? '1' : '0',
        'google_client_id'      => mb_substr(post('google_client_id'), 0, 255),
        'google_allowed_domain' => strtolower(trim(mb_substr(post('google_allowed_domain'), 0, 100), " @")),
    ];
    $googleSecret = mb_substr(post('google_client_secret'), 0, 255);
    if ($d['google_enabled'] === '1' && ($d['google_client_id'] === '' || ($googleSecret === '' && trim((string)setting('google_client_secret')) === ''))) {
        $errors['google'] = 'เปิดใช้ Google ต้องกรอก Client ID และ Client Secret';
    }
    if ($d['google_allowed_domain'] !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d['google_allowed_domain'])) {
        $errors['google'] = 'โดเมนไม่ถูกต้อง เช่น pticec.ac.th';
    }
    $exts = array_values(array_intersect($docExtensions, (array)($_POST['allowed_extensions'] ?? [])));

    foreach (['college_name' => 'ชื่อวิทยาลัย', 'system_name' => 'ชื่อระบบ'] as $k => $label) {
        if ($d[$k] === '') {
            $errors[$k] = 'กรุณากรอก' . $label;
        }
    }
    if ($d['max_upload_mb'] < 1 || $d['max_upload_mb'] > 200) {
        $errors['max_upload_mb'] = 'กำหนดได้ 1-200 MB';
    } elseif ($serverMaxMb > 0 && $d['max_upload_mb'] > $serverMaxMb) {
        $errors['max_upload_mb'] = 'เกินค่าที่ PHP บนเซิร์ฟเวอร์รับได้ (' . $serverMaxMb . ' MB) กรุณาปรับ upload_max_filesize / post_max_size ใน php.ini ก่อน';
    }
    if ($d['session_timeout_min'] < 5 || $d['session_timeout_min'] > 480) {
        $errors['session_timeout_min'] = 'กำหนดได้ 5-480 นาที';
    }
    foreach (['color_primary', 'color_accent'] as $k) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $d[$k])) {
            $errors[$k] = 'รูปแบบสีไม่ถูกต้อง';
        }
    }
    if (!$exts) {
        $errors['allowed_extensions'] = 'ต้องเลือกอย่างน้อย 1 นามสกุล';
    }
    // ผู้พิจารณารายงานการนิเทศ 3 ขั้น (ต้องเป็น admin หรือผู้บริหารที่ใช้งานอยู่)
    $approverIds = array_map('intval', array_column(
        db_all("SELECT id FROM users WHERE status = 'active' AND role IN ('admin','executive')"),
        'id'
    ));
    for ($i = 1; $i <= 3; $i++) {
        $aid = (int)post('approver_' . $i . '_user');
        $d['approver_' . $i . '_user'] = $aid && in_array($aid, $approverIds, true) ? (string)$aid : '';
        $d['approver_' . $i . '_title'] = mb_substr(post('approver_' . $i . '_title'), 0, 150);
        if ($d['approver_' . $i . '_title'] === '') {
            $errors['approvers'] = 'กรุณาระบุตำแหน่งของผู้พิจารณาให้ครบ 3 ขั้น';
        }
    }
    if ($d['site_url'] !== '' && !preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?(/[A-Za-z0-9._~\-/]*)?$#', $d['site_url'])) {
        $errors['site_url'] = 'รูปแบบ URL ไม่ถูกต้อง เช่น https://teachhub.ptc.ac.th หรือ http://192.168.1.10/teachhub_pt';
    }

    // อัปโหลดโลโก้ใหม่
    $newLogo = null;
    if (!$errors && !empty($_FILES['logo']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $up = handle_upload($_FILES['logo'], 'system', ['png', 'jpg', 'jpeg', 'webp'], 2 * 1024 * 1024);
        if (!$up['ok']) {
            $errors['logo'] = $up['error'];
        } else {
            $newLogo = $up['stored_name'];
        }
    }

    if (!$errors) {
        foreach ($d as $k => $v) {
            setting_save($k, (string)$v);
        }
        setting_save('allowed_extensions', implode(',', $exts));
        if ($googleSecret !== '') {
            setting_save('google_client_secret', $googleSecret);
        }
        if ($newLogo) {
            delete_upload('system', setting('logo'));
            setting_save('logo', $newLogo);
        }
        log_activity('settings_update', 'แก้ไขตั้งค่าระบบ' . ($newLogo ? ' (เปลี่ยนโลโก้)' : ''));
        flash('success', 'บันทึกการตั้งค่าเรียบร้อยแล้ว');
        redirect('admin/settings.php');
    }
}

$v = fn(string $k, string $default = '') => is_post() ? post($k) : (string)setting($k, $default);
$currentExts = is_post() ? (array)($_POST['allowed_extensions'] ?? []) : allowed_upload_extensions();
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$logoUrl = logo_url();

$pageTitle = 'ตั้งค่าระบบ';
$pageIcon = 'bi-gear';
$breadcrumbs = ['ตั้งค่าระบบ' => null];
require APP_ROOT . '/includes/header.php';
?>
<form method="post" enctype="multipart/form-data" novalidate data-confirm="บันทึกการตั้งค่าระบบ?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-building me-2"></i>ข้อมูลหน่วยงานและระบบ</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="college_name">ชื่อวิทยาลัย *</label>
                        <input class="form-control<?= $invalid('college_name') ?>" name="college_name" id="college_name" maxlength="150" value="<?= e($v('college_name')) ?>"><?= $err('college_name') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="department_name">หน่วยงานที่ดูแลระบบ</label>
                        <input class="form-control" name="department_name" id="department_name" maxlength="150" value="<?= e($v('department_name')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="system_name">ชื่อระบบ (ย่อ) *</label>
                        <input class="form-control<?= $invalid('system_name') ?>" name="system_name" id="system_name" maxlength="60" value="<?= e($v('system_name')) ?>"><?= $err('system_name') ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="system_fullname">ชื่อเต็มของระบบ</label>
                        <input class="form-control" name="system_fullname" id="system_fullname" maxlength="150" value="<?= e($v('system_fullname')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="slogan">สโลแกน</label>
                        <input class="form-control" name="slogan" id="slogan" maxlength="150" value="<?= e($v('slogan')) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="site_url">URL หลักของระบบ (ใช้สร้าง QR Code ให้นักเรียนประเมิน)</label>
                        <input class="form-control<?= $invalid('site_url') ?>" name="site_url" id="site_url" maxlength="200" value="<?= e($v('site_url')) ?>"
                               placeholder="เว้นว่าง = ใช้ที่อยู่ปัจจุบัน (<?= e(site_base_url()) ?>)">
                        <div class="form-text">ถ้าเปิดระบบผ่าน localhost โทรศัพท์นักเรียนจะสแกน QR ไม่ได้ ให้ใส่ที่อยู่จริง เช่น
                            <code>https://teachhub.ptc.ac.th</code> หรือ IP ในวิทยาลัย <code>http://192.168.1.10/teachhub_pt</code></div>
                        <?= $err('site_url') ?>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-cloud-upload me-2"></i>การอัปโหลดไฟล์งาน</div>
                <div class="card-body row g-3">
                    <div class="col-md-5">
                        <label class="form-label" for="max_upload_mb">ขนาดไฟล์สูงสุด (MB) *</label>
                        <input type="number" class="form-control<?= $invalid('max_upload_mb') ?>" name="max_upload_mb" id="max_upload_mb" min="1" max="200" value="<?= e($v('max_upload_mb', '30')) ?>">
                        <div class="form-text">PHP บนเซิร์ฟเวอร์รับได้สูงสุด <?= $serverMaxMb ?> MB (upload_max_filesize = <?= e((string)ini_get('upload_max_filesize')) ?>, post_max_size = <?= e((string)ini_get('post_max_size')) ?>)</div>
                        <?= $err('max_upload_mb') ?>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label d-block">นามสกุลไฟล์งานที่อนุญาต *</label>
                        <?php foreach ($docExtensions as $x): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="allowed_extensions[]" id="ext_<?= e($x) ?>" value="<?= e($x) ?>" <?= in_array($x, $currentExts, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ext_<?= e($x) ?>">.<?= e($x) ?></label>
                            </div>
                        <?php endforeach; ?>
                        <div class="form-text">ระบบตรวจทั้งนามสกุลและชนิดไฟล์จริง (MIME) ทุกครั้ง</div>
                        <?= $err('allowed_extensions') ?>
                    </div>
                </div>
            </div>

            <div class="card mb-4" id="approvers">
                <div class="card-header"><i class="bi bi-diagram-2 me-2"></i>ผู้พิจารณารายงานการนิเทศของหัวหน้าแผนก (เสนอตามลำดับ)</div>
                <div class="card-body">
                    <?php $approverOptions = db_all("SELECT id, prefix, first_name, last_name, role FROM users WHERE status = 'active' AND role IN ('admin','executive') ORDER BY role, first_name"); ?>
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                        <div class="row g-2 mb-2 align-items-end">
                            <div class="col-md-1 fw-bold text-center fs-5"><?= $i ?></div>
                            <div class="col-md-6">
                                <label class="form-label small mb-1" for="approver_<?= $i ?>_title">ตำแหน่ง</label>
                                <input class="form-control" name="approver_<?= $i ?>_title" id="approver_<?= $i ?>_title" maxlength="150"
                                       value="<?= e($v('approver_' . $i . '_title', ['', 'หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้', 'รองผู้อำนวยการฝ่ายวิชาการ', 'ผู้อำนวยการวิทยาลัยการอาชีพโพนทอง'][$i])) ?>">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small mb-1" for="approver_<?= $i ?>_user">ผู้ดำรงตำแหน่ง (บัญชีผู้ใช้)</label>
                                <select class="form-select" name="approver_<?= $i ?>_user" id="approver_<?= $i ?>_user">
                                    <option value="">-- ยังไม่กำหนด --</option>
                                    <?php foreach ($approverOptions as $o): ?>
                                        <option value="<?= (int)$o['id'] ?>" <?= (int)$v('approver_' . $i . '_user') === (int)$o['id'] ? 'selected' : '' ?>>
                                            <?= e(full_name($o)) ?> (<?= e($o['role'] === 'admin' ? 'ผู้ดูแลระบบ' : 'ผู้บริหาร') ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endfor; ?>
                    <?= $err('approvers') ?>
                    <div class="form-text">ผู้พิจารณาต้องมีบัญชีสิทธิ์ “ผู้ดูแลระบบ” หรือ “ผู้บริหาร” (เพิ่มได้ที่เมนูจัดการผู้ใช้)
                        · ขั้น 1–2 เลือก “เห็นควรเสนอ” หรือ “ส่งกลับแก้ไข” · ขั้น 3 เลือก “ทราบ/อนุมัติ” หรือ “ส่งกลับแก้ไข”</div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-shield-lock me-2"></i>สมาชิกและความปลอดภัย</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="allow_register" id="allow_register" value="1"
                                <?= (is_post() ? isset($_POST['allow_register']) : setting('allow_register', '1') === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="allow_register">เปิดให้ครูสมัครสมาชิกเอง (ต้องรอ admin อนุมัติ)</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="session_timeout_min">หมดเวลาเมื่อไม่ใช้งาน (นาที) *</label>
                        <input type="number" class="form-control<?= $invalid('session_timeout_min') ?>" name="session_timeout_min" id="session_timeout_min" min="5" max="480" value="<?= e($v('session_timeout_min', '30')) ?>"><?= $err('session_timeout_min') ?>
                    </div>
                </div>
            </div>

            <div class="card mb-4" id="google">
                <div class="card-header"><i class="bi bi-google me-2"></i>เข้าสู่ระบบ / สมัครสมาชิกด้วยบัญชี Google</div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="google_enabled" id="google_enabled" value="1"
                                <?= (is_post() ? isset($_POST['google_enabled']) : setting('google_enabled', '0') === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="google_enabled">เปิดปุ่ม “เข้าสู่ระบบด้วย Google” ในหน้าเข้าสู่ระบบและสมัครสมาชิก</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="google_client_id">Client ID</label>
                        <input class="form-control<?= $invalid('google') ?>" name="google_client_id" id="google_client_id" maxlength="255"
                               value="<?= e($v('google_client_id')) ?>" placeholder="xxxx.apps.googleusercontent.com" autocomplete="off">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="google_client_secret">Client Secret</label>
                        <input type="password" class="form-control<?= $invalid('google') ?>" name="google_client_secret" id="google_client_secret" maxlength="255"
                               placeholder="<?= setting('google_client_secret') !== '' ? 'บันทึกไว้แล้ว — เว้นว่างถ้าไม่เปลี่ยน' : 'GOCSPX-...' ?>" autocomplete="new-password">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="google_allowed_domain">จำกัดเฉพาะอีเมลโดเมน (ไม่บังคับ)</label>
                        <div class="input-group">
                            <span class="input-group-text">@</span>
                            <input class="form-control<?= $invalid('google') ?>" name="google_allowed_domain" id="google_allowed_domain" maxlength="100"
                                   value="<?= e($v('google_allowed_domain')) ?>" placeholder="เช่น pticec.ac.th">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Authorized redirect URI (คัดลอกไปใส่ใน Google Cloud)</label>
                        <input class="form-control font-monospace small" value="<?= e(google_redirect_uri()) ?>" readonly onclick="this.select()">
                    </div>
                    <div class="col-12"><?= $err('google') ?>
                        <div class="form-text">
                            สร้างได้ที่ Google Cloud Console → APIs &amp; Services → Credentials → Create OAuth client ID (Web application)
                            แล้วใส่ Redirect URI ด้านบน · ผู้ที่ไม่มีบัญชีจะถูกพาไปกรอกข้อมูลสมัครสมาชิกและต้องรอ admin อนุมัติ
                            · บัญชีเดิมที่อีเมลตรงกันจะเชื่อมกับ Google ให้อัตโนมัติ
                            <?php if (site_url_is_local()): ?><br><span class="text-danger">ขณะนี้ URL เป็น localhost — Google รองรับ http://localhost สำหรับทดสอบ แต่ใช้งานจริงต้องเป็น https และตั้ง “URL หลักของระบบ” ให้ถูกต้อง</span><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-image me-2"></i>โลโก้วิทยาลัย</div>
                <div class="card-body text-center">
                    <img src="<?= e($logoUrl) ?>" alt="โลโก้ปัจจุบัน" id="logoPreview" style="width:120px;height:120px;object-fit:contain" class="mb-3 border rounded-circle p-2 bg-white">
                    <input type="file" class="form-control<?= $invalid('logo') ?>" name="logo" id="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp">
                    <div class="form-text">PNG/JPG/WEBP ไม่เกิน 2MB แนะนำภาพสี่เหลี่ยมจัตุรัสพื้นโปร่งใส</div>
                    <?= $err('logo') ?>
                    <?php if (setting('logo')): ?>
                        <button type="submit" form="removeLogo" class="btn btn-sm btn-outline-danger mt-2"><i class="bi bi-trash"></i> ใช้โลโก้เริ่มต้น</button>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-palette me-2"></i>สีธีม</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="color_primary">สีหลัก</label>
                            <input type="color" class="form-control form-control-color w-100<?= $invalid('color_primary') ?>" name="color_primary" id="color_primary" value="<?= e($v('color_primary', COLOR_PRIMARY_DEFAULT)) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="color_accent">สีรอง</label>
                            <input type="color" class="form-control form-control-color w-100<?= $invalid('color_accent') ?>" name="color_accent" id="color_accent" value="<?= e($v('color_accent', COLOR_ACCENT_DEFAULT)) ?>">
                        </div>
                    </div>
                    <?= $err('color_primary') ?: $err('color_accent') ?>
                    <button type="button" class="btn btn-sm btn-link px-0 mt-2" id="resetColors">คืนค่าสีเริ่มต้น (น้ำเงินกรมท่า + ส้ม)</button>
                </div>
            </div>

            <button type="submit" class="btn btn-accent w-100 py-2" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกการตั้งค่า</button>
        </div>
    </div>
</form>
<form method="post" id="removeLogo" data-confirm="ลบโลโก้และกลับไปใช้โลโก้เริ่มต้น?" class="d-none">
    <?= csrf_field() ?><input type="hidden" name="action" value="remove_logo">
</form>
<?php
$pageScripts = '(function () {
    document.getElementById("logo").addEventListener("change", function (e) {
        var f = e.target.files[0]; if (f) document.getElementById("logoPreview").src = URL.createObjectURL(f);
    });
    function apply() {
        document.documentElement.style.setProperty("--th-primary", document.getElementById("color_primary").value);
        document.documentElement.style.setProperty("--th-accent", document.getElementById("color_accent").value);
    }
    ["color_primary", "color_accent"].forEach(function (id) { document.getElementById(id).addEventListener("input", apply); });
    document.getElementById("resetColors").addEventListener("click", function () {
        document.getElementById("color_primary").value = ' . json_encode(COLOR_PRIMARY_DEFAULT) . ';
        document.getElementById("color_accent").value = ' . json_encode(COLOR_ACCENT_DEFAULT) . ';
        apply();
    });
})();';
require APP_ROOT . '/includes/footer.php';
