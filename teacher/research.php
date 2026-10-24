<?php
/**
 * ไฟล์: teacher/research.php
 * โมดูลที่ 3: ส่งวิจัยในชั้นเรียน แยก 2 ขั้นตอน
 *   1) เค้าโครงวิจัย (proposal) — ส่งในรอบที่ phase = proposal
 *   2) รายงานวิจัยฉบับสมบูรณ์ (final) — ส่งในรอบที่ phase = final โดยต้องอ้างอิงเค้าโครงที่ "ผ่าน" แล้ว
 * 1 รอบ ส่งได้ 1 เรื่องต่อครู 1 คน
 *   ?round_id=xx = เลือกรอบให้อัตโนมัติ (ถ้าส่งแล้วจะพาไปหน้ารายละเอียด)
 *   ?id=xx       = รายละเอียด/ประวัติ และส่งฉบับแก้ไข
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$type = 'research';
$errors = [];

/** ตรวจข้อมูลแบบฟอร์มวิจัย */
function research_validate(array &$errors): array
{
    $d = [
        'title'        => mb_substr(post('title'), 0, 255),
        'problem'      => mb_substr(post('problem'), 0, 10000),
        'target_group' => mb_substr(post('target_group'), 0, 255),
        'subject_text' => mb_substr(post('subject_text'), 0, 255),
    ];
    if (mb_strlen($d['title']) < 5) {
        $errors['title'] = 'กรุณากรอกชื่อเรื่องวิจัย';
    }
    if ($d['problem'] === '') {
        $errors['problem'] = 'กรุณาระบุปัญหาการวิจัย';
    }
    if ($d['target_group'] === '') {
        $errors['target_group'] = 'กรุณาระบุกลุ่มเป้าหมาย';
    }
    // จับคู่รหัสวิชาจากข้อความ (เช่น "20104-2101 วงจรไฟฟ้า...") กับรายวิชาในระบบ
    $d['subject_id'] = null;
    if (preg_match('/^([0-9A-Za-z-]{4,20})\s/u', $d['subject_text'] . ' ', $m)) {
        $sid = db_value('SELECT id FROM subjects WHERE code = ?', 's', [strtoupper($m[1])]);
        $d['subject_id'] = $sid ? (int)$sid : null;
    }
    return $d;
}

