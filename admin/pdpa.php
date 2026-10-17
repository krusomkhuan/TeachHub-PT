<?php
/**
 * ไฟล์: admin/pdpa.php
 * จัดการข้อตกลง PDPA: แก้ไขเนื้อหา, ประกาศฉบับใหม่ (ทุกคนต้องยอมรับอีกครั้ง), เปิด/ปิดการบังคับยอมรับ
 * และดูสถานะการยอมรับของผู้ใช้แต่ละคน
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');

    if ($action === 'reset_default') {
        // กลับไปใช้ข้อความเริ่มต้น (ไม่เปลี่ยนฉบับ)
        setting_save('pdpa_content', '');
        log_activity('pdpa_update', 'คืนค่าข้อตกลง PDPA เป็นข้อความเริ่มต้น');
        flash('success', 'คืนค่าข้อความเริ่มต้นเรียบร้อยแล้ว');
        redirect('admin/pdpa.php');
    }

    $content = str_replace("\r\n", "\n", (string)($_POST['pdpa_content'] ?? ''));
    $content = trim($content);
    if (mb_strlen($content) < 50) {
        $errors['pdpa_content'] = 'กรุณากรอกเนื้อหาข้อตกลงอย่างน้อย 50 ตัวอักษร';
    } elseif (mb_strlen($content) > 30000) {
        $errors['pdpa_content'] = 'เนื้อหายาวเกิน 30,000 ตัวอักษร';
    }

    if (!$errors) {
        // ถ้าข้อความเหมือนข้อความเริ่มต้น เก็บเป็นค่าว่างไว้ (ชื่อวิทยาลัยเปลี่ยนแล้วข้อความจะตามอัตโนมัติ)
        setting_save('pdpa_content', $content === trim(pdpa_default_content()) ? '' : $content);
        setting_save('pdpa_required', isset($_POST['pdpa_required']) ? '1' : '0');
        $msg = 'บันทึกข้อตกลง PDPA เรียบร้อยแล้ว';
        if (isset($_POST['new_version'])) {
            $next = (string)((int)pdpa_version() + 1);
            setting_save('pdpa_version', $next);
            setting_save('pdpa_updated', date('Y-m-d'));
            $msg = 'ประกาศข้อตกลง PDPA ฉบับที่ ' . $next . ' แล้ว ผู้ใช้ทุกคนต้องยอมรับอีกครั้งเมื่อเข้าใช้งาน';
        }
        log_activity('pdpa_update', $msg);
        flash('success', $msg);
        redirect('admin/pdpa.php');
    }
}

$content = is_post() ? (string)($_POST['pdpa_content'] ?? '') : pdpa_content();
$required = is_post() ? isset($_POST['pdpa_required']) : pdpa_required();
$version = pdpa_version();

// สถานะการยอมรับของผู้ใช้ที่ใช้งานอยู่
$users = db_all(
    "SELECT u.id, u.prefix, u.first_name, u.last_name, u.username, u.role, u.pdpa_version, u.pdpa_accepted_at,
            d.name AS department_name
     FROM users u LEFT JOIN departments d ON d.id = u.department_id
     WHERE u.status = 'active'
     ORDER BY (u.pdpa_version = ?) DESC, u.pdpa_accepted_at DESC, u.first_name",
    's',
    [$version]
);
$accepted = count(array_filter($users, fn($u) => (string)$u['pdpa_version'] === $version));
$total = count($users);

$pageTitle = 'ข้อตกลง PDPA';
$pageIcon = 'bi-shield-check';
$breadcrumbs = ['ข้อตกลง PDPA' => null];
$pageActions = '<a href="' . e(url('public/pdpa.php')) . '" target="_blank" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>ดูหน้าข้อตกลง</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-8">
        <form method="post" novalidate data-confirm="บันทึกข้อตกลง PDPA?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <div class="card">
                <div class="card-header d-flex align-items-center">
                    <span><i class="bi bi-pencil-square me-2"></i>เนื้อหาข้อตกลง</span>
                    <span class="badge bg-primary ms-auto">ฉบับที่ <?= e($version) ?> · ประกาศใช้ <?= e(thai_date(pdpa_effective_date())) ?></span>
                </div>
                <div class="card-body">
                    <div class="form-text mb-2">
                        รูปแบบ: เว้นบรรทัดว่างเพื่อขึ้นย่อหน้าใหม่ · ขึ้นต้นบรรทัดด้วย <code>## </code> = หัวข้อ · ขึ้นต้นด้วย <code>- </code> = รายการแบบจุด
                    </div>
                    <textarea class="form-control font-monospace small<?= isset($errors['pdpa_content']) ? ' is-invalid' : '' ?>"
                              name="pdpa_content" id="pdpa_content" rows="22"><?= e($content) ?></textarea>
                    <?php if (isset($errors['pdpa_content'])): ?><div class="invalid-feedback"><?= e($errors['pdpa_content']) ?></div><?php endif; ?>

                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="pdpa_required" name="pdpa_required" value="1" <?= $required ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pdpa_required">บังคับให้ผู้ใช้ยอมรับข้อตกลงก่อนเข้าใช้งานระบบ</label>
                    </div>
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="new_version" name="new_version" value="1">
                        <label class="form-check-label" for="new_version">
                            ประกาศเป็น <strong>ฉบับใหม่ (ฉบับที่ <?= (int)$version + 1 ?>)</strong> — ผู้ใช้ทุกคนต้องกดยอมรับอีกครั้ง
                        </label>
                        <div class="form-text">ใช้เมื่อเปลี่ยนสาระสำคัญของข้อตกลง ถ้าแก้คำผิดเล็กน้อยไม่ต้องเลือก</div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2 justify-content-end">
                    <button type="submit" class="btn btn-accent px-4"><i class="bi bi-save me-1"></i>บันทึก</button>
                </div>
            </div>
        </form>
        <form method="post" class="mt-2 text-end" data-confirm="คืนค่าเนื้อหาเป็นข้อความเริ่มต้นของระบบ?" data-confirm-icon="warning">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_default">
            <button type="submit" class="btn btn-sm btn-link text-danger"><i class="bi bi-arrow-counterclockwise me-1"></i>คืนค่าข้อความเริ่มต้น</button>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-people me-2"></i>การยอมรับฉบับปัจจุบัน</div>
            <div class="card-body text-center">
                <div class="display-6 fw-bold text-primary-th"><?= $accepted ?> / <?= $total ?></div>
                <div class="text-muted small mb-2">ผู้ใช้ที่ใช้งานอยู่ ยอมรับแล้ว</div>
                <div class="progress" role="progressbar" aria-label="ร้อยละการยอมรับ"
                     aria-valuenow="<?= $total ? round($accepted * 100 / $total) : 0 ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar bg-success" style="width: <?= $total ? round($accepted * 100 / $total) : 0 ?>%"></div>
                </div>
                <?php if (!$required): ?>
                    <div class="alert alert-warning small mt-3 mb-0">ขณะนี้ไม่ได้บังคับยอมรับ ผู้ใช้ใช้งานได้โดยไม่ต้องกดยอมรับ</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-check me-2"></i>สถานะการยอมรับรายบุคคล</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable" data-order="[]">
                        <thead><tr><th>ชื่อ-สกุล</th><th>แผนก</th><th>สิทธิ์</th><th>สถานะ</th><th>ยอมรับเมื่อ</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $u): $ok = (string)$u['pdpa_version'] === $version; ?>
                            <tr>
                                <td><?= e(full_name($u)) ?> <small class="text-muted">(<?= e($u['username']) ?>)</small></td>
                                <td class="small"><?= e($u['department_name'] ?? '-') ?></td>
                                <td class="small"><?= e(role_label($u['role'])) ?></td>
                                <td>
                                    <?php if ($ok): ?>
                                        <span class="badge bg-success">ยอมรับแล้ว</span>
                                    <?php elseif ($u['pdpa_version']): ?>
                                        <span class="badge bg-warning text-dark">ยอมรับฉบับที่ <?= e($u['pdpa_version']) ?> (ยังไม่ยอมรับฉบับใหม่)</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">ยังไม่ยอมรับ</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small" data-order="<?= e((string)$u['pdpa_accepted_at']) ?>"><?= e(thai_datetime($u['pdpa_accepted_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
