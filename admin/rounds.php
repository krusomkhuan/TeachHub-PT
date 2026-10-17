<?php
/**
 * ไฟล์: admin/rounds.php
 * จัดการ "รอบการส่งงาน" (กำหนดเปิด-ปิด) ของทุกประเภทงาน
 *   ?new=1        = ฟอร์มสร้างรอบใหม่
 *   ?edit=id      = ฟอร์มแก้ไขรอบ
 *   ?type=xxx     = กรองตามประเภท
 * การเปิด-ปิดรับตรวจฝั่งเซิร์ฟเวอร์ด้วย round_state() ทุกครั้งที่ครูส่งงาน
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('admin');
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$yearIds = array_map('intval', array_column($years, 'id'));
$errors = [];

// =====================================================================
// POST: บันทึก / เปิด-ปิดใช้งาน / ลบ
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');
    $id = (int)post('id');

    if ($action === 'toggle' || $action === 'delete') {
        $round = db_one('SELECT * FROM submission_rounds WHERE id = ?', 'i', [$id]);
        if (!$round) {
            flash('error', 'ไม่พบรอบการส่ง');
            redirect('admin/rounds.php');
        }
        if ($action === 'toggle') {
            db_exec('UPDATE submission_rounds SET is_active = 1 - is_active WHERE id = ?', 'i', [$id]);
            log_activity('round_toggle', ($round['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน') . 'รอบ: ' . $round['name']);
            flash('success', ($round['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน') . 'รอบ "' . $round['name'] . '" แล้ว');
        } else {
            $table = submission_table($round['type']);
            $used = (int)db_value("SELECT COUNT(*) FROM `$table` WHERE round_id = ?", 'i', [$id]);
            if ($used > 0) {
                flash('error', 'ลบไม่ได้ เพราะมีงานที่ส่งในรอบนี้แล้ว ' . $used . ' รายการ (ใช้ "ปิดใช้งาน" แทน)');
            } else {
                db_exec('DELETE FROM submission_rounds WHERE id = ?', 'i', [$id]);
                log_activity('round_delete', 'ลบรอบ: ' . $round['name']);
                flash('success', 'ลบรอบ "' . $round['name'] . '" แล้ว');
            }
        }
        redirect('admin/rounds.php');
    }

    if ($action === 'save') {
        $data = [
            'type'             => post('type'),
            'phase'            => post('phase'),
            'name'             => mb_substr(post('name'), 0, 200),
            'academic_year_id' => (int)post('academic_year_id'),
            'fiscal_year'      => (int)post('fiscal_year'),
            'description'      => mb_substr(post('description'), 0, 2000),
            'open_at'          => post('open_at'),
            'close_at'         => post('close_at'),
            'allow_late'       => isset($_POST['allow_late']) ? 1 : 0,
            'is_active'        => isset($_POST['is_active']) ? 1 : 0,
        ];

        // ---------- ตรวจสอบ ----------
        if (!in_array($data['type'], submission_types(), true)) {
            $errors['type'] = 'กรุณาเลือกประเภทงาน';
        } elseif (!in_array($data['phase'], round_phase_options($data['type']), true)) {
            $errors['phase'] = 'ขั้นตอนไม่ตรงกับประเภทงาน';
        }
        if ($data['name'] === '') {
            $errors['name'] = 'กรุณากรอกชื่อรอบ';
        }
        if (!in_array($data['academic_year_id'], $yearIds, true)) {
            $errors['academic_year_id'] = 'กรุณาเลือกภาคเรียน/ปีการศึกษา';
        }
        if ($data['type'] === 'pa' && ($data['fiscal_year'] < 2500 || $data['fiscal_year'] > 2700)) {
            $errors['fiscal_year'] = 'กรุณากรอกปีงบประมาณ (พ.ศ.) เช่น ' . current_fiscal_year();
        }
        $openTs = strtotime(str_replace('T', ' ', $data['open_at']));
        $closeTs = strtotime(str_replace('T', ' ', $data['close_at']));
        if (!$openTs) {
            $errors['open_at'] = 'กรุณาระบุวันเวลาเปิดรับ';
        }
        if (!$closeTs) {
            $errors['close_at'] = 'กรุณาระบุวันเวลาปิดรับ';
        } elseif ($openTs && $closeTs <= $openTs) {
            $errors['close_at'] = 'วันเวลาปิดรับต้องอยู่หลังวันเวลาเปิดรับ';
        }
        // แก้ไขรอบที่มีงานแล้ว ห้ามเปลี่ยนประเภทงาน
        $old = $id ? db_one('SELECT * FROM submission_rounds WHERE id = ?', 'i', [$id]) : null;
        if ($id && !$old) {
            flash('error', 'ไม่พบรอบการส่ง');
            redirect('admin/rounds.php');
        }
        if ($old && $old['type'] !== $data['type']) {
            $table = submission_table($old['type']);
            if ((int)db_value("SELECT COUNT(*) FROM `$table` WHERE round_id = ?", 'i', [$id]) > 0) {
                $errors['type'] = 'รอบนี้มีงานส่งแล้ว ไม่สามารถเปลี่ยนประเภทงานได้';
            }
        }

        if (!$errors) {
            $openAt = date('Y-m-d H:i:s', $openTs);
            $closeAt = date('Y-m-d H:i:s', $closeTs);
            $fiscal = $data['type'] === 'pa' ? $data['fiscal_year'] : null;
            if ($old) {
                db_exec(
                    'UPDATE submission_rounds SET type = ?, phase = ?, name = ?, academic_year_id = ?, fiscal_year = ?,
                            description = ?, open_at = ?, close_at = ?, allow_late = ?, is_active = ? WHERE id = ?',
                    'sssiisssiii',
                    [$data['type'], $data['phase'], $data['name'], $data['academic_year_id'], $fiscal, $data['description'],
                        $openAt, $closeAt, $data['allow_late'], $data['is_active'], $id]
                );
                log_activity('round_update', 'แก้ไขรอบ: ' . $data['name']);
                flash('success', 'บันทึกการแก้ไขรอบ "' . $data['name'] . '" แล้ว');
            } else {
                db_insert(
                    'INSERT INTO submission_rounds (type, phase, name, academic_year_id, fiscal_year, description,
                            open_at, close_at, allow_late, is_active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'sssiisssiii',
                    [$data['type'], $data['phase'], $data['name'], $data['academic_year_id'], $fiscal, $data['description'],
                        $openAt, $closeAt, $data['allow_late'], $data['is_active'], (int)$user['id']]
                );
                log_activity('round_create', 'สร้างรอบ: ' . $data['name']);
                flash('success', 'สร้างรอบ "' . $data['name'] . '" แล้ว');
            }
            redirect('admin/rounds.php');
        }
    }
}

// =====================================================================
// ข้อมูลสำหรับแสดงผล
// =====================================================================
$editId = get_int('edit');
$showForm = $editId > 0 || get('new') === '1' || $errors;
$editing = $editId ? db_one('SELECT * FROM submission_rounds WHERE id = ?', 'i', [$editId]) : null;
if ($editId && !$editing) {
    abort_page(404, 'ไม่พบรอบการส่ง');
}

$fType = get('type');
if (!in_array($fType, submission_types(), true)) {
    $fType = '';
}
$rounds = db_all(
    "SELECT r.*, ay.year, ay.semester,
        CASE r.type
          WHEN 'lesson_plan' THEN (SELECT COUNT(*) FROM lesson_plans x WHERE x.round_id = r.id)
          WHEN 'pa' THEN (SELECT COUNT(*) FROM pa_submissions x WHERE x.round_id = r.id)
          ELSE (SELECT COUNT(*) FROM research_submissions x WHERE x.round_id = r.id)
        END AS submissions
     FROM submission_rounds r LEFT JOIN academic_years ay ON ay.id = r.academic_year_id
     WHERE (? = '' OR r.type = ?)
     ORDER BY r.close_at DESC",
    'ss',
    [$fType, $fType]
);

/** ค่าในฟอร์ม */
$fv = function (string $k, string $default = '') use ($editing) {
    if (is_post()) {
        return post($k);
    }
    if ($editing) {
        $v = (string)($editing[$k] ?? '');
        if (in_array($k, ['open_at', 'close_at'], true) && $v !== '') {
            return date('Y-m-d\TH:i', strtotime($v));
        }
        return $v;
    }
    return $default;
};
$fChecked = function (string $k, bool $default) use ($editing): string {
    if (is_post()) {
        return isset($_POST[$k]) ? 'checked' : '';
    }
    if ($editing) {
        return $editing[$k] ? 'checked' : '';
    }
    return $default ? 'checked' : '';
};
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$current = current_academic_year();

