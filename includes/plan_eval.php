<?php
/**
 * ไฟล์: includes/plan_eval.php
 * ฟังก์ชันกลางของ "การประเมินแผนการจัดการเรียนรู้" (แบบประเมิน 3 ตอน ตามแบบฟอร์มของวิทยาลัย)
 *   ตอนที่ 1 ตรวจองค์ประกอบ (มี / ไม่มี / ไม่เกี่ยวข้อง) · ตอนที่ 2 ประเมินคุณภาพ 5 ระดับ · ตอนที่ 3 ข้อเสนอแนะและผลการพิจารณา
 * - ประเมินแผนการจัดการเรียนรู้ที่ครูส่งในระบบ (lesson_plans.doc_type = plan) แผนละ 1 ผล
 * - ผู้ประเมิน: admin และหัวหน้างานพัฒนาหลักสูตรฯ (ตามตำแหน่ง) ทุกแผนก / หัวหน้าแผนก (supervisor) เฉพาะแผนกตนเอง
 * - การประเมินแยกจากสถานะตรวจรับงานเดิม (ผ่าน/ให้แก้ไข) ไม่เปลี่ยนสถานะงาน
 * - บันทึกเป็นร่าง (draft) ได้ ครูเห็นผลเมื่อผู้ประเมินกด "ส่งผลการประเมิน" (final) แล้วเท่านั้น
 *   รายงาน/จำนวนที่ประเมินแล้ว นับเฉพาะผลที่ส่งแล้ว
 */

/** ตำแหน่งที่ประเมินแผนได้ทุกแผนก (แก้ตำแหน่งได้เฉพาะผู้ดูแลระบบที่หน้าแก้ไขผู้ใช้) */
const PLAN_EVAL_HEAD_POSITION = 'หัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้';

/** เกณฑ์ผ่าน: ค่าเฉลี่ยรวมตอนที่ 2 ตั้งแต่ 3.51 (ระดับดี) */
const PLAN_EVAL_PASS_AVG = 3.51;

/** ผู้ใช้เป็นหัวหน้างานพัฒนาหลักสูตรและการจัดการเรียนรู้หรือไม่ */
function is_curriculum_head(array $user): bool
{
    if (($user['status'] ?? 'active') !== 'active' || ($user['role'] ?? '') === 'executive') {
        return false;
    }
    return normalize_position((string)($user['position'] ?? '')) === PLAN_EVAL_HEAD_POSITION;
}

/** ประเมินแผนได้ทุกแผนก (admin + หัวหน้างานพัฒนาหลักสูตรฯ) */
function plan_eval_scope_all(array $user): bool
{
    return admin_can($user, 'plan_eval') || is_curriculum_head($user);
}

/** เป็นผู้ประเมินแผน (ทุกแผนก หรือหัวหน้าแผนกที่มีแผนกสังกัด) */
function is_plan_evaluator(array $user): bool
{
    return plan_eval_scope_all($user)
        || ($user['role'] === 'supervisor' && (int)$user['department_id'] > 0 && ($user['status'] ?? 'active') === 'active');
}

/**
 * ขอบเขตแผนกที่ผู้ใช้ดูรายงานได้
 * @return int|null 0 = ทุกแผนก, >0 = เฉพาะแผนกนั้น, null = ไม่มีสิทธิ์ดูรายงาน
 */
function plan_eval_report_scope(array $user): ?int
{
    if (plan_eval_scope_all($user) || $user['role'] === 'executive' || is_designated_reviewer($user, 'lesson_plan')) {
        return 0;
    }
    if ($user['role'] === 'supervisor' && (int)$user['department_id'] > 0) {
        return (int)$user['department_id'];
    }
    return null;
}

/**
 * ผู้ใช้ประเมิน (บันทึก/แก้ไข) แผนนี้ได้หรือไม่ — ตรวจฝั่งเซิร์ฟเวอร์ทุกครั้งที่บันทึก
 * - เฉพาะเอกสารประเภทแผนการจัดการเรียนรู้ และไม่ประเมินแผนของตนเอง
 * - admin / หัวหน้างานพัฒนาหลักสูตรฯ : ทุกแผนก
 * - หัวหน้าแผนก : เฉพาะครูในแผนกตนเอง (ตามแผนกปัจจุบันของครู)
 */
