# Others controls

Collection de scripts Python contenant les réflexes des Others.

Copiez `config.example.json` vers `config.json`, puis renseignez l'URL de base et
le token API. Pour vérifier la connexion et les accès en lecture :

```console
python3 scripts/others_control/test_connection.py
```

## Construction ponctuelle d’un dépôt

Le script suivant choisit le premier auxiliaire embarqué libre du vaisseau mère
et lance la construction d’un dépôt dans son secteur courant :

```console
python3 scripts/others_control/build_germination_depot.py \
  --token TOKEN_OTHERS \
  --mothership-id ship_0123456789abcdefabcd
```

L’API locale `http://127.0.0.1:8000` est utilisée par défaut. Pour viser une
autre instance :

```console
python3 scripts/others_control/build_germination_depot.py \
  --base-url https://jeu.example \
  --token TOKEN_OTHERS \
  --mothership-id ship_0123456789abcdefabcd
```

La commande vérifie que l’identifiant désigne un vaisseau mère, parcourt tous
ses auxiliaires par pagination et retient le premier identifiant disponible.
Le serveur choisit le secteur courant, réserve les 2 ECE de métaux nécessaires
et renvoie l’action de construction prévue pour trente minutes.

## Défense étoile — attente

### Journaux spectateur

Le contrôleur écrit automatiquement un journal de bord dans
`scripts/others_control/logs/{mothership-id}.log`, en complément de la sortie
habituelle accessible via `journalctl`. Avec `--fleet-id`, le nom du fichier
utilise également l'identifiant du vaisseau mère, résolu lors de la lecture de
la flotte. Aucun changement des commandes systemd n'est nécessaire si leur
utilisateur peut écrire dans `scripts/others_control/logs`.

Le dossier est créé automatiquement et ignoré par Git, y compris les archives
et l'état de suivi. Son chemin dépend de l'emplacement du script, pas du
répertoire de lancement. Chaque ligne contient une date avec fuseau et une
catégorie ; le fichier est en UTF-8 et ouvert en ajout. Il tourne à **10 Mio**,
avec **cinq archives** (`.log.1` à `.log.5`). Exemple de consultation :

```console
tail -F scripts/others_control/logs/ship_0123456789abcdefabcd.log
```

Événements retenus :

- Démarrage/reprise du contrôleur, effectif et vaisseau mère.
- Moissons lancées : planète, vaisseau, nombre d'auxiliaires et échéance prévue ;
  blocages et reprise. Une ligne est produite par moisson lancée.
- Fabrications de missiles, auxiliaires et vaisseaux : lancement, puis fin ou
  échec lorsque les lectures habituelles des crafts le confirment.
- Alertes centrales, missiles hostiles détectés, tirs et engagements laser,
  rappels de guerre et retraites tactiques.
- Transferts de missiles, carburant et ressources, réparations engagées et
  restauration d'intégrité constatée.
- Affectations, retours et déchargements de navettes, construction de dépôts,
  relèves et déploiements de sentinelles et gardiens.
- Départs programmés, départs et arrivées constatés, recherche d'un nouveau
  système et étapes du déménagement. Les secteurs sont toujours **relatifs**.

Les attentes sélectionnées ne sont pas répétées tant que leur état reste
identique. Les scans ordinaires, requêtes HTTP, délais entre contrôles et erreurs
techniques restent dans la sortie de diagnostic. Le journal n'annonce jamais
une destruction ou une réussite à partir d'une simple disparition du scan ou
de la liste des actions actives. Les résultats disponibles dans les lectures
déjà effectuées sont repris sans requête supplémentaire dédiée ; cela ne
constitue pas un historique exhaustif des fins de moisson, réparations ou impacts.

#### Collecte des alertes

