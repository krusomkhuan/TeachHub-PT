<?php
/**
 * ไฟล์: includes/hr.php
 * ฟังก์ชันกลางของ "ระบบงานบุคลากร" (v1.19)
 * - ทะเบียนประวัติ / การลา / การพัฒนาตนเอง-อบรม / คำสั่ง / การเข้าร่วมกิจกรรมวันสำคัญ / รายงานสรุป
 * - บุคลากร = ผู้ใช้ที่สถานะ "ใช้งาน" ทุก role · ทุกคนบันทึกข้อมูลของตนเอง แล้วงานบุคลากรตรวจสอบ
 * - ผู้ดูแลงานบุคลากร: ผู้ดูแลระบบ/เจ้าหน้าที่ดูแลระบบที่ได้รับสิทธิ์ "hr" และครูที่ตำแหน่ง หัวหน้างานบุคลากร / เจ้าหน้าที่งานบุคลากร
 * - ใบลา: ผู้ลา → หัวหน้าแผนก (ความเห็น) → งานบุคลากร (ตรวจสอบ) → รองผู้อำนวยการ (ความเห็น) → ผู้อำนวยการ (อนุญาต/ไม่อนุญาต)
 *   ขั้นที่ไม่มีผู้รับผิดชอบ (เช่น ผู้ลาไม่มีแผนก/เป็นหัวหน้าแผนกเอง) ข้ามไปขั้นถัดไปอัตโนมัติ
 * - ข้อมูลส่วนบุคคล (เลขประจำตัวประชาชน วันเกิด ที่อยู่ ผู้ติดต่อฉุกเฉิน) เห็นเฉพาะเจ้าของและผู้ดูแลงานบุคลากร (PDPA)
 * - ไฟล์แนบทั้งหมดเก็บที่ uploads/hr บนโฮสต์ และเปิดผ่าน hr/file.php ที่ตรวจสิทธิ์ทุกครั้ง
 */

const HR_UPLOAD_DIR = 'hr';
const HR_MAX_FILE_BYTES = 10 * 1024 * 1024;

// =====================================================================
// เปิด-ปิดระบบงานบุคลากร (ตั้งค่าระบบ → hr_enabled, ค่าเริ่มต้น = ปิด ระหว่างพัฒนา)
// =====================================================================

/** ระบบงานบุคลากรเปิดให้ทุกคนใช้งานแล้วหรือไม่ */
function hr_enabled(): bool
{
    return setting('hr_enabled', '0') === '1';
}

/**
 * ผู้ใช้คนนี้ใช้ระบบงานบุคลากรได้หรือไม่
 * ปิดอยู่: ใช้ได้เฉพาะผู้ดูแลระบบเต็มสิทธิ์ (ไว้ทดสอบระหว่างพัฒนา) · เปิดแล้ว: ทุกคนตามสิทธิ์ปกติ
 */
function hr_available(array $user): bool
{
    return hr_enabled() || is_full_admin($user);
}

/** หน้าในโฟลเดอร์ hr/ — ปฏิเสธเมื่อระบบปิดอยู่ (เรียกจาก require_login) */
function hr_enforce_enabled(array $user): void
{
    $path = ltrim(substr(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), strlen(BASE_URL)), '/');
    if (str_starts_with($path, 'hr/') && !hr_available($user)) {
        abort_page(404, 'ระบบงานบุคลากรยังไม่เปิดใช้งาน', 'ระบบนี้อยู่ระหว่างพัฒนา จะเปิดให้ใช้งานเร็ว ๆ นี้');
    }
}

// =====================================================================
// สิทธิ์
// =====================================================================

/** ตำแหน่งผู้ดูแลงานบุคลากร (กำหนดที่ช่อง "ตำแหน่ง" ของผู้ใช้ — แก้ได้เฉพาะผู้ดูแลระบบ) */
function hr_staff_positions(): array
{
    return ['หัวหน้างานบุคลากร', 'เจ้าหน้าที่งานบุคลากร'];
}

/** ตำแหน่งนี้เป็นตำแหน่งงานบุคลากรหรือไม่ */
function is_hr_staff_position(string $position): bool
{
    return in_array(normalize_position($position), hr_staff_positions(), true);
}

/** ครู/หัวหน้าแผนกที่เป็นหัวหน้างาน/เจ้าหน้าที่งานบุคลากรตามตำแหน่ง */
function is_hr_staff(array $user): bool
{
    if (($user['status'] ?? 'active') !== 'active' || ($user['role'] ?? '') === 'executive') {
        return false;
    }
    return is_hr_staff_position((string)($user['position'] ?? ''));
}

/** จัดการงานบุคลากรได้ (ดูทะเบียนประวัติทุกคน ตรวจสอบใบลา/การอบรม จัดการคำสั่งและกิจกรรม) */
function can_manage_hr(array $user): bool
{
    return admin_can($user, 'hr') || is_hr_staff($user);
}

