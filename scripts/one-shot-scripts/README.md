Scripts de migration à exécuter explicitement lors des déploiements qui le demandent.

## Nettoyage des anciennes références de containers détachés

Après l’import des containers détachés dans SQL avec l’ancienne migration,
`cleanup-legacy-detached-container-json.php` supprime uniquement des fichiers de
secteurs les clés `detachedContainers`, `hiddenDetachedContainers`,
`planetDroppedContainers` et les entrées `objects` de type
`detached_container`. Ce script n’ouvre jamais la base et ne tente donc pas de
réimporter les données supprimées.

Application, scheduler et workers arrêtés, avec une sauvegarde du répertoire
d’univers :

```bash
php scripts/one-shot-scripts/cleanup-legacy-detached-container-json.php --dry-run
php scripts/one-shot-scripts/cleanup-legacy-detached-container-json.php
php scripts/one-shot-scripts/cleanup-legacy-detached-container-json.php
```

Le dernier passage doit annoncer `filesChanged: 0`. Après ce déploiement, le
chargement d’un secteur contenant encore une de ces références échoue
explicitement au lieu de l’ignorer. Le filtrage à la sauvegarde reste actif afin
de ne pas recopier dans le JSON les containers agrégés depuis SQL.

## Suppression de la ressource historique `other`

`cleanup-legacy-resources.php` audite et migre les dernières représentations
`other` dans SQL, les événements/tâches Manny et les fichiers de secteurs. Il
fusionne les quantités dans `carbon_compounds`, sauf l’ancien cargo Manny qui
est réparti selon son profil minier lorsqu’il permet encore de distinguer glace
et composés carbonés. Il supprime enfin `other_stock` et `cargo_other` si ces
colonnes existent encore.

Arrêtez l’application, le scheduler et les workers, sauvegardez la base et le
répertoire d’univers, puis exécutez :

```bash
php scripts/one-shot-scripts/cleanup-legacy-resources.php --dry-run --database-config=var/database-prod.json
php scripts/one-shot-scripts/cleanup-legacy-resources.php --database-config=var/database-prod.json
php scripts/one-shot-scripts/cleanup-legacy-resources.php --database-config=var/database-prod.json
```

Le dernier passage doit annoncer zéro ligne, payload, règle, projection et
fichier modifié. Utilisez `--universe-path=/chemin/vers/univers` si nécessaire,
ou `--database-only` / `--sectors-only` pour séparer les contrôles. Le code de
l’application ne lit plus aucun format `other` : ne redémarrez pas les services
avant un second passage vierge.

## Encombrement des moteurs au deutérium

`migrate-deuterium-engine-container-space.php` applique le passage de 0,06 à
0,05 ECE aux moteurs existants dans SQL, aux tâches actives et aux piles à la
dérive conservées dans les fichiers de secteurs.

Arrêtez l’application, le scheduler et les workers, puis sauvegardez la base et
le répertoire d’univers avant d’exécuter :

```bash
php scripts/one-shot-scripts/migrate-deuterium-engine-container-space.php --dry-run --database-config=config/database.json
php scripts/one-shot-scripts/migrate-deuterium-engine-container-space.php --database-config=config/database.json
php scripts/one-shot-scripts/migrate-deuterium-engine-container-space.php --database-config=config/database.json
```

Le dernier passage doit annoncer zéro ligne et zéro fichier modifiés. Utilisez
`--universe-path=/chemin/vers/univers` si le chemin diffère de `config/app.json`.

## Images d’illustration des alertes

`migrate-alert-illustration-images.php` est obligatoire avant de déployer la
version API 114 sur une base existante. Il ajoute, de façon idempotente, la
colonne nullable `probe_damage_warnings.illustration_image_url` :

```bash
php scripts/one-shot-scripts/migrate-alert-illustration-images.php --database-config=config/database.json
```

Sauvegardez la base, arrêtez l’application et les workers, déployez le code,
exécutez cette commande puis relancez-la. Le second passage doit indiquer
`column_added=no`. Ne redémarrez l’application qu’après ces contrôles.

## Trajectoires d’astéroïdes motorisés

`migrate-asteroid-trajectories.php` est obligatoire avant de déployer la version
API 111. Il convertit atomiquement les fichiers de secteurs : tout ancien
astéroïde `motorized: true` reçoit `motorFuelStatus: full`, puis il initialise le
schéma SQL des trajectoires. L’application ne contient aucun fallback de lecture
pour l’ancien format.

Ordre de production obligatoire :

1. Arrêter le scheduler et les workers concernés.
2. Déployer le nouveau code sans démarrer l’application.
3. Vérifier sans écrire :
   `php scripts/one-shot-scripts/migrate-asteroid-trajectories.php --dry-run --database-config=config/database.json`
4. Sauvegarder la base et le répertoire d’univers.
5. Migrer et contrôler le code de sortie :
   `php scripts/one-shot-scripts/migrate-asteroid-trajectories.php --database-config=config/database.json`
6. Relancer exactement la même commande : elle doit annoncer zéro fichier et
   zéro astéroïde modifiés. La création du schéma SQL est elle aussi idempotente.
7. Démarrer l’application puis redémarrer le scheduler et les workers.

En cas d’échec, laisser les workers arrêtés, corriger la cause indiquée et
relancer le même script. Ne jamais démarrer avec un mélange de formats secteur.

Utilisez `--universe-path=/chemin/vers/univers` si l’univers ne correspond pas à
celui de la configuration de l’application.
