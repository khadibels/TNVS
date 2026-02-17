<?php
require_once __DIR__ . '/includes/config.php';
$BASE = rtrim(BASE_URL, '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Vendor Portal | ViaHale — Partner With Us</title>
  <meta name="description" content="Join the ViaHale vendor network. Browse open bid opportunities, register as a vendor partner, and start supplying goods and services to TNVS.">

  <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="<?= $BASE ?>/css/vendor_portal_saas.css" rel="stylesheet" />

  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <style>
    :root {
      --brand-primary: #6532C9;
      --brand-deep: #4311A5;
      --brand-accent: #7c3aed;
      --brand-light: #f4f2ff;
      --text-dark: #2b2349;
      --text-muted: #6f6c80;
      --border-soft: #ece7ff;
    }
    *{ box-sizing: border-box; }
    body{
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      margin: 0; color: var(--text-dark);
      background: #fbfbff;
    }

    /* ─── Navbar ─── */
    .lp-nav{
      position: sticky; top: 0; z-index: 100;
      background: rgba(255,255,255,.88);
      backdrop-filter: blur(14px) saturate(1.4);
      -webkit-backdrop-filter: blur(14px) saturate(1.4);
      border-bottom: 1px solid var(--border-soft);
      padding: .85rem 0;
    }
    .lp-nav .inner{
      max-width: 1200px; margin: auto; padding: 0 1.5rem;
      display: flex; align-items: center; justify-content: space-between;
    }
    .lp-brand{ display: flex; align-items: center; gap: .6rem; text-decoration: none; }
    .lp-brand img{ height: 36px; }
    .lp-brand span{ font-family: 'Bricolage Grotesque', sans-serif; font-weight: 700; font-size: 1.15rem; color: var(--brand-deep); }
    .lp-nav-links{ display: flex; gap: .6rem; align-items: center; }
    .btn-ghost{
      background: transparent; border: 1.5px solid var(--border-soft); color: var(--brand-deep);
      padding: .55rem 1.25rem; border-radius: .6rem; font-weight: 600; font-size: .88rem;
      transition: all .2s;
    }
    .btn-ghost:hover{ border-color: var(--brand-accent); background: var(--brand-light); color: var(--brand-primary); }
    .btn-fill{
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-accent));
      color: #fff; border: none; padding: .6rem 1.4rem; border-radius: .6rem;
      font-weight: 600; font-size: .88rem; box-shadow: 0 4px 14px rgba(101,50,201,.25);
      transition: all .2s;
    }
    .btn-fill:hover{ transform: translateY(-1px); box-shadow: 0 6px 20px rgba(101,50,201,.35); color:#fff; }

    /* ─── Hero ─── */
    .hero{
      position: relative; overflow: hidden;
      background:
        radial-gradient(ellipse 90% 70% at 20% 30%, rgba(124,58,237,.12), transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 10%, rgba(101,50,201,.08), transparent 50%),
        linear-gradient(180deg, #fbfbff 0%, #f1edff 100%);
      padding: 5rem 1.5rem 4rem; text-align: center;
    }
    .hero-badge{
      display: inline-flex; align-items: center; gap: .4rem;
      background: rgba(101,50,201,.08); border: 1px solid rgba(101,50,201,.14);
      border-radius: 999px; padding: .35rem 1rem; font-size: .78rem; font-weight: 600;
      color: var(--brand-deep); margin-bottom: 1.25rem; letter-spacing: .3px;
    }
    .hero h1{
      font-family: 'Bricolage Grotesque', sans-serif;
      font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 700;
      color: var(--brand-deep); line-height: 1.15; max-width: 700px; margin: 0 auto .75rem;
    }
    .hero h1 span{ color: var(--brand-accent); }
    .hero p{
      font-size: 1.05rem; color: var(--text-muted); max-width: 560px; margin: 0 auto 2rem; line-height: 1.55;
    }
    .hero-actions{ display: flex; justify-content: center; gap: .75rem; flex-wrap: wrap; }
    .hero-actions .btn-fill{ padding: .75rem 2rem; font-size: .95rem; }
    .hero-actions .btn-ghost{ padding: .75rem 2rem; font-size: .95rem; }

    /* floating shapes */
    .hero::before, .hero::after{
      content:''; position: absolute; border-radius: 50%; pointer-events: none; opacity: .25;
      background: linear-gradient(135deg, var(--brand-accent), var(--brand-primary));
    }
    .hero::before{ width: 340px; height: 340px; top: -120px; left: -80px; filter: blur(80px); }
    .hero::after{ width: 250px; height: 250px; bottom: -60px; right: -50px; filter: blur(60px); }

    /* ─── Section container ─── */
    .lp-section{ max-width: 1200px; margin: 0 auto; padding: 4rem 1.5rem; }
    .section-header{ text-align: center; margin-bottom: 2.5rem; }
    .section-header h2{
      font-family: 'Bricolage Grotesque', sans-serif;
      font-size: clamp(1.4rem, 3vw, 2rem); font-weight: 700; color: var(--brand-deep);
    }
    .section-header p{ color: var(--text-muted); max-width: 520px; margin: .5rem auto 0; }

    /* ─── Bid Cards ─── */
    .bids-grid{
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 1.25rem;
    }
    .bid-card{
      background: #fff; border: 1px solid var(--border-soft); border-radius: 16px;
      padding: 1.5rem; display: flex; flex-direction: column;
      box-shadow: 0 2px 8px rgba(67,17,165,.04);
      transition: transform .2s, box-shadow .2s;
    }
    .bid-card:hover{ transform: translateY(-3px); box-shadow: 0 8px 24px rgba(67,17,165,.1); }
    .bid-card .rfq-label{
      font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
      color: var(--brand-accent); margin-bottom: .35rem;
    }
    .bid-card h3{ font-size: 1.05rem; font-weight: 600; margin: 0 0 .6rem; line-height: 1.3; }
    .bid-meta{ display: flex; gap: 1rem; font-size: .82rem; color: var(--text-muted); margin-bottom: 1rem; }
    .bid-meta ion-icon{ font-size: 1rem; vertical-align: -2px; margin-right: 3px; color: var(--brand-accent); }
    .bid-card .bid-cta{
      margin-top: auto; display: inline-flex; align-items: center; gap: .4rem;
      font-size: .85rem; font-weight: 600; color: var(--brand-primary); text-decoration: none;
    }
    .bid-card .bid-cta:hover{ color: var(--brand-accent); }
    .bid-card .bid-cta ion-icon{ font-size: 1rem; transition: transform .2s; }
    .bid-card .bid-cta:hover ion-icon{ transform: translateX(3px); }
    .bids-empty{
      grid-column: 1 / -1; text-align: center; padding: 3rem 1rem; color: var(--text-muted);
    }
    .bids-empty ion-icon{ font-size: 2.5rem; color: var(--border-soft); margin-bottom: .75rem; display: block; }

    /* ─── Features ─── */
    .features-grid{
      display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;
    }
    .feat-card{
      background: #fff; border: 1px solid var(--border-soft); border-radius: 16px;
      padding: 2rem 1.5rem; text-align: center;
      box-shadow: 0 2px 8px rgba(67,17,165,.04);
      transition: transform .2s, box-shadow .2s;
    }
    .feat-card:hover{ transform: translateY(-3px); box-shadow: 0 8px 24px rgba(67,17,165,.1); }
    .feat-icon{
      width: 56px; height: 56px; border-radius: 14px; margin: 0 auto 1rem;
      display: grid; place-items: center; font-size: 1.6rem;
      background: linear-gradient(135deg, #efe9ff, #f4f2ff);
      color: var(--brand-primary); border: 1px solid rgba(101,50,201,.1);
    }
    .feat-card h3{ font-size: 1rem; font-weight: 700; margin-bottom: .4rem; }
    .feat-card p{ font-size: .88rem; color: var(--text-muted); margin: 0; line-height: 1.5; }

    /* ─── CTA Banner ─── */
    .cta-banner{
      background: linear-gradient(135deg, var(--brand-primary), var(--brand-accent));
      border-radius: 20px; padding: 3rem 2rem; text-align: center; color: #fff;
      box-shadow: 0 12px 36px rgba(101,50,201,.25); margin-bottom: 3rem;
    }
    .cta-banner h2{ font-family: 'Bricolage Grotesque', sans-serif; font-size: clamp(1.3rem,3vw,1.8rem); font-weight: 700; margin-bottom: .5rem; }
    .cta-banner p{ opacity: .9; max-width: 480px; margin: 0 auto 1.5rem; }
    .cta-banner .btn-white{
      background: #fff; color: var(--brand-deep); border: none;
      padding: .75rem 2rem; border-radius: .6rem; font-weight: 600; font-size: .95rem;
      box-shadow: 0 4px 14px rgba(0,0,0,.1); transition: all .2s;
    }
    .cta-banner .btn-white:hover{ transform: translateY(-1px); box-shadow: 0 6px 20px rgba(0,0,0,.15); }

    /* ─── Responsive ─── */
    @media (max-width: 576px) {
      .hero{ padding: 3.5rem 1rem 3rem; }
      .lp-section{ padding: 2.5rem 1rem; }
      .lp-nav-links .btn-label{ display: none; }
    }
  </style>
</head>
<body>

  <!-- ── Navbar ── -->
  <nav class="lp-nav" id="topNav">
    <div class="inner">
      <a href="<?= $BASE ?>/landing_page.php" class="lp-brand">
        <img src="<?= $BASE ?>/img/logo.png" alt="ViaHale logo">
        <span>ViaHale</span>
      </a>
      <div class="lp-nav-links">
        <a href="<?= $BASE ?>/login.php" class="btn-ghost">
          <ion-icon name="log-in-outline" style="vertical-align:-2px;margin-right:4px"></ion-icon>
          <span class="btn-label">Sign In</span>
        </a>
        <a href="<?= $BASE ?>/vendor_portal/vendor/register.php" class="btn-fill">
          <ion-icon name="person-add-outline" style="vertical-align:-2px;margin-right:4px"></ion-icon>
          Register
        </a>
      </div>
    </div>
  </nav>

  <!-- ── Hero ── -->
  <section class="hero">
    <div class="hero-badge">
      <ion-icon name="storefront-outline"></ion-icon>
      Vendor Portal
    </div>
    <h1>Become a <span>TNVS Vendor</span> Partner</h1>
    <p>Join our growing network of trusted suppliers. Browse open bid opportunities, submit competitive quotes, and grow your business with ViaHale.</p>
    <div class="hero-actions">
      <a href="<?= $BASE ?>/vendor_portal/vendor/register.php" class="btn-fill" id="heroRegister">
        <ion-icon name="rocket-outline" style="vertical-align:-2px;margin-right:4px"></ion-icon>
        Register Now
      </a>
      <a href="<?= $BASE ?>/login.php" class="btn-ghost" id="heroSignIn">
        <ion-icon name="log-in-outline" style="vertical-align:-2px;margin-right:4px"></ion-icon>
        Sign In
      </a>
    </div>
  </section>

  <!-- ── Open Bids ── -->
  <section class="lp-section" id="bids">
    <div class="section-header">
      <h2>Items Open for Bidding</h2>
      <p>These are the current procurement requests looking for vendor quotations. Register to submit your bids.</p>
    </div>
    <div class="bids-grid" id="bidsGrid">
      <div class="bids-empty">
        <ion-icon name="hourglass-outline"></ion-icon>
        Loading open bids…
      </div>
    </div>
  </section>

  <!-- ── Features ── -->
  <section class="lp-section" style="padding-top:0">
    <div class="section-header">
      <h2>Why Partner With Us?</h2>
      <p>TNVS offers a streamlined, transparent procurement process for all vendor partners.</p>
    </div>
    <div class="features-grid">
      <div class="feat-card">
        <div class="feat-icon"><ion-icon name="shield-checkmark-outline"></ion-icon></div>
        <h3>Transparent Bidding</h3>
        <p>Every RFQ is published with clear specifications, quantities, and deadlines — no hidden requirements.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><ion-icon name="flash-outline"></ion-icon></div>
        <h3>Fast Payments</h3>
        <p>Approved purchase orders are processed promptly so you receive payment on time, every time.</p>
      </div>
      <div class="feat-card">
        <div class="feat-icon"><ion-icon name="clipboard-outline"></ion-icon></div>
        <h3>Simple Onboarding</h3>
        <p>Register in minutes with a guided wizard. Upload your documents once — we handle the rest.</p>
      </div>
    </div>
  </section>

  <!-- ── CTA Banner ── -->
  <section class="lp-section" style="padding-top:0">
    <div class="cta-banner">
      <h2>Ready to Start Supplying?</h2>
      <p>Create your vendor account today and get access to all open bid opportunities.</p>
      <a href="<?= $BASE ?>/vendor_portal/vendor/register.php" class="btn-white" id="ctaRegister">
        <ion-icon name="person-add-outline" style="vertical-align:-2px;margin-right:4px"></ion-icon>
        Register as Vendor
      </a>
    </div>
  </section>

  <!-- ── Footer ── -->
  <?php include __DIR__ . '/includes/legal_footer.php'; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <?php include __DIR__ . '/includes/policy_modal.php'; ?>

  <script>
    const BASE = '<?= $BASE ?>';
    const API_URL = BASE + '/vendor_portal/vendor/api/public_rfqs.php';

    function escHtml(s) {
      return String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
    }

    function timeLeft(dueStr) {
      const ms = new Date(String(dueStr).replace(' ', 'T')) - Date.now();
      if (ms <= 0) return 'Closing soon';
      const d = Math.floor(ms / 86400000);
      const h = Math.floor((ms % 86400000) / 3600000);
      if (d > 0) return d + 'd ' + h + 'h left';
      return h + 'h left';
    }

    async function loadBids() {
      const grid = document.getElementById('bidsGrid');
      try {
        const res = await fetch(API_URL);
        const json = await res.json();
        const rows = (json.ok && Array.isArray(json.data)) ? json.data : [];

        if (rows.length === 0) {
          grid.innerHTML = `
            <div class="bids-empty">
              <ion-icon name="document-text-outline"></ion-icon>
              <strong>No open bids right now</strong><br>
              <span>Check back soon — new procurement requests are posted regularly.</span>
            </div>`;
          return;
        }

        grid.innerHTML = rows.map(r => `
          <div class="bid-card">
            <div class="rfq-label">RFQ ${escHtml(r.rfq_no)}</div>
            <h3>${escHtml(r.title)}</h3>
            <div class="bid-meta">
              <span><ion-icon name="cube-outline"></ion-icon>${r.item_count} item${r.item_count != 1 ? 's' : ''}</span>
              <span><ion-icon name="time-outline"></ion-icon>${timeLeft(r.due_at)}</span>
            </div>
            <a href="${BASE}/vendor_portal/vendor/register.php" class="bid-cta">
              Register to Bid <ion-icon name="arrow-forward-outline"></ion-icon>
            </a>
          </div>
        `).join('');
      } catch (e) {
        grid.innerHTML = `
          <div class="bids-empty">
            <ion-icon name="alert-circle-outline"></ion-icon>
            Unable to load bids. Please try again later.
          </div>`;
        console.error('Failed to load bids:', e);
      }
    }

    loadBids();
  </script>
</body>
</html>
