<?php
/**
 * ไฟล์: includes/supervision_report.php
 * รายงานการนิเทศครูผู้สอน (หัวหน้าแผนกวิชาเป็นผู้จัดทำ)
 * ลำดับการเสนอ: ขั้น 1 หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้ → ขั้น 2 รองผู้อำนวยการฝ่ายวิชาการ → ขั้น 3 ผู้อำนวยการ
 *  - ผู้พิจารณาแต่ละขั้น admin กำหนดในหน้าตั้งค่าระบบ (settings: approver_N_user / approver_N_title)
 *  - ขั้น 1-2: "เห็นควรเสนอ" หรือ "ส่งกลับแก้ไข" · ขั้น 3: "ทราบ/อนุมัติ" หรือ "ส่งกลับแก้ไข"
 *  - ส่งกลับแก้ไข → ผู้จัดทำแก้แล้วเสนอใหม่ เริ่มที่ขั้น 1 อีกครั้ง
 */

/** ลำดับผู้พิจารณา [ขั้น => ['user_id', 'title', 'name']] */
function report_approval_chain(): array
{
    static $chain = null;
    if ($chain === null) {
        $chain = [];
        for ($i = 1; $i <= 3; $i++) {
            $uid = (int)setting('approver_' . $i . '_user', '0');
            $u = $uid ? db_one("SELECT id, prefix, first_name, last_name FROM users WHERE id = ? AND status = 'active'", 'i', [$uid]) : null;
            $chain[$i] = [
                'user_id' => $u ? (int)$u['id'] : 0,
                'name'    => $u ? full_name($u) : '',
                'title'   => (string)setting('approver_' . $i . '_title', ['', 'หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้',
                    'รองผู้อำนวยการฝ่ายวิชาการ', 'ผู้อำนวยการ' . college_name()][$i]),
            ];
        }
    }
    return $chain;
}

/** ผู้พิจารณาครบทุกขั้นหรือไม่ */
function report_chain_ready(): bool
{
    foreach (report_approval_chain() as $s) {
        if (!$s['user_id']) {
            return false;
        }
    }
    return true;
}

/** ผู้ใช้เป็นผู้พิจารณาขั้นใดบ้าง */
function report_user_steps(int $userId): array
{
    $steps = [];
    foreach (report_approval_chain() as $i => $s) {
        if ($s['user_id'] === $userId) {
            $steps[] = $i;
        }
    }
    return $steps;
}

/** ดึงรายงาน พร้อมชื่อแผนก ภาคเรียน ผู้จัดทำ */
function report_fetch(int $id): ?array
{
    return db_one(
        "SELECT r.*, d.name AS department_name, ay.year AS ay_year, ay.semester AS ay_semester,
                CONCAT(a.prefix, a.first_name, ' ', a.last_name) AS author_name, a.position AS author_position
         FROM supervision_reports r
         JOIN departments d ON d.id = r.department_id
         JOIN academic_years ay ON ay.id = r.academic_year_id
         JOIN users a ON a.id = r.author_id
         WHERE r.id = ?",
        'i',
        [$id]
    );
}

/** ข้อความสถานะของรายงาน */
function report_status_text(array $r): string
{
    if ($r['status'] === 'pending') {
        return 'รอ' . (report_approval_chain()[(int)$r['current_step']]['title'] ?? 'ผู้พิจารณา') . 'พิจารณา';
    }
    return ['draft' => 'ร่าง (ยังไม่เสนอ)', 'returned' => 'ส่งกลับแก้ไข', 'approved' => 'ผู้อำนวยการลงนามแล้ว'][$r['status']] ?? $r['status'];
}

function report_status_badge(array $r): string
{
    $cls = ['draft' => 'bg-secondary', 'pending' => 'bg-warning text-dark', 'returned' => 'bg-danger', 'approved' => 'bg-success'][$r['status']] ?? 'bg-light';
    return '<span class="badge ' . $cls . '">' . e(report_status_text($r)) . '</span>';
}

/**
 * ผู้ใช้เปิดดูรายงานได้หรือไม่
 * - admin / ผู้บริหาร / ผู้พิจารณาทุกขั้น : ได้ทุกรายงานที่เสนอแล้ว (ร่างเห็นเฉพาะผู้จัดทำ แผนกเดียวกัน และ admin)
 * - หัวหน้าแผนก : รายงานของแผนกตนเอง
 */
