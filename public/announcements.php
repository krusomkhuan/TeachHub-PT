<?php
/**
 * ไฟล์: public/announcements.php
 * ประกาศข่าวสารจากงานพัฒนาหลักสูตรฯ
 *   - ไม่มี ?id  : แสดงรายการประกาศ (แบ่งหน้า + ค้นหา)
 *   - มี ?id=xx : แสดงรายละเอียดประกาศ
 */
require_once __DIR__ . '/../config/config.php';

$id = get_int('id');
$activeNav = 'announcements';

// ===================== หน้ารายละเอียด =====================
if ($id > 0) {
    $item = db_one(
        "SELECT a.*, CONCAT(u.prefix, u.first_name, ' ', u.last_name) AS author
         FROM announcements a LEFT JOIN users u ON u.id = a.created_by
         WHERE a.id = ? AND a.is_published = 1 AND a.published_at <= NOW()",
        'i',
        [$id]
    );
    if (!$item) {
        abort_page(404, 'ไม่พบประกาศ', 'ประกาศนี้อาจถูกลบหรือยังไม่เผยแพร่');
    }

    // นับจำนวนผู้เข้าชม (นับครั้งเดียวต่อ session)
    $_SESSION['viewed_news'] = $_SESSION['viewed_news'] ?? [];
    if (!in_array($id, $_SESSION['viewed_news'], true)) {
        db_exec('UPDATE announcements SET views = views + 1 WHERE id = ?', 'i', [$id]);
        $_SESSION['viewed_news'][] = $id;
        $item['views']++;
    }

    $others = db_all(
        'SELECT id, title, published_at FROM announcements
         WHERE is_published = 1 AND published_at <= NOW() AND id <> ?
         ORDER BY published_at DESC LIMIT 5',
        'i',
        [$id]
    );

    $pageTitle = $item['title'];
    require APP_ROOT . '/includes/public_header.php';
    ?>
    <div class="container py-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb small">
                <li class="breadcrumb-item"><a href="<?= e(url('public/index.php')) ?>">หน้าแรก</a></li>
                <li class="breadcrumb-item"><a href="<?= e(url('public/announcements.php')) ?>">ประกาศข่าวสาร</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?= e(str_limit($item['title'], 50)) ?></li>
            </ol>
        </nav>
        <div class="row g-4">
            <div class="col-lg-8">
                <article class="card">
                    <div class="card-body p-4">
                        <?php if ($item['is_pinned']): ?>
                            <span class="badge bg-accent mb-2"><i class="bi bi-pin-angle-fill me-1"></i>ประกาศสำคัญ</span>
                        <?php endif; ?>
                        <h2 class="h3 fw-bold text-primary-th"><?= e($item['title']) ?></h2>
                        <div class="small text-muted mb-4">
                            <i class="bi bi-calendar3 me-1"></i><?= e(thai_datetime($item['published_at'], true)) ?>
                            <?php if ($item['author']): ?> · <i class="bi bi-person me-1"></i><?= e($item['author']) ?><?php endif; ?>
                            · <i class="bi bi-eye me-1"></i><?= number_format((int)$item['views']) ?> ครั้ง
                        </div>
                        <div class="fs-6 lh-lg"><?= nl2br(e($item['content'])) ?></div>
                    </div>
                </article>
                <a href="<?= e(url('public/announcements.php')) ?>" class="btn btn-outline-secondary mt-3"><i class="bi bi-arrow-left"></i> กลับรายการประกาศ</a>
            </div>
            <div class="col-lg-4">
                <h5 class="section-title">ประกาศอื่น ๆ</h5>
                <div class="card">
                    <?php foreach ($others as $o): ?>
                        <a class="news-item" href="<?= e(url('public/announcements.php?id=' . (int)$o['id'])) ?>">
                            <div class="news-title small"><?= e($o['title']) ?></div>
                            <div class="small text-muted"><?= e(thai_date($o['published_at'])) ?></div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$others): ?><div class="empty-state small">ไม่มีประกาศอื่น</div><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
    require APP_ROOT . '/includes/public_footer.php';
    exit;
}

// ===================== หน้ารายการ =====================
$q = mb_substr(get('q'), 0, 100);
$perPage = 10;
$page = max(1, get_int('page', 1));

$where = 'is_published = 1 AND published_at <= NOW()';
$types = '';
$params = [];
if ($q !== '') {
    $where .= ' AND (title LIKE ? OR content LIKE ?)';
    $like = '%' . $q . '%';
    $types = 'ss';
    $params = [$like, $like];
}

$total = (int)db_value("SELECT COUNT(*) FROM announcements WHERE $where", $types, $params);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$items = db_all(
    "SELECT id, title, content, is_pinned, views, published_at FROM announcements
     WHERE $where ORDER BY is_pinned DESC, published_at DESC LIMIT ? OFFSET ?",
    $types . 'ii',
    array_merge($params, [$perPage, $offset])
);

$pageTitle = 'ประกาศข่าวสาร';
require APP_ROOT . '/includes/public_header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
        <h3 class="section-title mb-0">ประกาศข่าวสาร</h3>
        <form class="ms-auto d-flex gap-2" method="get" role="search">
            <input type="search" class="form-control" name="q" value="<?= e($q) ?>" placeholder="ค้นหาประกาศ..." aria-label="ค้นหาประกาศ">
            <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <div class="card">
        <?php foreach ($items as $n): ?>
            <a class="news-item" href="<?= e(url('public/announcements.php?id=' . (int)$n['id'])) ?>">
                <div class="d-flex gap-3">
                    <div class="text-center flex-shrink-0 d-none d-sm-block" style="width:64px">
                        <div class="fs-3 fw-bold text-accent lh-1"><?= (int)date('j', strtotime($n['published_at'])) ?></div>
                        <div class="small text-muted"><?= e(thai_months()[(int)date('n', strtotime($n['published_at']))]) ?> <?= thai_year(strtotime($n['published_at'])) ?></div>
                    </div>
                    <div class="flex-grow-1">
                        <div class="news-title">
                            <?php if ($n['is_pinned']): ?><i class="bi bi-pin-angle-fill text-accent me-1"></i><?php endif; ?>
                            <?= e($n['title']) ?>
                        </div>
                        <div class="small mb-1"><?= e(str_limit($n['content'], 200)) ?></div>
                        <div class="small text-muted"><i class="bi bi-eye me-1"></i><?= number_format((int)$n['views']) ?> · <?= e(thai_date($n['published_at'])) ?></div>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if (!$items): ?>
            <div class="empty-state"><i class="bi bi-megaphone"></i><?= $q !== '' ? 'ไม่พบประกาศที่ค้นหา' : 'ยังไม่มีประกาศ' ?></div>
        <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mt-3" aria-label="แบ่งหน้า">
            <ul class="pagination justify-content-center">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <li class="page-item<?= $i === $page ? ' active' : '' ?>">
                        <a class="page-link" href="?<?= e(http_build_query(['q' => $q, 'page' => $i])) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/public_footer.php'; ?>
