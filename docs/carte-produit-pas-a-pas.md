# Carte produit → tablette cuisine : les données du « pas à pas »

Destinataire : équipe de développement du panel `atelierby.tfbuddy.com`.
Demandeur : réseau L'Atelier by — production en magasin.
Objet : ce que l'**assistant de la carte produit** doit saisir pour que la fiche
descende sur la tablette cuisine (`/kitchen/knowledge/products/{id}`) et
s'affiche en **pas à pas** : une étape par écran, comme la cuisson guidée
d'un Thermomix. Cela couvre aussi la mise sur plaque, les photos « à faire /
à éviter » et le programme du four (Unox).

Sur la tablette, le pas à pas s'ouvre de deux façons :
**Base de connaissances → Pas à pas de production** (`/kitchen/knowledge/book`,
tous les produits du magasin par catégorie), ou le bouton « Lancer le pas à pas »
d'une fiche produit.

La tablette est **déjà prête** : elle lit tous les champs décrits ici. Tant
qu'ils sont absents, elle se rabat sur ce qu'elle sait déduire du texte des
étapes (§ 6). Rien ne casse si le panel livre ces champs un par un.

---

## 0. En bref

| | |
|---|---|
| Endpoint lu par la tablette | `GET /api/v1/products/{id}/technical-sheet/raw?lang_code={fr\|en\|pl\|it\|nl}` (inchangé) |
| Ce qui change | des **champs en plus** dans `preparation.steps[]`, et un tableau `quality_checks[]` à la racine de `data` |
| Compatibilité | tous les nouveaux champs sont **facultatifs** ; un champ absent ou `null` = « pas saisi » |
| Nouvel endpoint | `POST /api/v1/shops/{shopId}/ovens/programs/send` (envoi d'un programme aux fours Unox du magasin), § 5 |
| Photos | même stockage que les photos produit : chemin `r2://…`, servi sous `/shared-assets/…` |
| Textes | traduits comme `step_description`, selon `lang_code` (§ 4) |

---

## 1. L'assistant de la carte produit — écran par écran

Légende : ✅ existe déjà · ★ nouveau · (obl.) obligatoire pour publier
le pas à pas · (rec.) recommandé.

### Écran 1 — Identité et photos ✅

Nom, catégorie, photo principale. Rien à changer. La **catégorie** et le
**nom** servent à la tablette pour choisir la forme dessinée sur la plaque
(§ 2.3) ; la photo principale ouvre le pas à pas.

### Écran 2 — Recette et rendement ✅ + une règle

Ingrédients, sous-recettes, quantités : rien à changer.

- **Rendement (`recipe.yield_quantity`) (obl.)** : pour un produit vendu à la
  pièce (`is_piece_based = 1`), le rendement doit être un **nombre de
  pièces**. La tablette calcule avec : « × 2 fournées = 54 pièces », puis
  « 54 pièces → 6 plaques ».

### Écran 3 — Temps ✅

Types de préparation et durées (`preparation.types[]`). Rien à changer. Ils
alimentent le « temps total » de la couverture, et deviennent des pages
chronométrées tant qu'aucune étape n'est rédigée.

### Écran 4 — Étapes (les SOP) ★ — le cœur

Une liste ordonnée d'étapes, glisser-déposer pour réordonner. Chaque étape est
une carte repliable avec les blocs ci-dessous. Seul le **texte** est
obligatoire. Les autres blocs s'ouvrent à la demande (bouton « + Plaque »,
« + Four », « + Photos »…).

