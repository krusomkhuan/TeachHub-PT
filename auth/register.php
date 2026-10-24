<?php
/**
 * ไฟล์: auth/register.php
 * หน้าสมัครสมาชิกสำหรับครู — สมัครแล้วสถานะ "รออนุมัติ" ต้องให้ admin อนุมัติก่อน
 */
require_once __DIR__ . '/../config/config.php';

redirect_if_logged_in();

if (setting('allow_register', '1') !== '1') {
    flash('warning', 'ขณะนี้ระบบปิดรับสมัครสมาชิก กรุณาติดต่องานพัฒนาหลักสูตรฯ');
    redirect('auth/login.php');
}

$departments = db_all('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name');
$errors = [];

// สมัครด้วยบัญชี Google (ยืนยันกับ Google แล้ว รอกรอกข้อมูลเพิ่ม)
if (get('google') === 'cancel') {
    unset($_SESSION['google_signup']);
    redirect('auth/register.php');
}
$google = google_pending_signup();

if (is_post()) {
    csrf_verify();

    // รับค่าจากฟอร์ม
    $data = [
        'prefix'        => post('prefix'),
        'first_name'    => post('first_name'),
        'last_name'     => post('last_name'),
        'department_id' => (int)post('department_id'),
        'position'      => post('position'),
        // สมัครด้วย Google: ใช้อีเมลที่ Google ยืนยันแล้วเท่านั้น
        'email'         => $google ? $google['email'] : mb_strtolower(post('email')),
        'phone'         => preg_replace('/[\s-]/', '', post('phone')),
        'username'      => post('username'),
    ];
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password_confirm'] ?? '');

    // ---------- ตรวจสอบข้อมูล ----------
    if (!in_array($data['prefix'], name_prefixes(), true)) {
        $errors['prefix'] = 'กรุณาเลือกคำนำหน้า';
    }
    if ($data['first_name'] === '' || mb_strlen($data['first_name']) > 100) {
        $errors['first_name'] = 'กรุณากรอกชื่อ';
    }
    if ($data['last_name'] === '' || mb_strlen($data['last_name']) > 100) {
        $errors['last_name'] = 'กรุณากรอกนามสกุล';
    }
    // รองผู้อำนวยการไม่สังกัดแผนกวิชา จึงไม่บังคับเลือกแผนก
    $deptIds = array_map('intval', array_column($departments, 'id'));
    $isExec = is_executive_position((string)$data['position']);
    if ($isExec && $data['department_id'] === 0) {
        $data['department_id'] = null;
    } elseif (!in_array($data['department_id'], $deptIds, true)) {
        $errors['department_id'] = 'กรุณาเลือกแผนกวิชา';
    }
    if (!in_array($data['position'], position_options(), true)) {
        $errors['position'] = 'กรุณาเลือกตำแหน่ง';
    }
    if (!valid_email($data['email']) || mb_strlen($data['email']) > 150) {
        $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }
    if (!valid_phone($data['phone'])) {
        $errors['phone'] = 'เบอร์โทรศัพท์ไม่ถูกต้อง (ตัวเลข 9-10 หลัก ขึ้นต้นด้วย 0)';
    }
    if (!valid_username($data['username'])) {
        $errors['username'] = 'ชื่อผู้ใช้ต้องเป็นตัวอักษรภาษาอังกฤษ ยาว 4-30 ตัว (ใช้ตัวเลข 0-9 และ _ . - ได้ แต่ไม่บังคับ) ห้ามมีภาษาไทยหรือช่องว่าง';
    }
    if ($google) {
        // เข้าสู่ระบบด้วย Google จึงไม่ต้องตั้งรหัสผ่าน (สร้างรหัสสุ่มที่ไม่มีใครรู้ไว้แทน)
        $password = bin2hex(random_bytes(24));
        if (db_value('SELECT id FROM users WHERE google_sub = ?', 's', [$google['sub']])) {
            $errors['email'] = 'บัญชี Google นี้สมัครไว้แล้ว';
        }
    } elseif ($msg = password_policy_error($password)) {
        $errors['password'] = $msg;
    } elseif ($password !== $password2) {
        $errors['password_confirm'] = 'ยืนยันรหัสผ่านไม่ตรงกัน';
    }
    if (!isset($_POST['pdpa_accept'])) {
        $errors['pdpa_accept'] = 'กรุณายอมรับข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล (PDPA)';
    }

    // ตรวจข้อมูลซ้ำ
    if (!isset($errors['username']) && db_value('SELECT id FROM users WHERE username = ?', 's', [$data['username']])) {
        $errors['username'] = 'ชื่อผู้ใช้นี้ถูกใช้แล้ว';
    }
    if (!isset($errors['email']) && db_value('SELECT id FROM users WHERE email = ?', 's', [$data['email']])) {
        $errors['email'] = 'อีเมลนี้ถูกใช้สมัครแล้ว';
    }

    // ---------- บันทึก ----------
    if (!$errors) {
        $newId = db_insert(
            'INSERT INTO users (username, password_hash, prefix, first_name, last_name,
                                department_id, position, email, phone, role, status, pdpa_version, pdpa_accepted_at,
                                google_sub, password_login)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'teacher\', \'pending\', ?, NOW(), ?, ?)',
            'sssssisssssi',
            [
                $data['username'], password_hash($password, PASSWORD_DEFAULT), $data['prefix'],
                $data['first_name'], $data['last_name'], $data['department_id'],
                $data['position'], $data['email'], $data['phone'], pdpa_version(),
                $google ? $google['sub'] : null, $google ? 0 : 1,
            ]
        );
        log_activity('register', 'สมัครสมาชิกใหม่: ' . $data['username'] . ($google ? ' (บัญชี Google)' : ''), $newId);
        unset($_SESSION['google_signup']);

        flash('success', 'สมัครสมาชิกเรียบร้อยแล้ว กรุณารอผู้ดูแลระบบอนุมัติ จึงจะเข้าสู่ระบบได้');
        redirect('auth/login.php');
    }
}

