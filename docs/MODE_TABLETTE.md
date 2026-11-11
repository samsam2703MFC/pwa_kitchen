# Mode de la tablette — Gestion · Production · WebShop

Une même application tourne sur trois postes qui n'ont pas le même métier : le
fournil produit, le bureau contrôle, le comptoir vend. Le **mode** dit à quoi
sert un appareil, et donc ce qu'il affiche.

> Contrat d'origine : `INTEGRATION_KITCHEN_WEBSHOP.md` (dépôt
> `samsam2703MFC/WebShop`), **révision du 2 août 2026** — le mode PIN a été
> retiré, la tablette s'identifie par un jeton d'appareil. Ce document dit ce
> qui a été fait, ce qui a été fait autrement, et pourquoi.

---

## 1. Ce que chaque mode montre

| Mode | Menu | Barre du bas |
|---|---|---|
| **Gestion** | Tableau de bord, Checklists, Base de connaissances, Réclamations | Accueil, Checklists, Connaissances, Réclamations |
| **Production** *(défaut)* | Tableau de bord, Production, Checklists, Commandes, Base de connaissances, Réclamations | Accueil, Production, Checklists, Commandes |
| **WebShop** | WebShop | Préparation, Stock du jour, Tableau de bord |

Ce tableau est une **référence**, pas un défaut : c'est le contenu que la table
`pwa_kitchen_param` doit porter pour reproduire l'affichage historique. Depuis
le 13/08/2026, l'écran ne montre QUE ce que la table décrit — si elle ne répond
pas, il n'y a aucun menu et un bandeau nomme la route à créer. Voir §2.

« Objectifs & Primes » a quitté les deux menus qui le portaient : l'entrée était
grisée et n'ouvrait rien. Une section se déclare le jour où son écran existe.

Le **Profil** est présent dans les trois modes, dans la barre du bas et en bas du
menu. Ce n'est pas une exception décorative : c'est par lui qu'on revient aux
réglages. Une tablette dont on ne peut plus changer le mode serait à
réinstaller.

**Production est le défaut, et il ne change rien.** Les tablettes déjà en
service tournent en production : leur navigation après ce déploiement est
exactement celle d'avant. C'est la seule garantie qui compte au moment de
livrer.

### Deux libellés du brief sans module dédié

Le brief range dans Gestion « contrôle qualité » et « KPI ». Aucun des deux
n'existe comme module :

- le **contrôle qualité** se fait aujourd'hui par les **Checklists** ;
- les **KPI** vivent dans le **Tableau de bord** (statistiques des checklists du
  jour), et l'entrée **Objectifs & Primes** — badgée « En construction »,
  déjà présente avant ce travail — est leur emplacement annoncé.

On n'a donc pas créé d'entrée de menu supplémentaire : elle n'aurait mené nulle
part, et le brief interdit les entrées mortes. Quand ces deux modules
existeront, il suffira d'ajouter leur clé dans `DeviceModeService::NAV`.

---

## 2. Où vit le réglage

Le brief demande, par ordre de préférence :

1. côté ERP, sur l'appareil (`GET /devices/me`, champ `mode`) ;
2. à défaut, en cookie persistant côté Kitchen.

**On a pris l'option 2, explicitement.** `GET /devices/me` est bien consommé
(`app/Repositories/Me/DeviceRepository`), mais sa réponse ne porte aucun champ
`mode` et Kitchen n'a pas d'écriture sur l'appareil. Inventer un troisième
mécanisme était exclu ; le cookie est celui que le brief nomme.

| Cookie | Contenu | Durée |
|---|---|---|
| `kitchen_device_mode` | `gestion` \| `production` \| `webshop` | 5 ans |
| `kitchen_webshop_url` | surcharge locale de l'URL du back-office | 5 ans |
| `kitchen_webshop_token` | **le jeton d'appareil du webshop — un secret** | 5 ans |
| `kitchen_mode_config` | cache de `GET /devices/{id}/configuration` — ce que chaque mode affiche | 10 min |

