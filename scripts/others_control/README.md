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

Une sonde ou une Manny détectée dans le secteur du vaisseau mère déclenche la
défense centrale. Les sentinelles voisines disponibles sont rappelées et le
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

En parallèle de cette formation, le vaisseau mère entretient sa logistique :

- les crafts abordables sont lancés avant la moisson, avec priorité aux
  auxiliaires jusqu'à un total projeté de 20, puis aux missiles jusqu'à un
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
- jusqu'à dix auxiliaires embarqués encore disponibles moissonnent une planète
  locale dont le scan Others indique `harvestable: true` ; avec un seul
  auxiliaire, celui-ci crafte dès que la recette d'un auxiliaire est abordable,
  sinon il moissonne ;
- les actions de moisson canoniques sont relancées au fil de leur achèvement et
  regroupées en fenêtres d'une heure. Une action encore active à la fin d'une
  fenêtre n'est pas annulée, notamment pour ne pas perdre la progression sur
  une planète habitée ;
- une fois 20 auxiliaires et 60 missiles atteints, la moisson continue jusqu'à
  conserver les matières premières de 10 auxiliaires et 10 missiles, soit 250
  ECE de métaux, 25 de glace, 60 de composés carbonés et 10,5 de deutérium.

Lorsque ces objectifs logistiques sont atteints, les ressources excédant la
réserve de reconstruction peuvent financer des vaisseaux standards. Le
contrôleur lance jusqu'à **trois constructions actives simultanément**, avec
un auxiliaire embarqué libre par construction. Il lit les coûts dans la
recette API `standard_ship` : actuellement 6 000 ECE de métaux, 1 000 de glace,
2 000 de composés carbonés et 100 de deutérium de soute, pour sept jours de
construction. La réserve reste disponible après chaque lancement.

Les crafts `standard_ship` en état `queued` ou `running` sont recomptés depuis
l'API à chaque cycle, y compris après redémarrage. Une construction achevée
ou échouée libère une place pour le cycle suivant, sous les mêmes conditions
de ressources et de disponibilité. Les autres fonctions continuent et la
production d'auxiliaires/missiles reste prioritaire. La moisson conserve ses
objectifs existants : elle n'est pas prolongée uniquement pour financer des
vaisseaux supplémentaires.

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

Une sentinelle voisine sans missile est relevée lorsqu'un vaisseau armé et
disponible se trouve auprès du vaisseau mère. Le remplaçant part en premier ; la
sentinelle vide ne reçoit son ordre de retour qu'après acceptation de ce
déplacement, afin de ne pas dégarnir le secteur sur un premier échec. Une
sentinelle engagée tactiquement n'est pas relevée. Comme les transferts
d'inventaire exigent la présence des deux vaisseaux dans le même secteur, les
sentinelles voisines sont réarmées exclusivement par cette rotation.

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
