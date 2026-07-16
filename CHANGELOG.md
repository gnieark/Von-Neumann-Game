# Changelog

Toutes les modifications notables de Von Neumann Game seront documentées ici, avec une attention particulière aux changements qui peuvent impacter les frontends et les intégrations API.

## 2026-07-16

### Changed

- API v93 : ajout de `POST /api/probe/mannies/{mannyId}/transfer-to-probe` et de sa variante avec `{probeId}` pour transférer une Manny vers une autre sonde possédée du même secteur, ou vers une sonde dans le secteur distant de la Manny via SCUT; la durée est celle d’un détachement de container et les tâches en cours sont annulées avec la logique de recall avant le transfert.
- Interface : `/mannies` ajoute l’ordre “Transférer la Manny vers une sonde” dans le groupe Secteur, avec sélection paresseuse des sondes ou drones possédés disponibles dans le secteur de la Manny.
- API v92 : lorsqu’un assemblage de sonde par Manny est annulé via `POST /api/probe/mannies/{mannyId}/recall`, les composants et les deux containers consommés au démarrage sont désormais relâchés comme piles d’items à la dérive dans le secteur d’assemblage.
- Maintenance : ajout de `scripts/generate-threejs-point-cloud-sectors.php` pour exporter les secteurs générés, visités, occupés par une sonde et couverts par SCUT en nuages de points JSON chargeables par Three.js.

## 2026-07-14

### Changed

- API v91 : `POST /api/probe/mannies/{mannyId}/detach-storage-container` accepte le mode `attach_to_probe` avec `objectId` ciblant une autre sonde possédée dans le même secteur; à la fin du délai, le container et son contenu sont réattachés à l’inventaire de cette sonde.
- Interface : `/mannies` et `/inventories` proposent le mode “Attach to another probe” pour détacher un container puis le rattacher à une autre sonde ou drone possédé dans le même secteur.
- Interface : `/inventories` reconstruit le panneau “Manage storage rules by container” quand les règles reçues changent, tout en conservant l’accordéon ouvert, afin de refléter les modifications faites directement via l’API.
- API v90 : ajout du journal de bord rattaché à chaque sonde, avec stockage `probe_logbook_pages` et endpoints `GET /api/probe/{probeId}/logbook-pages`, `POST /api/probe/{probeId}/logbook-page`, `GET/PATCH/DELETE /api/probe/{probeId}/logbook-page/{logbookPageId}`.
- Interface : la page Sonde affiche le journal de bord de la sonde sélectionnée, chargé à l’ouverture et après création, modification ou suppression, sans polling.
- Interface : `/inventories` affiche le nom de chaque Manny directement après le libellé `Manny` dans les cartes d’inventaire.
- API v89 : `GET /api/sector` conserve `sector.distance` pour rétrocompatibilité en l’exprimant depuis la sonde par défaut, et ajoute `sector.distances` avec la distance du secteur demandé depuis chaque sonde possédée ainsi que la sonde effectivement utilisée pour le scan.
- Interface : `/sensors` affiche désormais la distance du secteur scanné depuis la sonde sélectionnée par l’utilisateur, même quand `sector.distance` reste exprimé depuis la sonde par défaut pour rétrocompatibilité.

## 2026-07-13

### Changed

- Interface : `/mannies` ajoute des filtres d’état et de portée, un tri par nom, tâche ou secteur, et une commande de réinitialisation pour organiser la liste des Mannys sans changer le contrat API.

## 2026-07-09

### Changed

- API v88 : les piles d’items à la dérive dont chaque unité dépasse la capacité d’emport d’une Manny ne sont plus exposées avec `salvageable: true` dans les scans de secteur; cela masque notamment les anciens `drifting_item` `scut_relay`, tandis que les vrais relais SCUT éteints restent récupérables.
- Interface : `/mannies` ne propose plus les relais SCUT activés ou les anciens items `scut_relay` trop volumineux dans “Récupérer un objet à la dérive”; seuls les relais éteints explicitement récupérables restent listés.
- Gameplay : une tâche Manny “Assemble a new probe” arrivée à terme se finalise désormais même si la sonde-mère a quitté le secteur entre-temps; le drone est créé dans le secteur où la Manny assemblait la sonde.
- API v87 : les noms API des items et stocks d’inventaire exposés par les endpoints de sonde sont désormais canoniques en anglais; exécuter `php scripts/migrate-probe-item-names.php --database-config=config/database.json` après le déploiement pour normaliser les lignes `probe_items` existantes.
- Stats : le podium des explorateurs classe désormais les joueurs par nombre de secteurs visités, toutes leurs sondes possédées confondues.
- Stats : la distance entre les deux sondes les plus proches ignore désormais les paires de sondes appartenant au même joueur.
- API v86 : `GET /api/sector` utilise désormais la sonde possédée reachable la plus proche du secteur demandé quand elle fournit un scan valide, en ignorant les candidates encore en `insufficient_scan_data`.
- API v85 : les entrées `sector.probes` de `/api/probe/sector` exposent désormais `owned` pour distinguer les sondes du joueur authentifié des sondes étrangères dans le secteur courant.
- WebUI : l’alerte de sonde détectée dans le secteur ignore désormais les autres sondes possédées par le joueur.
- API v84 : les blueprints d’améliorations de sonde sont désormais connus au niveau joueur et partagés par toutes ses sondes; `GET /api/probe/{probeId}/probe-improvements-available` retourne les améliorations connues du joueur propriétaire de la sonde sélectionnée, avec `done` calculé pour cette sonde précise. La migration de production dédiée est `php scripts/migrate-probe-improvements.php`.
- API v83 : ajout de `POST /api/probe/mannies/{mannyId}/transfer-deuterium-to-probe` et de sa variante `/api/probe/{probeId}/mannies/{mannyId}/transfer-deuterium-to-probe`; une Manny réserve immédiatement du deutérium de la sonde source, réalise le transfert en 5 minutes vers une sonde ou un drone du même secteur, remplit la cible au maximum et rend le surplus à la source.
- Interface : `/mannies` ajoute l’ordre “Transfer deuterium to a probe or drone” dans le groupe Containers, avec choix de la sonde cible du même secteur et de la quantité; le taux de deutérium de la cible n’est affiché que lorsqu’il est accessible pour la sonde du joueur.
- API v82 : lorsqu’une sonde par défaut est détruite par collision de déplacement ou piégée par un trou noir, l’esprit du joueur bascule automatiquement vers une autre sonde opérationnelle du joueur si elle existe; l’ancienne sonde est supprimée sans effacer les données rattachées au joueur, et une alerte `mind_snapshot_transferred` en anglais est créée sur la nouvelle sonde.
- OpenAPI : les opérations `{probeId}` réutilisées pour les déplacements de stock, messages, alertes, warnings, secteurs visités, secteur courant, déplacement, Mannys et impression atomique déclarent maintenant `probeId` au niveau opération pour l’affichage Swagger UI.
- Scheduler : les tâches actives des Mannys sont désormais liées à `scheduled_events` via `mannies.task_scheduled_event_id`; le cron `scripts/scheduler.php` peut les finaliser avec les mêmes handlers que les refresh API. Après déploiement, exécuter `php scripts/migrate-manny-tasks-to-scheduled-events.php --database-config=config/database.json`.

