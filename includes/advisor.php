<?php
/**
 * ไฟล์: includes/advisor.php
 * ฟังก์ชันกลางของ "รายงานการพบนักเรียนของครูที่ปรึกษา"
 * - ครูที่ปรึกษาส่งรายงาน 1 ครั้งต่อกลุ่มเรียน ต่อ 1 สัปดาห์ (จันทร์–อาทิตย์) เลือกวันที่พบนักเรียนได้ทุกวัน
 *   สัปดาห์อ้างอิงด้วยวันพุธของสัปดาห์ (advisor_week_wed) · ส่งย้อนหลังได้ แต่เลยกำหนดจะติดป้าย "ส่งช้า"
 * - ผู้ตรวจ: หัวหน้างาน/เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว (กำหนดที่ช่อง "ตำแหน่ง" ของผู้ใช้) และ admin
 * - วันพุธที่งดพบ (advisor_skip_dates) ไม่นับเป็นสัปดาห์ที่ต้องส่ง
 */

/** ภาพถ่ายประกอบรายงาน: จำนวนสูงสุด / ขนาดสูงสุดต่อรูป */
const ADVISOR_MAX_IMAGES = 3;
const ADVISOR_MAX_IMAGE_BYTES = 5 * 1024 * 1024;

/**
 * ตำแหน่งผู้ตรวจรายงานครูที่ปรึกษา (แก้ได้เฉพาะผู้ดูแลระบบที่หน้าแก้ไขผู้ใช้)
 * ใช้แนวทางเดียวกับผู้ตรวจงานตามตำแหน่ง (reviewer_positions) ไม่ต้องเพิ่ม role ใหม่
 */
function advisor_staff_positions(): array
{
    return ['หัวหน้างานครูที่ปรึกษาและแนะแนว', 'เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว'];
}

/** ผู้ใช้เป็นหัวหน้างาน/เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนวหรือไม่ */
function is_advisor_staff(array $user): bool
{
    if (($user['status'] ?? 'active') !== 'active' || ($user['role'] ?? '') === 'executive') {
        return false;
    }
    return in_array(normalize_position((string)($user['position'] ?? '')), advisor_staff_positions(), true);
}

/** ตำแหน่งที่เห็นเฉพาะเมนูงานครูที่ปรึกษา (ไม่เห็นเมนูส่งงาน/นิเทศของครู) */
const ADVISOR_STAFF_ONLY_POSITION = 'เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว';

/** เจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว (ที่ไม่ใช่ admin) — เมนูและหน้าแรกเป็นงานครูที่ปรึกษาอย่างเดียว */
function is_advisor_staff_only(array $user): bool
{
    return ($user['role'] ?? '') !== 'admin' && is_advisor_staff($user)
        && normalize_position((string)($user['position'] ?? '')) === ADVISOR_STAFF_ONLY_POSITION;
}

/** จัดการกลุ่มที่ปรึกษา วันงดพบ และตรวจรายงานได้ (admin + งานครูที่ปรึกษาฯ) */
function can_manage_advisor(array $user): bool
{
    return $user['role'] === 'admin' || is_advisor_staff($user);
}

/** กำหนด/ยกเลิกวันงดพบได้ — เฉพาะหัวหน้างานและเจ้าหน้าที่งานครูที่ปรึกษาและแนะแนว (admin ดูได้อย่างเดียว) */
function can_manage_advisor_skip(array $user): bool
{
    return is_advisor_staff($user);
}

/** ดูสรุปรายงานทั้งวิทยาลัยได้ (ผู้ตรวจ + ผู้บริหาร) — หัวหน้าแผนกดูได้เฉพาะแผนกตนเอง */
function can_view_advisor_overview(array $user): bool
{
    return can_manage_advisor($user) || in_array($user['role'], ['executive', 'supervisor'], true);
}

/** ส่งได้ภายในกี่วันหลังวันพุธโดยไม่นับว่าส่งช้า */
function advisor_late_days(): int
{
    return max(0, min(6, (int)setting('advisor_late_days', '2')));
}

/** วันสุดท้ายที่ส่งได้โดยไม่นับว่าช้า (Y-m-d) */
function advisor_deadline(string $meetDate): string
{
    return date('Y-m-d', strtotime($meetDate . ' +' . advisor_late_days() . ' day'));
}

