# Changelog

Toutes les modifications notables de Von Neumann Game seront documentées ici, avec une attention particulière aux changements qui peuvent impacter les frontends et les intégrations API.

## 2026-06-16

### Added

- API v33 : ajout des alertes persistantes généralistes avec `GET /api/probe/alerts` et `PATCH /api/probe/alerts/{alertId}`.
- Mouvement : lorsqu’une sonde arrive dans un secteur contenant une planète avec vie intelligente, une alerte persistante `intelligent_life` est créée avec le secteur et la planète concernés.

### Changed

- API : `GET /api/probe/damage-warnings` et `PATCH /api/probe/damage-warnings/{damageWarningId}` restent disponibles comme alias de compatibilité, mais sont remplacés par les endpoints d’alertes généralistes.
- Interface : la page `/alerts` et l’onglet de navigation consomment les alertes persistantes généralistes, dont les détections de vie intelligente.

## 2026-06-15

### Added

- API v32 : les observations détaillées de secteur exposent `intelligentLife` sur les planètes des secteurs courants ou déjà visités.
- Génération : une planète habitable (`habitabilityScore > 0.35`) a désormais 20% de chance d’héberger une espèce intelligente, information persistée dans le JSON du secteur.
- Stats : le compteur de mondes avec vie intelligente est alimenté depuis les secteurs générés.
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
