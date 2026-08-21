# Plan de migration php-music → Symfony 8.1 + Twig

> Ce document est un **mode d'emploi pas-à-pas**. Suis-le **exactement**, dans
> l'ordre. Ne devine pas. Si une étape n'est pas claire, relis la section
> « RÈGLES ABSOLUES » et « RECETTE UNIVERSELLE ». Une seule feature à la fois.

---

## 0. RÈGLES ABSOLUES (à respecter TOUJOURS)

À FAIRE :
- ✅ Tout passe par `public/index.php` (le front controller Symfony).
- ✅ Une feature = un ou plusieurs **contrôleurs** + **templates Twig** + **entités Doctrine**.
- ✅ Le HTML va **toujours** dans un fichier `.twig` (jamais de HTML dans du PHP).
- ✅ Les commentaires dans le code sont en **anglais**.
- ✅ Vérifier après chaque feature (voir « TESTS »).

À NE JAMAIS FAIRE :
- ❌ Ne **jamais** exécuter `legacy/php_music.php`. Il sert **uniquement de référence à lire**.
- ❌ Ne **jamais** utiliser `$GLOBALS` ni de fonction globale.
- ❌ Ne **jamais** écrire de HTML avec `echo` dans un contrôleur.
- ❌ Ne **jamais** lancer ces commandes (INTERDITES) :
  - `docker-compose` / `docker compose`
  - `doctrine:schema:validate` (ni avec `--skip-sync`)
- ❌ Ne **jamais** faire `git commit` ou `git push` (c'est l'utilisateur qui commite).

