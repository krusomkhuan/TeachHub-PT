<?php
/**
 * ไฟล์: admin/download_categories.php
 * จัดการหมวดหมู่เอกสารดาวน์โหลด (เพิ่ม แก้ไขชื่อ เรียงลำดับ เปิด/ปิดใช้งาน ลบ)
 * ลบได้เฉพาะหมวดที่ยังไม่มีเอกสาร — หมวดที่มีเอกสารแล้วให้ปิดใช้งานแทน
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');
    $old = $id ? db_one('SELECT * FROM download_categories WHERE id = ?', 'i', [$id]) : null;

    if ($action === 'save') {
        $name = mb_substr(post('name'), 0, 100);
        $sort = max(0, min(9999, (int)post('sort_order')));
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            $errors['name'] = 'กรุณากรอกชื่อหมวดหมู่';
        } elseif (db_value('SELECT id FROM download_categories WHERE name = ? AND id <> ?', 'si', [$name, $id])) {
            $errors['name'] = 'มีหมวดหมู่ชื่อนี้แล้ว';
        }
        if (!$errors) {
            if ($old) {
                db_exec('UPDATE download_categories SET name = ?, sort_order = ?, is_active = ? WHERE id = ?',
                    'siii', [$name, $sort, $active, $id]);
            } else {
                // รหัสหมวดสร้างอัตโนมัติจาก id (ใช้อ้างอิงใน downloads.category และลิงก์ ?cat=)
                $newId = db_insert('INSERT INTO download_categories (code, name, sort_order, is_active) VALUES (?, ?, ?, ?)',
                    'ssii', ['tmp' . bin2hex(random_bytes(8)), $name, $sort, $active]);
                db_exec('UPDATE download_categories SET code = ? WHERE id = ?', 'si', ['cat' . $newId, $newId]);
            }
            log_activity('download_category_save', ($old ? 'แก้ไข' : 'เพิ่ม') . 'หมวดหมู่เอกสารดาวน์โหลด: ' . $name);
            flash('success', 'บันทึกหมวดหมู่ "' . $name . '" แล้ว');
            redirect('admin/download_categories.php');
        }
    }
    if ($action === 'delete' && $old) {
        $used = (int)db_value('SELECT COUNT(*) FROM downloads WHERE category = ?', 's', [$old['code']]);
        if ($used > 0) {
            flash('error', 'ลบไม่ได้ เพราะมีเอกสาร ' . $used . ' รายการในหมวดนี้ (ย้ายเอกสารไปหมวดอื่น หรือปิดใช้งานหมวดแทน)');
        } else {
            db_exec('DELETE FROM download_categories WHERE id = ?', 'i', [$id]);
            log_activity('download_category_delete', 'ลบหมวดหมู่เอกสารดาวน์โหลด: ' . $old['name']);
            flash('success', 'ลบหมวดหมู่ "' . $old['name'] . '" แล้ว');
        }
        redirect('admin/download_categories.php');
    }
}

$editId = get_int('edit') ?: (int)post('id');
$editing = $editId ? db_one('SELECT * FROM download_categories WHERE id = ?', 'i', [$editId]) : null;
$rows = db_all(
    'SELECT c.*, (SELECT COUNT(*) FROM downloads d WHERE d.category = c.code) AS docs
     FROM download_categories c ORDER BY c.sort_order, c.name'
);
$nextSort = (int)db_value('SELECT COALESCE(MAX(sort_order), 0) FROM download_categories WHERE sort_order < 90') + 10;
$fv = fn(string $k, string $d = '') => is_post() ? post($k) : (string)($editing[$k] ?? $d);

$pageTitle = 'หมวดหมู่เอกสารดาวน์โหลด';
$pageIcon = 'bi-tags';
$breadcrumbs = ['เอกสารดาวน์โหลด' => 'admin/downloads.php', 'หมวดหมู่' => null];
$activeMenu = 'admin/downloads.php';
$pageActions = '<a href="' . e(url('admin/downloads.php')) . '" class="btn btn-outline-primary"><i class="bi bi-cloud-download me-1"></i>เอกสารดาวน์โหลด</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><i class="bi <?= $editing ? 'bi-pencil-square' : 'bi-plus-circle' ?> me-2"></i><?= $editing ? 'แก้ไขหมวดหมู่' : 'เพิ่มหมวดหมู่' ?></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="name">ชื่อหมวดหมู่ *</label>
                            <input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" name="name" id="name" maxlength="100" value="<?= e($fv('name')) ?>" placeholder="เช่น คำสั่ง/ประกาศ, คู่มือ">
                            <?php if (isset($errors['name'])): ?><div class="invalid-feedback"><?= e($errors['name']) ?></div><?php endif; ?>
                        </div>
                        <div class="col-5">
                            <label class="form-label" for="sort_order">ลำดับ</label>
                            <input type="number" class="form-control" name="sort_order" id="sort_order" min="0" max="9999" value="<?= e($fv('sort_order', (string)$nextSort)) ?>">
                        </div>
                        <div class="col-7 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" <?= (is_post() ? isset($_POST['is_active']) : (int)($editing['is_active'] ?? 1) === 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_active">เปิดใช้งาน</label>
                            </div>
                        </div>
                    </div>
                    <div class="form-text">ลำดับน้อยแสดงก่อน · ปิดใช้งาน = ซ่อนแท็บหมวดในหน้าดาวน์โหลดและเลือกให้เอกสารใหม่ไม่ได้</div>
                    <div class="d-flex gap-2 mt-3">
                        <?php if ($editing): ?><a href="<?= e(url('admin/download_categories.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a><?php endif; ?>
                        <button class="btn btn-accent flex-fill"><i class="bi bi-save me-1"></i> บันทึก</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-xl-8">
        <div class="card">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>หมวดหมู่ (<?= count($rows) ?>)</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead><tr><th style="width:70px">ลำดับ</th><th>ชื่อหมวดหมู่</th><th>เอกสาร</th><th>สถานะ</th><th class="text-end"></th></tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
                                <td><?= (int)$r['sort_order'] ?></td>
                                <td><?= e($r['name']) ?></td>
                                <td><a href="<?= e(url('public/downloads.php?cat=' . rawurlencode($r['code']))) ?>" target="_blank"><?= (int)$r['docs'] ?></a></td>
                                <td><?= $r['is_active'] ? '<span class="badge bg-success">เปิด</span>' : '<span class="badge bg-secondary">ปิด</span>' ?></td>
                                <td class="text-end text-nowrap">
                                    <a href="?edit=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                    <?php if ((int)$r['docs'] === 0): ?>
                                        <form method="post" class="d-inline" data-confirm="ลบหมวดหมู่ <?= e($r['name']) ?>?" data-confirm-icon="warning">
                                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-secondary" disabled title="มีเอกสารในหมวดนี้ ลบไม่ได้ (ปิดใช้งานแทน)"><i class="bi bi-trash"></i></button>
                                    <?php endif; ?>
                                </td>
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
