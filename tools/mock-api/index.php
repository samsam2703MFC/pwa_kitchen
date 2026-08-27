<?php
/**
 * Serveur d'API bouchon — pour tester les modules Production et Cuisson avant
 * que le back-office ne serve quoi que ce soit.
 *
 *     php -S 127.0.0.1:8081 tools/mock-api/index.php
 *     KITCHEN_API_BASE=http://127.0.0.1:8081  (voir tools/mock-api/README.md)
 *
 * Il garde son état sur disque : valider une MEP augmente le stock, sortir une
 * fournée du four la fait passer en finition, valider une recuisson recalcule
 * les propositions. Un jeu de JSON figés montrerait les écrans ; celui-ci
 * laisse les traverser.
 *
 * Une route inconnue répond 404 avec « not_mocked » plutôt qu'une liste vide :
 * un écran qui se remplit de rien ne dit pas s'il manque une donnée ou un
 * endpoint.
 */

require __DIR__ . '/data.php';

const STATE_FILE = __DIR__ . '/.state.json';

// ── Sortie ────────────────────────────────────────────────────────────────
/**
 * Le corps est renvoyé NU, sans enveloppe.
 *
 * C'est la convention de toute l'application : `ApiClient::get()` construit
 * lui-même `['success' => <code HTTP 2xx>, 'data' => <corps décodé>]`. Ajouter
 * un `{"success":…, "data":…}` côté serveur ferait donc arriver la charge utile
 * sous `$response['data']['data']`, et tous les dépôts liraient à côté.
 */
function ok(mixed $data = null, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * L'échec se dit par le code HTTP : c'est lui que lit `ApiClient`, qui met
 * alors `success` à false. Le corps ne porte que le détail, sous `description`
 * — la clé que `post()` et `patch()` savent remonter.
 */
function ko(string $description, int $code = 400): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['description' => $description], JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $j   = json_decode($raw, true);
    if (is_array($j)) {
        return $j;
    }
    // Certains appels de l'app passent en multipart ou en form-urlencoded.
    return $_POST ?: [];
}

// ── État ──────────────────────────────────────────────────────────────────
function now_minutes(): int { return (int)date('G') * 60 + (int)date('i'); }

function state(): array
{
    static $state = null;
    if ($state !== null) {
        return $state;
    }

    $today = date('Y-m-d');
    if (is_readable(STATE_FILE)) {
        $loaded = json_decode((string)file_get_contents(STATE_FILE), true);
        // L'état ne vaut que pour la journée qui l'a produit : au lendemain,
        // on repart d'un plan frais plutôt que d'afficher les fournées d'hier.
        if (is_array($loaded) && ($loaded['date'] ?? null) === $today) {
            return $state = $loaded;
        }
    }

    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $state = [
        'date'    => $today,
        'stock'   => mock_initial_stock(),
        'batches' => mock_baking_batches(now_minutes()),
        'mep'     => [
            $today    => mock_mep_today($today),
            $tomorrow => mock_mep_tomorrow($tomorrow),
        ],
    ];
    save($state);
    return $state;
}

