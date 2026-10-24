<?php
/**
 * ไฟล์: hr/events.php
 * กิจกรรมวันสำคัญของฉัน — ดูกำหนดการ รายงานการเข้าร่วม (แนบภาพได้) และสรุปการเข้าร่วมรายปีงบประมาณ   ?fy=
 * รายงานเองได้ตั้งแต่วันงานถึง 7 วันหลังวันงาน และแก้ไขได้จนกว่างานบุคลากรจะยืนยัน
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$self = 'hr/events.php';
$activeMenu = $self;

if (is_post()) {
    csrf_verify();
    $ev = db_one('SELECT * FROM hr_events WHERE id = ?', 'i', [(int)post('event_id')]);
    $old = $ev ? db_one('SELECT * FROM hr_event_attendances WHERE event_id = ? AND user_id = ?', 'ii', [(int)$ev['id'], $uid]) : null;
    if (!$ev || !hr_event_is_participant($ev, $uid) || !hr_event_self_open($ev) || ($old && (int)$old['verified'] === 1)) {
        abort_page(403, 'รายงานการเข้าร่วมกิจกรรมนี้ไม่ได้', 'หมดเวลารายงาน หรืองานบุคลากรยืนยันแล้ว');
    }
    $status = in_array(post('status'), ['attended', 'leave'], true) ? post('status') : 'attended';
    $note = mb_substr(post('note'), 0, 300);
    $up = hr_take_upload('photo', true);
    if (!$up['ok']) {
        flash('error', $up['error']);
        redirect($self . '?fy=' . (int)$ev['fiscal_year']);
    }
    $photo = $up['stored_name'] ? [$up['stored_name'], $up['original_name']] : [$old['photo_stored'] ?? null, $old['photo_original'] ?? null];
    db_exec(
        'INSERT INTO hr_event_attendances (event_id, user_id, status, note, photo_stored, photo_original, verified, recorded_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), photo_stored = VALUES(photo_stored),
                                 photo_original = VALUES(photo_original), recorded_by = VALUES(recorded_by)',
        'iissssi',
        [(int)$ev['id'], $uid, $status, $note, $photo[0], $photo[1], $uid]
    );
    if ($up['stored_name'] && !empty($old['photo_stored'])) {
        delete_upload(HR_UPLOAD_DIR, $old['photo_stored']);
    }
    log_activity('hr_attend_self', 'รายงาน' . hr_attend_labels()[$status] . 'กิจกรรม ' . $ev['title']);
    flash('success', 'รายงานการเข้าร่วมกิจกรรมแล้ว — รองานบุคลากรยืนยัน');
    redirect($self . '?fy=' . (int)$ev['fiscal_year']);
}

$fy = hr_fiscal_param();
$rows = db_all(
    'SELECT e.*, a.status AS my_status, a.note AS my_note, a.verified, a.photo_stored
     FROM hr_events e LEFT JOIN hr_event_attendances a ON a.event_id = e.id AND a.user_id = ?
     WHERE e.fiscal_year = ? AND ' . hr_event_participant_sql('?') . ' ORDER BY e.event_date DESC',
    'iii',
    [$uid, $fy, $uid]
);
$today = date('Y-m-d');
$req = array_filter($rows, fn($r) => (int)$r['is_required'] === 1 && $r['event_date'] <= $today);
$attended = count(array_filter($req, fn($r) => $r['my_status'] === 'attended'));

$pageTitle = 'กิจกรรมวันสำคัญ';
$pageIcon = 'bi-flag';
$breadcrumbs = ['กิจกรรมวันสำคัญ' => null];
if (can_manage_hr($user)) {
    $pageActions = '<a href="' . e(url('hr/events_manage.php')) . '" class="btn btn-outline-primary"><i class="bi bi-gear me-1"></i> จัดการกิจกรรม/เช็คชื่อ</a>';
}
require APP_ROOT . '/includes/header.php';
?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-4"><div class="stat-card"><div class="stat-icon ic-green"><i class="bi bi-flag"></i></div>
            <div><div class="stat-value"><?= $attended ?> / <?= count($req) ?></div><div class="stat-label">เข้าร่วมกิจกรรมที่กำหนด (ถึงวันนี้)</div>
                <div class="stat-sub"><?= $req ? percent($attended, count($req)) . '%' : '-' ?></div></div></div></div>
    <div class="col-sm-6 col-xl-4">
        <form method="get" class="stat-card"><div class="stat-icon ic-blue"><i class="bi bi-calendar3"></i></div>
            <div class="flex-fill"><label class="stat-label d-block" for="fy">ปีงบประมาณ</label>
                <select name="fy" id="fy" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
        </form>
    </div>
</div>

<div class="row g-3">
    <?php foreach ($rows as $r): $open = hr_event_self_open($r) && !(int)$r['verified']; ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100<?= $r['event_date'] >= $today ? ' border-primary' : '' ?>">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <div class="text-center rounded bg-light px-2 py-1" style="min-width:58px">
                            <div class="fw-bold fs-5 lh-1"><?= (int)date('j', strtotime($r['event_date'])) ?></div>
                            <div class="small"><?= e(thai_months()[(int)date('n', strtotime($r['event_date']))]) ?></div>
                        </div>
                        <div>
                            <div class="fw-semibold"><?= e($r['title']) ?></div>
                            <div class="small text-muted"><?= $r['start_time'] ? e(substr($r['start_time'], 0, 5)) . ' น. · ' : '' ?><?= e($r['location'] ?: '-') ?></div>
                            <?php if ($r['dress_code'] !== ''): ?><div class="small"><i class="bi bi-person-standing me-1"></i><?= e($r['dress_code']) ?></div><?php endif; ?>
                        </div>
                        <div class="ms-auto text-end"><?= hr_attend_badge($r['my_status']) ?>
                            <?php if ($r['my_status'] && !(int)$r['verified']): ?><div class="small text-muted">รอยืนยัน</div><?php endif; ?></div>
                    </div>
                    <?php if ($r['detail'] !== ''): ?><div class="small mb-2" style="white-space:pre-line"><?= e($r['detail']) ?></div><?php endif; ?>
                    <?php if ($open): ?>
                        <form method="post" enctype="multipart/form-data" class="border-top pt-2">
                            <?= csrf_field() ?><input type="hidden" name="event_id" value="<?= (int)$r['id'] ?>">
                            <div class="d-flex gap-2 mb-2">
                                <select name="status" class="form-select form-select-sm">
                                    <option value="attended" <?= $r['my_status'] !== 'leave' ? 'selected' : '' ?>>เข้าร่วม</option>
                                    <option value="leave" <?= $r['my_status'] === 'leave' ? 'selected' : '' ?>>ลา/ไปราชการ</option>
                                </select>
                                <input name="note" class="form-control form-control-sm" maxlength="300" placeholder="หมายเหตุ" value="<?= e($r['my_note'] ?? '') ?>">
                            </div>
                            <input type="file" name="photo" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png,.webp" aria-label="ภาพหลักฐาน">
                            <button class="btn btn-sm btn-accent w-100"><i class="bi bi-send me-1"></i> รายงานการเข้าร่วม</button>
                        </form>
                    <?php elseif ((int)$r['self_report'] === 1 && $r['event_date'] > $today): ?>
                        <div class="small text-muted border-top pt-2">รายงานการเข้าร่วมได้ตั้งแต่วันงาน</div>
                    <?php endif; ?>
                    <?php if ($r['photo_stored']): ?><a class="small" href="<?= e(hr_file_url('attend', (int)$r['id'], $uid)) ?>" target="_blank" rel="noopener"><i class="bi bi-image me-1"></i>ภาพที่แนบ</a><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (!$rows): ?><div class="col-12"><div class="alert alert-light border">ยังไม่มีกิจกรรมที่ท่านต้องเข้าร่วมในปีงบประมาณ <?= $fy ?></div></div><?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