/** ดูรายชื่อ/รายงานสรุปบุคลากรได้ (ผู้ดูแลงานบุคลากร + ผู้บริหาร) */
function can_view_hr_overview(array $user): bool
{
    return can_manage_hr($user) || ($user['role'] ?? '') === 'executive';
}

/** รองผู้อำนวยการ */
function is_hr_deputy(array $user): bool
{
    return ($user['role'] ?? '') === 'executive' && mb_strpos(normalize_position((string)$user['position']), 'รองผู้อำนวยการ') === 0;
}

/** ผู้อำนวยการ (ผู้บริหารที่ไม่ใช่รองผู้อำนวยการ) */
function is_hr_director(array $user): bool
{
    return ($user['role'] ?? '') === 'executive' && !is_hr_deputy($user);
}

/** ดูทะเบียนประวัติของบุคลากรคนนี้ได้หรือไม่ (ผู้บริหารเห็นเฉพาะข้อมูลการปฏิบัติงาน) */
function hr_can_view_profile(array $viewer, int $uid): bool
{
    return (int)$viewer['id'] === $uid || can_view_hr_overview($viewer);
}

/** เห็นข้อมูลส่วนบุคคล (เลขประจำตัวประชาชน วันเกิด ที่อยู่ ผู้ติดต่อฉุกเฉิน) — เจ้าของ + ผู้ดูแลงานบุคลากร */
function hr_can_view_personal(array $viewer, int $uid): bool
{
    return (int)$viewer['id'] === $uid || can_manage_hr($viewer);
}

/** แก้ไขทะเบียนประวัติได้ (เจ้าของ + ผู้ดูแลงานบุคลากร) */
function hr_can_edit_profile(array $viewer, int $uid): bool
{
    return hr_can_view_personal($viewer, $uid);
}

// =====================================================================
// ตัวเลือก / ป้ายกำกับ
// =====================================================================

function hr_staff_types(): array
{
    return ['ข้าราชการครู', 'ข้าราชการพลเรือน', 'พนักงานราชการ (ครู)', 'พนักงานราชการ (สนับสนุน)', 'ลูกจ้างประจำ',
        'ครูอัตราจ้าง', 'เจ้าหน้าที่อัตราจ้าง', 'ครูพิเศษสอน', 'อื่น ๆ'];
}

function hr_education_levels(): array
{
    return ['ปริญญาเอก', 'ปริญญาโท', 'ประกาศนียบัตรบัณฑิต', 'ปริญญาตรี', 'อนุปริญญา/ปวส.', 'ปวช.', 'มัธยมศึกษาตอนปลาย', 'ต่ำกว่ามัธยมศึกษาตอนปลาย'];
}

function hr_training_categories(): array
{
    return ['อบรม', 'สัมมนา', 'ประชุมวิชาการ', 'ศึกษาดูงาน', 'อบรมออนไลน์', 'วิทยากร', 'อื่น ๆ'];
}

function hr_order_categories(): array
{
    return ['แต่งตั้งคณะกรรมการ', 'มอบหมายงาน/หน้าที่', 'ไปราชการ', 'ย้าย/บรรจุ/แต่งตั้ง', 'เลื่อนเงินเดือน/ค่าตอบแทน', 'อื่น ๆ'];
}

/**
 * ประเภทการลา: code => [ชื่อ, วิธีนับวัน (work = วันทำการ จันทร์–ศุกร์ / calendar = นับวันต่อเนื่อง), ไอคอน]
 */
function hr_leave_types(): array
{
    return [
        'sick'       => ['ลาป่วย', 'work', 'bi-bandaid'],
        'personal'   => ['ลากิจส่วนตัว', 'work', 'bi-briefcase'],
        'vacation'   => ['ลาพักผ่อน', 'work', 'bi-sun'],
        'maternity'  => ['ลาคลอดบุตร', 'calendar', 'bi-heart'],
        'paternity'  => ['ลาไปช่วยเหลือภริยาที่คลอดบุตร', 'work', 'bi-people'],
        'ordination' => ['ลาอุปสมบท/ประกอบพิธีฮัจย์', 'calendar', 'bi-flower1'],
        'other'      => ['ลาอื่น ๆ', 'work', 'bi-three-dots'],
    ];
}

function hr_leave_type_label(string $type): string
{
    return hr_leave_types()[$type][0] ?? $type;
}

function hr_leave_status_labels(): array
{
    return [
        'pending_head'     => 'รอหัวหน้าแผนก',
        'pending_hr'       => 'รองานบุคลากรตรวจสอบ',
        'pending_deputy'   => 'รอรองผู้อำนวยการ',
        'pending_director' => 'รอผู้อำนวยการ',
        'approved'         => 'อนุญาต',
        'rejected'         => 'ไม่อนุญาต',
        'cancelled'        => 'ยกเลิก',
    ];
}

