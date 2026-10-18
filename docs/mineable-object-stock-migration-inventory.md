# Stocks minables SQL — inventaire du lot 0

Date : 16 octobre 2026. Mise à jour : 17 octobre 2026. Ce rapport conserve l’inventaire initial et suit les adaptations réalisées jusqu’au lot 3 ; aucune bascule de production n’a été exécutée. Le plan directeur reste `var/doc-diverse-non-publiée/plan-migration-stocks-asteroides-sql.md`.

## Périmètre et écarts constatés

Le périmètre explicite retenu par le plan est `Asteroid` et `DormantConstruct::SUBTYPE_OTHERS_MOTHERSHIP_WRECK`, même épuisés. Le prédicat de stockage ne doit pas dépendre de la quantité restante. Les planètes et les autres sous-types de constructs restent exclus.

**Décision de périmètre confirmée :** le moissonnage collectif Others `planet_harvest`, les stocks des planètes et l'optimisation des verrous de ce moissonnage sont exclus. Les Others sont moins nombreux et leur activité est maîtrisée selon l'utilisateur ; la priorité est la contention du minage Manny. Ce choix ne démontre pas une absence de concurrence ni un coût nul des verrous Others : les protections actuelles du moissonnage restent en place.

**Distinction nécessaire :** `OthersService::startAuxiliaryMining` correspond au minage individuel `auxiliary_mine`, distinct du moissonnage collectif. Il accepte les astéroïdes, les planètes et tout construct ayant un sous-type non nul, donc aussi `others_auxiliary` et `others_auxiliary_wreck`. `MannyService::isMineableObject` ne permet que les épaves de vaisseau-mère parmi les constructs. Le périmètre de stockage reste explicitement limité aux astéroïdes et épaves de vaisseau-mère ; leurs writers Others doivent partager le stock SQL et le verrou de source avec les Mannies. Les planètes et constructs auxiliaires gardent leur stockage propre. Cette cohérence des sources partagées n'exige pas une refonte générale des verrous des acteurs Others.

Écarts relevés au lot 0 et suivi :

- `AuthService::starterIronAsteroid` et `ProbeReinstantiationService::starterIronAsteroid` créent des astéroïdes hors du générateur de secteurs. Raccordés à `createMineable` au lot 2.
- `SystemImpactPhaseHandler::resolvePlanet` crée des fragments d'astéroïdes depuis une planète exclue du stockage SQL. Leur création initiale reste explicite ; les transformations d'astéroïdes existants ne recalculent jamais depuis la masse.
- `SectorContentGenerator::neighborSignature` hachait `SectorContent::toArray`, stocks compris. Corrigé au lot 2 : seuls les descripteurs persistants participent à cette signature.
- `AccountDeletionService::deletePlayer` préparait une liste de Mannies et publiait les abandons avant sa transaction. Corrigé au lot 2 bis : relecture sous verrous, détachement SQL et intention dans la même transaction, publication après commit. Le protocole général de fermeture/drainage reste au lot 4 bis.
- `OthersSectorService` prend le verrou de secteur avant de composer ses objets ; le débit du minage individuel Others doit sortir de ce chemin pour les seules sources SQL. Le moissonnage planétaire conserve ce chemin et ses protections. Les sérialisations/effets communs doivent préserver les stocks planétaires et ne jamais recopier en JSON les stocks SQL d'autres objets du secteur.
- `StorageTransaction::run` rejoint une transaction existante. L'admission ne peut pas être une closure imbriquée dans une livraison : sa validation doit précéder cette transaction.

## Lecteurs, writers et sérialisations

| Famille | Points d'entrée | Adaptation nécessaire |
| --- | --- | --- |
| JSON de secteur | `SectorFileRepository::load/saveLocked`, `SectorContent::toArray/fromArray`, `SolarSystem`, `OrbitingBody`, `UniverseObjectFactory` | Descripteurs récursifs sans stock des sources couvertes ; aucune reconstruction au chargement |
| Modèles | `Asteroid::__construct/fromArray/toArray/withId/withResourceAmounts`, `DormantConstruct` | Distinguer création initiale, descripteur et représentation hydratée ; registre explicite de catégories |
| Lecture du jeu | `SectorService::getOrCreateSector/withSqlDetachedContainers`, `SectorObservationService`, `AutonomousUnitObservationService` | Une lecture SQL groupée ; la projection d'un effet non publié n'établit pas la présence d'une source |
| Génération | `SectorContentGenerator`, `SectorService::createSector`, `AuthService`, `ProbeReinstantiationService` | Réservation d'identité et stock avant publication durable ; mêmes attributions au replay |
| Minage Manny | `MannyService::startMining/withTaskLock/depleteMiningTarget/transferMiningResourcesToProbe/transferMiningResourcesToDetachedContainer`, `MiningTaskHandler::start/refresh` | Un seul vecteur arrondi pour débit/crédit, relecture tâche/capacité, transaction commune ; source absente ne livre rien |
| Minage individuel Others sur source couverte | `OthersService::startAuxiliaryMining/completeAuxiliaryMining`, persistence `production->finishAuxiliaryMining` | Même stock SQL, verrou de source et transaction débit/cargaison/action que les autres mineurs ; les sources exclues gardent leur stockage propre |
| Moissonnage collectif Others — exclu | `OthersService::startHarvestLocked/processHarvest/extractHarvestResources`, action `planet_harvest` | Conserver stocks planétaires, réservations, fonctionnement et verrous ; seule la non-régression des composants communs est requise |
| Motorisation | `MotorizeAsteroidTaskHandler::refresh` | Nouvelle identité durable, transfert SQL et mise à jour des références, publication rejouable |
| Trajectoires | `AsteroidTrajectoryService::create`, `AsteroidTrajectoryPhaseProcessor`, `SystemImpactPhaseHandler`, `SectorTransferPhaseHandler`, `BlackHoleOrbitPhaseHandler` | Verrouillage des sources avant mutation ; transit conserve le stock ; snapshots opérationnels sans autorité sur le stock |
| Fusion/fragmentation | `SystemImpactPhaseHandler::mergeAsteroids/fragmentAsteroids/resolvePlanet`, `MaterialDistributionCalculator` | Consommation unique des sources, nouvelle attribution, résidu déterministe ; source SQL manquante contribue zéro |
| Métadonnées | `RefuelMotorizedAsteroidTaskHandler`, `SculptDuckAsteroidTaskHandler`, `WaypointBookmarkService`, inspection de `DormantConstruct` | Sauvegarder le descripteur sans réinjecter un stock hydraté périmé |
| Création d'épaves | `OthersService::createMothershipWreck`, `DormantConstruct::fromOthersMothership`, `OthersPersistenceMigration` | Stock et intention dans la transaction de destruction ; replay de publication sans réinitialisation |
| Effets | `SectorEffect::apply`, `OthersSectorService::saveSector`, `SectorEffectRepository`, `SectorEffectService` | Comparaisons de descripteurs ; coordination des opérations structurelles et publication après commit |
| Outils | `scripts/add-deuterium-asteroid-alert.php`, `add-dormant-construct.php`, `one-shot-scripts/name-generated-asteroids.php`, `migrate-mining-to-terminal-events.php` | Créations via le service canonique ; anciens scripts de transformation explicitement incompatibles ou adaptés |
| Composition | Les trois constructions de `SectorService` dans `AppFactory`, fixtures et scripts autonomes | Repository SQL obligatoire à la bascule, sans chemin JSON facultatif |

Les appels `toArray` servent également aux historiques et réponses API : conserver une représentation métier et introduire une méthode distincte de descripteur. Les réponses publiques ne gagnent aucun champ. Les coordonnées restent internes.

