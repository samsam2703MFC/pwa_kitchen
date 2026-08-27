# État de Kitchen contre la production réelle

Relevé le 26/08/2026 contre `https://atelierby.tfbuddy.com/api/v1`, en croisant
chaque route appelée par Kitchen avec le **swagger de production** (933 routes,
`atelierby.tfbuddy.com/api/swagger/openapi.json`). Le swagger fait foi : un
appel non authentifié rend tantôt 401, tantôt 404, tantôt 500 selon le
contrôleur — il ne dit pas si la route existe.

## Ce qui marche aujourd'hui

Backend présent → l'écran fonctionne avec une vraie session tablette.

| Écran | Route(s) | Swagger |
|---|---|---|
| Connexion (choix magasin) | `GET /public/shops` | ✓ (200 public) |
| Checklists — qui signe | `GET /shops/{id}/schedule`, `/employees` | ✓ (200) |
| Modes de la tablette | `GET /devices/{id}/configuration` | ✓ (200, rend `[]`) |
| Commandes clients | `GET /shops/{id}/orders`, `GET/POST /client-orders`, `…/{id}` | ✓ |
| Clients | `GET /shops/{id}/clients` | ✓ (200) |
| Connaissances — liste produits | `GET /shops/{id}/products/available` | ✓ (200) |
| Fiche produit | `GET /products/{id}/technical-sheet/raw` | ✓ |
| **Parcours de préparation** | `GET /preparation-paths/configured-product-ids`, `/products/{id}/preparation-path`, `/preparation-batch-groups` | ✓ (401 en direct — **déployé sur prod depuis la génération du swagger**) |
| Réclamations | `GET /material-complaint-reasons`, `POST /material-complaints` | ✓ |

## Ce qui NE peut pas marcher : le back n'existe pas

Ces 12 routes sont **absentes du swagger de production** (et 404 en direct).
Kitchen les appelle, ne reçoit rien, et le dit à l'écran sans casser — mais
aucun réglage côté Kitchen ne les fera fonctionner : **elles doivent être
construites côté ERP.**

| Module | Routes absentes |
|---|---|
| **Production — besoins** | `GET /shops/{id}/production/config`, `/production/products`, `/stock`, `/sales/profile` |
| **Production — mise en place** | `GET /shops/{id}/mep`, `POST /shops/{id}/mep`, `POST /shops/{id}/mep/validate` |
| **Production — lancer** | `POST /shops/{id}/production/batches`, `GET /production/pending-count` |
| **Cuisson** | `GET /shops/{id}/baking`, `POST /shops/{id}/baking`, `PATCH /baking/{id}`, `GET /shops/{id}/baking/pending-count`, `GET /shops/{id}/ovens` |

Chaque route a sa forme JSON exacte, champ par champ, dans
`docs/BACKEND_A_FAIRE.md`. La plus importante est `GET /shops/{id}/stock` — sans
elle, ni besoins, ni minimums, ni tri par tension. `sales/profile` peut se faire
en un `GROUP BY` sur `transaction` + `transaction_product`.

## Comment Kitchen se comporte quand ces routes manquent

Vérifié en faisant tourner Kitchen contre un miroir de la production (le bouchon
renvoie 404 exactement sur les routes ci-dessus — `MOCK_PROD_MIRROR=1`) :

- **aucune erreur PHP, aucun écran blanc** — les sept écrans répondent 200 ;
- **production** affiche « Le catalogue de production n'est pas encore servi par
  l'API. L'écran est prêt : il s'affichera dès que l'endpoint répondra. » ;
- **le mode** revient aux menus par défaut et le bandeau nomme la route ;
- tout le reste — checklists, commandes, réclamations, connaissances, fiche
  produit, parcours de préparation — s'affiche avec ses vraies données.

Autrement dit : **Kitchen est prêt. Le chemin critique de « ça doit marcher »
pour production et cuisson est entièrement côté back-office ERP** — les douze
routes du tableau ci-dessus, dans l'ordre de priorité de `BACKEND_A_FAIRE.md`.

## Reproduire ce relevé

```bash
# la carte des routes
curl -s https://atelierby.tfbuddy.com/api/swagger/openapi.json > /tmp/prod.json

# le comportement de Kitchen sans ces back
MOCK_PROD_MIRROR=1 php -S 127.0.0.1:8791 tools/mock-api/index.php
```
