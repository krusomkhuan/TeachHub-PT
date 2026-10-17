<?php
/**
 * ไฟล์: public/downloads.php
 * ดาวน์โหลดเอกสาร/แบบฟอร์มต้นฉบับ (แบบฟอร์มแผนการสอน, แบบ PA, เค้าโครงวิจัย, แบบนิเทศ)
 */
require_once __DIR__ . '/../config/config.php';

$categories = download_categories();
$cat = get('cat');
if (!isset($categories[$cat])) {
    $cat = '';
}

$sql = 'SELECT id, title, description, category, original_name, file_size, download_count, created_at
        FROM downloads WHERE is_active = 1 AND stored_name IS NOT NULL';
$types = '';
$params = [];
if ($cat !== '') {
    $sql .= ' AND category = ?';
    $types = 's';
    $params = [$cat];
}
$sql .= ' ORDER BY category, title';
$docs = db_all($sql, $types, $params);

// จำนวนเอกสารในแต่ละหมวด
$counts = [];
foreach (db_all('SELECT category, COUNT(*) AS c FROM downloads WHERE is_active = 1 AND stored_name IS NOT NULL GROUP BY category') as $r) {
    $counts[$r['category']] = (int)$r['c'];
}

$pageTitle = 'ดาวน์โหลดเอกสาร';
$activeNav = 'downloads';
require APP_ROOT . '/includes/public_header.php';
?>
<div class="container py-4">
    <h3 class="section-title">ดาวน์โหลดเอกสาร / แบบฟอร์มต้นฉบับ</h3>

    <!-- แท็บหมวดหมู่ -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <a href="<?= e(url('public/downloads.php')) ?>" class="btn btn-sm <?= $cat === '' ? 'btn-primary' : 'btn-outline-primary' ?>">
            ทั้งหมด <span class="badge bg-light text-dark ms-1"><?= array_sum($counts) ?></span>
        </a>
        <?php foreach ($categories as $k => $label): ?>
            <a href="?cat=<?= e($k) ?>" class="btn btn-sm <?= $cat === $k ? 'btn-primary' : 'btn-outline-primary' ?>">
                <?= e($label) ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$k] ?? 0 ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($docs): ?>
        <div class="row g-3">
            <?php foreach ($docs as $d): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body d-flex gap-3">
                            <i class="bi <?= e(file_icon($d['original_name'])) ?>" style="font-size:2.6rem"></i>
                            <div class="flex-grow-1 min-w-0">
                                <span class="badge bg-light text-dark border mb-1"><?= e($categories[$d['category']] ?? '') ?></span>
                                <h6 class="fw-bold mb-1"><?= e($d['title']) ?></h6>
                                <?php if ($d['description']): ?><p class="small text-muted mb-2"><?= e($d['description']) ?></p><?php endif; ?>
                                <div class="small text-muted">
                                    <?= e(strtoupper(pathinfo((string)$d['original_name'], PATHINFO_EXTENSION))) ?> ·
                                    <?= e(format_bytes((int)$d['file_size'])) ?> ·
                                    <i class="bi bi-download"></i> <?= number_format((int)$d['download_count']) ?> ครั้ง
                                </div>
                            </div>
                        </div>
                        <div class="card-footer bg-white border-0 pt-0 pb-3 px-3">
                            <a href="<?= e(url('public/download.php?id=' . (int)$d['id'])) ?>" class="btn btn-accent btn-sm w-100">
                                <i class="bi bi-download me-1"></i> ดาวน์โหลด
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card"><div class="empty-state"><i class="bi bi-folder2-open"></i>ยังไม่มีเอกสารในหมวดนี้</div></div>
    <?php endif; ?>
</div>
<?php require APP_ROOT . '/includes/public_footer.php'; ?>
