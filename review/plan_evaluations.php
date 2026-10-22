<?php
/**
 * ไฟล์: review/plan_evaluations.php
 * ประเมินแผนการจัดการเรียนรู้ (แบบประเมิน 3 ตอน) จากแผนที่ครูส่งในระบบ — แผนละ 1 ผล
 *   ผู้ใช้: admin และหัวหน้างานพัฒนาหลักสูตรฯ (ตามตำแหน่ง) ทุกแผนก / หัวหน้าแผนก เฉพาะแผนกตนเอง
 *   (ไม่มีพารามิเตอร์)  รายการแผน + ตัวกรอง ?ay= ?dept= ?state=
 *   ?evaluate=lesson_plan_id  ฟอร์มประเมิน (เพิ่ม/แก้ไข) — จอกว้างแบ่งซ้าย/ขวา: ไฟล์แผน (PDF แสดงในหน้า) + แบบประเมิน
 *   ?view=lesson_plan_id      ดูผลการประเมิน
 * การประเมินแยกจากสถานะตรวจรับงานเดิม (ผ่าน/ให้แก้ไข) ไม่เปลี่ยนสถานะงาน
 * บันทึกร่างได้ (กรอกไม่ครบได้ ครูยังไม่เห็น) / "ส่งผลการประเมิน" ต้องกรอกครบ แล้วครูจึงเห็นผลและกดรับทราบได้
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!is_plan_evaluator($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับ ' . PLAN_EVAL_HEAD_POSITION . ' หัวหน้าแผนกวิชา และผู้ดูแลระบบ');
}
$scopeAll = plan_eval_scope_all($user);
$uid = (int)$user['id'];
$self = 'review/plan_evaluations.php';
$activeMenu = $self;
$errors = [];

/** ดึงแผนและตรวจสิทธิ์ดู (ใช้กับทุกการกระทำที่ระบุแผน) */
$loadPlan = function (int $id) use ($user): array {
    $plan = $id > 0 ? plan_eval_fetch_plan($id) : null;
    if (!$plan || $plan['doc_type'] !== 'plan' || !can_view_plan_eval($user, $plan)) {
        abort_page(404, 'ไม่พบแผนการจัดการเรียนรู้', 'แผนนี้ไม่มีอยู่ หรืออยู่นอกแผนกที่ท่านรับผิดชอบ');
    }
    return $plan;
};

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $plan = $loadPlan((int)post('plan_id'));
    if (!can_evaluate_plan($user, $plan)) {
        log_activity('access_denied', 'พยายามประเมินแผนการจัดการเรียนรู้ #' . (int)$plan['id'] . ' นอกขอบเขต');
        abort_page(403, 'ไม่มีสิทธิ์ประเมินแผนนี้', 'หัวหน้าแผนกประเมินได้เฉพาะแผนของครูในแผนกตนเอง และไม่ประเมินแผนของตนเอง');
    }

    if (post('action') === 'delete') {
        if ($user['role'] !== 'admin' || !$plan['eval_id']) {
            abort_page(403, 'ไม่มีสิทธิ์ลบผลการประเมิน');
        }
        db_exec('DELETE FROM plan_evaluations WHERE id = ?', 'i', [(int)$plan['eval_id']]);
        log_activity('plan_eval_delete', 'ลบผลประเมินแผนฯ ' . $plan['subject_code'] . ' ของ ' . $plan['teacher_name']);
        flash('success', 'ลบผลการประเมินแล้ว');
        redirect($self);
    }

    if (post('action') === 'save') {
        $final = post('mode') !== 'draft';
        $info = [
            'program'          => in_array(post('program'), ['ปวช.', 'ปวส.'], true) ? post('program') : '',
            'subject_category' => mb_substr(post('subject_category'), 0, 150),
            'major'            => mb_substr(post('major'), 0, 150),
            'credit'           => mb_substr(post('credit'), 0, 20),
            'unit_count'       => (int)post('unit_count'),
            'plan_count'       => (int)post('plan_count'),
            'has_standard'     => post('has_standard') === '1' ? 1 : 0,
            'strengths'        => mb_substr(post('strengths'), 0, 5000),
            'improvements'     => mb_substr(post('improvements'), 0, 5000),
            'decision'         => post('decision'),
            'resubmit_by'      => null,
        ];
        // ส่งผลให้ครู: ต้องกรอกครบ / บันทึกร่าง: เว้นว่างได้ (ใช้ค่าเริ่มต้นแทน)
        if ($info['program'] === '') {
            if ($final) {
                $errors['program'] = 'กรุณาเลือกหลักสูตร';
            }
            $info['program'] = $plan['level'];
        }
        if ($info['unit_count'] < ($final ? 1 : 0) || $info['unit_count'] > 999) {
            $errors['unit_count'] = 'ระบุจำนวนหน่วย 1–999';
        }
        if ($info['plan_count'] < ($final ? 1 : 0) || $info['plan_count'] > 999) {
            $errors['plan_count'] = 'ระบุจำนวนแผน 1–999';
        }
        if (!isset(plan_eval_decisions()[$info['decision']])) {
            if ($final) {
                $errors['decision'] = 'กรุณาเลือกผลการพิจารณา';
            }
            $info['decision'] = null;
        } elseif ($info['decision'] === 'resubmit') {
            $d = post('resubmit_by');
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $errors['decision'] = 'วันที่นำเสนอใหม่ไม่ถูกต้อง';
            }
            $info['resubmit_by'] = $d !== '' ? $d : null;
        }
        $calc = plan_eval_compute(
            plan_eval_criteria_active('check'),
            plan_eval_criteria_active('quality'),
            (array)($_POST['check'] ?? []),
            (array)($_POST['score'] ?? []),
            $final
        );
        $errors += $calc['errors'];
        if (!$errors) {
            plan_eval_save($user, $plan, $info, $calc);
            log_activity('plan_eval_save', ($final ? 'ส่งผล' : 'บันทึกร่าง') . 'ประเมินแผนฯ ' . $plan['subject_code']
                . ' ของ ' . $plan['teacher_name'] . ' (เฉลี่ย ' . number_format($calc['avg'], 2) . ')');
            flash('success', $final ? 'ส่งผลการประเมินแผนการจัดการเรียนรู้แล้ว ครูจะเห็นผลและกดรับทราบได้'
                : 'บันทึกร่างแล้ว (ครูยังไม่เห็นผล) — กด "ส่งผลการประเมิน" เมื่อประเมินเสร็จ');
            redirect($self . ($final ? '?view=' : '?evaluate=') . (int)$plan['id']);
        }
        $_GET['evaluate'] = (string)$plan['id'];
    }
}

