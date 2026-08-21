# Rhythm Game — dossier de référence (Feature 20, en pause)

Ce dossier contient **tout le code utile du legacy** pour reconstruire la
Feature 20 (Rhythm game) plus tard, extrait verbatim de
`legacy/php_music.php` (jamais exécuté — lecture seule), plus les notes de
conception nécessaires pour décider comment la porter. Feature 20 est en
pause dans `docs/PLAN_MIGRATION.md` — ce dossier permet de reprendre sans
tout re-fouiller dans les 87 650 lignes du monolithe.

## En bref : comment ça marche, et sur quels fichiers

**Réponse à "est-ce compatible avec n'importe quel MP3 ou faut-il des
fichiers spéciaux ?"** : compatible avec **n'importe quel morceau déjà
scanné** (aucune préparation spéciale). Mais la génération de chart est
**100% pseudo-aléatoire déterministe**, pas une vraie analyse audio — voir
`chart-generation-functions.php` et la section « Génération de chart » plus
bas.

| Fichier | Contenu | Lignes legacy source |
|---|---|---|
| `db-schema.sql` | Schéma des 3 tables (`rhythm_scores`, `rhythm_favorites`, `rhythm_charts`) + colonne `users.rhythm_strikes` | 22854-22886 |
| `chart-generation-functions.php` | `generatePhpChart()` + `calculatePhpLevel()` — génération pseudo-aléatoire des notes | 22145-22373 |
| `backend-dispatcher-cases.php` | Les 14 handlers d'API JSON (score, leaderboard, charts, favoris, anti-triche, admin rescan) | 30237-31235 |
| `style.css` | CSS scopé `.rg-*` du jeu (thème, layout hub + écran de jeu) | 36727-37550 |
| `markup.html` | Template HTML du hub + écran de jeu (SPA JS), + 3 modales (config densité, stats joueur, "How to Play") | 54922-55429 + 43853-44059 |
| `frontend-game-engine.js` | La classe `RhythmGameEngine` complète (5000 lignes) : boucle de jeu, input, judging, scoring, UI hub | 80990-85989 |

## Fonctionnalités du legacy (à couvrir ou trancher explicitement si on n'en veut pas)

- **Hub joueur** : parcourir/rechercher les morceaux, liste de favoris dédiée
  au rhythm game (table `rhythm_favorites`, distincte des favoris normaux),
  classement (leaderboard) global et par morceau, page "profil joueur"
  d'un autre utilisateur (stats, morceaux, classement).
- **Sélection de difficulté** : 6 niveaux — `easy`/`medium` (4 lanes),
  `hard`/`expert`/`master` (6 lanes), `demon` (10 lanes) — chacun avec son
  propre espacement de notes et probabilités d'accords (voir tableau
  ci-dessous).
- **3 types de notes** (voir `markup.html`, modale "How to Play") :
  - **Tap** (cyan) : appuyer exactement quand la note atteint la ligne de
    jugement.
  - **Hold** (vert) : maintenir la touche du début à la fin de la note,
    lâcher trop tôt casse le combo.
  - **Swipe** (rose) : variante d'appui avec un geste, peut aussi terminer
    un hold (`hitEndSwipe`).
- **Jugement** (`RhythmGameEngine.JUDGMENT`, en secondes) :
  **Perfect ≤ 45ms, Great ≤ 80ms, Good ≤ 125ms, Bad ≤ 170ms**, au-delà = Miss.
- **Calibration** : décalage audio réglable en ms par joueur (latence de
  l'appareil, stocké en `localStorage`, rien côté serveur), vitesse de
  défilement des notes ("tick speed"), volume des SFX, réassignation des
  touches (10 lanes possibles : A S D F G H J K L ;).
- **Scoring & combo** : score, combo max, compteurs perfect/great/good/
  bad/miss enregistrés par run (`rhythm_scores`).
- **Anti-triche côté serveur** (`save_rhythm_score`, 3 couches) :
  1. **Précision inhumaine** : si >80% des écarts de frappe (`hit_deltas`)
     sont sous 5ms → triche.
  2. **Détection de macro** : écart-type des `hit_deltas` < 1,5ms sur 20+
     frappes → triche (un humain a toujours une variance naturelle).
  3. **Bornes mathématiques** : score/combo déclarés impossibles au regard
     du nombre de frappes réelles → triche.
  → 1er/2e strike = banni du rhythm game seulement (`rhythm_strikes`
  incrémenté) ; 3e strike = **compte entièrement banni** (`banned=1`).
- **Favoris rhythm-game** (liste + ordre manuel, comme `listen_later`/
  `offline_songs` déjà portés).
- **Admin** (hors périmètre actuel — panneau admin exclu du projet) :
  override manuel d'un chart (`save_rhythm_chart`), reset global des charts
  + régénération, rescan en masse, gestion des strikes/bannissements,
  modale "Adjust Chart Scan" (densité globale par défaut).

## Génération de chart : ce qui existe vs ce qu'il faudrait

**Ce qui existe (legacy, reproduit dans `chart-generation-functions.php`)**
est **entièrement pseudo-aléatoire déterministe** :
- Seed = `crc32(song_id + difficulty + duration) + hexdec(md5(...))`.
- RNG xorshift à partir de ce seed → placement des notes.
- La **seule** donnée réellement tirée de l'audio est `duration` (déjà
  disponible dans ce port via `App\Entity\Music::getDuration()`, extraite
  par getID3 au scan — voir `App\Service\MusicScanner`).
