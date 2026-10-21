<?php
$base = isset($base) ? $base : '';
// Halaman customer dengan tombol/bar tetap di bawah: beri ruang agar footer tidak tertutup
$footerFab = in_array(isset($page) ? $page : '', ['menu', 'cart', 'checkout'], true);
?>
<footer class="site-footer<?= $footerFab ? ' site-footer-fab' : '' ?>">Copyright &copy; <?= date('Y') ?> IT Division</footer>
<?php if (!empty($admin_shell)): ?>
  </div><!-- /admin-main -->
</div><!-- /admin-shell -->
<?php endif; ?>
<script src="<?= $base ?>assets/vendor/bootstrap.bundle.min.js"></script>
<?php if (!empty($extra_scripts)): foreach ($extra_scripts as $s): ?>
<script src="<?= $base . e($s) ?>"></script>
<?php endforeach; endif; ?>
<script src="<?= $base ?>assets/js/app.js?v=<?= asset_ver('assets/js/app.js') ?>"></script>
</body>
</html>