Le quatrième est un **cache**, pas un réglage : ce que chaque mode montre vient
désormais de la table `pwa_kitchen_param` côté ERP (voir
`docs/BACKEND_A_FAIRE.md` §8). La tablette l'interroge au plus une fois par
tranche de dix minutes — un réglage coché au back-office descend donc dans le
quart d'heure, sans qu'on touche à la tablette et sans déploiement.

**Endpoint absent ou muet : aucun menu, et l'écran le dit.** Chaque page affiche
alors « API à créer : `GET /devices/{id}/configuration` ».

C'est un choix assumé, demandé le 13/08/2026, et il a un coût : sans cet
endpoint, la tablette n'a plus de navigation. C'est le prix de la règle
inverse — un menu codé en dur qui prend la place fait croire que tout va bien,
et le manque ne se voit qu'au moment de s'en servir. Pendant que le back se
construit, mieux vaut un écran qui gêne qu'un écran qui ment.

Le mode, lui, reste réglable sur la tablette : c'est par le profil qu'on y
accède, et l'onglet Profil est toujours présent dans la barre du bas.

`Secure` · `HttpOnly` · `SameSite=Strict` · `path=/`, comme les cookies d'auth
existants.

**Les deux premiers ne sont pas une identité.** Ils n'entrent dans aucune
décision d'autorisation : les forger ne donne accès à rien. Chaque module reste
protégé par le jeton de session, et le back-office WebShop par son jeton
d'appareil, que le serveur du webshop vérifie lui-même. Ils ne font que choisir
des entrées de menu.

**Le troisième, si.** `HttpOnly` le met hors de portée du JavaScript de la page,
`Secure` hors de portée d'un réseau en clair, et il ne part jamais vers une
autre origine que Kitchen. Voir §3 pour le reste du traitement.

### Ce qui vient de l'ERP, et ce qui n'en vient pas

**Tranché le 12/08/2026.** La frontière passe entre deux questions qu'on
confondait :

| Question | Où elle se règle | Pourquoi |
|---|---|---|
| **Ce que chaque mode affiche** | table `pwa_kitchen_param`, côté ERP | c'est une décision de réseau, elle appartient au franchisé et ne doit pas demander un déploiement |
| **Quel mode porte cette tablette** | la tablette, dans ses réglages | on déplace une tablette du fournil au comptoir un samedi matin ; l'équipe doit pouvoir la basculer sur-le-champ, sans appeler quelqu'un qui a accès au back-office |

La première est branchée : `GET /devices/{id}/configuration` est lu au plus une fois par
tranche de dix minutes, filtré par `DeviceModeService::sanitise()` et mis en
cache dans `kitchen_mode_config`. Voir `docs/BACKEND_A_FAIRE.md` §8.

La seconde ne le sera pas. `GET /devices/{id}/configuration` **ne sert pas** de champ
`mode`, `PATCH /devices/me/settings` n'en accepte pas, et trois assertions de
`bin/mode-test.php` interdisent qu'un tel champ prenne la main un jour sans
qu'on l'ait décidé. Le mode reste ce qu'il a toujours été ici : un cookie de
cinq ans sur l'appareil.

Ce n'est pas un renoncement à la centralisation. C'est reconnaître que les deux
réglages n'ont pas le même rythme : l'un change quand la politique change,
l'autre quand on déplace un meuble.

---

## 3. Mode WebShop

**Le back-office franchisé n'est pas réécrit.** Il existe, il est déployé, il
compte 37 sections en 6 groupes, et il gère déjà tout ce qui est délicat :
portée du jeton, cloisonnement à la boutique, révocation, liste blanche des
écrans ouverts. Kitchen lui donne un cadre.

- Route : `GET /webshop` → `app/Views/webshop/index.twig`
- Rendu : **vue intégrée** (iframe plein écran), option 1 des trois proposées —
  l'utilisateur ne quitte pas l'application et garde la barre d'onglets.
- URL ouverte : **celle qui a été configurée, telle quelle.**

Kitchen n'ajoute plus `?shop=`. Depuis la révision, c'est le jeton qui impose la
boutique et le serveur **ignore** ce paramètre : l'ajouter laisserait croire que
la tablette choisit son magasin.