/** ส่ง ณ วันนี้ถือว่าส่งช้าหรือไม่ */
function advisor_is_late_now(string $meetDate): bool
{
    return date('Y-m-d') > advisor_deadline($meetDate);
}

/** ภาคเรียนตาม id (ไม่พบ/ไม่ระบุ = ภาคเรียนปัจจุบัน) */
function advisor_academic_year(int $ayId): ?array
{
    return $ayId > 0 ? db_one('SELECT * FROM academic_years WHERE id = ?', 'i', [$ayId]) : current_academic_year();
}

/** วันงดพบในช่วงวันที่ [วันที่ => เหตุผล] */
function advisor_skip_dates(string $from, string $to): array
{
    $out = [];
    foreach (db_all('SELECT skip_date, reason FROM advisor_skip_dates WHERE skip_date BETWEEN ? AND ? ORDER BY skip_date', 'ss', [$from, $to]) as $r) {
        $out[$r['skip_date']] = $r['reason'];
    }
    return $out;
}

/**
 * วันพุธทั้งหมดของภาคเรียน [วันที่ => เหตุผลงดพบ หรือ null]
 * @param bool $untilToday true = เฉพาะวันพุธที่ถึงแล้ว (ใช้คำนวณขาดส่ง)
 */
function advisor_wednesdays(?array $ay, bool $untilToday = true): array
{
    if (!$ay || empty($ay['start_date']) || empty($ay['end_date'])) {
        return [];
    }
    $end = $ay['end_date'];
    if ($untilToday && $end > date('Y-m-d')) {
        $end = date('Y-m-d');
    }
    if ($end < $ay['start_date']) {
        return [];
    }
    $skips = advisor_skip_dates($ay['start_date'], $end);
    $out = [];
    $ts = strtotime($ay['start_date']);
    $ts = strtotime('+' . ((3 - (int)date('N', $ts) + 7) % 7) . ' day', $ts); // วันพุธแรก
    for (; date('Y-m-d', $ts) <= $end; $ts = strtotime('+7 day', $ts)) {
        $d = date('Y-m-d', $ts);
        $out[$d] = $skips[$d] ?? null;
    }
    return $out;
}

/**
 * วันพุธของสัปดาห์ (จันทร์–อาทิตย์) ที่วันที่นี้อยู่ — ใช้จับคู่รายงานกับสัปดาห์ที่ต้องส่ง
 * ครูเลือกวันที่พบนักเรียนได้ทุกวัน แต่ส่งได้ 1 รายงานต่อกลุ่มต่อสัปดาห์
 */
function advisor_week_wed(string $date): string
{
    $ts = strtotime($date);
    return date('Y-m-d', strtotime((3 - (int)date('N', $ts)) . ' day', $ts));
}

/** ช่วงวันของสัปดาห์ [วันจันทร์, วันอาทิตย์] ของวันที่นี้ */
function advisor_week_range(string $date): array
{
    $wed = strtotime(advisor_week_wed($date));
    return [date('Y-m-d', strtotime('-2 day', $wed)), date('Y-m-d', strtotime('+4 day', $wed))];
}

/** รายงานที่ส่งแล้วของกลุ่มในสัปดาห์เดียวกับวันที่นี้ (id หรือ null) */
function advisor_week_report_id(int $groupId, string $date, int $exceptId = 0): ?int
{
    [$mon, $sun] = advisor_week_range($date);
    $id = db_value('SELECT id FROM advisor_reports WHERE group_id = ? AND meet_date BETWEEN ? AND ? AND id <> ? LIMIT 1',
        'issi', [$groupId, $mon, $sun, $exceptId]);
    return $id ? (int)$id : null;
}

/** วันพุธที่ต้องส่งรายงาน (ถึงแล้ว และไม่ใช่วันงดพบ) */
function advisor_due_dates(?array $ay): array
{
    return array_keys(array_filter(advisor_wednesdays($ay), fn($reason) => $reason === null));
}

/**
 * ตรวจว่าส่งรายงานของวันที่นี้ได้หรือไม่
 * @return string ข้อความเหตุผล (ว่าง = ส่งได้)
 */
