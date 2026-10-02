<?php
/**
 * partner/automation.php — 237Biz Growth Partner
 * WHEN→IF→THEN Automation Builder
 * Phase 3C: Lead Follow-Up, Review Automation, Custom Workflows
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/partner-helpers.php';

$partner = requireGrowthPartner();
$pid     = (int)$partner['id'];
$pdo     = db();
$userId  = (int)($_SESSION['user_id'] ?? 0);

/* ── Trigger types catalogue ─────────────────────────────────────── */
$TRIGGERS = [
    'new_lead'           => ['label' => 'New Lead Received',        'icon' => '💬', 'group' => 'Leads'],
    'lead_status_change' => ['label' => 'Lead Status Changes',      'icon' => '🔄', 'group' => 'Leads'],
    'lead_no_contact'    => ['label' => 'Lead Not Contacted (N days)','icon'=> '⏰', 'group' => 'Leads'],
    'booking_completed'  => ['label' => 'Booking Completed',        'icon' => '📅', 'group' => 'Bookings'],
    'booking_reminder'   => ['label' => 'Booking Upcoming (N days) ','icon' => '🔔', 'group' => 'Bookings'],
    'review_received'    => ['label' => 'New Review Received',      'icon' => '⭐', 'group' => 'Reviews'],
    'no_recent_review'   => ['label' => 'No Review in N Days',      'icon' => '📣', 'group' => 'Reviews'],
    'task_overdue'       => ['label' => 'Task Becomes Overdue',     'icon' => '⚠️', 'group' => 'Tasks'],
    'campaign_started'   => ['label' => 'Campaign Goes Live',       'icon' => '📣', 'group' => 'Campaigns'],
    'campaign_ended'     => ['label' => 'Campaign Ends',            'icon' => '🏁', 'group' => 'Campaigns'],
    'content_published'  => ['label' => 'Content Published',        'icon' => '📝', 'group' => 'Content'],
];

/* ── Action types catalogue ──────────────────────────────────────── */
$ACTIONS = [
    'create_task'         => ['label' => 'Create a Task',           'icon' => '✅'],
    'send_notification'   => ['label' => 'Send Me a Notification',  'icon' => '🔔'],
    'log_lead_activity'   => ['label' => 'Log Lead Activity',       'icon' => '📋'],
    'update_lead_status'  => ['label' => 'Update Lead Status',      'icon' => '🔄'],
    'send_template'       => ['label' => 'Queue Template Message',  'icon' => '📨'],
    'create_followup'     => ['label' => 'Schedule Follow-Up',      'icon' => '📅'],
    'flag_for_review'     => ['label' => 'Flag for My Review',      'icon' => '🚩'],
];

/* ── Condition fields catalogue ──────────────────────────────────── */
$CONDITIONS = [
    'lead_status'   => 'Lead Status',
    'lead_source'   => 'Lead Source',
    'days_since'    => 'Days Since Created',
    'campaign_type' => 'Campaign Type',
    'review_rating' => 'Review Rating',
    'has_email'     => 'Lead Has Email',
    'has_phone'     => 'Lead Has Phone',
];

/* ── Starter templates ───────────────────────────────────────────── */
function starterWorkflows(): array {
    return [
        [
            'name'           => 'New Lead — Immediate Follow-Up',
            'description'    => 'When a new lead arrives, create a follow-up task and log the activity so nothing is missed.',
            'trigger_type'   => 'new_lead',
            'execution_mode' => 'automatic',
            'steps'          => [
                ['step_type'=>'action','action_type'=>'create_task',
                 'config'=>['title'=>'Follow up with new lead: {lead_name}','category'=>'leads','priority'=>'high','due_days'=>1]],
                ['step_type'=>'action','action_type'=>'log_lead_activity',
                 'config'=>['activity_type'=>'follow_up','notes'=>'Auto-logged: new lead received, task created']],
            ],
        ],
        [
            'name'           => 'Lead Not Contacted in 2 Days',
            'description'    => 'If a lead is still "new" after 2 days with no activity, send yourself a reminder notification.',
            'trigger_type'   => 'lead_no_contact',
            'execution_mode' => 'automatic',
            'steps'          => [
                ['step_type'=>'condition','condition_field'=>'lead_status','condition_op'=>'equals','condition_value'=>'new','config'=>[]],
                ['step_type'=>'action','action_type'=>'send_notification',
                 'config'=>['message'=>'⚠ Lead not contacted in 2 days: {lead_name} from {business}']],
                ['step_type'=>'action','action_type'=>'create_task',
                 'config'=>['title'=>'URGENT: Contact {lead_name} — overdue follow-up','category'=>'leads','priority'=>'urgent','due_days'=>0]],
            ],
        ],
        [
            'name'           => 'Post-Booking Review Request',
            'description'    => 'After a booking is completed, create a task to send a review request to the customer.',
            'trigger_type'   => 'booking_completed',
            'execution_mode' => 'requires_approval',
            'steps'          => [
                ['step_type'=>'action','action_type'=>'create_task',
                 'config'=>['title'=>'Send review request to booking customer','category'=>'reviews','priority'=>'medium','due_days'=>2]],
                ['step_type'=>'action','action_type'=>'send_notification',
                 'config'=>['message'=>'Booking completed for {business} — review request task created']],
            ],
        ],
        [
            'name'           => 'Lead Converted — Log & Celebrate',
            'description'    => 'When a lead is marked as converted, log the activity and flag for commission tracking.',
            'trigger_type'   => 'lead_status_change',
            'execution_mode' => 'automatic',
            'steps'          => [
                ['step_type'=>'condition','condition_field'=>'lead_status','condition_op'=>'equals','condition_value'=>'converted','config'=>[]],
                ['step_type'=>'action','action_type'=>'log_lead_activity',
                 'config'=>['activity_type'=>'converted','notes'=>'Auto-logged: lead marked as converted']],
                ['step_type'=>'action','action_type'=>'flag_for_review',
                 'config'=>['reason'=>'Converted lead — check commission eligibility']],
            ],
        ],
        [
            'name'           => 'No Review in 30 Days',
            'description'    => 'If a business has received no reviews in 30 days, create a task to run a review campaign.',
            'trigger_type'   => 'no_recent_review',
            'execution_mode' => 'requires_approval',
            'steps'          => [
                ['step_type'=>'action','action_type'=>'create_task',
                 'config'=>['title'=>'Run review campaign — no review in 30+ days','category'=>'reviews','priority'=>'medium','due_days'=>3]],
                ['step_type'=>'action','action_type'=>'send_notification',
                 'config'=>['message'=>'{business} has had no new reviews in 30+ days — action needed']],
            ],
        ],
    ];
}

