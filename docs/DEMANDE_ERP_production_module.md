# Demande ERP — Module de pilotage de production (Kitchen)

**De :** équipe Kitchen (PWA tablette atelier)
**Contexte :** le module « Pilotage de la production » (4 étapes : Matin → MEP·Stock → Recuisson → Réductions) est construit et fonctionne en lecture sur les endpoints existants. Trois compléments côté ERP débloquent les étapes d'écriture et la gestion par lot. Tout a été vérifié sur la production (`atelierby.tfbuddy.com`, boutique 2 « Corbais », relevés du 27/08/2026).

---

## 1. Contrat du `PATCH /api/v1/shops/{shopId}/products/{productId}/inventory`

**Pourquoi.** L'étape 2 du flux (« valider la mise en place → ajouter au stock vitrine ») a besoin d'écrire le stock du produit fini. La route existe au Swagger mais est une *runtime route* : **ni corps de requête, ni réponse documentés**. Nous ne codons aucune écriture à l'aveugle.

**Demande.** Documenter (ou confirmer) :
- le corps exact — par ex. `{ "in_stock": 42 }` ? incrément (`{ "delta": +30 }`) ou valeur absolue ?
- la réponse (le stock résultant ?) ;
- les droits requis (le token tablette shop suffit-il, ou faut-il un scope admin ?).

**Usage prévu.** À la validation d'une MEP ou d'une fournée sur la tablette : une écriture par produit validé. Aucune autre écriture.

## 2. Lecture des mouvements produits horodatés (lots de cuisson)

**Pourquoi.** La gestion des invendus par lot (« ce plateau de baguettes sort à 8 h 31, tenue 24 h, à solder à 18 h ») exige de savoir **quel volume est sorti du four à quelle heure**. Aujourd'hui :
- `POST /api/v1/product-movements` existe (runtime route, contrat non documenté) mais il n'existe **aucun GET** pour relire les mouvements ;
- `in_stock` (catalogue `products/available`) vaut 999/10000000 presque partout — une sentinelle, pas un stock tenu.

**Demande.**
- documenter le contrat du `POST /product-movements` (types de mouvement : production, vente, démarque ?) ;
- ajouter un **`GET /shops/{shopId}/product-movements?date_from=&date_to=`** renvoyant les mouvements horodatés `{ product_id, type, quantity, created_at }`.

**Ce que ça débloque.** Stock du produit cuit reconstruit lot par lot ; heure de sortie + `shelf_life_minutes` ⇒ péremption par lot ; ventes flash ciblées sur le lot qui expire (l'admin des promos existe déjà : `POST /admin/promotions/scheduled-product-discount`).

## 3. Remplissage des champs déjà en place (back-office, pas de dev)

Relevé réel sur les **583 produits** de la boutique 2 :

| Champ | Relevé | Attendu pour le module |
|---|---|---|
| `is_pdm` | **0 produit** coché | cocher les produits préparés la veille (toggle « Matin / La veille » de l'étape 1) |
| `is_prepared_before_sales` | 4 produits | idem — les deux drapeaux sont lus |
| `reheating_time_minutes` / `reheating_temperature_celsius` | 4 produits | renseigner les produits recuits (l'étape 3 affiche « recuisson 10 min · 180 °C ») |
| `in_stock` | sentinelle (999…) | devient fiable via le point 1 |
| `sector_name` (ventes) | null partout | facultatif — le module groupe déjà par `group_name` |
| Parcours de préparation | **3 produits** configurés (1300003, 1300023, 6700096) | configurer les gammes des produits à four (heures de fin et fournées calculées automatiquement dès qu'une gamme existe) |

## 4. Fin de journée (volet « Solder ») — contrats d'écriture

La tablette envoie désormais (et affiche l'erreur en nommant la route si le back refuse) :

- **`POST /shops/{shopId}/products/{productId}/waste`** — corps envoyé :
  `{ "quantity": n, "reason": "end_of_day", "photo_base64"?: "…" }`.
  ⚠ Le Swagger du 26/08 ne documente que le **GET** sur ce chemin : confirmer que le POST existe (sinon l'ajouter), et confirmer/corriger le corps (photo de preuve comprise).
- **`POST /product-movements`** — corps envoyé :
  `{ "id_shop": s, "id_product": p, "quantity": n, "type": "IN", "source": "kitchen_end_of_day" }`.
  Route présente au Swagger, corps non documenté : confirmer/corriger.
- **Vente rapide** : aucune route boutique n'existe pour déclarer une vente flash depuis la tablette (les promos sont sous `/admin/promotions/...`, scope admin). Demande : un `POST /shops/{shopId}/scheduled-product-discounts` (produit, %, fenêtre horaire) utilisable avec le token boutique. En attendant, la validation reste locale à la tablette.

## 5. Rappel — demande déjà transmise

Le filtre **`sales_daypart_id`** sur `GET /shops/{id}/statistics/sales/product-category-groups` (voir `DEMANDE_ERP_daypart_filter.md`) reste la clé de la prévision **par créneau** (Matin / Midi / Après-midi). Les 3 créneaux sont déjà définis dans l'admin ; aucun endpoint de ventes ne les consomme encore.

---

### Priorités proposées

1. **P1 — Contrat `PATCH inventory`** : débloque l'étape 2 (écriture MEP), petite (documentation).
2. **P2 — `GET product-movements`** : débloque les lots + invendus par lot (l'objectif « pas de poubelle, pas de vide »).
3. **P3 — Remplissage back-office** (`is_pdm`, recuisson, gammes) : zéro développement, tout le module s'enrichit immédiatement.
4. **P4 — `sales_daypart_id`** : prévision par créneau (déjà spécifié).
