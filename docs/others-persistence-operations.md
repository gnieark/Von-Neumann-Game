# Persistance et transactions Others — lots O0 à F0

## Référence O0

Base de travail : `141f035`, intégration de main v139 et des extractions S0–S4. La campagne d’intégration avait validé 4 062 assertions API, 196 secteur, 245 tests Python et 25 contrôles de concurrence/migration sur SQLite et MariaDB 10.11.18 (`READ-COMMITTED`). Ces résultats constituent la référence, pas une validation des changements ci-dessous.

L’inventaire initial compte 189 sites SQL dans `OthersService`, le verrou de compte/idempotence dans `ApiKernel` et les accès connexes de `ProbeReinstantiationService`. `OthersAdminService` utilise déjà les repositories. La connexion partagée relie commandes, interruptions, scheduler, stockage et réinstanciation.

| Parcours | Entrées et persistance | Unité atomique / verrous attendus | Effets et invariants |
| --- | --- | --- | --- |
| Inventaire, transfert | HTTP, `others_inventory_*`, actions, événements | deux vaisseaux ordonnés, auxiliaire, action | contenu et capacité réservés ensemble ; un règlement |
| Fabrication/réparation | HTTP/worker, inventaire, crafts, auxiliaires | vaisseau, auxiliaire, action | débit, acteur, sortie réservée et événement communs |
| Récolte/minage/rappel | HTTP/worker, harvests, swarm, auxiliaires, planète | vaisseau, auxiliaires, action | bilan extrait/consommé/stocké ; carburant prioritaire |
| Déplacement/annulation | HTTP/worker, mouvements, vaisseaux, auxiliaires | vaisseau, auxiliaires, actions et transferts | carburant et échéance uniques ; interruptions causales |
| Flotte collective | HTTP, même parcours par vaisseau | transaction de commande englobante ; refus métier par entrée | réponse partielle `created/ignored/blocked` conservée ; erreur technique annule la commande |
| Tâches auxiliaires collectives | HTTP, même parcours par auxiliaire | transaction de commande englobante | comportement de refus collectif existant conservé |
| Missile Others/sonde | HTTP/Manny/worker, items, launches, projectiles, history | porteur, acteur, lancement/action, cible | une munition, un lancement, une résolution |
| Laser | HTTP/worker, locks, stock, dommages | porteur, action, cible | un laser actif ; carburant comptabilisé jusqu’à l’échéance causale |
| Destruction/abandon | dégâts/départ, flotte, inventaires, actions, réservations | porteurs et acteurs avant actions ; mêmes verrous que les transferts | pertes ou épave explicites ; compteurs et alertes uniques |
| Largage | HTTP, inventaire, pile de secteur | vaisseau puis inventaire | débit unique ; intention durable pour l’objet dérivant |
| Lecture | HTTP/observation, flottes, inventaires, actions | projections bornées sans verrou d’écriture inutile | propriété vérifiée ; coordonnées relatives à la frontière HTTP |
| Idempotence | toutes commandes Others, player, keys, audit | compte puis transaction métier | clé liée au joueur/méthode/chemin/corps/réponse ; pas de second effet |

## Anomalies identifiées avant correction

- Le worker général charge une action sans verrou puis effectue ses conséquences avant sa transition terminale : deux workers peuvent produire deux sorties.
- Déplacement, transfert de carburant et laser prennent certaines décisions sur des états chargés avant leur transaction ; certains chemins ne prennent aucun verrou racine.
- Le largage, l’épave et les auxiliaires dormants écrivent les fichiers avant commit SQL. Leur marqueur ne rend pas ces écritures annulables.
- Récolte et minage modifient également les secteurs au cours des transactions ; leur audit doit couvrir les pannes, pas seulement l’extraction SQL.
- Les nouvelles extractions doivent conserver les opérations conditionnelles et le coût des collections. Elles ne doivent pas simplement transférer les règles métier dans un repository monolithique.

## Frontières retenues

Les repositories Others sont répartis par actions/acteurs, inventaires, production, mouvements, combat, destructions et cibles. Les règles métier, durées, recettes, aléas déterministes et décisions d’interruption restent dans `OthersService`. Le protocole HTTP/idempotence est confié à un service de commande ; les repositories n’acceptent aucun SQL venant du métier et ne retournent aucune connexion ni aucun statement.

Les lots sont validés par extraction, puis par corrections accompagnées de scénarios de panne/concurrence. Les résultats, budgets et éventuelles limites sont complétés au fil des validations.

## Implémentation et propriétaires de transaction

