# Captures pour la fiche landing

La landing publique (`samsam2703MFC/landing_tfb`) ne fabrique pas d'images :
elle reprend celles que ce dépôt publie **dans ce dossier**, au déploiement
suivant. Tant qu'il est vide, la fiche « Cuisine » n'a rien à montrer.

## Le nom du fichier fait le rattachement

`cuisine-<clé>.png` — la clé est celle de la fonction dans `.tfb/module.json`.
`cuisine-checklists.png` se range donc sous « Listes de contrôle ». Un nom qui
ne correspond à aucune clé reste rattaché au module entier, sans erreur mais
sans précision.

Cinq sont publiées. Un écran — `dashboard` — agrège des chiffres rattachables à
une boutique identifiable : il est marqué `sensible` dans le plan et ne sort
qu'avec `--sensibles`, depuis une instance de démonstration.

| Fichier | Écran |
|---|---|
| `cuisine-checklists.png` | `/checklists` — Listes de contrôle |
| `cuisine-produits.png` | `/knowledge/products` — Produits et fiches techniques |
| `cuisine-commandes.png` | `/orders` — Commandes clients |
| `cuisine-nouvelle-commande.png` | `/orders/new` — Prise de commande |
| `cuisine-reclamations.png` | `/complaints` — Réclamations fournisseur |
| `cuisine-dashboard.png` | `/dashboard` — Tableau de bord du jour · **sensible** |

## L'anonymisation

Juste avant chaque déclic, le script retire du rendu ce qui identifie le
réseau : le logo du client devient « RESEAU DEMO », chaque point de vente et
chaque personne reçoit un pseudonyme **stable** — « Boutique 1 » est la même
boutique sur toutes les captures, sinon on ne pourrait plus la suivre d'un
écran à l'autre. Les villes deviennent « Ville », les courriels et les numéros
de téléphone sont neutralisés, les références de commande masquées.

Les photos de pièces jointes et le texte libre saisi en magasin sont **floutés**
plutôt que remplacés : personne ne relit ce qu'un équipier écrit dans une
réclamation, et une phrase inventée ferait passer une invention pour une
capture.

Les chiffres ne sont pas touchés. C'est pour ça que l'écran qui en agrège est
écarté tant que la source n'est pas une instance de démonstration.

`--sans-anonymat` lève la règle, à n'utiliser que sur des données fictives.

## Les produire — automatique

**Actions → captures-landing → Run workflow.** Le workflow ouvre une session
sur l'instance, prend les écrans en 1194 × 834 densité 2 — le kiosque est un
poste tablette, posé au passe — et les commite ici s'ils ont changé. Le push
déclenche `notify-landing`, qui demande à la landing de resynchroniser. Il
repasse aussi tout seul le 1er de chaque mois.

Trois secrets à renseigner une fois, dans Settings → Secrets and variables →
Actions :

| Secret | Valeur |
|---|---|
| `CAPTURE_BASE` | `https://185.180.206.46/kitchen` |
| `CAPTURE_USER` | l'identifiant d'un compte cuisine |
| `CAPTURE_PASS` | son mot de passe |

Prenez un compte dont la boutique a des checklists actives et des commandes :
les écrans seront remplis plutôt que vides, et c'est ce qui fait la différence
sur la fiche.

Tant que `CAPTURE_BASE` est vide, le workflow ne fait rien et reste vert.

## Les produire — à la main

```bash
npm i -D playwright && npx playwright install chromium

 CAPTURE_USER='…' CAPTURE_PASS='…' \
 node tools/capturer-ecrans.mjs \
   --module=cuisine \
   --base=https://<serveur>/kitchen
```

Les images sont écrites directement dans `docs/landing/`. La connexion demande
d'abord la boutique — le script prend la première proposée — puis l'identifiant
et le mot de passe. Sans session, tous les écrans sauf `/auth` renvoient vers
la connexion, et le script le dit plutôt que d'enregistrer six pages de login.

`--attente=5000` si les listes sortent vides — c'est le temps laissé aux
données avant le déclic.

## Ce qui se passe ensuite

`pipeline/sync-captures.mjs` les télécharge au déploiement de la landing, les
range sous `apps/web/public/captures/cuisine/` et crée la ligne correspondante
dans `landing_captures`. Titre, ordre et rattachement se corrigent ensuite dans
la console d'administration, sans toucher à ce dépôt.
