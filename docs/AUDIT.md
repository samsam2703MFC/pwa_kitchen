# Audit — fonctions, fluidité, flow

> **État au 5 août 2026, après corrections.** Les sept premières lignes du plan
> de traitement sont appliquées et vérifiées au navigateur. Le relevé ci-dessous
> décrit ce qui a été trouvé ; le tableau final dit ce qu'il en reste.
>
> Résultat mesuré après correction : **629 à 751 ms par page**, contre 13 400 ms
> avant, et **aucun hôte externe contacté**.
>
> Une erreur de ce rapport a été corrigée : voir §4d, `/baking`.

Relevé du 5 août 2026, sur la version déployée (`db6a5f2`), mesuré au navigateur
sur les 22 écrans atteignables, plus lecture des 54 routes déclarées.

Méthode : Chromium piloté, tablette 1024×768, connexion réelle (`tablette` /
`1234`), API bouchonnée en local. Chaque chiffre cité ci-dessous a été mesuré,
pas estimé. Les scripts sont dans le bac à sable de la session
(`audit-flow.js`, `audit-perf.js`, `audit-touch.js`, `audit-checklist.js`).

---

## Ce qui va bien, et qu'il ne faut pas casser

Le serveur est rapide : **22 à 53 ms** pour rendre une page, appels API compris.
Rien à optimiser de ce côté — tout le temps perdu l'est dans le navigateur.

Les services métier sont purs et testés sans navigateur : 230 assertions vertes
réparties sur sept fichiers (`bin/*-test.php`). C'est la partie la plus solide de
l'application, et c'est elle qui porte les décisions difficiles (secteurs,
prévisions, jeton de poste, statuts webshop).

Les états d'échec sont dits, pas masqués : « réseau », « jeton révoqué »,
« section non ouverte » sont trois messages distincts. Aucun écran n'invente de
données quand l'API ne répond pas. C'est rare et ça vaut d'être protégé.

---

## 1. Bloquant — chaque page attend 12,7 s une police Google

C'est, de loin, le premier problème de l'application.

`layouts/base.twig` charge en `<head>`, en feuille de style bloquante :

```
https://fonts.googleapis.com/css2?family=Nunito:...
```

Mesure sur `/production` :

| | avec la police distante | sans |
|---|---|---|
| DOMContentLoaded | **12 895 ms** | ~640 ms |
| réseau au repos | 13 417 ms | 794 ms |

La requête vers Google échoue au bout de **12 751 ms** ; pendant tout ce temps le
navigateur n'affiche rien, parce qu'une feuille de style en `<head>` bloque le
rendu. Toutes les pages qui héritent de la coque sont concernées — les deux
seules qui ne l'héritent pas se chargent en 517 ms.

Et la police n'est même pas celle de l'interface : le thème Atelier sert
Gotham et GC_Vank, hébergés en local (311 Ko déjà chargés). Nunito n'arrive que
par `bootstrap.css`, pour un `body { font-family: Nunito }` que le thème
recouvre.

Ce n'est pas un artefact de bac à sable. Un magasin derrière un portail captif,
un DNS lent ou un pare-feu d'entreprise reproduit exactement ce comportement, et
l'équipe voit une tablette blanche pendant treize secondes au moment où elle
ouvre le magasin.

**Correction : supprimer les deux lignes `fonts.googleapis` / `preconnect`.**
Une minute de travail, treize secondes gagnées par page.

## 2. Bloquant — 934 Ko et 31 requêtes pour afficher une liste

