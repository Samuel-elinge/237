<?php
/**
 * Admin – BHC Commercial Terms
 * Stage 5 of Phase 2: manage commercial arrangements per provider/service.
 *
 * Actions (POST, JSON): save_term, delete_term, toggle_term
 * Filters (GET): provider_id, service_id, model, p (page)
 */
define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

// ── Auth ─── Super Admin only (Ops Managers cannot access financial/commercial terms) ──
session_start();
$staffId = (int)($_SESSION['staff_id'] ?? 0);
$isAdmin = (bool)($_SESSION['is_admin']  ?? false);
if (!$staffId || !$isAdmin) {
    // Ops Manager trying to access: show 403, not redirect, to make the restriction clear
    if ($staffId && !empty($_SESSION['is_ops_manager'])) {
        http_response_code(403);
        die('Access denied. Commercial terms management requires Super Admin access.');
    }
    header('Location: /login.php');
    exit;
}

require_once __DIR__ . '/includes/bhc-commercial.php';
require_once __DIR__ . '/includes/bhc-catalogue.php';

$pdo = db();

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    // CSRF check
    if (($_POST['csrf'] ?? '') !== ($_SESSION['bhc_comm_csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    switch ($action) {

        case 'save_term':
            // Create or update
            $id = (int)($_POST['id'] ?? 0);
            $data = [
                'provider_id'   => (int)($_POST['provider_id']  ?? 0),
                'service_id'    => $_POST['service_id']  ?? '',
                'model'         => $_POST['model']        ?? '',
                'rate_pct'      => $_POST['rate_pct']     ?? '',
                'flat_amount'   => $_POST['flat_amount']  ?? '',
                'currency'      => $_POST['currency']     ?? 'XAF',
                'billing_cycle' => $_POST['billing_cycle'] ?? '',
                'valid_from'    => $_POST['valid_from']   ?? '',
                'valid_until'   => $_POST['valid_until']  ?? '',
                'notes'         => trim($_POST['notes']   ?? ''),
                'is_active'     => isset($_POST['is_active']) ? 1 : 0,
            ];
            if (!$data['provider_id'] || !in_array($data['model'], ['referral','fee_per_lead','subscription','revenue_share','featured'], true)) {
                echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
                exit;
            }
            if ($id > 0) {
                $ok = bhc_update_commercial_term($pdo, $id, $data);
                echo json_encode(['ok' => $ok]);
            } else {
                $newId = bhc_create_commercial_term($pdo, $data, $staffId);
                echo json_encode(['ok' => (bool)$newId, 'id' => $newId]);
            }
            exit;

        case 'delete_term':
            if (!$isAdmin) { echo json_encode(['ok' => false, 'error' => 'Admin only']); exit; }
            $id = (int)($_POST['id'] ?? 0);
            echo json_encode(['ok' => bhc_delete_commercial_term($pdo, $id)]);
            exit;

        case 'toggle_term':
            $id  = (int)($_POST['id']     ?? 0);
            $val = (int)($_POST['active'] ?? 0);
            $st  = $pdo->prepare('UPDATE bhc_service_commercial_terms SET is_active = :v WHERE id = :id');
            $st->execute([':v' => $val, ':id' => $id]);
            echo json_encode(['ok' => $st->rowCount() > 0]);
            exit;

        case 'get_term':
            $id   = (int)($_POST['id'] ?? 0);
            $term = bhc_get_commercial_term($pdo, $id);
            echo json_encode(['ok' => (bool)$term, 'term' => $term]);
            exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── CSRF token ────────────────────────────────────────────────────────────────
if (empty($_SESSION['bhc_comm_csrf'])) {
    $_SESSION['bhc_comm_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['bhc_comm_csrf'];

// ── Filters ───────────────────────────────────────────────────────────────────
$filterProvider = (int)($_GET['provider_id'] ?? 0);
$filterService  = (int)($_GET['service_id']  ?? 0);
$filterModel    = $_GET['model'] ?? '';
$page           = max(1, (int)($_GET['p'] ?? 1));
$perPage        = 30;
$offset         = ($page - 1) * $perPage;

$filters = [];
if ($filterProvider) $filters['provider_id'] = $filterProvider;
if ($filterService)  $filters['service_id']  = $filterService;
if ($filterModel)    $filters['model']        = $filterModel;
$filters['active_only'] = false; // show all in admin

$terms    = bhc_list_commercial_terms($pdo, $filters, $perPage, $offset);
$total    = bhc_count_commercial_terms($pdo, $filters);
$pages    = (int)ceil($total / $perPage);

// Dropdowns
$providers  = bhc_get_all_providers($pdo);
$services   = $pdo->query('SELECT id, name_en FROM bhc_services WHERE is_active=1 ORDER BY name_en')->fetchAll(PDO::FETCH_ASSOC);

$models = ['referral','fee_per_lead','subscription','revenue_share','featured'];

$modelLabels = [
    'referral'      => 'Referral Commission',
    'fee_per_lead'  => 'Fee Per Lead',
    'subscription'  => 'Subscription',
    'revenue_share' => 'Revenue Share',
    'featured'      => 'Featured Placement',
];

$statusColors = [
    'referral'      => '#3b82f6',
    'fee_per_lead'  => '#8b5cf6',
    'subscription'  => '#10b981',
    'revenue_share' => '#f59e0b',
    'featured'      => '#ec4899',
];

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
.bhc-comm-wrap{max-width:1300px;margin:0 auto;padding:24px 16px}
.bhc-comm-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:20px}
.bhc-comm-toolbar select,.bhc-comm-toolbar input{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px}
.bhc-comm-btn{padding:8px 16px;border-radius:6px;border:none;cursor:pointer;font-size:14px;font-weight:600}
.bhc-comm-btn-primary{background:#2563eb;color:#fff}
.bhc-comm-btn-primary:hover{background:#1d4ed8}
.bhc-comm-btn-sm{padding:4px 10px;font-size:12px}
.bhc-comm-btn-danger{background:#ef4444;color:#fff}
.bhc-comm-btn-secondary{background:#6b7280;color:#fff}
table.bhc-comm-tbl{width:100%;border-collapse:collapse;font-size:14px;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
table.bhc-comm-tbl th{background:#f8fafc;padding:10px 12px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;white-space:nowrap}
table.bhc-comm-tbl td{padding:10px 12px;border-top:1px solid #f1f5f9;vertical-align:middle}
table.bhc-comm-tbl tr:hover td{background:#f8fafc}
.model-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;color:#fff}
.status-pill{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600}
.status-active{background:#d1fae5;color:#065f46}
.status-inactive{background:#fee2e2;color:#991b1b}
/* Modal */
.bhc-comm-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.bhc-comm-overlay.open{display:flex}
.bhc-comm-modal{background:#fff;border-radius:12px;padding:28px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.bhc-comm-modal h3{margin:0 0 20px;font-size:18px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.form-row.single{grid-template-columns:1fr}
.form-row label{display:block;font-size:13px;font-weight:600;color:#374151;margin-bottom:4px}
.form-row input,.form-row select,.form-row textarea{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box}
.form-row textarea{resize:vertical;min-height:70px}
.rate-hint{font-size:12px;color:#6b7280;margin-top:3px}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb}
.bhc-comm-pagination{display:flex;gap:6px;margin-top:20px;justify-content:center}
.bhc-comm-pagination a,.bhc-comm-pagination span{padding:6px 12px;border-radius:6px;border:1px solid #d1d5db;font-size:13px;text-decoration:none;color:#374151}
.bhc-comm-pagination .current{background:#2563eb;color:#fff;border-color:#2563eb}
.toast{position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:600;z-index:9999;display:none;box-shadow:0 4px 12px rgba(0,0,0,.2)}
.toast.show{display:block}
.toast.ok{background:#10b981;color:#fff}
.toast.err{background:#ef4444;color:#fff}
</style>

<div class="bhc-comm-wrap">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="margin:0;font-size:24px">Commercial Terms</h1>
      <p style="margin:4px 0 0;color:#6b7280;font-size:14px">Configure commercial arrangements between 237biz and service providers.</p>
    </div>
    <button class="bhc-comm-btn bhc-comm-btn-primary" onclick="openForm()">+ Add Term</button>
  </div>

  <!-- Filters -->
  <div class="bhc-comm-toolbar">
    <form method="get" style="display:contents">
      <select name="provider_id" onchange="this.form.submit()">
        <option value="">All providers</option>
        <?php foreach ($providers as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $filterProvider == $p['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($p['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="service_id" onchange="this.form.submit()">
        <option value="">All services</option>
        <?php foreach ($services as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filterService == $s['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($s['name_en']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="model" onchange="this.form.submit()">
        <option value="">All models</option>
        <?php foreach ($modelLabels as $k => $lbl): ?>
          <option value="<?= $k ?>" <?= $filterModel === $k ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="bhc-comm-btn bhc-comm-btn-secondary bhc-comm-btn-sm">Filter</button>
      <a href="admin-bhc-commercial.php" style="font-size:13px;color:#6b7280;text-decoration:none">Clear</a>
    </form>
    <span style="margin-left:auto;font-size:13px;color:#6b7280"><?= $total ?> term<?= $total != 1 ? 's' : '' ?></span>
  </div>

  <!-- Table -->
  <?php if ($terms): ?>
  <table class="bhc-comm-tbl">
    <thead>
      <tr>
        <th>Provider</th>
        <th>Service</th>
        <th>Model</th>
        <th>Rate / Amount</th>
        <th>Billing</th>
        <th>Valid</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($terms as $t): ?>
      <tr>
        <td><strong><?= htmlspecialchars($t['provider_name']) ?></strong></td>
        <td><?= $t['service_name_en'] ? htmlspecialchars($t['service_name_en']) : '<em style="color:#9ca3af">All services</em>' ?></td>
        <td>
          <span class="model-badge" style="background:<?= $statusColors[$t['model']] ?? '#6b7280' ?>">
            <?= htmlspecialchars($modelLabels[$t['model']] ?? $t['model']) ?>
          </span>
        </td>
        <td><?= htmlspecialchars(bhc_commercial_rate_display($t)) ?></td>
        <td><?= htmlspecialchars(bhc_billing_cycle_label($t['billing_cycle'])) ?></td>
        <td style="font-size:12px;color:#6b7280">
          <?php if ($t['valid_from'] || $t['valid_until']): ?>
            <?= $t['valid_from'] ? date('d M Y', strtotime($t['valid_from'])) : '…' ?>
            –
            <?= $t['valid_until'] ? date('d M Y', strtotime($t['valid_until'])) : 'ongoing' ?>
          <?php else: ?>
            <span style="color:#9ca3af">No expiry</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="status-pill <?= $t['is_active'] ? 'status-active' : 'status-inactive' ?>">
            <?= $t['is_active'] ? 'Active' : 'Inactive' ?>
          </span>
        </td>
        <td style="white-space:nowrap">
          <button class="bhc-comm-btn bhc-comm-btn-secondary bhc-comm-btn-sm"
                  onclick="editTerm(<?= $t['id'] ?>)">Edit</button>
          <button class="bhc-comm-btn bhc-comm-btn-sm"
                  style="background:<?= $t['is_active'] ? '#f59e0b' : '#10b981' ?>;color:#fff"
                  onclick="toggleTerm(<?= $t['id'] ?>, <?= $t['is_active'] ? 0 : 1 ?>)">
            <?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>
          </button>
          <?php if ($isAdmin): ?>
          <button class="bhc-comm-btn bhc-comm-btn-danger bhc-comm-btn-sm"
                  onclick="deleteTerm(<?= $t['id'] ?>)">Delete</button>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php else: ?>
    <div style="text-align:center;padding:60px 20px;color:#9ca3af">
      <div style="font-size:48px;margin-bottom:16px">💼</div>
      <p>No commercial terms found. <a href="#" onclick="openForm();return false">Add the first one.</a></p>
    </div>
  <?php endif; ?>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="bhc-comm-pagination">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?php
        $q = http_build_query(array_filter([
          'provider_id' => $filterProvider ?: null,
          'service_id'  => $filterService  ?: null,
          'model'       => $filterModel    ?: null,
          'p'           => $i > 1 ? $i : null,
        ]));
      ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="?<?= $q ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── Add / Edit Modal ─────────────────────────────────────────────────────── -->
<div class="bhc-comm-overlay" id="commOverlay" onclick="closeIfBg(event)">
  <div class="bhc-comm-modal">
    <h3 id="commModalTitle">Add Commercial Term</h3>
    <form id="commForm" onsubmit="saveTerm(event)">
      <input type="hidden" id="termId" name="id" value="0">

      <div class="form-row">
        <div>
          <label>Provider *</label>
          <select id="fProvider" name="provider_id" required>
            <option value="">— select —</option>
            <?php foreach ($providers as $p): ?>
              <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Service <span style="font-weight:400;color:#9ca3af">(leave blank = all)</span></label>
          <select id="fService" name="service_id">
            <option value="">All services</option>
            <?php foreach ($services as $s): ?>
              <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name_en']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="form-row single">
        <div>
          <label>Commercial Model *</label>
          <select id="fModel" name="model" required onchange="showRateFields()">
            <option value="">— select —</option>
            <?php foreach ($modelLabels as $k => $lbl): ?>
              <option value="<?= $k ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Rate fields (shown/hidden by model) -->
      <div class="form-row" id="rateRow">
        <div id="ratePctWrap">
          <label>Rate (%)</label>
          <input type="number" id="fRatePct" name="rate_pct" min="0" max="100" step="0.01" placeholder="e.g. 10">
          <div class="rate-hint" id="rateHint"></div>
        </div>
        <div id="flatAmountWrap">
          <label>Flat Amount</label>
          <div style="display:flex;gap:6px">
            <input type="number" id="fFlatAmount" name="flat_amount" min="0" step="1" placeholder="e.g. 5000" style="flex:1">
            <select name="currency" id="fCurrency" style="width:80px">
              <option value="XAF">XAF</option>
              <option value="GBP">GBP</option>
              <option value="EUR">EUR</option>
              <option value="USD">USD</option>
            </select>
          </div>
        </div>
      </div>

      <div class="form-row">
        <div>
          <label>Billing Cycle</label>
          <select name="billing_cycle" id="fBillingCycle">
            <option value="">— not set —</option>
            <option value="one_off">One-off</option>
            <option value="monthly">Monthly</option>
            <option value="annual">Annual</option>
            <option value="per_lead">Per lead</option>
          </select>
        </div>
        <div style="display:flex;align-items:center;padding-top:22px">
          <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:400">
            <input type="checkbox" name="is_active" id="fActive" value="1" checked style="width:auto">
            Active
          </label>
        </div>
      </div>

      <div class="form-row">
        <div>
          <label>Valid From</label>
          <input type="date" name="valid_from" id="fValidFrom">
        </div>
        <div>
          <label>Valid Until</label>
          <input type="date" name="valid_until" id="fValidUntil">
        </div>
      </div>

      <div class="form-row single">
        <div>
          <label>Internal Notes</label>
          <textarea name="notes" id="fNotes" placeholder="Contract reference, agreed terms, context…"></textarea>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="bhc-comm-btn bhc-comm-btn-secondary" onclick="closeModal()">Cancel</button>
        <button type="submit" class="bhc-comm-btn bhc-comm-btn-primary">Save Term</button>
      </div>
    </form>
  </div>
</div>

<div class="toast" id="commToast"></div>

<script>
const CSRF = <?= json_encode($csrf) ?>;

// ── Rate field visibility ────────────────────────────────────────────────────
const rateHints = {
  referral:      'Percentage of the value of a converted enquiry paid to 237biz.',
  fee_per_lead:  'Fixed amount paid by provider per qualified lead delivered.',
  subscription:  'Monthly or annual fee for access / placement.',
  revenue_share: 'Percentage of ongoing contract value paid to 237biz.',
  featured:      'Fixed placement fee for featured listing in the catalogue.',
};

function showRateFields() {
  const model = document.getElementById('fModel').value;
  const usePct  = ['referral','revenue_share'].includes(model);
  const useFlat = ['fee_per_lead','subscription','featured'].includes(model);

  document.getElementById('ratePctWrap').style.display    = usePct  ? '' : 'none';
  document.getElementById('flatAmountWrap').style.display = useFlat ? '' : 'none';
  document.getElementById('rateHint').textContent = rateHints[model] ?? '';

  // Billing cycle defaults
  const bc = document.getElementById('fBillingCycle');
  if (model === 'fee_per_lead' && !bc.value) bc.value = 'per_lead';
  if (model === 'subscription'  && !bc.value) bc.value = 'monthly';
  if (model === 'featured'      && !bc.value) bc.value = 'one_off';
}

// ── Open form ────────────────────────────────────────────────────────────────
function openForm(term = null) {
  document.getElementById('commModalTitle').textContent = term ? 'Edit Commercial Term' : 'Add Commercial Term';
  document.getElementById('commForm').reset();
  document.getElementById('termId').value = term ? term.id : 0;

  if (term) {
    document.getElementById('fProvider').value     = term.provider_id    ?? '';
    document.getElementById('fService').value      = term.service_id     ?? '';
    document.getElementById('fModel').value        = term.model          ?? '';
    document.getElementById('fRatePct').value      = term.rate_pct       ?? '';
    document.getElementById('fFlatAmount').value   = term.flat_amount    ?? '';
    document.getElementById('fCurrency').value     = term.currency       ?? 'XAF';
    document.getElementById('fBillingCycle').value = term.billing_cycle  ?? '';
    document.getElementById('fValidFrom').value    = term.valid_from     ?? '';
    document.getElementById('fValidUntil').value   = term.valid_until    ?? '';
    document.getElementById('fNotes').value        = term.notes          ?? '';
    document.getElementById('fActive').checked     = !!parseInt(term.is_active);
  }

  showRateFields();
  document.getElementById('commOverlay').classList.add('open');
}

function editTerm(id) {
  apiPost({ action: 'get_term', id }).then(r => {
    if (r.ok && r.term) openForm(r.term);
    else showToast('Could not load term.', true);
  });
}

function closeModal() {
  document.getElementById('commOverlay').classList.remove('open');
}
function closeIfBg(e) {
  if (e.target === document.getElementById('commOverlay')) closeModal();
}

// ── Save ─────────────────────────────────────────────────────────────────────
function saveTerm(e) {
  e.preventDefault();
  const fd = new FormData(document.getElementById('commForm'));
  fd.append('action', 'save_term');
  fd.append('csrf', CSRF);
  if (!document.getElementById('fActive').checked) fd.set('is_active', '0');

  fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
    .then(r => r.json())
    .then(r => {
      if (r.ok) { showToast('Saved.'); closeModal(); setTimeout(() => location.reload(), 600); }
      else       showToast(r.error || 'Error saving term.', true);
    });
}

// ── Toggle ───────────────────────────────────────────────────────────────────
function toggleTerm(id, active) {
  apiPost({ action: 'toggle_term', id, active }).then(r => {
    if (r.ok) location.reload();
    else showToast('Could not update status.', true);
  });
}

// ── Delete ───────────────────────────────────────────────────────────────────
function deleteTerm(id) {
  if (!confirm('Permanently delete this commercial term? This cannot be undone.')) return;
  apiPost({ action: 'delete_term', id }).then(r => {
    if (r.ok) location.reload();
    else showToast('Could not delete term.', true);
  });
}

// ── Shared fetch ─────────────────────────────────────────────────────────────
function apiPost(params) {
  const fd = new FormData();
  fd.append('csrf', CSRF);
  for (const [k, v] of Object.entries(params)) fd.append(k, v);
  return fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
         .then(r => r.json());
}

function showToast(msg, isError = false) {
  const t = document.getElementById('commToast');
  t.textContent = msg;
  t.className = 'toast show ' + (isError ? 'err' : 'ok');
  setTimeout(() => t.classList.remove('show'), 3000);
}
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
