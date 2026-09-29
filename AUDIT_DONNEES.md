# AUDIT_DONNEES — les données servies à l'écran

**Dépôt** `pwa_kitchen` · branche `claude/ux-pwa-consultant-t02npa` · commit `c468b76`
**Sujet** ce que l'écran affiche quand l'API ne répond pas.
**Règle vérifiée** — la tienne, mot pour mot : *« pas de simulation ou fall back, tout via
API ou donne un message d'erreur (type : créer api xxxx) ».*
**Périmètre** tout le dépôt, sauf `vendor/`, `public/assets/vendors/`, `node_modules/`.
**Nature** lecture seule. Aucun fichier existant n'a été modifié ; ce rapport est le seul
fichier écrit.

Les variables du gabarit n'étaient pas remplies : je prends celles que le contexte impose
et je les déclare ici. Si le sujet visé était autre, le rapport se rejoue tel quel.

---

## Ce qui a été lu

129 fichiers PHP hors `vendor/`, 51 gabarits Twig, 9 suites de vérification dans `bin/`,
un workflow (`.github/workflows/deploy.yml`). Les 39 déclarations de routes viennent de
`src/core/Bootstrap/Routes/` (`OrderRoutes.php:8`, `ProductionRoutes.php:6`, … ) ; les
attributs `#[Route]` posés sur les contrôleurs ne sont lus par personne, ils ne prouvent
donc l'existence d'aucun écran.

Méthode : partir du seul point d'entrée réseau (`src/core/Http/ApiClient.php:46`),
descendre dans les 16 dépôts (`src/app/Repositories/`), puis dans les services, puis dans
les 16 contrôleurs, puis dans les vues — et à chaque étage poser une seule question : *entre
« la liste est vide » et « je n'ai pas pu savoir », ce code sait-il encore faire la
différence ?*

---

## Le mécanisme central

Tout se joue à un endroit : `src/core/Http/ApiClient.php:67-88`. La réponse HTTP est
traduite en un tableau qui porte `success`, et **`data` vaut `[]` par défaut**
(`ApiClient.php:67`). Un timeout, un 500, un DNS mort : tous rendent la même forme qu'un
magasin sans commandes. La distinction existe donc bien — dans `success` — et toute la
question est de savoir qui la lit.

Deux camps nets dans les dépôts.

**Ceux qui la lisent** et rendent `null` : `BakingRepository.php:31,85` ·
`Me/DeviceConfigRepository.php:43` · `Me/DeviceRepository.php:21` ·
`ProductionRepository.php:37,48` · `Staff/StaffRepository.php:57-77` ·
`Knowledge/Product/TechnicalSheetRepository.php:26,32` · `Auth/LoginRepository.php:22,31`
· `Order/OrderRepository.php:67,72`.

**Ceux qui l'écrasent** et rendent `[]` : `Checklist/ChecklistRepository.php:17,26,35` ·
`Client/ClientRepository.php:18,29` · `Complaint/ComplaintRepository.php:15,32` ·
`Complaint/MaterialOrderForComplaintRepository.php:24,37` · `Order/OrderRepository.php:43`.

**Ceux qui ne regardent même pas `success`** : `Shop/ShopRepository.php:23-29` ·
`Knowledge/Product/ProductRepository.php:39-45` · `Knowledge/Recipe/RecipeRepository.php:24`.

Et au-dessus, un second aplatissement : `Controller::safeFetch()` a pour valeur de repli
`$default = []` (`src/app/Http/Controllers/Controller.php:34`), et 18 des 19 appels du
dépôt la prennent telle quelle — par ex. `Dashboard/DashboardController.php:15-20`,
`Complaint/ComplaintController.php:21-26`, `Order/OrderController.php:38`. Une exception
révèle la règle : `ComplaintController.php:57-62` passe `null` en défaut, parce que là le
contrôleur avait besoin de savoir.

Le canal pour *dire* ce qui manque existe pourtant, et il est bon : `Controller.php:144-147`
accumule une liste de routes manquantes, `src/app/Views/layouts/base.twig:150-153` l'affiche
en bandeau. **Deux contrôleurs sur seize l'alimentent** :
`Checklist/ChecklistController.php:100` et `Production/ProductionController.php:309`.

---

## P0