## Identités et références

| Création | Format actuel | Longueur usuelle |
| --- | --- | --- |
| Générateur | 20 caractères hexadécimaux, hash coordonnées/type/index | 20 |
| Astéroïde initial de joueur | préfixe configurable + `-` + hash de 16 caractères | 29 avec `starter-iron` |
| Motorisation | `mtr_` + 24 caractères hexadécimaux aléatoires | 28 |
| Fusion | `ast_merge_` + hash de 20 caractères | 30 |
| Fragments | `ast_frag_` + hash de 20 caractères | 29 |
| Épave vaisseau-mère | `others-mothership-wreck-` + hash de 20 caractères | 44 |
| Astéroïde administratif de deutérium | `deuterium-asteroid-` + hash de 24 caractères | 43 |

Les préfixes configurables exigent une validation à l'allocation ; la longueur usuelle n'est pas une borne universelle. Retenir des IDs de 1 à 255 octets ASCII sans espace, comparés avec sensibilité à la casse. Vérifier aussi le format retenu par les réponses et validateurs d'ordres lors de la résolution d'une collision.

Le registre durable proposé est `mineable_object_identities(object_id PRIMARY KEY, creation_key UNIQUE NULL)`. `creation_key` est le SHA-256 de l'identité stable d'opération et de l'emplacement de création ; les attributions historiques réservées sans création récupérable ont `NULL`. Un replay retrouve l'ID mais ne recrée jamais un stock supprimé. Le registre ne porte ni présence, ni ressources, ni description. La table de stocks référence ce registre, sans cascade supprimant les identités. Les inserts de stock et d'identité sont dans la même transaction appelante.

Ce schéma est maintenant déclaré dans `SchemaInitializer::mineableObjectStockStatements` : `VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin` sous MariaDB, `TEXT COLLATE BINARY` sous SQLite ; ressources `DOUBLE`/`REAL`, version positive et contraintes de non-négativité. `MineableObjectStockRepository` exige la transaction appelante pour toute mutation. Le transfert d'identité/fusion utilise la même primitive de verrou que le débit ; le service de cycle de vie devra encore coordonner les intentions structurelles. Une absence de stock reste distincte d'une ligne à zéro via la version interne nullable de la projection.

Références à prendre en compte dans le manifeste par occurrence : `manny_tasks.object_id/target_object_id`, charges utiles des événements actifs, `asteroid_trajectories.asteroid_id/target_object_id` et snapshots d'attachements, `sector_effects.object_id/payload_json` (avant/après imbriqués compris), `others_actions.payload_json`, `others_auxiliaries.object_id`, `detached_storage_containers.target_object_id`, `sector_storage_transfers.object_public_id`, `probe_damage_warnings.object_id`, références et waypoints JSON. Une copie historique ne compte pas comme une deuxième source. Un contexte insuffisant pour départager deux occurrences doit rester une anomalie, sans remplacement textuel global.

Les historiques de trajectoires et effets encore présents fournissent des anciennes identités récupérables. L'audit borne ces parcours et ne parcourt pas les événements terminaux du scheduler ; il ne prétend pas retrouver les IDs disparus de toutes les traces.

## Matrice des protections actuelles

| Opération | Acquisitions constatées | Risque pour la bascule |
| --- | --- | --- |
| Lancement Manny | `MannyService::withProbeLock` → ligne sonde → lecture engagements/capacité → tâche | Remplacer par garde d'admission et sérialisation locale du couple sonde/source, sans réservation entre sondes |
| Fin Manny | `withTaskLock` → sonde, ou Manny pour les tâches de transfert → stockage → secteur SQL → `flock` | Ne retirer aucune protection avant adaptation des destinations et du nettoyage |
| Rappel Manny | `recallManny` → sonde ; rappel de conteneurs → `withMannyLock` | Le rappel peut finaliser une récolte ; même protocole que la fin scheduler |
| Mouvement | `ProbeMovementService` → sonde → listes de Mannies/présences | Relire chaque Manny sous verrou local avant changement d'oubli ; décision de présence préalable à la livraison |
| Transfert Manny | sonde → Manny → transfert → conteneur embarqué → dépôt/conteneur détaché | Ordre à conserver et étendre à tous les writers de capacité |
| Transfert Others | vaisseau → auxiliaire → action → transfert → dépôt | Ajouter le stock couvert au même ordre de familles |
| Moissonnage planétaire Others — exclu | vaisseau/actions → coordination de secteur par `OthersSectorService` → publication des effets | Conserver les protections ; leur suppression et leur optimisation ne conditionnent pas la livraison |
| Motorisation | sonde/tâche → `saveSector` → secteur → fichier | Nouvelle identité et source communes au minage ; effet durable hors transaction de récolte |
| Trajectoire | `withLockedTrajectory` → handler → secteur/sonde/Others | Inversion possible avec trajectoire créée sous verrou de sonde ; préparer toutes les racines avant la mutation |
| Fusion/fragmentation | trajectoire → lectures secteur → sauvegarde secteur | Verrous des sources triés et publication rejouable ; pas de décision depuis un objet ancien |
| Destruction/réinstanciation | liste de Mannies préparée avant transaction → suppression SQL et effets | Admission fermée puis drainage ; liste finale relue, annulations métier avant suppression |
| Suppression de compte | liste de Mannies → fichiers d'abandon → transaction SQL | Même protocole de fermeture/drainage sur toutes les sondes concernées |

Convention cible : admission courte séparée ; racines d'acteurs nécessaires, tâches/actions/trajectoires, coordination de secteur pour les seules opérations structurelles, identités de sources triées, stocks et inventaires dans un ordre de familles fixe (conteneurs embarqués, dépôts, détachés), puis lignes de ressources. Ne jamais remonter vers un acteur ou un secteur depuis un verrou de stock. Les finalisations sans mutation structurelle n'acquièrent pas le secteur. Les ensembles de destinations sont déterminés puis revalidés sous leurs verrous ; une modification de cet ensemble provoque une reprise, pas une acquisition inversée.

Le verrou de source porte d'abord sur l'identité durable : il existe encore après disparition du stock. Une lecture simple manquante retourne quatre zéros sans insertion ; une mutation ne restaure pas une ligne manquante. L'allocation d'une nouvelle identité repose sur l'unicité SQL, jamais sur un simple SELECT préalable.

Le verrou de sonde ne protège pas seulement le minage : `ProbeStorageService` réserve/place ressources, objets et Mannies dans une capacité commune. Les transferts et fabrications doivent participer aux mêmes verrous de destination avant de retirer l'enveloppe du minage. Le réservoir `neumann_probes.deuterium_stock` garde son verrou propre.

Cette convention cible concerne les chemins adaptés par le chantier ; elle n'étend pas le retrait des verrous au moissonnage planétaire exclu. Les lots suivants doivent vérifier qu'une Manny et un auxiliaire Others minant le même astéroïde ou la même épave couverte ne prélèvent pas deux fois ses ressources. Pour `planet_harvest`, vérifier seulement que les changements de sérialisation et d'effets ne modifient ni les stocks des planètes, ni les résultats de récolte/annulation ; aucun objectif de réduction des verrous n'est ajouté.

## Publication des créations et transformations : décision validée

Le 16 octobre 2026, l’utilisateur a validé la réutilisation de `sector_effects` et `SectorEffectService`, avec extension explicite à la création initiale des secteurs. Cette décision précise le protocole du chapitre 5.2 du plan ; son intégration pour les créations est réalisée au lot 2 et son extension au cycle de vie au lot 3 est décrite plus bas.