function hr_leave_status_badge(string $status): string
{
    $map = ['approved' => 'bg-success', 'rejected' => 'bg-danger', 'cancelled' => 'bg-secondary'];
    $cls = $map[$status] ?? 'bg-warning text-dark';
    return '<span class="badge ' . $cls . '">' . e(hr_leave_status_labels()[$status] ?? $status) . '</span>';
}

/** สถานะที่ยังอยู่ระหว่างพิจารณา */
function hr_leave_pending_statuses(): array
{
    return ['pending_head', 'pending_hr', 'pending_deputy', 'pending_director'];
}

function hr_leave_step_labels(): array
{
    return [
        'submit' => 'ยื่นใบลา', 'head' => 'หัวหน้าแผนก', 'hr' => 'งานบุคลากร', 'deputy' => 'รองผู้อำนวยการ',
        'director' => 'ผู้อำนวยการ', 'cancel' => 'ยกเลิก', 'record' => 'งานบุคลากรบันทึกแทน',
    ];
}

function hr_attend_labels(): array
{
    return ['attended' => 'เข้าร่วม', 'leave' => 'ลา/ไปราชการ', 'absent' => 'ไม่เข้าร่วม'];
}

function hr_attend_badge(?string $status): string
{
    if ($status === null || $status === '') {
        return '<span class="badge bg-light text-muted border">ยังไม่รายงาน</span>';
    }
    $map = ['attended' => 'bg-success', 'leave' => 'bg-info text-dark', 'absent' => 'bg-danger'];
    return '<span class="badge ' . ($map[$status] ?? 'bg-secondary') . '">' . e(hr_attend_labels()[$status] ?? $status) . '</span>';
}

/** เลขประจำตัวประชาชนแบบปิดบางส่วน */
function hr_mask_citizen(?string $id): string
{
    $id = (string)$id;
    return strlen($id) === 13 ? substr($id, 0, 1) . '-xxxx-xxxxx-' . substr($id, 10, 2) . '-' . substr($id, 12) : '-';
}

/** ตรวจเลขประจำตัวประชาชน 13 หลัก (checksum) */
function hr_valid_citizen(string $id): bool
{
    if (!preg_match('/^\d{13}$/', $id)) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$id[$i] * (13 - $i);
    }
    return (11 - $sum % 11) % 10 === (int)$id[12];
}

/** ตรวจรูปแบบวันที่ Y-m-d */
function hr_valid_date(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
}

// =====================================================================
// ปีงบประมาณ
// =====================================================================

/** ปีงบประมาณ (พ.ศ.) ของวันที่ — เริ่ม 1 ต.ค. */
function hr_fiscal_year_of(string $date): int
{
    $ts = strtotime($date);
    return (int)date('Y', $ts) + 543 + ((int)date('n', $ts) >= 10 ? 1 : 0);
}

/** ช่วงวันที่ของปีงบประมาณ [Y-m-d, Y-m-d] */
function hr_fiscal_range(int $fy): array
{
    $ce = $fy - 543;
    return [($ce - 1) . '-10-01', $ce . '-09-30'];
}

/** ปีงบประมาณจาก ?fy= (ไม่ระบุ/ผิดรูปแบบ = ปีปัจจุบัน) */
function hr_fiscal_param(): int
{
    $fy = get_int('fy');
    return ($fy >= 2550 && $fy <= 2700) ? $fy : current_fiscal_year();
}

/** ปีงบประมาณที่ให้เลือก (ปีถัดไป ถึง 5 ปีก่อน) */
function hr_fiscal_years(): array
{
    $cur = current_fiscal_year();
    return range($cur + 1, $cur - 5);
}

// =====================================================================
// บุคลากร / ทะเบียนประวัติ
// =====================================================================

/** รายชื่อบุคลากรที่ใช้งานอยู่ทั้งหมด (+ ข้อมูลทะเบียนประวัติ) */
function hr_people(int $deptId = 0): array
{
    $sql = "SELECT u.id, u.prefix, u.first_name, u.last_name, u.position, u.academic_standing, u.role, u.email, u.phone,
                   u.department_id, d.name AS department_name,
                   CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS full_name,
                   p.staff_type, p.position_no, p.service_start_date, p.college_start_date, p.verified_at, p.updated_at AS profile_updated_at,
                   (p.user_id IS NOT NULL) AS has_profile
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN hr_profiles p ON p.user_id = u.id
            WHERE u.status = 'active'" . ($deptId > 0 ? ' AND u.department_id = ?' : '') . '
            ORDER BY u.first_name, u.last_name';
    return $deptId > 0 ? db_all($sql, 'i', [$deptId]) : db_all($sql);
}

