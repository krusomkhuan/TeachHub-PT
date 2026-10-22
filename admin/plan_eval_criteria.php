<?php
/**
 * ไฟล์: admin/plan_eval_criteria.php
 * จัดการรายการแบบประเมินแผนการจัดการเรียนรู้ — เพิ่ม/แก้ไข/เรียงลำดับ/เปิด-ปิด/ลบ
 *   ?part=check   : ตอนที่ 1 การตรวจสอบองค์ประกอบ (มี / ไม่มี / ไม่เกี่ยวข้อง) + กำหนด "ต้องมีทุกรายวิชา"
 *   ?part=quality : ตอนที่ 2 การประเมินคุณภาพ (5 ระดับ) แยกตามด้าน
 * หมายเหตุ: ผลการประเมินเก็บสำเนาชื่อรายการไว้แล้ว การแก้ไขภายหลังจึงไม่กระทบผลเดิม
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];
$part = (get('part') ?: post('part')) === 'check' ? 'check' : 'quality';
$back = 'admin/plan_eval_criteria.php?part=' . $part;
$usedSql = '(SELECT COUNT(*) FROM plan_eval_items i WHERE i.criteria_id = c.id)';

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'save') {
        $category = mb_substr(post('category'), 0, 150);
        $title = mb_substr(post('title'), 0, 500);
        $sort = max(0, min(9999, (int)post('sort_order')));
        $active = isset($_POST['is_active']) ? 1 : 0;
        $required = $part === 'check' && isset($_POST['is_required']) ? 1 : 0;
        if ($category === '') {
            $errors['category'] = $part === 'check' ? 'กรุณาระบุส่วน' : 'กรุณาระบุด้านการประเมิน';
        }
        if ($title === '') {
            $errors['title'] = 'กรุณาระบุรายการ';
        }
        if (!$errors) {
            if ($id > 0) {
                db_exec('UPDATE plan_eval_criteria SET category = ?, title = ?, is_required = ?, sort_order = ?, is_active = ? WHERE id = ? AND part = ?',
                    'ssiiiis', [$category, $title, $required, $sort, $active, $id, $part]);
                log_activity('plan_criteria_update', 'แก้ไขรายการประเมินแผนฯ (' . $part . '): ' . $title);
                flash('success', 'บันทึกการแก้ไขรายการแล้ว');
            } else {
                db_insert('INSERT INTO plan_eval_criteria (part, category, title, is_required, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)',
                    'sssiii', [$part, $category, $title, $required, $sort, $active]);
                log_activity('plan_criteria_create', 'เพิ่มรายการประเมินแผนฯ (' . $part . '): ' . $title);
                flash('success', 'เพิ่มรายการประเมินแล้ว');
            }
            redirect($back);
        }
    }

    if ($action === 'toggle') {
        db_exec('UPDATE plan_eval_criteria SET is_active = 1 - is_active WHERE id = ?', 'i', [$id]);
        log_activity('plan_criteria_update', 'เปิด/ปิดรายการประเมินแผนฯ #' . $id);
        flash('success', 'เปลี่ยนสถานะรายการแล้ว');
        redirect($back);
    }

    if ($action === 'delete') {
        $used = (int)db_value("SELECT $usedSql FROM plan_eval_criteria c WHERE c.id = ?", 'i', [$id]);
        if ($used > 0) {
            flash('error', 'รายการนี้ถูกใช้ประเมินแล้ว ' . $used . ' ครั้ง ไม่สามารถลบได้ (ใช้ "ปิดใช้งาน" แทน)');
        } else {
            db_exec('DELETE FROM plan_eval_criteria WHERE id = ?', 'i', [$id]);
            log_activity('plan_criteria_delete', 'ลบรายการประเมินแผนฯ #' . $id);
            flash('success', 'ลบรายการแล้ว');
        }
        redirect($back);
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM plan_eval_criteria WHERE id = ? AND part = ?', 'is', [$editId, $part]) : null;
$criteria = db_all("SELECT c.*, $usedSql AS used FROM plan_eval_criteria c WHERE c.part = ? ORDER BY c.sort_order, c.id", 's', [$part]);
$categories = array_values(array_unique(array_column($criteria, 'category')));
$nextSort = $criteria ? max(array_column($criteria, 'sort_order')) + 1 : 1;
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);
$counts = [];
foreach (db_all('SELECT part, COUNT(*) AS n FROM plan_eval_criteria WHERE is_active = 1 GROUP BY part') as $r) {
    $counts[$r['part']] = (int)$r['n'];
}

$pageTitle = 'รายการแบบประเมินแผนการจัดการเรียนรู้';
$pageIcon = 'bi-list-check';
$breadcrumbs = ['ประเมินแผนการจัดการเรียนรู้' => 'review/plan_evaluations.php', 'รายการแบบประเมิน' => null];
require APP_ROOT . '/includes/header.php';
?>
<ul class="nav nav-pills mb-4 gap-1">
    <li class="nav-item"><a class="nav-link <?= $part === 'check' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?part=check">
        <i class="bi bi-list-check me-1"></i>ตอนที่ 1 ตรวจสอบองค์ประกอบ <span class="badge bg-light text-dark ms-1"><?= $counts['check'] ?? 0 ?></span></a></li>
    <li class="nav-item"><a class="nav-link <?= $part === 'quality' ? 'active bg-primary-th' : 'bg-white border' ?>" href="?part=quality">
        <i class="bi bi-ui-checks-grid me-1"></i>ตอนที่ 2 ประเมินคุณภาพ <span class="badge bg-light text-dark ms-1"><?= $counts['quality'] ?? 0 ?></span></a></li>
</ul>

<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขรายการ' : 'เพิ่มรายการ' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="part" value="<?= e($part) ?>">
                    <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="category"><?= $part === 'check' ? 'ส่วน' : 'ด้านการประเมิน' ?> *</label>
                        <input class="form-control<?= isset($errors['category']) ? ' is-invalid' : '' ?>" name="category" id="category" maxlength="150"
                               value="<?= e($fv('category')) ?>" list="catList" placeholder="<?= $part === 'check' ? 'เช่น ส่วนนำ' : 'เช่น ด้านที่ 1 หลักสูตรรายวิชาและการวิเคราะห์หลักสูตร' ?>">
                        <datalist id="catList"><?php foreach ($categories as $cat): ?><option value="<?= e($cat) ?>"><?php endforeach; ?></datalist>
                        <?php if (isset($errors['category'])): ?><div class="invalid-feedback"><?= e($errors['category']) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="title">รายการ *</label>
                        <textarea class="form-control<?= isset($errors['title']) ? ' is-invalid' : '' ?>" name="title" id="title" rows="4" maxlength="500"><?= e($fv('title')) ?></textarea>
                        <?php if (isset($errors['title'])): ?><div class="invalid-feedback"><?= e($errors['title']) ?></div><?php endif; ?>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-4">
                            <label class="form-label" for="sort_order">ลำดับ</label>
                            <input type="number" class="form-control" name="sort_order" id="sort_order" min="0" max="9999" value="<?= e($fv('sort_order', (string)$nextSort)) ?>">
                        </div>
                        <div class="col-8 d-flex flex-column justify-content-end">
                            <?php if ($part === 'check'): ?>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" name="is_required" id="is_required" value="1"
                                        <?= (is_post() ? isset($_POST['is_required']) : (int)($editing['is_required'] ?? 0) === 1) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_required">ต้องมีทุกรายวิชา</label>
                                </div>
                            <?php endif; ?>
                            <div class="form-check form-switch">
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
            <i class="bi bi-info-circle me-1"></i>
            <?php if ($part === 'check'): ?>
                ตอนที่ 1 ผู้ประเมินเลือก มี / ไม่มี / ไม่เกี่ยวข้อง · รายการที่ "ต้องมีทุกรายวิชา" เลือก "ไม่เกี่ยวข้อง" ไม่ได้ และต้อง "มี" ครบจึงจะผ่านเกณฑ์
            <?php else: ?>
                ตอนที่ 2 มาตราส่วน 5 ระดับ: 5 ดีมาก · 4 ดี · 3 พอใช้ · 2 ควรปรับปรุง · 1 ต้องปรับปรุงอย่างยิ่ง<br>
                ผ่านเกณฑ์เมื่อค่าเฉลี่ยรวมตั้งแต่ <?= PLAN_EVAL_PASS_AVG ?> ขึ้นไป · คะแนนเต็ม = จำนวนข้อที่ใช้งาน × 5
            <?php endif; ?>
        </div>
    </div>

    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ol me-2"></i><?= $part === 'check' ? 'ตอนที่ 1 การตรวจสอบองค์ประกอบของแผนการจัดการเรียนรู้' : 'ตอนที่ 2 การประเมินคุณภาพของแผนการจัดการเรียนรู้' ?>
                (<?= count($criteria) ?> รายการ · ใช้งาน <?= count(array_filter($criteria, fn($c) => (int)$c['is_active'] === 1)) ?>)</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th style="width:70px">ลำดับ</th><th><?= $part === 'check' ? 'ส่วน' : 'ด้าน' ?> / รายการ</th><th>ใช้แล้ว</th><th>สถานะ</th><th class="text-end">จัดการ</th></tr></thead>
                    <tbody>
                    <?php $lastCat = null; foreach ($criteria as $c): ?>
                        <?php if ($c['category'] !== $lastCat): $lastCat = $c['category']; ?>
                            <tr class="table-light"><td colspan="5" class="fw-semibold text-primary-th"><?= e($c['category']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="<?= $c['is_active'] ? '' : 'text-muted' ?>">
                            <td class="text-center"><?= (int)$c['sort_order'] ?></td>
                            <td><?= e($c['title']) ?><?= $part === 'check' && $c['is_required'] ? ' <span class="badge bg-light text-dark border fw-normal">ต้องมี</span>' : '' ?></td>
                            <td><?= (int)$c['used'] ?></td>
                            <td><?= $c['is_active'] ? '<span class="badge bg-success">ใช้งาน</span>' : '<span class="badge bg-secondary">ปิด</span>' ?></td>
                            <td class="text-end text-nowrap">
                                <a href="?part=<?= e($part) ?>&amp;edit=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="part" value="<?= e($part) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="เปิด/ปิด"><i class="bi <?= $c['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                                </form>
                                <?php if ((int)$c['used'] === 0): ?>
                                    <form method="post" class="d-inline" data-confirm="ลบรายการนี้?" data-confirm-icon="warning">
                                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="part" value="<?= e($part) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$criteria): ?><tr><td colspan="5" class="empty-state">ยังไม่มีรายการ</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
