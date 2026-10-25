#!/usr/bin/env bash
# Smoke test of the Subsonic API: calls every implemented method (read-only,
# except a star/unstar and a rating set back to its value) and checks for
# "status":"ok". One OK/FAIL line per call; exit code = number of failures.
#
# Usage: bash docs/subsonic-smoke.sh http://localhost:8000 [user:password]
#   (the optional credentials are sent as HTTP Basic Auth, for Apache)

set -u
BASE="${1:?usage: $0 BASE_URL [user:password]}"
AUTH=()
[ $# -ge 2 ] && AUTH=(-u "$2")
COMMON="u=smoke&t=x&s=y&v=1.16.1&c=smoke&f=json"
FAIL=0

call() { curl -s "${AUTH[@]}" "$BASE/rest/$1?$COMMON${2:+&$2}"; }
check() {
    local method="$1" params="${2:-}"
    if call "$method" "$params" | grep -q '"status":"ok"'; then
        echo "OK    $method $params"
    else
        echo "FAIL  $method $params"; FAIL=$((FAIL + 1))
    fi
}
first() { call "$1" "$2" | grep -o "$3" | head -1 | sed -E 's/.*"([^"]+)"$/\1/'; }

check ping
check getLicense
check getOpenSubsonicExtensions
check getMusicFolders
check getUser
check getScanStatus
check getArtists
check getIndexes
check getGenres

ARTIST=$(first getArtists "" '"id":"ar-[0-9a-f]*"')
ALBUM=$(first getAlbumList2 "type=alphabeticalByName&size=1" '"id":"al-[0-9a-f]*"')
SONG=$(first getAlbum "id=$ALBUM" '"id":"[0-9]*"')
GENRE=$(call getGenres | grep -o '"value":"[^"]*"' | head -1 | sed -E 's/.*"([^"]+)"$/\1/' | sed 's/ /%20/g')
echo "      (artist=$ARTIST album=$ALBUM song=$SONG genre=$GENRE)"

check getArtist "id=$ARTIST"
check getArtistInfo2 "id=$ARTIST"
check getArtistInfo "id=$ARTIST"
check getAlbum "id=$ALBUM"
check getAlbumInfo2 "id=$ALBUM"
check getSong "id=$SONG"
check getMusicDirectory "id=$ARTIST"
check getMusicDirectory "id=$ALBUM"
for type in alphabeticalByName alphabeticalByArtist newest recent frequent random starred highest; do
    check getAlbumList2 "type=$type&size=5"
done
check getAlbumList2 "type=byYear&fromYear=2000&toYear=1990"
check getAlbumList2 "type=byGenre&genre=$GENRE"
check getAlbumList "type=newest&size=5"
check getRandomSongs "size=5"
check getSongsByGenre "genre=$GENRE&count=5"
check getStarred
check getStarred2
check getTopSongs "artist=x"
check getSimilarSongs2 "id=$SONG&count=5"
check search3 "query=&songCount=500&songOffset=0"
check search3 "query=a"
check search2 "query=a"
check getLyricsBySongId "id=$SONG"
check getLyrics "artist=x&title=y"
check getPlaylists
check getPlaylist "id=pl-offline"
check getPlayQueue
check star "id=$SONG"
check unstar "id=$SONG"
for stub in getShares getInternetRadioStations getPodcasts getNewestPodcasts getNowPlaying getBookmarks; do
    check "$stub"
done

# Media (raw responses, not JSON)
CODE=$(curl -s "${AUTH[@]}" -o /dev/null -r 0-99 -w '%{http_code}' "$BASE/rest/stream?$COMMON&id=$SONG")
if [ "$CODE" = "206" ]; then echo "OK    stream (Range → 206)"; else echo "FAIL  stream (Range → $CODE)"; FAIL=$((FAIL + 1)); fi
CODE=$(curl -s "${AUTH[@]}" -o /dev/null -w '%{http_code}' "$BASE/rest/getCoverArt?$COMMON&id=$ALBUM&size=300")
if [ "$CODE" = "200" ]; then echo "OK    getCoverArt"; else echo "FAIL  getCoverArt ($CODE)"; FAIL=$((FAIL + 1)); fi

echo
[ "$FAIL" -eq 0 ] && echo "All Subsonic calls OK." || echo "$FAIL failure(s)."
exit "$FAIL"