| Bloc | Champ JSON | Saisie | Sur la tablette |
|---|---|---|---|
| Texte (obl.) | `step_description` ✅ | texte, 1 action par étape, ≤ 280 car. conseillé | le texte en très gros ; durées, °C, vitesses, « 3 x 3 » surlignés |
| Titre (rec.) | `step_title` ★ | texte court : « Cuisson », « Façonnage » | titre de la page, rappelé dans la barre du haut |
| Type (rec.) | `step_kind` ★ | liste (§ 2.2) | sur-titre de la page ; règles de publication (§ 3) |
| Durée | `duration_second`, `duration_max_second` ★ | min/s ; « max » pour une plage (12 à 15 min) | **minuteur** qui sonne (bip + vibration), visible d'une page à l'autre |
| Température | `temperature_celsius`, `temperature_max_celsius` ★ | °C ; « max » pour une plage | pastille rouge « 230°C » |
| Vitesse | `speed` ★, `reverse` ★ | texte (« 2 », « cuillère », « turbo ») + case « sens inverse » | pastille bleue « Vitesse 2 », « Sens inverse » |
| Appareil | `equipment` ★ | texte ou liste : « Four Unox BAKERTOP », « Thermomix », « Batteur » | pastille grise |
| Ingrédients de l'étape | `ingredients[]` ★ | cases à cocher parmi les éléments de la recette (écran 2), quantité facultative si on n'en met qu'une partie | « Ingrédients de cette étape » avec la quantité, qui suit les fournées |
| Mise sur plaque | `tray` ★ | lignes × colonnes, nom de la plaque, forme (facultatif) | **schéma de la plaque garnie** + nombre de plaques nécessaires |
| Programme four | `oven_program` ★ | choix dans la bibliothèque Unox **ou** saisie manuelle (§ 2.4) | carte « écran du four » : n° et nom du programme, préchauffage, phases ; bouton « Envoyer au four » |
| Point critique | `critical_note` ★ | texte : « Ne pas ouvrir la porte 5 min », « ≥ 63 °C à cœur » | encadré rouge |
| Photo de l'étape | `photo_path` ★ | 1 photo | photo sous le texte, touchée = plein écran |
| À faire / à éviter | `checks[]` ★ | 1 à 4 photos, chacune « à faire » ou « à éviter » + légende | cadres verts ✓ / rouges ✗, côte à côte, touchés = plein écran |

### Écran 5 — Contrôle final : à faire / à éviter ★

Les photos de vérification **avant la mise en vitrine** : la bonne cuisson, et
les défauts à ne pas vendre (trop pâle, trop cuit, grigne fermée, garniture
débordante…). Champ `quality_checks[]` à la racine. Sur la tablette : page
« C'est prêt », bloc « Contrôle visuel avant la vitrine ».

Recommandation à afficher dans l'assistant : **au moins 1 « à faire » et
1 « à éviter »** pour tout produit cuit en magasin.

### Écran 6 — Conservation, emballage, étiquette ✅

Stockage, température, DLC, réchauffage, emballage, format d'étiquette,
positionnement : rien à changer. Tout s'affiche sur la page finale « C'est prêt ».

### Écran 7 — Aperçu et publication ★

- Un bouton **« Voir sur la tablette »** ouvre
  `https://atelierby.tfbuddy.com/kitchen/knowledge/products/{id}#pas-a-pas`.
  On peut viser une page précise : `#pas-a-pas/3` ouvre la page 3.
- La **jauge de complétude** (§ 3) dit ce qui manque avant publication.

---

## 2. Le contrat JSON

### 2.1 Exemple complet (Baguette Tradition)

Extrait de `data` dans la réponse de
`GET /products/{id}/technical-sheet/raw`. Les champs ✅ existants ne sont pas
tous répétés.