/** เค้าโครงของฉันที่ผ่านแล้ว และยังไม่มีฉบับสมบูรณ์อ้างอิง */
function research_available_proposals(int $uid): array
{
    return db_all(
        "SELECT p.id, p.title, p.problem, p.target_group, p.subject_text, p.submitted_at
         FROM research_submissions p
         WHERE p.teacher_id = ? AND p.stage = 'proposal' AND p.status = 'approved'
           AND NOT EXISTS (SELECT 1 FROM research_submissions f WHERE f.parent_id = p.id)
         ORDER BY p.submitted_at DESC",
        'i',
        [$uid]
    );
}

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- ส่งใหม่ ----------
    if ($action === 'create') {
        $roundId = (int)post('round_id');
        $round = db_one("SELECT * FROM submission_rounds WHERE id = ? AND type = 'research'", 'i', [$roundId]);
        $d = research_validate($errors);
        $parentId = null;

        if (!$round) {
            $errors['round_id'] = 'กรุณาเลือกรอบการส่ง';
        } else {
            if ($existing = db_value('SELECT id FROM research_submissions WHERE round_id = ? AND teacher_id = ?', 'ii', [$roundId, $uid])) {
                flash('warning', 'ท่านส่งวิจัยในรอบนี้แล้ว หากต้องการแก้ไขให้ส่งฉบับใหม่ในหน้านี้');
                redirect('teacher/research.php?id=' . (int)$existing);
            }
            // ฉบับสมบูรณ์ต้องอ้างอิงเค้าโครงที่ผ่านแล้ว
            if ($round['phase'] === 'final') {
                $parentId = (int)post('parent_id');
                $okIds = array_map('intval', array_column(research_available_proposals($uid), 'id'));
                if (!in_array($parentId, $okIds, true)) {
                    $errors['parent_id'] = 'กรุณาเลือกเค้าโครงวิจัยที่ผ่านการพิจารณาแล้ว';
                }
            }
        }

        if (!$errors) {
            $stage = $round['phase'] === 'final' ? 'final' : 'proposal';
            $res = submission_create($type, $round, $uid, post('note'), function (bool $isLate) use ($roundId, $uid, $round, $parentId, $stage, $d): int {
                return db_insert(
                    'INSERT INTO research_submissions (round_id, teacher_id, academic_year_id, parent_id, stage, title, problem,
                                                       target_group, subject_id, subject_text, is_late)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'iiiissssisi',
                    [$roundId, $uid, $round['academic_year_id'], $parentId, $stage, $d['title'], $d['problem'],
                        $d['target_group'], $d['subject_id'], $d['subject_text'], $isLate ? 1 : 0]
                );
            });
            if (!$res['ok']) {
                $errors[$res['field'] ?? 'round_id'] = $res['error'];
            } else {
                log_activity('submit_research', 'ส่ง' . phase_label($stage) . ': ' . $d['title'] . ($res['is_late'] ? ' (ส่งช้า)' : ''));
                flash('success', 'ส่ง' . phase_label($stage) . 'เรียบร้อยแล้ว' . ($res['is_late'] ? ' (บันทึกเป็นส่งช้า)' : ''));
                redirect('teacher/research.php?id=' . $res['id']);
            }
        }
    }

    // ---------- ส่งฉบับแก้ไข ----------
    if ($action === 'resubmit') {
        $sub = own_submission($type, (int)post('id'), $uid);
        if (!$sub) {
            abort_page(404, 'ไม่พบงาน');
        }
        $d = research_validate($errors);
        if (!$errors) {
            $error = submission_resubmit(
                $type, $sub, $uid, post('note'),
                'title = ?, problem = ?, target_group = ?, subject_id = ?, subject_text = ?',
                'sssis',
                [$d['title'], $d['problem'], $d['target_group'], $d['subject_id'], $d['subject_text']]
            );
            if ($error === '') {
                flash('success', 'ส่งฉบับที่ ' . ((int)$sub['current_version'] + 1) . ' เรียบร้อยแล้ว สถานะเปลี่ยนเป็น "รอตรวจ"');
                redirect('teacher/research.php?id=' . (int)$sub['id']);
            }
            $errors['file'] = $error;
        }
    }

    // ---------- ลบ ----------
    if ($action === 'delete') {
        $sub = own_submission($type, (int)post('id'), $uid);
        if (!$sub) {
            abort_page(404, 'ไม่พบงาน');
        }
        if (!submission_can_delete($sub)) {
            flash('error', 'ไม่สามารถลบได้ (งานถูกตรวจแล้ว หรือรอบปิดรับแล้ว)');
            redirect('teacher/research.php?id=' . (int)$sub['id']);
        }
        submission_delete($type, $sub);
        log_activity('delete_research', 'ลบวิจัย #' . (int)$sub['id'] . ': ' . $sub['title'] . ' (' . (int)$sub['current_version'] . ' ฉบับ)');
        flash('success', 'ลบงานวิจัยแล้ว');
        redirect('teacher/research.php');
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$activeMenu = 'teacher/research.php';
$subjectOptions = db_all(
    'SELECT code, name FROM subjects WHERE is_active = 1 ORDER BY (department_id = ?) DESC, code',
    'i',
    [(int)$user['department_id']]
);

/** ช่องกรอกข้อมูลวิจัย (ใช้ทั้งฟอร์มส่งใหม่และฟอร์มแก้ไข) */
function research_fields(array $src, callable $invalid, callable $err, array $subjectOptions): void
{
    $v = fn(string $k) => is_post() ? post($k) : (string)($src[$k] ?? '');
    ?>
    <div class="col-12">
        <label class="form-label" for="title">ชื่อเรื่องวิจัย *</label>
        <input class="form-control<?= $invalid('title') ?>" name="title" id="title" maxlength="255" value="<?= e($v('title')) ?>"
               placeholder="เช่น การพัฒนาทักษะการต่อวงจรไฟฟ้าของนักเรียน ปวช.1 โดยใช้ชุดฝึกปฏิบัติ"><?= $err('title') ?>
    </div>
    <div class="col-12">
        <label class="form-label" for="problem">ปัญหาการวิจัย *</label>
        <textarea class="form-control<?= $invalid('problem') ?>" name="problem" id="problem" rows="5" maxlength="10000"
                  placeholder="สภาพปัญหาที่พบในชั้นเรียน และสาเหตุของปัญหา"><?= e($v('problem')) ?></textarea><?= $err('problem') ?>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="target_group">กลุ่มเป้าหมาย *</label>
        <input class="form-control<?= $invalid('target_group') ?>" name="target_group" id="target_group" maxlength="255" value="<?= e($v('target_group')) ?>"
               placeholder="เช่น นักเรียน ปวช.1/1 สาขาช่างไฟฟ้ากำลัง จำนวน 25 คน"><?= $err('target_group') ?>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="subject_text">วิชาที่เกี่ยวข้อง</label>
        <input class="form-control" name="subject_text" id="subject_text" maxlength="255" value="<?= e($v('subject_text')) ?>" list="subjectList"
               placeholder="พิมพ์รหัสหรือชื่อวิชา">
        <datalist id="subjectList">
            <?php foreach ($subjectOptions as $s): ?><option value="<?= e($s['code'] . ' ' . $s['name']) ?>"><?php endforeach; ?>
        </datalist>
    </div>
    <?php
}

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
    $parent = $sub['parent_id'] ? db_one('SELECT id, title, status FROM research_submissions WHERE id = ?', 'i', [(int)$sub['parent_id']]) : null;
    $child = db_one('SELECT id, status FROM research_submissions WHERE parent_id = ?', 'i', [(int)$sub['id']]);

    $pageTitle = phase_label($sub['stage']);
    $pageIcon = 'bi-search';
    $breadcrumbs = ['วิจัยในชั้นเรียน' => 'teacher/research.php', str_limit($sub['title'], 40) => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($sub['status'] === 'revision' && $lastReview): ?>
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> ผู้ตรวจให้แก้ไข</div>
            <?= nl2br(e((string)$lastReview['comment'])) ?: 'กรุณาแก้ไขและส่งใหม่' ?>
        </div>
    <?php endif; ?>

    <!-- ขั้นตอนวิจัย -->
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
            <?php
            $propStatus = $sub['stage'] === 'proposal' ? $sub['status'] : ($parent['status'] ?? 'approved');
            $finalStatus = $sub['stage'] === 'final' ? $sub['status'] : ($child['status'] ?? 'not_submitted');
            ?>
            <div><span class="badge rounded-pill bg-primary-th me-1">1</span> เค้าโครงวิจัย <?= status_badge($propStatus) ?>
                <?php if ($parent): ?><a class="small ms-1" href="<?= e(url('teacher/research.php?id=' . (int)$parent['id'])) ?>">ดู</a><?php endif; ?></div>
            <i class="bi bi-arrow-right text-muted"></i>
            <div><span class="badge rounded-pill bg-accent me-1">2</span> รายงานฉบับสมบูรณ์ <?= status_badge($finalStatus) ?>
                <?php if ($child): ?><a class="small ms-1" href="<?= e(url('teacher/research.php?id=' . (int)$child['id'])) ?>">ดู</a><?php endif; ?></div>
        </div>
    </div>

    <?php require APP_ROOT . '/includes/partials/submission_detail.php'; ?>

    <div class="card mt-4">
        <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>แก้ไขข้อมูลและส่งฉบับใหม่</div>
        <div class="card-body">
            <?php if ($blockReason !== ''): ?>
                <div class="text-muted"><i class="bi bi-lock me-1"></i><?= e($blockReason) ?></div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" novalidate
                      data-confirm="ยืนยันส่งฉบับที่ <?= (int)$sub['current_version'] + 1 ?>? สถานะจะกลับเป็น &quot;รอตรวจ&quot;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resubmit">
                    <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                    <div class="row g-3">
                        <?php research_fields(['title' => $sub['research_title']] + $sub, $invalid, $err, $subjectOptions); ?>
                        <div class="col-md-6">
                            <label class="form-label" for="file">ไฟล์<?= e(phase_label($sub['stage'])) ?> ฉบับใหม่ *</label>
                            <input type="file" class="form-control<?= $invalid('file') ?>" name="file" id="file" accept="<?= e(upload_accept()) ?>" required>
                            <div class="form-text"><?= e(upload_hint()) ?></div><?= $err('file') ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="note">สิ่งที่แก้ไข / หมายเหตุ</label>
                            <input class="form-control" name="note" id="note" maxlength="500" value="<?= old('note') ?>">
                        </div>
                    </div>
                    <div class="text-end mt-3">
                        <button type="submit" class="btn btn-accent" data-loading-text="กำลังอัปโหลด..."><i class="bi bi-upload me-1"></i> ส่งฉบับที่ <?= (int)$sub['current_version'] + 1 ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-4">
        <a href="<?= e(url('teacher/research.php')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการวิจัยของฉัน</a>
        <?php if (submission_can_delete($sub) && !$child): ?>
            <form method="post" class="ms-auto" data-confirm="ลบงานวิจัยนี้พร้อมไฟล์ทั้งหมด? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                <button class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i> ลบงานนี้</button>
            </form>
        <?php endif; ?>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารวม
// =====================================================================
$rounds = db_all(
    "SELECT r.*, ay.year, ay.semester FROM submission_rounds r
     LEFT JOIN academic_years ay ON ay.id = r.academic_year_id
     WHERE r.type = 'research' AND r.is_active = 1 AND (r.close_at >= NOW() - INTERVAL 30 DAY OR r.open_at > NOW())
     ORDER BY r.close_at"
);
$sentRounds = array_map('intval', array_column(
    db_all('SELECT round_id FROM research_submissions WHERE teacher_id = ?', 'i', [$uid]),
    'round_id'
));
$submittable = array_values(array_filter($rounds, fn($r) => round_can_submit($r) && !in_array((int)$r['id'], $sentRounds, true)));

$selRound = (int)(post('round_id') ?: get_int('round_id'));
if ($selRound && !is_post()) {
    $existing = db_value('SELECT id FROM research_submissions WHERE round_id = ? AND teacher_id = ?', 'ii', [$selRound, $uid]);
    if ($existing) {
        redirect('teacher/research.php?id=' . (int)$existing);
    }
}
$formRound = null;
foreach ($submittable as $r) {
    if (!$selRound || (int)$r['id'] === $selRound) {
        $formRound = $r;
        break;
    }
}
$proposals = research_available_proposals($uid);

$mine = db_all(
    "SELECT s.*, r.name AS round_name, ay.year, ay.semester FROM research_submissions s
     JOIN submission_rounds r ON r.id = s.round_id LEFT JOIN academic_years ay ON ay.id = s.academic_year_id
     WHERE s.teacher_id = ? ORDER BY s.submitted_at DESC",
    'i',
    [$uid]
);

$pageTitle = 'วิจัยในชั้นเรียน';
$pageIcon = 'bi-search';
$breadcrumbs = ['วิจัยในชั้นเรียน' => null];
require APP_ROOT . '/includes/header.php';
?>
<div class="alert alert-light border small">
    <i class="bi bi-signpost-split me-1 text-accent"></i>
    ขั้นตอน: <strong>1) ส่งเค้าโครงวิจัย</strong> → รอผู้ตรวจพิจารณา "ผ่าน" → <strong>2) ส่งรายงานวิจัยฉบับสมบูรณ์</strong> ในรอบถัดไป
