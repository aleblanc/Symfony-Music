# API Subsonic : phases 1 et 2, plan de mise en œuvre (version courte)

> **Pour les agents :** utiliser superpowers:subagent-driven-development ou
> superpowers:executing-plans, une tâche à la fois. Cocher `- [ ]` au fur et à mesure.

**But :** exposer `/rest/*`, compatible Subsonic/OpenSubsonic, pour que Tempo
(en local) puis Symfonium (sur le Pi) lisent toute la bibliothèque.

**Architecture :** une seule route `/rest/{endpoint}` qui envoie chaque appel
au *handler* qui le déclare. Les handlers renvoient un tableau PHP, que
`SubsonicResponse` convertit en JSON ou en XML. Les albums et les artistes n'ont
pas de table : on les identifie par des clés de hachage stockées sur `Music`.

**Spec :** `docs/PLAN_APP_ANDROID.md` § 3 (phase 1) et § 4 (phase 2).

**Rangement :** tout ce qui répond à une requête de l’API (le contrôleur et ses handlers) va dans `src/Controller/Api/` (namespace `App\Controller\Api`). Les services sans route (`IdCodec`, `SongMapper`, `LibraryQuery`…) restent dans `src/Subsonic/`. Une future API (par exemple pour l’appli maison) ira aussi dans `src/Controller/Api/`.

## Contraintes globales
- PHP ≥ 8.4, Symfony 8.1, Doctrine ORM 3, SQLite. Pas de nouveau bundle.
- Commentaires du code **en anglais**.
- Tests : scripts autonomes `php tests/<nom>-test.php` (exit 0 = OK), même modèle que `tests/replaygain-test.php`.
- **L'utilisateur lance lui-même** les migrations et les commandes `app:*`, une par une. **Ne jamais écrire dans `var/library/music.db` depuis le bac à sable.**
- **Pas de `git commit`.** Chaque tâche se termine par un texte de commit proposé (≈ 20 mots en anglais, puis une ligne vide, puis `noref`).
- Les erreurs Subsonic répondent en HTTP **200**, avec `status="failed"`.
- Visibilité : toutes les requêtes respectent `MusicRepository::applyVisibility()` ou `isVisibleTo()`.

## Fichiers

```
src/Subsonic/IdCodec.php                 # ids: song=<int>, al-<key>, ar-<key>, pl-<id>, pl-offline
src/Subsonic/SubsonicRequest.php         # repeated-param aware reader (GET + POST form)
src/Subsonic/SubsonicResponse.php        # envelope → JSON / XML
src/Subsonic/SubsonicException.php       # code + message (10, 50, 70…)
src/Subsonic/SubsonicHandler.php         # interface, autoconfigured tag
src/Subsonic/SongMapper.php              # Music → "child" array
src/Subsonic/LibraryQuery.php            # album/artist/genre aggregates (DBAL)
src/Controller/Api/SubsonicController.php   # App\Controller\Api — single route + dispatch + error envelope
src/Controller/Api/Subsonic/{System,Browsing,Lists,Search,Media,Annotation,Playlist,PlayQueue,Stub}Handler.php  # App\Controller\Api\Subsonic
src/Entity/{StarredAlbum,StarredArtist,PlayQueue}.php (+ repositories)
src/Command/SubsonicBackfillCommand.php  # app:subsonic:backfill
migrations/Version20261025100000.php     # columns + tables
docs/subsonic-smoke.sh
tests/subsonic-{idcodec,request,response,songmapper}-test.php
```

---

# Phase 1 : les données

### Tâche 1 : `IdCodec`
**Produit :** `IdCodec::albumKey(?string $albumArtist, ?string $artist, ?string $album): string`,
`IdCodec::artistKey(?string $albumArtist, ?string $artist): string`,
`IdCodec::decode(string $id): array{type: 'song'|'album'|'artist'|'playlist'|'offline', value: string|int}`.

```php
private static function norm(?string $s): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $s) ?? ''));
}
public static function artistKey(?string $albumArtist, ?string $artist): string
{
    $name = self::norm($albumArtist) !== '' ? $albumArtist : $artist;
    return substr(sha1(self::norm($name)), 0, 16);
}
public static function albumKey(?string $albumArtist, ?string $artist, ?string $album): string
{
    return substr(sha1(self::artistKey($albumArtist, $artist) . "\0" . self::norm($album)), 0, 16);
}
```
`decode` : `pl-offline` donne `offline`, `al-`/`ar-`/`pl-` donnent leur type, une
chaîne de chiffres donne `song`. Tout le reste lève `SubsonicException(70)`.

