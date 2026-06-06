# Von Neumann Game

https://neumann-probe.net/

Prototype PHP 8.2 d'un jeu persistant autour d'une sonde de Von Neumann dans un
univers procedural. Cette documentation est volontairement technique: elle sert a
retrouver vite les responsabilites du code, les points d'entree et les
invariants du domaine.

## Etat Du Projet


Le projet est en iteration active. L'application expose a la fois une interface
web simple et une API JSON. La documentation utilisateur n'est pas maintenue ici:
le contrat REST vit dans [docs/openapi.yaml](docs/openapi.yaml), et une future
documentation de jeu pourra etre faite ailleurs.

## Demarrage Local

```bash
composer install
php scripts/init-db.php
php scripts/create-user.php remi secret "Remi"
php -S 127.0.0.1:8000 -t public
```

Puis creer une session API:

```bash
curl -s -X POST http://127.0.0.1:8000/api/session \
  -H 'Content-Type: application/json' \
  -d '{"username":"remi","password":"secret"}'
```

Les routes protegees utilisent ensuite `Authorization: Bearer <token>`.

## Configuration

`config/database.json` configure la base relationnelle. SQLite est le defaut
local:

```json
{
  "driver": "sqlite",
  "path": "var/database.sqlite"
}
```

Le code accepte aussi `mysql` via PDO avec `host`, `port`, `database`,
`username`, `password` et `charset`.

`config/app.json` contient les parametres applicatifs:

```json
{
  "worldSeed": "local-development-world",
  "universePath": "data/universe",
  "sessionTtlDays": 7,
  "schedulerProcessLimit": 100
}
```

`config/gameplay.json` centralise les curseurs de gameplay: zone d'apparition
des nouveaux joueurs, stock initial des sondes, capacites de containers, delais
des actions Manny, timings et risques de mouvement, delais de scan et couts de
craft. `config/universe.json` centralise les probabilites et plages de valeurs
de la generation procedurale.

Chaque fichier JSON de `config/` peut etre surcharge localement avec un fichier
du meme nom termine par `-local.json`, par exemple
`config/gameplay-local.json` ou `config/universe-local.json`. Les objets sont
fusionnes recursivement et les listes sont remplacees. Ces fichiers locaux sont
ignores par Git.

L'authentification OAuth est optionnelle. Copier `config/oauth.example.json` vers
`config/oauth.json`, renseigner Google, Discord et/ou GitHub, puis declarer les
callbacks `/auth/provider/google`, `/auth/provider/discord` et/ou
`/auth/provider/github` cote fournisseur. Le serveur ne conserve que le nom du
fournisseur et l'identifiant stable retourne par celui-ci. GitHub est configure
avec un scope vide pour eviter de demander les adresses email.

## Organisation Du Code

```text
public/index.php        Point d'entree HTTP: routes web, auth web, delegation API.
public/assets/          JavaScript et CSS sans pipeline de build.
templates/home.html     Template HTML principal, rendu avec src/View/TplBlock.php.
src/AppFactory.php      Composition des dependances applicatives.
src/Http/               ApiKernel et format des reponses JSON.
src/Auth/               Authentification mot de passe, OAuth, sessions.
src/Database/           Lecture config PDO, schema et migrations legeres.
src/Repository/         Acces aux tables relationnelles.
src/Domain/             Objets metier persistants et DTO de reponse.
src/Service/            Cas d'usage: mouvement, observation, Mannies, scheduler.
src/Sector/             Moteur de secteurs/univers sous VonNeumannGame\Sector.
src/View/               Helpers de rendu HTML.
scripts/                Outils CLI: init-db, create-user, scheduler.
tests/                  Tests API/integration.
docs/openapi.yaml       Contrat REST.
```

Composer charge l'espace de noms applicatif `VonNeumannGame\` depuis `src/`.

## Vue D'Architecture

`AppFactory` assemble l'application a partir de la configuration locale. Pour
l'API, il cree les repositories PDO, le service d'authentification, le service de
secteurs base sur fichiers JSON, puis les services metier injectes dans
`ApiKernel`.

Le stockage est hybride:

- les joueurs, sondes, sessions, mouvements, Mannies, secteurs visites et
  evenements planifies sont en base SQL;
- le contenu procedural des secteurs est persiste en JSON sous
  `data/universe/sectors/...`;
- `var/database.sqlite` et `data/` sont des donnees locales ignorees par Git.

## Domaine Principal

Lors d'une inscription, `AuthService` cree un joueur, un secteur d'origine
absolu aleatoire valide, une sonde initiale, quatre Mannies par defaut et marque
le secteur de depart comme visite. Les coordonnees absolues restent internes:
l'API expose des coordonnees relatives au secteur d'origine du joueur.

Une sonde neuve demarre avec:

- la capacite cargo et les reserves initiales definies dans `config/gameplay.json`;
- une imprimante 3D atomique stockee dans la sonde;
- le nombre de Mannies et leur encombrement definis dans `config/gameplay.json`;
- une cuve externe de deuterium pleine, hors capacite cargo.

`MannyService` gere le renommage, la reparation, le minage, la fabrication, la
recuperation, les deplacements de stock, l'installation de waypoint-bookmarks et
le rappel des Mannies. Les taches sont temporelles: leur progression est derivee
des timestamps stockes, comme pour les mouvements de sonde.

## Secteurs Et Coordonnees

Le moteur de secteurs vit dans `src/Sector/` avec le namespace
`VonNeumannGame\Sector`.

Les coordonnees utilisent un reseau FCC:

- un secteur est valide si `x + y + z` est pair;
- chaque secteur a 12 voisins directs: `(+-1,+-1,0)`, `(+-1,0,+-1)` et
  `(0,+-1,+-1)`;
