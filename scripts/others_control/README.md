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

Les observations passent par `GET /api/others/sector`, avec le vaisseau mère
comme désignateur de flotte. La précision du scan et l'historique de visite
restent propres à cette flotte. Les Mannys déployées sont suivies par la route
`autonomous-units` de la sentinelle et les stocks par son inventaire.
