<?php
/**
 * ไฟล์: public/evaluate.php?t=TOKEN
 * แบบประเมินการสอนสำหรับนักเรียน นักศึกษา — เปิดจาก QR Code ไม่ต้องเป็นสมาชิก/ไม่ต้องล็อกอิน
 *
 * ความเป็นนิรนาม: ไม่เก็บชื่อ รหัสนักเรียน หรือ IP ของผู้ตอบ และไม่บันทึกลง Log
 * กันประเมินซ้ำ: 1 เบราว์เซอร์ = 1 ครั้ง (cookie + session), จำกัดจำนวนผู้ตอบตามที่ admin ตั้ง,
 *               ครูเจ้าของรายการที่ล็อกอินอยู่ประเมินตนเองไม่ได้, มีช่องดักบอท (honeypot)
 */
require_once __DIR__ . '/../config/config.php';

$token = get('t') ?: post('t');
$se = preg_match('/^[0-9a-f]{32}$/', $token) ? student_eval_fetch($token, 'token') : null;

$view = 'form';    // form | message | done
$message = ['icon' => '', 'title' => '', 'text' => ''];
$errors = [];

if (!$se) {
    http_response_code(404);
    $view = 'message';
    $message = ['icon' => 'bi-question-circle text-secondary', 'title' => 'ไม่พบแบบประเมิน',
        'text' => 'ลิงก์หรือ QR Code ไม่ถูกต้อง หรือถูกยกเลิกแล้ว กรุณาสอบถามครูผู้สอน'];
} else {
    $cookie = student_eval_cookie((int)$se['id']);
    $already = isset($_COOKIE[$cookie]) || !empty($_SESSION['student_eval_done'][(int)$se['id']]);
    $viewer = is_logged_in() ? current_user() : null;
    $state = student_eval_state($se);

    if (get('done') === '1' && $already) {
        $view = 'done';
    } elseif ($already) {
        $view = 'message';
        $message = ['icon' => 'bi-check2-circle text-success', 'title' => 'ท่านประเมินแล้ว', 'text' => 'ขอบคุณที่ร่วมประเมินการสอน เครื่องนี้ได้ส่งแบบประเมินของรายวิชานี้แล้ว'];
    } elseif ($viewer && (int)$viewer['id'] === (int)$se['teacher_id']) {
        $view = 'message';
        $message = ['icon' => 'bi-person-x text-danger', 'title' => 'ครูไม่สามารถประเมินตนเองได้', 'text' => 'แบบประเมินนี้สำหรับนักเรียน นักศึกษาเท่านั้น'];
    } elseif ($state !== 'open') {
        $view = 'message';
        $texts = [
            'inactive' => ['bi-pause-circle text-secondary', 'ปิดการประเมินชั่วคราว', 'กรุณาสอบถามครูผู้สอนหรืองานพัฒนาหลักสูตรฯ'],
            'upcoming' => ['bi-hourglass-top text-info', 'ยังไม่เปิดให้ประเมิน', 'เปิดให้ประเมิน ' . thai_datetime($se['open_at'])],
            'closed'   => ['bi-lock text-dark', 'ปิดการประเมินแล้ว', 'หมดเวลาประเมินเมื่อ ' . thai_datetime($se['close_at'])],
            'full'     => ['bi-people-fill text-warning', 'มีผู้ประเมินครบจำนวนแล้ว', 'ขอบคุณที่ให้ความสนใจ'],
        ];
        [$icon, $title, $text] = $texts[$state];
        $message = ['icon' => $icon, 'title' => $title, 'text' => $text];
    }
}

$criteria = ($se && $view === 'form') ? supervision_criteria_active('student') : [];
if ($se && $view === 'form' && !$criteria) {
    $view = 'message';
    $message = ['icon' => 'bi-tools text-secondary', 'title' => 'แบบประเมินยังไม่พร้อม', 'text' => 'ยังไม่มีหัวข้อประเมิน กรุณาแจ้งงานพัฒนาหลักสูตรฯ'];
}