- la distance entre secteurs est `max(abs(dx), abs(dy), abs(dz))`;
- `SectorCoordinates` est immutable et serialisable en cle `x:y:z`.

`SectorService` cree les secteurs a la demande. Quand un secteur manque, il est
genere deterministiquement par `SectorContentGenerator` avec `worldSeed`, puis
sauvegarde par `SectorFileRepository`. Les voisins manquants immediats sont aussi
crees pour donner du contexte local a la generation. Les objets possibles
incluent systemes stellaires, etoiles, planetes, asteroides, nuages de poussiere
et trous noirs.

## Observation Et Mouvement

`SectorObservationService` transforme les coordonnees relatives en coordonnees
absolues, calcule la distance depuis la sonde, puis renvoie un niveau de
connaissance:

- `detailed` pour le secteur courant ou deja visite;
- `neighbor_scan` a distance 1 apres assez de residence passive;
- `distant_scan` a distance 2;
- `long_range_estimation` au-dela.

`ProbeMovementService` lance et rafraichit les mouvements intersecteurs. Un
mouvement consomme et dure selon les parametres `movement` de
`config/gameplay.json`.

L'etat courant est derive des timestamps a chaque lecture. Le scheduler CLI
(`php scripts/scheduler.php`) traite aussi les evenements planifies utiles aux
transitions et aux pieges de trous noirs. Pendant un mouvement, les capteurs sont
`normal`, `degraded` ou `blind` selon la phase. La croisiere longue peut detruire
la sonde, et l'arrivee applique des degats de poussiere intersectorielle.

## API

Toutes les routes API sont dans `src/Http/ApiKernel.php`; le detail des schemas
est dans [docs/openapi.yaml](docs/openapi.yaml). Une interface Swagger UI est
disponible sur `/api-docs`, et la spec brute est servie par `/openapi.yaml`.

Routes principales:

- `GET /api/version`
- `POST /api/session`
- `GET /api/me`
- `POST /api/me/api-key`
- `GET /api/crafting-recipes`
- `GET /api/probe`
- `GET /api/probe/visited-sectors`
- `GET /api/probe/sector`
- `POST /api/probe/move`
- `GET /api/probe/inventory/{itemId}`
- `POST /api/probe/inventory/{itemId}/jettison`
- `GET /api/probe/mannies`
- `PATCH /api/probe/mannies/{mannyId}`
- `POST /api/probe/mannies/{mannyId}/repair`
- `POST /api/probe/mannies/{mannyId}/mine`
- `POST /api/probe/mannies/{mannyId}/craft`
- `POST /api/probe/mannies/{mannyId}/salvage`
- `POST /api/probe/mannies/{mannyId}/install-bookmark`
- `POST /api/probe/mannies/{mannyId}/recall`
- `GET /api/sector?x=...&y=...&z=...`

Les coordonnees envoyees par l'API sont toujours relatives au joueur et doivent
respecter la parite FCC.
`GET /api/probe/visited-sectors` liste les secteurs deja visites par la sonde
courante avec leurs coordonnees relatives, dates de premiere/derniere visite et
compteur de visites.
Les Mannys abandonnees ou oubliees apparaissent dans les objets detectes
uniquement pour le secteur ou se trouve actuellement la sonde.
La jetabilite depend du type d'entree d'inventaire: l'imprimante 3D atomique
reste non jetable; les ressources de base et le deuterium jetes disparaissent;
une Manny jetee devient un objet individuel abandonne dans le secteur courant;
les objets craftables standards (`waypoint_bookmark`, `steel_bar`,
`steel_plate`) rejoignent une pile agregee d'objets a la derive dans le secteur
courant. Une Manny peut recuperer ces piles avec l'action `salvage`, dans la
limite de sa capacite de transport par voyage. Les conteneurs supplementaires
gardent une mecanique de jettison reservee pour plus tard.
Un `waypoint_bookmark` stocke s'installe via une tache Manny
`installing_waypoint_bookmark`: la Manny reserve l'objet, passe 10 secondes a
lui donner son impulsion, puis la persistance habituelle des `waypointBookmarks`
est appliquee a l'objet cible du secteur courant.
Les autres sondes detectees dans le secteur courant apparaissent dans
`sector.probes` avec leur id, leur nom et leur etat de mouvement.

Les endpoints proteges acceptent un Bearer token de session ou une clef API
generee par `POST /api/me/api-key`. Les clefs API sont affichees une seule fois
au joueur et stockees uniquement sous forme de hash.

## Base De Donnees

Le schema est initialise par `src/Database/SchemaInitializer.php`. Les tables
actuelles couvrent:

- `players`
- `player_auth_methods`
- `neumann_probes`
- `mannies`
- `probe_movements`
- `scheduled_events`
- `visited_sectors`
- `sessions`
- `api_keys`

Les migrations sont legeres et codees dans l'initializer pour SQLite et MySQL.
Elles couvrent seulement les colonnes ajoutees pendant les iterations recentes.

## Tests Et Verification

```bash
composer test
php tests/SectorTests.php
```

`composer test` execute les tests API/integration dans `tests/ApiTests.php`,
puis les tests du moteur de secteurs dans `tests/SectorTests.php`.
`php tests/SectorTests.php` peut aussi etre lance seul pour verifier les
coordonnees FCC, le voisinage, la generation et la persistance fichier.

Un scenario manuel contre un serveur lance existe aussi:

```bash
php tests/normalusetest.php http://127.0.0.1:8000 remi secret
```

Le scheduler peut etre lance manuellement:

```bash
php scripts/scheduler.php
php scripts/scheduler.php --limit=25
```