```json
{
  "object": { "id": 2300001, "name": "Baguette Tradition 500 g.", "category_name": "Pains", "is_piece_based": 1 },
  "recipe": {
    "id": 9, "yield_quantity": 27,
    "elements": [
      { "type": "ingredient", "ingredient_id": 501, "name": "Pâton baguette tradition surgelé", "recipe_quantity": 27, "unit_name": "pc" },
      { "type": "ingredient", "ingredient_id": 502, "name": "Farine de fleurage", "recipe_quantity": 50, "unit_name": "g" }
    ]
  },
  "preparation": {
    "types": [ { "id": 2, "name": "Cuisson", "duration_second": 1080 } ],
    "steps": [
      {
        "step_number": 2,
        "step_description": "Fleurer légèrement et poser les pâtons soudure dessous.",
        "step_title": "Mise sur plaque",
        "step_kind": "mise_sur_plaque",
        "ingredients": [ { "ingredient_id": 502, "quantity": 50 } ],
        "tray": { "rows": 3, "cols": 3, "tray_name": "Plaque perforée 600×400", "piece_shape": "baguette" },
        "checks": [
          { "verdict": "do",   "photo_path": "r2://products/2300001/checks/espaces.jpg", "caption": "Espacés de 3 doigts, soudure dessous" },
          { "verdict": "dont", "photo_path": "r2://products/2300001/checks/grigne.jpg",  "caption": "Grigne écrasée : ne pas toucher le dessus" }
        ]
      },
      {
        "step_number": 3,
        "step_description": "Enfourner les plaques et lancer le programme.",
        "step_title": "Cuisson",
        "step_kind": "cuisson",
        "equipment": "Four Unox BAKERTOP",
        "critical_note": "Ne pas ouvrir la porte pendant les 5 premières minutes (buée).",
        "oven_program": {
          "source": "unox",
          "unox_program_id": "<id du programme dans la bibliothèque Unox>",
          "program_name": "BAGUETTE TRADI",
          "program_number": "12",
          "oven_line": "BAKERTOP MIND.Maps",
          "preheat_celsius": 250,
          "trays": 6,
          "phases": [
            { "mode": "mixed",      "temperature_celsius": 230, "humidity_percent": 60, "fan_speed": "2", "duration_second": 300 },
            { "mode": "convection", "temperature_celsius": 225, "humidity_percent": 0,  "fan_speed": "3", "duration_second": 780 }
          ],
          "sendable": true
        },
        "checks": [
          { "verdict": "do",   "photo_path": "r2://…/ok.jpg",    "caption": "Croûte dorée, grignes bien ouvertes" },
          { "verdict": "dont", "photo_path": "r2://…/pale.jpg",  "caption": "Trop pâle : relancer 2 min" },
          { "verdict": "dont", "photo_path": "r2://…/brule.jpg", "caption": "Trop cuite : ne pas vendre" }
        ]
      }
    ]
  },
  "quality_checks": [
    { "verdict": "do",   "photo_path": "r2://…/ok.jpg",    "caption": "Dorée, sonne creux" },
    { "verdict": "dont", "photo_path": "r2://…/brule.jpg", "caption": "Brûlée : au rebut" }
  ]
}
```

### 2.2 Champs d'une étape (`preparation.steps[]`)

| Champ | Type | Contraintes | Absent → la tablette… |
|---|---|---|---|
| `step_number` ✅ | int | ordre d'affichage | trie dans l'ordre reçu |
| `step_description` ✅ | string (traduit) | | — |
| `step_title` | string (traduit) \| null | ≤ 40 car. | n'affiche pas de titre |
| `step_kind` | enum \| null | `mise_en_place`, `preparation`, `faconnage`, `repos`, `mise_sur_plaque`, `cuisson`, `refroidissement`, `finition`, `emballage`, `controle` | pas de sur-titre |
| `duration_second` | int \| null | > 0 ; un minuteur n'est proposé que jusqu'à 24 h | cherche une durée dans le texte |
| `duration_max_second` | int \| null | ≥ `duration_second` | — |
| `temperature_celsius` | number \| null | −40 à 300 | cherche « 180°C » dans le texte |
| `temperature_max_celsius` | number \| null | ≥ `temperature_celsius` | — |
| `speed` | string \| null | tel qu'affiché sur l'appareil | cherche « vitesse 3 » dans le texte |
| `reverse` | bool | défaut `false` | cherche « sens inverse » |
| `equipment` | string (traduit) \| null | | — |
| `ingredients` | array \| null | `[{ "ingredient_id": int, "quantity"?: number }]` pour un ingrédient, `[{ "recipe_id": int, "quantity"?: number }]` pour une sous-recette ; les id sont ceux de `recipe.elements[]` ; `quantity` dans l'unité de la recette, **pour une fournée** | rapproche les noms d'ingrédients cités dans le texte (approximatif). Un tableau vide `[]` veut dire « aucun ingrédient à cette étape » |
| `tray` | object \| null | § 2.3 | cherche « plaque 3 x 3 » dans le texte |
| `oven_program` | object \| null | § 2.4 | — |
| `critical_note` | string (traduit) \| null | | — |
| `photo_path` | string \| null | `r2://…` (§ 2.5) | — |
| `checks` | array \| null | `[{ "verdict": "do" \| "dont", "photo_path": string\|null, "caption": string\|null }]`, 0 à 4 | — |

### 2.3 Mise sur plaque (`tray`)

| Champ | Type | Contraintes |
|---|---|---|
| `rows` | int | 1 à 12 |
| `cols` | int | 1 à 12 |
| `tray_name` | string (traduit) \| null | « Plaque perforée 600×400 », « Grille », « Plaque silicone » |
| `piece_shape` | enum \| null | `baguette`, `loaf` (pain, pavé), `roll` (pistolet, petit pain), `croissant`, `viennoiserie` (pain au chocolat, couque, suisse), `tart` (quiche, tarte, tartissière, flan, entremets), `cake`, `cookie`, `focaccia`, `eclair`, `piece` |