/** แสดงข้อความผิดพลาดใต้ช่องกรอก */
function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<div class="invalid-feedback d-block">' . e($errors[$key]) . '</div>' : '';
}

function invalid(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' is-invalid' : '';
}

$pageTitle = 'สมัครสมาชิก';
$authWide = true;
require APP_ROOT . '/includes/auth_header.php';
?>
<h3 class="auth-title"><i class="bi bi-person-plus me-1"></i> สมัครสมาชิก</h3>
<p class="auth-subtitle">สำหรับครูผู้สอน — หลังสมัครแล้วต้องรอผู้ดูแลระบบอนุมัติก่อนเข้าใช้งาน</p>

<?php if ($errors): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i> กรุณาตรวจสอบข้อมูลที่ไม่ถูกต้อง</div>
<?php endif; ?>

<?php if ($google): ?>
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-google fs-5"></i>
        <div class="flex-fill">สมัครด้วยบัญชี Google: <strong><?= e($google['email']) ?></strong><br>
            <small>กรอกข้อมูลที่เหลือให้ครบ ไม่ต้องตั้งรหัสผ่าน — เข้าสู่ระบบด้วยปุ่ม “เข้าสู่ระบบด้วย Google”</small></div>
        <a href="<?= e(url('auth/register.php?google=cancel')) ?>" class="btn btn-sm btn-outline-secondary">ยกเลิก</a>
    </div>
<?php elseif (google_enabled()): ?>
    <?= google_button_html('สมัครด้วยบัญชี Google', url('auth/google_login.php')) ?>
    <div class="text-center text-muted small my-3">— หรือกรอกข้อมูลเพื่อสมัครด้วยชื่อผู้ใช้และรหัสผ่าน —</div>
<?php endif; ?>

