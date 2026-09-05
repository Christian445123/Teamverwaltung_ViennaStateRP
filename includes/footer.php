<?php
defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');
$user = current_user();
?>
<p class="text-muted" style="text-align:center;font-size:12px;margin:24px 0 4px;">
  <a href="<?= url('impressum.php') ?>" style="color:inherit;">Impressum</a> ·
  <a href="<?= url('datenschutz.php') ?>" style="color:inherit;">Datenschutzerklärung</a>
</p>
<?php if ($user): ?>
  </main>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>