- Une identité stable de création permet à deux demandes concurrentes du même secteur de rejoindre la même opération. L’intention conserve le descripteur exact, avec les IDs attribués, noms et caractéristiques ; une reprise ne relance pas la génération.
- La réservation des identités, la création ou transformation des stocks, l’intention de publication et l’événement scheduler de reprise sont validés dans une même transaction SQL. Le descripteur ne contient pas les ressources des sources couvertes ; les stocks planétaires restent préservés.
- Après commit, tenter immédiatement la publication atomique du fichier avec sa marque d’opération appliquée, puis acquitter l’intention. Le scheduler reprend les publications interrompues sans réexécuter les mutations de stocks. Une interruption après écriture du fichier et avant acquittement doit seulement conduire à reconnaître la publication déjà effectuée.
- Un nouvel objet devient utilisable après publication effective. Une publication échouée diffère l’accès concerné. Une seconde transformation des mêmes objets attend la résolution de la première ; les publications de plusieurs fichiers sont ordonnées et rejouables, sans prétendre les rendre atomiques ensemble.
- Aucun replay ne recharge un stock ni ne ressuscite un objet supprimé ultérieurement. Le minage ordinaire ne produit aucune intention de secteur et n’acquiert aucun verrou de fichier pour ce protocole.

Validation réalisée au lot 2 : créations concurrentes du même secteur sur SQLite et MariaDB ; rollback avant commit, arrêt après commit avant publication, après publication avant acquittement ; replay après minage ou suppression ultérieure. Un échec d’écriture du fichier conserve stock et intention, et remet l’événement de publication en attente. La reprise ne réinitialise pas le stock. Les garanties des transformations générales sont décrites dans la réalisation du lot 3 ci-dessous.

## Admission et pertes de sonde : choix du lot 4 bis

Le protocole livré le 17 octobre est détaillé en fin de rapport. `probe_operation_gates` porte la fermeture, `probe_operations` les seules admissions actives, `probe_losses` la preuve durable de perte. Le minage n'utilise plus l'enveloppe exclusive générale de sonde. La destruction ferme les admissions puis attend leur résolution sans conserver le verrou de sonde ; elle le prend seulement pour finaliser le nettoyage. Le verrou local du réservoir demeure.

Les missiles en vol et les trajectoires d'astéroïdes initiées poursuivent leur progression. Les opérations entrantes sont identifiées depuis les projections canoniques `manny_tasks` et le payload résiduel du scheduler, pas depuis un `probeId` supposé présent sur tous les événements. Les planètes et le moissonnage collectif Others restent exclus du périmètre.

## Outil d'audit livré

```bash
php scripts/one-shot-scripts/audit-mineable-object-stock-migration.php \
  --database-config=PATH --universe-path=PATH \
  --max-files=250000 --max-rows=100000 > /tmp/mineable-stock-audit.json
```

Les deux chemins sont obligatoires. Le script n'initialise aucun schéma, ne génère aucun secteur et ne publie aucun effet. SQLite est ouvert en lecture seule ; MariaDB utilise une transaction READ ONLY en REPEATABLE READ. Les requêtes sont paginées par 400 lignes, les rapprochements de Mannies sont groupés. Une limite atteinte rend l'inventaire invalide. Codes de sortie : 0 inventaire sans anomalie bloquante, 1 anomalies, 2 erreur d'invocation/lecture. Ce résultat ne constitue pas une validation de bascule.

Le rapport contient les hashes des fichiers et stocks normalisés par occurrence, sous-totaux, maxima, stocks invalides, doublons, snapshots distincts, références opérationnelles, états des trajectoires et rapprochements SQL/JSON des Mannies oubliées. Il ne recalcule pas les stocks absents depuis la masse. Les divergences de cargaison sont signalées pour décision documentée, sans copie ni addition. Les effets en attente doivent être drainés ou convertis explicitement.

L'utilisateur a confirmé que les ressources absentes des astéroïdes correspondent à leur épuisement. L'option d'audit `--missing-resources=zero` prépare explicitement ces normalisations, chacune listée avec son emplacement et les ressources concernées. Elle préserve les quantités présentes, ne réécrit aucun fichier et ne constitue pas un fallback du jeu. Sans cette option, l'audit conserve le contrôle strict. Les stocks absents d'autres catégories et les valeurs présentes invalides restent des anomalies.

Les floats ne garantissent pas une résolution de 0,0001 au-delà de 2^39. Cas reproductible : `(549755813888.0 + 0.0001) - 549755813888.0` donne environ `0.0001220703`. L'audit signale cette borne ; aucune tolérance élargie ni changement vers DECIMAL n'est introduit.

La couche d'arithmétique refuse explicitement une mutation non nulle atteignant cette borne, plutôt que de masquer une perte. Les valeurs observées sont très inférieures à cette limite. Une occurrence réelle demanderait un arbitrage numérique. Les paramètres PDO sont formatés à quatre décimales pour éviter la troncature implicite par le réglage PHP `precision`.

## Résultat de l'audit autorisé du 16 octobre

Sources : `/home/gnieark/copieuniverseprod`, et `var/database-prod.json` en transaction SQL READ ONLY. Aucune modification de production ni de la copie JSON. Rapport brut intégral : `/tmp/vng-mineable-lot0-prod-audit-complete.json`. Bornes : 250 000 fichiers, 100 000 lignes par table ; aucune borne atteinte lors du passage intégral.

| Mesure | Résultat |
| --- | --- |
| Fichiers JSON parcourus | 204 486 |
| Astéroïdes présents dans les fichiers | 82 997 |
| Astéroïdes en transit SQL, absents des fichiers | 23 |
| Épaves de vaisseau-mère couvertes | 2 |
| Sources et IDs uniques, transit compris | 83 022 |
| Doublons d'ID détectés | 0 |
| Longueur maximale constatée | 44 octets |
| Autres constructs exclus | 1 033, sans sous-type |
| Mannies oubliées rapprochées | 41, aucune divergence détectée |
| Mannies abandonnées JSON | 24 |
| Trajectoires SQL | 491 : 458 capturées, 9 perdues, 1 sans effet, 23 en transit |
| Intentions de secteur parcourues | 1 080 ; aucune en attente dans cet instantané SQL |
| Événements non terminaux ou échoués parcourus | 1 857 |
| Événements Manny échoués à annuler avec rappel lors de la migration | 2 |

**Décision utilisateur du 16 octobre 2026 :** à la migration, annuler les événements Manny en échec et déclencher le rappel des Mannies concernées via le mécanisme métier existant. Il ne s’agit pas d’une nouvelle politique de reprise des erreurs en fonctionnement. Le script du lot 6 recensera les échecs dans l’instantané cohérent de maintenance, tracera annulation et rappel dans le manifeste et garantira une reprise sans double rappel. Vérifier la tâche courante pour ne pas écraser une mission ayant remplacé un ancien échec ; consigner les Mannies disparues, déjà rentrées, déjà rappelées ou impossibles à rappeler, sans recréation ni restitution supposée de ressources. Programmer les rappels après conversion canonique, sans relancer le scheduler général avant la réouverture. Cette décision est enregistrée ; aucune annulation ni aucun rappel de production n’a été exécuté.

**Correction du diagnostic initial :** les 72 anomalies ne sont pas toutes des objets sans champ de stock. Il y a 65 champs `resourceAmounts` absents et 7 vecteurs dont seule la clé `ice` est absente. Ces 7 objets contiennent encore **86 512 métaux**, à conserver. La règle d'épuisement validée donne quatre zéros aux 65 premiers et seulement `ice=0` aux 7 autres. La proposition par occurrence et les hashes sont enregistrés dans `var/mineable-exhausted-normalizations-20261016.json` (`proposal_not_applied`). Il ne s'agit pas d'une migration appliquée.