/** ผู้ใช้ที่ใช้งานอยู่ตาม id (+ ชื่อแผนก) */
function hr_person(int $uid): ?array
{
    return db_one(
        "SELECT u.*, d.name AS department_name, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS full_name
         FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.status = 'active'",
        'i',
        [$uid]
    );
}

/** ทะเบียนประวัติ (null = ยังไม่กรอก) */
function hr_profile(int $uid): ?array
{
    return db_one('SELECT * FROM hr_profiles WHERE user_id = ?', 'i', [$uid]);
}

/** วุฒิสูงสุดของแต่ละคน [user_id => ระดับ] (เรียงตาม hr_education_levels) */
function hr_highest_educations(): array
{
    $rank = array_flip(hr_education_levels());
    $out = [];
    foreach (db_all('SELECT user_id, level FROM hr_educations') as $r) {
        $u = (int)$r['user_id'];
        $lv = $r['level'];
        if (!isset($out[$u]) || ($rank[$lv] ?? 99) < ($rank[$out[$u]] ?? 99)) {
            $out[$u] = $lv;
        }
    }
    return $out;
}

/** อายุราชการ/อายุงานเป็นข้อความ เช่น "12 ปี 3 เดือน" */
function hr_service_length(?string $from): string
{
    if (!$from) {
        return '-';
    }
    $diff = (new DateTime($from))->diff(new DateTime('today'));
    return $diff->invert ? '-' : $diff->y . ' ปี ' . $diff->m . ' เดือน';
}

// =====================================================================
// ไฟล์แนบ (uploads/hr)
// =====================================================================

/** นามสกุลไฟล์แนบ: เอกสาร = pdf + รูป, รูป = รูปอย่างเดียว */
function hr_file_extensions(bool $imageOnly = false): array
{
    return $imageOnly ? ['jpg', 'jpeg', 'png', 'webp'] : ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
}

/**
 * รับไฟล์แนบจากฟอร์ม (ไม่ได้เลือกไฟล์ = ok + stored_name null)
 * @return array ['ok', 'error', 'stored_name', 'original_name']
 */
function hr_take_upload(string $field, bool $imageOnly = false): array
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'error' => '', 'stored_name' => null, 'original_name' => null];
    }
    return handle_upload($f, HR_UPLOAD_DIR, hr_file_extensions($imageOnly), min(HR_MAX_FILE_BYTES, max_upload_bytes()));
}

/** ลิงก์เปิดไฟล์แนบผ่านสคริปต์ตรวจสิทธิ์ */
function hr_file_url(string $kind, int $id, int $uid = 0): string
{
    return url('hr/file.php?k=' . $kind . '&id=' . $id . ($uid > 0 ? '&u=' . $uid : ''));
}

// =====================================================================
// การลา
// =====================================================================

/** นับจำนวนวันลา (ลาครึ่งวันได้เฉพาะลา 1 วัน) */
function hr_count_leave_days(string $type, string $start, string $end, string $half = ''): float
{
    if ($start === $end && in_array($half, ['morning', 'afternoon'], true)) {
        return 0.5;
    }
    $mode = hr_leave_types()[$type][1] ?? 'work';
    $days = 0;
    for ($ts = strtotime($start), $last = strtotime($end); $ts <= $last; $ts = strtotime('+1 day', $ts)) {
        if ($mode === 'calendar' || (int)date('N', $ts) <= 5) {
            $days++;
        }
    }
    return (float)$days;
}

/** ใบลา + ข้อมูลผู้ลา */
function hr_leave_fetch(int $id): ?array
{
    return db_one(
        "SELECT l.*, u.prefix, u.first_name, u.last_name, u.position, u.academic_standing, u.role AS user_role,
                u.department_id, u.phone, d.name AS department_name,
                CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS user_name
         FROM hr_leaves l
         JOIN users u ON u.id = l.user_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE l.id = ?",
        'i',
        [$id]
    );
}

/** ลำดับการพิจารณา (เก่า → ใหม่) */
function hr_leave_actions(int $leaveId): array
{
    return db_all(
        "SELECT a.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS actor_name, u.position AS actor_position,
                u.academic_standing AS actor_standing
         FROM hr_leave_actions a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.leave_id = ? ORDER BY a.id",
        'i',
        [$leaveId]
    );
}

function hr_leave_log_action(int $leaveId, string $step, string $decision = '', string $comment = '', ?int $uid = null): void
{
    db_insert(
        'INSERT INTO hr_leave_actions (leave_id, user_id, step, decision, comment) VALUES (?, ?, ?, ?, ?)',
        'iisss',
        [$leaveId, $uid ?? (int)($_SESSION['user_id'] ?? 0), $step, $decision, mb_substr($comment, 0, 1000)]
    );
}

/** ผู้ใช้ executive ที่ใช้งานอยู่ */
function hr_executives(): array
{
    return db_all("SELECT id, role, position, status FROM users WHERE role = 'executive' AND status = 'active'");
}

