<?php
/**
 * ไฟล์: teacher/home_visits.php
 * รายงานการเยี่ยมบ้านนักเรียนของครูที่ปรึกษา — นักเรียน 1 คน เยี่ยม 1 ครั้งต่อปีการศึกษา
 *   (ไม่มี)  รายการเยี่ยมบ้านของปีการศึกษา   ?year= ปีการศึกษา (พ.ศ.)
 *   ?new=1   บันทึกการเยี่ยมบ้าน   ?id=xx ดูรายงาน   ?edit=xx แก้ไข (เฉพาะ "รอตรวจ" หรือ "ให้แก้ไข")
 * ครูต้องมีกลุ่มที่ปรึกษาในปีการศึกษานั้น (กำหนดโดยงานครูที่ปรึกษาและแนะแนว)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_role('teacher', 'supervisor');
$uid = (int)$user['id'];
$self = 'teacher/home_visits.php';
$activeMenu = $self;
$errors = [];

/** รายงานเยี่ยมบ้านของครูคนนี้เท่านั้น */
function own_home_visit(int $id, int $uid): ?array
{
    $v = home_visit_fetch($id);
    return ($v && (int)$v['teacher_id'] === $uid) ? $v : null;
}

/**
 * ตรวจข้อมูลแบบฟอร์ม
 * @param array $groups กลุ่มที่ปรึกษาของครูในปีการศึกษา (เลือกได้เฉพาะกลุ่มเหล่านี้)
 */
function home_visit_validate(array &$errors, array $groups, int $year): array
{
    $d = [
        'group_id'           => (int)post('group_id'),
        'student_code'       => mb_substr(post('student_code'), 0, 20),
        'student_name'       => mb_substr(preg_replace('/\s+/u', ' ', post('student_name')), 0, 150),
        'visit_date'         => post('visit_date'),
        'informant_name'     => mb_substr(post('informant_name'), 0, 150),
        'informant_relation' => mb_substr(post('informant_relation'), 0, 50),
        'address'            => mb_substr(post('address'), 0, 500),
        'living_with'        => post('living_with'),
        'economic_status'    => post('economic_status'),
        'family_info'        => post('family_info'),
        'learning_behavior'  => post('learning_behavior'),
        'risk_level'         => post('risk_level'),
        'assistance'         => post('assistance'),
        'latitude'           => post('latitude'),
        'longitude'          => post('longitude'),
    ];
    if (!in_array($d['group_id'], array_map('intval', array_column($groups, 'id')), true)) {
        $errors['group_id'] = 'กรุณาเลือกกลุ่มที่ปรึกษา';
    }
    if (!preg_match('/^[A-Za-z0-9-]{1,20}$/', $d['student_code'])) {
        $errors['student_code'] = 'รหัสนักเรียนเป็นตัวเลข/ตัวอักษรภาษาอังกฤษ ไม่เกิน 20 ตัว';
    }
    if ($d['student_name'] === '') {
        $errors['student_name'] = 'กรุณาระบุชื่อ-สกุลนักเรียน';
    }
    $range = home_visit_year_range($year);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['visit_date']) || strtotime($d['visit_date']) === false) {
        $errors['visit_date'] = 'กรุณาเลือกวันที่เยี่ยมบ้าน';
    } elseif ($d['visit_date'] > date('Y-m-d')) {
        $errors['visit_date'] = 'วันที่เยี่ยมบ้านต้องไม่เกินวันนี้';
    } elseif ($range && ($d['visit_date'] < $range[0] || $d['visit_date'] > $range[1])) {
        $errors['visit_date'] = 'วันที่ต้องอยู่ในปีการศึกษา ' . $year . ' (' . thai_date($range[0]) . ' – ' . thai_date($range[1]) . ')';
    }
    if ($d['informant_name'] === '') {
        $errors['informant_name'] = 'กรุณาระบุผู้ให้ข้อมูล';
    }
    if ($d['address'] === '') {
        $errors['address'] = 'กรุณาระบุที่อยู่';
    }
    if (!in_array($d['living_with'], home_visit_living_options(), true)) {
        $errors['living_with'] = 'กรุณาเลือก';
    }
    if (!isset(home_visit_economic_labels()[$d['economic_status']])) {
        $errors['economic_status'] = 'กรุณาเลือก';
    }
    if (!isset(home_visit_risk_labels()[$d['risk_level']])) {
        $errors['risk_level'] = 'กรุณาเลือกผลการคัดกรอง';
    }
    foreach (['family_info' => 'สภาพครอบครัวและที่อยู่อาศัย', 'learning_behavior' => 'ด้านการเรียนและพฤติกรรม'] as $k => $label) {
        if ($d[$k] === '' || mb_strlen($d[$k]) > 5000) {
            $errors[$k] = 'กรุณากรอก' . $label . ' (ไม่เกิน 5,000 ตัวอักษร)';
        }
    }
    if (mb_strlen($d['assistance']) > 5000) {
        $errors['assistance'] = 'ไม่เกิน 5,000 ตัวอักษร';
    } elseif ($d['assistance'] === '' && in_array($d['risk_level'], ['risk', 'problem'], true)) {
        $errors['assistance'] = 'นักเรียนกลุ่มเสี่ยง/มีปัญหา กรุณาระบุการช่วยเหลือ';
    }
    // พิกัด (ไม่บังคับ) ต้องมีครบทั้งคู่
    if ($d['latitude'] === '' && $d['longitude'] === '') {
        $d['latitude'] = $d['longitude'] = null;
    } elseif (!is_numeric($d['latitude']) || !is_numeric($d['longitude'])
        || abs((float)$d['latitude']) > 90 || abs((float)$d['longitude']) > 180) {
        $errors['latitude'] = 'พิกัดไม่ถูกต้อง';
    } else {
        $d['latitude'] = round((float)$d['latitude'], 7);
        $d['longitude'] = round((float)$d['longitude'], 7);
    }
    return $d;
}