Totaux bruts des 82 950 sources dont le vecteur était complet : deutérium 33 084 095,6898 ; métaux 541 839 529,6688 ; glace 126 933 334,5704 ; composés carbonés 121 266 489,4727. Ajouter les 86 512 métaux explicitement préservés donne **541 926 041,6688 métaux** dans l'inventaire normalisé proposé. Les autres totaux restent identiques. Maximum par source observé : 32 243,8383 unités de glace, très inférieur à la limite de résolution numérique.

Les références sans occurrence trouvée restent visibles avec une liste de candidats vide ; l'auditeur ne prétend pas encore distinguer chaque référence historique légitime d'une référence active cassée. Cette résolution complète appartient au manifeste de migration. Les fichiers copiés et la base active ne sont pas un instantané commun : ces résultats permettent de préparer le chantier, mais ne remplacent pas la répétition de maintenance sur une copie SQL/JSON cohérente.

## Validation et état des lots

- L'outil est couvert par `tests/Support/MineableObjectStockAuditTests.php`, exécuté depuis `php tests/SectorTests.php` : fichiers imbriqués, épuisement, exclusions, snapshots, transit, bornes, doublons, invalidité numérique, Mannies oubliées et absence d'écriture.
- Audit de production : parcours intégral terminé, normalisation d'épuisement validée par l'utilisateur et préparée sans application. La répétition sur copie cohérente reste requise au lot 6.
- Lot 1 : schéma, repository, arithmétique, import vérifiable et tests de rollback/concurrence implémentés. `migrate-mineable-object-stock-schema.php` n'applique que le DDL explicite, en simulation par défaut ; aucun import n'a été exécuté sur la production. Les lectures groupées de 801 sources utilisent 3 requêtes. Les courses de débit, collisions/replays d'identité et non-résurrection passent sur SQLite et MariaDB de développement ; MariaDB démontre en plus qu'une source distincte peut commettre pendant qu'une autre reste verrouillée.
- Lot 2 : `MineableDescriptors` et `toDescriptor/fromDescriptor` séparent les fichiers canoniques des objets hydratés. L'ancien champ JSON des sources couvertes est refusé au chargement ; aucune reconstruction depuis la masse. Les planètes et constructs auxiliaires conservent leurs stocks. `SectorService` requiert SQL, hydrate par lots de 400 IDs et renvoie quatre zéros sans insertion si une ligne manque. Les tests vérifient aussi les corps orbitaux imbriqués et les signatures de génération indépendantes du stock.
- Les créations de secteurs, astéroïdes de démarrage, épaves de vaisseau-mère et créations administratives réservent l'identité et enregistrent stock/intention/événement dans la même transaction. La lecture canonique n'annonce pas une source encore non publiée ; `OthersSectorService` dispose d'une vue structurelle distincte. Les observations de transit reprennent les quantités SQL. Les comparaisons et écritures d'effets utilisent les descripteurs.
- Migration explicite supplémentaire : `scripts/one-shot-scripts/migrate-sector-creation-effects-schema.php --database-config=PATH` simule l'extension de la contrainte `sector_effects.effect_type` ; `--apply` l'applique sous maintenance. Le DDL courant figure dans `SchemaInitializer`. La migration est testée sur SQLite et MariaDB, y compris sa réexécution ; elle ne convertit pas les données de jeu. La migration historique Others utilise aussi les créations de stock explicites pour les nouvelles épaves.
- Raccords anticipés nécessaires à la cohérence du lot 2 : débits SQL des Mannies et du minage individuel Others sur les sources couvertes, transfert SQL d'identité lors de la motorisation. Le test Others refuse une source non publiée, vérifie le rollback stock/cargaison lors d'un échec de livraison et l'absence de double débit au replay. Le moissonnage collectif et les stocks planétaires restent exclus.
- **Branche intermédiaire, non déployable en production :** lots 3, 4, 4 bis, 5, 6 et 7 encore requis. Restent notamment les snapshots et transformations générales, la répétition globale de migration, la refonte de la finalisation Manny, le retrait de son enveloppe globale de sonde et la coordination avec les destructions. Les raccords ci-dessus ne constituent pas la réalisation de ces lots. L'import/conversion complet des données et le manifeste de reprise seront livrés au lot 6 ; les scripts de schéma seuls ne permettent pas d'ouvrir les anciens fichiers avec ce code. Aucun contrat API changé (v148).
- Vérification finale de cette étape : `php tests/SectorTests.php` — 376 succès ; `php tests/ApiTests.php` — 4 553 succès ; `php tests/StorageConcurrencyTests.php` et sa variante `--mysql-config=config/database.json` — succès. Les tables MariaDB de test sont isolées par préfixe et supprimées par la suite. Le test existant d'impact Others a été rendu déterministe : il choisit un tir réussi avant de tester l'idempotence, au lieu d'échouer lors des 5 % de ratés prévus par le jeu.


## Lot 2 bis — Mannies oubliées SQL, réalisé le 17 octobre

- `mannies.is_forgotten` est un booléen interne, faux à la création. Départ, annulation, nouvel ordre et fins distantes remplacent les anciennes écritures JSON par les transitions SQL explicites ; le retour et l'abandon effacent le flag avec le placement/détachement. Une sauvegarde ancienne d'une Manny déployée ne rétablit pas une valeur périmée du flag.
- `SectorContent` conserve une collection de lecture séparée pour les Mannies oubliées ; `getObjects` et `findObjectById` la consultent, les descripteurs ne la sérialisent jamais. Le JSON ancien est refusé sans fallback. Les règles existantes d'observation locale, de récupération réservée au propriétaire, d'inspection, de waypoints et de ciblage restent applicables. Les unités autonomes gardent leur lecture SQL existante, sans ajout d'une seconde entrée.
- La sélection groupée utilise l'index existant `idx_mannies_sector`. Test dense : 101 présences, une requête, plan SQLite utilisant cet index ; aucun nouvel index ajouté. Les présences SQL sont ordonnées par ID de Manny ; les objets persistants conservent leur ordre.
- La récupération automatique lit les candidates SQL du propriétaire et du secteur, puis revalide tâche, localisation et oubli sous verrou de Manny. Les conteneurs sont verrouillés avant placement. L'enveloppe de sonde existante est conservée à cette étape. Les courses consultation/consultation et consultation/nouvel ordre passent sur SQLite et MariaDB : une seule place ne reçoit qu'une Manny ; celle sans place reste oubliée. Aucun fichier de secteur n'est nécessaire pour récupérer.
- Les abandons exceptionnels et restitutions d'objets lors d'expiration utilisent des intentions durables, publiées après commit. La suppression de compte relit les Mannies dans la transaction ; les pertes de sonde effacent l'oubli lors du détachement. L'observation ne transforme plus des entrées oubliées JSON en abandonnées. Le protocole complet d'admission et de destruction reste au lot 4 bis.
- Les statistiques comptent les Mannies oubliées en SQL. La garde d'architecture imposait d'extraire les accès SQL de `UniverseStatsService` avant modification : ils résident maintenant dans `UniverseStatsRepository`, sans exception supplémentaire ni changement des classements.

Conversion sous maintenance, sur une copie SQL/JSON cohérente avant tout déploiement :

