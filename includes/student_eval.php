<?php
/**
 * ไฟล์: includes/student_eval.php
 * ฟังก์ชันกลางของ "การนิเทศ/ประเมินการสอนโดยนักเรียน นักศึกษา"
 *  - admin เป็นผู้สร้างรายการประเมิน (ครู + วิชา/ห้อง + ช่วงเวลา) → ได้ลิงก์/QR Code
 *  - นักเรียนประเมินผ่านลิงก์ได้โดยไม่ต้องเป็นสมาชิก (ไม่เก็บชื่อและ IP)
 *  - ครูเห็นเฉพาะ QR Code และผลของตนเอง, หัวหน้าแผนกเห็นผลของครูในแผนก (ไม่เห็น QR)
 */

/** ระดับของกลุ่มนักเรียน => จำนวนชั้นปี (ปวช. 1-3, ปวส. 1-2) */
function student_levels(): array
{
    return ['ปวช.' => 3, 'ปวส.' => 2];
}

/** ชื่อกลุ่มนักเรียนสำหรับแสดงผล เช่น "ปวช. 1/2" หรือ "ปวส. 2" (ไม่มีกลุ่ม) */
function student_group_label(string $level, int $year, string $group): string
{
    $label = $level . ' ' . $year;
    return $group !== '' ? $label . '/' . $group : $label;
}

/** ความหมายคะแนนสำหรับนักเรียน (ระดับความคิดเห็น) */
function student_score_labels(): array
{
    return [5 => 'มากที่สุด', 4 => 'มาก', 3 => 'ปานกลาง', 2 => 'น้อย', 1 => 'น้อยที่สุด'];
}

/** URL หลักของระบบแบบเต็ม (ใช้สร้าง QR Code ที่โทรศัพท์นักเรียนเปิดได้) */
function site_base_url(): string
{
    $configured = rtrim((string)setting('site_url'), '/');
    if ($configured !== '' && preg_match('#^https?://#i', $configured)) {
        return $configured;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 80) === 443;
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . BASE_URL;
}

/** URL ทำงานแค่บนเครื่องนี้หรือไม่ (โทรศัพท์นักเรียนจะเปิดไม่ได้) */
function site_url_is_local(): bool
{
    $host = (string)parse_url(site_base_url(), PHP_URL_HOST);
    return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
}

/** ลิงก์แบบประเมินสำหรับนักเรียน */
function student_eval_url(string $token): string
{
    return site_base_url() . '/public/evaluate.php?t=' . rawurlencode($token);
}

/**
 * ดึงรายการประเมิน 1 รายการ พร้อมชื่อครู แผนก ภาคเรียน และจำนวนผู้ตอบ
 * @param string $by id | token
 */
function student_eval_fetch($key, string $by = 'id'): ?array
{
    $col = $by === 'token' ? 's.token' : 's.id';
    return db_one(
        "SELECT s.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS teacher_name, u.department_id AS teacher_department_id,
                u.position AS teacher_position, d.name AS department_name, ay.year AS ay_year, ay.semester AS ay_semester,
                (SELECT COUNT(*) FROM student_eval_responses r WHERE r.session_id = s.id) AS responses,
                (SELECT ROUND(AVG(r.avg_score), 2) FROM student_eval_responses r WHERE r.session_id = s.id) AS avg_score
         FROM student_eval_sessions s
         JOIN users u ON u.id = s.teacher_id
         LEFT JOIN departments d ON d.id = u.department_id
         LEFT JOIN academic_years ay ON ay.id = s.academic_year_id
         WHERE $col = ?",
        $by === 'token' ? 's' : 'i',
        [$key]
    );
}

/**
 * สถานะของรายการประเมิน ณ ตอนนี้
 * @return string inactive | upcoming | open | full | closed
 */
function student_eval_state(array $s): string
{
    if (empty($s['is_active'])) {
        return 'inactive';
    }
    $now = time();
    if ($now < strtotime($s['open_at'])) {
        return 'upcoming';
    }
    if ($now > strtotime($s['close_at'])) {
        return 'closed';
    }
    if ((int)$s['max_responses'] > 0 && (int)($s['responses'] ?? 0) >= (int)$s['max_responses']) {
        return 'full';
    }
    return 'open';
}

