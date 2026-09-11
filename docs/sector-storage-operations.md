# Stockages de secteur : livraison et exploitation

Les dépôts, leurs stocks, la connaissance de chaque sonde, les transferts et leurs réservations sont canoniques en SQL. Les fichiers de secteur ne contiennent ni projection ni copie des stocks d’un dépôt. Les containers détachés restent dans leurs tables SQL existantes.

## Bascule d’une installation existante

1. Suspendre les commandes de logistique et arrêter les workers. Sauvegarder la base SQL complète et le répertoire `universePath` du fichier `config/app.json`, ainsi que la version du code.
2. Répéter la procédure sur une copie de la base. Le script refuse les identités dupliquées et les types Others inconnus : les corriger par une migration explicite qui traite aussi leurs références actives.
3. Simuler puis appliquer :

   ```sh
   php scripts/one-shot-scripts/migrate-sector-storage.php --database-config=config/database.json --dry-run
   php scripts/one-shot-scripts/migrate-sector-storage.php --database-config=config/database.json --apply
   ```

4. Déployer le code canonique et auditer avant de rouvrir les commandes :

   ```sh
   php scripts/one-shot-scripts/audit-sector-storage.php --database-config=config/database.json --sector-path=data/universe
   ```

5. Redémarrer les workers existants. Ils traitent `sector.effect`, `anomaly.broadcast`, les actions Others et les tâches Manny. Réactiver les commandes après vérification des résultats et des erreurs du scheduler.

La migration ajoute les dix colonnes définies dans `SchemaInitializer::sectorStorageColumnDefinitions()` et les tables/index de `sectorStorageStatements()`. Elle renseigne explicitement les noms et la provenance des anciens objets Others, puis les dates des objets détachés. Les stocks et UID restent identiques ; les compteurs avant/après sont affichés. La relancer ne convertit pas en technologie Others un objet de sonde importé depuis sa première exécution. Elle ne crée aucun dépôt et ne transforme aucun fichier secteur. Elle ne remplace pas la migration historique des containers JSON.

Après transformation, un retour arrière exige la restauration cohérente de SQL, des secteurs et du code sauvegardés. Ne pas réutiliser un ancien binaire contre le nouveau schéma.

## Reprise et conservation

L’acceptation réserve le contenu et la place puis inscrit une seule échéance. Le résultat durable distingue contenu livré, perdu et libéré. La fin déjà échue l’emporte sur une interruption. Une Manny peut attendre une place pour rentrer après livraison ; cela ne relivre pas le contenu. Le GET de suivi reste disponible indépendamment de la réutilisation de l’acteur.

Les effets de secteur sont inscrits dans la transaction métier. Le worker ne les projette qu’après commit. Il prend le verrou du fichier, recharge son état et mémorise l’opération dans `appliedSectorEffects`. Une panne après écriture du JSON et avant acquittement SQL se reprend sans appliquer deux fois la mutation. Les autres écritures du dépôt de fichiers utilisent le même verrou et une comparaison de révision SHA-256 ; une écriture périmée échoue et doit être reprise depuis un chargement neuf. La révision reste en mémoire ; `appliedSectorEffects` est un journal canonique d’opérations, vide sur les secteurs qui n’ont reçu aucun effet.

L’ouverture et son émission sont atomiques. Les destinataires sont bornés par leurs IDs présents à l’ouverture. Chaque page contient au plus 100 sondes et 100 vaisseaux, sans filtre de statut. Livraison, alerte et curseur avancent dans la même transaction. Les alertes ne divulguent que la direction normalisée ou l’origine locale, jamais la position de la source. Les lignes physiquement supprimées avant traitement ne sont pas recréées. Les effectifs capturés dans `anomaly_broadcast_recipient_counts` permettent de journaliser les destinataires manquants, une seule fois, dans `others_operator_audit` (`recipients_missing`).

En cas d’événement `failed`, consulter `last_error`, les IDs de transfert/action/effet et l’audit. Ne pas modifier un stock, une réservation ou un curseur à la main pour contourner l’erreur. Après correction de sa cause, réarmer explicitement l’événement concerné avec la procédure du scheduler. Un GET n’effectue aucune réparation.

L’audit est en lecture seule et retourne un statut non nul si un invariant contrôlé échoue : quantités et réservations invalides, références orphelines, doublons d’identité, dépassements des stocks et réservations de capacité, émission manquante, auxiliaire occupé par une action terminale, effet sans événement de reprise, dépôt copié dans les JSON. Les contrôles SQL de capacité embarquée portent sur les stocks et réservations des transferts ; les placements d’unités restent contrôlés par le moteur de stockage. Il rapporte aussi les transferts par état, les pertes par motif, le plus ancien effet, les intervalles d’IDs restant à diffuser et les événements en erreur. Un intervalle d’IDs n’est pas un nombre exact de destinataires lorsque des lignes ont été supprimées.

## Vérifications reproductibles

```sh
php tests/ApiTests.php
php tests/SectorTests.php
php tests/StorageConcurrencyTests.php
php tests/StorageConcurrencyTests.php --mysql-config=config/database.json
```

Les deux premières suites exécutent leurs fixtures isolées et les tests spécialisés. La dernière commande utilise de vraies connexions MariaDB/InnoDB et des tables portant un préfixe aléatoire dans la base configurée : les tables du jeu ne sont jamais modifiées. Cela fonctionne avec un compte autorisé à créer des tables mais pas à créer une base. Le nettoyage supprime uniquement les tables du test. `pcntl` et les sockets locaux sont nécessaires aux barrières entre processus. La campagne répète aussi la migration d’un inventaire isolé et compare les colonnes migrées à celles d’une installation neuve.

Les tests de budget comptent les exécutions, lignes lues et paramètres. Pour 1/10/100 dépôts, présence et connaissance utilisent deux requêtes. Une page fixe d’inventaire garde deux lectures avec 1/50/500 objets. Les identités utilisent cinq lectures par bloc de 100 IDs. La diffusion de 1/100/1 000 sondes utilise respectivement 10/10/91 requêtes sur SQLite, avec au plus 800 paramètres par insertion. Tout dépassement des budgets fait échouer la suite.

Les injections de panne couvrent le débit avant crédit, la page d’alertes avant progression du curseur et le rejeu d’une projection déjà écrite. Les rejeux de règlement et les conflits de versions vérifient également qu’aucune quantité ni identité n’est dupliquée.