/** id ผู้พิจารณาขั้นรองผู้อำนวยการ (ตำแหน่งตามค่าตั้งค่า ถ้าไม่มีผู้ใช้ตำแหน่งนั้น = รองผู้อำนวยการทุกฝ่าย) */
function hr_deputy_ids(): array
{
    $deputies = array_filter(hr_executives(), 'is_hr_deputy');
    $pos = normalize_position((string)setting('hr_deputy_position', ''));
    $match = array_filter($deputies, fn($u) => $pos !== '' && normalize_position($u['position']) === $pos);
    return array_map('intval', array_column($match ?: $deputies, 'id'));
}

/** id ผู้อนุญาตการลา (ผู้อำนวยการ — ถ้าไม่มีบัญชีผู้อำนวยการ = ผู้บริหารทุกคน) */
function hr_director_ids(): array
{
    $execs = hr_executives();
    $directors = array_filter($execs, 'is_hr_director');
    return array_map('intval', array_column($directors ?: $execs, 'id'));
}

/** id หัวหน้าแผนกของแผนก */
function hr_dept_head_ids(int $deptId): array
{
    if ($deptId <= 0) {
        return [];
    }
    return array_map('intval', array_column(db_all(
        "SELECT id FROM users WHERE role = 'supervisor' AND status = 'active' AND department_id = ?",
        'i',
        [$deptId]
    ), 'id'));
}

/** ผู้รับผิดชอบของขั้นนี้ (ไม่นับตัวผู้ลาเอง) — [] = ไม่มีผู้รับผิดชอบ (ข้ามขั้น) */
function hr_leave_step_actors(string $status, array $leave): array
{
    $owner = (int)$leave['user_id'];
    switch ($status) {
        case 'pending_head':
            $ids = ($leave['user_role'] ?? '') === 'teacher' ? hr_dept_head_ids((int)$leave['department_id']) : [];
            break;
        case 'pending_deputy':
            $ids = ($leave['user_role'] ?? '') === 'executive' ? [] : hr_deputy_ids();
            break;
        case 'pending_director':
            $ids = in_array($owner, hr_director_ids(), true) ? [] : hr_director_ids();
            break;
        default:
            return [];
    }
    return array_values(array_diff($ids, [$owner]));
}

/**
 * สถานะถัดไปของใบลา เริ่มหาจากขั้น $from (ข้ามขั้นที่ไม่มีผู้รับผิดชอบ)
 * ขั้นงานบุคลากรไม่ข้ามเสมอ · ไม่มีผู้อนุญาตเลย (เช่น ผู้อำนวยการลาเอง) = อนุญาตเมื่องานบุคลากรตรวจสอบแล้ว
 */
function hr_leave_next_status(string $from, array $leave): string
{
    $order = ['pending_head', 'pending_hr', 'pending_deputy', 'pending_director'];
    for ($i = array_search($from, $order, true); $i !== false && $i < count($order); $i++) {
        $st = $order[$i];
        if ($st === 'pending_hr' || hr_leave_step_actors($st, $leave)) {
            return $st;
        }
    }
    return 'approved';
}

/** ขั้นของสถานะ (ใช้บันทึกใน hr_leave_actions) */
function hr_leave_status_step(string $status): string
{
    return ['pending_head' => 'head', 'pending_hr' => 'hr', 'pending_deputy' => 'deputy', 'pending_director' => 'director'][$status] ?? '';
}

/** ผู้ใช้พิจารณาใบลานี้ในขั้นปัจจุบันได้หรือไม่ */
function hr_leave_can_act(array $user, array $leave): bool
{
    $status = $leave['status'];
    if (!in_array($status, hr_leave_pending_statuses(), true) || (int)$leave['user_id'] === (int)$user['id']) {
        return false;
    }
    if ($status === 'pending_hr') {
        return can_manage_hr($user);
    }
    return in_array((int)$user['id'], hr_leave_step_actors($status, $leave), true);
}

/** ดูใบลานี้ได้หรือไม่ */
function hr_leave_can_view(array $user, array $leave): bool
{
    $uid = (int)$user['id'];
    if ($uid === (int)$leave['user_id'] || can_view_hr_overview($user)) {
        return true;
    }
    if ($user['role'] === 'supervisor' && (int)$user['department_id'] === (int)$leave['department_id'] && $leave['department_id'] !== null) {
        return true;
    }
    return (bool)db_value('SELECT 1 FROM hr_leave_actions WHERE leave_id = ? AND user_id = ? LIMIT 1', 'ii', [(int)$leave['id'], $uid]);
}

