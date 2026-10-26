# Plafond de minage par objet — API v150

`manny.maxMiningManniesPerObject` dans `config/gameplay.json` fixe le plafond,
à **2 000** par défaut (minimum effectif : 1). Il est partagé par tous les
joueurs et toutes les sondes, indépendamment des ressources choisies, du SCUT
et des conteneurs de destination. Il concerne chaque objet accepté par le
minage Manny : astéroïdes, petites planètes et épaves de vaisseaux-mères Others.

Une place est occupée dès l'acceptation de l'ordre, trajet compris. Elle reste
occupée tant que `mannies.current_task` vaut `mining` avec une projection liée
au même événement. Une attente de stockage ou un événement scheduler en échec
ne libère pas la place. Un rappel, une fin de tâche ou la suppression du Manny
la libère ; un retour après rappel ne compte plus. Aucune date estimée ni
activité du worker n'est utilisée pour libérer une place prématurément.

Les départs dépassant le plafond répondent HTTP **409** avec
`mining_target_capacity_reached`. Un batch est intégralement annulé si l'un de
ses ordres dépasse le plafond ; l'erreur contient `details.taskIndex` et
`details.mannyId`. Les tâches déjà présentes au-delà du plafond ne sont pas
annulées : aucun nouveau départ n'est accepté jusqu'à ce qu'une place se libère.

## Transactions et coût

La table `mining_admission_locks` contient un verrou SQL par identifiant
d'objet. Les admissions concurrentes sur le même objet sont sérialisées jusqu'au
commit ou rollback. Les batches acquièrent tous leurs verrous d'admission dans
l'ordre binaire des identifiants avant de traiter leurs membres, tout en
conservant l'ordre demandé pour les actions et leurs réponses.

Le comptage lit `manny_tasks` via l'index existant `idx_manny_tasks_object` et
rejoint `mannies` par sa clé primaire. Il ne lit ni les payloads JSON ni
`scheduled_events`, et n'ajoute aucun compteur persistant à synchroniser.
L'admission de sonde possède la transaction en isolation READ COMMITTED sur
MySQL/MariaDB, afin de voir les admissions validées avant l'obtention du verrou.

Un batch composé uniquement de minages compte une seule fois chaque objet,
puis incrémente son nombre local après chaque démarrage réussi. Ce cache est
toujours supprimé en sortie, y compris après erreur. Un batch mixte recompte
au démarrage de chaque minage : une autre action peut avoir libéré des places.
Les fins concurrentes peuvent rendre le comptage local conservateur, jamais
autoriser un dépassement.

Le scheduler ne prend pas le verrou d'admission. Un verrou sur l'objet A ne
verrouille ni son stock, ni les admissions sur B. Sur SQLite, la sérialisation
globale des écritures reste celle du moteur ; les tests MariaDB vérifient
l'indépendance effective des lignes. Les transactions de batches peuvent
retenir le verrou pendant tout leur traitement : aucune promesse de latence
chiffrée n'est faite sans mesure en charge.

## Mise à niveau explicite

Avant la reprise du nouveau code sur une base existante, arrêter les écrivains
et appliquer la migration additive :

```sh
php scripts/one-shot-scripts/migrate-mining-admission-locks.php --database-config=config/database.json --dry-run
php scripts/one-shot-scripts/migrate-mining-admission-locks.php --database-config=config/database.json --apply
```

Utiliser le chemin de configuration de la base visée. La simulation n'ouvre
aucune connexion. L'application est rejouable et ne modifie ni les stocks, ni
les tâches, ni les fichiers de secteurs. Les lignes de verrou sont créées à la
demande ; il n'y a aucun compteur à initialiser. Le schéma des bases neuves est
déclaré dans `SchemaInitializer::miningAdmissionLockStatements()`. Le code
requiert cette table ; aucune création ni réparation automatique à l'exécution.
L'ancienne table de verrous par couple sonde/objet n'est plus utilisée par les
admissions ; sa suppression n'est pas nécessaire à cette migration additive.

## Vérifications

- `php tests/ApiTests.php` : contrat v150, erreur individuelle et batch atomique
  à la dernière place, indice du membre refusé et réutilisation après rollback.
- `php tests/SectorTests.php` : seuil réel de 2 000, configuration, tous les types
  minables, attentes, échecs, rappels, livraisons, surcapacité préexistante et
  comptage mutualisé.
- `php tests/StorageConcurrencyTests.php` : compétition de deux joueurs pour
  la dernière place et batches portant sur deux objets dans un ordre opposé.
- `php tests/StorageConcurrencyTests.php --mysql-config=config/database.json` :
  mêmes scénarios sur des tables isolées et connexions indépendantes ; vérifie
  aussi qu'un verrou d'admission détenu sur A laisse démarrer B et terminer un
  minage de A. La suite crée puis supprime uniquement ses tables préfixées.