function advisor_date_block_reason(?array $ay, string $date): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || date('Y-m-d', strtotime($date)) !== $date) {
        return 'วันที่ไม่ถูกต้อง';
    }
    if (!$ay || empty($ay['start_date']) || empty($ay['end_date'])) {
        return 'ภาคเรียนนี้ยังไม่ได้กำหนดวันเปิด-ปิดภาคเรียน กรุณาแจ้งผู้ดูแลระบบ';
    }
    if ($date < $ay['start_date'] || $date > $ay['end_date']) {
        return 'วันที่อยู่นอกภาคเรียน ' . academic_label($ay);
    }
    if ($date > date('Y-m-d')) {
        return 'ยังไม่ถึงวันที่เลือก (บันทึกได้เฉพาะวันที่ผ่านมาแล้วหรือวันนี้)';
    }
    $skip = db_value('SELECT reason FROM advisor_skip_dates WHERE skip_date = ?', 's', [$date]);
    if ($skip !== null) {
        return 'วันที่ ' . thai_date($date) . ' เป็นวันงดพบ' . ($skip !== '' ? ' (' . $skip . ')' : '');
    }
    return '';
}

/**
 * ครูที่ปรึกษาร่วม: ครูคนอื่นที่มีกลุ่มเรียนชื่อเดียวกันในภาคเรียนเดียวกัน
 * (1 กลุ่มเรียนมีครูที่ปรึกษาหลายคนได้ — เก็บเป็น advisor_groups คนละแถว แต่ละคนส่งรายงานของตนเอง)
 * @return string[] ชื่อเต็มของครู
 */
function advisor_co_advisors(int $ayId, string $groupName, int $exceptTeacherId = 0): array
{
    return array_map('full_name', db_all(
        'SELECT u.prefix, u.first_name, u.last_name FROM advisor_groups g JOIN users u ON u.id = g.teacher_id
         WHERE g.academic_year_id = ? AND g.group_name = ? AND g.teacher_id <> ? ORDER BY u.first_name',
        'isi',
        [$ayId, $groupName, $exceptTeacherId]
    ));
}

/** กลุ่มที่ปรึกษา 1 กลุ่ม พร้อมชื่อครู แผนก และภาคเรียน */
function advisor_group_fetch(int $id): ?array
{
    $g = db_one(
        "SELECT g.*, u.prefix, u.first_name, u.last_name, u.department_id AS teacher_department_id,
                d.name AS department_name, ay.year AS ay_year, ay.semester AS ay_semester
         FROM advisor_groups g
         JOIN users u ON u.id = g.teacher_id
         LEFT JOIN departments d ON d.id = g.department_id
         JOIN academic_years ay ON ay.id = g.academic_year_id
         WHERE g.id = ?",
        'i',
        [$id]
    );
    if ($g) {
        $g['teacher_name'] = full_name($g);
    }
    return $g;
}

/** กลุ่มที่ปรึกษาของครูในภาคเรียน */
function advisor_teacher_groups(int $teacherId, int $ayId): array
{
    return db_all(
        'SELECT g.*, d.name AS department_name FROM advisor_groups g LEFT JOIN departments d ON d.id = g.department_id
         WHERE g.teacher_id = ? AND g.academic_year_id = ? ORDER BY g.group_name',
        'ii',
        [$teacherId, $ayId]
    );
}

/** ครูคนนี้เคยได้รับมอบหมายเป็นครูที่ปรึกษาหรือไม่ (ใช้แสดงเมนู) */
function is_advisor_teacher(int $teacherId): bool
{
    return (bool)db_value('SELECT 1 FROM advisor_groups WHERE teacher_id = ? LIMIT 1', 'i', [$teacherId]);
}

/** รายงาน 1 รายการ พร้อมข้อมูลกลุ่ม ครู ผู้ตรวจ */
function advisor_report_fetch(int $id): ?array
{
    $r = db_one(
        "SELECT r.*, g.group_name, g.academic_year_id, g.department_id, g.student_count,
                u.prefix, u.first_name, u.last_name, u.email AS teacher_email, u.phone AS teacher_phone,
                u.department_id AS teacher_department_id,
                d.name AS department_name, ay.year AS ay_year, ay.semester AS ay_semester,
                CONCAT(rv.prefix, rv.first_name, ' ', rv.last_name) AS reviewer_name
         FROM advisor_reports r
         JOIN advisor_groups g ON g.id = r.group_id
         JOIN users u ON u.id = r.teacher_id
         LEFT JOIN departments d ON d.id = g.department_id
         JOIN academic_years ay ON ay.id = g.academic_year_id
         LEFT JOIN users rv ON rv.id = r.reviewed_by
         WHERE r.id = ?",
        'i',
        [$id]
    );
    if ($r) {
        $r['teacher_name'] = full_name($r);
    }
    return $r;
}