function save(array $state): void
{
    $GLOBALS['__state'] = $state;
    @file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function mutate(callable $fn): array
{
    $s = state();
    $s = $fn($s);
    save($s);
    // Purge le cache statique de state() pour la suite de la requête.
    return $s;
}

// ── Jeton d'accès ─────────────────────────────────────────────────────────
/**
 * L'application décode le JWT sans en vérifier la signature ; elle n'en lit
 * que les claims et l'expiration. Un jeton de façade suffit donc — et il
 * n'expose évidemment rien : ce serveur ne doit jamais quitter un poste de
 * développement.
 */
function fake_jwt(int $shopId, int $hours = 12): string
{
    $b64 = fn(array $a) => rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
    return $b64(['alg' => 'HS256', 'typ' => 'JWT']) . '.' .
           $b64([
               'shop_id'   => $shopId,
               'device_id' => 42,
               'dv_nm'     => 'Tablette atelier (mock)',
               'dv_lncd'   => 'fr',
               'iat'       => time(),
               'exp'       => time() + $hours * 3600,
           ]) . '.' .
           rtrim(strtr(base64_encode('mock-signature'), '+/', '-_'), '=');
}

// ── Miroir de la PRODUCTION reelle (atelierby.tfbuddy.com) ─────────────────
// MOCK_PROD_MIRROR=1 : le bouchon renvoie 404 EXACTEMENT sur les routes
// absentes du swagger de prod (933 routes, verifie le 26/08). Sert a voir ce
// qu'une vraie tablette voit aujourd'hui : les modules production et cuisson
// n'ont pas de back, l'ecran doit le dire sans casser.
if (getenv('MOCK_PROD_MIRROR')) {
    $absentes = [
        '#/shops/\d+/production/config#', '#/shops/\d+/production/products#',
        '#/shops/\d+/production/batches#', '#/shops/\d+/production/pending-count#',
        '#/shops/\d+/stock#', '#/shops/\d+/sales/profile#',
        '#/shops/\d+/mep#', '#/shops/\d+/baking#', '#/shops/\d+/ovens#',
    ];
    $chemin = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
    foreach ($absentes as $re) {
        if (preg_match($re, $chemin)) { http_response_code(404); exit; }
    }
}

// ── Routage ───────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path   = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '', '/');
$q      = $_GET;

$m = fn(string $pattern, ?array &$vars = null) => (bool)preg_match('#^' . $pattern . '$#', $path, $vars);

// ── Authentification ──────────────────────────────────────────────────────
if ($method === 'GET' && $path === '/public/shops') {
    ok(mock_shops());
}

if ($method === 'POST' && in_array($path, ['/devices/auth/login', '/devices/auth/refresh'], true)) {
    $in     = body();
    $shopId = (int)($in['shop_id'] ?? $in['id_shop'] ?? MOCK_SHOP_ID) ?: MOCK_SHOP_ID;

    // Réponse à plat, sans enveloppe : c'est ce que lit LoginRepository.
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'access_token'  => fake_jwt($shopId),
        'refresh_token' => fake_jwt($shopId, 24 * 30),
    ]);
    exit;
}

// ── Production : configuration et catalogue ───────────────────────────────
if ($method === 'GET' && $m('/shops/\d+/production/config')) {
    ok([
        'periods' => [
            ['key' => 'morning',   'label' => 'Matin',      'start' => '05:00', 'end' => '11:00'],
            ['key' => 'noon',      'label' => 'Midi',       'start' => '11:00', 'end' => '14:00'],
            ['key' => 'afternoon', 'label' => 'Après-midi', 'start' => '14:00', 'end' => '19:00'],
        ],
        'forecast_hours' => 2,
        'history_weeks'  => 6,
        'safety_margin'  => 0,
    ]);
}

if ($method === 'GET' && $m('/shops/\d+/production/products')) {
    ok(mock_products());
}

// ── Production : MEP ──────────────────────────────────────────────────────
if ($method === 'GET' && $m('/shops/\d+/mep')) {
    $date = $q['date'] ?? date('Y-m-d');
    $s    = state();
    ok($s['mep'][$date] ?? ['date' => $date, 'prepared_at' => null, 'status' => 'PREPARED', 'lines' => []]);
}

// Encodage de la MEP d'une date à venir : l'appel remplace ce qui existait.
if ($method === 'POST' && $m('/shops/\d+/mep')) {
    $in   = body();
    $date = (string)($in['date'] ?? date('Y-m-d', strtotime('+1 day')));
    $byId = [];
    foreach (mock_products() as $p) {
        $byId[$p['id_product']] = $p;
    }

    $s = mutate(function (array $s) use ($in, $date, $byId) {
        $lines = [];
        $i = 4600;
        foreach ($in['lines'] ?? [] as $l) {
            $idp = (int)($l['id_product'] ?? 0);
            $qty = (float)($l['quantity'] ?? 0);
            if ($idp <= 0 || $qty <= 0) {
                continue;
            }
            $p = $byId[$idp] ?? null;
            $lines[] = [
                'id'                 => $i++,
                'id_product'         => $idp,
                'name'               => $p['name'] ?? ('Produit ' . $idp),
                'category_name'      => $p['category_name'] ?? null,
                // La période vient de la fiche produit : c'est le serveur qui
                // la connaît, pas l'écran de saisie.
                'period'             => $p['periods'][0] ?? 'morning',
                'quantity_planned'   => $qty,
                'quantity_validated' => null,
                'unit_name'          => $p['unit_name'] ?? 'pc',
                'status'             => 'PREPARED',
            ];
        }
        $s['mep'][$date] = [
            'date' => $date, 'prepared_at' => date('Y-m-d H:i:s'),
            'status' => 'PREPARED', 'lines' => $lines,
        ];
        return $s;
    });

    ok($s['mep'][$date]);
}