### Aucune connexion personnelle

La tablette est posée dans le magasin et sert à toute l'équipe. Lui demander un
identifiant à chaque geste n'aurait pas de sens : l'identité, c'est **l'appareil**.

Deux réglages, pris dans le back-office sous **Réglages › Tablette Kitchen** :

| Réglage | Où il vit | Repli |
|---|---|---|
| **URL du back-office** | cookie `kitchen_webshop_url`, ou `SetEnv WEBSHOP_BO_URL` | serveur, puis rien |
| **Jeton d'appareil** | cookie `kitchen_webshop_token` | **aucun** |

L'URL a une valeur serveur pour ne pas la saisir sur cinquante tablettes. **Le
jeton n'en a pas, et c'est délibéré** : un jeton vaut pour une boutique, et un
même serveur Kitchen sert plusieurs magasins. Une valeur globale ouvrirait le
back-office du même magasin pour tout le monde.

### Le jeton est un secret, et il est traité comme tel

- **Jamais dans une URL** : ni dans le `src` de l'iframe, ni en paramètre de
  requête. Une URL se retrouve dans les journaux d'accès, dans le `Referer` et
  dans l'historique du navigateur. Il ne voyage qu'en en-tête `X-Device-Token`.
- **Jamais dans un log.**
- **Jamais rendu dans la page.** Une tablette de comptoir reste allumée devant
  tout le monde : le champ de réglage part vide et n'affiche que les **quatre
  derniers caractères** — assez pour reconnaître celui qui est en place, pas
  assez pour le recopier. D'où aussi la règle du formulaire : *un champ vide
  conserve le jeton*, et l'effacer demande de cocher une case.
- Cookie `HttpOnly` : illisible par le JavaScript de la page.
- **Le jeton admin ERP n'apparaît nulle part** — ni code, ni config, ni log, ni
  stockage. Il donne accès aux marges et aux paramètres réseau ; il n'a rien à
  faire sur un comptoir.

### Vérification au démarrage, et révocation

Le jeton est révocable depuis le back-office : c'est le seul recours si la
tablette disparaît. À chaque ouverture de `/webshop`, Kitchen appelle
`GET <origine>/webshop/api/franchisee/me` avec l'en-tête, et décide :

| Réponse | Ce qu'on affiche |
|---|---|
| `2xx` | le cadre |
| `401` / `403` | **écran de configuration** — « cette tablette n'est plus autorisée ». Aucun cadre monté, donc aucune donnée en cache affichée |
| réseau injoignable | le cadre **et** un bandeau « jeton non vérifié, le webshop est injoignable » |
| `5xx` | idem réseau : un incident serveur n'est pas un verdict sur le jeton |

Le cas réseau mérite d'être justifié : bloquer sur un hoquet de wifi rendrait le
comptoir inutilisable, alors que le serveur du webshop refusera de toute façon
un jeton révoqué. On ouvre, et on **écrit** qu'on n'a pas pu vérifier — un échec
réseau dit « réseau », jamais « aucune donnée ».

La base d'API est **déduite** de l'URL configurée (son origine + `/webshop/api`)
plutôt que saisie : deux champs pour une seule information, ce sont deux
occasions de les rendre incohérents.

### Quand le mode est indisponible

L'option est **grisée avec sa raison**, jamais masquée. Quatre raisons
distinctes, parce qu'elles n'appellent pas la même réparation :

| Code | Message | Où corriger |
|---|---|---|
| `no_url` | aucune adresse enregistrée | réglages, ou `WEBSHOP_BO_URL` |
| `bad_url` | l'adresse n'est pas une adresse web | réglages |
| `no_token` | aucun jeton enregistré | réglages, jeton pris dans le back-office |
| `bad_token` | le jeton n'a pas la forme attendue | réglages — c'est l'URL collée dans le mauvais champ, neuf fois sur dix |