### 1. Un code juste est refusé quand l'API tombe, et l'opérateur est déclaré inconnu

`ChecklistService::verifyPin()` parcourt `getEmployeesForShop()`
(`src/app/Services/Checklist/ChecklistService.php:133`), qui rend `[]` si la route ne
répond pas (`src/app/Repositories/Checklist/ChecklistRepository.php:35`). La boucle ne
trouve rien et la fonction sort sur `employee_not_found`
(`ChecklistService.php:146`). L'écran affiche alors « Nie znaleziono pracownika »
(`src/app/Views/checklist/index.twig:857`).

Quelqu'un qui tape le bon code s'entend répondre qu'il n'existe pas. C'est le pire des
trois messages possibles : il envoie chercher au back-office une fiche qui est là, pendant
que la panne est ailleurs. Et c'est le seul contrôle qui décide si une signature est vraie
(`ChecklistService.php:117-125`).

### 2. Le tableau de bord annonce « rien à faire » quand il ne sait rien

`DashboardController.php:15-20` récupère les checklists avec un défaut `[]`, puis
`:23-26` somme `tasks_done` et `tasks_total` sur ce tableau vide. Les deux tuiles rendues
affichent donc `0` et `0` (`src/app/Views/dashboard/dashboard.twig:198,218`) — et `0` tâche
restante est le chiffre exact d'une journée finie.

La tuile « en cours » du même écran montre comment il fallait faire : elle affiche `–` et
« en construction » parce que l'API ne sait pas la remplir (`dashboard.twig:203-213`). Le
soin est là, à côté.

### 3. L'écran des checklists dit « aucune checklist aujourd'hui » à la place de la panne

`ChecklistController.php:30-35` prend `[]` en défaut ; `ChecklistRepository.php:17` le
produit sur échec. La vue conclut « Brak aktywnych checklist dla wybranego dnia »
(`src/app/Views/checklist/index.twig:625`) — *il n'y a pas de checklist pour ce jour*.

L'équipe range la tablette. C'est l'écran le plus utilisé de l'application, et c'est le
message qui interrompt le travail le plus proprement, sans que personne n'ait rien à
signaler.

À noter : le même écran fait exactement ce qu'il faut pour le personnel, quelques lignes
plus loin — `index.twig:726` distingue « la liste vient de l'API, qui ne répond pas encore »
de `:731` « personne n'est au planning » et de `:736` « aucun employé actif ». Le modèle
correct est donc déjà dans ce fichier.

### 4. La liste des magasins tombe en silence, et plus personne ne se connecte

`ShopRepository::getAll()` n'examine jamais `success` : il itère `$response['data']` s'il
est là, et rend `[]` sinon (`src/app/Repositories/Shop/ShopRepository.php:23-29`).
`AuthController.php:31` le passe tel quel, `src/app/Views/auth/login.twig:238-242` boucle
dessus. Sur échec, le sélecteur ne contient que son placeholder
(`login.twig:237`) et la page ne porte aucun message : `errors.shop`
(`login.twig:244-246`) ne se remplit qu'à la validation du formulaire.

Le champ est obligatoire. Une tablette qui ne peut pas choisir son magasin ne peut pas
entrer, et l'écran ne dit pas pourquoi.

---

## P1

### 5. Réclamations, commandes, clients, produits : la même confusion, un cran plus bas

- Réclamations : `ComplaintRepository.php:15` rend `[]` sur échec →
  `src/app/Views/complaints/overview.twig:164` affiche `no_complaints_found`. Les motifs
  aussi (`ComplaintRepository.php:32`), ce qui vide le formulaire de saisie
  (`ComplaintController.php:41-46`).
- Commandes : `OrderRepository.php:43` → `src/app/Views/orders/overview.twig:341`,
  « aucune commande ne correspond aux critères », avec en dessous le conseil de changer de
  date (`:343`). Le conseil est faux.
- Clients : `ClientRepository.php:18,29` → `OrderController.php:98-109`. Le formulaire de
  nouvelle commande s'ouvre avec zéro client sélectionnable.
- Catalogue produit : `ProductRepository.php:39-45`, sans lecture de `success`.

Aucun de ces quatre écrans n'alimente `missing_api` — vérifié par recherche : seuls
`ChecklistController.php:100` et `ProductionController.php:309` le font.

