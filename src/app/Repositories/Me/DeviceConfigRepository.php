<?php

namespace App\Kitchen\app\Repositories\Me;

use App\Kitchen\core\Http\ApiClient;
use App\Kitchen\core\Support\GlobalRegistry;

/**
 * Ce que la tablette a le droit d'afficher, par mode.
 *
 * Alimenté par la table `pwa_kitchen_param` côté ERP : une ligne par couple
 * (mode, fonctionnalité), avec son état et sa place. `GET /devices/{id}/configuration` assemble ces
 * lignes en une réponse que la PWA lit telle quelle — voir
 * docs/BACKEND_A_FAIRE.md §8. L'identifiant de l'appareil vient du jeton de
 * session (revendication `device_id`), pas d'un appel de plus.
 *
 * ── Pourquoi une table plutôt qu'une constante ──
 * Ce que montre chaque mode se décidait dans le code, donc changeait par un
 * déploiement. Ouvrir les réclamations au comptoir demandait un commit, une
 * revue et une mise en production, pour un choix qui appartient au franchisé.
 * La table déplace ce choix là où il se prend.
 *
 * ── Ce que ça ne fait pas ──
 * Ce n'est pas un contrôle d'accès. Retirer une entrée retire un chemin
 * d'accès, pas le droit d'y aller : les routes restent servies, et un mode
 * forgé n'ouvre rien de plus. La sécurité tient au jeton de session, comme
 * avant. C'est un réglage d'ergonomie, et il ne faut pas le vendre pour autre
 * chose.
 */
class DeviceConfigRepository
{
    public function __construct(
        private ApiClient $apiClient
    ) {}

    /** La route, telle que l'écran la nomme quand elle ne répond pas. */
    public const ROUTE = 'GET /devices/{id}/configuration';

    /**
     * L'appareil courant, lu dans le jeton de session.
     *
     * `device_id` est une revendication du jeton émis par l'ERP — le
     * middleware d'authentification la lit déjà (AuthMiddleware). On ne la
     * redemande donc pas au réseau : elle est là, à chaque requête.
     */
    private function deviceId(): int
    {
        return (int)(GlobalRegistry::get('user')['device_id'] ?? 0);
    }

    /**
     * @return array|null null = configuration non servie. Les menus restent
     *                    alors VIDES et l'écran nomme la route : voir
     *                    DeviceModeService::sanitise(). On ne sert pas un menu
     *                    codé en dur qui ferait croire que tout va bien.
     */
    public function get(): ?array
    {
        $id = $this->deviceId();
        if ($id <= 0) {
            // Sans appareil identifié, la question n'a pas de réponse. Ce
            // n'est pas une route en panne : c'est un appel qui ne la pose
            // pas — mais l'écran doit quand même rester vide plutôt que de
            // supposer un mode.
            return null;
        }

        $res = $this->apiClient->get("/devices/{$id}/configuration");
        if (!($res['success'] ?? false) || !is_array($res['data'] ?? null)) {
            return null;
        }

        $data = $res['data'];

        // La réponse peut envelopper la configuration une fois de plus. On ne
        // devine pas au-delà : un emballage inconnu rend null, et l'écran le dit.
        if (!isset($data['modes']) && isset($data['configuration']['modes'])) {
            $data = $data['configuration'];
        } elseif (!isset($data['modes']) && isset($data['data']['modes'])) {
            $data = $data['data'];
        }

        return $data;
    }
}