## 2026-07-07

### Changed

- API v81 : ajout de `POST /api/probe/mannies/{mannyId}/assemble-probe`, une tâche Manny extérieure de 3 heures qui consomme 2 containers vides, 1 `deuterium_engine`, 1 `scut_relay`, 5 `electric_motor`, 2 `atomic_printer_part` et 4 `solar_panel`, puis crée une sonde `drone-N` appartenant au joueur et y transfère la Manny.
- Interface : l’accordéon Fabrication des Mannys propose “Assemble a new probe”, avec description, liste des composants et sélection de deux containers vides.
- API v80 : ajout des recettes `atomic_printer_part` et `deuterium_engine`; la pièce d’imprimante atomique se fabrique via l’imprimante atomique en 45 minutes, et le moteur au deutérium s’assemble via Manny en 2 heures avec 0.5 ECE de deutérium direct.
- Interface : ajout des libellés et descriptions FR/EN pour les pièces d’imprimante atomique et moteurs au deutérium dans les pages Mannys et Inventaires.

## 2026-07-06

### Changed

- API v79 : `GET /api/probes` expose `isReachable` pour chaque sonde, vrai quand elle est la sonde par défaut, dans le même secteur, ou joignable via une couverture SCUT partagée.
- Interface : la page Sonde affiche le nom et le type de la sonde, permet de la renommer quand elle est joignable, et propose depuis la sonde principale une bascule d’instance vers un drone joignable.
- Interface : les pages ciblant une sonde injoignable harmonisent leur avertissement hors portée et réduisent les pages Capteurs, Mouvement, SCUT, Messagerie et Alertes à leur titre, rafraîchissement et message d’avertissement.
- Interface : le sélecteur de sonde du nav-panel indique les sondes inaccessibles.
- Interface : le libellé `Mannys & printer` du nav-panel anglais n’affiche plus l’entité HTML échappée.

## 2026-07-04

### Changed

- API v78 : `GET /api/probe/{probeId}` limite désormais une sonde possédée hors secteur courant et hors réseau SCUT partagé à `id`, `name`, `status: out_of_scut_range` et `sector` relatif.
- Maintenance : ajout de `scripts/add-drone-probe.php` pour créer une sonde `drone` exclue des stats publiques, avec une Manny `manny-drone` déjà placée dans son stockage.
- Interface : les pages principales acceptent un ID de sonde dans l’URL, le nav-panel propose un sélecteur de sonde active, et les liens de navigation conservent cet ID pour les sondes non défaut.
- API v77 : les endpoints opérationnels de sonde acceptent désormais des variantes `/api/probe/{probeId}/...` pour agir sur une sonde possédée non défaut, à condition qu’elle soit dans le même secteur que la sonde par défaut ou joignable par le même réseau SCUT.
- API v76 : les missions sont désormais rattachées au joueur (`probe_missions.player_id`) et restent visibles depuis `/api/probe/missions` après un changement de sonde par défaut; le script `scripts/migrate-probe-missions-to-player.php` migre les bases existantes depuis `probe_id`.
- API v75 : `PATCH /api/probe/{probeId}` permet de définir la sonde par défaut si la sonde par défaut actuelle et la sonde cible sont dans le même secteur ou dans des secteurs couverts par le même réseau SCUT.
- API v74 : `GET /api/probe` retourne désormais explicitement la sonde par défaut, `GET /api/probes` liste les sondes du joueur avec `defaultProbeId` et `isDefault`, et `GET /api/probe/{probeId}` retourne une sonde possédée ou `404` sinon.
- Base de données : préparation interne du modèle multi-sondes avec `players.default_probe_id` et un lien one-to-many de `players` vers `neumann_probes`.

### Fixed

- Interface : les liens du nav-panel pour les sondes non défaut ne dupliquent plus l’ID de sonde (`/sensors/537/537`).
- Interface : `/inventories` ne propose plus l’imprimante atomique ni les containers additionnels dans les items filtrables de “Manage storage rules by container”.
- API : le minage Manny vers un container détaché n’exige plus 0,05 ECE de place libre fantôme dans la sonde quand la Manny qui part libère déjà son propre emplacement.
- Debug : `scripts/add-deuterium-asteroid-alert.php` et `scripts/add-deuterium-asteroid-alerts-for-low-fuel.php` n’ajoutent plus d’astéroïde ni d’alerte dans les secteurs qui contiennent déjà un astéroïde avec du deutérium, et l’alerte injectée est désormais rédigée en anglais.

## 2026-07-03

### Fixed

- Interface : `/inventories` conserve désormais l’accordéon “Manage storage rules by container” ouvert pendant les refreshs, qui ne mettent plus à jour que les métriques et les inventaires quand ce panneau est déplié.
- Base de données : l’initialisation MySQL ajoute désormais la colonne générée `probe_movements.active_probe_id` avant de créer son index unique, ce qui évite de bloquer l’authentification sur les bases existantes après le passage InnoDB.
- Interface : `/authbypwd` affiche désormais un message traduit avec un statut 503 quand le stockage d’authentification est indisponible, au lieu d’une erreur 500 vide.
- API : les mutateurs critiques de sonde/Manny/stockage passent désormais par le verrou transactionnel `withProbeLock`; les complétions Manny ne s’appuient plus sur un `flock` fichier pour le minage, et la base impose un seul mouvement actif par sonde avec tables MySQL créées explicitement en InnoDB.
- API v73 : `POST /api/probe/storage-moves` refuse désormais de déplacer les items `additional_container`; tant qu’ils ne sont pas détachés ou cachés sur un astéroïde, ils restent liés au stockage interne de la sonde.
- Interface : `/inventories` masque le bouton “Move” sur les lignes de containers additionnels tout en conservant l’alignement des contrôles.
- Maintenance : ajout de `scripts/relink-additional-containers-to-core.php` pour remettre les `additional_container` déjà stockés dans un container vers `probe-core` après déploiement, et supprimer les containers orphelins vides dont l’item backing a déjà disparu.

