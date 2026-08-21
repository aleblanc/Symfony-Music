# Rhythm Game — Plan d'implémentation (Feature 20)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Porter le jeu de rythme du monolithe legacy vers l'app Symfony, en deux phases : **Phase 1** = réplique fidèle « tel que c'était » (génération de charts pseudo-aléatoire déterministe, aucune analyse audio réelle) ; **Phase 2** = vraie prise en compte des beats/rythme des morceaux (détection d'onsets) pour caler les notes sur la musique.

**Architecture :** 3 entités Doctrine (`RhythmChart`, `RhythmScore`, `RhythmFavorite`) + colonne `User.rhythmStrikes` ; un service pur `RhythmChartGenerator` (port verbatim des fonctions PHP legacy, sortie déterministe reproductible) ; un `RhythmController` exposant une API JSON (exception justifiée à la règle « pas de REST par défaut » : boucle de jeu temps réel pilotée par JS) ; un front vanilla (`assets/rhythm.js` + `assets/styles/rhythm.css` + templates `rhythm/`) copié depuis le dossier de référence et re-câblé sur les routes Symfony. Phase 2 ajoute `music.bpm` / `music.beat_grid_json`, un service `BeatAnalyzer` (binaire externe via Process) et une génération de chart calée sur les beats, avec repli automatique sur la Phase 1 quand aucun beat grid n'existe.

**Tech Stack :** PHP 8 / Symfony 8.1, Doctrine ORM + migrations (SQLite `var/library/music.db`), Twig, AssetMapper (importmap, **aucun build**), JS vanilla. Phase 2 : binaire `aubio` (aubioonset/aubiotrack) invoqué via `symfony/process`.

**Sources de vérité (lecture seule) :** tout est déjà extrait dans `docs/rhythm-game-reference/` :
- `README.md` — spéc fonctionnelle + tableau des difficultés + pistes de portage.
- `chart-generation-functions.php` — `generatePhpChart()` + `calculatePhpLevel()` (à porter **verbatim**).
- `backend-dispatcher-cases.php` — les 14 handlers d'API JSON.
- `db-schema.sql` — schéma des 3 tables + `users.rhythm_strikes`.
- `markup.html`, `style.css`, `frontend-game-engine.js` — front à copier/adapter.

---

## Décisions de scope (trims conscients, pas des oublis)

Alignées sur `docs/PLAN_MIGRATION.md` §0bis (panneau admin exclu, social follow/block hors scope) :

| Handler legacy | Décision Phase 1 | Raison |
|---|---|---|
| `save_rhythm_score` | **Porté** (anti-triche 3 couches + 3-strike ban) | Cœur du scoring |
| `get_rhythm_leaderboard`, `get_song_rhythm_leaderboard` | **Portés** | Cœur, ne dépendent pas du social |
| `get_rhythm_chart`, `get_rhythm_levels`, `get_all_rhythm_levels` | **Portés** (génération lazy + cache) | Cœur gameplay |
| `get_user_rhythm_scores` | **Porté** | Historique joueur |
| `toggle_rhythm_favorite`, `get_rhythm_favorites`, `get_rhythm_favorite_songs` | **Portés** | Favoris rhythm-game |
| `get_rhythm_artists`, `get_rhythm_artists_stats` | **Trim** (on garde une version minimale sans colonnes `followers`/`following`) ou drop de l'onglet « Artists » | Dépendent de la table `follows` (social, hors scope) |
| `save_rhythm_chart` (override manuel) | **Différé** → deviendra une commande console propriétaire en Phase 2 | Admin exclu |
| `reset_rhythm_charts`, `rescan_charts` (bulk) | **Non porté** en Phase 1 (génération lazy suffit au gameplay) ; réintroduit comme commande console en Phase 2 | Admin exclu |
| `vacuum_database` | **Non porté** | Maintenance DB sans rapport avec le jeu |
| écritures `admin_logs` | **Non portées** (on incrémente juste `rhythmStrikes` + `banned`) | Admin exclu |
| Onglets front « Following » / « Playlists » de la fiche joueur | **Retirés** du template | Social hors scope |

**Contrôle d'accès :** les GET (hub, chart, leaderboard, levels) sont publics ; les POST (`score`, `favorite`) exigent `ROLE_USER` (`#[IsGranted('ROLE_USER')]` au niveau méthode). Aucune entrée `access_control` globale à ajouter (les chemins non listés dans `config/packages/security.yaml` sont accessibles par défaut).

**Fidélité de la génération :** le port du générateur est **PHP → PHP** : copier l'arithmétique **à l'identique** (ne rien « corriger »), avec casts entiers explicites là où le legacy s'appuyait sur la coercition de `%` (ex. `((int)$t) % 20`). La détermination du seed (`crc32` + `hexdec(substr(md5()))`) et le xorshift donnent une sortie identique au legacy sur la même version de PHP — vérifié par un golden-master (Task 1.12).

---

## Structure des fichiers

**Phase 1 — créés :**
- `src/Entity/RhythmChart.php` — chart caché (PK composite song+difficulty).
- `src/Entity/RhythmScore.php` — un run de score (id surrogate).
- `src/Entity/RhythmFavorite.php` — favori rhythm-game (PK composite user+song, sortOrder).
- `src/Repository/RhythmChartRepository.php`
- `src/Repository/RhythmScoreRepository.php`
- `src/Repository/RhythmFavoriteRepository.php`
- `src/Service/RhythmChartGenerator.php` — port verbatim (logique pure).
- `src/Service/RhythmAntiCheat.php` — 3 couches anti-triche (logique pure).
- `src/Controller/RhythmController.php` — API JSON + page hub.
- `templates/rhythm/hub.html.twig` — hub + écran de jeu + modales (depuis `markup.html`, trimmé).
- `assets/rhythm.js` — moteur de jeu (depuis `frontend-game-engine.js`, re-câblé).
- `assets/styles/rhythm.css` — depuis `style.css`.
- `migrations/VersionXXXXXXXXXXXXXX.php` — 3 tables + `users.rhythm_strikes`.

**Phase 1 — modifiés :**
- `src/Entity/User.php` — ajout `rhythmStrikes`.
- `assets/app.js` — import conditionnel de `rhythm.js` + `rhythm.css`.
- `importmap.php` — entrée `rhythm.js` si nécessaire.
- `templates/base.html.twig` — un lien « Rhythm » dans la sidebar (⚠️ fichier aussi touché par le travail « persistance du player » en cours en parallèle : faire cet ajout en dernier et minimal — voir Task 1.15).

**Phase 2 — créés/modifiés :** `src/Service/BeatAnalyzer.php`, `src/Command/AnalyzeBeatsCommand.php`, `src/Command/RegenerateChartsCommand.php`, extension de `RhythmChartGenerator`, migration `music.bpm` + `music.beat_grid_json` + `rhythm_charts.generator_version`.

---

## Convention de vérification (ce projet n'a NI PHPUnit NI PHPStan installés)

Le projet vérifie chaque feature par (cf. `docs/PLAN_MIGRATION.md` §3) :
1. `php bin/console cache:clear`
2. `php bin/console debug:router | grep rhythm` (les routes existent)
3. Lancer le serveur de test : `php -S 127.0.0.1:8000 -t public public/index.php` (arrière-plan)
4. `curl` bout-en-bout sur chaque route (statut + JSON attendu)

**Interdits (CLAUDE.md) :** ne jamais lancer `docker compose`, ni `doctrine:schema:validate` (même `--skip-sync`). Chaque commande shell dans une invocation **séparée** (pas de `;`/`&&`/`||`, pas de `<(...)`).

Pour le générateur (logique pure critique pour la fidélité), on ajoute un **golden-master** autonome exécuté avec `php` (Task 1.12), sans framework de test : il compare la sortie du service Symfony à celle des fonctions legacy de référence pour des tuples fixes.

---

# PHASE 1 — Réplique fidèle (charts pseudo-aléatoires déterministes)

### Task 1.1 : Entité `RhythmChart`

**Files:**
- Create: `src/Entity/RhythmChart.php`

- [ ] **Step 1 : Écrire l'entité**