- Les "drops"/refrains sont un **tirage à pile ou face toutes les ~20s**
  (`if ($t % 20 < 1) $is_drop = ($rng() > 0.5)`), pas une vraie détection
  de section musicale.
- Résultat : le chart *a l'air* rythmique (densité variable, accords,
  holds, syncopations sur les difficultés élevées) mais n'est **jamais**
  synchronisé aux vrais temps forts du morceau. C'est pour ça que l'"outil
  de calibration" existe côté client : il compense la latence de
  l'appareil, pas un décalage avec la musique (il n'y en a pas puisque rien
  n'est calé sur la musique).

**⚠️ À PRÉVOIR si on veut une vraie synchronisation au rythme réel** :
remplacer (ou compléter) cette génération pseudo-aléatoire par une
**analyse FFT / détection d'onset (onset detection)** sur le fichier audio
pour extraire les vrais temps forts (beats, BPM, transitoires). C'est
**bien plus lourd** que l'approche actuelle :
- Nécessiterait soit une **librairie d'analyse audio côté PHP** (rien
  d'équivalent à `librosa`/`aubio`/`essentia` n'existe nativement en PHP —
  à évaluer : bindings FFI vers une lib C, ou appel à un binaire externe
  comme `aubioonset`/`aubiotrack` si disponible sur le serveur cible), soit
  un **prétraitement séparé** (ex. un script Python/Node exécuté au moment
  du scan ou en tâche de fond, qui produit un fichier de beats/onsets à
  côté du morceau, consommé ensuite par le générateur de chart PHP).
- Implique de décider **quand** cette analyse tourne (au scan, à la
  demande, en file d'attente asynchrone — potentiellement coûteux en CPU
  sur une grosse bibliothèque) et **où** stocker le résultat (nouvelle
  colonne/table, ex. `music.bpm`, `music.beat_grid_json`).
- Reste un vrai chantier à part entière — **pas commencé, pas chiffré** ;
  documenté ici uniquement pour ne pas le perdre de vue le jour où la
  Feature 20 est relancée. Tant que ce n'est pas fait, la voie la plus
  rapide reste de reproduire l'algorithme pseudo-aléatoire du legacy à
  l'identique (fidèle, simple, déjà éprouvé) et de traiter l'analyse audio
  réelle comme une amélioration ultérieure séparée.

## Difficulté : lanes, espacement, accords (extrait de `generatePhpChart`)

| Difficulté | Lanes | Espacement notes | Chance d'accord (2 notes) | Triple accord | Chance de hold |
|---|---|---|---|---|---|
| easy | 4 | 0.850s | 0% | — | 20% |
| medium | 4 | 0.500s | 0% | — | 20% |
| hard | 6 | 0.320s | 18% | — | 20% |
| expert | 6 | 0.200s | 35% | 5% | 20% |
| master | 6 | 0.140s | 55% | 15% | 20% |
| demon | 10 | 0.025s | 75% | 30% | 10% |

(Espacement divisé par le multiplicateur de densité choisi par
l'utilisateur/admin, 0.5x à 2.0x dans la modale "Adjust Chart Scan".)

## Pistes de portage Symfony (pas commencé, juste le plan)

- **Entités** : `RhythmChart` (PK composite `song_id`+`difficulty`),
  `RhythmScore` (comme `History`/`PlayCount` déjà portés), `RhythmFavorite`
  (clé composite comme `Favorite`/`ListenLater`, même patron). Ajouter
  `rhythmStrikes` sur `User` (déjà a `banned`, garder cohérent).
- **Service** `RhythmChartGenerator` : port direct de
  `chart-generation-functions.php` (logique pure, facile à tester
  unitairement — seed déterministe donc résultat reproductible).
- **Contrôleur** `RhythmController` : porter les handlers de
  `backend-dispatcher-cases.php` en routes Symfony. **Attention** : la
  plupart sont déjà bien adaptés à rester en JSON (scores, leaderboard,
  charts) car c'est un jeu temps réel piloté par JS — contrairement à la
  règle "pas de REST par défaut" appliquée aux autres features CRUD de ce
  projet, ici le JSON est justifié par le besoin réel (boucle de jeu qui
  fetch les données sans rechargement de page).
- **Frontend** : `frontend-game-engine.js` est du JS vanilla directement
  réutilisable comme module AssetMapper — adapter surtout les URLs
  `fetch()`/gestion CSRF pour matcher les routes Symfony, et remplacer les
  sélecteurs DOM `id="rg-*"` par le HTML réel généré par les templates Twig
  (`markup.html` → templates `rhythm/hub.html.twig` + `rhythm/game.html.twig`).
- **CSS** : `style.css` copiable presque tel quel dans
  `assets/styles/rhythm.css`.
- **Anti-triche** : porter les 3 couches telles quelles (logique pure,
  aucune dépendance externe).
- **Hors périmètre actuel** (panneau admin exclu du projet, voir §0bis de
  `docs/PLAN_MIGRATION.md`) : override manuel de chart, reset/rescan en
  masse, gestion admin des strikes/bannissements — soit à laisser en accès
  "propriétaire du morceau" plutôt qu'admin, soit à documenter comme TODO
  si le panneau admin est un jour reconsidéré.

## Rappel

Ce dossier est de la **documentation/référence**, pas du code exécuté par
l'app Symfony — les fichiers `.php`/`.js`/`.css`/`.html` ici sont des
extraits bruts du legacy (parfois non auto-suffisants hors contexte), à lire
et adapter, jamais à inclure/exécuter directement.
