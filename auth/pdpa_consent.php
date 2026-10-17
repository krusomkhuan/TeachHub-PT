<?php
/**
 * ไฟล์: auth/pdpa_consent.php
 * หน้ายอมรับข้อตกลง PDPA — ผู้ใช้ที่ยังไม่ยอมรับฉบับปัจจุบันจะถูกพามาหน้านี้ก่อนใช้งานส่วนอื่น
 * ไม่ยอมรับ = ออกจากระบบ
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$errors = [];

// ยอมรับแล้ว ไม่ต้องอยู่หน้านี้
if (!pdpa_needs_consent($user) && !is_post()) {
    redirect(dashboard_url($user['role']));
}

if (is_post()) {
    csrf_verify();
    if (!isset($_POST['pdpa_accept'])) {
        $errors['pdpa_accept'] = 'กรุณาทำเครื่องหมายยอมรับข้อตกลงก่อน';
    } else {
        pdpa_accept((int)$user['id']);
        flash('success', 'ขอบคุณที่ยอมรับข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล');
        redirect(dashboard_url($user['role']));
    }
}

$pageTitle = 'ข้อตกลง PDPA';
$authWide = true;
require APP_ROOT . '/includes/auth_header.php';
?>
<h3 class="auth-title"><i class="bi bi-shield-check me-1"></i> ข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล</h3>
<p class="auth-subtitle"><?= e(full_name($user)) ?> (<?= e($user['username']) ?>) · ฉบับที่ <?= e(pdpa_version()) ?></p>

<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    <?= !empty($user['pdpa_version'])
        ? 'ข้อตกลงมีการปรับปรุง กรุณาอ่านและยอมรับฉบับใหม่ก่อนใช้งานต่อ'
        : 'กรุณาอ่านและยอมรับข้อตกลงการคุ้มครองข้อมูลส่วนบุคคลก่อนเริ่มใช้งานระบบ' ?>
</div>

<div class="border rounded p-3 mb-3 bg-light small lh-lg" style="max-height:45vh;overflow-y:auto" tabindex="0">
    <?= pdpa_render(pdpa_content()) ?>
</div>

<form method="post" action="<?= e(url('auth/pdpa_consent.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <div class="form-check mb-3">
        <input class="form-check-input<?= isset($errors['pdpa_accept']) ? ' is-invalid' : '' ?>" type="checkbox"
               id="pdpa_accept" name="pdpa_accept" value="1" required>
        <label class="form-check-label" for="pdpa_accept">
            ข้าพเจ้าได้อ่านและยอมรับข้อตกลงการคุ้มครองข้อมูลส่วนบุคคลข้างต้น
        </label>
        <?php if (isset($errors['pdpa_accept'])): ?><div class="invalid-feedback"><?= e($errors['pdpa_accept']) ?></div><?php endif; ?>
    </div>
    <div class="d-grid d-md-flex justify-content-md-end gap-2">
        <a href="<?= e(url('auth/logout.php?_csrf=' . csrf_token())) ?>" class="btn btn-outline-secondary px-4">
            ไม่ยอมรับ (ออกจากระบบ)
        </a>
        <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังบันทึก...">
            <i class="bi bi-check2-circle me-1"></i> ยอมรับและเข้าใช้งาน
        </button>
    </div>
</form>

<?php require APP_ROOT . '/includes/auth_footer.php'; ?>
