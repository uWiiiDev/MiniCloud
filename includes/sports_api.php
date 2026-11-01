<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' && basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('Zugriff verweigert.');
}

/**
 * MiniCloud – Multi-Sport Live-API-Adapter (Fußball International, NFL, NBA, NHL, ATP/WTA Tennis-Weltrangliste)
 * Nutzt öffentliche, schlüssellose JSON-Endpunkte mit MariaDB-Caching in `bundesliga_cache`.
 */

/**
 * Lädt eine beliebige JSON-URL mit MariaDB-Cache in `bundesliga_cache`.
 *
 * @return array{data: array<string, mixed>, cached_at: string, from_cache: bool}
 */
function fetchCachedSportsJson(PDO $pdo, string $cacheKey, string $url, int $ttlSeconds = 300, bool $forceRefresh = false): array
{
    $stmt = $pdo->prepare('SELECT json_data, updated_at FROM bundesliga_cache WHERE cache_key = ?');
    $stmt->execute([$cacheKey]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cached && !$forceRefresh) {
        $age = time() - (int)strtotime((string)$cached['updated_at']);
        if ($age >= 0 && $age <= $ttlSeconds) {
            $decoded = json_decode((string)$cached['json_data'], true);
            if (is_array($decoded)) {
                return [
                    'data'       => $decoded,
                    'cached_at'  => (string)$cached['updated_at'],
                    'from_cache' => true,
                ];
            }
        }
    }

    $raw = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 6,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_USERAGENT      => 'MiniCloud-Sports-Center/2.0',
                CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            ]);
            $res  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($res) && $code >= 200 && $code < 300) {
                $raw = $res;
            }
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 6,
                'header'  => "User-Agent: MiniCloud-Sports-Center/2.0\r\nAccept: application/json\r\n",
            ],
        ]);
        $res = @file_get_contents($url, false, $ctx);
        if (is_string($res) && $res !== '') {
            $raw = $res;
        }
    }

    if ($raw !== null) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $now = date('Y-m-d H:i:s');
            $up  = $pdo->prepare(
                'INSERT INTO bundesliga_cache (cache_key, json_data, updated_at)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE json_data = VALUES(json_data), updated_at = VALUES(updated_at)'
            );
            $up->execute([$cacheKey, $raw, $now]);
            return [
                'data'       => $decoded,
                'cached_at'  => $now,
                'from_cache' => false,
            ];
        }
    }

    if ($cached) {
        $decoded = json_decode((string)$cached['json_data'], true);
        if (is_array($decoded)) {
            return [
                'data'       => $decoded,
                'cached_at'  => (string)$cached['updated_at'],
                'from_cache' => true,
            ];
        }
    }

    return [
        'data'       => [],
        'cached_at'  => date('Y-m-d H:i:s'),
        'from_cache' => false,
    ];
}

/**
 * Lädt die aktuelle Tabelle für eine ESPN-Team-Liga (Premier League, LaLiga, Serie A, Champions League, NFL, NBA, NHL)
 * und konvertiert sie in das einheitliche Tabellenformat von `bundesliga.php`.
 *
 * @param array<string, mixed> $leagueMeta
 * @return array{data: array<int, array<string, mixed>>, cached_at: string}
 */
