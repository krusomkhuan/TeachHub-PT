<?php
/**
 * ไฟล์: hr/events_manage.php
 * กิจกรรมวันสำคัญ (ผู้ดูแลงานบุคลากร) — สร้างกิจกรรม และเช็คชื่อ/ยืนยันการเข้าร่วมของบุคลากร
 *   ?new=1 เพิ่ม   ?edit=xx แก้ไข   ?id=xx เช็คชื่อ   ?fy= ปีงบประมาณ
 * บุคลากรรายงานการเข้าร่วมเองได้ที่ hr/events.php (ถ้าเปิดให้รายงานเอง) — งานบุคลากรยืนยัน/แก้ไขได้ที่หน้าเช็คชื่อ
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
if (!can_manage_hr($user)) {
    abort_page(403, 'ไม่มีสิทธิ์เข้าถึงหน้านี้', 'หน้านี้สำหรับผู้ดูแลงานบุคลากร');
}
$uid = (int)$user['id'];
$self = 'hr/events_manage.php';
$activeMenu = $self;
$errors = [];

if (is_post()) {
    csrf_verify();
    $id = (int)post('id');
    $ev = $id > 0 ? db_one('SELECT * FROM hr_events WHERE id = ?', 'i', [$id]) : null;
    if ($id > 0 && !$ev) {
        abort_page(404, 'ไม่พบกิจกรรม');
    }
    $action = post('action');

    if ($action === 'delete' && $ev) {
        foreach (db_all('SELECT photo_stored FROM hr_event_attendances WHERE event_id = ? AND photo_stored IS NOT NULL', 'i', [$id]) as $ph) {
            delete_upload(HR_UPLOAD_DIR, $ph['photo_stored']);
        }
        db_exec('DELETE FROM hr_events WHERE id = ?', 'i', [$id]);
        log_activity('hr_event_delete', 'ลบกิจกรรม ' . $ev['title'] . ' (' . thai_date($ev['event_date']) . ')');
        flash('success', 'ลบกิจกรรมแล้ว');
        redirect($self . '?fy=' . (int)$ev['fiscal_year']);
    }

    if ($action === 'attend' && $ev) {
        // บันทึกการเช็คชื่อทั้งหมด: status[user_id] = attended|leave|absent|'' (ว่าง = ยังไม่บันทึก/ลบรายการ)
        $valid = array_flip(hr_event_participant_ids($ev)); // เช็คชื่อได้เฉพาะผู้ที่ถูกกำหนดให้เข้าร่วม
        $statuses = (array)($_POST['status'] ?? []);
        $notes = (array)($_POST['note'] ?? []);
        $existing = [];
        foreach (db_all('SELECT * FROM hr_event_attendances WHERE event_id = ?', 'i', [$id]) as $a) {
            $existing[(int)$a['user_id']] = $a;
        }
        $saved = 0;
        foreach ($statuses as $pid => $st) {
            $pid = (int)$pid;
            if (!isset($valid[$pid])) {
                continue;
            }
            $note = mb_substr((string)($notes[$pid] ?? ''), 0, 300);
            if ($st === '') {
                if (isset($existing[$pid])) {
                    delete_upload(HR_UPLOAD_DIR, $existing[$pid]['photo_stored']);
                    db_exec('DELETE FROM hr_event_attendances WHERE event_id = ? AND user_id = ?', 'ii', [$id, $pid]);
                    $saved++;
                }
                continue;
            }
            if (!isset(hr_attend_labels()[$st])) {
                continue;
            }
            $old = $existing[$pid] ?? null;
            if ($old && $old['status'] === $st && $old['note'] === $note && (int)$old['verified'] === 1) {
                continue;
            }
            db_exec(
                'INSERT INTO hr_event_attendances (event_id, user_id, status, note, verified, recorded_by) VALUES (?, ?, ?, ?, 1, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), verified = 1, recorded_by = VALUES(recorded_by)',
                'iissi',
                [$id, $pid, $st, $note, $uid]
            );
            $saved++;
        }
        log_activity('hr_attend_save', 'เช็คชื่อกิจกรรม ' . $ev['title'] . ' (' . $saved . ' รายการ)');
        flash('success', 'บันทึกการเช็คชื่อแล้ว (' . $saved . ' รายการ)');
        redirect($self . '?id=' . $id);
    }

    // ---------- บันทึกกิจกรรม ----------
    $d = [
        'title'       => mb_substr(post('title'), 0, 300),
        'event_date'  => post('event_date'),
        'start_time'  => preg_match('/^\d{2}:\d{2}$/', post('start_time')) ? post('start_time') : null,
        'location'    => mb_substr(post('location'), 0, 200),
        'dress_code'  => mb_substr(post('dress_code'), 0, 200),
        'detail'      => mb_substr(post('detail'), 0, 2000),
        'is_required' => post('is_required') === '1' ? 1 : 0,
        'self_report' => post('self_report') === '1' ? 1 : 0,
        'participant_mode' => post('participant_mode') === 'selected' ? 'selected' : 'all',
        'order_id'    => (int)post('order_id') ?: null,
    ];
    if ($d['title'] === '') {
        $errors['title'] = 'กรุณาระบุชื่อกิจกรรม';
    }
    if (!hr_valid_date($d['event_date'])) {
        $errors['event_date'] = 'กรุณาเลือกวันที่จัดกิจกรรม';
    }
    if ($d['order_id'] !== null && !db_value('SELECT 1 FROM hr_orders WHERE id = ?', 'i', [$d['order_id']])) {
        $d['order_id'] = null;
    }
    // ผู้เข้าร่วม (เฉพาะผู้ที่กำหนด): ติ๊กเลือกรายคน (หน้าแบบฟอร์มมีปุ่มเพิ่มจากคำสั่ง/ทั้งแผนก)
    $participants = [];
    if ($d['participant_mode'] === 'selected') {
        $participants = array_map('intval', (array)($_POST['people'] ?? []));
        $participants = array_values(array_intersect(array_unique($participants), array_map('intval', array_column(hr_people(), 'id'))));
        if (!$participants) {
            $errors['people'] = 'กรุณาเลือกผู้เข้าร่วมอย่างน้อย 1 คน (หรือเลือก “บุคลากรทุกคน”)';
        }
    }
    if ($errors) {
        $_GET[$ev ? 'edit' : 'new'] = $ev ? (string)$id : '1';
    } else {
        $fy = hr_fiscal_year_of($d['event_date']);
        if ($ev) {
            db_exec('UPDATE hr_events SET fiscal_year = ?, title = ?, event_date = ?, start_time = ?, location = ?, dress_code = ?, detail = ?, is_required = ?, self_report = ?,
                            participant_mode = ?, order_id = ? WHERE id = ?',
                'issssssiisii', [$fy, $d['title'], $d['event_date'], $d['start_time'], $d['location'], $d['dress_code'], $d['detail'], $d['is_required'], $d['self_report'],
                    $d['participant_mode'], $d['order_id'], $id]);
        } else {
            $id = db_insert('INSERT INTO hr_events (fiscal_year, title, event_date, start_time, location, dress_code, detail, is_required, self_report, participant_mode, order_id, created_by)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'issssssiisii', [$fy, $d['title'], $d['event_date'], $d['start_time'], $d['location'], $d['dress_code'], $d['detail'], $d['is_required'], $d['self_report'],
                    $d['participant_mode'], $d['order_id'], $uid]);
        }
        // รายชื่อผู้เข้าร่วมเดิมแทนที่ด้วยรายชื่อใหม่ (ผลเช็คชื่อของผู้ที่ถูกนำออกยังเก็บไว้ แต่ไม่นับในสรุป)
        db_exec('DELETE FROM hr_event_participants WHERE event_id = ?', 'i', [$id]);
        foreach ($participants as $p) {
            db_exec('INSERT INTO hr_event_participants (event_id, user_id) VALUES (?, ?)', 'ii', [$id, $p]);
        }
        log_activity('hr_event_save', ($ev ? 'แก้ไข' : 'สร้าง') . 'กิจกรรม ' . $d['title'] . ' (' . thai_date($d['event_date']) . ') ผู้เข้าร่วม: '
            . ($d['participant_mode'] === 'all' ? 'ทุกคน' : count($participants) . ' คน'));
        flash('success', 'บันทึกกิจกรรมเรียบร้อยแล้ว');
        redirect($self . '?fy=' . $fy);
    }
}

// =====================================================================
// แบบฟอร์มกิจกรรม
// =====================================================================
$editId = get_int('edit');
if (get('new') === '1' || $editId > 0) {
    $ev = $editId > 0 ? db_one('SELECT * FROM hr_events WHERE id = ?', 'i', [$editId]) : null;
    if ($editId > 0 && !$ev) {
        abort_page(404, 'ไม่พบกิจกรรม');
    }
    $val = fn(string $k, string $def = '') => is_post() ? post($k) : (string)($ev[$k] ?? $def);
    $err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
    $inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
    $people = hr_people();
    $mode = $val('participant_mode', 'all') === 'selected' ? 'selected' : 'all';
    $selected = is_post() ? array_map('intval', (array)($_POST['people'] ?? []))
        : ($ev ? array_map('intval', array_column(db_all('SELECT user_id FROM hr_event_participants WHERE event_id = ?', 'i', [$editId]), 'user_id')) : []);
    $orders = db_all("SELECT o.id, o.order_no, o.title, o.order_date, (SELECT COUNT(*) FROM hr_order_users ou WHERE ou.order_id = o.id) AS people
                      FROM hr_orders o ORDER BY o.order_date DESC, o.id DESC LIMIT 200");
    $orderMembers = [];
    foreach (db_all('SELECT order_id, user_id FROM hr_order_users') as $r) {
        $orderMembers[(int)$r['order_id']][] = (int)$r['user_id'];
    }
    $deptGroups = [];
    foreach ($people as $pp) {
        $deptGroups[preg_replace('/^แผนกวิชา/u', '', (string)$pp['department_name']) ?: 'ไม่สังกัดแผนก'][] = (int)$pp['id'];
    }
    ksort($deptGroups);
    $pageTitle = $ev ? 'แก้ไขกิจกรรมวันสำคัญ' : 'เพิ่มกิจกรรมวันสำคัญ';
    $pageIcon = 'bi-flag';
    $breadcrumbs = ['กิจกรรมวันสำคัญ' => $self, $pageTitle => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <form method="post" novalidate class="row g-4">
      <div class="col-xl-7">
       <div class="card">
        <div class="card-body">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)($ev['id'] ?? 0) ?>">
            <div class="row g-3">
                <div class="col-12"><label class="form-label" for="title">ชื่อกิจกรรม <span class="text-danger">*</span></label>
                    <input class="form-control<?= $inv('title') ?>" name="title" id="title" maxlength="300" list="evList" value="<?= e($val('title')) ?>" required
                           placeholder="เช่น พิธีไหว้ครู ประจำปีการศึกษา 2569"><?= $err('title') ?>
                    <datalist id="evList"><?php foreach (['พิธีไหว้ครู', 'วันเฉลิมพระชนมพรรษา', 'วันแม่แห่งชาติ', 'วันพ่อแห่งชาติ', 'วันปิยมหาราช', 'วันครู', 'วันสถาปนาวิทยาลัย', 'พิธีวันคล้ายวันสวรรคต', 'กิจกรรมเข้าแถวหน้าเสาธง'] as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
                <div class="col-md-4"><label class="form-label" for="event_date">วันที่ <span class="text-danger">*</span></label>
                    <input type="date" class="form-control<?= $inv('event_date') ?>" name="event_date" id="event_date" value="<?= e($val('event_date')) ?>" required><?= $err('event_date') ?></div>
                <div class="col-md-3"><label class="form-label" for="start_time">เวลา</label>
                    <input type="time" class="form-control" name="start_time" id="start_time" value="<?= e(substr($val('start_time'), 0, 5)) ?>"></div>
                <div class="col-md-5"><label class="form-label" for="location">สถานที่</label>
                    <input class="form-control" name="location" id="location" maxlength="200" value="<?= e($val('location')) ?>"></div>
                <div class="col-12"><label class="form-label" for="dress_code">การแต่งกาย</label>
                    <input class="form-control" name="dress_code" id="dress_code" maxlength="200" value="<?= e($val('dress_code')) ?>" placeholder="เช่น ชุดปกติขาว / ชุดผ้าไทย"></div>
                <div class="col-12"><label class="form-label" for="detail">รายละเอียด</label>
                    <textarea class="form-control" name="detail" id="detail" rows="3" maxlength="2000"><?= e($val('detail')) ?></textarea></div>
                <div class="col-md-6"><div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_required" id="is_required" value="1" <?= $val('is_required', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_required">บังคับเข้าร่วม (นับในสรุปการเข้าร่วมของผู้ที่ถูกกำหนด)</label></div></div>
                <div class="col-md-6"><div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="self_report" id="self_report" value="1" <?= $val('self_report', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="self_report">ให้บุคลากรรายงานการเข้าร่วมเอง (ตั้งแต่วันงานถึง 7 วันหลังวันงาน)</label></div></div>
            </div>
        </div>
        <div class="card-footer d-flex gap-2">
            <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            <button class="btn btn-accent ms-auto" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกกิจกรรม</button>
        </div>
       </div>
      </div>
      <div class="col-xl-5">
        <div class="card">
            <div class="card-header d-flex align-items-center"><i class="bi bi-people me-2"></i>ผู้เข้าร่วมกิจกรรม
                <span class="badge bg-primary ms-2" id="pickCount"><?= $mode === 'all' ? count($people) : count($selected) ?></span></div>
            <div class="card-body">
                <div class="btn-group w-100 mb-3" role="group" aria-label="ผู้เข้าร่วม">
                    <input type="radio" class="btn-check" name="participant_mode" id="modeAll" value="all" <?= $mode === 'all' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="modeAll">บุคลากรทุกคน</label>
                    <input type="radio" class="btn-check" name="participant_mode" id="modeSel" value="selected" <?= $mode === 'selected' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="modeSel">เฉพาะผู้ที่กำหนด</label>
                </div>
                <div class="mb-3">
                    <label class="form-label small mb-1" for="order_id">คำสั่งที่อ้างอิง (ถ้ามี)</label>
                    <select class="form-select form-select-sm" name="order_id" id="order_id">
                        <option value="">-- ไม่ระบุ --</option>
                        <?php foreach ($orders as $o): ?>
                            <option value="<?= (int)$o['id'] ?>" <?= $val('order_id') === (string)$o['id'] ? 'selected' : '' ?>>
                                <?= e($o['order_no'] . ' ' . str_limit($o['title'], 60) . ' (' . (int)$o['people'] . ' คน)') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="pickBox"<?= $mode === 'all' ? ' style="display:none"' : '' ?>>
                    <?php if (isset($errors['people'])): ?><div class="alert alert-danger small py-2"><?= e($errors['people']) ?></div><?php endif; ?>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-success" id="pickOrder" title="เพิ่มรายชื่อทุกคนในคำสั่งที่เลือก"><i class="bi bi-file-earmark-plus"></i> เพิ่มจากคำสั่ง</button>
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">เพิ่มทั้งแผนก</button>
                            <ul class="dropdown-menu" style="max-height:300px;overflow:auto">
                                <?php foreach ($deptGroups as $dn => $ids): ?>
                                    <li><button type="button" class="dropdown-item small" data-dept="<?= e(json_encode($ids)) ?>"><?= e($dn) ?> (<?= count($ids) ?>)</button></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="pickNone">ล้าง</button>
                    </div>
                    <input type="search" class="form-control form-control-sm mb-2" id="pickFilter" placeholder="ค้นหาชื่อ / แผนก">
                    <div class="border rounded p-2" style="max-height:420px;overflow:auto" id="pickList">
                        <?php foreach ($people as $pp): $pid = (int)$pp['id']; ?>
                            <div class="form-check" data-text="<?= e($pp['full_name'] . ' ' . $pp['department_name'] . ' ' . $pp['position']) ?>">
                                <input class="form-check-input" type="checkbox" name="people[]" value="<?= $pid ?>" id="pp<?= $pid ?>" <?= in_array($pid, $selected, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="pp<?= $pid ?>"><?= e($pp['full_name']) ?>
                                    <span class="small text-muted"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$pp['department_name']) ?: $pp['position']) ?></span></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text">เพิ่มคนทีหลังได้ที่ปุ่ม “แก้ไขกิจกรรม” — ผู้ที่ไม่ได้ถูกกำหนดจะไม่เห็นกิจกรรมนี้และไม่ถูกนับในสรุป</div>
                </div>
                <div id="allNote" class="small text-muted"<?= $mode === 'selected' ? ' style="display:none"' : '' ?>>บุคลากรที่ใช้งานระบบทุกคน (<?= count($people) ?> คน) ต้องเข้าร่วมกิจกรรมนี้</div>
            </div>
        </div>
      </div>
    </form>
    <?php
    $pageScripts = '
(function () {
    var members = ' . json_encode($orderMembers) . ', total = ' . count($people) . ';
    var list = document.getElementById("pickList"), c = document.getElementById("pickCount"), box = document.getElementById("pickBox"),
        note = document.getElementById("allNote"), sel = document.getElementById("modeSel"), f = document.getElementById("pickFilter");
    function check(ids) { ids.forEach(function (id) { var i = document.getElementById("pp" + id); if (i) i.checked = true; }); count(); }
    function count() { c.textContent = sel.checked ? list.querySelectorAll("input:checked").length : total; }
    function mode() { box.style.display = sel.checked ? "" : "none"; note.style.display = sel.checked ? "none" : ""; count(); }
    document.querySelectorAll("input[name=participant_mode]").forEach(function (r) { r.addEventListener("change", mode); });
    document.getElementById("pickOrder").addEventListener("click", function () {
        var id = document.getElementById("order_id").value;
        if (!id) { Swal.fire("เลือกคำสั่งก่อน", "เลือกคำสั่งที่อ้างอิงด้านบน แล้วกดเพิ่มจากคำสั่ง", "info"); return; }
        if (!(members[id] || []).length) { Swal.fire("คำสั่งนี้ยังไม่มีรายชื่อ", "เพิ่มรายชื่อบุคลากรในคำสั่งที่เมนูคำสั่ง/หนังสือราชการ", "info"); return; }
        check(members[id]);
    });
    document.querySelectorAll("[data-dept]").forEach(function (b) { b.addEventListener("click", function () { check(JSON.parse(b.dataset.dept)); }); });
    document.getElementById("pickNone").addEventListener("click", function () { list.querySelectorAll("input").forEach(function (i) { i.checked = false; }); count(); });
    f.addEventListener("input", function () {
        var q = f.value.trim().toLowerCase();
        list.querySelectorAll(".form-check").forEach(function (el) { el.style.display = el.dataset.text.toLowerCase().indexOf(q) >= 0 ? "" : "none"; });
    });
    list.addEventListener("change", count);
})();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// เช็คชื่อ (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $ev = db_one('SELECT * FROM hr_events WHERE id = ?', 'i', [$viewId]);
    if (!$ev) {
        abort_page(404, 'ไม่พบกิจกรรม');
    }
    $partIds = array_flip(hr_event_participant_ids($ev));
    $people = array_values(array_filter(hr_people(), fn($p) => isset($partIds[(int)$p['id']])));
    $att = [];
    foreach (db_all('SELECT * FROM hr_event_attendances WHERE event_id = ?', 'i', [$viewId]) as $a) {
        if (isset($partIds[(int)$a['user_id']])) { // ผลของผู้ที่ถูกนำออกจากรายชื่อไม่แสดง/ไม่นับ
            $att[(int)$a['user_id']] = $a;
        }
    }
    $order = $ev['order_id'] ? db_one('SELECT id, order_no, title, file_stored FROM hr_orders WHERE id = ?', 'i', [(int)$ev['order_id']]) : null;
    $cnt = array_fill_keys(array_keys(hr_attend_labels()), 0);
    foreach ($att as $a) {
        $cnt[$a['status']]++;
    }
    $pageTitle = 'เช็คชื่อ: ' . $ev['title'];
    $pageIcon = 'bi-flag';
    $breadcrumbs = ['กิจกรรมวันสำคัญ' => $self . '?fy=' . (int)$ev['fiscal_year'], 'เช็คชื่อ' => null];
    $pageActions = '<a href="' . e(url($self . '?edit=' . $viewId)) . '" class="btn btn-outline-primary"><i class="bi bi-person-plus me-1"></i> เพิ่ม/แก้ไขผู้เข้าร่วม</a>';
    require APP_ROOT . '/includes/header.php';
    ?>
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-3 align-items-center">
            <div><i class="bi bi-calendar-event me-1"></i><?= e(thai_date($ev['event_date'], false, true)) ?><?= $ev['start_time'] ? ' เวลา ' . e(substr($ev['start_time'], 0, 5)) . ' น.' : '' ?>
                <?= $ev['location'] !== '' ? ' · ' . e($ev['location']) : '' ?></div>
            <div class="ms-auto d-flex gap-2 flex-wrap">
                <span class="badge bg-success">เข้าร่วม <?= $cnt['attended'] ?></span>
                <span class="badge bg-info text-dark">ลา/ไปราชการ <?= $cnt['leave'] ?></span>
                <span class="badge bg-danger">ไม่เข้าร่วม <?= $cnt['absent'] ?></span>
                <span class="badge bg-light text-dark border">ยังไม่บันทึก <?= count($people) - array_sum($cnt) ?></span>
            </div>
        </div>
    </div>
    <form method="post" class="card">
        <?= csrf_field() ?><input type="hidden" name="action" value="attend"><input type="hidden" name="id" value="<?= $viewId ?>">
        <div class="card-header d-flex flex-wrap gap-2 align-items-center">
            <span><i class="bi bi-person-check me-2"></i><?= hr_event_is_selected($ev) ? 'ผู้ที่ต้องเข้าร่วม' : 'บุคลากรทุกคน' ?> (<?= count($people) ?> คน)
                <?php if ($order): ?><span class="small text-muted ms-1">ตามคำสั่งที่ <?= e($order['order_no']) ?>
                    <?php if ($order['file_stored']): ?><a href="<?= e(hr_file_url('order', (int)$order['id'])) ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i></a><?php endif; ?></span><?php endif; ?></span>
            <div class="ms-auto d-flex gap-2">
                <input type="search" class="form-control form-control-sm" id="attFilter" placeholder="ค้นหาชื่อ">
                <button type="button" class="btn btn-sm btn-outline-success text-nowrap" id="markRest">ที่ยังไม่บันทึก = เข้าร่วม</button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" id="attTable">
                    <thead><tr><th>ชื่อ-สกุล</th><th>แผนก/ตำแหน่ง</th><th>รายงานเอง</th><th style="min-width:330px">การเข้าร่วม</th><th>หมายเหตุ</th></tr></thead>
                    <tbody>
                    <?php foreach ($people as $pp): $pid = (int)$pp['id']; $a = $att[$pid] ?? null; $cur = $a['status'] ?? ''; ?>
                        <tr data-text="<?= e($pp['full_name']) ?>">
                            <td><?= e($pp['full_name']) ?></td>
                            <td class="small text-muted"><?= e(preg_replace('/^แผนกวิชา/u', '', (string)$pp['department_name']) ?: $pp['position']) ?></td>
                            <td class="small"><?php if ($a && !(int)$a['verified']): ?><span class="badge bg-warning text-dark">รอยืนยัน</span><?php endif; ?>
                                <?php if ($a && $a['photo_stored']): ?><a href="<?= e(hr_file_url('attend', $viewId, $pid)) ?>" target="_blank" rel="noopener" title="ภาพหลักฐาน"><i class="bi bi-image fs-5"></i></a><?php endif; ?></td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <?php foreach (['attended' => ['เข้าร่วม', 'success'], 'leave' => ['ลา/ราชการ', 'info'], 'absent' => ['ไม่เข้าร่วม', 'danger'], '' => ['-', 'secondary']] as $k => [$label, $cls]): ?>
                                        <input type="radio" class="btn-check" name="status[<?= $pid ?>]" id="s<?= $pid ?>_<?= $k ?: 'none' ?>" value="<?= $k ?>" <?= $cur === $k ? 'checked' : '' ?>>
                                        <label class="btn btn-outline-<?= $cls ?>" for="s<?= $pid ?>_<?= $k ?: 'none' ?>"><?= e($label) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td><input class="form-control form-control-sm" name="note[<?= $pid ?>]" maxlength="300" value="<?= e($a['note'] ?? '') ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer d-flex">
            <span class="small text-muted">บันทึกแล้วถือว่างานบุคลากรยืนยันการเข้าร่วม · เลือก “-” เพื่อลบรายการ</span>
            <button class="btn btn-accent ms-auto" data-loading-text="กำลังบันทึก..."><i class="bi bi-save me-1"></i> บันทึกการเช็คชื่อ</button>
        </div>
    </form>
    <?php
    $pageScripts = '
(function () {
    var f = document.getElementById("attFilter"), rows = document.querySelectorAll("#attTable tbody tr");
    f.addEventListener("input", function () {
        var q = f.value.trim().toLowerCase();
        rows.forEach(function (tr) { tr.style.display = tr.dataset.text.toLowerCase().indexOf(q) >= 0 ? "" : "none"; });
    });
    document.getElementById("markRest").addEventListener("click", function () {
        rows.forEach(function (tr) {
            var none = tr.querySelector("input[value=\'\']");
            if (none && none.checked) tr.querySelector("input[value=attended]").checked = true;
        });
    });
})();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// รายการกิจกรรม
// =====================================================================
$fy = hr_fiscal_param();
$total = count(hr_people());
$partCounts = [];
foreach (db_all("SELECT p.event_id, COUNT(*) AS n FROM hr_event_participants p JOIN users u ON u.id = p.user_id AND u.status = 'active' GROUP BY p.event_id") as $pc) {
    $partCounts[(int)$pc['event_id']] = (int)$pc['n'];
}
$rows = db_all(
    "SELECT e.*, COALESCE(SUM(a.status = 'attended'), 0) AS attended, COALESCE(SUM(a.status = 'leave'), 0) AS on_leave,
            COALESCE(SUM(a.status = 'absent'), 0) AS absent, COALESCE(SUM(a.verified = 0), 0) AS unverified
     FROM hr_events e LEFT JOIN hr_event_attendances a ON a.event_id = e.id AND " . hr_event_participant_sql('a.user_id') . "
     WHERE e.fiscal_year = ? GROUP BY e.id ORDER BY e.event_date DESC",
    'i',
    [$fy]
);

$pageTitle = 'กิจกรรมวันสำคัญ';
$pageIcon = 'bi-flag';
$breadcrumbs = ['กิจกรรมวันสำคัญ' => null];
$pageActions = '<a href="' . e(url($self . '?new=1')) . '" class="btn btn-accent"><i class="bi bi-plus-lg me-1"></i> เพิ่มกิจกรรม</a>'
    . ' <a href="' . e(url('hr/reports.php?tab=events&fy=' . $fy)) . '" class="btn btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i> สรุปการเข้าร่วม</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3"><label class="form-label small mb-1" for="fy">ปีงบประมาณ</label>
                <select name="fy" id="fy" class="form-select" onchange="this.form.submit()"><?php foreach (hr_fiscal_years() as $y): ?><option value="<?= $y ?>" <?= $y === $fy ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle datatable" data-order="[]">
                <thead><tr><th>วันที่</th><th>กิจกรรม</th><th class="text-center">เข้าร่วม</th><th class="text-center">ลา/ราชการ</th><th class="text-center">ไม่เข้าร่วม</th><th class="text-center">ยังไม่บันทึก</th><th class="no-sort"></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="text-nowrap" data-order="<?= e($r['event_date']) ?>"><?= e(thai_date($r['event_date'])) ?></td>
                        <td><?= e($r['title']) ?>
                            <div class="small text-muted"><?= e($r['location'] ?: '-') ?><?= (int)$r['is_required'] ? '' : ' · ไม่บังคับ' ?><?= (int)$r['self_report'] ? ' · รายงานเองได้' : '' ?></div>
                            <span class="badge bg-light text-dark border"><i class="bi bi-people me-1"></i><?= hr_event_is_selected($r) ? 'ผู้ที่กำหนด ' . ($partCounts[(int)$r['id']] ?? 0) . ' คน' : 'ทุกคน' ?></span>
                            <?php if ((int)$r['unverified'] > 0): ?><span class="badge bg-warning text-dark">รอยืนยัน <?= (int)$r['unverified'] ?></span><?php endif; ?></td>
                        <td class="text-center"><?= (int)$r['attended'] ?></td>
                        <td class="text-center"><?= (int)$r['on_leave'] ?></td>
                        <td class="text-center"><?= (int)$r['absent'] ?></td>
                        <td class="text-center"><?= max(0, (hr_event_is_selected($r) ? ($partCounts[(int)$r['id']] ?? 0) : $total) - (int)$r['attended'] - (int)$r['on_leave'] - (int)$r['absent']) ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-accent"><i class="bi bi-person-check"></i> เช็คชื่อ</a>
                            <a href="<?= e(url($self . '?edit=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary" title="แก้ไข"><i class="bi bi-pencil"></i></a>
                            <form method="post" class="d-inline" data-confirm="ลบกิจกรรม “<?= e($r['title']) ?>” พร้อมข้อมูลการเข้าร่วม?" data-confirm-icon="warning">
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
