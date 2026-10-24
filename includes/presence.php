<?php
/**
 * ไฟล์: includes/presence.php
 * ผู้ใช้ออนไลน์, ตัวนับผู้เข้าชมเว็บ และข้อความชื่นชมผู้ใช้งานสม่ำเสมอ (v1.20)
 *
 * - ออนไลน์ = ใช้งานภายใน PRESENCE_ONLINE_MIN นาที (users.last_seen_at เขียนไม่เกิน 1 ครั้งต่อนาทีต่อผู้ใช้)
 * - ผู้เข้าชม = นับ 1 ครั้งต่อคนต่อวัน กันซ้ำด้วย session + hash ของ IP/เบราว์เซอร์/วันที่ (ไม่เก็บ IP ดิบ)
 * - ข้อความชื่นชม = แสดงเฉพาะเจ้าของในแดชบอร์ด ไม่จัดอันดับให้ผู้อื่นเห็น
 * ทุกฟังก์ชันไม่ทำให้หน้าเว็บหยุดทำงาน แม้ยังไม่ได้นำเข้า update_v1.20.sql
 */

const PRESENCE_ONLINE_MIN = 5;

/** บันทึกว่าผู้ใช้ยังใช้งานอยู่ (เรียกจาก require_login และหน้าบ้านเมื่อล็อกอินอยู่) */
function presence_touch(array $user): void
{
    $now = time();
    if ($now - (int)($_SESSION['_seen_at'] ?? 0) < 60) {
        return;
    }
    $_SESSION['_seen_at'] = $now;
    try {
        $uid = (int)$user['id'];
        // updated_at = updated_at : ไม่ให้เวลาแก้ไขข้อมูลผู้ใช้เปลี่ยนเพราะการเปิดหน้า
        db_exec('UPDATE users SET last_seen_at = NOW(), updated_at = updated_at WHERE id = ?', 'i', [$uid]);
        $today = date('Y-m-d');
        if (($_SESSION['_active_day'] ?? '') !== $today) {
            db_exec('INSERT IGNORE INTO user_active_days (user_id, active_date) VALUES (?, ?)', 'is', [$uid, $today]);
            $_SESSION['_active_day'] = $today;
        }
    } catch (Throwable $ex) {
        error_log('presence_touch: ' . $ex->getMessage());
    }
}

/** ออกจากระบบ: เลื่อนเวลาใช้งานล่าสุดให้พ้นช่วงออนไลน์ทันที */
function presence_clear(int $uid): void
{
    try {
        db_exec(
            'UPDATE users SET last_seen_at = NOW() - INTERVAL ' . (PRESENCE_ONLINE_MIN + 1) . ' MINUTE, updated_at = updated_at
             WHERE id = ? AND last_seen_at IS NOT NULL',
            'i',
            [$uid]
        );
    } catch (Throwable $ex) {
        error_log('presence_clear: ' . $ex->getMessage());
    }
}

/** จำนวนผู้ใช้ที่ออนไลน์อยู่ */
function presence_online_count(): int
{
    try {
        return (int)db_value(
            "SELECT COUNT(*) FROM users WHERE status = 'active'
             AND last_seen_at >= NOW() - INTERVAL " . PRESENCE_ONLINE_MIN . ' MINUTE'
        );
    } catch (Throwable $ex) {
        return 0;
    }
}

/** รายชื่อผู้ใช้ที่ออนไลน์อยู่ (ล่าสุดก่อน) — แสดงเฉพาะผู้ที่ล็อกอินแล้ว */
function presence_online_users(int $limit = 30): array
{
    try {
        return db_all(
            "SELECT u.id, u.prefix, u.first_name, u.last_name, u.avatar, u.role, u.last_seen_at, d.name AS department_name
             FROM users u LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.status = 'active' AND u.last_seen_at >= NOW() - INTERVAL " . PRESENCE_ONLINE_MIN . ' MINUTE
             ORDER BY u.last_seen_at DESC LIMIT ?',
            'i',
            [$limit]
        );
    } catch (Throwable $ex) {
        return [];
    }
}

