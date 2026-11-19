<?php
/**
 * Admin — BHC Enquiries Management
 *
 * Features:
 *   - List/search/filter all service enquiries
 *   - View enquiry detail with notes timeline
 *   - Update status
 *   - Assign/reassign provider
 *   - Add internal notes
 *
 * Auth: requires $_SESSION['staff_id'] + $_SESSION['is_admin']
 */

define('IN_237BIZ', true);
require_once __DIR__ . '/includes/config.php';

$staffId      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$isAdmin      = ($_SESSION['role'] ?? '') === 'admin';
$isOpsManager = !$isAdmin && ($_SESSION['role'] ?? '') === 'operations_admin';
if (!$staffId || (!$isAdmin && !$isOpsManager)) {
    http_response_code(403);
    die('Admin login required.');
}
$canManage = $isAdmin || $isOpsManager;

require_once __DIR__ . '/includes/bhc-catalogue.php';
require_once __DIR__ . '/includes/bhc-enquiry.php';

$pdo    = db();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── AJAX handlers ──────────────────────────────────────────────────────────
if ($action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    switch ($action) {

        case 'update_status': {
            $id     = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if ($id && $status) {
                $ok = bhc_update_enquiry_status($pdo, $id, $status, $staffId);
                echo json_encode(['ok' => $ok]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Missing id or status']);
            }
            exit;
        }

        case 'assign_provider': {
            $id         = (int)($_POST['id']          ?? 0);
            $providerId = (int)($_POST['provider_id'] ?? 0);
            if ($id && $providerId) {
                $ok = bhc_assign_enquiry($pdo, $id, $providerId, $staffId);
                echo json_encode(['ok' => $ok]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Missing id or provider_id']);
            }
            exit;
        }

        case 'add_note': {
            $id   = (int)($_POST['id']   ?? 0);
            $note = trim($_POST['note']  ?? '');
            if ($id && $note) {
                $noteId = bhc_add_enquiry_note($pdo, $id, $staffId, $note);
                echo json_encode(['ok' => (bool)$noteId, 'note_id' => $noteId]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Missing id or note']);
            }
            exit;
        }

        case 'get_notes': {
            $id    = (int)($_POST['id'] ?? 0);
            $notes = $id ? bhc_get_enquiry_notes($pdo, $id) : [];
            echo json_encode(['ok' => true, 'notes' => $notes]);
            exit;
        }

        case 'get_enquiry': {
            $id  = (int)($_POST['id'] ?? 0);
            $enq = $id ? bhc_get_enquiry($pdo, $id) : null;
            if ($enq) {
                $enq['notes']     = bhc_get_enquiry_notes($pdo, $id);
                $enq['providers'] = bhc_get_providers_for_service($pdo, (int)$enq['service_id']);
            }
            echo json_encode(['ok' => (bool)$enq, 'enquiry' => $enq]);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Filters for list view ──────────────────────────────────────────────────
$filterStatus     = trim($_GET['status']   ?? '');
$filterSearch     = trim($_GET['q']        ?? '');
$filterCategory   = (int)($_GET['cat']     ?? 0);
$filterUnassigned = !empty($_GET['unassigned']);
$page             = max(1, (int)($_GET['p'] ?? 1));
$perPage          = 30;
$offset           = ($page - 1) * $perPage;

$filters = [];
if ($filterStatus)     $filters['status']               = $filterStatus;
if ($filterSearch)     $filters['search']               = $filterSearch;
if ($filterCategory)   $filters['service_category_id']  = $filterCategory;
if ($filterUnassigned) $filters['unassigned']            = true;

$enquiries = bhc_get_enquiries($pdo, $filters, $perPage, $offset);
$total     = bhc_count_enquiries($pdo, $filters);
$pages     = (int)ceil($total / $perPage);

// ── Supporting data ────────────────────────────────────────────────────────
$categories = bhc_get_service_categories($pdo, 'en');
$providers  = bhc_get_all_providers($pdo);

$validStatuses = ['new','awaiting_assignment','assigned','contacted',
                  'quotation_provided','in_progress','converted','not_converted','closed'];

$statusLabels = [
    'new'                 => 'New',
    'awaiting_assignment' => 'Awaiting Assignment',
    'assigned'            => 'Assigned',
    'contacted'           => 'Contacted',
    'quotation_provided'  => 'Quotation Provided',
    'in_progress'         => 'In Progress',
    'converted'           => 'Converted',
    'not_converted'       => 'Not Converted',
    'closed'              => 'Closed',
];

$statusColors = [
    'new'                 => '#3b82f6',
    'awaiting_assignment' => '#f59e0b',
    'assigned'            => '#8b5cf6',
    'contacted'           => '#06b6d4',
    'quotation_provided'  => '#6366f1',
    'in_progress'         => '#10b981',
    'converted'           => '#22c55e',
    'not_converted'       => '#ef4444',
    'closed'              => '#6b7280',
];

require_once __DIR__ . '/includes/admin-header.php';
?>

<style>
/* ── Layout ── */
.enq-wrap        { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }
.enq-top         { display: flex; align-items: center; justify-content: space-between;
                   gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.enq-top h1      { margin: 0; font-size: 1.5rem; }
/* ── Filters ── */
.enq-filters     { display: flex; gap: .6rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
.enq-filters input, .enq-filters select { padding: .4rem .7rem; border: 1px solid #ddd;
  border-radius: .4rem; font-size: .9rem; }
.enq-filters .btn-sm { padding: .4rem .9rem; font-size: .85rem; }
/* ── Table ── */
.enq-table-wrap  { overflow-x: auto; border-radius: .5rem;
                   box-shadow: 0 1px 6px rgba(0,0,0,.08); }
.enq-table       { width: 100%; border-collapse: collapse; font-size: .9rem; }
.enq-table th    { background: #f8f9fa; padding: .65rem .9rem; text-align: left;
                   font-weight: 600; white-space: nowrap;
                   border-bottom: 2px solid #e9ecef; }
.enq-table td    { padding: .6rem .9rem; border-bottom: 1px solid #f0f0f0;
                   vertical-align: middle; }
.enq-table tr:hover td { background: #fafafa; }
/* ── Status badge ── */
.status-badge    { display: inline-block; padding: .2rem .55rem; border-radius: 10rem;
                   font-size: .78rem; font-weight: 600; color: #fff; white-space: nowrap; }
/* ── Action buttons ── */
.enq-action      { background: none; border: none; cursor: pointer;
                   color: #3b82f6; padding: 0 .4rem; font-size: 1rem; }
.enq-action:hover { color: #2563eb; }
/* ── Pagination ── */
.enq-pagination  { display: flex; gap: .4rem; justify-content: center;
                   margin-top: 1.5rem; flex-wrap: wrap; }
.enq-pagination a, .enq-pagination span {
  padding: .35rem .75rem; border: 1px solid #ddd; border-radius: .35rem;
  font-size: .88rem; text-decoration: none; color: inherit; }
.enq-pagination .current { background: #3b82f6; color: #fff; border-color: #3b82f6; }
/* ── Detail modal ── */
.enq-modal-bg    { display: none; position: fixed; inset: 0;
                   background: rgba(0,0,0,.5); z-index: 1000;
                   align-items: flex-start; justify-content: center;
                   padding: 2rem 1rem; overflow-y: auto; }
.enq-modal-bg.open { display: flex; }
.enq-modal       { background: #fff; border-radius: .75rem; width: 100%; max-width: 740px;
                   box-shadow: 0 8px 40px rgba(0,0,0,.2); animation: slideUp .2s ease; }
@keyframes slideUp { from { transform: translateY(16px); opacity:0; }
                     to   { transform: translateY(0);    opacity:1; } }
.enq-modal__hd   { display: flex; align-items: center; justify-content: space-between;
                   padding: 1.2rem 1.5rem; border-bottom: 1px solid #eee; }
.enq-modal__hd h2 { margin: 0; font-size: 1.15rem; }
.enq-modal__close { background: none; border: none; cursor: pointer; font-size: 1.4rem;
                    color: #888; line-height: 1; }
.enq-modal__bd   { padding: 1.4rem 1.5rem; }
/* ── Detail grid ── */
.enq-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem 1.2rem;
                   margin-bottom: 1.2rem; }
.enq-detail-row  { font-size: .88rem; }
.enq-detail-row strong { display: block; color: #555; font-size: .78rem;
                           text-transform: uppercase; letter-spacing: .04em; }
/* ── Action panels ── */
.enq-panel       { background: #f8f9fa; border-radius: .5rem;
                   padding: 1rem 1.1rem; margin-bottom: 1rem; }
.enq-panel h3    { margin: 0 0 .75rem; font-size: .95rem; }
.enq-panel select, .enq-panel textarea {
  width: 100%; padding: .45rem .65rem; border: 1px solid #ddd;
  border-radius: .35rem; font-size: .9rem; font-family: inherit;
  box-sizing: border-box; }
.enq-panel textarea { resize: vertical; }
.enq-panel .btn  { margin-top: .6rem; }
/* ── Notes timeline ── */
.notes-list      { list-style: none; margin: .5rem 0 0; padding: 0; }
.notes-list li   { border-left: 3px solid #3b82f6; padding: .4rem .8rem;
                   margin-bottom: .6rem; font-size: .88rem;
                   background: #fff; border-radius: 0 .35rem .35rem 0; }
.notes-list .note-meta { font-size: .78rem; color: #888; margin-bottom: .2rem; }
.notes-list .note-text { color: #333; }
/* ── Empty state ── */
.enq-empty { text-align: center; padding: 3rem 1rem; color: #888; }
</style>

<div class="enq-wrap">
  <div class="enq-top">
    <h1>BHC Enquiries
      <small style="font-size:.7em;color:#888;font-weight:400;">
        <?= number_format($total) ?> total
      </small>
    </h1>
  </div>

  <!-- Filters -->
  <form method="GET" class="enq-filters">
    <input type="text" name="q" placeholder="Search ref / business / email…"
           value="<?= htmlspecialchars($filterSearch) ?>">

    <select name="status">
      <option value="">All statuses</option>
      <?php foreach ($statusLabels as $k => $v): ?>
        <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>>
          <?= htmlspecialchars($v) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="cat">
      <option value="">All categories</option>
      <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= $filterCategory == $cat['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($cat['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label style="display:flex;align-items:center;gap:.35rem;font-size:.9rem;">
      <input type="checkbox" name="unassigned" value="1"
             <?= $filterUnassigned ? 'checked' : '' ?>>
      Unassigned only
    </label>

    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
    <a href="admin-bhc-enquiries.php" class="btn btn-secondary btn-sm">Reset</a>
  </form>

  <!-- Table -->
  <?php if ($enquiries): ?>
  <div class="enq-table-wrap">
    <table class="enq-table">
      <thead>
        <tr>
          <th>Ref</th>
          <th>Business</th>
          <th>Contact</th>
          <th>Service</th>
          <th>Provider</th>
          <th>Status</th>
          <th>Date</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($enquiries as $enq): ?>
        <tr>
          <td><code style="font-size:.82rem"><?= htmlspecialchars($enq['ref']) ?></code></td>
          <td><?= htmlspecialchars($enq['business_name']) ?></td>
          <td>
            <div><?= htmlspecialchars($enq['contact_name']) ?></div>
            <small style="color:#888"><?= htmlspecialchars($enq['email']) ?></small>
          </td>
          <td>
            <div><?= htmlspecialchars($enq['service_name_en'] ?? '—') ?></div>
            <small style="color:#888"><?= htmlspecialchars($enq['category_name_en'] ?? '') ?></small>
          </td>
          <td><?= $enq['provider_name'] ? htmlspecialchars($enq['provider_name']) : '<span style="color:#f59e0b">Unassigned</span>' ?></td>
          <td>
            <span class="status-badge"
                  style="background:<?= $statusColors[$enq['status']] ?? '#888' ?>">
              <?= htmlspecialchars($statusLabels[$enq['status']] ?? ucfirst($enq['status'])) ?>
            </span>
          </td>
          <td style="white-space:nowrap;font-size:.82rem;color:#666">
            <?= date('d M Y', strtotime($enq['created_at'])) ?>
          </td>
          <td>
            <button class="enq-action" title="View details"
                    onclick="openEnquiry(<?= $enq['id'] ?>)">👁</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div class="enq-pagination">
    <?php
    $qs = http_build_query(array_filter([
        'q'          => $filterSearch,
        'status'     => $filterStatus,
        'cat'        => $filterCategory ?: '',
        'unassigned' => $filterUnassigned ? '1' : '',
    ]));
    for ($i = 1; $i <= $pages; $i++):
        $href = '?' . ($qs ? $qs . '&' : '') . 'p=' . $i;
    ?>
      <?php if ($i === $page): ?>
        <span class="current"><?= $i ?></span>
      <?php else: ?>
        <a href="<?= htmlspecialchars($href) ?>"><?= $i ?></a>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <div class="enq-empty">
    <p style="font-size:2rem">📋</p>
    <p>No enquiries found matching your filters.</p>
  </div>
  <?php endif; ?>
</div>

<!-- ── Detail Modal ──────────────────────────────────────────────────────── -->
<div class="enq-modal-bg" id="enqModalBg" onclick="closeIfBg(event)">
  <div class="enq-modal" id="enqModal">
    <div class="enq-modal__hd">
      <h2 id="modalTitle">Enquiry</h2>
      <button class="enq-modal__close" onclick="closeModal()">×</button>
    </div>
    <div class="enq-modal__bd" id="modalBody">
      <p style="text-align:center;color:#888">Loading…</p>
    </div>
  </div>
</div>

<script>
const statusLabels  = <?= json_encode($statusLabels)  ?>;
const statusColors  = <?= json_encode($statusColors)  ?>;
const validStatuses = <?= json_encode($validStatuses) ?>;
const allProviders  = <?= json_encode(array_map(fn($p) => [
    'id'   => $p['id'],
    'name' => $p['business_name'],
], $providers)) ?>;

let currentEnqId = null;

function openEnquiry(id) {
    currentEnqId = id;
    document.getElementById('enqModalBg').classList.add('open');
    document.getElementById('modalBody').innerHTML = '<p style="text-align:center;color:#888">Loading…</p>';

    fetch('admin-bhc-enquiries.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'get_enquiry', id})
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) renderModal(data.enquiry);
        else document.getElementById('modalBody').innerHTML = '<p style="color:red">Failed to load.</p>';
    });
}

function renderModal(e) {
    document.getElementById('modalTitle').textContent = `Enquiry ${e.ref}`;

    const statusOpts = validStatuses
        .map(s => `<option value="${s}" ${e.status===s?'selected':''}>${statusLabels[s]||s}</option>`)
        .join('');

    const provOpts = allProviders
        .map(p => `<option value="${p.id}" ${e.assigned_provider_id==p.id?'selected':''}>${p.name}</option>`)
        .join('');

    const notesHtml = (e.notes && e.notes.length)
        ? `<ul class="notes-list">${e.notes.map(n =>
            `<li>
              <div class="note-meta">${n.staff_name||'Staff'} · ${formatDate(n.created_at)}</div>
              <div class="note-text">${escHtml(n.note)}</div>
            </li>`).join('')}</ul>`
        : '<p style="color:#aaa;font-size:.85rem">No notes yet.</p>';

    document.getElementById('modalBody').innerHTML = `
      <div class="enq-detail-grid">
        <div class="enq-detail-row">
          <strong>Reference</strong>${escHtml(e.ref)}
        </div>
        <div class="enq-detail-row">
          <strong>Status</strong>
          <span class="status-badge" style="background:${statusColors[e.status]||'#888'}">
            ${statusLabels[e.status]||e.status}
          </span>
        </div>
        <div class="enq-detail-row">
          <strong>Business</strong>${escHtml(e.business_name)}
        </div>
        <div class="enq-detail-row">
          <strong>Contact</strong>${escHtml(e.contact_name)}
        </div>
        <div class="enq-detail-row">
          <strong>Email</strong><a href="mailto:${escHtml(e.email)}">${escHtml(e.email)}</a>
        </div>
        <div class="enq-detail-row">
          <strong>Phone</strong>${e.phone ? escHtml(e.phone) : '—'}
        </div>
        <div class="enq-detail-row">
          <strong>Location</strong>${e.location ? escHtml(e.location) : '—'}
        </div>
        <div class="enq-detail-row">
          <strong>Preferred contact</strong>${escHtml(e.preferred_contact||'email')}
        </div>
        <div class="enq-detail-row" style="grid-column:1/-1">
          <strong>Service</strong>${escHtml(e.service_name_en||'')}
          ${e.category_name_en ? ` <span style="color:#888">/ ${escHtml(e.category_name_en)}</span>` : ''}
        </div>
        ${e.requirements ? `<div class="enq-detail-row" style="grid-column:1/-1">
          <strong>Requirements</strong>${escHtml(e.requirements)}
        </div>` : ''}
        <div class="enq-detail-row">
          <strong>Submitted</strong>${formatDate(e.created_at)}
        </div>
        <div class="enq-detail-row">
          <strong>Language</strong>${(e.lang||'en').toUpperCase()}
        </div>
        ${e.referral_source ? `<div class="enq-detail-row">
          <strong>Referral source</strong>${escHtml(e.referral_source)}
        </div>` : ''}
        ${e.gp_id ? `<div class="enq-detail-row">
          <strong>Growth Partner ID</strong>${e.gp_id}
        </div>` : ''}
      </div>

      <!-- Update status -->
      <div class="enq-panel">
        <h3>Update Status</h3>
        <select id="newStatus">${statusOpts}</select>
        <button class="btn btn-primary btn-sm" onclick="updateStatus()">Save status</button>
      </div>

      <!-- Assign provider -->
      <div class="enq-panel">
        <h3>Assign Provider</h3>
        <select id="newProvider">
          <option value="">— select provider —</option>
          ${provOpts}
        </select>
        <button class="btn btn-primary btn-sm" onclick="assignProvider()">Assign &amp; notify</button>
      </div>

      <!-- Notes -->
      <div class="enq-panel">
        <h3>Internal Notes</h3>
        ${notesHtml}
        <textarea id="newNote" rows="2" placeholder="Add a note…" style="margin-top:.6rem"></textarea>
        <button class="btn btn-secondary btn-sm" onclick="addNote()">Add note</button>
      </div>
    `;
}

function updateStatus() {
    const status = document.getElementById('newStatus').value;
    apiPost({action: 'update_status', id: currentEnqId, status})
        .then(d => {
            if (d.ok) { showToast('Status updated'); openEnquiry(currentEnqId); }
            else showToast('Failed: ' + (d.error||''), true);
        });
}

function assignProvider() {
    const providerId = document.getElementById('newProvider').value;
    if (!providerId) { showToast('Please select a provider', true); return; }
    apiPost({action: 'assign_provider', id: currentEnqId, provider_id: providerId})
        .then(d => {
            if (d.ok) { showToast('Provider assigned and notified'); openEnquiry(currentEnqId); }
            else showToast('Failed: ' + (d.error||''), true);
        });
}

function addNote() {
    const note = document.getElementById('newNote').value.trim();
    if (!note) return;
    apiPost({action: 'add_note', id: currentEnqId, note})
        .then(d => {
            if (d.ok) { showToast('Note added'); openEnquiry(currentEnqId); }
            else showToast('Failed', true);
        });
}

function apiPost(params) {
    return fetch('admin-bhc-enquiries.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams(params)
    }).then(r => r.json());
}

function closeModal() {
    document.getElementById('enqModalBg').classList.remove('open');
    currentEnqId = null;
}
function closeIfBg(e) { if (e.target === document.getElementById('enqModalBg')) closeModal(); }

function formatDate(dt) {
    if (!dt) return '—';
    const d = new Date(dt);
    return d.toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) +
           ' ' + d.toLocaleTimeString('en-GB', {hour:'2-digit',minute:'2-digit'});
}
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// Toast
function showToast(msg, error = false) {
    const t = document.createElement('div');
    t.style.cssText = `position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;
      padding:.7rem 1.2rem;border-radius:.5rem;font-size:.9rem;color:#fff;
      background:${error?'#ef4444':'#22c55e'};box-shadow:0 2px 8px rgba(0,0,0,.2)`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
}
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
