/**
 * Parcours navigateur du pilotage de production — verrouille le JS de l'écran.
 *
 * Lancement (voir tools/mock-api/README.md pour les deux serveurs) :
 *   node tools/e2e/pilotage.e2e.js [URL_APP]
 *
 * Le scénario vit une journée : ouvrir chaque étape, enfourner une fournée
 * bornée par la prévision, la sortir, jeter une pièce en fin de journée, et
 * vérifier que les compteurs et le bilan suivent. Sort avec un code ≠ 0 au
 * premier échec — utilisable tel quel en CI avec un chromium Playwright.
 */
const { chromium } = require('playwright');
const BASE = process.argv[2] || 'http://127.0.0.1:8010';

function fail(msg) { console.error('✗ ' + msg); process.exit(1); }
function ok(msg) { console.log('✓ ' + msg); }

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 820, height: 1180 } })).newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));

  await page.goto(BASE + '/auth', { waitUntil: 'networkidle' });
  await page.selectOption('select[name="shop_id"]', '1');
  await page.fill('input[name="login"]', 'tablette');
  await page.fill('input[name="password"]', '1234');
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');

  await page.goto(BASE + '/production/pilotage', { waitUntil: 'networkidle' });
  await page.evaluate(() => {
    Object.keys(localStorage).filter(k => k.startsWith('pl.')).forEach(k => localStorage.removeItem(k));
  });
  await page.reload({ waitUntil: 'networkidle' });

  // Les trois volets du stepper répondent.
  for (const pane of ['p2', 'p4', 'p1']) {
    await page.click('#flowBar [data-pane="' + pane + '"]');
    await page.waitForTimeout(150);
    const visible = await page.evaluate(id => {
      const el = document.getElementById(id);
      return el && el.classList.contains('is-active');
    }, pane);
    if (!visible) fail('volet ' + pane + ' inactif');
  }
  ok('stepper : les trois volets s\'activent');

  // Enfourner la baguette (bornée), la sortir : les compteurs suivent.
  await page.click('#pdmToggle [data-bucket="day"]');
  await page.waitForTimeout(200);
  const launched = await page.evaluate(() => {
    const z = [...document.querySelectorAll('#bucketDay .pl-launch')].find(x => x.dataset.pid === '1300003');
    if (!z) return null;
    const go = z.querySelector('.pl-fbtn-go');
    if (!go) return null;
    const qty = +z.querySelector('.pl-qty-num').textContent;
    go.click();
    return qty;
  });
  if (!launched) fail('pas de bouton Enfourner pour la baguette');
  await page.waitForTimeout(300);
  const prod = await page.evaluate(() => +document.getElementById('kpiProd').textContent);
  if (prod !== launched) fail('compteur EN PRODUCTION attendu ' + launched + ', vu ' + prod);
  ok('enfourner : ' + launched + ' pièces au four, compteur juste');

  await page.evaluate(() => {
    const z = [...document.querySelectorAll('#bucketDay .pl-launch')].find(x => x.dataset.pid === '1300003');
    const c = z && z.querySelector('.pl-fbtn-cooking');
    if (c) c.click();
  });
  await page.waitForTimeout(300);
  const stock = await page.evaluate(() => +document.getElementById('kpiStock').textContent);
  if (stock !== launched) fail('compteur EN STOCK attendu ' + launched + ', vu ' + stock);
  ok('sortir : ' + stock + ' pièces cuites, compteur juste');

  // La journée en 4 cuissons : la barre bascule, et la vitrine (fournées
  // sorties − vendu) se déduit de la liste d'ouverture pour la baguette.
  const slots = await page.evaluate(() => {
    const bar = [...document.querySelectorAll('#plSlots .pl-slot-btn')];
    if (bar.length !== 4) return { n: bar.length };
    bar[0].click();
    const pane = document.querySelector('#plSlots .pl-slot-pane[data-i="0"]');
    const row = pane && [...pane.querySelectorAll('.pl-slot-row')].find(r => r.dataset.pid === '1300003');
    return {
      n: bar.length,
      activeIs0: bar[0].classList.contains('is-active'),
      paneShown: pane && !pane.hidden,
      firm: row ? +row.dataset.firm : null,
      vendu: row ? +row.dataset.vendu : null,
      vit: row ? +row.querySelector('.pl-slot-vit').textContent : null,
      out: row ? +row.querySelector('.pl-slot-out-n').textContent : null,
    };
  });
  if (slots.n !== 4) fail('4 créneaux attendus, vu ' + slots.n);
  if (!slots.activeIs0 || !slots.paneShown) fail('la barre des créneaux ne bascule pas sur l\'ouverture');
  if (slots.firm === null) fail('la baguette manque dans la liste d\'ouverture');
  await page.waitForTimeout(100);
  const after = await page.evaluate(() => {
    const row = [...document.querySelectorAll('#plSlots .pl-slot-pane[data-i="0"] .pl-slot-row')].find(r => r.dataset.pid === '1300003');
    return { vit: +row.querySelector('.pl-slot-vit').textContent, out: +row.querySelector('.pl-slot-out-n').textContent };
  });
  const wantVit = Math.max(0, launched - slots.vendu);
  if (after.vit !== wantVit) fail('vitrine baguette attendue ' + wantVit + ' (sorties ' + launched + ' − vendu ' + slots.vendu + '), vue ' + after.vit);
  if (after.out !== Math.max(0, slots.firm - wantVit)) fail('à cuire baguette attendu ' + Math.max(0, slots.firm - wantVit) + ', vu ' + after.out);
  ok('4 cuissons : ouverture ' + slots.firm + ' ferme, vitrine ' + after.vit + ' déduite, à cuire ' + after.out);

  // Fin de journée : jeter une pièce, le bilan suit.
  await page.click('#flowBar [data-pane="p4"]');
  await page.waitForTimeout(200);
  await page.evaluate(() => { const r = document.querySelector('.pl-eod-row'); if (r) r.click(); });
  await page.waitForTimeout(200);
  await page.evaluate(() => {
    const d = [...document.querySelectorAll('.pl-eod-drawer')].find(x => !x.hasAttribute('hidden'));
    if (d) d.querySelector('[data-act="waste"]').click();
  });
  await page.waitForTimeout(800);
  const jete = await page.evaluate(() => +document.getElementById('repJete').textContent);
  if (jete < 1) fail('le bilan ne compte pas le jeté');
  ok('fin de journée : jeté compté au bilan');

  if (errors.length) fail('erreurs JS : ' + errors.join(' | '));
  ok('aucune erreur JS');
  await browser.close();
  console.log('✓ parcours pilotage complet');
})().catch(e => fail(String(e)));