## 2026-07-01

### Changed

- API : `ApiKernel` utilise désormais un routeur déclaratif, avec les routes Forum déplacées dans un controller dédié et leur sérialisation dans un presenter, sans changement de contrat public.
- API : les routes `/api/probe/mannies` et `/api/probe/atomic-printer/craft` sont déplacées dans `ProbeManniesApiController`, avec leur présentation dans `ProbeManniesApiPresenter`, sans changement de contrat public.
- Manny : `MannyService` délègue désormais la présentation publique des Mannys à un presenter dédié et le rafraîchissement des tâches à des handlers typés par tâche, sans changement de contrat API.
- API v67 : ajout du stockage `probe_improvements`, de `GET /api/probe/probe-improvements-available` et de `POST /api/probe/mannies/{mannyId}/improve-probe`; la première amélioration, `deuterium_compression`, consomme 1 `electric_motor` et 2 `steel_bar`, dure 5 minutes et porte la cuve de deutérium à 200 % une fois terminée.
- API v68 : `POST /api/probe/mannies/{mannyId}/inspect-sector-object` accepte aussi une Manny inactive dans un secteur distant couvert par le même réseau SCUT que la sonde; l’objet inspecté doit se trouver dans le secteur de cette Manny, qui reste oubliée sur place à la fin de l’inspection.
- API v69 : ajout de l’amélioration `reinforced_container_couplings` via `GET /api/probe/probe-improvements-available` et `POST /api/probe/mannies/{mannyId}/improve-probe`; elle consomme 1 `integrated_circuit` et 0.4 ECE de `carbon_compounds`, dure 5 minutes et ignore 5 containers supplémentaires dans le calcul du risque de rupture de container au déplacement.
- API v70 : terminer `POST /api/probe/mannies/{mannyId}/inspect-sector-object` sur un `dormant_construct` crée une alerte `manny_report` avec un message de rapport en anglais et débloque soit `deuterium_compression`, soit `reinforced_container_couplings`; le scénario choisi est persisté dans le JSON du secteur sans être exposé dans les scans API.
- API v71 : `POST /api/probe/mannies/{mannyId}/recover-storage-container` interrompt les Mannys qui minent vers le container récupéré; elles larguent leur cargo minier éventuel et repassent en tâche `returning` avec la raison `target_container_recovered`.
- API v64 : ajout des objets de secteur `dormant_construct`, exposés uniquement dans les scans détaillés de `/api/probe/sector` et `/api/sector`.
- API v65 : `POST /api/probe/mannies/{mannyId}/inspect-sector-object` remplace l’action spécialisée `inspect-asteroid`, désormais dépréciée. L’inspection accepte les astéroïdes, les `dormant_construct` et les containers détachés visibles ou déjà découverts; les rapports de contenu de containers créent une alerte `manny_report`.
- API v66 : l’arrivée d’une sonde dans un secteur contenant un `dormant_construct` crée une alerte persistante `sector_object_detected` en anglais invitant à envoyer une Manny l’inspecter.
- Génération : les nouveaux secteurs ont désormais 1 chance sur 200 de contenir un `Dormant construct`; `scripts/add-dormant-construct.php` permet d’en ajouter un à un secteur, et `scripts/backfill-dormant-constructs.php` peut compléter les secteurs JSON non visités déjà générés.
- Interface : `/mannies` propose l’action générique “Inspecter un objet du secteur” et `/alerts` met les rapports de Manny en évidence avec un style dédié.
- Interface : une Manny inactive dans un secteur distant joignable via SCUT peut désormais recevoir l’action “Inspect a sector object” depuis `/mannies`; la liste des objets inspectables provient du scan `/api/sector` du secteur de la Manny.
- Interface : `/mannies` regroupe les ordres Manny en accordéons Probe/Sector/Containers/Craft et ajoute “Improve the probe”, avec sélection des améliorations disponibles et vérification des ressources nécessaires.

### Fixed

- API v72 : `POST /api/probe/storage-moves` soustrait désormais le volume déjà réservé par les autres déplacements Manny vers le même container avant d’accepter un ordre, afin d’éviter de dépasser sa capacité.
- Interface : la page Probe affiche désormais une métrique `Améliorations installées`, chargée une seule fois depuis `/api/probe/probe-improvements-available`, avec un résumé des deux premières améliorations et un `+N` si la liste est plus longue.
- Interface : la métrique Deuterium de la page Probe affiche désormais en petit le plafond amélioré, par exemple `max 200`, quand `fuel.maxDeuterium` dépasse la valeur standard.
- API : les rapports d’inspection Manny sont idempotents pour une même fin de tâche, afin d’éviter plusieurs alertes `manny_report` identiques quand la complétion est rafraîchie simultanément.
- API : les rapports d’inspection Manny créés hors déplacement ne provoquent plus d’erreur 500 sur `/api/probe/sector`, `/api/probe/mannies` ou `/api/probe/alerts`; `probe_damage_warnings.movement_id` accepte désormais `NULL` pour ces alertes autonomes.
- API : les nouveaux rapports Manny de contenu de container sont désormais générés en anglais, y compris quand aucun contenu n’est détecté.

## 2026-06-28

### Changed

