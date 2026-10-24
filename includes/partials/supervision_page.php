<?php
/**
 * ไฟล์: includes/partials/supervision_page.php
 * หน้านิเทศการสอน (ใช้ร่วมกันระหว่าง admin และหัวหน้าแผนก/ผู้นิเทศ)
 *
 * ตัวแปรที่ต้องเตรียม: $user = require_role('admin') หรือ require_role('supervisor')
 *
 * โหมด:
 *   (ไม่มี)        รายการตารางนิเทศ + ตัวกรอง
 *   ?new=1         สร้างตารางนิเทศ
 *   ?edit=id       แก้ไขตารางนิเทศ
 *   ?record=id     บันทึก/แก้ไขผลการนิเทศ (แบบประเมินออนไลน์ 5 ระดับ)
 *   ?view=id       ดูผลการนิเทศ
 *
 * ขอบเขต supervisor: ตารางที่ตนเองเป็นผู้นิเทศ หรือครูในแผนกตนเอง
 */
$isAdmin = $user['role'] === 'admin';
$uid = (int)$user['id'];
$deptId = (int)$user['department_id'];
$self = $isAdmin ? 'admin/supervision.php' : 'supervisor/supervision.php';
$activeMenu = $self;
$errors = [];
$maxImages = 10;
$maxImageBytes = 5 * 1024 * 1024;