Au démarrage après identification de la flotte, puis toutes les **cinq minutes**,
le contrôleur appelle `GET /api/others/alerts?status=unread`. Il retient uniquement
les alertes des `shipId` connus de sa flotte, les écrit de la plus ancienne à la
plus récente avec leur date d'origine, puis appelle
`POST /api/others/alerts/mark-read` par lots de **500** identifiants maximum.
Il faut donc une requête de lecture et une requête par lot non vide ; toutes
utilisent l'espacement et la gestion de rate-limit habituels. Une API ralentie
peut retarder la collecte. Les messages d'alerte sont conservés tels que fournis
par le serveur, y compris leur langue (actuellement l'anglais).

L'historique des vaisseaux et les identifiants d'alertes écrites en attente
d'acquittement sont sauvegardés dans `logs/.state/{mothership-id}.json`.
Conservez ce fichier lors des redémarrages : il permet de rattacher une alerte
à un vaisseau désormais absent, et de retenter un acquittement sans répéter le
message. Les anciens vaisseaux jamais observés ne peuvent pas être attribués :
leurs alertes restent non lues. Plusieurs contrôleurs du même compte peuvent
ainsi suivre des flottes distinctes ; utilisez **un seul contrôleur par flotte**
et **un serveur de jeu par répertoire de logs**.

Une erreur d'écriture ou d'acquittement est signalée dans `journalctl` sans
interrompre le contrôle de la flotte. Les alertes ne sont acquittées qu'après
écriture durable du journal et de leur suivi. Une coupure précisément entre ces
deux écritures peut laisser un doublon exceptionnel, jamais justifier
l'acquittement d'une alerte non sauvegardée. Un état corrompu n'est pas écrasé.

### Dépôts et navettes logistiques

Lorsque la cale du vaisseau mère dispose de **moins de 40 ECE libres**, le
contrôleur organise son déchargement. Les arrivées réservées (`reservedEce > 0`)
doivent d'abord se terminer. La cible est **50 % de remplissage**, dans la limite
des excédents exportables : chaque ressource conserve une réserve pour
**10 auxiliaires et 10 missiles**, ainsi qu'un budget consommable pour les
**trois prochains vaisseaux standards**, indépendamment des constructions déjà
payées et en cours. Ces quantités sont calculées à partir des recettes API.
Les réservations techniques sont également déduites des ressources disponibles.

- Sans dépôt connu ni dépôt local identifiable, un auxiliaire libre construit un dépôt.
- En présence d'un dépôt local, un auxiliaire y dépose les excédents, répartis
  proportionnellement jusqu'à la cible. Une ressource rare reste à bord même si
  une autre encombre la cale. Le deutérium brut est inclus ; les objets et le
  carburant du réservoir restent à bord. Les ressources protégées peuvent
  maintenir l'occupation au-dessus de 50 %.
- Sinon, un vaisseau standard intact, libre et présent auprès du vaisseau mère est
  affecté au dépôt connu le plus proche. Il doit posséder un auxiliaire embarqué libre.
  Le vaisseau mère le ravitaille pour l'aller-retour si nécessaire, puis le charge
  via son auxiliaire, ressource par ressource, dans la limite de la capacité libre
  de la navette. La composition de la cargaison est proportionnelle aux excédents.
- Si aucun transporteur local n'est admissible, le contrôleur rappelle une
  sentinelle intacte et disponible d'un secteur voisin, même en présence d'une
  menace à son poste. Les sentinelles voisines ont la même durée de retour et
  sont départagées par identifiant. Elle doit avoir ses auxiliaires libres et
  embarqués, de la place en soute et le carburant pour rentrer, puis pouvoir
  effectuer l'aller-retour au dépôt après ravitaillement. Elle est réservée
  dès le rappel, puis réintègre la formation après la mission. Les gardiens
  affectés aux dépôts ne sont pas réquisitionnés.
- Après confirmation de tous les chargements, la navette rejoint le dépôt par
  étapes de dix secteurs au maximum, décharge ses ressources via son propre auxiliaire,
  attend sa fin de tâche, puis revient auprès du vaisseau mère. D'autres navettes
  peuvent partir sans attendre ce retour pour poursuivre le déchargement vers
  50 %. Le budget protégé et la cible sont réévalués à chaque chargement.

Les navettes restent exclues des déploiements, rappels et tâches de défense jusqu'au
retour, y compris pendant les contrôles rapides de défense centrale. L'alerte centrale
garde la priorité sur la progression logistique. La production et la moisson du
vaisseau mère attendent pendant une construction de dépôt, un dépôt local ou le
chargement d'une navette ; elles peuvent reprendre pendant le rappel et le voyage.
Sans excédent exportable ou transporteur admissible, un message explique le
blocage du déchargement et la production reste autorisée à libérer de la place.

Le journal est enregistré sous `var/others-logistics`, séparément par serveur et
flotte. Il conserve les étapes, les identifiants d'action et les requêtes avec leurs
clés d'idempotence avant envoi, pour reprendre après un redémarrage ou une réponse
HTTP perdue. Conservez ce répertoire et utilisez un seul contrôleur par flotte.
`--logistics-state-dir CHEMIN` permet de changer son emplacement. Les coordonnées
du journal sont relatives et il ne contient pas le token API.

Le coût de carburant par étape est de 2 points par défaut. Ajustez
`--logistics-fuel-per-hop` si le serveur utilise un autre coût. Le contrôleur attend
si aucun transporteur admissible, auxiliaire ou carburant suffisant n'est disponible.

### Gardiens des dépôts connus

Chaque secteur renvoyé par `GET /api/others/fleets/{fleetId}/known-depots` reçoit
**quatre gardiens standards** de cette flotte. Plusieurs dépôts dans un même secteur
ne multiplient pas cet effectif. Le contrôleur privilégie les vaisseaux déjà sur
place, puis les vaisseaux disponibles auprès du vaisseau mère, avec priorité aux
mieux armés. Les navettes en mission et l'éclaireur de déménagement sont exclus.
Si l'effectif manque, les places restantes sont complétées aux cycles suivants.

Les affectations et les trajets sont persistants : un gardien déjà en route réserve
sa place, y compris après redémarrage. Les trajets éloignés se font par étapes d'au
plus dix secteurs ; un départ exige le carburant de l'aller-retour. Un secteur
voisin protégé par ces gardiens ne reçoit pas de cinquième vaisseau comme sentinelle.

Chaque gardien utilise les mêmes observations, priorités de tir, engagements laser
et retours tactiques que les sentinelles, y compris lors des contrôles d'activité
rapides. Les gardiens continuent leur surveillance pendant une alerte centrale.
Leur stock de missiles est inclus dans l'objectif de production de la flotte ;
ceux présents auprès du vaisseau mère participent au réarmement, au ravitaillement
et aux réparations.

Un vaisseau disponible intact possédant **strictement plus de missiles** peut relever
un gardien non engagé. Le remplaçant part d'abord ; l'ancien garde le poste jusqu'à
son arrivée effective, puis revient auprès du vaisseau mère. Une relève déjà en
route empêche d'en envoyer une seconde pour le même gardien. Un gardien perdu ou
en retour tactique libère sa place pour un renfort.

Les gardiens **restent aux dépôts lors des déménagements**. Leurs affectations et
leurs relèves ne bloquent pas le déplacement de la flotte mobile. Un retour tactique
vise le secteur actuel du vaisseau mère, ou sa destination lorsqu'il est en transit.
Les affectations sont conservées dans `*.guards.json`, dans le répertoire
`--logistics-state-dir`, séparément par serveur et flotte et en coordonnées relatives.

### Déménagement après épuisement du secteur

Lorsqu'un scan détaillé du secteur du vaisseau mère ne contient plus aucune
planète `harvestable: true`, le contrôleur cherche un nouveau système. Une
information de scan insuffisante ne déclenche pas de déménagement.

- Il examine d'abord les douze voisins directs occupés par un vaisseau standard
  de la flotte, dans l'ordre de la formation, et choisit le premier qui contient
  une planète moissonnable.
- À défaut, il affecte un vaisseau disponible du secteur central, ou une sentinelle
  si aucun vaisseau local n'est disponible. Les navettes logistiques restent réservées.
  Cet éclaireur visite successivement les 50 secteurs FCC à distance exactement 2
  selon la métrique du jeu, dans l'ordre des coordonnées relatives `(x, y, z)`.
  Chaque secteur est inspecté à l'arrivée ; un scan encore imprécis est attendu.
- Sous 4 points de deutérium, la recherche est mise en pause et l'éclaireur revient
  auprès du vaisseau mère. Le ravitaillement existant complète son réservoir ; la
  recherche reprend après la fin effective du transfert et le plein, au prochain
  secteur non inspecté. Le vaisseau mère conserve le carburant d'un déplacement.
  Si le coût configuré dépasse 2 points, le seuil protège aussi l'aller-retour.
- Dès qu'une destination est trouvée, les nouvelles productions et missions
  logistiques restent suspendues. Les navettes déjà parties finissent leur mission
  et reviennent ; les auxiliaires occupés ou déployés sont attendus. Les vaisseaux
  qui manquent de carburant pour rejoindre la destination reviennent se ravitailler.
  Chaque vaisseau de la flotte mobile reçoit ensuite son déplacement, par étapes si nécessaire.
  La formation se redéploie autour du nouveau centre au cycle suivant l'arrivée
  de **toute la flotte mobile**, y compris les vaisseaux dont le départ a été retardé.
  Les gardiens restent affectés à leurs dépôts et sont exclus de cette attente.

Pendant la recherche, la défense reste active avec les autres vaisseaux ; l'éclaireur
est exclu des ordres de formation et de défense centrale. La production et les
nouvelles missions logistiques attendent déjà pour préparer le départ. Si toute la
couronne est vide, l'éclaireur revient et la recherche attend un voisin occupé
moissonnable, sans refaire les mêmes visites en boucle.

La progression, l'éclaireur, son retour pour ravitaillement et la destination sont
conservés dans un fichier `*.relocation.json` du répertoire `--logistics-state-dir`,
séparément par serveur et flotte. Conservez ce journal pour reprendre la recherche
ou le déplacement après redémarrage. Il utilise exclusivement des coordonnées relatives.

### Exécution et défense

Le contrôleur maintient le vaisseau mère au centre et jusqu'à une sentinelle dans
chacun de ses douze secteurs voisins. Il tient compte des vaisseaux déjà sur
place et de ceux qui sont en mouvement, rappelle les vaisseaux hors formation
et exclut uniquement les trous noirs confirmés par un scan détaillé. Un scan
incertain n'interdit donc pas un déploiement.

Lancez le mode continu avec l'identifiant public du vaisseau mère :

```console
python3 scripts/others_control/defense_etoile_attente.py --mothership-id ship_0123456789abcdefabcd
```

Vous pouvez aussi désigner directement la flotte ; son vaisseau mère est alors
identifié automatiquement :

```console
python3 scripts/others_control/defense_etoile_attente.py --fleet-id fleet_0123456789abcdefabcd
```

Pour vérifier une seule réconciliation sans maintenir le processus en attente :

```console
python3 scripts/others_control/defense_etoile_attente.py --once --fleet-id fleet_0123456789abcdefabcd
```

Le contrôleur vérifie toutes les vingt secondes les activités attribuables aux
sondes dans le secteur du vaisseau mère et depuis chaque sentinelle en poste.
La réconciliation générale de la flotte
reste espacée d'au plus cinq minutes et se réveille plus tôt lorsque `arrivalAt`
annonce une arrivée. Les rappels dépassant la portée
d'un mouvement sont automatiquement découpés en étapes de dix secteurs. Un
vaisseau seul déjà immobilisé dans un secteur contenant un trou noir n'est pas
encore rappelé dans cette version.

Au lancement, le contrôleur affiche un état de chaque vaisseau de la flotte :
position relative, état courant, nombre total et déployé d'auxiliaires, nombre
de missiles en inventaire et éventuel mouvement avec sa destination relative et
son heure d'arrivée prévue. Une ligne d'inventaire détaille également
l'occupation et les réservations de la soute, les quantités de ressources et les
objets regroupés par type.

Une sonde, une Manny ou un missile visant le vaisseau mère détecté dans son secteur
déclenche la défense centrale. Les autres vaisseaux de la flotte présents dans ce
secteur tirent en priorité un missile par missile entrant pour tenter de
l'intercepter, y compris les gardiens et navettes présents. Le vaisseau mère ne
fournit pas ces intercepteurs. Les missiles visant d'autres vaisseaux sont ignorés
par cette défense centrale. Une tentative acceptée ou un intercepteur Others déjà
visible empêche tout nouveau tir contre le même missile pendant sa présence,
même si l'interception échoue ; faute de munition ou après un refus de tir,
l'interception reste à tenter.
Les gardiens locaux délèguent leurs réactions aux missiles à cette défense
commune afin de respecter la limite d'un intercepteur par cible.
Les sentinelles voisines disponibles sont rappelées et le
redéploiement normal est suspendu pendant l'alerte. La flotte maintient quatre
missiles en vol vers chaque sonde présente et remplace ceux qui disparaissent du
scan. Chaque Manny détectée reçoit un verrouillage laser provenant d'un vaisseau
local distinct disposant de plus de 12 points de deutérium. Les ordres acceptés
mais pas encore visibles sont suivis temporairement pour éviter les tirs en
double pendant le traitement de l'ordonnanceur.

Chaque sentinelle compare aussi ses observations locales d'un cycle au suivant
et applique les procédures d'engagement suivantes :

- une Manny déployée nouvelle ou dont l'état spatial change reçoit le premier
  missile disponible, puis sa sonde porteuse le second ; à défaut de missile et
  avec plus de 12 unités de deutérium, la sentinelle maintient un laser pendant
  dix minutes. Elle retourne ensuite auprès du vaisseau mère ;
- un missile lancé par une sonde est intercepté en priorité, puis la sonde est
  visée si un second missile reste disponible, avant le retour ;
- une Manny éjectée reçoit un missile sans provoquer de retour ;
- l'apparition, la modification ou la disparition d'un objet à la dérive ou
  d'un conteneur détaché déclenche un missile vers une sonde locale puis le
  retour ;
- le démarrage ou la modification des paramètres d'une trajectoire d'astéroïde
  motorisé déclenche un missile vers l'astéroïde puis un autre vers une sonde,
  sans repli de la sentinelle ;
- un changement de marqueurs de navigation déclenche un missile vers une sonde
  locale puis le retour.

Une seule munition respecte toujours l'ordre de priorité indiqué. Les missiles
déjà lancés par les Others sont ignorés et les alertes d'impact ne provoquent
aucune action supplémentaire.

À chaque réconciliation générale, les réparations passent avant la distribution
des missiles, le ravitaillement et la production, y compris pendant une alerte
de défense centrale. Chaque vaisseau endommagé disposant des métaux nécessaires
affecte un seul auxiliaire libre embarqué à la réparation de toute son intégrité
manquante, en appelant `POST /api/others/ships/{shipId}/auxiliaries/{auxiliaryId}/repair`.
Cela vaut aussi pour une sentinelle qui possède déjà son propre stock de métaux.
Les réparations `auxiliary_repair` en cours sont relues depuis les auxiliaires
à chaque cycle : un redémarrage ne lance pas une seconde réparation. Leur
échéance participe au réveil du contrôleur.

Le vaisseau mère fournit aux vaisseaux endommagés présents dans son secteur,
sans mouvement engagé, le complément exact de métaux encore nécessaire.
Chaque livraison utilise un auxiliaire libre du vaisseau mère et un transfert
d'inventaire de ressource `metals`. Les métaux réservés sont exclus des stocks
disponibles, et la capacité libre du destinataire est vérifiée. La réparation
du vaisseau mère est prioritaire sur ces livraisons. Si le stock ou les
auxiliaires manquent, l'opération est reportée ; aucune livraison partielle
n'est lancée. Tout transfert d'inventaire encore actif sur les auxiliaires du
vaisseau mère suspend les nouvelles livraisons de métaux, y compris après
redémarrage. Après réception, le destinataire lance sa réparation au cycle suivant.

Le calcul des besoins utilise **0,01 ECE de métaux par point d'intégrité**.
Si le serveur utilise un autre coût Manny, indiquez la même valeur avec
`--repair-metals-per-integrity-point` ; la durée reste déterminée par le serveur.
Les vaisseaux endommagés présents auprès du vaisseau mère restent au centre
jusqu'à leur réparation complète avant de pouvoir repartir en sentinelle.

En parallèle de cette formation, le vaisseau mère entretient sa logistique :

- les crafts abordables sont lancés avant la moisson, avec priorité aux
  auxiliaires jusqu'à un total projeté de 30, puis aux missiles jusqu'à un
  stock global de 60 dans la flotte ;
- les crafts déjà actifs comptent dans ces objectifs afin d'éviter une
  surproduction ;
- le vaisseau mère conserve toujours 10 missiles disponibles et confie son
  surplus, par vagues, aux vaisseaux présents dans son secteur. Les moins armés
  sont servis en premier afin de réduire les écarts, sans jamais compléter un
  vaisseau au-delà de 3 missiles ;
- une vague mobilise au plus un auxiliaire et un missile par destinataire. Le
  contrôleur se réveille à la fin des transferts avant de poursuivre la
  répartition ; pendant ce délai, la fabrication d'auxiliaires et la moisson
  continuent, mais les nouveaux crafts de missiles attendent que le stock
  global redevienne observable ;
- jusqu'à vingt auxiliaires embarqués encore disponibles moissonnent une planète
  locale dont le scan Others indique `harvestable: true` ; avec un seul
  auxiliaire, celui-ci crafte dès que la recette d'un auxiliaire est abordable,
  sinon il moissonne ;
- les actions de moisson canoniques sont relancées au fil de leur achèvement et
  regroupées en fenêtres d'une heure. Une action encore active à la fin d'une
  fenêtre n'est pas annulée, notamment pour ne pas perdre la progression sur
  une planète habitée ;
- une fois 30 auxiliaires et 60 missiles atteints, la moisson continue tant
  qu'une planète locale est moissonnable, même si la réserve est complète ou
  si trois constructions de vaisseaux sont déjà actives. La réserve de
  reconstruction représente 250 ECE de métaux, 25 de glace, 60 de composés
  carbonés et 10,5 de deutérium.

Lorsque ces objectifs logistiques sont atteints, les ressources excédant la
réserve de reconstruction peuvent financer des vaisseaux standards. Le
contrôleur lance jusqu'à **trois constructions actives simultanément**, avec
un auxiliaire embarqué libre par construction. Il lit les coûts dans la
recette API `standard_ship` : actuellement 6 000 ECE de métaux, 1 000 de glace,
2 000 de composés carbonés et 100 de deutérium de soute, pour sept jours de
construction. La réserve reste disponible après chaque lancement. Le budget
protégé des dépôts est consommable pour ces constructions : un seul vaisseau
finançable suffit pour lancer un chantier, sans attendre le financement des trois.

Les crafts `standard_ship` en état `queued` ou `running` sont recomptés depuis
l'API à chaque cycle, y compris après redémarrage. Une construction achevée
ou échouée libère une place pour le cycle suivant, sous les mêmes conditions
de ressources et de disponibilité. Les autres fonctions continuent et la
production d'auxiliaires/missiles reste prioritaire. La moisson continue avec
les auxiliaires restants après les constructions, jusqu'à épuisement de toutes
les planètes moissonnables du secteur, puis le déménagement prend le relais.

La capacité libre de la soute et les réservations en cours limitent toujours la
taille de l'essaim. Les planètes non habitées sont choisies avant les planètes
habitées lorsque plusieurs cibles sont disponibles.

Le vaisseau mère ravitaille aussi les autres vaisseaux de sa flotte présents
dans son secteur et sans mouvement engagé. Après la distribution des missiles
et avant les crafts/moissons, il affecte un auxiliaire embarqué disponible par
destinataire, dans l'ordre des identifiants publics. Chaque transfert complète
le réservoir cible, dans la limite du carburant encore disponible sur le
vaisseau mère : le dernier plein peut donc être partiel. Aucun minimum de
carburant n'est conservé sur le vaisseau mère ; seule sa réserve de propulsion
est utilisée, pas le deutérium de sa soute.

À chaque réconciliation générale, le contrôleur recharge les vaisseaux et les
`activeActions` de la flotte. Tant qu'une action `deuterium_transfer` est
`queued` ou `running`, quel que soit son vaisseau source ou son secteur, aucun
nouveau ravitaillement n'est lancé. Cette règle s'applique dès le premier cycle
après un redémarrage. Une échéance `endsAt` dépassée ne suffit pas : le script
attend la fin effective de toutes les actions, puis recalcule les besoins à
partir du nouvel état API. Les transferts durent cinq minutes, sous réserve du
traitement par l'ordonnanceur. Leurs échéances participent au réveil du cycle.
Pendant cette attente, la défense, la formation, la distribution de missiles,
les crafts et la moisson poursuivent leurs propres cycles avec les auxiliaires
disponibles.

Une sentinelle voisine sans missile **ou endommagée** est relevée lorsqu'un
vaisseau **intact**, armé et disponible se trouve auprès du vaisseau mère.
Le remplaçant part en premier ; la
sentinelle ne reçoit son ordre de retour qu'après acceptation de ce
déplacement, afin de ne pas dégarnir le secteur sur un premier échec. Une
sentinelle engagée tactiquement n'est pas relevée. Comme les transferts
d'inventaire exigent la présence des deux vaisseaux dans le même secteur, les
sentinelles voisines sont réarmées et approvisionnées en métaux par cette rotation.
Si le retour est temporairement impossible, le remplaçant intact armé conserve
le poste à son arrivée et le vaisseau endommagé est rappelé. Un remplaçant déjà
en route empêche un second départ de relève vers le même secteur.

Les observations passent par `GET /api/others/sector`, avec le vaisseau mère
comme désignateur de flotte. La précision du scan et l'historique de visite
restent propres à cette flotte. Les Mannys déployées sont suivies par la route
`autonomous-units` de la sentinelle et les stocks par son inventaire.
Les deux routes d'observation tactique du vaisseau mère et des sentinelles en
poste sont interrogées toutes les vingt secondes ;
les lectures d'inventaire et les commandes ne sont ajoutées qu'en cas d'engagement.

Les appels HTTP sont espacés d’au moins une seconde, y compris pendant les
cycles et la pagination. Ce délai se règle avec `--request-interval-seconds`
(par exemple `2` si plusieurs scripts partagent le token). Après un HTTP 429,
le contrôleur attend le `Retry-After` indiqué par le serveur, puis rejoue
uniquement la requête refusée, avec le même corps et la même clé d’idempotence.
Le cycle reprend à cet endroit, sans refaire les appels déjà réussis (également
avec `--once`). Sans délai serveur exploitable, l’attente augmente de 5 à 300
secondes. Ctrl+C permet d’arrêter l’attente. Ces pauses peuvent allonger les
cycles de surveillance.
La régulation est locale à chaque instance du script.

### Architecture et tests

`defense_etoile_attente.py` est uniquement le point d'entrée canonique. Le
paquet `defense_etoile` sépare la CLI et le transport HTTP de la logique
d'armement, de ravitaillement, de formation, de logistique, d'observation, de détection des
événements, d'engagement et de connaissance des dangers. Les modules tactiques
dépendent du protocole `OthersApi`, ce qui permet de les tester sans serveur ni
base de données.

La suite Python se lance depuis la racine du projet :

```console
python3 -B -m unittest discover -s scripts/others_control/tests -v
```