- Stockage : les couvertures SCUT ne sont plus stockées dans des colonnes JSON `covered_sectors_json`; elles passent par la table relationnelle `scut_covered_sectors`. Après déploiement sur une base existante, exécuter `php scripts/migrate-scut-coverage.php`.
- API v58 : `GET /api/probe/mannies` expose les détails de tâche d’une Manny éloignée seulement si son secteur et celui de la sonde sont couverts par le même réseau SCUT; sinon `currentTask` vaut `unknown_too_far` et le payload/progrès de tâche est masqué.
- API v59 : `POST /api/probe/mannies/{mannyId}/recall` accepte désormais l’arrêt distant d’une Manny active située dans un secteur couvert par le même réseau SCUT que la sonde; la Manny ne revient pas, sa tâche est annulée et elle est enregistrée comme `forgotten` dans son secteur.
- API v60 : `POST /api/probe/mannies/{mannyId}/mine` accepte désormais l’ordre pour une Manny oubliée située dans un secteur distant couvert par le même réseau SCUT que la sonde, à condition de déposer le minage dans un container détaché du secteur de la Manny.
- API v61 : les messages publics `ProbeDamageWarning.message` des alertes de rupture de container pendant un mouvement utilisent désormais les coordonnées relatives du joueur et ne divulguent plus les coordonnées absolues du secteur.
- API v62 : les containers détachés cachés sur astéroïde ne sont plus exposés comme récupérables via `POST /api/probe/mannies/{mannyId}/salvage`; ils restent récupérables via `POST /api/probe/mannies/{mannyId}/recover-storage-container`.
- API v63 : `docs/openapi.yaml` documente explicitement les champs SCUT relay-only des `SectorObject`, dont `status`, `coverageRadiusSectors`, `network`, `createdByProbeId`, `createdByProbeName` et la convention `id` string vers `relayId` entier.
- Interface : `/mannies` affiche les tâches distantes joignables via SCUT avec la progression habituelle et une mention courte de liaison SCUT, tout en conservant “Trop éloignée” hors portée.
- Interface : la mention de tâche distante via SCUT est maintenant visible directement dans le bouton d’accordéon Manny, et l’action de rappel distante est libellée comme un abandon de tâche.
- Interface : une Manny oubliée mais encore dans un secteur couvert par le même réseau SCUT s’affiche maintenant comme inactive avec la mention “Secteur distant via SCUT”, au lieu de “Trop éloignée”.
- Interface : une Manny oubliée joignable via SCUT propose désormais uniquement l’action “Mine the sector”, alimentée par `/api/sector` pour cibler le secteur de la Manny et limiter “Store in” aux containers détachés de ce secteur.
- Interface : `/sensors` affiche maintenant le joueur et l’âge de pose des marque-pages de navigation dans le scan courant, les tuiles de secteurs voisins et les scans détaillés des secteurs visités.
- Interface : `/mannies` ne propose plus les containers détachés cachés dans “Récupérer un objet à la dérive”; ils restent listés dans “Récupérer un container détaché”.

### Fixed

- Manny : les livraisons de minage parallèles incrémentent désormais les stocks de la sonde sans écraser les apports d’une autre Manny, y compris pour le deutérium stocké dans la cuve dédiée.
- Interface : `/mannies` ne propose plus de récupérer un container détaché caché uniquement connu via une ancienne détection Manny; le formulaire suit les containers présents dans le scan du secteur courant.
- Authentification : les sessions créées avec `Se souvenir de moi` prolongent désormais automatiquement leur expiration à chaque nouvelle requête WebUI/API portant le cookie.

## 2026-06-27

### Changed

- Interface : ajout de la page `/scut` “SCUT Network”, avec LED de couverture dans le nav-panel, synthèse du réseau courant, sondes détectées et relais listés avec coordonnées relatives.
- Statistiques publiques : ajout des podiums SCUT des activateurs de relais allumés et des réseaux les plus étendus, ainsi que du nombre de secteurs couverts par au moins un réseau SCUT.
- API v57 : les endpoints de messages peuvent exposer `type: unknown` pour les émetteurs inconnus; `scripts/add-origin-anomaly-alerts.php` diffuse maintenant le message des plans SCUT après 60 s, puis une seconde alerte d’intégration des plans.
- API v56 : ajout des alertes persistantes `anomaly_detected` et du script CLI `scripts/add-origin-anomaly-alerts.php`, qui injecte une alerte d’anomalie vers l’origine absolue pour chaque sonde avec une direction approximative relative à sa position courante.
- API v55 : les relais SCUT conservent l'id historique de leur sonde créatrice sans clé étrangère bloquante; les payloads de relais exposent `createdByProbeName`, avec le fallback `death probe` quand cet id ne correspond plus à une sonde existante.
- Interface : `/messaging` propose aussi les sondes joignables via les réseaux SCUT couvrant le secteur courant.
- Interface : `/mannies` propose l’action Manny d’activation d’un relais SCUT éteint, avec sélection du relais et nom de réseau facultatif, en vérifiant la présence d’un circuit intégré en stock.
- API v54 : `POST /api/probe/inventory/{itemId}/jettison` déploie désormais un item `scut_relay` comme relais SCUT éteint dans le secteur courant, exposé comme récupérable par les Mannys.
- API v53 : `POST /api/probe/mannies/{mannyId}/turn-on-relay` exige désormais une étoile dans le secteur courant (`scut_relay_requires_star`) et `POST /api/probe/mannies/{mannyId}/salvage` peut récupérer un relais SCUT éteint présent dans le secteur.

### Fixed

- Interface : un item `scut_relay` en inventaire peut de nouveau être jetisonné depuis la WebUI pour déployer un relais SCUT éteint dans le secteur.
- Manny : deux Mannys ne peuvent plus démarrer simultanément la récupération du même relais SCUT éteint.

## 2026-06-26

### Added

- API v52 : ajout des recettes craftables `solar_panel` et `scut_relay`; un relais SCUT complet demande environ 72 h de craft cumulé par une Manny et l'imprimante atomique, hors temps de minage.

## 2026-06-25

### Added

- API v51 : ajout des relais SCUT persistés en base, de l'action Manny `POST /api/probe/mannies/{mannyId}/turn-on-relay`, de `GET /api/probe/scut-network/{scutNetworkId}`, de la couverture `scutNetworks` dans les scans et de la messagerie entre sondes couvertes par un même réseau SCUT.
- Debug : ajout de `scripts/create-scut-relay.php` pour créer un relais SCUT éteint dans un secteur absolu donné.

### Changed

- Interface : `/mannies` exclut les détails de `probe.inventory` des hashes de cartes pour éviter les reconstructions inutiles pendant le polling; les formulaires dépendants de l'inventaire sont construits à l'ouverture de leur accordéon après rafraîchissement de `/api/probe`.

### Fixed

- API : la restauration d'un snapshot d'esprit supprime maintenant les alertes de dégâts avant les mouvements, purge les missions de l'ancienne sonde et conserve les relais SCUT en retirant seulement leur référence à la sonde détruite, évitant un `internal_error` sur les sondes mortes avec historique riche.
- API v50 : les payloads publics de taches Manny n'exposent plus les objets internes de reservation de container detache ni les secteurs cibles absolus.
- Stockage : la récupération d'un container détaché est désormais idempotente, afin qu'un refresh concurrent ou répété ne recrée pas plusieurs containers portant le même libellé dans l'inventaire.

## 2026-06-24

### Added

