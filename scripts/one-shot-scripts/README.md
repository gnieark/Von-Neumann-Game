Scripts de migration à exécuter explicitement lors des déploiements qui le demandent.

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