function can_evaluate_plan(array $user, array $plan): bool
{
    if (($plan['doc_type'] ?? '') !== 'plan' || (int)$plan['teacher_id'] === (int)$user['id']) {
        return false;
    }
    if (plan_eval_scope_all($user)) {
        return true;
    }
    return $user['role'] === 'supervisor'
        && (int)$user['department_id'] > 0
        && (int)$plan['department_id'] === (int)$user['department_id'];
}

/** ผู้ใช้ดูผลการประเมินแผนนี้ได้หรือไม่ (ครูเจ้าของแผน / ผู้ประเมินตามขอบเขต / ผู้ดูรายงาน) */
function can_view_plan_eval(array $user, array $plan): bool
{
    if ((int)$plan['teacher_id'] === (int)$user['id'] || can_evaluate_plan($user, $plan)) {
        return true;
    }
    $scope = plan_eval_report_scope($user);
    return $scope === 0 || ($scope !== null && $scope === (int)$plan['department_id']);
}

/** นิพจน์ SQL ภาคเรียนของแผน (แผนรุ่นเก่าอาจไม่มี academic_year_id ใช้ของรอบแทน) */
function plan_eval_ay_sql(string $lp = 'lp', string $r = 'r'): string
{
    return "COALESCE($lp.academic_year_id, $r.academic_year_id)";
}

/**
 * ดึงแผนการจัดการเรียนรู้ 1 รายการ พร้อมครู แผนก ภาคเรียน ท-ป-น และผลประเมิน (pe_*) ถ้ามี
 */
function plan_eval_fetch_plan(int $lessonPlanId): ?array
{
    $ay = plan_eval_ay_sql();
    return db_one(
        "SELECT lp.*, $ay AS ay_id, r.name AS round_name,
                u.prefix, u.first_name, u.last_name, u.position AS teacher_position, u.academic_standing AS teacher_standing,
                u.department_id, d.name AS department_name, ay.year AS ay_year, ay.semester AS ay_semester,
                COALESCE(sj.credit, sc.credit, '') AS subject_credit,
                CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name,
                pe.id AS eval_id
         FROM lesson_plans lp
         JOIN submission_rounds r ON r.id = lp.round_id
         JOIN users u ON u.id = lp.teacher_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN academic_years ay ON ay.id = $ay
         LEFT JOIN subjects sj ON sj.id = lp.subject_id
         LEFT JOIN subjects sc ON sc.code = lp.subject_code
         LEFT JOIN plan_evaluations pe ON pe.lesson_plan_id = lp.id
         WHERE lp.id = ?",
        'i',
        [$lessonPlanId]
    );
}

/** ผลการประเมิน 1 รายการ พร้อมชื่อผู้ประเมินและแผนกที่บันทึกไว้ */
function plan_eval_fetch(int $evalId): ?array
{
    return db_one(
        "SELECT pe.*, CONCAT(ev.prefix, ev.first_name, ' ', ev.last_name) AS evaluator_name, d.name AS eval_department_name
         FROM plan_evaluations pe
         LEFT JOIN users ev ON ev.id = pe.evaluator_id
         LEFT JOIN departments d ON d.id = pe.department_id
         WHERE pe.id = ?",
        'i',
        [$evalId]
    );
}

/** รายการประเมินที่เปิดใช้งาน
 * @param string $part check = ตอนที่ 1, quality = ตอนที่ 2
 */
function plan_eval_criteria_active(string $part): array
{
    return db_all(
        'SELECT * FROM plan_eval_criteria WHERE part = ? AND is_active = 1 ORDER BY sort_order, id',
        's',
        [$part === 'check' ? 'check' : 'quality']
    );
}

/** ผลรายข้อของการประเมิน ['check' => [criteria_id => แถว], 'quality' => [...]] */
function plan_eval_items(int $evalId): array
{
    $out = ['check' => [], 'quality' => []];
    foreach (db_all('SELECT * FROM plan_eval_items WHERE evaluation_id = ? ORDER BY part, id', 'i', [$evalId]) as $r) {
        $out[$r['part']][(int)$r['criteria_id'] ?: -(int)$r['id']] = $r;
    }
    return $out;
}

