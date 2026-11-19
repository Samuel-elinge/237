-- =============================================================================
-- Migration 011: Phase 2 Stage 1 — Personalised Recommendations Engine
-- Creates: bhc_recommendation_rules (per-answer triggers)
--          bhc_reports (report generation log)
-- Seeds:   bhc_recommendations with full EN+FR content for all 7 categories × 4 bands
-- =============================================================================

-- ── bhc_recommendation_rules ─────────────────────────────────────────────────
-- Allows recommendations to be triggered by specific question answers,
-- in addition to the existing category+band triggers.
CREATE TABLE IF NOT EXISTS bhc_recommendation_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recommendation_id INT UNSIGNED NOT NULL COMMENT 'FK → bhc_recommendations.id',
    trigger_type    ENUM('category_band','question_answer','category_pct') NOT NULL DEFAULT 'category_band',
    -- for trigger_type = question_answer:
    question_id     INT UNSIGNED NULL COMMENT 'FK → bhc_questions.id',
    option_key      VARCHAR(20)  NULL COMMENT 'e.g. not_at_all, rarely — triggers when this answer given',
    -- for trigger_type = category_pct:
    category_slug   VARCHAR(60)  NULL COMMENT 'e.g. online_visibility',
    pct_max         TINYINT UNSIGNED NULL COMMENT 'fires when category_pct <= this value (0-100)',
    pct_min         TINYINT UNSIGNED NULL DEFAULT 0,
    -- metadata
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_question  (question_id, option_key),
    INDEX idx_cat_pct   (category_slug, pct_max),
    INDEX idx_rec       (recommendation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── bhc_reports ──────────────────────────────────────────────────────────────
-- Tracks when personalised reports are generated/viewed.
CREATE TABLE IF NOT EXISTS bhc_reports (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id   INT UNSIGNED NOT NULL,
    generated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generation_type ENUM('view','pdf','email') NOT NULL DEFAULT 'view',
    lang            VARCHAR(5)   NOT NULL DEFAULT 'en',
    total_score     DECIMAL(5,2) NULL,
    score_band      VARCHAR(30)  NULL,
    recs_shown      TINYINT UNSIGNED NULL COMMENT 'number of recommendations displayed',
    INDEX idx_assessment (assessment_id),
    INDEX idx_generated  (generated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- SEED DATA: bhc_recommendations
-- 7 categories × 4 bands = 28 rows, each with EN + FR content
-- Categories: online_visibility, customer_acquisition, customer_trust,
--             digital_presence, customer_management, marketing_growth,
--             business_operations
-- Bands: needs_attention (0-39), developing (40-59),
--        good_progress (60-79), strong_foundations (80-100)
-- =============================================================================

-- We use INSERT IGNORE so re-running this migration is safe.
-- category_id values match the INSERT order in migration 010:
--   1=online_visibility, 2=customer_acquisition, 3=customer_trust,
--   4=digital_presence, 5=customer_management, 6=marketing_growth,
--   7=business_operations

-- ── ONLINE VISIBILITY ─────────────────────────────────────────────────────────

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'needs_attention', 1,
    'Build Your Online Foundation',
    'Your business is largely invisible online. Start by claiming your Google Business Profile — it''s free and immediately puts you on Google Maps and Search. Add your business name, address, phone, hours, and a few photos. This single step can generate customer enquiries within days.',
    'Construisez votre présence en ligne',
    'Votre entreprise est peu visible en ligne. Commencez par revendiquer votre profil Google Business — c''est gratuit et vous place immédiatement sur Google Maps et la Recherche. Ajoutez le nom, l''adresse, le numéro de téléphone, les horaires et quelques photos. Cette seule étape peut générer des demandes clients en quelques jours.'
FROM bhc_categories c WHERE c.slug = 'online_visibility';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'developing', 2,
    'Strengthen Your Local Search Presence',
    'You have some online presence but it needs consistency. Ensure your business name, address, and phone number (NAP) are identical across Google, Facebook, and any directories. Inconsistency confuses search engines and costs you rankings. Aim to respond to all Google reviews — positive and negative — within 48 hours.',
    'Renforcez votre présence dans les recherches locales',
    'Vous avez une certaine présence en ligne mais elle manque de cohérence. Assurez-vous que votre nom, adresse et numéro de téléphone (NAP) sont identiques sur Google, Facebook et les annuaires. L''incohérence perturbe les moteurs de recherche. Répondez à tous les avis Google — positifs et négatifs — dans les 48 heures.'
FROM bhc_categories c WHERE c.slug = 'online_visibility';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'good_progress', 3,
    'Expand to Video and Rich Content',
    'Your online visibility is solid. The next level is rich media: short videos (60–90 seconds) showing your products, services, or behind-the-scenes work perform exceptionally well in Cameroon on WhatsApp Status and YouTube. Post one per week consistently for 90 days to see compounding growth.',
    'Développez-vous avec la vidéo et le contenu enrichi',
    'Votre visibilité en ligne est solide. L''étape suivante est le contenu riche : les courtes vidéos (60–90 secondes) montrant vos produits, services ou les coulisses de votre activité fonctionnent très bien au Cameroun sur WhatsApp Status et YouTube. Publiez-en une par semaine pendant 90 jours pour voir une croissance exponentielle.'
FROM bhc_categories c WHERE c.slug = 'online_visibility';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'strong_foundations', 4,
    'Lead with Thought Leadership Content',
    'You have excellent online visibility. Now build authority: publish monthly articles or guides on topics your customers care about. Use your Google Business profile to post weekly updates. Consider a simple SEO strategy targeting 3–5 keywords specific to your city and industry.',
    'Devenez leader d''opinion dans votre secteur',
    'Vous avez une excellente visibilité en ligne. Construisez maintenant votre autorité : publiez des articles ou guides mensuels sur des sujets qui intéressent vos clients. Utilisez votre profil Google Business pour des mises à jour hebdomadaires. Envisagez une stratégie SEO simple ciblant 3 à 5 mots-clés spécifiques à votre ville et secteur.'
FROM bhc_categories c WHERE c.slug = 'online_visibility';

-- ── CUSTOMER ACQUISITION ──────────────────────────────────────────────────────

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'needs_attention', 1,
    'Create a Simple Referral System',
    'Most of your new customers likely come from word of mouth — but you have no system to amplify it. Start this week: tell your 5 best customers "If you refer someone who becomes a client, I''ll give you [a discount / a gift / a free service]." A simple referral programme can double your new customers within 60 days.',
    'Créez un système de parrainage simple',
    'La plupart de vos nouveaux clients viennent probablement du bouche-à-oreille — mais vous n''avez pas de système pour l''amplifier. Cette semaine : dites à vos 5 meilleurs clients "Si vous recommandez quelqu''un qui devient client, je vous offre [une réduction / un cadeau / un service gratuit]." Un programme de parrainage simple peut doubler vos nouveaux clients en 60 jours.'
FROM bhc_categories c WHERE c.slug = 'customer_acquisition';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'developing', 2,
    'Build a Lead Capture Process',
    'You are generating some leads but losing many because there is no follow-up system. Create a simple contact form or WhatsApp business number for enquiries. Respond to every new enquiry within 2 hours — businesses that do this convert leads at 7× the rate of those that wait a day.',
    'Construisez un processus de capture de prospects',
    'Vous générez des prospects mais en perdez beaucoup faute de suivi. Créez un formulaire de contact simple ou un numéro WhatsApp Business. Répondez à chaque nouvelle demande dans les 2 heures — les entreprises qui le font convertissent les prospects 7 fois plus que celles qui attendent une journée.'
FROM bhc_categories c WHERE c.slug = 'customer_acquisition';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'good_progress', 3,
    'Diversify Your Acquisition Channels',
    'You are acquiring customers but rely on too few channels. Identify your top 2 sources and invest there, while testing one new channel this quarter — Facebook Ads with a 50,000 XAF/month budget, a partnership with a complementary business, or a WhatsApp broadcast list of 200+ contacts.',
    'Diversifiez vos canaux d''acquisition',
    'Vous acquérez des clients mais dépendez de trop peu de canaux. Identifiez vos 2 meilleures sources et investissez-y, tout en testant un nouveau canal ce trimestre — Facebook Ads avec un budget de 50 000 XAF/mois, un partenariat avec une entreprise complémentaire, ou une liste de diffusion WhatsApp de 200+ contacts.'
FROM bhc_categories c WHERE c.slug = 'customer_acquisition';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'strong_foundations', 4,
    'Implement a Formal Sales Funnel',
    'Your customer acquisition is strong. Now systematise it: document your sales funnel from awareness to close, track your conversion rate at each stage, and identify where prospects drop off. Even a 10% improvement at one stage can significantly increase revenue without more marketing spend.',
    'Mettez en place un entonnoir de vente formel',
    'Votre acquisition de clients est solide. Systématisez maintenant : documentez votre entonnoir de vente de la notoriété à la clôture, suivez votre taux de conversion à chaque étape et identifiez où les prospects abandonnent. Une amélioration de 10% à une seule étape peut augmenter significativement le chiffre d''affaires sans dépenses marketing supplémentaires.'
FROM bhc_categories c WHERE c.slug = 'customer_acquisition';

-- ── CUSTOMER TRUST ────────────────────────────────────────────────────────────

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'needs_attention', 1,
    'Collect Your First 10 Google Reviews',
    'Trust signals are essential in Cameroon where customers heavily research before buying. This week, personally ask your 10 happiest customers to leave a Google review — send them the direct link. 10 genuine reviews will immediately separate you from competitors who have none.',
    'Obtenez vos 10 premiers avis Google',
    'Les signaux de confiance sont essentiels au Cameroun où les clients font beaucoup de recherches avant d''acheter. Cette semaine, demandez personnellement à vos 10 clients les plus satisfaits de laisser un avis Google — envoyez-leur le lien direct. 10 avis authentiques vous distingueront immédiatement des concurrents qui n''en ont aucun.'
FROM bhc_categories c WHERE c.slug = 'customer_trust';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'developing', 2,
    'Showcase Social Proof Consistently',
    'You have some reviews but are not maximising them. Post customer testimonials (with permission) on your WhatsApp Status and Facebook page at least twice a week. Share before-and-after results, success stories, or photos of happy customers. Social proof shared regularly builds compounding trust.',
    'Montrez régulièrement vos preuves sociales',
    'Vous avez quelques avis mais ne les exploitez pas pleinement. Publiez des témoignages clients (avec permission) sur votre WhatsApp Status et Facebook au moins deux fois par semaine. Partagez des résultats avant/après, des histoires de réussite, ou des photos de clients satisfaits. La preuve sociale partagée régulièrement crée une confiance croissante.'
FROM bhc_categories c WHERE c.slug = 'customer_trust';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'good_progress', 3,
    'Create a Customer Success Case Study',
    'Your trust signals are good. Take it further by creating one detailed case study: a real customer, their problem, your solution, and the measurable result. This can be a 500-word story on your website or a 2-minute video. Case studies convert sceptical prospects better than any advertisement.',
    'Créez une étude de cas client détaillée',
    'Vos signaux de confiance sont bons. Allez plus loin en créant une étude de cas détaillée : un vrai client, son problème, votre solution et le résultat mesurable. Cela peut être une histoire de 500 mots sur votre site ou une vidéo de 2 minutes. Les études de cas convertissent les prospects sceptiques mieux que toute publicité.'
FROM bhc_categories c WHERE c.slug = 'customer_trust';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'strong_foundations', 4,
    'Build a Formal Guarantee Policy',
    'You have excellent trust. Formalise it with a written satisfaction guarantee — "If you are not happy, we will make it right." Display it prominently. Counterintuitively, strong guarantees reduce refund requests because customers feel safe and are more loyal. Document your quality processes for further credibility.',
    'Établissez une politique de garantie formelle',
    'Votre crédibilité est excellente. Formalisez-la avec une garantie de satisfaction écrite — "Si vous n''êtes pas satisfait, nous arrangerons les choses." Affichez-la en évidence. Paradoxalement, les garanties solides réduisent les demandes de remboursement car les clients se sentent en sécurité et sont plus fidèles.'
FROM bhc_categories c WHERE c.slug = 'customer_trust';

-- ── DIGITAL PRESENCE ──────────────────────────────────────────────────────────

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'needs_attention', 1,
    'Create a Professional WhatsApp Business Profile',
    'A professional digital presence starts with WhatsApp Business — the primary communication tool in Cameroon. Set up a Business account with your logo, description, address, and catalogue of services/products. Add an auto-reply greeting so every enquiry gets an instant professional response, even at night.',
    'Créez un profil WhatsApp Business professionnel',
    'Une présence numérique professionnelle commence par WhatsApp Business — le principal outil de communication au Cameroun. Créez un compte Business avec votre logo, votre description, votre adresse et un catalogue de services/produits. Ajoutez un message d''accueil automatique pour que chaque demande reçoive une réponse professionnelle instantanée, même la nuit.'
FROM bhc_categories c WHERE c.slug = 'digital_presence';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'developing', 2,
    'Launch a Simple One-Page Website',
    'You have digital tools but no central hub. A simple one-page website — with your services, prices, contact information, and 3–5 customer reviews — immediately increases your credibility and gives you an address to share everywhere. 237biz can build this for you professionally.',
    'Lancez un site web simple d''une page',
    'Vous disposez d''outils numériques mais n''avez pas de hub central. Un site web simple d''une page — avec vos services, prix, coordonnées et 3 à 5 avis clients — augmente immédiatement votre crédibilité et vous donne une adresse à partager partout. 237biz peut vous le construire professionnellement.'
FROM bhc_categories c WHERE c.slug = 'digital_presence';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'good_progress', 3,
    'Optimise Your Website for Mobile and Speed',
    'Your digital presence is solid but performance matters. Test your website speed at PageSpeed Insights — if it scores under 70 on mobile, customers are leaving before it loads. Compress images, enable caching, and ensure every page works perfectly on a 4G smartphone. In Cameroon, most customers browse on mobile.',
    'Optimisez votre site pour le mobile et la vitesse',
    'Votre présence numérique est solide mais les performances comptent. Testez la vitesse de votre site sur PageSpeed Insights — s''il obtient moins de 70 sur mobile, les clients partent avant qu''il se charge. Compressez les images, activez le cache et assurez-vous que chaque page fonctionne parfaitement sur smartphone 4G. Au Cameroun, la plupart des clients naviguent sur mobile.'
FROM bhc_categories c WHERE c.slug = 'digital_presence';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'strong_foundations', 4,
    'Integrate a Booking or E-commerce System',
    'Your digital presence is excellent. The next level is transaction capability: add an online booking system, payment link (Orange Money / MTN MoMo), or simple product catalogue with order form. Businesses that allow customers to initiate transactions online see 30–50% higher conversion from digital traffic.',
    'Intégrez un système de réservation ou de e-commerce',
    'Votre présence numérique est excellente. L''étape suivante est la capacité transactionnelle : ajoutez un système de réservation en ligne, un lien de paiement (Orange Money / MTN MoMo) ou un catalogue produits simple avec formulaire de commande. Les entreprises permettant aux clients d''initier des transactions en ligne voient 30 à 50% de conversions supplémentaires.'
FROM bhc_categories c WHERE c.slug = 'digital_presence';

-- ── CUSTOMER MANAGEMENT ───────────────────────────────────────────────────────

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'needs_attention', 1,
    'Start a Basic Customer Database',
    'You are likely losing repeat business because you have no way to stay in touch with past customers. This week, create a simple spreadsheet: customer name, phone, service purchased, date. With 50 contacts you can send a WhatsApp broadcast monthly — this alone can increase repeat revenue by 20–40%.',
    'Commencez une base de données clients basique',
    'Vous perdez probablement des affaires récurrentes car vous n''avez aucun moyen de rester en contact avec vos anciens clients. Cette semaine, créez un tableau simple : nom du client, téléphone, service acheté, date. Avec 50 contacts, vous pouvez envoyer une diffusion WhatsApp mensuelle — cela seul peut augmenter les revenus récurrents de 20 à 40%.'
FROM bhc_categories c WHERE c.slug = 'customer_management';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'developing', 2,
    'Implement a Follow-Up Sequence',
    'You track customers but do not maximise their lifetime value. Create a simple follow-up sequence: 3 days after purchase (thank you + ask for review), 30 days (check satisfaction), 90 days (re-engagement offer). This can be done by WhatsApp with a template message. Automated follow-up consistently doubles repeat purchase rates.',
    'Mettez en place une séquence de suivi',
    'Vous suivez les clients mais ne maximisez pas leur valeur à vie. Créez une séquence de suivi simple : 3 jours après l''achat (remerciement + demande d''avis), 30 jours (vérification de la satisfaction), 90 jours (offre de réengagement). Cela peut se faire par WhatsApp avec un message modèle. Le suivi automatisé double régulièrement les taux d''achats répétés.'
FROM bhc_categories c WHERE c.slug = 'customer_management';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'good_progress', 3,
    'Segment Customers for Targeted Offers',
    'Your customer management is good. Now segment: separate your high-value customers (top 20% by spend) and treat them differently — exclusive previews, loyalty discounts, personal check-ins. The top 20% of customers typically generate 60–80% of revenue. A VIP programme costs little and retains your most valuable clients.',
    'Segmentez vos clients pour des offres ciblées',
    'Votre gestion clients est bonne. Maintenant segmentez : séparez vos clients à forte valeur (20% supérieur par dépenses) et traitez-les différemment — aperçus exclusifs, remises de fidélité, contacts personnels. Les 20% de clients les plus importants génèrent typiquement 60 à 80% du chiffre d''affaires. Un programme VIP coûte peu et fidélise vos clients les plus précieux.'
FROM bhc_categories c WHERE c.slug = 'customer_management';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'strong_foundations', 4,
    'Implement a Formal Loyalty Programme',
    'Your customer management is excellent. Formalise loyalty: a points system (buy 5 services, get 1 free), a tiered membership (Bronze / Silver / Gold), or an exclusive WhatsApp group for top customers. Loyalty programmes increase purchase frequency by an average of 20% and significantly reduce churn.',
    'Mettez en place un programme de fidélité formel',
    'Votre gestion clients est excellente. Formalisez la fidélité : un système de points (achetez 5 services, obtenez 1 gratuit), un membership à niveaux (Bronze / Argent / Or), ou un groupe WhatsApp exclusif pour les meilleurs clients. Les programmes de fidélité augmentent la fréquence d''achat en moyenne de 20% et réduisent significativement le taux d''attrition.'
FROM bhc_categories c WHERE c.slug = 'customer_management';

-- ── MARKETING & GROWTH ────────────────────────────────────────────────────────

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'needs_attention', 1,
    'Define Your Core Marketing Message',
    'Before spending on marketing, define your unique value: complete this sentence — "We help [specific customer type] to [achieve specific result] unlike [competitors] because [your differentiator]." Without this, every marketing effort is unfocused. Write it down, put it on your WhatsApp Bio, and use it in every sales conversation.',
    'Définissez votre message marketing central',
    'Avant de dépenser en marketing, définissez votre valeur unique : complétez cette phrase — "Nous aidons [type de client spécifique] à [atteindre un résultat spécifique] contrairement à [concurrents] parce que [votre différenciateur]." Sans cela, chaque effort marketing est flou. Écrivez-le, mettez-le sur votre Bio WhatsApp et utilisez-le dans chaque conversation de vente.'
FROM bhc_categories c WHERE c.slug = 'marketing_growth';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'developing', 2,
    'Establish a Consistent Content Calendar',
    'You market occasionally but inconsistency kills momentum. Create a simple 30-day content calendar: 3 posts per week (Monday: tip/education, Wednesday: product/service showcase, Friday: customer story or behind-the-scenes). Consistency over 90 days builds an audience that knows, likes, and trusts you.',
    'Établissez un calendrier de contenu régulier',
    'Vous faites du marketing occasionnellement mais l''incohérence tue l''élan. Créez un calendrier de contenu simple sur 30 jours : 3 publications par semaine (lundi : conseil/éducation, mercredi : vitrine produit/service, vendredi : histoire client ou coulisses). La régularité sur 90 jours crée une audience qui vous connaît, vous apprécie et vous fait confiance.'
FROM bhc_categories c WHERE c.slug = 'marketing_growth';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'good_progress', 3,
    'Launch Targeted Paid Advertising',
    'Your organic marketing is working. Amplify it with paid ads: Facebook/Instagram Ads with a 100,000–200,000 XAF/month budget, targeted to your city + relevant interests. Test two ad formats (video vs. image), run each for 2 weeks, and double down on the winner. Paid ads paired with strong organic content multiply results.',
    'Lancez de la publicité payante ciblée',
    'Votre marketing organique fonctionne. Amplifiez-le avec des publicités payantes : Facebook/Instagram Ads avec un budget de 100 000 à 200 000 XAF/mois, ciblé sur votre ville et les intérêts pertinents. Testez deux formats (vidéo vs. image), exécutez chacun pendant 2 semaines et doublez la mise sur le gagnant. Les publicités payantes combinées à un contenu organique fort multiplient les résultats.'
FROM bhc_categories c WHERE c.slug = 'marketing_growth';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'strong_foundations', 4,
    'Build a Marketing Analytics Dashboard',
    'Your marketing is strong. Now measure everything: track where each new customer comes from, your cost per acquisition per channel, and your monthly revenue trend. Even a simple spreadsheet dashboard updated monthly reveals which 20% of activities drive 80% of results — letting you cut waste and invest where it pays.',
    'Créez un tableau de bord d''analytique marketing',
    'Votre marketing est solide. Maintenant mesurez tout : suivez d''où vient chaque nouveau client, votre coût d''acquisition par canal et votre tendance de chiffre d''affaires mensuel. Même un tableau de bord tableur simple mis à jour mensuellement révèle quels 20% d''activités génèrent 80% des résultats — vous permettant de réduire le gaspillage et d''investir là où ça paie.'
FROM bhc_categories c WHERE c.slug = 'marketing_growth';

-- ── BUSINESS OPERATIONS ───────────────────────────────────────────────────────

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'needs_attention', 1,
    'Document Your Core Business Processes',
    'Most small businesses run entirely in the owner''s head — if you are sick or take a holiday, everything stops. This month, write down your 3 most important processes: how you serve a customer, how you handle payment, how you deliver your product/service. Even a 1-page checklist per process transforms reliability and allows you to delegate.',
    'Documentez vos processus commerciaux essentiels',
    'La plupart des petites entreprises fonctionnent entièrement dans la tête du propriétaire — si vous êtes malade ou en vacances, tout s''arrête. Ce mois-ci, notez vos 3 processus les plus importants : comment vous servez un client, comment vous gérez le paiement, comment vous livrez votre produit/service. Même une liste de contrôle d''une page par processus transforme la fiabilité et vous permet de déléguer.'
FROM bhc_categories c WHERE c.slug = 'business_operations';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'developing', 2,
    'Implement Basic Financial Tracking',
    'You operate but do not fully measure your finances. This week: separate your business and personal bank accounts (if not done), and track every business expense and income in a simple spreadsheet. Knowing your monthly profit — not just revenue — is the foundation of every growth decision. Without this data, you are flying blind.',
    'Mettez en place un suivi financier de base',
    'Vous opérez mais ne mesurez pas complètement vos finances. Cette semaine : séparez vos comptes bancaires personnel et professionnel (si ce n''est pas fait), et suivez chaque dépense et revenu dans un tableau simple. Connaître votre bénéfice mensuel — pas seulement le chiffre d''affaires — est le fondement de toute décision de croissance. Sans ces données, vous volez à l''aveugle.'
FROM bhc_categories c WHERE c.slug = 'business_operations';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'good_progress', 3,
    'Build a Delegation and Team Structure',
    'Your operations are functional. The growth ceiling for most businesses is the owner''s personal bandwidth. Identify the 2–3 tasks consuming most of your time that someone else could do with 2 weeks of training. Hiring one assistant or part-time employee for these tasks frees you for the high-value work only you can do.',
    'Construisez une structure de délégation et d''équipe',
    'Vos opérations fonctionnent bien. Le plafond de croissance pour la plupart des entreprises est la bande passante personnelle du propriétaire. Identifiez les 2 à 3 tâches qui consomment le plus votre temps et qu''une autre personne pourrait faire avec 2 semaines de formation. Embaucher un assistant ou un employé à temps partiel pour ces tâches vous libère pour le travail à haute valeur que vous seul pouvez faire.'
FROM bhc_categories c WHERE c.slug = 'business_operations';

INSERT IGNORE INTO bhc_recommendations
    (category_id, score_band, priority, title_en, body_en, title_fr, body_fr)
SELECT c.id, 'strong_foundations', 4,
    'Plan for Structured Business Growth',
    'Your operations are excellent — you have built a business that can function systematically. Now plan structured growth: set a 12-month revenue target (20–30% above current), identify the 2–3 key investments (a hire, a tool, a new service) that would unlock it, and review progress monthly. Strong operational foundations make scaling dramatically less risky.',
    'Planifiez une croissance structurée de l''entreprise',
    'Vos opérations sont excellentes — vous avez bâti une entreprise qui peut fonctionner systématiquement. Planifiez maintenant une croissance structurée : fixez un objectif de chiffre d''affaires sur 12 mois (20 à 30% au-dessus du niveau actuel), identifiez les 2 à 3 investissements clés (une embauche, un outil, un nouveau service) qui le permettraient, et examinez les progrès mensuellement.'
FROM bhc_categories c WHERE c.slug = 'business_operations';
