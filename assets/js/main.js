// 237BIZ — assets/js/main.js

// ── MOBILE NAV TOGGLE ──
document.addEventListener('DOMContentLoaded', function () {

  // Close nav when clicking outside
  document.addEventListener('click', function (e) {
    const nav = document.getElementById('navbar');
    if (nav && nav.classList.contains('open') && !nav.contains(e.target)) {
      nav.classList.remove('open');
    }
  });

  // ── SCROLL REVEAL ──
  const reveals = document.querySelectorAll('.reveal');
  if (reveals.length) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(e => { if (e.isIntersecting) e.target.classList.add('visible'); });
    }, { threshold: 0.08 });
    reveals.forEach(el => observer.observe(el));
  }

  // ── FILTER TABS (listings page) ──
  document.querySelectorAll('.filter-tab[data-filter]').forEach(tab => {
    tab.addEventListener('click', function () {
      document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
      this.classList.add('active');
    });
  });

  // ── AUTO-DISMISS FLASH MESSAGES ──
  document.querySelectorAll('.flash').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity 0.5s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    }, 4000);
  });

  // ── CONFIRM DELETES ──
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', function (e) {
      if (!confirm(this.dataset.confirm)) e.preventDefault();
    });
  });

  // ── IMAGE PREVIEW ON UPLOAD ──
  document.querySelectorAll('input[type="file"][accept="image/*"]').forEach(input => {
    input.addEventListener('change', function () {
      const file = this.files[0];
      if (!file) return;
      const reader = new FileReader();
      reader.onload = e => {
        let preview = this.parentElement.querySelector('.img-preview');
        if (!preview) {
          preview = document.createElement('img');
          preview.className = 'img-preview';
          preview.style.cssText = 'width:80px;height:80px;object-fit:cover;border-radius:10px;border:1px solid var(--border);margin-top:0.5rem;display:block;';
          this.parentElement.appendChild(preview);
        }
        preview.src = e.target.result;
      };
      reader.readAsDataURL(file);
    });
  });

});

// ── CLICK TRACKING ────────────────────────────────────────
function trackClick(listingId, type) {
  if (!listingId) return;
  fetch('/track-click.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'listing_id=' + listingId + '&type=' + type
  }).catch(() => {}); // silent fail
}

// Auto-attach to contact links on listing pages
document.querySelectorAll('[data-track-listing]').forEach(el => {
  const lid  = el.dataset.trackListing;
  const type = el.dataset.trackType;
  if (lid && type) {
    el.addEventListener('click', () => trackClick(lid, type));
  }
});

// ── PROMO CODE VALIDATION ─────────────────────────────────
const promoInput = document.getElementById('promo-code');
const promoBtn   = document.getElementById('promo-apply');
const promoMsg   = document.getElementById('promo-message');
const amountEl   = document.getElementById('price-amount');

if (promoInput && promoBtn) {
  promoBtn.addEventListener('click', async function () {
    const code    = promoInput.value.trim().toUpperCase();
    const amount  = parseInt(document.getElementById('hidden-amount')?.value || 0);
    const type    = document.getElementById('promo-applies-to')?.value || 'featured';

    if (!code) return;
    promoBtn.textContent = '...';

    const res  = await fetch('/validate-promo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'code=' + encodeURIComponent(code) + '&amount=' + amount + '&applies_to=' + type
    });
    const data = await res.json();

    promoBtn.textContent = document.documentElement.lang === 'fr' ? 'Appliquer' : 'Apply';

    if (data.valid) {
      promoMsg.style.color = 'var(--green)';
      promoMsg.textContent = '✓ ' + data.message;
      if (amountEl) amountEl.textContent = data.final.toLocaleString() + ' XAF';
      document.getElementById('applied-promo-code').value = code;
      document.getElementById('final-amount').value = data.final;
    } else {
      promoMsg.style.color = '#ff6b6b';
      promoMsg.textContent = '✗ ' + data.error;
    }
    promoMsg.style.display = 'block';
  });
}

// ── SOCIAL SHARING ────────────────────────────────────────

function shareFacebook(url) {
  window.open('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url), '_blank', 'width=600,height=400');
}

function shareTikTok(title, url) {
  // TikTok doesn't support direct URL sharing via web — copy link + open TikTok
  const text = '🇨🇲 ' + title + '\n\n' + url + '\n\n#Cameroon #237Biz #Limbe';
  navigator.clipboard.writeText(text).then(() => {
    // Show a modal with instructions
    const modal = document.getElementById('tiktok-share-modal');
    if (modal) {
      document.getElementById('tiktok-share-text').value = text;
      modal.style.display = 'flex';
    } else {
      alert('Caption copied!\n\nOpen TikTok, create a video, and paste this caption:\n\n' + text);
    }
  }).catch(() => {
    // Fallback for browsers that block clipboard
    prompt('Copy this caption for your TikTok video:', text);
  });
}

function shareInstagram(title, url) {
  // Instagram doesn't support direct web sharing — copy link + open Instagram
  navigator.clipboard.writeText(url).then(() => {
    const modal = document.getElementById('instagram-share-modal');
    if (modal) {
      modal.style.display = 'flex';
    } else {
      alert('Link copied!\n\nOpen Instagram, paste the link in your bio or story.');
    }
  }).catch(() => {
    prompt('Copy this link for Instagram:', url);
  });
}

function copyLink(url) {
  navigator.clipboard.writeText(url).then(() => {
    const btn = document.getElementById('copy-link-btn');
    if (btn) {
      const old = btn.innerHTML;
      btn.innerHTML = '✓ ' + (document.documentElement.lang === 'fr' ? 'Copié !' : 'Copied!');
      setTimeout(() => btn.innerHTML = old, 2000);
    }
  }).catch(() => {
    prompt('Copy this link:', url);
  });
}

// ── EXPIRY COUNTDOWN ──────────────────────────────────────
document.querySelectorAll('[data-expires]').forEach(el => {
  const exp  = new Date(el.dataset.expires);
  const now  = new Date();
  const days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
  if (days <= 7 && days > 0) {
    el.style.color = 'var(--yellow)';
    el.textContent = el.textContent + ' (' + days + ' days left)';
  } else if (days <= 0) {
    el.style.color = '#ff6b6b';
    el.textContent = el.textContent + ' (Expired)';
  }
});