/** ระดับคุณภาพตามแบบประเมินแผนฯ (ค่าเฉลี่ย 5 ระดับ) */
function plan_eval_level(float $avg): string
{
    if ($avg >= 4.51) return 'ดีมาก';
    if ($avg >= 3.51) return 'ดี';
    if ($avg >= 2.51) return 'พอใช้';
    if ($avg >= 1.51) return 'ควรปรับปรุง';
    if ($avg > 0)     return 'ต้องปรับปรุงอย่างยิ่ง';
    return '-';
}

/** ระดับคุณภาพและความหมาย (ตามคำชี้แจงข้อ 3 ของแบบฟอร์ม) */
function plan_eval_score_labels(): array
{
    return [
        5 => ['ดีมาก', 'มีครบถ้วน ถูกต้อง สอดคล้องกันทุกองค์ประกอบ และเป็นแบบอย่างได้'],
        4 => ['ดี', 'มีครบถ้วน ถูกต้อง สอดคล้องกันเป็นส่วนใหญ่'],
        3 => ['พอใช้', 'มีครบถ้วน แต่ยังมีบางส่วนไม่ถูกต้องหรือไม่สอดคล้องกัน'],
        2 => ['ควรปรับปรุง', 'มีไม่ครบถ้วน หรือไม่ถูกต้อง/ไม่สอดคล้องกันเป็นส่วนใหญ่'],
        1 => ['ต้องปรับปรุงอย่างยิ่ง', 'ไม่มี หรือมีแต่ไม่ถูกต้องตามแบบฟอร์ม'],
    ];
}

/** การแปลผล [ช่วงค่าเฉลี่ย, ร้อยละ, ระดับ] */
function plan_eval_level_table(): array
{
    return [
        ['4.51 – 5.00', 'ร้อยละ 90 ขึ้นไป', 'ดีมาก'],
        ['3.51 – 4.50', 'ร้อยละ 70 – 89', 'ดี'],
        ['2.51 – 3.50', 'ร้อยละ 50 – 69', 'พอใช้'],
        ['1.51 – 2.50', 'ร้อยละ 30 – 49', 'ควรปรับปรุง'],
        ['1.00 – 1.50', 'ต่ำกว่าร้อยละ 30', 'ต้องปรับปรุงอย่างยิ่ง'],
    ];
}

/** ป้ายระดับคุณภาพ */
function plan_eval_level_badge(?float $avg): string
{
    if ($avg === null || $avg <= 0) {
        return '<span class="text-muted">-</span>';
    }
    $level = plan_eval_level($avg);
    $cls = ['ดีมาก' => 'bg-success', 'ดี' => 'bg-primary', 'พอใช้' => 'bg-warning text-dark',
        'ควรปรับปรุง' => 'badge-late', 'ต้องปรับปรุงอย่างยิ่ง' => 'bg-danger'][$level] ?? 'bg-secondary';
    return '<span class="badge ' . $cls . '">' . number_format($avg, 2) . ' · ' . e($level) . '</span>';
}

/** ป้ายผ่าน/ไม่ผ่านเกณฑ์ */
function plan_eval_pass_badge(bool $passed): string
{
    return $passed ? '<span class="badge bg-success">ผ่านเกณฑ์</span>' : '<span class="badge bg-danger">ไม่ผ่านเกณฑ์</span>';
}

/** ผลที่ส่งให้ครูแล้ว (ไม่ใช่ร่าง) */
function plan_eval_is_final(?array $ev): bool
{
    return $ev !== null && ($ev['status'] ?? '') === 'final';
}

/** ป้ายสถานะร่าง/ส่งผลแล้ว */
function plan_eval_status_badge(string $status): string
{
    return $status === 'final'
        ? '<span class="badge bg-success-subtle text-success border border-success-subtle">ส่งผลให้ครูแล้ว</span>'
        : '<span class="badge bg-warning-subtle text-dark border border-warning-subtle"><i class="bi bi-pencil"></i> ร่าง (ครูยังไม่เห็น)</span>';
}

/** ตัวเลือกตอนที่ 1 */
function plan_eval_check_labels(): array
{
    return ['yes' => 'มี', 'no' => 'ไม่มี', 'na' => 'ไม่เกี่ยวข้อง'];
}

/** ผลการพิจารณา (ตอนที่ 3) */
function plan_eval_decisions(): array
{
    return [
        'approve'        => 'ควรอนุญาตให้ใช้การสอนได้',
        'approve_revise' => 'ควรอนุญาตให้ใช้การสอนได้ โดยปรับปรุงตามข้อเสนอแนะ',
        'resubmit'       => 'ควรปรับปรุงแก้ไขและนำเสนอเพื่อพิจารณาใหม่',
    ];
}

