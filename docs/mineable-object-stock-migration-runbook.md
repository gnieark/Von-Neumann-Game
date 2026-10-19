# Migration des stocks minables — maintenance et restauration

Cette procédure convertit ensemble SQL et les secteurs pour les lots 1 à 6. Le déploiement en production relève du lot 7 : [commandes de bascule et surveillance](mineable-object-stock-cutover.md), préparées pour exécution par l’opérateur. Les planètes, leur moissonnage Others et les constructs hors `others_mothership_wreck` conservent leurs stocks JSON. Le contrat public reste en version 148.

## Préparer la copie et la maintenance

Arrêter nginx, le scheduler, les contrôleurs Others, les cron et tous les autres writers ; empêcher leur relance automatique. Attendre la fin des requêtes PHP et transactions engagées. Un GET qui génère un secteur est aussi un writer. Maintenir cet arrêt jusqu'à la fin des deux copies : `--single-transaction` seul ne synchronise pas SQL avec les fichiers.

Depuis la VM de développement :

```bash
bash scripts/one-shot-scripts/export-mineable-maintenance-copy.sh \
  root@192.168.100.20 --maintenance-confirmed
```

Le script lit `var/database-prod.json`, effectue un `mysqldump` sans verrouillage des tables puis un `rsync --checksum` depuis `/var/www/neumann-probe.tinad.fr/data/universe/`. Un troisième argument permet d'indiquer un autre répertoire d'univers. La connexion MariaDB interne utilise explicitement `--skip-ssl`, ce serveur ne proposant pas TLS ; la clé SSH concerne rsync. Le mot de passe SQL passe par un fichier temporaire privé, supprimé à la sortie. Chaque exécution utilise un nouveau dossier `var/lot6-copy-XXXXXXXX` ; une sortie non nulle signifie que la copie n'est pas validée.

Pour une répétition, importer le dump dans une **base dédiée** et dupliquer le répertoire d'univers. Ne jamais lancer la migration sur la configuration SQL de production pendant le développement. La configuration de répétition doit désigner cette base dédiée. Les sauvegardes, manifestes et dumps contiennent des données privées et restent hors Git et hors répertoire servi par HTTP.

Conserver également la révision de l'ancien code et sa configuration. Exécuter la maintenance avec le compte propriétaire des fichiers de jeu. Prévoir l'espace pour la copie de travail, la sauvegarde SQL/JSON et les descripteurs préparés. L'outil refuse les liens symboliques dans l'univers et les installations SQL comportant des tables non InnoDB, des vues, triggers, routines ou événements qui exigeraient une procédure de sauvegarde supplémentaire.

## Simuler puis appliquer

Depuis la racine du projet, remplacer les trois chemins ci-dessous par ceux de la même paire SQL/univers :

```bash
DB_CONFIG=/chemin/prive/database-copy.json
UNIVERSE=/chemin/prive/universe-copy
MANIFEST=/chemin/prive/migration-stocks-20261018

php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE"

# Tous les writers de cette paire restent arrêtés et drainés.
php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --apply --maintenance-confirmed

php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --verify
```

La simulation et `--verify` ne font aucune écriture SQL ni JSON. La simulation bloque sur les intentions en attente : l'application les traite explicitement **après sauvegarde**, sans lancer le scheduler général. Une intention dont le `before` contredit le secteur ou dont la source est ambiguë bloque la conversion. Le marqueur d'application permet de reprendre après publication du fichier mais avant confirmation SQL.

L'application procède par phases enregistrées dans `manifest.json` :

1. Sauvegarde du schéma et des lignes de toutes les tables, des séquences SQLite et des octets de tous les secteurs. Les fichiers sont regroupés dans `backup/sectors.rows`, avec hash d'archive et hashes individuels ; les lignes SQL sont stockées dans `backup/sql/*.rows`. La sauvegarde est synchronisée avant publication de `backup.json`.
2. Schéma explicite issu de `SchemaInitializer`, garde SQL `storage_migrations` bloquée, initialisation de la coordination des sondes et drainage contrôlé des anciennes intentions. Les admissions encore actives bloquent la maintenance.
3. Plan immuable `plan.json` : occurrence, ancien/nouvel ID, stocks normalisés, références, décisions et hashes avant/après. Pour un doublon, la première occurrence par ordre lexicographique de chemin conserve l'ID ; les suivantes reçoivent un ID déterministe propre au manifeste, en conservant notamment le préfixe `mtr_`. Une référence ambiguë exige une décision par occurrence.
4. Réservation des identités courantes et historiques identifiables, puis import par transactions de 200 sources. Un replay vérifie l'attribution et les valeurs : il ne réinitialise pas une ligne modifiée ou supprimée.
5. Réécriture des références opérationnelles, publication atomique des descripteurs sans stock, conversion SQL des Mannies oubliées avec son propre manifeste `forgotten.json`. Les snapshots terminaux restent historiques.
6. Annulation des événements `manny.task` en échec et rappel métier de la tâche encore associée. Aucune récolte échue n'est exécutée. Une mission ayant remplacé l'événement n'est pas écrasée ; une Manny absente n'est pas recréée. Les reçus SQL rendent cette phase reprenable, y compris après commit avant écriture du manifeste. Un rappel impossible bloque la validation.
7. Vérification des stocks par source, de l'unicité, des identités réservées, des références modifiées, des Mannies oubliées/rappelées et des hashes de fichiers. Les présences oubliées et stocks JSON couverts résiduels sont interdits. L'ouverture de la garde n'intervient qu'après validation globale.

