<?php // includes/footer.php ?>
<footer id="site-footer" style="background:#05160A;border-top:1px solid rgba(255,255,255,0.07);margin-top:0;">

  <!-- Main footer grid -->
  <div class="container" style="padding-top:56px;padding-bottom:40px;">
    <div style="display:grid;grid-template-columns:1.6fr 1fr 1fr 1fr 1fr;gap:40px;flex-wrap:wrap;">

      <!-- Brand column -->
      <div>
        <a href="<?= SITE_URL ?>/" style="text-decoration:none;display:inline-flex;align-items:baseline;gap:1px;margin-bottom:12px;">
          <span style="font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;color:#fcd116;">237</span><span style="font-family:'Fraunces',serif;font-weight:900;font-size:1.5rem;color:#fff;">Biz</span><span style="font-size:0.65rem;color:rgba(255,255,255,0.35);margin-left:1px;">.net</span>
        </a>
        <p style="font-size:13.5px;color:rgba(255,255,255,0.55);line-height:1.65;margin-bottom:16px;max-width:220px;">
          <?= t('Cameroon\'s Business Hub — Discover. Connect. Grow.','Le Hub des Entreprises du Cameroun — Découvrez. Connectez. Grandissez.') ?>
        </p>
        <!-- Language toggle -->
        <div style="display:flex;gap:0;border-radius:8px;overflow:hidden;border:1px solid rgba(255,255,255,0.1);display:inline-flex;margin-bottom:18px;">
          <a href="?lang=en" style="padding:6px 14px;font-size:12px;font-weight:700;text-decoration:none;<?= lang()==='en'?'background:rgba(0,168,120,0.25);color:#00A878;':'color:rgba(255,255,255,0.45);' ?>">EN</a>
          <a href="?lang=fr" style="padding:6px 14px;font-size:12px;font-weight:700;text-decoration:none;<?= lang()==='fr'?'background:rgba(0,168,120,0.25);color:#00A878;':'color:rgba(255,255,255,0.45);' ?>">FR</a>
        </div>
        <div style="font-size:12px;color:rgba(255,255,255,0.3);">
          <?= t('Powered by','Propulsé par') ?>
          <a href="https://maroonhosting.com" target="_blank" rel="noopener" style="color:rgba(255,255,255,0.45);text-decoration:none;margin-left:3px;">Maroon Hosting</a>
        </div>
      </div>

      <!-- Discover -->
      <div>
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#00A878;margin-bottom:14px;">
          🔍 <?= t('Discover','Découvrir') ?>
        </div>
        <?php foreach ([
          [SITE_URL.'/listings',                    t('Browse Businesses','Toutes les Entreprises')],
          [SITE_URL.'/listings?sort=featured',      t('Featured Businesses','Entreprises Vedettes')],
          [SITE_URL.'/listings?verified=1',         t('Verified Businesses','Entreprises Vérifiées')],
          [SITE_URL.'/locations',                   t('Browse by City','Parcourir par Ville')],
          [SITE_URL.'/listings',                    t('Browse by Category','Parcourir par Catégorie')],
          [SITE_URL.'/reviews',                     t('Customer Reviews','Avis Clients')],
        ] as [$url,$label]): ?>
        <a href="<?= $url ?>" style="display:block;font-size:13px;color:rgba(255,255,255,0.55);text-decoration:none;padding:4px 0;transition:color .15s;line-height:1.5;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <!-- Business Hub -->
      <div>
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#fcd116;margin-bottom:14px;">
          💼 <?= t('Business Hub','Business Hub') ?>
        </div>
        <?php foreach ([
          [SITE_URL.'/business-hub',                t('Why 237Biz?','Pourquoi 237Biz ?')],
          [SITE_URL.'/add-listing',                 t('List Your Business','Lister votre Entreprise')],
          [SITE_URL.'/claim-listing',               t('Claim Your Business','Revendiquer votre Entreprise')],
          [SITE_URL.'/business-listing',            t('Featured Listings','Annonces Vedettes')],
          [SITE_URL.'/online-presence',             t('Website & Online Presence','Site Web & Présence')],
          [SITE_URL.'/services',                    t('Business Solutions','Solutions Entreprises')],
          [SITE_URL.'/help',                        t('Help Centre','Centre d\'Aide')],
        ] as [$url,$label]): ?>
        <a href="<?= $url ?>" style="display:block;font-size:13px;color:rgba(255,255,255,0.55);text-decoration:none;padding:4px 0;transition:color .15s;line-height:1.5;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <!-- Partners -->
      <div>
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#8ab4f8;margin-bottom:14px;">
          🤝 <?= t('Partners','Partenaires') ?>
        </div>
        <?php foreach ([
          [SITE_URL.'/partners',                    t('Partner Programme','Programme Partenaires')],
          [SITE_URL.'/partners#agents',             t('Sales Agents','Agents de Vente')],
          [SITE_URL.'/partners#creators',           t('Content Creators','Créateurs de Contenu')],
          [SITE_URL.'/join?path=agent',             t('Become an Agent','Devenir Agent')],
          [SITE_URL.'/join?path=creator',           t('Become a Creator','Devenir Créateur')],
          [SITE_URL.'/agent/dashboard',             t('Agent Login','Connexion Agent')],
          [SITE_URL.'/creator/dashboard',           t('Creator Login','Connexion Créateur')],
        ] as [$url,$label]): ?>
        <a href="<?= $url ?>" style="display:block;font-size:13px;color:rgba(255,255,255,0.55);text-decoration:none;padding:4px 0;transition:color .15s;line-height:1.5;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <!-- Account -->
      <div>
        <div style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,0.5);margin-bottom:14px;">
          👤 <?= t('Account','Compte') ?>
        </div>
        <?php foreach ([
          [SITE_URL.'/join',          t('Join 237Biz','Rejoindre 237Biz')],
          [SITE_URL.'/login',         t('Sign In','Connexion')],
          [SITE_URL.'/dashboard',     t('My Dashboard','Mon Tableau de bord')],
          [SITE_URL.'/analytics',     t('Analytics','Analytiques')],
          [SITE_URL.'/manage-bookings', t('Bookings','Réservations')],
          [SITE_URL.'/add-listing',   t('Add Listing','Ajouter une Annonce')],
        ] as [$url,$label]): ?>
        <a href="<?= $url ?>" style="display:block;font-size:13px;color:rgba(255,255,255,0.55);text-decoration:none;padding:4px 0;transition:color .15s;line-height:1.5;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.55)'"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

    </div>
  </div>

  <!-- City links strip -->
  <div style="border-top:1px solid rgba(255,255,255,0.06);padding:16px 0;">
    <div class="container">
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:12.5px;">
        <span style="color:rgba(255,255,255,0.3);font-weight:600;margin-right:4px;">📍</span>
        <?php
        $cities = [
          'limbe'      => 'Limbe',
          'buea'       => 'Buea',
          'tiko'       => 'Tiko',
          'kumba'      => 'Kumba',
          'douala'     => 'Douala',
          'yaounde'    => 'Yaoundé',
          'bafoussam'  => 'Bafoussam',
          'bamenda'    => 'Bamenda',
          'garoua'     => 'Garoua',
          'maroua'     => 'Maroua',
          'ngaoundere' => 'Ngaoundéré',
          'bertoua'    => 'Bertoua',
        ];
        $cityLinks = [];
        foreach ($cities as $slug => $name) {
            $cityLinks[] = '<a href="' . SITE_URL . '/location/' . $slug . '" style="color:rgba(255,255,255,0.4);text-decoration:none;transition:color .15s;" onmouseover="this.style.color=\'#00A878\'" onmouseout="this.style.color=\'rgba(255,255,255,0.4)\'">' . $name . '</a>';
        }
        echo implode(' <span style="color:rgba(255,255,255,0.15);">·</span> ', $cityLinks);
        ?>
        <span style="color:rgba(255,255,255,0.15);">·</span>
        <a href="<?= SITE_URL ?>/locations" style="color:#00A878;font-weight:700;text-decoration:none;font-size:12px;"><?= t('All Cities →','Toutes les villes →') ?></a>
      </div>
    </div>
  </div>

  <!-- Bottom bar -->
  <div style="border-top:1px solid rgba(255,255,255,0.06);padding:16px 0;">
    <div class="container" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <p style="font-size:12px;color:rgba(255,255,255,0.3);margin:0;">
        © <?= date('Y') ?> 237Biz.net — <?= t('Cameroon\'s Business Hub','Le Hub des Entreprises du Cameroun') ?>
      </p>
      <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <?php foreach ([
          [SITE_URL.'/privacy-policy',  t('Privacy Policy','Politique de Confidentialité')],
          [SITE_URL.'/terms',           t('Terms of Use','Conditions d\'Utilisation')],
          [SITE_URL.'/help',            t('Help','Aide')],
          [SITE_URL.'/contact',         t('Contact','Contact')],
        ] as [$url,$label]): ?>
        <a href="<?= $url ?>" style="font-size:12px;color:rgba(255,255,255,0.3);text-decoration:none;transition:color .15s;" onmouseover="this.style.color='rgba(255,255,255,0.6)'" onmouseout="this.style.color='rgba(255,255,255,0.3)'"><?= $label ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</footer>

<script src="<?= SITE_URL ?>/assets/js/main.js"></script>
<?= $extraScripts ?? '' ?>
</body>
</html>