La plaque est dessinée en 600 × 400 vue de dessus : le plus grand des deux
nombres va sur la longueur. Sans `piece_shape`, la tablette reconnaît la forme
au nom et à la catégorie du produit (Baguette → baguette, Quiche → tart…).
Le nom passe avant la catégorie (« Brownies » rangé dans une catégorie de tartes
reste un brownie). Sur le catalogue de Halle (septembre 2026), la forme est
juste pour les familles cuites en magasin. Les FlipFlap, sandwichs et wraps
gardent la forme neutre `piece`. Ne remplir `piece_shape` que si elle se trompe.

**Pièces par plaque = `rows × cols`.** C'est aussi la bonne valeur pour le
plan de production du cockpit, qui arrondit aujourd'hui la production aux
« pièces par plaque » **de la catégorie** (`gp.categories[cat].plaque`, dépôt
consultant_BO). Avec cette donnée par produit, l'arrondi pourra se faire
produit par produit.

### 2.4 Programme four (`oven_program`)

| Champ | Type | Contraintes |
|---|---|---|
| `source` | `"unox"` \| `"manual"` | `unox` = choisi dans la bibliothèque Unox synchronisée (§ 5) |
| `unox_program_id` | string \| null | identifiant du programme côté Unox ; obligatoire si `source = unox` |
| `program_name` | string \| null | **tel qu'il apparaît sur l'écran du four** (non traduit) |
| `program_number` | string \| null | n° ou raccourci du programme sur le four, s'il y en a un |
| `oven_line` | string \| null | « BAKERTOP MIND.Maps », « CHEFTOP-X »… |
| `preheat_celsius` | number \| null | |
| `trays` | int \| null | nombre de plaques/niveaux enfournés ensemble |
| `phases` | array | 0 à 10 phases, dans l'ordre |
| `phases[].mode` | enum \| null | `convection`, `steam` (vapeur), `mixed` (convection + vapeur), `dry` (air sec / extraction) ; une autre valeur s'affiche telle quelle |
| `phases[].temperature_celsius` | number \| null | |
| `phases[].humidity_percent` | int \| null | 0 à 100 |
| `phases[].fan_speed` | string \| null | tel qu'affiché sur le four |
| `phases[].duration_second` | int \| null | |
| `phases[].core_probe_celsius` | number \| null | fin de phase à la sonde à cœur (au lieu d'une durée) |
| `sendable` | bool | **calculé par le panel**, pas saisi : `true` seulement si le magasin a l'intégration Unox active **et** des fours connectés, **et** si le programme existe dans sa bibliothèque Unox. La tablette n'affiche « Envoyer au four » que dans ce cas |

La somme des `duration_second` des phases donne le minuteur de l'étape.

Les modes et réglages réels (vapeur, extraction, vitesse de ventilation,
sonde) dépendent du modèle de four. La liste ci-dessus est le **format
commun** côté panel. La correspondance avec les champs Unox se fait dans la
synchronisation (§ 5), une fois la documentation de l'API Unox en main.

### 2.5 Photos

- Stockage : le même que les photos produit (`photos.main_photo_path`).
  Chemin `r2://products/{id}/…`, résolu par la tablette en
  `https://atelierby.tfbuddy.com/shared-assets/products/{id}/…`.
- Format conseillé : **4:3, ≥ 1200 × 900, JPEG ou WebP, ≤ 1,5 Mo**. Les vignettes
  sont recadrées en 4:3 (`object-fit: cover`), et la photo s'ouvre entière quand on la touche.
- Une photo « à faire / à éviter » se prend **sur un vrai produit, sur le poste
  du magasin**, cadrée sur le défaut. Ce n'est pas une photo marketing.
- Légende (`caption`) : ce qu'on voit, puis quoi faire. « Trop pâle : relancer
  2 min ». ≤ 60 caractères, traduite.

### 2.6 Racine : `quality_checks`

Même forme que `checks` d'une étape : `[{ "verdict", "photo_path", "caption" }]`,
0 à 6. Le contrôle final, affiché sur la page « C'est prêt ».

---

## 3. Règles de l'assistant (jauge de complétude)

Le panel affiche une jauge, sans bloquer l'enregistrement. Le pas à pas
fonctionne même incomplet.

| Règle | Niveau |
|---|---|
| Au moins une étape rédigée | bloquant pour « publié » |
| Produit à la pièce : `yield_quantity` > 0 | bloquant pour « publié » |
| Étape `cuisson` : un `oven_program` **ou** (`temperature_celsius` + `duration_second`) | avertissement |
| Étape `mise_sur_plaque` : un `tray` | avertissement |
| Étape `cuisson` : au moins 1 `checks` « do » et 1 « dont » | avertissement |
| Produit cuit en magasin : au moins 1 `quality_checks` « do » et 1 « dont » | avertissement |
| `oven_program.source = unox` sans `unox_program_id` | erreur de saisie |
| `tray.rows` / `tray.cols` hors 1–12 | erreur de saisie |
| `checks[]` : `verdict` hors `do` / `dont` | erreur de saisie |
| Une étape > 280 caractères | conseil : « coupez en deux étapes » |

---

## 4. Traductions

Comme aujourd'hui pour `step_description`, l'endpoint renvoie la langue de
`lang_code`. Champs à traduire : `step_title`, `step_description`,
`equipment`, `critical_note`, `tray.tray_name`, `checks[].caption`,
`quality_checks[].caption`. Pas `program_name` ni `program_number` : ils
doivent rester **identiques à l'écran du four**.

Si une traduction manque, renvoyer le français plutôt que vide.

---

## 5. Unox : bibliothèque de programmes et envoi au four

### 5.1 Ce qu'on sait de l'API Unox (vérifié le 01/11/2026)

- Unox propose une **« Cloud REST API »**, module complémentaire de sa plateforme
  **Data Driven Cooking (DDC)**, pour « faire communiquer DDC avec vos logiciels »
  (ERP, planification de production).
- DDC gère la **création, la modification et l'envoi de programmes de cuisson
  aux fours**, ainsi que la visualisation et l'export des **données HACCP**.
- Le pilotage à distance est réservé aux fours connectés **Digital.ID**
  (gammes CHEFTOP / BAKERTOP MIND.Maps, CHEFTOP-X, EVEREO, SPEED…).
- **La documentation de l'API n'est pas publique** : endpoints, authentification
  et champs sont à obtenir auprès d'Unox (contact commercial / revendeur, ou
  `info@unox.com`). Rien dans ce document ne suppose leur forme.