/** ข้อความผลการพิจารณา (รวมวันที่กำหนดนำเสนอใหม่) */
function plan_eval_decision_text(array $ev): string
{
    $text = plan_eval_decisions()[$ev['decision']] ?? '-';
    if ($ev['decision'] === 'resubmit' && !empty($ev['resubmit_by'])) {
        $text .= ' ภายในวันที่ ' . thai_date($ev['resubmit_by'], false, true);
    }
    return $text;
}

/** ค่าเฉลี่ยรายด้านของตอนที่ 2 [ด้าน => ['n' => จำนวนข้อ, 'sum' => คะแนนรวม, 'avg' => ค่าเฉลี่ย]] */
function plan_eval_category_summary(array $qualityItems): array
{
    $out = [];
    foreach ($qualityItems as $it) {
        $c = $it['category'];
        $out[$c] = $out[$c] ?? ['n' => 0, 'sum' => 0, 'avg' => 0.0];
        $out[$c]['n']++;
        $out[$c]['sum'] += (int)$it['score'];
    }
    foreach ($out as &$row) {
        $row['avg'] = round($row['sum'] / max(1, $row['n']), 2);
    }
    return $out;
}

/**
 * ตรวจและคำนวณผลจากข้อมูลที่กรอก
 * @param array $checks  [criteria_id => yes|no|na]  (ตอนที่ 1)
 * @param array $scores  [criteria_id => 1..5]       (ตอนที่ 2)
 * @param bool  $final   true = ส่งผลให้ครู (ต้องกรอกครบ), false = บันทึกร่าง (กรอกไม่ครบได้ ค่าเฉลี่ยคิดจากข้อที่กรอก)
 * @return array ['errors' => [...], 'items' => [...], 'total' => , 'count' => , 'avg' => , 'check_passed' => ]
 */
function plan_eval_compute(array $checkCriteria, array $qualityCriteria, array $checks, array $scores, bool $final = true): array
{
    $errors = [];
    $items = [];
    $checkPassed = true;
    $missingCheck = 0;
    foreach ($checkCriteria as $c) {
        $cid = (int)$c['id'];
        $val = (string)($checks[$cid] ?? '');
        if (!isset(plan_eval_check_labels()[$val]) || ($val === 'na' && (int)$c['is_required'] === 1)) {
            $missingCheck++;
            continue;
        }
        if ((int)$c['is_required'] === 1 && $val !== 'yes') {
            $checkPassed = false;
        }
        $items[] = ['criteria_id' => $cid, 'part' => 'check', 'category' => $c['category'], 'title' => $c['title'],
            'is_required' => (int)$c['is_required'], 'check_result' => $val, 'score' => null];
    }
    if ($missingCheck && $final) {
        $errors['check'] = 'ตอนที่ 1 ยังเลือกไม่ครบ ' . $missingCheck . ' รายการ (รายการที่ต้องมีทุกรายวิชาเลือก "ไม่เกี่ยวข้อง" ไม่ได้)';
    }
    $total = 0;
    $count = 0;
    $missingScore = 0;
    foreach ($qualityCriteria as $c) {
        $cid = (int)$c['id'];
        $s = (int)($scores[$cid] ?? 0);
        if ($s < 1 || $s > 5) {
            $missingScore++;
            continue;
        }
        $total += $s;
        $count++;
        $items[] = ['criteria_id' => $cid, 'part' => 'quality', 'category' => $c['category'], 'title' => $c['title'],
            'is_required' => 0, 'check_result' => null, 'score' => $s];
    }
    if (!$qualityCriteria || ($missingScore && $final)) {
        $errors['score'] = $qualityCriteria ? 'ตอนที่ 2 ยังให้คะแนนไม่ครบ ' . $missingScore . ' ข้อ' : 'ยังไม่มีรายการประเมินตอนที่ 2 (ผู้ดูแลระบบต้องเพิ่มรายการก่อน)';
    }
    $avg = $count ? round($total / $count, 2) : 0.0;
    return ['errors' => $errors, 'items' => $items, 'total' => $total, 'count' => $count, 'avg' => $avg,
        'check_passed' => $checkPassed && !$missingCheck, 'final' => $final];
}