// =====================================================================
// ตัวนับผู้เข้าชม
// =====================================================================

/** โปรแกรมเก็บข้อมูลเว็บ/บอต — ไม่นับเป็นผู้เข้าชม */
function visit_is_bot(string $ua): bool
{
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|java\/|httpclient|headless/i', $ua);
}

/** นับผู้เข้าชม 1 ครั้งต่อคนต่อวัน (เรียกจากส่วนหัวของหน้าเว็บ) */
function visit_track(): void
{
    $today = date('Y-m-d');
    if (($_SESSION['_visit_day'] ?? '') === $today) {
        return;
    }
    $_SESSION['_visit_day'] = $today;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (visit_is_bot($ua)) {
        return;
    }
    try {
        $salt = (string)setting('visit_salt');
        if ($salt === '') {
            $salt = bin2hex(random_bytes(16));
            setting_save('visit_salt', $salt);
        }
        // เปิดเว็บจากเบราว์เซอร์เดิมที่ไม่เก็บคุกกี้ ก็ยังไม่นับซ้ำในวันเดียวกัน
        $hash = hash('sha256', $today . '|' . client_ip() . '|' . $ua . '|' . $salt);
        if (db_exec('INSERT IGNORE INTO site_visitors (visit_date, visitor_hash) VALUES (?, ?)', 'ss', [$today, $hash]) < 1) {
            return;
        }
        $isNewDay = db_exec('INSERT IGNORE INTO site_visit_daily (visit_date, visits) VALUES (?, 1)', 's', [$today]) > 0;
        if ($isNewDay) {
            // ผู้เข้าชมคนแรกของวัน: ล้าง hash ของวันก่อน ๆ
            db_exec('DELETE FROM site_visitors WHERE visit_date < ? - INTERVAL 1 DAY', 's', [$today]);
        } else {
            db_exec('UPDATE site_visit_daily SET visits = visits + 1 WHERE visit_date = ?', 's', [$today]);
        }
    } catch (Throwable $ex) {
        error_log('visit_track: ' . $ex->getMessage());
    }
}

