<?php
/**
 * ไฟล์: includes/submissions.php
 * ฟังก์ชันกลางของ "งานที่ส่ง" ทุกประเภท (แผนการสอน / PA / วิจัย)
 * - ดึงข้อมูลงาน + ไฟล์ทุกเวอร์ชัน + ประวัติการตรวจ
 * - บันทึกไฟล์เวอร์ชันใหม่, บันทึกผลตรวจ, ลบงาน
 * - ตรวจสิทธิ์การดู/การตรวจ
 */

/** โฟลเดอร์เก็บไฟล์ใน uploads/ ของแต่ละประเภท */
function submission_subdir(string $type): string
{
    $map = ['lesson_plan' => 'lesson_plans', 'pa' => 'pa', 'research' => 'research'];
    if (!isset($map[$type])) {
        throw new InvalidArgumentException('ประเภทงานไม่ถูกต้อง');
    }
    return $map[$type];
}

/** ขั้นตอน (phase) ที่ใช้ได้ของรอบแต่ละประเภท */
function round_phase_options(string $type): array
{
    $map = [
        'lesson_plan' => ['main'], // รอบเดียวรับทั้งโครงการสอนและแผนการจัดการเรียนรู้ (ครูเลือกประเภทเอกสารในฟอร์ม)
        'pa'          => ['agreement', 'report'],
        'research'    => ['proposal', 'final'],
    ];
    return $map[$type] ?? [];
}

/**
 * นิพจน์ SQL สำหรับ "ชื่องาน" ของแต่ละประเภท (ใช้แสดงในตาราง)
 * ค่าที่คืนมาเป็นโค้ดคงที่ ไม่มีข้อมูลจากผู้ใช้
 */
function submission_title_sql(string $type, string $a = 's'): string
{
    switch ($type) {
        case 'lesson_plan':
            return "CONCAT(IF($a.doc_type = 'outline', '[โครงการสอน] ', '[แผนฯ] '), $a.subject_code, ' ', $a.subject_name)";
        case 'pa':
            return "CONCAT('PA ปีงบประมาณ ', $a.fiscal_year, IF($a.phase = 'agreement', ' (ข้อตกลงต้นปี)', ' (รายงานผลปลายปี)'))";
        case 'research':
            return "CONCAT(IF($a.stage = 'proposal', '[เค้าโครง] ', '[ฉบับสมบูรณ์] '), $a.title)";
    }
    throw new InvalidArgumentException('ประเภทงานไม่ถูกต้อง');
}

/**
 * ดึงงาน 1 รายการ พร้อมข้อมูลรอบ ครู แผนก และภาคเรียน
 */
function submission_fetch(string $type, int $id): ?array
{
    $table = submission_table($type);
    $title = submission_title_sql($type);
    // วิจัยมีคอลัมน์ title ของตนเอง ซึ่งถูกแทนที่ด้วยชื่อสำหรับแสดงผล จึงเก็บค่าจริงไว้ใน research_title
    $raw = $type === 'research' ? 's.title AS research_title,' : '';
    $row = db_one(
        "SELECT s.*, $raw $title AS title,
                r.name AS round_name, r.phase AS round_phase, r.open_at, r.close_at, r.allow_late,
                r.is_active AS round_active, r.fiscal_year AS round_fiscal_year,
                ay.year AS ay_year, ay.semester AS ay_semester,
                u.prefix, u.first_name, u.last_name, u.department_id, u.email AS teacher_email, u.phone AS teacher_phone,
                d.name AS department_name
         FROM `$table` s
         JOIN submission_rounds r ON r.id = s.round_id
         LEFT JOIN academic_years ay ON ay.id = r.academic_year_id
         JOIN users u ON u.id = s.teacher_id
         LEFT JOIN departments d ON d.id = u.department_id
         WHERE s.id = ?",
        'i',
        [$id]
    );
    if ($row) {
        $row['_type'] = $type;
        $row['teacher_name'] = full_name($row);
    }
    return $row;
}

/** รอบการส่งในรูปแบบที่ round_state() ใช้ได้ จากแถวที่ได้จาก submission_fetch() */
function submission_round(array $sub): array
{
    return [
        'id'         => $sub['round_id'],
        'is_active'  => $sub['round_active'],
        'open_at'    => $sub['open_at'],
        'close_at'   => $sub['close_at'],
        'allow_late' => $sub['allow_late'],
    ];
}

