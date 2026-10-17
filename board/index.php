<?php
/**
 * ไฟล์: board/index.php
 * กระดานข่าว — รายการกระทู้ + ตั้งกระทู้ใหม่ (เฉพาะสมาชิกที่เข้าสู่ระบบ)
 *   ?cat=general|question|feature|bug  ?status=  ?q=คำค้น  ?mine=1  ?page=
 *   ?new=1  เปิดฟอร์มตั้งกระทู้ (?new=1&cat=feature เลือกหมวดให้)
 */
require_once __DIR__ . '/../config/config.php';

$user = require_login();
$uid = (int)$user['id'];
$self = 'board/index.php';
$errors = [];

// ---------- ตั้งกระทู้ใหม่ ----------
if (is_post() && post('action') === 'create') {
    csrf_verify();
    $cat = post('category');
    $title = mb_substr(post('title'), 0, 200);
    $body = trim((string)($_POST['body'] ?? ''));
    if (!isset(board_categories()[$cat])) {
        $errors['category'] = 'กรุณาเลือกหมวด';
    }
    $errors += board_validate($title, $body);
    if (!$errors && board_rate_limited($uid)) {
        $errors['body'] = 'โพสต์ถี่เกินไป กรุณารอสักครู่แล้วลองใหม่';
    }
    if (!$errors) {
        $id = db_insert(
            'INSERT INTO board_topics (user_id, category, title, body) VALUES (?, ?, ?, ?)',
            'isss',
            [$uid, $cat, $title, $body]
        );
        log_activity('board_topic', 'ตั้งกระทู้ #' . $id . ' ' . $title);
        flash('success', 'ตั้งกระทู้เรียบร้อยแล้ว');
        redirect('board/topic.php?id=' . $id);
    }
}

// ---------- รายการ ----------
$fCat = isset(board_categories()[get('cat')]) ? get('cat') : '';
$fStatus = isset(board_statuses()[get('status')]) ? get('status') : '';
$fQ = mb_substr(trim(get('q')), 0, 100);
$fMine = get('mine') === '1';
$page = max(1, get_int('page', 1));
$perPage = 20;

$where = ['1 = 1'];
$types = '';
$params = [];
if ($fCat !== '') {
    $where[] = 't.category = ?';
    $types .= 's';
    $params[] = $fCat;
}
if ($fStatus !== '') {
    $where[] = 't.status = ?';
    $types .= 's';
    $params[] = $fStatus;
}
if ($fQ !== '') {
    $where[] = '(t.title LIKE ? OR t.body LIKE ?)';
    $types .= 'ss';
    $like = '%' . addcslashes($fQ, '%_\\') . '%';
    $params[] = $like;
    $params[] = $like;
}
if ($fMine) {
    $where[] = 't.user_id = ?';
    $types .= 'i';
    $params[] = $uid;
}
$whereSql = implode(' AND ', $where);
$total = (int)db_value("SELECT COUNT(*) FROM board_topics t WHERE $whereSql", $types, $params);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$topics = db_all(
    "SELECT t.*, u.prefix, u.first_name, u.last_name, u.avatar, u.id AS author_id
     FROM board_topics t LEFT JOIN users u ON u.id = t.user_id
     WHERE $whereSql
     ORDER BY t.is_pinned DESC, COALESCE(t.last_reply_at, t.created_at) DESC
     LIMIT " . (($page - 1) * $perPage) . ", $perPage",
    $types,
    $params
);
$counts = [];
foreach (db_all('SELECT category, COUNT(*) AS c FROM board_topics GROUP BY category') as $r) {
    $counts[$r['category']] = (int)$r['c'];
}
$showForm = get('new') === '1' || ($errors && post('action') === 'create');
$q = fn(array $over) => '?' . http_build_query(array_filter(array_merge(
    ['cat' => $fCat, 'status' => $fStatus, 'q' => $fQ, 'mine' => $fMine ? '1' : ''], $over), fn($v) => $v !== '' && $v !== null));
$invalid = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';

$pageTitle = 'กระดานข่าว';
$pageIcon = 'bi-chat-square-text';
$breadcrumbs = ['กระดานข่าว' => null];
$activeMenu = $self;
$pageActions = '<a href="' . e(url($self . '?new=1')) . '" class="btn btn-accent"><i class="bi bi-pencil-square me-1"></i>ตั้งกระทู้ใหม่</a>';
require APP_ROOT . '/includes/header.php';
?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1 text-accent"></i>
    ช่องทางติดต่อประสานงานระหว่างสมาชิก สอบถามการใช้งาน แจ้งปัญหา หรือ <strong>เสนอให้พัฒนาความสามารถเพิ่มเติม</strong>
    · อ่านและโพสต์ได้เฉพาะสมาชิก · กรุณาใช้ถ้อยคำสุภาพ และไม่โพสต์ข้อมูลส่วนบุคคลของผู้อื่น</div>