/** ใบลาที่รอผู้ใช้คนนี้พิจารณา */
function hr_leave_pending_for(array $user): array
{
    $rows = db_all(
        "SELECT l.*, u.role AS user_role, u.department_id, d.name AS department_name, u.position,
                CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS user_name
         FROM hr_leaves l JOIN users u ON u.id = l.user_id LEFT JOIN departments d ON d.id = u.department_id
         WHERE l.status IN ('pending_head', 'pending_hr', 'pending_deputy', 'pending_director')
         ORDER BY l.created_at"
    );
    return array_values(array_filter($rows, fn($l) => hr_leave_can_act($user, $l)));
}

/** จำนวนใบลาที่รอผู้ใช้คนนี้พิจารณา (ตัวเลขบนเมนู) */
function hr_leave_pending_count(array $user): int
{
    if (!can_manage_hr($user) && !in_array($user['role'], ['supervisor', 'executive'], true)) {
        return 0;
    }
    return count(hr_leave_pending_for($user));
}

/** จำนวนวันลาที่อนุญาตแล้วในปีงบประมาณ [ประเภท => ['times' => ครั้ง, 'days' => วัน]] */
function hr_leave_used(int $uid, int $fy, int $beforeLeaveId = 0): array
{
    $out = array_fill_keys(array_keys(hr_leave_types()), ['times' => 0, 'days' => 0.0]);
    $rows = db_all(
        "SELECT leave_type, COUNT(*) AS times, SUM(days) AS days FROM hr_leaves
         WHERE user_id = ? AND fiscal_year = ? AND status = 'approved'" . ($beforeLeaveId > 0 ? ' AND id < ?' : '') . '
         GROUP BY leave_type',
        $beforeLeaveId > 0 ? 'iii' : 'ii',
        $beforeLeaveId > 0 ? [$uid, $fy, $beforeLeaveId] : [$uid, $fy]
    );
    foreach ($rows as $r) {
        $out[$r['leave_type']] = ['times' => (int)$r['times'], 'days' => (float)$r['days']];
    }
    return $out;
}

/** ใบลาครั้งก่อน (อนุญาตแล้ว) ประเภทเดียวกัน — ใช้ในแบบใบลา "ลาครั้งสุดท้าย" */
function hr_leave_previous(array $leave): ?array
{
    $types = in_array($leave['leave_type'], ['sick', 'personal', 'maternity'], true) ? ['sick', 'personal', 'maternity'] : [$leave['leave_type']];
    $in = implode(',', array_fill(0, count($types), '?'));
    return db_one(
        "SELECT * FROM hr_leaves WHERE user_id = ? AND status = 'approved' AND id <> ? AND start_date < ? AND leave_type IN ($in)
         ORDER BY start_date DESC LIMIT 1",
        'iis' . str_repeat('s', count($types)),
        array_merge([(int)$leave['user_id'], (int)$leave['id'], $leave['start_date']], $types)
    );
}

/** สิทธิ์ลาพักผ่อนในปีงบประมาณ (รวมวันสะสม) */
function hr_vacation_quota(int $uid, int $fy): float
{
    $v = db_value('SELECT vacation_days FROM hr_leave_quotas WHERE user_id = ? AND fiscal_year = ?', 'ii', [$uid, $fy]);
    return $v !== null ? (float)$v : (float)setting('hr_vacation_days', '10');
}

/** ช่วงวันลาเป็นข้อความ */
function hr_leave_range_text(array $l): string
{
    $half = ['morning' => ' (ครึ่งวันเช้า)', 'afternoon' => ' (ครึ่งวันบ่าย)'][$l['half_day']] ?? '';
    return $l['start_date'] === $l['end_date']
        ? thai_date($l['start_date']) . $half
        : thai_date($l['start_date']) . ' – ' . thai_date($l['end_date']);
}

/** แสดงจำนวนวัน (ตัด .0) */
function hr_days(float $d): string
{
    return rtrim(rtrim(number_format($d, 1), '0'), '.');
}

// =====================================================================
// การอบรม / คำสั่ง / กิจกรรม
// =====================================================================

/** จำนวนรายการอบรมที่รอตรวจ */
function hr_training_pending_count(): int
{
    return (int)db_value("SELECT COUNT(*) FROM hr_trainings WHERE status = 'pending'");
}

/** ผู้ใช้เห็นคำสั่งนี้ได้หรือไม่ */
function hr_order_visible(array $user, array $order): bool
{
    if ((int)$order['is_public'] === 1 || can_view_hr_overview($user)) {
        return true;
    }
    return (bool)db_value('SELECT 1 FROM hr_order_users WHERE order_id = ? AND user_id = ?', 'ii', [(int)$order['id'], (int)$user['id']]);
}

/** กิจกรรมนี้กำหนดผู้เข้าร่วมเฉพาะบางคน (ไม่ใช่บุคลากรทุกคน) */
function hr_event_is_selected(array $ev): bool
{
    return ($ev['participant_mode'] ?? 'all') === 'selected';
}