/**
 * บันทึกผลการประเมิน (เพิ่มใหม่ หรือแก้ไขผลเดิมของแผนนี้) — ผู้เรียกต้องตรวจสิทธิ์ can_evaluate_plan() ก่อน
 * แก้ไขผลเดิมจะล้างการรับทราบของครู ให้ครูรับทราบผลใหม่อีกครั้ง
 * $calc['final'] = true ส่งผลให้ครู (status final), false = บันทึกร่าง (ครูยังไม่เห็น)
 * @return int id ของผลการประเมิน
 */
function plan_eval_save(array $user, array $plan, array $info, array $calc): int
{
    $passed = $calc['avg'] >= PLAN_EVAL_PASS_AVG && $calc['check_passed'];
    $level = plan_eval_level($calc['avg']);
    db()->begin_transaction();
    try {
        $params = [(int)$plan['department_id'] ?: null, (int)$plan['ay_id'] ?: null, (int)$user['id'], mb_substr((string)$user['position'], 0, 150),
            (int)$plan['current_version'], $info['program'], $info['subject_category'], $info['major'], $info['credit'],
            $info['unit_count'], $info['plan_count'], $info['has_standard'],
            $calc['total'], $calc['count'] * 5, $calc['count'], $calc['avg'], $level, $calc['check_passed'] ? 1 : 0, $passed ? 1 : 0,
            $info['strengths'], $info['improvements'], $info['decision'], $info['resubmit_by'], $calc['final'] ? 'final' : 'draft'];
        $types = 'iiisissssiiiiiidsiisssss';
        // วันที่ส่งผล: ส่งครั้งแรก/ส่งใหม่หลังแก้ไข = ตอนนี้, บันทึกร่าง = ล้าง
        $finalSql = $calc['final'] ? 'NOW()' : 'NULL';
        if (!empty($plan['eval_id'])) {
            $evalId = (int)$plan['eval_id'];
            db_exec(
                "UPDATE plan_evaluations SET department_id = ?, academic_year_id = ?, evaluator_id = ?, evaluator_position = ?,
                    plan_version = ?, program = ?, subject_category = ?, major = ?, credit = ?, unit_count = ?, plan_count = ?, has_standard = ?,
                    total_score = ?, max_score = ?, item_count = ?, avg_score = ?, quality_level = ?, check_passed = ?, is_passed = ?,
                    strengths = ?, improvements = ?, decision = ?, resubmit_by = ?, status = ?, finalized_at = $finalSql, acknowledged_at = NULL
                 WHERE id = ?",
                $types . 'i',
                array_merge($params, [$evalId])
            );
            db_exec('DELETE FROM plan_eval_items WHERE evaluation_id = ?', 'i', [$evalId]);
        } else {
            $evalId = db_insert(
                "INSERT INTO plan_evaluations (department_id, academic_year_id, evaluator_id, evaluator_position,
                    plan_version, program, subject_category, major, credit, unit_count, plan_count, has_standard,
                    total_score, max_score, item_count, avg_score, quality_level, check_passed, is_passed,
                    strengths, improvements, decision, resubmit_by, status, finalized_at, lesson_plan_id, teacher_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, $finalSql, ?, ?)",
                $types . 'ii',
                array_merge($params, [(int)$plan['id'], (int)$plan['teacher_id']])
            );
        }
        foreach ($calc['items'] as $it) {
            db_insert(
                'INSERT INTO plan_eval_items (evaluation_id, criteria_id, part, category, title, is_required, check_result, score)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                'iisssisi',
                [$evalId, $it['criteria_id'], $it['part'], $it['category'], $it['title'], $it['is_required'], $it['check_result'], $it['score']]
            );
        }
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollback();
        throw $ex;
    }
    return $evalId;
}

/**
 * เงื่อนไข SQL ของแผนที่อยู่ในขอบเขต (ใช้กับ lp / u / pe)
 * @return array [where, types, params]
 */
function plan_eval_scope_where(int $ayId, int $deptId): array
{
    $where = ["lp.doc_type = 'plan'"];
    $types = '';
    $params = [];
    if ($ayId > 0) {
        $where[] = plan_eval_ay_sql() . ' = ?';
        $types .= 'i';
        $params[] = $ayId;
    }
    if ($deptId > 0) {
        $where[] = 'COALESCE(pe.department_id, u.department_id) = ?';
        $types .= 'i';
        $params[] = $deptId;
    }
    return [implode(' AND ', $where), $types, $params];
}