// =====================================================================
// POST
// =====================================================================
if (is_post()) {
    csrf_verify();
    $action = post('action');

    if ($action === 'save') {
        $id = (int)post('id');
        $hv = $id ? own_home_visit($id, $uid) : null;
        if ($id && !$hv) {
            abort_page(404, 'ไม่พบรายงาน');
        }
        if ($hv && !in_array($hv['status'], ['pending', 'revision'], true)) {
            flash('error', 'รายงานนี้ผ่านการตรวจแล้ว ไม่สามารถแก้ไขได้');
            redirect($self . '?id=' . $id);
        }
        $year = $hv ? (int)$hv['school_year'] : home_visit_year((int)post('year'));
        $groups = home_visit_teacher_groups($uid, $year);
        $d = home_visit_validate($errors, $groups, $year);
        if (!isset($errors['student_code'])) {
            $dup = db_one(
                "SELECT v.id, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name FROM home_visits v JOIN users u ON u.id = v.teacher_id
                 WHERE v.school_year = ? AND v.student_code = ? AND v.id <> ?",
                'isi',
                [$year, $d['student_code'], $id]
            );
            if ($dup) {
                $errors['student_code'] = 'นักเรียนรหัสนี้มีรายงานเยี่ยมบ้านในปีการศึกษา ' . $year . ' แล้ว (โดย ' . $dup['teacher_name'] . ')';
            }
        }

        $newFiles = normalize_multi_files($_FILES['images'] ?? null);
        $existingImages = $hv ? count(home_visit_images((int)$hv['id'])) : 0;
        if (count($newFiles) + $existingImages > HOME_VISIT_MAX_IMAGES) {
            $errors['images'] = 'แนบภาพได้สูงสุด ' . HOME_VISIT_MAX_IMAGES . ' ภาพ (มีอยู่แล้ว ' . $existingImages . ' ภาพ)';
        } elseif (count($newFiles) + $existingImages < 1) {
            $errors['images'] = 'กรุณาแนบภาพถ่ายการเยี่ยมบ้านอย่างน้อย 1 ภาพ';
        }
        $uploaded = [];
        if (!$errors) {
            foreach ($newFiles as $f) {
                $up = handle_upload($f, 'home_visit', ['jpg', 'jpeg', 'png', 'webp'], ADVISOR_MAX_IMAGE_BYTES);
                if (!$up['ok']) {
                    $errors['images'] = $f['name'] . ': ' . $up['error'];
                    break;
                }
                $uploaded[] = $up;
            }
            if ($errors) {
                foreach ($uploaded as $up) {
                    delete_upload('home_visit', $up['stored_name']);
                }
                $uploaded = [];
            }
        }

        if (!$errors) {
            $cols = [$d['group_id'], $d['student_code'], $d['student_name'], $d['visit_date'], $d['informant_name'], $d['informant_relation'],
                $d['address'], $d['living_with'], $d['economic_status'], $d['family_info'], $d['learning_behavior'], $d['risk_level'],
                $d['assistance'], $d['latitude'], $d['longitude']];
            db()->begin_transaction();
            try {
                if ($hv) {
                    db_exec(
                        "UPDATE home_visits SET group_id = ?, student_code = ?, student_name = ?, visit_date = ?, informant_name = ?, informant_relation = ?,
                                address = ?, living_with = ?, economic_status = ?, family_info = ?, learning_behavior = ?, risk_level = ?,
                                assistance = ?, latitude = ?, longitude = ?, status = 'pending' WHERE id = ?",
                        'issssssssssssddi',
                        array_merge($cols, [(int)$hv['id']])
                    );
                    $visitId = (int)$hv['id'];
                } else {
                    $visitId = db_insert(
                        'INSERT INTO home_visits (group_id, student_code, student_name, visit_date, informant_name, informant_relation, address,
                                                  living_with, economic_status, family_info, learning_behavior, risk_level, assistance,
                                                  latitude, longitude, school_year, teacher_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                        'issssssssssssddii',
                        array_merge($cols, [$year, $uid])
                    );
                }
                foreach ($uploaded as $up) {
                    db_insert(
                        'INSERT INTO home_visit_images (visit_id, original_name, stored_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?)',
                        'isssi',
                        [$visitId, $up['original_name'], $up['stored_name'], $up['mime'], $up['size']]
                    );
                }
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollback();
                foreach ($uploaded as $up) {
                    delete_upload('home_visit', $up['stored_name']);
                }
                throw $ex;
            }
            $label = $d['student_name'] . ' (' . $d['student_code'] . ') วันที่ ' . thai_date($d['visit_date']);
            log_activity($hv ? 'home_visit_update' : 'home_visit_submit', ($hv ? 'แก้ไข' : 'ส่ง') . 'รายงานเยี่ยมบ้าน ' . $label);
            flash('success', $hv ? 'บันทึกการแก้ไขแล้ว สถานะเป็น "รอตรวจ"' : 'ส่งรายงานการเยี่ยมบ้าน ' . $label . ' เรียบร้อยแล้ว');
            redirect($self . '?id=' . $visitId);
        }
        if ($hv) {
            $_GET['edit'] = (string)$hv['id'];
        } else {
            $_GET['new'] = '1';
            $_GET['year'] = (string)$year;
        }
    }

    if ($action === 'delete_image') {
        $img = db_one('SELECT * FROM home_visit_images WHERE id = ?', 'i', [(int)post('image_id')]);
        $hv = $img ? own_home_visit((int)$img['visit_id'], $uid) : null;
        if (!$hv || !in_array($hv['status'], ['pending', 'revision'], true)) {
            abort_page(403, 'ไม่มีสิทธิ์ลบภาพนี้');
        }
        if (count(home_visit_images((int)$hv['id'])) <= 1) {
            flash('error', 'รายงานต้องมีภาพอย่างน้อย 1 ภาพ — เพิ่มภาพใหม่ก่อนแล้วจึงลบภาพเดิม');
        } else {
            db_exec('DELETE FROM home_visit_images WHERE id = ?', 'i', [(int)$img['id']]);
            delete_upload('home_visit', $img['stored_name']);
            flash('success', 'ลบภาพแล้ว');
        }
        redirect($self . '?edit=' . (int)$hv['id']);
    }

    if ($action === 'delete') {
        $hv = own_home_visit((int)post('id'), $uid);
        if (!$hv) {
            abort_page(404, 'ไม่พบรายงาน');
        }
        if ($hv['status'] !== 'pending' || $hv['reviewed_at']) {
            flash('error', 'ไม่สามารถลบได้ เพราะรายงานถูกตรวจแล้ว');
            redirect($self . '?id=' . (int)$hv['id']);
        }
        home_visit_delete($hv);
        log_activity('home_visit_delete', 'ลบรายงานเยี่ยมบ้าน ' . $hv['student_name'] . ' (' . $hv['student_code'] . ')');
        flash('success', 'ลบรายงานแล้ว');
        redirect($self . '?year=' . (int)$hv['school_year']);
    }
}