/* ── POST handlers ───────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Install starter workflow ────────────────────────────────────
    if ($action === 'install_starter') {
        $idx = (int)($_POST['starter_index'] ?? -1);
        $lid = (int)($_POST['listing_id'] ?? 0);
        $starters = starterWorkflows();
        if (!isset($starters[$idx])) { setFlash('error','Invalid starter.'); redirect(SITE_URL.'/partner/automation'); }
        $s = $starters[$idx];

        // Verify listing ownership
        $listings = getPartnerListings($pid);
        $listingIds = array_column($listings, 'id');
        if ($lid && !in_array($lid, $listingIds)) { setFlash('error','Listing not found.'); redirect(SITE_URL.'/partner/automation'); }

        $pdo->prepare("INSERT INTO automation_workflows
                       (partner_id,listing_id,name,description,trigger_type,execution_mode,status,created_at)
                       VALUES (?,?,?,?,?,?,'draft',NOW())")
            ->execute([$pid, $lid ?: null, $s['name'], $s['description'], $s['trigger_type'], $s['execution_mode']]);
        $wid = (int)$pdo->lastInsertId();

        foreach ($s['steps'] as $order => $step) {
            $pdo->prepare("INSERT INTO automation_steps
                           (workflow_id,step_order,step_type,action_type,condition_field,condition_op,condition_value,config)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([
                    $wid, $order,
                    $step['step_type'],
                    $step['action_type'] ?? null,
                    $step['condition_field'] ?? null,
                    $step['condition_op'] ?? null,
                    $step['condition_value'] ?? null,
                    json_encode($step['config'] ?? []),
                ]);
        }

        partnerAuditLog($pid, $userId, 'automation_created', "Installed starter: {$s['name']}", ['workflow_id'=>$wid]);
        setFlash('success', "Workflow \"{$s['name']}\" installed as a draft. Activate it when ready.");
        redirect(SITE_URL.'/partner/automation?view=edit&wid='.$wid);
    }

    // ── Create blank workflow ───────────────────────────────────────
    if ($action === 'create_workflow') {
        $name    = trim($_POST['name'] ?? '');
        $trigger = $_POST['trigger_type'] ?? '';
        $mode    = $_POST['execution_mode'] ?? 'requires_approval';
        $lid     = (int)($_POST['listing_id'] ?? 0);
        $desc    = trim($_POST['description'] ?? '');

        if (!$name || !$trigger) { setFlash('error','Name and trigger are required.'); redirect(SITE_URL.'/partner/automation'); }

        $validTriggers = ['new_lead','lead_status_change','lead_no_contact','booking_completed','booking_reminder','review_received','no_recent_review','task_overdue','campaign_started','campaign_ended','content_published'];
        $validModes    = ['automatic','requires_approval','manual'];
        if (!in_array($trigger, $validTriggers)) $trigger = 'new_lead';
        if (!in_array($mode, $validModes))       $mode    = 'requires_approval';

        $pdo->prepare("INSERT INTO automation_workflows
                       (partner_id,listing_id,name,description,trigger_type,execution_mode,status,created_at)
                       VALUES (?,?,?,?,?,?,'draft',NOW())")
            ->execute([$pid, $lid ?: null, $name, $desc, $trigger, $mode]);
        $wid = (int)$pdo->lastInsertId();

        partnerAuditLog($pid, $userId, 'automation_created', "Created workflow: $name", ['workflow_id'=>$wid]);
        setFlash('success', 'Workflow created. Add your steps below.');
        redirect(SITE_URL.'/partner/automation?view=edit&wid='.$wid);
    }

    // ── Add step ───────────────────────────────────────────────────
    if ($action === 'add_step') {
        $wid       = (int)($_POST['workflow_id'] ?? 0);
        $stepType  = $_POST['step_type'] ?? 'action';
        $actionType= $_POST['action_type'] ?? '';
        $condField = $_POST['condition_field'] ?? '';
        $condOp    = $_POST['condition_op'] ?? 'equals';
        $condVal   = trim($_POST['condition_value'] ?? '');
        $cfgRaw    = $_POST['config'] ?? [];

        $wrow = $pdo->prepare("SELECT * FROM automation_workflows WHERE id=? AND partner_id=?");
        $wrow->execute([$wid, $pid]);
        $wf = $wrow->fetch();
        if (!$wf) { setFlash('error','Workflow not found.'); redirect(SITE_URL.'/partner/automation'); }

        $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(step_order),0) FROM automation_steps WHERE workflow_id=?");
        $maxOrder->execute([$wid]);
        $nextOrder = (int)$maxOrder->fetchColumn() + 1;

        $config = [];
        if ($stepType === 'action') {
            if ($actionType === 'create_task') {
                $config = [
                    'title'    => trim($cfgRaw['task_title'] ?? 'Follow up'),
                    'category' => $cfgRaw['task_category'] ?? 'leads',
                    'priority' => $cfgRaw['task_priority'] ?? 'medium',
                    'due_days' => (int)($cfgRaw['due_days'] ?? 1),
                ];
            } elseif ($actionType === 'send_notification') {
                $config = ['message' => trim($cfgRaw['message'] ?? 'Automation triggered')];
            } elseif ($actionType === 'log_lead_activity') {
                $config = [
                    'activity_type' => $cfgRaw['activity_type'] ?? 'follow_up',
                    'notes'         => trim($cfgRaw['notes'] ?? ''),
                ];
            } elseif ($actionType === 'update_lead_status') {
                $config = ['new_status' => $cfgRaw['new_status'] ?? 'contacted'];
            } elseif ($actionType === 'flag_for_review') {
                $config = ['reason' => trim($cfgRaw['reason'] ?? '')];
            } elseif ($actionType === 'create_followup') {
                $config = [
                    'title'    => trim($cfgRaw['followup_title'] ?? 'Follow up'),
                    'due_days' => (int)($cfgRaw['due_days'] ?? 3),
                ];
            } elseif ($actionType === 'send_template') {
                $config = ['template_id' => (int)($cfgRaw['template_id'] ?? 0)];
            }
        } elseif ($stepType === 'wait') {
            $config = ['wait_minutes' => max(1, (int)($cfgRaw['wait_minutes'] ?? 60))];
        }

        $pdo->prepare("INSERT INTO automation_steps
                       (workflow_id,step_order,step_type,action_type,condition_field,condition_op,condition_value,config)
                       VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$wid, $nextOrder, $stepType,
                       $stepType==='action'?$actionType:null,
                       $stepType==='condition'?$condField:null,
                       $stepType==='condition'?$condOp:null,
                       $stepType==='condition'?$condVal:null,
                       json_encode($config)]);

        partnerAuditLog($pid, $userId, 'automation_step_added', "Step added to workflow $wid", []);
        setFlash('success', 'Step added.');
        redirect(SITE_URL.'/partner/automation?view=edit&wid='.$wid);
    }

    // ── Delete step ────────────────────────────────────────────────
    if ($action === 'delete_step') {
        $stepId = (int)($_POST['step_id'] ?? 0);
        $wid    = (int)($_POST['workflow_id'] ?? 0);
        $pdo->prepare("DELETE FROM automation_steps WHERE id=? AND workflow_id=? AND workflow_id IN
                       (SELECT id FROM automation_workflows WHERE partner_id=?)")
            ->execute([$stepId, $wid, $pid]);
        // Re-number
        $steps = $pdo->prepare("SELECT id FROM automation_steps WHERE workflow_id=? ORDER BY step_order ASC");
        $steps->execute([$wid]);
        foreach ($steps->fetchAll() as $i => $st) {
            $pdo->prepare("UPDATE automation_steps SET step_order=? WHERE id=?")->execute([$i+1, $st['id']]);
        }
        setFlash('success','Step removed.');
        redirect(SITE_URL.'/partner/automation?view=edit&wid='.$wid);
    }

    // ── Toggle workflow status ──────────────────────────────────────
    if ($action === 'toggle_status') {
        $wid    = (int)($_POST['workflow_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!in_array($status, ['active','paused','draft'])) { setFlash('error','Invalid status.'); redirect(SITE_URL.'/partner/automation'); }
        $wrow = $pdo->prepare("SELECT * FROM automation_workflows WHERE id=? AND partner_id=?");
        $wrow->execute([$wid, $pid]);
        $wf = $wrow->fetch();
        if (!$wf) { setFlash('error','Not found.'); redirect(SITE_URL.'/partner/automation'); }
        // Require at least one step to activate
        if ($status === 'active') {
            $stepCount = $pdo->prepare("SELECT COUNT(*) FROM automation_steps WHERE workflow_id=?");
            $stepCount->execute([$wid]);
            if ((int)$stepCount->fetchColumn() === 0) {
                setFlash('error','Add at least one step before activating.');
                redirect(SITE_URL.'/partner/automation?view=edit&wid='.$wid);
            }
        }
        $pdo->prepare("UPDATE automation_workflows SET status=? WHERE id=? AND partner_id=?")->execute([$status, $wid, $pid]);
        partnerAuditLog($pid, $userId, 'automation_status_changed', "Workflow $wid → $status", []);
        setFlash('success', 'Workflow ' . ucfirst($status) . '.');
        redirect(SITE_URL.'/partner/automation?view=edit&wid='.$wid);
    }

    // ── Delete workflow ─────────────────────────────────────────────
    if ($action === 'delete_workflow') {
        $wid = (int)($_POST['workflow_id'] ?? 0);
        $pdo->prepare("DELETE FROM automation_steps WHERE workflow_id=? AND workflow_id IN
                       (SELECT id FROM automation_workflows WHERE partner_id=?)")->execute([$wid, $pid]);
        $pdo->prepare("DELETE FROM automation_workflows WHERE id=? AND partner_id=?")->execute([$wid, $pid]);
        partnerAuditLog($pid, $userId, 'automation_deleted', "Workflow $wid deleted", []);
        setFlash('success','Workflow deleted.');
        redirect(SITE_URL.'/partner/automation');
    }

    // ── Approve pending run ─────────────────────────────────────────
    if ($action === 'approve_run') {
        $runId = (int)($_POST['run_id'] ?? 0);
        $pdo->prepare("UPDATE automation_runs SET status='pending', approval_required=0, approved_by=?, approved_at=NOW()
                       WHERE id=? AND partner_id=? AND status='awaiting_approval'")
            ->execute([$userId, $runId, $pid]);
        setFlash('success','Run approved — will execute shortly.');
        redirect(SITE_URL.'/partner/automation');
    }

    // ── Dismiss/cancel run ──────────────────────────────────────────
    if ($action === 'dismiss_run') {
        $runId = (int)($_POST['run_id'] ?? 0);
        $pdo->prepare("UPDATE automation_runs SET status='cancelled' WHERE id=? AND partner_id=?")->execute([$runId, $pid]);
        setFlash('success','Run dismissed.');
        redirect(SITE_URL.'/partner/automation');
    }
}

/* ── View routing ────────────────────────────────────────────────── */
$view = $_GET['view'] ?? 'list';
$wid  = (int)($_GET['wid'] ?? 0);