```php
<?php

namespace App\Entity;

use App\Repository\RhythmChartRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A cached, generated note chart for one (song, difficulty) pair. Maps onto
 * the legacy `rhythm_charts` table (composite PK song_id+difficulty, no
 * surrogate id). `notesJson` is a JSON array of note objects with the shape
 * {time, lane, isLong?, endTime?, isSwipe?, hitEndSwipe?} produced by
 * {@see \App\Service\RhythmChartGenerator}. `level` is the computed 1..999
 * difficulty rating shown before play.
 */
#[ORM\Entity(repositoryClass: RhythmChartRepository::class)]
#[ORM\Table(name: 'rhythm_charts')]
class RhythmChart
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Id]
    #[ORM\Column(name: 'difficulty', type: 'string', length: 16)]
    private string $difficulty = 'medium';

    #[ORM\Column(name: 'notes_json', type: 'text', nullable: true)]
    private ?string $notesJson = null;

    #[ORM\Column(type: 'integer', options: ['default' => 1])]
    private int $level = 1;

    public function getSong(): ?Music
    {
        return $this->song;
    }

    public function setSong(?Music $song): static
    {
        $this->song = $song;

        return $this;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    public function setDifficulty(string $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getNotesJson(): ?string
    {
        return $this->notesJson;
    }

    public function setNotesJson(?string $notesJson): static
    {
        $this->notesJson = $notesJson;

        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;

        return $this;
    }
}
```

- [ ] **Step 2 : Commit** — texte proposé :
```
Add RhythmChart entity (composite song+difficulty PK)

ref #noref
```

---

### Task 1.2 : Entité `RhythmScore`

**Files:**
- Create: `src/Entity/RhythmScore.php`

- [ ] **Step 1 : Écrire l'entité** (surrogate id, patron identique à {@see History})

```php
<?php

namespace App\Entity;

use App\Repository\RhythmScoreRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One recorded rhythm-game run. Maps onto the legacy `rhythm_scores` table
 * (surrogate id + per-run judgment counters + difficulty). Append-only,
 * same lifecycle as {@see History}.
 */
#[ORM\Entity(repositoryClass: RhythmScoreRepository::class)]
#[ORM\Table(name: 'rhythm_scores')]
#[ORM\Index(name: 'rhythm_scores_user_idx', columns: ['user_id'])]
#[ORM\Index(name: 'rhythm_scores_song_idx', columns: ['song_id'])]
class RhythmScore
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $score = 0;

    #[ORM\Column(name: 'max_combo', type: 'integer', options: ['default' => 0])]
    private int $maxCombo = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $perfect = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $great = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $good = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $bad = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $miss = 0;

    #[ORM\Column(type: 'string', length: 16, options: ['default' => 'medium'])]
    private string $difficulty = 'medium';

    /** Legacy stores this as a TEXT timestamp (see {@see History}). */
    #[ORM\Column(name: 'played_at', type: 'string', nullable: true)]
    private ?string $playedAt = null;

    public function __construct()
    {
        $this->playedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSong(): ?Music
    {
        return $this->song;
    }

    public function setSong(?Music $song): static
    {
        $this->song = $song;

        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): static
    {
        $this->score = $score;

        return $this;
    }

    public function getMaxCombo(): int
    {
        return $this->maxCombo;
    }

    public function setMaxCombo(int $maxCombo): static
    {
        $this->maxCombo = $maxCombo;

        return $this;
    }

    public function getPerfect(): int
    {
        return $this->perfect;
    }

    public function setPerfect(int $perfect): static
    {
        $this->perfect = $perfect;

        return $this;
    }

    public function getGreat(): int
    {
        return $this->great;
    }

    public function setGreat(int $great): static
    {
        $this->great = $great;

        return $this;
    }

    public function getGood(): int
    {
        return $this->good;
    }

    public function setGood(int $good): static
    {
        $this->good = $good;

        return $this;
    }

    public function getBad(): int
    {
        return $this->bad;
    }

    public function setBad(int $bad): static
    {
        $this->bad = $bad;

        return $this;
    }

    public function getMiss(): int
    {
        return $this->miss;
    }

    public function setMiss(int $miss): static
    {
        $this->miss = $miss;

        return $this;
    }

    public function getDifficulty(): string
    {
        return $this->difficulty;
    }

    public function setDifficulty(string $difficulty): static
    {
        $this->difficulty = $difficulty;

        return $this;
    }

    public function getPlayedAt(): ?string
    {
        return $this->playedAt;
    }
}
```

- [ ] **Step 2 : Commit** — `Add RhythmScore entity (per-run judgment counters)\n\nref #noref`

---

### Task 1.3 : Entité `RhythmFavorite`

**Files:**
- Create: `src/Entity/RhythmFavorite.php`

- [ ] **Step 1 : Écrire l'entité** (patron identique à {@see ListenLater})

```php
<?php

namespace App\Entity;

use App\Repository\RhythmFavoriteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A rhythm-game-specific favorite, distinct from the general {@see Favorite}
 * list. Maps onto the legacy `rhythm_favorites` table (composite user+song
 * PK + manual `sort_order`), structurally identical to {@see ListenLater}.
 */
#[ORM\Entity(repositoryClass: RhythmFavoriteRepository::class)]
#[ORM\Table(name: 'rhythm_favorites')]
class RhythmFavorite
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Music::class)]
    #[ORM\JoinColumn(name: 'song_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Music $song = null;

    #[ORM\Column(name: 'sort_order', nullable: true)]
    private ?int $sortOrder = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSong(): ?Music
    {
        return $this->song;
    }

    public function setSong(?Music $song): static
    {
        $this->song = $song;

        return $this;
    }

    public function getSortOrder(): ?int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(?int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }
}
```

- [ ] **Step 2 : Commit** — `Add RhythmFavorite entity (rhythm-specific favorites)\n\nref #noref`

---

### Task 1.4 : Colonne `User.rhythmStrikes`

**Files:**
- Modify: `src/Entity/User.php`

- [ ] **Step 1 : Ajouter la propriété** (après `private bool $banned = false;`, ligne ~46)

```php
    /**
     * Rhythm-game anti-cheat strike counter (see {@see \App\Service\RhythmAntiCheat}).
     * 1st/2nd strike = rhythm-game ban only; 3rd strike flips `banned` (full
     * account ban). Ported from the legacy `users.rhythm_strikes` column.
     */
    #[ORM\Column(name: 'rhythm_strikes', type: 'integer', options: ['default' => 0])]
    private int $rhythmStrikes = 0;
```

- [ ] **Step 2 : Ajouter les accesseurs** (à la fin de la classe, avant `eraseCredentials`)

```php
    public function getRhythmStrikes(): int
    {
        return $this->rhythmStrikes;
    }

    public function setRhythmStrikes(int $rhythmStrikes): static
    {
        $this->rhythmStrikes = $rhythmStrikes;

        return $this;
    }
```

- [ ] **Step 3 : Commit** — `Add rhythmStrikes column to User\n\nref #noref`

---

### Task 1.5 : Migration (3 tables + `users.rhythm_strikes`)

**Files:**
- Create: `migrations/VersionXXXXXXXXXXXXXX.php` (généré)

- [ ] **Step 1 : Générer la migration**

Run: `php bin/console doctrine:migrations:diff --no-interaction`
Expected : un nouveau fichier `migrations/Version*.php` créé, contenant `CREATE TABLE rhythm_charts`, `CREATE TABLE rhythm_scores`, `CREATE TABLE rhythm_favorites` et `ALTER TABLE users ADD COLUMN rhythm_strikes`.

- [ ] **Step 2 : Relire la migration générée** et vérifier : PK composite sur `rhythm_charts (song_id, difficulty)` et `rhythm_favorites (user_id, song_id)`, `rhythm_scores.id` autoincrement, FKs `ON DELETE CASCADE`. Corriger à la main si le diff a raté une PK composite (SQLite + Doctrine peut nécessiter un ajustement).

- [ ] **Step 3 : Appliquer**

Run: `php bin/console doctrine:migrations:migrate --no-interaction`
Expected : `migrated` sans erreur.

- [ ] **Step 4 : Vérifier le schéma sans `schema:validate` (interdit)** — lister les tables :

Run: `php bin/console dbal:run-sql "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'rhythm_%'"`
Expected : `rhythm_charts`, `rhythm_scores`, `rhythm_favorites`.

- [ ] **Step 5 : Commit** — `Add rhythm game tables migration\n\nref #noref`

---

### Task 1.6 : `RhythmChartGenerator` (port VERBATIM — cœur)

**Files:**
- Create: `src/Service/RhythmChartGenerator.php`

