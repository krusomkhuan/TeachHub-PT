<?php
/**
 * ไฟล์: includes/reports.php
 * ฟังก์ชันสร้างข้อมูลรายงาน (แยก 4 งาน) — ใช้ทั้งการแสดงผล กราฟ และส่งออก CSV
 *
 * ตัวกรอง ($f):
 *   ay       = id ภาคเรียน (0 = ทั้งหมด)
 *   round_id = id รอบการส่ง (0 = ทั้งหมด) — เฉพาะงานที่ต้องส่งไฟล์
 *   dept     = id แผนก (0 = ทั้งหมด)
 * การกรองสถานะรายคนทำด้วย report_filter() หลังคำนวณสรุปแล้ว
 */

/** ตัวเลือกสถานะรายคนของแต่ละรายงาน */
function report_status_options(string $type): array
{
    if ($type === 'supervision') {
        return ['done' => 'นิเทศแล้ว', 'scheduled' => 'มีตารางแต่ยังไม่นิเทศ', 'none' => 'ยังไม่มีตารางนิเทศ'];
    }
    if ($type === 'student_eval') {
        return ['done' => 'นักเรียนประเมินแล้ว', 'scheduled' => 'มีแบบประเมินแต่ยังไม่มีผู้ตอบ', 'none' => 'ยังไม่มีแบบประเมิน'];
    }
    return [
        'submitted'     => 'ส่งแล้ว',
        'not_submitted' => 'ยังไม่ส่ง',
        'late'          => 'ส่งช้า',
        'approved'      => 'ผ่าน',
        'revision'      => 'ให้แก้ไข',
        'pending'       => 'รอตรวจ',
    ];
}

/**
 * สถานะรวมของครู 1 คน จากงานหลายรายการ
 * ไม่ส่ง → not_submitted, มีให้แก้ไข → revision, มีรอตรวจ → pending, ที่เหลือ → approved
 */
function report_person_status(array $r): string
{
    if ((int)$r['sent'] === 0) {
        return 'not_submitted';
    }
    if ((int)$r['revision'] > 0) {
        return 'revision';
    }
    if ((int)$r['pending'] > 0) {
        return 'pending';
    }
    return 'approved';
}

/**
 * รายงานงานที่ต้องส่ง (แผนการสอน / PA / วิจัย) รายคน
 * @return array แถวละครู: id, teacher_name, department_name, sent, approved, revision, pending, late, last_at, person_status
 */
function report_submission_rows(string $type, array $f): array
{
    $table = submission_table($type);
    // รายงานแผนการสอน: ถ้าไม่ระบุรอบ นับเฉพาะแผนการจัดการเรียนรู้ (ไม่รวมโครงการสอน) — เป็นข้อความคงที่
    $docSql = ($type === 'lesson_plan' && empty($f['round_id'])) ? " AND x.doc_type = 'plan'" : '';
    $rows = db_all(
        "SELECT u.id, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name, u.department_id,
                COALESCE(d.name, '-') AS department_name,
                COUNT(s.id) AS sent,
                COALESCE(SUM(s.status = 'approved'), 0) AS approved,
                COALESCE(SUM(s.status = 'revision'), 0) AS revision,
                COALESCE(SUM(s.status = 'pending'), 0)  AS pending,
                COALESCE(SUM(s.is_late = 1), 0)         AS late,
                MAX(s.submitted_at) AS last_at
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN (
            SELECT x.* FROM `$table` x JOIN submission_rounds r ON r.id = x.round_id
            WHERE (? = 0 OR x.round_id = ?) AND (? = 0 OR r.academic_year_id = ?)$docSql
         ) s ON s.teacher_id = u.id
         WHERE u.status = 'active' AND u.role IN ('teacher','supervisor') AND (? = 0 OR u.department_id = ?)
         GROUP BY u.id, u.prefix, u.first_name, u.last_name, u.department_id, d.name
         ORDER BY d.name, u.first_name",
        'iiiiii',
        [$f['round_id'], $f['round_id'], $f['ay'], $f['ay'], $f['dept'], $f['dept']]
    );
    foreach ($rows as &$r) {
        $r['person_status'] = report_person_status($r);
    }
    unset($r);
    return $rows;
}

/**
 * กรองแถวรายคนตามสถานะ (ทำหลังคำนวณสรุป เพื่อให้ร้อยละคิดจากครูทั้งหมด)
 */