### 6. Deux `?? []` effacent une distinction que le code du dessous avait pris soin d'établir

`ProductionService.php:153` et `:177` appellent `getProducts() ?? []`. Or
`productsInSector()` juste à côté (`:168-172`) garde explicitement le `null` et le
commente : *« le filtre ne transforme pas une absence en liste vide »*. Conséquence : le
sélecteur de secteur disparaît sans un mot quand le catalogue n'est pas servi, alors que
le tableau de la même page, lui, l'écrit (`src/app/Views/production/_period.twig:36-42`).

`ChecklistService.php:50` fait de même (`getEmployees($date) ?? []`). L'écran des
checklists s'en tire parce que `roster()` relit l'état à la source
(`src/app/Services/Staff/StaffService.php:227-232`), mais tout autre appelant de cette
méthode hériterait de la confusion.

### 7. Le bouchon de simulation est déployé sur le serveur, et un fichier commité l'allume

Le `rsync` de production n'exclut que `.git`, `.github`, `.idea`, `.env`
(`.github/workflows/deploy.yml:134`) : `tools/mock-api/index.php` et son jeu de données
(`tools/mock-api/data.php`, 29 Ko) partent sur le serveur à chaque déploiement.

L'allumage se fait par la seule présence de `tools/mock-api/ENABLED`
(`deploy.yml:171`), qui bascule ensuite le `.htaccess` du site vers le bouchon. Un garde-fou
existe — `deploy.yml:175` force `false` si la branche est `master`. Mais la branche
publiée aujourd'hui sur `/kitchen` est `claude/ux-pwa-consultant-t02npa`
(`deploy.yml:60`), qui n'est pas `master` : le garde-fou ne la couvre pas.

Le fichier `ENABLED` n'existe pas (`tools/mock-api/` ne contient que `.gitignore`,
`.state.json`, `README.md`, `data.php`, `index.php`). Le site tourne donc bien sur l'API
réelle — le log de déploiement l'a confirmé (`USE_MOCK_API (normalisé) = false`). C'est un
risque dormant, pas une violation active : un `git add` de trois octets suffirait à servir
des données inventées en production.

---

## P2

### 8. Une erreur réseau s'écrit en clair dans la page

`ApiClient.php:63` ferme la ressource, `:64` teste `curl_errno($ch)` et `:65` fait
`echo 'cURL error: ' . curl_error($ch)`. Le texte part dans le flux de sortie avant tout
gabarit — il s'imprime au-dessus du HTML, et le message qu'il porte est destiné à un
développeur, pas à un opérateur. C'est de plus le seul `echo` de diagnostic du dépôt.

### 9. Un dépôt qui plantera le jour où il sera appelé

`RecipeRepository::getAll()` fait `return new RecipeModel($resp['data']) ?? null`
(`src/app/Repositories/Knowledge/Recipe/RecipeRepository.php:24`). Le `?? null` ne peut
jamais s'appliquer — l'opérande gauche est une instanciation, jamais `null` — et
`$resp['data']` vaut `[]` sur échec (`ApiClient.php:67`).

Aucun risque aujourd'hui : `RecipeController.php:17-22` porte un attribut `#[Route]`
décoratif, et aucune route `recipes` n'est déclarée dans `src/core/Bootstrap/Routes/`.
Sa vue `src/app/Views/knowledge/recipe/overview.twig` fait 0 octet. C'est du code mort qui
a l'air vivant.

### 10. Un commentaire qui décrit la règle inverse de celle qu'applique le code

`src/core/Support/DeviceMode.php:73-76` annonce : *« s'il n'y en a pas, les valeurs par
défaut de l'application. Une tablette sans menu […] ne doit jamais arriver ici. »*

Le code fait exactement le contraire depuis la révision du 13/08 :
`DeviceModeService::sanitise()` rend des cartes vides et `ok = false`
(`src/app/Services/Me/DeviceModeService.php:154-160,181`), `missingApi()` nomme
`GET /devices/me/config` (`:121-124`), et le bandeau l'affiche. C'est le comportement que
tu as demandé — seul le commentaire est resté sur l'ancienne consigne, et c'est lui qu'on
relira dans six mois.

---

## Ce qui tient

À dire aussi, parce que ces trois-là sont le gabarit du reste :