function fetchEspnStandingsTable(PDO $pdo, string $leagueKey, array $leagueMeta, bool $forceRefresh = false): array
{
    $sportSlug  = (string)($leagueMeta['espn_sport'] ?? 'soccer');
    $leagueSlug = (string)($leagueMeta['espn_league'] ?? 'eng.1');
    $url        = "https://site.api.espn.com/apis/v2/sports/{$sportSlug}/{$leagueSlug}/standings";

    $res  = fetchCachedSportsJson($pdo, "espn_standings_{$leagueKey}", $url, 600, $forceRefresh);
    $json = $res['data'];

    $groups = [];
    if (!empty($json['children']) && is_array($json['children'])) {
        $groups = $json['children'];
    } elseif (!empty($json['standings']['entries']) && is_array($json['standings']['entries'])) {
        $groups = [$json];
    }

    $rows = [];
    foreach ($groups as $grp) {
        $groupName = trim((string)($grp['name'] ?? $grp['abbreviation'] ?? ''));
        $entries   = $grp['standings']['entries'] ?? [];
        if (!is_array($entries)) {
            continue;
        }

        foreach ($entries as $entry) {
            $team = $entry['team'] ?? [];
            $teamId = (int)($team['id'] ?? 0);
            if ($teamId <= 0) {
                continue;
            }
            $teamName  = (string)($team['displayName'] ?? $team['name'] ?? 'Team');
            $shortName = (string)($team['shortDisplayName'] ?? $team['abbreviation'] ?? $teamName);
            $logoUrl   = '';
            if (!empty($team['logos'][0]['href'])) {
                $logoUrl = (string)$team['logos'][0]['href'];
            }

            $statMap = [];
            $statDisplay = [];
            foreach (($entry['stats'] ?? []) as $st) {
                $sName = (string)($st['name'] ?? '');
                if ($sName !== '') {
                    $statMap[$sName]     = $st['value'] ?? 0;
                    $statDisplay[$sName] = (string)($st['displayValue'] ?? $st['value'] ?? '');
                }
            }

            $won        = (int)($statMap['wins'] ?? 0);
            $lost       = (int)($statMap['losses'] ?? 0);
            $draw       = (int)($statMap['ties'] ?? $statMap['otLosses'] ?? 0);
            $matches    = (int)($statMap['gamesPlayed'] ?? ($won + $lost + $draw));
            $goalsFor   = (int)round((float)($statMap['pointsFor'] ?? 0));
            $goalsAg    = (int)round((float)($statMap['pointsAgainst'] ?? 0));
            $goalDiff   = (int)round((float)($statMap['pointDifferential'] ?? $statMap['differential'] ?? ($goalsFor - $goalsAg)));
            $points     = (int)round((float)($statMap['points'] ?? ($won * 2)));
            $winPct     = (float)($statMap['winPercent'] ?? ($matches > 0 ? ($won / $matches) : 0.0));
            $winPctStr  = (string)($statDisplay['winPercent'] ?? number_format($winPct, 3, '.', ''));
            $streakStr  = (string)($statDisplay['streak'] ?? '');
            $apiRank    = (int)($statMap['rank'] ?? $statMap['playoffSeed'] ?? 999);

            $rows[] = [
                'teamInfoId'    => $teamId,
                'teamName'      => $teamName,
                'shortName'     => $shortName,
                'teamIconUrl'   => $logoUrl,
                'matches'       => $matches,
                'won'           => $won,
                'draw'          => $draw,
                'lost'          => $lost,
                'goals'         => $goalsFor,
                'opponentGoals' => $goalsAg,
                'goalDiff'      => $goalDiff,
                'points'        => $points,
                '_win_pct'      => $winPct,
                '_win_pct_str'  => $winPctStr,
                '_streak'       => $streakStr,
                '_group_name'   => $groupName,
                '_api_rank'     => $apiRank,
            ];
        }
    }

    $isUsSport = in_array($sportSlug, ['basketball', 'football'], true);
    usort($rows, static function (array $a, array $b) use ($isUsSport): int {
        if ($isUsSport) {
            if (abs($b['_win_pct'] - $a['_win_pct']) > 0.0001) {
                return $b['_win_pct'] <=> $a['_win_pct'];
            }
            if ($b['won'] !== $a['won']) {
                return $b['won'] <=> $a['won'];
            }
            return $b['goalDiff'] <=> $a['goalDiff'];
        }
        if ($b['points'] !== $a['points']) {
            return $b['points'] <=> $a['points'];
        }
        if ($b['goalDiff'] !== $a['goalDiff']) {
            return $b['goalDiff'] <=> $a['goalDiff'];
        }
        return $b['goals'] <=> $a['goals'];
    });

    return [
        'data'      => $rows,
        'cached_at' => $res['cached_at'],
    ];
}

/**
 * Lädt die aktuellen Begegnungen / Ergebnisse eines ESPN-Scoreboards (Fußball, NFL, NBA, NHL, ATP/WTA)
 * und wandelt sie in das Match-Format von `bundesliga.php` um (inkl. Tippspiel-Unterstützung!).
 *
 * @param array<string, mixed> $leagueMeta
 * @return array{data: array<int, array<string, mixed>>, cached_at: string, label: string}
 */