Après chaque modif de code, proposer à l'utilisateur un court texte de commit
(≈20 mots, **en anglais**) finissant par une ligne vide puis `noref`
(ou `ref #XXXX` s'il y a un ticket).

---

## 0bis. PÉRIMÈTRE DU PROJET (décidé le 2026-08-21, définitif)

L'utilisateur ne veut **que** la partie musique/artiste de l'app legacy —
pas les features sociales/génériques greffées dessus. Périmètre :

**Dans le périmètre** (§5) : Authentification, Bibliothèque, Streaming,
Favoris, Playlists (base + avancées = Feature 9), Historique/compteurs,
Profil (1), Bibliothèque avancée (2), Upload/ID3 (3), Réglages audio (4),
Listen Later/Offline (5), Découverte/mixes (10), PWA (17), Rhythm game (20).

**Hors périmètre — ne JAMAIS (re)créer**, même si une ancienne version de
ce document ou un vieux commit les mentionne : réseau social (follow/block),
commentaires/réactions sur les morceaux, fil communautaire, messagerie
(chat/groupes/statuts), blogs, notes & tâches, notifications/fil d'activité,
clés API publiques, panneau d'administration, PHPShares (galerie art),
PHPBoard (imageboard), éditeur d'image, éditeur Markdown toolbar, DB
manager. Ces features **ont déjà été implémentées puis intégralement
retirées** (code, migrations, données de test) le 2026-08-21 — si tu vois
une référence à `SocialController`, `SongCommentController`,
`CommunityController`, `BlogController`, `NoteController`, `TaskController`,
aux tables `follows`/`blocks`/`song_comments`/`community_posts`/`blogs`/
`personal_notes`/`tasks`, ou à `User::getDisplayArtist()`/
`UserRepository::searchByArtistName`, c'est un résidu à supprimer, pas une
base à compléter.

**Direction future (pas encore demandée)** : la cible finale est une app
**mono-utilisateur** (juste lire sa bibliothèque MP3 locale). Le système de
comptes (`User`, login/register, `#[CurrentUser]`) est **laissé en place
pour l'instant** car il fonctionne et beaucoup de features en dépendent —
ne pas le supprimer sans une demande explicite.

---

## 1. ENVIRONNEMENT ET COMMANDES DE BASE

Dossier de travail : la racine du repo (le projet Symfony a été remonté
depuis `symfony/` le 2026-08-21 — plus de sous-dossier intermédiaire).

Commandes utiles (chacune dans son propre appel, jamais chaînées avec `;` ou `&&`) :

```bash
php bin/console cache:clear                       # vider le cache
php bin/console debug:router                      # lister les routes
php bin/console lint:twig templates               # vérifier les templates
php bin/console lint:container                     # vérifier l'injection
php bin/console make:entity                        # créer/modifier une entité
php bin/console doctrine:migrations:diff --no-interaction   # générer une migration
php bin/console doctrine:migrations:migrate --no-interaction # appliquer les migrations
php bin/console app:scan                           # (ré)indexer la musique
php -S 127.0.0.1:8000 -t public public/index.php   # lancer le serveur
```

Base de données : SQLite, fichier `var/library/music.db`. **Doctrine possède le
schéma** (via les migrations). On ne touche jamais au `init_db()` du legacy.

---

## 2. RECETTE UNIVERSELLE (à appliquer à CHAQUE feature)

Fais ces étapes **dans l'ordre**, à chaque feature.

### Étape 1 — Lire le legacy (référence)
1. Ouvre `legacy/php_music.php`.
2. Cherche le `case '...':` de l'action (voir la fiche de la feature, section 5).
3. Lis :
   - les requêtes SQL (`$db->prepare(...)`, `SELECT/INSERT/UPDATE/DELETE`) → ça dit **quelles tables/colonnes** sont utilisées ;
   - ce qui est renvoyé : du **HTML** (`echo`, heredoc `<<<HTML`) → deviendra un **template Twig** ; du **JSON** (`json_encode`) → deviendra une `JsonResponse` ;
   - les entrées : `$_GET['x']`, `$_POST['x']`, `json_decode(file_get_contents('php://input'))`.

### Étape 2 — Créer/compléter les entités Doctrine
- Une **table** = une **entité** dans `src/Entity/`.
- Reprends **exactement** les noms de colonnes du legacy (voir section 4 pour la conversion des noms).
- Génère la migration puis applique-la :
```bash
php bin/console doctrine:migrations:diff --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction
```
- Si une entité existe déjà (User, Music, …), n'ajoute que les colonnes manquantes.

### Étape 3 — Créer le repository (requêtes)
- Mets les requêtes dans `src/Repository/<Nom>Repository.php` (méthodes claires).
- Utilise le QueryBuilder, jamais du SQL brut concaténé avec des entrées utilisateur.

### Étape 4 — Créer le contrôleur
- Fichier `src/Controller/<Nom>Controller.php`.
- Une **route** par action. Attribut `#[Route('/chemin', name: 'app_xxx')]`.
- Injecte les dépendances dans les arguments (repository, EntityManagerInterface, etc.).
- Pour une page → `return $this->render('dossier/fichier.html.twig', [...]);`
- **Pas de REST/JSON par défaut.** Pour une action qui modifie des données
  (créer/éditer/supprimer un blog, une note, une catégorie, un post, etc.) :
  formulaire HTML classique → `POST` → `$this->addFlash(...)` →
  `return $this->redirectToRoute('app_xxx');`. C'est le cas par défaut,
  simple et idiomatique Symfony — ne le remplace par du JSON que si ça
  apporte un vrai bénéfice UX (retour instantané sans rechargement de page,
  comme le cœur "favori" ou les icônes listen-later/offline dans le lecteur
  persistant, où `return new JsonResponse([...]);` + `fetch()` a du sens).
  En cas de doute : formulaire classique.
- Pour une action qui modifie (POST) → vérifier le **jeton CSRF** et `#[IsGranted('ROLE_USER')]` si connexion requise.

### Étape 5 — Créer le(s) template(s) Twig
- Dans `templates/<dossier>/<fichier>.html.twig`.
- Étends le layout : `{% extends 'base.html.twig' %}`.
- Recopie le HTML du legacy en remplaçant les variables PHP par du Twig (voir section 4).
- Réutilise le macro de liste de pistes : `{% import 'partials/_tracks.html.twig' as tracks %}` puis `{{ tracks.track_list(songs, favoriteIds) }}`.

### Étape 6 — Extraire la logique réutilisable (si besoin)
- Si le legacy a un **calcul** complexe (ex. génération de chart, traitement d'image, recommandations), crée un **service** dans `src/Service/` (classe injectable). Jamais de fonction globale.

### Étape 7 — Sécurité & échappement
- Twig échappe le HTML automatiquement : écris `{{ x }}` (ne mets **pas** de `htmlspecialchars`).
- N'utilise `|raw` **que** pour des URLs construites côté serveur (jamais pour du texte utilisateur).
- Ne mets jamais de contenu non fiable dans `innerHTML` en JS ; utilise `textContent` ou `DOMParser`.

### Étape 8 — Vérifier (voir section « TESTS »)
```bash
php bin/console cache:clear
php bin/console lint:twig templates
php bin/console lint:container
```
Puis lancer le serveur et tester la route avec `curl`.

### Étape 9 — Marquer la feature « faite »
- Mets `[x]` devant la feature dans la section 5.
- Propose un texte de commit à l'utilisateur (voir RÈGLES ABSOLUES).
- Le `case` legacy reste en place (référence) jusqu'à la toute fin du projet.

---

## 3. TESTS (comment vérifier qu'une feature marche)

1. `php bin/console cache:clear` → doit finir par `[OK]`.
2. `php bin/console lint:twig templates` → `[OK]`.
3. `php bin/console lint:container` → `[OK]`.
4. Lancer le serveur : `php -S 127.0.0.1:8000 -t public public/index.php` (en arrière-plan).
5. Tester la route :
```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/ma-route
```
   → attendu : `200` (ou `302` pour une redirection, `403` si connexion requise sans être connecté).
6. Vérifier le contenu attendu :
```bash
curl -s http://127.0.0.1:8000/ma-route | grep -o "un-texte-attendu"
```
7. Pour une action POST/JSON : envoyer une requête et vérifier le JSON de retour.

Si un test échoue : lire le message d'erreur, corriger, revérifier. Ne pas passer
à la feature suivante tant que ce n'est pas vert.

---

## 4. AIDE-MÉMOIRE : traduire le PHP legacy vers Symfony/Twig

| Legacy (PHP monolithe) | Symfony / Twig (propre) |
| --- | --- |
| `$_GET['id']` | `$request->query->get('id')` ou argument de route `{id}` |
| `$_POST['name']` | `$request->request->get('name')` |
| `json_decode(file_get_contents('php://input'), true)` | `json_decode($request->getContent(), true)` |
| `$db->prepare("SELECT ...")` + `execute()` | méthode de repository avec QueryBuilder |
| `echo "<div>...</div>";` | HTML dans un template `.twig` + `return $this->render(...)` |
| `echo json_encode($data);` | `return new JsonResponse($data);` |
| `htmlspecialchars($x)` | `{{ x }}` (échappement automatique) |
| `{$var}` dans un heredoc | `{{ var }}` dans le template |
| `$_SESSION['user_id']` | l'utilisateur connecté : `#[CurrentUser] ?User $user` |
| `password_hash(...)` / `password_verify(...)` | `UserPasswordHasherInterface` |
| `header('Location: ...'); exit;` | `return $this->redirectToRoute('app_xxx');` |
| `http_response_code(403); exit;` | `throw $this->createAccessDeniedException();` |
| introuvable → `die("not found")` | `throw $this->createNotFoundException();` |
| vérif CSRF manuelle | `$this->isCsrfTokenValid('id', $token)` + champ `_token` dans le form |

Colonnes : le legacy utilise `snake_case` (`user_id`, `is_private`, `created_at`).
Les propriétés d'entité sont en `camelCase` (`userId`, `isPrivate`, `createdAt`) ;
la stratégie de nommage `underscore` de Doctrine fait la correspondance
automatiquement. Garde les mêmes noms de colonnes que le legacy.

---

## 4bis. PIÈGES DÉJÀ RENCONTRÉS (à ne pas refaire)

- **Import de `Route`** : utiliser **exclusivement**
  `use Symfony\Component\Routing\Attribute\Route;`. L'ancien
  `Symfony\Component\Routing\Annotation\Route` **n'existe plus** en Symfony 8.1
  — l'attribut échoue silencieusement à se charger et **aucune route du
  contrôleur n'est enregistrée** (pas d'erreur visible, juste un 404 au
  runtime). Toujours vérifier avec `php bin/console debug:router | grep app_xxx`
  après avoir créé un contrôleur.
- **CSRF dans un formulaire Twig** : `{{ csrf_token('id') }}` seul **n'insère
  aucun champ** — ça affiche juste le texte du token dans la page. Il faut
  toujours l'envelopper : `<input type="hidden" name="_token" value="{{ csrf_token('id') }}">`.
  Vérifier avec `curl` que le champ `_token` est bien présent dans le HTML
  généré avant de considérer la feature terminée.
- **Ne jamais mélanger JS et Twig dans un `<script>`** : `{{ path(unePlainVariableJS) }}`
  ne fonctionne pas — Twig est rendu côté serveur *avant* que le JS n'existe,
  il ne peut pas lire une variable JS. Générer l'URL avec `{{ path('app_xxx') }}`
  **directement**, en dur, dans le `<script>` (ou la passer via un attribut
  `data-*` sur l'élément HTML).
- **`config/packages/csrf.yaml`** : la protection CSRF **stateless**
  (`stateless_token_ids`) exige un cookie signé qui, sur ce projet, n'est
  jamais posé → le token rendu vaut littéralement la chaîne `"csrf-token"`
  (invalide) et toute soumission échoue avec « Invalid CSRF token ». Cette app
  utilise un firewall Symfony classique à session (`form_login`, `lazy: true`)
  → garder la protection CSRF **par défaut, basée sur la session**
  (`framework.csrf_protection: true`), pas de mode stateless.
- **Changer le `status` d'un utilisateur en SQL brut pendant qu'il est
  connecté déconnecte sa session** : le firewall Symfony (`ContextListener`)
  recharge le `User` depuis la BDD à chaque requête ; si les rôles recalculés
  diffèrent de ceux stockés dans le token de session, il log
  "Cannot refresh token because user has changed" et désauthentifie
  silencieusement (retour à l'état « non connecté », pas d'erreur visible).
  Pour tester un compte admin/super-admin avec `curl`, promouvoir le
  `status` **puis** refaire un `POST /login` (nouvelle session) plutôt que de
  réutiliser un cookie de session déjà émis.
- **`UploadedFile::getMimeType()` plante avec un 500** : cette méthode Symfony
  délègue au composant `symfony/mime`, qui **n'est pas** une dépendance de ce
  projet (`composer.json` ne le liste pas) → `LogicException: You cannot
  guess the mime type as the Mime component is not installed`. Pour sniffer
  le vrai type MIME d'un fichier uploadé (extension ≠ contenu réel), utiliser
  `finfo_open(FILEINFO_MIME_TYPE)` / `finfo_file()` directement (ext-fileinfo,
  déjà utilisée ailleurs dans le projet, ex. `StreamController`), jamais
  `UploadedFile::getMimeType()`.
- **`lib/getid3/write.php` (`getid3_writetags`) exige que `getid3.php` ait
  déjà été *exécuté* dans la requête, pas juste que la classe `getID3` soit
  classmappée par composer** : le fichier vérifie `defined('GETID3_INCLUDEPATH')`
  et lève une `Exception` sinon. Cette constante n'est définie qu'une fois que
  `getid3.php` tourne réellement — le classmap composer ne l'inclut que
  lorsqu'une classe qu'il déclare (`getID3`) est effectivement référencée.
  Si le code appelant ne fait *que* `require_once '.../write.php'; new
  getid3_writetags();` sans jamais toucher à `\getID3` avant, ça casse avec
  « getid3.php MUST be included before calling getid3_writetags ». Toujours
  faire un `class_exists(\getID3::class);` (ou `new \getID3()`) avant de
  `require_once` `write.php`.
- **`lib/getid3/write.php` ne sait pas écrire de tags dans un `.wav`** dont
  `getID3->analyze()` renvoie `fileformat === 'wav'` : le `switch` interne de
  `WriteTags()` ne reconnaît que `'riff'` (pas `'wav'`) pour autoriser
  `id3v1`/`id3v2.x`, donc `$AllowedTagFormats` reste vide et `WriteTags()`
  renvoie `false` avec l'erreur `Tag format "id3v2.3" is not allowed on
  "wav.wav" files` — quelle que soit la lib appelante (legacy et ce port
  Symfony sont affectés identiquement, ce n'est pas un bug introduit par la
  migration). La mise à jour de la ligne `music` en BDD reste correcte ;
  seule l'écriture physique du tag échoue silencieusement pour ce format. Pas
  de fix tenté ici (modifier une lib vendorisée est hors périmètre) — documenté
  pour ne pas perdre de temps à re-diagnostiquer si un futur test avec un
  fichier `.wav` semble "ne pas écrire les tags".
- **`QueryBuilder::select('u')` sur un alias joint sans inclure l'alias
  racine** : dans une entité à clé composite (jointure vers 2 `User` par
  exemple), faire `createQueryBuilder('f')->select('u')->join('f.x', 'u')`
  (sans sélectionner `f`) déclenche au runtime
  `Doctrine\ORM\Query\QueryException: Cannot select entity through
  identification variables without choosing at least one root entity
  alias`. **`lint:container`/`lint:twig` ne le détectent pas** (DQL construit
  dynamiquement, jamais compilé avant l'exécution réelle) — seul un vrai
  `curl` sur la route déclenche l'erreur. Fix : toujours sélectionner
  l'alias racine en même temps que l'alias joint, `select('f', 'u')` — c'est
  déjà le pattern utilisé par `FavoriteRepository::recentFavorites`
  (`select('f', 'song')`) ; Doctrine fusionne alors le résultat en un tableau
  plat de l'entité racine (ici `Follow`), pas un tableau de paires, donc
  `getResult()` reste mappable avec `array_map(fn (Follow $f) => $f->getX(), ...)`.
- **Toujours tester le scénario complet avec `curl`**, pas seulement les lints :
  `cache:clear`/`lint:twig`/`lint:container` passent même si une route n'existe
  pas ou si un template plante à l'exécution (erreur runtime seulement visible
  au rendu réel). Le seul test fiable est une requête `curl` bout en bout
  (formulaire → soumission → vérif SQL si pertinent).
- **Collision d'alias DQL entre l'alias de l'entité racine et un alias de
  colonne calculée** : `createQueryBuilder('c')->select('IDENTITY(c.parent)
  AS parent_id', 'COUNT(c.id) AS c')` lève
  `[Semantical Error] ... 'c' is already defined` **au runtime seulement**
  (`lint:container`/`lint:twig` ne le détectent pas, DQL construit
  dynamiquement) — ici parce que l'alias de colonne `AS c` réutilise
  malencontreusement le même nom que l'alias d'entité racine `c` passé à
  `createQueryBuilder()`. Fix : ne jamais nommer un alias de colonne calculée
  comme un alias d'entité déjà utilisé dans la même requête (ex. `AS
  reply_count` au lieu de `AS c`). Vu dans
  `SongCommentRepository::replyCountsForRoots` (Feature 7), cassait
  uniquement les tris `most_liked`/`most_replied` (500), pas le chargement
  normal de la page — encore une preuve qu'il faut tester **chaque** branche
  (chaque valeur de `?sort=`, pas seulement la valeur par défaut).
- **Pointeur auto-référencé (`ManyToOne` vers la même entité) sans cascade,
  accédé en profondeur dans Twig** : si une entité `A` a une relation
  `ManyToOne(A::class)` nullable et sans `onDelete` (ex. `SongComment
  ->replyTo`, qui n'a jamais eu de contrainte FK dans le schéma legacy — la
  colonne a été ajoutée par un simple `ALTER TABLE`), et qu'une ligne cible
  est supprimée, la relation devient un pointeur pendant. Le `ManyToOne` est
  chargé comme un **proxy Doctrine paresseux** : lire seulement son `id`
  (ex. `$reply->getParent()?->getId()`) ne déclenche **aucune requête** (l'id
  est déjà connu depuis la colonne FK) et ne plante donc jamais ; mais lire
  n'importe quelle autre propriété (ex. `comment.replyTo.user.displayArtist`
  dans un template Twig) force l'initialisation complète du proxy, qui
  lève `Doctrine\ORM\EntityNotFoundException` si la ligne référencée n'existe
  plus. **Invisible à `lint:twig`/`lint:container`**, ne casse qu'au rendu
  réel d'une page contenant une entité orpheline — donc il faut spécifiquement
  tester le scénario "supprimer une ligne pointée par une autre" avant de
  clore une feature avec des relations auto-référencées facultatives. Fix :
  dans l'accesseur (`getReplyTo()` par ex.), forcer l'initialisation dans un
  `try/catch (EntityNotFoundException)` et renvoyer `null` si la ligne
  cible a disparu, plutôt que de laisser l'exception remonter jusqu'au
  template — voir `SongComment::getReplyTo()` (Feature 7).
- **Ne jamais mettre `manifest.json`/`sw.js`/une icône en fichier physique
  dans `public/` si l'app est testée avec `php -S -t public public/index.php`
  (le "serveur de test" imposé par ce plan, §1/§3)** : le serveur intégré de
  PHP sert un fichier qui existe réellement dans le docroot **avant même
  d'appeler le front controller** (`public/index.php`), en devinant le
  `Content-Type` via sa propre table d'extensions codée en dur — table
  incomplète. Constaté ici : une requête sur un `public/manifest.json`
  physique renvoyait bien le bon contenu JSON mais avec
  `Content-Type: text/html` (silencieusement faux, `curl -D -` nécessaire
  pour le voir) ; pire, une requête sur un `public/icon.svg` physique a fait
  planter le serveur avec un **`Parse error` PHP** — le serveur, ne
  reconnaissant pas `.svg` dans sa table, a tenté d'**exécuter le fichier
  comme du PHP** au lieu de le servir statiquement. `lint:twig`/
  `lint:container`/`debug:router` ne détectent rien (les fichiers ne
  passent jamais par le routeur Symfony). Fix : pour ces 3 assets PWA,
  toujours passer par un contrôleur dédié (`PwaController` ici) qui force
  explicitement le bon `Content-Type` via `Response`/`JsonResponse`, jamais
  un fichier physique dans `public/` — même si le contenu est 100% statique
  et ne dépend d'aucune donnée dynamique. Toujours vérifier avec
  `curl -s -D -` (pas juste `-o /dev/null -w "%{http_code}"`) que le
  `Content-Type` annoncé est le bon, pas seulement que le code HTTP est 200.
- **`ON DELETE CASCADE` déclaré dans une migration SQLite n'est jamais
  réellement appliqué par ce projet** : `SqlitePragmaMiddleware` (appelé sur
  chaque nouvelle connexion DBAL) ne pose que `PRAGMA busy_timeout` et
  `PRAGMA journal_mode` — jamais `PRAGMA foreign_keys = ON`. Or SQLite
  désactive l'application des contraintes de clé étrangère **par défaut**
  tant que ce pragma n'est pas explicitement activé sur la connexion. Donc
  toute FK `ON DELETE CASCADE` écrite dans une migration Doctrine (ex.
  `playlist_collaborators.playlist_id`, `playlist_invites.playlist_id`)
  n'est qu'une déclaration de schéma cosmétique : supprimer la ligne parente
  via l'app (`$em->remove($playlist); $em->flush();`) laisse les lignes
  filles orphelines en base — confirmé en conditions réelles (pas seulement
  via `dbal:run-sql`, qui a le même souci) : créer une playlist, générer un
  `playlist_invites`, supprimer la playlist via `POST /playlists/{id}/delete`,
  et la ligne `playlist_invites` survit. Le nettoyage ne marche **que** pour
  les relations où Doctrine a en plus une cascade **ORM** explicite côté
  entité parente (`#[ORM\OneToMany(..., cascade: ['remove'], orphanRemoval:
  true)]`, ex. `Playlist::$items` → `playlist_songs`) — l'ORM fait alors le
  ménage lui-même en émettant des `DELETE` un par un, indépendamment de la
  FK SQLite. Toute nouvelle table jointe qui n'est **pas** modélisée comme
  une collection `OneToMany` cascadée sur son parent doit être nettoyée
  **explicitement** dans le repository avant de supprimer le parent (voir
  `PlaylistRepository::remove()`, qui supprime `PlaylistCollaborator`/
  `PlaylistInvite` par DQL avant de `remove()` la `Playlist`). Ni
  `lint:container`/`lint:twig` ni même un simple `SELECT` juste après le
  `DELETE` du parent dans la même session PHP ne suffisent à détecter ça de
  façon fiable si on ne pense pas spécifiquement à vérifier les tables
  filles orphelines — toujours tester "supprimer le parent, puis
  `SELECT COUNT(*)` sur chaque table qui le référence" avant de clore une
  feature qui ajoute une nouvelle table avec une FK `ON DELETE CASCADE`.
- **Paramètres de route qui peuvent contenir un `/`** (nom d'artiste, album,
  genre…) : par défaut un `{name}` dans une route Symfony matche `[^/]++`
  (pas de slash). Or les tags réels contiennent des slashs — ex. un artiste
  `HUNTR/X` ou `Parov Stelar/Lightnin' Hopkins` (collaborations). Résultat :
  `path('app_artist', {name: 'HUNTR/X'})` lève au **rendu Twig**
  `Parameter "name" for route "app_artist" must match "[^/]++"` — invisible
  aux lints, et seulement sur les vraies données (jamais reproduit avec les
  3 morceaux de test "Unknown Artist"). Fix : `requirements: ['name' => '.+']`
  sur la route (déjà appliqué à `app_artist`/`app_album`/`app_genre`). Le
  générateur d'URL garde alors le `/` littéral et le matcher le ré-accepte
  (aller-retour vérifié : `/artist/HUNTR/X` → 200). À prévoir pour toute
  future route dont le paramètre vient d'un champ texte libre saisi par
  l'utilisateur ou lu d'un tag.

## 5. LISTE DES FEATURES À MIGRER (dans l'ordre)

Légende statut : `[x]` fait · `[ ]` à faire.

Chaque fiche suit le **même format**. Applique la RECETTE UNIVERSELLE (section 2).

---

### DÉJÀ FAIT (référence de ce à quoi ressemble une feature terminée)

- [x] **Authentification** — actions legacy : `login`, `logout`, `register`.
  Contrôleurs : `SecurityController`, `RegistrationController`. Entité : `User`.
- [x] **Navigation bibliothèque (base)** — `song`, `artist`, `album`, `search`.
  Contrôleur : `LibraryController`. Templates : `library/*`. Entité : `Music`.
- [x] **Streaming & pochettes** — `get_stream`, `get_image`, `embed`.
  Contrôleurs : `StreamController`, `EmbedController`.
- [x] **Favoris (base)** — `toggle_favorite`, `get_favorites`.
  Contrôleur : `FavoriteController`. Entité : `Favorite`. *(CSRF ajouté après
  coup le 2026-08-21 — `toggle` n'en avait aucune, repéré pendant la Feature 5 ;
  testé : sans token → 403, avec token → 200.)*
- [x] **Playlists (base)** — `create_playlist`, `add_to_playlist`, `remove_from_playlist`, `delete_playlist`.
  Contrôleur : `PlaylistController`. Entités : `Playlist`, `PlaylistSong`.
- [x] **Historique / compteurs** — `log_play`.
  Contrôleur : `PlaybackController`. Entités : `History`, `PlayCount`.

---

### FEATURE 1 — [x] Profil & compte utilisateur
- **But** : voir/éditer son profil, changer nom/mot de passe, photo, supprimer/restaurer le compte.
- **Actions legacy à lire** : `get_user_profile`, `get_user_stats`, `get_profile_songs`,
  `save_bio`, `upload_profile_picture`, `get_profile_picture`,
  `upload_profile_background`, `get_profile_background`, `change_name`,
  `change_password`, `forgot_password`, `reset_password_submit`,
  `request_verification`, `delete_account_all`, `delete_account_keep_data`,
  `restore_account`, `export_user_data`, `import_user_data`.
- **Table(s)** : `users` (colonnes à ajouter à l'entité `User` : `profile_picture` BLOB,
  `profile_picture_type`, `profile_background` BLOB, `profile_background_type`,
  `verified`, `backup_key`, `reset_requested`).
- **Entités** : compléter `User`.
- **Routes/contrôleur** : `ProfileController` (`/profile`, `/profile/edit`, `/profile/picture`, …).
- **Templates** : `profile/show.html.twig`, `profile/edit.html.twig`.
- **Test** : `/profile` renvoie 200 connecté ; changer le nom met bien à jour la BDD.
- **Fait** : show/edit/change-password/upload picture+background (avec fallback
  SVG)/delete (soft+hard)/restore, tout testé en conditions réelles (register →
  login → edit → re-login avec le nouveau mot de passe → vérif SQL). Reste en
  TODO (hors périmètre du test minimal) : suppression des données liées lors du
  hard delete, stats réelles sur `/profile` (actuellement à 0), export/import
  JSON du profil.

### FEATURE 2 — [x] Bibliothèque avancée (tri, genres, années, détail morceau)
- **But** : tris (album/artiste/année asc/desc), navigation par genre/année, page détail d'un morceau, versions.
- **Actions legacy** : `get_songs`, `get_albums`, `get_artists`, `get_genres`,
  `get_all_genres`, `genre_songs`, `album_songs`, `artist_songs`, `year_songs`,
  `get_years`, `album_asc`, `album_desc`, `artist_asc`, `artist_desc`,
  `year_asc`, `year_desc`, `details`, `song`, `get_song_data`,
  `get_song_versions`, `restore_song_version`.
- **Table(s)** : `music`.
- **Contrôleur** : compléter `LibraryController` (paramètre de tri `?sort=`), `SongController` (détail).
- **Templates** : `library/list.html.twig` (ajouter le tri), `song/show.html.twig`.
- **Test** : `/library?sort=artist_desc` trie bien ; `/song/1` affiche le détail.
- **Fait** : `music` avait déjà les colonnes `genre`/`year` (pas de migration
  nécessaire). `MusicRepository` : tri paramétrable (`SORT_KEYS` =
  artist/album/year asc|desc) + `listGenres`/`findByGenre`/`listYears`/`findByYear`.
  `LibraryController` : `?sort=` sur `/library` (whitelist stricte, fallback
  `artist_asc`) + nouvelles routes `/genres`, `/genre/{name}`, `/years`,
  `/year/{year}`. Nouveau `SongController` : `/song/{id}` (détail : cover,
  artiste/album/genre/année/durée/bitrate/lyrics/favori/nombre d'écoutes) +
  `/song/{id}/versions/restore` (POST, CSRF, propriétaire ou super-admin
  seulement). `get_song_versions`/`restore_song_version` n'ont pas de table
  dédiée dans le legacy (ce sont des copies de fichiers sous
  `MUSIC_DIR/.file_version/<basename>/`) → portés dans un service
  `SongVersionManager` (liste + restore avec snapshot avant écrasement) plutôt
  qu'une entité. Lien "ℹ" ajouté sur chaque ligne de `_tracks.html.twig` vers
  `/song/{id}`, et liens Genres/Years ajoutés à la sidebar. Testé en conditions
  réelles avec le serveur PHP + `curl` : tri (artist/album/year asc/desc) sur
  données réelles, `/genres`+`/genre/Rock`, `/years`+`/year/2001`, 404 sur
  genre/année inexistants, `/song/1` (contenu + liens), bascule favori depuis
  la page détail, section "File versions" visible seulement pour le
  propriétaire/super-admin, restore réel (fichier `.wav` modifié → restauré,
  vérifié par hash MD5, avec snapshot de l'état pré-restore créé), CSRF invalide
  → 403, non connecté → redirection login. TODO restant : pas de bouton
  "upload d'une nouvelle version" (viendra avec la Feature 3 upload/ID3) ;
  navigation genre/année ne gère pas encore les featuring multi-artistes comme
  `get_artists` (pas nécessaire pour cette feature, `artist` reste une chaîne
  brute comme avant).

### FEATURE 3 — [x] Upload & éditeur de métadonnées (ID3)
- **But** : uploader des morceaux, éditer les tags (titre/artiste/album/pochette/paroles), supprimer, rescan.
- **Actions legacy** : `upload_song`, `edit_metadata`, `delete_song`,
  `inspect_audio`, `save_audio_editor`, `full_scan`, `force_rescan`, `rescan_covers`.
- **Table(s)** : `music`, `user_song_settings`.
- **Service** : réutiliser `MusicScanner` ; créer `MetadataWriter` (écriture des tags via `lib/getid3/write.php`).
- **Contrôleur** : `UploadController`, `MetadataController`.
- **Templates** : `upload/form.html.twig`, `metadata/edit.html.twig`.
- **Attention** : quota (10/jour dans le legacy) → à reporter. Fichiers dans `MUSIC_DIR/uploads/...`.
- **Test** : uploader un fichier crée une ligne `music` ; éditer un tag met à jour le fichier ET la BDD.
- **Fait** : `UploadController` (`GET/POST /upload`, quota 10/jour réel avec
  colonnes `users.last_upload_date`/`daily_upload_count` — migration
  `Version20260821125844`), `MetadataController` (`/song/{id}/edit` GET+POST,
  `/song/{id}/delete` POST, `/admin/rescan` POST `ROLE_ADMIN`), service
  `MetadataWriter` (port du bloc d'écriture ID3 de `edit_metadata`/
  `save_audio_editor` : choix de `tagformats` par extension, retry avec
  `remove_other_tags=true` en cas d'échec). `MusicScanner::scan()` a gagné un
  paramètre `$force` (skip du check mtime) qui unifie `full_scan`/
  `force_rescan`/`rescan_covers` en une seule action admin "Rescan library" /
  "Force full rescan" (rescan_covers n'a pas de route dédiée : le scanner
  réextrait déjà les pochettes à chaque fichier traité — TODO si un jour un
  rescan "covers only" plus léger est nécessaire). `user_song_settings`
  n'a **pas** été créée : aucune des actions de cette fiche ne la lit/écrit
  réellement (laissée pour la Feature 4 comme prévu). Simplification
  documentée : la propriété d'un morceau utilise la vraie FK `music.user_id`
  (déjà présente) au lieu du hack legacy `"Artist (id:N)"` dans la colonne
  `artist` — pas besoin de porter la logique de collaborateurs
  (`song_collaborators`/`song_invites`), qui reste hors périmètre (Feature 9).
  Validation upload : whitelist d'extensions **et** sniff du vrai type MIME
  via `finfo` (magic bytes, pas `UploadedFile::getMimeType()` — voir piège
  ci-dessous) **et** un vrai `getID3->analyze()` qui doit reconnaître le
  contenu comme audio ; taille max configurable (`app.max_upload_bytes`,
  50 Mo par défaut). Testé en conditions réelles avec le serveur PHP + `curl` :
  upload réel d'un `.wav` (ligne `music` créée, fichier shardé sous
  `uploads/<md5[0:2]>/<artist>/`), compteur de quota incrémenté puis blocage
  au 10e (429→flash + redirection), rejet d'un faux `.mp3` (texte brut,
  détecté par `finfo`), édition de métadonnées (titre/artiste/album/genre/
  paroles mis à jour en BDD ET tentative d'écriture ID3 physique — voir piège
  WAV ci-dessous), 403 sur CSRF invalide, 403 sur édition par un non-
  propriétaire/non-admin, suppression réelle (ligne BDD + fichier physique
  supprimés), rescan admin (`ROLE_ADMIN` requis, 403 sinon) exécuté sur la
  bibliothèque réelle. Utilisateurs et morceau de test nettoyés après coup.
  TODO restant (hors périmètre minimal) : `inspect_audio` (prévisualisation
  de tags avant upload, côté formulaire) n'a pas de route dédiée — l'upload
  fait déjà l'équivalent server-side ; pas de page "audio editor" dédiée pour
  `save_audio_editor` (fusionnée conceptuellement avec `edit_metadata`, les
  deux réutilisent `MetadataWriter`).

### FEATURE 4 — [x] Paramètres audio par morceau
- **But** : volume + égaliseur mémorisés par utilisateur et par morceau.
- **Actions legacy** : `save_song_settings`, `reset_song_settings`, `get_song_data`.
- **Table(s)** : `user_song_settings` (clé composite `user_id` + `song_id`).
- **Entité** : `UserSongSetting` (clé composite comme `Favorite`).
- **Contrôleur** : `SongSettingsController` (POST JSON).
- **Test** : sauver un réglage puis le relire renvoie les mêmes valeurs.
- **Fait** : entité `UserSongSetting` (clé composite `user_id`/`song_id`,
  modélisée comme `Favorite` ; `volume_multiplier` FLOAT défaut `1.0`,
  `eq_bands` TEXT en JSON — même format que le legacy, tableau de 5 gains
  dB pour les bandes `[60, 230, 910, 3600, 14000]` Hz) + migration
  `Version20260821131610`. `UserSongSettingRepository::findOneForUser`.
  `SongSettingsController` (`ROLE_USER`) : `GET /song/{id}/settings`
  (valeurs actuelles ou défauts `1.0`/`null`), `POST /song/{id}/settings`
  (sauvegarde upsert), `POST /song/{id}/settings/reset` (suppression de la
  ligne) — CSRF vérifié via l'en-tête `X-CSRF-TOKEN` (id de jeton
  `song_settings_{id}`, cohérent avec le pattern JSON déjà utilisé dans
  `ProfileController::delete`) car ce sont des requêtes JSON sans corps
  form-encodé. Intégration front minimale ajoutée dans
  `templates/song/show.html.twig` (panneau volume + 5 sliders EQ, visible
  aux utilisateurs connectés) et `assets/player.js` (chargement au load de
  la page, sauvegarde/reset via `fetch`, URLs et jeton CSRF passés en
  attributs `data-*` — jamais de mélange JS/Twig dans un `<script>`).
  Testé en conditions réelles avec le serveur PHP + `curl` : GET avant
  sauvegarde (défauts), POST save (volume 1.5, eq `[3,-2,0,4,-6]`) puis
  relecture identique, deuxième utilisateur avec des valeurs différentes
  sur le **même** morceau et vérification d'indépendance croisée (les deux
  restent inchangés après l'action de l'autre), jeton CSRF invalide → 403
  sans effet sur les données, reset → ligne supprimée en BDD (vérifié en
  SQL) et GET revient aux défauts, non connecté → 302 (redirection login),
  morceau inexistant → 404. Utilisateurs et lignes `user_song_settings` de
  test nettoyés après coup (table vide, `SELECT count(*)` = 0 en fin de
  session).