- **Production** distingue quatre absences séparément et les écrit à l'écran :
  `_period.twig:55-69` liste « le plan de cuisson n'est pas servi », « le stock live n'est
  pas servi », « le profil de ventes n'est pas servi : le classement se fait sur le stock
  seul », « le carnet de commandes n'est pas servi ». Les drapeaux viennent du service
  (`ProductionService.php:469,509`), pas de la vue. Les cinq messages sont traduits dans
  les cinq langues (`src/core/I18n/translations/page/{fr,pl,en,it,nl}/production.json`).
- **Personnel** sépare les trois cas — route muette, planning vide, aucun employé —
  jusque dans les termes (`StaffService.php:225-247`, `checklist/index.twig:726-737`), et
  cette séparation est vérifiée sans réseau (`bin/staff-test.php:173-195`).
- **Modes** ne sert plus de menu codé en dur et nomme sa route
  (`DeviceModeService.php:110-124,152-181`).

---

## Zones non couvertes

Ce que je n'ai pas pu établir, et qui ne doit donc rien peser dans les conclusions
ci-dessus :

- **Le comportement réel en production.** Le proxy de cette session refuse
  `atelierby.tfbuddy.com` et `185.180.206.46` (403 sur CONNECT). Tout ce qui précède est
  lu dans le code, pas observé sur la tablette.
- **Ce que l'API rend vraiment sur erreur.** Je n'ai jamais vu une réponse de
  `https://atelierby.tfbuddy.com/api/v1` ; son code source n'est dans aucun dépôt
  atteignable. Les corps d'erreur pourraient porter une forme que `ApiClient.php:86-88`
  jette (il ne garde que le code HTTP).
- **`GET /devices/me/config` et `pwa_kitchen_param`.** La table est créée côté ERP, mais
  l'endpoint n'est pas encore servi : je n'ai pas pu vérifier que la requête de
  `docs/sql/pwa_kitchen_param.sql:79-87` rend la forme que `sanitise()` attend
  (`DeviceModeService.php:158`).
- **Le module WebShop.** Il parle à un autre serveur avec un autre jeton
  (`src/app/Repositories/WebShop/WebShopRepository.php`) et n'obéit pas aux mêmes règles ;
  je l'ai laissé hors périmètre plutôt que de le juger avec la mauvaise grille. Seul point
  relevé au passage, non vérifié : `WebShopService.php:113` enchaîne trois orthographes de
  clé avant `?? []`.
- **Les 35 fichiers JavaScript de `public/assets/js/`.** Lus en diagonale pour y chercher
  des données en dur ; rien trouvé, mais la recherche n'a pas été exhaustive et
  `functions.js` / `main.js` n'ont pas été rattachés à un gabarit.
- **La bascule hors ligne.** `public/sw.js` sert `public/offline.html` quand le réseau
  manque ; je n'ai pas vérifié quels écrans passent par ce chemin plutôt que par les
  messages ci-dessus, ni s'ils se contredisent.

---

## Verdict

La règle est tenue sur les trois modules qui ont été retouchés depuis le 13/08 —
production, personnel, modes — et elle y est tenue proprement, jusqu'aux traductions.
Elle ne l'est nulle part ailleurs.

Ce n'est pas une négligence répartie : c'est **une seule décision, prise une fois dans
`Controller::safeFetch()` (`Controller.php:34`) et répétée dans huit dépôts**, qui consiste
à rendre `[]` quand on ne sait pas. Le canal pour dire ce qui manque existe déjà et
fonctionne (`Controller.php:144-147`, `base.twig:150-153`) ; il n'est branché que deux fois
sur seize.

Les quatre P0 partagent la même signature : l'écran affirme un fait — *aucune tâche*,
*aucune checklist*, *employé inconnu*, *aucun magasin* — là où il devrait dire qu'il ne
sait pas. Ce sont les quatre endroits où la confusion coûte quelque chose : une équipe qui
range la tablette, un opérateur qui se croit radié, une tablette qui ne se connecte pas.

Aucun code de ce dépôt ne **fabrique** de donnée plausible : pas de jeu d'essai, pas de
valeur inventée, pas de repli sur une autre source. La moitié « pas de simulation » de ta
règle est respectée partout. C'est la moitié « ou donne un message d'erreur » qui manque.