- API v49 : ajout de `POST /api/probe/mannies/{mannyId}/refill-deuterium-tank`, une tache Manny d'une minute qui remplit le reservoir de deuterium de la sonde quand une station de recharge est presente dans le secteur courant.
- API v48 : le scenario `return_to_space_program` attend maintenant 48 heures apres les dons de materiaux avant de faire apparaitre une station de recharge en deuterium dans les scans detailles du secteur; la reussite est persistee pour le joueur et les contributeurs.
- Interface : `/mannies` propose l'action de recharge du reservoir de deuterium uniquement lorsqu'une station de recharge en deuterium est detectee dans le secteur courant.
- Interface : `/sensors` met en exergue les stations de recharge en deuterium detectees dans les tuiles des secteurs deja visites, avant le scan detaille.

### Changed

- Interface : `/mannies` rafraichit les informations des Mannys toutes les 5 secondes et apres validation d'une tache, sans reconstruire les cartes dont le hash d'etat n'a pas change; les pourcentages de progression et le temps restant a la minute defilent localement sans polling additionnel.
- Interface : `/sensors` met en exergue les waypoint bookmarks detectes dans les tuiles des secteurs deja visites, avant le scan detaille.

### Fixed

- Stockage : les collections de containers détachés d'un secteur dedoublonnent les entrées par identifiant, y compris au chargement, pour éviter la multiplication des containers cachés sur astéroïde et leur remplissage en double par les Mannys.

## 2026-06-23

### Added

- Ops : ajout de `scripts/migrate-sqlite-to-mysql.php` pour migrer la base SQLite active vers une base MySQL/MariaDB future, verrouiller la source pendant la copie et basculer `config/database.json` après succès.
- Scénario : `return_to_space_program` envoie un message planétaire final quand les dons atteignent 5 ECE de métaux et 1 ECE de composés carbonés, avec diffusion aux contributeurs présents dans le secteur.

### Fixed

- Stockage : récupérer un container détaché ne bloque plus si un container reconstruit a repris le même identifiant technique; la récupération recrée alors un identifiant libre en conservant le contenu.
- Interface : les vues Inventaires et Mannys affichent désormais le nom personnalisé du container interne de la sonde au lieu de le remplacer systématiquement par le libellé traduit par défaut.
- Base de donnees : les index MariaDB des endpoints de messagerie utilisent des longueurs de préfixe compatibles avec la limite de clé InnoDB en `utf8mb4`.
- Base de donnees : `players.username` utilise une collation binaire sous MariaDB, afin de conserver l'unicite sensible a la casse de la base SQLite pendant la migration.

## 2026-06-18

### Added

- API v40 : `/api/probe/alerts` peut exposer des alertes `sector_object_detected` avec un objet detecte dans le secteur relatif.
- Debug : ajout de `scripts/add-deuterium-asteroid-alert.php` et `scripts/add-deuterium-asteroid-alerts-for-low-fuel.php` pour injecter des asteroides de deuterium et avertir les joueurs concernes.
- Debug : ajout de `scripts/add-inventory-item.php` pour injecter des objets, containers additionnels ou Mannys dans l'inventaire d'un joueur de developpement.
- API v38 : `/api/probe/missions` et les reponses de mission n'exposent plus de coordonnees absolues dans les descriptions, `metadata` ou `createdByEvent`; les secteurs publics y sont convertis en `sector.relative`.
- API v37 : les arrivees dans un secteur jamais visite contenant une planete habitee peuvent declencher un scenario de premier contact pondere par `gameplay.intelligentLife.scenarios`; le premier scenario implemente, `return_to_space_program`, cree une mission `Premier contact` et un message planetaire en nombres premiers.
- Debug : ajout de `scripts/force-inhabited-planet.php` pour injecter en CLI une planete habitee dans un secteur donne.
- API v42 : le premier contact `return_to_space_program` demande maintenant 5 ECE de métaux et 1 ECE de composés carbonés, puis comptabilise les matériaux largués par container sur la planète avec le joueur donateur.
- API v43 : ajout de `POST /api/probe/mannies/{mannyId}/drop-manny-cargo` pour larguer immédiatement la cargaison d’une Manny en attente de place et retenter son retour à bord.
- API v44 : ajout de `PATCH /api/probe/storage-containers/{containerId}` pour renommer un container de stockage via son champ `label`.
- API v45 : `POST /api/probe/mannies/{mannyId}/mine` accepte `targetContainerId` pour déposer les ressources minées dans un container détaché visible ou caché sur astéroïde.
- API v46 : `POST /api/probe/mannies/{mannyId}/detach-storage-container` expose `artificialObjectDetected` lors d’un détachement `hidden_on_asteroid`, avec l’id du container caché et l’astéroïde cible.
- API v47 : les containers cachés sur astéroïde persistent leurs découvreurs dans `discoveredByPlayerIds` et remontent dans `/api/probe/sector` uniquement pour ces joueurs.
- Scénario : chaque largage de matériaux demandé par `return_to_space_program` déclenche un message de remerciement planétaire indiquant les matériaux restant à envoyer.
- Interface : dans `/inventories`, le filtre par container propose une action de renommage quand un container précis est sélectionné.
- Interface : dans `/mannies`, une Manny `waiting_for_space` propose de rentrer sans sa cargaison.
- Interface : dans `/mannies`, le formulaire de minage peut envoyer les ressources vers un container détaché visible ou détecté sur l’astéroïde ciblé.
- Interface : ajout d’une vraie page 404 avec retour vers l’accueil, utilisée comme route frontend par défaut quand aucune route ne correspond.
- Stats : ajout du podium des découvreurs de mondes habités par une espèce intelligente sur `/stats`.
- Stats : les trois podiums de `/stats` proposent un bouton pour afficher les 9 premiers du classement.

### Fixed

