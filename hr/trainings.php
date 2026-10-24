<?php
/**
 * ไฟล์: hr/trainings.php
 * การพัฒนาตนเอง/อบรมของฉัน — บันทึกการอบรม สัมมนา ศึกษาดูงาน แนบเกียรติบัตร และสรุปชั่วโมงพัฒนารายปีงบประมาณ
 *   (ไม่มี) รายการ ?fy=    ?new=1 บันทึกใหม่    ?edit=xx แก้ไข (เฉพาะ "รอตรวจ" / "ให้แก้ไข")
 * งานบุคลากรตรวจที่ hr/trainings_review.php — นับชั่วโมงเฉพาะรายการที่ตรวจแล้ว (ผ่าน)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$self = 'hr/trainings.php';
$activeMenu = $self;
$errors = [];

/** รายการอบรมของผู้ใช้คนนี้ที่ยังแก้ไขได้ */
function own_training(int $id, int $uid): ?array
{
    $t = db_one('SELECT * FROM hr_trainings WHERE id = ? AND user_id = ?', 'ii', [$id, $uid]);
    return ($t && $t['status'] !== 'approved') ? $t : null;
}

if (is_post()) {
    csrf_verify();
    $action = post('action');
    if ($action === 'delete') {
        $t = own_training((int)post('id'), $uid);
        if (!$t) {
            abort_page(403, 'ลบรายการนี้ไม่ได้', 'รายการที่ตรวจผ่านแล้วลบไม่ได้ ติดต่องานบุคลากร');
        }
        db_exec('DELETE FROM hr_trainings WHERE id = ?', 'i', [(int)$t['id']]);
        delete_upload(HR_UPLOAD_DIR, $t['cert_stored']);
        log_activity('hr_training_delete', 'ลบรายการอบรม: ' . $t['title']);
        flash('success', 'ลบรายการแล้ว');
        redirect($self . '?fy=' . (int)$t['fiscal_year']);
    }
    $editId = (int)post('id');
    $old = $editId > 0 ? own_training($editId, $uid) : null;
    if ($editId > 0 && !$old) {
        abort_page(403, 'แก้ไขรายการนี้ไม่ได้');
    }
    $d = [
        'category'   => post('category'),
        'title'      => mb_substr(post('title'), 0, 300),
        'organizer'  => mb_substr(post('organizer'), 0, 200),
        'location'   => mb_substr(post('location'), 0, 200),
        'start_date' => post('start_date'),
        'end_date'   => post('end_date') ?: post('start_date'),
        'hours'      => round((float)post('hours'), 1),
        'detail'     => mb_substr(post('detail'), 0, 2000),
    ];
    if (!in_array($d['category'], hr_training_categories(), true)) {
        $errors['category'] = 'กรุณาเลือกประเภท';
    }
    if ($d['title'] === '') {
        $errors['title'] = 'กรุณาระบุชื่อเรื่อง/หลักสูตร';
    }
    if (!hr_valid_date($d['start_date']) || $d['start_date'] > date('Y-m-d')) {
        $errors['start_date'] = 'กรุณาเลือกวันที่ (ไม่เกินวันนี้)';
    } elseif (!hr_valid_date($d['end_date']) || $d['end_date'] < $d['start_date']) {
        $errors['end_date'] = 'วันที่สิ้นสุดต้องไม่ก่อนวันเริ่ม';
    }
    if ($d['hours'] <= 0 || $d['hours'] > 1000) {
        $errors['hours'] = 'จำนวนชั่วโมง 0.5 – 1000';
    }
    $up = hr_take_upload('certificate');
    if (!$up['ok']) {
        $errors['certificate'] = $up['error'];
    }
    if ($errors) {
        if (!empty($up['stored_name'])) {
            delete_upload(HR_UPLOAD_DIR, $up['stored_name']);
        }
        $_GET[$old ? 'edit' : 'new'] = $old ? (string)$old['id'] : '1';
    } else {
        $fy = hr_fiscal_year_of($d['start_date']);
        if ($old) {
            $cert = $up['stored_name'] ? [$up['stored_name'], $up['original_name']] : [$old['cert_stored'], $old['cert_original']];
            db_exec(
                "UPDATE hr_trainings SET fiscal_year = ?, category = ?, title = ?, organizer = ?, location = ?, start_date = ?, end_date = ?, hours = ?,
                        detail = ?, cert_stored = ?, cert_original = ?, status = 'pending' WHERE id = ?",
                'issssssdsssi',
                [$fy, $d['category'], $d['title'], $d['organizer'], $d['location'], $d['start_date'], $d['end_date'], $d['hours'], $d['detail'],
                    $cert[0], $cert[1], (int)$old['id']]
            );
            if ($up['stored_name'] && $old['cert_stored']) {
                delete_upload(HR_UPLOAD_DIR, $old['cert_stored']);
            }
        } else {
            db_insert(
                'INSERT INTO hr_trainings (user_id, fiscal_year, category, title, organizer, location, start_date, end_date, hours, detail, cert_stored, cert_original)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'iissssssdsss',
                [$uid, $fy, $d['category'], $d['title'], $d['organizer'], $d['location'], $d['start_date'], $d['end_date'], $d['hours'], $d['detail'],
                    $up['stored_name'], $up['original_name']]
            );
        }
        log_activity('hr_training_save', ($old ? 'แก้ไข' : 'บันทึก') . 'การอบรม: ' . $d['title'] . ' (' . hr_days($d['hours']) . ' ชม.)');
        flash('success', 'บันทึกเรียบร้อยแล้ว — รองานบุคลากรตรวจ');
        redirect($self . '?fy=' . $fy);
    }
}