$pageTitle = 'รอบการส่งงาน';
$pageIcon = 'bi-hourglass-split';
$breadcrumbs = ['รอบการส่งงาน' => null];
$pageActions = $showForm ? '' : '<a href="' . e(url('admin/rounds.php?new=1')) . '" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i>สร้างรอบใหม่</a>';
require APP_ROOT . '/includes/header.php';
?>

<?php if ($showForm): ?>
    <div class="card mb-4 border-start border-4 border-warning">
        <div class="card-header"><i class="bi bi-pencil-square me-2"></i><?= $editing ? 'แก้ไขรอบ: ' . e($editing['name']) : 'สร้างรอบการส่งงานใหม่' ?></div>
        <div class="card-body">
            <form method="post" action="<?= e(url('admin/rounds.php' . ($editing ? '?edit=' . (int)$editing['id'] : '?new=1'))) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="type">ประเภทงาน *</label>
                        <select class="form-select<?= $invalid('type') ?>" name="type" id="type" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach (submission_types() as $t): ?>
                                <option value="<?= e($t) ?>" <?= $fv('type') === $t ? 'selected' : '' ?>><?= e(submission_type_label($t)) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('type') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="phase">ขั้นตอน/รอบย่อย *</label>
                        <select class="form-select<?= $invalid('phase') ?>" name="phase" id="phase" data-value="<?= e($fv('phase', 'main')) ?>" required></select>
                        <?= $err('phase') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="academic_year_id">ภาคเรียน/ปีการศึกษา *</label>
                        <select class="form-select<?= $invalid('academic_year_id') ?>" name="academic_year_id" id="academic_year_id" required>
                            <option value="">-- เลือก --</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y['id'] ?>" <?= (int)$fv('academic_year_id', (string)($current['id'] ?? '')) === (int)$y['id'] ? 'selected' : '' ?>>
                                    <?= e(academic_label($y)) ?><?= $y['is_current'] ? ' (ปัจจุบัน)' : '' ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('academic_year_id') ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="name">ชื่อรอบ *</label>
                        <input class="form-control<?= $invalid('name') ?>" name="name" id="name" maxlength="200" value="<?= e($fv('name')) ?>"
                               placeholder="เช่น ส่งโครงการสอนและแผนการจัดการเรียนรู้ ภาคเรียนที่ 2/2569"><?= $err('name') ?>
                    </div>
                    <div class="col-md-4" id="fiscalWrap">
                        <label class="form-label" for="fiscal_year">ปีงบประมาณ (พ.ศ.) *</label>
                        <input type="number" class="form-control<?= $invalid('fiscal_year') ?>" name="fiscal_year" id="fiscal_year"
                               min="2500" max="2700" value="<?= e($fv('fiscal_year', (string)current_fiscal_year())) ?>"><?= $err('fiscal_year') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="open_at">วันเวลาเปิดรับ *</label>
                        <input type="datetime-local" class="form-control<?= $invalid('open_at') ?>" name="open_at" id="open_at"
                               value="<?= e($fv('open_at', date('Y-m-d\T08:00'))) ?>"><?= $err('open_at') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="close_at">วันเวลาปิดรับ *</label>
                        <input type="datetime-local" class="form-control<?= $invalid('close_at') ?>" name="close_at" id="close_at"
                               value="<?= e($fv('close_at', date('Y-m-d\T16:30', strtotime('+14 days')))) ?>"><?= $err('close_at') ?>
                    </div>
                    <div class="col-md-4 d-flex flex-column justify-content-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="allow_late" id="allow_late" value="1" <?= $fChecked('allow_late', false) ?>>
                            <label class="form-check-label" for="allow_late">อนุญาตส่งช้า (ติดป้าย "ส่งช้า")</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" <?= $fChecked('is_active', true) ?>>
                            <label class="form-check-label" for="is_active">เปิดใช้งานรอบนี้</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="description">รายละเอียด/คำชี้แจง</label>
                        <textarea class="form-control" name="description" id="description" rows="3" maxlength="2000"><?= e($fv('description')) ?></textarea>
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <a href="<?= e(url('admin/rounds.php')) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึก</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span><i class="bi bi-list-ul me-2"></i>รอบการส่งงานทั้งหมด</span>
        <div class="ms-auto btn-group btn-group-sm" role="group" aria-label="กรองประเภท">
            <a href="<?= e(url('admin/rounds.php')) ?>" class="btn <?= $fType === '' ? 'btn-primary' : 'btn-outline-primary' ?>">ทั้งหมด</a>
            <?php foreach (submission_types() as $t): ?>
                <a href="?type=<?= e($t) ?>" class="btn <?= $fType === $t ? 'btn-primary' : 'btn-outline-primary' ?>"><?= e(submission_type_label($t)) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead>
                <tr><th>ชื่อรอบ</th><th>ประเภท/ขั้นตอน</th><th>ภาคเรียน</th><th>เปิดรับ</th><th>ปิดรับ</th><th>สถานะ</th><th>งานที่ส่ง</th><th class="no-sort text-end">จัดการ</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rounds as $r): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= e($r['name']) ?></div>
                            <?php if ($r['allow_late']): ?><small class="text-warning"><i class="bi bi-clock-history"></i> อนุญาตส่งช้า</small><?php endif; ?>
                        </td>
                        <td class="small"><?= e(submission_type_label($r['type'])) ?><br><span class="text-muted"><?= e(phase_label($r['phase'])) ?><?= $r['fiscal_year'] ? ' · ปีงบ ' . (int)$r['fiscal_year'] : '' ?></span></td>
                        <td class="small"><?= $r['year'] ? e($r['semester'] . '/' . $r['year']) : '-' ?></td>
                        <td class="small" data-order="<?= e($r['open_at']) ?>"><?= e(thai_datetime($r['open_at'])) ?></td>
                        <td class="small" data-order="<?= e($r['close_at']) ?>"><?= e(thai_datetime($r['close_at'])) ?>
                            <?php if (round_state($r) === 'open'): ?>
                                <div class="small">เหลือ <span data-countdown="<?= strtotime($r['close_at']) * 1000 ?>"><?= e(remaining_text(strtotime($r['close_at']) - time())) ?></span></div>
                            <?php endif; ?>
                        </td>
                        <td><?= round_state_badge($r) ?></td>
                        <td>
                            <?php if ($r['type'] === 'lesson_plan' || is_file(APP_ROOT . '/' . review_page('admin', $r['type']))): ?>
                                <a href="<?= e(url(review_page('admin', $r['type']) . '?round_id=' . (int)$r['id'])) ?>"><?= (int)$r['submissions'] ?> รายการ</a>
                            <?php else: ?>
                                <?= (int)$r['submissions'] ?> รายการ
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url('admin/rounds.php?edit=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline" data-confirm="<?= $r['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>รอบ &quot;<?= e($r['name']) ?>&quot;?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm <?= $r['is_active'] ? 'btn-outline-secondary' : 'btn-outline-success' ?>" title="<?= $r['is_active'] ? 'ปิดใช้งาน' : 'เปิดใช้งาน' ?>" data-bs-toggle="tooltip">
                                    <i class="bi <?= $r['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i></button>
                            </form>
                            <?php if ((int)$r['submissions'] === 0): ?>
                                <form method="post" class="d-inline" data-confirm="ลบรอบ &quot;<?= e($r['name']) ?>&quot;? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="ลบ" data-bs-toggle="tooltip"><i class="bi bi-trash"></i></button>
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

<?php
// ตัวเลือกขั้นตอนตามประเภทงาน + แสดงช่องปีงบประมาณเฉพาะ PA
$phaseMap = [];
foreach (submission_types() as $t) {
    foreach (round_phase_options($t) as $p) {
        $phaseMap[$t][] = ['value' => $p, 'label' => phase_label($p)];
    }
}
$pageScripts = '(function () {
    var type = document.getElementById("type"); if (!type) return;
    var phase = document.getElementById("phase"), fiscal = document.getElementById("fiscalWrap");
    var map = ' . json_encode($phaseMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
    function refresh() {
        var cur = phase.value || phase.dataset.value;
        phase.innerHTML = "";
        (map[type.value] || []).forEach(function (o) {
            var op = new Option(o.label, o.value, false, o.value === cur);
            phase.add(op);
        });
        fiscal.style.display = type.value === "pa" ? "" : "none";
    }
    type.addEventListener("change", refresh);
    refresh();
})();';
require APP_ROOT . '/includes/footer.php';