function can_view_report(array $user, array $r): bool
{
    $uid = (int)$user['id'];
    if ($user['role'] === 'admin' || (int)$r['author_id'] === $uid) {
        return true;
    }
    if ($user['role'] === 'supervisor' && (int)$user['department_id'] === (int)$r['department_id']) {
        return true;
    }
    $submitted = in_array($r['status'], ['pending', 'approved'], true) || $r['submitted_at'] !== null;
    return $submitted && ($user['role'] === 'executive' || report_user_steps($uid));
}

/** ผู้ใช้เป็นผู้พิจารณาขั้นปัจจุบันของรายงานนี้หรือไม่ */
function is_current_report_approver(array $user, array $r): bool
{
    return $r['status'] === 'pending'
        && (report_approval_chain()[(int)$r['current_step']]['user_id'] ?? 0) === (int)$user['id'];
}

/** ผู้จัดทำแก้ไข/เสนอได้หรือไม่ */
function can_edit_report(array $user, array $r): bool
{
    return (int)$r['author_id'] === (int)$user['id'] && in_array($r['status'], ['draft', 'returned'], true);
}

/** ประวัติการเสนอ/พิจารณา */
function report_actions(int $reportId): array
{
    return db_all(
        "SELECT x.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS user_name
         FROM supervision_report_actions x LEFT JOIN users u ON u.id = x.user_id
         WHERE x.report_id = ? ORDER BY x.id",
        'i',
        [$reportId]
    );
}

/** ความเห็นล่าสุดของแต่ละขั้น (หลังการเสนอครั้งล่าสุด) [ขั้น => แถว] */
function report_latest_opinions(int $reportId): array
{
    $out = [];
    foreach (report_actions($reportId) as $a) {
        if ($a['action'] === 'submit') {
            $out = []; // เสนอใหม่ เริ่มนับความเห็นใหม่
            continue;
        }
        if ($a['step'] >= 1) {
            $out[(int)$a['step']] = $a;
        }
    }
    return $out;
}

/**
 * ข้อมูลผลการนิเทศของแผนก (สดจากฐานข้อมูล) สำหรับใส่ในรายงาน
 * @return array ['teachers' => [รายครู], 'details' => [รายการนิเทศ], 'categories' => [ด้าน => avg], 'stats' => [...], 'generated_at']
 */