// Validation de la MEP du jour : c'est elle qui alimente le stock.
if ($method === 'POST' && $m('/shops/\d+/mep/validate')) {
    $in   = body();
    $date = (string)($in['date'] ?? date('Y-m-d'));

    $s = mutate(function (array $s) use ($in, $date) {
        $wanted = [];
        foreach ($in['lines'] ?? [] as $l) {
            $wanted[(int)($l['id'] ?? 0)] = $l;
        }

        // Itération par index, pas par référence : « $s[...] ?? [] » produit
        // une copie temporaire, et les écritures faites dessus se perdent.
        $lines = $s['mep'][$date]['lines'] ?? [];
        foreach ($lines as $i => $line) {
            $w = $wanted[$line['id']] ?? null;
            if ($w === null) {
                continue;
            }
            if (!empty($w['skipped'])) {
                $lines[$i]['status'] = 'SKIPPED';
                $lines[$i]['quantity_validated'] = 0;
                $lines[$i]['quantity_shelved']   = 0;
                continue;
            }
            $qty = max(0, (float)($w['quantity'] ?? 0));
            $lines[$i]['status'] = 'VALIDATED';
            $lines[$i]['quantity_validated'] = $qty;
            $lines[$i]['quantity_shelved']   = (float)($line['quantity_shelved'] ?? 0);
            // Le stock vendable n'est PAS crédité ici : valider une MEP
            // constate ce qui est sorti du four, pas ce que la caisse peut
            // vendre. C'est la mise en rayon (source SHELF) qui crédite.
        }
        $s['mep'][$date]['lines'] = $lines;

        $reste = array_filter($lines, fn($l) => $l['status'] === 'PREPARED');
        $s['mep'][$date]['status'] = $reste === [] ? 'VALIDATED' : 'PREPARED';
        return $s;
    });

    ok($s['mep'][$date] + ['stock' => stock_rows($s)]);
}

// ── Production : stock et ventes ──────────────────────────────────────────
function stock_rows(array $s): array
{
    $rows = [];
    foreach (mock_products() as $p) {
        $rows[] = [
            'id_product'    => $p['id_product'],
            'name'          => $p['name'],
            'category_name' => $p['category_name'],
            'quantity'      => (float)($s['stock'][$p['id_product']] ?? 0),
            'unit_name'     => $p['unit_name'] ?? 'pc',
        ];
    }
    usort($rows, fn($a, $b) => $a['quantity'] <=> $b['quantity']);
    return $rows;
}

if ($method === 'GET' && $m('/shops/\d+/stock')) {
    ok(['updated_at' => date('Y-m-d H:i:s'), 'items' => stock_rows(state())]);
}

if ($method === 'GET' && $m('/shops/\d+/sales/profile')) {
    ok(mock_sales_profile());
}

// ── Production : carnet de commandes ──────────────────────────────────────
// Magasin, click & collect, livraison : une seule route pour les trois canaux,
// ce sont les mêmes produits et le même four. Le canal voyage sur la ligne.
if ($method === 'GET' && $m('/shops/\d+/orders')) {
    ok(['date' => $q['date'] ?? date('Y-m-d'), 'items' => mock_orders(now_minutes())]);
}