function student_eval_state_badge(array $s): string
{
    $map = [
        'inactive' => ['bg-secondary', 'ปิดใช้งาน'],
        'upcoming' => ['bg-info text-dark', 'ยังไม่เปิด'],
        'open'     => ['bg-success', 'เปิดรับการประเมิน'],
        'full'     => ['bg-warning text-dark', 'ครบจำนวนแล้ว'],
        'closed'   => ['bg-dark', 'ปิดแล้ว'],
    ];
    [$cls, $label] = $map[student_eval_state($s)];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/** ผู้ใช้ดูผลการประเมินนี้ได้หรือไม่ */
function can_view_student_eval(array $user, array $s): bool
{
    if (admin_can($user, 'student_eval') || $user['role'] === 'executive') {
        return true;
    }
    if ((int)$s['teacher_id'] === (int)$user['id']) {
        return true;
    }
    return $user['role'] === 'supervisor' && (int)$user['department_id'] > 0
        && (int)$s['teacher_department_id'] === (int)$user['department_id'];
}

/**
 * ผู้ใช้เห็นลิงก์/QR Code ได้หรือไม่ — เฉพาะ admin และครูเจ้าของรายการ
 * (หัวหน้าแผนก/ผู้บริหาร เห็นเฉพาะผล เพื่อลดโอกาสการกรอกแทนนักเรียน)
 */
function can_see_student_eval_qr(array $user, array $s): bool
{
    return admin_can($user, 'student_eval') || (int)$s['teacher_id'] === (int)$user['id'];
}

/**
 * สรุปผลการประเมินของรายการ (หรือหลายรายการ)
 * @param int[] $sessionIds
 * @return array ['responses' => int, 'avg' => ?float, 'items' => [...], 'categories' => [หมวด => avg], 'comments' => [...]]
 */
function student_eval_summary(array $sessionIds, bool $withComments = true): array
{
    $sessionIds = array_values(array_filter(array_map('intval', $sessionIds)));
    $empty = ['responses' => 0, 'avg' => null, 'items' => [], 'categories' => [], 'comments' => []];
    if (!$sessionIds) {
        return $empty;
    }
    // สร้าง ? ตามจำนวน id (ค่าทั้งหมดผูกผ่าน prepared statement)
    $in = implode(',', array_fill(0, count($sessionIds), '?'));
    $types = str_repeat('i', count($sessionIds));

    $head = db_one(
        "SELECT COUNT(*) AS n, ROUND(AVG(avg_score), 2) AS avg_score FROM student_eval_responses WHERE session_id IN ($in)",
        $types,
        $sessionIds
    );
    if ((int)$head['n'] === 0) {
        return $empty;
    }
    $items = db_all(
        "SELECT sc.category, sc.criteria_title, ROUND(AVG(sc.score), 2) AS avg_score, COUNT(*) AS n,
                SUM(sc.score = 5) AS s5, SUM(sc.score = 4) AS s4, SUM(sc.score = 3) AS s3, SUM(sc.score = 2) AS s2, SUM(sc.score = 1) AS s1
         FROM student_eval_scores sc JOIN student_eval_responses r ON r.id = sc.response_id
         WHERE r.session_id IN ($in)
         GROUP BY sc.category, sc.criteria_title
         ORDER BY MIN(sc.id)",
        $types,
        $sessionIds
    );
    $cats = [];
    foreach (db_all(
        "SELECT sc.category, ROUND(AVG(sc.score), 2) AS avg_score
         FROM student_eval_scores sc JOIN student_eval_responses r ON r.id = sc.response_id
         WHERE r.session_id IN ($in) GROUP BY sc.category ORDER BY MIN(sc.id)",
        $types,
        $sessionIds
    ) as $c) {
        $cats[$c['category']] = (float)$c['avg_score'];
    }
    $comments = $withComments ? db_all(
        "SELECT comment, created_at FROM student_eval_responses
         WHERE session_id IN ($in) AND comment IS NOT NULL AND comment <> '' ORDER BY id DESC",
        $types,
        $sessionIds
    ) : [];
    return [
        'responses'  => (int)$head['n'],
        'avg'        => (float)$head['avg_score'],
        'items'      => $items,
        'categories' => $cats,
        'comments'   => $comments,
    ];
}

/** ชื่อ cookie กันประเมินซ้ำของรายการนี้ (1 เบราว์เซอร์ = 1 ครั้ง) */
function student_eval_cookie(int $sessionId): string
{
    return 'thpt_eval_' . $sessionId;
}
