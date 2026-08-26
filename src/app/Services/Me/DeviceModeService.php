<?php

namespace App\Kitchen\app\Services\Me;

/**
 * Le mode de la tablette : ce qu'elle sert, et donc ce qu'elle montre.
 *
 * Une même application tourne sur trois postes qui n'ont pas le même métier :
 * le fournil produit, le bureau contrôle, le comptoir vend. Leur donner à
 * tous les sept menus, c'est demander à chacun d'ignorer les cinq qui ne le
 * concernent pas, à chaque écran, toute la journée.
 *
 * Le mode est un réglage de l'APPAREIL, pas du compte : la tablette du fournil
 * reste en Production même quand le responsable s'y connecte. Deux comptes qui
 * partagent une tablette partagent son mode ; un compte qui passe d'une
 * tablette à l'autre change de mode. C'est voulu.
 *
 * Cette classe ne connaît ni cookie, ni requête, ni horloge : elle dit
 * seulement quelles règles s'appliquent, ce qui la rend vérifiable par
 * bin/mode-test.php. La persistance vit dans core/Support/DeviceMode.
 *
 * Contrat d'intégration : docs/MODE_TABLETTE.md
 */
class DeviceModeService
{
    public const MODE_GESTION    = 'gestion';
    public const MODE_PRODUCTION = 'production';
    public const MODE_WEBSHOP    = 'webshop';

    /**
     * Production par défaut, et ce défaut n'est pas neutre : les tablettes déjà
     * en service tournent en production. Tout autre défaut changerait ce
     * qu'elles affichent au premier déploiement, sans que personne l'ait
     * demandé.
     */
    public const DEFAULT_MODE = self::MODE_PRODUCTION;

    /**
     * Ce que chaque mode affichait avant que la table `pwa_kitchen_param`
     * n'existe.
     *
     * **Ce n'est plus un repli.** Depuis le 13/08/2026, la configuration vient
     * de l'API et d'elle seule : si elle ne répond pas, l'écran le DIT et nomme
     * l'endpoint à créer, au lieu de servir ces valeurs en faisant croire que
     * tout va bien. Un repli silencieux masquait exactement ce qu'on cherche à
     * voir pendant que le back se construit.
     *
     * Elles restent ici comme référence : c'est le contenu que la table doit
     * porter pour reproduire l'affichage historique.
     */
    public const DEFAULT_NAV = [
        self::MODE_PRODUCTION => ['dashboard', 'production', 'checklists', 'orders', 'knowledge', 'complaints'],
        self::MODE_GESTION    => ['dashboard', 'checklists', 'knowledge', 'complaints'],
        self::MODE_WEBSHOP    => ['webshop'],
    ];

    /**
     * La barre du bas n'est pas le menu : elle porte les gestes du quotidien,
     * pas l'inventaire des sections. Quatre au plus, le profil en cinquième
     * étant ajouté par la coque.
     */
    public const DEFAULT_TABS = [
        self::MODE_PRODUCTION => ['dashboard', 'production', 'checklists', 'orders'],
        self::MODE_GESTION    => ['dashboard', 'checklists', 'knowledge', 'complaints'],
        self::MODE_WEBSHOP    => ['ws_prep', 'ws_stock', 'ws_board'],
    ];

    /**
     * Les fonctionnalités que l'application sait rendre.
     *
     * C'est une liste FERMÉE, et c'est le point important : la table
     * `pwa_kitchen_param` décide de ce qui est montré, pas de ce qui existe.
     * Une ligne y désignant « facturation » n'ouvrirait pas un écran de
     * facturation — elle ajouterait une entrée de menu qui ne mène nulle part,
     * exactement le défaut qu'on a retiré du menu en août. Ce qui n'est pas
     * dans cette liste est donc ignoré, en silence côté écran et signalé côté
     * log.
     *
     * Y ajouter une clé demande d'abord d'écrire l'écran, sa route et son
     * entrée dans components/app_nav.twig.
     */
    public const KNOWN_NAV = [
        'dashboard', 'production', 'checklists', 'orders', 'knowledge', 'complaints', 'webshop',
    ];

    /** Les onglets du bas : les clés de menu, plus les trois vues WebShop. */
    public const KNOWN_TABS = [
        'dashboard', 'production', 'checklists', 'orders', 'knowledge', 'complaints', 'webshop',
        'ws_prep', 'ws_stock', 'ws_board',
    ];

    /** La barre du bas tient quatre onglets, le profil étant ajouté par la coque. */
    public const MAX_TABS = 4;

    /**
     * Les cartes effectives, telles que `GET /devices/{id}/configuration` les a servies.
     *
     * @var array{nav: array<string, string[]>, tabs: array<string, string[]>, ok: bool}|null
     */
    private ?array $maps = null;