/** ไฟล์ทุกเวอร์ชันของงาน (ใหม่สุดก่อน) */
function submission_files(string $type, int $id): array
{
    return db_all(
        "SELECT f.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS uploader
         FROM submission_files f LEFT JOIN users u ON u.id = f.uploaded_by
         WHERE f.submission_type = ? AND f.submission_id = ?
         ORDER BY f.version DESC, f.id DESC",
        'si',
        [$type, $id]
    );
}

/** ประวัติการตรวจ (ใหม่สุดก่อน) */
function submission_reviews(string $type, int $id): array
{
    return db_all(
        "SELECT rv.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS reviewer_name, u.role AS reviewer_role
         FROM reviews rv LEFT JOIN users u ON u.id = rv.reviewer_id
         WHERE rv.submission_type = ? AND rv.submission_id = ?
         ORDER BY rv.id DESC",
        'si',
        [$type, $id]
    );
}

/** ความเห็นล่าสุดของผู้ตรวจ */
function submission_last_review(string $type, int $id): ?array
{
    return db_one(
        'SELECT * FROM reviews WHERE submission_type = ? AND submission_id = ? ORDER BY id DESC LIMIT 1',
        'si',
        [$type, $id]
    );
}

/**
 * บันทึกข้อมูลไฟล์ที่อัปโหลดแล้ว (จาก handle_upload) เป็นเวอร์ชันใหม่
 */