function report_build_data(int $deptId, int $ayId, ?string $from, ?string $to): array
{
    $from = $from ?: '1000-01-01';
    $to = $to ?: '9999-12-31';

    // ครูทุกคนในแผนก + สรุปการนิเทศโดยหัวหน้าแผนก + คะแนนจากนักเรียน
    $teachers = db_all(
        "SELECT u.id, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS name, u.position,
                COUNT(res.id) AS times, ROUND(AVG(res.avg_score), 2) AS avg_score, MAX(sc.supervise_date) AS last_date,
                COALESCE(SUM(res.acknowledged_at IS NOT NULL), 0) AS acked,
                (SELECT ROUND(AVG(r.avg_score), 2) FROM student_eval_responses r
                   JOIN student_eval_sessions s ON s.id = r.session_id
                  WHERE s.teacher_id = u.id AND s.academic_year_id = ?) AS student_avg,
                (SELECT COUNT(r.id) FROM student_eval_responses r
                   JOIN student_eval_sessions s ON s.id = r.session_id
                  WHERE s.teacher_id = u.id AND s.academic_year_id = ?) AS student_n
         FROM users u
         LEFT JOIN supervision_schedules sc ON sc.teacher_id = u.id AND sc.status = 'completed'
              AND sc.academic_year_id = ? AND sc.supervise_date BETWEEN ? AND ?
         LEFT JOIN supervision_results res ON res.schedule_id = sc.id
         WHERE u.department_id = ? AND u.status = 'active' AND u.role IN ('teacher','supervisor')
         GROUP BY u.id, u.prefix, u.first_name, u.last_name, u.position
         ORDER BY u.first_name",
        'iiissi',
        [$ayId, $ayId, $ayId, $from, $to, $deptId]
    );

    // รายการนิเทศแต่ละครั้ง (+ รูปหลักฐานสูงสุด 2 รูป สำหรับภาคผนวก)
    $details = db_all(
        "SELECT sc.id AS schedule_id, sc.supervise_date, sc.start_time, sc.end_time, sc.subject_name, sc.classroom,
                CONCAT(t.prefix, t.first_name, ' ', t.last_name) AS teacher_name,
                CONCAT(s.prefix, s.first_name, ' ', s.last_name) AS supervisor_name,
                res.id AS result_id, res.avg_score, res.quality_level, res.strengths, res.suggestions, res.acknowledged_at
         FROM supervision_schedules sc
         JOIN users t ON t.id = sc.teacher_id
         JOIN users s ON s.id = sc.supervisor_id
         JOIN supervision_results res ON res.schedule_id = sc.id
         WHERE t.department_id = ? AND sc.academic_year_id = ? AND sc.status = 'completed'
           AND sc.supervise_date BETWEEN ? AND ?
         ORDER BY sc.supervise_date, sc.start_time",
        'iiss',
        [$deptId, $ayId, $from, $to]
    );
    foreach ($details as &$d) {
        $d['images'] = array_map('intval', array_column(
            db_all('SELECT id FROM supervision_images WHERE result_id = ? ORDER BY id LIMIT 2', 'i', [(int)$d['result_id']]),
            'id'
        ));
    }
    unset($d);

    // คะแนนเฉลี่ยรายด้าน
    $categories = [];
    foreach (db_all(
        "SELECT ss.category, ROUND(AVG(ss.score), 2) AS avg_score
         FROM supervision_scores ss
         JOIN supervision_results res ON res.id = ss.result_id
         JOIN supervision_schedules sc ON sc.id = res.schedule_id
         JOIN users t ON t.id = sc.teacher_id
         WHERE t.department_id = ? AND sc.academic_year_id = ? AND sc.supervise_date BETWEEN ? AND ?
         GROUP BY ss.category ORDER BY MIN(ss.id)",
        'iiss',
        [$deptId, $ayId, $from, $to]
    ) as $c) {
        $categories[$c['category']] = (float)$c['avg_score'];
    }

    $supervised = array_filter($teachers, fn($t) => (int)$t['times'] > 0);
    $avg = $details ? round(array_sum(array_column($details, 'avg_score')) / count($details), 2) : null;
    $studentRows = array_filter($teachers, fn($t) => $t['student_avg'] !== null);
    return [
        'teachers'     => $teachers,
        'details'      => $details,
        'categories'   => $categories,
        'stats'        => [
            'teachers'    => count($teachers),
            'supervised'  => count($supervised),
            'percent'     => percent(count($supervised), count($teachers)),
            'times'       => count($details),
            'avg'         => $avg,
            'quality'     => $avg !== null ? quality_level($avg) : '-',
            'student_avg' => $studentRows ? round(array_sum(array_column($studentRows, 'student_avg')) / count($studentRows), 2) : null,
        ],
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

/** ข้อมูลของรายงาน: ถ้าเสนอแล้วใช้ snapshot, ถ้ายังเป็นร่างใช้ข้อมูลสด */
function report_data(array $r): array
{
    if ($r['snapshot'] && !in_array($r['status'], ['draft', 'returned'], true)) {
        $data = json_decode($r['snapshot'], true);
        if (is_array($data)) {
            return $data;
        }
    }
    return report_build_data((int)$r['department_id'], (int)$r['academic_year_id'], $r['period_start'], $r['period_end']);
}

/** จำนวนรายงานที่รอผู้ใช้คนนี้พิจารณา */
function report_pending_count(int $userId): int
{
    $steps = report_user_steps($userId);
    if (!$steps) {
        return 0;
    }
    $in = implode(',', array_fill(0, count($steps), '?'));
    return (int)db_value(
        "SELECT COUNT(*) FROM supervision_reports WHERE status = 'pending' AND current_step IN ($in)",
        str_repeat('i', count($steps)),
        $steps
    );
}

/** ชื่อการกระทำ */
function report_action_label(string $action, int $step): string
{
    if ($action === 'submit') {
        return 'เสนอรายงาน';
    }
    if ($action === 'return') {
        return 'ส่งกลับแก้ไข';
    }
    return $step >= 3 ? 'ทราบ/อนุมัติ' : 'เห็นควรเสนอขั้นถัดไป';
}