### FEATURE 5 — [x] Listen Later & Offline
- **But** : marque-pages « à écouter plus tard » et liste hors-ligne, avec tri manuel.
- **Actions legacy** : `toggle_listen_later`, `get_listen_later`, `get_listen_later_ids`,
  `clear_listen_later`, `update_listen_later_order`, `toggle_offline`,
  `get_offline_songs`, `get_offline_ids`, `clear_offline`, `update_offline_order`.
- **Table(s)** : `listen_later`, `offline_songs` (clés composites + `sort_order`).
- **Entités** : `ListenLater`, `OfflineSong` (comme `Favorite`).
- **Contrôleur** : `ListenLaterController`, `OfflineController`.
- **Templates** : `listen_later/index.html.twig`, `offline/index.html.twig`.
- **Test** : ajouter/retirer marche ; l'ordre se sauvegarde.
- **Fait** : entités `ListenLater`/`OfflineSong` (structure identique à
  `Favorite` : clé composite `user_id`/`song_id` + `sort_order`) + migration
  `Version20260821132530` (deux nouvelles tables ; la diff a aussi
  régénéré `user_song_settings` à l'identique — bruit sans rapport,
  non destructif, données préservées via `CREATE TEMPORARY TABLE`).
  `ListenLaterRepository`/`OfflineSongRepository` : `findForUser` (tri
  `sort_order` ASC = `manual_order` legacy), `songIdsForUser`,
  `nextSortOrder`, `updateOrder` (index de la liste reçue = nouveau
  `sort_order`, entrées non trouvées silencieusement ignorées comme le
  legacy), `clearForUser`. `ListenLaterController`/`OfflineController`
  (`ROLE_USER`) : `GET /listen-later|/offline` (page HTML, liste triée),
  `POST .../toggle/{id}` (JSON, ajoute avec `sort_order = max+1` ou retire),
  `POST .../order` (JSON `{"ids":[...]}`), `POST .../clear` (form classique
  **ou** JSON selon `X-Requested-With`/`Accept`, comme les autres actions de
  suppression en masse). CSRF vérifié pour **toutes** les actions
  mutantes — contrairement à `FavoriteController::toggle` qui n'en a
  aucune (lacune pré-existante, non corrigée ici, hors périmètre de cette
  fiche) — avec un seul id de jeton par contrôleur (`'listen_later'`/
  `'offline'`), lu soit dans le header `X-CSRF-TOKEN` (fetch JSON), soit
  dans le champ `_token` (formulaire classique). Intégration front dans
  **toute l'app**, pas seulement les 2 pages dédiées : `_tracks.html.twig`
  (macros `track_row`/`track_list`) a gagné 2 nouveaux boutons icône
  (⏰/🕐 listen-later, ⬇/☁ offline) à côté du cœur favori, avec des
  paramètres optionnels `listenLaterIds`/`offlineIds` (défaut `[]`, donc
  rétrocompatible) ; `LibraryController` (toutes les actions),
  `FavoriteController::index` et `SongController::show` calculent et
  passent ces ids ; `song/show.html.twig` a les mêmes boutons que le cœur
  favori. Jetons + URLs (template `/listen-later/toggle/0` puis JS
  remplace le `0` final par le vrai id, même technique que `cfg.recordUrl`)
  exposés une fois par page dans le bloc JSON `#player-config` de
  `base.html.twig` (jamais de variable JS dans un `{{ }}` Twig). Nouveaux
  handlers génériques dans `assets/player.js` (`bindToggle`), vérifié
  `node --check`. Testé en conditions réelles avec le serveur PHP (port
  8050 dédié) + `curl` : ajout de 3 morceaux à listen-later (sort_order
  1/2/3), page triée conforme, réordonnancement `{"ids":[3,1,2]}` → BDD
  `sort_order` 0/1/2 dans le nouvel ordre et page HTML reflète l'ordre,
  toggle offline indépendant sur les mêmes morceaux (retirer de
  listen-later ne touche pas offline, vérifié en SQL), clear listen-later
  via formulaire classique (302 + BDD vidée) et clear offline via JSON
  fetch (`X-Requested-With`), jeton CSRF invalide → 403 sur toggle/order
  **et** accès refusé sur clear, sans aucun effet sur les données (vérifié
  en SQL après chaque tentative), morceau inexistant → 404, non connecté →
  302 (redirection login), et isolation stricte entre deux utilisateurs
  (chacun ne voit que ses propres entrées, `count()` par utilisateur
  vérifié). Icônes toggle vérifiées présentes sur `/library` et sur
  `/song/{id}` (état `ll-on` reflété correctement après ajout). Utilisateurs
  et lignes de test nettoyés après coup (tables `listen_later`/
  `offline_songs` vides, 0 utilisateur `ll_test_*` restant). TODO restant
  (hors périmètre minimal) : pas de glisser-déposer JS pour réordonner
  (le backend `POST .../order` est complet et testé, mais aucune UI de
  drag-and-drop n'a été construite — seul le clic sur l'icône
  ajouter/retirer est câblé côté front) ; `export_listen_later`/
  `import_offline`/`import_listen_later`/`export_offline` (import/export
  JSON) non portés, comme `export_favorites` ne l'était pas non plus dans
  la Feature « Favoris (base) ».