```bash
php scripts/one-shot-scripts/migrate-forgotten-mannies.php \
  --database-config=PATH --universe-path=PATH
# Après arrêt de l'API et des workers, avec sauvegarde complète :
php scripts/one-shot-scripts/migrate-forgotten-mannies.php \
  --database-config=PATH --universe-path=PATH --apply --manifest=MANIFEST_PATH
```

La simulation ouvre SQLite en lecture seule ou utilise une transaction MariaDB READ ONLY. L'application ajoute explicitement la colonne si nécessaire, crée le manifeste avant les mutations, vérifie les états SQL et hashes, valide SQL avant le retrait JSON et remplace chaque fichier atomiquement. Le manifeste contient les originaux et la décision par UID ; reprendre avec le même chemin, toujours sous maintenance. Ne pas ouvrir le jeu sur une conversion partielle.

Doublons (y compris oublié/abandonné pour un même UID), localisation divergente, embarquement déjà effectué et publications oubliées en attente bloquent la conversion. `--resolutions=PATH` accepte un objet JSON par UID : `{"acceptSqlCargo":true,"reason":"justification vérifiée"}` pour un snapshot de cargaison ancien, ou `{"action":"abandon","reason":"perte confirmée"}` pour une orpheline. Les deux décisions peuvent se combiner. Une tâche/réservation encore active d'orpheline doit être annulée via l'ancien mécanisme métier avant conversion. Une orpheline sans ligne SQL conserve son objet et sa cargaison sous forme abandonnée ; aucune Manny SQL attachée à un propriétaire fictif n'est créée. Aucun montant n'est additionné ou recrédité.

Tests ajoutés : flag et sauvegarde ancienne, distinction déployée/oubliée, annulation, retour et rollback, rejet du JSON ancien, lecture dense, absence d'écriture JSON, abandon interrompu puis publié, divergences, doublons, orphelines explicites, anciens effets, interruption après commit SQL et reprise après publication, ajout de colonne rejouable sur SQLite/MariaDB, récupération concurrente et course avec un ordre. Les scénarios API existants de départ/retour, SCUT, abandon, récupération, combat et perte de propriétaire passent. Aucun test ni script de conversion n'a modifié la production.

Le script de ce lot convertit seulement les Mannies oubliées. Il ne suffit pas au déploiement de la branche : cycle de vie des stocks, finalisation du minage, destructions et répétition globale restent aux lots suivants.


## Lot 3 — Cycle de vie SQL, réalisé le 17 octobre

`SectorService::changeMineablesAfterCommit` coordonne les mutations rares avec `sector_effects`. La transaction verrouille le secteur structurel, lit sa projection ordonnée, verrouille les identités des sources par ordre d'ID puis relit les stocks courants. Les conteneurs attachés concernés et leurs contenus sont également relus sous verrou : une cargaison créditée après le premier snapshot SQL ne peut pas être écrasée par la motorisation ou enregistrée périmée au départ. La publication du fichier intervient après commit ; ses replays n'exécutent aucune mutation de ressources.

- Motorisation : transfert du stock vers l'ID `mtr_` réservé durablement, suppression du stock précédent et mutation de la tâche dans la même transaction. `MineableObjectReferenceRepository` fait suivre les colonnes normalisées de `manny_tasks`, les descripteurs des événements actifs, les cibles des trajectoires actives, les actions/auxiliaires Others et les conteneurs attachés. Les événements terminaux et les intentions déjà enregistrées restent inchangés. Une source sans ligne crée un résultat explicite à zéro.
- Fusion : somme des seuls stocks SQL courants, allocation durable et consommation des deux sources sous verrous triés. Fragmentation : pertes métier, répartition à quatre décimales et attribution déterministe du résidu aux plus grandes fractions, puis par indice ; les IDs effectivement réservés figurent dans les descripteurs. Les erreurs de résolution numérique provoquent un rollback. Les fragments d'une planète sont des créations explicites d'astéroïdes ; aucun stock planétaire n'est migré.
- Transit : départ, état de trajectoire, pièces jointes et intention de retrait partagent le commit. La ligne de stock reste présente jusqu'à capture ou perte. La dernière trajectoire de transfert fait autorité pour refuser le minage sur un fichier de départ en retard, même si l'arrivée est déjà résolue. Capture par étoile, planète ou trou noir : même ID et même stock. Un ID déjà présent à destination constitue une incohérence bloquante ; le moteur ne renomme plus cet objet silencieusement.
- Absorption par trou noir, limite de traversées, destruction lors d'un impact et troisième missile : retrait du stock avec la transition SQL. Tant que le retrait JSON n'est pas publié, le descripteur restant est hydraté à zéro. L'impact sur un dépôt conserve son intention de consommation et supprime le stock dans la même transaction.
- Sculpture, ravitaillement, waypoint/renommage et scénario d'inspection d'une épave couverte : patches de descripteurs sans copie des ressources. Un stock absent n'est pas recréé. L'épuisement ne supprime pas l'épave ni sa ligne à zéro. Les créateurs d'épaves et scripts administratifs raccordés au lot 2 continuent d'utiliser la création explicite.
- Snapshots opérationnels de trajectoire : descripteurs canoniques sans `resourceAmounts`, lecture stricte sans calcul depuis la masse. Les observations en transit hydratent SQL. La dépendance SQL gelée de `AsteroidTrajectoryService` a été déplacée dans le repository ; aucune nouvelle exception à la garde d'architecture.

Conversion ciblée fournie, **à exécuter sous maintenance après l'import et la vérification des stocks du lot 6**, sur la copie cohérente de répétition avant production :

```bash
php scripts/one-shot-scripts/migrate-asteroid-snapshot-descriptors.php --database-config=PATH
php scripts/one-shot-scripts/migrate-asteroid-snapshot-descriptors.php \
  --database-config=PATH --apply --backup=NEW_BACKUP_FILE
php scripts/one-shot-scripts/migrate-asteroid-snapshot-descriptors.php --database-config=PATH --verify
```

Simulation par défaut. Le script contrôle identité/type et présence de la ligne SQL de chaque trajectoire active ; il refuse de reconstruire un stock depuis le snapshot. Les snapshots originaux sont sauvegardés avant l'unique transaction de conversion, avec création exclusive du fichier de sauvegarde. Après commit, une réexécution ne modifie rien et ne remplace pas la sauvegarde. Après rollback, réutiliser une nouvelle destination de sauvegarde : les preuves précédentes restent conservées. Les trajectoires terminales ne sont pas converties ; leurs quantités sont historiques. Ce script ne remplace pas l'import global, le manifeste ni la procédure de restauration du lot 6.

Validation : tests moteur/API, et concurrence réelle sur SQLite et MariaDB de développement. Cas couverts : rollback avant commit, arrêt après commit avant publication, fusion après prélèvement et replays après minage, fragmentation avec ligne absente/collision/résidu, création de fragments planétaires, captures étoile/planète/trou noir, perte en transit, troisième missile, motorisation sans stock, références actives, sculpture/ravitaillement/renommage sans régénération, conversion et sauvegarde des snapshots. Courses minage/fusion, minage/destruction, minage/métadonnées et minage/départ ; MariaDB vérifie aussi une transformation après un snapshot REPEATABLE READ périmé, avec conservation du stock et de la cargaison attachée.

Les courses sont enveloppées par la reprise de l'unité SQL entière : l'intégration de la politique de reprise dans le scheduler appartient toujours au lot 5. La suite MariaDB a révélé un interblocage intermittent sur le verrou de secteur existant : `INSERT IGNORE` prenait un verrou partagé avant la demande exclusive. Il est remplacé par un upsert sans changement de valeur, qui prend directement le verrou exclusif ; une course répétée vérifie cette acquisition. Cela ne constitue pas une démonstration d'absence d'interblocages dans toutes les familles d'actions. Les enveloppes actuelles de sonde et les transactions de finalisation du minage restent à refondre aux lots 4 et 4 bis, avec consolidation de l'ordre des verrous. La branche reste non déployable avant les lots 4, 4 bis, 5, 6 et 7. Aucune donnée de production n'a été modifiée.

