<?php
/**
 * 237biz BHC — Service Catalogue & Provider Network Admin
 * Manages: service categories, services, service providers, provider-service assignments.
 *
 * Access: Super Admin + Operations Manager only.
 */
define('IN_237BIZ', true);
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/bhc-config.php';
require_once __DIR__ . '/includes/bhc-catalogue.php';

$pdo = db();

// ── Auth ──────────────────────────────────────────────────────────────────
$staffId      = isset($_SESSION['staff_id'])      ? (int)$_SESSION['staff_id']      : null;
$isAdmin      = isset($_SESSION['is_admin'])      ? (bool)$_SESSION['is_admin']     : false;
$isOpsManager = !$isAdmin && isset($_SESSION['is_ops_manager']) && $_SESSION['is_ops_manager'];
// Ops Managers may VIEW catalogue but cannot create/edit/delete services or providers
if (!$staffId || (!$isAdmin && !$isOpsManager)) {
    http_response_code(403);
    die('Admin login required.');
}
$canEditCatalogue = $isAdmin; // Ops Managers are read-only on catalogue

// ── AJAX / POST actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = trim($_POST['action'] ?? '');
    $json = fn(array $d, int $c = 200) => (http_response_code($c) && print(json_encode($d))) || exit;

    // Ops Managers cannot perform write operations on the catalogue
    if (!$canEditCatalogue) {
        $json(['ok' => false, 'error' => 'Insufficient permissions. Catalogue edits require Super Admin access.'], 403);
    }

    // ---- Service Categories ----
    if ($action === 'save_category') {
        $id    = (int)($_POST['id'] ?? 0);
        $slug  = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $nameEn= trim($_POST['name_en'] ?? '');
        $nameFr= trim($_POST['name_fr'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive  = isset($_POST['is_active']) ? 1 : 0;

        if (!$slug || !$nameEn) $json(['ok'=>false,'error'=>'Slug and English name required.']);

        if ($id) {
            $stmt = $pdo->prepare(
                "UPDATE bhc_service_categories
                 SET slug=:slug, name_en=:ne, name_fr=:nf, sort_order=:so, is_active=:ia
                 WHERE id=:id"
            );
            $stmt->execute([':slug'=>$slug,':ne'=>$nameEn,':nf'=>$nameFr,':so'=>$sortOrder,':ia'=>$isActive,':id'=>$id]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO bhc_service_categories (slug,name_en,name_fr,sort_order,is_active)
                 VALUES (:slug,:ne,:nf,:so,:ia)"
            );
            $stmt->execute([':slug'=>$slug,':ne'=>$nameEn,':nf'=>$nameFr,':so'=>$sortOrder,':ia'=>$isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        $json(['ok'=>true,'id'=>$id]);
    }

    // ---- Services ----
    if ($action === 'save_service') {
        $id      = (int)($_POST['id'] ?? 0);
        $catId   = (int)($_POST['category_id'] ?? 0);
        $slug    = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $nameEn  = trim($_POST['name_en'] ?? '');
        $nameFr  = trim($_POST['name_fr'] ?? '');
        $descEn  = trim($_POST['description_en'] ?? '');
        $descFr  = trim($_POST['description_fr'] ?? '');
        $pricing = trim($_POST['pricing_info'] ?? '');
        $pricingType = trim($_POST['pricing_type'] ?? 'quote');
        $sortOrder   = (int)($_POST['sort_order'] ?? 0);
        $isFeatured  = isset($_POST['is_featured']) ? 1 : 0;
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (!$slug || !$nameEn || !$catId) $json(['ok'=>false,'error'=>'Category, slug and English name required.']);

        if ($id) {
            $stmt = $pdo->prepare(
                "UPDATE bhc_services
                 SET category_id=:cat, slug=:slug, name_en=:ne, name_fr=:nf,
                     description_en=:de, description_fr=:df,
                     pricing_info=:pi, pricing_type=:pt, sort_order=:so,
                     is_featured=:feat, is_active=:ia
                 WHERE id=:id"
            );
            $stmt->execute([':cat'=>$catId,':slug'=>$slug,':ne'=>$nameEn,':nf'=>$nameFr,
                ':de'=>$descEn,':df'=>$descFr,':pi'=>$pricing,':pt'=>$pricingType,
                ':so'=>$sortOrder,':feat'=>$isFeatured,':ia'=>$isActive,':id'=>$id]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO bhc_services
                 (category_id,slug,name_en,name_fr,description_en,description_fr,
                  pricing_info,pricing_type,sort_order,is_featured,is_active)
                 VALUES (:cat,:slug,:ne,:nf,:de,:df,:pi,:pt,:so,:feat,:ia)"
            );
            $stmt->execute([':cat'=>$catId,':slug'=>$slug,':ne'=>$nameEn,':nf'=>$nameFr,
                ':de'=>$descEn,':df'=>$descFr,':pi'=>$pricing,':pt'=>$pricingType,
                ':so'=>$sortOrder,':feat'=>$isFeatured,':ia'=>$isActive]);
            $id = (int)$pdo->lastInsertId();
        }
        $json(['ok'=>true,'id'=>$id]);
    }

    // ---- Providers ----
    if ($action === 'save_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $fields = [
            'slug'            => preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? ''))),
            'business_name'   => trim($_POST['business_name'] ?? ''),
            'short_bio_en'    => trim($_POST['short_bio_en'] ?? ''),
            'short_bio_fr'    => trim($_POST['short_bio_fr'] ?? ''),
            'website_url'     => trim($_POST['website_url'] ?? ''),
            'email'           => trim($_POST['email'] ?? ''),
            'phone'           => trim($_POST['phone'] ?? ''),
            'whatsapp'        => trim($_POST['whatsapp'] ?? ''),
            'location'        => trim($_POST['location'] ?? ''),
            'areas_served'    => trim($_POST['areas_served'] ?? ''),
            'languages'       => trim($_POST['languages'] ?? 'en,fr'),
            'pricing_notes'   => trim($_POST['pricing_notes'] ?? ''),
            'is_verified'     => isset($_POST['is_verified']) ? 1 : 0,
            'is_preferred'    => isset($_POST['is_preferred']) ? 1 : 0,
            'is_active'       => isset($_POST['is_active']) ? 1 : 0,
            'receives_enquiries' => isset($_POST['receives_enquiries']) ? 1 : 0,
            'enquiry_routing' => in_array($_POST['enquiry_routing']??'', ['auto','manual','admin_only'])
                                 ? $_POST['enquiry_routing'] : 'admin_only',
            'notes_internal'  => trim($_POST['notes_internal'] ?? ''),
        ];

        if (!$fields['slug'] || !$fields['business_name']) {
            $json(['ok'=>false,'error'=>'Slug and business name required.']);
        }

        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "`{$k}`=:{$k}", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE bhc_service_providers SET {$sets} WHERE id=:id");
            $stmt->execute(array_merge(
                array_combine(array_map(fn($k)=>':'.$k, array_keys($fields)), array_values($fields)),
                [':id' => $id]
            ));
        } else {
            $cols   = implode(',', array_map(fn($k) => "`{$k}`", array_keys($fields)));
            $phs    = implode(',', array_map(fn($k) => ":{$k}", array_keys($fields)));
            $stmt   = $pdo->prepare("INSERT INTO bhc_service_providers ({$cols}) VALUES ({$phs})");
            $stmt->execute(array_combine(
                array_map(fn($k) => ':'.$k, array_keys($fields)),
                array_values($fields)
            ));
            $id = (int)$pdo->lastInsertId();
        }
        $json(['ok'=>true,'id'=>$id]);
    }

    // ---- Provider ↔ Service assignments ----
    if ($action === 'assign_service') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $serviceId  = (int)($_POST['service_id']  ?? 0);
        $isPrimary  = isset($_POST['is_primary']) ? 1 : 0;
        $isActive   = isset($_POST['is_active']) ? 1 : 0;
        $pricing    = trim($_POST['pricing_detail'] ?? '');

        if (!$providerId || !$serviceId) $json(['ok'=>false,'error'=>'Provider and service required.']);

        $pdo->prepare(
            "INSERT INTO bhc_provider_services (provider_id,service_id,is_primary,is_active,pricing_detail)
             VALUES (:p,:s,:ip,:ia,:pd)
             ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary), is_active=VALUES(is_active), pricing_detail=VALUES(pricing_detail)"
        )->execute([':p'=>$providerId,':s'=>$serviceId,':ip'=>$isPrimary,':ia'=>$isActive,':pd'=>$pricing]);
        $json(['ok'=>true]);
    }

    if ($action === 'remove_service') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $serviceId  = (int)($_POST['service_id']  ?? 0);
        $pdo->prepare("DELETE FROM bhc_provider_services WHERE provider_id=:p AND service_id=:s")
            ->execute([':p'=>$providerId,':s'=>$serviceId]);
        $json(['ok'=>true]);
    }

    $json(['ok'=>false,'error'=>'Unknown action.'], 400);
}