function report_filter(string $type, array $rows, string $status): array
{
    if ($status === '') {
        return $rows;
    }
    return array_values(array_filter($rows, function ($r) use ($type, $status) {
        $isEval = in_array($type, ['supervision', 'student_eval'], true);
        if (!$isEval && $status === 'submitted') {
            return (int)$r['sent'] > 0;
        }
        if (!$isEval && $status === 'late') {
            return (int)$r['late'] > 0;
        }
        return $r['person_status'] === $status;
    }));
}

/** สรุปจำนวนและร้อยละจากแถวรายคน (ก่อนกรองสถานะ) */
function report_submission_summary(array $rows): array
{
    $total = count($rows);
    $s = ['total' => $total, 'submitted' => 0, 'not_submitted' => 0, 'late' => 0, 'approved' => 0, 'revision' => 0, 'pending' => 0, 'files' => 0];
    foreach ($rows as $r) {
        $s['files'] += (int)$r['sent'];
        if ((int)$r['sent'] > 0) {
            $s['submitted']++;
        }
        if ((int)$r['late'] > 0) {
            $s['late']++;
        }
        $s[$r['person_status']]++;
    }
    foreach (['submitted', 'not_submitted', 'late', 'approved', 'revision', 'pending'] as $k) {
        $s[$k . '_pct'] = percent($s[$k], $total);
    }
    return $s;
}

/** จัดกลุ่มแถวรายคนตามแผนก (สำหรับกราฟ) */
function report_group_by_department(array $rows, string $statusKey = 'person_status'): array
{
    $out = [];
    foreach ($rows as $r) {
        $d = preg_replace('/^แผนกวิชา/u', '', $r['department_name']);
        $out[$d][$r[$statusKey]] = ($out[$d][$r[$statusKey]] ?? 0) + 1;
        $out[$d]['_total'] = ($out[$d]['_total'] ?? 0) + 1;
    }
    return $out;
}

/**
 * รายงานนิเทศการสอนรายคน
 * @return array แถว: id, teacher_name, department_name, scheduled, done, acked, avg_score, last_date, person_status
 */
function report_supervision_rows(array $f): array
{
    $rows = db_all(
        "SELECT u.id, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name, COALESCE(d.name, '-') AS department_name,
                COUNT(sc.id) AS scheduled, COUNT(res.id) AS done,
                COALESCE(SUM(res.acknowledged_at IS NOT NULL), 0) AS acked,
                ROUND(AVG(res.avg_score), 2) AS avg_score, MAX(sc.supervise_date) AS last_date
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN supervision_schedules sc ON sc.teacher_id = u.id AND sc.status <> 'cancelled' AND (? = 0 OR sc.academic_year_id = ?)
         LEFT JOIN supervision_results res ON res.schedule_id = sc.id
         WHERE u.status = 'active' AND u.role IN ('teacher','supervisor') AND (? = 0 OR u.department_id = ?)
         GROUP BY u.id, u.prefix, u.first_name, u.last_name, d.name
         ORDER BY d.name, u.first_name",
        'iiii',
        [$f['ay'], $f['ay'], $f['dept'], $f['dept']]
    );
    foreach ($rows as &$r) {
        $r['person_status'] = (int)$r['done'] > 0 ? 'done' : ((int)$r['scheduled'] > 0 ? 'scheduled' : 'none');
    }
    unset($r);
    return $rows;
}

/** คะแนนนิเทศเฉลี่ยรายด้าน */
function report_supervision_categories(array $f): array
{
    return db_all(
        "SELECT ss.category, ROUND(AVG(ss.score), 2) AS avg_score, COUNT(*) AS n
         FROM supervision_scores ss
         JOIN supervision_results res ON res.id = ss.result_id
         JOIN supervision_schedules sc ON sc.id = res.schedule_id
         JOIN users u ON u.id = sc.teacher_id
         WHERE (? = 0 OR sc.academic_year_id = ?) AND (? = 0 OR u.department_id = ?)
         GROUP BY ss.category ORDER BY MIN(ss.id)",
        'iiii',
        [$f['ay'], $f['ay'], $f['dept'], $f['dept']]
    );
}