Les références sans source minable locale restent inventoriées dans `referencesWithoutLocalSource` : elles peuvent concerner un objet exclu, une cible disparue ou un objet ayant changé de secteur. Elles ne créent aucun stock. Les contradictions de trajectoire active et les références ambiguës à des doublons bloquent le plan.

Les restitutions structurelles des rappels sont prouvées séparément : `recall-sectors.json` conserve les secteurs avant rappel et la frontière des intentions, puis `recall-files.json` consigne les publications vérifiées par rejeu des seules intentions. Les secteurs nécessaires doivent déjà exister ; aucune génération procédurale n'est autorisée pour réparer un secteur manquant pendant la migration. Le manifeste conserve aussi les empreintes des sources PHP utilisées lors des exécutions, en complément de la révision Git.

Les ressources absentes d'un astéroïde sont explicitement normalisées à zéro selon la décision validée, en conservant les champs présents. Cette règle ne s'applique pas aux épaves. Les quantités présentes négatives, non finies ou invalides bloquent la conversion. Aucun stock n'est calculé depuis la masse.

`--schema-only --maintenance-confirmed` prépare uniquement le schéma et la coordination des sondes ; ce n'est pas une conversion de l'univers ni une autorisation de rouvrir le jeu.

## Décisions explicites

Passer `--resolutions=/chemin/prive/resolutions.json` lors de la première application. Exemple de structure, avec des emplacements issus de l'audit réel :

```json
{
  "references": {
    "sql:scheduled_events/123/payload_json/objectId": {
      "occurrence": "x_p0/y_p0/z_p0/sector_p0_p0_p0.json#/objects/0",
      "reason": "Rattachement vérifié à cette occurrence"
    }
  },
  "forgottenMannies": {
    "UID_A_REMPLACER": {
      "acceptSqlCargo": true,
      "reason": "SQL porte la cargaison courante ; présence JSON ancienne"
    }
  }
}
```

Une orpheline nécessite `action: "abandon"` et une justification. Ses engagements doivent avoir été annulés selon les règles métier avant conversion. Aucune ligne Manny fictive ni addition de cargaison n'est créée. Les résolutions restent dans le manifeste ; une reprise ne peut pas en substituer d'autres.

## Interruption et reprise

Garder tous les writers arrêtés et relancer exactement `--apply` avec le **même** répertoire de manifeste. Ne pas créer un deuxième manifeste pour contourner une anomalie. Les phases finies et les opérations déjà validées sont vérifiées ou reconnues par leurs reçus. Un autre couple SQL/univers, un plan modifié, une sauvegarde corrompue ou un stock ayant changé sont refusés.

La garde bloque le point d'entrée web, la construction du noyau API et du scheduler tant que la migration est incomplète. Les outils de maintenance utilisent leur propre connexion explicite pour pouvoir terminer ou restaurer. Cette garde ne remplace pas l'arrêt des anciens processus et autres scripts de maintenance.

La vérification est une vérification **avant réouverture** : après reprise normale du jeu, stocks, tâches et fichiers évoluent légitimement et ne doivent plus correspondre au manifeste initial.

## Restaurer avant réouverture

```bash
php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --restore --maintenance-confirmed
```

Cette commande est destructive pour la paire explicitement désignée : elle vérifie d'abord toute la sauvegarde, remplace **toutes les tables de la base**, restaure les octets des secteurs et retire les nouveaux JSON absents de la sauvegarde. Elle vérifie les nombres de lignes et les hashes restaurés. Si elle s'interrompt, maintenir l'arrêt et la relancer avec le même manifeste.

Restaurer ensuite l'ancien code et sa configuration, vérifier propriétaires et droits d'accès, puis contrôler le démarrage avant réouverture. Conserver le manifeste restauré comme preuve ; utiliser un nouveau dossier pour une nouvelle répétition. Après réouverture et nouvelles écritures de jeu, cette restauration historique ferait perdre ces écritures : corriger en avant ou préparer une migration inverse contrôlée sous maintenance. Restaurer seulement les JSON n'est jamais un retour arrière valide.


## Dimensionnement observé sur la répétition

La copie synchronisée testée contient **208 241 secteurs, 84 540 sources et 4 096 793 lignes dans 72 tables SQL**. Les trois exécutions avec deux interruptions volontaires totalisent environ **18 minutes**, la vérification indépendante **85 secondes**, et la restauration SQL/secteurs **6 min 08 s**. Le pic mémoire PHP de l’application atteint **632 Mio** ; la sauvegarde canonique occupe environ **1,30 Gio**, auxquels s’ajoutent les entrées, le clone de travail, les descripteurs préparés et la base locale.

Prévoir une marge sur ces mesures de développement, réalisées avec un stockage et une version MariaDB différents de la production. Le [rapport du lot 6](mineable-object-stock-migration-inventory.md#lot-6--migration-explicite-et-répétition-complète-18-octobre) détaille les preuves, les limites et les journaux. La comparaison exhaustive indépendante après restauration (toutes les lignes SQL, tous les hashes et lecture de tous les secteurs par l’ancien code) a pris **3 min 27 s**, avec un pic de **932 Mio**. La fenêtre réelle de maintenance et la surveillance du jeu après ouverture font partie du lot 7.