</div>

<div class="row g-3 mb-4">
    <?php foreach ($rounds as $r): ?>
        <div class="col-md-6 col-xl-4">
            <?= round_card_html($r) ?>
            <?php if (in_array((int)$r['id'], $sentRounds, true)): ?>
                <div class="small text-success mt-1"><i class="bi bi-check-circle"></i> ท่านส่งรอบนี้แล้ว</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!$rounds): ?>
        <div class="col-12"><div class="card"><div class="empty-state"><i class="bi bi-calendar-x"></i>ยังไม่มีรอบการส่งวิจัยในชั้นเรียน</div></div></div>
    <?php endif; ?>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-cloud-upload me-2"></i>ส่งวิจัยในชั้นเรียน</div>
    <div class="card-body">
        <?php if (!$formRound): ?>
            <div class="empty-state"><i class="bi bi-lock"></i>ไม่มีรอบที่เปิดรับซึ่งท่านยังไม่ได้ส่ง — ระบบไม่อนุญาตให้อัปโหลดนอกช่วงเวลา</div>
        <?php elseif ($formRound['phase'] === 'final' && !$proposals): ?>
            <div class="empty-state"><i class="bi bi-exclamation-circle"></i>
                รอบนี้เป็นการส่งรายงานฉบับสมบูรณ์ ท่านต้องมีเค้าโครงวิจัยที่ "ผ่าน" แล้วก่อน</div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" novalidate data-confirm="ตรวจสอบข้อมูลและไฟล์ถูกต้องแล้ว ต้องการส่งงานวิจัยใช่หรือไม่?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="round_id">รอบการส่ง *</label>
                        <select class="form-select<?= $invalid('round_id') ?>" name="round_id" id="round_id" onchange="location.href='?round_id=' + this.value">
                            <?php foreach ($submittable as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= (int)$formRound['id'] === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= e($r['name']) ?><?= round_state($r) === 'late' ? ' [ส่งช้า]' : '' ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('round_id') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ขั้นตอน</label>
                        <input class="form-control" value="<?= e(phase_label($formRound['phase'])) ?>" readonly>
                    </div>
                    <?php if ($formRound['phase'] === 'final'): ?>
                        <div class="col-12">
                            <label class="form-label" for="parent_id">เค้าโครงวิจัยที่ผ่านแล้ว *</label>
                            <select class="form-select<?= $invalid('parent_id') ?>" name="parent_id" id="parent_id">
                                <?php foreach ($proposals as $p): ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= (int)post('parent_id') === (int)$p['id'] ? 'selected' : '' ?>
                                            data-title="<?= e($p['title']) ?>" data-problem="<?= e($p['problem']) ?>"
                                            data-target="<?= e($p['target_group']) ?>" data-subject="<?= e($p['subject_text']) ?>"><?= e($p['title']) ?></option>
                                <?php endforeach; ?>
                            </select><?= $err('parent_id') ?>
                            <div class="form-text">ข้อมูลด้านล่างจะดึงจากเค้าโครงให้อัตโนมัติ และแก้ไขได้</div>
                        </div>
                    <?php endif; ?>
                    <?php research_fields([], $invalid, $err, $subjectOptions); ?>
                    <div class="col-md-6">
                        <label class="form-label" for="file">ไฟล์<?= e(phase_label($formRound['phase'])) ?> *</label>
                        <input type="file" class="form-control<?= $invalid('file') ?>" name="file" id="file" accept="<?= e(upload_accept()) ?>" required>
                        <div class="form-text"><?= e(upload_hint()) ?></div><?= $err('file') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="note">หมายเหตุ</label>
                        <input class="form-control" name="note" id="note" maxlength="500" value="<?= old('note') ?>">
                    </div>
                </div>
                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังอัปโหลด..."><i class="bi bi-send me-1"></i> ส่ง<?= e(phase_label($formRound['phase'])) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-folder2-open me-2"></i>งานวิจัยของฉัน (<?= count($mine) ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>ชื่อเรื่อง</th><th>ขั้นตอน</th><th>ภาคเรียน</th><th>ฉบับที่</th><th>สถานะ</th><th>ส่งเมื่อ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($mine as $m): ?>
                    <tr>
                        <td><?= e($m['title']) ?></td>
                        <td class="small"><?= e(phase_label($m['stage'])) ?></td>
                        <td><?= $m['year'] ? e($m['semester'] . '/' . $m['year']) : '-' ?></td>
                        <td class="text-center"><?= (int)$m['current_version'] ?></td>
                        <td data-order="<?= e($m['status']) ?>"><?= status_badge($m['status'], (bool)$m['is_late']) ?></td>
                        <td class="small" data-order="<?= e($m['submitted_at']) ?>"><?= e(thai_datetime($m['submitted_at'])) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url('teacher/research.php?id=' . (int)$m['id'])) ?>" class="btn btn-sm <?= $m['status'] === 'revision' ? 'btn-danger' : 'btn-outline-primary' ?> text-nowrap">
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
// ฉบับสมบูรณ์: ดึงข้อมูลจากเค้าโครงที่เลือกมาใส่ในฟอร์ม
$pageScripts = '(function () {
    var sel = document.getElementById("parent_id"); if (!sel) return;
    function fill(force) {
        var o = sel.options[sel.selectedIndex]; if (!o) return;
        [["title", "title"], ["problem", "problem"], ["target_group", "target"], ["subject_text", "subject"]].forEach(function (p) {
            var el = document.getElementById(p[0]);
            if (el && (force || !el.value)) el.value = o.dataset[p[1]] || "";
        });
    }
    sel.addEventListener("change", function () { fill(true); });
    fill(false);
})();';
require APP_ROOT . '/includes/footer.php';