// =====================================================================
// ?view= ดูผลการประเมิน
// =====================================================================
if (get_int('view') > 0) {
    $plan = $loadPlan(get_int('view'));
    $ev = $plan['eval_id'] ? plan_eval_fetch((int)$plan['eval_id']) : null;
    $pageTitle = 'ผลการประเมินแผนการจัดการเรียนรู้';
    $pageIcon = 'bi-clipboard-data';
    $breadcrumbs = ['ประเมินแผนการจัดการเรียนรู้' => $self, $plan['subject_code'] => null];
    ob_start();
    if ($ev): ?>
        <a href="<?= e(url('reports/plan_eval_print.php?plan=' . (int)$plan['id'])) ?>" target="_blank" class="btn btn-outline-secondary"><i class="bi bi-printer me-1"></i> พิมพ์แบบประเมิน</a>
    <?php endif;
    if (can_evaluate_plan($user, $plan)): ?>
        <a href="<?= e(url($self . '?evaluate=' . (int)$plan['id'])) ?>" class="btn btn-accent"><i class="bi bi-pencil-square me-1"></i> <?= $ev ? 'แก้ไขผลประเมิน' : 'ประเมินแผนนี้' ?></a>
    <?php endif;
    $pageActions = ob_get_clean();
    require APP_ROOT . '/includes/header.php';
    if ($ev && !plan_eval_is_final($ev)): ?>
        <div class="alert alert-warning"><i class="bi bi-pencil me-1"></i>ผลนี้ยังเป็น <strong>ร่าง</strong> ครูยังไม่เห็นผล — กด "แก้ไขผลประเมิน" แล้วเลือก "ส่งผลการประเมิน" เมื่อประเมินเสร็จ</div>
    <?php endif;
    require APP_ROOT . '/includes/partials/plan_eval_result.php';
    ?>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
        <a href="<?= e(url(review_page($user['role'], 'lesson_plan') . '?id=' . (int)$plan['id'])) ?>" class="btn btn-outline-primary"><i class="bi bi-files me-1"></i> ไฟล์แผนที่ครูส่ง</a>
        <?php if ($ev && $user['role'] === 'admin'): ?>
            <form method="post" class="ms-auto" data-confirm="ลบผลการประเมินแผนนี้? (ลบแล้วกู้คืนไม่ได้)" data-confirm-icon="warning">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
                <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i> ลบผลการประเมิน</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// ?evaluate= ฟอร์มประเมิน
// =====================================================================
if (get_int('evaluate') > 0) {
    $plan = $loadPlan(get_int('evaluate'));
    if (!can_evaluate_plan($user, $plan)) {
        flash('warning', (int)$plan['teacher_id'] === $uid ? 'ไม่สามารถประเมินแผนของตนเองได้' : 'ท่านประเมินได้เฉพาะแผนของครูในแผนกตนเอง');
        redirect($self . '?view=' . (int)$plan['id']);
    }
    $ev = $plan['eval_id'] ? plan_eval_fetch((int)$plan['eval_id']) : null;
    $oldItems = $ev ? plan_eval_items((int)$ev['id']) : ['check' => [], 'quality' => []];
    $checkCriteria = plan_eval_criteria_active('check');
    $qualityCriteria = plan_eval_criteria_active('quality');
    // ค่าเริ่มต้นข้อมูลทั่วไป: ผลเดิม > ผลประเมินครั้งก่อนของครูคนเดียวกัน > ข้อมูลจากแผน/รายวิชา
    $prev = $ev ?: db_one('SELECT subject_category, major FROM plan_evaluations WHERE teacher_id = ? ORDER BY id DESC LIMIT 1', 'i', [(int)$plan['teacher_id']]);
    $defaults = [
        'program' => $ev['program'] ?? $plan['level'], 'subject_category' => $prev['subject_category'] ?? '', 'major' => $prev['major'] ?? '',
        'credit' => $ev['credit'] ?? $plan['subject_credit'], 'unit_count' => $ev['unit_count'] ?? '', 'plan_count' => $ev['plan_count'] ?? '',
        'has_standard' => (string)($ev['has_standard'] ?? ''), 'strengths' => $ev['strengths'] ?? '', 'improvements' => $ev['improvements'] ?? '',
        'decision' => $ev['decision'] ?? '', 'resubmit_by' => $ev['resubmit_by'] ?? '',
    ];
    $fv = fn(string $k) => is_post() ? post($k) : (string)($defaults[$k] ?? '');
    $checkVal = fn(int $cid) => is_post() ? (string)($_POST['check'][$cid] ?? '') : (string)($oldItems['check'][$cid]['check_result'] ?? '');
    $scoreVal = fn(int $cid) => is_post() ? (int)($_POST['score'][$cid] ?? 0) : (int)($oldItems['quality'][$cid]['score'] ?? 0);
    $invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
    $err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
    $subjectCats = array_column(db_all("SELECT DISTINCT subject_category FROM plan_evaluations WHERE subject_category <> '' ORDER BY subject_category"), 'subject_category');
    $majors = array_column(db_all("SELECT DISTINCT major FROM plan_evaluations WHERE major <> '' ORDER BY major"), 'major');
    // ไฟล์แผนทุกฉบับ (ใหม่สุดก่อน) — เปิดผ่าน file.php ซึ่งตรวจสิทธิ์ดูงานทุกครั้ง
    $planFiles = submission_files('lesson_plan', (int)$plan['id']);

    $pageTitle = ($ev ? 'แก้ไข' : '') . 'แบบประเมินแผนการจัดการเรียนรู้';
    $pageIcon = 'bi-clipboard-data';
    $breadcrumbs = ['ประเมินแผนการจัดการเรียนรู้' => $self, $plan['subject_code'] => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="alert alert-light border d-flex flex-wrap gap-3 small">
        <span><i class="bi bi-person me-1 text-accent"></i><strong><?= e($plan['teacher_name']) ?></strong> (<?= e($plan['department_name'] ?? '-') ?>)</span>
        <span><i class="bi bi-book me-1"></i><?= e($plan['subject_code'] . ' ' . $plan['subject_name']) ?></span>
        <span><i class="bi bi-calendar3 me-1"></i>ภาคเรียน <?= $plan['ay_year'] ? e($plan['ay_semester'] . '/' . $plan['ay_year']) : '-' ?></span>
        <span><i class="bi bi-files me-1"></i>ฉบับที่ <?= (int)$plan['current_version'] ?>
            · <a href="<?= e(url(review_page($user['role'], 'lesson_plan') . '?id=' . (int)$plan['id'])) ?>" target="_blank">รายละเอียดงานที่ส่ง</a></span>
    </div>
    <?php if ($ev): ?><div class="mb-3"><?= plan_eval_status_badge($ev['status']) ?></div><?php endif; ?>
    <?php if ($ev && $ev['acknowledged_at']): ?>
        <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i>ครูรับทราบผลเดิมแล้ว เมื่อบันทึกการแก้ไข ครูจะต้องกดรับทราบผลใหม่อีกครั้ง</div>
    <?php elseif ($ev && plan_eval_is_final($ev)): ?>
        <div class="alert alert-info small"><i class="bi bi-info-circle me-1"></i>ผลนี้ส่งให้ครูแล้ว ถ้าเลือก "บันทึกร่าง" ผลจะถูกซ่อนจากครูจนกว่าจะส่งผลอีกครั้ง</div>
    <?php endif; ?>
    <?php if ($errors): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-circle me-1"></i>กรุณาตรวจสอบข้อมูล: <?= e(implode(' · ', $errors)) ?></div>
    <?php endif; ?>

    <div class="row g-3">
    <!-- ฝั่งซ้าย: ไฟล์แผนการจัดการเรียนรู้ (จอกว้างแสดง PDF ในหน้า ติดอยู่กับที่ขณะเลื่อนแบบประเมิน) -->
    <div class="col-xl-6" id="planFileCol">
        <div class="card plan-file-panel mb-0">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <i class="bi bi-file-earmark-text"></i> ไฟล์แผนการจัดการเรียนรู้
                <?php if ($planFiles): ?>
                    <select id="planFileSel" class="form-select form-select-sm w-auto ms-auto" aria-label="เลือกไฟล์แผน">
                        <?php foreach ($planFiles as $f): ?>
                            <option value="<?= (int)$f['id'] ?>" data-pdf="<?= strtolower(pathinfo($f['stored_name'], PATHINFO_EXTENSION)) === 'pdf' ? 1 : 0 ?>"
                                    data-name="<?= e($f['original_name']) ?>" <?= (int)$f['version'] === (int)$plan['current_version'] ? 'selected' : '' ?>>
                                ฉบับที่ <?= (int)$f['version'] ?><?= (int)$f['version'] === (int)$plan['current_version'] ? ' (ล่าสุด)' : '' ?> · <?= e(str_limit($f['original_name'], 40)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <a id="planFileOpen" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary" title="เปิดในแท็บใหม่"><i class="bi bi-box-arrow-up-right"></i></a>
                    <a id="planFileDl" href="#" class="btn btn-sm btn-outline-secondary" title="ดาวน์โหลด"><i class="bi bi-download"></i></a>
                    <button type="button" id="planFileHide" class="btn btn-sm btn-outline-secondary d-none d-xl-inline-block" title="ซ่อนไฟล์ (ขยายแบบประเมินเต็มความกว้าง)"><i class="bi bi-arrows-angle-expand"></i></button>
                <?php endif; ?>
            </div>
            <?php if (!$planFiles): ?>
                <div class="card-body text-muted small">ไม่พบไฟล์แผนที่ครูส่ง</div>
            <?php else: ?>
                <div class="card-body p-0 d-none d-xl-flex flex-column flex-fill">
                    <iframe id="planFileFrame" title="ไฟล์แผนการจัดการเรียนรู้" class="flex-fill w-100 border-0"></iframe>
                    <div id="planFileNoPreview" class="d-none p-4 text-center text-muted">
                        <i class="bi bi-file-earmark-word display-5 d-block mb-2"></i>
                        ไฟล์นี้ไม่ใช่ PDF จึงแสดงในหน้าไม่ได้<br>กด <i class="bi bi-download"></i> ดาวน์โหลด แล้วเปิดดูด้วยโปรแกรมในเครื่อง
                    </div>
                </div>
                <div class="card-body small text-muted d-xl-none py-2">
                    บนมือถือ/แท็บเล็ต กด <i class="bi bi-box-arrow-up-right"></i> เพื่อเปิดไฟล์แผนในแท็บใหม่ แล้วสลับกลับมาประเมินในหน้านี้
                    (บนคอมพิวเตอร์จอกว้าง ไฟล์ PDF จะแสดงคู่กับแบบประเมิน)
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ฝั่งขวา: แบบประเมิน -->
    <div class="col-xl-6" id="evalFormCol">
    <button type="button" id="planFileShow" class="btn btn-sm btn-outline-primary mb-3 d-none"><i class="bi bi-layout-split me-1"></i> แสดงไฟล์แผนคู่กับแบบประเมิน</button>
    <form method="post" novalidate id="evalForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>ข้อมูลทั่วไป</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label d-block">หลักสูตร *</label>
                        <?php foreach (['ปวช.', 'ปวส.'] as $p): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input<?= $invalid('program') ?>" type="radio" name="program" id="prog<?= e($p) ?>" value="<?= e($p) ?>" <?= $fv('program') === $p ? 'checked' : '' ?>>
                                <label class="form-check-label" for="prog<?= e($p) ?>"><?= e($p) ?></label>
                            </div>
                        <?php endforeach; ?><?= $err('program') ?>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="subject_category">ประเภทวิชา</label>
                        <input class="form-control" name="subject_category" id="subject_category" maxlength="150" list="catList" value="<?= e($fv('subject_category')) ?>" placeholder="เช่น อุตสาหกรรม">
                        <datalist id="catList"><?php foreach ($subjectCats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="major">สาขาวิชา</label>
                        <input class="form-control" name="major" id="major" maxlength="150" list="majorList" value="<?= e($fv('major')) ?>" placeholder="เช่น ช่างยนต์">
                        <datalist id="majorList"><?php foreach ($majors as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="credit">ท-ป-น</label>
                        <input class="form-control" name="credit" id="credit" maxlength="20" value="<?= e($fv('credit')) ?>" placeholder="เช่น 1-3-2">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="unit_count">จำนวนหน่วยการเรียนรู้ *</label>
                        <div class="input-group"><input type="number" class="form-control<?= $invalid('unit_count') ?>" name="unit_count" id="unit_count" min="1" max="999" value="<?= e($fv('unit_count')) ?>"><span class="input-group-text">หน่วย</span></div>
                        <?= $err('unit_count') ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="plan_count">จำนวนแผนการจัดการเรียนรู้ *</label>
                        <div class="input-group"><input type="number" class="form-control<?= $invalid('plan_count') ?>" name="plan_count" id="plan_count" min="1" max="999" value="<?= e($fv('plan_count')) ?>"><span class="input-group-text">แผน</span></div>
                        <?= $err('plan_count') ?>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label d-block">อ้างอิงมาตรฐานอาชีพ/หลักสูตรการฝึกยกระดับฝีมือ</label>
                        <?php foreach (['1' => 'มี', '0' => 'ไม่มี'] as $k => $label): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="has_standard" id="std<?= $k ?>" value="<?= $k ?>" <?= $fv('has_standard') === (string)$k ? 'checked' : '' ?>>
                                <label class="form-check-label" for="std<?= $k ?>"><?= $label ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap align-items-center gap-2"><i class="bi bi-list-check"></i> ตอนที่ 1 การตรวจสอบองค์ประกอบของแผนการจัดการเรียนรู้
                <span class="ms-auto small fw-normal">รายการที่ต้องมี "มี" ครบ: <strong id="liveCheck">-</strong></span></div>
            <div class="card-body pb-0 small text-muted">ทำเครื่องหมายตามที่ปรากฏในเล่มแผนการจัดการเรียนรู้ · ช่อง "ไม่เกี่ยวข้อง" ที่แรเงา คือรายการที่ต้องมีทุกรายวิชา</div>
            <?= isset($errors['check']) ? '<div class="alert alert-danger m-3 mb-0">' . e($errors['check']) . '</div>' : '' ?>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle mt-2">
                    <thead class="text-center"><tr><th style="width:44px">ที่</th><th class="text-start">รายการ</th><th style="width:70px">มี</th><th style="width:70px">ไม่มี</th><th style="width:90px">ไม่เกี่ยวข้อง</th></tr></thead>
                    <tbody>
                    <?php $lastCat = null; $i = 0; foreach ($checkCriteria as $c): $i++; $cid = (int)$c['id']; $req = (int)$c['is_required'] === 1; ?>
                        <?php if ($c['category'] !== $lastCat): $lastCat = $c['category']; ?>
                            <tr class="table-light"><td colspan="5" class="fw-semibold text-primary-th"><?= e($c['category']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="<?= is_post() && $checkVal($cid) === '' ? 'table-danger' : '' ?>">
                            <td class="text-center"><?= $i ?></td>
                            <td><?= e($c['title']) ?></td>
                            <?php foreach (['yes', 'no', 'na'] as $k): ?>
                                <td class="text-center<?= $k === 'na' && $req ? ' bg-secondary-subtle' : '' ?>">
                                    <?php if (!($k === 'na' && $req)): ?>
                                        <input class="form-check-input check-input" type="radio" name="check[<?= $cid ?>]" value="<?= $k ?>" data-required="<?= $req ? 1 : 0 ?>"
                                               aria-label="<?= e($c['title']) ?> = <?= e(plan_eval_check_labels()[$k]) ?>" <?= $checkVal($cid) === $k ? 'checked' : '' ?>>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <i class="bi bi-ui-checks-grid"></i> ตอนที่ 2 การประเมินคุณภาพของแผนการจัดการเรียนรู้
                <span class="ms-auto small fw-normal">คะแนนรวม: <strong id="liveTotal">-</strong> · ค่าเฉลี่ย: <strong id="liveAvg">-</strong> <span id="liveLevel" class="badge bg-secondary">-</span></span>
            </div>
            <div class="card-body pb-0 small text-muted">
                <?php foreach (plan_eval_score_labels() as $n => [$label, $meaning]): ?><span class="me-3"><strong><?= $n ?></strong> = <?= e($label) ?></span><?php endforeach; ?>
            </div>
            <?= isset($errors['score']) ? '<div class="alert alert-danger m-3 mb-0">' . e($errors['score']) . '</div>' : '' ?>
            <div class="table-responsive">
                <table class="table table-bordered mb-0 align-middle mt-2">
                    <thead><tr class="text-center"><th style="width:44px">ข้อ</th><th class="text-start">รายการประเมิน</th>
                        <?php foreach (plan_eval_score_labels() as $n => [$label, $meaning]): ?><th style="width:72px" title="<?= e($meaning) ?>"><?= $n ?><div class="small fw-normal"><?= e($label) ?></div></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php $lastCat = null; $i = 0; foreach ($qualityCriteria as $c): $i++; $cid = (int)$c['id']; ?>
                        <?php if ($c['category'] !== $lastCat): $lastCat = $c['category']; ?>
                            <tr class="table-light"><td colspan="7" class="fw-semibold text-primary-th"><?= e($c['category']) ?></td></tr>
                        <?php endif; ?>
                        <tr class="<?= is_post() && $scoreVal($cid) === 0 ? 'table-danger' : '' ?>">
                            <td class="text-center"><?= $i ?></td>
                            <td><?= e($c['title']) ?></td>
                            <?php foreach (array_keys(plan_eval_score_labels()) as $n): ?>
                                <td class="text-center">
                                    <input class="form-check-input score-input" type="radio" name="score[<?= $cid ?>]" value="<?= $n ?>"
                                           aria-label="ข้อ <?= $i ?> = <?= $n ?>" <?= $scoreVal($cid) === $n ? 'checked' : '' ?>>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small">
                <strong>เกณฑ์การผ่าน</strong> ค่าเฉลี่ยรวมตั้งแต่ <?= PLAN_EVAL_PASS_AVG ?> ขึ้นไป (ระดับดี) และตอนที่ 1 รายการที่ต้องมีทุกรายวิชาต้อง "มี" ครบทุกรายการ
                · ผลเบื้องต้น: <strong id="livePass">-</strong>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-chat-square-quote me-2"></i>ตอนที่ 3 ข้อเสนอแนะและผลการพิจารณา</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="strengths"><i class="bi bi-star text-success me-1"></i>จุดเด่นของแผนการจัดการเรียนรู้</label>
                        <textarea class="form-control" name="strengths" id="strengths" rows="4" maxlength="5000"><?= e($fv('strengths')) ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="improvements"><i class="bi bi-lightbulb text-accent me-1"></i>สิ่งที่ควรปรับปรุง/พัฒนา</label>
                        <textarea class="form-control" name="improvements" id="improvements" rows="4" maxlength="5000"><?= e($fv('improvements')) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold d-block">ผลการพิจารณา *</label>
                        <?php foreach (plan_eval_decisions() as $k => $label): ?>
                            <div class="form-check">
                                <input class="form-check-input<?= $invalid('decision') ?>" type="radio" name="decision" id="dec_<?= e($k) ?>" value="<?= e($k) ?>" <?= $fv('decision') === $k ? 'checked' : '' ?>>
                                <label class="form-check-label" for="dec_<?= e($k) ?>"><?= e($label) ?></label>
                                <?php if ($k === 'resubmit'): ?>
                                    <span class="d-inline-flex align-items-center gap-1 ms-1">ภายในวันที่
                                        <input type="date" class="form-control form-control-sm" style="width:auto" name="resubmit_by" value="<?= e($fv('resubmit_by')) ?>" aria-label="นำเสนอใหม่ภายในวันที่">
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?= $err('decision') ?>
                        <div class="form-text">ผลการพิจารณานี้แยกจากสถานะ "ผ่าน/ให้แก้ไข" ของงานที่ส่ง (ตรวจรับงานได้ที่เมนูตรวจงานตามเดิม)</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            <button type="submit" name="mode" value="draft" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i> บันทึกร่าง (ครูยังไม่เห็น)</button>
            <button type="submit" name="mode" value="final" class="btn btn-accent px-4" id="btnFinal"><i class="bi bi-send-check me-1"></i> ส่งผลการประเมิน</button>
        </div>
    </form>
    </div>
    </div>
    <style>
        /* จอกว้าง: แผงไฟล์ติดอยู่ใต้แถบด้านบน สูงเต็มจอ และเลื่อนแยกจากแบบประเมิน */
        @media (min-width: 1200px) {
            .plan-file-panel { position: sticky; top: 76px; height: calc(100vh - 96px); display: flex; flex-direction: column; }
            #evalFormCol.col-xl-6 .col-md-3, #evalFormCol.col-xl-6 .col-md-4, #evalFormCol.col-xl-6 .col-md-5 { flex: 0 0 auto; width: 50%; }
        }
    </style>
    <?php
    // คำนวณคะแนนรวม/ค่าเฉลี่ย/ผ่านเกณฑ์สดขณะกรอก (ผลจริงคำนวณที่เซิร์ฟเวอร์)
    $pageScripts = '(function () {
        var total = ' . count($qualityCriteria) . ', req = ' . count(array_filter($checkCriteria, fn($c) => (int)$c['is_required'] === 1)) . ';
        function level(a) { return a >= 4.51 ? ["ดีมาก","bg-success"] : a >= 3.51 ? ["ดี","bg-primary"] : a >= 2.51 ? ["พอใช้","bg-warning text-dark"] : a >= 1.51 ? ["ควรปรับปรุง","badge-late"] : ["ต้องปรับปรุงอย่างยิ่ง","bg-danger"]; }
        function calc() {
            var checked = document.querySelectorAll(".score-input:checked"), sum = 0, a = 0;
            checked.forEach(function (r) { sum += parseInt(r.value, 10); });
            var yes = document.querySelectorAll(".check-input[data-required=\"1\"][value=\"yes\"]:checked").length;
            document.getElementById("liveCheck").textContent = yes + "/" + req;
            var el = document.getElementById("liveAvg"), lv = document.getElementById("liveLevel");
            document.getElementById("liveTotal").textContent = sum + "/" + (total * 5);
            if (checked.length) {
                a = sum / checked.length; var l = level(a);
                el.textContent = a.toFixed(2) + " (" + checked.length + "/" + total + " ข้อ)";
                lv.textContent = l[0]; lv.className = "badge " + l[1];
            } else { el.textContent = "-"; }
            var done = checked.length === total;
            document.getElementById("livePass").textContent = !done ? "ยังให้คะแนนไม่ครบ" : (a >= ' . PLAN_EVAL_PASS_AVG . ' && yes === req ? "ผ่านเกณฑ์" : "ไม่ผ่านเกณฑ์");
        }
        document.querySelectorAll(".score-input, .check-input").forEach(function (r) { r.addEventListener("change", calc); });
        calc();
        // แผงไฟล์แผน: เลือกฉบับ / แสดง PDF ในหน้า (เฉพาะจอกว้าง) / ซ่อน-แสดงแผง
        var sel = document.getElementById("planFileSel");
        if (sel) {
            var base = ' . json_encode(url('file.php?id=')) . ', wide = window.matchMedia("(min-width: 1200px)");
            var frame = document.getElementById("planFileFrame"), noPrev = document.getElementById("planFileNoPreview");
            var showFile = function () {
                var opt = sel.options[sel.selectedIndex], pdf = opt.getAttribute("data-pdf") === "1";
                document.getElementById("planFileOpen").href = base + opt.value + (pdf ? "&inline=1" : "");
                document.getElementById("planFileDl").href = base + opt.value;
                frame.classList.toggle("d-none", !pdf);
                noPrev.classList.toggle("d-none", pdf);
                // โหลด PDF เข้า iframe เฉพาะจอกว้าง (มือถือบางรุ่นจะดาวน์โหลดไฟล์แทนการแสดง)
                var src = pdf && wide.matches ? base + opt.value + "&inline=1" : "about:blank";
                if (frame.getAttribute("src") !== src) { frame.setAttribute("src", src); }
            };
            sel.addEventListener("change", showFile);
            wide.addEventListener ? wide.addEventListener("change", showFile) : wide.addListener(showFile);
            showFile();
            var fileCol = document.getElementById("planFileCol"), formCol = document.getElementById("evalFormCol"), showBtn = document.getElementById("planFileShow");
            var setHidden = function (h) {
                fileCol.classList.toggle("d-xl-none", h);
                formCol.classList.toggle("col-xl-6", !h);
                showBtn.classList.toggle("d-xl-inline-block", h);
                try { localStorage.setItem("planEvalHideFile", h ? "1" : "0"); } catch (e) {}
            };
            document.getElementById("planFileHide").addEventListener("click", function () { setHidden(true); });
            showBtn.addEventListener("click", function () { setHidden(false); });
            try { if (localStorage.getItem("planEvalHideFile") === "1") { setHidden(true); } } catch (e) {}
        }
        // ยืนยันก่อนส่งผลให้ครู (บันทึกร่างไม่ต้องยืนยัน)
        document.getElementById("btnFinal").addEventListener("click", function (e) {
            if (!confirm("ยืนยันส่งผลการประเมิน? เมื่อส่งแล้วครูจะเห็นผลและกดรับทราบได้")) { e.preventDefault(); }
        });
    })();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// รายการแผนในขอบเขต
// =====================================================================
$ayId = plan_eval_selected_ay();
$dept = $scopeAll ? get_int('dept') : (int)$user['department_id'];
$state = get('state');
[$where, $types, $params] = plan_eval_scope_where($ayId, $dept);
$stateSql = [
    'pending' => ' AND pe.id IS NULL',
    'draft'   => " AND pe.status = 'draft'",
    'done'    => " AND pe.status = 'final'",
    'passed'  => " AND pe.status = 'final' AND pe.is_passed = 1",
    'failed'  => " AND pe.status = 'final' AND pe.is_passed = 0",
][$state] ?? '';
$rows = db_all(
    "SELECT lp.id, lp.subject_code, lp.subject_name, lp.level, lp.status, lp.is_late, lp.current_version, lp.submitted_at, lp.teacher_id,
            CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name, d.name AS department_name,
            pe.id AS eval_id, pe.avg_score, pe.is_passed, pe.decision, pe.plan_version, pe.acknowledged_at, pe.status AS eval_status,
            CONCAT(ev.prefix, ev.first_name, ' ', ev.last_name) AS evaluator_name
     FROM lesson_plans lp
     JOIN submission_rounds r ON r.id = lp.round_id
     JOIN users u ON u.id = lp.teacher_id
     LEFT JOIN plan_evaluations pe ON pe.lesson_plan_id = lp.id
     LEFT JOIN departments d ON d.id = COALESCE(pe.department_id, u.department_id)
     LEFT JOIN users ev ON ev.id = pe.evaluator_id
     WHERE $where $stateSql
     ORDER BY (pe.id IS NULL OR pe.status = 'draft') DESC, lp.submitted_at DESC",
    $types,
    $params
);
$counts = ['all' => 0, 'pending' => 0, 'done' => 0];
foreach (db_all("SELECT pe.status AS eval_status FROM lesson_plans lp JOIN submission_rounds r ON r.id = lp.round_id JOIN users u ON u.id = lp.teacher_id
                 LEFT JOIN plan_evaluations pe ON pe.lesson_plan_id = lp.id WHERE $where", $types, $params) as $r) {
    $counts['all']++;
    $counts[$r['eval_status'] === 'final' ? 'done' : 'pending']++;
}
$ays = db_all('SELECT * FROM academic_years ORDER BY year DESC, semester DESC');
$departments = $scopeAll ? db_all('SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name') : [];

$pageTitle = 'ประเมินแผนการจัดการเรียนรู้';
$pageIcon = 'bi-clipboard-data';
$breadcrumbs = ['ประเมินแผนการจัดการเรียนรู้' => null];
$pageActions = '<a href="' . e(url('reports/plan_eval_summary.php')) . '" class="btn btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i> รายงานผลการประเมิน</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
    <?= $scopeAll ? 'ท่านประเมินแผนการจัดการเรียนรู้ได้ทุกแผนกวิชา' : 'ท่านประเมินแผนการจัดการเรียนรู้ได้เฉพาะ ' . e($user['department_name'] ?? 'แผนกของท่าน') ?>
    · แสดงเฉพาะเอกสารประเภท "แผนการจัดการเรียนรู้" ที่ครูส่งในระบบ (แผนละ 1 ผลประเมิน) · ไม่สามารถประเมินแผนของตนเองได้</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="ay">ภาคเรียน</label>
                <select name="ay" id="ay" class="form-select">
                    <option value="all" <?= $ayId === 0 ? 'selected' : '' ?>>ทุกภาคเรียน</option>
                    <?php foreach ($ays as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $ayId === (int)$a['id'] ? 'selected' : '' ?>><?= e(academic_label($a)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php if ($scopeAll): ?>
                <div class="col-md-4">
                    <label class="form-label small mb-1" for="dept">แผนกวิชา</label>
                    <select name="dept" id="dept" class="form-select">
                        <option value="0">ทุกแผนก</option>
                        <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="state">สถานะการประเมิน</label>
                <select name="state" id="state" class="form-select">
                    <?php foreach (['' => 'ทั้งหมด', 'pending' => 'ยังไม่ประเมิน', 'draft' => 'ร่าง (ยังไม่ส่งผล)', 'done' => 'ส่งผลแล้ว', 'passed' => 'ผ่านเกณฑ์', 'failed' => 'ไม่ผ่านเกณฑ์'] as $k => $v): ?>
                        <option value="<?= e($k) ?>" <?= $state === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100"><i class="bi bi-funnel"></i> แสดง</button></div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([[$counts['all'], 'แผนที่ส่งทั้งหมด', 'ic-navy', 'bi-journal-text'], [$counts['pending'], 'รอประเมิน / ร่าง', 'ic-orange', 'bi-hourglass-split'],
        [$counts['done'], 'ส่งผลแล้ว', 'ic-green', 'bi-clipboard-check']] as [$val, $label, $ic, $icon]): ?>
        <div class="col-md-4">
            <div class="stat-card"><div class="stat-icon <?= $ic ?>"><i class="bi <?= $icon ?>"></i></div>
                <div><div class="stat-value"><?= $val ?></div><div class="stat-label"><?= e($label) ?></div></div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-list-ul me-2"></i>แผนการจัดการเรียนรู้ (<?= count($rows) ?> รายการ)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>ครูผู้สอน</th><th>แผนก</th><th>รายวิชา</th><th>ส่งเมื่อ</th><th>สถานะงาน</th><th>ผลประเมิน</th><th>ผู้ประเมิน</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $own = (int)$r['teacher_id'] === $uid; ?>
                    <tr>
                        <td><?= e($r['teacher_name']) ?></td>
                        <td class="small"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$r['department_name'])) ?></td>
                        <td><span class="fw-semibold"><?= e($r['subject_code']) ?></span> <?= e($r['subject_name']) ?> <span class="small text-muted">(<?= e($r['level']) ?> · ฉบับที่ <?= (int)$r['current_version'] ?>)</span></td>
                        <td class="small text-nowrap" data-order="<?= e($r['submitted_at']) ?>"><?= e(thai_date($r['submitted_at'])) ?></td>
                        <td><?= status_badge($r['status'], (bool)$r['is_late']) ?></td>
                        <td data-order="<?= $r['eval_id'] ? e($r['avg_score']) : -1 ?>">
                            <?php if ($r['eval_id']): ?>
                                <?php if ($r['eval_status'] === 'final'): ?>
                                    <?= plan_eval_level_badge((float)$r['avg_score']) ?> <?= plan_eval_pass_badge((bool)$r['is_passed']) ?>
                                <?php else: ?><?= plan_eval_status_badge('draft') ?><?php endif; ?>
                                <?php if ((int)$r['plan_version'] !== (int)$r['current_version']): ?><div class="small text-warning">ประเมินฉบับที่ <?= (int)$r['plan_version'] ?> · มีฉบับใหม่</div><?php endif; ?>
                            <?php else: ?><span class="badge bg-secondary">ยังไม่ประเมิน</span><?php endif; ?>
                        </td>
                        <td class="small"><?= e($r['evaluator_name'] ?? '-') ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($r['eval_id'] && $r['eval_status'] === 'draft' && !$own): ?>
                                <a href="<?= e(url($self . '?evaluate=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-warning text-dark"><i class="bi bi-pencil"></i> ประเมินต่อ</a>
                            <?php elseif ($r['eval_id']): ?>
                                <a href="<?= e(url($self . '?view=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i> ดูผล</a>
                            <?php elseif (!$own): ?>
                                <a href="<?= e(url($self . '?evaluate=' . (int)$r['id'])) ?>" class="btn btn-sm btn-accent"><i class="bi bi-clipboard-check"></i> ประเมิน</a>
                            <?php else: ?><span class="small text-muted">แผนของท่าน</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