/* ── Listings for dropdowns ──────────────────────────────────────── */
$listings = getPartnerListings($pid);

/* ── Edit view data ──────────────────────────────────────────────── */
$editWorkflow = null;
$editSteps    = [];
$editTemplates= [];
if ($view === 'edit' && $wid) {
    $ew = $pdo->prepare("SELECT aw.*, l.title AS biz_name FROM automation_workflows aw LEFT JOIN listings l ON l.id=aw.listing_id WHERE aw.id=? AND aw.partner_id=?");
    $ew->execute([$wid, $pid]);
    $editWorkflow = $ew->fetch();
    if (!$editWorkflow) { setFlash('error','Workflow not found.'); redirect(SITE_URL.'/partner/automation'); }
    $es = $pdo->prepare("SELECT * FROM automation_steps WHERE workflow_id=? ORDER BY step_order ASC");
    $es->execute([$wid]);
    $editSteps = $es->fetchAll();
    // Templates for send_template action
    $et = $pdo->prepare("SELECT id, name, template_type, channel FROM communication_templates WHERE partner_id=? AND status='active' ORDER BY name ASC");
    $et->execute([$pid]);
    $editTemplates = $et->fetchAll();
}

/* ── List view data ──────────────────────────────────────────────── */
$workflows    = [];
$pendingRuns  = [];
$runCounts    = [];
if ($view === 'list') {
    $wfs = $pdo->prepare("SELECT aw.*, l.title AS biz_name,
                          (SELECT COUNT(*) FROM automation_steps WHERE workflow_id=aw.id) AS step_count
                          FROM automation_workflows aw
                          LEFT JOIN listings l ON l.id=aw.listing_id
                          WHERE aw.partner_id=?
                          ORDER BY aw.created_at DESC");
    $wfs->execute([$pid]);
    $workflows = $wfs->fetchAll();

    $pr = $pdo->prepare("SELECT ar.*, aw.name AS workflow_name, l.title AS biz_name
                         FROM automation_runs ar
                         JOIN automation_workflows aw ON aw.id=ar.workflow_id
                         LEFT JOIN listings l ON l.id=ar.listing_id
                         WHERE ar.partner_id=? AND ar.status='awaiting_approval'
                         ORDER BY ar.created_at DESC LIMIT 20");
    $pr->execute([$pid]);
    $pendingRuns = $pr->fetchAll();
}

$GLOBALS['TRIGGERS'] = $TRIGGERS;
$GLOBALS['ACTIONS']  = $ACTIONS;

/* ─────────────────── HTML ──────────────────────────────────────── */
$pageTitle = 'Automation Builder';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
:root{--pauto:#7c3aed;--pauto-light:#f5f3ff;--pauto-border:#ddd6fe}
.auto-header{margin-bottom:24px}
.auto-header h1{font-size:1.6rem;font-weight:700;color:#1f2937;margin:0 0 4px}
.auto-header p{color:#6b7280;margin:0}

/* Workflow cards */
.wf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;margin-bottom:28px}
.wf-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;position:relative}
.wf-card.active{border-left:4px solid #16a34a}
.wf-card.paused{border-left:4px solid #ca8a04}
.wf-card.draft{border-left:4px solid #9ca3af}
.wf-title{font-weight:700;color:#1f2937;font-size:1rem;margin:0 0 4px}
.wf-meta{font-size:.82rem;color:#6b7280;margin:0 0 10px}
.wf-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600}
.badge-active{background:#dcfce7;color:#166534}
.badge-paused{background:#fef9c3;color:#854d0e}
.badge-draft{background:#f3f4f6;color:#6b7280}
.wf-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;padding-top:10px;border-top:1px solid #f3f4f6}
.step-count{font-size:.8rem;color:#6b7280}

/* Step builder */
.step-list{display:flex;flex-direction:column;gap:0}
.step-row{display:flex;align-items:stretch;gap:0}
.step-connector{width:2px;background:#e5e7eb;margin:0 auto;min-height:20px}
.step-block{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;flex:1;position:relative;margin-bottom:8px}
.step-block.type-condition{border-left:4px solid #2563eb;background:#eff6ff}
.step-block.type-action{border-left:4px solid var(--pauto);background:var(--pauto-light)}
.step-block.type-wait{border-left:4px solid #ca8a04;background:#fefce8}
.step-label{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;margin-bottom:4px}
.step-desc{font-size:.88rem;color:#374151;font-weight:600}
.step-sub{font-size:.8rem;color:#6b7280;margin-top:2px}
.step-num{position:absolute;top:-10px;left:12px;background:#fff;border:1px solid #e5e7eb;border-radius:99px;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:700;color:#6b7280}

/* Add step form */
.add-step-panel{background:#f9fafb;border:2px dashed #d1d5db;border-radius:10px;padding:20px;margin-top:12px}
.add-step-panel h4{margin:0 0 14px;font-size:.92rem;color:#374151}
.step-type-tabs{display:flex;gap:8px;margin-bottom:14px}
.sttab{padding:6px 14px;border-radius:6px;border:1px solid #d1d5db;background:#fff;font-size:.85rem;cursor:pointer;font-weight:500;color:#374151}
.sttab.active{background:var(--pauto);color:#fff;border-color:var(--pauto)}

/* Pending runs */
.pending-run{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 16px;margin-bottom:10px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.pending-run .run-info{flex:1;min-width:200px}
.pending-run .run-actions{display:flex;gap:8px}

/* Starter templates */
.starters-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;margin-bottom:28px}
.starter-card{background:#fff;border:1px solid var(--pauto-border);border-radius:10px;padding:16px}
.starter-title{font-weight:700;color:var(--pauto);font-size:.9rem;margin:0 0 5px}
.starter-desc{font-size:.82rem;color:#6b7280;margin:0 0 12px;line-height:1.4}
.starter-trigger{font-size:.78rem;background:var(--pauto-light);color:var(--pauto);padding:3px 8px;border-radius:4px;font-weight:600;margin-bottom:10px;display:inline-block}

/* Status/trigger badges in edit view */
.trigger-banner{background:var(--pauto-light);border:1px solid var(--pauto-border);border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px}
.trigger-icon{font-size:1.6rem}
.trigger-info h3{margin:0 0 2px;font-size:1rem;color:var(--pauto)}
.trigger-info p{margin:0;font-size:.83rem;color:#6b7280}

/* Utility */
.btn-xs{padding:4px 10px;font-size:.8rem;border-radius:5px;border:1px solid #d1d5db;background:#fff;cursor:pointer;color:#374151;text-decoration:none;display:inline-block}
.btn-xs:hover{background:#f3f4f6}
.btn-xs.primary{background:#1a56db;color:#fff;border-color:#1a56db}
.btn-xs.purple{background:var(--pauto);color:#fff;border-color:var(--pauto)}
.btn-xs.green{background:#16a34a;color:#fff;border-color:#16a34a}
.btn-xs.red{background:#dc2626;color:#fff;border-color:#dc2626}
.btn-xs.amber{background:#ca8a04;color:#fff;border-color:#ca8a04}
.back-link{display:inline-flex;align-items:center;gap:6px;color:#6b7280;font-size:.87rem;text-decoration:none;margin-bottom:16px}
.back-link:hover{color:#374151}
.flash{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.9rem}
.flash.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.flash.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.section-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:20px}
.section-card h3{margin:0 0 14px;font-size:1rem;color:#1f2937}
label.fl{display:block;font-size:.85rem;font-weight:600;color:#374151;margin-bottom:4px}
select.fi,input.fi,textarea.fi{width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:7px;font-size:.9rem;box-sizing:border-box;margin-bottom:12px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:600px){.form-row{grid-template-columns:1fr}}
.empty-state{text-align:center;padding:40px 20px;color:#9ca3af}
.empty-state p{margin:8px 0 0;font-size:.9rem}
.mode-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.75rem;font-weight:600}
.mode-auto{background:#dcfce7;color:#166534}
.mode-approval{background:#fffbeb;color:#854d0e}
.mode-manual{background:#f3f4f6;color:#6b7280}
</style>

<?php if ($flash = getFlash('success')): ?>
<div class="flash success"><?= e($flash) ?></div>
<?php endif; ?>
<?php if ($flash = getFlash('error')): ?>
<div class="flash error"><?= e($flash) ?></div>
<?php endif; ?>

<?php if ($view === 'edit' && $editWorkflow): ?>
<!-- ══════════════════════════════════════════════════════ -->
<!-- EDIT / BUILD VIEW                                       -->
<!-- ══════════════════════════════════════════════════════ -->
<a href="<?= SITE_URL ?>/partner/automation" class="back-link">← Back to Automations</a>

<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:18px">
<div>
    <h1 style="font-size:1.4rem;font-weight:700;color:#1f2937;margin:0 0 4px"><?= e($editWorkflow['name']) ?></h1>
    <p style="color:#6b7280;margin:0;font-size:.87rem">
        <?= $editWorkflow['biz_name'] ? e($editWorkflow['biz_name']) . ' · ' : 'All portfolio · ' ?>
        <span class="mode-badge mode-<?= $editWorkflow['execution_mode']==='automatic'?'auto':($editWorkflow['execution_mode']==='requires_approval'?'approval':'manual') ?>">
            <?= $editWorkflow['execution_mode']==='automatic'?'Runs automatically':($editWorkflow['execution_mode']==='requires_approval'?'Requires approval':'Manual only') ?>
        </span>
    </p>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($editWorkflow['status'] !== 'active'): ?>
    <form method="post" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="workflow_id" value="<?= $editWorkflow['id'] ?>">
        <input type="hidden" name="status" value="active">
        <button type="submit" class="btn-xs green">▶ Activate</button>
    </form>
    <?php endif; ?>
    <?php if ($editWorkflow['status'] === 'active'): ?>
    <form method="post" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="toggle_status">
        <input type="hidden" name="workflow_id" value="<?= $editWorkflow['id'] ?>">
        <input type="hidden" name="status" value="paused">
        <button type="submit" class="btn-xs amber">⏸ Pause</button>
    </form>
    <?php endif; ?>
    <form method="post" style="display:inline" onsubmit="return confirm('Delete this workflow and all its steps?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_workflow">
        <input type="hidden" name="workflow_id" value="<?= $editWorkflow['id'] ?>">
        <button type="submit" class="btn-xs red">🗑 Delete</button>
    </form>
</div>
</div>

<!-- Trigger banner -->
<?php $trig = $TRIGGERS[$editWorkflow['trigger_type']] ?? ['label'=>$editWorkflow['trigger_type'],'icon'=>'⚡','group'=>'']; ?>
<div class="trigger-banner">
    <div class="trigger-icon"><?= $trig['icon'] ?></div>
    <div class="trigger-info">
        <h3>WHEN: <?= e($trig['label']) ?></h3>
        <p>Group: <?= e($trig['group']) ?> · Status: <strong><?= ucfirst($editWorkflow['status']) ?></strong> · Mode: <?= ucwords(str_replace('_',' ',$editWorkflow['execution_mode'])) ?></p>
    </div>
</div>

<!-- Step list -->
<div class="section-card">
    <h3>Workflow Steps</h3>
    <?php if (empty($editSteps)): ?>
    <p style="color:#9ca3af;font-size:.9rem">No steps yet. Add your first step below.</p>
    <?php else: ?>
    <div class="step-list">
    <?php foreach ($editSteps as $i => $step): ?>
        <?php
        $cfg = json_decode($step['config'] ?? '{}', true) ?: [];
        if ($step['step_type'] === 'condition') {
            $fieldLabel = $CONDITIONS[$step['condition_field']] ?? $step['condition_field'];
            $desc = "IF: $fieldLabel {$step['condition_op']} \"{$step['condition_value']}\"";
            $sub  = '';
        } elseif ($step['step_type'] === 'action') {
            $actLabel = $ACTIONS[$step['action_type']]['icon']??'' .' '. ($ACTIONS[$step['action_type']]['label'] ?? $step['action_type']);
            $desc = 'THEN: ' . trim($actLabel);
            $sub = '';
            switch ($step['action_type']) {
                case 'create_task':        $sub = "Task: \"{$cfg['title']}\" · {$cfg['priority']} priority · due in {$cfg['due_days']}d"; break;
                case 'send_notification':  $sub = "Message: \"{$cfg['message']}\""; break;
                case 'log_lead_activity':  $sub = "Type: {$cfg['activity_type']}" . ($cfg['notes'] ? " · \"{$cfg['notes']}\"" : ""); break;
                case 'update_lead_status': $sub = "New status: {$cfg['new_status']}"; break;
                case 'flag_for_review':    $sub = "Reason: \"{$cfg['reason']}\""; break;
                case 'create_followup':    $sub = "Follow-up: \"{$cfg['title']}\" in {$cfg['due_days']}d"; break;
                case 'send_template':      $sub = "Template ID: {$cfg['template_id']}"; break;
            }
        } else {
            $desc = 'WAIT: ' . (int)($cfg['wait_minutes'] ?? 60) . ' minutes';
            $sub  = '';
        }
        $typeClass = 'type-' . $step['step_type'];
        ?>
    <div style="position:relative;padding-top:12px">
        <div class="step-block <?= $typeClass ?>">
            <div class="step-num"><?= $i+1 ?></div>
            <div class="step-label"><?= strtoupper($step['step_type']) ?></div>
            <div class="step-desc"><?= e($desc) ?></div>
            <?php if ($sub): ?><div class="step-sub"><?= e($sub) ?></div><?php endif; ?>
            <form method="post" style="position:absolute;top:10px;right:10px" onsubmit="return confirm('Remove this step?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_step">
                <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
                <input type="hidden" name="workflow_id" value="<?= $editWorkflow['id'] ?>">
                <button type="submit" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:1rem;padding:0">✕</button>
            </form>
        </div>
    </div>
    <?php if ($i < count($editSteps)-1): ?>
    <div style="display:flex;justify-content:center;margin:2px 0"><div style="width:2px;height:16px;background:#d1d5db"></div></div>
    <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add step form -->
<div class="section-card">
    <h3>Add Step</h3>
    <div class="step-type-tabs" id="stepTypeTabs">
        <button type="button" class="sttab active" onclick="switchStepType('condition',this)">IF Condition</button>
        <button type="button" class="sttab active" onclick="switchStepType('action',this)" style="background:var(--pauto);color:#fff;border-color:var(--pauto)">THEN Action</button>
        <button type="button" class="sttab" onclick="switchStepType('wait',this)">WAIT</button>
    </div>

    <form method="post" id="addStepForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_step">
        <input type="hidden" name="workflow_id" value="<?= $editWorkflow['id'] ?>">
        <input type="hidden" name="step_type" id="stepTypeField" value="action">

        <!-- Condition fields -->
        <div id="conditionFields" style="display:none">
            <div class="form-row">
                <div>
                    <label class="fl">Field</label>
                    <select name="condition_field" class="fi">
                        <?php foreach ($CONDITIONS as $k => $l): ?>
                        <option value="<?= $k ?>"><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="fl">Operator</label>
                    <select name="condition_op" class="fi">
                        <option value="equals">equals</option>
                        <option value="not_equals">not equals</option>
                        <option value="greater_than">greater than</option>
                        <option value="less_than">less than</option>
                        <option value="contains">contains</option>
                    </select>
                </div>
            </div>
            <label class="fl">Value</label>
            <input type="text" name="condition_value" class="fi" placeholder="e.g. new, converted, 7">
        </div>

        <!-- Action fields -->
        <div id="actionFields">
            <label class="fl">Action Type</label>
            <select name="action_type" class="fi" id="actionTypeSelect" onchange="switchActionConfig(this.value)">
                <?php foreach ($ACTIONS as $k => $a): ?>
                <option value="<?= $k ?>"><?= $a['icon'] ?> <?= e($a['label']) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- create_task -->
            <div id="cfg_create_task">
                <label class="fl">Task Title <span style="font-weight:400;color:#9ca3af">(use {lead_name}, {business})</span></label>
                <input type="text" name="config[task_title]" class="fi" value="Follow up with {lead_name}">
                <div class="form-row">
                    <div>
                        <label class="fl">Category</label>
                        <select name="config[task_category]" class="fi">
                            <?php foreach (['leads','reviews','marketing','content','social_media','customer_followup','campaign','other'] as $tc): ?>
                            <option value="<?= $tc ?>"><?= ucwords(str_replace('_',' ',$tc)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="fl">Priority</label>
                        <select name="config[task_priority]" class="fi">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>
                <label class="fl">Due In (days)</label>
                <input type="number" name="config[due_days]" class="fi" value="1" min="0" max="90">
            </div>

            <!-- send_notification -->
            <div id="cfg_send_notification" style="display:none">
                <label class="fl">Notification Message <span style="font-weight:400;color:#9ca3af">(use {lead_name}, {business}, {date})</span></label>
                <textarea name="config[message]" class="fi" rows="2" placeholder="e.g. New lead received from {business}: {lead_name}"></textarea>
            </div>

            <!-- log_lead_activity -->
            <div id="cfg_log_lead_activity" style="display:none">
                <label class="fl">Activity Type</label>
                <select name="config[activity_type]" class="fi">
                    <?php foreach (['follow_up','contacted','email_sent','message_sent','phone_call','note'] as $at): ?>
                    <option value="<?= $at ?>"><?= ucwords(str_replace('_',' ',$at)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="fl">Notes (optional)</label>
                <input type="text" name="config[notes]" class="fi" placeholder="Auto-logged note…">
            </div>

            <!-- update_lead_status -->
            <div id="cfg_update_lead_status" style="display:none">
                <label class="fl">Set Lead Status To</label>
                <select name="config[new_status]" class="fi">
                    <?php foreach (['contacted','follow_up','qualified','converted','lost','closed'] as $ls): ?>
                    <option value="<?= $ls ?>"><?= ucfirst($ls) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- flag_for_review -->
            <div id="cfg_flag_for_review" style="display:none">
                <label class="fl">Reason</label>
                <input type="text" name="config[reason]" class="fi" placeholder="e.g. Converted lead — check commission eligibility">
            </div>

            <!-- create_followup -->
            <div id="cfg_create_followup" style="display:none">
                <label class="fl">Follow-up Title</label>
                <input type="text" name="config[followup_title]" class="fi" value="Follow up with customer">
                <label class="fl">Due In (days)</label>
                <input type="number" name="config[due_days]" class="fi" value="3" min="1" max="90">
            </div>

            <!-- send_template -->
            <div id="cfg_send_template" style="display:none">
                <label class="fl">Template</label>
                <select name="config[template_id]" class="fi">
                    <?php if ($editTemplates): ?>
                    <?php foreach ($editTemplates as $tmpl): ?>
                    <option value="<?= $tmpl['id'] ?>"><?= e($tmpl['name']) ?> (<?= $tmpl['channel'] ?>)</option>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <option value="0">— No active templates found —</option>
                    <?php endif; ?>
                </select>
                <?php if (!$editTemplates): ?>
                <p style="font-size:.82rem;color:#9ca3af">Create templates in <a href="<?= SITE_URL ?>/partner/templates" style="color:var(--pauto)">Message Templates</a> first.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Wait fields -->
        <div id="waitFields" style="display:none">
            <label class="fl">Wait Duration (minutes)</label>
            <input type="number" name="config[wait_minutes]" class="fi" value="60" min="1">
            <p style="font-size:.82rem;color:#9ca3af;margin-top:-8px">Delays action steps that follow. Minimum 1 minute.</p>
        </div>

        <button type="submit" class="btn-xs purple" style="padding:8px 20px;font-size:.9rem">+ Add Step</button>
    </form>
</div>

<script>
function switchStepType(type, btn) {
    document.querySelectorAll('.sttab').forEach(b => { b.classList.remove('active'); b.style.background='#fff'; b.style.color='#374151'; b.style.borderColor='#d1d5db'; });
    btn.classList.add('active');
    btn.style.background = type==='action'?'var(--pauto)':type==='condition'?'#2563eb':'#ca8a04';
    btn.style.color='#fff'; btn.style.borderColor=btn.style.background;
    document.getElementById('stepTypeField').value = type;
    document.getElementById('conditionFields').style.display = type==='condition'?'block':'none';
    document.getElementById('actionFields').style.display    = type==='action'?'block':'none';
    document.getElementById('waitFields').style.display      = type==='wait'?'block':'none';
}
function switchActionConfig(type) {
    const cfgs = ['create_task','send_notification','log_lead_activity','update_lead_status','flag_for_review','create_followup','send_template'];
    cfgs.forEach(c => document.getElementById('cfg_'+c).style.display = 'none');
    const el = document.getElementById('cfg_'+type);
    if (el) el.style.display = 'block';
}
// Init
switchStepType('action', document.querySelectorAll('.sttab')[1]);
</script>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════ -->
<!-- LIST VIEW                                               -->
<!-- ══════════════════════════════════════════════════════ -->
<div class="auto-header">
    <h1>🤖 Automation Builder</h1>
    <p>Create WHEN→IF→THEN workflows that run automatically across your portfolio.</p>
</div>

<!-- Pending approvals -->
<?php if ($pendingRuns): ?>
<div class="section-card" style="border-color:#fde68a;background:#fffbeb">
    <h3 style="color:#854d0e">⏳ Pending Approval (<?= count($pendingRuns) ?>)</h3>
    <?php foreach ($pendingRuns as $run): ?>
    <div class="pending-run">
        <div class="run-info">
            <strong><?= e($run['workflow_name']) ?></strong>
            <div style="font-size:.82rem;color:#6b7280"><?= e($run['biz_name']) ?> · Triggered <?= date('d M, H:i', strtotime($run['created_at'])) ?></div>
        </div>
        <div class="run-actions">
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="approve_run">
                <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
                <button type="submit" class="btn-xs green">✓ Approve</button>
            </form>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="dismiss_run">
                <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
                <button type="submit" class="btn-xs">✗ Dismiss</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Existing workflows -->
<?php if ($workflows): ?>
<div class="section-card">
    <h3>Your Workflows (<?= count($workflows) ?>)</h3>
    <div class="wf-grid">
    <?php foreach ($workflows as $wf):
        $trig = $TRIGGERS[$wf['trigger_type']] ?? ['label'=>$wf['trigger_type'],'icon'=>'⚡'];
        $statusClass = $wf['status'];
        $badgeClass  = 'badge-'.$wf['status'];
    ?>
    <div class="wf-card <?= $statusClass ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px">
            <div class="wf-title"><?= e($wf['name']) ?></div>
            <span class="wf-badge <?= $badgeClass ?>"><?= ucfirst($wf['status']) ?></span>
        </div>
        <div class="wf-meta">
            <?= $trig['icon'] ?> <?= e($trig['label']) ?> · <?= $wf['biz_name'] ? e($wf['biz_name']) : 'All portfolio' ?>
        </div>
        <div class="step-count"><?= $wf['step_count'] ?> step<?= $wf['step_count']!==1?'s':'' ?> · Runs: <?= $wf['run_count'] ?></div>
        <div class="wf-actions">
            <a href="<?= SITE_URL ?>/partner/automation?view=edit&wid=<?= $wf['id'] ?>" class="btn-xs purple">✏ Edit</a>
            <?php if ($wf['status'] !== 'active'): ?>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="workflow_id" value="<?= $wf['id'] ?>">
                <input type="hidden" name="status" value="active">
                <button type="submit" class="btn-xs green">▶ Activate</button>
            </form>
            <?php else: ?>
            <form method="post" style="display:inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="workflow_id" value="<?= $wf['id'] ?>">
                <input type="hidden" name="status" value="paused">
                <button type="submit" class="btn-xs amber">⏸ Pause</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Starter templates -->
<div class="section-card">
    <h3>🚀 Quick-Start Templates</h3>
    <p style="font-size:.87rem;color:#6b7280;margin:0 0 16px">Install a pre-built workflow and customise the steps.</p>
    <?php $starters = starterWorkflows(); ?>
    <div class="starters-grid">
    <?php foreach ($starters as $i => $s): ?>
    <div class="starter-card">
        <div class="starter-title"><?= e($s['name']) ?></div>
        <div class="starter-trigger"><?= $TRIGGERS[$s['trigger_type']]['icon']??'⚡' ?> <?= e($TRIGGERS[$s['trigger_type']]['label'] ?? $s['trigger_type']) ?></div>
        <div class="starter-desc"><?= e($s['description']) ?></div>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="install_starter">
            <input type="hidden" name="starter_index" value="<?= $i ?>">
            <div style="margin-bottom:8px">
                <select name="listing_id" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-size:.82rem">
                    <option value="0">— All portfolio —</option>
                    <?php foreach ($listings as $l): ?>
                    <option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-xs purple" style="width:100%;padding:7px">Install Workflow</button>
        </form>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- Create from scratch -->
<div class="section-card">
    <h3>+ Create Custom Workflow</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create_workflow">
        <div class="form-row">
            <div>
                <label class="fl">Workflow Name</label>
                <input type="text" name="name" class="fi" placeholder="e.g. VIP Lead Fast-Track" required>
            </div>
            <div>
                <label class="fl">Apply To</label>
                <select name="listing_id" class="fi">
                    <option value="0">— All portfolio —</option>
                    <?php foreach ($listings as $l): ?>
                    <option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div>
                <label class="fl">Trigger (WHEN)</label>
                <select name="trigger_type" class="fi">
                    <?php
                    $grouped = [];
                    foreach ($TRIGGERS as $k => $t) $grouped[$t['group']][$k] = $t;
                    foreach ($grouped as $grp => $trigs): ?>
                    <optgroup label="<?= e($grp) ?>">
                        <?php foreach ($trigs as $k => $t): ?>
                        <option value="<?= $k ?>"><?= $t['icon'] ?> <?= e($t['label']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="fl">Execution Mode</label>
                <select name="execution_mode" class="fi">
                    <option value="requires_approval">Requires my approval before running</option>
                    <option value="automatic">Run automatically</option>
                    <option value="manual">Manual only</option>
                </select>
            </div>
        </div>
        <label class="fl">Description (optional)</label>
        <input type="text" name="description" class="fi" placeholder="What does this workflow do?">
        <button type="submit" class="btn-xs purple" style="padding:9px 22px;font-size:.9rem">Create Workflow →</button>
    </form>
</div>

<?php if (empty($workflows)): ?>
<div class="empty-state" style="display:none"></div>
<?php endif; ?>

<?php endif; // end list/edit ?>

</div><!-- /container -->

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