/** จำนวนแผนที่รอประเมิน (ยังไม่ประเมิน หรือยังเป็นร่าง) ในขอบเขตของผู้ประเมิน (ภาคเรียนปัจจุบัน) — ใช้แสดงบนเมนู */
function plan_eval_pending_count(array $user): int
{
    if (!is_plan_evaluator($user)) {
        return 0;
    }
    $dept = plan_eval_scope_all($user) ? 0 : (int)$user['department_id'];
    [$where, $types, $params] = plan_eval_scope_where((int)(current_academic_year()['id'] ?? 0), $dept);
    return (int)db_value(
        "SELECT COUNT(*) FROM lesson_plans lp JOIN submission_rounds r ON r.id = lp.round_id JOIN users u ON u.id = lp.teacher_id
         LEFT JOIN plan_evaluations pe ON pe.lesson_plan_id = lp.id
         WHERE $where AND (pe.id IS NULL OR pe.status = 'draft') AND lp.teacher_id <> ?",
        $types . 'i',
        array_merge($params, [(int)$user['id']])
    );
}

/** จำนวนผลประเมินแผนของครูที่ส่งแล้วแต่ยังไม่กดรับทราบ */
function plan_eval_unack_count(int $teacherId): int
{
    return (int)db_value("SELECT COUNT(*) FROM plan_evaluations WHERE teacher_id = ? AND status = 'final' AND acknowledged_at IS NULL", 'i', [$teacherId]);
}

/** ภาคเรียนที่เลือกจาก ?ay= (ไม่ระบุ = ภาคเรียนปัจจุบัน, 0 = ทุกภาคเรียน ผ่าน ?ay=all) */
function plan_eval_selected_ay(): int
{
    if (get('ay') === 'all') {
        return 0;
    }
    $ay = get_int('ay');
    if ($ay > 0 && db_value('SELECT id FROM academic_years WHERE id = ?', 'i', [$ay])) {
        return $ay;
    }
    return (int)(current_academic_year()['id'] ?? 0);
}

/**
 * ข้อมูลรายงานผลการประเมินแผนฯ — นับเป็น "ประเมินแล้ว" เฉพาะผลที่ส่งให้ครูแล้ว (ร่างนับเป็น drafts)
 * @return array plans (ทุกแผนในขอบเขต + ผลประเมิน), totals, by_dept, by_category, by_item, check_items, levels
 */