### FEATURE 9 — [x] Playlists avancées (collaboratives, import/export, copie)
- **But** : playlists collaboratives, invitations, copie, import/export JSON, tri.
- **Actions legacy** : `edit_playlist`, `copy_playlist`, `update_playlist_order`,
  `manage_collaborators`, `leave_collab`, `get_collab_playlists`, `get_user_playlists`,
  `export_playlist`, `import_playlist`, `get_playlist_songs`, `add_mix_to_playlist`,
  `toggle_collaborative`, `manage_song_collaborators`.
- **Table(s)** : `playlists`, `playlist_songs`, `playlist_collaborators`,
  `playlist_invites`, `song_collaborators`, `song_invites`.
- **Entités** : compléter `Playlist`/`PlaylistSong` ; créer `PlaylistCollaborator`, `PlaylistInvite`.
- **Contrôleur** : compléter `PlaylistController`.
- **Test** : copier une playlist publique crée une copie chez l'utilisateur.
- **Fait** : `Playlist` complétée avec `isCollaborative`/`playCount` ;
  `PlaylistSong` complétée avec `addedBy` (qui a ajouté le morceau, owner ou
  collaborateur) ; nouvelles entités `PlaylistCollaborator` (clé composite
  comme les autres join tables) et `PlaylistInvite` (PK = le token, comme le
  legacy). Migration `Version20260821173414`. `song_collaborators`/
  `song_invites`/`manage_song_collaborators` **non portés** : comme
  documenté dans la fiche, c'est la collaboration sur un morceau (feature
  upload), hors périmètre de cette fiche playlists.
  `PlaylistController` complété (routes existantes `app_playlist_new`/
  `_show`/`_delete`/`_add`/`_remove` conservées, non dupliquées) : édition
  (nom/description/privé), `toggle-collaborative` (owner-only, wipe des
  collaborateurs si désactivé — comme le legacy `is_collaborative = CASE
  WHEN...`), gestion des collaborateurs (page dédiée : liste, ajout par
  email/nom exact, suppression, génération de lien d'invite avec expiration
  optionnelle), page d'acceptation d'invite (`GET` affiche les infos,
  `POST` rejoint), `leave` (collaborateur quitte), copie (`POST
  /playlists/copy`, public_id en formulaire), import/export JSON (upload de
  fichier / téléchargement), ajout des morceaux d'un mix existant
  (`MixRepository`/`Mix`/`MixSong` de la Feature 10 réutilisés tels quels),
  réordonnancement. Autorisation exactement comme demandé dans la fiche :
  owner/super-admin seul pour supprimer/éditer/toggle-collaboratif/gérer
  collaborateurs/réordonner ; owner **ou** collaborateur effectif (présent
  dans `playlist_collaborators`) pour ajouter/retirer des morceaux et
  ajouter un mix. **Déviation documentée vs. legacy** : le `remove_from_playlist`
  legacy autorise en réalité *n'importe quel* utilisateur connecté dès que
  `playlists.is_collaborative = 1` (il ne vérifie jamais l'appartenance à
  `playlist_collaborators`) — probable faille du legacy ; ici la
  vérification est resserrée à l'appartenance réelle, conformément à la
  règle explicite donnée dans la fiche. Autre déviation documentée :
  `copy_playlist` legacy n'a **aucun** filtre de visibilité (copie possible
  d'une playlist privée si on connaît son `public_id`) — ici la copie
  (et la nouvelle page `GET /playlists/shared/{publicId}`, qui n'a pas
  d'équivalent legacy direct car la découverte de playlists publiques
  passait par des pages de profil désormais hors périmètre) exige
  `is_private = false`, ce qui donne enfin un sens réel au flag pour cette
  action. Réordonnancement porté en formulaire classique (boutons
  Monter/Descendre, `POST /playlists/{id}/move/{songId}`) plutôt qu'un
  endpoint JSON de drag-and-drop (le plan n'autorise le JSON que si un vrai
  glisser-déposer est construit ; aucun ne l'a été ici). Ajout d'une route
  séparée `app_playlist_add_by_id` (`POST /playlists/{id}/add-track`, body
  `songId`) à côté de la route historique `app_playlist_add`
  (`/playlists/{id}/add/{songId}`) : le formulaire "ajouter un morceau par
  id" sur la page playlist ne peut pas connaître le futur id au moment du
  rendu Twig, donc ne peut pas pré-calculer le bon jeton CSRF pour la route
  historique (qui l'inclut dans son id de jeton) — piège JS/Twig déjà
  documenté en §4bis, évité ici en gardant l'id de morceau uniquement dans
  le corps de la requête pour cette route-là. Import : ajout de
  `MusicRepository::matchImportedSong` (chaîne de correspondance
  titre+artiste+album, titre+artiste, titre/artiste `LIKE`, puis nom de
  fichier — simplification partagée avec les Features 2/10 : la fonction
  SQL `match_artist` du legacy, qui éclate les artistes multi-featuring,
  n'est pas portée). Ajout de `UserRepository::findOneByEmailOrArtistName`
  (recherche exacte email/nom, pour ajouter un collaborateur — distinct de
  la recherche floue `collab_action === 'search'` du legacy, non portée,
  hors périmètre). `PlaybackController::record` a aussi gagné la prise en
  compte optionnelle d'un `playlist_public_id` en body pour incrémenter
  `Playlist::playCount`, fidèle au `log_play` legacy (inactif tant que le
  front-end ne l'envoie pas, aucun front existant ne le fait pour l'instant
  — amélioration de fidélité mineure, pas un pré-requis testé de cette
  fiche). **Bug réel trouvé et corrigé pendant les tests** : voir le nouveau
  piège en §4bis sur les FK SQLite jamais appliquées — `PlaylistRepository::remove()`
  supprime maintenant explicitement les `PlaylistCollaborator`/`PlaylistInvite`
  avant de supprimer la playlist. Testé en conditions réelles avec le
  serveur PHP (port 8300 dédié) + `curl`, 3 comptes de test (owner,
  collaborateur, outsider) : création de playlist, ajout de 3 morceaux,
  passage en collaboratif, génération d'un lien d'invite (avec expiration),
  le collaborateur rejoint via le lien puis ajoute un morceau (vérifié en
  BDD `added_by`), un outsider reçoit bien 403 sur la vue, l'ajout, la
  gestion des collaborateurs, le toggle collaboratif et la suppression,
  CSRF invalide → 403 (y compris avec permission valide par ailleurs), non
  connecté → 302, réordonnancement (bouton "descendre" vérifié en BDD :
  échange de `sort_order`), export JSON (bon `Content-Disposition`, morceaux
  dans l'ordre), import du fichier exporté par un **autre** utilisateur
  recrée la même playlist à l'identique (mêmes morceaux, même ordre), import
  avec correspondance uniquement par nom de fichier (2/3 entrées résolues,
  1 introuvable silencieusement ignorée, comme le legacy), import JSON
  malformé → flash d'erreur + redirection (pas de 500), copie d'une
  playlist publique par un non-propriétaire crée une copie chez lui (mêmes
  morceaux/ordre), copier sa propre playlist → erreur explicite, copier une
  playlist passée en privé → "introuvable" (déviation volontaire), page
  `/playlists/shared/{id}` inaccessible (404) sur une playlist privée pour
  un non-collaborateur, ajout de tous les morceaux d'un mix existant à une
  playlist (7 morceaux ajoutés, doublons évités), désactivation de la
  collaboration wipe bien les collaborateurs (vérifié en BDD), retrait d'un
  collaborateur nommé, un collaborateur peut quitter (`leave`, redirige vers
  `/playlists/collab`), lien d'invite invalide/expiré → 404 dans les deux
  cas, chemin JSON `fetch` de la route d'ajout historique (`X-Requested-With`)
  retourne bien `{"added":true}` puis `{"added":false}` sur un doublon.
  Toutes les données de test nettoyées après coup (utilisateurs `pl9*`,
  playlists/morceaux/mixes de test, tables `playlist_songs`/
  `playlist_collaborators`/`playlist_invites` vérifiées vides en fin de
  session). TODO restant (hors périmètre minimal) : pas de recherche
  "live" d'utilisateur pour choisir un collaborateur (`collab_action ===
  'search'` du legacy, non porté — l'ajout se fait par email/nom exact) ;
  pas de vrai drag-and-drop JS pour le tri (boutons Monter/Descendre
  seulement, formulaire classique) ; le teaser mix sur la page playlist ne
  liste que les mixes existants (pas de génération à la demande, cohérent
  avec la Feature 10 qui ne génère plus de mixes à la demande côté UI) ;
  l'intégration `playlist_public_id` dans `PlaybackController` n'est pas
  câblée côté front (`assets/player.js` n'envoie pas ce champ) ; pas de
  wiring "ajouter à une playlist" depuis la bibliothèque/page morceau (le
  formulaire "ajouter par id" suffit pour les tests bout en bout, mais il
  n'y a toujours pas de sélecteur de playlist ailleurs dans l'app — la
  fonctionnalité existe et est testée via `curl`, seule l'UI de découverte
  reste absente, comme documenté pour `app_playlist_add`/`_remove` avant
  cette fiche).

### FEATURE 10 — [x] Découverte (mixes, recommandations, radio)
- **But** : mixes « Pour toi », artistes/morceaux recommandés, radio/queue auto, tendances.
- **Actions legacy** : `get_mixes`, `mix`, `mix_songs`, `get_recommendations`,
  `get_recommended_artists`, `get_explore`, `get_trending`, `get_radio_tracks`,
  `get_queue_songs`.
- **Table(s)** : `mixes`, `mix_songs`, `play_counts`, `history`.
- **Service** : `RecommendationService` (toute la logique de calcul ici).
- **Contrôleur** : `DiscoveryController`.
- **Templates** : `discovery/*.html.twig`.
- **Test** : `/discovery` renvoie 200 et liste des morceaux.
- **Fait** : entités `Mix`/`MixSong` (le legacy `mix_songs` n'a en réalité
  **aucune** PK déclarée dans son `CREATE TABLE` — juste le rowid implicite
  SQLite ; ici composite PK `mix_id`+`song_id`, comme tous les autres join
  tables du projet ; sans effet pratique car la génération n'insère jamais
  deux fois la même paire) + migration `Version20260821134522` (a aussi
  régénéré `user_song_settings` à l'identique — même bruit sans rapport que
  Feature 5, non destructif). Point notable porté fidèlement du legacy : la
  **génération** des mixes (seeds artiste/genre/année + 50 chansons
  aléatoires, padding si besoin) ne filtre **pas** par visibilité (comme
  `get_mixes` original, qui pioche dans toute la table `music` sans check
  `is_private`) — global, pas par utilisateur — alors que **l'affichage**
  d'un mix (`/mix/{publicId}`) filtre les morceaux privés (propriétaire/
  super-admin seulement), exactement le même écart que le legacy entre la
  requête de génération et le résolveur `mix_songs`. Mixes éphémères :
  expirent après 3 jours (`MixRepository::expireOlderThan`) et se
  régénèrent automatiquement (24 mixes) dès que la table est vide, à chaque
  chargement de `/discover` ou `/discover/mixes` — pas de bouton "refresh"
  manuel exposé (le `refresh=1`/`generate=1` du legacy n'a pas d'équivalent
  UI ici, simplification volontaire pour éviter d'exposer une action
  potentiellement coûteuse sans authentification/CSRF ; le top-up passif
  couvre le cas d'usage réel).
  `MusicRepository` a gagné toute la logique de sélection : `trending`
  (jointure agrégée sur `play_counts`, visibilité respectée), `visibleIds`/
  `randomVisible`/`randomAlbums`/`distinctArtistNames`/`radioCandidateIds`
  (toutes échantillonnent une liste d'ids via QueryBuilder puis `shuffle()`
  PHP — **aucune** fonction SQL `RANDOM()` utilisée, contrairement au
  legacy, pour rester sur du QueryBuilder pur comme l'impose la recette ;
  acceptable ici car la bibliothèque cible n'est pas un très gros catalogue),
  `hydrateByIds` (réordonne des entités selon une liste d'ids, réutilisé pour
  `get_queue_songs`), `isVisibleTo`, `distinctSeedValues`/`idsMatchingSeed`/
  `oneImageIdMatchingSeed`/`randomAnyIds` (génération des mixes, volontairement
  non filtrées par visibilité comme expliqué plus haut). Ajouts ciblés dans
  `PlayCountRepository` (`topArtist`, `topGenres`), `HistoryRepository`
  (`songIdsForUser`), `FavoriteRepository` (`recentFavorites`),
  `UserRepository` (`findOneByArtistName`, `randomSuggested` — porte
  `get_recommended_artists`, exclusion `banned`/`email LIKE 'deleted_%'`
  comme le legacy).
  `RecommendationService` orchestre tout : `trending`, `explore` (shelves
  invités : Discover Songs/Albums/Artists + Frequently Played si connecté —
  simplifié, pas de shelf "From Your Artists" : dépendrait d'un système de
  follow, hors périmètre du projet), `recommendations` (shelves connecté :
  Recently Played, More from `<top artist>`, Your Genre Mix, Your
  Favorites, Discover New Songs/Albums/Artists, Frequently Played —
  simplifié : pas de shelf "Your Playlists", pas de cache 5 minutes
  `sys_get_temp_dir()` du legacy), `recommendedArtists`, `radioTracks`
  (fallback aléatoire si le morceau seed n'existe pas, comme le legacy),
  `queueSongs` (pas de check de visibilité, fidèle à `get_queue_songs` qui
  ne le fait pas non plus), `refreshMixes`. Simplification documentée
  partagée avec la Feature 2 : pas de split des artistes multi-featuring
  (`artist` reste une chaîne brute) dans les shelves "Discover
  Albums"/"Discover Artists" ; pas de `total_plays` sur les cartes album
  (détail d'UI du legacy, abandonné pour simplifier la requête).
  `DiscoveryController` : `GET /discover` (hub — trending + shelves explore
  + teaser mixes), `GET /discover/trending` (liste paginée complète),
  `GET /discover/for-you` (`ROLE_USER`, shelves recommandations),
  `GET /discover/mixes` (liste recherchable/triable, auto-seed/expire),
  `GET /mix/{publicId}` (détail d'un mix, visibilité filtrée, 404 si
  inconnu), `GET /discover/radio/{id}` (JSON), `POST /discover/queue`
  (JSON, pas de CSRF — lecture pure, ne modifie rien, comme
  `get_queue_songs`), `GET /discover/recommended-artists` (JSON). Lien
  "Discover" ajouté à la sidebar (`base.html.twig`), visible à tous
  (connecté ou non). Testé en conditions réelles avec le serveur PHP (port
  8070 dédié) + `curl` : 6 morceaux de test insérés (3 artistes/genres/
  années distincts) + 1 morceau **privé**, 2 utilisateurs de test, écoutes
  générées via `POST /play/{id}` réel (incrémente `play_counts`/`history`) ;
  `/discover/trending` reflète l'ordre réel des écoutes cumulées (vérifié
  avec des compteurs différents par utilisateur) et **exclut le morceau
  privé pour un invité et pour un non-propriétaire connecté**, tout en
  l'incluant en tête pour son propriétaire (5 écoutes, visibilité stricte
  vérifiée dans les 3 cas) ; `/discover` (200, shelves Discover
  Songs/Albums/Artists + Mixes visibles, aucune fuite du morceau privé côté
  invité) ; génération réelle de mixes (10 mixes créés à partir des 10 seeds
  disponibles — 3 artistes + 3 genres + 4 années — chaque mix limité à 9
  pistes car bibliothèque de test à 9 morceaux non-privés) ; recherche
  `?q=Rock` sur `/discover/mixes` filtre bien ; `/mix/{publicId}` affiche
  les bonnes pistes, 404 sur un `publicId` inconnu ; `/discover/for-you`
  non connecté → 302 (redirection login), connecté → 200 avec shelves
  cohérentes (« More from Disco Test Artist » correctement calculé comme
  le top artiste réel de l'utilisateur de test, morceau privé du
  propriétaire visible dans ses propres shelves "Recently
  Played"/"Frequently Played") ; `/discover/radio/{id}` avec seed réel ne
  renvoie que des morceaux du même artiste/genre (hors le seed lui-même) ;
  seed inexistant → fallback aléatoire (200, pas de 404, fidèle au legacy) ;
  `/discover/queue` (POST JSON `{"ids":[9,5,8]}`) préserve l'ordre exact
  demandé. Toutes les données de test nettoyées après coup (`music`,
  `mixes`, `mix_songs`, `play_counts`, `history`, `users` de test — tables
  concernées vérifiées vides/à leur état initial en fin de session). TODO
  restant (hors périmètre minimal) : pas de shelf "From Your Artists"
  (dépendrait d'un système de follow, hors périmètre) ni "Your Playlists" ;
  pas de bouton "refresh manuel" des mixes (top-up passif seulement) ;
  `get_recommended_artists` est porté fidèlement en JSON mais n'est pas
  affiché comme un shelf visible sur le hub (aucune page de profil public
  par utilisateur, hors périmètre).

### FEATURE 17 — [x] PWA & Service Worker
- **But** : manifeste, service worker, icône d'app, mise en cache hors-ligne.
- **Actions legacy** : génération du SW (`?pwa=...`), `get_app_icon`, manifest.
- **À faire** : servir `manifest.json` et `sw.js` en tant que **fichiers statiques** (dans `public/`) ou via un contrôleur dédié. Pas de HTML.
- **Test** : `/manifest.json` et `/sw.js` renvoient 200 avec le bon `Content-Type`.
- **Fait** : nouveau `PwaController` (`/manifest.json`, `/sw.js`, `/icon.svg`) —
  **pas** de fichiers physiques dans `public/` (voir piège ci-dessous, ajouté
  en §4bis). Manifest fidèle au legacy (`name`/`short_name`/`start_url: "/"`/
  `scope: "/"`/`display: standalone`/couleurs `#0a0a0a`/description),
  icônes 192/512 pointant vers le même `icon.svg` (SVG vectoriel, une seule
  taille suffit). `icon.svg` : port fidèle du SVG statique de
  `get_app_icon` (le `size` du legacy ne changeait que les attributs
  `width`/`height`, sans effet visuel réel puisque c'est un vecteur avec
  `viewBox` fixe — simplifié en un seul fichier `512x512`, cache
  `public, max-age=31536000`). `sw.js` : réécrit (pas un port ligne à
  ligne) car le legacy précachait des URLs CDN statiques
  (Bootstrap/Sortable/Google Fonts) qui n'existent pas ici (ce projet sert
  ses assets via AssetMapper avec des noms hashés, imprévisibles à
  l'avance) — stratégie adaptée mais dans le même esprit : network-first +
  fallback cache pour les navigations (app shell), cache-first + peuplement
  paresseux pour les fichiers reconnus comme statiques par extension
  (css/js/svg/images/fonts), tout le reste (JSON dynamique, requêtes non-GET,
  requêtes avec en-tête `Range` = streaming audio) volontairement **non
  intercepté** — pas de cache audio complexe comme demandé. `base.html.twig`
  : `<link rel="icon">` (remplace le favicon data-URI emoji précédent) +
  `<link rel="manifest">` + `<meta name="theme-color">` + script
  d'enregistrement `navigator.serviceWorker.register('/sw.js')` en fin de
  `<body>`. Testé en conditions réelles avec le serveur PHP (port 8400
  dédié) + `curl` : `/manifest.json` → 200, `Content-Type: application/json`,
  JSON valide avec les bons champs ; `/sw.js` → 200, `Content-Type:
  text/javascript; charset=utf-8`, `Service-Worker-Allowed: /`, contenu
  validé avec `node --check` (pas d'erreur de syntaxe) ; `/icon.svg` → 200,
  `Content-Type: image/svg+xml`, `Cache-Control: public, max-age=31536000` ;
  `/` → 200 et contient bien `rel="icon"`/`rel="manifest"`/
  `serviceWorker.register('/sw.js')`. `lint:twig`/`lint:container`/
  `debug:router` (3 nouvelles routes `app_pwa_manifest`/
  `app_pwa_service_worker`/`app_pwa_icon`) tous `[OK]`. TODO restant (hors
  périmètre minimal) : pas de précache des JS/CSS hashés de l'AssetMapper au
  moment de l'`install` du SW (peuplement paresseux au premier chargement à
  la place, pour éviter de casser le cache à chaque changement d'asset) ;
  pas de bouton "installer l'app" dédié dans l'UI (`beforeinstallprompt`,
  présent dans le legacy) — hors périmètre de cette fiche (manifeste + SW +
  icône + cache hors-ligne de base seulement).

### FEATURE 20 — [ ] Rhythm game — **EN PAUSE**
- **But** : jeu de rythme basé sur les morceaux, scores, classements.
- **Actions legacy** : toutes les `get_rhythm_*`, `save_rhythm_chart`,
  `save_rhythm_score`, `get_rhythm_leaderboard`, `get_song_rhythm_leaderboard`,
  `toggle_rhythm_favorite`, `reset_rhythm_charts`, `rescan_charts`.
- **Table(s)** : tables rhythm (charts, scores, favorites) — voir `init_db` legacy.
- **Service** : `RhythmChartGenerator` (toute la génération de beatmap ici).
- **Contrôleur** : `RhythmController`.
- **Test** : générer un chart pour un morceau ; enregistrer un score l'ajoute au classement.
- **En pause (2026-08-21)** : mise en attente d'une décision sur la méthode
  de génération de chart (pseudo-aléatoire fidèle au legacy vs vraie analyse
  audio FFT/onset detection — plus lourd, nécessite une lib d'analyse audio
  côté PHP ou un prétraitement séparé). **Tout le code legacy utile a été
  extrait et documenté dans `docs/rhythm-game-reference/`** (schéma DB,
  fonctions de génération de chart, handlers d'API, CSS, HTML, moteur JS
  complet de 5000 lignes) — lire `docs/rhythm-game-reference/README.md`
  avant de reprendre cette feature, il explique aussi précisément comment
  fonctionne (et ne fonctionne pas) la génération de chart actuelle.

---

## 6. ORDRE CONSEILLÉ

Fais les features restantes dans cet ordre :
9 → 17 → 20.

(1, 2, 3, 4, 5, 10 sont déjà faites. Toutes les autres features qui
existaient dans une version antérieure de ce plan — réseau social,
commentaires/réactions, fil communautaire, messagerie, blogs, notes &
tâches, notifications, clés API, panneau admin, PHPShares, PHPBoard,
éditeur d'image, éditeur Markdown toolbar, DB manager — sont **hors
périmètre définitif** (voir § 0bis) et ne doivent pas être (re)créées.)

Quand **toutes** les features `[x]` sont faites et testées :
- supprimer `legacy/php_music.php` (référence devenue inutile),
- supprimer `lib/getid3` seulement si plus aucun service ne l'utilise,
- mettre à jour le `README.md`.

---

## 7. RAPPEL FINAL (le plus important)

- Une seule feature à la fois. Recette section 2. Tests section 3.
- HTML → toujours Twig. Logique → services. JAMAIS `$GLOBALS`.
- Ne jamais exécuter `legacy/php_music.php`.
- Ne jamais lancer les commandes interdites (section 0).
- Toujours vérifier avec `cache:clear`, `lint:twig`, `lint:container` et `curl`.