// Lot produit — MEP validée ou recuisson.
if ($method === 'POST' && $m('/shops/\d+/production/batches')) {
    $in  = body();
    $idp = (int)($in['id_product'] ?? 0);
    $qty = (float)($in['quantity'] ?? 0);
    if ($idp <= 0 || $qty <= 0) {
        ko('invalid_payload', 422);
    }

    $lineId = isset($in['id_mep_line']) ? (int)$in['id_mep_line'] : 0;

    // Porter plus que ce qui est sorti du four se refuse ici plutôt que de se
    // rogner en silence : deux tablettes peuvent porter le même plateau, et un
    // écrêtage muet ferait croire aux deux qu'elles ont réussi.
    if ($lineId > 0) {
        foreach (state()['mep'] as $mep) {
            foreach ($mep['lines'] as $l) {
                if ((int)$l['id'] !== $lineId) {
                    continue;
                }
                $reste = (float)($l['quantity_validated'] ?? 0) - (float)($l['quantity_shelved'] ?? 0);
                if ($qty > $reste) {
                    ko('shelf_exceeds_produced', 409);
                }
            }
        }
    }

    $s = mutate(function (array $s) use ($idp, $qty, $lineId) {
        $s['stock'][$idp] = ($s['stock'][$idp] ?? 0) + $qty;

        // Mise en rayon rattachée à une ligne de MEP : on décompte le reste à
        // porter, sinon l'écran reproposerait indéfiniment le même plateau.
        if ($lineId > 0) {
            foreach ($s['mep'] as $date => $mep) {
                foreach ($mep['lines'] as $i => $l) {
                    if ((int)$l['id'] !== $lineId) {
                        continue;
                    }
                    $s['mep'][$date]['lines'][$i]['quantity_shelved'] =
                        (float)($l['quantity_shelved'] ?? 0) + $qty;
                }
            }
        }
        return $s;
    });

    $line = null;
    foreach (stock_rows($s) as $r) {
        if ($r['id_product'] === $idp) {
            $line = $r;
        }
    }

    ok([
        'batch' => [
            'id'          => random_int(9000, 9999),
            'id_product'  => $idp,
            'quantity'    => $qty,
            'source'      => strtoupper((string)($in['source'] ?? 'REBAKE')),
            'created_at'  => date('Y-m-d H:i:s'),
            'id_employee' => $in['id_employee'] ?? null,
        ],
        // Le stock résultant, pour que l'écran réaffiche un chiffre serveur
        // au lieu de sa propre addition.
        'stock' => $line,
    ]);
}

if ($method === 'GET' && $m('/shops/\d+/production/pending-count')) {
    $s = state();
    $date = date('Y-m-d');
    $pending = count(array_filter($s['mep'][$date]['lines'] ?? [], fn($l) => $l['status'] === 'PREPARED'));
    ok(['mep_pending' => $pending, 'rebakes_suggested' => 3]);
}

// ── Équipe ────────────────────────────────────────────────────────────────
if ($method === 'GET' && $m('/shops/\d+/employees')) {
    ok(mock_employees());
}

// Valider — ou refuser — une tache de checklist. Le bouchon ne conserve rien :
// il repond, ce qui suffit a verifier le parcours a l'ecran. Le statut est
// renvoye tel quel pour qu'une erreur de champ se voie tout de suite.
if ($method === 'POST' && $m('/employees/\d+/tasks/\d+/mark-as-done')) {
    $in = body();
    $st = strtoupper((string)($in['status'] ?? ''));
    if (!in_array($st, ['DONE', 'FAILED'], true)) {
        ko('invalid_status', 422);
    }
    ok(['message' => 'ok', 'status' => $st, 'note' => (string)($in['note'] ?? '')]);
}

// Ce que chaque mode de tablette affiche — table pwa_kitchen_param, §8 du
// document de passation. Un 404 ici n'est pas une panne : la PWA garde ses
// valeurs par defaut. C'est exactement ce qu'on veut pouvoir verifier.
if ($method === 'GET' && $m('/devices/\d+/configuration')) {
    // MOCK_CONFIG_DEAD=1 : la route se tait. Depuis le 26/08, la PWA doit
    // alors montrer les menus PAR DEFAUT et nommer la route en bandeau —
    // c'est exactement ce que ce reglage permet de verifier a l'ecran.
    if (getenv('MOCK_CONFIG_DEAD')) { http_response_code(503); exit; }
    ok(mock_device_config());
}

// Ventes par produit d'une journee — product-category-groups (forme reelle).
// Sert le pilotage de production (version journee entiere).
if ($method === 'GET' && $m('/shops/\\d+/statistics/sales/product-category-groups')) {
    ok(mock_product_category_groups($q['date_from'] ?? date('Y-m-d')));
}

// Les commandes clients d'un jour de retrait — releve reel (avec lignes
// produit, include=products). Sert la mise en rayon : ce qu'il faut reserver.
if ($method === 'GET' && $m('/shops/\\d+/client-orders')) {
    ok(mock_shop2_fixture()['client_orders_by_date'][(string)($q['date_from'] ?? '')] ?? []);
}

