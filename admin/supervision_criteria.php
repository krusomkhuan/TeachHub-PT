<?php
/**
 * ไฟล์: admin/supervision_criteria.php
 * จัดการหัวข้อแบบประเมินการนิเทศ (มาตราส่วน 5 ระดับ) แยก 2 ชุด — เพิ่ม/แก้ไข/เรียงลำดับ/เปิด-ปิด/ลบ
 *   ?audience=supervisor : ชุดที่หัวหน้าแผนกใช้นิเทศการสอน
 *   ?audience=student    : ชุดที่นักเรียน นักศึกษาใช้ประเมินผ่าน QR Code
 * หมายเหตุ: ผลการประเมินเก็บสำเนาชื่อหัวข้อไว้แล้ว การแก้ชื่อหัวข้อภายหลังจึงไม่กระทบผลเดิม
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];
$audience = (get('audience') ?: post('audience')) === 'student' ? 'student' : 'supervisor';
$back = 'admin/supervision_criteria.php?audience=' . $audience;
// จำนวนครั้งที่หัวข้อถูกใช้ (รวมทั้งนิเทศโดยหัวหน้าแผนกและโดยนักเรียน)
$usedSql = '((SELECT COUNT(*) FROM supervision_scores s WHERE s.criteria_id = c.id)
            + (SELECT COUNT(*) FROM student_eval_scores t WHERE t.criteria_id = c.id))';

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $category = mb_substr(post('category'), 0, 150);
        $title = mb_substr(post('title'), 0, 255);
        $sort = max(0, min(9999, (int)post('sort_order')));
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($category === '') {
            $errors['category'] = 'กรุณาระบุด้านการประเมิน';
        }
        if ($title === '') {
            $errors['title'] = 'กรุณาระบุรายการประเมิน';
        }
        if (!$errors) {
            if ($id > 0) {
                db_exec('UPDATE supervision_criteria SET category = ?, title = ?, sort_order = ?, is_active = ? WHERE id = ? AND audience = ?',
                    'ssiiis', [$category, $title, $sort, $active, $id, $audience]);
                log_activity('criteria_update', 'แก้ไขหัวข้อประเมิน (' . $audience . '): ' . $title);
                flash('success', 'บันทึกการแก้ไขหัวข้อแล้ว');
            } else {
                db_insert('INSERT INTO supervision_criteria (audience, category, title, sort_order, is_active) VALUES (?, ?, ?, ?, ?)',
                    'sssii', [$audience, $category, $title, $sort, $active]);
                log_activity('criteria_create', 'เพิ่มหัวข้อประเมิน (' . $audience . '): ' . $title);
                flash('success', 'เพิ่มหัวข้อประเมินแล้ว');
            }
            redirect($back);
        }
    }

    if ($action === 'toggle') {
        db_exec('UPDATE supervision_criteria SET is_active = 1 - is_active WHERE id = ?', 'i', [$id]);
        log_activity('criteria_update', 'เปิด/ปิดหัวข้อประเมิน #' . $id);
        flash('success', 'เปลี่ยนสถานะหัวข้อแล้ว');
        redirect($back);
    }

    if ($action === 'delete') {
        $used = (int)db_value("SELECT $usedSql FROM supervision_criteria c WHERE c.id = ?", 'i', [$id]);
        if ($used > 0) {
            flash('error', 'หัวข้อนี้ถูกใช้ประเมินแล้ว ' . $used . ' ครั้ง ไม่สามารถลบได้ (ใช้ "ปิดใช้งาน" แทน)');
        } else {
            db_exec('DELETE FROM supervision_criteria WHERE id = ?', 'i', [$id]);
            log_activity('criteria_delete', 'ลบหัวข้อประเมิน #' . $id);
            flash('success', 'ลบหัวข้อแล้ว');
        }
        redirect($back);
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM supervision_criteria WHERE id = ? AND audience = ?', 'is', [$editId, $audience]) : null;
$criteria = db_all(
    "SELECT c.*, $usedSql AS used FROM supervision_criteria c WHERE c.audience = ? ORDER BY c.sort_order, c.id",
    's',
    [$audience]
);
$categories = array_values(array_unique(array_column($criteria, 'category')));
$nextSort = $criteria ? max(array_column($criteria, 'sort_order')) + 1 : 1;
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);
$counts = [];
foreach (db_all('SELECT audience, COUNT(*) AS n FROM supervision_criteria WHERE is_active = 1 GROUP BY audience') as $r) {
    $counts[$r['audience']] = (int)$r['n'];
}
$scale = $audience === 'student'
    ? '5 = มากที่สุด, 4 = มาก, 3 = ปานกลาง, 2 = น้อย, 1 = น้อยที่สุด'
    : '5 = ดีมาก, 4 = ดี, 3 = ปานกลาง, 2 = พอใช้, 1 = ควรปรับปรุง';

$pageTitle = 'หัวข้อแบบประเมินการนิเทศ';
$pageIcon = 'bi-list-check';
$breadcrumbs = ['นิเทศการสอน' => $audience === 'student' ? 'admin/student_eval.php' : 'admin/supervision.php', 'หัวข้อแบบประเมิน' => null];
require APP_ROOT . '/includes/header.php';
?>
<ul class="nav nav-pills mb-4 gap-1">
    <li class="nav-item"><a class="nav-link <?= $audience === 'supervisor' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?audience=supervisor">
        <i class="bi bi-person-video3 me-1"></i>1. นิเทศโดยหัวหน้าแผนก <span class="badge bg-light text-dark ms-1"><?= $counts['supervisor'] ?? 0 ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $audience === 'student' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?audience=student">
        <i class="bi bi-people me-1"></i>2. ประเมินโดยนักเรียน นักศึกษา <span class="badge bg-light text-dark ms-1"><?= $counts['student'] ?? 0 ?></span></a></li>
</ul>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขหัวข้อ' : 'เพิ่มหัวข้อประเมิน' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="audience" value="<?= e($audience) ?>">
                    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="category">ด้านการประเมิน *</label>
                        <input class="form-control<?= isset($errors['category']) ? ' is-invalid' : '' ?>" name="category" id="category" maxlength="150"
                               value="<?= e($fv('category')) ?>" list="catList" placeholder="<?= $audience === 'student' ? 'เช่น ด้านการสอนของครู' : 'เช่น ด้านการจัดการเรียนรู้' ?>">
                        <datalist id="catList"><?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?></datalist>
                        <?php if (isset($errors['category'])): ?><div class="invalid-feedback"><?= e($errors['category']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="title">รายการประเมิน *</label>
                        <textarea class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>" name="title" id="title" rows="3" maxlength="255"
                                  placeholder="<?= $audience === 'student' ? 'ใช้ภาษาที่นักเรียนเข้าใจง่าย เช่น ครูอธิบายเนื้อหาชัดเจน' : '' ?>"><?= e($fv('title')) ?></textarea>
                        <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?= e($errors['title']) ?></div><?php endif; ?>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="sort_order">ลำดับ</label>
                            <input type="number" class="form-control" name="sort_order" id="sort_order" min="0" max="9999" value="<?= e($fv('sort_order', (string)$nextSort)) ?>">
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1"
                                    <?= (is_post() ? isset($_POST['is_active']) : (int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">ใช้งาน</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($editing): ?><a href="<?= e(url($back)) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
                        <button class="btn btn-accent flex-fill"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="alert alert-light border small mt-3">
            <i class="bi bi-info-circle me-1"></i> มาตราส่วน 5 ระดับ: <?= e($scale) ?><br>
            ระดับคุณภาพจากค่าเฉลี่ย: 4.51–5.00 ดีมาก · 3.51–4.50 ดี · 2.51–3.50 ปานกลาง · 1.51–2.50 พอใช้ · 1.00–1.50 ควรปรับปรุง
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ol me-2"></i><?= $audience === 'student' ? 'หัวข้อที่นักเรียนประเมิน' : 'หัวข้อที่หัวหน้าแผนกนิเทศ' ?>
                (<?= count($criteria) ?> ข้อ · ใช้งาน <?= count(array_filter($criteria, fn($c) => (int)$c['is_active'] === 1)) ?> ข้อ)</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th style="width:70px">ลำดับ</th><th>ด้าน / รายการประเมิน</th><th>ใช้แล้ว</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                    <tbody>
                    <?php $lastCat = null; foreach ($criteria as $c): ?>
                        <?php if ($c['category'] !== $lastCat): $lastCat = $c['category']; ?>
                            <tr class="table-light"><td colspan="5" class="fw-semibold text-primary-th"><?= e($c['category']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="<?= $c['is_active'] ? '' : 'text-muted' ?>">
                            <td class="text-center"><?= (int)$c['sort_order'] ?></td>
                            <td><?= e($c['title']) ?></td>
                            <td><?= (int)$c['used'] ?></td>
                            <td><?= $c['is_active'] ? '<span class="badge bg-success">ใช้งาน</span>' : '<span class="badge bg-secondary">ปิด</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?audience=<?= e($audience) ?>&amp;edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="audience" value="<?= e($audience) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="เปิด/ปิด"><i class="bi <?= $c['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                                </form>
                                <?php if ((int)$c['used'] === 0): ?>
                                    <form method="post" class="d-inline" data-confirm="ลบหัวข้อนี้?" data-confirm-icon="warning">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="audience" value="<?= e($audience) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$criteria): ?><tr><td colspan="5" class="empty-state">ยังไม่มีหัวข้อประเมิน</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