/** ภาพถ่ายของรายงาน */
function advisor_report_images(int $reportId): array
{
    return db_all('SELECT * FROM advisor_report_images WHERE report_id = ? ORDER BY id', 'i', [$reportId]);
}

/**
 * ดูข้อมูลรายงาน/สรุปของครูคนนี้ได้หรือไม่
 * - ตนเอง, admin, งานครูที่ปรึกษาฯ, ผู้บริหาร : ได้
 * - หัวหน้าแผนก : เฉพาะครูในแผนกตนเอง
 */
function can_view_advisor_teacher(array $user, int $teacherId): bool
{
    if ((int)$user['id'] === $teacherId || can_manage_advisor($user) || $user['role'] === 'executive') {
        return true;
    }
    return can_view_teacher($user, $teacherId);
}

/** ผู้ใช้ตรวจรายงานนี้ได้หรือไม่ (admin ทุกฉบับ / งานครูที่ปรึกษาฯ ยกเว้นของตนเอง) */
function can_review_advisor_report(array $user, array $report): bool
{
    if ($user['role'] === 'admin') {
        return true;
    }
    return is_advisor_staff($user) && (int)$report['teacher_id'] !== (int)$user['id'];
}

/**
 * ผู้ใช้ลบรายงานของครูได้หรือไม่ (ใช้ทั้งรายงานกิจกรรมโฮมรูมและรายงานการเยี่ยมบ้าน)
 * - admin ลบได้ทุกฉบับ / หัวหน้างานและเจ้าหน้าที่งานครูที่ปรึกษาฯ ลบได้ทุกฉบับยกเว้นของตนเอง (ครูลบเองที่หน้าครู)
 */
function can_delete_advisor_report(array $user, array $report): bool
{
    return can_review_advisor_report($user, $report);
}

/** จำนวนรายงานที่รอตรวจ (ไม่นับของตนเอง) */
function advisor_pending_count(int $excludeTeacherId = 0): int
{
    return (int)db_value("SELECT COUNT(*) FROM advisor_reports WHERE status = 'pending' AND teacher_id <> ?", 'i', [$excludeTeacherId]);
}

/** ลบรายงานพร้อมรูปภาพ */
function advisor_report_delete(array $report): void
{
    $images = advisor_report_images((int)$report['id']);
    db_exec('DELETE FROM advisor_reports WHERE id = ?', 'i', [(int)$report['id']]); // รูปในฐานข้อมูลลบตาม (CASCADE)
    foreach ($images as $im) {
        delete_upload('advisor', $im['stored_name']);
    }
}

/**
 * สรุปการส่งรายงานรายครูในภาคเรียน
 * @return array [
 *   'weeks'    => วันพุธที่ต้องส่ง,
 *   'teachers' => [teacher_id => [ชื่อ, แผนก, groups, expected, submitted, on_time, late, missing, approved, pending, revision,
 *                                 students, present, late_students, leave, absent, attend_pct, submit_pct]],
 *   'by_week'  => [วันที่ => ['expected' => n, 'submitted' => n]],
 *   'totals'   => ผลรวมของทุกครู + teachers/groups
 * ]
 */