### 5.2 Questions à poser à Unox

1. Accès à la Cloud REST API : abonnement DDC requis, prix, environnement de test (sandbox) ?
2. Authentification (clé, OAuth) et rattachement aux comptes : un compte par magasin, ou un compte réseau multi-sites ?
3. Lecture de la **bibliothèque de programmes** : quels champs (id, nom, n°, phases, mode, température, humidité, ventilation, sonde) ?
4. **Envoi d'un programme** à un four ou à un groupe de fours : quel appel ? Peut-on seulement le pousser dans la bibliothèque du four, ou aussi le **présélectionner / lancer** ?
5. Retour d'information : statut de l'envoi, programme en cours, **journal des cuissons / HACCP** par four ?
6. Identification des fours : n° de série ? Comment relier un four à un magasin ?
7. Limites d'appels, webhooks éventuels.

### 5.3 Ce que le panel construit

**a) Configuration par magasin**
Paramètres du magasin → « Fours Unox » : les fours du magasin (n° de série /
identifiant Unox, nom affiché « Four 1 »), et l'intégration active ou non. Les
**identifiants Unox restent au panel** (secrets serveur). Ils ne descendent
jamais vers la tablette ni vers le navigateur.

**b) Synchronisation de la bibliothèque**
Une tâche planifiée (par ex. toutes les heures) et un bouton « Synchroniser »
recopient les programmes Unox dans une table du panel : id Unox, nom, n°,
phases au format commun du § 2.4. L'assistant (écran 4, bloc « Programme
four ») propose alors une **liste de choix** : on ne retape pas les
températures, elles viennent du four. La saisie manuelle (`source = manual`)
reste possible pour un four non connecté.

**c) Envoi au four depuis la tablette**

```
POST /api/v1/shops/{shopId}/ovens/programs/send
Authorization: Bearer <jeton de la tablette, comme les autres appels>
Content-Type: application/json

{ "product_id": 2300001, "step_number": 3, "unox_program_id": "<id>" }
```

Le panel vérifie que le programme appartient bien à cette étape de ce produit,
puis appelle l'API Unox pour l'envoyer aux fours du magasin.

