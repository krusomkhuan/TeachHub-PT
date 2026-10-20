<?php
/**
 * ไฟล์: includes/home_visit.php
 * ฟังก์ชันกลางของ "รายงานการเยี่ยมบ้านนักเรียน" ของครูที่ปรึกษา
 * - นักเรียน 1 คน เยี่ยมบ้าน 1 ครั้งต่อปีการศึกษา (ตรวจซ้ำด้วยรหัสนักเรียน) ไม่กำหนดเป้าจำนวน
 * - ครูเลือกกลุ่มจากกลุ่มที่ปรึกษาของตนเองในปีการศึกษานั้น (advisor_groups) แล้วพิมพ์รหัส/ชื่อนักเรียน
 * - ผู้ตรวจ/สิทธิ์การดู ใช้ชุดเดียวกับรายงานครูที่ปรึกษา (includes/advisor.php)
 */

const HOME_VISIT_MAX_IMAGES = 3;

function home_visit_risk_labels(): array
{
    return ['normal' => 'ปกติ', 'risk' => 'กลุ่มเสี่ยง', 'problem' => 'กลุ่มมีปัญหา'];
}

function home_visit_risk_badge(string $risk): string
{
    $cls = ['normal' => 'bg-success', 'risk' => 'bg-warning text-dark', 'problem' => 'bg-danger'][$risk] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . e(home_visit_risk_labels()[$risk] ?? $risk) . '</span>';
}

function home_visit_economic_labels(): array
{
    return ['sufficient' => 'พอเพียง', 'insufficient' => 'ไม่พอเพียง', 'poor' => 'ขัดสน'];
}

/** ตัวเลือก "นักเรียนอาศัยอยู่กับ" */
function home_visit_living_options(): array
{
    return ['บิดาและมารดา', 'บิดา', 'มารดา', 'ญาติ', 'ผู้ปกครองอื่น', 'อยู่ลำพัง/หอพัก'];
}

/** ปีการศึกษาทั้งหมด (พ.ศ.) ใหม่สุดก่อน */
function home_visit_years(): array
{
    return array_map('intval', array_column(db_all('SELECT DISTINCT year FROM academic_years ORDER BY year DESC'), 'year'));
}

/** ปีการศึกษาจาก ?year= (ไม่ระบุ/ไม่มีในระบบ = ปีของภาคเรียนปัจจุบัน) */
function home_visit_year(int $year): int
{
    $years = home_visit_years();
    if ($year > 0 && in_array($year, $years, true)) {
        return $year;
    }
    return (int)(current_academic_year()['year'] ?? ($years[0] ?? thai_year()));
}

/** ช่วงวันที่ของปีการศึกษา [วันเปิดภาคแรก, วันปิดภาคสุดท้าย] (ไม่ได้กำหนดวันที่ = null) */
function home_visit_year_range(int $year): ?array
{
    $r = db_one('SELECT MIN(start_date) AS s, MAX(end_date) AS e FROM academic_years WHERE year = ?', 'i', [$year]);
    return ($r && $r['s'] && $r['e']) ? [$r['s'], $r['e']] : null;
}

/** กลุ่มที่ปรึกษาของครูในปีการศึกษา (ทุกภาคเรียนของปีนั้น) */
function home_visit_teacher_groups(int $teacherId, int $year): array
{
    return db_all(
        'SELECT g.*, ay.semester, d.name AS department_name
         FROM advisor_groups g JOIN academic_years ay ON ay.id = g.academic_year_id LEFT JOIN departments d ON d.id = g.department_id
         WHERE g.teacher_id = ? AND ay.year = ? ORDER BY g.group_name, ay.semester',
        'ii',
        [$teacherId, $year]
    );
}

/** รายงานเยี่ยมบ้าน 1 รายการ พร้อมข้อมูลกลุ่ม ครู ผู้ตรวจ */
function home_visit_fetch(int $id): ?array
{
    $v = db_one(
        "SELECT v.*, g.group_name, g.department_id, ay.semester,
                u.prefix, u.first_name, u.last_name, u.email AS teacher_email, u.phone AS teacher_phone,
                d.name AS department_name, CONCAT(rv.prefix, rv.first_name, ' ', rv.last_name) AS reviewer_name
         FROM home_visits v
         JOIN advisor_groups g ON g.id = v.group_id
         JOIN academic_years ay ON ay.id = g.academic_year_id
         JOIN users u ON u.id = v.teacher_id
         LEFT JOIN departments d ON d.id = g.department_id
         LEFT JOIN users rv ON rv.id = v.reviewed_by
         WHERE v.id = ?",
        'i',
        [$id]
    );
    if ($v) {
        $v['teacher_name'] = full_name($v);
    }
    return $v;
}

function home_visit_images(int $visitId): array
{
    return db_all('SELECT * FROM home_visit_images WHERE visit_id = ? ORDER BY id', 'i', [$visitId]);
}

