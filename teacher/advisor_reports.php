<?php
/**
 * ไฟล์: teacher/advisor_reports.php
 * รายงานการพบนักเรียนของครูที่ปรึกษา — ส่งสัปดาห์ละ 1 ครั้ง (ทุกวันพุธ) ต่อ 1 กลุ่มเรียน
 *   (ไม่มี)            ตารางวันพุธของภาคเรียน + สถานะการส่งแต่ละกลุ่ม   ?ay= เลือกภาคเรียน
 *   ?group=xx&date=..  ส่งรายงานใหม่ของกลุ่ม/วันพุธนั้น
 *   ?id=xx             ดูรายงาน   ?edit=xx  แก้ไขรายงาน (เฉพาะสถานะ "รอตรวจ" หรือ "ให้แก้ไข")
 * กลุ่มที่ปรึกษากำหนดโดยงานครูที่ปรึกษาและแนะแนว (review/advisor_groups.php)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$self = 'teacher/advisor_reports.php';
$activeMenu = $self;
$errors = [];

/** รายงานของครูคนนี้เท่านั้น */
function own_advisor_report(int $id, int $uid): ?array
{
    $r = advisor_report_fetch($id);
    return ($r && (int)$r['teacher_id'] === $uid) ? $r : null;
}

/** ตรวจข้อมูลแบบฟอร์ม (จำนวนนักเรียน + ข้อความ) */
function advisor_validate(array &$errors): array
{
    $d = [];
    foreach (['total_students', 'present_count', 'late_count', 'leave_count', 'absent_count'] as $k) {
        $v = post($k);
        if ($v === '' || !ctype_digit($v) || (int)$v > 200) {
            $errors[$k] = 'กรอกตัวเลข 0-200';
        }
        $d[$k] = (int)$v;
    }
    if (!$errors) {
        if ($d['total_students'] < 1) {
            $errors['total_students'] = 'จำนวนนักเรียนทั้งหมดต้องมากกว่า 0';
        } elseif ($d['present_count'] + $d['late_count'] + $d['leave_count'] + $d['absent_count'] !== $d['total_students']) {
            $errors['counts'] = 'มา + มาสาย + ลา + ขาด ต้องเท่ากับจำนวนนักเรียนทั้งหมด (' . $d['total_students'] . ' คน)';
        }
    }
    $d['topics'] = post('topics');
    $d['problems'] = post('problems');
    if ($d['topics'] === '' || mb_strlen($d['topics']) > 5000) {
        $errors['topics'] = 'กรุณากรอกเรื่องที่พูดคุย/แจ้งนักเรียน (ไม่เกิน 5,000 ตัวอักษร)';
    }
    if (mb_strlen($d['problems']) > 5000) {
        $errors['problems'] = 'ไม่เกิน 5,000 ตัวอักษร';
    }
    return $d;
}

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- ส่ง/แก้ไขรายงาน ----------
    if ($action === 'save') {
        $id = (int)post('id');
        $rep = $id ? own_advisor_report($id, $uid) : null;
        if ($id && !$rep) {
            abort_page(404, 'ไม่พบรายงาน');
        }
        if ($rep && !in_array($rep['status'], ['pending', 'revision'], true)) {
            flash('error', 'รายงานนี้ผ่านการตรวจแล้ว ไม่สามารถแก้ไขได้');
            redirect($self . '?id=' . $id);
        }
        $advGroup = advisor_group_fetch($rep ? (int)$rep['group_id'] : (int)post('group_id'));
        if (!$advGroup || (int)$advGroup['teacher_id'] !== $uid) {
            abort_page(403, 'ไม่ใช่กลุ่มที่ปรึกษาของท่าน');
        }
        $meetDate = $rep ? $rep['meet_date'] : post('meet_date');
        if (!$rep) {
            $reason = advisor_date_block_reason(advisor_academic_year((int)$advGroup['academic_year_id']), $meetDate);
            if ($reason !== '') {
                flash('error', $reason);
                redirect($self . '?ay=' . (int)$advGroup['academic_year_id']);
            }
            $existing = db_value('SELECT id FROM advisor_reports WHERE group_id = ? AND meet_date = ?', 'is', [(int)$advGroup['id'], $meetDate]);
            if ($existing) {
                flash('warning', 'ส่งรายงานของวันนี้แล้ว หากต้องการแก้ไขให้กด "แก้ไข"');
                redirect($self . '?id=' . (int)$existing);
            }
        }
        $d = advisor_validate($errors);

        $newFiles = normalize_multi_files($_FILES['images'] ?? null);
        $existingImages = $rep ? count(advisor_report_images((int)$rep['id'])) : 0;
        if (count($newFiles) + $existingImages > ADVISOR_MAX_IMAGES) {
            $errors['images'] = 'แนบภาพได้สูงสุด ' . ADVISOR_MAX_IMAGES . ' ภาพ (มีอยู่แล้ว ' . $existingImages . ' ภาพ)';
        } elseif (count($newFiles) + $existingImages < 1) {
            $errors['images'] = 'กรุณาแนบภาพถ่ายการพบนักเรียนอย่างน้อย 1 ภาพ';
        }
        $uploaded = [];
        if (!$errors) {
            foreach ($newFiles as $f) {
                $up = handle_upload($f, 'advisor', ['jpg', 'jpeg', 'png', 'webp'], ADVISOR_MAX_IMAGE_BYTES);
                if (!$up['ok']) {
                    $errors['images'] = $f['name'] . ': ' . $up['error'];
                    break;
                }
                $uploaded[] = $up;
            }
            if ($errors) {
                foreach ($uploaded as $up) {
                    delete_upload('advisor', $up['stored_name']);
                }
                $uploaded = [];
            }
        }

        if (!$errors) {
            $isLate = $rep ? (bool)$rep['is_late'] : advisor_is_late_now($meetDate);
            db()->begin_transaction();
            try {
                if ($rep) {
                    db_exec(
                        "UPDATE advisor_reports SET total_students = ?, present_count = ?, late_count = ?, leave_count = ?, absent_count = ?,
                                topics = ?, problems = ?, status = 'pending' WHERE id = ?",
                        'iiiiissi',
                        [$d['total_students'], $d['present_count'], $d['late_count'], $d['leave_count'], $d['absent_count'],
                            $d['topics'], $d['problems'], (int)$rep['id']]
                    );
                    $repId = (int)$rep['id'];
                } else {
                    $repId = db_insert(
                        'INSERT INTO advisor_reports (group_id, teacher_id, meet_date, total_students, present_count, late_count, leave_count,
                                                      absent_count, topics, problems, is_late)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        'iisiiiiissi',
                        [(int)$advGroup['id'], $uid, $meetDate, $d['total_students'], $d['present_count'], $d['late_count'], $d['leave_count'],
                            $d['absent_count'], $d['topics'], $d['problems'], $isLate ? 1 : 0]
                    );
                }
                foreach ($uploaded as $up) {
                    db_insert(
                        'INSERT INTO advisor_report_images (report_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)',
                        'isssi',
                        [$repId, $up['original_name'], $up['stored_name'], $up['mime'], $up['size']]
                    );
                }
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollback();
                foreach ($uploaded as $up) {
                    delete_upload('advisor', $up['stored_name']);
                }
                throw $ex;
            }
            $label = $advGroup['group_name'] . ' วันพุธที่ ' . thai_date($meetDate);
            if ($rep) {
                log_activity('advisor_report_update', 'แก้ไขรายงานครูที่ปรึกษา ' . $label);
                flash('success', 'บันทึกการแก้ไขแล้ว สถานะเป็น "รอตรวจ"');
            } else {
                log_activity('advisor_report_submit', 'ส่งรายงานครูที่ปรึกษา ' . $label . ($isLate ? ' (ส่งช้า)' : ''));
                flash('success', 'ส่งรายงานการพบนักเรียน ' . $label . ' เรียบร้อยแล้ว' . ($isLate ? ' (บันทึกเป็นส่งช้า)' : ''));
            }
            redirect($self . '?id=' . $repId);
        }
        // แสดงฟอร์มเดิมพร้อมข้อผิดพลาด
        if ($rep) {
            $_GET['edit'] = (string)$rep['id'];
        } else {
            $_GET['group'] = (string)$advGroup['id'];
            $_GET['date'] = $meetDate;
        }
    }

    // ---------- ลบภาพ ----------
    if ($action === 'delete_image') {
        $img = db_one('SELECT * FROM advisor_report_images WHERE id = ?', 'i', [(int)post('image_id')]);
        $rep = $img ? own_advisor_report((int)$img['report_id'], $uid) : null;
        if (!$rep || !in_array($rep['status'], ['pending', 'revision'], true)) {
            abort_page(403, 'ไม่มีสิทธิ์ลบภาพนี้');
        }
        if (count(advisor_report_images((int)$rep['id'])) <= 1) {
            flash('error', 'รายงานต้องมีภาพอย่างน้อย 1 ภาพ — เพิ่มภาพใหม่ก่อนแล้วจึงลบภาพเดิม');
        } else {
            db_exec('DELETE FROM advisor_report_images WHERE id = ?', 'i', [(int)$img['id']]);
            delete_upload('advisor', $img['stored_name']);
            flash('success', 'ลบภาพแล้ว');
        }
        redirect($self . '?edit=' . (int)$rep['id']);
    }

    // ---------- ลบรายงาน (ยังไม่เคยถูกตรวจ) ----------
    if ($action === 'delete') {
        $rep = own_advisor_report((int)post('id'), $uid);
        if (!$rep) {
            abort_page(404, 'ไม่พบรายงาน');
        }
        if ($rep['status'] !== 'pending' || $rep['reviewed_at']) {
            flash('error', 'ไม่สามารถลบได้ เพราะรายงานถูกตรวจแล้ว');
            redirect($self . '?id=' . (int)$rep['id']);
        }
        advisor_report_delete($rep);
        log_activity('advisor_report_delete', 'ลบรายงานครูที่ปรึกษา ' . $rep['group_name'] . ' วันพุธที่ ' . thai_date($rep['meet_date']));
        flash('success', 'ลบรายงานแล้ว');
        redirect($self . '?ay=' . (int)$rep['academic_year_id']);
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

// =====================================================================
// ดูรายงาน (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $rep = own_advisor_report($viewId, $uid);
    if (!$rep) {
        abort_page(404, 'ไม่พบรายงาน', 'รายงานนี้ไม่มีอยู่ หรือไม่ใช่รายงานของท่าน');
    }
    $pageTitle = 'รายงานการพบนักเรียน';
    $pageIcon = 'bi-people';
    $breadcrumbs = ['รายงานครูที่ปรึกษา' => $self . '?ay=' . (int)$rep['academic_year_id'], advisor_date_label($rep['meet_date']) => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($rep['status'] === 'revision'): ?>
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> ผู้ตรวจให้แก้ไข</div>
            <?= nl2br(e((string)$rep['review_comment'])) ?: 'กรุณาแก้ไขและส่งใหม่' ?>
        </div>
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-xl-8"><?php require APP_ROOT . '/includes/partials/advisor_report_detail.php'; ?></div>
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-body d-grid gap-2">
                    <?php if (in_array($rep['status'], ['pending', 'revision'], true)): ?>
                        <a href="<?= e(url($self . '?edit=' . (int)$rep['id'])) ?>" class="btn btn-accent"><i class="bi bi-pencil-square me-1"></i>
                            <?= $rep['status'] === 'revision' ? 'แก้ไขและส่งใหม่' : 'แก้ไขรายงาน' ?></a>
                    <?php else: ?>
                        <div class="text-success small"><i class="bi bi-check-circle me-1"></i>รายงานผ่านการตรวจแล้ว</div>
                    <?php endif; ?>
                    <?php if ($rep['status'] === 'pending' && !$rep['reviewed_at']): ?>
                        <form method="post" data-confirm="ลบรายงานนี้? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$rep['id'] ?>">
                            <button class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i> ลบรายงาน</button>
                        </form>
                    <?php endif; ?>
                    <a href="<?= e(url($self . '?ay=' . (int)$rep['academic_year_id'])) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
                </div>
            </div>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// ฟอร์มส่งใหม่ (?group=&date=) / แก้ไข (?edit=)
// =====================================================================
$editId = get_int('edit');
$newGroupId = get_int('group');
if ($editId > 0 || $newGroupId > 0) {
    $rep = null;
    if ($editId > 0) {
        $rep = own_advisor_report($editId, $uid);
        if (!$rep) {
            abort_page(404, 'ไม่พบรายงาน');
        }
        if (!in_array($rep['status'], ['pending', 'revision'], true)) {
            flash('error', 'รายงานนี้ผ่านการตรวจแล้ว ไม่สามารถแก้ไขได้');
            redirect($self . '?id=' . (int)$rep['id']);
        }
        $advGroup = advisor_group_fetch((int)$rep['group_id']);
        $meetDate = $rep['meet_date'];
    } else {
        $advGroup = advisor_group_fetch($newGroupId);
        if (!$advGroup || (int)$advGroup['teacher_id'] !== $uid) {
            abort_page(404, 'ไม่พบกลุ่มที่ปรึกษา', 'กลุ่มนี้ไม่ใช่กลุ่มที่ปรึกษาของท่าน');
        }
        $meetDate = get('date');
        $reason = advisor_date_block_reason(advisor_academic_year((int)$advGroup['academic_year_id']), $meetDate);
        if ($reason !== '') {
            flash('error', $reason);
            redirect($self . '?ay=' . (int)$advGroup['academic_year_id']);
        }
        $existing = db_value('SELECT id FROM advisor_reports WHERE group_id = ? AND meet_date = ?', 'is', [(int)$advGroup['id'], $meetDate]);
        if ($existing) {
            redirect($self . '?id=' . (int)$existing);
        }
    }
    $images = $rep ? advisor_report_images((int)$rep['id']) : [];
    $isLate = $rep ? (bool)$rep['is_late'] : advisor_is_late_now($meetDate);
    $v = function (string $k, string $default = '') use ($rep): string {
        return is_post() ? post($k) : (string)($rep[$k] ?? $default);
    };

    $pageTitle = $rep ? 'แก้ไขรายงานการพบนักเรียน' : 'ส่งรายงานการพบนักเรียน';
    $pageIcon = 'bi-people';
    $breadcrumbs = ['รายงานครูที่ปรึกษา' => $self . '?ay=' . (int)$advGroup['academic_year_id'], advisor_date_label($meetDate) => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($rep && $rep['status'] === 'revision'): ?>
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> สิ่งที่ผู้ตรวจให้แก้ไข</div>
            <?= nl2br(e((string)$rep['review_comment'])) ?: '-' ?>
        </div>
    <?php endif; ?>
    <?php if ($isLate): ?>
        <div class="alert alert-warning small"><i class="bi bi-clock-history me-1"></i>
            เลยกำหนดส่งของสัปดาห์นี้แล้ว (ภายใน <?= e(thai_date(advisor_deadline($meetDate))) ?>) รายงานจะถูกบันทึกเป็น <span class="badge badge-late">ส่งช้า</span></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" novalidate data-confirm="<?= $rep ? 'ยืนยันบันทึกการแก้ไข?' : 'ยืนยันส่งรายงาน?' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($rep['id'] ?? 0) ?>">
        <input type="hidden" name="group_id" value="<?= (int)$advGroup['id'] ?>">
        <input type="hidden" name="meet_date" value="<?= e($meetDate) ?>">

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>ข้อมูลการพบนักเรียน</div>
            <div class="card-body">
                <div class="row g-3 mb-2">
                    <div class="col-md-4"><div class="small text-muted">กลุ่มเรียน</div><div class="fw-semibold"><?= e($advGroup['group_name']) ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">แผนก</div><div><?= e($advGroup['department_name'] ?? '-') ?></div></div>
                    <div class="col-md-4"><div class="small text-muted">วันที่พบนักเรียน</div><div class="fw-semibold"><?= e(thai_date($meetDate, false, true)) ?> (วันพุธ)</div></div>
                </div>
                <hr>
                <div class="fw-semibold mb-2">จำนวนนักเรียน (คน)</div>
                <div class="row g-2">
                    <?php foreach ([['total_students', 'ทั้งหมด *', (string)$advGroup['student_count']], ['present_count', 'มา *', ''], ['late_count', 'มาสาย *', '0'],
                                    ['leave_count', 'ลา *', '0'], ['absent_count', 'ขาด *', '0']] as [$k, $label, $def]): ?>
                        <div class="col-6 col-md">
                            <label class="form-label small" for="<?= $k ?>"><?= e($label) ?></label>
                            <input type="number" class="form-control advisor-count<?= $invalid($k) ?>" name="<?= $k ?>" id="<?= $k ?>" min="0" max="200" inputmode="numeric"
                                   value="<?= e($v($k, $def)) ?>" required><?= $err($k) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="small mt-2" id="countCheck"></div>
                <?= $err('counts') ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-chat-dots me-2"></i>เนื้อหาการพบนักเรียน</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="topics">เรื่องที่พูดคุย / แจ้งนักเรียน *</label>
                    <textarea class="form-control<?= $invalid('topics') ?>" name="topics" id="topics" rows="5" maxlength="5000"
                              placeholder="เช่น แจ้งกำหนดการสอบกลางภาค, ติดตามการเข้าเรียน, อบรมคุณธรรมจริยธรรม, กิจกรรมโฮมรูม"><?= e($v('topics')) ?></textarea>
                    <?= $err('topics') ?>
                </div>
                <div>
                    <label class="form-label fw-semibold" for="problems">นักเรียนที่มีปัญหาและการช่วยเหลือ</label>
                    <textarea class="form-control<?= $invalid('problems') ?>" name="problems" id="problems" rows="4" maxlength="5000"
                              placeholder="ระบุปัญหาที่พบ (เช่น ขาดเรียนบ่อย ปัญหาการเรียน ปัญหาครอบครัว) และวิธีช่วยเหลือ/ส่งต่อ — ไม่มีให้เว้นว่าง"><?= e($v('problems')) ?></textarea>
                    <?= $err('problems') ?>
                    <div class="form-text">ข้อมูลนักเรียนเป็นข้อมูลส่วนบุคคล โปรดระบุเท่าที่จำเป็นต่อการช่วยเหลือ</div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-images me-2"></i>ภาพถ่ายการพบนักเรียน * (1–<?= ADVISOR_MAX_IMAGES ?> ภาพ, ภาพละไม่เกิน 5MB)</div>
            <div class="card-body">
                <?php if ($images): ?>
                    <div class="row g-2 mb-3">
                        <?php foreach ($images as $im): ?>
                            <div class="col-6 col-md-3 text-center">
                                <img src="<?= e(url('advisor_image.php?id=' . (int)$im['id'])) ?>" class="img-fluid rounded border mb-1" style="aspect-ratio:4/3;object-fit:cover;width:100%" alt="">
                                <button type="submit" form="delImg<?= (int)$im['id'] ?>" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash"></i> ลบ</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="row g-2">
                    <div class="col-sm-6">
                        <label class="btn btn-accent w-100 mb-0" for="imagesCamera"><i class="bi bi-camera me-1"></i>ถ่ายภาพจากกล้อง</label>
                        <input type="file" class="d-none" name="images[]" id="imagesCamera" accept="image/*" capture="environment"
                               data-resize-images="1600" data-preview="#imgPreview">
                    </div>
                    <div class="col-sm-6">
                        <label class="btn btn-outline-primary w-100 mb-0" for="images"><i class="bi bi-images me-1"></i>เลือกภาพจากเครื่อง</label>
                        <input type="file" class="d-none" name="images[]" id="images" multiple accept="image/jpeg,image/png,image/webp,image/*"
                               data-resize-images="1600" data-preview="#imgPreview">
                    </div>
                </div>
                <div class="form-text">ภาพจากโทรศัพท์จะถูกย่อขนาดอัตโนมัติก่อนอัปโหลด · รองรับ JPG/PNG/WEBP</div>
                <?= $err('images') ?>
                <div id="imgPreview" class="row g-2 mt-2"></div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <a href="<?= e(url($rep ? $self . '?id=' . (int)$rep['id'] : $self . '?ay=' . (int)$advGroup['academic_year_id'])) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังบันทึก..."><i class="bi bi-send me-1"></i> <?= $rep ? 'บันทึกและส่งตรวจ' : 'ส่งรายงาน' ?></button>
        </div>
    </form>
    <?php foreach ($images as $im): ?>
        <form method="post" id="delImg<?= (int)$im['id'] ?>" data-confirm="ลบภาพนี้?" data-confirm-icon="warning" class="d-none">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>">
        </form>
    <?php endforeach; ?>
    <?php
    // ตรวจผลรวมจำนวนนักเรียนขณะกรอก
    $pageScripts = '(function () {
        var box = document.getElementById("countCheck");
        function val(id) { return parseInt(document.getElementById(id).value, 10) || 0; }
        function check() {
            var total = val("total_students"), sum = val("present_count") + val("late_count") + val("leave_count") + val("absent_count");
            box.className = "small mt-2 " + (sum === total ? "text-success" : "text-danger");
            box.textContent = "รวม มา + มาสาย + ลา + ขาด = " + sum + " / " + total + " คน" + (sum === total ? " ✓" : " (ยังไม่ตรงกับจำนวนทั้งหมด)");
        }
        document.querySelectorAll(".advisor-count").forEach(function (el) { el.addEventListener("input", check); });
        check();
    })();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารายการ: ตารางวันพุธของภาคเรียน
// =====================================================================
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$ay = advisor_academic_year(get_int('ay'));
$ayId = (int)($ay['id'] ?? 0);
$groups = $ay ? advisor_teacher_groups($uid, $ayId) : [];
$summary = advisor_summary($ay, 0, $uid);
$mine = $summary['teachers'][$uid] ?? null;

// วันพุธที่ถึงแล้ว (ใหม่สุดก่อน) + วันพุธถัดไป 1 สัปดาห์ (แสดงว่ายังไม่ถึง)
$allWeds = advisor_wednesdays($ay, false);
$today = date('Y-m-d');
$weeks = array_filter($allWeds, fn($d) => $d <= $today, ARRAY_FILTER_USE_KEY);
$next = array_key_first(array_filter($allWeds, fn($d) => $d > $today, ARRAY_FILTER_USE_KEY));
$weeks = array_reverse($weeks, true);
if ($next) {
    $weeks = [$next => $allWeds[$next]] + $weeks;
}

$reports = [];
foreach (db_all(
    'SELECT r.id, r.group_id, r.meet_date, r.status, r.is_late FROM advisor_reports r JOIN advisor_groups g ON g.id = r.group_id
     WHERE r.teacher_id = ? AND g.academic_year_id = ?',
    'ii',
    [$uid, $ayId]
) as $r) {
    $reports[(int)$r['group_id']][$r['meet_date']] = $r;
}

$pageTitle = 'รายงานการพบนักเรียน (ครูที่ปรึกษา)';
$pageIcon = 'bi-people';
$breadcrumbs = ['รายงานครูที่ปรึกษา' => null];
$pageActions = $groups ? '<a href="' . e(url('reports/advisor_teacher.php?teacher=' . $uid . '&ay=' . $ayId)) . '" class="btn btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i> สรุปของฉัน</a>' : '';
require APP_ROOT . '/includes/header.php';
?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
    ครูที่ปรึกษาส่งรายงานการพบนักเรียน <strong>สัปดาห์ละ 1 ครั้ง ทุกวันพุธ</strong> (1 รายงานต่อ 1 กลุ่มเรียน)
    ส่งได้ภายใน <?= advisor_late_days() ?> วันหลังวันพุธ หากเลยกำหนดยังส่งย้อนหลังได้แต่จะติดป้าย <span class="badge badge-late">ส่งช้า</span></div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= (int)$y['id'] ?>" <?= $ayId === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?>
                            <?= $y['start_date'] ? '(' . e(thai_date($y['start_date'])) . ' – ' . e(thai_date($y['end_date'])) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
</div>

<?php if (!$groups): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
        ท่านยังไม่ได้รับมอบหมายเป็นครูที่ปรึกษาในภาคเรียน <?= e(academic_label($ay)) ?>
        — กลุ่มที่ปรึกษากำหนดโดยงานครูที่ปรึกษาและแนะแนว หากข้อมูลไม่ถูกต้องกรุณาติดต่องานครูที่ปรึกษาและแนะแนว</div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ([['expected', 'ต้องส่ง (ถึงปัจจุบัน)', 'ic-navy', 'bi-calendar-week'], ['submitted', 'ส่งแล้ว', 'ic-blue', 'bi-send-check'],
                        ['missing', 'ยังไม่ส่ง', 'ic-red', 'bi-dash-circle'], ['late', 'ส่งช้า', 'ic-orange', 'bi-clock-history'],
                        ['approved', 'ผ่าน', 'ic-green', 'bi-check2-circle'], ['revision', 'ให้แก้ไข', 'ic-yellow', 'bi-pencil-square']] as [$k, $label, $ic, $icon]): ?>
            <div class="col-6 col-md-4 col-xl-2">
                <div class="stat-card">
                    <div class="stat-icon <?= $ic ?>" style="width:40px;height:40px;font-size:1.2rem"><i class="bi <?= $icon ?>"></i></div>
                    <div><div class="stat-value"><?= (int)($mine[$k] ?? 0) ?></div><div class="stat-label"><?= e($label) ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($ay['start_date'])): ?>
        <div class="alert alert-warning">ภาคเรียนนี้ยังไม่ได้กำหนดวันเปิด-ปิดภาคเรียน จึงยังไม่สามารถคำนวณวันพุธได้ กรุณาแจ้งผู้ดูแลระบบ</div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="bi bi-calendar-week me-2"></i>วันพุธของภาคเรียน <?= e(academic_label($ay)) ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr><th style="width:180px">วันพุธ</th>
                        <?php foreach ($groups as $g): ?><th><?= e($g['group_name']) ?> <span class="text-muted small fw-normal">(<?= (int)$g['student_count'] ?> คน)</span></th><?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if (!$weeks): ?>
                        <tr><td colspan="<?= count($groups) + 1 ?>" class="text-center text-muted py-4">
                            ยังไม่ถึงวันพุธแรกของภาคเรียน<?= $ay['start_date'] ? ' (เปิดภาคเรียน ' . e(thai_date($ay['start_date'])) . ')' : '' ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($weeks as $date => $skip): ?>
                        <tr class="<?= $date === $next ? 'table-light' : '' ?>">
                            <td class="text-nowrap"><?= e(advisor_date_label($date)) ?></td>
                            <?php if ($skip !== null): ?>
                                <td colspan="<?= count($groups) ?>"><span class="badge bg-secondary"><i class="bi bi-calendar-x me-1"></i>งดพบ</span>
                                    <span class="small text-muted"><?= e($skip) ?></span></td>
                            <?php elseif ($date > $today): ?>
                                <td colspan="<?= count($groups) ?>" class="small text-muted"><i class="bi bi-hourglass me-1"></i>สัปดาห์ถัดไป (ยังไม่ถึงกำหนด)</td>
                            <?php else: ?>
                                <?php foreach ($groups as $g): $r = $reports[(int)$g['id']][$date] ?? null; ?>
                                    <td>
                                        <?php if ($r): ?>
                                            <?= status_badge($r['status'], (bool)$r['is_late']) ?>
                                            <a href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-link">
                                                <?= $r['status'] === 'revision' ? 'แก้ไข' : 'ดู' ?></a>
                                        <?php else: ?>
                                            <?= status_badge('not_submitted') ?>
                                            <?php if (advisor_is_late_now($date)): ?><span class="small text-danger ms-1">เลยกำหนด</span><?php endif; ?>
                                            <a href="<?= e(url($self . '?group=' . (int)$g['id'] . '&date=' . $date)) ?>" class="btn btn-sm btn-accent ms-1">
                                                <i class="bi bi-send"></i> ส่งรายงาน</a>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