function fetchEspnScoreboardMatches(PDO $pdo, string $leagueKey, array $leagueMeta, bool $forceRefresh = false): array
{
    $sportSlug  = (string)($leagueMeta['espn_sport'] ?? 'soccer');
    $leagueSlug = (string)($leagueMeta['espn_league'] ?? 'eng.1');
    $url        = "https://site.api.espn.com/apis/site/v2/sports/{$sportSlug}/{$leagueSlug}/scoreboard";

    $res    = fetchCachedSportsJson($pdo, "espn_scoreboard_{$leagueKey}", $url, 180, $forceRefresh);
    $json   = $res['data'];
    $events = $json['events'] ?? [];

    $roundLabel = 'Aktuelle Spiele & Ergebnisse';
    if (!empty($json['week']['number'])) {
        $roundLabel = 'Week ' . (int)$json['week']['number'];
    } elseif (!empty($json['leagues'][0]['season']['displayName'])) {
        $roundLabel = 'Saison ' . (string)$json['leagues'][0]['season']['displayName'];
    }

    $matches = [];
    if (!is_array($events)) {
        return ['data' => [], 'cached_at' => $res['cached_at'], 'label' => $roundLabel];
    }

    foreach ($events as $ev) {
        // Bei Tennis-Turnieren stecken die einzelnen Matches oft in groupings -> competitions
        $competitions = [];
        if (!empty($ev['competitions']) && is_array($ev['competitions'])) {
            $competitions = $ev['competitions'];
        } elseif (!empty($ev['groupings']) && is_array($ev['groupings'])) {
            foreach ($ev['groupings'] as $grp) {
                foreach (($grp['competitions'] ?? []) as $cItem) {
                    $competitions[] = $cItem;
                }
            }
        }

        $tournamentName = trim((string)($ev['name'] ?? ''));

        foreach ($competitions as $comp) {
            $competitors = $comp['competitors'] ?? [];
            if (!is_array($competitors) || count($competitors) < 2) {
                continue;
            }

            $home = null;
            $away = null;
            foreach ($competitors as $c) {
                if (($c['homeAway'] ?? '') === 'home') {
                    $home = $c;
                } elseif (($c['homeAway'] ?? '') === 'away') {
                    $away = $c;
                }
            }
            if (!$home) {
                $home = $competitors[0];
            }
            if (!$away) {
                $away = $competitors[1];
            }

            $rawId = (string)($comp['id'] ?? $ev['id'] ?? '');
            $matchId = (int)preg_replace('/\D+/', '', $rawId);
            if ($matchId <= 0) {
                $matchId = abs(crc32($leagueKey . '_' . $rawId));
            }

            // Team- oder Spielerdaten extrahieren
            $extractSide = static function (array $side): array {
                if (!empty($side['team']) && is_array($side['team'])) {
                    $t = $side['team'];
                    $id = (int)($t['id'] ?? 0);
                    $name = (string)($t['displayName'] ?? $t['name'] ?? 'Team');
                    $short = (string)($t['shortDisplayName'] ?? $t['abbreviation'] ?? $name);
                    $logo = (string)($t['logo'] ?? $t['logos'][0]['href'] ?? '');
                    return ['teamId' => $id, 'teamName' => $name, 'shortName' => $short, 'teamIconUrl' => $logo];
                }
                if (!empty($side['athlete']) && is_array($side['athlete'])) {
                    $a = $side['athlete'];
                    $id = (int)($a['id'] ?? 0);
                    $name = (string)($a['displayName'] ?? $a['shortName'] ?? 'Spieler');
                    $short = (string)($a['shortName'] ?? $name);
                    $logo = (string)($a['flag']['href'] ?? $a['headshot']['href'] ?? '');
                    return ['teamId' => $id, 'teamName' => $name, 'shortName' => $short, 'teamIconUrl' => $logo];
                }
                return ['teamId' => 0, 'teamName' => 'Team', 'shortName' => 'Team', 'teamIconUrl' => ''];
            };

            $t1 = $extractSide($home);
            $t2 = $extractSide($away);

            $state     = strtolower((string)($comp['status']['type']['state'] ?? 'pre'));
            $completed = !empty($comp['status']['type']['completed']) || $state === 'post';
            $clockStr  = trim((string)($comp['status']['type']['shortDetail'] ?? $comp['status']['displayClock'] ?? ''));
            $dateStr   = (string)($comp['date'] ?? $ev['date'] ?? '');
            $koTs      = $dateStr !== '' ? (int)strtotime($dateStr) : 0;

            $s1 = isset($home['score']) && $home['score'] !== '' ? (int)$home['score'] : null;
            $s2 = isset($away['score']) && $away['score'] !== '' ? (int)$away['score'] : null;

            // Bei Tennis: gewonnene Sätze zählen, falls score leer ist
            $periods = [];
            if (!empty($home['linescores']) && is_array($home['linescores']) && !empty($away['linescores']) && is_array($away['linescores'])) {
                $sets1 = 0;
                $sets2 = 0;
                $maxP = min(count($home['linescores']), count($away['linescores']));
                for ($i = 0; $i < $maxP; $i++) {
                    $v1 = (int)($home['linescores'][$i]['value'] ?? 0);
                    $v2 = (int)($away['linescores'][$i]['value'] ?? 0);
                    $periods[] = "{$v1}:{$v2}";
                    if (!empty($home['linescores'][$i]['winner'])) {
                        $sets1++;
                    } elseif (!empty($away['linescores'][$i]['winner'])) {
                        $sets2++;
                    }
                }
                if ($sportSlug === 'tennis' && ($state === 'in' || $completed)) {
                    $s1 = $sets1;
                    $s2 = $sets2;
                }
            }

            $matchResults = [];
            if (($state === 'in' || $completed) && $s1 !== null && $s2 !== null) {
                $matchResults[] = [
                    'resultTypeID'  => 2,
                    'resultOrderID' => 2,
                    'pointsTeam1'   => $s1,
                    'pointsTeam2'   => $s2,
                ];
            }

            $venueName = (string)($comp['venue']['fullName'] ?? $tournamentName);
            $venueCity = (string)($comp['venue']['address']['city'] ?? '');

            $matches[] = [
                'matchID'         => $matchId,
                'matchDateTime'   => $koTs > 0 ? date('Y-m-d\TH:i:s', $koTs) : '',
                'matchIsFinished' => $completed,
                '_live_state'     => $completed ? 'post' : ($state === 'in' ? 'in' : 'pre'),
                '_live_clock'     => $clockStr,
                '_periods_custom' => !empty($periods) ? implode(' • ', $periods) : '',
                'team1'           => $t1,
                'team2'           => $t2,
                'matchResults'    => $matchResults,
                'goals'           => [],
                'location'        => [
                    'locationStadium' => $venueName,
                    'locationCity'    => $venueCity,
                ],
                'numberOfViewers' => (int)($comp['attendance'] ?? 0),
            ];

            if (count($matches) >= 30) {
                break 2;
            }
        }
    }

    return [
        'data'      => $matches,
        'cached_at' => $res['cached_at'],
        'label'     => $roundLabel,
    ];
}

