# Employés de service — comment le front s'y prend

État au 13/08/2026. Ce document décrivait une demande faite au back ; il décrit
maintenant ce qui est branché.

## La demande, rappelée

Quand on marque une tâche faite, la liste ne doit proposer que les personnes
**de service ce jour-là**. Proposer toute l'équipe invite à sélectionner
quelqu'un qui n'était pas là — et le relevé de checklist perd sa valeur de
preuve.

## Ce qui est en place côté front

**Mis à jour le 13/08/2026 : une seule route.**

```
GET /shops/{shopId}/schedule?date=YYYY-MM-DD
```

Elle répond, et elle porte les personnes — identifiant **et** nom. C'est donc
elle, et elle seule, qui dit qui peut signer une tâche ce jour-là.

`GET /franchisee-employees` a été retiré. Il servait à obtenir les noms, que le
planning devait ensuite désigner par identifiant : deux appels, un croisement,
et un point de panne de plus pour une question à laquelle une seule route sait
répondre. Et la bonne — quelqu'un qui n'est pas au planning ne travaille pas,
sa fiche existât-elle.

La lecture est dans `StaffService::peopleOf()`, pure et vérifiée sans réseau par
`bin/staff-test.php` (37 assertions).

### Ce que le front encaisse sans broncher

Ces tolérances ne sont pas de la coquetterie : chacune correspond à une façon
dont la liste se viderait en silence, et une liste vide rend la checklist
inachevable.

- **L'employé à plat ou en fiche imbriquée** : `employee_id` + `name` sur la
  ligne, ou un objet `employee: {id, name}`.
- **Le nom sous plusieurs orthographes** : `name`, `full_name`,
  `employee_name`, `display_name`, `label`, ou `first_name`/`prenom` +
  `last_name`/`nom` recomposés.
- **L'identifiant sous six noms** : `employee_id`, `franchisee_employee_id`,
  `id_employee`, `id_franchisee_employee`, `user_id`, ou `id` **de la fiche
  imbriquée**.
- **L'identifiant en nombre ou en chaîne** : `41` et `"41"` sont la même
  personne. La comparaison se fait en chaînes ; typée, elle dédoublerait
  quelqu'un.

### Deux pièges, et comment ils sont traités

**`id` sur la ligne de planning est celui du SERVICE, pas de l'employé.** Le
lire comme un identifiant de personne attribuerait la tâche au mauvais numéro —
et le relevé serait faux sans que rien ne le signale. Il n'est donc lu que sur
la fiche imbriquée, jamais sur la ligne.

**Deux services dans la journée font deux lignes, pas deux personnes.**
Quelqu'un qui ouvre puis ferme apparaîtrait deux fois dans la modale, et on
douterait d'avoir touché le bon badge. La liste est dédoublonnée par
identifiant.

### Ce qui est exigé de chaque ligne

Un identifiant **et** un nom. Une ligne sans nom est écartée : un badge sans
nom ne se choisit pas, et signer sous « #47 » ne vaut pas mieux que ne pas
signer. Une fiche explicitement désactivée (`is_active: false`, `status:
ARCHIVED`…) est écartée aussi, même inscrite au planning.

### Trois situations, et elles ne se confondent pas

| Situation | Ce que l'écran montre |
|---|---|
| planning servi, des gens dedans | ces personnes-là, et elles seules |
| planning servi, **vide** | « Personne n'est au planning ce jour-là. » |
| planning qui ne répond pas | **personne**, et un bandeau nomme la route |
| planning servi, mais **aucune ligne exploitable** | personne, et le bandeau dit « réponse sans nom d'employé » |

Les deuxième et troisième lignes ne sont pas la même chose, et c'est le point
délicat : un planning **servi et vide** est une réponse, pas une panne. L'une se
règle au back-office, l'autre chez le développeur — les confondre envoie
chercher au mauvais endroit.

La quatrième est le cas qu'on ne devine pas autrement : la route répond 200,
mais dans une forme que le front ne sait pas lire. Sans ce message, l'écran
dirait « personne au planning » un jour où quinze personnes travaillent.

### La date compte

C'est celle de la checklist consultée, pas celle du jour : une checklist se
relit pour hier, et il faut alors savoir qui était de service **ce jour-là**.
`ChecklistController` passe donc `$date`, pas `date('Y-m-d')`.

Une ligne datée d'un autre jour est écartée même si l'endpoint est censé
filtrer. Ce n'est pas de la défiance : une ligne de la veille laissée passer
ferait signer quelqu'un qui n'était pas là.

### Aucun repli

Si la route ne répond pas, on ne propose personne. La version précédente rendait
toute l'équipe active « pour ne pas bloquer » : c'était commode et trompeur — un
trou passait pour un fonctionnement normal, et la route n'était jamais réclamée.

`/shops/{shopId}/employees` reste la source du PIN, côté serveur uniquement —
voir la note ci-dessous. Elle n'alimente plus aucun affichage.

## Note de sécurité, hors sujet mais rencontrée

`ChecklistService::completeTask()` récupère la liste des employés **avec leur
PIN** pour comparer en PHP :

```php
if (($employee['pin'] ?? '') !== $pin) { … }
```

Les PIN de toute l'équipe transitent donc par le front-end à chaque
validation, et la comparaison n'est pas à temps constant. Une vérification
côté API — `POST /employees/{id}/verify-pin`, ou le PIN passé directement à
`mark-as-done` — éviterait de les faire circuler. À arbitrer avec vous : ce
n'est pas une régression introduite ici, mais l'écran de saisie du PIN vient
d'être retravaillé, autant le signaler.