<?php if ($showForm): ?>
    <div class="card mb-4 border-top border-4 border-warning" id="newTopic">
        <div class="card-header"><i class="bi bi-pencil-square me-2"></i>ตั้งกระทู้ใหม่</div>
        <div class="card-body">
            <form method="post" action="<?= e(url($self)) ?>" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="category">หมวด *</label>
                        <select class="form-select<?= $invalid('category') ?>" name="category" id="category">
                            <?php $selCat = post('category') ?: (get('cat') ?: 'question'); ?>
                            <?php foreach (board_categories() as $k => [$label]): ?>
                                <option value="<?= e($k) ?>" <?= $selCat === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select><?= $err('category') ?>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="title">หัวข้อ *</label>
                        <input class="form-control<?= $invalid('title') ?>" name="title" id="title" maxlength="200" value="<?= old('title') ?>"
                               placeholder="เช่น อยากให้ระบบแจ้งเตือนทางอีเมลเมื่อใกล้ปิดรอบ"><?= $err('title') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="body">รายละเอียด *</label>
                        <textarea class="form-control<?= $invalid('body') ?>" name="body" id="body" rows="6" maxlength="10000"
                                  placeholder="อธิบายสิ่งที่ต้องการสอบถาม/ปัญหาที่พบ (ทำอะไร ที่หน้าไหน เกิดอะไรขึ้น) หรือความสามารถที่อยากให้เพิ่ม"><?= old('body') ?></textarea><?= $err('body') ?>
                    </div>
                </div>
                <div class="d-flex gap-2 justify-content-end mt-3">
                    <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary">ยกเลิก</a>
                    <button class="btn btn-accent px-4" data-loading-text="กำลังโพสต์..."><i class="bi bi-send me-1"></i> โพสต์กระทู้</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- หมวด -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <a href="<?= e($q(['cat' => '', 'page' => null])) ?>" class="btn btn-sm <?= $fCat === '' ? 'btn-primary' : 'btn-outline-primary' ?>">
        ทั้งหมด <span class="badge bg-light text-dark ms-1"><?= array_sum($counts) ?></span></a>
    <?php foreach (board_categories() as $k => [$label, $icon]): ?>
        <a href="<?= e($q(['cat' => $k, 'page' => null])) ?>" class="btn btn-sm <?= $fCat === $k ? 'btn-primary' : 'btn-outline-primary' ?>">
            <i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$k] ?? 0 ?></span></a>
    <?php endforeach; ?>
</div>

<!-- ตัวกรอง -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="cat" value="<?= e($fCat) ?>">
            <div class="col-md-5">
                <label class="form-label small mb-1" for="q">ค้นหา</label>
                <input class="form-control" name="q" id="q" value="<?= e($fQ) ?>" placeholder="หัวข้อหรือข้อความ">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1" for="status">สถานะ</label>
                <select class="form-select" name="status" id="status">
                    <option value="">ทุกสถานะ</option>
                    <?php foreach (board_statuses() as $k => [$label]): ?>
                        <option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="mine" id="mine" value="1" <?= $fMine ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mine">เฉพาะกระทู้ของฉัน</label>
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-fill"><i class="bi bi-funnel"></i> กรอง</button>
                <a href="<?= e(url($self)) ?>" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-x-lg"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- รายการกระทู้ -->
<div class="card">
    <div class="list-group list-group-flush">
        <?php foreach ($topics as $t): ?>
            <a href="<?= e(url('board/topic.php?id=' . (int)$t['id'])) ?>" class="list-group-item list-group-item-action py-3">
                <div class="d-flex gap-3 align-items-start">
                    <img src="<?= e(avatar_url(['id' => $t['author_id'], 'avatar' => $t['avatar']])) ?>" class="avatar-sm mt-1" alt="" loading="lazy">
                    <div class="flex-fill">
                        <div class="d-flex flex-wrap gap-1 mb-1">
                            <?php if ($t['is_pinned']): ?><span class="badge bg-accent"><i class="bi bi-pin-angle-fill me-1"></i>ปักหมุด</span><?php endif; ?>
                            <?= board_category_badge($t['category']) ?> <?= board_status_badge($t['status']) ?>
                        </div>
                        <div class="fw-semibold text-primary-th"><?= e($t['title']) ?></div>
                        <div class="small text-muted"><?= e(board_author($t)) ?> · <?= e(thai_datetime($t['created_at'])) ?></div>
                    </div>
                    <div class="text-end small text-muted text-nowrap">
                        <div><i class="bi bi-chat me-1"></i><?= (int)$t['reply_count'] ?> ตอบ</div>
                        <div><i class="bi bi-eye me-1"></i><?= (int)$t['views'] ?></div>
                        <?php if ($t['last_reply_at']): ?><div>ล่าสุด <?= e(thai_datetime($t['last_reply_at'])) ?></div><?php endif; ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if (!$topics): ?>
            <div class="empty-state"><i class="bi bi-chat-square-text"></i>ยังไม่มีกระทู้<?= $fCat || $fStatus || $fQ || $fMine ? 'ตามเงื่อนไขที่เลือก' : ' — เริ่มตั้งกระทู้แรกได้เลย' ?></div>
        <?php endif; ?>
    </div>
    <?php if ($pages > 1): ?>
        <div class="card-footer d-flex justify-content-center">
            <nav aria-label="หน้ากระทู้"><ul class="pagination pagination-sm mb-0">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e($q(['page' => $p])) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
            </ul></nav>
        </div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