/**
 * Lädt die offizielle ATP- (Herren) oder WTA- (Damen) Tennis-Weltrangliste (Top 100).
 *
 * @return array{data: array<int, array<string, mixed>>, cached_at: string}
 */
function fetchEspnTennisRankings(PDO $pdo, string $tourKey, bool $forceRefresh = false): array
{
    $tour = $tourKey === 'wta' ? 'wta' : 'atp';
    $url  = "https://site.api.espn.com/apis/site/v2/sports/tennis/{$tour}/rankings";

    $res   = fetchCachedSportsJson($pdo, "espn_tennis_rank_{$tour}", $url, 1800, $forceRefresh);
    $json  = $res['data'];
    $ranks = $json['rankings'][0]['ranks'] ?? [];

    if (!is_array($ranks)) {
        return ['data' => [], 'cached_at' => $res['cached_at']];
    }

    $rows = [];
    foreach (array_slice($ranks, 0, 100) as $r) {
        $ath       = $r['athlete'] ?? [];
        $athId     = (int)($ath['id'] ?? 0);
        $name      = (string)($ath['displayName'] ?? 'Spieler');
        $shortName = (string)($ath['shortname'] ?? $name);
        $headshot  = (string)($ath['headshot'] ?? '');
        $flagUrl   = (string)($ath['flag'] ?? '');
        $country   = (string)($ath['citizenshipCountry'] ?? '');
        $age       = (int)($ath['age'] ?? 0);
        $curRank   = (int)($r['current'] ?? 0);
        $prevRank  = (int)($r['previous'] ?? $curRank);
        $points    = (int)round((float)($r['points'] ?? 0));
        $diffRank  = $prevRank > 0 ? ($prevRank - $curRank) : 0;

        $rows[] = [
            'teamInfoId'    => $athId > 0 ? $athId : $curRank,
            'teamName'      => $name,
            'shortName'     => $shortName,
            'teamIconUrl'   => $headshot !== '' ? $headshot : $flagUrl,
            '_flag_url'     => $flagUrl,
            '_country'      => $country,
            '_age'          => $age,
            '_prev_rank'    => $prevRank,
            'matches'       => $prevRank,
            'won'           => 0,
            'draw'          => 0,
            'lost'          => 0,
            'goals'         => 0,
            'opponentGoals' => 0,
            'goalDiff'      => $diffRank,
            'points'        => $points,
            '_is_tennis'    => true,
        ];
    }

    return [
        'data'      => $rows,
        'cached_at' => $res['cached_at'],
    ];
}
