<?php
/**
 * ไฟล์: includes/auth_footer.php
 * ส่วนท้ายของหน้ากลุ่มยืนยันตัวตน
 */
?>
        </div>
        <footer class="auth-footer">
            &copy; <?= thai_year() ?> <?= e(college_name()) ?> · <?= e(system_name()) ?> v<?= e(APP_VERSION) ?><br>
            พัฒนาระบบโดย <?= e(APP_DEVELOPER) ?> · <a href="<?= e(url('public/pdpa.php')) ?>" target="_blank">ข้อตกลง PDPA</a>
        </footer>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.14.5/dist/sweetalert2.all.min.js"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