- API v41 : le rappel d'une Manny sortie depuis moins d'un temps de trajet la fait maintenant faire demi-tour; la duree de retour correspond au temps deja passe sur la tache annulee.
- API v39 : `/api/probe/sector`, la messagerie, les alertes de vie intelligente et les reponses de mission remplacent les noms publics de planetes habitees qui contiendraient les coordonnees absolues du secteur par un libelle public sans coordonnees absolues; la messagerie n'utilise plus l'identifiant technique d'une planete comme libelle de destinataire.
- Debug : `scripts/force-inhabited-planet.php` genere maintenant un id opaque et stable par secteur pour ses planetes forcees, et retire l'ancien objet debug du meme secteur lorsqu'il est relance.
- Debug : `scripts/teleport-probe.php` finalise désormais les téléportations via `ProbeMovementService`, afin de déclencher les effets d'arrivée comme les scénarios de premier contact.
- Scénarios : observer un secteur courant contenant une planète habitée lance désormais le premier contact manquant, même si l'arrivée normale a été contournée par un outil de debug.
- Stockage : les synchronisations d'inventaire ne reconstruisent plus les ressources des containers depuis des totaux historiques potentiellement périmés, ce qui pouvait effacer des matériaux lors de requêtes concurrentes de minage ou de craft.
- Interface : la carte de l'imprimante atomique dans `Mannys & imprimante` utilise maintenant le libelle traduit en anglais.
- Interface : les rafraîchissements automatiques de la page Inventaires ne réinitialisent plus les règles de stockage en cours de modification.
- Interface : la page `/movement` propose désormais par défaut les coordonnées courantes de la sonde et garde le bouton de saut désactivé tant qu'une autre destination valide n'est pas saisie; les destinations ouvertes depuis `Sensors and radars` > `Prepare jump` restent préremplies.
- Interface : la page `/movement` affiche un avertissement discret lorsqu'une destination atteint la distance où un risque de destruction en croisière apparaît.
- Interface : la page `/sensors` affiche un journal repliable des secteurs précédemment visités, chargé par lots de 9 avec les scans détaillés disponibles.
- Debug : ajout de `scripts/sector-json.php` pour afficher en CLI le JSON brut d'un secteur à partir de ses coordonnées absolues.

## 2026-06-17

### Added

- API v36 : ajout de `POST /api/probe/mind-snapshot/reassign` pour réassigner le snapshot d'esprit d'une sonde morte ou piégée par un trou noir vers une nouvelle sonde, avec reset du référentiel local à `0,0,0`.
- API v35 : ajout du socle générique des missions de sonde avec persistance `probe_missions` / `probe_mission_steps`, objets `Mission` / `MissionStep`, `GET /api/probe/missions` (`/api/probe/mission` en alias) et `POST /api/probe/missions/{missionId}/abandon`.
- API v34 : la messagerie `/api/probe/messages` accepte des destinataires typés via `recipient.type` (`probe` par défaut, ou `planet`) et `recipient.id`.
- Messagerie : les messages exposent désormais `sender.type` et `recipient.type`; les endpoints peuvent être une sonde (`probeId`) ou une planète habitée (`planetId`).
- Interface : la page `/messaging` permet d’envoyer un message aux sondes du secteur courant et aux planètes habitées détectées dans ce secteur.
- API v32 : ajout de `POST /api/probe/mind-snapshot/reassign` pour réassigner le snapshot d'esprit d'une sonde morte ou piégée par un trou noir vers une nouvelle sonde, avec reset du référentiel local à `0,0,0`.
- Interface : les sondes mortes ou piégées par un trou noir affichent maintenant une alerte explicite avec une action de réattribution du snapshot d'esprit.
- Debug : ajout de `scripts/userinfos.php` pour auditer en CLI l'état complet d'une sonde, dont position absolue/relative, inventaire brut, secteurs visités, mouvements et événements planifiés.

### Fixed

- Base de donnees : l'initialisation d'une ancienne table `probe_messages` migre maintenant les colonnes `sender_type` / `recipient_type` avant de creer leurs index, evitant un crash au demarrage apres changement de branche.
- Craft : un craft terminé alors que le cargo est plein ne casse plus `GET /api/probe` ou `GET /api/probe/mannies`; il reste en attente de place et se finalise après libération de stockage.
- Stockage : `GET /api/probe/mannies` initialise/répare désormais le container interne de la sonde, réattache les items/Mannys à bord dont le container a disparu et libère les Mannys coincés sur un déplacement de stockage expiré devenu impossible.
- Interface : le container interne de la sonde est traduit en anglais dans les vues d'inventaire.

## 2026-06-15

### Added

- API v31 : les observations détaillées de secteur exposent `habitabilityScore` sur les planètes des secteurs courants ou déjà visités.
- API v30 : ajout des recettes `thermal_protection_shell`, `parachute_pack`, `descent_guidance_module` et `atmospheric_drop_kit`.
- API : ajout de `POST /api/probe/mannies/{mannyId}/drop-storage-container` pour larguer un container additionnel sur une planète avec consommation d’un kit de largage atmosphérique.
- Secteurs : les containers largués sur planète sont persistés dans le JSON du secteur avec `originProbeId`, sans être exposés dans les observations API pour le moment.
- Interface : ajout du formulaire Manny “Larguer un container sur une planète”.
- Stats : ajout du nombre de planètes habitables dans les secteurs générés et dans les secteurs visités.
- Interface : la page `/sensors` affiche le score d’habitabilité des planètes et documente ce score avec les unités.

### Fixed

- Craft : la complétion d’une fabrication est idempotente, évitant qu’un double rafraîchissement concurrent crée deux containers, items ou Mannys pour une seule tâche.

## 2026-06-14

### Added

- Stats : ajout du nombre de waypoints installés dans l'univers et d'un podium des joueurs en ayant installé le plus.
- Stats : ajout d'un podium des sondes ayant visité le plus de secteurs sur la page publique des statistiques.
- Stats : ajout du champ `neumann_probes.exclude_from_stats` pour retirer des sondes des statistiques publiques.

### Fixed

- Interface : le panneau de navigation utilise désormais le même fond que l'espace de travail.
- Interface : dans `Inventaires`, les compteurs de total sont alignés avec les compteurs des lignes par container.
- Interface : la case `Se souvenir de moi` est de nouveau transmise lors d'une connexion OAuth, afin de poser un cookie de session persistant.

## 2026-06-13

### Added

- API v28 : les posts du forum exposent désormais `firstMessageId` et les réponses de détail de post incluent `firstMessage`.
- API v27 : les messages du forum exposent `editedAt`, renseigné lorsqu’un message a été modifié.
- API v26 : ajout d’un forum léger sous `/api/forum/*`, avec catégories ordonnées, posts et messages/réponses.
- Forum : ajout des endpoints `GET|POST /api/forum/categories`, `GET|PATCH|DELETE /api/forum/categories/{categoryId}`, `GET|POST /api/forum/posts`, `GET|PATCH|DELETE /api/forum/posts/{postId}`, `GET|POST /api/forum/posts/{postId}/messages` et `PATCH|DELETE /api/forum/messages/{messageId}`.
- Forum : les catégories sont réservées aux joueurs `forumAdmin`; les joueurs `forumModerator` ou `forumAdmin` peuvent épingler, modifier et supprimer tous les posts et messages.
- Joueurs : ajout des flags persistants `forumAdmin` et `forumModerator`, exposés dans `/api/me`.

### Changed