    /**
     * Brancher la configuration distante.
     *
     * Appelée une fois par requête, depuis core/Support/DeviceMode. Sans
     * configuration exploitable, les menus restent VIDES et `missingApi()`
     * nomme l'endpoint : l'écran affiche alors ce qui manque, plutôt que de
     * servir un menu codé en dur qui ferait croire que tout va bien.
     */
    public function applyConfig(?array $config): void
    {
        $this->maps = self::sanitise($config);
    }

    /**
     * L'endpoint à créer, ou null si la configuration est arrivée.
     *
     * L'écran l'affiche tel quel. Nommer la route évite l'aller-retour
     * « ça ne marche pas » → « qu'est-ce qui ne marche pas ».
     */
    public function missingApi(): ?string
    {
        return ($this->maps['ok'] ?? false)
            ? null
            : \App\Kitchen\app\Repositories\Me\DeviceConfigRepository::ROUTE;
    }

    /**
     * Transforme la configuration servie en cartes utilisables.
     *
     * Pure : c'est elle qu'on vérifie dans bin/mode-test.php.
     *
     * ── Rien n'est inventé ──
     * Sans configuration exploitable, elle rend des cartes VIDES et `ok` à
     * false. L'appelant en tire un message qui nomme l'endpoint à créer. C'est
     * le point de la révision du 13/08/2026 : pendant que le back se construit,
     * un repli silencieux masque précisément ce qu'on cherche à voir.
     *
     * ── Ce qui est écarté, et pourquoi ──
     * Ce ne sont pas des replis mais des validations — on n'ajoute rien, on
     * refuse ce qui ne veut rien dire :
     * • une fonctionnalité inconnue de l'application (voir KNOWN_NAV) : elle
     *   ajouterait une entrée de menu qui n'ouvre aucun écran ;
     * • un mode inconnu : il n'a ni accueil ni vues ;
     * • au-delà de quatre onglets : la barre n'en affiche pas plus, et les
     *   suivants disparaîtraient sans un mot.
     *
     * Un mode absent de la configuration reste vide : c'est une information —
     * la table ne le décrit pas — et l'écran la donne telle quelle.
     *
     * @param array|null $config  ['modes' => ['production' => ['nav' => [...], 'tabs' => [...]], …]]
     * @return array{nav: array<string, string[]>, tabs: array<string, string[]>, ok: bool}
     */
    public static function sanitise(?array $config): array
    {
        $vide = array_fill_keys(array_keys(self::DEFAULT_NAV), []);
        $nav  = $vide;
        $tabs = $vide;

        $modes = $config['modes'] ?? null;
        if (!is_array($modes) || $modes === []) {
            return ['nav' => $nav, 'tabs' => $tabs, 'ok' => false];
        }

        foreach ($modes as $mode => $spec) {
            $mode = strtolower(trim((string)$mode));
            if (!array_key_exists($mode, self::DEFAULT_NAV) || !is_array($spec)) {
                continue;
            }

            if (isset($spec['nav']) && is_array($spec['nav'])) {
                $nav[$mode] = self::keepKnown($spec['nav'], self::KNOWN_NAV);
            }
            if (isset($spec['tabs']) && is_array($spec['tabs'])) {
                $tabs[$mode] = array_slice(
                    self::keepKnown($spec['tabs'], self::KNOWN_TABS), 0, self::MAX_TABS
                );
            }
        }

        // Une réponse qui ne décrit aucun mode connu n'est pas une
        // configuration : on la traite comme une absence, et on le dit.
        $ok = $nav !== $vide || $tabs !== $vide;

        return ['nav' => $nav, 'tabs' => $tabs, 'ok' => $ok];
    }