Réponse attendue (le kiosque teste le code HTTP 200/201 et `success`) :

```json
{ "success": true, "message": "Programme envoyé à Four 1 et Four 2" }
```

En cas d'échec, renvoyer un code 4xx/5xx avec `description`. La tablette
affiche alors « Le four n'a pas reçu le programme : choisissez-le à la main ».
Côté tablette, c'est déjà branché : route `POST
/kitchen/knowledge/products/{id}/oven/send` → `TechnicalSheetRepository::sendOvenProgram()`.

**d) Plus tard : le retour du four**
Si Unox expose le journal des cuissons, le panel peut le relier au produit et
à la plaque. On saurait alors à quelle heure tel programme a tourné, combien
de plaques sont sorties, et à quelle température à cœur. C'est la preuve de
cuisson pour l'HACCP, et le « réel » du plan de production du cockpit
(consultant_BO). À cadrer après les réponses d'Unox.

---

## 6. Ce que la tablette sait déjà faire sans ces champs

Tant que les champs ★ ne sont pas livrés, la tablette lit le **texte** des
étapes. Pour que les SOP rédigées aujourd'hui en profitent, les écrire ainsi :

| Écrire | La tablette en fait |
|---|---|
| « Cuire **18 min** à **230°C** » / « **12 à 15 min** » / « **1h30** » | un minuteur (sur la borne basse d'une plage) + pastille °C |
| « Mixer **30 sec** **vitesse 4** », « **sens inverse** » | minuteur + pastille vitesse |
| « **Mise sur plaque 3 x 3** » (le mot *plaque*, *tôle* ou *grille* dans la même étape) | le schéma de la plaque + le nombre de plaques |
| Nommer l'ingrédient comme dans la recette : « ajouter le **beurre** » | rappel « Beurre doux 200 g » sur la page |

Dès qu'un champ structuré est présent, il **fait foi**. Le texte ne fait plus
que compléter.

---

## 7. Livraison proposée, par lots

| Lot | Contenu | Dépend d'Unox |
|---|---|---|
| 1 | Écran 4 « Étapes » : titre, type, durée, température, vitesse, appareil, ingrédients liés, point critique | non |
| 2 | Mise sur plaque (`tray`) + photos d'étape + « à faire / à éviter » (`checks`, `quality_checks`) | non |
| 3 | Programme four **manuel** (`oven_program`, `source = manual`) | non |
| 4 | Fours Unox du magasin + synchronisation de la bibliothèque + choix du programme dans l'assistant | oui (lecture) |
| 5 | `POST /shops/{shopId}/ovens/programs/send` + `sendable` | oui (écriture) |
| 6 | Retour des cuissons / HACCP | oui, à cadrer |

Les lots 1 à 3 ne dépendent de personne et rendent déjà le pas à pas complet
sur la tablette.

---

## 8. Recette

1. Remplir une fiche avec l'exemple du § 2.1.
2. Ouvrir `/kitchen/knowledge/products/{id}#pas-a-pas` sur la tablette
   (1194 × 834, paysage) :
   - couverture : temps total, programme four, mise sur plaque ;
   - page « Mise sur plaque » : la plaque 3 × 3 de baguettes, « Pour 27 pièces : 3 plaques » ;
   - passer à × 2 fournées sur la couverture : « 54 pièces : 6 plaques », quantités doublées ;
   - page « Cuisson » : carte du four, minuteur de 18 min, point critique, photos ✓ / ✗ ;
   - page « C'est prêt » : contrôle visuel.
3. Couper le champ `oven_program.sendable` : le bouton « Envoyer au four » disparaît.
4. Une fiche **sans** aucun champ ★ doit s'afficher comme avant : rien ne casse.

---

Sources Unox (consultées le 01/11/2026) :
[Data Driven Cooking](https://www.unox.com/us_ca/app-and-operating-system/data-driven-cooking/data-driven-cooking/) ·
[Data Driven Cooking — tarifs et fonctions](https://www.unox.com/us_us/app-and-operating-system/data-driven-cooking/data-driven-cooking-pricing/) ·
[Digital.ID](https://www.unox.com/us_us/app-and-operating-system/digitalid-operating-system/digital-id-operating-system/) ·
[Data Driven Cooking 2.0](https://www.foodserviceequipmentjournal.com/unox-unveils-data-driven-cooking-2-0-solution/)