- **O1** : `ActionRepository`, `InventoryRepository`, `ProductionRepository` et `ActorRepository` portent les écritures conditionnelles. Les règles de recette, de capacité, de durée, de réparation et de rendement restent dans le service. Les identités d’objets et les participants d’essaim sont traités par blocs de 200.
- **O2** : `MovementRepository` porte les transitions. Les commandes relisent le porteur sous verrou ; les workers verrouillent les porteurs concernés, l’auxiliaire puis l’action. L’identifiant de l’événement courant empêche un événement de départ rejoué de déclencher l’arrivée. Les relations de la projection de flotte sont chargées en une requête ; les lectures sous verrou nécessaires aux écritures restent propres à chaque acteur.
- **O3** : `CombatRepository` et `TargetRepository` portent les lancements, projectiles, lasers, candidats et événements de dommage. Les cibles mobiles sont relues sous verrou avant la résolution. La fin de préparation d’un missile de sonde recharge son porteur et sa Manny et refuse un porteur terminal. Les probabilités et clés déterministes existantes sont conservées.
- **O4** : `DestructionRepository`, `OthersSectorService` et l’outbox commune `sector_effects` remplacent les écritures de fichiers au milieu des transactions Others. La réinstanciation passe par `ProbeReinstantiationRepository` et le même protocole d’abandon durable.
- **O5** : `OthersCommandService` possède l’unité de commande HTTP, son verrou de compte, l’idempotence et l’audit. `ApiKernel` conserve validation d’en-tête, empreinte canonique, autorisation et présentation publique. Le repository d’idempotence ne connaît plus `ApiResponse`. Aucun accès PDO n’est exposé au métier.

La commande HTTP possède la transaction englobante. Un appel direct de service ou un worker en devient propriétaire s’il n’en existe pas. Les participants ne valident pas la transaction de leur appelant. Une erreur technique remonte au propriétaire ; les interblocages/occupations connus ont **trois tentatives maximum**, avec rollback complet entre les tentatives. Les callbacks après commit sont exécutés hors de cette boucle : un problème de publication ne rejoue jamais une commande déjà validée.

Une flotte conserve ses réponses partielles. Chaque membre refusé revient à son point de sauvegarde, y compris ses intentions de secteur ; les membres acceptés restent dans la commande. Une erreur technique annule l’ensemble de la transaction HTTP. Sans transaction HTTP englobante, les appels indépendants conservent leurs validations individuelles.

Les interactions combat/stockage peuvent découvrir une racine cible après le verrou de l’émetteur. Les erreurs d’interblocage sont alors reprises par le propriétaire, jamais absorbées par un participant. Le verrou de sonde existant participe aux callbacks de commit, sans ajouter de reprise automatique aux parcours historiques qui écrivent encore directement des fichiers.

## Bilan des destructions et interruptions

Avant la destruction, les constructions et transferts de stockage suivent leur règlement causal existant. Les transferts d’inventaire et de carburant encore actifs sont interrompus dans la même transaction : réservations source et destination libérées, acteur libéré, action terminale et événement annulé. Le verrou des deux porteurs sérialise cette interruption avec une livraison concurrente.

Le contenu physique du vaisseau-mère, ressources réservées comprises, alimente son épave ; ses missiles deviennent des objets dérivants. Le carburant du réservoir, le contenu des autres vaisseaux dissous et les composants sans équivalent récupérable restent perdus, conformément au bilan antérieur. Les ingrédients d’une fabrication sont consommés au lancement ; une fabrication interrompue ne crée aucune sortie. Les capacités réservées, fabrications, récoltes, lasers et actions du porteur sont terminés. Les auxiliaires déployés deviennent des objets dormants ; leur recherche utilise un index d’identités en mémoire. Le compteur de destruction est attribué au seul passage effectif du vaisseau à son état terminal.

## Publication SQL → secteurs

`sector_effects` accepte `add_object`, `consume_object` et `patch_objects`. Le dernier format contient les objets avant/après nécessaires à l’opération. Les intentions et leur événement sont insérés dans la transaction métier. `sector_effect_locks` fournit une racine SQL par secteur ; elle protège les décisions d’extraction et de récupération. Sous SQLite, une écriture vide dans cette table d’infrastructure prend l’intention d’écriture avant les lectures de décision.

Après commit, une livraison immédiate est tentée. En cas d’échec, l’effet reste en attente et son événement permet la reprise. Une livraison immédiate réussie annule l’événement devenu inutile. La livraison garde le verrou SQL du secteur jusqu’à son accusé SQL : une commande ne peut donc pas lire l’ancien fichier puis manquer l’intention qui vient d’être livrée. Les observations incluent les intentions en attente ; un écrivain ayant chargé une ancienne projection ne peut pas les écraser. Les écritures passent toujours par le verrou de fichier et le contrôle de révision. Un contenu inattendu est refusé et enregistré dans `last_error`, jamais écrasé silencieusement.

Les workers parcourent les intentions du secteur par pages de 100 et par identifiant croissant, y compris lorsqu’un événement plus récent est reçu en premier. Le marqueur d’opération est écrit avec le contenu du fichier. Une interruption après le renommage du fichier et avant l’accusé SQL se reprend sans seconde conséquence. Il s’agit d’une livraison durable et rejouable, sans transaction distribuée entre SQL et fichiers.

Le journal `others_cross_store_operations` ne sert plus à choisir un chemin d’exécution. Ses lignes anciennes restent disponibles pour l’historique et la suppression administrative ; les nouvelles opérations utilisent uniquement l’outbox commune.

## Migration d’une installation S0–S4 existante

