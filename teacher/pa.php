<?php
/**
 * ไฟล์: teacher/pa.php
 * โมดูลที่ 2: ส่งแบบเสนอข้อตกลงในการพัฒนางาน (PA)
 *   - รอบ "ข้อตกลงต้นปี" (agreement) และ "รายงานผลปลายปี" (report) แยกกันตามรอบที่ admin สร้าง
 *   - 1 รอบ ส่งได้ 1 รายการต่อครู 1 คน (แก้ไขด้วยการส่งฉบับใหม่)
 *   ?round_id=xx = เลือกรอบให้อัตโนมัติ (ถ้าส่งแล้วจะพาไปหน้ารายละเอียด)
 *   ?id=xx       = รายละเอียด/ประวัติ และส่งฉบับแก้ไข
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$type = 'pa';
$errors = [];
$maxText = 20000; // ความยาวสูงสุดของส่วนที่ 1 / 2

/** ตรวจข้อมูลแบบฟอร์ม PA (ใช้ทั้งส่งใหม่และส่งฉบับแก้ไข) */
function pa_validate(array &$errors, int $maxText): array
{
    $d = [
        'position'          => mb_substr(post('position'), 0, 100),
        'academic_standing' => post('academic_standing'),
        'part1'             => post('part1_workload'),
        'part2'             => post('part2_challenge'),
    ];
    if ($d['position'] === '') {
        $errors['position'] = 'กรุณาระบุตำแหน่ง';
    }
    if (!in_array($d['academic_standing'], academic_standing_options(), true)) {
        $errors['academic_standing'] = 'วิทยฐานะไม่ถูกต้อง';
    }
    if ($d['part1'] === '' || mb_strlen($d['part1']) > $maxText) {
        $errors['part1_workload'] = 'กรุณากรอกส่วนที่ 1 (ไม่เกิน ' . number_format($maxText) . ' ตัวอักษร)';
    }
    if ($d['part2'] === '' || mb_strlen($d['part2']) > $maxText) {
        $errors['part2_challenge'] = 'กรุณากรอกส่วนที่ 2 (ไม่เกิน ' . number_format($maxText) . ' ตัวอักษร)';
    }
    return $d;
}

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    // ---------- ส่ง PA ใหม่ ----------
    if ($action === 'create') {
        $roundId = (int)post('round_id');
        $round = db_one("SELECT * FROM submission_rounds WHERE id = ? AND type = 'pa'", 'i', [$roundId]);
        $d = pa_validate($errors, $maxText);
        if (!$round) {
            $errors['round_id'] = 'กรุณาเลือกรอบการส่ง';
        } elseif ($existing = db_value('SELECT id FROM pa_submissions WHERE round_id = ? AND teacher_id = ?', 'ii', [$roundId, $uid])) {
            flash('warning', 'ท่านส่ง PA ในรอบนี้แล้ว หากต้องการแก้ไขให้ส่งฉบับใหม่ในหน้านี้');
            redirect('teacher/pa.php?id=' . (int)$existing);
        }

        if (!$errors) {
            $fiscal = (int)($round['fiscal_year'] ?: current_fiscal_year());
            $phase = $round['phase'] === 'report' ? 'report' : 'agreement';
            $res = submission_create($type, $round, $uid, post('note'), function (bool $isLate) use ($roundId, $uid, $phase, $fiscal, $d): int {
                return db_insert(
                    'INSERT INTO pa_submissions (round_id, teacher_id, phase, fiscal_year, position, academic_standing,
                                                 part1_workload, part2_challenge, is_late)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    'iisissssi',
                    [$roundId, $uid, $phase, $fiscal, $d['position'], $d['academic_standing'], $d['part1'], $d['part2'], $isLate ? 1 : 0]
                );
            });
            if (!$res['ok']) {
                $errors[$res['field'] ?? 'round_id'] = $res['error'];
            } else {
                log_activity('submit_pa', 'ส่ง PA ปีงบประมาณ ' . $fiscal . ' (' . phase_label($phase) . ')' . ($res['is_late'] ? ' ส่งช้า' : ''));
                flash('success', 'ส่ง PA ' . phase_label($phase) . ' ปีงบประมาณ ' . $fiscal . ' เรียบร้อยแล้ว' . ($res['is_late'] ? ' (บันทึกเป็นส่งช้า)' : ''));
                redirect('teacher/pa.php?id=' . $res['id']);
            }
        }
    }

    // ---------- ส่งฉบับแก้ไข (แก้ข้อความได้ + ไฟล์ใหม่) ----------
    if ($action === 'resubmit') {
        $sub = own_submission($type, (int)post('id'), $uid);
        if (!$sub) {
            abort_page(404, 'ไม่พบงาน');
        }
        $d = pa_validate($errors, $maxText);
        if (!$errors) {
            $error = submission_resubmit(
                $type, $sub, $uid, post('note'),
                'position = ?, academic_standing = ?, part1_workload = ?, part2_challenge = ?',
                'ssss',
                [$d['position'], $d['academic_standing'], $d['part1'], $d['part2']]
            );
            if ($error === '') {
                flash('success', 'ส่ง PA ฉบับที่ ' . ((int)$sub['current_version'] + 1) . ' เรียบร้อยแล้ว สถานะเปลี่ยนเป็น "รอตรวจ"');
                redirect('teacher/pa.php?id=' . (int)$sub['id']);
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
            redirect('teacher/pa.php?id=' . (int)$sub['id']);
        }
        submission_delete($type, $sub);
        log_activity('delete_pa', 'ลบ PA: ' . $sub['title']);
        flash('success', 'ลบ ' . $sub['title'] . ' แล้ว');
        redirect('teacher/pa.php');
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
$activeMenu = 'teacher/pa.php';

/**
 * ช่องกรอกข้อมูล PA (ใช้ทั้งฟอร์มส่งใหม่และฟอร์มแก้ไข)
 * @param array $src ค่าเริ่มต้น (แถว pa_submissions หรือข้อมูลผู้ใช้)
 */
function pa_fields(array $src, string $phase, callable $invalid, callable $err, int $maxText): void
{
    $v = fn(string $k, string $col) => is_post() ? post($k) : (string)($src[$col] ?? '');
    [$p1, $p2] = pa_part_labels($phase);
    ?>
    <div class="col-md-6">
        <label class="form-label" for="position">ตำแหน่ง *</label>
        <input class="form-control<?= $invalid('position') ?>" name="position" id="position" maxlength="100" value="<?= e($v('position', 'position')) ?>" list="positionList">
        <datalist id="positionList"><?php foreach (position_options() as $p): ?><option value="<?= e($p) ?>"><?php endforeach; ?></datalist>
        <?= $err('position') ?>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="academic_standing">วิทยฐานะ</label>
        <select class="form-select<?= $invalid('academic_standing') ?>" name="academic_standing" id="academic_standing">
            <?php foreach (academic_standing_options() as $s): ?>
                <option value="<?= e($s) ?>" <?= $v('academic_standing', 'academic_standing') === $s ? 'selected' : '' ?>><?= $s === '' ? '- ไม่มีวิทยฐานะ -' : e($s) ?></option>
            <?php endforeach; ?>
        </select><?= $err('academic_standing') ?>
    </div>
    <div class="col-12">
        <label class="form-label fw-semibold" for="part1_workload"><?= e($p1) ?> *</label>
        <textarea class="form-control<?= $invalid('part1_workload') ?>" name="part1_workload" id="part1_workload" rows="6" maxlength="<?= $maxText ?>"
                  placeholder="<?= $phase === 'report' ? 'สรุปผลการปฏิบัติงานด้านการจัดการเรียนรู้ ด้านการส่งเสริมและสนับสนุน และด้านการพัฒนาตนเองและวิชาชีพ' : 'ภาระงานสอน (ชั่วโมง/สัปดาห์), งานส่งเสริมและสนับสนุนการจัดการเรียนรู้, งานตอบสนองนโยบายและจุดเน้น ฯลฯ' ?>"><?= e($v('part1_workload', 'part1_workload')) ?></textarea>
        <?= $err('part1_workload') ?>
    </div>
    <div class="col-12">
        <label class="form-label fw-semibold" for="part2_challenge"><?= e($p2) ?> *</label>
        <textarea class="form-control<?= $invalid('part2_challenge') ?>" name="part2_challenge" id="part2_challenge" rows="6" maxlength="<?= $maxText ?>"
                  placeholder="<?= $phase === 'report' ? 'ผลลัพธ์การเรียนรู้ของผู้เรียนที่เกิดขึ้นจริง ทั้งเชิงปริมาณและเชิงคุณภาพ' : 'ประเด็นท้าทาย, สภาพปัญหา, วิธีดำเนินการ, ผลลัพธ์ที่คาดหวังเชิงปริมาณและเชิงคุณภาพ' ?>"><?= e($v('part2_challenge', 'part2_challenge')) ?></textarea>
        <?= $err('part2_challenge') ?>
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
    // รายงานปลายปี: แสดงลิงก์ข้อตกลงต้นปีของปีงบประมาณเดียวกัน
    $agreement = $sub['phase'] === 'report' ? db_one(
        "SELECT id, status FROM pa_submissions WHERE teacher_id = ? AND fiscal_year = ? AND phase = 'agreement' ORDER BY id DESC LIMIT 1",
        'ii',
        [$uid, (int)$sub['fiscal_year']]
    ) : null;

    $pageTitle = $sub['title'];
    $pageIcon = 'bi-clipboard-check';
    $breadcrumbs = ['ข้อตกลงในการพัฒนางาน (PA)' => 'teacher/pa.php', 'ปีงบประมาณ ' . $sub['fiscal_year'] => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($sub['status'] === 'revision' && $lastReview): ?>
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> ผู้ตรวจให้แก้ไข</div>
            <?= nl2br(e((string)$lastReview['comment'])) ?: 'กรุณาแก้ไขและส่งใหม่' ?>
        </div>
    <?php endif; ?>
    <?php if ($agreement): ?>
        <div class="alert alert-light border small"><i class="bi bi-link-45deg me-1"></i>
            ข้อตกลงต้นปีของปีงบประมาณนี้: <a href="<?= e(url('teacher/pa.php?id=' . (int)$agreement['id'])) ?>">ดูข้อตกลง</a> <?= status_badge($agreement['status']) ?></div>
    <?php endif; ?>

    <?php require APP_ROOT . '/includes/partials/submission_detail.php'; ?>

    <div class="card mt-4">
        <div class="card-header"><i class="bi bi-arrow-repeat me-2"></i>แก้ไขข้อมูลและส่งฉบับใหม่</div>
        <div class="card-body">
            <?php if ($blockReason !== ''): ?>
                <div class="text-muted"><i class="bi bi-lock me-1"></i><?= e($blockReason) ?></div>
            <?php else: ?>
                <form method="post" enctype="multipart/form-data" novalidate
                      data-confirm="ยืนยันส่ง PA ฉบับที่ <?= (int)$sub['current_version'] + 1 ?>? สถานะจะกลับเป็น &quot;รอตรวจ&quot;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resubmit">
                    <input type="hidden" name="id" value="<?= (int)$sub['id'] ?>">
                    <div class="row g-3">
                        <?php pa_fields($sub, $sub['phase'], $invalid, $err, $maxText); ?>
                        <div class="col-md-6">
                            <label class="form-label" for="file">ไฟล์แบบ PA ฉบับใหม่ *</label>
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
        <a href="<?= e(url('teacher/pa.php')) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการ PA ของฉัน</a>
        <?php if (submission_can_delete($sub)): ?>
            <form method="post" class="ms-auto" data-confirm="ลบ <?= e($sub['title']) ?> พร้อมไฟล์ทั้งหมด? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
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
     WHERE r.type = 'pa' AND r.is_active = 1 AND (r.close_at >= NOW() - INTERVAL 30 DAY OR r.open_at > NOW())
     ORDER BY r.close_at"
);
$sentRounds = array_map('intval', array_column(
    db_all('SELECT round_id FROM pa_submissions WHERE teacher_id = ?', 'i', [$uid]),
    'round_id'
));
// รอบที่ส่งได้ และยังไม่เคยส่ง
$submittable = array_values(array_filter($rounds, fn($r) => round_can_submit($r) && !in_array((int)$r['id'], $sentRounds, true)));

$selRound = (int)(post('round_id') ?: get_int('round_id'));
if ($selRound && !is_post()) {
    $existing = db_value('SELECT id FROM pa_submissions WHERE round_id = ? AND teacher_id = ?', 'ii', [$selRound, $uid]);
    if ($existing) {
        redirect('teacher/pa.php?id=' . (int)$existing);
    }
}
$formPhase = 'agreement';
foreach ($submittable as $r) {
    if ((int)$r['id'] === $selRound || !$selRound) {
        $formPhase = $r['phase'];
        if (!$selRound) {
            $selRound = (int)$r['id'];
        }
        break;
    }
}

$mine = db_all(
    "SELECT p.*, r.name AS round_name FROM pa_submissions p JOIN submission_rounds r ON r.id = p.round_id
     WHERE p.teacher_id = ? ORDER BY p.fiscal_year DESC, p.phase, p.submitted_at DESC",
    'i',
    [$uid]
);

$pageTitle = 'ข้อตกลงในการพัฒนางาน (PA)';
$pageIcon = 'bi-clipboard-check';
$breadcrumbs = ['ข้อตกลงในการพัฒนางาน (PA)' => null];
require APP_ROOT . '/includes/header.php';
?>
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
        <div class="col-12"><div class="card"><div class="empty-state"><i class="bi bi-calendar-x"></i>ยังไม่มีรอบการส่ง PA</div></div></div>
    <?php endif; ?>
</div>

<div class="card mb-4">
    <div class="card-header"><i class="bi bi-cloud-upload me-2"></i>ส่งแบบเสนอข้อตกลงในการพัฒนางาน (PA)</div>
    <div class="card-body">
        <?php if (!$submittable): ?>
            <div class="empty-state"><i class="bi bi-lock"></i>ไม่มีรอบที่เปิดรับซึ่งท่านยังไม่ได้ส่ง — ระบบไม่อนุญาตให้อัปโหลดนอกช่วงเวลา</div>
        <?php else: ?>
            <form method="post" enctype="multipart/form-data" novalidate data-confirm="ตรวจสอบข้อมูลและไฟล์ถูกต้องแล้ว ต้องการส่ง PA ใช่หรือไม่?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="round_id">รอบการส่ง *</label>
                        <select class="form-select<?= $invalid('round_id') ?>" name="round_id" id="round_id" onchange="location.href='?round_id=' + this.value">
                            <?php foreach ($submittable as $r): ?>
                                <option value="<?= (int)$r['id'] ?>" <?= $selRound === (int)$r['id'] ? 'selected' : '' ?>>
                                    <?= e($r['name']) ?> — <?= e(phase_label($r['phase'])) ?><?= round_state($r) === 'late' ? ' [ส่งช้า]' : '' ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('round_id') ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">ปีงบประมาณ</label>
                        <?php foreach ($submittable as $r): if ((int)$r['id'] === $selRound): ?>
                            <input class="form-control" value="<?= (int)($r['fiscal_year'] ?: current_fiscal_year()) ?>" readonly>
                        <?php endif; endforeach; ?>
                    </div>
                    <?php pa_fields($user, $formPhase, $invalid, $err, $maxText); ?>
                    <div class="col-md-6">
                        <label class="form-label" for="file">ไฟล์แบบ PA (ฉบับลงนาม) *</label>
                        <input type="file" class="form-control<?= $invalid('file') ?>" name="file" id="file" accept="<?= e(upload_accept()) ?>" required>
                        <div class="form-text"><?= e(upload_hint()) ?></div><?= $err('file') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="note">หมายเหตุ</label>
                        <input class="form-control" name="note" id="note" maxlength="500" value="<?= old('note') ?>">
                    </div>
                </div>
                <div class="text-end mt-4">
                    <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังอัปโหลด..."><i class="bi bi-send me-1"></i> ส่ง PA</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-folder2-open me-2"></i>PA ของฉัน (<?= count($mine) ?>)</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable" data-order="[]">
                <thead><tr><th>ปีงบประมาณ</th><th>รอบ</th><th>ชื่อรอบการส่ง</th><th>ฉบับที่</th><th>สถานะ</th><th>ส่งเมื่อ</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($mine as $m): ?>
                    <tr>
                        <td class="fw-semibold"><?= (int)$m['fiscal_year'] ?></td>
                        <td><?= e(phase_label($m['phase'])) ?></td>
                        <td class="small"><?= e($m['round_name']) ?></td>
                        <td class="text-center"><?= (int)$m['current_version'] ?></td>
                        <td data-order="<?= e($m['status']) ?>"><?= status_badge($m['status'], (bool)$m['is_late']) ?></td>
                        <td class="small" data-order="<?= e($m['submitted_at']) ?>"><?= e(thai_datetime($m['submitted_at'])) ?></td>
                        <td class="text-end">
                            <a href="<?= e(url('teacher/pa.php?id=' . (int)$m['id'])) ?>" class="btn btn-sm <?= $m['status'] === 'revision' ? 'btn-danger' : 'btn-outline-primary' ?> text-nowrap">
                                <?= $m['status'] === 'revision' ? '<i class="bi bi-pencil-square"></i> แก้ไข' : '<i class="bi bi-eye"></i> ดู' ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
