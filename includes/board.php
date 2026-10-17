<?php
/**
 * ไฟล์: includes/board.php
 * กระดานข่าว — ช่องทางติดต่อประสานงาน สอบถาม แจ้งปัญหา และเสนอพัฒนาระบบ
 *   - อ่าน/โพสต์/ตอบได้เฉพาะสมาชิกที่เข้าสู่ระบบ (ทุกบทบาท)
 *   - เจ้าของแก้ไขข้อความของตนเองได้ · ลบกระทู้ของตนเองได้เมื่อยังไม่มีผู้ตอบ
 *   - ผู้ดูแลระบบ: ปักหมุด เปลี่ยนสถานะ ปิดกระทู้ ลบข้อความใดก็ได้
 */

/** หมวดกระทู้: [ชื่อ, ไอคอน, สี badge] */
function board_categories(): array
{
    return [
        'general'  => ['ประสานงานทั่วไป', 'bi-chat-dots', 'secondary'],
        'question' => ['สอบถามการใช้งาน', 'bi-question-circle', 'primary'],
        'feature'  => ['เสนอพัฒนาระบบ', 'bi-lightbulb', 'success'],
        'bug'      => ['แจ้งปัญหา/ข้อผิดพลาด', 'bi-bug', 'danger'],
    ];
}

/** สถานะกระทู้: [ชื่อ, สี badge] */
function board_statuses(): array
{
    return [
        'open'     => ['รอตอบ', 'warning text-dark'],
        'answered' => ['ตอบแล้ว', 'info text-dark'],
        'planned'  => ['รับไว้พัฒนา', 'primary'],
        'done'     => ['ดำเนินการแล้ว', 'success'],
        'closed'   => ['ปิดกระทู้', 'secondary'],
    ];
}

function board_category_badge(string $cat): string
{
    [$label, $icon, $color] = board_categories()[$cat] ?? board_categories()['general'];
    return '<span class="badge bg-' . $color . '-subtle text-' . $color . '-emphasis border border-' . $color . '-subtle">'
        . '<i class="bi ' . $icon . ' me-1"></i>' . e($label) . '</span>';
}

function board_status_badge(string $status): string
{
    [$label, $color] = board_statuses()[$status] ?? board_statuses()['open'];
    return '<span class="badge bg-' . $color . '">' . e($label) . '</span>';
}

/** ผู้ดูแลกระดาน (ผู้ดูแลระบบ) */
function board_can_moderate(array $user): bool
{
    return $user['role'] === 'admin';
}

/** ข้อความในกระทู้ (escape ทุกตัวอักษร + ขึ้นบรรทัด) */
function board_render(string $text): string
{
    return nl2br(e($text));
}

/** กันโพสต์ถี่เกินไป: ไม่เกิน 10 ข้อความใน 10 นาที */
function board_rate_limited(int $userId): bool
{
    $n = (int)db_value(
        'SELECT (SELECT COUNT(*) FROM board_topics WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE)
              + (SELECT COUNT(*) FROM board_replies WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE)',
        'ii',
        [$userId, $userId]
    );
    return $n >= 10;
}

/** ตรวจหัวข้อ/ข้อความ คืน array ข้อผิดพลาด */
function board_validate(string $title, string $body, bool $needTitle = true): array
{
    $errors = [];
    if ($needTitle && ($title === '' || mb_strlen($title) > 200)) {
        $errors['title'] = 'กรุณากรอกหัวข้อ (ไม่เกิน 200 ตัวอักษร)';
    }
    if (mb_strlen(trim($body)) < 5) {
        $errors['body'] = 'กรุณากรอกรายละเอียดอย่างน้อย 5 ตัวอักษร';
    } elseif (mb_strlen($body) > 10000) {
        $errors['body'] = 'ข้อความยาวเกิน 10,000 ตัวอักษร';
    }
    return $errors;
}

/** ดึงกระทู้พร้อมชื่อผู้โพสต์ */
function board_topic_fetch(int $id): ?array
{
    return db_one(
        "SELECT t.*, u.prefix, u.first_name, u.last_name, u.role AS author_role, u.position AS author_position, u.avatar, u.id AS author_id
         FROM board_topics t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ?",
        'i',
        [$id]
    );
}

/** คำนวณจำนวนคำตอบและเวลาตอบล่าสุดใหม่ */
function board_refresh_counts(int $topicId): void
{
    db_exec(
        'UPDATE board_topics SET reply_count = (SELECT COUNT(*) FROM board_replies WHERE topic_id = ?),
                                 last_reply_at = (SELECT MAX(created_at) FROM board_replies WHERE topic_id = ?)
         WHERE id = ?',
        'iii',
        [$topicId, $topicId, $topicId]
    );
}

/** จำนวนกระทู้ที่รอตอบ (แสดงบนเมนูของผู้ดูแลระบบ) */
function board_open_count(): int
{
    return (int)db_value("SELECT COUNT(*) FROM board_topics WHERE status = 'open'");
}

/** ชื่อผู้โพสต์ (ผู้ใช้ถูกลบ = "ผู้ใช้ที่ถูกลบ") */
function board_author(array $row): string
{
    return !empty($row['first_name']) ? full_name($row) : 'ผู้ใช้ที่ถูกลบ';
}