- [ ] Écrire `tests/subsonic-idcodec-test.php` : casse et espaces multiples donnent la même clé, un artiste d'album vide se rabat sur l'artiste, « Various Artists » donne une seule clé pour des artistes de piste différents, les accents sont conservés, `decode` sur tous les préfixes et sur `xx-1` (doit lever l'exception).
- [ ] Le lancer : il échoue (classe absente). Implémenter. Le relancer : il passe.
- [ ] Commit proposé : `Add Subsonic IdCodec computing stable album and artist keys from tags`

### Tâche 2 : schéma (colonnes, tables, migration)
**Modifier :** `src/Entity/Music.php`. **Créer :** `StarredAlbum`, `StarredArtist`, la migration.

- `Music` : `albumKey` (string 16, nullable), `artistKey` (string 16, nullable),
  `addedAt` et `updatedAt` (datetime_immutable, nullable). Index
  `music_album_key_idx (album_key, disc_number, track_number)`,
  `music_artist_key_idx (artist_key)`, `music_updated_at_idx (updated_at)`.
- **Callbacks de cycle de vie** sur `Music` (`#[ORM\HasLifecycleCallbacks]`).
  Le scanner, l'édition de tags et la note sont ainsi couverts **sans modifier
  leur code** :
  ```php
  #[ORM\PrePersist]
  public function initSubsonicFields(): void
  {
      $this->artistKey = IdCodec::artistKey($this->albumArtist, $this->artist);
      $this->albumKey = IdCodec::albumKey($this->albumArtist, $this->artist, $this->album);
      $this->updatedAt = new \DateTimeImmutable();
      $this->addedAt = $this->updatedAt; // new track: added now
  }

  // In preUpdate, Doctrine ignores direct property changes: they MUST go
  // through setNewValue(), and only for fields already in the changeset.
  // Hence a dedicated listener (MusicSubsonicListener, preUpdate) that does:
  //   $args->setNewValue('artistKey', …) / ('albumKey', …) / ('updatedAt', now)
  // after adding them to the changeset via $uow->recomputeSingleEntityChangeSet().
  // addedAt is NEVER touched on update (the backfill sets it for old rows).
  ```
  ⚠️ Piège Doctrine : dans `preUpdate`, modifier `$this->x` directement **n'est
  pas enregistré**. Il faut passer par un listener `preUpdate` qui appelle
  `$args->setNewValue()`, ou par `onFlush` avec `recomputeSingleEntityChangeSet()`.
  **Préférer `onFlush`**, plus simple et plus fiable : pour chaque `Music` mise à
  jour, on recalcule les clés et `updatedAt`, puis on appelle
  `$uow->recomputeSingleEntityChangeSet($meta, $music)`.
- [ ] Test : sur une **copie** de la base, modifier l'album d'un morceau (et rien
      d'autre). Vérifier que `album_key` a changé, que `updated_at` a avancé, que
      `added_at` est **inchangé**, et que toutes les autres colonnes (note, paroles,
      image, compteurs) sont identiques avant et après.
- `StarredAlbum(albumKey PK string 16, starredAt)` et `StarredArtist(artistKey PK, starredAt)`.
  Un seul utilisateur (D2), donc pas de colonne `user`.
- La migration est écrite à la main en SQLite, comme `Version20261024200000.php` :
  `ALTER TABLE music ADD COLUMN …` ×4, `CREATE INDEX` ×3, `CREATE TABLE starred_album`,
  `CREATE TABLE starred_artist`. Le `down()` ne supprime que les tables et les index
  (SQLite ne sait pas faire `DROP COLUMN` de façon sûre).
- [ ] **Sauvegarde d'abord** : l'utilisateur arrête le serveur et copie `var/library/music.db` (avec `-wal` et `-shm` s'ils existent) vers `music.db.bak-avant-subsonic`.
- [ ] L'utilisateur lance `php bin/console doctrine:migrations:migrate`.
- [ ] Vérifier sur une copie de la base : `PRAGMA table_info(music)` montre les 4 colonnes.
- [ ] Commit proposé : `Add album/artist keys, added/updated timestamps on music and starred album/artist tables`

### Tâche 3 : `app:subsonic:backfill`
Une commande sur le modèle de `ScanCommand`. Elle parcourt `music` par lots de
200 via DBAL (sans hydrater les BLOB `image`). Elle ne lit que `id, artist,
album_artist, album, last_modified` et n'écrit **que** les 4 nouvelles colonnes,
avec un `UPDATE … WHERE id = ?` (jamais d'`UPDATE` sur une autre colonne). Elle calcule les clés avec `IdCodec`,
remplit `added_at = datetime(last_modified, 'unixepoch')` (ou maintenant si
c'est NULL) et `updated_at = added_at` quand ils sont vides. Option `--dry-run`.
Elle affiche le nombre de lignes mises à jour.
- [ ] L'utilisateur lance `php bin/console app:subsonic:backfill`.
- [ ] Vérifier (sur la copie) : `SELECT COUNT(*) FROM music WHERE album_key IS NULL` renvoie 0, et `SELECT COUNT(DISTINCT album_key) FROM music` renvoie ≈ 572.
- [ ] Commit proposé : `Add app:subsonic:backfill command filling album/artist keys and timestamps for existing tracks`

> Les pochettes en fichiers (D11) **ne font pas partie de ces phases** :
> `getCoverArt` lit le BLOB du premier morceau de l'album. Ce sera une tâche à
> part, quand la bibliothèque aura grossi.

---

# Phase 2 : l'API

### Tâche 4 : `SubsonicRequest`
**Produit :** `SubsonicRequest::fromRequest(Request $r): self`, `get(string $k): ?string`,
`getAll(string $k): list<string>`, `require(string $k): string` (lève l'erreur 10),
`int(string $k, int $default): int`, `bool(string $k): bool`, `format(): 'json'|'xml'`, `client(): string`.

Le cœur, qui gère le piège des paramètres répétés :
```php
foreach ([$r->server->get('QUERY_STRING', ''), $r->getContent()] as $raw) {
    foreach (explode('&', (string) $raw) as $pair) {
        if ($pair === '') continue;
        [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
        $this->params[urldecode($k)][] = urldecode($v);
    }
}
```
Le corps n'est lu que si le `Content-Type` est `application/x-www-form-urlencoded`.
- [ ] Test : `songIdToAdd=1&songIdToAdd=2` donne `[1, 2]`, la même chose en POST, `+` et `%20` sont décodés, `require` lève une erreur 10 si le paramètre manque, `f=json` donne `json`, sans `f` on obtient `xml`.
- [ ] Rouge, implémentation, vert. Commit proposé : `Add SubsonicRequest parsing repeated query and form parameters`

### Tâche 5 : `SubsonicResponse` et `SubsonicException`
**Produit :** `SubsonicResponse::ok(array $payload, string $format): Response`,
`::error(int $code, string $msg, string $format): Response`.
`SubsonicException(int $code, string $message)`.

- L'enveloppe : `status`, `version: "1.16.1"`, `type: "symfony-music"`,
  `serverVersion: "1.0"`, `openSubsonic: true`, plus le contenu.
- **JSON** : `{"subsonic-response": {...}}`.
- **XML** (`xmlns="http://subsonic.org/restapi"`) :
  - une valeur scalaire devient un **attribut** (les booléens s'écrivent `true` ou `false`) ;
  - un tableau associatif devient un élément enfant ;
  - une liste devient des éléments répétés, au nom de la clé ;
  - la clé `value` devient le texte de l'élément (pour les paroles).
- [ ] Test : `ping` en JSON et en XML ; une liste `album: [{id:1},{id:2}]` produit deux `<album id="1"/>` ; une erreur 70 produit `status="failed"` et `<error code="70" …/>`.
- [ ] Rouge, implémentation, vert. Commit proposé : `Add Subsonic response envelope rendering JSON and XML`

### Tâche 6 : routage, pare-feu, `SystemHandler`
**Créer :** `src/Subsonic/SubsonicHandler.php` (interface), `src/Controller/Api/SubsonicController.php`, `src/Controller/Api/Subsonic/SystemHandler.php`. **Modifier :** `config/packages/security.yaml`.

```php
#[AutoconfigureTag('app.subsonic_handler')]
interface SubsonicHandler
{
    /** @return list<string> Subsonic method names this handler answers */
    public static function methods(): array;
    /** @return array<string, mixed>|Response payload (without envelope) or a raw response */
    public function handle(string $method, SubsonicRequest $request, User $user): array|Response;
}
```
- Le contrôleur : `#[Route('/rest/{endpoint}', requirements: ['endpoint' => '[A-Za-z0-9]+(\.view)?'], methods: ['GET','POST'])]`.
  Il retire le suffixe `.view`, trouve le handler dans un `iterable` taggé
  (`#[AutowireIterator('app.subsonic_handler')]`, table construite à partir de
  `methods()`), et transforme les exceptions comme suit :
  `SubsonicException` → `error(code)`, méthode inconnue → `error(0, "Not implemented: X")`,
  `\Throwable` → journalisée puis `error(0)`.
- `security.yaml`, **à placer avant `main`** :
  ```yaml
  subsonic:
      pattern: ^/rest/
      stateless: true
      provider: app_user_provider
      custom_authenticators:
          - App\Security\AutoLoginAuthenticator
  ```
- `SystemHandler` répond à :
  - `ping` : `[]` ;
  - `getLicense` : `license: {valid: true}` ;
  - `getOpenSubsonicExtensions` : `formPost` v1 et `songLyrics` v1 ;
  - `getMusicFolders` : un dossier, `id: 1` ;
  - `getUser` : tous les rôles utiles à `true` ;
  - `getScanStatus` : `scanning: false` et le nombre de morceaux.
- [ ] Vérifier : `symfony server:start`, puis `curl 'localhost:8000/rest/ping.view?f=json'` renvoie `status: ok`, `curl localhost:8000/rest/nope` renvoie une erreur 0, et `curl localhost:8000/` (le site web) marche toujours.
- [ ] Commit proposé : `Add /rest Subsonic dispatcher, firewall and system endpoints (ping, license, extensions, folders, user)`

### Tâche 7 : `SongMapper` et `LibraryQuery`
**Produit :**
- `SongMapper::child(Music $m, array $ctx): array`. `$ctx` contient `starred`
  (`array<int, string>` : morceau → date), `plays` (`array<int, array{count:int,last:?string}>`)
  et `musicDir`.
- `SongMapper::context(User $u, list<int> $ids): array` charge les favoris et les
  compteurs d'écoute en **2 requêtes** pour toute la liste (pas de N+1).
- `LibraryQuery` (DBAL) :
  - `albums(array $filter, string $order, int $size, int $offset): list<array>` ;
  - `album(string $key): ?array` ;
  - `artists(): list<array>` ;
  - `artist(string $key): ?array` ;
  - `genres(): list<array>` ;
  - `songIdsOfAlbum(string $key): list<int>`.
  Les agrégats : `MIN(id) AS cover_song`, `COUNT(*)`, `SUM(duration)`, `MAX(year)`,
  `MIN(added_at)`, `AVG(rating)`. **Le filtre de visibilité** est reproduit dans
  le SQL, à partir de `isPrivate`, `owner` et du super-admin.

Les champs d'un *child* sont ceux du § 4.4 de la spec, avec `coverArt = "al-<albumKey>"`.
- [ ] Test `subsonic-songmapper-test.php` sur un `Music` construit en mémoire (sans base) : les champs complets, `bitRate` en kbps, `path` relatif, `suffix` et `contentType` pour m4a, mp3 et flac, `replayGain` absent quand les gains sont vides, `starred` absent quand le morceau n'est pas favori.
- [ ] Rouge, implémentation, vert. Commit proposé : `Add Subsonic song mapper and library aggregate queries`

### Tâche 8 : `BrowsingHandler`
Méthodes : `getArtists`, `getIndexes` (mêmes données, avec `lastModified`),
`getArtist`, `getAlbum`, `getSong`, `getGenres`, `getMusicDirectory` (`ar-` donne
ses albums en `isDir=true`, `al-` donne ses morceaux), `getArtistInfo2` et
`getArtistInfo` (depuis `ArtistProfile` : summary, imageUrl, mbid), `getAlbumInfo2`
et `getAlbumInfo` (vides).
Pour l'index alphabétique : on ignore les articles `The Les Le La L'`, et ce qui
ne commence pas par une lettre va sous `#`.
- [ ] `curl` : `getArtists` contient ≈ 217 artistes, `getAlbum?id=al-…` renvoie les pistes dans l'ordre disque puis piste, et un id inconnu renvoie une erreur 70.
- [ ] Commit proposé : `Add Subsonic browsing endpoints (artists, indexes, album, song, genres, directories, info)`

### Tâche 9 : `ListsHandler`
- `getAlbumList2` et `getAlbumList` acceptent les types `alphabeticalByName`,
  `alphabeticalByArtist`, `newest`, `recent`, `frequent`, `random`, `starred`,
  `highest`, `byYear` et `byGenre`, avec `size` (500 au maximum) et `offset`.
- Pour `recent` et `frequent`, on trie par `MAX(history.played_at)` et par
  `SUM(play_counts.play_count)`, jointure par `album_key`.
- `getRandomSongs` : `size`, `genre`, `fromYear`, `toYear`. On réutilise
  `MusicRepository::visibleIds()` puis `shuffle()`.
- `getSongsByGenre`.
- `getStarred2` et `getStarred` : `Favorite`, `StarredAlbum`, `StarredArtist`.
- `getTopSongs?artist=` : on trie par nombre d'écoutes puis par note.
- `getSimilarSongs2` et `getSimilarSongs` : des morceaux au hasard du même genre,
  sans l'artiste de départ.
- [ ] `curl` : `byYear&fromYear=2000&toYear=1990` est trié par ordre décroissant, et `size=600` est ramené à 500.
- [ ] Commit proposé : `Add Subsonic album and song list endpoints (album lists, random, genre, starred, top, similar)`

### Tâche 10 : `SearchHandler`
- `search3` et `search2` cherchent avec `LIKE` sur title, artist, album et
  albumArtist, avec `*Count` et `*Offset`.
- `query` vide ou `""` (avec les guillemets) : **tous les morceaux**, triés par `id`.
- Extension maison `changedSince=<ms epoch>` : on ajoute `updated_at > :since`.
- [ ] `curl` : `search3?query=&songCount=500&songOffset=2000` renvoie 247 morceaux, et `changedSince` égal à maintenant renvoie 0 morceau.
- [ ] Commit proposé : `Add Subsonic search endpoints with full-library paging and changedSince extension`

### Tâche 11 : `MediaHandler`
- `stream` et `download` : on extrait la logique `BinaryFileResponse` de
  `StreamController::streamAudio` dans un service `AudioFileResponder`, et
  `StreamController` l'utilise aussi, pour ne pas la dupliquer. `download` ajoute
  `attachment`. `maxBitRate` et `format` sont ignorés (D3).
- `getCoverArt` :
  - `al-…` → le BLOB du premier morceau de l'album qui en a un ;
  - `ar-…` → redirection 302 vers `ArtistProfile.imageUrl`, sinon l'album le plus récent ;
  - un nombre → le morceau.
  - `size` → redimensionné avec GD (`imagescale`) et écrit en cache dans
    `var/cache/covers/{id}-{size}.jpg`, avec `ETag` et `max-age=604800`.
  - Sans image : le SVG de remplacement de `StreamController`, déplacé lui aussi
    dans le service.
- `getLyricsBySongId` : un texte LRC (`[mm:ss.xx]`) donne `synced: true` et des
  `line[{start (ms), value}]`, sinon on renvoie le texte brut. `getLyrics?artist=&title=`
  fait la même chose.
- [ ] `curl -r 0-99 …/rest/stream?id=N -o /dev/null -w '%{http_code}'` renvoie `206`.
- [ ] `getCoverArt?id=al-…&size=300` renvoie un JPEG de 300 px, et le second appel est servi depuis le cache.
- [ ] Le lecteur du site web marche toujours (non-régression de `/stream`).
- [ ] Commit proposé : `Add Subsonic media endpoints (stream, download, cover art with resize cache, lyrics); share audio responder`

### Tâche 12 : `AnnotationHandler`
- `star` et `unstar` acceptent `id…` (vers `Favorite`, avec `sortOrder` via
  `FavoriteRepository`), `albumId…` (vers `StarredAlbum`) et `artistId…` (vers
  `StarredArtist`). Les répétitions sont acceptées, et l'opération est idempotente.
- `setRating` : `rating` de 0 à 5, avec 0 qui donne `null`. Hors bornes : erreur 10.
- `scrobble` : avec `submission=false`, rien ne se passe. Avec `true`, on fait
  comme `PlaybackController::record`, avec `playedAt = time` (en ms) ou maintenant.
  **Anti-doublon** : on ignore si une ligne `History` existe déjà pour ce morceau
  à cette seconde-là. Le code commun est extrait dans `PlayRecorder`, utilisé aussi
  par `PlaybackController`.
- [ ] `curl` : une étoile mise sur un morceau apparaît dans `/favorites` sur le site. Le même scrobble envoyé deux fois ne compte qu'une écoute.
- [ ] Commit proposé : `Add Subsonic annotation endpoints (star, unstar, rating, scrobble with offline time and dedup)`

### Tâche 13 : `PlaylistHandler` (dont `pl-offline`)
- `getPlaylists` : les playlists de l'utilisateur (`pl-<id>`) plus `pl-offline`
  (« Hors ligne », `readonly: false`).
- `getPlaylist` : les `PlaylistSong` triés par `sortOrder`, ou les `OfflineSong`.
- `createPlaylist` : `name` et `songId…`. Avec `playlistId`, la playlist est
  remplacée. Le `publicId` vaut `bin2hex(random_bytes(8))`, comme dans `PlaylistController`.
- `updatePlaylist` : `name`, `comment` (va dans description), `public` (inverse
  de isPrivate), `songIdToAdd…` et `songIndexToRemove…`. Les index sont supprimés
  **du plus grand au plus petit**. Sur `pl-offline`, ce sont des `OfflineSong`
  (avec `nextSortOrder`).
- `deletePlaylist` : passe par `PlaylistRepository::remove()`. Sur `pl-offline` : erreur 50.
- [ ] `curl` : ajouter 3 morceaux à `pl-offline` les fait apparaître sur la page `/offline` du site. Retirer l'index 1 enlève le bon morceau.
- [ ] Commit proposé : `Add Subsonic playlist endpoints, exposing the offline list as playlist pl-offline`

### Tâche 14 : `PlayQueueHandler`
- Entité `PlayQueue` avec une seule ligne (`id=1`) : `songIds` (json), `current`
  (?int), `position` (ms), `changedAt`, `changedBy`. Une migration `CREATE TABLE play_queue`.
- `savePlayQueue` prend `id…`, `current` et `position`. `getPlayQueue` renvoie
  `playQueue{entry[], current, position, changed, changedBy}`, ou une réponse
  vide s'il n'y a rien.
- [ ] L'utilisateur lance la migration. `curl` : ce qu'on enregistre avec save se relit avec get.
- [ ] Commit proposé : `Add Subsonic play queue sync (save/get) for cross-device resume`

### Tâche 15 : `StubHandler` (pour Tempo)
- `getShares` → `shares: {}`, `getInternetRadioStations` → `internetRadioStations: {}`,
  `getPodcasts` → `podcasts: {}`, `getNewestPodcasts` → `newestPodcasts: {}`,
  `getNowPlaying` → `nowPlaying: {}`, `startScan` → `scanStatus{scanning:false, count}`.
- [ ] Commit proposé : `Add empty Subsonic stubs (shares, radios, podcasts, now playing, startScan) for Tempo compatibility`

### Tâche 16 : test de fumée et validation avec de vrais clients
- `docs/subsonic-smoke.sh BASE_URL [user:pass]` appelle chaque méthode en JSON
  et vérifie `"status":"ok"` avec `grep`. La sortie est une ligne `OK`/`FAIL` par
  méthode, et le code de sortie vaut le nombre d'échecs.
- [ ] `bash docs/subsonic-smoke.sh http://localhost:8000` : tout est `OK`.
- [ ] **Tempo** (en local, sur le même Wi-Fi) : accueil, bibliothèque, album, artiste, recherche, lecture avec avance, étoile, note, file, **sans aucune erreur à l'écran**.
- [ ] **Symfonium** (en local, puis sur le Pi derrière Basic Auth) : synchro complète, nombre de morceaux, albums et artistes égal à celui du site, ≈ 572 appels `getCoverArt` (et pas 2 247), la playlist `Hors ligne` en téléchargement automatique, et des écoutes faites en mode avion qui remontent.
- [ ] `vendor/bin/phpstan analyse` : aucune nouvelle erreur.
- [ ] Commit proposé : `Add Subsonic smoke test script; validated with Tempo and Symfonium`

---

## Ordre et dépendances
1 → 2 → 3 (phase 1), puis 4 → 5 → 6 → 7, puis 8 à 15 dans n'importe quel ordre
(ils ne dépendent que des tâches 4 à 7), puis 16.
