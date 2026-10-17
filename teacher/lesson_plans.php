<?php
/**
 * ไฟล์: teacher/lesson_plans.php
 * โมดูลที่ 1: ส่งโครงการสอน / แผนการจัดการเรียนรู้ ในจุดเดียว (เลือกประเภทเอกสารจาก list)
 *   ครู และหัวหน้าแผนกที่ส่งงานของตนเอง · ?doc=outline|plan เลือกประเภทให้อัตโนมัติ
 *   (ไม่มีพารามิเตอร์)  = รอบที่เปิดรับ + ฟอร์มส่งแผน + รายการแผนของฉัน
 *   ?round_id=xx       = เลือกรอบในฟอร์มให้อัตโนมัติ
 *   ?id=xx             = ดูรายละเอียด/ประวัติทุกเวอร์ชัน และส่งฉบับแก้ไข
 * กติกา: ส่งได้เฉพาะช่วงรอบเปิดรับ (ตรวจเวลาฝั่งเซิร์ฟเวอร์) / ถ้ารอบอนุญาตส่งช้าจะติดป้าย "ส่งช้า"
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$type = 'lesson_plan';
$errors = [];

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- ส่งแผนใหม่ ----------
    if ($action === 'create') {
        $roundId = (int)post('round_id');
        $round = db_one("SELECT * FROM submission_rounds WHERE id = ? AND type = 'lesson_plan'", 'i', [$roundId]);
        $units = (int)post('units');
        $note = post('note');
        $code = $name = $level = '';
        // ประเภทเอกสาร: เลือกจาก list (โครงการสอน / แผนการจัดการเรียนรู้)
        $docType = post('doc_type');
        $ayId = 0;
        if (!isset(lesson_doc_types()[$docType])) {
            $errors['doc_type'] = 'กรุณาเลือกประเภทเอกสาร (โครงการสอน หรือ แผนการจัดการเรียนรู้)';
            $docType = 'plan';
        }

        if (!$round) {
            $errors['round_id'] = 'กรุณาเลือกรอบการส่ง';
        } elseif (!round_can_submit($round)) {
            $errors['round_id'] = round_state($round) === 'upcoming' ? 'รอบนี้ยังไม่เปิดรับ' : 'รอบนี้ปิดรับแล้ว';
        } else {
            $ayId = round_academic_year_id($round);
            // วิชา: เลือกจาก "รายวิชาที่สอน" ของภาคเรียนเดียวกับรอบ
            $ts = db_one(
                'SELECT * FROM teaching_subjects WHERE id = ? AND teacher_id = ? AND academic_year_id = ?',
                'iii',
                [(int)post('teaching_subject_id'), $uid, $ayId]
            );
            if (!$ts) {
                $errors['teaching_subject_id'] = 'กรุณาเลือกรายวิชาที่สอนในภาคเรียนของรอบนี้ (เพิ่มได้ที่เมนู รายวิชาที่สอน)';
            } else {
                [$code, $name, $level] = [$ts['subject_code'], $ts['subject_name'], $ts['level']];
            }
        }
        if ($units < 1 || $units > 99) {
            $errors['units'] = 'จำนวนรายวิชาต้องอยู่ระหว่าง 1-99';
        }
        if (!$errors && db_value(
            'SELECT id FROM lesson_plans WHERE round_id = ? AND teacher_id = ? AND doc_type = ? AND subject_code = ?',
            'iiss',
            [$roundId, $uid, $docType, $code]
        )) {
            $errors['teaching_subject_id'] = 'ท่านส่ง' . lesson_doc_label($docType) . 'วิชานี้ในรอบนี้แล้ว หากต้องการแก้ไขให้กด "ดู/ส่งใหม่" ที่รายการด้านล่าง';
        }

        if (!$errors) {
            $res = submission_create($type, $round, $uid, $note, function (bool $isLate) use ($roundId, $uid, $ayId, $docType, $code, $name, $level, $units): int {
                return db_insert(
                    'INSERT INTO lesson_plans (round_id, doc_type, teacher_id, academic_year_id, subject_code, subject_name, level, units, is_late)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'isiisssii',
                    [$roundId, $docType, $uid, $ayId, $code, $name, $level, $units, $isLate ? 1 : 0]
                );
            });
            if (!$res['ok']) {
                $errors[$res['field'] ?? 'round_id'] = $res['error'];
            } else {
                log_activity('submit_lesson_plan', 'ส่ง' . lesson_doc_label($docType) . ' ' . $code . ' ' . $name . ($res['is_late'] ? ' (ส่งช้า)' : ''));
                flash('success', 'ส่ง' . lesson_doc_label($docType) . 'วิชา ' . $code . ' เรียบร้อยแล้ว' . ($res['is_late'] ? ' (บันทึกเป็นส่งช้า)' : ''));
                redirect('teacher/lesson_plans.php?id=' . $res['id']);
            }
        }
    }

    // ---------- ส่งฉบับแก้ไข (เวอร์ชันใหม่) ----------
    if ($action === 'resubmit') {
        $sub = own_submission($type, (int)post('id'), $uid);
        if (!$sub) {
            abort_page(404, 'ไม่พบงาน');
        }
        $error = submission_resubmit($type, $sub, $uid, post('note'));
        if ($error === '') {
            flash('success', 'ส่งฉบับที่ ' . ((int)$sub['current_version'] + 1) . ' เรียบร้อยแล้ว สถานะเปลี่ยนเป็น "รอตรวจ"');
            redirect('teacher/lesson_plans.php?id=' . (int)$sub['id']);
        }
        $errors['file'] = $error;
    }

    // ---------- ลบงาน (ยังไม่ถูกตรวจ และรอบยังเปิด) ----------
    if ($action === 'delete') {
        $sub = own_submission($type, (int)post('id'), $uid);
        if (!$sub) {
            abort_page(404, 'ไม่พบงาน');
        }
        if (!submission_can_delete($sub)) {
            flash('error', 'ไม่สามารถลบได้ (งานถูกตรวจแล้ว หรือรอบปิดรับแล้ว)');
            redirect('teacher/lesson_plans.php?id=' . (int)$sub['id']);
        }
        submission_delete($type, $sub);
        log_activity('delete_lesson_plan', 'ลบแผนการสอน: ' . $sub['title']);
        flash('success', 'ลบแผนการสอน ' . $sub['title'] . ' แล้ว');
        redirect('teacher/lesson_plans.php');
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$accept = upload_accept();
$fileHint = upload_hint();
$activeMenu = 'teacher/lesson_plans.php';

// =====================================================================
// หน้ารายละเอียด (?id=)
// =====================================================================
$viewId = get_int('id') ?: (post('action') === 'resubmit' ? (int)post('id') : 0);
if ($viewId > 0) {
    $sub = own_submission($type, $viewId, $uid);
    if (!$sub) {
        abort_page(404, 'ไม่พบงาน', 'งานนี้ไม่มีอยู่ หรือไม่ใช่งานของท่าน');
    }
    $blockReason = submission_resubmit_block_reason($sub);
    $lastReview = submission_last_review($type, (int)$sub['id']);

    $pageTitle = lesson_doc_label($sub['doc_type']) . ': ' . $sub['subject_code'];
    $pageIcon = 'bi-journal-text';
    $breadcrumbs = ['ส่งโครงการสอน/แผนการจัดการเรียนรู้' => 'teacher/lesson_plans.php', $sub['subject_code'] => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($sub['status'] === 'revision' && $lastReview): ?>
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> ผู้ตรวจให้แก้ไข</div>
            <?= nl2br(e((string)$lastReview['comment'])) ?: 'กรุณาแก้ไขและส่งใหม่' ?>
        </div>
    <?php endif; ?>

    <?php require APP_ROOT . '/includes/partials/submission_detail.php'; ?>

    <div class="row g-4 mt-1">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>ส่งฉบับแก้ไข / แทนที่ไฟล์</div>
                <div class="card-body">
                    <?php if ($blockReason !== ''): ?>
                        <div class="text-muted"><i class="bi bi-lock me-1"></i><?= e($blockReason) ?></div>
                    <?php else: ?>
                        <form method="post" enctype="multipart/form-data" novalidate
                              data-confirm="ยืนยันส่งไฟล์ฉบับที่ <?= (int)$sub['current_version'] + 1 ?>? สถานะจะกลับเป็น &quot;รอตรวจ&quot;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="resubmit">
                            <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                            <div class="mb-3">
                                <label class="form-label" for="file">ไฟล์ฉบับใหม่ *</label>
                                <input type="file" class="form-control<?= $invalid('file') ?>" name="file" id="file" accept="<?= e($accept) ?>" required>
                                <div class="form-text"><?= e($fileHint) ?></div><?= $err('file') ?>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="note">สิ่งที่แก้ไข / หมายเหตุ</label>
                                <input class="form-control" name="note" id="note" maxlength="500" value="<?= old('note') ?>" placeholder="เช่น แก้ไขหน่วยที่ 3 ตามคำแนะนำ">
                            </div>
                            <button type="submit" class="btn btn-accent" data-loading-text="กำลังอัปโหลด..."><i class="bi bi-upload me-1"></i> ส่งฉบับที่ <?= (int)$sub['current_version'] + 1 ?></button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="d-grid gap-2">
                <a href="<?= e(url('teacher/lesson_plans.php')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการงานของฉัน</a>
                <?php if (submission_can_delete($sub)): ?>
                    <form method="post" data-confirm="ลบแผนการสอน <?= e($sub['title']) ?> พร้อมไฟล์ทั้งหมด? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                        <button class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i> ลบงานนี้</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารวม: รอบที่เปิด + ฟอร์มส่ง + รายการของฉัน
// =====================================================================
$rounds = db_all(
    "SELECT r.*, ay.year, ay.semester FROM submission_rounds r
     LEFT JOIN academic_years ay ON ay.id = r.academic_year_id
     WHERE r.type = 'lesson_plan' AND r.is_active = 1
       AND (r.close_at >= NOW() - INTERVAL 30 DAY OR r.open_at > NOW())
     ORDER BY r.close_at"
);
$submittable = array_values(array_filter($rounds, 'round_can_submit'));
$selRound = (int)(post('round_id') ?: get_int('round_id'));
$selRoundRow = null;
if ($selRound) {
    $selRoundRow = db_one("SELECT * FROM submission_rounds WHERE id = ? AND type = 'lesson_plan'", 'i', [$selRound]);
}

// รายวิชาที่สอน (ทุกภาคเรียนของรอบที่เปิดรับ) ใช้เป็นตัวเลือกวิชาในฟอร์ม
$roundAy = [];
foreach ($submittable as $r) {
    $roundAy[(int)$r['id']] = round_academic_year_id($r);
}
$mySubjects = [];
foreach (array_unique($roundAy) as $ayId) {
    foreach (teaching_subjects($uid, $ayId) as $ts) {
        $mySubjects[] = $ts;
    }
}

$mine = db_all(
    "SELECT lp.*, r.name AS round_name, r.open_at, r.close_at, r.allow_late, r.is_active AS round_active,
            ay.year, ay.semester
     FROM lesson_plans lp
     JOIN submission_rounds r ON r.id = lp.round_id
     LEFT JOIN academic_years ay ON ay.id = lp.academic_year_id
     WHERE lp.teacher_id = ?
     ORDER BY lp.submitted_at DESC",
    'i',
    [$uid]
);

$pageTitle = 'ส่งโครงการสอน / แผนการจัดการเรียนรู้';
$pageIcon = 'bi-journal-text';
$breadcrumbs = ['ส่งโครงการสอน/แผนการจัดการเรียนรู้' => null];
require APP_ROOT . '/includes/header.php';
?>

<?php if ($selRoundRow && !round_can_submit($selRoundRow)): ?>
    <div class="alert alert-warning"><i class="bi bi-info-circle me-1"></i>
        รอบ "<?= e($selRoundRow['name']) ?>" <?= round_state($selRoundRow) === 'upcoming'
            ? 'ยังไม่เปิดรับ (เปิด ' . e(thai_datetime($selRoundRow['open_at'])) . ')'
            : 'ปิดรับแล้ว' ?>
    </div>
<?php endif; ?>

<!-- รอบการส่ง -->
<div class="row g-3 mb-4">
    <?php foreach ($rounds as $r): ?>
        <div class="col-md-6 col-xl-4"><?= round_card_html($r) ?></div>
    <?php endforeach; ?>
    <?php if (!$rounds): ?>
        <div class="col-12"><div class="card"><div class="empty-state"><i class="bi bi-calendar-x"></i>ยังไม่มีรอบการส่งโครงการสอน / แผนการจัดการเรียนรู้</div></div></div>
    <?php endif; ?>
</div>

<!-- ฟอร์มส่งโครงการสอน / แผน -->
<div class="card mb-4" id="submitForm">
    <div class="card-header"><i class="bi bi-cloud-upload me-2"></i>ส่งโครงการสอน / แผนการจัดการเรียนรู้</div>
    <div class="card-body">
        <div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
            เกณฑ์แต่ละภาคเรียน: ส่ง <strong>โครงการสอนครบทุกรายวิชาที่สอน</strong> และ <strong>แผนการจัดการเรียนรู้อย่างน้อย 1 วิชา</strong>
            — เลือกประเภทเอกสารที่จะส่งได้ในฟอร์มเดียวกัน · วิชาที่เลือกได้มาจาก <a href="<?= e(url('teacher/teaching_subjects.php')) ?>">รายวิชาที่สอน</a></div>
        <?php if (!$submittable): ?>
            <div class="empty-state"><i class="bi bi-lock"></i>ขณะนี้ไม่มีรอบที่เปิดรับ — ระบบไม่อนุญาตให้อัปโหลดนอกช่วงเวลา</div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" novalidate data-confirm="ตรวจสอบข้อมูลและไฟล์ถูกต้องแล้ว ต้องการส่งงานใช่หรือไม่?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="round_id">รอบการส่ง *</label>
                        <select class="form-select<?= $invalid('round_id') ?>" name="round_id" id="round_id" required>
                            <?php foreach ($submittable as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" data-ay="<?= $roundAy[(int)$r['id']] ?>" <?= $selRound === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= e($r['name']) ?><?= round_state($r) === 'late' ? ' [ส่งช้า]' : '' ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('round_id') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="doc_type">ประเภทเอกสาร *</label>
                        <select class="form-select<?= $invalid('doc_type') ?>" name="doc_type" id="doc_type" required>
                            <option value="">-- เลือกประเภท --</option>
                            <?php foreach (lesson_doc_types() as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= (post('doc_type') ?: get('doc')) === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('doc_type') ?>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label" for="teaching_subject_id">รายวิชา *</label>
                        <select class="form-select<?= $invalid('teaching_subject_id') ?>" name="teaching_subject_id" id="teaching_subject_id">
                            <option value="" data-ay="">-- เลือกรายวิชาที่สอน --</option>
                            <?php foreach ($mySubjects as $ts): ?>
                                <option value="<?= (int)$ts['id'] ?>" data-ay="<?= (int)$ts['academic_year_id'] ?>"
                                    <?= (int)post('teaching_subject_id') === (int)$ts['id'] ? 'selected' : '' ?>><?= e($ts['subject_code'] . ' ' . $ts['subject_name'] . ' (' . $ts['level'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('teaching_subject_id') ?>
                        <div class="form-text" id="noSubjectHint">ไม่พบวิชาที่ต้องการ? เพิ่มที่ <a href="<?= e(url('teacher/teaching_subjects.php')) ?>">รายวิชาที่สอน</a> ก่อน</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="units">จำนวนรายวิชา *</label>
                        <input type="number" class="form-control<?= $invalid('units') ?>" name="units" id="units" min="1" max="99" value="<?= old('units', '1') ?>"><?= $err('units') ?>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label" for="file">ไฟล์โครงการสอน / แผนการจัดการเรียนรู้ *</label>
                        <input type="file" class="form-control<?= $invalid('file') ?>" name="file" id="file" accept="<?= e($accept) ?>" required>
                        <div class="form-text"><?= e($fileHint) ?></div><?= $err('file') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note">หมายเหตุ</label>
                        <input class="form-control" name="note" id="note" maxlength="500" value="<?= old('note') ?>">
                    </div>
                </div>
                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังอัปโหลด..."><i class="bi bi-send me-1"></i> ส่งงาน</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- รายการของฉัน -->
<div class="card">
    <div class="card-header"><i class="bi bi-folder2-open me-2"></i>โครงการสอน / แผนการจัดการเรียนรู้ของฉัน (<?= count($mine) ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead>
                <tr><th>ประเภท</th><th>รหัส/ชื่อวิชา</th><th>ระดับ</th><th>จำนวนรายวิชา</th><th>ภาคเรียน</th><th>รอบ</th><th>ฉบับที่</th><th>สถานะ</th><th>ส่งเมื่อ</th><th class="no-sort"></th></tr>
                </thead>
                <tbody>
                <?php foreach ($mine as $m): ?>
                    <tr>
                        <td><span class="badge <?= $m['doc_type'] === 'outline' ? 'bg-info text-dark' : 'bg-primary' ?>"><?= e(lesson_doc_label($m['doc_type'])) ?></span></td>
                        <td><div class="fw-semibold"><?= e($m['subject_code']) ?></div><small><?= e($m['subject_name']) ?></small></td>
                        <td><?= e($m['level']) ?></td>
                        <td><?= (int)$m['units'] ?></td>
                        <td><?= $m['year'] ? e($m['semester'] . '/' . $m['year']) : '-' ?></td>
                        <td class="small"><?= e($m['round_name']) ?></td>
                        <td class="text-center"><?= (int)$m['current_version'] ?></td>
                        <td data-order="<?= e($m['status']) ?>"><?= status_badge($m['status'], (bool)$m['is_late']) ?></td>
                        <td class="small" data-order="<?= e($m['submitted_at']) ?>"><?= e(thai_datetime($m['submitted_at'])) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url('teacher/lesson_plans.php?id=' . (int)$m['id'])) ?>"
                               class="btn btn-sm <?= $m['status'] === 'revision' ? 'btn-danger' : 'btn-outline-primary' ?> text-nowrap">
                                <?= $m['status'] === 'revision' ? '<i class="bi bi-pencil-square"></i> แก้ไข' : '<i class="bi bi-eye"></i> ดู' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// แสดงเฉพาะรายวิชาของภาคเรียนเดียวกับรอบที่เลือก
$pageScripts = '(function () {
    var r = document.getElementById("round_id"), s = document.getElementById("teaching_subject_id");
    if (!r || !s) return;
    function sync() {
        var ay = r.options[r.selectedIndex] ? r.options[r.selectedIndex].dataset.ay : "", n = 0;
        Array.prototype.forEach.call(s.options, function (o) {
            if (!o.value) return;
            var ok = o.dataset.ay === ay; o.hidden = !ok; o.disabled = !ok; if (ok) n++;
            if (!ok && o.selected) s.value = "";
        });
        s.options[0].textContent = n ? "-- เลือกรายวิชาที่สอน --" : "-- ยังไม่มีรายวิชาที่สอนในภาคเรียนของรอบนี้ --";
    }
    r.addEventListener("change", sync);
    sync();
})();';
require APP_ROOT . '/includes/footer.php';
