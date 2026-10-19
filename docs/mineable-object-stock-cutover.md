# Lot 7 — Bascule des stocks minables en production

**Bascule effectuée le 19 octobre 2026, après autorisation explicite de l’utilisateur.** La préparation initiale du 18 octobre confiait l’exécution à l’opérateur ; l’utilisateur a ensuite autorisé l’assistant à effectuer la maintenance, les snapshots et la migration. Le compte rendu effectif figure dans [le rapport](mineable-object-stock-migration-inventory.md#lot-7--exécution-en-production-19-octobre). La répétition complète, les deux reprises et la restauration du lot 6 y sont également consignées.

Pour cette intervention, les VM `lnmp2` et `mariadb2` ont été arrêtées proprement puis sauvegardées par snapshots internes hors ligne `neumann-stocks-before-20261019-lot7`. La VM des contrôleurs `neumanothers` a été suspendue pendant la maintenance, puis reprise. Des conditions systemd persistantes ont empêché le redémarrage de nginx, PHP-FPM, du scheduler et de cron après le redémarrage des VM : les masques `--runtime` des exemples ci-dessous ne suffiraient pas dans ce cas. Les gardes de la session ont été levées progressivement. Les snapshots les contiennent encore ; une restauration exige leur levée explicite après vérification du couple code/données. L’utilisateur a accepté l’indisponibilité des autres vhosts et la perte éventuelle de leurs écritures récentes en cas de restauration.

La révision livrée est `1200b367df8d1327ad223374040998a8ab2f3edb`, importée par bundle Git vérifié depuis la VM de développement, sans push GitHub. Git et PHP s’exécutent sous `www-data`. Les dépendances Composer sont conservées : `composer.json` et `composer.lock` sont identiques à la révision précédente, l’autoloader existant n’est pas autoritaire et le chargement des nouvelles classes a été vérifié. Les commandes ci-dessous restent un guide général ; les écarts et résultats de cette session sont consignés au rapport.

Paramètres confirmés : application `/var/www/neumann-probe.tinad.fr/`, propriétaire et compte Git `www-data`, scheduler `php-vhost-neumann-probe-scheduler`, PHP-FPM `php8.4-fpm`. Le compte `www-data` possède la clé GitHub. Exécuter les blocs séparément, en contrôlant les résultats avant le bloc suivant ; conserver les variables de cette session. Les commandes serveur suivantes sont à exécuter dans un shell Bash administrateur ; Git, Composer et PHP sont exécutés sous `www-data`. L’arrêt de nginx et de PHP-FPM porte sur ces services entiers.

## 1. Préparer la version et identifier tous les writers

Sur la VM de développement, commiter les changements du lot 7 que vous souhaitez livrer, puis pousser la branche. Ces commandes ne sont pas exécutées par l’assistant :

```bash
git push origin removestoragesfromjsons
```

Sur le serveur de production, avant la fenêtre de maintenance :

```bash
set -euo pipefail
APP=/var/www/neumann-probe.tinad.fr
SCHEDULER=php-vhost-neumann-probe-scheduler
FPM=php8.4-fpm
DB_CONFIG="$APP/config/database.json"
# Vérifier cette valeur dans config/app.json, sans modifier la configuration.
UNIVERSE="$APP/data/universe"
SESSION="/var/backups/neumann-stocks/$(date -u +%Y%m%dT%H%M%SZ)"
MANIFEST="$SESSION/migration"
install -d -m 0700 -o www-data -g www-data "$SESSION"

sudo -u www-data git -C "$APP" fetch origin removestoragesfromjsons
TARGET=$(sudo -u www-data git -C "$APP" rev-parse origin/removestoragesfromjsons)
OLD_REV=$(sudo -u www-data git -C "$APP" rev-parse HEAD)
sudo -u www-data git -C "$APP" cat-file -e "$TARGET:scripts/check-mineable-cutover.php"
sudo -u www-data git -C "$APP" cat-file -e "$TARGET:src/Operations/MineableCutoverMonitor.php"
sudo -u www-data git -C "$APP" status --short
sudo -u www-data git -C "$APP" diff --quiet
sudo -u www-data git -C "$APP" diff --cached --quiet
sudo -u www-data git -C "$APP" diff --stat "$OLD_REV" "$TARGET"
printf '%s\n' "$OLD_REV" > "$SESSION/old-revision.txt"
printf '%s\n' "$TARGET" > "$SESSION/target-revision.txt"

systemctl cat "$SCHEDULER" "$FPM" nginx
systemctl list-timers --all
systemctl list-units --all --type=service | grep -Ei 'neumann|others|scheduler|cron'
pgrep -af 'scheduler(-worker)?\.php|others_control|generate-stats\.php' || true
crontab -l || true
sudo -u www-data crontab -l || true
ls /etc/cron.d /etc/cron.hourly /etc/cron.daily
sudo -u www-data php -v
journalctl -u "$SCHEDULER" --since "-15 minutes" -o cat --no-pager > "$SESSION/scheduler-before.log"
df -h "$APP" "$SESSION"
```

Vérifier que `TARGET` contient tous les lots et les outils de ce document. Résoudre les modifications locales et fichiers de code non suivis **avant** la maintenance, sans `reset --hard` ni suppression globale. Conserver la configuration effective de production : univers, graine, connexion SQL, OAuth, Redis et paramètres métier. Vérifier les `ExecStart`, utilisateurs et répertoires de travail des services. Si un timer, cron, contrôleur Others ou outil externe écrit dans ce monde, relever sa commande d’arrêt et sa commande de remise en service dans le compte rendu de session. Les noms des contrôleurs Others ne sont pas connus de cette procédure.

Le répertoire `SESSION` doit rester privé, hors HTTP et hors univers. Prévoir l’espace pour le code/configuration, les données SQL et secteurs sauvegardées, les descripteurs préparés et une restauration. Sur la copie du lot 6 : sauvegarde canonique 1,30 Gio, application avec reprises environ 18 minutes, vérification 85 secondes, restauration 6 min 08 s, pic PHP d’application 632 Mio. Ajouter une marge adaptée au serveur ; ce ne sont pas des garanties de durée.

## 2. Arrêter et drainer

Suspendre d’abord les contrôleurs, timers et cron relevés ci-dessus, y compris leurs relances automatiques. Puis :

```bash
systemctl stop nginx
systemctl stop "$SCHEDULER"
systemctl stop "$FPM"
systemctl mask --runtime nginx "$SCHEDULER" "$FPM"
systemctl show nginx "$SCHEDULER" "$FPM" -p Id -p ActiveState -p SubState -p LoadState
systemctl is-enabled nginx "$SCHEDULER" "$FPM" || true
pgrep -af 'scheduler(-worker)?\.php|others_control|generate-stats\.php|php-fpm' || true
journalctl -u "$SCHEDULER" -u "$FPM" --since '-10 minutes' --no-pager
```

Exiger l’arrêt effectif des writers et vérifier que le masquage empêche leur redémarrage. Un masque temporaire qui n’a pas pris effet, un processus résiduel, un arrêt forcé ou une requête SQL encore engagée impose une vérification avant de continuer. Contrôler les connexions/transactions du compte applicatif avec les outils SQL d’administration habituels, sans publier les requêtes ou secrets dans le rapport. Ne pas tuer une transaction inconnue. Les commandes de contrôle peuvent retourner un code non nul pour un service masqué/inactif ou une crontab absente : interpréter leur sortie.

Conserver les autres sites/services éventuels dans le plan d’exploitation de cette fenêtre. Aucun script de migration ne prouve à lui seul l’absence de tous les anciens writers.

## 3. Sauvegarder le code et installer la révision figée

Les writers restent arrêtés et masqués :

```bash
sudo -u www-data tar -C "$APP" -czf "$SESSION/code-before.tar.gz" \
  src public scripts templates vendor config composer.json composer.lock
sha256sum "$SESSION/code-before.tar.gz" > "$SESSION/code-before.tar.gz.sha256"
tar -tzf "$SESSION/code-before.tar.gz" > "$SESSION/code-before-files.txt"

sudo -u www-data git -C "$APP" switch --detach "$TARGET"
# Chemin de Composer à adapter si ce n’est pas le binaire installé.
sudo -u www-data composer --working-dir="$APP" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
sudo -u www-data composer --working-dir="$APP" check-platform-reqs --no-dev
sudo -u www-data php -l "$APP/scripts/one-shot-scripts/migrate-mineable-object-stocks.php"
sudo -u www-data php -l "$APP/scripts/check-mineable-cutover.php"
sudo -u www-data git -C "$APP" rev-parse HEAD
```

Exiger que la révision soit exactement `TARGET`, que chaque commande réussisse et que les configurations effectives restent celles de production. Comparer en particulier les fichiers `config/` avec la sauvegarde ; rétablir les valeurs de production si nécessaire, sans réimporter une configuration de développement. Les nouvelles options de retry sont facultatives, avec valeurs par défaut documentées au lot 5. Ne pas démarrer le code installé avant conversion.

Le détachement de HEAD fixe la version de cette opération et ne réalise aucune fusion implicite. Les prochains déploiements devront sélectionner explicitement leur branche/révision. En cas d’échec à ce stade, revenir à `OLD_REV` et aux fichiers/configurations sauvegardés avant toute remise en service ; aucune donnée n’a encore été migrée.

## 4. Convertir et vérifier avant toute reprise

Depuis la racine effective de l’application :

```bash
cd "$APP"
DRY_RC=0
sudo -u www-data php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  > "$SESSION/dry-run.json" 2> "$SESSION/dry-run.log" || DRY_RC=$?
printf "Code de simulation : %s\n" "$DRY_RC"
```

Examiner le résultat. Une sortie non nulle exige une décision explicite après lecture du journal ; ne pas enchaîner automatiquement. Une référence ambiguë, une divergence non arbitrée ou une mauvaise paire SQL/univers bloque la suite. Si la simulation signale des **intentions anciennes en attente**, le drainage explicite de l’application les traitera après sa sauvegarde ; ne pas lancer le scheduler général pour les vider. Toute autre erreur doit être comprise avant application. Les résolutions éventuelles sont privées, justifiées et passées avec `--resolutions=PATH` selon [le guide du lot 6](mineable-object-stock-migration-runbook.md).

```bash
sudo -u www-data php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --apply --maintenance-confirmed \
  > "$SESSION/applied.json" 2> "$SESSION/apply.log"

sudo -u www-data php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --verify \
  > "$SESSION/verified.json" 2> "$SESSION/verify.log"

CHECK_RC=0
BEFORE_OPEN=$(date -u +%Y-%m-%dT%H:%M:%S+00:00)
sudo -u www-data php scripts/check-mineable-cutover.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --since="$BEFORE_OPEN" --before-open \
  > "$SESSION/before-open.json" 2> "$SESSION/before-open.log" || CHECK_RC=$?
CHECK_RC=${CHECK_RC:-0}
printf "Code du contrôle avant ouverture : %s\n" "$CHECK_RC"
test "$CHECK_RC" -lt 2
unset CHECK_RC
```

Avec `set -e`, un échec d’application ou de vérification arrête l’exécution ; garder les writers arrêtés et reprendre avec les chemins de cette session. Les deux premières commandes doivent réussir et indiquer respectivement `complete: true` et `verified: true`. La sauvegarde SQL/JSON est créée **avant** drainage et conversion dans `MANIFEST/backup`. Le dernier contrôle doit contenir `beforeOpenVerification.verified: true`. Son code 1 peut correspondre à un retard du scheduler accumulé pendant l’arrêt : consigner et comprendre chaque avertissement. Un code 2 ou une vérification absente interdit de poursuivre.

Adapter `--lease-seconds` et `--alert-seconds` aux valeurs effectives `schedulerLeaseSeconds` et `schedulerMiningConflictAlertSeconds` si elles diffèrent de 3600 et 900. La garde ne s’ouvre qu’après validation globale. Les hashes et stocks du manifeste ne sont valables qu’avant réouverture : **ne plus employer `--verify` ou `--before-open` après reprise des writers**.

## 5. Reprendre progressivement et surveiller

Enregistrer la frontière avant le tout premier writer. À partir de ce moment, même sans nginx, le scheduler peut modifier le monde et la restauration historique n’est plus un retour arrière sans perte.

```bash
OPENED_AT=$(date -u +%Y-%m-%dT%H:%M:%S+00:00)
printf '%s\n' "$OPENED_AT" > "$SESSION/opened-at.txt"
systemctl unmask --runtime "$SCHEDULER"
systemctl start "$SCHEDULER"
systemctl status "$SCHEDULER" --no-pager
journalctl -u "$SCHEDULER" --since "$OPENED_AT" -o cat --no-pager

CHECK_RC=0
sudo -u www-data php scripts/check-mineable-cutover.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --since="$OPENED_AT" \
  > "$SESSION/live-first.json" 2> "$SESSION/live-first.log" || CHECK_RC=$?
CHECK_RC=${CHECK_RC:-0}
printf "Code du contrôle en service : %s\n" "$CHECK_RC"
test "$CHECK_RC" -lt 2
unset CHECK_RC
```

Observer la progression des événements dus, des rappels préparés par la migration, des pertes de sondes et des intentions de secteurs. Refaire le contrôle après quelques minutes. Ne pas lancer en parallèle un scheduler ponctuel pour « accélérer » ce contrôle. Une erreur permanente, un nouveau `failed`, une incohérence de stock ou une série de conflits persistante impose le diagnostic avant de réactiver les autres producteurs.

Quand ce premier palier est validé :

```bash
systemctl unmask --runtime "$FPM" nginx
systemctl start "$FPM"
systemctl start nginx
curl --fail --silent --show-error https://neumann-probe.net/api/version
```

Vérifier la réponse API v148 puis, avec un compte de contrôle, les observations d’un astéroïde et d’une épave, les stocks/cargaisons, une petite mission de minage suivie jusqu’à livraison, et un rappel. Si disponible, vérifier une Manny oubliée et un conteneur détaché. Consigner les IDs publics, quantités avant/après et événements concernés dans le rapport privé ; ne pas publier de coordonnées absolues. Le débit doit correspondre à la livraison et les relectures ne doivent pas recréer de stock. Ne pas lancer les suites de tests du dépôt contre la production.

Réactiver enfin, un par un, les contrôleurs Others, timers et cron suspendus. Vérifier aussi le moissonnage planétaire, qui conserve son format et ses règles. La présence de missiles et trajectoires en vol ne doit pas être annulée au titre de la migration. Leurs transitions normales continuent avec le scheduler.

Pour une fenêtre de surveillance de quinze minutes, par exemple, fixer les deux bornes après qu’elle s’est écoulée :

```bash
LOG_END=$(date -u +%Y-%m-%dT%H:%M:%S+00:00)
LOG_START=$(date -u -d "$LOG_END -15 minutes" +%Y-%m-%dT%H:%M:%S+00:00)
journalctl -u "$SCHEDULER" --since "$LOG_START" --until "$LOG_END" -o cat --no-pager \
  > "$SESSION/mining-window.log"
METRICS_RC=0
php scripts/summarize-mining-metrics.php \
  --log-file="$SESSION/mining-window.log" --window-seconds=900 \
  > "$SESSION/mining-window.json" || METRICS_RC=$?
printf "Code du résumé de mesures : %s\n" "${METRICS_RC:-0}"
unset METRICS_RC
```

Si l’unité redirige stderr ailleurs, exporter ce journal effectif sur la même fenêtre. Si plusieurs workers produisent des logs, les regrouper sans duplication. La durée passée à `--window-seconds` doit correspondre exactement à l’export ; l’outil ne filtre pas lui-même les dates des lignes. Relever également les erreurs PHP/HTTP et la disponibilité de l’interface. Conserver les exports successifs sous des noms distincts. Le journal `scheduler-before.log` conserve une référence avant arrêt ; si l’ancien code ne produisait pas les mêmes métriques, une comparaison chiffrée avant/après ne peut pas en être déduite. Ne pas présenter l’absence de métriques comme une latence ou un taux d’erreur nul.

## 6. Interpréter les outils

`check-mineable-cutover.php` ouvre une connexion SQL explicitement en lecture seule et un instantané de lecture. Il vérifie l’identité du manifeste, la garde SQL et le hash du plan. En mode normal, il ne parcourt pas les fichiers et ne compare pas les stocks actuels aux montants initiaux : le minage et les transformations restent autorisés à les faire évoluer. Il n’exécute aucun événement, ne récupère aucun bail et ne répare aucune donnée. Les schémas incomplets sont refusés, sans fallback.

| Résultat | Interprétation |
| --- | --- |
| Code 0 / `ok` | Aucun signal parmi les contrôles effectués ; ce n’est pas une preuve d’activité ni de bon fonctionnement de tous les chemins du jeu |
| Code 1 / `warning` | Retard ancien, bail/admission expiré, conflit prolongé, publication en erreur/ancienne, perte de sonde non terminée ou échec historique ; examiner les compteurs et leur évolution |
| Code 2 / `critical` | Stock négatif/version invalide, identité manquante, événement actuellement `failed` dont la date d’échec est postérieure ou égale à `--since` |
| Code 2 sans rapport | Connexion, schéma, manifeste, date ou vérification impossible ; jamais interpréter comme un contrôle réussi |

Les admissions expirées peuvent encore être détenues par une transaction vivante : le compteur n’autorise pas leur suppression. Les gardes fermées et pertes en cours sont attendues lors d’une destruction. Les événements `failed` sans date sont signalés séparément, leur ancienneté étant indéterminée. Les compteurs SQL décrivent l’état présent, pas un historique immuable d’incidents.

`summarize-mining-metrics.php` agrège les lignes `scheduler_mining`, éventuellement préfixées par le logger. Il calcule les percentiles exacts par rang supérieur pour les durées totales, d’admission et de transaction, séparément par résultat. Il distingue succès avec fin d’exécution, reports métier réussis, conflits et erreurs permanentes ; compte les codes SQL, familles de verrou, reprises réussies et alertes. Le débit décrit les **exécutions terminées observées**, pas une mesure garantie de missions distinctes. Une fenêtre vide ne fabrique ni percentile ni débit observé.

Son code 1 signale des alertes, des lignes métriques invalides ou l’absence de mesures ; son code 2 signale une erreur permanente ou une lecture impossible. Il borne la lecture à un million de mesures et refuse les lignes excessivement longues. Aucun payload brut n’est reproduit dans le résumé. Il ne mesure pas séparément chaque attente de verrou, les écritures JSON ou les performances d’observation ; ces propriétés reposent sur les validations des lots précédents et, pour les performances réelles, sur une mesure complémentaire adaptée.

## 7. En cas d’incident

**Avant le premier redémarrage d’un writer**, maintenir la maintenance. Pour reprendre une conversion interrompue, relancer `--apply` avec le même manifeste et les mêmes chemins. Pour abandonner après création de la sauvegarde complète :

```bash
cd "$APP"
sudo -u www-data php scripts/one-shot-scripts/migrate-mineable-object-stocks.php \
  --database-config="$DB_CONFIG" --universe-path="$UNIVERSE" \
  --manifest-dir="$MANIFEST" --restore --maintenance-confirmed \
  > "$SESSION/restored.json" 2> "$SESSION/restore.log"
sha256sum --check "$SESSION/code-before.tar.gz.sha256"
sudo -u www-data git -C "$APP" switch --detach "$OLD_REV"
sudo -u www-data mv "$APP/vendor" "$SESSION/vendor-after-cutover"
sudo -u www-data tar -C "$APP" -xzf "$SESSION/code-before.tar.gz"
```

Vérifier `restored: true`, l’ancien code, les configurations, droits et propriétaires avant remise en service. Une sauvegarde SQL/JSON incomplète (`backup.json` absent) n’est pas restaurable par cette commande ; l’outil n’a normalement pas encore commencé la conversion, et l’état doit être vérifié avant de redémarrer. Le déplacement de `vendor` conserve les dépendances installées pour diagnostic et permet de restaurer exactement les anciennes, sans fichiers supplémentaires. Utiliser un autre nom de destination si cette opération a déjà été effectuée ; ne pas écraser une sauvegarde existante. Ne pas restaurer uniquement les fichiers de secteurs.

**Après le premier writer relancé**, arrêter à nouveau les writers en cas d’incident, conserver les preuves et diagnostiquer. Ne pas appliquer automatiquement la sauvegarde historique : elle effacerait les écritures nouvelles, y compris celles du scheduler seul. Préparer une correction en avant ou une migration inverse contrôlée.

## 8. Clôturer la bascule

Compléter le rapport privé de session avec révisions avant/après, chemins du manifeste, heures d’arrêt/conversion/réouverture, liste des writers suspendus puis relancés, vérifications, minages de contrôle, observations des erreurs et métriques sur les premières fenêtres puis à J+1. Consigner les avertissements acceptés et leur justification. Le lot 7 opérationnel sera terminé lorsque ces contrôles réels auront réussi ; la préparation présente ne déclare pas la production migrée.
