<?php

namespace App\Kitchen\app\Repositories\WebShop;

/**
 * Le client du webshop, côté serveur.
 *
 * Il ne passe PAS par ApiClient : celui-ci parle à l'ERP, avec sa base d'URL et
 * le jeton de session de l'utilisateur. Le webshop est un autre serveur, avec
 * une autre identité — les mélanger enverrait tôt ou tard le mauvais en-tête au
 * mauvais hôte.
 *
 * ── Pourquoi côté serveur et pas en JavaScript ──
 * Le navigateur pourrait appeler le webshop directement : les deux applications
 * sont servies par le même hôte, et le cookie du jeton partirait tout seul.
 * On préfère PHP pour trois raisons qui tiennent toutes à ce qu'on voit à
 * l'écran : le rendu est fait par Twig comme partout ailleurs dans Kitchen, les
 * pannes se disent en français dans la page plutôt qu'en console, et le jeton ne
 * quitte jamais le serveur — il est lu depuis un cookie HttpOnly et posé en
 * en-tête, sans jamais traverser du code client.
 *
 * ── Ce qu'il renvoie ──
 * Toujours un tableau ['ok' => bool, 'data' => mixed, 'error' => string|null].
 * Jamais de repli qui fabrique de la donnée : une panne réseau se dit
 * « réseau », un jeton révoqué se dit « révoqué », et une liste vide veut dire
 * qu'il n'y a rien — trois choses différentes que l'écran ne doit pas
 * confondre.
 */
class WebShopRepository
{
    public const OK      = 'ok';
    public const REVOKED = 'revoked';
    public const CLOSED  = 'closed';   // section non accordée à cette tablette
    public const NETWORK = 'network';

    /** Court : c'est un rendu d'écran, pas un traitement de fond. */
    private const TIMEOUT_S = 6;

    public function get(string $apiBase, string $token, string $path, array $query = []): array
    {
        return $this->call('GET', $apiBase, $token, $path, $query, null);
    }

    public function post(string $apiBase, string $token, string $path, array $body): array
    {
        return $this->call('POST', $apiBase, $token, $path, [], $body);
    }

    private function call(string $method, string $apiBase, string $token, string $path, array $query, ?array $body): array
    {
        if ($apiBase === '' || $token === '') {
            return ['ok' => false, 'state' => self::REVOKED, 'data' => null];
        }

        $url = rtrim($apiBase, '/') . $path . ($query ? '?' . http_build_query($query) : '');

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_S,
            // Le jeton voyage en en-tête, jamais en paramètre d'URL : une URL
            // se retrouve dans les journaux d'accès, dans le Referer et dans
            // l'historique du navigateur.
            CURLOPT_HTTPHEADER     => ['X-Device-Token: ' . $token, 'Accept: application/json'],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_errno($ch);
        curl_close($ch);

        // Un échec réseau n'est pas une révocation, et pas non plus une liste
        // vide. Les confondre enverrait reconfigurer une tablette au premier
        // hoquet de wifi.
        if ($err !== 0 || $raw === false || $code === 0) {
            return ['ok' => false, 'state' => self::NETWORK, 'data' => null];
        }

        $data = json_decode((string) $raw, true);

        if ($code === 401 || $code === 403) {
            // 403 recouvre deux choses très différentes : « ce jeton n'existe
            // plus » et « cet écran n'est pas ouvert à cette tablette ». Le
            // serveur nomme la section dans le second cas ; c'est ce qui permet
            // de dire « demandez l'écran à votre franchisé » au lieu de
            // « reconfigurez la tablette ».
            $section = is_array($data) ? ($data['section'] ?? null) : null;
            return [
                'ok'      => false,
                'state'   => $section !== null ? self::CLOSED : self::REVOKED,
                'section' => $section,
                'data'    => null,
            ];
        }

        if ($code < 200 || $code >= 300) {
            // 500, 502, maintenance : un incident du serveur, pas un verdict
            // sur le jeton. On ne déconfigure pas la tablette pour ça.
            return ['ok' => false, 'state' => self::NETWORK, 'data' => null];
        }

        return ['ok' => true, 'state' => self::OK, 'data' => $data];
    }
}
