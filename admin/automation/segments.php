<?php
require_once __DIR__ . '/../../includes/config.php';
requireAdmin();

// Add/remove tag from user
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $form = $_POST['form'] ?? '';

    if ($form === 'add_tag') {
        $userId = (int)$_POST['user_id'];
        $tagId  = (int)$_POST['tag_id'];
        if ($userId && $tagId) {
            db()->prepare("INSERT IGNORE INTO user_tags (user_id,tag_id) VALUES (?,?)")->execute([$userId,$tagId]);
            flash('success', 'Tag added.');
        }
    } elseif ($form === 'remove_tag') {
        $userId = (int)$_POST['user_id'];
        $tagId  = (int)$_POST['tag_id'];
        db()->prepare("DELETE FROM user_tags WHERE user_id=? AND tag_id=?")->execute([$userId,$tagId]);
        flash('success', 'Tag removed.');
    } elseif ($form === 'create_tag') {
        $name   = trim($_POST['tag_name'] ?? '');
        $colour = trim($_POST['colour'] ?? '#00A878');
        $desc   = trim($_POST['description'] ?? '');
        if ($name) {
            db()->prepare("INSERT IGNORE INTO automation_tags (name,colour,description) VALUES (?,?,?)")
                 ->execute([$name,$colour,$desc]);
            flash('success', "Tag '$name' created.");
        }
    } elseif ($form === 'delete_tag') {
        $tagId = (int)$_POST['tag_id'];
        db()->prepare("DELETE FROM automation_tags WHERE id=?")->execute([$tagId]);
        flash('success', 'Tag deleted.');
    }
    redirect(SITE_URL . '/admin/automation/segments.php');
}

// Load tags with user counts
$tags = db()->query("
    SELECT t.*, COUNT(ut.user_id) AS user_count
    FROM automation_tags t
    LEFT JOIN user_tags ut ON ut.tag_id = t.id
    GROUP BY t.id ORDER BY user_count DESC, t.name
")->fetchAll();

// Load users with their tags for the user tag panel
$filterTag = $_GET['tag'] ?? '';
$search    = trim($_GET['q'] ?? '');
$where = ['1=1'];
$params = [];

if ($filterTag) {
    $where[] = "EXISTS (SELECT 1 FROM user_tags ut JOIN automation_tags at ON at.id=ut.tag_id WHERE ut.user_id=u.id AND at.name=?)";
    $params[] = $filterTag;
}
if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%"]);
}

