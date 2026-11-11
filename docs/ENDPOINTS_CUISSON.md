# Module Cuisson — contrat d'API

État au 01/08/2026. **Aucun de ces endpoints n'existe encore.** Le front est
écrit contre ce contrat : le jour où l'API répond, l'écran fonctionne sans
modification. Mocks exécutables dans `docs/mocks/cuisson/`.

Module distinct de Production. Production répond à « qu'est-ce qu'on doit
sortir aujourd'hui » ; Cuisson répond à « qu'est-ce qui est au four en ce
moment, et à quelle heure ce sera en rayon ». Le premier se consulte le matin,
le second toute la journée.

---

## Forme des réponses

**Le corps est renvoyé nu, sans enveloppe.** C'est la convention de toute
l'application : `ApiClient::get()` construit lui-même
`['success' => <code HTTP 2xx>, 'data' => <corps décodé>]`. Un serveur qui
répondrait `{"success": true, "data": {…}}` ferait donc arriver la charge utile
sous `$response['data']['data']`, et tous les dépôts liraient à côté.

L'échec se dit par le **code HTTP**. Le corps ne porte que le détail, sous
`description` — la seule clé que `post()` et `patch()` savent remonter.

À noter pour l'écriture du serveur : `ApiClient::post()` et `::patch()`
**ne remontent pas le corps de la réponse**, seulement `message`,
`inserted_id`, `description` et le code. Les réponses décrites plus bas pour
les écritures restent utiles pour l'avenir, mais le front n'en dépend pas : il
relit systématiquement après une écriture.

---

## Le principe

Une **fournée** traverse trois étapes, dans cet ordre, et une seule à la fois :

```
Préparation  →  Cuisson  →  Finition  →  [délai]  →  en rayon
```

Chaque étape occupe une ressource différente. Le four est libre dès la cuisson
finie ; c'est la finition qui décale la mise en rayon. **Un produit n'est
vendable qu'après la finition ET son délai de mise en rayon** — c'est le seul
horaire qui intéresse le client.

### Les deux natures de finition

| | Au lot | À la pièce |
|---|---|---|
| Exemple | ressuage d'une baguette | nappage d'un éclair |
| Durée | fixe | quantité × durée unitaire |
| Ressource | des grilles | **quelqu'un** |
| Doubler la quantité | ne change rien | double la durée |

Cette distinction n'est pas cosmétique : elle décide si une fournée mobilise un
poste de travail ou seulement du temps. Un champ unique « durée de finition »
ne saurait pas la représenter, et sous-estimerait toutes les fournées nappées.

---

## 1. Les fours

```
GET /shops/{shopId}/ovens
```

```json
[
  {
    "id": 1,
    "name": "Four 1 — Rotatif",
    "levels": 8,
    "temp_min": 160,
    "temp_max": 250
  }
]
```

Facultatif pour l'écran, qui affiche le nom porté par chaque fournée. Devient
nécessaire le jour où l'on veut vérifier la charge des fours.

---

## 2. Le plan de cuisson du jour

```
GET /shops/{shopId}/baking?date=YYYY-MM-DD
```

`date` optionnelle, défaut : aujourd'hui. L'écran ne propose pas de sélecteur
de date — une cuisine ne cuit pas pour hier.

```json
{
  "date": "2026-08-01",
  "server_time": "07:20",
  "batches": [
    {
      "id": 5501,
      "id_product": 6700210,
      "name": "Éclair chocolat",
      "category_name": "Pâtisserie",
      "quantity": 36,
      "unit_name": "pc",
      "id_oven": 2,
      "oven_name": "Four 2 — Ventilé",
      "temperature": 180,
      "prep_start": "06:00",
      "prep_minutes": 30,
      "cook_start": "06:40",
      "cook_minutes": 20,
      "finish_type": "PIECE",
      "finish_label": "Nappage",
      "finish_per_piece_minutes": 1,
      "shelf_delay_minutes": 10,
      "status": "FINISHING",
      "prep_started_at": "2026-08-01 06:02:00",
      "cook_started_at": "2026-08-01 06:41:00",
      "finish_started_at": "2026-08-01 07:01:00"
    }
  ]
}
```

