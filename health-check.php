<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/config.php';
$pdo = db();
require_once __DIR__ . '/includes/health-check-questions.php';

$staffUser = currentStaffUser();
$staffId = $staffUser['id'] ?? null;

$ref = trim($_GET['ref'] ?? '');
$existing = null;
$isPublic = false;

if ($ref !== '') {
    $stmt = $pdo->prepare("SELECT * FROM health_assessments WHERE referral_code = ?");
    $stmt->execute([$ref]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        http_response_code(404);
        die('Assessment link not found.');
    }
    if ($existing['status'] === 'completed') {
        header('Location: health-check-results.php?ref=' . urlencode($ref));
        exit;
    }
    $isPublic = ($existing['source'] === 'whatsapp' && !$staffId);
} else {
    // No ref and no staff session => can't start a brand-new agent assessment
    if (!$staffId) {
        http_response_code(403);
        die('Please log in as staff to start a new assessment, or use a valid assessment link.');
    }
}

$questions = get_health_check_questions();
$existingAnswers = $existing && $existing['answers_json'] ? json_decode($existing['answers_json'], true) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>237biz Digital Health Check</title>
<style>
  :root{--brand:#0f8a5f;--brand-dark:#0b6a49;--bg:#f6f8f7;--card:#fff;--text:#1c2b26;--muted:#6b7b75;--border:#e2e8e5;}
  *{box-sizing:border-box;}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);}
  .wrap{max-width:640px;margin:0 auto;padding:16px 16px 100px;}
  header{padding:14px 16px;background:var(--brand);color:#fff;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;}
  header h1{font-size:16px;margin:0;font-weight:600;}
  .lang-toggle{background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 10px;border-radius:6px;font-size:13px;cursor:pointer;}
  .progress-bar{height:4px;background:#e2e8e5;}
  .progress-fill{height:100%;background:var(--brand);transition:width .3s;}
  .step-label{font-size:13px;color:var(--muted);margin:16px 0 4px;}
  .card{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:20px;margin-top:8px;}
  .card h2{font-size:19px;margin:0 0 18px;}
  .q{margin-bottom:22px;}
  .q label.qlabel{display:block;font-weight:600;font-size:15px;margin-bottom:10px;}
  .options{display:flex;flex-direction:column;gap:8px;}
  .opt{display:flex;align-items:center;gap:10px;border:1.5px solid var(--border);border-radius:10px;padding:12px 14px;cursor:pointer;font-size:14.5px;transition:.15s;}
  .opt:active{transform:scale(.99);}
  .opt.selected{border-color:var(--brand);background:#eafaf3;font-weight:600;}
  .opt input{accent-color:var(--brand);width:18px;height:18px;}
  input[type=text],input[type=tel],select{width:100%;padding:12px;border:1.5px solid var(--border);border-radius:10px;font-size:15px;background:#fff;}
  .scale{display:flex;gap:8px;}
  .scale button{flex:1;padding:14px 0;border-radius:10px;border:1.5px solid var(--border);background:#fff;font-size:16px;font-weight:600;cursor:pointer;}
  .scale button.selected{background:var(--brand);color:#fff;border-color:var(--brand);}
  .nav-bar{position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid var(--border);padding:12px 16px;display:flex;gap:10px;justify-content:space-between;}
  .btn{padding:13px 22px;border-radius:10px;border:none;font-size:15px;font-weight:600;cursor:pointer;}
  .btn-primary{background:var(--brand);color:#fff;flex:1;}
  .btn-secondary{background:#eef1f0;color:var(--text);}
  .btn:disabled{opacity:.5;}
  .save-indicator{font-size:12px;color:var(--muted);text-align:center;margin-top:6px;height:14px;}
</style>
</head>
<body>
<header>
  <h1>237biz Digital Health Check</h1>
  <button class="lang-toggle" id="langToggle">FR</button>
</header>
<div class="progress-bar"><div class="progress-fill" id="progressFill" style="width:14%"></div></div>

<div class="wrap">
  <div class="step-label" id="stepLabel">Step 1 of 7</div>
  <div class="card" id="formCard"></div>
  <div class="save-indicator" id="saveIndicator"></div>
</div>

<div class="nav-bar">
  <button class="btn btn-secondary" id="backBtn">Back</button>
  <button class="btn btn-primary" id="nextBtn">Next</button>
</div>

<script>
const QUESTIONS = <?php echo json_encode($questions); ?>;
const STEPS = <?php echo json_encode(HEALTH_CHECK_STEPS); ?>;
const CATEGORIES = <?php echo json_encode(BUSINESS_CATEGORIES); ?>;
const CITIES = <?php echo json_encode(HEALTH_CHECK_CITIES); ?>;
const YEARS = <?php echo json_encode(YEARS_IN_BUSINESS); ?>;
const REF = <?php echo json_encode($ref ?: ''); ?>;
const EXISTING_FIELDS = <?php echo json_encode($existing ?: new stdClass()); ?>;
const EXISTING_ANSWERS = <?php echo json_encode($existingAnswers ?: new stdClass()); ?>;

let lang = localStorage.getItem('237biz_hc_lang') || (navigator.language && navigator.language.toLowerCase().startsWith('fr') ? 'fr' : 'en');
let langChosen = !!localStorage.getItem('237biz_hc_lang'); // has the user explicitly picked before?
let currentStep = 1;
const totalSteps = 7;
let ref = REF;
let fields = {
  business_name: EXISTING_FIELDS.business_name || '',
  business_category: EXISTING_FIELDS.business_category || '',
  city: EXISTING_FIELDS.city || '',
  contact_name: EXISTING_FIELDS.contact_name || '',
  whatsapp_number: EXISTING_FIELDS.whatsapp_number || '',
  business_phone: EXISTING_FIELDS.business_phone || '',
  years_in_business: EXISTING_FIELDS.years_in_business || '',
  preferred_language: EXISTING_FIELDS.preferred_language || lang,
};
let answers = Object.assign({}, EXISTING_ANSWERS);

function setLang(newLang) {
  lang = newLang;
  fields.preferred_language = newLang;
  localStorage.setItem('237biz_hc_lang', newLang);
  document.getElementById('langToggle').textContent = lang === 'en' ? 'FR' : 'EN';
  document.documentElement.setAttribute('lang', lang);
}

const stepQuestionIds = {
  2: ['has_website','google_presence','google_business_profile','has_237biz_listing'],
  3: ['social_facebook','social_tiktok','social_instagram','social_whatsapp_business','posting_frequency','promo_content'],
  4: ['discovery_channels','biggest_challenge','visibility_satisfaction'],
  5: ['whatsapp_contact','response_time','collects_reviews','displays_reviews','location_findability'],
  6: ['invests_marketing','marketing_channels','marketing_rating','marketing_needs'],
};

function t(en, fr) { return lang === 'en' ? en : fr; }

function renderLangChoice() {
  document.getElementById('stepLabel').textContent = '';
  document.getElementById('progressFill').style.width = '0%';
  document.getElementById('formCard').innerHTML = `
    <h2 style="text-align:center;">Choose your language / Choisissez votre langue</h2>
    <div class="options" style="max-width:280px;margin:0 auto;">
      <label class="opt" id="pickEn"><span>🇬🇧 English</span></label>
      <label class="opt" id="pickFr"><span>🇫🇷 Français</span></label>
    </div>`;
  document.getElementById('pickEn').addEventListener('click', () => { setLang('en'); langChosen = true; currentStep = 1; renderStep(); });
  document.getElementById('pickFr').addEventListener('click', () => { setLang('fr'); langChosen = true; currentStep = 1; renderStep(); });
  document.getElementById('backBtn').style.visibility = 'hidden';
  document.getElementById('nextBtn').style.display = 'none';
}

function renderStep() {
  document.getElementById('nextBtn').style.display = 'block';
  document.getElementById('stepLabel').textContent = `Step ${currentStep} of ${totalSteps}`;
  document.getElementById('progressFill').style.width = ((currentStep / totalSteps) * 100) + '%';
  const card = document.getElementById('formCard');
  const stepInfo = STEPS[currentStep];
  let html = `<h2>${lang==='en' ? stepInfo.label_en : stepInfo.label_fr}</h2>`;

  if (currentStep === 1) {
    html += businessInfoHTML();
  } else if (currentStep === 7) {
    html += eventStepHTML();
  } else {
    (stepQuestionIds[currentStep] || []).forEach(qid => {
      html += renderQuestion(qid, QUESTIONS[qid]);
    });
  }
  card.innerHTML = html;
  attachHandlers();
  document.getElementById('backBtn').style.visibility = currentStep === 1 ? 'hidden' : 'visible';
  document.getElementById('nextBtn').textContent = currentStep === totalSteps ? t('See My Results','Voir mes résultats') : t('Next','Suivant');
}

function businessInfoHTML() {
  const catOptions = Object.entries(CATEGORIES).map(([key, meta]) =>
    `<option value="${key}" ${fields.business_category===key?'selected':''}>${lang==='en'?meta[0]:meta[1]}</option>`).join('');
  const cityOptions = Object.entries(CITIES).map(([key, meta]) =>
    `<option value="${key}" ${fields.city===key?'selected':''}>${lang==='en'?meta[0]:meta[1]}</option>`).join('');
  const yearOptions = Object.entries(YEARS).map(([key, meta]) =>
    `<option value="${key}" ${fields.years_in_business===key?'selected':''}>${lang==='en'?meta[0]:meta[1]}</option>`).join('');
  return `
    <div class="q"><label class="qlabel">${t('Business Name','Nom de l\'entreprise')}</label>
      <input type="text" id="f_business_name" value="${escapeAttr(fields.business_name)}"></div>
    <div class="q"><label class="qlabel">${t('Business Category','Catégorie d\'entreprise')}</label>
      <select id="f_business_category"><option value="">--</option>${catOptions}</select></div>
    <div class="q"><label class="qlabel">${t('City','Ville')}</label>
      <select id="f_city"><option value="">--</option>${cityOptions}</select></div>
    <div class="q"><label class="qlabel">${t('Contact Name','Nom du contact')}</label>
      <input type="text" id="f_contact_name" value="${escapeAttr(fields.contact_name)}"></div>
    <div class="q"><label class="qlabel">${t('WhatsApp Number','Numéro WhatsApp')}</label>
      <input type="tel" id="f_whatsapp_number" value="${escapeAttr(fields.whatsapp_number)}"></div>
    <div class="q"><label class="qlabel">${t('Business Phone','Téléphone professionnel')}</label>
      <input type="tel" id="f_business_phone" value="${escapeAttr(fields.business_phone)}"></div>
    <div class="q"><label class="qlabel">${t('Years in Business','Années d\'activité')}</label>
      <select id="f_years_in_business"><option value="">--</option>${yearOptions}</select></div>
  `;
}

function eventStepHTML() {
  const heard = renderSingleChoice('heard_of_237biz', {
    label_en: 'Before today, had you heard of 237biz?', label_fr: 'Avant aujourd\'hui, aviez-vous entendu parler de 237biz ?',
    options: { yes: ['Yes','Oui'], no: ['No','Non'] }
  });
  const interest = renderSingleChoice('listing_interest', {
    label_en: 'Would you be interested in having your business listed on 237biz?',
    label_fr: 'Seriez-vous intéressé à faire lister votre entreprise sur 237biz ?',
    options: { definitely: ['Definitely','Certainement'], probably: ['Probably','Probablement'], maybe: ['Maybe','Peut-être'], not_currently: ['Not currently','Pas pour le moment'] }
  });
  const event = renderSingleChoice('event_interest', {
    label_en: 'Would you attend a local event for business owners?',
    label_fr: 'Assisteriez-vous à un événement local pour propriétaires d\'entreprise ?',
    options: { definitely: ['Definitely','Certainement'], probably: ['Probably','Probablement'], maybe: ['Maybe','Peut-être'], no: ['No','Non'] }
  });
  return heard + interest + event;
}

function renderQuestion(qid, q) {
  if (!q) return '';
  if (q.type === 'single') return renderSingleChoice(qid, q);
  if (q.type === 'multi') return renderMultiChoice(qid, q);
  if (q.type === 'scale') return renderScale(qid, q);
  return '';
}

function renderSingleChoice(qid, q) {
  const label = lang === 'en' ? q.label_en : q.label_fr;
  let opts = '';
  for (const [val, meta] of Object.entries(q.options)) {
    const optLabel = Array.isArray(meta) ? (lang==='en'?meta[0]:meta[1]) : (lang==='en'?meta.en:meta.fr);
    const checked = answers[qid] === val ? 'selected' : '';
    opts += `<label class="opt ${checked}" data-qid="${qid}" data-val="${val}">
      <input type="radio" name="${qid}" value="${val}" ${answers[qid]===val?'checked':''}> ${optLabel}</label>`;
  }
  return `<div class="q"><div class="qlabel">${label}</div><div class="options">${opts}</div></div>`;
}

function renderMultiChoice(qid, q) {
  const label = lang === 'en' ? q.label_en : q.label_fr;
  const current = Array.isArray(answers[qid]) ? answers[qid] : [];
  let opts = '';
  for (const [val, meta] of Object.entries(q.options)) {
    const optLabel = lang==='en'?meta[0]:meta[1];
    const checked = current.includes(val) ? 'selected' : '';
    opts += `<label class="opt ${checked}" data-qid="${qid}" data-val="${val}" data-multi="1">
      <input type="checkbox" value="${val}" ${current.includes(val)?'checked':''}> ${optLabel}</label>`;
  }
  return `<div class="q"><div class="qlabel">${label}</div><div class="options">${opts}</div></div>`;
}

function renderScale(qid, q) {
  const label = lang === 'en' ? q.label_en : q.label_fr;
  const current = answers[qid] || null;
  let btns = '';
  for (let i = 1; i <= 5; i++) {
    btns += `<button type="button" class="${current==i?'selected':''}" data-qid="${qid}" data-val="${i}">${i}</button>`;
  }
  return `<div class="q"><div class="qlabel">${label}</div><div class="scale">${btns}</div></div>`;
}

function escapeAttr(s) { return (s||'').replace(/"/g,'&quot;'); }

function attachHandlers() {
  document.querySelectorAll('.opt[data-multi="1"]').forEach(el => {
    el.addEventListener('click', (e) => {
      if (e.target.tagName !== 'INPUT') e.target.querySelector('input').checked = !e.target.querySelector('input').checked;
      const qid = el.dataset.qid, val = el.dataset.val;
      if (!Array.isArray(answers[qid])) answers[qid] = [];
      const idx = answers[qid].indexOf(val);
      if (idx >= 0) { answers[qid].splice(idx,1); el.classList.remove('selected'); }
      else { answers[qid].push(val); el.classList.add('selected'); }
    });
  });
  document.querySelectorAll('.opt:not([data-multi="1"])').forEach(el => {
    el.addEventListener('click', () => {
      const qid = el.dataset.qid, val = el.dataset.val;
      answers[qid] = val;
      document.querySelectorAll(`.opt[data-qid="${qid}"]`).forEach(o => o.classList.remove('selected'));
      el.classList.add('selected');
    });
  });
  document.querySelectorAll('.scale button').forEach(el => {
    el.addEventListener('click', () => {
      const qid = el.dataset.qid, val = el.dataset.val;
      answers[qid] = val;
      document.querySelectorAll(`.scale button[data-qid="${qid}"]`).forEach(o => o.classList.remove('selected'));
      el.classList.add('selected');
    });
  });
}

function collectFieldsFromStep1() {
  ['business_name','business_category','city','contact_name','whatsapp_number','business_phone','years_in_business'].forEach(f => {
    const el = document.getElementById('f_' + f);
    if (el) fields[f] = el.value;
  });
}

async function saveStep() {
  if (currentStep === 1) collectFieldsFromStep1();
  const indicator = document.getElementById('saveIndicator');
  indicator.textContent = t('Saving…','Enregistrement…');
  const body = new URLSearchParams();
  body.set('action','save_step');
  body.set('ref', ref);
  body.set('step', currentStep);
  body.set('fields', JSON.stringify(fields));
  body.set('answers', JSON.stringify(answers));
  try {
    const res = await fetch('save-health-check.php', { method:'POST', body });
    const data = await res.json();
    if (data.ref) ref = data.ref;
    indicator.textContent = t('Saved','Enregistré');
    setTimeout(()=>indicator.textContent='', 1200);
  } catch(e) {
    indicator.textContent = t('Save failed — check connection','Échec — vérifiez la connexion');
  }
}

async function submitAssessment() {
  const body = new URLSearchParams();
  body.set('action','submit');
  body.set('ref', ref);
  body.set('answers', JSON.stringify(answers));
  const res = await fetch('save-health-check.php', { method:'POST', body });
  const data = await res.json();
  if (data.redirect) window.location.href = data.redirect;
}

document.getElementById('nextBtn').addEventListener('click', async () => {
  await saveStep();
  if (currentStep < totalSteps) {
    currentStep++;
    renderStep();
    window.scrollTo(0,0);
  } else {
    document.getElementById('nextBtn').disabled = true;
    await submitAssessment();
  }
});
document.getElementById('backBtn').addEventListener('click', () => {
  if (currentStep > 1) { currentStep--; renderStep(); window.scrollTo(0,0); }
});
document.getElementById('langToggle').addEventListener('click', () => {
  setLang(lang === 'en' ? 'fr' : 'en');
  renderStep();
});

document.getElementById('langToggle').textContent = lang === 'en' ? 'FR' : 'EN';
document.documentElement.setAttribute('lang', lang);

// Show the explicit language picker only on a brand-new, never-visited-before
// assessment where no language has been chosen yet. Returning to an existing
// ref (agent continuing, or business owner resuming) skips straight to the form.
if (!langChosen && !ref) {
  renderLangChoice();
} else {
  renderStep();
}
</script>
</body>
</html>