/** id ผู้ที่ต้องเข้าร่วมกิจกรรม (ทุกคน = บุคลากรที่ใช้งานอยู่ทั้งหมด) */
function hr_event_participant_ids(array $ev): array
{
    if (!hr_event_is_selected($ev)) {
        return array_map('intval', array_column(hr_people(), 'id'));
    }
    return array_map('intval', array_column(db_all(
        "SELECT p.user_id FROM hr_event_participants p JOIN users u ON u.id = p.user_id AND u.status = 'active' WHERE p.event_id = ?",
        'i',
        [(int)$ev['id']]
    ), 'user_id'));
}

/** ผู้ใช้คนนี้ถูกกำหนดให้เข้าร่วมกิจกรรมหรือไม่ */
function hr_event_is_participant(array $ev, int $uid): bool
{
    return !hr_event_is_selected($ev)
        || (bool)db_value('SELECT 1 FROM hr_event_participants WHERE event_id = ? AND user_id = ?', 'ii', [(int)$ev['id'], $uid]);
}

/** เงื่อนไข SQL: กิจกรรม e ที่ผู้ใช้ (คอลัมน์/ค่า $userExpr) ต้องเข้าร่วม */
function hr_event_participant_sql(string $userExpr): string
{
    return "(e.participant_mode <> 'selected' OR EXISTS (SELECT 1 FROM hr_event_participants ep WHERE ep.event_id = e.id AND ep.user_id = $userExpr))";
}

/** กิจกรรมที่ยังรายงานเองได้: ตั้งแต่วันงาน ถึง 7 วันหลังวันงาน */
function hr_event_self_open(array $ev): bool
{
    $today = date('Y-m-d');
    return (int)$ev['self_report'] === 1 && $today >= $ev['event_date'] && $today <= date('Y-m-d', strtotime($ev['event_date'] . ' +7 day'));
}

// =====================================================================
// รายงานสรุป (ใช้ร่วมกัน: หน้า hr/reports.php, ส่งออก CSV, หน้าพิมพ์/PDF hr/report_print.php)
// =====================================================================

/** ชนิดรายงาน => ชื่อรายงาน */
function hr_report_types(): array
{
    return [
        'staff'    => 'รายชื่อบุคลากร',
        'leave'    => 'สรุปวันลา',
        'training' => 'สรุปชั่วโมงการพัฒนาตนเอง/อบรม',
        'events'   => 'สรุปการเข้าร่วมกิจกรรมวันสำคัญ',
    ];
}

/**
 * ข้อมูลรายงานแบบตาราง
 * @return array ['title' => ชื่อ, 'header' => [หัวคอลัมน์], 'rows' => [[ค่า...]], 'num' => [index คอลัมน์ตัวเลข]]
 */
