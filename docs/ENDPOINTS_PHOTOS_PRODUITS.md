# Photos produits — ce que l'API doit fournir

État au 31/07/2026.

## Pourquoi

La liste des produits compte 573 entrées, dont des séries entières qui ne se
distinguent que par la fin de leur nom : « Assiette - Carpaccio »,
« Assiette - Pêche au thon », « Assiette - Quinoa & Thon rouge »,
« Assiette - Salade César »… Coiffées de la même icône, elles obligent à lire
chaque intitulé jusqu'au bout. En cuisine, c'est la photo qui tranche.

Sur la fiche, même raisonnement : on compare ce qu'on a produit à ce qu'on
doit obtenir. La photo y était reléguée dans un coin de l'en-tête, en
180×130 px.

## Ce qui est en place côté front

**Fiche produit** — la photo passe en tête, pleine largeur, bornée à 42 % de
la hauteur d'écran (38 % en portrait) pour que la lecture commence sans
défiler. Elle s'ouvre au clic dans la visionneuse existante. Ce chemin
**fonctionne déjà** : `ProductDetailModel` porte un `ProductPhotoModel` qui
résout les chemins `r2://…` sur `SHARED_FILES_URL`.

**Liste des produits** — chaque carte peut afficher sa photo en tête, en 4/3.
`ProductModel` accepte désormais `main_photo_path`, à plat ou sous une clé
`photos`, et résout `r2://` de la même façon.

Sans photo, la carte retombe sur un bandeau de 74 px avec l'icône : la liste
reste compacte et lisible. C'est un repli, pas l'objectif.

## Ce qui manque

### La photo dans la réponse de liste

`GET /shops/{shopId}/products/available`

Aujourd'hui cet endpoint ne renvoie pas de champ photo — c'est la seule chose
qui manque pour que la liste s'anime :

```json
{ "main_photo_path": "r2://products/6700106/main.jpg" }
```

ou, si vous préférez rester cohérent avec le détail :

```json
{ "photos": { "main_photo_path": "r2://products/6700106/main.jpg" } }
```

Les deux formes sont acceptées par le front.

### Une vignette, idéalement

573 photos pleine résolution sur une seule page, c'est plusieurs dizaines de
mégaoctets sur une tablette en Wi-Fi de magasin. `loading="lazy"` limite la
casse, mais une variante réduite servie par l'API — 400 px de large suffisent
pour une carte — ferait la différence :

```json
{ "main_photo_thumb_path": "r2://products/6700106/main_400.jpg" }
```

À défaut, une pagination de la liste devient nécessaire.

## À vérifier avant de conclure

Je n'ai pas pu appeler l'API depuis cet environnement. Deux points restent à
confirmer sur le serveur :

1. `SHARED_FILES_URL` pointe sur `https://<hôte>/shared-assets`, construit à
   partir de l'hôte courant. Si les fichiers sont servis depuis un autre
   domaine — comme l'API l'est déjà via `KITCHEN_API_BASE` —, il faudra la
   rendre configurable de la même manière.
2. Que les chemins renvoyés par le détail se résolvent effectivement, c'est-à-dire
   qu'une photo s'affiche aujourd'hui sur une fiche réelle. Si le détail
   n'affiche rien non plus, le problème est en amont du front.
