<?php
/**
 * Vérifie DeviceModeService sans serveur, sans cookie, sans navigateur.
 *
 *     php bin/mode-test.php
 *
 * Le mode décide de ce que la tablette affiche : une règle fausse ici ne se
 * voit pas à l'écran, elle se voit par une section manquante que personne ne
 * pense à réclamer. Les trois choses à tenir sont le défaut (production, pour
 * ne rien changer aux tablettes en service), la robustesse aux valeurs
 * inconnues (jamais de menu vide), et le refus d'ouvrir un WebShop
 * partiellement configuré.
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Kitchen\app\Services\Me\DeviceModeService;

$ok = 0;
$ko = [];
function check(string $what, $got, $want): void
{
    global $ok, $ko;
    if ($got === $want) { $ok++; return; }
    $ko[] = sprintf("  ✗ %s\n      attendu : %s\n      obtenu  : %s",
        $what, json_encode($want, JSON_UNESCAPED_UNICODE), json_encode($got, JSON_UNESCAPED_UNICODE));
}

$m = new DeviceModeService();

// ── Le défaut ─────────────────────────────────────────────────────────────
// C'est la garantie du brief : une tablette déjà en service ne change pas de
// comportement au déploiement.
check('trois modes',                   $m->modes(), ['gestion', 'production', 'webshop']);
check('défaut = production',           DeviceModeService::DEFAULT_MODE, 'production');
check('absent → production',           $m->normalise(null), 'production');
check('vide → production',             $m->normalise(''), 'production');
check('inconnu → production',          $m->normalise('vente'), 'production');
check('casse ignorée',                 $m->normalise('WebShop'), 'webshop');
check('espaces ignorés',               $m->normalise('  gestion '), 'gestion');

// ── Ce que chaque mode montre ─────────────────────────────────────────────
// Depuis le 13/08/2026, cela vient de la table pwa_kitchen_param et de rien
// d'autre. Un service qui n'a pas reçu de configuration ne montre RIEN et
// nomme la route : c'est le point de la révision — pendant que le back se
// construit, un menu codé en dur masque exactement ce qu'on cherche à voir.
check('sans config : menu vide',       $m->navKeys('production'), []);
check('sans config : onglets vides',   $m->tabKeys('production'), []);
check('sans config : la route nommée', $m->missingApi(), 'GET /devices/{id}/configuration');

// DEFAULT_NAV / DEFAULT_TABS ne sont plus un repli : c'est la référence, le
// contenu que la table doit porter pour reproduire l'affichage historique. On
// vérifie donc qu'appliquée, elle donne bien cet affichage.
$ref = new DeviceModeService();
$ref->applyConfig(['modes' => [
    'production' => ['nav' => DeviceModeService::DEFAULT_NAV['production'],
                     'tabs' => DeviceModeService::DEFAULT_TABS['production']],
    'gestion'    => ['nav' => DeviceModeService::DEFAULT_NAV['gestion'],
                     'tabs' => DeviceModeService::DEFAULT_TABS['gestion']],
    'webshop'    => ['nav' => DeviceModeService::DEFAULT_NAV['webshop'],
                     'tabs' => DeviceModeService::DEFAULT_TABS['webshop']],
]]);

check('référence : menu production',   $ref->navKeys('production'),
    ['dashboard', 'production', 'checklists', 'orders', 'knowledge', 'complaints']);
check('référence : onglets production', $ref->tabKeys('production'),
    ['dashboard', 'production', 'checklists', 'orders']);
// « objectives » a quitté les menus : l'entrée était disabled avec href="#" et
// n'ouvrait rien. La clé n'est même plus connue de l'application.
check('objectives n\'est plus une clé',
    in_array('objectives', DeviceModeService::KNOWN_NAV, true), false);

check('gestion : pas de production',   $ref->allows('gestion', 'production'), false);
check('gestion : pas de commandes',    $ref->allows('gestion', 'orders'), false);
check('gestion : checklists',          $ref->allows('gestion', 'checklists'), true);
check('gestion : connaissances',       $ref->allows('gestion', 'knowledge'), true);
check('gestion : réclamations',        $ref->allows('gestion', 'complaints'), true);
check('gestion : tableau de bord',     $ref->allows('gestion', 'dashboard'), true);
check('gestion : pas de webshop',      $ref->allows('gestion', 'webshop'), false);

check('webshop : le webshop seul',     $ref->navKeys('webshop'), ['webshop']);
check('webshop : pas de production',   $ref->allows('webshop', 'production'), false);
check('webshop : pas de dashboard',    $ref->allows('webshop', 'dashboard'), false);

// Un mode forgé retombe sur le mode par défaut — le MODE, pas le menu : celui
// de production tel que la table le décrit.
check('mode forgé → menu du défaut',   $ref->navKeys('n/importe quoi'), $ref->navKeys('production'));
check('mode forgé → onglets du défaut', $ref->tabKeys(null), $ref->tabKeys('production'));

// ── Où l'on atterrit ──────────────────────────────────────────────────────
check('accueil production',            $m->home('production'), '/dashboard');
check('accueil gestion',               $m->home('gestion'), '/dashboard');
check('accueil webshop',               $m->home('webshop'), '/webshop');
check('accueil mode inconnu',          $m->home('zzz'), '/dashboard');

// ── WebShop : ouvrir, ou dire pourquoi on n'ouvre pas ─────────────────────
// Depuis la révision du 2 août 2026 : plus de connexion personnelle, plus d'id
// de boutique à saisir. Deux réglages, une URL et un jeton d'appareil — et
// c'est le jeton qui porte la boutique.
$url = 'https://exemple.tld/webshop/backoffice_franchisee/?shop=2';
$tok = str_repeat('a1b2', 16);   // 64 caractères, comme les vrais

check('URL + jeton',                   $m->webshopUrl($url, $tok), $url);
check('tout est là : aucune raison',   $m->webshopBlocker($url, $tok), null);
check('sans URL',                      $m->webshopUrl('', $tok), null);
check('sans URL : raison',             $m->webshopBlocker('', $tok), 'no_url');
check('sans jeton',                    $m->webshopUrl($url, ''), null);
check('sans jeton : raison',           $m->webshopBlocker($url, ''), 'no_token');
check('sans jeton : null aussi',       $m->webshopBlocker($url, null), 'no_token');

// L'URL est prise telle quelle : c'est le jeton qui impose la boutique, et le
// serveur ignore ?shop=. Y ajouter quoi que ce soit laisserait croire que la
// tablette choisit son magasin.
check("l'URL n'est pas complétée",     $m->webshopUrl('https://x.tld/bo', $tok), 'https://x.tld/bo');
check('espaces rognés',                $m->webshopUrl('  https://x.tld/bo  ', $tok), 'https://x.tld/bo');

// Le champ finit en src d'une iframe : tout ce qui n'est pas http(s) est
// refusé, y compris ce qui ressemble à une URL.
check('javascript: refusé',            $m->webshopBlocker('javascript:alert(1)', $tok), 'bad_url');
check('data: refusé',                  $m->webshopBlocker('data:text/html,<b>x', $tok), 'bad_url');
check('sans schéma refusé',            $m->webshopBlocker('exemple.tld/bo', $tok), 'bad_url');
check('http accepté',                  $m->webshopBlocker('http://exemple.tld/bo', $tok), null);
check('majuscules du schéma',          $m->webshopBlocker('HTTPS://exemple.tld/bo', $tok), null);

// La faute qu'on attrape sur le jeton, c'est le mauvais champ ou le
// copier-coller approximatif — pas le mauvais jeton, que seul le serveur sait.
check('URL collée dans le jeton',      $m->webshopBlocker($url, 'https://exemple.tld/bo'), 'bad_token');
check('jeton trop court',              $m->webshopBlocker($url, 'abc123'), 'bad_token');
check('espace au milieu',              $m->webshopBlocker($url, str_repeat('ab', 8) . ' x'), 'bad_token');
check('espaces autour : tolérés',      $m->webshopBlocker($url, '  ' . $tok . '  '), null);
check('jeton majuscules',              $m->webshopBlocker($url, strtoupper($tok)), null);

// ── La base d'API, déduite de l'URL ───────────────────────────────────────
// Un seul champ pour une seule information : deux champs à saisir seraient
// deux occasions de les rendre incohérents.
check('api déduite',                   $m->webshopApiBase($url), 'https://exemple.tld/webshop/api');
check('api : le port suit',            $m->webshopApiBase('http://127.0.0.1:8080/webshop/backoffice_franchisee/'),
    'http://127.0.0.1:8080/webshop/api');
check('api : le chemin saute',         $m->webshopApiBase('https://x.tld/a/b/c?d=e'), 'https://x.tld/webshop/api');
check('api sans URL',                  $m->webshopApiBase(''), null);
check('api sur URL malformée',         $m->webshopApiBase('exemple.tld'), null);

// ── Le jeton ne s'affiche pas ─────────────────────────────────────────────
// Une tablette de comptoir reste allumée devant tout le monde : la page dit
// lequel est en place, jamais lequel c'est.
check('indice : quatre caractères',    $m->tokenHint('0123456789abcdef'), '…cdef');
check('indice : rien sans jeton',      $m->tokenHint(''), null);
check('indice : rien sur null',        $m->tokenHint(null), null);

// ── La configuration servie par pwa_kitchen_param ───────────────────────────
// Revision du 13/08/2026 : plus de repli. Sans configuration exploitable, les
// menus sont VIDES et l'ecran nomme la route a creer. Un menu code en dur qui
// prend la place ferait croire que tout va bien, et le manque ne se verrait
// qu'au moment de s'en servir.

$vide = array_fill_keys(array_keys(DeviceModeService::DEFAULT_NAV), []);

check('config nulle → vide',           DeviceModeService::sanitise(null)['nav'], $vide);
check('config nulle → pas ok',         DeviceModeService::sanitise(null)['ok'], false);
check('config vide → vide',            DeviceModeService::sanitise([])['nav'], $vide);
check('« modes » absent',              DeviceModeService::sanitise(['autre' => 1])['ok'], false);
check('« modes » non tableau',         DeviceModeService::sanitise(['modes' => 'x'])['ok'], false);
check('« modes » vide',                DeviceModeService::sanitise(['modes' => []])['ok'], false);
// Une reponse qui ne decrit aucun mode connu n'est pas une configuration.
check('que des modes inconnus',        DeviceModeService::sanitise(['modes' => [
    'comptoir' => ['nav' => ['dashboard']],
]])['ok'], false);

// Servie : c'est elle qui decide, entierement.
$c = DeviceModeService::sanitise(['modes' => [
    'production' => ['nav' => ['dashboard', 'checklists'], 'tabs' => ['dashboard']],
]]);
check('servie : le menu vient d\'elle', $c['nav']['production'], ['dashboard', 'checklists']);
check('servie : ok',                    $c['ok'], true);
// Un mode que la table ne decrit pas reste vide : c'est une information, pas
// une occasion de remettre un defaut.
check('mode non decrit → vide',         $c['nav']['gestion'], []);
check('onglets non decrits → vide',     $c['tabs']['gestion'], []);

// L'ordre servi est l'ordre affiche : c'est sort_order qui le porte.
check('ordre respecté',                 DeviceModeService::sanitise(['modes' => [
    'gestion' => ['nav' => ['complaints', 'dashboard', 'knowledge']],
]])['nav']['gestion'], ['complaints', 'dashboard', 'knowledge']);

// ── Ce qu'on ecarte : des validations, pas des replis ──────────────────────
// Une fonctionnalite que l'application ne sait pas rendre ajouterait une entree
// de menu qui n'ouvre rien.
check('feature inconnue écartée',       DeviceModeService::sanitise(['modes' => [
    'gestion' => ['nav' => ['dashboard', 'facturation', 'checklists']],
]])['nav']['gestion'], ['dashboard', 'checklists']);

check('doublons écartés',               DeviceModeService::sanitise(['modes' => [
    'gestion' => ['nav' => ['dashboard', 'dashboard', 'checklists']],
]])['nav']['gestion'], ['dashboard', 'checklists']);

check('casse et espaces',               DeviceModeService::sanitise(['modes' => [
    'GESTION' => ['nav' => [' Dashboard ', 'CHECKLISTS']],
]])['nav']['gestion'], ['dashboard', 'checklists']);

check('valeurs non textuelles',         DeviceModeService::sanitise(['modes' => [
    'gestion' => ['nav' => [['x'], null, true, 'dashboard']],
]])['nav']['gestion'], ['dashboard']);

// La barre du bas tient quatre onglets : au-dela, les suivants disparaitraient
// sans un mot.
check('cinq onglets → quatre',          DeviceModeService::sanitise(['modes' => [
    'production' => ['tabs' => ['dashboard', 'production', 'checklists', 'orders', 'knowledge']],
]])['tabs']['production'], ['dashboard', 'production', 'checklists', 'orders']);

// Les vues internes du WebShop n'existent qu'en bas.
check('ws_* accepté en onglet',         DeviceModeService::sanitise(['modes' => [
    'webshop' => ['tabs' => ['ws_stock', 'ws_prep']],
]])['tabs']['webshop'], ['ws_stock', 'ws_prep']);
check('ws_* refusé au menu',            DeviceModeService::sanitise(['modes' => [
    'webshop' => ['nav' => ['ws_stock']],
]])['nav']['webshop'], []);

// ── Et une fois appliquee ──────────────────────────────────────────────────
$m2 = new DeviceModeService();
$m2->applyConfig(['modes' => ['production' => [
    'nav'  => ['dashboard', 'checklists'],
    'tabs' => ['dashboard', 'checklists'],
]]]);
check('appliquée : menu',               $m2->navKeys('production'), ['dashboard', 'checklists']);
check('appliquée : onglets',            $m2->tabKeys('production'), ['dashboard', 'checklists']);
check('appliquée : allows suit',        $m2->allows('production', 'orders'), false);
check('appliquée : rien à signaler',    $m2->missingApi(), null);

// Sans configuration : aucun menu, et la route est nommee. C'est LA regle de
// cette revision — l'ecran doit dire ce qui manque, pas faire semblant.
$m3 = new DeviceModeService();
$m3->applyConfig(null);
check('sans config : menu vide',        $m3->navKeys('production'), []);
check('sans config : onglets vides',    $m3->tabKeys('production'), []);
check('sans config : rien n\'est permis', $m3->allows('production', 'orders'), false);
check('sans config : la route nommée',  $m3->missingApi(), 'GET /devices/{id}/configuration');
// Jamais appelee du tout : meme verdict qu'une config absente.
check('jamais appliquée : nommée',      (new DeviceModeService())->missingApi(), 'GET /devices/{id}/configuration');

// ── Verdict ───────────────────────────────────────────────────────────────
echo $ko === []
    ? "✓ {$ok} vérifications passées\n"
    : sprintf("%d passées, %d échouées :\n%s\n", $ok, count($ko), implode("\n", $ko));
exit($ko === [] ? 0 : 1);