function advisor_summary(?array $ay, int $deptId = 0, int $teacherId = 0, ?array $range = null): array
{
    $weeks = advisor_due_dates($ay);
    if ($range) {
        // จำกัดช่วงเวลา (รายสัปดาห์/รายเดือน) — สัปดาห์นับตามวันพุธของสัปดาห์
        $weeks = array_values(array_filter($weeks, fn($w) => $w >= $range[0] && $w <= $range[1]));
    }
    $empty = ['groups' => 0, 'expected' => 0, 'submitted' => 0, 'on_time' => 0, 'late' => 0, 'missing' => 0,
        'approved' => 0, 'pending' => 0, 'revision' => 0, 'students' => 0, 'present' => 0, 'late_students' => 0,
        'leave' => 0, 'absent' => 0];
    $out = ['weeks' => $weeks, 'teachers' => [], 'by_week' => array_fill_keys($weeks, ['expected' => 0, 'submitted' => 0]),
        'totals' => $empty + ['teachers' => 0, 'submit_pct' => 0.0, 'on_time_pct' => 0.0, 'attend_pct' => 0.0]];
    if (!$ay) {
        return $out;
    }
    $groups = db_all(
        "SELECT g.id, g.teacher_id, g.group_name, u.prefix, u.first_name, u.last_name, d.name AS department_name
         FROM advisor_groups g JOIN users u ON u.id = g.teacher_id LEFT JOIN departments d ON d.id = g.department_id
         WHERE g.academic_year_id = ? AND (? = 0 OR g.department_id = ?) AND (? = 0 OR g.teacher_id = ?)
         ORDER BY d.name, u.first_name, g.group_name",
        'iiiii',
        [(int)$ay['id'], $deptId, $deptId, $teacherId, $teacherId]
    );
    if (!$groups) {
        return $out;
    }
    $reports = [];
    if ($weeks) {
        // รายงานลงวันที่ใดก็ได้ในสัปดาห์ → นับเป็นของสัปดาห์นั้น (คีย์ week = วันพุธของสัปดาห์)
        $rows = db_all(
            "SELECT r.group_id, r.meet_date, r.status, r.is_late, r.total_students, r.present_count, r.late_count, r.leave_count, r.absent_count
             FROM advisor_reports r JOIN advisor_groups g ON g.id = r.group_id
             WHERE g.academic_year_id = ? AND r.meet_date BETWEEN ? AND ?",
            'iss',
            [(int)$ay['id'], advisor_week_range($weeks[0])[0], advisor_week_range(end($weeks))[1]]
        );
        $due = array_flip($weeks);
        foreach ($rows as $r) {
            $r['week'] = advisor_week_wed($r['meet_date']);
            if (isset($due[$r['week']])) {
                $reports[(int)$r['group_id']][] = $r;
            }
        }
    }
    foreach ($groups as $g) {
        $tid = (int)$g['teacher_id'];
        if (!isset($out['teachers'][$tid])) {
            $out['teachers'][$tid] = $empty + [
                'teacher_id' => $tid, 'teacher_name' => full_name($g), 'department_name' => $g['department_name'] ?? '-', 'group_names' => [],
            ];
            $out['totals']['teachers']++;
        }
        $t = &$out['teachers'][$tid];
        $t['groups']++;
        $t['group_names'][] = $g['group_name'];
        $t['expected'] += count($weeks);
        foreach ($weeks as $w) {
            $out['by_week'][$w]['expected']++;
        }
        foreach ($reports[(int)$g['id']] ?? [] as $r) {
            $t['submitted']++;
            $t[$r['is_late'] ? 'late' : 'on_time']++;
            $t[$r['status']]++;
            $t['students'] += (int)$r['total_students'];
            $t['present'] += (int)$r['present_count'];
            $t['late_students'] += (int)$r['late_count'];
            $t['leave'] += (int)$r['leave_count'];
            $t['absent'] += (int)$r['absent_count'];
            $out['by_week'][$r['week']]['submitted']++;
        }
        $t['missing'] = $t['expected'] - $t['submitted'];
        unset($t);
    }
    foreach ($out['teachers'] as &$t) {
        $t['submit_pct'] = percent($t['submitted'], $t['expected']);
        $t['attend_pct'] = percent($t['present'] + $t['late_students'], $t['students']);
        foreach (array_keys($empty) as $k) {
            $out['totals'][$k] += $t[$k];
        }
    }
    unset($t);
    $out['totals']['submit_pct'] = percent($out['totals']['submitted'], $out['totals']['expected']);
    $out['totals']['on_time_pct'] = percent($out['totals']['on_time'], $out['totals']['expected']);
    $out['totals']['attend_pct'] = percent($out['totals']['present'] + $out['totals']['late_students'], $out['totals']['students']);
    return $out;
}

/** ข้อความวันที่แบบไทยพร้อมชื่อวันย่อ เช่น พ. 4 พ.ย. 2569, จ. 2 พ.ย. 2569 */
function advisor_date_label(string $date): string
{
    return ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'][(int)date('w', strtotime($date))] . ' ' . thai_date($date);
}

