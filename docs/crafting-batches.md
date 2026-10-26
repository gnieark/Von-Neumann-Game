# Acceptation des lots de fabrications Manny

Les lots de `POST /api/probe/{probeId}/mannies/tasks` composés exclusivement de tâches `craft` utilisent un inventaire de travail partagé pendant leur transaction. Les recettes peuvent être différentes. La limite reste de 100 tâches ; le contrat API reste en v150 et aucune migration n’est nécessaire.

## Traitement

L’admission protège d’abord la sonde, puis le traitement verrouille sa ligne, les Mannies du lot dans l’ordre de leurs identifiants SQL, et ses conteneurs. Les autres Mannies ne sont pas verrouillées pour construire l’inventaire. La lecture des composants consommables, des stocks disponibles et de l’occupation des conteneurs est mutualisée à l’échelle du lot.

Chaque ordre reste évalué dans l’ordre de la requête. Les composants déjà consommés disparaissent de l’inventaire de travail ; les stocks et l’espace libre sont actualisés après les écritures SQL. Quand les composants disponibles s’épuisent, la résolution récursive des recettes recalcule les ingrédients et la durée des ordres suivants. Les fabrications ne disposent pas des objets dont la livraison est encore à venir.

Les débits conservent leurs conditions SQL de disponibilité et l’ordre de consommation des conteneurs. Les composants réservés à un transfert ou provenant des Others restent exclus. Le placement tient compte des filtres, des réservations de sortie et des déplacements internes ; consommer un composant destiné à un déplacement libère aussi la capacité réservée à sa destination. Le deutérium continue d’être débité sur la sonde verrouillée.

Le contexte est détruit à la sortie, y compris en cas d’exception. Un refus annule toute la transaction et conserve les informations `taskIndex` et `mannyId` de l’erreur. Les lots mixtes continuent à consulter l’état produit par chaque action précédente : un rappel peut notamment rendre des ingrédients à une fabrication suivante.

Chaque Manny conserve sa tâche, sa réservation, sa durée et son événement scheduler. Cette modification accélère l’acceptation des ordres ; elle ne regroupe pas les exécutions du scheduler.

## Concurrence et limites

Les livraisons du scheduler prennent le verrou du conteneur de destination et attendent si le lot l’utilise. Les ordres concurrents de la même sonde sont sérialisés ; les autres sondes restent indépendantes. Les conteneurs étaient déjà verrouillés jusqu’au commit dès la première réservation de fabrication ; ils le sont désormais avant la lecture de l’inventaire. La transaction garde l’isolation `READ COMMITTED` existante sous MariaDB.

Le nombre d’écritures reste proportionnel au nombre de tâches. Le coût de chargement et la mémoire du contexte dépendent encore de la taille de l’inventaire, mais les lectures complètes ne sont plus répétées pour chacun des 100 ordres. Les résultats synthétiques ne préjugent pas des délais HTTP en production, des attentes de verrous ou du coût d’autres types de tâches.

## Vérification

- `php tests/SectorTests.php` compare les résultats à ceux du traitement séquentiel : composants épuisés, plusieurs recettes et conteneurs, carburant, ressources réservées, déplacements internes, saturation et filtres. Il vérifie les rollbacks métier/SQL/commit et la réutilisation du service après erreur. Une régression de lecture est détectée par le nombre de lignes et de requêtes SQL, sans seuil de temps dépendant de la machine.
- `php tests/ApiTests.php` couvre notamment un lot de 100 fabrications, les erreurs indexées, une Manny occupée et le mélange rappel/fabrication.
- `php tests/StorageConcurrencyTests.php` et sa variante `--mysql-config=config/database.json` vérifient deux lots concurrents avec un stock insuffisant pour les deux. MariaDB vérifie également une livraison concurrente du scheduler et la progression d’une autre sonde. Les tables MariaDB sont isolées par un préfixe temporaire et supprimées après le test.
- `php tests/BenchmarkCraftingBatches.php` compare les deux parcours sur des bases SQLite en mémoire neuves, avec 2 000 Mannies, 1 000 objets et 1/10/25/50/100 ordres `steel_plate`. La préparation des données est exclue des mesures. Ce script ne lit pas la configuration de connexion de l’application.

Mesure locale indicative du 26 octobre 2026 pour 100 ordres : 3 737 ms et 3 708 requêtes / 712 202 lignes lues sur le parcours séquentiel, contre 65 ms et 1 121 requêtes / 4 412 lignes lues avec l’inventaire partagé. Ces temps mesurent le service sur SQLite en mémoire, sans HTTP ni concurrence ; ils ne constituent pas un engagement de latence en production.
