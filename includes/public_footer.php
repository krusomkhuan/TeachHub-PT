<?php
/**
 * ไฟล์: includes/public_footer.php
 * ส่วนท้ายของหน้าบ้าน
 * ตัวแปรเสริม: $pageScripts (JavaScript เพิ่มเติม), $useChart (true = โหลด Chart.js)
 */
$pageScripts = $pageScripts ?? '';
$useChart = $useChart ?? false;
?>
<footer class="pub-footer mt-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-6">
                <h5 class="text-white fw-bold mb-1"><?= e(system_name()) ?></h5>
                <div><?= e(setting('system_fullname', 'ระบบงานพัฒนาหลักสูตรและการจัดการเรียนรู้')) ?></div>
                <div class="mt-2" style="color:#ffb37a">“<?= e(setting('slogan', 'ทุกงานวิชาการครบ จบในที่เดียว')) ?>”</div>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="text-white"><?= e(setting('department_name', 'งานพัฒนาหลักสูตรและการจัดการเรียนรู้')) ?></div>
                <div><?= e(college_name()) ?></div>
                <div class="mt-2">
                    <a href="<?= e(url('public/calendar.php')) ?>">ปฏิทินส่งงาน</a> ·
                    <a href="<?= e(url('public/downloads.php')) ?>">ดาวน์โหลดเอกสาร</a> ·
                    <a href="<?= e(url('public/pdpa.php')) ?>">ข้อตกลง PDPA</a> ·
                    <a href="<?= e(url('auth/login.php')) ?>">เข้าสู่ระบบ</a>
                </div>
            </div>
        </div>
        <hr class="border-secondary">
        <div class="text-center small">
            &copy; <?= thai_year() ?> <?= e(college_name()) ?> · พัฒนาระบบโดย <?= e(APP_DEVELOPER) ?> · v<?= e(APP_VERSION) ?>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<?php if ($useChart): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?php if ($pageScripts !== ''): ?>
<script>
<?= $pageScripts /* สคริปต์ที่หน้าสร้างเอง ข้อมูลผ่าน json_encode แล้ว */ ?>
</script>
<?php endif; ?>
</body>
</html>