Vérification finale du lot 3 : `php tests/SectorTests.php` — **460 succès** ; `php tests/ApiTests.php` — **4 558 succès** ; suites de concurrence SQLite et MariaDB — succès, y compris le verrou de secteur répété et la conservation des cargaisons après lecture périmée. Syntaxe PHP et `git diff --check` vérifiés. Logs de développement : `/tmp/vng-lot3-sector.log`, `/tmp/vng-lot3-api.log`, `/tmp/vng-lot3-concurrency-sqlite.log`, `/tmp/vng-lot3-concurrency-mariadb.log`.

## Lot 4 — Minage atomique, 17 octobre

La fin de minage passe par `MannyService::processScheduledMiningTask`. Le débit de source, le crédit, le retour ou l'oubli SQL de la Manny, `manny_tasks` et l'acquittement/report de l'événement partagent une transaction. La Manny est relue sous verrou avec son rattachement et l'identité de tâche ; le bail est vérifié sous verrou avec l'identifiant de worker **et le numéro de prise en charge**, y compris lorsqu'un même worker reprend un événement. Une reprise après commit ne réextrait rien. Une sonde morte/absente ou une Manny disparue/détachée ne sont pas recréées pour terminer une ancienne livraison.

Le vecteur arrondi des quatre ressources est transmis tel quel du débit au crédit. Une source absente, en transit ou sans ligne SQL ne fournit aucune ressource. La capacité réelle du conteneur détaché est lue directement sous son verrou SQL : ressources, objets, Mannies et réservations entrantes. Le crédit incrémente uniquement les lignes de ressources et la version d'inventaire ; il ne remplace pas les enfants, n'efface pas de réservation et ne sauvegarde pas le secteur. Un conteneur disparu conserve son erreur métier et provoque un rollback sans prélèvement. Le réservoir de deutérium conserve son verrou de ligne sonde ; sa place déjà réservée aux transferts reste indisponible au minage.

Pour une livraison embarquée, tous les conteneurs candidats sont verrouillés par ID avant la décision de capacité et le placement. Les ajouts de ressources et d'objets via `ProbeStorageService` utilisent les mêmes racines ; les sorties de fabrication verrouillent leur conteneur réservé. La transaction de minage utilise `READ COMMITTED` sur MariaDB pour relire les inventaires après attente d'un verrou, sans modifier le niveau d'isolation par défaut de la connexion. Les stocks et les contenus détachés utilisent aussi des lectures courantes explicites. SQLite conserve sa sérialisation des writers.

Le lancement acquiert une ligne `mining_order_locks(probe_id, object_id)` puis la Manny avant la lecture des engagements et l'enregistrement de l'ordre. Cette ligne est une exclusion mutuelle locale, sans quantité réservée et sans réservation entre sondes. Elle reste réutilisable jusqu'à la suppression de la sonde (cascade SQL). Le rappel acquiert également la Manny et sa tâche avant décision. La découverte d'une cache lors du lancement est désormais enregistrée directement dans `detached_storage_container_discoveries`, sans sauvegarde du secteur.

L'expiration d'attente est traitée avant l'entrée dans la récolte. Elle ne prend aucun verrou de source et conserve les règles d'abandon existantes ; une Manny abandonnée passe par l'intention durable du lot 2 bis, publiée après commit. Une récolte livrée dont seul le retour manque de place devient `waiting_for_space` : les reprises exécutent le retour, jamais un second prélèvement.

### Matrice de verrous après le lot 4

La flèche décrit les acquisitions métier, hors relecture d'un verrou déjà détenu. L'enveloppe de sonde est encore présente conformément à la dépendance 4/4 bis.

| Opération | Ordre effectif et protection | Suite requise |
| --- | --- | --- |
| Lancement minage | Sonde → couple sonde/source → Manny/tâche → éventuelles découvertes de conteneurs | Remplacer l'enveloppe par l'admission coordonnée au lot 4 bis ; la paire protège les engagements locaux |
| Récolte | Sonde provisoire → Manny/tâche/événement avec bail → source → conteneurs embarqués triés si retour possible → destination détachée | Aucun verrou de secteur ni écriture JSON ; retirer l'enveloppe seulement après intégration 4 bis |
| Récolte de deutérium | Même ordre ; la ligne sonde protège aussi le réservoir avant la source | Le verrou de réservoir reste distinct de l'enveloppe à retirer |
| Rappel | Sonde → Manny/tâche ; transition SQL vers retour ou oubli | Les rappels exceptionnels d'autres familles conservent leur protocole |
| Fabrication | Manny/tâche → conteneur réservé ; réservation remplacée par la sortie dans la même transaction | Validation croisée sans enveloppe au lot 4 bis |
| Ajout ressource/objet | Ensemble des conteneurs candidats triés → inventaire | Le conteneur sert de racine commune à la capacité, pas uniquement les lignes de ressources |
| Transfert Manny | Sonde → Manny → transfert → conteneur embarqué → stockage externe | Ordre embarqué/détaché conservé dans le minage |
| Récupération/détachement | Sonde → tâche/réservations → inventaires ; effets structurels selon l'action | Conserver les protections des opérations rares pendant l'intégration |
| Mouvement | Sonde → transitions SQL d'oubli ; récupération avec Manny puis conteneurs | Course départ entre décision et commit à valider avec le retrait de l'enveloppe au lot 4 bis |
| Motorisation, trajectoire, fusion, fragmentation | Acteurs/trajectoire nécessaires → secteur structurel → sources triées → attachements/références concernées | Le minage ne demande jamais le secteur après sa source ; les conflits d'autres familles restent soumis au rollback, puis à la reprise ciblée du lot 5 |
| Expiration/abandon | Branche exceptionnelle avant récolte → intention structurelle → publication après commit | Aucun débit de source pendant l'abandon |

Cette matrice ne prétend pas démontrer l'absence universelle d'interblocages : les transformations rares qui réécrivent des références actives et les changements d'ensemble de conteneurs doivent être inclus dans la validation conjointe du lot 4 bis. Aucun retry automatique des handlers historiques à effets de fichiers n'a été ajouté. Le report progressif des conflits SQL reste le lot 5.

Migration additive explicite, simulation sans ouverture de connexion par défaut :

```bash
php scripts/one-shot-scripts/migrate-mining-order-locks.php --database-config=PATH
php scripts/one-shot-scripts/migrate-mining-order-locks.php --database-config=PATH --apply
```

Ce script crée uniquement les verrous d'engagements ; le schéma est également déclaré dans `SchemaInitializer`. Il ne convertit aucun stock et n'autorise pas la bascule. Aucun changement du contrat API (v148), aucun changement du moissonnage planétaire Others, aucune écriture en production.

Tests spécifiques : panne après crédit et avant sauvegarde Manny, rollback intégral, reprise après commit avant acquittement, ancien bail repris par le même worker, quatre ressources et arrondis, source absente/ligne absente, capacité consommée après lancement, réservation entrante, dernier 0,0001 ECE de carburant non réservé, destination disparue, fichiers inchangés et absence de ligne de verrou secteur. Courses avec deux connexions : même astéroïde ou même épave, destination commune, ordres concurrents et rappel. MariaDB maintient une transaction de récolte ouverte jusqu'au commit d'une seconde sur des acteurs/sources/destinations indépendants du même secteur : la seconde ne dépend pas de la première.