- Craft : clarification de la description du marque-page de navigation dans l’interface et les exemples API.
- Forum : `GET /api/forum/posts/{postId}` sépare le message initial dans `firstMessage`; le tableau `messages` contient uniquement les réponses paginées.
- Forum : l’auteur d’un message peut désormais modifier son propre message via `PATCH /api/forum/messages/{messageId}`; les modérateurs et admins conservent le droit de modifier tous les messages.
- Les clients typés doivent accepter `apiVersion: 28` et les nouveaux schémas `ForumCategory*`, `ForumPost*`, `ForumMessage*`, les champs `ForumPost.firstMessageId`, `ForumMessage.editedAt`, `firstMessage` sur les réponses de détail/création de post, ainsi que les champs `forumAdmin` et `forumModerator` sur `Player`.

### Fixed

- Interface : la recette sélectionnée dans `Mannys & imprimante > Fabriquer` reste conservée après les rafraîchissements automatiques.

## 2026-06-12

### Added

- API v25 : ajout des warnings persistants de dégâts de mouvement avec `GET /api/probe/damage-warnings` et `PATCH /api/probe/damage-warnings/{damageWarningId}` pour marquer un warning comme lu.
- Mouvement : à partir de 5 containers additionnels, un saut peut casser un lien de container avec 10% de risque, puis +10 points par container supplémentaire jusqu’à 100%.
- Mouvement : le container perdu est tiré au sort dès l’initiation du saut, puis devient un container dérivant avec son contenu dans le secteur de départ en fin d’accélération ou dans le secteur d’arrivée en début de décélération.
- API v24 : ajout des recettes `electric_motor`, `battery_pack`, `linear_actuator` et `manny`.
- Craft : la fabrication d’une Manny crée désormais une vraie entité Manny stockée dans la sonde, avec l’encombrement et la capacité cargo standards.

### Changed

- Craft : le calcul des composants manquants est désormais récursif, afin qu’une recette puisse embarquer plusieurs niveaux de sous-recettes dans son coût et sa durée.
- Interface : les écrans `Mannys & imprimante` et `Inventaires` affichent les nouveaux composants et calculent récursivement la disponibilité des ingrédients.
- Interface : les warnings de dégâts non lus mettent l’onglet `Alertes` en style warning et s’acquittent via l’API au lieu du stockage local.
- Déploiement : le schéma initialise automatiquement la table `probe_damage_warnings` et ses index.
- Les clients typés doivent accepter `apiVersion: 25`, les nouveaux types d’items `electric_motor`, `battery_pack` et `linear_actuator`, la recette/sortie `manny`, ainsi que les schémas `ProbeDamageWarning*`.

### Fixed

- Interface : les panneaux interactifs ouverts dans les listes de métriques conservent maintenant leur état lors des rafraîchissements automatiques.

## 2026-06-11

### Changed

- Réécriture complète de la Web UI : l’ancien template monolithique a été remplacé par un shell commun `templates/main.html`, des templates dédiés par page et des scripts JavaScript spécifiques par écran.
- Les anciens panneaux console sont désormais accessibles comme pages web autonomes : `/`, `/sensors`, `/movement`, `/inventories`, `/mannies`, `/messaging` et `/alerts`.
- Les pages publiques `/about`, `/changelog`, `/stats` et `/api-docs` ont été intégrées au même système de routes frontend, avec Swagger UI sur `/api-docs`.
- Le sélecteur de langue persiste maintenant le choix dans un cookie, reste sur la route courante et recharge le dictionnaire i18n avec une URL versionnée.
- Le menu tutoriel du header ouvre les tutoriels intégrés; après une première connexion OAuth et le choix du pseudonyme, le joueur est redirigé vers `/?tutorial=context`.

### Removed

- Suppression de l’ancien template `templates/home.html`, qui n’était plus utilisé depuis la migration vers les routes frontend dédiées.

## 2026-06-09

### Added

- Ajout de la page publique `/stats`, accessible depuis le pied de page, qui affiche les statistiques agrégées de l’univers depuis `var/stats.json`.
- Ajout du script `scripts/generate-stats.php` pour générer le JSON de statistiques destiné à une tâche CRON quotidienne.
- Les statistiques couvrent notamment les sondes, secteurs générés et visités, trous noirs, astéroïdes par ressource minable, Mannys perdus ou oubliés, containers détachés et distances extrêmes entre sondes.

## 2026-06-08

### Added

- API v23 : ajout du détachement de containers additionnels par Manny, en mode dérivant (`drifting`) ou caché sur astéroïde (`hidden_on_asteroid`), avec conservation du contenu et des règles de routing.
- API : ajout de `POST /api/probe/mannies/{mannyId}/detach-storage-container`, `POST /api/probe/mannies/{mannyId}/inspect-asteroid` et `POST /api/probe/mannies/{mannyId}/recover-storage-container`; le salvage existant peut aussi récupérer un container détaché dérivant.
- API : les containers cachés sur astéroïde peuvent être détectés lors du minage ou d’une inspection Manny via `artificialObjectDetected`, sans exposer leur contenu.

### Changed

- Interface : le temps restant d’un déplacement dans l’onglet `Sonde` se met maintenant à jour localement et rafraîchit les données à l’arrivée.
- Interface : amélioration de l’onglet `Capteurs et radars`, qui désactive désormais le bouton `Scanner` quand la somme des coordonnées relatives est impaire.
- Interface : dans `Mannys & imprimante`, une Manny située dans un autre secteur affiche `Trop éloignée` dans son accordéon et ne conserve que la métrique `Position` au dépliage.
- Les clients typés doivent accepter `apiVersion: 23`, les tâches Manny `detaching_storage_container` et `inspecting_asteroid`, ainsi que le type de secteur `detached_container`.

### Fixed

- API : lorsqu’une sonde revient dans le secteur d’une Manny `forgotten` qui lui appartient encore, la Manny inactive est automatiquement remise à bord si une place de stockage est disponible, et l’objet de secteur `forgotten` est supprimé.
- JS : une tentative de scan avec des coordonnées relatives invalides efface maintenant le résultat du scan précédent tout en conservant le message d’erreur existant.
- JS : la liste des Mannys est resynchronisée après le chargement du secteur courant de la sonde afin d’afficher correctement les Mannys trop éloignées.

## 2026-06-06

### Added

