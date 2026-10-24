<?php
/**
 * ไฟล์: hr/orders_manage.php
 * คำสั่ง/หนังสือราชการ (ผู้ดูแลงานบุคลากร) — บันทึกเลขที่คำสั่ง แนบไฟล์ และระบุบุคลากรที่มีชื่อในคำสั่ง
 *   ?new=1 เพิ่ม   ?edit=xx แก้ไข   ?fy= ?cat= ตัวกรอง
 * บุคลากรเห็นคำสั่งที่มีชื่อตนเอง และคำสั่งที่ตั้งค่า "เผยแพร่ให้ทุกคน" ที่ hr/orders.php
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_manage_hr($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ดูแลงานบุคลากร');
}
$uid = (int)$user['id'];
$self = 'hr/orders_manage.php';
$activeMenu = $self;
$errors = [];

if (is_post()) {
    csrf_verify();
    $id = (int)post('id');
    $old = $id > 0 ? db_one('SELECT * FROM hr_orders WHERE id = ?', 'i', [$id]) : null;
    if ($id > 0 && !$old) {
        abort_page(404, 'ไม่พบคำสั่ง');
    }
    if (post('action') === 'delete' && $old) {
        db_exec('DELETE FROM hr_orders WHERE id = ?', 'i', [$id]);
        delete_upload(HR_UPLOAD_DIR, $old['file_stored']);
        log_activity('hr_order_delete', 'ลบคำสั่งที่ ' . $old['order_no'] . ' ' . $old['title']);
        flash('success', 'ลบคำสั่งแล้ว');
        redirect($self);
    }
    $d = [
        'order_no'   => mb_substr(post('order_no'), 0, 50),
        'order_date' => post('order_date'),
        'category'   => post('category'),
        'title'      => mb_substr(post('title'), 0, 300),
        'detail'     => mb_substr(post('detail'), 0, 2000),
        'is_public'  => post('is_public') === '1' ? 1 : 0,
    ];
    $people = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['people'] ?? [])))));
    if ($d['order_no'] === '') {
        $errors['order_no'] = 'กรุณาระบุเลขที่คำสั่ง';
    }
    if (!hr_valid_date($d['order_date'])) {
        $errors['order_date'] = 'กรุณาเลือกวันที่สั่ง';
    }
    if (!in_array($d['category'], hr_order_categories(), true)) {
        $errors['category'] = 'กรุณาเลือกประเภทคำสั่ง';
    }
    if ($d['title'] === '') {
        $errors['title'] = 'กรุณาระบุเรื่อง';
    }
    if ($people) {
        $valid = array_map('intval', array_column(hr_people(), 'id'));
        $people = array_values(array_intersect($people, $valid));
    }
    $up = hr_take_upload('order_file');
    if (!$up['ok']) {
        $errors['order_file'] = $up['error'];
    }
    if ($errors) {
        if (!empty($up['stored_name'])) {
            delete_upload(HR_UPLOAD_DIR, $up['stored_name']);
        }
        $_GET[$old ? 'edit' : 'new'] = $old ? (string)$id : '1';
    } else {
        if ($old) {
            $file = $up['stored_name'] ? [$up['stored_name'], $up['original_name']] : [$old['file_stored'], $old['file_original']];
            db_exec('UPDATE hr_orders SET order_no = ?, order_date = ?, category = ?, title = ?, detail = ?, is_public = ?, file_stored = ?, file_original = ? WHERE id = ?',
                'sssssissi', [$d['order_no'], $d['order_date'], $d['category'], $d['title'], $d['detail'], $d['is_public'], $file[0], $file[1], $id]);
            if ($up['stored_name'] && $old['file_stored']) {
                delete_upload(HR_UPLOAD_DIR, $old['file_stored']);
            }
            db_exec('DELETE FROM hr_order_users WHERE order_id = ?', 'i', [$id]);
        } else {
            $id = db_insert('INSERT INTO hr_orders (order_no, order_date, category, title, detail, is_public, file_stored, file_original, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'sssssissi', [$d['order_no'], $d['order_date'], $d['category'], $d['title'], $d['detail'], $d['is_public'], $up['stored_name'], $up['original_name'], $uid]);
        }
        foreach ($people as $p) {
            db_exec('INSERT INTO hr_order_users (order_id, user_id) VALUES (?, ?)', 'ii', [$id, $p]);
        }
        log_activity('hr_order_save', ($old ? 'แก้ไข' : 'บันทึก') . 'คำสั่งที่ ' . $d['order_no'] . ' ' . $d['title'] . ' (' . count($people) . ' คน)');
        flash('success', 'บันทึกคำสั่งเรียบร้อยแล้ว');
        redirect($self);
    }
}

// =====================================================================
// แบบฟอร์ม
// =====================================================================
$editId = get_int('edit');
if (get('new') === '1' || $editId > 0) {
    $o = $editId > 0 ? db_one('SELECT * FROM hr_orders WHERE id = ?', 'i', [$editId]) : null;
    if ($editId > 0 && !$o) {
        abort_page(404, 'ไม่พบคำสั่ง');
    }
    $selected = is_post() ? array_map('intval', (array)($_POST['people'] ?? []))
        : ($o ? array_map('intval', array_column(db_all('SELECT user_id FROM hr_order_users WHERE order_id = ?', 'i', [$editId]), 'user_id')) : []);
    $people = hr_people();
    $val = fn(string $k, string $def = '') => is_post() ? post($k) : (string)($o[$k] ?? $def);
    $err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
    $inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
    $pageTitle = $o ? 'แก้ไขคำสั่ง' : 'เพิ่มคำสั่ง';
    $pageIcon = 'bi-file-earmark-text';
    $breadcrumbs = ['คำสั่ง/หนังสือราชการ' => $self, $pageTitle => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <form method="post" enctype="multipart/form-data" novalidate class="row g-4">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($o['id'] ?? 0) ?>">
        <div class="col-xl-7">
            <div class="card">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="order_no">เลขที่คำสั่ง <span class="text-danger">*</span></label>
                            <input class="form-control<?= $inv('order_no') ?>" name="order_no" id="order_no" maxlength="50" placeholder="เช่น 123/2569" value="<?= e($val('order_no')) ?>" required><?= $err('order_no') ?></div>
                        <div class="col-md-4"><label class="form-label" for="order_date">วันที่สั่ง <span class="text-danger">*</span></label>
                            <input type="date" class="form-control<?= $inv('order_date') ?>" name="order_date" id="order_date" value="<?= e($val('order_date', date('Y-m-d'))) ?>" required><?= $err('order_date') ?></div>
                        <div class="col-md-4"><label class="form-label" for="category">ประเภท <span class="text-danger">*</span></label>
                            <select class="form-select<?= $inv('category') ?>" name="category" id="category" required><option value="">-- เลือก --</option>
                                <?php foreach (hr_order_categories() as $c): ?><option <?= $val('category') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
                            </select><?= $err('category') ?></div>
                        <div class="col-12"><label class="form-label" for="title">เรื่อง <span class="text-danger">*</span></label>
                            <input class="form-control<?= $inv('title') ?>" name="title" id="title" maxlength="300" value="<?= e($val('title')) ?>" required><?= $err('title') ?></div>
                        <div class="col-12"><label class="form-label" for="detail">รายละเอียด/หมายเหตุ</label>
                            <textarea class="form-control" name="detail" id="detail" rows="3" maxlength="2000"><?= e($val('detail')) ?></textarea></div>
                        <div class="col-12"><label class="form-label" for="order_file">ไฟล์คำสั่ง (PDF หรือรูปภาพ)</label>
                            <input type="file" class="form-control<?= $inv('order_file') ?>" name="order_file" id="order_file" accept=".pdf,.jpg,.jpeg,.png,.webp"><?= $err('order_file') ?>
                            <?php if (!empty($o['file_stored'])): ?><div class="form-text">ไฟล์เดิม: <a href="<?= e(hr_file_url('order', (int)$o['id'])) ?>" target="_blank" rel="noopener"><?= e($o['file_original']) ?></a> (เลือกไฟล์ใหม่เพื่อแทนที่)</div><?php endif; ?></div>
                        <div class="col-12"><div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_public" id="is_public" value="1" <?= $val('is_public') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="is_public">เผยแพร่ให้บุคลากรทุกคนเห็น (ไม่ติ๊ก = เห็นเฉพาะผู้มีชื่อในคำสั่ง)</label></div></div>
                    </div>
                </div>
                <div class="card-footer d-flex gap-2">
                    <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <button class="btn btn-accent ms-auto" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกคำสั่ง</button>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header d-flex align-items-center"><i class="bi bi-people me-2"></i>บุคลากรที่มีชื่อในคำสั่ง
                    <span class="badge bg-primary ms-2" id="pickCount"><?= count($selected) ?></span></div>
                <div class="card-body">
                    <div class="d-flex gap-2 mb-2">
                        <input type="search" class="form-control form-control-sm" id="pickFilter" placeholder="ค้นหาชื่อ / แผนก">
                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" id="pickAll">เลือกที่แสดง</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary text-nowrap" id="pickNone">ล้าง</button>
                    </div>
                    <div class="border rounded p-2" style="max-height:420px;overflow:auto" id="pickList">
                        <?php foreach ($people as $pp): $pid = (int)$pp['id']; ?>
                            <div class="form-check" data-text="<?= e($pp['full_name'] . ' ' . $pp['department_name'] . ' ' . $pp['position']) ?>">
                                <input class="form-check-input" type="checkbox" name="people[]" value="<?= $pid ?>" id="pp<?= $pid ?>" <?= in_array($pid, $selected, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pp<?= $pid ?>"><?= e($pp['full_name']) ?>
                                    <span class="small text-muted"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$pp['department_name']) ?: $pp['position']) ?></span></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php
    $pageScripts = '
(function () {
    var list = document.getElementById("pickList"), f = document.getElementById("pickFilter"), c = document.getElementById("pickCount");
    function count() { c.textContent = list.querySelectorAll("input:checked").length; }
    f.addEventListener("input", function () {
        var q = f.value.trim().toLowerCase();
        list.querySelectorAll(".form-check").forEach(function (el) { el.style.display = el.dataset.text.toLowerCase().indexOf(q) >= 0 ? "" : "none"; });
    });
    document.getElementById("pickAll").addEventListener("click", function () {
        list.querySelectorAll(".form-check").forEach(function (el) { if (el.style.display !== "none") el.querySelector("input").checked = true; }); count();
    });
    document.getElementById("pickNone").addEventListener("click", function () { list.querySelectorAll("input").forEach(function (i) { i.checked = false; }); count(); });
    list.addEventListener("change", count);
})();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// รายการ
// =====================================================================
$fy = hr_fiscal_param();
[$from, $to] = hr_fiscal_range($fy);
$fCat = in_array(get('cat'), hr_order_categories(), true) ? get('cat') : '';
$rows = db_all(
    'SELECT o.*, (SELECT COUNT(*) FROM hr_order_users ou WHERE ou.order_id = o.id) AS people
     FROM hr_orders o WHERE o.order_date BETWEEN ? AND ?' . ($fCat !== '' ? ' AND o.category = ?' : '') . ' ORDER BY o.order_date DESC, o.id DESC',
    $fCat !== '' ? 'sss' : 'ss',
    $fCat !== '' ? [$from, $to, $fCat] : [$from, $to]
);

$pageTitle = 'คำสั่ง/หนังสือราชการ';
$pageIcon = 'bi-file-earmark-text';
$breadcrumbs = ['คำสั่ง/หนังสือราชการ' => null];
$pageActions = '<a href="' . e(url($self . '?new=1')) . '" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> เพิ่มคำสั่ง</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small mb-1" for="fy">ปีงบประมาณ</label>
                <select name="fy" id="fy" class="form-select"><?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
            <div class="col-md-5"><label class="form-label small mb-1" for="cat">ประเภท</label>
                <select name="cat" id="cat" class="form-select"><option value="">ทั้งหมด</option>
                    <?php foreach (hr_order_categories() as $c): ?><option <?= $fCat === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> กรอง</button></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable" data-order="[]">
                <thead><tr><th>เลขที่</th><th>วันที่</th><th>เรื่อง</th><th>ประเภท</th><th class="text-center">บุคลากร</th><th>การเผยแพร่</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap"><?= e($r['order_no']) ?></td>
                        <td class="text-nowrap" data-order="<?= e($r['order_date']) ?>"><?= e(thai_date($r['order_date'])) ?></td>
                        <td><?= e($r['title']) ?>
                            <?php if ($r['file_stored']): ?><a href="<?= e(hr_file_url('order', (int)$r['id'])) ?>" target="_blank" rel="noopener" class="ms-1"><i class="bi <?= e(file_icon($r['file_original'])) ?>"></i></a><?php endif; ?></td>
                        <td class="small"><?= e($r['category']) ?></td>
                        <td class="text-center"><?= (int)$r['people'] ?></td>
                        <td><?= (int)$r['is_public'] ? '<span class="badge bg-success">ทุกคน</span>' : '<span class="badge bg-light text-dark border">เฉพาะผู้มีชื่อ</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url($self . '?edit=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline" data-confirm="ลบคำสั่งที่ <?= e($r['order_no']) ?>?" data-confirm-icon="warning">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