// Les reductions programmees actives — releve reel (aucune le 27/08 : la
// liste vide est une reponse, pas une panne).
if ($method === 'GET' && $m('/shops/\\d+/scheduled-product-discounts/active')) {
    ok(mock_shop2_fixture()['discounts_active'] ?? ['items' => []]);
}

// Fin de journee — les ecritures du volet « Solder ». Le bouchon accepte et
// repond comme un back qui a enregistre ; la prod, elle, repondra ce qu'elle
// veut et la PWA affichera l'erreur en nommant la route.
if ($method === 'POST' && $m('/shops/(\\d+)/products/(\\d+)/waste', $vars)) {
    ok(['status' => 'ok', 'id_product' => (int)$vars[2]]);
}
if ($method === 'POST' && $m('/product-movements')) {
    ok(['status' => 'ok', 'id' => 1]);
}

// La demarque produits — releve reel. Un jour seul (journee en cours) est
// vide ; une plage de plusieurs jours rejoue la semaine capturee (4 produits,
// raison « expiration »).
if ($method === 'GET' && $m('/shops/\\d+/products/waste')) {
    $from = (string)($q['date_from'] ?? '');
    $to   = (string)($q['date_to'] ?? '');
    $fx   = mock_shop2_fixture();
    ok($from !== '' && $from === $to
        ? ($fx['waste_today'] ?? ['products' => []])
        : ($fx['waste_week'] ?? ['products' => []]));
}

// Le catalogue vendable de la boutique — releve reel (583 produits). Porte les
// drapeaux du pilotage : is_pdm, is_prepared_before_sales, tenue en vitrine
// (shelf_life_minutes) et parametres de recuisson.
if ($method === 'GET' && $m('/shops/\\d+/products/available')) {
    ok(mock_shop2_fixture()['products_available'] ?? []);
}

// Le poste d'un employe — GET /employees/{id}/positions (contrat confirme).
if ($method === 'GET' && $m('/employees/(\\d+)/positions', $vars)) {
    ok(mock_employee_positions((int)$vars[1]));
}

// La fiche technique, juste assez pour ouvrir l'ecran du produit.
if ($method === 'GET' && $m('/products/(\\d+)/technical-sheet/raw', $vars)) {
    ok(mock_technical_sheet((int)$vars[1]));
}

// ── Parcours de preparation ───────────────────────────────────────────────
// Les gestes definis par le reseau pour un produit : instruction, duree,
// groupe de batch, four, photos. Trois cas a pouvoir observer, parce qu'ils se
// ressemblent a l'ecran et n'appellent pas la meme reaction :
//   ?configured=0 — le produit n'a pas de parcours. Un fait, pas une panne.
//   ?dead=1       — la route ne repond pas. La PWA doit alors la NOMMER.
//   defaut        — un parcours complet, four et batch compris.
// Les produits qui ont un parcours : un appel pour tout le reseau. Releve
// reel — la Baguette Tradition 500 g. (1300003) est le produit test.
if ($method === 'GET' && $m('/preparation-paths/configured-product-ids')) {
    // Liste nue, comme l'API reelle la sert.
    ok(mock_shop2_fixture()['configured_product_ids'] ?? []);
}

if ($method === 'GET' && $m('/products/(\d+)/preparation-path', $vars)) {
    // MOCK_PREP se regle au lancement du bouchon, pour observer les trois cas
    // depuis la PWA elle-meme : elle appelle la route sans parametre, on ne
    // peut donc pas les declencher par l'URL.
    $forced = strtolower((string)getenv('MOCK_PREP'));
    if (!empty($q['dead']) || $forced === 'dead')       { http_response_code(503); exit; }
    if ($forced === 'off'
        || (isset($q['configured']) && !$q['configured'])) { ok(['configured' => false]); }
    ok(mock_preparation_path((int)$vars[1]));
}

// Qui travaille, un jour donne — et la SEULE source du personnel depuis le
// 13/08/2026. /franchisee-employees a ete retire : le planning porte les
// personnes, il n'y a plus rien a croiser.
if ($method === 'GET' && $m('/shops/\d+/schedule')) {
    // ?empty=1 : un planning servi mais qui ne designe personne. C'est le cas
    // qui vidait la liste et rendait toute la checklist invalidable.
    if (!empty($q['empty'])) { ok([]); }
    ok(mock_schedule($q['date'] ?? date('Y-m-d')));
}