Chaque page charge 13 feuilles de style et 7 scripts, quel qu'en soit le
contenu : `filepond` et `filepond-plugin-image-preview` (téléversement),
`cropper` (recadrage d'image), `choices.js` (sélecteurs riches), `sweetalert2`,
`perfect-scrollbar`, `dripicons`, `bootstrap-icons`, `bootstrap.css` complet.

Le tableau de bord, qui affiche quatre chiffres, paie les 37 Ko de `cropper` et
les 73 Ko de `choices`. Sur une tablette de comptoir en wifi de magasin, c'est
le prix de chaque navigation, puisqu'il n'y a pas de cache applicatif (§4).

**Correction : charger ces vendors par page qui les utilise**, via le bloc
`{% block head %}` qui existe déjà. Trois d'entre eux ne servent qu'à l'écran
Réclamations.

## 3. Bloquant — l'application n'est pas une PWA

Il n'y a ni `public/manifest.json`, ni service worker, nulle part dans le dépôt.

Conséquences concrètes, pour une application dont le nom est `pwa_kitchen` :

- elle ne s'installe pas sur l'écran d'accueil de la tablette ; on y accède par
  une barre d'URL, dans un navigateur, avec ses onglets ;
- elle ne survit à aucune coupure réseau, même d'une seconde ;
- elle ne garde rien en cache : les 934 Ko de §2 repartent à chaque navigation
  si l'en-tête HTTP ne les couvre pas ;
- elle n'a pas d'icône, pas de couleur de thème, pas de mode plein écran.

C'est le seul point de cet audit qui demande un vrai chantier — mais un chantier
court : un manifeste, une icône, et un service worker qui met en cache la coque
et les assets suffisent à changer l'usage quotidien.

## 4. Écrans cassés et culs-de-sac

Quatre défauts visibles par l'équipe, tous vérifiés au navigateur.

**a. Toute URL inconnue rend une page blanche, en HTTP 200.**
`core/Bootstrap/App.php` traite `Dispatcher::NOT_FOUND` par un `break` qui sort
de la méthode sans rien afficher. Résultat mesuré :

```
/production/nimporte  → status 200, 0 octet
/Dashboard            → status 200, 0 octet
```

Le second cas n'est pas théorique : `layouts/base.twig` ligne 118 propose un
bouton « retour » vers `ROOT ~ '/Dashboard'`, avec une majuscule, alors que la
route est `/dashboard`. Le bouton de secours mène donc à l'écran blanc. Et le
`errors/404.twig` existe, mais le routeur ne l'appelle jamais.

**b. `/knowledge/recipes` n'a aucune route.**
`knowledge/overview.twig` ligne 31 présente une carte « Sous-recettes »,
cliquable, bordée en couleur primaire. Elle mène à l'écran blanc du point (a).
`RecipeController` et `Knowledge/Recipe/TechnicalSheetController` existent, sont
injectables, et ne sont jamais atteints : `KnowledgeRoutes.php` ne déclare que
`/knowledge`, `/knowledge/products` et `/knowledge/products/{id}`.

**c. 15 des 54 routes pointent vers un contrôleur qui n'existe pas.**
Les dossiers `Controllers/Catalog`, `Controllers/Ingredient` et
`Controllers/Price` ont disparu ; leurs fichiers de routes sont restés.

| fichier de routes | routes mortes |
|---|---|
| `CatalogRoutes.php` | 7 |
| `IngredientRoutes.php` | 5 |
| `ClientRoutes.php` (partie Price) | 3 |

Ce qu'on obtient en les appelant, mesuré :

```
/catalog              → 500, corps = « Błąd DI : … »
/clients/1/price-list → 500, corps = « Błąd DI : … »
```

Une erreur 500 nue, en polonais, sans coque ni bouton de retour.

**d. ~~`/baking` n'est au menu d'aucun mode.~~ — constat erroné, retiré.**
J'avais conclu à un écran orphelin en lisant les routes, le service et les huit
gabarits, sans lire le corps de `BakingController::index()`. Il ne rend rien :
il redirige en 302 vers `/production?view=planning`. Le planning a été fondu
dans Production — un module, quatre questions — et `/baking` ne survit que comme
ancienne adresse, pour les liens qui circulent déjà. Il n'a donc rien à faire au
menu : l'y mettre doublerait l'entrée Production. Rien à corriger.

## 5. L'écran Checklists est en polonais, dans les cinq langues

Le module le plus récemment livré affiche, sur une tablette française :
« Checklisty », « Przeglądaj zadania dzienne zgrupowane według checklisty »,
« Data », « Pokaż », « Checklista », « — wszystkie — », « ŁĄCZNIE / WYKONANE /
NIEWYKONANE », « Wykonaj », « Oczekuje », « Zrealizowane », « 1/5 wyk. » —
autour de noms de tâches français.

La cause tient en une lettre. `Controller::view()` déduit le module du premier
segment du chemin de gabarit :

```php
$moduleName = explode("/", $name)[0];        // « checklist/index » → « checklist »
loadTranslations('page', $langCode, $moduleName);
```

Le dossier de vues s'appelle `checklist/`, le fichier de traduction
`checklists.json`. Le fichier n'est donc jamais trouvé — **dans aucune des cinq
langues** — et les gabarits retombent sur leurs valeurs polonaises codées en
dur, héritées du squelette d'origine.

**Correction : renommer les cinq `checklists.json` en `checklist.json`.** Rien
d'autre. J'ai vérifié qu'aucun autre module n'a le même écart : les douze autres
correspondent.

## 6. Deux entrées de menu sur huit ne mènent nulle part

- **« Objectifs & Primes »** est déclarée `disabled` avec `href: '#'`, et
  figure au menu des modes Production **et** Gestion. Elle porte un badge
  « En construction » et ne fait rien.
- **« Production »**, l'écran le plus abouti de l'application, porte le même
  badge « En construction » (`wip: true`), ce qui invite l'équipe à s'en méfier.

Les deux se corrigent dans `components/app_nav.twig` : retirer l'entrée
`objectives` de `DeviceModeService::NAV` tant que l'écran n'existe pas, et
retirer `wip: true` de l'entrée `production`.

## 7. Ergonomie tactile — les cibles sont sous le minimum

Mesuré sur neuf écrans, hauteur des éléments cliquables. Le seuil admis pour un
doigt est 44 px ; les tablettes de fournil sont souvent manipulées avec des
mains farinées ou gantées, ce qui n'aide pas.

| élément | hauteur | où | par écran |
|---|---|---|---|
| `.pr-rail-i` (rail des périodes) | **19 px** | Production | 4 |
| `.set-switch` (interrupteurs) | 32 px | Réglages | 1 |
| `.btn` (actions secondaires) | 31 px | Tableau de bord, Réclamations | 2 |
| `.cat-chip` (filtres catégorie) | 34–40 px | Production, Stock, Minimums | 8–11 |
| `.form-control` (date, recherche) | 38 px | Checklists, Commandes | 1–2 |
| `.app-tab` (barre du bas) | 39 px | partout | 5 |

Total : de 6 à 25 cibles sous 44 px selon l'écran, le pire étant
`?view=stock` (25). Le rail des périodes à 19 px est le point le plus dur : il
sert à changer de journée, plusieurs fois par service.

## 8. Flow — le geste du jour est sous la ligne de flottaison

Sur Checklists, dans l'ordre vertical : titre, sous-titre, sélecteur de date,
bouton « Afficher », sélecteur de checklist, trois cartes de checklist,
en-tête de la checklist retenue, compteurs — **puis** la première tâche à
valider. Le premier bouton d'action tombe à environ 1 045 px, soit sous la
ligne de flottaison d'une tablette de 768 px de haut.

Or l'écran a un seul usage : ouvrir la checklist du moment et enchaîner ses
tâches. Trois remarques dans cet ordre de rentabilité :

1. **Présélectionner la checklist due maintenant** plutôt que d'exiger un choix.
   On connaît l'heure planifiée de chacune ; à 6 h 10, « Ouverture du magasin »
   est la seule réponse plausible. Le filtre reste, replié.
2. **Replier le bloc de filtres** une fois une checklist ouverte. Il occupe
   aujourd'hui la totalité du premier écran.
3. **Changer de checklist recharge la page** (`?checklist_id=`). C'est un
   aller-retour serveur complet pour un geste de navigation interne, alors que
   le reste de l'application fait ce genre de chose en AJAX.

Le reste du flow est sain : la prise de poste supprime bien la ressaisie du PIN,
et le fil horizontal de production tient sur une ligne comme prévu.

## 9. Réglages de production restés en position « développement »

- **`config/app.php:103` — `const DEBUG = true;`** Le seul usage de cette
  constante est `ini_set('display_errors', 1)` dans `public/index.php`. Sur
  `185.180.206.46/kitchen`, la moindre alerte PHP s'imprime donc dans la page,
  avec les chemins du serveur. À passer à `false`, ou mieux à lire depuis
  l'environnement.
- **Twig est instancié à chaque rendu, `cache: false`, `debug: true`**
  (`Controller::render()`). Le coût mesuré reste faible (22–53 ms), donc ce
  n'est pas urgent — mais `debug: true` en production n'a pas de raison d'être.
- **`tools/mock-api/ENABLED` est présent dans le dépôt** et le `rsync` de
  déploiement n'exclut que `.git`, `.github`, `.idea` et `.env` : le bouchon
  part sur le serveur. Il n'est pas exposé au web (le docroot est `public/`),
  mais il n'a rien à y faire.

## 10. Dette de structure, à traiter quand il y aura du temps

- **Les attributs `#[Route]` sont décoratifs.** Rien dans `src/core/` ne les lit
  (aucun `getAttributes`, aucun `ReflectionAttribute`) : le routage vient
  uniquement de `Bootstrap/Routes/*.php`. Les deux sont aujourd'hui tenus en
  parallèle à la main. Ajouter un attribut sans toucher au fichier de routes
  donne un 404 — et un développeur qui lit le contrôleur n'a aucun moyen de le
  deviner. Soit on les scanne, soit on les supprime.
- **`ProductionController` fait 900 lignes**, contre 278 pour le suivant. Cinq
  vues y cohabitent (besoins, planning, stock, minimums, MEP). Le découpage
  suivrait naturellement ces cinq lignes.
- **Deux troncs, `main` et `master`**, et c'est `master` que le workflow de
  déploiement écoute. Ma branche est 76 commits devant `main` et 3 derrière
  (trois commits de fiche vitrine, sans code applicatif). Il faudra trancher
  lequel est le tronc avant toute fusion.
- **Les commentaires et messages sont en trois langues** : polonais hérité,
  français des ajouts récents, anglais des libellés Mazer. Les messages d'erreur
  utilisateur en polonais (« Brak danych klienta. », « Błąd DI ») sont ceux qui
  gênent le plus.

---

## Ordre de traitement — état

### Fait, et vérifié au navigateur

| # | Correction | Vérification |
|---|---|---|
| 1 | Police Google retirée de `base.twig`, `error_mobile.twig` **et `auth/login.twig`** | 629–751 ms par page, 0 hôte externe |
| 2 | `checklists.json` → `checklist.json` dans les cinq langues, plus deux clés manquantes | l'écran est en français de bout en bout |
| 3 | `NOT_FOUND` rend `errors/404.twig` en HTTP 404 ; `/ajax/…` répond en JSON ; `/Dashboard` corrigé | `/nexistepas` → 404, page lisible, bouton de retour valide |
| 4 | `CatalogRoutes` et `IngredientRoutes` supprimés, `PriceRoutes` sorti de `ClientRoutes` | `/catalog` et `/clients/1/price-list` → 404 propre, plus de 500 |
| 5 | `DEBUG` lu depuis `KITCHEN_DEBUG`, éteint par défaut ; `debug` de Twig aligné | plus de `display_errors` en production |
| 6 | Carte « Sous-recettes » retirée ; `objectives` sorti des deux menus ; badge « En construction » retiré de Production | 6 entrées de menu, toutes vivantes |
| 7 | *(annulé — constat erroné, voir §4d)* | — |

Deux réparations non prévues ont été faites au passage, parce qu'elles
tombaient dans le même geste : les trois pages d'erreur affichaient une
**illustration Mazer jamais déployée** (remplacée par le code en grand, sans
fichier), et la page d'erreur sortait dans la langue du navigateur plutôt que
dans celle réglée sur la tablette — le middleware qui pose la langue ne tourne
pas quand le routage échoue.

**Et une panne que la correction 5 a mise au jour : « Nouvelle réclamation »
était cassé, et l'était déjà.** Tant que `display_errors` était allumé, l'écran
imprimait une trace PHP et passait pour à moitié chargé ; éteint, il est devenu
un 500 muet. La cause : `/shops/{id}/orders` sert deux appelants avec deux
formes — `{ date, items: [...] }` pour le carnet de production, la liste seule
pour ce formulaire. Le tri parcourait donc la chaîne « date » comme si c'était
une commande. Deux corrections :

- le dépôt accepte les deux formes et écarte les entrées mal formées ;
- `safeFetch` rattrape désormais `Throwable` et plus seulement `Exception`. Une
  `TypeError` n'est pas une `Exception` : elle traversait le filet et emportait
  la page entière, alors que cette méthode existe pour qu'un panneau en panne
  n'en emporte pas d'autres.

`/complaints/new` répond 200 et affiche son formulaire.

Les sept suites de tests restent vertes : **231 assertions**.

### Fait aussi, et vérifié au navigateur

| # | Correction | Vérification |
|---|---|---|
| 8 | Plancher tactile de 44 px : rail des périodes, pastilles de filtre, boutons, champs, onglets du bas, logo, dépliants, commutateurs | **0 cible sous 44 px** sur les onze écrans mesurés (contre 6 à 25) |
| 9 | Six bibliothèques sorties de la coque ; Choices, SweetAlert et modules.js déclarés par les deux écrans de commande | 31 → **14 requêtes**, 934 → **662 Ko** par page |
| 10 | Checklist du moment présélectionnée, filtres repliés en bandeau | premier bouton « Effectuer » à **537 px** au lieu de 1 045 px |
| 11 | Manifeste, icônes, service worker, page hors ligne | worker **actif**, 11 fichiers en cache, page « Pas de réseau » servie serveur arrêté |

#### Ce que le service worker fait, et ce qu'il refuse de faire

Il met en cache **les fichiers, jamais les données**. Aucune page HTML, aucun
appel `/ajax/` ne passe par un cache : réseau d'abord, toujours. C'est la même
règle que le reste de l'application — une tablette de cuisine ne doit jamais
afficher un stock d'hier comme s'il était d'aujourd'hui. Le gain est donc de la
vitesse et de l'installation, pas de l'autonomie.

Vérifié en arrêtant réellement le serveur : la navigation affiche la page
« Pas de réseau », et un appel `/ajax/` échoue franchement plutôt que de servir
une valeur périmée.

> **Prérequis non rempli aujourd'hui.** Le service worker et « Ajouter à
> l'écran d'accueil » exigent un contexte sécurisé. Le site est servi en
> `http://185.180.206.46/kitchen`, en clair sur une adresse IP : le navigateur
> lit le manifeste mais refuse d'enregistrer le worker, et n'offre pas
> l'installation. **Tout le code est en place et s'activera de lui-même le jour
> où le site passe en HTTPS** — rien à changer alors, ni dans les gabarits, ni
> dans le `.htaccess`. C'est une décision d'hébergement, pas de code.

Trois défauts trouvés en chemin ont été réparés au passage : `favicon.svg`,
référencé sur chaque page, n'existait pas ; le bouchon d'API renvoyait
« Ouverture du magasin » quelle que soit la checklist demandée, ce qui faisait
lire une contradiction à l'écran ; et un `background` en raccourci aurait annulé
le `background-clip` des commutateurs agrandis.

### À faire

| # | Correction | Gain | Effort |
|---|---|---|---|
| 12 | Écran des sous-recettes (le contrôleur et le service attendent, le gabarit est vide) | la carte peut revenir | 0,5 j |
| 13 | Servir le site en HTTPS | l'application devient installable, le worker s'active | hébergement |
| 14 | Découper `ProductionController` (900 lignes, cinq vues) | lisibilité | 0,5 j |
| 15 | Scanner les attributs `#[Route]` ou les supprimer | plus de double tenue de livres | 2 h |