    /**
     * Ne garde que les clés que l'application sait rendre, dans l'ordre servi,
     * sans doublon.
     *
     * @param array<int, mixed> $keys
     * @param string[] $known
     * @return string[]
     */
    private static function keepKnown(array $keys, array $known): array
    {
        $out = [];
        foreach ($keys as $k) {
            if (!is_string($k) && !is_int($k)) {
                continue;
            }
            $k = strtolower(trim((string)$k));
            if ($k !== '' && in_array($k, $known, true) && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /** @return string[] */
    public function modes(): array
    {
        return [self::MODE_GESTION, self::MODE_PRODUCTION, self::MODE_WEBSHOP];
    }

    /**
     * Une valeur inconnue — cookie tronqué, mode retiré d'une version
     * ultérieure, valeur forgée à la main — retombe sur le défaut plutôt que
     * de vider la navigation. Une tablette sans menu est irrécupérable au
     * doigt ; une tablette en production ne l'est jamais.
     */
    public function normalise(?string $raw): string
    {
        $raw = strtolower(trim((string)$raw));

        return in_array($raw, $this->modes(), true) ? $raw : self::DEFAULT_MODE;
    }

    /** @return string[] clés des sections visibles dans le menu */
    public function navKeys(?string $mode): array
    {
        return ($this->maps['nav'] ?? array_fill_keys(array_keys(self::DEFAULT_NAV), []))[$this->normalise($mode)];
    }

    /** @return string[] clés des onglets du bas, profil non compris */
    public function tabKeys(?string $mode): array
    {
        return ($this->maps['tabs'] ?? array_fill_keys(array_keys(self::DEFAULT_TABS), []))[$this->normalise($mode)];
    }

    public function allows(?string $mode, string $navKey): bool
    {
        return in_array($navKey, $this->navKeys($mode), true);
    }

    /**
     * Où atterrit la tablette : après connexion, après un changement de mode,
     * et derrière le logo. Un mode qui laisse l'utilisateur sur un écran qu'il
     * ne peut plus atteindre par le menu serait un cul-de-sac.
     */
    public function home(?string $mode): string
    {
        return $this->normalise($mode) === self::MODE_WEBSHOP ? '/webshop' : '/dashboard';
    }

    /**
     * Pourquoi le mode WebShop ne peut pas s'ouvrir, ou null s'il le peut.
     *
     * On distingue les causes parce qu'elles n'appellent pas la même
     * réparation : une URL absente et un jeton absent se règlent tous deux dans
     * les paramètres, mais pas au même champ, et un jeton mal collé — l'URL
     * dans le mauvais champ, un espace au bout — ne se voit pas si on répond
     * « non configuré ».
     *
     * @return string|null 'no_url' | 'bad_url' | 'no_token' | 'bad_token'
     */
    public function webshopBlocker(?string $base, ?string $token): ?string
    {
        $base  = trim((string)$base);
        $token = trim((string)$token);

        if ($base === '') {
            return 'no_url';
        }
        // Le champ est saisi à la main sur la tablette et finit en src d'une
        // iframe : tout ce qui n'est pas http(s) — javascript:, data: — est
        // refusé ici, à l'écriture comme à la lecture.
        if (!preg_match('#^https?://#i', $base)) {
            return 'bad_url';
        }
        if ($token === '') {
            return 'no_token';
        }
        // On ne vérifie pas que le jeton est LE bon — seul le serveur le sait,
        // et c'est son 403 qui fait foi. On vérifie qu'il a la forme d'un
        // jeton : la faute qu'on attrape ici, c'est l'URL collée dans le champ
        // du jeton, ou un espace ramassé au passage. Volontairement large : un
        // format plus strict enfermerait la tablette le jour où le webshop
        // change la longueur de ses jetons.
        if (!preg_match('#^[A-Za-z0-9_.:-]{16,256}$#', $token)) {
            return 'bad_token';
        }

        return null;
    }

    /**
     * L'URL du back-office, ou null si un réglage manque.
     *
     * Elle est prise telle qu'elle a été configurée : depuis la révision du
     * 2 août 2026, c'est le jeton qui impose la boutique, et le serveur
     * **ignore** `?shop=`. Kitchen n'a donc plus rien à y ajouter — l'ajouter
     * quand même laisserait croire que la tablette choisit son magasin.
     */
    public function webshopUrl(?string $base, ?string $token): ?string
    {
        if ($this->webshopBlocker($base, $token) !== null) {
            return null;
        }

        return trim((string)$base);
    }

    /**
     * La base d'API du webshop, déduite de l'URL du back-office : le brief la
     * définit comme « l'origine du webshop » + /webshop/api. On la déduit
     * plutôt que de la faire saisir — deux champs pour une seule information,
     * c'est un champ de trop et une occasion de les rendre incohérents.
     *
     * Sert au seul appel que Kitchen passe lui-même : la vérification du jeton
     * au démarrage (GET /franchisee/me).
     */
    public function webshopApiBase(?string $base): ?string
    {
        $base = trim((string)$base);
        if ($base === '' || !preg_match('#^https?://#i', $base)) {
            return null;
        }

        $parts = parse_url($base);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . '/webshop/api';
    }

    /**
     * Les quatre derniers caractères d'un jeton, pour dire « c'est bien
     * celui-là » sans l'écrire. Le jeton est un secret : il ne doit apparaître
     * ni dans un log, ni dans une URL, ni dans le HTML d'une page laissée
     * ouverte sur un comptoir.
     */
    public function tokenHint(?string $token): ?string
    {
        $token = trim((string)$token);

        return $token === '' ? null : '…' . substr($token, -4);
    }
}
