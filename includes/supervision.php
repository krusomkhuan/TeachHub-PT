<?php
/**
 * ไฟล์: includes/supervision.php
 * ฟังก์ชันกลางของโมดูลนิเทศการสอน
 * - ตารางนิเทศ / ผลการนิเทศ / คะแนนรายข้อ / รูปหลักฐาน
 * - ตรวจสิทธิ์การดูและการบันทึกผล
 */

/** สถานะตารางนิเทศ */
function schedule_status_badge(string $status): string
{
    $map = [
        'scheduled' => ['bg-info text-dark', 'รอนิเทศ'],
        'completed' => ['bg-success', 'นิเทศแล้ว'],
        'cancelled' => ['bg-secondary', 'ยกเลิก'],
    ];
    [$cls, $label] = $map[$status] ?? ['bg-light text-dark', $status];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/** ป้ายระดับคุณภาพ */
function quality_badge(?float $avg): string
{
    if ($avg === null || $avg <= 0) {
        return '<span class="text-muted">-</span>';
    }
    $level = quality_level($avg);
    $cls = ['ดีมาก' => 'bg-success', 'ดี' => 'bg-primary', 'ปานกลาง' => 'bg-warning text-dark',
        'พอใช้' => 'badge-late', 'ควรปรับปรุง' => 'bg-danger'][$level] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . number_format($avg, 2) . ' · ' . e($level) . '</span>';
}

/** ความหมายของคะแนน 1-5 */
function score_labels(): array
{
    return [5 => 'ดีมาก', 4 => 'ดี', 3 => 'ปานกลาง', 2 => 'พอใช้', 1 => 'ควรปรับปรุง'];
}

/**
 * ดึงตารางนิเทศ 1 รายการ พร้อมชื่อครู ผู้นิเทศ แผนก ภาคเรียน และผลการนิเทศ (ถ้ามี)
 */
function schedule_fetch(int $id): ?array
{
    return db_one(
        "SELECT sc.*,
                CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name, t.position AS teacher_position,
                t.academic_standing AS teacher_standing, t.department_id AS teacher_department_id,
                CONCAT(s.prefix, s.first_name, ' ', s.last_name) AS supervisor_name, s.position AS supervisor_position,
                d.name AS department_name, ay.year AS ay_year, ay.semester AS ay_semester,
                res.id AS result_id, res.avg_score, res.quality_level, res.strengths, res.suggestions,
                res.acknowledged_at, res.created_at AS result_created_at, res.updated_at AS result_updated_at
         FROM supervision_schedules sc
         JOIN users t ON t.id = sc.teacher_id
         JOIN users s ON s.id = sc.supervisor_id
         LEFT JOIN departments d ON d.id = t.department_id
         LEFT JOIN academic_years ay ON ay.id = sc.academic_year_id
         LEFT JOIN supervision_results res ON res.schedule_id = sc.id
         WHERE sc.id = ?",
        'i',
        [$id]
    );
}

/**
 * ผู้ใช้ดูตารางนิเทศ/ผลนี้ได้หรือไม่
 * - admin / executive : ทั้งหมด
 * - teacher           : เฉพาะที่ตนเองถูกนิเทศ
 * - supervisor        : ที่ตนเองเป็นผู้นิเทศ, ที่ตนเองถูกนิเทศ หรือครูในแผนกตนเอง
 */
function can_view_schedule(array $user, array $sc): bool
{
    if (admin_can($user, 'supervision') || $user['role'] === 'executive') {
        return true;
    }
    $uid = (int)$user['id'];
    if ((int)$sc['teacher_id'] === $uid || (int)$sc['supervisor_id'] === $uid) {
        return true;
    }
    return $user['role'] === 'supervisor' && (int)$user['department_id'] > 0
        && (int)$sc['teacher_department_id'] === (int)$user['department_id'];
}

/**
 * ผู้ใช้บันทึก/แก้ไขผลการนิเทศได้หรือไม่
 * - admin หรือผู้นิเทศที่ถูกกำหนดในตาราง
 * - ตารางต้องไม่ถูกยกเลิก และครูยังไม่กดรับทราบผล
 */
function can_record_schedule(array $user, array $sc): bool
{
    if ($sc['status'] === 'cancelled' || !empty($sc['acknowledged_at'])) {
        return false;
    }
    return admin_can($user, 'supervision') || (int)$sc['supervisor_id'] === (int)$user['id'];
}

/**
 * ผู้ใช้จัดการ (แก้ไข/ยกเลิก/ลบ) ตารางนี้ได้หรือไม่
 * - admin ทุกตาราง, supervisor เฉพาะครูในแผนกตนเอง หรือที่ตนเองเป็นผู้สร้าง
 */
function can_manage_schedule(array $user, array $sc): bool
{
    if (admin_can($user, 'supervision')) {
        return true;
    }
    return $user['role'] === 'supervisor'
        && ((int)$sc['created_by'] === (int)$user['id']
            || ((int)$user['department_id'] > 0 && (int)$sc['teacher_department_id'] === (int)$user['department_id']));
}

/**
 * หัวข้อประเมินที่เปิดใช้งาน (เรียงตามลำดับ)
 * @param string $audience supervisor = หัวหน้าแผนกนิเทศ, student = นักเรียนประเมิน
 */
function supervision_criteria_active(string $audience = 'supervisor'): array
{
    return db_all(
        'SELECT * FROM supervision_criteria WHERE audience = ? AND is_active = 1 ORDER BY sort_order, id',
        's',
        [$audience === 'student' ? 'student' : 'supervisor']
    );
}

/** คะแนนรายข้อของผลการนิเทศ [criteria_id => แถว] */
function supervision_scores(int $resultId): array
{
    $out = [];
    foreach (db_all('SELECT * FROM supervision_scores WHERE result_id = ? ORDER BY id', 'i', [$resultId]) as $r) {
        $out[(int)$r['criteria_id']] = $r;
    }
    return $out;
}

/** คะแนนเฉลี่ยรายด้าน [ด้าน => ค่าเฉลี่ย] */
function supervision_category_avg(array $scores): array
{
    $sum = [];
    foreach ($scores as $s) {
        $sum[$s['category']][] = (int)$s['score'];
    }
    return array_map(fn($list) => round(array_sum($list) / count($list), 2), $sum);
}

/** รูปหลักฐานของผลการนิเทศ */
function supervision_images(int $resultId): array
{
    return db_all('SELECT * FROM supervision_images WHERE result_id = ? ORDER BY id', 'i', [$resultId]);
}

/** จัดรูป $_FILES ที่อัปโหลดหลายไฟล์ ให้เป็นรายการไฟล์ทีละรายการ */
function normalize_multi_files(?array $files): array
{
    if (!$files || !is_array($files['name'] ?? null)) {
        return [];
    }
    $out = [];
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name' => $name, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i], 'size' => $files['size'][$i],
        ];
    }
    return $out;
}

/** ตัวเลือกผู้นิเทศ (หัวหน้าแผนก ผู้บริหาร และ admin ที่ใช้งาน) */
function supervisor_options(): array
{
    return db_all(
        "SELECT u.id, u.prefix, u.first_name, u.last_name, u.role, d.name AS department_name
         FROM users u LEFT JOIN departments d ON d.id = u.department_id
         WHERE u.status = 'active' AND u.role IN ('supervisor','executive','admin')
         ORDER BY FIELD(u.role, 'supervisor', 'executive', 'admin'), u.first_name"
    );
}