1. Arrêter les workers et suspendre les commandes qui écrivent les secteurs ou les entités concernées. Sauvegarder SQL et le répertoire d’univers ensemble. Répéter la procédure sur une copie avant la bascule.
2. Avec le nouveau code disponible mais les processus toujours arrêtés :

   ```sh
   php scripts/one-shot-scripts/migrate-others-persistence.php --database-config=config/database.json --universe-path=data/universe --dry-run
   php scripts/one-shot-scripts/migrate-others-persistence.php --database-config=config/database.json --universe-path=data/universe --apply
   ```

   Adapter les deux chemins à l’installation. La simulation n’écrit ni SQL ni fichiers. L’application ajoute la racine de verrou et les index, étend la contrainte de type d’effet et convertit les anciennes intentions non livrées dont le bilan SQL est validé. Le script connaît les contraintes de colonne et de table MariaDB ; SQLite reconstruit la table en conservant ses lignes.
3. Le script refuse une ancienne ligne `sql_applied=0` : son bilan est ambigu et doit être réconcilié explicitement à partir de la sauvegarde avant de relancer. Une épave déjà présente n’est pas créditée une seconde fois. Les lignes prises en charge portent `status=migrated` ; leur livraison est désormais suivie dans `sector_effects`.
4. Démarrer uniquement le nouveau code et reprendre les workers. Vérifier la livraison des intentions et les éventuels `sector_effects.last_error`. Puis rouvrir les commandes.

La migration est rejouable. Elle n’est pas exécutée automatiquement au chargement d’un service et n’a pas été appliquée à la base de développement ou à une base de production pendant ce chantier.

## Budgets et garde-fous F0

Référence O0 : les projections de flotte utilisaient déjà une lecture agrégée ; la sélection de N identités d’inventaire utilisait une seule clause `IN` sans borne, et la réservation de N objets faisait N écritures. Ces deux derniers coûts sont désormais répartis en blocs de 200. Les budgets portent sur le chemin nominal ; le test d’interblocage injecté contrôle séparément la limite de trois tentatives.

| Parcours testé | Tailles | Borne |
| --- | --- | --- |
| Projection d’une flotte et de ses relations | 1, 10, 100 vaisseaux | 1 requête ; nombre de lignes proportionnel aux vaisseaux demandés |
| Identités d’inventaire sélectionnées | 1, 200, 201, 500 | `ceil(N/200)` requêtes ; au plus 201 paramètres |
| Réservation d’identités / participants | blocs de 200 | au plus 202 paramètres par mise à jour et 600 par insertion d’essaim |
| Commande de mouvement de flotte, idempotence et audit compris | 1, 10, 100 | au plus `30N + 10` requêtes ; au plus 20 paramètres par requête |
| Relecture/livraison d’intentions de secteur | pages de 100 | taille de lecture bornée ; curseur sur l’index secteur/statut/identifiant |

`tests/PersistenceArchitectureTests.php`, également intégré à `ApiTests.php`, analyse les tokens PHP : dépendances PDO, appels SQL, SQL dans le métier, exposition d’une connexion et SQL fourni par l’appelant à une méthode publique de repository. Des contre-exemples vérifient les alias PDO et les paramètres de SQL renommés ; les commentaires sont ignorés.

Le contrôle couvre `src/Service`, `src/Http` et les interfaces publiques de `src/Repository`. Les exceptions SQL sont fermées : infrastructure `Database`, repositories, scripts de migration explicites et tests. Deux migrations historiques restent identifiées individuellement par leur rôle (`SectorStorageMigration`, `DetachedContainerJsonMigrationService`), sans exemption globale de `Service/`. Deux dettes hors extraction sont gelées par empreinte : statistiques (`UniverseStatsService`) et traduction d’exception d’unicité des trajectoires (`AsteroidTrajectoryService`). Leur prochain lot doit retirer la dépendance avant toute évolution. La suppression de compte dans les scripts reste un lot de maintenance distinct, pas une autorisation d’ajouter du SQL au métier migré.

## Validation du 24 septembre 2026

- `php tests/ApiTests.php` : **4 116 assertions**, dont budgets, reprise après panne, ancien schéma SQLite, migration et architecture.
- `php tests/SectorTests.php` : **196 assertions**.
- `python3 -B -m unittest discover -s scripts/others_control/tests -v` : **245 tests**.
- `php tests/StorageConcurrencyTests.php` : **83 contrôles** SQLite, avec processus et connexions indépendants.
- Même lanceur avec `--mysql-config=config/database.json` : **85 contrôles** MariaDB 10.11.18, isolation **READ-COMMITTED**, tables préfixées isolées ; deux contrôles supplémentaires vérifient la migration de contrainte MariaDB.
- Les courses Others couvrent notamment fabrication/réparation/récolte et doubles achèvements, capacité et munition disputées, idempotence simultanée, transfert contre départ/destruction, récolte contre annulation, fabrication contre destruction, préparation contre destruction du porteur, impact contre départ de la cible, laser contre départ, annulation contre arrivée et destruction contre départ.
- Syntaxe PHP et `git diff --check` vérifiés. Le contrat public commun reste **v139**.
