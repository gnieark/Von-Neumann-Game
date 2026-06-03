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
  "sessionTtlDays": 7
}
```

L'authentification OAuth est optionnelle. Copier `config/oauth.example.json` vers
`config/oauth.json`, renseigner Google et/ou Discord, puis declarer les callbacks
`/auth/provider/google` et `/auth/provider/discord` cote fournisseur. Le serveur
ne conserve que le nom du fournisseur et le `sub` OpenID.

## Organisation Du Code

```text
public/index.php        Point d'entree HTTP: routes web, auth web, delegation API.
public/assets/          JavaScript et CSS sans pipeline de build.
templates/home.html     Template HTML principal, rendu avec class/TplBlock.php.
src/AppFactory.php      Composition des dependances applicatives.
src/Http/               ApiKernel et format des reponses JSON.
src/Auth/               Authentification mot de passe, OAuth, sessions.
src/Database/           Lecture config PDO, schema et migrations legeres.
src/Repository/         Acces aux tables relationnelles.
src/Domain/             Objets metier persistants et DTO de reponse.
src/Service/            Cas d'usage: mouvement, observation, Mannies, scheduler.
class/                  Moteur de secteurs/univers sous VonNeumannGame\Sector.
scripts/                Outils CLI: init-db, create-user, scheduler.
tests/                  Tests API/integration.
docs/openapi.yaml       Contrat REST.
```

Composer charge deux espaces de noms: `VonNeumannGame\` depuis `src/` et
`VonNeumannGame\Sector\` depuis `class/`. Le dossier `class/` est historique,
mais il contient encore le moteur de generation de secteurs et le helper de
template legacy.

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

- une capacite cargo de `1 earth_container_equivalent`;
- une imprimante 3D atomique occupant `0.3`;
- quatre Mannies occupant chacun `0.05` quand ils sont a bord;
- une cuve externe de deuterium pleine, hors capacite cargo.

`MannyService` gere le renommage, la reparation, le minage et le rappel des
Mannies. Les taches sont temporelles: leur progression est derivee des timestamps
stockes, comme pour les mouvements de sonde.

## Secteurs Et Coordonnees

Le moteur de secteurs vit dans `class/` avec le namespace
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
mouvement consomme 2% du deuterium courant et suit la timeline:

- 5 minutes de preparation pendant la beta;
- 10 minutes par secteur d'acceleration pendant la beta;
- 15 minutes par secteur de croisiere pendant la beta;
- 10 minutes par secteur de deceleration pendant la beta.

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
- `GET /api/probe/sector`
- `POST /api/probe/move`
- `GET /api/probe/inventory/{itemId}`
- `POST /api/probe/inventory/{itemId}/jettison`
- `GET /api/probe/mannies`
- `PATCH /api/probe/mannies/{mannyId}`
- `POST /api/probe/mannies/{mannyId}/repair`
- `POST /api/probe/mannies/{mannyId}/mine`
- `POST /api/probe/mannies/{mannyId}/craft`
- `POST /api/probe/mannies/{mannyId}/recall`
- `GET /api/sector?x=...&y=...&z=...`

Les coordonnees envoyees par l'API sont toujours relatives au joueur et doivent
respecter la parite FCC.
Les Mannys abandonnees ou oubliees apparaissent dans les objets detectes
uniquement pour le secteur ou se trouve actuellement la sonde.
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
php class/Tests.php
```

`composer test` execute les tests API/integration dans `tests/ApiTests.php`.
`php class/Tests.php` verifie le moteur de secteurs historique: coordonnees FCC,
voisinage, generation et persistance fichier.

Un scenario manuel contre un serveur lance existe aussi:

```bash
php tests/normalusetest.php http://127.0.0.1:8000 remi secret
```

Le scheduler peut etre lance manuellement:

```bash
php scripts/scheduler.php
php scripts/scheduler.php --limit=25
```
