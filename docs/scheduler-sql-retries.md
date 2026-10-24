# Reprise des conflits SQL du scheduler

Les tâches Manny `mining`, `crafting` et `assisting_atomic_printer` disposent d’une reprise différée des conflits SQL temporaires. Une fabrication ne reste donc plus définitivement en échec parce que sa transaction a rencontré un verrou concurrent.

## Conditions de reprise

La prise de l’événement, les écritures métier et son acquittement s’exécutent dans la même transaction d’admission. Un conflit annule cette transaction et libère l’admission avant de reporter l’événement. Pour une fabrication, cela comprend l’objet ou la nouvelle Manny, son rangement, la réservation et la tâche de l’ouvrière. Les ingrédients consommés au lancement de la fabrication ne sont pas consommés à nouveau.

Seuls les codes natifs MySQL/MariaDB `1205`, `1213`, `3572` et SQLite `5`, `6` déclenchent cette reprise. Le texte d’une exception ne sert jamais à déterminer si elle est temporaire. Les autres erreurs SQL et les règles métier conservent leur traitement habituel. Les autres familles d’événements ne deviennent pas automatiquement rejouables.

Le report vérifie que l’événement est toujours en attente avec la même version d’exécution, la même échéance et le même compteur de conflits. Il vérifie aussi la tâche, la sonde, son admission ouverte et l’identité `craftingRunId`. Un ancien worker ne peut donc pas reporter une fabrication remplacée, terminée, rappelée ou dont la destruction a fermé l’admission. La même protection s’applique à l’enregistrement d’une erreur permanente après rollback.

## Délais et diagnostic

Dans `config/app.json` :

| Famille | Délais | Seuil d’alerte |
| --- | --- | --- |
| Minage | `schedulerMiningRetryDelays` | `schedulerMiningConflictAlertSeconds` |
| Fabrication et imprimante atomique | `schedulerCraftingRetryDelays` | `schedulerCraftingConflictAlertSeconds` |

Les valeurs par défaut sont `[1, 5, 15, 60, 300]` secondes et `900` secondes. Chaque délai comporte une dispersion de ±20 %, avec un plafond absolu de cinq minutes. Après épuisement de la liste, la reprise continue au dernier délai. Une alerte est émise une fois par série de conflits au franchissement du seuil ; elle n’arrête pas les reprises.

Les compteurs `conflict_count`, `conflict_started_at` et `conflict_alerted_at` de `scheduled_events` survivent au rollback ; `attempts` ne compte pas les prises annulées. Une exécution réussie, y compris un report métier normal, remet la série de conflits à zéro. Les échéances métier ne sont pas prolongées par cette politique.

Les lignes JSON internes utilisent `metric=scheduler_mining` ou `metric=scheduler_crafting`, avec `outcome=sql_conflict`, `success` ou `permanent_failure`. Elles identifient l’événement, la Manny et, pour une fabrication, `crafting_run_id`. Un conflit précise le code SQL, la famille de verrou, les durées, le prochain délai et l’éventuelle alerte. Les messages SQL bruts et les paramètres liés ne sont pas journalisés dans ces diagnostics. Un échec du diagnostic ne remet pas en cause une livraison validée.

## Mise en service

Ce correctif réutilise les colonnes déjà créées pour la reprise du minage : aucune migration supplémentaire ni changement de version publique n’est nécessaire sur la base actuelle. Le processus scheduler doit être redémarré après déploiement pour charger le nouveau code.

Les événements déjà marqués `failed` ne sont pas réactivés au démarrage. Leur reprise demeure une opération administrative explicite, notamment via `scripts/one-shot-scripts/requeue-failed-manny-storage-transactions.php`, après examen de sa simulation et avec sauvegarde lors de l’application.

## Vérification

- `php tests/SectorTests.php` : conflits après écriture de l’objet, admission, prise de l’événement, acquittement et commit ; imprimante atomique et création de Manny ; conservation des ingrédients et réservations ; délais, alerte, erreurs permanentes, rappels et changement d’identité de fabrication.
- `php tests/StorageConcurrencyTests.php --mysql-config=config/database.json` : tables isolées et connexions concurrentes réelles ; délais d’attente `1205` et deadlocks `1213`, reprise et livraison unique pour minage et fabrication.
- `php tests/ApiTests.php` : non-régression du contrat et des comportements API.