/** ครูที่เลือกได้ในตารางนิเทศ */
function schedulable_teachers(bool $isAdmin, int $deptId, int $uid): array
{
    return db_all(
        "SELECT u.id, u.prefix, u.first_name, u.last_name, d.name AS department_name
         FROM users u LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.status = 'active' AND u.role IN ('teacher','supervisor')
           AND (? = 1 OR (u.department_id = ? AND u.id <> ?))
         ORDER BY d.name, u.first_name",
        'iii',
        [$isAdmin ? 1 : 0, $deptId, $uid]
    );
}

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- บันทึกตารางนิเทศ (สร้าง/แก้ไข) ----------
    if ($action === 'save_schedule') {
        $id = (int)post('id');
        $old = $id ? schedule_fetch($id) : null;
        if ($id && (!$old || !can_manage_schedule($user, $old))) {
            abort_page(403, 'ไม่มีสิทธิ์แก้ไขตารางนี้');
        }
        if ($old && $old['status'] === 'completed') {
            flash('error', 'ตารางนี้บันทึกผลแล้ว ไม่สามารถแก้ไขได้');
            redirect($self);
        }
        $d = [
            'teacher_id'       => (int)post('teacher_id'),
            'supervisor_id'    => (int)post('supervisor_id'),
            'academic_year_id' => (int)post('academic_year_id'),
            'subject_name'     => mb_substr(post('subject_name'), 0, 200),
            'classroom'        => mb_substr(post('classroom'), 0, 100),
            'supervise_date'   => post('supervise_date'),
            'start_time'       => post('start_time'),
            'end_time'         => post('end_time'),
            'note'             => mb_substr(post('note'), 0, 500),
        ];
        $teacherIds = array_map('intval', array_column(schedulable_teachers($isAdmin, $deptId, $uid), 'id'));
        $supIds = array_map('intval', array_column(supervisor_options(), 'id'));
        $ayIds = array_map('intval', array_column(db_all('SELECT id FROM academic_years'), 'id'));

        if (!in_array($d['teacher_id'], $teacherIds, true)) {
            $errors['teacher_id'] = 'กรุณาเลือกครูผู้รับการนิเทศ';
        }
        if (!in_array($d['supervisor_id'], $supIds, true)) {
            $errors['supervisor_id'] = 'กรุณาเลือกผู้นิเทศ';
        } elseif ($d['supervisor_id'] === $d['teacher_id']) {
            $errors['supervisor_id'] = 'ผู้นิเทศต้องไม่ใช่คนเดียวกับผู้รับการนิเทศ';
        }
        if (!in_array($d['academic_year_id'], $ayIds, true)) {
            $errors['academic_year_id'] = 'กรุณาเลือกภาคเรียน';
        }
        // รายวิชาต้องเป็นวิชาที่แผนการจัดการเรียนรู้ "ผ่าน" แล้ว (แก้ไขตารางเดิมโดยไม่เปลี่ยนวิชาได้)
        $keepOld = $old && $old['subject_name'] === $d['subject_name'] && (int)$old['teacher_id'] === $d['teacher_id']
            && (int)$old['academic_year_id'] === $d['academic_year_id'];
        if ($d['subject_name'] === '') {
            $errors['subject_name'] = 'กรุณาเลือกรายวิชา';
        } elseif (!$keepOld && !isset($errors['teacher_id'])
            && !approved_plan_subject_ok($d['teacher_id'], $d['academic_year_id'], $d['subject_name'])) {
            $errors['subject_name'] = 'รายวิชาที่นิเทศต้องเป็นวิชาที่แผนการจัดการเรียนรู้ของครูผ่านแล้วในภาคเรียนนี้';
        }
        $dt = DateTime::createFromFormat('Y-m-d', $d['supervise_date']);
        if (!$dt || $dt->format('Y-m-d') !== $d['supervise_date']) {
            $errors['supervise_date'] = 'กรุณาระบุวันที่นิเทศ';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $d['start_time']) || !preg_match('/^\d{2}:\d{2}$/', $d['end_time'])) {
            $errors['start_time'] = 'กรุณาระบุเวลาเริ่ม-สิ้นสุด';
        } elseif ($d['end_time'] <= $d['start_time']) {
            $errors['start_time'] = 'เวลาสิ้นสุดต้องหลังเวลาเริ่ม';
        }
        // ตรวจเวลาชนกันของผู้นิเทศ หรือของครูในวันเดียวกัน
        if (!$errors) {
            $clash = db_one(
                "SELECT sc.id, CONCAT(t.first_name, ' ', t.last_name) AS tname FROM supervision_schedules sc
                 JOIN users t ON t.id = sc.teacher_id
                 WHERE sc.status <> 'cancelled' AND sc.id <> ? AND sc.supervise_date = ?
                   AND (sc.supervisor_id = ? OR sc.teacher_id = ?)
                   AND sc.start_time < ? AND sc.end_time > ? LIMIT 1",
                'isiiss',
                [$id, $d['supervise_date'], $d['supervisor_id'], $d['teacher_id'], $d['end_time'] . ':00', $d['start_time'] . ':00']
            );
            if ($clash) {
                $errors['start_time'] = 'ช่วงเวลานี้ชนกับตารางนิเทศอื่น (' . $clash['tname'] . ')';
            }
        }

        if (!$errors) {
            if ($old) {
                db_exec(
                    'UPDATE supervision_schedules SET teacher_id = ?, supervisor_id = ?, academic_year_id = ?, subject_name = ?, classroom = ?,
                            supervise_date = ?, start_time = ?, end_time = ?, note = ? WHERE id = ?',
                    'iiissssssi',
                    [$d['teacher_id'], $d['supervisor_id'], $d['academic_year_id'], $d['subject_name'], $d['classroom'],
                        $d['supervise_date'], $d['start_time'], $d['end_time'], $d['note'], $id]
                );
                log_activity('schedule_update', 'แก้ไขตารางนิเทศ #' . $id);
                flash('success', 'บันทึกการแก้ไขตารางนิเทศแล้ว');
            } else {
                $subjectId = db_value('SELECT id FROM subjects WHERE CONCAT(code, \' \', name) = ? LIMIT 1', 's', [$d['subject_name']]);
                $id = db_insert(
                    'INSERT INTO supervision_schedules (academic_year_id, teacher_id, supervisor_id, subject_id, subject_name, classroom,
                                                        supervise_date, start_time, end_time, note, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'iiiissssssi',
                    [$d['academic_year_id'], $d['teacher_id'], $d['supervisor_id'], $subjectId ? (int)$subjectId : null, $d['subject_name'],
                        $d['classroom'], $d['supervise_date'], $d['start_time'], $d['end_time'], $d['note'], $uid]
                );
                log_activity('schedule_create', 'สร้างตารางนิเทศ #' . $id . ' วันที่ ' . $d['supervise_date']);
                flash('success', 'สร้างตารางนิเทศเรียบร้อยแล้ว');
            }
            redirect($self);
        }
    }

    // ---------- ยกเลิก / ลบตาราง ----------
    if (in_array($action, ['cancel', 'restore', 'delete'], true)) {
        $sc = schedule_fetch((int)post('id'));
        if (!$sc || !can_manage_schedule($user, $sc)) {
            abort_page(403, 'ไม่มีสิทธิ์จัดการตารางนี้');
        }
        if ($sc['result_id']) {
            flash('error', 'ตารางนี้มีผลการนิเทศแล้ว ไม่สามารถยกเลิกหรือลบได้');
        } elseif ($action === 'cancel') {
            db_exec("UPDATE supervision_schedules SET status = 'cancelled' WHERE id = ?", 'i', [(int)$sc['id']]);
            log_activity('schedule_cancel', 'ยกเลิกตารางนิเทศ #' . (int)$sc['id']);
            flash('success', 'ยกเลิกตารางนิเทศแล้ว');
        } elseif ($action === 'restore') {
            db_exec("UPDATE supervision_schedules SET status = 'scheduled' WHERE id = ?", 'i', [(int)$sc['id']]);
            flash('success', 'คืนสถานะตารางนิเทศแล้ว');
        } else {
            db_exec('DELETE FROM supervision_schedules WHERE id = ?', 'i', [(int)$sc['id']]);
            log_activity('schedule_delete', 'ลบตารางนิเทศ #' . (int)$sc['id']);
            flash('success', 'ลบตารางนิเทศแล้ว');
        }
        redirect($self);
    }

    // ---------- บันทึกผลการนิเทศ ----------
    if ($action === 'save_result') {
        $sc = schedule_fetch((int)post('schedule_id'));
        if (!$sc || !can_record_schedule($user, $sc)) {
            abort_page(403, 'ไม่มีสิทธิ์บันทึกผลการนิเทศนี้', 'เฉพาะผู้นิเทศที่ได้รับมอบหมายหรือผู้ดูแลระบบ และครูต้องยังไม่กดรับทราบผล');
        }
        $criteria = supervision_criteria_active();
        $scoresIn = $_POST['score'] ?? [];
        $scores = [];
        foreach ($criteria as $c) {
            $v = (int)($scoresIn[$c['id']] ?? 0);
            if ($v < 1 || $v > 5) {
                $errors['score'] = 'กรุณาให้คะแนนให้ครบทุกข้อ';
            }
            $scores[] = ['c' => $c, 'score' => $v];
        }
        if (!$criteria) {
            $errors['score'] = 'ยังไม่มีหัวข้อแบบประเมิน กรุณาให้ผู้ดูแลระบบเพิ่มหัวข้อก่อน';
        }
        $strengths = mb_substr(post('strengths'), 0, 5000);
        $suggestions = mb_substr(post('suggestions'), 0, 5000);

        $newFiles = normalize_multi_files($_FILES['images'] ?? null);
        $existingImages = $sc['result_id'] ? count(supervision_images((int)$sc['result_id'])) : 0;
        if (count($newFiles) + $existingImages > $maxImages) {
            $errors['images'] = 'แนบรูปได้สูงสุด ' . $maxImages . ' รูป';
        }

        $uploaded = [];
        if (!$errors) {
            foreach ($newFiles as $f) {
                $up = handle_upload($f, 'supervision', ['jpg', 'jpeg', 'png', 'webp'], $maxImageBytes);
                if (!$up['ok']) {
                    $errors['images'] = $f['name'] . ': ' . $up['error'];
                    break;
                }
                $uploaded[] = $up;
            }
            if ($errors) {
                foreach ($uploaded as $up) {
                    delete_upload('supervision', $up['stored_name']);
                }
                $uploaded = [];
            }
        }

        if (!$errors) {
            $avg = round(array_sum(array_column($scores, 'score')) / count($scores), 2);
            $level = quality_level($avg);
            db()->begin_transaction();
            try {
                db_exec(
                    'INSERT INTO supervision_results (schedule_id, supervisor_id, avg_score, quality_level, strengths, suggestions)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE supervisor_id = VALUES(supervisor_id), avg_score = VALUES(avg_score),
                        quality_level = VALUES(quality_level), strengths = VALUES(strengths), suggestions = VALUES(suggestions)',
                    'iidsss',
                    [(int)$sc['id'], (int)$sc['supervisor_id'], $avg, $level, $strengths, $suggestions]
                );
                $resultId = (int)db_value('SELECT id FROM supervision_results WHERE schedule_id = ?', 'i', [(int)$sc['id']]);
                db_exec('DELETE FROM supervision_scores WHERE result_id = ?', 'i', [$resultId]);
                foreach ($scores as $s) {
                    db_insert(
                        'INSERT INTO supervision_scores (result_id, criteria_id, category, criteria_title, score) VALUES (?, ?, ?, ?, ?)',
                        'iissi',
                        [$resultId, (int)$s['c']['id'], $s['c']['category'], $s['c']['title'], $s['score']]
                    );
                }
                foreach ($uploaded as $up) {
                    db_insert(
                        'INSERT INTO supervision_images (result_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)',
                        'isssi',
                        [$resultId, $up['original_name'], $up['stored_name'], $up['mime'], $up['size']]
                    );
                }
                db_exec("UPDATE supervision_schedules SET status = 'completed' WHERE id = ?", 'i', [(int)$sc['id']]);
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollback();
                foreach ($uploaded as $up) {
                    delete_upload('supervision', $up['stored_name']);
                }
                throw $ex;
            }
            log_activity('supervision_record', 'บันทึกผลนิเทศ ' . $sc['teacher_name'] . ' เฉลี่ย ' . $avg . ' (' . $level . ')');
            flash('success', 'บันทึกผลการนิเทศแล้ว คะแนนเฉลี่ย ' . number_format($avg, 2) . ' ระดับ "' . $level . '"');
            redirect($self . '?view=' . (int)$sc['id']);
        }
        $_GET['record'] = (string)$sc['id'];
    }

    // ---------- ลบรูปหลักฐาน ----------
    if ($action === 'delete_image') {
        $img = db_one(
            'SELECT i.*, r.schedule_id FROM supervision_images i JOIN supervision_results r ON r.id = i.result_id WHERE i.id = ?',
            'i',
            [(int)post('image_id')]
        );
        $sc = $img ? schedule_fetch((int)$img['schedule_id']) : null;
        if (!$sc || !can_record_schedule($user, $sc)) {
            abort_page(403, 'ไม่มีสิทธิ์ลบรูปนี้');
        }
        db_exec('DELETE FROM supervision_images WHERE id = ?', 'i', [(int)$img['id']]);
        delete_upload('supervision', $img['stored_name']);
        log_activity('supervision_image_delete', 'ลบรูปหลักฐาน #' . (int)$img['id'] . ' ของการนิเทศ #' . (int)$sc['id'] . ' ' . $sc['teacher_name']);
        flash('success', 'ลบรูปแล้ว');
        redirect($self . '?record=' . (int)$sc['id']);
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

// =====================================================================
// ?view= ดูผลการนิเทศ
// =====================================================================
if (get_int('view') > 0) {
    $sc = schedule_fetch(get_int('view'));
    if (!$sc || !can_view_schedule($user, $sc)) {
        abort_page(404, 'ไม่พบตารางนิเทศ');
    }
    $pageTitle = 'ผลการนิเทศการสอน';
    $pageIcon = 'bi-person-video3';
    $breadcrumbs = ['นิเทศโดยหัวหน้าแผนก' => $self, $sc['teacher_name'] => null];
    ob_start(); ?>
    <?php if (can_record_schedule($user, $sc)): ?>
        <a href="<?= e(url($self . '?record=' . (int)$sc['id'])) ?>" class="btn btn-outline-primary"><i class="bi bi-pencil-square me-1"></i><?= $sc['result_id'] ? 'แก้ไขผล' : 'บันทึกผล' ?></a>
    <?php endif; ?>
    <?php if ($sc['result_id']): ?>
        <a href="<?= e(url('reports/supervision_print.php?id=' . (int)$sc['id'])) ?>" target="_blank" class="btn btn-accent"><i class="bi bi-printer me-1"></i>พิมพ์ / PDF</a>
    <?php endif; ?>
    <?php
    $pageActions = ob_get_clean();
    require APP_ROOT . '/includes/header.php';
    require APP_ROOT . '/includes/partials/supervision_result.php';
    echo '<a href="' . e(url($self)) . '" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// ?record= บันทึกผลการนิเทศ
// =====================================================================
if (get_int('record') > 0) {
    $sc = schedule_fetch(get_int('record'));
    if (!$sc || !can_view_schedule($user, $sc)) {
        abort_page(404, 'ไม่พบตารางนิเทศ');
    }
    if (!can_record_schedule($user, $sc)) {
        flash('warning', $sc['acknowledged_at'] ? 'ครูรับทราบผลแล้ว ไม่สามารถแก้ไขผลได้' : 'ท่านไม่ใช่ผู้นิเทศของตารางนี้');
        redirect($self . '?view=' . (int)$sc['id']);
    }
    $criteria = supervision_criteria_active();
    $oldScores = $sc['result_id'] ? supervision_scores((int)$sc['result_id']) : [];
    $images = $sc['result_id'] ? supervision_images((int)$sc['result_id']) : [];
    $postedScores = $_POST['score'] ?? [];
    $scoreVal = function (int $cid) use ($postedScores, $oldScores): int {
        if (is_post()) {
            return (int)($postedScores[$cid] ?? 0);
        }
        return (int)($oldScores[$cid]['score'] ?? 0);
    };

    $pageTitle = ($sc['result_id'] ? 'แก้ไข' : 'บันทึก') . 'ผลการนิเทศ';
    $pageIcon = 'bi-clipboard2-check';
    $breadcrumbs = ['นิเทศโดยหัวหน้าแผนก' => $self, $sc['teacher_name'] => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="alert alert-light border d-flex flex-wrap gap-3 small">
        <span><i class="bi bi-person me-1 text-accent"></i><strong><?= e($sc['teacher_name']) ?></strong> (<?= e($sc['department_name'] ?? '-') ?>)</span>
        <span><i class="bi bi-calendar-event me-1"></i><?= e(thai_date($sc['supervise_date'], false, true)) ?> <?= e(substr($sc['start_time'], 0, 5)) ?>–<?= e(substr($sc['end_time'], 0, 5)) ?> น.</span>
        <span><i class="bi bi-book me-1"></i><?= e($sc['subject_name']) ?> · <?= e($sc['classroom']) ?></span>
        <span><i class="bi bi-person-check me-1"></i>ผู้นิเทศ: <?= e($sc['supervisor_name']) ?></span>
    </div>

    <form method="post" enctype="multipart/form-data" novalidate data-confirm="ยืนยันบันทึกผลการนิเทศ?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_result">
        <input type="hidden" name="schedule_id" value="<?= (int)$sc['id'] ?>">

        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <i class="bi bi-ui-checks-grid"></i> แบบประเมินการนิเทศการสอน (มาตราส่วน 5 ระดับ)
                <span class="ms-auto small fw-normal">คะแนนเฉลี่ย: <strong id="liveAvg">-</strong> <span id="liveLevel" class="badge bg-secondary">-</span></span>
            </div>
            <?php if (isset($errors['score'])): ?><div class="alert alert-danger m-3 mb-0"><?= e($errors['score']) ?></div><?php endif; ?>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle">
                    <thead>
                    <tr class="text-center">
                        <th class="text-start">รายการประเมิน</th>
                        <?php foreach (score_labels() as $n => $label): ?><th style="width:72px"><?= $n ?><div class="small fw-normal"><?= e($label) ?></div></th><?php endforeach; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php $lastCat = null; $i = 0; foreach ($criteria as $c): $i++; ?>
                        <?php if ($c['category'] !== $lastCat): $lastCat = $c['category']; ?>
                            <tr class="table-light"><td colspan="6" class="fw-semibold text-primary-th"><?= e($c['category']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="<?= is_post() && $scoreVal((int)$c['id']) === 0 ? 'table-danger' : '' ?>">
                            <td><?= $i ?>. <?= e($c['title']) ?></td>
                            <?php foreach (array_keys(score_labels()) as $n): ?>
                                <td class="text-center">
                                    <input class="form-check-input score-input" type="radio" name="score[<?= (int)$c['id'] ?>]" value="<?= $n ?>"
                                           aria-label="<?= e($c['title']) ?> = <?= $n ?>" <?= $scoreVal((int)$c['id']) === $n ? 'checked' : '' ?>>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="strengths"><i class="bi bi-star text-success me-1"></i>จุดเด่น</label>
                <textarea class="form-control" name="strengths" id="strengths" rows="5" maxlength="5000"><?= e(is_post() ? post('strengths') : (string)$sc['strengths']) ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold" for="suggestions"><i class="bi bi-lightbulb text-accent me-1"></i>ข้อเสนอแนะ</label>
                <textarea class="form-control" name="suggestions" id="suggestions" rows="5" maxlength="5000"><?= e(is_post() ? post('suggestions') : (string)$sc['suggestions']) ?></textarea>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-images me-2"></i>รูปภาพหลักฐาน (สูงสุด <?= $maxImages ?> รูป, รูปละไม่เกิน 5MB)</div>
            <div class="card-body">
                <?php if ($images): ?>
                    <div class="row g-2 mb-3">
                        <?php foreach ($images as $im): ?>
                            <div class="col-6 col-md-2 text-center">
                                <img src="<?= e(url('supervision_image.php?id=' . (int)$im['id'])) ?>" class="img-fluid rounded border mb-1" style="aspect-ratio:4/3;object-fit:cover;width:100%" alt="">
                                <button type="submit" form="delImg<?= (int)$im['id'] ?>" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash"></i> ลบ</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <!-- 2 ช่อง: ถ่ายจากกล้องโทรศัพท์ทันที / เลือกจากคลังภาพ (ส่งชื่อ images[] เดียวกัน) -->
                <div class="row g-2">
                    <div class="col-sm-6">
                        <label class="btn btn-accent w-100 mb-0" for="imagesCamera"><i class="bi bi-camera me-1"></i>ถ่ายภาพจากกล้อง</label>
                        <input type="file" class="d-none" name="images[]" id="imagesCamera" accept="image/*" capture="environment"
                               data-resize-images="1600" data-preview="#imgPreview">
                    </div>
                    <div class="col-sm-6">
                        <label class="btn btn-outline-primary w-100 mb-0" for="images"><i class="bi bi-images me-1"></i>เลือกรูปจากเครื่อง (หลายรูป)</label>
                        <input type="file" class="d-none" name="images[]" id="images" multiple accept="image/jpeg,image/png,image/webp,image/*"
                               data-resize-images="1600" data-preview="#imgPreview">
                    </div>
                </div>
                <div class="form-text">ภาพจากโทรศัพท์จะถูกย่อขนาดอัตโนมัติก่อนอัปโหลด (ประหยัดเน็ตและพื้นที่) · รองรับ JPG/PNG/WEBP</div>
                <?= $err('images') ?>
                <div id="imgPreview" class="row g-2 mt-2"></div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกผลการนิเทศ</button>
        </div>
    </form>
    <?php foreach ($images as $im): ?>
        <form method="post" id="delImg<?= (int)$im['id'] ?>" data-confirm="ลบรูปนี้?" data-confirm-icon="warning" class="d-none">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>">
        </form>
    <?php endforeach; ?>
    <?php
    // คำนวณค่าเฉลี่ย/ระดับคุณภาพสดขณะกรอก + แสดงตัวอย่างรูป
    $pageScripts = '(function () {
        var total = ' . count($criteria) . ';
        function level(a) { return a >= 4.51 ? ["ดีมาก","bg-success"] : a >= 3.51 ? ["ดี","bg-primary"] : a >= 2.51 ? ["ปานกลาง","bg-warning text-dark"] : a >= 1.51 ? ["พอใช้","badge-late"] : ["ควรปรับปรุง","bg-danger"]; }
        function calc() {
            var checked = document.querySelectorAll(".score-input:checked"), sum = 0;
            checked.forEach(function (r) { sum += parseInt(r.value, 10); });
            var el = document.getElementById("liveAvg"), lv = document.getElementById("liveLevel");
            if (!checked.length) { el.textContent = "-"; return; }
            var a = sum / checked.length, l = level(a);
            el.textContent = a.toFixed(2) + " (" + checked.length + "/" + total + " ข้อ)";
            lv.textContent = l[0]; lv.className = "badge " + l[1];
        }
        document.querySelectorAll(".score-input").forEach(function (r) { r.addEventListener("change", calc); });
        calc();
    })();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// ?new=1 / ?edit= ฟอร์มตารางนิเทศ
// =====================================================================
if (get('new') === '1' || get_int('edit') > 0 || post('action') === 'save_schedule') {
    $editing = null;
    $editId = get_int('edit') ?: (int)post('id');
    if ($editId) {
        $editing = schedule_fetch($editId);
        if (!$editing || !can_manage_schedule($user, $editing)) {
            abort_page(404, 'ไม่พบตารางนิเทศ');
        }
    }
    $teachers = schedulable_teachers($isAdmin, $deptId, $uid);
    $supervisors = supervisor_options();
    $years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
    $current = current_academic_year();
    $fv = function (string $k, string $d = '') use ($editing) {
        if (is_post()) {
            return post($k);
        }
        if ($editing) {
            $v = (string)($editing[$k] ?? '');
            return in_array($k, ['start_time', 'end_time'], true) ? substr($v, 0, 5) : $v;
        }
        return $d;
    };

    $pageTitle = $editing ? 'แก้ไขตารางนิเทศ' : 'สร้างตารางนิเทศ';
    $pageIcon = 'bi-calendar-plus';
    $breadcrumbs = ['นิเทศโดยหัวหน้าแผนก' => $self, $pageTitle => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="card">
        <div class="card-body">
            <form method="post" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_schedule">
                <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="teacher_id">ครูผู้รับการนิเทศ *</label>
                        <select class="form-select<?= $invalid('teacher_id') ?>" name="teacher_id" id="teacher_id">
                            <option value="">-- เลือกครู --</option>
                            <?php $g = null; foreach ($teachers as $t):
                                if ($g !== $t['department_name']) { if ($g !== null) echo '</optgroup>'; $g = $t['department_name']; echo '<optgroup label="' . e($g ?? 'ไม่ระบุแผนก') . '">'; } ?>
                                <option value="<?= (int)$t['id'] ?>" <?= (int)$fv('teacher_id') === (int)$t['id'] ? 'selected' : '' ?>><?= e(full_name($t)) ?></option>
                            <?php endforeach; if ($g !== null) echo '</optgroup>'; ?>
                        </select><?= $err('teacher_id') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="supervisor_id">ผู้นิเทศ *</label>
                        <select class="form-select<?= $invalid('supervisor_id') ?>" name="supervisor_id" id="supervisor_id">
                            <option value="">-- เลือกผู้นิเทศ --</option>
                            <?php foreach ($supervisors as $s): ?>
                                <option value="<?= (int)$s['id'] ?>" <?= (int)$fv('supervisor_id', $user['role'] !== 'teacher' ? (string)$uid : '') === (int)$s['id'] ? 'selected' : '' ?>>
                                    <?= e(full_name($s)) ?> (<?= e($s['role'] === 'supervisor' ? ($s['department_name'] ?? 'หัวหน้าแผนก') : role_label($s['role'])) ?>)</option>
                            <?php endforeach; ?>
                        </select><?= $err('supervisor_id') ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="academic_year_id">ภาคเรียน *</label>
                        <select class="form-select<?= $invalid('academic_year_id') ?>" name="academic_year_id" id="academic_year_id">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y['id'] ?>" <?= (int)$fv('academic_year_id', (string)($current['id'] ?? '')) === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('academic_year_id') ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="supervise_date">วันที่นิเทศ *</label>
                        <input type="date" class="form-control<?= $invalid('supervise_date') ?>" name="supervise_date" id="supervise_date" value="<?= e($fv('supervise_date', date('Y-m-d', strtotime('+7 days')))) ?>"><?= $err('supervise_date') ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="start_time">เวลาเริ่ม *</label>
                        <input type="time" class="form-control<?= $invalid('start_time') ?>" name="start_time" id="start_time" value="<?= e($fv('start_time', '09:00')) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="end_time">เวลาสิ้นสุด *</label>
                        <input type="time" class="form-control<?= $invalid('start_time') ?>" name="end_time" id="end_time" value="<?= e($fv('end_time', '11:00')) ?>">
                    </div>
                    <?php if (isset($errors['start_time'])): ?><div class="col-12 mt-0"><?= $err('start_time') ?></div><?php endif; ?>
                    <div class="col-md-8">
                        <label class="form-label" for="subject_name">รายวิชา *</label>
                        <select class="form-select<?= $invalid('subject_name') ?>" name="subject_name" id="subject_name" data-value="<?= e($fv('subject_name')) ?>">
                            <option value="">-- เลือกครูและภาคเรียนก่อน --</option>
                        </select>
                        <div class="form-text" id="subjectHint">แสดงเฉพาะรายวิชาที่แผนการจัดการเรียนรู้ของครู “ผ่าน” แล้วในภาคเรียนที่เลือก</div>
                        <?= $err('subject_name') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="classroom">ห้องเรียน/ชั้น</label>
                        <input class="form-control" name="classroom" id="classroom" maxlength="100" value="<?= e($fv('classroom')) ?>" placeholder="เช่น ชฟ.1/1 ห้อง 2301">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note">หมายเหตุ</label>
                        <input class="form-control" name="note" id="note" maxlength="500" value="<?= e($fv('note')) ?>">
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-end mt-4">
                    <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <button type="submit" class="btn btn-accent px-4"><i class="bi bi-save me-1"></i> บันทึกตารางนิเทศ</button>
                </div>
            </form>
        </div>
    </div>
    <?php
    $pageScripts = approved_plan_subject_js(approved_plan_subject_map());
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารายการ
// =====================================================================
$fAy = get_int('ay', (int)(current_academic_year()['id'] ?? 0));
$fStatus = get('status');
$fDept = $isAdmin ? get_int('dept') : 0;
$fMine = !$isAdmin && get('mine') === '1';

$where = ['(? = 0 OR sc.academic_year_id = ?)'];
$types = 'ii';
$params = [$fAy, $fAy];
if (in_array($fStatus, ['scheduled', 'completed', 'cancelled'], true)) {
    $where[] = 'sc.status = ?';
    $types .= 's';
    $params[] = $fStatus;
}
if ($fDept > 0) {
    $where[] = 't.department_id = ?';
    $types .= 'i';
    $params[] = $fDept;
}
if (!$isAdmin) {
    // ขอบเขตหัวหน้าแผนก: ตนเองเป็นผู้นิเทศ หรือครูในแผนก
    $where[] = $fMine ? 'sc.supervisor_id = ?' : '(sc.supervisor_id = ? OR (t.department_id = ? AND t.department_id IS NOT NULL))';
    $types .= $fMine ? 'i' : 'ii';
    $params = array_merge($params, $fMine ? [$uid] : [$uid, $deptId]);
}
$rows = db_all(
    "SELECT sc.*, CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name, t.department_id AS teacher_department_id,
            CONCAT(s.prefix, s.first_name, ' ', s.last_name) AS supervisor_name, d.name AS department_name,
            res.id AS result_id, res.avg_score, res.acknowledged_at
     FROM supervision_schedules sc
     JOIN users t ON t.id = sc.teacher_id
     JOIN users s ON s.id = sc.supervisor_id
     LEFT JOIN departments d ON d.id = t.department_id
     LEFT JOIN supervision_results res ON res.schedule_id = sc.id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY FIELD(sc.status, 'scheduled', 'completed', 'cancelled'), sc.supervise_date, sc.start_time",
    $types,
    $params
);
$years = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$departments = $isAdmin ? db_all('SELECT id, name FROM departments ORDER BY name') : [];
$stat = ['scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($rows as $r) {
    $stat[$r['status']]++;
}

$pageTitle = 'นิเทศการสอนโดยหัวหน้าแผนก';
$pageIcon = 'bi-person-video3';
$breadcrumbs = ['นิเทศโดยหัวหน้าแผนก' => null];
$pageActions = ($isAdmin ? '<a href="' . e(url('admin/supervision_criteria.php')) . '" class="btn btn-outline-primary"><i class="bi bi-list-check me-1"></i>หัวข้อประเมิน</a>' : '')
    . '<a href="' . e(url($self . '?new=1')) . '" class="btn btn-accent"><i class="bi bi-calendar-plus me-1"></i>สร้างตารางนิเทศ</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card"><div class="stat-icon ic-blue"><i class="bi bi-calendar-week"></i></div><div><div class="stat-value"><?= $stat['scheduled'] ?></div><div class="stat-label">รอนิเทศ</div></div></div></div>
    <div class="col-4"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-check2-circle"></i></div><div><div class="stat-value"><?= $stat['completed'] ?></div><div class="stat-label">นิเทศแล้ว</div></div></div></div>
    <div class="col-4"><div class="stat-card"><div class="stat-icon ic-navy"><i class="bi bi-x-circle"></i></div><div><div class="stat-value"><?= $stat['cancelled'] ?></div><div class="stat-label">ยกเลิก</div></div></div></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <option value="0">ทุกภาคเรียน</option>
                    <?php foreach ($years as $y): ?><option value="<?= (int)$y['id'] ?>" <?= $fAy === (int)$y['id'] ? 'selected' : '' ?>><?= e(academic_label($y)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="status">สถานะ</label>
                <select name="status" id="status" class="form-select">
                    <option value="">ทั้งหมด</option>
                    <option value="scheduled" <?= $fStatus === 'scheduled' ? 'selected' : '' ?>>รอนิเทศ</option>
                    <option value="completed" <?= $fStatus === 'completed' ? 'selected' : '' ?>>นิเทศแล้ว</option>
                    <option value="cancelled" <?= $fStatus === 'cancelled' ? 'selected' : '' ?>>ยกเลิก</option>
                </select>
            </div>
            <?php if ($isAdmin): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $fDept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <div class="col-md-4">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="mine" value="1" id="mine" <?= $fMine ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mine">เฉพาะที่ฉันเป็นผู้นิเทศ</label>
                    </div>
                </div>
            <?php endif; ?>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>วันเวลา</th><th>ผู้รับการนิเทศ</th><th>วิชา/ห้อง</th><th>ผู้นิเทศ</th><th>สถานะ</th><th>ผลการนิเทศ</th><th class="no-sort text-end">จัดการ</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $overdue = $r['status'] === 'scheduled' && $r['supervise_date'] < date('Y-m-d');
                    $canRecord = can_record_schedule($user, $r);
                    $canManage = can_manage_schedule($user, $r); ?>
                    <tr class="<?= $overdue ? 'table-warning' : '' ?>">
                        <td data-order="<?= e($r['supervise_date'] . ' ' . $r['start_time']) ?>" class="text-nowrap">
                            <?= e(thai_date($r['supervise_date'])) ?><br><small class="text-muted"><?= e(substr($r['start_time'], 0, 5)) ?>–<?= e(substr($r['end_time'], 0, 5)) ?> น.</small>
                        </td>
                        <td><?= e($r['teacher_name']) ?><br><small class="text-muted"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></small></td>
                        <td class="small"><?= e($r['subject_name']) ?><br><span class="text-muted"><?= e($r['classroom']) ?></span></td>
                        <td class="small"><?= e($r['supervisor_name']) ?></td>
                        <td><?= schedule_status_badge($r['status']) ?><?= $overdue ? '<br><small class="text-danger">เลยกำหนด</small>' : '' ?></td>
                        <td data-order="<?= e((string)$r['avg_score']) ?>">
                            <?= $r['result_id'] ? quality_badge((float)$r['avg_score']) : '<span class="text-muted">-</span>' ?>
                            <?php if ($r['result_id']): ?><br><small class="<?= $r['acknowledged_at'] ? 'text-success' : 'text-warning' ?>"><?= $r['acknowledged_at'] ? 'รับทราบแล้ว' : 'รอครูรับทราบ' ?></small><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($canRecord && $r['status'] !== 'cancelled'): ?>
                                <a href="<?= e(url($self . '?record=' . (int)$r['id'])) ?>" class="btn btn-sm btn-accent" title="บันทึกผล" data-bs-toggle="tooltip"><i class="bi bi-clipboard2-check"></i></a>
                            <?php endif; ?>
                            <a href="<?= e(url($self . '?view=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary" title="ดูรายละเอียด" data-bs-toggle="tooltip"><i class="bi bi-eye"></i></a>
                            <?php if ($r['result_id']): ?>
                                <a href="<?= e(url('reports/supervision_print.php?id=' . (int)$r['id'])) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="พิมพ์" data-bs-toggle="tooltip"><i class="bi bi-printer"></i></a>
                            <?php endif; ?>
                            <?php if ($canManage && !$r['result_id']): ?>
                                <?php if ($r['status'] === 'scheduled'): ?>
                                    <a href="<?= e(url($self . '?edit=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-secondary" title="แก้ไข" data-bs-toggle="tooltip"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <form method="post" class="d-inline" data-confirm="<?= $r['status'] === 'cancelled' ? 'คืนสถานะตารางนิเทศนี้?' : 'ยกเลิกตารางนิเทศนี้?' ?>">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $r['status'] === 'cancelled' ? 'restore' : 'cancel' ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-warning" title="<?= $r['status'] === 'cancelled' ? 'คืนสถานะ' : 'ยกเลิก' ?>" data-bs-toggle="tooltip"><i class="bi <?= $r['status'] === 'cancelled' ? 'bi-arrow-counterclockwise' : 'bi-x-circle' ?>"></i></button>
                                </form>
                                <form method="post" class="d-inline" data-confirm="ลบตารางนิเทศนี้? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
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
<?php require APP_ROOT . '/includes/footer.php'; ?>