function submission_store_file(string $type, int $id, int $version, array $upload, bool $isLate, string $note, int $uid): int
{
    return db_insert(
        'INSERT INTO submission_files
            (submission_type, submission_id, version, original_name, stored_name, file_dir, mime_type, file_size, is_late, note, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        'siissssiisi',
        [$type, $id, $version, $upload['original_name'], $upload['stored_name'], $upload['subdir'],
            $upload['mime'], $upload['size'], $isLate ? 1 : 0, mb_substr($note, 0, 500), $uid]
    );
}

/**
 * อัปโหลดไฟล์งานตามค่าตั้งค่าระบบ (นามสกุล/ขนาด)
 */
function submission_upload(string $type, string $field = 'file'): array
{
    if (empty($_FILES[$field])) {
        return ['ok' => false, 'error' => 'กรุณาแนบไฟล์'];
    }
    return handle_upload($_FILES[$field], submission_subdir($type), allowed_upload_extensions(), max_upload_bytes());
}

/** รหัสสิทธิ์เจ้าหน้าที่ดูแลระบบที่ใช้ตรวจงานประเภทนี้ (includes/admin_perm.php) */
function submission_admin_perm(string $type): string
{
    return ['lesson_plan' => 'lesson_plans', 'pa' => 'pa', 'research' => 'research'][$type] ?? $type;
}

/**
 * ผู้ใช้ดูงานนี้ได้หรือไม่
 */
function can_view_submission(array $user, array $sub): bool
{
    // เจ้าหน้าที่ดูแลระบบ: ดูได้เฉพาะงานประเภทที่ได้รับสิทธิ์ตรวจ (แผนฯ ดูได้ด้วยสิทธิ์ประเมินแผน) หรืองานของตนเอง
    if (is_limited_admin($user)) {
        $type = $sub['_type'] ?? '';
        return (int)$sub['teacher_id'] === (int)$user['id']
            || ($type !== '' && admin_can($user, submission_admin_perm($type)))
            || ($type === 'lesson_plan' && admin_can($user, 'plan_eval'));
    }
    if (isset($sub['_type']) && is_designated_reviewer($user, $sub['_type'])) {
        return true;
    }
    return can_view_teacher($user, (int)$sub['teacher_id']);
}

/**
 * ผู้ตรวจงานตามตำแหน่ง (กำหนดที่ช่อง "ตำแหน่ง" ของผู้ใช้ — แก้ได้เฉพาะผู้ดูแลระบบ)
 *   วิจัยในชั้นเรียน       → หัวหน้างานส่งเสริมการวิจัย นวัตกรรม และสิ่งประดิษฐ์
 *   ข้อตกลง PA            → หัวหน้างานบุคลากร
 *   โครงการสอน/แผนฯ       → หัวหน้างาน และเจ้าหน้าที่งานพัฒนาหลักสูตรและการจัดการเรียนรู้
 */
function reviewer_positions(): array
{
    return [
        'lesson_plan' => ['หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้', 'เจ้าหน้าที่งานพัฒนาหลักสูตรและการจัดการเรียนรู้'],
        'pa'          => ['หัวหน้างานบุคลากร'],
        'research'    => ['หัวหน้างานส่งเสริมการวิจัย นวัตกรรม และสิ่งประดิษฐ์'],
    ];
}

/** รายชื่อตำแหน่งผู้ตรวจทั้งหมด (ไม่ซ้ำ) */
function reviewer_position_list(): array
{
    return array_values(array_unique(array_merge(...array_values(reviewer_positions()))));
}

/** ตำแหน่งนี้เป็นตำแหน่งผู้ตรวจงานหรือไม่ */
function is_reviewer_position(string $position): bool
{
    return in_array(normalize_position($position), reviewer_position_list(), true);
}

/** ตัดช่องว่างซ้ำในชื่อตำแหน่ง เพื่อเทียบได้ตรง */
function normalize_position(string $position): string
{
    return trim(preg_replace('/\s+/u', ' ', $position));
}

/** ประเภทงานที่ผู้ใช้เป็นผู้ตรวจตามตำแหน่ง */
function designated_review_types(array $user): array
{
    if (($user['status'] ?? 'active') !== 'active' || ($user['role'] ?? '') === 'executive') {
        return [];
    }
    $pos = normalize_position((string)($user['position'] ?? ''));
    $types = [];
    foreach (reviewer_positions() as $type => $positions) {
        if ($pos !== '' && in_array($pos, $positions, true)) {
            $types[] = $type;
        }
    }
    return $types;
}

function is_designated_reviewer(array $user, string $type): bool
{
    return in_array($type, designated_review_types($user), true);
}

/** ผู้ใช้ที่เป็นผู้ตรวจงานประเภทนี้ (สำหรับแสดงในหน้าครู) */
function designated_reviewers(string $type): array
{
    $positions = reviewer_positions()[$type] ?? [];
    if (!$positions) {
        return [];
    }
    $in = implode(',', array_fill(0, count($positions), '?'));
    return db_all(
        "SELECT id, prefix, first_name, last_name, position FROM users
         WHERE status = 'active' AND role <> 'executive' AND position IN ($in) ORDER BY first_name",
        str_repeat('s', count($positions)),
        $positions
    );
}

/**
 * ผู้ใช้ตรวจงานนี้ได้หรือไม่
 * - admin ตรวจได้ทุกงาน
 * - ผู้ตรวจตามตำแหน่ง ตรวจงานประเภทนั้นได้ทุกแผนก (ยกเว้นงานของตนเอง)
 * - supervisor ตรวจได้เฉพาะครูในแผนกตนเอง และไม่ตรวจงานของตนเอง
 */
function can_review_submission(array $user, array $sub): bool
{
    if ($user['role'] === 'admin') {
        return is_full_admin($user) || (isset($sub['_type']) && admin_can($user, submission_admin_perm($sub['_type'])));
    }
    if (isset($sub['_type']) && is_designated_reviewer($user, $sub['_type'])) {
        return (int)$sub['teacher_id'] !== (int)$user['id'];
    }
    return $user['role'] === 'supervisor'
        && (int)$sub['teacher_id'] !== (int)$user['id']
        && (int)$sub['department_id'] === (int)$user['department_id']
        && (int)$user['department_id'] > 0;
}

/**
 * บันทึกผลการตรวจ และปรับสถานะงาน
 * @param string $result approved | revision
 */
function submission_review(string $type, array $sub, string $result, string $comment, int $reviewerId): void
{
    $table = submission_table($type);
    db()->begin_transaction();
    try {
        db_insert(
            'INSERT INTO reviews (submission_type, submission_id, version, reviewer_id, result, comment) VALUES (?, ?, ?, ?, ?, ?)',
            'siiiss',
            [$type, (int)$sub['id'], (int)$sub['current_version'], $reviewerId, $result, $comment]
        );
        db_exec("UPDATE `$table` SET status = ? WHERE id = ?", 'si', [$result, (int)$sub['id']]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollback();
        throw $ex;
    }
}

/**
 * ลบงานพร้อมไฟล์ทุกเวอร์ชันและประวัติการตรวจ
 */
function submission_delete(string $type, array $sub): void
{
    $table = submission_table($type);
    $files = submission_files($type, (int)$sub['id']);
    db()->begin_transaction();
    try {
        db_exec('DELETE FROM submission_files WHERE submission_type = ? AND submission_id = ?', 'si', [$type, (int)$sub['id']]);
        db_exec('DELETE FROM reviews WHERE submission_type = ? AND submission_id = ?', 'si', [$type, (int)$sub['id']]);
        db_exec("DELETE FROM `$table` WHERE id = ?", 'i', [(int)$sub['id']]);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollback();
        throw $ex;
    }
    // ลบไฟล์จริงหลังลบข้อมูลสำเร็จ
    foreach ($files as $f) {
        delete_upload($f['file_dir'], $f['stored_name']);
    }
}

/**
 * สร้างงานใหม่พร้อมไฟล์ฉบับที่ 1 (ใช้ร่วมกันทุกประเภทงาน)
 * - ตรวจรอบเปิดรับฝั่งเซิร์ฟเวอร์
 * - อัปโหลดไฟล์ แล้วเรียก $insert(bool $isLate): int เพื่อ INSERT ข้อมูลเฉพาะของประเภทงาน
 * - ถ้าบันทึกฐานข้อมูลไม่สำเร็จ จะลบไฟล์ที่อัปโหลดทิ้ง
 * @return array ['ok' => bool, 'id' => int, 'is_late' => bool, 'error' => string]
 */
function submission_create(string $type, array $round, int $uid, string $note, callable $insert): array
{
    if (!round_can_submit($round)) {
        return ['ok' => false, 'error' => round_state($round) === 'upcoming' ? 'รอบนี้ยังไม่เปิดรับ' : 'รอบนี้ปิดรับแล้ว'];
    }
    $up = submission_upload($type);
    if (!$up['ok']) {
        return ['ok' => false, 'error' => $up['error'], 'field' => 'file'];
    }
    $isLate = round_is_late_now($round);
    db()->begin_transaction();
    try {
        $id = (int)$insert($isLate);
        submission_store_file($type, $id, 1, $up, $isLate, $note, $uid);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollback();
        delete_upload($up['subdir'], $up['stored_name']);
        throw $ex;
    }
    return ['ok' => true, 'id' => $id, 'is_late' => $isLate, 'error' => ''];
}

/**
 * ส่งฉบับใหม่ (เวอร์ชัน +1) สถานะกลับเป็น "รอตรวจ"
 * @param string $setSql    คอลัมน์ที่ต้องการแก้เพิ่ม เช่น "title = ?, problem = ?" (โค้ดคงที่จากหน้าเรียกใช้)
 * @param string $setTypes  ชนิดข้อมูลของ $setParams
 * @return string ข้อความผิดพลาด (ว่าง = สำเร็จ)
 */
function submission_resubmit(string $type, array $sub, int $uid, string $note, string $setSql = '', string $setTypes = '', array $setParams = []): string
{
    $reason = submission_resubmit_block_reason($sub);
    if ($reason !== '') {
        return $reason;
    }
    $up = submission_upload($type);
    if (!$up['ok']) {
        return $up['error'];
    }
    $table = submission_table($type);
    $isLate = in_array(round_state(submission_round($sub)), ['late', 'closed'], true);
    $version = (int)$sub['current_version'] + 1;
    db()->begin_transaction();
    try {
        db_exec(
            "UPDATE `$table` SET current_version = ?, status = 'pending'" . ($setSql !== '' ? ', ' . $setSql : '') . ' WHERE id = ?',
            'i' . $setTypes . 'i',
            array_merge([$version], $setParams, [(int)$sub['id']])
        );
        submission_store_file($type, (int)$sub['id'], $version, $up, $isLate, $note, $uid);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollback();
        delete_upload($up['subdir'], $up['stored_name']);
        throw $ex;
    }
    log_activity('resubmit_' . $type, 'ส่ง' . submission_type_label($type) . ' ฉบับที่ ' . $version . ': ' . $sub['title']);
    return '';
}

/** ดึงงานของครูคนนี้เท่านั้น (ป้องกันการเปิดงานของผู้อื่นด้วยการเดา id) */
function own_submission(string $type, int $id, int $uid): ?array
{
    $sub = submission_fetch($type, $id);
    return ($sub && (int)$sub['teacher_id'] === $uid) ? $sub : null;
}

/** ข้อความแนะนำชนิด/ขนาดไฟล์ และค่า accept ของ input file */
function upload_hint(): string
{
    return 'ไฟล์ ' . strtoupper(implode(', ', allowed_upload_extensions())) . ' ขนาดไม่เกิน ' . format_bytes(max_upload_bytes());
}

function upload_accept(): string
{
    return implode(',', array_map(fn($x) => '.' . $x, allowed_upload_extensions()));
}

/**
 * ครูส่งใหม่ (เวอร์ชันใหม่) ได้หรือไม่
 * - งานที่ "ผ่าน" แล้วส่งใหม่ไม่ได้
 * - งานที่ "ให้แก้ไข" ส่งใหม่ได้เสมอ แม้รอบปิดแล้ว (เป็นการแก้ตามที่ผู้ตรวจขอ)
 * - งานที่ "รอตรวจ" แทนที่ไฟล์ได้เฉพาะช่วงที่รอบยังรับงาน
 * @return string ข้อความเหตุผลถ้าส่งไม่ได้ (ว่าง = ส่งได้)
 */
function submission_resubmit_block_reason(array $sub): string
{
    if ($sub['status'] === 'approved') {
        return 'งานนี้ผ่านการตรวจแล้ว ไม่สามารถส่งใหม่ได้';
    }
    if ($sub['status'] === 'revision') {
        return '';
    }
    return round_can_submit(submission_round($sub)) ? '' : 'รอบการส่งปิดรับแล้ว ไม่สามารถแทนที่ไฟล์ได้';
}

/** ครูลบงานของตนเองได้หรือไม่ (ยังไม่เคยถูกตรวจ และรอบยังเปิดรับ) */
function submission_can_delete(array $sub): bool
{
    return $sub['status'] === 'pending'
        && !submission_last_review($sub['_type'], (int)$sub['id'])
        && round_can_submit(submission_round($sub));
}

/**
 * รายละเอียดเฉพาะของแต่ละประเภท [ป้ายชื่อ => ค่า] สำหรับแสดงผล
 */
function submission_detail_fields(string $type, array $s): array
{
    $common = [
        'ครูผู้ส่ง'   => $s['teacher_name'],
        'แผนกวิชา'  => $s['department_name'] ?? '-',
        'รอบการส่ง' => $s['round_name'],
    ];
    switch ($type) {
        case 'lesson_plan':
            return $common + [
                'ประเภทเอกสาร'        => lesson_doc_label((string)($s['doc_type'] ?? 'plan')),
                'ภาคเรียน/ปีการศึกษา' => $s['ay_year'] ? $s['ay_semester'] . '/' . $s['ay_year'] : '-',
                'รหัสวิชา'            => $s['subject_code'],
                'ชื่อวิชา'             => $s['subject_name'],
                'ระดับชั้น'            => $s['level'],
                'จำนวนรายวิชา' => $s['units'] . ' รายวิชา',
            ];
        case 'pa':
            [$p1, $p2] = pa_part_labels($s['phase']);
            return $common + [
                'ปีงบประมาณ' => $s['fiscal_year'],
                'รอบ'        => phase_label($s['phase']),
                'ตำแหน่ง'    => $s['position'],
                'วิทยฐานะ'   => $s['academic_standing'] ?: '-',
                $p1          => $s['part1_workload'],
                $p2          => $s['part2_challenge'],
            ];
        case 'research':
            return $common + [
                'ขั้นตอน'           => phase_label($s['stage']),
                'ชื่อเรื่อง'         => $s['research_title'] ?? $s['title'],
                'ปัญหาการวิจัย'     => $s['problem'],
                'กลุ่มเป้าหมาย'     => $s['target_group'],
                'วิชาที่เกี่ยวข้อง'  => $s['subject_text'] ?: '-',
            ];
    }
    return $common;
}

/**
 * ชื่อหัวข้อส่วนที่ 1 / 2 ของแบบ PA ตามรอบ
 * - ต้นปี (agreement) : ข้อตกลงภาระงาน / ประเด็นท้าทาย
 * - ปลายปี (report)   : ผลการปฏิบัติงานตามข้อตกลง / ผลการพัฒนาตามประเด็นท้าทาย
 */
function pa_part_labels(string $phase): array
{
    return $phase === 'report'
        ? ['ส่วนที่ 1 ผลการปฏิบัติงานตามข้อตกลงภาระงาน', 'ส่วนที่ 2 ผลการพัฒนางานตามประเด็นท้าทาย']
        : ['ส่วนที่ 1 ข้อตกลงภาระงาน', 'ส่วนที่ 2 ประเด็นท้าทาย'];
}

/** หน้าตรวจงานของ role ปัจจุบัน */
function review_page(string $role, string $type): string
{
    $file = ['lesson_plan' => 'lesson_plans.php', 'pa' => 'pa.php', 'research' => 'research.php'][$type];
    $dir = ['supervisor' => 'supervisor/', 'admin' => 'admin/'][$role] ?? 'review/'; // ครูที่เป็นผู้ตรวจตามตำแหน่ง ใช้ review/
    return $dir . $file;
}