Port direct de `docs/rhythm-game-reference/chart-generation-functions.php`. **Conserver l'arithmétique à l'identique.** Les seuls changements autorisés : casts entiers explicites pour `%` (le legacy s'appuyait sur la coercition implicite) et encapsulation en méthodes.

- [ ] **Step 1 : Écrire le service**

```php
<?php

namespace App\Service;

/**
 * Deterministic pseudo-random rhythm chart generator. Direct verbatim port of
 * the legacy `generatePhpChart()` / `calculatePhpLevel()` (see
 * docs/rhythm-game-reference/chart-generation-functions.php). Pure logic, no
 * I/O: given the same (songId, duration, difficulty, density) it always
 * returns the same notes/level (xorshift RNG seeded from the inputs). It does
 * NOT analyse audio — the only audio-derived input is `duration`. Real
 * beat/onset syncing is Phase 2. Arithmetic is intentionally identical to the
 * legacy; explicit int casts replace PHP's implicit `%` coercion so output
 * stays byte-for-byte identical.
 */
final class RhythmChartGenerator
{
    /**
     * @return list<array{time: float, lane: int, isLong?: bool, endTime?: float, isSwipe?: bool, hitEndSwipe?: bool}>
     */
    public function generate(int $songId, int $duration, string $difficulty, float $densityMultiplier = 1.0): array
    {
        // 1. Deterministic seed from song id + difficulty + duration.
        $seedString = $songId . '_' . $difficulty . '_' . $duration;
        $seed = crc32($seedString) + hexdec(substr(md5($seedString), 0, 8));

        // 2. Xorshift RNG (float 0.0..1.0).
        $rng = static function () use (&$seed): float {
            $seed ^= $seed << 13;
            $seed ^= $seed >> 17;
            $seed ^= $seed << 5;

            return abs($seed % 1000000) / 1000000;
        };

        $lanesCount = ($difficulty === 'easy' || $difficulty === 'medium') ? 4 : ($difficulty === 'demon' ? 10 : 6);

        // 3. Base spacing per difficulty tier.
        $baseSpacing = 0.500;
        if ($difficulty === 'easy') {
            $baseSpacing = 0.850;
        } elseif ($difficulty === 'medium') {
            $baseSpacing = 0.500;
        } elseif ($difficulty === 'hard') {
            $baseSpacing = 0.320;
        } elseif ($difficulty === 'expert') {
            $baseSpacing = 0.200;
        } elseif ($difficulty === 'master') {
            $baseSpacing = 0.140;
        } elseif ($difficulty === 'demon') {
            $baseSpacing = 0.025;
        }

        $spacing = $baseSpacing / max(0.1, min(10.0, $densityMultiplier));

        $notes = [];
        $totalTime = $duration ?: 180;

        // 4. Mechanic probabilities per difficulty tier.
        $chordChance = 0.0;
        $tripleChordChance = 0.0;
        if ($difficulty === 'hard') {
            $chordChance = 0.18;
        } elseif ($difficulty === 'expert') {
            $chordChance = 0.35;
            $tripleChordChance = 0.05;
        } elseif ($difficulty === 'master') {
            $chordChance = 0.55;
            $tripleChordChance = 0.15;
        } elseif ($difficulty === 'demon') {
            $chordChance = 0.75;
            $tripleChordChance = 0.30;
        }

        $swipeChance = ($difficulty === 'easy') ? 0.0 : (($difficulty === 'medium') ? 0.08 : 0.25);
        $longChance = ($difficulty === 'demon') ? 0.10 : 0.20;

        $laneBusyUntil = array_fill(0, $lanesCount, 0.0);
        $restrictHolds = in_array($difficulty, ['easy', 'medium', 'hard'], true);
        $anyHoldUntil = 0.0;

        $isDrop = false;
        $currentStep = $spacing;

        // 5. Generation loop with simulated musical phrasing.
        for ($t = 1.0; $t < $totalTime - 2.0; $t += $currentStep) {
            // Simulate drops (coin-flip every ~20s) — NOT real chorus detection.
            if (((int) $t) % 20 < 1) {
                $isDrop = ($rng() > 0.5);
            }
            $currentStep = $isDrop ? ($spacing * 0.8) : $spacing;

            if ($difficulty === 'demon' || $difficulty === 'master') {
                if ($rng() > 0.85) {
                    $currentStep *= 0.5; // Double-time burst.
                }
                if ($rng() > 0.96) {
                    $currentStep *= 1.5; // Rest / syncopation break.
                }
            }

            if ($restrictHolds && $t < $anyHoldUntil) {
                continue;
            }

            $noteCount = 1;
            if ($rng() < $chordChance) {
                $noteCount = 2;
            }
            if ($rng() < $tripleChordChance && $lanesCount === 6) {
                $noteCount = 3;
            }

            $allowLong = !($restrictHolds && $noteCount > 1);
            $usedLanes = [];

            for ($i = 0; $i < $noteCount; ++$i) {
                $lane = ((int) floor($rng() * $lanesCount)) % $lanesCount;
                $attempts = 0;

                while ((in_array($lane, $usedLanes, true) || $t < $laneBusyUntil[$lane]) && $attempts < $lanesCount * 2) {
                    $lane = ($lane + ($rng() > 0.5 ? 1 : -1) + $lanesCount) % $lanesCount;
                    ++$attempts;
                }
                if ($attempts >= $lanesCount * 2) {
                    continue;
                }

                $usedLanes[] = $lane;

                $isLong = $allowLong && ($rng() < $longChance);
                $isSwipe = !$isLong && ($rng() < $swipeChance);
                $hitEndSwipe = $isLong && ($rng() < $swipeChance);

                $dur = 0.0;
                if ($isLong) {
                    $durBase = 0.2 + ($rng() * 0.6);
                    $dur = round($durBase / $spacing) * $spacing;
                    if ($dur < 0.2) {
                        $dur = 0.2;
                    }
                }

                $laneBusyUntil[$lane] = $t + $dur + 0.05; // 50ms anti-overlap buffer.
                if ($isLong) {
                    $anyHoldUntil = max($anyHoldUntil, $t + $dur);
                }

                $note = [
                    'time' => round($t + 2.0, 3),
                    'lane' => (int) $lane,
                ];
                if ($isLong) {
                    $note['isLong'] = true;
                    $note['endTime'] = round($t + 2.0 + $dur, 3);
                    if ($hitEndSwipe) {
                        $note['hitEndSwipe'] = true;
                    }
                } elseif ($isSwipe) {
                    $note['isSwipe'] = true;
                }
                $notes[] = $note;
            }
        }

        return $notes;
    }

    /**
     * @param list<array<string, mixed>> $notes
     */
    public function level(array $notes, int $duration, string $difficulty = 'medium', float $densityMultiplier = 1.0): int
    {
        if ($duration <= 0 || $notes === []) {
            return 1;
        }

        $totalNotes = count($notes);
        $nps = ($totalNotes / $duration) / max(0.1, min(10.0, $densityMultiplier));

        // 1. Peak NPS over a rolling 5s window.
        $peakNps = 0.0;
        $windowSize = 5.0;
        for ($i = 0; $i < $duration; $i += 1.0) {
            $windowNotes = 0;
            foreach ($notes as $n) {
                if ($n['time'] >= $i && $n['time'] < $i + $windowSize) {
                    ++$windowNotes;
                }
                if ($n['time'] >= $i + $windowSize) {
                    break;
                }
            }
            $currentWindowNps = $windowNotes / $windowSize;
            if ($currentWindowNps > $peakNps) {
                $peakNps = $currentWindowNps;
            }
        }

        // 2. Pattern complexity.
        $chords = 0;
        $swipes = 0;
        $holds = 0;
        $crossLaneDistance = 0;
        $lastLane = 1;
        $lastTime = 0.0;
        $timeVarianceSum = 0.0;
        $intervalCount = 0;

        foreach ($notes as $n) {
            if (!empty($n['isLong'])) {
                ++$holds;
            }
            if (!empty($n['isSwipe'])) {
                ++$swipes;
            }

            if (($n['time'] - $lastTime) < 0.05) {
                ++$chords;
            } else {
                if ($intervalCount > 0 && $nps > 0) {
                    $timeVarianceSum += ($n['time'] - $lastTime - (1 / $nps)) ** 2;
                }
                ++$intervalCount;
            }

            $crossLaneDistance += abs($n['lane'] - $lastLane);
            $lastLane = $n['lane'];
            $lastTime = $n['time'];
        }

        $chordRatio = $totalNotes > 0 ? ($chords / $totalNotes) : 0;
        $swipeRatio = $totalNotes > 0 ? ($swipes / $totalNotes) : 0;
        $holdRatio = $totalNotes > 0 ? ($holds / $totalNotes) : 0;
        $avgJump = $totalNotes > 0 ? ($crossLaneDistance / $totalNotes) : 0;
        $rhythmIrregularity = $intervalCount > 0 ? sqrt($timeVarianceSum / $intervalCount) : 0;

        // 3. Base polynomial score.
        $baseComplexity = ($nps * 1.4) + ($peakNps * 0.9) + ($avgJump * 3.0) + ($rhythmIrregularity * 12);
        $mechanicWeight = ($chordRatio * 18) + ($swipeRatio * 22) + ($holdRatio * 5);
        $rawScore = $baseComplexity + $mechanicWeight;

        // 4. Bracket + tier multipliers.
        $level = 1.0;
        switch ($difficulty) {
            case 'easy':
                $level = ($rawScore * 0.15) + 1;
                $level = max(1, min(5, $level));
                break;
            case 'medium':
                $level = ($rawScore * 0.35) + 4;
                $level = max(6, min(12, $level));
                break;
            case 'hard':
                $level = ($rawScore * 0.65) + 10;
                $level = max(13, min(24, $level));
                break;
            case 'expert':
                $level = ($rawScore * 1.1) + 22;
                $level = max(25, min(45, $level));
                break;
            case 'master':
                $level = ($rawScore * 1.8) + 40;
                $level = max(46, min(75, $level));
                break;
            case 'demon':
                $level = ($rawScore * 20.0) + 80;
                $level += ($totalNotes % 50) + ($duration % 10);
                $level = max(76, min(999, $level));
                break;
            default:
                $level = 1;
        }

        // 5. Deterministic micro-variance so identical stats still differ.
        $variance = (crc32($totalNotes . $duration) % 5) - 2;
        $finalLevel = round($level) + $variance;

        return max(1, (int) $finalLevel);
    }
}
```

- [ ] **Step 2 : Commit** — `Add RhythmChartGenerator (verbatim port of legacy chart gen)\n\nref #noref`

---

### Task 1.7 : `RhythmAntiCheat` (port des 3 couches)

**Files:**
- Create: `src/Service/RhythmAntiCheat.php`

Port de la logique de `save_rhythm_score` (`backend-dispatcher-cases.php`, lignes 21-66). Logique pure : renvoie une raison de ban ou `null`.

- [ ] **Step 1 : Écrire le service**

```php
<?php

namespace App\Service;

/**
 * Server-side rhythm-game anti-cheat. Direct port of the 3-layer validation
 * in the legacy `save_rhythm_score` handler. Pure logic: given a submitted
 * run it returns a ban reason string, or null when the run looks human.
 */
final class RhythmAntiCheat
{
    /**
     * @param list<float|int|string> $hitDeltas per-hit timing errors in ms
     *
     * @return string|null ban reason, or null if the run is accepted
     */
    public function inspect(array $hitDeltas, int $claimedScore, int $maxCombo): ?string
    {
        $totalHits = count($hitDeltas);

        if ($totalHits > 20) {
            // Layer 1: inhuman precision (>80% of hits under 5ms).
            $suspiciousHits = 0;
            $sum = 0.0;
            foreach ($hitDeltas as $delta) {
                $d = (float) $delta;
                if ($d < 5.0) {
                    ++$suspiciousHits;
                }
                $sum += $d;
            }
            if (($suspiciousHits / $totalHits) > 0.8) {
                return 'Inhuman Precision (80%+ hits under 5ms)';
            }

            // Layer 2: macro / zero-variance detection (std dev < 1.5ms).
            $mean = $sum / $totalHits;
            $varianceSum = 0.0;
            foreach ($hitDeltas as $delta) {
                $varianceSum += ((float) $delta - $mean) ** 2;
            }
            $stdDev = sqrt($varianceSum / $totalHits);
            if ($stdDev < 1.5) {
                return 'Macro Pattern Detected (StdDev < 1.5ms)';
            }
        }

        // Layer 3: mathematical bounds (score/combo spoofing).
        $theoreticalMax = $totalHits * 1000;
        if ($claimedScore > $theoreticalMax || $maxCombo > $totalHits) {
            return 'Data Spoofing (Score or Combo out of mathematical bounds)';
        }

        return null;
    }
}
```

- [ ] **Step 2 : Commit** — `Add RhythmAntiCheat (3-layer score validation port)\n\nref #noref`

---

### Task 1.8 : Repositories

**Files:**
- Create: `src/Repository/RhythmChartRepository.php`
- Create: `src/Repository/RhythmScoreRepository.php`
- Create: `src/Repository/RhythmFavoriteRepository.php`

- [ ] **Step 1 : `RhythmChartRepository`**

```php
<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\RhythmChart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RhythmChart>
 */
class RhythmChartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RhythmChart::class);
    }

    public function findOneForSong(Music $song, string $difficulty): ?RhythmChart
    {
        return $this->findOneBy(['song' => $song, 'difficulty' => $difficulty]);
    }

    /**
     * @return array<string, int> difficulty => level, for one song
     */
    public function levelsForSong(int $songId): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.difficulty AS difficulty', 'c.level AS level')
            ->andWhere('IDENTITY(c.song) = :songId')
            ->setParameter('songId', $songId)
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['difficulty']] = (int) $r['level'];
        }

        return $out;
    }

    /**
     * @param int[] $songIds
     *
     * @return array<int, array<string, int>> songId => (difficulty => level)
     */
    public function levelsForSongs(array $songIds): array
    {
        if ($songIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.song) AS song_id', 'c.difficulty AS difficulty', 'c.level AS level')
            ->andWhere('IDENTITY(c.song) IN (:ids)')
            ->setParameter('ids', $songIds)
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['song_id']][(string) $r['difficulty']] = (int) $r['level'];
        }

        return $out;
    }
}
```

- [ ] **Step 2 : `RhythmScoreRepository`**

```php
<?php

namespace App\Repository;

use App\Entity\RhythmScore;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RhythmScore>
 */
class RhythmScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RhythmScore::class);
    }

    /**
     * Global leaderboard: total score per player, paginated. Ported from the
     * legacy `get_rhythm_leaderboard` aggregate.
     *
     * @return list<array<string, mixed>>
     */
    public function globalLeaderboard(int $page, int $pageSize): array
    {
        return $this->createQueryBuilder('rs')
            ->select(
                'IDENTITY(rs.user) AS user_id',
                'u.artist AS player_name',
                'SUM(rs.score) AS total_score',
                'COUNT(rs.id) AS total_plays',
                'SUM(rs.perfect) AS total_perfect',
                'SUM(rs.great) AS total_great',
                'SUM(rs.good) AS total_good',
                'SUM(rs.bad) AS total_bad',
                'SUM(rs.miss) AS total_miss',
            )
            ->join('rs.user', 'u')
            ->groupBy('rs.user')
            ->orderBy('total_score', 'DESC')
            ->setFirstResult(($page - 1) * $pageSize)
            ->setMaxResults($pageSize)
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * Per-song leaderboard (top 25 runs). Ported from
     * `get_song_rhythm_leaderboard`.
     *
     * @return list<array<string, mixed>>
     */
    public function songLeaderboard(int $songId): array
    {
        return $this->createQueryBuilder('rs')
            ->select(
                'rs.score AS score',
                'rs.maxCombo AS max_combo',
                'rs.perfect AS perfect',
                'rs.great AS great',
                'rs.good AS good',
                'rs.bad AS bad',
                'rs.miss AS miss',
                'u.artist AS player_name',
                'u.id AS user_id',
            )
            ->join('rs.user', 'u')
            ->andWhere('IDENTITY(rs.song) = :songId')
            ->setParameter('songId', $songId)
            ->orderBy('rs.score', 'DESC')
            ->addOrderBy('rs.maxCombo', 'DESC')
            ->addOrderBy('rs.perfect', 'DESC')
            ->setMaxResults(25)
            ->getQuery()
            ->getScalarResult();
    }

    /**
     * A player's runs, newest first. Ported from `get_user_rhythm_scores`.
     *
     * @return RhythmScore[]
     */
    public function forUser(User $user, ?int $page = null, int $pageSize = 25): array
    {
        $qb = $this->createQueryBuilder('rs')
            ->andWhere('rs.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rs.playedAt', 'DESC');

        if ($page !== null) {
            $qb->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize);
        }

        return $qb->getQuery()->getResult();
    }
}
```

- [ ] **Step 3 : `RhythmFavoriteRepository`** (copie fidèle des méthodes utiles de {@see ListenLaterRepository})

```php
<?php

namespace App\Repository;

use App\Entity\Music;
use App\Entity\RhythmFavorite;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RhythmFavorite>
 */
class RhythmFavoriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RhythmFavorite::class);
    }

    public function findOneForUser(User $user, Music $song): ?RhythmFavorite
    {
        return $this->findOneBy(['user' => $user, 'song' => $song]);
    }

    /**
     * @return RhythmFavorite[] ordered by manual sort order (legacy default)
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('rf')
            ->andWhere('rf.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rf.sortOrder', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return int[] song ids the user favorited for the rhythm game
     */
    public function songIdsForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('rf')
            ->select('IDENTITY(rf.song) AS song_id')
            ->andWhere('rf.user = :user')
            ->setParameter('user', $user)
            ->orderBy('rf.sortOrder', 'DESC')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): int => (int) $r['song_id'], $rows);
    }

    public function nextSortOrder(User $user): int
    {
        $max = $this->createQueryBuilder('rf')
            ->select('MAX(rf.sortOrder)')
            ->andWhere('rf.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return ((int) $max) + 1;
    }
}
```

- [ ] **Step 4 : Commit** — `Add rhythm repositories (chart, score, favorite)\n\nref #noref`

---

### Task 1.9 : `RhythmController` — API JSON + page hub

**Files:**
- Create: `src/Controller/RhythmController.php`

Mapping endpoints legacy → routes Symfony (à répercuter dans `assets/rhythm.js`, Task 1.13) :

| Legacy `?action=` | Route Symfony | Nom | Méthode |
|---|---|---|---|
| (page) | `/rhythm` | `app_rhythm` | GET |
| `get_rhythm_chart` | `/rhythm/chart/{songId}/{difficulty}` | `app_rhythm_chart` | GET |
| `get_rhythm_levels` | `/rhythm/levels/{songId}` | `app_rhythm_levels` | GET |
| `get_all_rhythm_levels` | `/rhythm/levels` | `app_rhythm_all_levels` | GET |
| `save_rhythm_score` | `/rhythm/score` | `app_rhythm_score` | POST |
| `get_rhythm_leaderboard` | `/rhythm/leaderboard` | `app_rhythm_leaderboard` | GET |
| `get_song_rhythm_leaderboard` | `/rhythm/leaderboard/song/{songId}` | `app_rhythm_song_leaderboard` | GET |
| `get_user_rhythm_scores` | `/rhythm/scores/{userId}` | `app_rhythm_user_scores` | GET |
| `toggle_rhythm_favorite` | `/rhythm/favorite/{songId}` | `app_rhythm_favorite_toggle` | POST |
| `get_rhythm_favorites` | `/rhythm/favorites` | `app_rhythm_favorites` | GET |
| `get_rhythm_favorite_songs` | `/rhythm/favorite-songs` | `app_rhythm_favorite_songs` | GET |
| (hub browse) | `/rhythm/songs` | `app_rhythm_songs` | GET |

- [ ] **Step 1 : Écrire le contrôleur** (constante `PAGE_SIZE = 30` ; CSRF header `X-CSRF-TOKEN` comme {@see ListenLaterController})

```php
<?php

namespace App\Controller;

use App\Entity\RhythmFavorite;
use App\Entity\RhythmScore;
use App\Entity\User;
use App\Repository\MusicRepository;
use App\Repository\RhythmChartRepository;
use App\Repository\RhythmFavoriteRepository;
use App\Repository\RhythmScoreRepository;
use App\Repository\UserRepository;
use App\Service\RhythmAntiCheat;
use App\Service\RhythmChartGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rhythm game (Feature 20). Unlike the rest of the port (classic Twig CRUD),
 * this controller is deliberately a JSON API: the gameplay loop is a
 * real-time JS engine that must fetch charts/scores/leaderboards without page
 * reloads. Chart generation is the deterministic pseudo-random Phase 1 port;
 * real beat syncing is Phase 2. See docs/rhythm-game-reference/.
 */
class RhythmController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'rhythm';
    private const PAGE_SIZE = 30;
    private const VALID_DIFFICULTIES = ['easy', 'medium', 'hard', 'expert', 'master', 'demon'];

    public function __construct(
        private readonly RhythmChartRepository $charts,
        private readonly RhythmScoreRepository $scores,
        private readonly RhythmFavoriteRepository $favorites,
        private readonly MusicRepository $music,
        private readonly UserRepository $users,
        private readonly RhythmChartGenerator $generator,
        private readonly RhythmAntiCheat $antiCheat,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/rhythm', name: 'app_rhythm')]
    public function hub(): Response
    {
        return $this->render('rhythm/hub.html.twig');
    }

    #[Route('/rhythm/songs', name: 'app_rhythm_songs', methods: ['GET'])]
    public function songs(Request $request, UrlGeneratorInterface $urls): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $songs = $this->music->findBy([], ['id' => 'DESC'], self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        $ids = array_map(static fn ($s) => $s->getId(), $songs);
        $levels = $this->charts->levelsForSongs($ids);

        $out = [];
        foreach ($songs as $s) {
            $out[] = [
                'id' => $s->getId(),
                'title' => $s->getTitle(),
                'artist' => $s->getArtist(),
                'album' => $s->getAlbum(),
                'duration' => $s->getDuration(),
                'stream' => $urls->generate('app_stream', ['id' => $s->getId()]),
                'cover' => $urls->generate('app_cover', ['id' => $s->getId()]),
                'levels' => $levels[$s->getId()] ?? null,
            ];
        }

        return new JsonResponse($out);
    }

    #[Route('/rhythm/chart/{songId}/{difficulty}', name: 'app_rhythm_chart', methods: ['GET'], requirements: ['songId' => '\d+'])]
    public function chart(int $songId, string $difficulty): JsonResponse
    {
        if (!in_array($difficulty, self::VALID_DIFFICULTIES, true)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid difficulty'], Response::HTTP_BAD_REQUEST);
        }

        $song = $this->music->find($songId);
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Song not found'], Response::HTTP_NOT_FOUND);
        }

        $chart = $this->charts->findOneForSong($song, $difficulty);
        $notes = $chart?->getNotesJson() !== null ? json_decode((string) $chart->getNotesJson(), true) : null;

        if ($chart !== null && is_array($notes) && $notes !== []) {
            return new JsonResponse(['status' => 'success', 'found' => true, 'notes' => $notes, 'level' => $chart->getLevel()]);
        }

        // Lazy generate + cache (Phase 1: pseudo-random, 1.0x density).
        $duration = $song->getDuration() ?: 180;
        $notes = $this->generator->generate($songId, $duration, $difficulty, 1.0);
        $level = $this->generator->level($notes, $duration, $difficulty, 1.0);

        $chart ??= (new \App\Entity\RhythmChart())->setSong($song)->setDifficulty($difficulty);
        $chart->setNotesJson(json_encode($notes));
        $chart->setLevel($level);
        $this->em->persist($chart);
        $this->em->flush();

        return new JsonResponse(['status' => 'success', 'found' => true, 'notes' => $notes, 'level' => $level]);
    }

    #[Route('/rhythm/levels/{songId}', name: 'app_rhythm_levels', methods: ['GET'], requirements: ['songId' => '\d+'])]
    public function levels(int $songId): JsonResponse
    {
        return new JsonResponse(['status' => 'success', 'levels' => $this->charts->levelsForSong($songId)]);
    }

    #[Route('/rhythm/levels', name: 'app_rhythm_all_levels', methods: ['GET'])]
    public function allLevels(): JsonResponse
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(c.song) AS song_id, c.difficulty AS difficulty, c.level AS level FROM App\Entity\RhythmChart c'
        )->getScalarResult();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['song_id']][(string) $r['difficulty']] = (int) $r['level'];
        }

        return new JsonResponse(['status' => 'success', 'levels' => $map]);
    }

    #[Route('/rhythm/leaderboard', name: 'app_rhythm_leaderboard', methods: ['GET'])]
    public function leaderboard(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', '1'));

        return new JsonResponse($this->scores->globalLeaderboard($page, self::PAGE_SIZE));
    }

    #[Route('/rhythm/leaderboard/song/{songId}', name: 'app_rhythm_song_leaderboard', methods: ['GET'], requirements: ['songId' => '\d+'])]
    public function songLeaderboard(int $songId): JsonResponse
    {
        return new JsonResponse($this->scores->songLeaderboard($songId));
    }

    #[Route('/rhythm/scores/{userId}', name: 'app_rhythm_user_scores', methods: ['GET'], requirements: ['userId' => '\d+'])]
    public function userScores(int $userId, Request $request): JsonResponse
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            return new JsonResponse([]);
        }

        $all = $request->query->get('all') === '1';
        $page = $all ? null : max(1, (int) $request->query->get('page', '1'));
        $rows = $this->scores->forUser($user, $page);

        $out = [];
        foreach ($rows as $rs) {
            $out[] = [
                'song_id' => $rs->getSong()?->getId(),
                'title' => $rs->getSong()?->getTitle(),
                'artist' => $rs->getSong()?->getArtist(),
                'score' => $rs->getScore(),
                'max_combo' => $rs->getMaxCombo(),
                'perfect' => $rs->getPerfect(),
                'great' => $rs->getGreat(),
                'good' => $rs->getGood(),
                'bad' => $rs->getBad(),
                'miss' => $rs->getMiss(),
                'difficulty' => $rs->getDifficulty(),
                'played_at' => $rs->getPlayedAt(),
            ];
        }

        return new JsonResponse($out);
    }

    #[Route('/rhythm/score', name: 'app_rhythm_score', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function saveScore(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid body'], Response::HTTP_BAD_REQUEST);
        }

        $hitDeltas = is_array($data['hit_deltas'] ?? null) ? $data['hit_deltas'] : [];
        $claimedScore = (int) ($data['score'] ?? 0);
        $maxCombo = (int) ($data['max_combo'] ?? 0);

        $banReason = $this->antiCheat->inspect($hitDeltas, $claimedScore, $maxCombo);
        if ($banReason !== null) {
            $newStrikes = abs($user->getRhythmStrikes()) + 1;
            $user->setRhythmStrikes($newStrikes);
            if ($newStrikes >= 3) {
                // 3rd strike: full account ban (legacy also destroyed the session).
                $user->setBanned(true);
                $this->em->flush();

                return new JsonResponse(
                    ['status' => 'error', 'message' => '3rd Strike Cheat detected: ' . $banReason . '. Your account has been permanently banned.'],
                    Response::HTTP_FORBIDDEN
                );
            }
            $this->em->flush();

            return new JsonResponse(
                ['status' => 'error', 'message' => 'Cheat detected (Strike ' . $newStrikes . '/3): ' . $banReason . '. You are banned from the Rhythm Game.'],
                Response::HTTP_FORBIDDEN
            );
        }

        $song = $this->music->find((int) ($data['song_id'] ?? 0));
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Song not found'], Response::HTTP_NOT_FOUND);
        }

        $run = new RhythmScore();
        $run->setUser($user)
            ->setSong($song)
            ->setScore($claimedScore)
            ->setMaxCombo($maxCombo)
            ->setPerfect((int) ($data['perfect'] ?? 0))
            ->setGreat((int) ($data['great'] ?? 0))
            ->setGood((int) ($data['good'] ?? 0))
            ->setBad((int) ($data['bad'] ?? 0))
            ->setMiss((int) ($data['miss'] ?? 0))
            ->setDifficulty((string) ($data['difficulty'] ?? 'medium'));
        $this->em->persist($run);
        $this->em->flush();

        return new JsonResponse(['status' => 'success']);
    }

    #[Route('/rhythm/favorite/{songId}', name: 'app_rhythm_favorite_toggle', methods: ['POST'], requirements: ['songId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function toggleFavorite(int $songId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $song = $this->music->find($songId);
        if ($song === null) {
            return new JsonResponse(['status' => 'error', 'message' => 'Song not found'], Response::HTTP_NOT_FOUND);
        }

        $existing = $this->favorites->findOneForUser($user, $song);
        if ($existing !== null) {
            $this->em->remove($existing);
            $this->em->flush();

            return new JsonResponse(['status' => 'removed', 'is_favorite' => 0]);
        }

        $fav = new RhythmFavorite();
        $fav->setUser($user)->setSong($song)->setSortOrder($this->favorites->nextSortOrder($user));
        $this->em->persist($fav);
        $this->em->flush();

        return new JsonResponse(['status' => 'added', 'is_favorite' => 1]);
    }

    #[Route('/rhythm/favorites', name: 'app_rhythm_favorites', methods: ['GET'])]
    public function favoriteIds(#[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse([]);
        }

        return new JsonResponse($this->favorites->songIdsForUser($user));
    }

    #[Route('/rhythm/favorite-songs', name: 'app_rhythm_favorite_songs', methods: ['GET'])]
    public function favoriteSongs(#[CurrentUser] ?User $user, UrlGeneratorInterface $urls): JsonResponse
    {
        if ($user === null) {
            return new JsonResponse([]);
        }

        $favs = $this->favorites->findForUser($user);
        $songs = array_map(static fn (RhythmFavorite $f) => $f->getSong(), $favs);
        $ids = array_map(static fn ($s) => $s?->getId(), $songs);
        $levels = $this->charts->levelsForSongs(array_filter($ids));

        $out = [];
        foreach ($songs as $s) {
            if ($s === null) {
                continue;
            }
            $out[] = [
                'id' => $s->getId(),
                'title' => $s->getTitle(),
                'artist' => $s->getArtist(),
                'album' => $s->getAlbum(),
                'duration' => $s->getDuration(),
                'stream' => $urls->generate('app_stream', ['id' => $s->getId()]),
                'cover' => $urls->generate('app_cover', ['id' => $s->getId()]),
                'rg_favorite' => 1,
                'levels' => $levels[$s->getId()] ?? null,
            ];
        }

        return new JsonResponse($out);
    }
}
```

- [ ] **Step 2 : `cache:clear` + vérifier les routes**

Run: `php bin/console cache:clear`
Run: `php bin/console debug:router | grep rhythm`
Expected : les 12 routes `app_rhythm*` listées.

- [ ] **Step 3 : Commit** — `Add RhythmController (JSON game API + hub page)\n\nref #noref`

---

### Task 1.10 : Template hub + écran de jeu (depuis `markup.html`)

**Files:**
- Create: `templates/rhythm/hub.html.twig`

- [ ] **Step 1 : Copier `docs/rhythm-game-reference/markup.html`** dans un `{% block body %}` étendant `base.html.twig`. Conserver tous les `id="rg-*"` (le moteur JS s'appuie dessus). **Retirer** les onglets/panneaux social hors scope : `#rg-art-tab-following`, `#rg-art-pane-following`, `#rg-art-tab-playlists`, `#rg-art-pane-playlists`, et l'onglet `#rg-tab-artists` + `#rg-list-artists` si on ne porte pas l'annuaire joueurs (voir Décisions de scope).

- [ ] **Step 2 : Ajouter un bloc config** `#rhythm-config` (mêmes principes que `#player-config` dans `base.html.twig`) exposant les URLs de routes + le token CSRF, pour que `rhythm.js` n'ait aucune URL en dur :

```twig
{% block body %}
    {# ... markup.html content (rg-* screens + modals) ... #}

    <script type="application/json" id="rhythm-config">
        {
            "csrf": "{{ csrf_token('rhythm') }}",
            "urls": {
                "songs": "{{ path('app_rhythm_songs') }}",
                "chart": "{{ path('app_rhythm_chart', {songId: 0, difficulty: 'DIFF'}) }}",
                "levels": "{{ path('app_rhythm_levels', {songId: 0}) }}",
                "allLevels": "{{ path('app_rhythm_all_levels') }}",
                "score": "{{ path('app_rhythm_score') }}",
                "leaderboard": "{{ path('app_rhythm_leaderboard') }}",
                "songLeaderboard": "{{ path('app_rhythm_song_leaderboard', {songId: 0}) }}",
                "userScores": "{{ path('app_rhythm_user_scores', {userId: 0}) }}",
                "favoriteToggle": "{{ path('app_rhythm_favorite_toggle', {songId: 0}) }}",
                "favorites": "{{ path('app_rhythm_favorites') }}",
                "favoriteSongs": "{{ path('app_rhythm_favorite_songs') }}"
            }
        }
    </script>
{% endblock %}
```

Note : les placeholders `0` / `'DIFF'` sont remplacés côté JS (mêmes techniques que `cfg.recordUrl.replace(/\/\d+$/, ...)` déjà utilisée dans `assets/player.js`).

- [ ] **Step 3 : Commit** — `Add rhythm hub + game Twig template\n\nref #noref`

---

### Task 1.11 : CSS (depuis `style.css`)

**Files:**
- Create: `assets/styles/rhythm.css`

- [ ] **Step 1 : Copier `docs/rhythm-game-reference/style.css`** verbatim dans `assets/styles/rhythm.css` (sélecteurs `.rg-*` déjà scopés, aucun conflit attendu avec `app.css`).
- [ ] **Step 2 : Commit** — `Add rhythm game stylesheet\n\nref #noref`

---

### Task 1.12 : Golden-master du générateur (fidélité au legacy)

**Files:**
- Create (jetable, NON commité) : `var/rhythm-golden-check.php`

Vérifie que le service Symfony produit une sortie **identique** aux fonctions legacy de référence.

- [ ] **Step 1 : Écrire le script**

```php
<?php
// Throwaway fidelity check — run with `php`, do not commit. Compares the
// Symfony RhythmChartGenerator against the verbatim legacy functions for
// fixed inputs. Both are PHP, so output MUST be byte-identical.

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../docs/rhythm-game-reference/chart-generation-functions.php';

$gen = new \App\Service\RhythmChartGenerator();

$cases = [
    [1, 200, 'easy'], [1, 200, 'medium'], [42, 183, 'hard'],
    [42, 183, 'expert'], [7, 240, 'master'], [7, 240, 'demon'],
];

$fail = 0;
foreach ($cases as [$id, $dur, $diff]) {
    $legacyNotes = generatePhpChart($id, $dur, $diff, 1.0);
    $newNotes = $gen->generate($id, $dur, $diff, 1.0);
    $legacyLevel = calculatePhpLevel($legacyNotes, $dur, $diff, 1.0);
    $newLevel = $gen->level($newNotes, $dur, $diff, 1.0);

    $notesOk = json_encode($legacyNotes) === json_encode($newNotes);
    $levelOk = $legacyLevel === $newLevel;
    printf("%-8s notes=%s level=%s (legacy=%d new=%d, %d notes)\n",
        $diff, $notesOk ? 'OK' : 'DIFF', $levelOk ? 'OK' : 'DIFF',
        $legacyLevel, $newLevel, count($legacyNotes));
    if (!$notesOk || !$levelOk) { $fail++; }
}

echo $fail === 0 ? "\nALL GOLDEN-MASTER CASES PASS\n" : "\n$fail CASE(S) FAILED\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2 : Exécuter**

Run: `php var/rhythm-golden-check.php`
Expected : `ALL GOLDEN-MASTER CASES PASS` (exit 0). Si `DIFF` → l'arithmétique du port a divergé : comparer ligne à ligne avec `chart-generation-functions.php`, corriger `RhythmChartGenerator`, relancer.

- [ ] **Step 3 : Supprimer le script jetable**

Run: `rm var/rhythm-golden-check.php`

(Pas de commit — script jetable.)

---

### Task 1.13 : Moteur de jeu JS (depuis `frontend-game-engine.js`)

**Files:**
- Create: `assets/rhythm.js`
- Modify: `assets/app.js`
- Modify: `importmap.php` (si `rhythm.js` doit être un module résolu)

- [ ] **Step 1 : Copier `docs/rhythm-game-reference/frontend-game-engine.js`** dans `assets/rhythm.js`.

- [ ] **Step 2 : Ajouter en tête un lecteur de config** (remplace toute URL `?action=` en dur) :

```js
// Read route map + CSRF token rendered by templates/rhythm/hub.html.twig.
// If the hub markup isn't on the page, bail out (this module is a no-op
// outside the rhythm game — same guard style as the per-song settings IIFE
// in assets/player.js).
const cfgEl = document.getElementById('rhythm-config');
if (!cfgEl || !document.getElementById('rg-screen-hub')) {
    // Not on the rhythm page; do nothing.
} else {
    const RG = JSON.parse(cfgEl.textContent);
    // Helper: swap the trailing "/0" (song id) or "DIFF" placeholder in a URL.
    const songUrl = (tpl, id) => tpl.replace(/\/0(\/|$)/, `/${id}$1`);
    const chartUrl = (id, diff) => RG.urls.chart.replace('/0/DIFF', `/${id}/${diff}`);
    // ... rest of the engine, using RG.urls.* and RG.csrf ...
}
```

- [ ] **Step 3 : Re-câbler chaque `fetch("?action=...")`** vers la route correspondante (table Task 1.9). Pour les `fetch` POST (`save_rhythm_score`, `toggle_rhythm_favorite`), ajouter l'en-tête `'X-CSRF-TOKEN': RG.csrf` (le corps `JSON.stringify(...)` reste identique). Pour le stream, utiliser `song.stream` (déjà fourni par `/rhythm/songs`) au lieu de `?action=get_stream&id=`.

- [ ] **Step 4 : Importer depuis `app.js`** :

```js
import './styles/rhythm.css';
import './rhythm.js';
```

- [ ] **Step 5 : Compiler l'importmap** (AssetMapper, pas de build) :

Run: `php bin/console asset-map:compile`
Expected : succès, `rhythm.js` + `rhythm.css` listés dans les assets.

- [ ] **Step 6 : Commit** — `Wire rhythm game frontend engine to Symfony routes\n\nref #noref`

---

### Task 1.14 : Vérification bout-en-bout (curl)

**Files:** aucun (vérification)

- [ ] **Step 1 : Démarrer le serveur de test** (arrière-plan)

Run: `php -S 127.0.0.1:8000 -t public public/index.php`

- [ ] **Step 2 : Page hub**

Run: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/rhythm`
Expected : `200`.

- [ ] **Step 3 : Génération lazy d'un chart** (remplacer `1` par un id de morceau existant)

Run: `curl -s http://127.0.0.1:8000/rhythm/chart/1/medium`
Expected : JSON `{"status":"success","found":true,"notes":[...],"level":N}` avec `notes` non vide.

- [ ] **Step 4 : Idempotence du cache** — rejouer la même requête, vérifier que le chart est servi depuis la table (mêmes notes) :

Run: `curl -s http://127.0.0.1:8000/rhythm/chart/1/medium`
Run: `php bin/console dbal:run-sql "SELECT difficulty, level FROM rhythm_charts WHERE song_id = 1"`
Expected : la ligne `medium` existe.

- [ ] **Step 5 : Levels, leaderboard, songs**

Run: `curl -s http://127.0.0.1:8000/rhythm/levels/1`
Run: `curl -s http://127.0.0.1:8000/rhythm/leaderboard`
Run: `curl -s http://127.0.0.1:8000/rhythm/songs`
Expected : JSON valide (leaderboard vide `[]` tant qu'aucun score).

- [ ] **Step 6 : Endpoints protégés sans session → 403 attendu**

Run: `curl -s -o /dev/null -w "%{http_code}" -X POST http://127.0.0.1:8000/rhythm/score`
Expected : `403` (CSRF/anonymous) — jamais `500`.

- [ ] **Step 7 : Arrêter le serveur de test.**

---

### Task 1.15 : Lien de navigation « Rhythm » (⚠️ fichier partagé)

**Files:**
- Modify: `templates/base.html.twig`

> ⚠️ `base.html.twig` est également modifié par le chantier « persistance du player » en cours en parallèle. Faire cet ajout **en dernier**, isolé, et re-`git diff` avant commit pour éviter d'écraser l'autre travail.

- [ ] **Step 1 : Ajouter un lien dans la sidebar** (bloc `{% if app.user %}`, à côté de « Playlists ») :

```twig
<a href="{{ path('app_rhythm') }}" class="{{ app.request.attributes.get('_route') starts with 'app_rhythm' ? 'active' }}">Rhythm</a>
```

- [ ] **Step 2 : Vérifier** que la page charge et que le lien est actif sur `/rhythm`.

Run: `curl -s http://127.0.0.1:8000/rhythm | grep -c 'rg-screen-hub'`
Expected : `1` (ou plus).

- [ ] **Step 3 : Commit** — `Add Rhythm link to sidebar nav\n\nref #noref`

---

**✅ Fin de la Phase 1 : le jeu est jouable « tel que c'était » — charts déterministes pseudo-aléatoires, scoring + anti-triche, leaderboards, favoris.**

---

# PHASE 2 — Prise en compte des beats & du rythme réel des morceaux

**Objectif :** au lieu de placer les notes sur une grille pseudo-aléatoire, les caler sur les **vrais temps forts** du morceau (onsets/beats détectés), tout en gardant la variété de mécaniques (tap/hold/swipe/accords) par difficulté. Repli automatique sur la Phase 1 quand aucun beat grid n'est disponible.

**⚠️ Incertitude assumée :** PHP n'a pas d'analyse audio native. La faisabilité dépend d'un outil externe. La Task 2.1 est un **spike** dont le résultat peut modifier les tâches suivantes — ne pas figer 2.2+ avant de l'avoir fait.

### Task 2.1 : Spike — choisir et valider l'outil d'analyse

**Files:** Create `docs/superpowers/specs/2026-08-21-beat-analysis-spike.md` (notes)

- [ ] **Step 1 : Vérifier la disponibilité d'`aubio`** sur la machine cible :

Run: `which aubioonset aubiotrack`
Expected : chemins renvoyés. Sinon documenter l'installation (`apt-get install aubio-tools` / `brew install aubio`) ou évaluer une alternative (FFI vers une lib C, ou préprocesseur Python/Node hors ligne).

- [ ] **Step 2 : Tester sur un vrai fichier** de `var/library` :

Run: `aubiotrack /chemin/vers/un.mp3 | head`
Run: `aubioonset /chemin/vers/un.mp3 | head`
Expected : liste de timestamps (secondes). Noter le format exact de sortie.

- [ ] **Step 3 : Décider et documenter** dans le spec : outil retenu, contrat de données (`float[] beatTimes` + `float bpm`), où tourne l'analyse (commande console à la demande, recommandé, plutôt qu'au scan — coûteux CPU), coût estimé sur la taille réelle de la bibliothèque. **Point de décision utilisateur avant 2.2.**

---

### Task 2.2 : Migration — stockage des beats

**Files:**
- Modify: `src/Entity/Music.php` (ajout `bpm`, `beatGridJson`)
- Modify: `src/Entity/RhythmChart.php` (ajout `generatorVersion`)
- Create: migration

- [ ] **Step 1 : Ajouter à `Music`**

```php
    /** Detected tempo (beats per minute); null until analysed. Phase 2. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $bpm = null;

    /** JSON array of detected beat/onset timestamps in seconds; null until analysed. Phase 2. */
    #[ORM\Column(name: 'beat_grid_json', type: 'text', nullable: true)]
    private ?string $beatGridJson = null;
```
(+ accesseurs `getBpm`/`setBpm`, `getBeatGridJson`/`setBeatGridJson`.)

- [ ] **Step 2 : Ajouter à `RhythmChart`** un discriminant de génération, pour invalider les charts Phase 1 :

```php
    /** 1 = Phase 1 pseudo-random; 2 = Phase 2 beat-synced. Lets us regenerate stale charts. */
    #[ORM\Column(name: 'generator_version', type: 'integer', options: ['default' => 1])]
    private int $generatorVersion = 1;
```
(+ accesseurs.)

- [ ] **Step 3 : Générer + appliquer la migration**

Run: `php bin/console doctrine:migrations:diff --no-interaction`
Run: `php bin/console doctrine:migrations:migrate --no-interaction`
Expected : colonnes ajoutées sans erreur.

- [ ] **Step 4 : Commit** — `Add beat-grid columns for Phase 2 rhythm sync\n\nref #noref`

---

### Task 2.3 : `BeatAnalyzer` (wrapper de l'outil externe)

**Files:**
- Create: `src/Service/BeatAnalyzer.php`

- [ ] **Step 1 : Écrire le service** (via `symfony/process` ; adapter le binaire/format selon la décision Task 2.1)

```php
<?php

namespace App\Service;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Extracts a beat/onset grid + tempo from an audio file using an external
 * analysis binary (default: aubio, chosen in the Phase 2 spike). Returns null
 * on any failure so callers can gracefully fall back to Phase 1 pseudo-random
 * generation. Never throws to the caller.
 */
final class BeatAnalyzer
{
    public function __construct(private readonly string $aubioTrackBin = 'aubiotrack')
    {
    }

    /**
     * @return array{bpm: float|null, beats: list<float>}|null
     */
    public function analyse(string $absolutePath): ?array
    {
        if (!is_file($absolutePath)) {
            return null;
        }

        try {
            $process = new Process([$this->aubioTrackBin, $absolutePath]);
            $process->setTimeout(120.0);
            $process->run();
            if (!$process->isSuccessful()) {
                return null;
            }
        } catch (ProcessFailedException) {
            return null;
        }

        $beats = [];
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $line = trim($line);
            if ($line !== '' && is_numeric($line)) {
                $beats[] = (float) $line;
            }
        }
        if ($beats === []) {
            return null;
        }

        // Estimate BPM from median inter-beat interval.
        $bpm = null;
        if (count($beats) > 1) {
            $intervals = [];
            for ($i = 1, $n = count($beats); $i < $n; ++$i) {
                $intervals[] = $beats[$i] - $beats[$i - 1];
            }
            sort($intervals);
            $median = $intervals[intdiv(count($intervals), 2)];
            if ($median > 0) {
                $bpm = round(60.0 / $median, 2);
            }
        }

        return ['bpm' => $bpm, 'beats' => $beats];
    }
}
```

- [ ] **Step 2 : Commit** — `Add BeatAnalyzer service (external onset detection)\n\nref #noref`

---

### Task 2.4 : Commande d'analyse

**Files:**
- Create: `src/Command/AnalyzeBeatsCommand.php`

- [ ] **Step 1 : Écrire une commande** `app:rhythm:analyze` qui itère les `Music` sans `beatGridJson`, appelle `BeatAnalyzer`, et persiste `bpm` + `beatGridJson`. Options : `--limit`, `--force` (ré-analyser). S'inspirer de `src/Command/ScanCommand.php` pour la structure (résolution du chemin absolu via le répertoire `var/library`, barre de progression, flush par lot).

- [ ] **Step 2 : Exécuter sur quelques morceaux**

Run: `php bin/console app:rhythm:analyze --limit=5`
Expected : `bpm`/`beat_grid_json` remplis. Vérifier :
Run: `php bin/console dbal:run-sql "SELECT id, bpm, length(beat_grid_json) FROM music WHERE beat_grid_json IS NOT NULL LIMIT 5"`

- [ ] **Step 3 : Commit** — `Add app:rhythm:analyze command\n\nref #noref`

---

### Task 2.5 : Génération de chart calée sur les beats

**Files:**
- Modify: `src/Service/RhythmChartGenerator.php`

- [ ] **Step 1 : Ajouter `generateFromBeats()`** : même logique de mécaniques/probabilités que `generate()` (accords, holds, swipes, densité par difficulté), mais au lieu d'avancer `$t` d'un pas fixe `$spacing`, avancer le long de la **grille de beats** (subdivisée : 1 note par beat en easy, par demi/quart de beat aux difficultés hautes pour approcher le `spacing` cible). Chaque `time` de note est **snappé** au beat/subdivision le plus proche. Garder la même forme de note de sortie (`{time, lane, isLong?, endTime?, isSwipe?, hitEndSwipe?}`).

- [ ] **Step 2 : Repli** : `generateFromBeats()` renvoie `[]` (ou délègue à `generate()`) si `beats` est vide, garantissant la dégradation gracieuse vers la Phase 1.

- [ ] **Step 3 : Golden-master de non-régression Phase 1** : réexécuter le script de la Task 1.12 pour confirmer que `generate()` (chemin pseudo-aléatoire) est **inchangé**. Expected : `ALL GOLDEN-MASTER CASES PASS`.

- [ ] **Step 4 : Commit** — `Add beat-synced chart generation with Phase 1 fallback\n\nref #noref`

---

### Task 2.6 : Brancher la génération beat-aware + régénération

**Files:**
- Modify: `src/Controller/RhythmController.php` (méthode `chart()`)
- Create: `src/Command/RegenerateChartsCommand.php`

- [ ] **Step 1 : `chart()`** — à la génération lazy, si le morceau a un `beatGridJson`, appeler `generateFromBeats(...)` et stocker `generatorVersion = 2` ; sinon garder la Phase 1 (`generatorVersion = 1`). Traiter un chart existant en `generatorVersion = 1` comme périmé **uniquement** si le morceau a désormais un beat grid (le régénérer).

- [ ] **Step 2 : `app:rhythm:regenerate-charts`** — commande (remplace le bulk `rescan_charts` admin qu'on a coupé) qui, pour chaque morceau + difficulté, régénère et met en cache le chart (beat-aware si dispo). Options : `--difficulty`, `--force`, `--density`. Transaction par morceau (cf. legacy « 1 write par morceau »).

- [ ] **Step 3 : Vérifier** qu'un morceau analysé produit un chart `generatorVersion = 2` dont les `time` s'alignent sur la grille de beats (tolérance ±30 ms) — petit script `php` jetable dans `var/` comparant `notes[].time` à `beat_grid_json`.

Run: `php bin/console app:rhythm:regenerate-charts --difficulty=medium --limit=1`
Run: `curl -s http://127.0.0.1:8000/rhythm/chart/<songAnalyse>/medium`
Expected : notes calées sur les beats.

- [ ] **Step 4 : Commit** — `Beat-aware chart generation wired into API + regenerate command\n\nref #noref`

---

## Self-review (exécutée à l'écriture du plan)

- **Couverture de la spéc** (`docs/rhythm-game-reference/README.md`) : hub/browse ✅ (1.9/1.10), favoris rhythm ✅ (1.3/1.8/1.9), difficultés + génération ✅ (1.6), 3 types de notes + jugement ✅ (portés tels quels dans `rhythm.js`, 1.13), scoring + combo ✅ (1.2/1.9), anti-triche 3 couches + strikes/ban ✅ (1.7/1.9/1.4), leaderboards global + par morceau ✅ (1.8/1.9), historique joueur ✅ (1.9). **Trims explicites** (annuaire joueurs avec follows, admin bulk/override, `admin_logs`, `vacuum`) documentés dans « Décisions de scope » — ce ne sont pas des oublis. Calibration/keybinds/tick-speed sont **client-only** (localStorage) et suivent le port verbatim de `rhythm.js`.
- **Placeholders** : aucun « TODO/TBD ». Les copies de gros fichiers (JS/CSS/markup) référencent un fichier source précis + les adaptations exactes.
- **Cohérence des types** : `generate()`/`level()`, `RhythmChartGenerator`, `RhythmAntiCheat::inspect()`, noms de routes `app_rhythm*` et clés du bloc `#rhythm-config` sont identiques entre les tâches qui les définissent (1.6/1.7/1.9) et celles qui les consomment (1.10/1.13).
- **Cohérence de scope** : respecte `docs/PLAN_MIGRATION.md` §0bis (admin exclu, social exclu) et l'exception REST justifiée pour ce jeu temps réel.

## Note sous-agent

Ce plan est le seul livrable de cette session (contexte : sous-agent isolé, un autre agent modifie le repo en parallèle). **Aucune tâche n'a été exécutée** ; seul ce fichier a été écrit. Le seul point de contact avec le travail parallèle est `templates/base.html.twig` (Task 1.15), volontairement isolé en dernière tâche.