function plan_eval_report(int $ayId, int $deptId): array
{
    [$where, $types, $params] = plan_eval_scope_where($ayId, $deptId);
    $plans = db_all(
        "SELECT lp.id, lp.subject_code, lp.subject_name, lp.level, lp.status, lp.teacher_id,
                CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name,
                COALESCE(pe.department_id, u.department_id) AS dept_id, d.name AS department_name,
                pe.id AS eval_id, pe.avg_score, pe.total_score, pe.max_score, pe.quality_level, pe.is_passed, pe.check_passed,
                pe.decision, pe.status AS eval_status, pe.acknowledged_at, pe.updated_at, pe.created_at AS evaluated_at,
                CONCAT(ev.prefix, ev.first_name, ' ', ev.last_name) AS evaluator_name
         FROM lesson_plans lp
         JOIN submission_rounds r ON r.id = lp.round_id
         JOIN users u ON u.id = lp.teacher_id
         LEFT JOIN plan_evaluations pe ON pe.lesson_plan_id = lp.id
         LEFT JOIN departments d ON d.id = COALESCE(pe.department_id, u.department_id)
         LEFT JOIN users ev ON ev.id = pe.evaluator_id
         WHERE $where
         ORDER BY d.name, u.first_name, lp.subject_code",
        $types,
        $params
    );

    $levels = array_fill_keys(['ดีมาก', 'ดี', 'พอใช้', 'ควรปรับปรุง', 'ต้องปรับปรุงอย่างยิ่ง'], 0);
    $totals = ['plans' => 0, 'evaluated' => 0, 'passed' => 0, 'avg' => 0.0, 'teachers' => 0, 'acknowledged' => 0, 'drafts' => 0];
    $byDept = [];
    $teachers = [];
    $avgSum = 0.0;
    foreach ($plans as $p) {
        $dk = (int)$p['dept_id'];
        $byDept[$dk] = $byDept[$dk] ?? ['name' => $p['department_name'] ?: '-', 'plans' => 0, 'evaluated' => 0, 'passed' => 0, 'avg_sum' => 0.0, 'avg' => 0.0];
        $byDept[$dk]['plans']++;
        $totals['plans']++;
        $teachers[(int)$p['teacher_id']] = true;
        if ($p['eval_id'] && $p['eval_status'] !== 'final') {
            $totals['drafts']++;
        } elseif ($p['eval_id']) {
            $a = (float)$p['avg_score'];
            $totals['evaluated']++;
            $byDept[$dk]['evaluated']++;
            $avgSum += $a;
            $byDept[$dk]['avg_sum'] += $a;
            if ((int)$p['is_passed'] === 1) {
                $totals['passed']++;
                $byDept[$dk]['passed']++;
            }
            if ($p['acknowledged_at']) {
                $totals['acknowledged']++;
            }
            $lv = plan_eval_level($a);
            if (isset($levels[$lv])) {
                $levels[$lv]++;
            }
        }
    }
    $totals['teachers'] = count($teachers);
    $totals['avg'] = $totals['evaluated'] ? round($avgSum / $totals['evaluated'], 2) : 0.0;
    foreach ($byDept as &$bd) {
        $bd['avg'] = $bd['evaluated'] ? round($bd['avg_sum'] / $bd['evaluated'], 2) : 0.0;
    }
    unset($bd);
    uasort($byDept, fn($a, $b) => strcmp($a['name'], $b['name']));

    // ค่าเฉลี่ยรายด้าน / รายข้อ (ตอนที่ 2) และร้อยละ "มี" รายการ (ตอนที่ 1) — จากผลรายข้อที่บันทึกไว้
    $byCategory = $byItem = $checkItems = [];
    if ($totals['evaluated']) {
        $rows = db_all(
            "SELECT it.part, it.category, it.title, it.is_required, it.criteria_id, it.check_result, it.score,
                    COALESCE(c.sort_order, 9999) AS sort_order
             FROM plan_eval_items it
             JOIN plan_evaluations pe ON pe.id = it.evaluation_id
             JOIN lesson_plans lp ON lp.id = pe.lesson_plan_id
             JOIN submission_rounds r ON r.id = lp.round_id
             JOIN users u ON u.id = lp.teacher_id
             LEFT JOIN plan_eval_criteria c ON c.id = it.criteria_id
             WHERE $where AND pe.status = 'final'
             ORDER BY it.part, sort_order, it.id",
            $types,
            $params
        );
        foreach ($rows as $r) {
            $key = $r['criteria_id'] ? 'c' . $r['criteria_id'] : 't' . md5($r['title']);
            if ($r['part'] === 'quality') {
                $byItem[$key] = $byItem[$key] ?? ['category' => $r['category'], 'title' => $r['title'], 'n' => 0, 'sum' => 0, 'avg' => 0.0];
                $byItem[$key]['n']++;
                $byItem[$key]['sum'] += (int)$r['score'];
                $byCategory[$r['category']] = $byCategory[$r['category']] ?? ['n' => 0, 'sum' => 0, 'avg' => 0.0];
                $byCategory[$r['category']]['n']++;
                $byCategory[$r['category']]['sum'] += (int)$r['score'];
            } else {
                $checkItems[$key] = $checkItems[$key] ?? ['category' => $r['category'], 'title' => $r['title'], 'is_required' => (int)$r['is_required'],
                    'yes' => 0, 'no' => 0, 'na' => 0];
                $checkItems[$key][$r['check_result']]++;
            }
        }
        foreach ($byItem as &$bi) {
            $bi['avg'] = round($bi['sum'] / max(1, $bi['n']), 2);
        }
        unset($bi);
        foreach ($byCategory as &$bc) {
            $bc['avg'] = round($bc['sum'] / max(1, $bc['n']), 2);
        }
        unset($bc);
    }

    return ['plans' => $plans, 'totals' => $totals, 'by_dept' => $byDept, 'by_category' => $byCategory,
        'by_item' => $byItem, 'check_items' => $checkItems, 'levels' => $levels];
}