`bad_token` ne dit **pas** que le jeton est faux : seul le serveur le sait, et
il le dit par un 403. Le contrôle local attrape la faute de saisie, rien de
plus — un format strict enfermerait la tablette le jour où le webshop change la
longueur de ses jetons.

`GET /webshop` affiche la même raison plutôt qu'un cadre vide. Aucun repli,
aucune donnée de démonstration.

### Une conséquence à connaître

Un appareil partagé sans identification personnelle signifie qu'**aucune action
n'est attribuable à quelqu'un** : changer le statut d'une commande est tracé
comme venant de la tablette, pas d'une personne. C'est le choix assumé du brief
pour ce poste. Si l'attribution devient nécessaire (litige, inventaire), il
faudra ajouter une identification — pas contourner celle-ci.

### Le côté WebShop : le jeton existe déjà

**Ne réécrivez pas ce jeton — il est en production.** La branche
`claude/webshop-bureau-zone-modal` du dépôt `samsam2703mfc/webshop` le porte, et
c'est elle qui est déployée (`workflow_dispatch`, pas `main` — `main` a plusieurs
centaines de commits de retard, et déployer depuis lui ramènerait le serveur en
arrière).

| Côté webshop | Ce qu'il y a |
|---|---|
| Table | `ws_shop_device_token` — migration **0052** |
| Stockage | SHA-256, plus un `token_prefix` en clair pour reconnaître le jeton actif |
| Règle | **un seul jeton actif par boutique** : régénérer révoque le précédent |
| Garde | `device_token_shop()` lit `X-Device-Token`, borne à la boutique du jeton |
| Portée | liste blanche de six écrans de comptoir, tout le reste refusé |

Kitchen envoie l'en-tête `X-Device-Token` depuis le serveur
(`app/Repositories/WebShop/WebShopRepository`) : c'est exactement ce que cette
garde attend. **Rien à ajouter côté webshop.**

Une version concurrente de ce jeton a été écrite ici par erreur, sur une base
trop ancienne pour voir l'existante, puis retirée. Ce qu'il faut en retenir :
avant d'écrire quoi que ce soit dans ce dépôt, regarder les branches — `main`
n'y est pas la ligne de travail.

**Où prendre le jeton** : dans le back-office franchisé de cette branche. Il
n'est affiché qu'une fois, comme un mot de passe.

**Si un jour les deux applications changent d'hôte**, rien ne casse : l'en-tête
franchit les origines. C'est le mode « cadre » (iframe) qui tomberait, et il
n'est plus utilisé — le comptoir a ses trois écrans natifs.

---

## 4. Où c'est écrit

| Rôle | Fichier |
|---|---|
| Les règles (pures, testées) | `app/Services/Me/DeviceModeService.php` |
| La persistance | `core/Support/DeviceMode.php` |
| Exposition à toutes les vues | `app/Http/Controllers/Controller.php` (`view()`) |
| Écran de réglage | `app/Views/me/about.twig`, `POST /me/settings` |
| Vue WebShop | `app/Http/Controllers/WebShop/`, `app/Views/webshop/index.twig` |
| Vérification du jeton | `app/Repositories/WebShop/DeviceTokenRepository.php` |
| Menu | `app/Views/components/app_nav.twig` |
| Barre du bas | `app/Views/layouts/base.twig` |
| Tests | `php bin/mode-test.php` |

Les règles ne connaissent ni cookie, ni requête, ni horloge : c'est ce qui rend
`bin/mode-test.php` possible sans navigateur, et ce qui permettra de passer à
l'ERP sans y toucher.

---

## 5. Effet de bord corrigé au passage

`GET /me` répondait **« Błąd DI »** sur toutes les tablettes : `ProfileController`
injectait `KitchenService`, une classe qui n'existe pas dans ce dépôt (nom
hérité du fork fournisseur, où elle s'appelait `SupplierService`). L'onglet
Profil ne s'ouvrait donc pas.

C'est l'écran des réglages : il est passé sur `DeviceService` (déjà présent,
qui sert `GET /devices/me`), et il s'ouvre désormais **même quand l'ERP ne
répond pas** — sinon une tablette hors ligne ne pourrait plus changer de mode.