La branche reste **non déployable** avant les lots 4 bis, 5, 6 et 7. En particulier, l'enveloppe exclusive de sonde n'est pas encore supprimée ; cette étape nécessite le protocole de destruction/admission du lot 4 bis et ses tests.

Vérification du lot 4 : `php tests/SectorTests.php` — **495 succès** ; `php tests/ApiTests.php` — **4 558 succès** ; `php tests/StorageConcurrencyTests.php` et sa variante `--mysql-config=config/database.json` — succès sur SQLite et tables MariaDB isolées. Le scénario de très petites récoltes vérifie aussi que l'arrondi de plusieurs composantes ne dépasse pas le total demandé. Migration de schéma vérifiée en simulation sans connexion ; syntaxe des PHP modifiés/ajoutés et `git diff --check` validés. Logs : `/tmp/vng-lot4-sector.log`, `/tmp/vng-lot4-api.log`, `/tmp/vng-lot4-concurrency-sqlite.log`, `/tmp/vng-lot4-concurrency-mariadb.log`.


## Lot 4 bis — Destruction coordonnée et retrait de l'enveloppe, 17 octobre

### Protocole effectivement intégré

- **Admission du minage et des traitements Manny du scheduler :** transaction courte en `READ COMMITTED`, gardes partagées prises par ID croissant, insertion d'un token durable avec bail de 60 secondes, commit. La transaction métier verrouille ensuite les lignes de ce token, vérifie leur présence et leur échéance, puis travaille sous les verrous locaux. Elle supprime le token dans son commit. Le token possède une ligne par sonde impliquée ; aucune FK ne reprend un verrou d'inventaire lors de son insertion.
- Un lot API de tâches s'admet avant sa transaction et réutilise son contexte d'admission. Un appel de minage imbriqué dans une transaction sans admission préalable est refusé explicitement, sans commit du propriétaire. Les autres écritures historiques protègent leurs gardes partagées jusqu'au commit de leur transaction ; elles n'utilisent pas de faux commit d'admission imbriqué. Les gardes des transferts entre sondes sont triées.
- **Perte :** `requested` est persisté dans la transaction causale avec un événement `probe.loss`. Une finalisation opportuniste après commit donne le comportement immédiat habituel en absence de travail actif. Le scheduler est la voie durable de reprise, y compris lorsque l'appelant utilise une transaction PDO sans callbacks. La fermeture exclusive utilise `NOWAIT` ; si un writer historique détient encore sa garde, le worker rend la main. La fermeture de la garde est le point d'ordonnancement avec les admissions concurrentes.
- **Drainage :** la fermeture est commise séparément, puis la perte passe à `draining`. Un token non expiré fait reporter la destruction de cinq secondes. Un token expiré n'est récupéré que si son verrou SQL peut être pris sans attente : une transaction active reste protégée même après l'échéance. Le nettoyage périodique des admissions expirées applique la même règle, y compris sur les sondes qui ne seront jamais détruites. Un ancien token ne peut plus exécuter une opération après récupération. Les erreurs de finalisation sont reportées de trente secondes ; après quinze minutes de drainage, le journal serveur contient la sonde, l'état et les tokens bloquants. Aucun traitement n'est forcé à disparaître.
- **Finalisation :** verrou de perte, joueur puis sonde ; relecture des Mannies sous verrous, annulation métier, libération des engagements, abandon des unités encore déployées, suppression des unités rentrées. Les décisions d'abandon ne réutilisent aucun inventaire préparé avant le drainage. Les effets de secteur et alertes sont enregistrés dans la transaction ; `completed` et la sonde de remplacement constituent la preuve de replay. Les gardes fermées et les preuves de perte sont conservées sans purge automatique ; les IDs ne sont pas réutilisés.
- Les minages futurs/échus sont annulés sans débit. Les annulations de transferts utilisent l'instant causal persistant et les règles métier de leur service. Les transferts entrants de carburant/Mannies/conteneurs sont recensés via `manny_tasks`, annulés côté source sous verrous, sans double restitution. Les réservations de fabrication, réparation, récupération, assemblage et objets consommés suivent les fonctions d'annulation existantes. Un stock déjà livré n'est jamais recrédité à l'astéroïde.
- La dernière sonde terminale reste l'ancrage public de réinstanciation. Celle-ci exige un drainage terminé, relit l'état et inscrit le nouvel ID dans la preuve de perte. La suppression de compte ferme et draine toutes ses gardes dans sa transaction, ou refuse l'opération pour nouvelle tentative sans supprimer de données ; elle applique aussi les annulations métier. Les scripts administratifs sont raccordés à ces services.
- Le scheduler exclut de sa sélection les tâches des sondes fermées et privilégie les pertes dues : un grand nombre de missions annulables ne peut pas bloquer les autres sondes. `claim` reste protégé par les gardes, indépendamment de ce filtre de sélection. Les événements de projectiles et de trajectoires restent hors fermeture de leur sonde initiatrice.

### Verrous après intégration 4 + 4 bis

| Chemin | Protection effective |
| --- | --- |
| Admission du minage | Gardes partagées triées → token ; commit avant la récolte |
| Lancement | Token → paire sonde/source → Manny/tâche ; aucune enveloppe exclusive de sonde |
| Récolte | Token → Manny/tâche/événement avec contrôle du bail → source → conteneurs embarqués triés si retour → destination détachée |
| Récolte de deutérium vers la sonde | Verrou local supplémentaire de `neumann_probes` pour le réservoir et ses réservations ; pas de séparation du réservoir dans ce lot |
| Rappel de minage, retour/attente de place | Admission ou garde de transaction → Manny/tâche → capacités et inventaires locaux |
| Fabrication et autres écritures | Admission/garde de transaction ; conteneur réservé ou ensemble de conteneurs candidats selon l'opération ; les déplacements/réservations de capacité partagent ces racines avec le minage |
| Fermeture | Garde exclusive `NOWAIT` ; commit avant toute attente |
| Nettoyage | Preuve de perte → joueur → sonde → Mannies/inventaires ; uniquement après drainage |

Les verrous locaux peuvent encore entrer en conflit avec d'autres familles d'actions. Ce lot ne prétend pas supprimer tout interblocage du moteur : la politique progressive de reprise du scheduler reste le lot 5. SQLite sérialise ses writers à l'échelle du fichier ; la démonstration de parallélisme utilise MariaDB.

### Migration et vérifications

```bash
php scripts/one-shot-scripts/migrate-probe-operations.php --database-config=PATH --dry-run
# Sous maintenance, API writers et workers arrêtés :
php scripts/one-shot-scripts/migrate-probe-operations.php --database-config=PATH --apply
```

Le schéma est déclaré dans `SchemaInitializer`. La simulation n'ouvre aucune connexion. L'application initialise seulement les gardes absentes et conserve les gardes fermées ; elle programme les pertes des sondes déjà terminales, sans nettoyer leurs inventaires pendant la migration. Pour ces seules pertes historiques sans preuve antérieure, la cause explicite est `migration_terminal` et l'instant causal est leur `updated_at` stocké. La répétition ne crée pas une seconde perte ni un second événement. Une garde manquante est refusée par le code courant ; aucun fallback ni réparation implicite.