### Champs

| champ | rôle | obligatoire |
|---|---|---|
| `id` | identifiant de la fournée | oui |
| `name`, `quantity` | ce qu'on cuit, combien | oui |
| `id_oven`, `oven_name`, `temperature` | où et à quelle température | recommandé |
| `prep_start`, `prep_minutes` | créneau de préparation, `HH:MM` | oui |
| `cook_start`, `cook_minutes` | créneau de cuisson | oui |
| `finish_type` | `LOT` ou `PIECE` | oui |
| `finish_minutes` | durée fixe — **si `LOT`** | conditionnel |
| `finish_per_piece_minutes` | durée unitaire — **si `PIECE`** | conditionnel |
| `finish_label` | « Ressuage », « Nappage », « Refroidissement »… | recommandé |
| `shelf_delay_minutes` | délai entre fin de finition et mise en vente | recommandé |
| `status` | voir ci-dessous | oui |
| `*_started_at` | horodatage réel de chaque étape | recommandé |
| `id_product` | pour ouvrir la fiche technique | facultatif |

**`server_time`** évite une classe entière de bugs : la tablette d'atelier est
rarement à l'heure, et un écran qui décide seul qu'une fournée est en retard
parce que son horloge dérive de six minutes fait perdre confiance en tout le
reste. Le front s'y cale, avec repli sur l'heure locale.

### Statuts

