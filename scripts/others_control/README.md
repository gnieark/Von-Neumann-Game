# Others controls

Collection de scripts Python contenant les réflexes des Others.

Copiez `config.example.json` vers `config.json`, puis renseignez l'URL de base et
le token API. Pour vérifier la connexion et les accès en lecture :

```console
python3 scripts/others_control/test_connection.py
```

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

Le contrôleur attend au plus cinq minutes entre deux contrôles et se réveille
plus tôt lorsque `arrivalAt` annonce une arrivée. Les rappels dépassant la portée
d'un mouvement sont automatiquement découpés en étapes de dix secteurs. Un
vaisseau seul déjà immobilisé dans un secteur contenant un trou noir n'est pas
encore rappelé dans cette version.

Au lancement, le contrôleur affiche un état de chaque vaisseau de la flotte :
position relative, état courant, nombre total et déployé d'auxiliaires, nombre
de missiles en inventaire et éventuel mouvement avec sa destination relative et
son heure d'arrivée prévue. Une ligne d'inventaire détaille également
l'occupation et les réservations de la soute, les quantités de ressources et les
objets regroupés par type.

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

La capacité libre de la soute et les réservations en cours limitent toujours la
taille de l'essaim. Les planètes non habitées sont choisies avant les planètes
habitées lorsque plusieurs cibles sont disponibles.

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

### Architecture et tests

`defense_etoile_attente.py` est uniquement le point d'entrée canonique. Le
paquet `defense_etoile` sépare la CLI et le transport HTTP de la logique
d'armement, de formation, de logistique, d'observation, de détection des
événements, d'engagement et de connaissance des dangers. Les modules tactiques
dépendent du protocole `OthersApi`, ce qui permet de les tester sans serveur ni
base de données.

La suite Python se lance depuis la racine du projet :

```console
python3 -B -m unittest discover -s scripts/others_control/tests -v
```