/**
 * รายงานการประเมินการสอนโดยนักเรียน รายคน
 * @return array แถว: id, teacher_name, department_name, scheduled (จำนวนแบบประเมิน), done (มีผู้ตอบ 1 = ใช่),
 *               responses (จำนวนนักเรียนที่ตอบ), avg_score, last_date, person_status
 */
function report_student_eval_rows(array $f): array
{
    $rows = db_all(
        "SELECT u.id, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name, COALESCE(d.name, '-') AS department_name,
                COUNT(DISTINCT s.id) AS scheduled, COUNT(r.id) AS responses,
                ROUND(AVG(r.avg_score), 2) AS avg_score, MAX(s.close_at) AS last_date
         FROM users u
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN student_eval_sessions s ON s.teacher_id = u.id AND (? = 0 OR s.academic_year_id = ?)
              AND (? = '' OR s.level = ?)
         LEFT JOIN student_eval_responses r ON r.session_id = s.id
         WHERE u.status = 'active' AND u.role IN ('teacher','supervisor') AND (? = 0 OR u.department_id = ?)
         GROUP BY u.id, u.prefix, u.first_name, u.last_name, d.name
         ORDER BY d.name, u.first_name",
        'iissii',
        [$f['ay'], $f['ay'], $f['level'] ?? '', $f['level'] ?? '', $f['dept'], $f['dept']]
    );
    foreach ($rows as &$r) {
        $r['done'] = (int)$r['responses'] > 0 ? 1 : 0;
        $r['person_status'] = $r['done'] ? 'done' : ((int)$r['scheduled'] > 0 ? 'scheduled' : 'none');
    }
    unset($r);
    return $rows;
}

/** คะแนนเฉลี่ยรายด้านจากการประเมินโดยนักเรียน */
function report_student_eval_categories(array $f): array
{
    return db_all(
        "SELECT sc.category, ROUND(AVG(sc.score), 2) AS avg_score, COUNT(*) AS n
         FROM student_eval_scores sc
         JOIN student_eval_responses r ON r.id = sc.response_id
         JOIN student_eval_sessions s ON s.id = r.session_id
         JOIN users u ON u.id = s.teacher_id
         WHERE (? = 0 OR s.academic_year_id = ?) AND (? = 0 OR u.department_id = ?) AND (? = '' OR s.level = ?)
         GROUP BY sc.category ORDER BY MIN(sc.id)",
        'iiiiss',
        [$f['ay'], $f['ay'], $f['dept'], $f['dept'], $f['level'] ?? '', $f['level'] ?? '']
    );
}

/**
 * สรุปการประเมินโดยนักเรียนแยกตามระดับ ปวช./ปวส. และชั้นปี
 * @return array แถว: level, grade_year, sessions, responses, avg_score
 */
function report_student_eval_by_level(array $f): array
{
    return db_all(
        "SELECT COALESCE(s.level, 'ไม่ระบุ') AS level, s.grade_year, COUNT(DISTINCT s.id) AS sessions,
                COUNT(r.id) AS responses, ROUND(AVG(r.avg_score), 2) AS avg_score
         FROM student_eval_sessions s
         JOIN users u ON u.id = s.teacher_id
         LEFT JOIN student_eval_responses r ON r.session_id = s.id
         WHERE (? = 0 OR s.academic_year_id = ?) AND (? = 0 OR u.department_id = ?) AND (? = '' OR s.level = ?)
         GROUP BY s.level, s.grade_year
         ORDER BY s.level IS NULL, s.level, s.grade_year",
        'iiiiss',
        [$f['ay'], $f['ay'], $f['dept'], $f['dept'], $f['level'] ?? '', $f['level'] ?? '']
    );
}

/** คะแนนนิเทศเฉลี่ยรายแผนก (จากแถวรายคน) */
function report_supervision_departments(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $d = preg_replace('/^แผนกวิชา/u', '', $r['department_name']);
        $out[$d] = $out[$d] ?? ['teachers' => 0, 'done' => 0, 'sum' => 0.0, 'n' => 0];
        $out[$d]['teachers']++;
        if ((int)$r['done'] > 0) {
            $out[$d]['done']++;
            $out[$d]['sum'] += (float)$r['avg_score'];
            $out[$d]['n']++;
        }
    }
    foreach ($out as &$o) {
        $o['avg'] = $o['n'] ? round($o['sum'] / $o['n'], 2) : null;
    }
    unset($o);
    return $out;
}