// ── Load data for display ─────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'categories';

$categories = bhc_get_service_categories($pdo, 'en');
// re-fetch with inactive for admin
$allCats = $pdo->query("SELECT * FROM bhc_service_categories ORDER BY sort_order,name_en")->fetchAll(PDO::FETCH_ASSOC);

$allServices = $pdo->query(
    "SELECT s.*, c.name_en AS cat_name FROM bhc_services s
     JOIN bhc_service_categories c ON c.id=s.category_id
     ORDER BY c.sort_order, s.sort_order, s.name_en"
)->fetchAll(PDO::FETCH_ASSOC);

$allProviders = $pdo->query(
    "SELECT * FROM bhc_service_providers ORDER BY is_preferred DESC, is_active DESC, business_name"
)->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'BHC Service Catalogue';
require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
.bhc-admin { max-width:1100px; margin:0 auto; padding:20px 16px; }
.bhc-tabs  { display:flex; gap:4px; border-bottom:2px solid #e2e8f0; margin-bottom:24px; }
.bhc-tabs a { padding:9px 18px; text-decoration:none; color:#4a5568; border-radius:6px 6px 0 0; font-size:14px; font-weight:600; }
.bhc-tabs a.active { background:#1a202c; color:#fff; }
.bhc-tabs a:not(.active):hover { background:#edf2f7; }
.bhc-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:20px; margin-bottom:16px; }
.bhc-card h3 { margin:0 0 14px; font-size:15px; color:#1a202c; }
.bhc-table { width:100%; border-collapse:collapse; font-size:13px; }
.bhc-table th { background:#f7fafc; text-align:left; padding:9px 12px; border-bottom:2px solid #e2e8f0; color:#4a5568; font-weight:700; }
.bhc-table td { padding:9px 12px; border-bottom:1px solid #f0f4f8; vertical-align:middle; }
.bhc-table tr:hover td { background:#fafbfc; }
.badge { display:inline-block; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; }
.badge-active  { background:#c6f6d5; color:#22543d; }
.badge-inactive{ background:#fed7d7; color:#742a2a; }
.badge-featured{ background:#fefcbf; color:#744210; }
.badge-verified{ background:#bee3f8; color:#2a4365; }
.badge-preferred{background:#e9d8fd; color:#44337a; }
.btn { padding:7px 14px; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; }
.btn-primary { background:#00A878; color:#fff; }
.btn-primary:hover { background:#008f65; }
.btn-sm { padding:4px 10px; font-size:12px; }
.btn-outline { background:#fff; border:1px solid #cbd5e0; color:#4a5568; }
.btn-outline:hover { background:#f7fafc; }
.btn-danger { background:#fff; border:1px solid #fc8181; color:#c53030; }
.btn-danger:hover { background:#fff5f5; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }
.form-row.single { grid-template-columns:1fr; }
.form-row.triple { grid-template-columns:1fr 1fr 1fr; }
.form-group { display:flex; flex-direction:column; gap:4px; }
.form-group label { font-size:12px; font-weight:700; color:#4a5568; }
.form-group input, .form-group select, .form-group textarea {
    padding:7px 10px; border:1px solid #cbd5e0; border-radius:6px; font-size:13px; }
.form-group textarea { resize:vertical; min-height:70px; }
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
.modal-bg.open { display:flex; }
.modal { background:#fff; border-radius:12px; padding:24px; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; }
.modal h2 { margin:0 0 18px; font-size:17px; }
.section-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; }
.section-header h2 { margin:0; font-size:16px; color:#1a202c; }
input[type=checkbox] { width:16px; height:16px; }
.check-row { display:flex; align-items:center; gap:8px; font-size:13px; color:#4a5568; }
</style>

<div class="bhc-admin">

<div class="bhc-tabs">
  <a href="?tab=categories" class="<?= $tab==='categories'?'active':'' ?>">Service Categories</a>
  <a href="?tab=services"   class="<?= $tab==='services'  ?'active':'' ?>">Services</a>
  <a href="?tab=providers"  class="<?= $tab==='providers' ?'active':'' ?>">Providers</a>
</div>

<!-- ═══ TAB: CATEGORIES ═══════════════════════════════════════════════════ -->
<?php if ($tab === 'categories'): ?>
<div class="bhc-card">
  <div class="section-header">
    <h2>Service Categories (<?= count($allCats) ?>)</h2>
    <button class="btn btn-primary" onclick="openCatModal()">+ Add Category</button>
  </div>
  <table class="bhc-table">
    <thead><tr>
      <th>Order</th><th>Slug</th><th>Name (EN)</th><th>Name (FR)</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($allCats as $c): ?>
    <tr>
      <td><?= $c['sort_order'] ?></td>
      <td><code><?= htmlspecialchars($c['slug']) ?></code></td>
      <td><?= htmlspecialchars($c['name_en']) ?></td>
      <td><?= htmlspecialchars($c['name_fr']) ?></td>
      <td><span class="badge <?= $c['is_active']?'badge-active':'badge-inactive' ?>"><?= $c['is_active']?'Active':'Inactive' ?></span></td>
      <td>
        <button class="btn btn-sm btn-outline"
          onclick='openCatModal(<?= json_encode($c) ?>)'>Edit</button>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<!-- Category Modal -->
<div class="modal-bg" id="catModal">
  <div class="modal">
    <h2 id="catModalTitle">Add Service Category</h2>
    <form id="catForm">
      <input type="hidden" name="action" value="save_category">
      <input type="hidden" name="id" id="catId">
      <div class="form-row">
        <div class="form-group">
          <label>Slug <small>(auto-generated, lowercase_underscore)</small></label>
          <input name="slug" id="catSlug" required placeholder="e.g. whatsapp_automation">
        </div>
        <div class="form-group">
          <label>Sort Order</label>
          <input type="number" name="sort_order" id="catOrder" value="0" min="0">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Name (English)</label>
          <input name="name_en" id="catNameEn" required>
        </div>
        <div class="form-group">
          <label>Name (French)</label>
          <input name="name_fr" id="catNameFr">
        </div>
      </div>
      <div class="form-row">
        <div class="check-row">
          <input type="checkbox" name="is_active" id="catActive" checked>
          <label for="catActive">Active</label>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-primary">Save Category</button>
        <button type="button" class="btn btn-outline" onclick="closeCatModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══ TAB: SERVICES ════════════════════════════════════════════════════ -->
<?php elseif ($tab === 'services'): ?>
<div class="bhc-card">
  <div class="section-header">
    <h2>Services (<?= count($allServices) ?>)</h2>
    <button class="btn btn-primary" onclick="openSvcModal()">+ Add Service</button>
  </div>
  <table class="bhc-table">
    <thead><tr>
      <th>Category</th><th>Name (EN)</th><th>Pricing</th><th>Status</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($allServices as $s): ?>
    <tr>
      <td><small><?= htmlspecialchars($s['cat_name']) ?></small></td>
      <td>
        <?= htmlspecialchars($s['name_en']) ?>
        <?php if ($s['is_featured']): ?><span class="badge badge-featured">Featured</span><?php endif ?>
      </td>
      <td><?= htmlspecialchars($s['pricing_type']) ?><?= $s['pricing_info'] ? ' — '.$s['pricing_info'] : '' ?></td>
      <td><span class="badge <?= $s['is_active']?'badge-active':'badge-inactive' ?>"><?= $s['is_active']?'Active':'Inactive' ?></span></td>
      <td>
        <button class="btn btn-sm btn-outline" onclick='openSvcModal(<?= json_encode($s) ?>)'>Edit</button>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<!-- Service Modal -->
<div class="modal-bg" id="svcModal">
  <div class="modal">
    <h2 id="svcModalTitle">Add Service</h2>
    <form id="svcForm">
      <input type="hidden" name="action" value="save_service">
      <input type="hidden" name="id" id="svcId">
      <div class="form-row">
        <div class="form-group">
          <label>Category</label>
          <select name="category_id" id="svcCat" required>
            <option value="">— Select —</option>
            <?php foreach ($allCats as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name_en']) ?></option>
            <?php endforeach ?>
          </select>
        </div>
        <div class="form-group">
          <label>Slug</label>
          <input name="slug" id="svcSlug" required placeholder="e.g. whatsapp_profile_setup">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Name (English)</label>
          <input name="name_en" id="svcNameEn" required>
        </div>
        <div class="form-group">
          <label>Name (French)</label>
          <input name="name_fr" id="svcNameFr">
        </div>
      </div>
      <div class="form-row single">
        <div class="form-group">
          <label>Description (English)</label>
          <textarea name="description_en" id="svcDescEn"></textarea>
        </div>
      </div>
      <div class="form-row single">
        <div class="form-group">
          <label>Description (French)</label>
          <textarea name="description_fr" id="svcDescFr"></textarea>
        </div>
      </div>
      <div class="form-row triple">
        <div class="form-group">
          <label>Pricing Type</label>
          <select name="pricing_type" id="svcPricingType">
            <option value="quote">Quote required</option>
            <option value="one_off">One-off</option>
            <option value="recurring">Recurring</option>
            <option value="free">Free</option>
          </select>
        </div>
        <div class="form-group">
          <label>Pricing Info</label>
          <input name="pricing_info" id="svcPricingInfo" placeholder="e.g. From XAF 50,000">
        </div>
        <div class="form-group">
          <label>Sort Order</label>
          <input type="number" name="sort_order" id="svcOrder" value="0" min="0">
        </div>
      </div>
      <div class="form-row">
        <div class="check-row">
          <input type="checkbox" name="is_active" id="svcActive" checked>
          <label for="svcActive">Active</label>
        </div>
        <div class="check-row">
          <input type="checkbox" name="is_featured" id="svcFeatured">
          <label for="svcFeatured">Featured</label>
        </div>
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-primary">Save Service</button>
        <button type="button" class="btn btn-outline" onclick="closeSvcModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══ TAB: PROVIDERS ═══════════════════════════════════════════════════ -->
<?php elseif ($tab === 'providers'): ?>
<div class="bhc-card">
  <div class="section-header">
    <h2>Service Providers (<?= count($allProviders) ?>)</h2>
    <button class="btn btn-primary" onclick="openProvModal()">+ Add Provider</button>
  </div>
  <table class="bhc-table">
    <thead><tr>
      <th>Provider</th><th>Location</th><th>Status</th><th>Routing</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($allProviders as $p):
      $svcs = bhc_get_provider_services_list($pdo, (int)$p['id']);
    ?>
    <tr>
      <td>
        <strong><?= htmlspecialchars($p['business_name']) ?></strong><br>
        <?php if ($p['is_verified']): ?><span class="badge badge-verified">Verified</span><?php endif ?>
        <?php if ($p['is_preferred']): ?><span class="badge badge-preferred">Preferred</span><?php endif ?>
        <?php if (!$p['is_active']): ?><span class="badge badge-inactive">Inactive</span><?php endif ?>
      </td>
      <td><?= htmlspecialchars($p['location'] ?? '—') ?></td>
      <td>
        <span class="badge <?= $p['is_active']?'badge-active':'badge-inactive' ?>"><?= $p['is_active']?'Active':'Inactive' ?></span>
      </td>
      <td><?= htmlspecialchars($p['enquiry_routing']) ?></td>
      <td style="display:flex;gap:6px;flex-wrap:wrap;">
        <button class="btn btn-sm btn-outline"
          onclick='openProvModal(<?= json_encode($p) ?>)'>Edit</button>
        <button class="btn btn-sm btn-outline"
          onclick='openAssignModal(<?= $p['id'] ?>, <?= htmlspecialchars(json_encode($svcs), ENT_QUOTES) ?>)'>Services (<?= count($svcs) ?>)</button>
      </td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</div>

<!-- Provider Modal -->
<div class="modal-bg" id="provModal">
  <div class="modal">
    <h2 id="provModalTitle">Add Provider</h2>
    <form id="provForm">
      <input type="hidden" name="action" value="save_provider">
      <input type="hidden" name="id" id="provId">
      <div class="form-row">
        <div class="form-group">
          <label>Business Name</label>
          <input name="business_name" id="provName" required>
        </div>
        <div class="form-group">
          <label>Slug</label>
          <input name="slug" id="provSlug" required placeholder="e.g. ms-it-solutions">
        </div>
      </div>
      <div class="form-row single">
        <div class="form-group">
          <label>Short Bio (English)</label>
          <textarea name="short_bio_en" id="provBioEn" maxlength="300"></textarea>
        </div>
      </div>
      <div class="form-row single">
        <div class="form-group">
          <label>Short Bio (French)</label>
          <textarea name="short_bio_fr" id="provBioFr" maxlength="300"></textarea>
        </div>
      </div>
      <div class="form-row triple">
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" id="provEmail">
        </div>
        <div class="form-group">
          <label>Phone</label>
          <input name="phone" id="provPhone">
        </div>
        <div class="form-group">
          <label>WhatsApp</label>
          <input name="whatsapp" id="provWhatsApp">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Website URL</label>
          <input name="website_url" id="provWebsite" placeholder="https://...">
        </div>
        <div class="form-group">
          <label>Location</label>
          <input name="location" id="provLocation" placeholder="e.g. Douala, Cameroon">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Areas Served</label>
          <input name="areas_served" id="provAreas" placeholder="e.g. Nationwide, Douala, Yaoundé">
        </div>
        <div class="form-group">
          <label>Languages</label>
          <select name="languages" id="provLangs">
            <option value="en,fr">English + French</option>
            <option value="en">English only</option>
            <option value="fr">French only</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>Pricing Notes</label>
          <input name="pricing_notes" id="provPricing" placeholder="e.g. Quotes provided within 24 hours">
        </div>
        <div class="form-group">
          <label>Enquiry Routing</label>
          <select name="enquiry_routing" id="provRouting">
            <option value="admin_only">Admin only (manual)</option>
            <option value="manual">Manual assignment</option>
            <option value="auto">Auto-assign</option>
          </select>
        </div>
      </div>
      <div class="form-row single">
        <div class="form-group">
          <label>Internal Notes (not shown to businesses or providers)</label>
          <textarea name="notes_internal" id="provNotes"></textarea>
        </div>
      </div>
      <div class="form-row" style="grid-template-columns:repeat(4,auto);justify-content:start;gap:16px;">
        <div class="check-row"><input type="checkbox" name="is_active" id="provActive" checked><label for="provActive">Active</label></div>
        <div class="check-row"><input type="checkbox" name="is_verified" id="provVerified"><label for="provVerified">Verified</label></div>
        <div class="check-row"><input type="checkbox" name="is_preferred" id="provPreferred"><label for="provPreferred">Preferred</label></div>
        <div class="check-row"><input type="checkbox" name="receives_enquiries" id="provEnquiries" checked><label for="provEnquiries">Receives enquiries</label></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:16px">
        <button type="submit" class="btn btn-primary">Save Provider</button>
        <button type="button" class="btn btn-outline" onclick="closeProvModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Assign Services Modal -->
<div class="modal-bg" id="assignModal">
  <div class="modal">
    <h2>Assign Services to Provider</h2>
    <input type="hidden" id="assignProviderId">
    <div id="assignCurrentList" style="margin-bottom:16px;"></div>
    <hr>
    <h3 style="font-size:14px;margin:14px 0 10px;">Add a Service</h3>
    <div class="form-row">
      <div class="form-group">
        <label>Service</label>
        <select id="assignServiceId">
          <option value="">— Select service —</option>
          <?php foreach ($allServices as $s): ?>
          <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['cat_name'].' — '.$s['name_en']) ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label>Provider Pricing (optional)</label>
        <input id="assignPricing" placeholder="e.g. XAF 75,000 setup">
      </div>
    </div>
    <div class="form-row">
      <div class="check-row">
        <input type="checkbox" id="assignPrimary"><label for="assignPrimary">Primary service for this provider</label>
      </div>
    </div>
    <div style="display:flex;gap:8px;margin-top:12px">
      <button class="btn btn-primary" onclick="doAssignService()">Assign Service</button>
      <button class="btn btn-outline" onclick="closeAssignModal()">Close</button>
    </div>
  </div>
</div>
<?php endif ?>

</div><!-- .bhc-admin -->

<script>
const ADMIN_URL = '/admin-bhc-catalogue.php';

// ── Generic POST helper ────────────────────────────────────────────────────
async function adminPost(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v ?? ''));
    const r = await fetch(ADMIN_URL, {method:'POST', body:fd});
    return r.json();
}

// ── Category Modal ─────────────────────────────────────────────────────────
function openCatModal(cat = null) {
    document.getElementById('catModalTitle').textContent = cat ? 'Edit Category' : 'Add Category';
    document.getElementById('catId').value       = cat?.id ?? '';
    document.getElementById('catSlug').value     = cat?.slug ?? '';
    document.getElementById('catNameEn').value   = cat?.name_en ?? '';
    document.getElementById('catNameFr').value   = cat?.name_fr ?? '';
    document.getElementById('catOrder').value    = cat?.sort_order ?? 0;
    document.getElementById('catActive').checked = cat ? cat.is_active == 1 : true;
    document.getElementById('catModal').classList.add('open');
}
function closeCatModal() { document.getElementById('catModal').classList.remove('open'); }

document.getElementById('catForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd);
    if (!fd.has('is_active')) data.is_active = '';
    const res = await adminPost(data);
    if (res.ok) { closeCatModal(); location.reload(); }
    else alert(res.error || 'Save failed');
});

// ── Service Modal ──────────────────────────────────────────────────────────
function openSvcModal(svc = null) {
    document.getElementById('svcModalTitle').textContent = svc ? 'Edit Service' : 'Add Service';
    document.getElementById('svcId').value         = svc?.id ?? '';
    document.getElementById('svcCat').value        = svc?.category_id ?? '';
    document.getElementById('svcSlug').value       = svc?.slug ?? '';
    document.getElementById('svcNameEn').value     = svc?.name_en ?? '';
    document.getElementById('svcNameFr').value     = svc?.name_fr ?? '';
    document.getElementById('svcDescEn').value     = svc?.description_en ?? '';
    document.getElementById('svcDescFr').value     = svc?.description_fr ?? '';
    document.getElementById('svcPricingType').value= svc?.pricing_type ?? 'quote';
    document.getElementById('svcPricingInfo').value= svc?.pricing_info ?? '';
    document.getElementById('svcOrder').value      = svc?.sort_order ?? 0;
    document.getElementById('svcActive').checked   = svc ? svc.is_active == 1 : true;
    document.getElementById('svcFeatured').checked = svc?.is_featured == 1;
    document.getElementById('svcModal').classList.add('open');
}
function closeSvcModal() { document.getElementById('svcModal').classList.remove('open'); }

document.getElementById('svcForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd);
    if (!fd.has('is_active'))   data.is_active   = '';
    if (!fd.has('is_featured')) data.is_featured  = '';
    const res = await adminPost(data);
    if (res.ok) { closeSvcModal(); location.reload(); }
    else alert(res.error || 'Save failed');
});

// ── Provider Modal ─────────────────────────────────────────────────────────
function openProvModal(p = null) {
    document.getElementById('provModalTitle').textContent = p ? 'Edit Provider' : 'Add Provider';
    const setVal = (id, v) => { const el=document.getElementById(id); if(el) el.value=v??''; };
    const setChk = (id, v) => { const el=document.getElementById(id); if(el) el.checked=!!v; };
    setVal('provId',       p?.id);
    setVal('provName',     p?.business_name);
    setVal('provSlug',     p?.slug);
    setVal('provBioEn',    p?.short_bio_en);
    setVal('provBioFr',    p?.short_bio_fr);
    setVal('provEmail',    p?.email);
    setVal('provPhone',    p?.phone);
    setVal('provWhatsApp', p?.whatsapp);
    setVal('provWebsite',  p?.website_url);
    setVal('provLocation', p?.location);
    setVal('provAreas',    p?.areas_served);
    setVal('provLangs',    p?.languages ?? 'en,fr');
    setVal('provPricing',  p?.pricing_notes);
    setVal('provRouting',  p?.enquiry_routing ?? 'admin_only');
    setVal('provNotes',    p?.notes_internal);
    setChk('provActive',     p ? p.is_active==1 : true);
    setChk('provVerified',   p?.is_verified==1);
    setChk('provPreferred',  p?.is_preferred==1);
    setChk('provEnquiries',  p ? p.receives_enquiries==1 : true);
    document.getElementById('provModal').classList.add('open');
}
function closeProvModal() { document.getElementById('provModal').classList.remove('open'); }

document.getElementById('provForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const data = Object.fromEntries(fd);
    ['is_active','is_verified','is_preferred','receives_enquiries']
        .forEach(k => { if (!fd.has(k)) data[k] = ''; });
    const res = await adminPost(data);
    if (res.ok) { closeProvModal(); location.reload(); }
    else alert(res.error || 'Save failed');
});

// ── Assign Services Modal ──────────────────────────────────────────────────
let currentProviderServices = [];

function openAssignModal(providerId, services) {
    document.getElementById('assignProviderId').value = providerId;
    currentProviderServices = services;
    renderCurrentServices(services);
    document.getElementById('assignModal').classList.add('open');
}
function closeAssignModal() { document.getElementById('assignModal').classList.remove('open'); }

function renderCurrentServices(services) {
    const el = document.getElementById('assignCurrentList');
    if (!services.length) {
        el.innerHTML = '<p style="color:#718096;font-size:13px">No services assigned yet.</p>';
        return;
    }
    el.innerHTML = '<table class="bhc-table"><thead><tr><th>Service</th><th>Primary?</th><th>Active?</th><th></th></tr></thead><tbody>' +
        services.map(s => `<tr>
            <td>${escHtml(s.cat_en)} — ${escHtml(s.name_en)} ${s.pricing_detail ? `<small>(${escHtml(s.pricing_detail)})</small>` : ''}</td>
            <td>${s.is_primary==1?'✓':''}</td>
            <td>${s.is_active==1?'<span class="badge badge-active">Yes</span>':'<span class="badge badge-inactive">No</span>'}</td>
            <td><button class="btn btn-sm btn-danger" onclick="doRemoveService(${s.id})">Remove</button></td>
        </tr>`).join('') +
        '</tbody></table>';
}

async function doAssignService() {
    const pid = document.getElementById('assignProviderId').value;
    const sid = document.getElementById('assignServiceId').value;
    if (!sid) { alert('Please select a service'); return; }
    const res = await adminPost({
        action: 'assign_service',
        provider_id: pid,
        service_id: sid,
        is_active: 1,
        is_primary: document.getElementById('assignPrimary').checked ? 1 : 0,
        pricing_detail: document.getElementById('assignPricing').value,
    });
    if (res.ok) {
        document.getElementById('assignServiceId').value = '';
        document.getElementById('assignPricing').value = '';
        document.getElementById('assignPrimary').checked = false;
        location.reload();
    } else alert(res.error || 'Failed to assign service');
}

async function doRemoveService(serviceId) {
    if (!confirm('Remove this service from the provider?')) return;
    const pid = document.getElementById('assignProviderId').value;
    const res = await adminPost({ action:'remove_service', provider_id:pid, service_id:serviceId });
    if (res.ok) location.reload();
    else alert(res.error || 'Failed');
}

function escHtml(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// Close modals on background click
['catModal','svcModal','provModal','assignModal'].forEach(id => {
    document.getElementById(id)?.addEventListener('click', e => {
        if (e.target.id === id) e.target.classList.remove('open');
    });
});
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