- API v16 : ajout de la messagerie inter-sondes avec `POST /api/probe/messages`, `GET /api/probe/messages` et `PATCH /api/probe/messages/{messageId}/read`.
- Un message peut être envoyé uniquement à une autre sonde présente dans le même secteur que la sonde émettrice.
- Les messages reçus exposent l’émetteur, le destinataire, le secteur relatif, le corps du message, le statut `unread` / `read`, `readAt`, `createdAt` et `updatedAt`.
- API v18 : ajout de `GET /api/probe/messages/sent` pour consulter les messages envoyés par la sonde courante avec la même pagination que les messages reçus.
- Interface : ajout de l’onglet `Messagerie` entre `Mouvement` et `Alertes`, avec envoi à une sonde du secteur, liste des messages reçus et passage des messages non lus au statut `read`.

### Changed

- API v17 : `GET /api/probe/messages` retourne par défaut les 50 derniers messages reçus et accepte les paramètres optionnels `limit` et `offset` pour consulter l’historique.
- La réponse de `GET /api/probe/messages` expose désormais un objet `pagination` avec `limit`, `offset`, `count`, `total` et `hasMore`.
- API v19 : `GET /api/probe/messages/sent` n’expose plus l’état de lecture du message (`status`, `readAt`, `updatedAt`).
- Interface : la vue `Messagerie` sépare maintenant `Messages reçus` et `Messages envoyés` en onglets de classeur.
- Interface : l’onglet `Messages envoyés` n’affiche plus de statut `Lu` / `Non lu`.
- Interface : les règles de stockage par container utilisent des listes à sélection multiple avec une aide contextualisée et empêchent un même type d’être choisi dans plusieurs règles du même container.
- API v20 : les objets détaillés de `/api/sector` et `/api/probe/sector` exposent désormais `massUnit` et `radiusUnit` quand `mass` / `radius` sont présents.
- Interface : les masses et rayons des corps détectés affichent leur unité (`M☉`, `R☉`, `M🜨`, `R🜨`, `km`, `AU`) et l’onglet `Capteurs et radars` explique ces unités.
- Interface : le dictionnaire de traduction JS est désormais servi via un JSON versionné et cacheable au lieu d’être injecté inline dans le HTML.
- API v21 : ajout des recettes `micro_conductor`, `ceramic_insulator`, `crystal_substrate`, `dopant_matrix` et `integrated_circuit`, fabriquées via l’imprimante atomique.
- Interface : l’`Imprimante 3D atomique` est renommée `Imprimante atomique`, et les nouveaux composants de circuit sont affichés dans les inventaires, recettes et règles de stockage.
- Craft : les coûts de deutérium des recettes sont vérifiés et consommés en ECE de cuve, afin que `0.13 ECE` corresponde à 13% d’une cuve pleine.
- API v22 : ajout de `POST /api/probe/atomic-printer/craft` pour lancer les recettes de l’imprimante atomique avec réservation automatique d’une Manny assistante.
- Interface : l’onglet `Mannys` devient `Mannys & imprimante`; l’imprimante atomique y apparaît comme poste de fabrication et les Mannys réservées affichent `Assistance à l’imprimante`.

### Breaking Changes

- Les clients typés doivent accepter `apiVersion: 22`, les nouveaux schémas `ProbeMessage*`, le champ `pagination` sur `ProbeMessagesResponse` et les nouveaux types d’items de craft électronique.
- Les recettes `atomic_3d_printer` doivent être lancées via `POST /api/probe/atomic-printer/craft`; `POST /api/probe/mannies/{mannyId}/craft` est réservé aux recettes fabriquées directement par une Manny.
- Les clients de `GET /api/probe/messages/sent` doivent utiliser `ProbeSentMessagesResponse` : les champs `status`, `readAt` et `updatedAt` ne sont plus présents sur les messages envoyés.

## 2026-06-05

### Added

- Rédaction et intégration des tutoriels de premiers pas : contexte de la sonde, déplacement et utilisation des Mannys, avec illustrations agrandissables.

### Changed

- API v15 : l’installation d’un waypoint-bookmark passe par `POST /api/probe/mannies/{mannyId}/install-bookmark` et crée une tâche Manny `installing_waypoint_bookmark` de 10 secondes.
- L’ancien endpoint instantané `/api/probe/waypoint-bookmarks/{itemId}/deploy` n’est plus exposé dans l’API publique.
- L’interface Actions ne contient plus le formulaire “Poser un marque-page”; l’action est disponible dans Mannys > Attribuer une tâche à la Manny.
- L’ordre consomme un waypoint-bookmark en stock au lancement de la tâche, puis conserve la persistance existante des `waypointBookmarks` sur l’objet ciblé.

### Breaking Changes

- Les clients typés doivent accepter `apiVersion: 15` et la nouvelle tâche Manny `installing_waypoint_bookmark`.

## 2026-06-04

### Added

- API v14 : ajout de la gestion des stocks par container (`probe-core` + containers supplémentaires individuels).
- Ajout des endpoints `/api/probe/storage-containers`, `/api/probe/storage-containers/{containerId}`, `/api/probe/storage-containers/{containerId}/rules` et `/api/probe/storage-moves`.
- Ajout du script `scripts/migrate-storage-containers.php` pour répartir les stocks existants selon la règle par défaut.
- L’interface des sous-systèmes affiche les lignes d’inventaire par container et permet de filtrer l’inventaire par container.
- Ajout de l’accordéon “Gérer les règles de stockage par container”.
- L’interface d’inventaire ajoute des actions par ligne de container pour déplacer le stock via une Manny ou le jeter dans l’espace avec confirmation.
- Ajout de `CHANGELOG.md` comme source de vérité versionnée pour les changements du projet.
- Ajout de la route `/changelog`, qui affiche ce changelog en HTML depuis le site.
- Ajout de `config/gameplay.json` et `config/universe.json` pour centraliser les curseurs de gameplay et de génération procédurale.

### Changed

- `/api/probe` conserve l’inventaire global, mais les items exposent maintenant leur `container` et les ressources exposent leurs lignes `containers`.
- Le déplacement de stock entre containers passe par une tâche Manny `moving_stockage`.
- `/api/probe/storage-moves` accepte des lots `itemIds` / `targetMannyIds`, et le jettison de ressources peut cibler un `containerId`.
- L’imprimante 3D atomique reste dans la sonde et ne peut pas être déplacée; le deutérium reste dans sa cuve dédiée.
- Les fichiers `config/*-local.json` surchargent les configurations versionnées sans être suivis par Git.

### Breaking Changes

- Les clients typés doivent accepter `apiVersion: 14`, le champ `inventory.containers`, les placements de stock par container et la nouvelle tâche Manny `moving_stockage`.