function hr_report_data(string $type, int $fy, int $deptId = 0): array
{
    $people = hr_people($deptId);
    $dept = fn(array $p) => preg_replace('/^แผนกวิชา/u', '', (string)$p['department_name']) ?: '-';
    $title = (hr_report_types()[$type] ?? '') . ($type === 'staff' ? '' : ' ปีงบประมาณ ' . $fy);
    $rows = [];
    $i = 0;

    if ($type === 'staff') {
        $edu = hr_highest_educations();
        foreach ($people as $p) {
            $rows[] = [++$i, $p['full_name'], $p['position'] ?: '-', $p['academic_standing'] ?: '-', $dept($p), $p['staff_type'] ?: '-',
                $p['position_no'] ?: '-', $p['service_start_date'] ? thai_date($p['service_start_date']) : '-',
                hr_service_length($p['service_start_date']), $edu[(int)$p['id']] ?? '-', $p['phone'] ?: '-'];
        }
        return ['title' => $title, 'num' => [0],
            'header' => ['ที่', 'ชื่อ-สกุล', 'ตำแหน่ง', 'วิทยฐานะ', 'แผนก/งาน', 'ประเภทบุคลากร', 'เลขที่ตำแหน่ง', 'วันที่บรรจุ', 'อายุราชการ', 'วุฒิสูงสุด', 'โทรศัพท์'],
            'rows' => $rows];
    }

    if ($type === 'leave') {
        $sum = [];
        foreach (db_all("SELECT user_id, leave_type, COUNT(*) AS n, SUM(days) AS d FROM hr_leaves WHERE fiscal_year = ? AND status = 'approved' GROUP BY user_id, leave_type", 'i', [$fy]) as $r) {
            $sum[(int)$r['user_id']][$r['leave_type']] = [(int)$r['n'], (float)$r['d']];
        }
        foreach ($people as $p) {
            $s = $sum[(int)$p['id']] ?? [];
            $g = fn(string $t, int $k) => $s[$t][$k] ?? 0;
            $other = 0.0;
            foreach (['maternity', 'paternity', 'ordination', 'other'] as $t) {
                $other += $g($t, 1);
            }
            $total = array_sum(array_map(fn($x) => $x[1], $s));
            $rows[] = [++$i, $p['full_name'], $dept($p), $g('sick', 0), hr_days($g('sick', 1)), $g('personal', 0), hr_days($g('personal', 1)),
                $g('vacation', 0), hr_days($g('vacation', 1)), hr_days(max(0, hr_vacation_quota((int)$p['id'], $fy) - $g('vacation', 1))),
                hr_days($other), hr_days($total)];
        }
        return ['title' => $title, 'num' => [0, 3, 4, 5, 6, 7, 8, 9, 10, 11],
            'header' => ['ที่', 'ชื่อ-สกุล', 'แผนก/งาน', 'ป่วย (ครั้ง)', 'ป่วย (วัน)', 'กิจ (ครั้ง)', 'กิจ (วัน)', 'พักผ่อน (ครั้ง)', 'พักผ่อน (วัน)',
                'พักผ่อนคงเหลือ', 'ลาอื่น ๆ (วัน)', 'รวม (วัน)'],
            'rows' => $rows];
    }

    if ($type === 'training') {
        $sum = [];
        foreach (db_all('SELECT user_id, status, COUNT(*) AS n, SUM(hours) AS h FROM hr_trainings WHERE fiscal_year = ? GROUP BY user_id, status', 'i', [$fy]) as $r) {
            $sum[(int)$r['user_id']][$r['status']] = [(int)$r['n'], (float)$r['h']];
        }
        foreach ($people as $p) {
            $s = $sum[(int)$p['id']] ?? [];
            $rows[] = [++$i, $p['full_name'], $p['position'] ?: '-', $dept($p), $s['approved'][0] ?? 0, hr_days($s['approved'][1] ?? 0),
                ($s['pending'][0] ?? 0) + ($s['revision'][0] ?? 0)];
        }
        return ['title' => $title, 'num' => [0, 4, 5, 6],
            'header' => ['ที่', 'ชื่อ-สกุล', 'ตำแหน่ง', 'แผนก/งาน', 'จำนวนรายการ (ผ่าน)', 'ชั่วโมงพัฒนา (ผ่าน)', 'รอตรวจ/แก้ไข'],
            'rows' => $rows];
    }

    if ($type === 'events') {
        // นับเฉพาะกิจกรรมที่บุคลากรคนนั้นถูกกำหนดให้เข้าร่วม (กิจกรรม "ทุกคน" นับทุกคน)
        $events = db_all('SELECT id, participant_mode FROM hr_events WHERE fiscal_year = ? AND is_required = 1 AND event_date <= CURDATE()', 'i', [$fy]);
        $allCount = count(array_filter($events, fn($e) => !hr_event_is_selected($e)));
        $selCount = [];
        foreach (db_all(
            "SELECT p.user_id, COUNT(*) AS n FROM hr_event_participants p JOIN hr_events e ON e.id = p.event_id
             WHERE e.fiscal_year = ? AND e.is_required = 1 AND e.event_date <= CURDATE() AND e.participant_mode = 'selected' GROUP BY p.user_id",
            'i',
            [$fy]
        ) as $r) {
            $selCount[(int)$r['user_id']] = (int)$r['n'];
        }
        $sum = [];
        foreach (db_all(
            'SELECT a.user_id, a.status, COUNT(*) AS n FROM hr_event_attendances a JOIN hr_events e ON e.id = a.event_id
             WHERE e.fiscal_year = ? AND e.is_required = 1 AND e.event_date <= CURDATE() AND ' . hr_event_participant_sql('a.user_id') . '
             GROUP BY a.user_id, a.status',
            'i',
            [$fy]
        ) as $r) {
            $sum[(int)$r['user_id']][$r['status']] = (int)$r['n'];
        }
        foreach ($people as $p) {
            $s = $sum[(int)$p['id']] ?? [];
            $required = $allCount + ($selCount[(int)$p['id']] ?? 0);
            $att = $s['attended'] ?? 0;
            $rows[] = [++$i, $p['full_name'], $dept($p), $required, $att, $s['leave'] ?? 0, $s['absent'] ?? 0,
                max(0, $required - array_sum($s)), $required ? percent($att, $required) . '%' : '-'];
        }
        return ['title' => $title, 'num' => [0, 3, 4, 5, 6, 7, 8],
            'header' => ['ที่', 'ชื่อ-สกุล', 'แผนก/งาน', 'กิจกรรมที่กำหนด', 'เข้าร่วม', 'ลา/ไปราชการ', 'ไม่เข้าร่วม', 'ไม่มีข้อมูล', 'ร้อยละการเข้าร่วม'],
            'rows' => $rows];
    }
    return ['title' => '', 'header' => [], 'rows' => [], 'num' => []];
}
