<!-- ── Partner footer ─────────────────────────────────────────── -->
<footer class="mt-5 py-3 border-top bg-white">
  <div class="container-fluid px-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 text-muted small">
      <span>&copy; <?= date('Y') ?> 237Biz &mdash; Growth Partner Portal</span>
      <span>
        <a href="<?= defined('SITE_URL') ? rtrim(SITE_URL, '/') : '' ?>/partner/dashboard" class="text-muted text-decoration-none me-3">Partner Home</a>
        <a href="<?= defined('SITE_URL') ? rtrim(SITE_URL, '/') : '' ?>/contact.php" class="text-muted text-decoration-none">Support</a>
      </span>
    </div>
  </div>
</footer>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
