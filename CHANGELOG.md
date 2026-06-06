# Changelog

Toutes les modifications notables de Von Neumann Game seront documentées ici, avec une attention particulière aux changements qui peuvent impacter les frontends et les intégrations API.

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