// ── Checklists ────────────────────────────────────────────────────────────
// L'écran des tâches était le seul module que le bouchon ne servait pas : on ne
// pouvait donc ni le voir ni le vérifier sans la vraie API.
if ($method === 'GET' && $m('/consultant/shops/\d+/checklists$')) {
    ok(mock_checklists());
}

if ($method === 'GET' && $m('/consultant/shops/\d+/checklists/\d+/progress')) {
    preg_match('#/checklists/(\d+)/progress#', $path, $mm);
    ok(mock_checklist_progress((int) ($mm[1] ?? 1)));
}

// ── Cuisson ───────────────────────────────────────────────────────────────
if ($method === 'GET' && $m('/shops/\d+/ovens')) {
    ok(mock_ovens());
}

if ($method === 'GET' && $m('/shops/\d+/baking')) {
    $s = state();
    ok([
        'date'        => $s['date'],
        // L'heure du serveur fait foi côté front : une tablette d'atelier
        // dérive, et l'écran ne doit pas décréter un retard pour autant.
        'server_time' => date('H:i'),
        'batches'     => array_values($s['batches']),
    ]);
}

// Programmer une fournée depuis un manque constaté en production.
// C'est le serveur qui place la fournée : ici, au plus tôt — dans dix minutes,
// sur un four au hasard. Un vrai back-office tiendrait compte de l'occupation.
if ($method === 'POST' && $m('/shops/\d+/baking')) {
    $in  = body();
    $idp = (int)($in['id_product'] ?? 0);
    $qty = (float)($in['quantity'] ?? 0);
    if ($idp <= 0 || $qty <= 0) {
        ko('invalid_payload', 422);
    }

    $produit = null;
    foreach (mock_products() as $p) {
        if ((int)$p['id_product'] === $idp) {
            $produit = $p;
        }
    }
    if ($produit === null) {
        ko('product_not_found', 404);
    }

    $now       = now_minutes();
    $prepStart = $now + 10;
    $prep      = 15;
    $cook      = max(10, (int)($produit['production_lead_minutes'] ?? 20));
    $clock     = fn(int $m) => sprintf('%02d:%02d', intdiv(max(0, $m), 60) % 24, max(0, $m) % 60);
    $ovens     = mock_ovens();
    $four      = $ovens[$idp % count($ovens)];

    $batch = [
        'id'                       => random_int(7000, 7999),
        'id_product'               => $idp,
        'name'                     => $produit['name'],
        'category_name'            => $produit['category_name'],
        'quantity'                 => $qty,
        'unit_name'                => $produit['unit_name'] ?? 'pc',
        'id_oven'                  => $four['id'],
        'oven_name'                => $four['name'],
        'temperature'              => 180,
        'prep_start'               => $clock($prepStart),
        'prep_minutes'             => $prep,
        'cook_start'               => $clock($prepStart + $prep + 5),
        'cook_minutes'             => $cook,
        'finish_type'              => 'LOT',
        'finish_label'             => 'Refroidissement',
        'finish_minutes'           => 10,
        'finish_per_piece_minutes' => 0,
        'shelf_delay_minutes'      => 5,
        'status'                   => 'PLANNED',
        'source'                   => strtoupper((string)($in['source'] ?? 'SHORTFALL')),
        'prep_started_at'          => null,
        'cook_started_at'          => null,
        'finish_started_at'        => null,
    ];

    mutate(function (array $s) use ($batch) {
        $s['batches'][] = $batch;
        return $s;
    });

    // `inserted_id` en tête : ApiClient::post() écarte le corps et ne remonte
    // que message / inserted_id. Sans lui, l'écran ne saurait pas quelle
    // fournée mettre en avant à l'arrivée.
    ok(['inserted_id' => $batch['id'], 'message' => 'batch_planned'] + $batch);
}