| statut | étape affichée | bouton proposé |
|---|---|---|
| `PLANNED` | préparation, à venir | *(aucun — en attente)* |
| `PREPARING` | préparation, en cours | Préparation terminée |
| `READY_TO_BAKE` | préparation, terminée | Enfourner |
| `BAKING` | cuisson | Sortir du four |
| `FINISHING` | finition | Finition terminée |
| `DONE` | — | *(retirée de l'écran)* |

Le statut fait autorité, pas l'horloge. Une fournée dont l'heure de cuisson est
passée mais qui est encore `PREPARING` reste en préparation : le planning est
une intention, le statut est un fait.

**Les horaires restent le plan, pas le réel.** Quand `*_started_at` est fourni,
le front en tire le temps restant ; sinon il retombe sur le créneau planifié et
la barre de progression devient indicative. Le contrat vaut dans les deux cas.

---

## 2 bis. Programmer une fournée

```
POST /shops/{shopId}/baking
```

```json
{ "id_product": 6700106, "quantity": 24, "source": "SHORTFALL", "id_employee": 41 }
```

**Endpoint à créer.** C'est le pendant de la boucle ouverte par l'écran de
production : quand une tuile annonce « À produire 24 pc », ce POST crée la
fournée. Elle entre au plan, et le produit bascule aussitôt en préparation,
puis cuisson, puis finition — au lieu d'être découvert en rupture vitrine
vide.

| champ | rôle | envoyé |
|---|---|---|
| `id_product` | le produit | toujours |
| `quantity` | multiple de la taille de fournée, ajusté à l'écran | toujours |
| `source` | `SHORTFALL` — programmée depuis un manque constaté | toujours |
| `id_employee` | qui l'a demandée | quand il est connu |

**Le front n'envoie aucun horaire, et n'en veut aucun en retour qu'il aurait
choisi.** La PWA ne connaît ni l'occupation des fours, ni les fournées des
autres postes, ni les temps de repos. Elle dit quoi et combien ; c'est le
serveur qui place la fournée — four, `prep_start`, `cook_start`, durées — et
qui renvoie la fournée complète, dans la forme du `GET`. Un front qui
inventerait un créneau produirait un plan que le back-office contredirait à la
requête suivante.

`quantity` est déjà un multiple de la taille de fournée : l'écran l'arrondit au
lot supérieur avant de l'afficher, et les boutons `−` / `+` montent par lot.
Le serveur peut la réarrondir, mais il ne devrait pas avoir à la refuser.

**La réponse doit porter `inserted_id`** — l'identifiant de la fournée créée, à
la racine, à côté de la fournée elle-même. C'est le seul champ que le client
HTTP de la PWA remonte d'un POST, et l'écran s'en sert pour enchaîner
directement sur le plan avec la nouvelle fournée mise en avant. Sans lui, le
lancement fonctionne mais retombe sur le plan entier, à charge pour l'équipe
d'y retrouver sa fournée.

---

## 3. Faire avancer une fournée

```
PATCH /baking/{batchId}
```

```json
{ "status": "BAKING", "id_employee": 12, "allotted_minutes": 25 }
```

Un seul champ obligatoire : `status`. Le serveur horodate lui-même le passage —
deux tablettes peuvent appuyer à quelques secondes d'intervalle, et l'arbitrage
ne peut pas vivre dans le navigateur.

| champ | rôle | envoyé |
|---|---|---|
| `status` | l'étape demandée | toujours |
| `id_employee` | à qui l'ordre a été attribué | quand quelqu'un a été désigné |
| `allotted_minutes` | temps imparti à l'étape, corrigé à l'écran | seulement s'il diffère du plan |

**`allotted_minutes` — champ à accepter côté serveur.** Le panneau d'ordres
permet d'ajuster la durée d'une étape par pas de 5 minutes avant de la lancer :
une pâte qui a mal poussé prendra dix minutes de plus, et le reste de la
journée en dépend. Il porte la durée de l'étape que le `status` demandé
concerne — `prep_minutes` pour `PREPARING`, `cook_minutes` pour `BAKING`,
`finish_minutes` pour `FINISHING`. Le serveur devrait la persister et
replanifier les horaires en aval (enfournement, finition, mise en rayon), puis
renvoyer la fournée à jour.

Le champ n'est **envoyé que s'il a été touché** : sans lui, le plan du serveur
fait foi, et le comportement est celui d'avant. Un serveur qui l'ignore ne
casse donc rien — l'écran affichera simplement la durée planifiée au prochain
rafraîchissement, ce qui est visible et honnête.

Réponse : la fournée mise à jour, dans la forme du `GET`. Le front la réaffiche
telle quelle plutôt que de deviner le nouvel état.

Les transitions admises sont celles du tableau ci-dessus, dans l'ordre. Un saut
d'étape (`PLANNED` → `BAKING`) doit être refusé côté serveur : enfourner sans
avoir préparé n'existe pas en atelier, et l'accepter fausserait les horaires de
mise en rayon de toute la journée.

> **Question ouverte** : faut-il un PIN employé, comme pour les checklists ?
> Le front est écrit sans. `id_employee` est envoyé quand il est connu.

### D'où vient la liste des employés

```
GET /shops/{shopId}/employees
```

Le même endpoint que les checklists. Le front n'en garde que `id`, `name` et
l'indicateur de présence ; le PIN ne sort jamais du serveur applicatif.

L'indicateur de présence est lu sous plusieurs noms — `on_schedule`,
`is_on_schedule`, `on_shift`, `is_working`, `is_present`, `scheduled_today` —
et **son absence n'est pas « personne n'est là »** : quand aucun de ces champs
n'est servi, toute l'équipe est proposée et l'écran écrit pourquoi. Un filtre
annoncé mais inopérant tromperait plus qu'il n'aiderait.

Voir docs/ENDPOINTS_EMPLOYES_PLANNING.md.

---

## 4. Compteur, pour la pastille du menu

```
GET /shops/{shopId}/baking/pending-count
```

```json
{
  "preparing": 1,
  "baking": 2,
  "finishing": 3
}
```

Facultatif — sans lui, l'entrée de menu n'affiche pas de pastille.

---

## Comportement du front en attendant

| Endpoint muet | Ce que montre l'écran |
|---|---|
| `baking` | « le plan de cuisson n'est pas encore servi par l'API » |
| `ovens` | rien de particulier — les noms de four viennent des fournées |
| `pending-count` | pas de pastille |

Ni page blanche, ni fausse liste vide. Une liste vide affichée à la place d'un
endpoint muet ferait croire qu'il n'y a rien au four.

L'écran se relit **toutes les 30 secondes** : le plan bouge en continu, et une
fournée sortie par un collègue doit disparaître de la tablette d'à côté sans
que personne ne rafraîchisse.
