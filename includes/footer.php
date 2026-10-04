<?php $base = isset($base) ? $base : ''; ?>
<?php if (!empty($admin_shell)): ?>
  </div><!-- /admin-main -->
</div><!-- /admin-shell -->
<?php endif; ?>
<script src="<?= $base ?>assets/vendor/bootstrap.bundle.min.js"></script>
<?php if (!empty($extra_scripts)): foreach ($extra_scripts as $s): ?>
<script src="<?= $base . e($s) ?>"></script>
<?php endforeach; endif; ?>
<script src="<?= $base ?>assets/js/app.js"></script>
</body>
</html>