<form method="post" action="<?= e(url('auth/register.php')) ?>" novalidate
      data-confirm="ตรวจสอบข้อมูลถูกต้องแล้ว ต้องการสมัครสมาชิกใช่หรือไม่?" data-confirm-title="ยืนยันการสมัครสมาชิก">
    <?= csrf_field() ?>

    <h6 class="text-primary-th fw-bold mb-3"><i class="bi bi-person-vcard me-1"></i> ข้อมูลส่วนตัว</h6>
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label" for="prefix">คำนำหน้า <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid($errors, 'prefix') ?>" id="prefix" name="prefix" required>
                <option value="">-- เลือก --</option>
                <?php foreach (name_prefixes() as $p): ?>
                    <option value="<?= e($p) ?>" <?= post('prefix') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'prefix') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="first_name">ชื่อ <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= invalid($errors, 'first_name') ?>" id="first_name" name="first_name"
                   value="<?= old('first_name', $google['given_name'] ?? '') ?>" maxlength="100" required>
            <?= field_error($errors, 'first_name') ?>
        </div>
        <div class="col-md-5">
            <label class="form-label" for="last_name">นามสกุล <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= invalid($errors, 'last_name') ?>" id="last_name" name="last_name"
                   value="<?= old('last_name', $google['family_name'] ?? '') ?>" maxlength="100" required>
            <?= field_error($errors, 'last_name') ?>
        </div>

        <div class="col-md-6">
            <label class="form-label" for="department_id">แผนกวิชา <span class="text-danger" id="deptRequired">*</span></label>
            <select class="form-select<?= invalid($errors, 'department_id') ?>" id="department_id" name="department_id" required>
                <option value="">-- เลือกแผนกวิชา --</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= (int)$d['id'] ?>" <?= (int)post('department_id') === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="form-text d-none" id="deptHint">รองผู้อำนวยการไม่ต้องเลือกแผนกวิชา</div>
            <?= field_error($errors, 'department_id') ?>
        </div>

        <div class="col-md-6">
            <label class="form-label" for="position">ตำแหน่ง <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid($errors, 'position') ?>" id="position" name="position" required>
                <option value="">-- เลือกตำแหน่ง --</option>
                <?php
                // แยกกลุ่ม ครู / ผู้บริหาร ให้เลือกง่าย
                $posGroups = ['ครูและบุคลากร' => [], 'ผู้บริหาร' => []];
                foreach (position_options() as $p) {
                    $posGroups[is_executive_position($p) ? 'ผู้บริหาร' : 'ครูและบุคลากร'][] = $p;
                }
                ?>
                <?php foreach ($posGroups as $gLabel => $items): ?>
                    <optgroup label="<?= e($gLabel) ?>">
                        <?php foreach ($items as $p): ?>
                            <option value="<?= e($p) ?>" data-exec="<?= is_executive_position($p) ? 1 : 0 ?>" <?= post('position') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
            <?= field_error($errors, 'position') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="email">อีเมล <span class="text-danger">*</span></label>
            <input type="email" class="form-control<?= invalid($errors, 'email') ?>" id="email" name="email"
                   value="<?= $google ? e($google['email']) : old('email') ?>" maxlength="150" required autocomplete="email"
                   <?= $google ? 'readonly' : '' ?>>
            <?= field_error($errors, 'email') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label" for="phone">เบอร์โทรศัพท์ <span class="text-danger">*</span></label>
            <input type="tel" class="form-control<?= invalid($errors, 'phone') ?>" id="phone" name="phone"
                   value="<?= old('phone') ?>" maxlength="12" placeholder="08xxxxxxxx" required>
            <?= field_error($errors, 'phone') ?>
        </div>
    </div>

    <h6 class="text-primary-th fw-bold mt-4 mb-3"><i class="bi bi-shield-lock me-1"></i> ข้อมูลเข้าสู่ระบบ</h6>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="username">ชื่อผู้ใช้ <span class="text-danger">*</span></label>
            <input type="text" class="form-control<?= invalid($errors, 'username') ?>" id="username" name="username"
                   value="<?= old('username', $google ? google_suggest_username($google['email']) : '') ?>" maxlength="30" required autocomplete="username">
            <div class="form-text">ตัวอักษรภาษาอังกฤษ ยาว 4-30 ตัว เช่น somchai · จะมีตัวเลข 0-9 หรือ _ . - ด้วยหรือไม่ก็ได้</div>
            <?= field_error($errors, 'username') ?>
        </div>
        <?php if (!$google): ?>
        <div class="col-md-4">
            <label class="form-label" for="password">รหัสผ่าน <span class="text-danger">*</span></label>
            <div class="input-group has-validation">
                <input type="password" class="form-control<?= invalid($errors, 'password') ?>" id="password" name="password"
                       required autocomplete="new-password" data-strength="#pwMeter">
                <span class="input-group-text toggle-password" data-target="#password"><i class="bi bi-eye"></i></span>
            </div>
            <div class="pw-strength mt-1" id="pwMeter"><div></div></div>
            <div class="form-text">อย่างน้อย 8 ตัว มีทั้งตัวอักษรและตัวเลข</div>
            <?= field_error($errors, 'password') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="password_confirm">ยืนยันรหัสผ่าน <span class="text-danger">*</span></label>
            <input type="password" class="form-control<?= invalid($errors, 'password_confirm') ?>" id="password_confirm"
                   name="password_confirm" required autocomplete="new-password">
            <?= field_error($errors, 'password_confirm') ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="form-check mt-4">
        <input class="form-check-input<?= invalid($errors, 'pdpa_accept') ?>" type="checkbox" id="pdpa_accept" name="pdpa_accept" value="1"
               required <?= isset($_POST['pdpa_accept']) ? 'checked' : '' ?>>
        <label class="form-check-label" for="pdpa_accept">
            ข้าพเจ้าได้อ่านและยอมรับ <a href="<?= e(url('public/pdpa.php')) ?>" target="_blank">ข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล (PDPA)</a>
            <span class="text-danger">*</span>
        </label>
        <?= field_error($errors, 'pdpa_accept') ?>
    </div>

    <div class="d-grid d-md-flex justify-content-md-end gap-2 mt-4">
        <a href="<?= e(url('auth/login.php')) ?>" class="btn btn-outline-secondary px-4">มีบัญชีแล้ว? เข้าสู่ระบบ</a>
        <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังบันทึก...">
            <i class="bi bi-person-check me-1"></i> สมัครสมาชิก
        </button>
    </div>
</form>

<script>
// รองผู้อำนวยการ: ไม่บังคับเลือกแผนกวิชา
(function () {
    var pos = document.getElementById('position'), dept = document.getElementById('department_id');
    function sync() {
        var opt = pos.options[pos.selectedIndex], isExec = opt && opt.getAttribute('data-exec') === '1';
        dept.required = !isExec;
        document.getElementById('deptRequired').classList.toggle('d-none', isExec);
        document.getElementById('deptHint').classList.toggle('d-none', !isExec);
    }
    pos.addEventListener('change', sync);
    sync();
})();
</script>

<?php require APP_ROOT . '/includes/auth_footer.php'; ?>
