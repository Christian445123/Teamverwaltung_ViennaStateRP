<?php
defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');
$user = current_user();
?>
<?php if ($user): ?>
  </main>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>