// =====================================================================
// บันทึกคำตอบ
// =====================================================================
if (is_post() && $se && $view === 'form') {
    csrf_verify();
    $scoresIn = $_POST['score'] ?? [];
    $scores = [];
    foreach ($criteria as $c) {
        $v = (int)($scoresIn[$c['id']] ?? 0);
        if ($v < 1 || $v > 5) {
            $errors['score'] = 'กรุณาตอบให้ครบทุกข้อ';
        }
        $scores[] = ['c' => $c, 'score' => $v];
    }
    $comment = trim(mb_substr(post('comment'), 0, 1000));

    // ช่องดักบอท: ผู้ใช้จริงมองไม่เห็นช่องนี้ ถ้ามีค่าแปลว่าเป็นโปรแกรมอัตโนมัติ
    if (post('website') !== '') {
        $errors['score'] = 'ไม่สามารถบันทึกได้';
    }

    if (!$errors) {
        $avg = round(array_sum(array_column($scores, 'score')) / count($scores), 2);
        db()->begin_transaction();
        try {
            // ล็อกแถวของรายการ ตรวจจำนวนผู้ตอบอีกครั้ง (กันส่งพร้อมกันจนเกินจำนวน)
            $row = db_one('SELECT id, max_responses, is_active, open_at, close_at FROM student_eval_sessions WHERE id = ? FOR UPDATE', 'i', [(int)$se['id']]);
            $row['responses'] = (int)db_value('SELECT COUNT(*) FROM student_eval_responses WHERE session_id = ?', 'i', [(int)$se['id']]);
            if (student_eval_state($row) !== 'open') {
                db()->rollback();
                redirect('public/evaluate.php?t=' . $se['token']);
            }
            $respId = db_insert(
                'INSERT INTO student_eval_responses (session_id, avg_score, comment) VALUES (?, ?, ?)',
                'ids',
                [(int)$se['id'], $avg, $comment !== '' ? $comment : null]
            );
            foreach ($scores as $s) {
                db_insert(
                    'INSERT INTO student_eval_scores (response_id, criteria_id, category, criteria_title, score) VALUES (?, ?, ?, ?, ?)',
                    'iissi',
                    [$respId, (int)$s['c']['id'], $s['c']['category'], $s['c']['title'], $s['score']]
                );
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollback();
            throw $ex;
        }
        // ผู้ตอบไม่ระบุตัวตน — บันทึกเฉพาะรายการประเมิน (ไม่เก็บคะแนน/ความคิดเห็นรายคนใน log)
        log_activity('student_eval_response', 'นักเรียนตอบแบบประเมินโดยนักเรียน #' . (int)$se['id'] . ' ' . $se['subject_name']
            . ' ' . $se['classroom'] . ' ของ ' . $se['teacher_name'] . ' (คำตอบ #' . $respId . ')');
        // จำว่าเครื่องนี้ประเมินแล้ว
        $_SESSION['student_eval_done'][(int)$se['id']] = 1;
        setcookie(student_eval_cookie((int)$se['id']), '1', [
            'expires'  => strtotime($se['close_at']) + 90 * 86400,
            'path'     => BASE_URL === '' ? '/' : BASE_URL . '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        redirect('public/evaluate.php?t=' . $se['token'] . '&done=1');
    }
}

$logo = logo_url();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>ประเมินการสอน | <?= e(system_name()) ?></title>
    <link rel="icon" href="<?= e(asset('img/logo.svg')) ?>" type="image/svg+xml">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= e(asset('css/style.css')) ?>" rel="stylesheet">
    <style>
        :root {
            --th-primary: <?= e(theme_color('color_primary', COLOR_PRIMARY_DEFAULT)) ?>;
            --th-accent: <?= e(theme_color('color_accent', COLOR_ACCENT_DEFAULT)) ?>;
        }
        body { background: var(--th-bg); }
        .eval-head { background: linear-gradient(150deg, var(--th-primary), #0e1930); color: #fff; padding: 1.25rem 0 3rem; }
        .eval-head img { width: 56px; height: 56px; border-radius: 50%; background: #fff; padding: 4px; }
        .eval-wrap { max-width: 720px; margin: -2.2rem auto 2rem; padding: 0 12px; }
        .q-item { padding: 1rem; border-bottom: 1px solid #eef1f7; }
        .q-item.missing { background: #fff5f5; }
        .q-scale .btn { font-size: .78rem; padding: .45rem .1rem; line-height: 1.15; }
        .q-scale .btn strong { display: block; font-size: 1.05rem; }
        .btn-check:checked + .btn-outline-primary { background: var(--th-accent); border-color: var(--th-accent); color: #fff; }
        .hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
        .progress-sticky { position: sticky; bottom: 0; background: #fff; border-top: 1px solid #e5e7eb; padding: .75rem 1rem; z-index: 5; }
    </style>
</head>
<body>
<div class="eval-head">
    <div class="container d-flex align-items-center gap-3" style="max-width:720px">
        <img src="<?= e($logo) ?>" alt="โลโก้">
        <div class="lh-sm">
            <div class="small opacity-75"><?= e(college_name()) ?></div>
            <div class="fw-bold fs-5">แบบประเมินการสอนโดยนักเรียน นักศึกษา</div>
        </div>
    </div>
</div>

<div class="eval-wrap">
    <?php if ($se): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="small text-muted">ครูผู้สอน</div>
                <div class="fs-5 fw-bold text-primary-th"><?= e($se['teacher_name']) ?></div>
                <div><i class="bi bi-book me-1 text-accent"></i><?= e($se['subject_name']) ?><?= $se['classroom'] ? ' · ' . e($se['classroom']) : '' ?></div>
                <div class="small text-muted mt-1">ภาคเรียน <?= $se['ay_year'] ? e($se['ay_semester'] . '/' . $se['ay_year']) : '-' ?>
                    · ปิดประเมิน <?= e(thai_datetime($se['close_at'])) ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($view === 'done'): ?>
        <div class="card text-center"><div class="card-body py-5">
            <i class="bi bi-heart-fill text-accent" style="font-size:3.5rem"></i>
            <h4 class="mt-3 fw-bold">ขอบคุณที่ร่วมประเมิน</h4>
            <p class="text-muted mb-0">คำตอบของท่านถูกบันทึกแบบไม่ระบุตัวตน<br>และจะนำไปใช้พัฒนาการจัดการเรียนการสอน</p>
        </div></div>

    <?php elseif ($view === 'message'): ?>
        <div class="card text-center"><div class="card-body py-5">
            <i class="bi <?= e($message['icon']) ?>" style="font-size:3.5rem"></i>
            <h4 class="mt-3 fw-bold"><?= e($message['title']) ?></h4>
            <p class="text-muted mb-0"><?= e($message['text']) ?></p>
        </div></div>

    <?php else: ?>
        <div class="alert alert-light border small">
            <i class="bi bi-shield-check text-success me-1"></i>
            <strong>ไม่ต้องใส่ชื่อ</strong> ระบบไม่เก็บข้อมูลส่วนตัวของผู้ตอบ โปรดตอบตามความเป็นจริง
            (5 = มากที่สุด, 4 = มาก, 3 = ปานกลาง, 2 = น้อย, 1 = น้อยที่สุด)
            <a href="<?= e(url('public/pdpa.php')) ?>" target="_blank" class="d-block mt-1">อ่านข้อตกลงการคุ้มครองข้อมูลส่วนบุคคล (PDPA)</a>
        </div>
        <?php if ($errors): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= e($errors['score']) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('public/evaluate.php')) ?>" novalidate id="evalForm">
            <?= csrf_field() ?>
            <input type="hidden" name="t" value="<?= e($se['token']) ?>">
            <div class="hp" aria-hidden="true"><label>เว็บไซต์ <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <div class="card mb-3">
                <?php $lastCat = null; $no = 0; foreach ($criteria as $c): $no++;
                    $val = (int)($_POST['score'][$c['id']] ?? 0); ?>
                    <?php if ($c['category'] !== $lastCat): $lastCat = $c['category']; ?>
                        <div class="card-header"><?= e($c['category']) ?></div>
                    <?php endif; ?>
                    <div class="q-item<?= is_post() && !$val ? ' missing' : '' ?>">
                        <div class="mb-2"><span class="fw-semibold"><?= $no ?>.</span> <?= e($c['title']) ?></div>
                        <div class="btn-group w-100 q-scale" role="group" aria-label="<?= e($c['title']) ?>">
                            <?php foreach (student_score_labels() as $n => $lb): $rid = 'q' . (int)$c['id'] . '_' . $n; ?>
                                <input type="radio" class="btn-check q-input" name="score[<?= (int)$c['id'] ?>]" id="<?= $rid ?>" value="<?= $n ?>" <?= $val === $n ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="<?= $rid ?>"><strong><?= $n ?></strong><?= e($lb) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="q-item">
                    <label class="form-label fw-semibold" for="comment">ข้อเสนอแนะถึงครู (ไม่บังคับ)</label>
                    <textarea class="form-control" name="comment" id="comment" rows="3" maxlength="1000"
                              placeholder="สิ่งที่ชอบ หรือสิ่งที่อยากให้ครูปรับปรุง"><?= e(post('comment')) ?></textarea>
                </div>
                <div class="progress-sticky d-flex align-items-center gap-3 rounded-bottom">
                    <div class="flex-grow-1">
                        <div class="small">ตอบแล้ว <strong id="answered">0</strong>/<?= count($criteria) ?> ข้อ</div>
                        <div class="progress progress-thin"><div class="progress-bar bg-accent" id="answerBar" style="width:0%"></div></div>
                    </div>
                    <button type="submit" class="btn btn-accent px-4" id="submitBtn"><i class="bi bi-send me-1"></i>ส่งแบบประเมิน</button>
                </div>
            </div>
        </form>
        <script>
            (function () {
                // แสดงความคืบหน้าการตอบ และเตือนก่อนส่งถ้ายังตอบไม่ครบ
                var total = <?= count($criteria) ?>;
                var form = document.getElementById('evalForm');
                function update() {
                    var n = form.querySelectorAll('.q-input:checked').length;
                    document.getElementById('answered').textContent = n;
                    document.getElementById('answerBar').style.width = (n * 100 / total) + '%';
                    return n;
                }
                form.addEventListener('change', update);
                form.addEventListener('submit', function (ev) {
                    if (update() < total) {
                        ev.preventDefault();
                        var first = Array.prototype.find.call(form.querySelectorAll('.q-item'), function (q) {
                            return q.querySelector('.q-input') && !q.querySelector('.q-input:checked');
                        });
                        if (first) { first.classList.add('missing'); first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                        alert('กรุณาตอบให้ครบทุกข้อ');
                        return;
                    }
                    document.getElementById('submitBtn').disabled = true;
                });
                update();
            })();
        </script>
    <?php endif; ?>

    <div class="text-center small text-muted mt-4"><?= e(system_name()) ?> · <?= e(college_name()) ?></div>
</div>
</body>
</html>