$totalUsers = count($params)
    ? db()->prepare("SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $where))->execute($params) ? db()->prepare("SELECT COUNT(*) FROM users u WHERE " . implode(' AND ', $where)) : null
    : null;

$userSt = db()->prepare("
    SELECT u.id, u.name, u.email, u.role, u.verified, u.created_at,
           GROUP_CONCAT(at.name ORDER BY at.name SEPARATOR '|||') AS tag_names,
           GROUP_CONCAT(at.id  ORDER BY at.name SEPARATOR ',')    AS tag_ids,
           GROUP_CONCAT(at.colour ORDER BY at.name SEPARATOR ',') AS tag_colours
    FROM users u
    LEFT JOIN user_tags ut ON ut.user_id = u.id
    LEFT JOIN automation_tags at ON at.id = ut.tag_id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY u.id ORDER BY u.created_at DESC LIMIT 50
");
$userSt->execute($params);
$users = $userSt->fetchAll();

$allTags = db()->query("SELECT * FROM automation_tags ORDER BY name")->fetchAll();

$pageTitle = 'Segments & Tags — Admin 237Biz';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header"><div class="container">
  <h1 style="font-family:'Fraunces',serif;font-weight:900;font-size:2rem;">🏷️ Segments & Tags</h1>
</div></div>

<section class="page-section"><div class="container">

  <div class="filter-tabs" style="margin-bottom:2rem;flex-wrap:wrap;">
    <a href="<?= SITE_URL ?>/admin/" class="filter-tab">📋 Listings</a>
    <a href="<?= SITE_URL ?>/admin/users.php" class="filter-tab">👤 Users</a>
    <a href="<?= SITE_URL ?>/admin/orders.php" class="filter-tab">📦 Orders</a>
    <a href="<?= SITE_URL ?>/admin/automation/" class="filter-tab">🤖 Automation</a>
    <a href="<?= SITE_URL ?>/admin/automation/templates.php" class="filter-tab">✉️ Templates</a>
    <a href="<?= SITE_URL ?>/admin/automation/sequences.php" class="filter-tab">🔗 Sequences</a>
    <a href="<?= SITE_URL ?>/admin/automation/segments.php" class="filter-tab active">🏷️ Segments</a>
    <a href="<?= SITE_URL ?>/admin/automation/log.php" class="filter-tab">📊 Send Log</a>
    <a href="<?= SITE_URL ?>/dashboard" class="filter-tab">← Dashboard</a>
  </div>

  <!-- Tag overview -->
  <div style="display:flex;flex-wrap:wrap;gap:0.6rem;margin-bottom:2rem;align-items:center;">
    <a href="?" class="btn btn-sm <?= !$filterTag ? 'btn-primary' : 'btn-outline' ?>" style="font-size:0.78rem;">All users</a>
    <?php foreach ($tags as $tag): ?>
      <a href="?tag=<?= urlencode($tag['name']) ?>"
         class="btn btn-sm <?= $filterTag===$tag['name'] ? 'btn-primary' : 'btn-outline' ?>"
         style="font-size:0.78rem;border-color:<?= e($tag['colour']) ?>20;<?= $filterTag===$tag['name'] ? 'background:'.$tag['colour'].';color:#fff;border-color:'.$tag['colour'].';' : 'color:'.$tag['colour'].';' ?>">
        <?= e($tag['name']) ?> <span style="opacity:0.7;">(<?= $tag['user_count'] ?>)</span>
      </a>
    <?php endforeach; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 300px;gap:1.5rem;align-items:start;">

    <!-- Users list -->
    <div>
      <form method="GET" style="margin-bottom:1rem;display:flex;gap:0.5rem;">
        <?php if ($filterTag): ?><input type="hidden" name="tag" value="<?= e($filterTag) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Search name or email..."
               style="flex:1;background:var(--card);border:1px solid var(--border);border-radius:8px;color:var(--white);padding:0.55rem 0.75rem;font-size:0.875rem;">
        <button type="submit" class="btn btn-primary btn-sm">Search</button>
        <?php if ($search || $filterTag): ?><a href="?" class="btn btn-outline btn-sm">Clear</a><?php endif; ?>
      </form>

      <p style="font-size:0.78rem;color:var(--muted-2);margin-bottom:0.75rem;"><?= count($users) ?> users shown<?= $filterTag ? ' tagged "' . e($filterTag) . '"' : '' ?></p>

      <div style="display:flex;flex-direction:column;gap:0.5rem;">
        <?php foreach ($users as $u): ?>
          <?php
          $userTagNames   = $u['tag_names'] ? explode('|||', $u['tag_names']) : [];
          $userTagIds     = $u['tag_ids'] ? explode(',', $u['tag_ids']) : [];
          $userTagColours = $u['tag_colours'] ? explode(',', $u['tag_colours']) : [];
          ?>
          <div class="listing-widget" style="padding:0.85rem 1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;flex-wrap:wrap;">
              <div>
                <div style="font-size:0.875rem;font-weight:500;color:var(--white);"><?= e($u['name']) ?>
                  <?php if ($u['role']==='admin'): ?><span class="badge badge-approved" style="font-size:0.6rem;">admin</span><?php endif; ?>
                  <?php if (!$u['verified']): ?><span class="badge badge-rejected" style="font-size:0.6rem;">unverified</span><?php endif; ?>
                </div>
                <div style="font-size:0.75rem;color:var(--muted-2);"><?= e($u['email']) ?> · joined <?= timeAgo($u['created_at']) ?></div>
                <div style="display:flex;flex-wrap:wrap;gap:0.3rem;margin-top:0.4rem;">
                  <?php foreach ($userTagNames as $i => $tn): ?>
                    <span style="display:inline-flex;align-items:center;gap:0.3rem;background:rgba(255,255,255,0.06);border-radius:20px;padding:0.15rem 0.6rem;font-size:0.68rem;color:<?= e($userTagColours[$i] ?? '#aaa') ?>;">
                      <?= e($tn) ?>
                      <form method="POST" style="display:inline;margin:0;" onsubmit="return confirm('Remove tag?')">
                        <input type="hidden" name="csrf" value="<?= csrf() ?>">
                        <input type="hidden" name="form" value="remove_tag">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <input type="hidden" name="tag_id" value="<?= $userTagIds[$i] ?? 0 ?>">
                        <button type="submit" style="background:none;border:none;color:inherit;cursor:pointer;padding:0;line-height:1;opacity:0.6;">×</button>
                      </form>
                    </span>
                  <?php endforeach; ?>
                </div>
              </div>
              <!-- Add tag to this user -->
              <form method="POST" style="display:flex;gap:0.3rem;align-items:center;flex-shrink:0;">
                <input type="hidden" name="csrf" value="<?= csrf() ?>">
                <input type="hidden" name="form" value="add_tag">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <select name="tag_id" style="font-size:0.75rem;background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--white);padding:0.3rem 0.5rem;">
                  <option value="">+ tag</option>
                  <?php foreach ($allTags as $t): if (in_array($t['name'], $userTagNames)) continue; ?>
                    <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-outline" style="font-size:0.72rem;padding:0.3rem 0.6rem;">Add</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Tag management sidebar -->
    <div>
      <div class="listing-widget" style="margin-bottom:1rem;">
        <h4 style="margin-bottom:1rem;font-size:0.9rem;">Create New Tag</h4>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= csrf() ?>">
          <input type="hidden" name="form" value="create_tag">
          <div class="form-group"><label style="font-size:0.78rem;">Tag Name *</label><input type="text" name="tag_name" required placeholder="e.g. vip-customer"></div>
          <div class="form-row" style="gap:0.5rem;">
            <div class="form-group" style="margin-bottom:0.5rem;"><label style="font-size:0.78rem;">Colour</label><input type="color" name="colour" value="#00A878" style="width:100%;height:36px;background:var(--card);border:1px solid var(--border);border-radius:6px;"></div>
          </div>
          <div class="form-group"><label style="font-size:0.78rem;">Description</label><input type="text" name="description" placeholder="What this tag means"></div>
          <button type="submit" class="btn btn-primary btn-sm btn-full">Create Tag</button>
        </form>
      </div>

      <div class="listing-widget">
        <h4 style="margin-bottom:0.75rem;font-size:0.9rem;">All Tags</h4>
        <div style="display:flex;flex-direction:column;gap:0.5rem;">
          <?php foreach ($tags as $tag): ?>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.4rem 0;border-bottom:1px solid var(--border);font-size:0.8rem;">
              <div>
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= e($tag['colour']) ?>;margin-right:0.4rem;"></span>
                <span style="color:var(--white);"><?= e($tag['name']) ?></span>
                <span style="color:var(--muted-2);margin-left:0.3rem;">(<?= $tag['user_count'] ?>)</span>
              </div>
              <?php if ($tag['user_count'] == 0): ?>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete tag?')">
                  <input type="hidden" name="csrf" value="<?= csrf() ?>">
                  <input type="hidden" name="form" value="delete_tag">
                  <input type="hidden" name="tag_id" value="<?= $tag['id'] ?>">
                  <button type="submit" style="background:none;border:none;color:#ff6b6b;cursor:pointer;font-size:0.75rem;">✗</button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div>

</div></section>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