/** สถิติผู้เข้าชม: วันนี้ / เดือนนี้ / ทั้งหมด */
function visit_stats(): array
{
    try {
        $row = db_one(
            "SELECT COALESCE(SUM(IF(visit_date = CURDATE(), visits, 0)), 0) AS today,
                    COALESCE(SUM(IF(visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01'), visits, 0)), 0) AS month,
                    COALESCE(SUM(visits), 0) AS total
             FROM site_visit_daily"
        );
        return array_map('intval', $row ?: ['today' => 0, 'month' => 0, 'total' => 0]);
    } catch (Throwable $ex) {
        return ['today' => 0, 'month' => 0, 'total' => 0];
    }
}

// =====================================================================
// ข้อความชื่นชมผู้ใช้งานสม่ำเสมอ (เห็นเฉพาะเจ้าของ)
// =====================================================================

/**
 * สถิติการเข้าใช้งานของผู้ใช้ + ข้อความชื่นชม
 * @return array|null ['streak','month_days','total_days','level','icon','message','milestone'] — null = ยังไม่มีข้อมูล
 *         milestone = ข้อความแจ้ง toast เมื่อวันนี้ถึงเกณฑ์ใหม่ (แจ้งครั้งเดียว)
 */
function user_cheer(array $user): ?array
{
    try {
        $uid = (int)$user['id'];
        $days = db_all(
            'SELECT active_date, cheered FROM user_active_days WHERE user_id = ? ORDER BY active_date DESC LIMIT 400',
            'i',
            [$uid]
        );
        if (!$days) {
            return null;
        }
        $total = (int)db_value('SELECT COUNT(*) FROM user_active_days WHERE user_id = ?', 'i', [$uid]);
    } catch (Throwable $ex) {
        return null;
    }

    $today = date('Y-m-d');
    $monthStart = date('Y-m-01');
    $month = 0;
    foreach ($days as $d) {
        if ($d['active_date'] >= $monthStart) {
            $month++;
        }
    }
    // วันต่อเนื่อง: นับย้อนจากวันนี้ (หรือเมื่อวาน ถ้าวันนี้ยังไม่ได้บันทึก)
    $streak = 0;
    $expect = $days[0]['active_date'] === $today ? $today : date('Y-m-d', strtotime('-1 day'));
    foreach ($days as $d) {
        if ($d['active_date'] !== $expect) {
            break;
        }
        $streak++;
        $expect = date('Y-m-d', strtotime($expect . ' -1 day'));
    }

    $levels = [ // [จำนวนวันทั้งหมดขั้นต่ำ, ระดับ, ไอคอน]
        [200, 'ผู้ใช้งานระดับตำนาน', 'bi-trophy-fill'],
        [100, 'ผู้ใช้งานระดับเพชร', 'bi-gem'],
        [50, 'ผู้ใช้งานระดับทอง', 'bi-award-fill'],
        [20, 'ผู้ใช้งานระดับเงิน', 'bi-star-fill'],
        [5, 'ผู้ใช้งานสม่ำเสมอ', 'bi-hand-thumbs-up-fill'],
        [0, 'ยินดีต้อนรับ', 'bi-emoji-smile-fill'],
    ];
    foreach ($levels as [$min, $level, $icon]) {
        if ($total >= $min) {
            break;
        }
    }

    $messages = [
        'ขอบคุณที่ใช้งานอย่างสม่ำเสมอ ความตั้งใจของท่านคือพลังของวิทยาลัย',
        'ทุกงานที่ส่งคือก้าวเล็ก ๆ ที่สร้างผลลัพธ์ยิ่งใหญ่ให้ผู้เรียน',
        'ขอบคุณที่เป็นแบบอย่างของความรับผิดชอบ',
        'ความสม่ำเสมอของท่านทำให้งานวิชาการเดินหน้าได้อย่างราบรื่น',
        'ขอให้วันนี้เป็นวันที่ดี มีความสุขกับการสอนและการทำงาน',
        'ขอบคุณที่ทุ่มเทเพื่อผู้เรียนทุกวัน ทีมงานเห็นและชื่นชมเสมอ',
        'เก่งมาก! การวางแผนที่ดีเริ่มจากการติดตามงานอย่างต่อเนื่องแบบนี้',
    ];
    $message = $total <= 1
        ? 'ยินดีต้อนรับสู่ ' . system_name() . ' ขอให้การใช้งานราบรื่นและเป็นประโยชน์'
        : $messages[($uid + (int)date('z')) % count($messages)];

    // ถึงเกณฑ์ใหม่วันนี้ → แจ้งครั้งเดียว
    $milestone = null;
    if ($days[0]['active_date'] === $today && (int)$days[0]['cheered'] === 0) {
        if (in_array($streak, [3, 7, 14, 30, 60, 100], true)) {
            $milestone = 'เยี่ยมมาก! ท่านเข้าใช้งานต่อเนื่อง ' . $streak . ' วันแล้ว ขอบคุณที่ใส่ใจงานอย่างสม่ำเสมอ';
        } elseif (in_array($total, [10, 30, 50, 100, 200, 365, 500], true)) {
            $milestone = 'ยินดีด้วย! ท่านเข้าใช้งานครบ ' . $total . ' วันแล้ว ขอบคุณที่อยู่ด้วยกันเสมอ';
        }
        if ($milestone !== null) {
            try {
                db_exec('UPDATE user_active_days SET cheered = 1 WHERE user_id = ? AND active_date = ?', 'is', [$uid, $today]);
            } catch (Throwable $ex) {
                $milestone = null;
            }
        }
    }

    return [
        'streak' => $streak, 'month_days' => $month, 'total_days' => $total,
        'level' => $level, 'icon' => $icon, 'message' => $message, 'milestone' => $milestone,
    ];
}