/**
 * ช่วงเวลาของรายงานสรุป: ภาคเรียน (term) / รายเดือน (month = Y-m) / รายสัปดาห์ (week = วันพุธของสัปดาห์)
 * จาก ?period= และ ?month= / ?week= — ค่าที่ไม่อยู่ในภาคเรียนจะใช้ค่าล่าสุดแทน
 * สัปดาห์นับเข้าเดือนตามวันพุธของสัปดาห์
 * @return array ['mode', 'week', 'month', 'range' => [from, to] หรือ null, 'label', 'weeks' => [วันพุธ => เหตุผลงดพบ], 'months' => [Y-m]]
 */
function advisor_period(?array $ay): array
{
    $weeks = advisor_wednesdays($ay); // ถึงวันนี้ รวมวันงดพบ
    $months = array_values(array_unique(array_map(fn($w) => substr($w, 0, 7), array_keys($weeks))));
    $mode = in_array(get('period'), ['week', 'month'], true) ? get('period') : 'term';
    $week = array_key_exists(get('week'), $weeks) ? get('week') : (string)(array_key_last($weeks) ?? ''); // ค่าเป็น null ได้ (ไม่ใช่วันงดพบ)
    $month = in_array(get('month'), $months, true) ? get('month') : (string)(end($months) ?: '');
    $p = ['mode' => $mode, 'week' => $week, 'month' => $month, 'range' => null, 'weeks' => $weeks, 'months' => $months,
        'label' => 'ภาคเรียน ' . academic_label($ay)];
    if ($mode === 'week' && $week !== '') {
        [$mon, $sun] = advisor_week_range($week);
        $p['range'] = [$week, $week];
        $p['label'] = 'สัปดาห์ ' . thai_date($mon) . ' – ' . thai_date($sun);
    } elseif ($mode === 'month' && $month !== '') {
        $p['range'] = [$month . '-01', date('Y-m-t', strtotime($month . '-01'))];
        $p['label'] = 'เดือน' . thai_months(false)[(int)substr($month, 5, 2)] . ' ' . ((int)substr($month, 0, 4) + 543);
    } else {
        $p['mode'] = 'term';
    }
    return $p;
}

/** พารามิเตอร์ช่วงเวลาสำหรับต่อท้ายลิงก์ */
function advisor_period_query(array $p): array
{
    return ['period' => $p['mode'] === 'term' ? null : $p['mode'], 'week' => $p['mode'] === 'week' ? $p['week'] : null,
        'month' => $p['mode'] === 'month' ? $p['month'] : null];
}

/** ช่องเลือกช่วงเวลา (วางในฟอร์ม GET ของหน้ารายงานสรุป) */
function advisor_period_controls(array $p): string
{
    $html = '<div class="col-md-2"><label class="form-label small mb-1" for="period">ช่วงเวลา</label>'
        . '<select name="period" id="period" class="form-select" onchange="TeachHubPeriod(this.value)">';
    foreach (['term' => 'ทั้งภาคเรียน', 'month' => 'รายเดือน', 'week' => 'รายสัปดาห์'] as $k => $label) {
        $html .= '<option value="' . $k . '"' . ($p['mode'] === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select></div><div class="col-md-3' . ($p['mode'] === 'month' ? '' : ' d-none') . '" id="periodMonth">'
        . '<label class="form-label small mb-1" for="month">เดือน</label><select name="month" id="month" class="form-select">';
    foreach (array_reverse($p['months']) as $m) {
        $html .= '<option value="' . e($m) . '"' . ($p['month'] === $m ? ' selected' : '') . '>'
            . e(thai_months(false)[(int)substr($m, 5, 2)] . ' ' . ((int)substr($m, 0, 4) + 543)) . '</option>';
    }
    $html .= '</select></div><div class="col-md-3' . ($p['mode'] === 'week' ? '' : ' d-none') . '" id="periodWeek">'
        . '<label class="form-label small mb-1" for="week">สัปดาห์</label><select name="week" id="week" class="form-select">';
    foreach (array_reverse($p['weeks'], true) as $w => $skip) {
        [$mon, $sun] = advisor_week_range($w);
        $html .= '<option value="' . e($w) . '"' . ($p['week'] === $w ? ' selected' : '') . '>'
            . e(thai_date($mon) . ' – ' . thai_date($sun) . ($skip !== null ? ' (งดพบ)' : '')) . '</option>';
    }
    return $html . '</select></div>'
        . '<script>function TeachHubPeriod(v){document.getElementById("periodMonth").classList.toggle("d-none",v!=="month");'
        . 'document.getElementById("periodWeek").classList.toggle("d-none",v!=="week");}</script>';
}