$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

// =====================================================================
// ดูรายงาน (?id=)
// =====================================================================
$viewId = get_int('id');
if ($viewId > 0) {
    $hv = own_home_visit($viewId, $uid);
    if (!$hv) {
        abort_page(404, 'ไม่พบรายงาน', 'รายงานนี้ไม่มีอยู่ หรือไม่ใช่รายงานของท่าน');
    }
    $pageTitle = 'รายงานการเยี่ยมบ้าน';
    $pageIcon = 'bi-house-heart';
    $breadcrumbs = ['รายงานการเยี่ยมบ้าน' => $self . '?year=' . (int)$hv['school_year'], $hv['student_name'] => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($hv['status'] === 'revision'): ?>
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> ผู้ตรวจให้แก้ไข</div>
            <?= nl2br(e((string)$hv['review_comment'])) ?: 'กรุณาแก้ไขและส่งใหม่' ?>
        </div>
    <?php endif; ?>
    <div class="row g-4">
        <div class="col-xl-8"><?php require APP_ROOT . '/includes/partials/home_visit_detail.php'; ?></div>
        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-body d-grid gap-2">
                    <?php if (in_array($hv['status'], ['pending', 'revision'], true)): ?>
                        <a href="<?= e(url($self . '?edit=' . (int)$hv['id'])) ?>" class="btn btn-accent"><i class="bi bi-pencil-square me-1"></i>
                            <?= $hv['status'] === 'revision' ? 'แก้ไขและส่งใหม่' : 'แก้ไขรายงาน' ?></a>
                    <?php else: ?>
                        <div class="text-success small"><i class="bi bi-check-circle me-1"></i>รายงานผ่านการตรวจแล้ว</div>
                    <?php endif; ?>
                    <?php if ($hv['status'] === 'pending' && !$hv['reviewed_at']): ?>
                        <form method="post" data-confirm="ลบรายงานนี้? (ไม่สามารถกู้คืนได้)" data-confirm-icon="warning">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$hv['id'] ?>">
                            <button class="btn btn-outline-danger w-100"><i class="bi bi-trash me-1"></i> ลบรายงาน</button>
                        </form>
                    <?php endif; ?>
                    <a href="<?= e(url($self . '?year=' . (int)$hv['school_year'])) ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i> กลับรายการ</a>
                </div>
            </div>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// ฟอร์มบันทึกใหม่ (?new=1) / แก้ไข (?edit=)
// =====================================================================
$editId = get_int('edit');
if ($editId > 0 || get('new') === '1') {
    $hv = null;
    if ($editId > 0) {
        $hv = own_home_visit($editId, $uid);
        if (!$hv) {
            abort_page(404, 'ไม่พบรายงาน');
        }
        if (!in_array($hv['status'], ['pending', 'revision'], true)) {
            flash('error', 'รายงานนี้ผ่านการตรวจแล้ว ไม่สามารถแก้ไขได้');
            redirect($self . '?id=' . (int)$hv['id']);
        }
    }
    $year = $hv ? (int)$hv['school_year'] : home_visit_year(get_int('year'));
    $groups = home_visit_teacher_groups($uid, $year);
    if (!$groups) {
        flash('error', 'ท่านยังไม่มีกลุ่มที่ปรึกษาในปีการศึกษา ' . $year);
        redirect($self . '?year=' . $year);
    }
    $range = home_visit_year_range($year);
    $images = $hv ? home_visit_images((int)$hv['id']) : [];
    $v = function (string $k, string $default = '') use ($hv): string {
        return is_post() ? post($k) : (string)($hv[$k] ?? $default);
    };

    $pageTitle = $hv ? 'แก้ไขรายงานการเยี่ยมบ้าน' : 'บันทึกการเยี่ยมบ้าน';
    $pageIcon = 'bi-house-heart';
    $breadcrumbs = ['รายงานการเยี่ยมบ้าน' => $self . '?year=' . $year, $pageTitle => null];
    require APP_ROOT . '/includes/header.php';
    ?>
    <?php if ($hv && $hv['status'] === 'revision'): ?>
        <div class="alert alert-danger">
            <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon me-1"></i> สิ่งที่ผู้ตรวจให้แก้ไข</div>
            <?= nl2br(e((string)$hv['review_comment'])) ?: '-' ?>
        </div>
    <?php endif; ?>
    <div class="alert alert-light border small"><i class="bi bi-shield-lock me-1 text-accent"></i>
        ข้อมูลครอบครัว ที่อยู่ และพิกัดบ้านเป็นข้อมูลส่วนบุคคลของนักเรียน เห็นได้เฉพาะครูที่ปรึกษา ผู้ตรวจ และผู้บริหารตามสิทธิ์ — โปรดกรอกเท่าที่จำเป็นต่อการดูแลช่วยเหลือ</div>

    <form method="post" enctype="multipart/form-data" novalidate data-confirm="<?= $hv ? 'ยืนยันบันทึกการแก้ไข?' : 'ยืนยันส่งรายงานการเยี่ยมบ้าน?' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($hv['id'] ?? 0) ?>">
        <input type="hidden" name="year" value="<?= $year ?>">

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-person me-2"></i>ข้อมูลนักเรียน (ปีการศึกษา <?= $year ?>)</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="group_id">กลุ่มเรียน *</label>
                    <select class="form-select<?= $invalid('group_id') ?>" name="group_id" id="group_id">
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int)$g['id'] ?>" <?= $v('group_id') === (string)$g['id'] ? 'selected' : '' ?>>
                                <?= e($g['group_name']) ?> (ภาคเรียน <?= (int)$g['semester'] ?>)</option>
                        <?php endforeach; ?>
                    </select><?= $err('group_id') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="student_code">รหัสนักเรียน *</label>
                    <input class="form-control<?= $invalid('student_code') ?>" name="student_code" id="student_code" maxlength="20" value="<?= e($v('student_code')) ?>"><?= $err('student_code') ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="student_name">ชื่อ-สกุลนักเรียน *</label>
                    <input class="form-control<?= $invalid('student_name') ?>" name="student_name" id="student_name" maxlength="150" value="<?= e($v('student_name')) ?>"><?= $err('student_name') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="visit_date">วันที่เยี่ยมบ้าน *</label>
                    <input type="date" class="form-control<?= $invalid('visit_date') ?>" name="visit_date" id="visit_date" value="<?= e($v('visit_date', date('Y-m-d'))) ?>"
                           max="<?= date('Y-m-d') ?>" <?= $range ? 'min="' . e($range[0]) . '"' : '' ?>><?= $err('visit_date') ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="informant_name">ผู้ให้ข้อมูล *</label>
                    <input class="form-control<?= $invalid('informant_name') ?>" name="informant_name" id="informant_name" maxlength="150" value="<?= e($v('informant_name')) ?>"><?= $err('informant_name') ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="informant_relation">เกี่ยวข้องเป็น</label>
                    <input class="form-control" name="informant_relation" id="informant_relation" maxlength="50" value="<?= e($v('informant_relation')) ?>"
                           list="relationList" placeholder="เช่น มารดา">
                    <datalist id="relationList"><?php foreach (['บิดา', 'มารดา', 'ปู่/ย่า', 'ตา/ยาย', 'ลุง/ป้า/น้า/อา', 'พี่', 'ผู้ปกครอง'] as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?></datalist>
                </div>
                <div class="col-12">
                    <label class="form-label" for="address">ที่อยู่ *</label>
                    <input class="form-control<?= $invalid('address') ?>" name="address" id="address" maxlength="500" value="<?= e($v('address')) ?>"
                           placeholder="บ้านเลขที่ หมู่ ตำบล อำเภอ จังหวัด"><?= $err('address') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="latitude">พิกัดบ้าน (ไม่บังคับ)</label>
                    <div class="input-group">
                        <input class="form-control<?= $invalid('latitude') ?>" name="latitude" id="latitude" inputmode="decimal" placeholder="ละติจูด" value="<?= e($v('latitude')) ?>">
                        <input class="form-control<?= $invalid('latitude') ?>" name="longitude" id="longitude" inputmode="decimal" placeholder="ลองจิจูด" value="<?= e($v('longitude')) ?>">
                        <button type="button" class="btn btn-outline-primary" id="btnGeo"><i class="bi bi-geo-alt"></i> ตำแหน่งปัจจุบัน</button>
                    </div>
                    <div class="form-text" id="geoMsg">กดปุ่มขณะอยู่ที่บ้านนักเรียน (ใช้ GPS ของโทรศัพท์)</div>
                    <?= $err('latitude') ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-house me-2"></i>ผลการเยี่ยมบ้าน</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="living_with">นักเรียนอาศัยอยู่กับ *</label>
                    <select class="form-select<?= $invalid('living_with') ?>" name="living_with" id="living_with">
                        <option value="">- เลือก -</option>
                        <?php foreach (home_visit_living_options() as $o): ?>
                            <option value="<?= e($o) ?>" <?= $v('living_with') === $o ? 'selected' : '' ?>><?= e($o) ?></option>
                        <?php endforeach; ?>
                    </select><?= $err('living_with') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="economic_status">ฐานะทางเศรษฐกิจ *</label>
                    <select class="form-select<?= $invalid('economic_status') ?>" name="economic_status" id="economic_status">
                        <?php foreach (home_visit_economic_labels() as $k => $label): ?>
                            <option value="<?= $k ?>" <?= $v('economic_status', 'sufficient') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select><?= $err('economic_status') ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">ผลการคัดกรอง *</label>
                    <div>
                        <?php foreach (home_visit_risk_labels() as $k => $label): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="risk_level" id="risk_<?= $k ?>" value="<?= $k ?>" <?= $v('risk_level', 'normal') === $k ? 'checked' : '' ?>>
                                <label class="form-check-label" for="risk_<?= $k ?>"><?= e($label) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div><?= $err('risk_level') ?>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="family_info">สภาพครอบครัวและที่อยู่อาศัย *</label>
                    <textarea class="form-control<?= $invalid('family_info') ?>" name="family_info" id="family_info" rows="3" maxlength="5000"
                              placeholder="เช่น สมาชิกในครอบครัว อาชีพผู้ปกครอง ลักษณะบ้าน สภาพแวดล้อม การเดินทางมาเรียน"><?= e($v('family_info')) ?></textarea><?= $err('family_info') ?>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="learning_behavior">ด้านการเรียนและพฤติกรรม *</label>
                    <textarea class="form-control<?= $invalid('learning_behavior') ?>" name="learning_behavior" id="learning_behavior" rows="3" maxlength="5000"
                              placeholder="เช่น การเรียน การทำการบ้าน พฤติกรรมที่บ้าน ความสัมพันธ์ในครอบครัว"><?= e($v('learning_behavior')) ?></textarea><?= $err('learning_behavior') ?>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="assistance">การช่วยเหลือ / ข้อเสนอแนะ</label>
                    <textarea class="form-control<?= $invalid('assistance') ?>" name="assistance" id="assistance" rows="3" maxlength="5000"
                              placeholder="จำเป็นเมื่อเป็นกลุ่มเสี่ยง/มีปัญหา เช่น ให้คำปรึกษา ประสานทุน ส่งต่องานแนะแนว"><?= e($v('assistance')) ?></textarea><?= $err('assistance') ?>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><i class="bi bi-images me-2"></i>ภาพถ่ายการเยี่ยมบ้าน * (1–<?= HOME_VISIT_MAX_IMAGES ?> ภาพ, ภาพละไม่เกิน 5MB)</div>
            <div class="card-body">
                <?php if ($images): ?>
                    <div class="row g-2 mb-3">
                        <?php foreach ($images as $im): ?>
                            <div class="col-6 col-md-3 text-center">
                                <img src="<?= e(url('home_visit_image.php?id=' . (int)$im['id'])) ?>" class="img-fluid rounded border mb-1" style="aspect-ratio:4/3;object-fit:cover;width:100%" alt="">
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
            <a href="<?= e(url($hv ? $self . '?id=' . (int)$hv['id'] : $self . '?year=' . $year)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
            <button type="submit" class="btn btn-accent px-4" data-loading-text="กำลังบันทึก..."><i class="bi bi-send me-1"></i> <?= $hv ? 'บันทึกและส่งตรวจ' : 'ส่งรายงาน' ?></button>
        </div>
    </form>
    <?php foreach ($images as $im): ?>
        <form method="post" id="delImg<?= (int)$im['id'] ?>" data-confirm="ลบภาพนี้?" data-confirm-icon="warning" class="d-none">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_image"><input type="hidden" name="image_id" value="<?= (int)$im['id'] ?>">
        </form>
    <?php endforeach; ?>
    <?php
    // ปุ่มดึงพิกัดปัจจุบันจาก GPS (ต้องใช้ HTTPS หรือ localhost)
    $pageScripts = '(function () {
        var btn = document.getElementById("btnGeo"), msg = document.getElementById("geoMsg");
        btn.addEventListener("click", function () {
            if (!navigator.geolocation) { msg.textContent = "อุปกรณ์นี้ไม่รองรับการระบุตำแหน่ง"; return; }
            msg.textContent = "กำลังระบุตำแหน่ง...";
            navigator.geolocation.getCurrentPosition(function (p) {
                document.getElementById("latitude").value = p.coords.latitude.toFixed(7);
                document.getElementById("longitude").value = p.coords.longitude.toFixed(7);
                msg.textContent = "ได้ตำแหน่งแล้ว (คลาดเคลื่อนประมาณ " + Math.round(p.coords.accuracy) + " เมตร)";
            }, function (e) { msg.textContent = "ระบุตำแหน่งไม่ได้: " + e.message + " (ต้องเปิด GPS และอนุญาตตำแหน่ง)"; },
            { enableHighAccuracy: true, timeout: 15000 });
        });
    })();';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

// =====================================================================
// หน้ารายการ
// =====================================================================
$years = home_visit_years();
$year = home_visit_year(get_int('year'));
$groups = home_visit_teacher_groups($uid, $year);
$sum = home_visit_summary($year, 0, $uid);
$mine = $sum['teachers'][$uid] ?? null;
$rows = db_all(
    'SELECT v.id, v.student_code, v.student_name, v.visit_date, v.risk_level, v.status, g.group_name
     FROM home_visits v JOIN advisor_groups g ON g.id = v.group_id
     WHERE v.teacher_id = ? AND v.school_year = ? ORDER BY v.visit_date DESC, v.id DESC',
    'ii',
    [$uid, $year]
);

$pageTitle = 'รายงานการเยี่ยมบ้านนักเรียน';
$pageIcon = 'bi-house-heart';
$breadcrumbs = ['รายงานการเยี่ยมบ้าน' => null];
$pageActions = $groups ? '<a href="' . e(url($self . '?new=1&year=' . $year)) . '" class="btn btn-accent"><i class="bi bi-plus-circle me-1"></i> บันทึกการเยี่ยมบ้าน</a>'
    . ' <a href="' . e(url('reports/home_visit_teacher.php?teacher=' . $uid . '&year=' . $year)) . '" class="btn btn-outline-primary"><i class="bi bi-bar-chart-line me-1"></i> สรุปของฉัน</a>' : '';
require APP_ROOT . '/includes/header.php';
?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
    ครูที่ปรึกษาบันทึกการเยี่ยมบ้านนักเรียนในที่ปรึกษา <strong>นักเรียน 1 คน บันทึกได้ 1 ครั้งต่อปีการศึกษา</strong> พร้อมภาพถ่ายอย่างน้อย 1 ภาพ</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1" for="year">ปีการศึกษา</label>
                <select name="year" id="year" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-9 small text-muted"><?= $groups ? 'กลุ่มที่ปรึกษา: ' . e(implode(', ', array_unique(array_column($groups, 'group_name')))) : '' ?></div>
        </form>
    </div>
</div>

<?php if (!$groups): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
        ท่านยังไม่ได้รับมอบหมายเป็นครูที่ปรึกษาในปีการศึกษา <?= $year ?> — กลุ่มที่ปรึกษากำหนดโดยงานครูที่ปรึกษาและแนะแนว</div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <?php foreach ([[(int)$mine['visits'] . '/' . (int)$mine['students'], 'เยี่ยมบ้านแล้ว (คน)', 'ic-blue', 'bi-house-check'],
                        [$mine['visit_pct'] . '%', 'ร้อยละของนักเรียนในที่ปรึกษา', 'ic-navy', 'bi-percent'],
                        [(int)$mine['risk'], 'กลุ่มเสี่ยง', 'ic-yellow', 'bi-exclamation-triangle'],
                        [(int)$mine['problem'], 'กลุ่มมีปัญหา', 'ic-red', 'bi-exclamation-octagon'],
                        [(int)$mine['revision'], 'ให้แก้ไข', 'ic-orange', 'bi-pencil-square']] as [$val, $label, $ic, $icon]): ?>
            <div class="col-6 col-md">
                <div class="stat-card">
                    <div class="stat-icon <?= $ic ?>" style="width:40px;height:40px;font-size:1.2rem"><i class="bi <?= $icon ?>"></i></div>
                    <div><div class="stat-value" style="font-size:1.4rem"><?= e((string)$val) ?></div><div class="stat-label"><?= e($label) ?></div></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-list-ul me-2"></i>รายการเยี่ยมบ้าน ปีการศึกษา <?= $year ?></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover datatable" data-order="[]">
                    <thead><tr><th>วันที่เยี่ยม</th><th>รหัส</th><th>ชื่อ-สกุลนักเรียน</th><th>กลุ่ม</th><th>ผลคัดกรอง</th><th>สถานะ</th><th class="no-sort"></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td data-order="<?= e($r['visit_date']) ?>"><?= e(thai_date($r['visit_date'])) ?></td>
                            <td><?= e($r['student_code']) ?></td>
                            <td><?= e($r['student_name']) ?></td>
                            <td class="small"><?= e($r['group_name']) ?></td>
                            <td><?= home_visit_risk_badge($r['risk_level']) ?></td>
                            <td data-order="<?= e($r['status']) ?>"><?= status_badge($r['status']) ?></td>
                            <td class="text-end"><a href="<?= e(url($self . '?id=' . (int)$r['id'])) ?>" class="btn btn-sm btn-outline-primary">
                                <?= $r['status'] === 'revision' ? 'แก้ไข' : 'ดู' ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