if ($method === 'PATCH' && $m('/baking/(\d+)', $vars)) {
    $id     = (int)$vars[1];
    $in     = body();
    $status = strtoupper((string)($in['status'] ?? ''));

    $ordre = ['PLANNED' => 0, 'PREPARING' => 1, 'READY_TO_BAKE' => 2, 'BAKING' => 3, 'FINISHING' => 4, 'DONE' => 5];
    if (!isset($ordre[$status])) {
        ko('invalid_status', 422);
    }

    $found = null;
    $s = mutate(function (array $s) use ($id, $status, $ordre, &$found) {
        foreach ($s['batches'] as &$b) {
            if ((int)$b['id'] !== $id) {
                continue;
            }
            // Enfourner sans avoir préparé n'existe pas en atelier : le saut
            // d'étape est refusé ici comme il devra l'être côté back-office.
            if ($ordre[$status] !== ($ordre[$b['status']] ?? 0) + 1) {
                $found = ['__error' => 'invalid_transition'];
                return $s;
            }
            $b['status'] = $status;
            // Temps imparti corrigé à l'écran : on le persiste sur l'étape
            // concernée, pour que la frise et les échéances suivantes suivent.
            if (isset($in['allotted_minutes'])) {
                $field = match ($status) {
                    'BAKING'    => 'cook_minutes',
                    'FINISHING' => 'finish_minutes',
                    default     => 'prep_minutes',
                };
                $b[$field] = max(0, (int)$in['allotted_minutes']);
            }
            if (isset($in['id_employee'])) {
                $b['id_employee'] = (int)$in['id_employee'];
            }
            $stamp = ['PREPARING' => 'prep_started_at', 'BAKING' => 'cook_started_at', 'FINISHING' => 'finish_started_at'];
            if (isset($stamp[$status])) {
                $b[$stamp[$status]] = date('Y-m-d H:i:s');
            }
            $found = $b;
        }
        unset($b);
        return $s;
    });

    if ($found === null) {
        ko('batch_not_found', 404);
    }
    if (isset($found['__error'])) {
        ko($found['__error'], 409);
    }
    ok($found);
}

if ($method === 'GET' && $m('/shops/\d+/baking/pending-count')) {
    $s = state();
    $n = fn(string $st) => count(array_filter($s['batches'], fn($b) => $b['status'] === $st));
    ok(['preparing' => $n('PREPARING') + $n('READY_TO_BAKE'), 'baking' => $n('BAKING'), 'finishing' => $n('FINISHING')]);
}

// ── Remise à zéro, pour rejouer un scénario ───────────────────────────────
if ($method === 'POST' && $path === '/__reset') {
    @unlink(STATE_FILE);
    ok(['reset' => true]);
}

// ── Tout le reste ─────────────────────────────────────────────────────────
/**
 * Mode relais, activé par la variable d'environnement MOCK_API_PASSTHROUGH :
 *
 *     MOCK_API_PASSTHROUGH=https://atelierby.tfbuddy.com/api/v1 \
 *     php -S 127.0.0.1:8081 tools/mock-api/index.php
 *
 * Ce que le bouchon connaît, il le sert ; tout le reste part vers l'API
 * réelle. Sans lui, brancher l'app sur le bouchon ferait tomber en 404 le
 * tableau de bord, les checklists, les commandes et la base de connaissances —
 * on verrait les nouveaux modules au prix de tous les autres écrans.
 */
$upstream = getenv('MOCK_API_PASSTHROUGH') ?: '';
if ($upstream !== '') {
    $url = rtrim($upstream, '/') . $_SERVER['REQUEST_URI'];

    $headers = [];
    foreach (getallheaders() ?: [] as $k => $v) {
        // Host et Content-Length sont recalculés par curl ; les recopier
        // ferait échouer la requête.
        if (!in_array(strtolower($k), ['host', 'content-length'], true)) {
            $headers[] = "$k: $v";
        }
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
    ]);
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents('php://input') ?: '');
    }

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) {
        error_log("[mock-api] relais injoignable : {$method} {$path}");
        ko('upstream_unreachable', 502);
    }

    error_log("[mock-api] relayé ({$code}) : {$method} {$path}");
    http_response_code($code ?: 502);
    header('Content-Type: ' . ($type ?: 'application/json; charset=utf-8'));
    echo $body;
    exit;
}

// Sans relais, volontairement 404 : un endpoint absent doit se voir. Renvoyer
// une liste vide laisserait croire que la donnée manque alors que c'est la
// route.
error_log("[mock-api] non bouchonné : {$method} {$path}");
ko('not_mocked', 404);