// =====================================================================
// แบบฟอร์ม (?new=1 / ?edit=xx)
// =====================================================================
$editId = get_int('edit');
if (get('new') === '1' || $editId > 0) {
    $t = $editId > 0 ? own_training($editId, $uid) : null;
    if ($editId > 0 && !$t) {
        abort_page(404, 'ไม่พบรายการ', 'รายการที่ตรวจผ่านแล้วแก้ไขไม่ได้');
    }
    $val = fn(string $k) => is_post() ? post($k) : (string)($t[$k] ?? '');
    $err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
    $inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
    $pageTitle = $t ? 'แก้ไขการพัฒนาตนเอง/อบรม' : 'บันทึกการพัฒนาตนเอง/อบรม';
    $pageIcon = 'bi-mortarboard';
    $breadcrumbs = ['พัฒนาตนเอง/อบรม' => $self, $t ? 'แก้ไข' : 'บันทึกใหม่' => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <form method="post" enctype="multipart/form-data" novalidate class="card" style="max-width:900px">
        <div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
            <?php if ($t && $t['status'] === 'revision' && $t['review_comment'] !== ''): ?>
                <div class="alert alert-danger small"><b>งานบุคลากรให้แก้ไข:</b> <?= e($t['review_comment']) ?></div>
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="category">ประเภท <span class="text-danger">*</span></label>
                    <select class="form-select<?= $inv('category') ?>" name="category" id="category" required>
                        <option value="">-- เลือก --</option>
                        <?php foreach (hr_training_categories() as $c): ?><option <?= $val('category') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
                    </select><?= $err('category') ?>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="title">ชื่อเรื่อง/หลักสูตร <span class="text-danger">*</span></label>
                    <input class="form-control<?= $inv('title') ?>" name="title" id="title" maxlength="300" value="<?= e($val('title')) ?>" required><?= $err('title') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="organizer">หน่วยงานที่จัด</label>
                    <input class="form-control" name="organizer" id="organizer" maxlength="200" value="<?= e($val('organizer')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="location">สถานที่ / รูปแบบ</label>
                    <input class="form-control" name="location" id="location" maxlength="200" value="<?= e($val('location')) ?>" placeholder="เช่น โรงแรม... / ออนไลน์ (Zoom)">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="start_date">ตั้งแต่วันที่ <span class="text-danger">*</span></label>
                    <input type="date" class="form-control<?= $inv('start_date') ?>" name="start_date" id="start_date" value="<?= e($val('start_date')) ?>" max="<?= date('Y-m-d') ?>" required><?= $err('start_date') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="end_date">ถึงวันที่</label>
                    <input type="date" class="form-control<?= $inv('end_date') ?>" name="end_date" id="end_date" value="<?= e($val('end_date')) ?>" max="<?= date('Y-m-d') ?>"><?= $err('end_date') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="hours">จำนวนชั่วโมง <span class="text-danger">*</span></label>
                    <input type="number" class="form-control<?= $inv('hours') ?>" name="hours" id="hours" step="0.5" min="0.5" max="1000" value="<?= e($val('hours') !== '' ? hr_days((float)$val('hours')) : '') ?>" required><?= $err('hours') ?>
                </div>
                <div class="col-12">
                    <label class="form-label" for="detail">สิ่งที่ได้รับ / การนำไปใช้</label>
                    <textarea class="form-control" name="detail" id="detail" rows="3" maxlength="2000"><?= e($val('detail')) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label" for="certificate">เกียรติบัตร/หลักฐาน (PDF หรือรูปภาพ)</label>
                    <input type="file" class="form-control<?= $inv('certificate') ?>" name="certificate" id="certificate" accept=".pdf,.jpg,.jpeg,.png,.webp"><?= $err('certificate') ?>
                    <?php if (!empty($t['cert_stored'])): ?>
                        <div class="form-text">ไฟล์เดิม: <a href="<?= e(hr_file_url('training', (int)$t['id'])) ?>" target="_blank" rel="noopener"><?= e($t['cert_original']) ?></a> (เลือกไฟล์ใหม่เพื่อแทนที่)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            <button class="btn btn-accent ms-auto" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึก</button>
        </div>
    </form>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// รายการ
// =====================================================================
$fy = hr_fiscal_param();
$rows = db_all('SELECT * FROM hr_trainings WHERE user_id = ? AND fiscal_year = ? ORDER BY start_date DESC, id DESC', 'ii', [$uid, $fy]);
$sum = ['approved' => 0.0, 'pending' => 0.0, 'revision' => 0.0];
foreach ($rows as $r) {
    $sum[$r['status']] += (float)$r['hours'];
}

$pageTitle = 'การพัฒนาตนเอง/อบรม';
$pageIcon = 'bi-mortarboard';
$breadcrumbs = ['พัฒนาตนเอง/อบรม' => null];
$pageActions = '<a href="' . e(url($self . '?new=1')) . '" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> บันทึกการอบรม</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-award"></i></div>
            <div><div class="stat-value"><?= hr_days($sum['approved']) ?></div><div class="stat-label">ชั่วโมงพัฒนา (ตรวจแล้ว)</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-yellow"><i class="bi bi-hourglass-split"></i></div>
            <div><div class="stat-value"><?= hr_days($sum['pending']) ?></div><div class="stat-label">ชั่วโมงรอตรวจ</div></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-list-check"></i></div>
            <div><div class="stat-value"><?= count($rows) ?></div><div class="stat-label">รายการทั้งหมด</div></div></div></div>
    <div class="col-sm-6 col-xl-3">
        <form method="get" class="stat-card">
            <div class="stat-icon ic-blue"><i class="bi bi-calendar3"></i></div>
            <div class="flex-fill"><label class="stat-label d-block" for="fy">ปีงบประมาณ</label>
                <select name="fy" id="fy" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                </select></div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>วันที่</th><th>เรื่อง/หลักสูตร</th><th>ประเภท</th><th class="text-end">ชั่วโมง</th><th>หลักฐาน</th><th>สถานะ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap" data-order="<?= e($r['start_date']) ?>"><?= e(thai_date($r['start_date'])) ?><?= $r['end_date'] !== $r['start_date'] ? ' – ' . e(thai_date($r['end_date'])) : '' ?></td>
                        <td><?= e($r['title']) ?><div class="small text-muted"><?= e($r['organizer']) ?></div>
                            <?php if ($r['status'] === 'revision' && $r['review_comment'] !== ''): ?><div class="small text-danger">แก้ไข: <?= e($r['review_comment']) ?></div><?php endif; ?></td>
                        <td class="small"><?= e($r['category']) ?></td>
                        <td class="text-end"><?= hr_days((float)$r['hours']) ?></td>
                        <td><?php if ($r['cert_stored']): ?><a href="<?= e(hr_file_url('training', (int)$r['id'])) ?>" target="_blank" rel="noopener" title="<?= e($r['cert_original']) ?>"><i class="bi <?= e(file_icon($r['cert_original'])) ?> fs-5"></i></a><?php else: ?>-<?php endif; ?></td>
                        <td data-order="<?= e($r['status']) ?>"><?= status_badge($r['status']) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($r['status'] !== 'approved'): ?>
                                <a href="<?= e(url($self . '?edit=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                                <form method="post" class="d-inline" data-confirm="ลบรายการ “<?= e($r['title']) ?>”?" data-confirm-icon="warning">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="ลบ"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