/** ตรวจได้หรือไม่ — เงื่อนไขเดียวกับรายงานครูที่ปรึกษา (admin ทุกฉบับ / งานครูที่ปรึกษาฯ ยกเว้นของตนเอง) */
function can_review_home_visit(array $user, array $visit): bool
{
    return can_review_advisor_report($user, $visit);
}

function home_visit_pending_count(int $excludeTeacherId = 0): int
{
    return (int)db_value("SELECT COUNT(*) FROM home_visits WHERE status = 'pending' AND teacher_id <> ?", 'i', [$excludeTeacherId]);
}

/** ลบรายงานพร้อมไฟล์ภาพ */
function home_visit_delete(array $visit): void
{
    $images = home_visit_images((int)$visit['id']);
    db_exec('DELETE FROM home_visits WHERE id = ?', 'i', [(int)$visit['id']]);
    foreach ($images as $im) {
        delete_upload('home_visit', $im['stored_name']);
    }
}

/**
 * สรุปการเยี่ยมบ้านรายครูในปีการศึกษา
 * นักเรียนในที่ปรึกษา = ผลรวมจำนวนนักเรียนของแต่ละชื่อกลุ่ม (กลุ่มเดียวกันหลายภาคเรียนนับครั้งเดียว ใช้ค่ามากสุด)
 * @return array ['teachers' => [teacher_id => [...]], 'totals' => [...], 'by_month' => ['Y-m' => n]]
 */
function home_visit_summary(int $year, int $deptId = 0, int $teacherId = 0): array
{
    $empty = ['students' => 0, 'visits' => 0, 'approved' => 0, 'pending' => 0, 'revision' => 0, 'normal' => 0, 'risk' => 0, 'problem' => 0];
    $out = ['teachers' => [], 'totals' => $empty + ['teachers' => 0, 'visit_pct' => 0.0], 'by_month' => []];
    $groups = db_all(
        "SELECT g.teacher_id, g.group_name, MAX(g.student_count) AS students, u.prefix, u.first_name, u.last_name,
                MAX(d.name) AS department_name
         FROM advisor_groups g JOIN academic_years ay ON ay.id = g.academic_year_id
         JOIN users u ON u.id = g.teacher_id LEFT JOIN departments d ON d.id = g.department_id
         WHERE ay.year = ? AND (? = 0 OR g.department_id = ?) AND (? = 0 OR g.teacher_id = ?)
         GROUP BY g.teacher_id, g.group_name, u.prefix, u.first_name, u.last_name
         ORDER BY department_name, u.first_name, g.group_name",
        'iiiii',
        [$year, $deptId, $deptId, $teacherId, $teacherId]
    );
    foreach ($groups as $g) {
        $tid = (int)$g['teacher_id'];
        if (!isset($out['teachers'][$tid])) {
            $out['teachers'][$tid] = $empty + ['teacher_id' => $tid, 'teacher_name' => full_name($g),
                'department_name' => $g['department_name'] ?? '-', 'group_names' => []];
        }
        $out['teachers'][$tid]['students'] += (int)$g['students'];
        $out['teachers'][$tid]['group_names'][] = $g['group_name'];
    }
    if ($out['teachers']) {
        $ids = array_keys($out['teachers']);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = db_all(
            "SELECT v.teacher_id, v.status, v.risk_level, v.visit_date FROM home_visits v JOIN advisor_groups g ON g.id = v.group_id
             WHERE v.school_year = ? AND (? = 0 OR g.department_id = ?) AND v.teacher_id IN ($in)",
            'iii' . str_repeat('i', count($ids)),
            array_merge([$year, $deptId, $deptId], $ids)
        );
        foreach ($rows as $r) {
            $t = &$out['teachers'][(int)$r['teacher_id']];
            $t['visits']++;
            $t[$r['status']]++;
            $t[$r['risk_level']]++;
            unset($t);
            $m = substr($r['visit_date'], 0, 7);
            $out['by_month'][$m] = ($out['by_month'][$m] ?? 0) + 1;
        }
        ksort($out['by_month']);
    }
    foreach ($out['teachers'] as &$t) {
        $t['visit_pct'] = percent($t['visits'], $t['students']);
        foreach (array_keys($empty) as $k) {
            $out['totals'][$k] += $t[$k];
        }
        $out['totals']['teachers']++;
    }
    unset($t);
    $out['totals']['visit_pct'] = percent($out['totals']['visits'], $out['totals']['students']);
    return $out;
}

/** ข้อความเดือนไทยจาก Y-m เช่น พ.ย. 2569 */
function home_visit_month_label(string $ym): string
{
    [$y, $m] = array_map('intval', explode('-', $ym));
    return thai_months()[$m] . ' ' . ($y + 543);
}
