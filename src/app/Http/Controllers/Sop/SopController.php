<?php

namespace App\Kitchen\app\Http\Controllers\Sop;

use App\Kitchen\app\Http\Controllers\Controller;
use App\Kitchen\core\Support\GlobalRegistry;

/**
 * L'onglet « SOP » : les standards du réseau (SOP), en français et en néerlandais, lus pas à pas sur la tablette du
 * poste. Ils vivent dans le module Formation du cockpit, section Standards (SOP) (/consulant_bo/formation/sop/tablette.html) : cet onglet y mène
 * avec le magasin de l'appareil, le poste de cuisson et la langue de l'appareil. On s'y identifie comme pour les
 * checklists (son nom, son PIN) et la responsable y valide « vu faire ».
 *
 * Adresse de la tablette : variable d'environnement FORMATION_URL ; par défaut, le cockpit du même serveur.
 */
class SopController extends Controller
{
    public function __construct() {}

    public function index(): void
    {
        $user = GlobalRegistry::get('user') ?? [];
        $shop = (int)($user['shop_id'] ?? 0);
        $lang = strtolower(substr((string)($user['lang_code'] ?? ''), 0, 2));
        $poste = in_array($_GET['poste'] ?? '', ['cuisson', 'production', 'vente', 'nettoyage'], true) ? $_GET['poste'] : 'cuisson';
        $query = ['poste' => $poste];
        if ($shop > 0) {
            $query['shop'] = $shop;
        }
        if (in_array($lang, ['fr', 'nl'], true)) {
            $query['langue'] = $lang;
        }
        header('Location: ' . self::url() . '?' . http_build_query($query), true, 302);
        exit;
    }

    /** L'adresse de la tablette Formation : FORMATION_URL, sinon le cockpit du même serveur. */
    private static function url(): string
    {
        $env = trim((string)($_ENV['FORMATION_URL'] ?? getenv('FORMATION_URL') ?: ''));
        if ($env !== '') {
            return $env;
        }
        $https = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '/consulant_bo/formation/sop/tablette.html';
    }
}
