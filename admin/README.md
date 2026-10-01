# Admin Nav Patch — adds "🩺 Health Check" tab to your existing admin pages

These are your 13 admin/*.php files, each with ONE line added: a
"🩺 Health Check" tab link (pointing to /agent-health-checks.php) inserted
right after the existing "📋 Listings" tab in the .filter-tabs bar.

Nothing else was changed — same diff pattern in every file:

  <a href="<?= SITE_URL ?>/admin/" class="filter-tab...">📋 Listings</a>
  <a href="<?= SITE_URL ?>/agent-health-checks.php" class="filter-tab">🩺 Health Check</a>   <- new line
  <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab...">👤 Users</a>
  ...

Files patched: categories.php, claims.php, enquiries.php, index.php,
leads.php, locations.php, orders.php, packages.php, promos.php, reviews.php,
seo-content.php, subscribers.php, users.php.

Not patched: index-bk.php (looked like a backup file — skipped on purpose),
services.php (doesn't have a .filter-tabs block, so nothing to insert).

## To deploy
Just overwrite these 13 files in your admin/ folder. Diff against your
current versions first if you've changed anything in them since you sent
these to me, so you don't lose other edits.