Validation : **541 tests moteur**, **4 558 tests API**, suites de concurrence SQLite et MariaDB sur tables isolées. Couverture ajoutée : admission/fermeture concurrentes, deux mineurs indépendants de la même sonde, fin de livraison après fermeture, source intacte si fermeture antérieure, retour de Manny pendant drainage, événement futur, anciens tokens, expiration pendant transaction active, crash avant transaction et après demande, rollback de finalisation après création d'alerte, reprise par un scheduler unique, transferts entrants, suppression de compte, renommage API concurrent au minage, rollback isolé d’un membre de lot API, deux causes de destruction simultanées et migration répétable. Les tests existants de missiles en vol et d'historique restent verts.

Mesures locales MariaDB sur tables isolées : admission seule **0,534 ms en moyenne sur 25 opérations**, perte sans opération active **7,855 ms**, retour du drainage devant un token expiré mais encore verrouillé **5,5 ms**. Mesures indicatives de développement ; charge et volumétrie de production à vérifier au lot 6.

Logs : `/tmp/vng-lot4bis-sector.log`, `/tmp/vng-lot4bis-api.log`, `/tmp/vng-lot4bis-concurrency-sqlite.log`, `/tmp/vng-lot4bis-concurrency-mariadb.log`. Aucun changement du contrat API (**v148**). Aucune écriture de production. Branche **non déployable avant les lots 5, 6 et 7** et la conversion explicite des données.


## Lot 5 — Reprises SQL et observation, 18 octobre

### Périmètre livré

Le scheduler reporte les conflits temporaires du **minage Manny transactionnel**, ainsi que ceux de son admission et de sa prise en charge. Il inspecte les codes SQL d'origine, y compris derrière `StorageBusyException` : MariaDB/MySQL 1205, 1213 et 3572 ; SQLite 5 et 6. Ni une erreur métier, ni une erreur d'intégrité, ni un message contenant le mot « deadlock » ne suffisent. Les autres familles de handlers ne reçoivent aucune politique générale de replay ; le moissonnage planétaire Others reste exclu.

La transaction annulée restitue débit, livraison, tâche et claim ; le ticket d'admission est supprimé avant le report. Un `UPDATE` conditionnel reporte uniquement l'événement encore `pending`, avec les mêmes `attempts`, compteur de conflits, date de programmation et identité de tâche minière, toujours rattachée à la sonde dont l'admission est ouverte. Aucun ancien worker ne reprend un événement pour en écraser le résultat. Un nouveau claim, une annulation, une tâche remplacée ou une fermeture de sonde invalide le report. Les transitions `release`, `markDone`, `markFailed` et le renouvellement de bail contrôlent aussi le numéro d'exécution, y compris lorsque les deux exécutions appartiennent au même worker.

Les trois champs canoniques de `scheduled_events` sont `conflict_count`, `conflict_started_at` et `conflict_alerted_at`. Ils sont indépendants de `attempts` : un claim annulé avec la transaction ne compte pas comme une exécution métier. Les succès, y compris les reports métier normaux d'une récolte encore en attente, remettent la série de conflits à zéro. Les reprises ne réécrivent ni le payload, ni les dates de tâche, ni l'origine du délai d'attente de stockage. À chaque nouvelle tentative, admission, état courant, destination et expiration sont revérifiés ; une destination disparue devient une erreur permanente et une attente expirée termine la mission sans prélèvement.

### Délais et alerte

Configuration dans `config/app.json` :

- `schedulerMiningRetryDelays` : `[1, 5, 15, 60, 300]`, entiers positifs croissants, plafonnés à 300 secondes. Le dernier palier est répété.
- Dispersion de ±20 %, arrondie en secondes, minimum une seconde et maximum 300 secondes, y compris au dernier palier. Les premières plages par défaut sont donc 1, 4–6, 12–18, 48–72, puis 240–300 secondes.
- `schedulerMiningConflictAlertSeconds` : `900`. Le premier dépassement produit un diagnostic interne avec `alert=true` et conserve sa date en SQL. Les tentatives suivantes continuent tant que la tâche est valide ; le compteur ne constitue pas un plafond d'essais. Une nouvelle série après succès peut produire une nouvelle alerte.

Le worker rend la main pendant ce délai ; il ne dort pas en conservant un verrou ou une admission. L'alerte est interne au journal du serveur, sans notification joueur ni nouveau contrat public.

### Diagnostics et mesures

Les lignes JSON du journal portent `metric=scheduler_mining` et un résultat `sql_conflict`, `success` ou `permanent_failure`. Les conflits comportent événement, UID de Manny, ID public de source, sonde, code SQL et SQLSTATE, famille de verrou identifiée dans la pile, compteur, âge de la série, délai choisi et indicateur d'alerte. `last_error` conserve le diagnostic de conflit borné ; aucun SQL brut, secret de connexion ou payload complet n'est enregistré. La famille générique `mining_transaction` s'applique si aucune famille plus précise n'est identifiée.

Les durées `duration_ms`, `admission_ms` et `transaction_ms` permettent de calculer débit, p50/p95/p99 et coût des reprises depuis les journaux. Les succès indiquent `completed` et `recovered_conflicts`. La durée d'une transaction en conflit inclut son attente ; ce n'est pas une mesure séparée de chaque acquisition de verrou. L'observation n'ajoute aucune table d'historique et une panne d'observateur ne provoque pas le replay d'une livraison commise.

Deux scénarios reproductibles dans `MiningRetryConcurrencyTests.php` utilisent de vrais processus et des tables MariaDB isolées : maintien d'un verrou source avec `innodb_lock_wait_timeout=1`, puis cycle d'interblocage entre source et événement. Lors de la validation locale, le timeout 1205 a duré **1 008 ms** au total (**1 004 ms** dans la transaction), et le conflit 1213 **113 ms** (**108 ms** dans la transaction, dont la synchronisation de test de 100 ms). Dans les deux cas, aucun débit après échec, aucune admission conservée et livraison unique après reprise. Ces mesures vérifient le mécanisme, sans prétendre représenter les percentiles sous charge de production ; la comparaison de charge complète et la volumétrie restent aux lots 6/7.

### Migration et validation

```bash
php scripts/one-shot-scripts/migrate-scheduler-mining-conflicts.php --database-config=PATH --dry-run
# Sous maintenance, API writers et workers arrêtés :
php scripts/one-shot-scripts/migrate-scheduler-mining-conflicts.php --database-config=PATH --apply
```

Le DDL est déclaré dans `SchemaInitializer`. La simulation n'ouvre aucune connexion ; l'application ajoute seulement les colonnes manquantes, reprend une migration partielle et conserve les compteurs déjà présents. Aucun événement en échec n'est remis en file par ce script. Pour la migration globale, leur annulation et le rappel métier des Mannies restent la décision du lot 6. La migration additive a été appliquée uniquement à `config/database.json`, base de développement, pour les validations.

Validation : **647 tests moteur**, **4 558 tests API**, suites de concurrence SQLite et MariaDB réussies. Tests ajoutés : rollback après débit, conflit à l'admission et au commit, six conflits successifs et alerte unique, reprise avec conservation exacte, remise à zéro après report métier, erreur permanente, disparition de destination après conflit, expiration métier, ancien report après nouveau claim/libération, transitions périmées du même worker, fermeture d'admission, exclusion des handlers non prouvés, migration simulée/partielle/répétée, et conflits serveur réels 1205/1213. La syntaxe PHP et `git diff --check` sont également vérifiés.

Logs : `/tmp/vng-lot5-sector.log`, `/tmp/vng-lot5-api.log`, `/tmp/vng-lot5-concurrency-sqlite.log`, `/tmp/vng-lot5-concurrency-mariadb.log`. Aucun changement du contrat API (**v148**), aucune écriture en production. Branche `removestoragesfromjsons` ; **lots 6 et 7 et conversion explicite des données encore nécessaires avant déploiement global**.
