<?php
declare(strict_types=1);

/**
 * MiniCloud – 🎤 Live-Mentimeter & Interaktive Präsentationen (menti.php)
 *
 * Funktionen:
 * - Beitritt per 6-stelligem PIN-Code oder 100 % lokal generiertem SVG-QR-Code (ohne Konto-Pflicht für Gäste!)
 * - 6 interaktive Folientypen:
 *   1. ☁️ Wortwolke (Word Cloud – mehrfach genannte Begriffe wachsen live größer)
 *   2. 📊 Multiple Choice / Live-Balkendiagramm (mit Prozenten & Stimmzahlen)
 *   3. 🎚️ Skala / Stimmungsbarometer (1 bis 10 mit Live-Durchschnitt Ø)
 *   4. 💬 Offene Antworten / Ideen-Pinnwand (Live-Karten)
 *   5. 🙋 Q&A Publikumsfragen mit Live-👍-Upvoting
 *   6. 🏆 Live-Quiz mit richtiger Antwort, Geschwindigkeits-Punkten & Bestenliste (Leaderboard)
 * - Echtzeit-Synchronisation (2-Sekunden-Polling): Schaltet der Moderator am Beamer eine Folie weiter,
 *   wechselt die Anzeige auf allen Smartphones im Publikum vollautomatisch mit!
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

$pdo = getDB();
$appSettings = getAppSettings($pdo);

if (($appSettings['enable_menti'] ?? '1') !== '1' && !isAdmin()) {
    setFlash('error', 'Das Live-Mentimeter-Modul ist aktuell deaktiviert.');
    header('Location: index.php');
    exit;
}

$isLoggedIn = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0;
$currentUserId = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;
$currentUsername = $isLoggedIn ? (string)($_SESSION['username'] ?? '') : '';
$isAdminUser = $isLoggedIn && isAdmin();

// Eindeutige Teilnehmer-Kennung für Smartphones (Cookie + Session, kein Konto nötig)
if (empty($_SESSION['menti_participant_token'])) {
    if (!empty($_COOKIE['mc_menti_ptok']) && preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['mc_menti_ptok'])) {
        $_SESSION['menti_participant_token'] = (string)$_COOKIE['mc_menti_ptok'];
    } else {
        $newPtok = bin2hex(random_bytes(16));
        $_SESSION['menti_participant_token'] = $newPtok;
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @setcookie('mc_menti_ptok', $newPtok, [
            'expires'  => time() + 86400 * 90,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
$participantToken = (string)$_SESSION['menti_participant_token'];
$savedParticipantName = trim((string)($_SESSION['menti_participant_name'] ?? $currentUsername));

/**
 * Erzeugt einen noch nicht vergebenen 6-stelligen numerischen PIN-Code.
 */
function generateUniqueMentiPin(PDO $pdo): string
{
    for ($i = 0; $i < 25; $i++) {
        $pin = (string)random_int(100000, 999999);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `menti_sessions` WHERE `pin_code` = :pin");
        $stmt->execute([':pin' => $pin]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $pin;
        }
    }
    return (string)random_int(100000, 999999);
}

/**
 * Formatiert einen 6-stelligen PIN-Code lesefreundlich als "123 456".
 */
function formatMentiPin(string $pin): string
{
    $digits = preg_replace('/\D+/', '', $pin) ?? $pin;
    if (strlen($digits) === 6) {
        return substr($digits, 0, 3) . ' ' . substr($digits, 3, 3);
    }
    return $pin;
}

/**
 * Berechnet alle aggregierten Live-Daten einer Sitzung und ihrer aktuellen Folie für Beamer & Smartphones.
 *
 * @return array<string, mixed>|null
 */
function buildMentiLiveState(PDO $pdo, array $session, string $participantToken): ?array
{
    $sessionId = (int)$session['id'];

    $stmtSlides = $pdo->prepare("
        SELECT * FROM `menti_slides`
        WHERE `session_id` = :sid
        ORDER BY `slide_order` ASC, `id` ASC
    ");
    $stmtSlides->execute([':sid' => $sessionId]);
    $slides = $stmtSlides->fetchAll();

    $activeSlideId = (int)($session['active_slide_id'] ?? 0);
    $activeSlide = null;
    $activeIndex = 0;

    foreach ($slides as $idx => $sl) {
        if ((int)$sl['id'] === $activeSlideId) {
            $activeSlide = $sl;
            $activeIndex = $idx;
            break;
        }
    }
    if ($activeSlide === null && !empty($slides)) {
        $activeSlide = $slides[0];
        $activeSlideId = (int)$activeSlide['id'];
        $activeIndex = 0;
    }

    // Gesamtzahl eindeutiger Teilnehmer in dieser Session
    $stmtPartCount = $pdo->prepare("SELECT COUNT(DISTINCT `participant_token`) FROM `menti_responses` WHERE `session_id` = :sid");
    $stmtPartCount->execute([':sid' => $sessionId]);
    $totalSessionParticipants = (int)$stmtPartCount->fetchColumn();

    // Quiz-Bestenliste (Leaderboard) über alle Quiz-Folien dieser Session
    $stmtLeader = $pdo->prepare("
        SELECT `participant_token`,
               MAX(COALESCE(NULLIF(`participant_name`, ''), 'Teilnehmer')) AS `name`,
               SUM(`points`) AS `total_points`,
               COUNT(*) AS `answers_count`
        FROM `menti_responses`
        WHERE `session_id` = :sid AND `points` > 0
        GROUP BY `participant_token`
        ORDER BY `total_points` DESC, MIN(`id`) ASC
        LIMIT 15
    ");
    $stmtLeader->execute([':sid' => $sessionId]);
    $leaderboard = [];
    foreach ($stmtLeader->fetchAll() as $rankIdx => $lRow) {
        $leaderboard[] = [
            'rank'   => $rankIdx + 1,
            'name'   => (string)$lRow['name'],
            'points' => (int)$lRow['total_points'],
            'is_me'  => ((string)$lRow['participant_token'] === $participantToken),
        ];
    }

    if ($activeSlide === null) {
        return [
            'session_id'       => $sessionId,
            'title'            => (string)$session['title'],
            'pin_formatted'    => formatMentiPin((string)$session['pin_code']),
            'show_results'     => (int)$session['show_results'] === 1,
            'voting_locked'    => (int)$session['voting_locked'] === 1,
            'is_active'        => (int)$session['is_active'] === 1,
            'slide_count'      => 0,
            'active_index'     => 0,
            'active_slide'     => null,
            'total_participants' => $totalSessionParticipants,
            'leaderboard'      => $leaderboard,
        ];
    }

    $slideType = (string)$activeSlide['slide_type'];
    $options = json_decode((string)($activeSlide['options_json'] ?? '[]'), true);
    if (!is_array($options)) {
        $options = [];
    }

    $stmtResp = $pdo->prepare("
        SELECT * FROM `menti_responses`
        WHERE `slide_id` = :slid
        ORDER BY `id` ASC
    ");
    $stmtResp->execute([':slid' => $activeSlideId]);
    $responses = $stmtResp->fetchAll();

    $hasVoted = false;
    $myAnswers = [];
    $uniqueSlideVoters = [];

    foreach ($responses as $r) {
        $ptok = (string)$r['participant_token'];
        $uniqueSlideVoters[$ptok] = true;
        if ($ptok === $participantToken) {
            $hasVoted = true;
            $myAnswers[] = (string)$r['answer_value'];
        }
    }

    $slideVoterCount = count($uniqueSlideVoters);
    $totalResponsesCount = count($responses);

    // Auswertung je nach Folientyp
    $aggregated = [
        'choices'   => [],
        'words'     => [],
        'scales'    => [],
        'open_list' => [],
        'qa_list'   => [],
    ];

    if ($slideType === 'choice' || $slideType === 'quiz') {
        $counts = array_fill(0, count($options), 0);
        foreach ($responses as $r) {
            $idx = (int)$r['answer_value'];
            if (isset($counts[$idx])) {
                $counts[$idx]++;
            }
        }
        $totalVotes = array_sum($counts);
        foreach ($options as $i => $optLabel) {
            $c = $counts[$i] ?? 0;
            $pct = $totalVotes > 0 ? round(($c / $totalVotes) * 100, 1) : 0.0;
            $aggregated['choices'][] = [
                'index'      => $i,
                'label'      => (string)$optLabel,
                'count'      => $c,
                'percent'    => $pct,
                'is_correct' => ($slideType === 'quiz' && $activeSlide['correct_option'] !== null && (int)$activeSlide['correct_option'] === $i),
            ];
        }
    } elseif ($slideType === 'wordcloud') {
        $wordMap = [];
        $displayMap = [];
        foreach ($responses as $r) {
            $rawWords = json_decode((string)$r['answer_value'], true);
            if (!is_array($rawWords)) {
                $rawWords = [(string)$r['answer_value']];
            }
            foreach ($rawWords as $w) {
                $clean = trim(preg_replace('/\s+/', ' ', (string)$w) ?? '');
                if ($clean === '') {
                    continue;
                }
                $lower = mb_strtolower($clean, 'UTF-8');
                if (!isset($wordMap[$lower])) {
                    $wordMap[$lower] = 0;
                    $displayMap[$lower] = $clean;
                }
                $wordMap[$lower]++;
            }
        }
        arsort($wordMap);
        $maxWeight = !empty($wordMap) ? max($wordMap) : 1;
        foreach (array_slice($wordMap, 0, 60, true) as $lowerKey => $cnt) {
            $aggregated['words'][] = [
                'text'   => $displayMap[$lowerKey],
                'count'  => $cnt,
                'weight' => $maxWeight > 0 ? round($cnt / $maxWeight, 2) : 1.0,
            ];
        }
    } elseif ($slideType === 'scale') {
        $statements = !empty($options) ? $options : ['Bewertung (1 = Stimme nicht zu, 10 = Stimme voll zu)'];
        $sums = array_fill(0, count($statements), 0.0);
        $cnts = array_fill(0, count($statements), 0);
        foreach ($responses as $r) {
            $vals = json_decode((string)$r['answer_value'], true);
            if (!is_array($vals)) {
                $vals = [0 => (int)$r['answer_value']];
            }
            foreach ($statements as $sIdx => $_) {
                if (isset($vals[$sIdx])) {
                    $v = max(1, min(10, (int)$vals[$sIdx]));
                    $sums[$sIdx] += $v;
                    $cnts[$sIdx]++;
                }
            }
        }
        foreach ($statements as $sIdx => $sLabel) {
            $c = $cnts[$sIdx];
            $avg = $c > 0 ? round($sums[$sIdx] / $c, 1) : 0.0;
            $aggregated['scales'][] = [
                'index'   => $sIdx,
                'label'   => (string)$sLabel,
                'average' => $avg,
                'count'   => $c,
                'percent' => $avg > 0 ? round((($avg - 1) / 9) * 100, 1) : 0.0,
            ];
        }
    } elseif ($slideType === 'open') {
        foreach (array_reverse($responses) as $r) {
            $aggregated['open_list'][] = [
                'id'   => (int)$r['id'],
                'name' => (string)($r['participant_name'] ?: 'Anonym'),
                'text' => (string)$r['answer_value'],
                'time' => date('H:i', strtotime((string)$r['created_at']) ?: time()),
            ];
        }
    } elseif ($slideType === 'qa') {
        // Nach Upvotes absteigend sortieren
        $qaRows = $responses;
        usort($qaRows, static function (array $a, array $b): int {
            $cmp = ((int)$b['upvotes']) <=> ((int)$a['upvotes']);
            return $cmp !== 0 ? $cmp : (((int)$a['id']) <=> ((int)$b['id']));
        });
        foreach ($qaRows as $r) {
            $upvotedBy = json_decode((string)($r['upvoted_by_json'] ?? '[]'), true);
            if (!is_array($upvotedBy)) {
                $upvotedBy = [];
            }
            $aggregated['qa_list'][] = [
                'id'         => (int)$r['id'],
                'name'       => (string)($r['participant_name'] ?: 'Teilnehmer'),
                'text'       => (string)$r['answer_value'],
                'upvotes'    => (int)$r['upvotes'],
                'upvoted_me' => in_array($participantToken, $upvotedBy, true),
                'time'       => date('H:i', strtotime((string)$r['created_at']) ?: time()),
            ];
        }
    }

    return [
        'session_id'         => $sessionId,
        'title'              => (string)$session['title'],
        'pin_formatted'      => formatMentiPin((string)$session['pin_code']),
        'show_results'       => (int)$session['show_results'] === 1,
        'voting_locked'      => (int)$session['voting_locked'] === 1,
        'is_active'          => (int)$session['is_active'] === 1,
        'slide_count'        => count($slides),
        'active_index'       => $activeIndex,
        'prev_slide_id'      => $activeIndex > 0 ? (int)$slides[$activeIndex - 1]['id'] : null,
        'next_slide_id'      => $activeIndex + 1 < count($slides) ? (int)$slides[$activeIndex + 1]['id'] : null,
        'total_participants' => $totalSessionParticipants,
        'slide_voters'       => $slideVoterCount,
        'total_responses'    => $totalResponsesCount,
        'has_voted'          => $hasVoted,
        'my_answers'         => $myAnswers,
        'leaderboard'        => $leaderboard,
        'active_slide'       => [
            'id'             => $activeSlideId,
            'type'           => $slideType,
            'question'       => (string)$activeSlide['question'],
            'subtitle'       => (string)($activeSlide['subtitle'] ?? ''),
            'options'        => $options,
            'correct_option' => $activeSlide['correct_option'] !== null ? (int)$activeSlide['correct_option'] : null,
            'aggregated'     => $aggregated,
        ],
    ];
}

// ============================================================================
// 1. PIN-Suche: Weiterleitung auf den Teilnahme-Link menti.php?join=<token>
// ============================================================================
if (isset($_GET['pin'])) {
    $rawPin = preg_replace('/\D+/', '', (string)$_GET['pin']) ?? '';
    if ($rawPin !== '') {
        $clientIp = getClientIp();
        if (isLoginRateLimited($pdo, $clientIp)) {
            setFlash('error', 'Zu viele fehlgeschlagene PIN-Versuche. Bitte warte 5 Minuten.');
            header('Location: menti.php');
            exit;
        }
        $stmtPin = $pdo->prepare("SELECT `public_token` FROM `menti_sessions` WHERE `pin_code` = :pin LIMIT 1");
        $stmtPin->execute([':pin' => $rawPin]);
        $foundToken = $stmtPin->fetchColumn();
        if ($foundToken !== false && $foundToken !== '') {
            header('Location: menti.php?join=' . urlencode((string)$foundToken));
            exit;
        }
        recordLoginFailure($pdo, $clientIp, 'menti_pin');
        setFlash('error', 'Unter dem PIN-Code „' . formatMentiPin($rawPin) . '“ wurde keine aktive Live-Präsentation gefunden.');
        header('Location: menti.php');
        exit;
    }
}

// ============================================================================
// 2. AJAX-Endpunkt: Live-State abrufen (für Beamer-Präsentation & Smartphones)
// ============================================================================
if (isset($_GET['ajax_state'])) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $token = trim((string)($_GET['token'] ?? ''));
    $sessionId = (int)($_GET['session_id'] ?? 0);

    if ($token !== '') {
        $stmtS = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `public_token` = :tok LIMIT 1");
        $stmtS->execute([':tok' => $token]);
    } else {
        $stmtS = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `id` = :id LIMIT 1");
        $stmtS->execute([':id' => $sessionId]);
    }
    $sess = $stmtS->fetch();
    if (!$sess) {
        echo json_encode(['ok' => false, 'error' => 'Session nicht gefunden.']);
        exit;
    }

    $state = buildMentiLiveState($pdo, $sess, $participantToken);
    echo json_encode(['ok' => true, 'state' => $state], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

// ============================================================================
// 3. POST-Aktionen für Teilnehmer (Smartphone-Stimmabgabe & Q&A-Upvote, ohne Login)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['participant_action'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $pAction = (string)$_POST['participant_action'];
    $token = trim((string)($_POST['token'] ?? ''));

    $stmtS = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `public_token` = :tok LIMIT 1");
    $stmtS->execute([':tok' => $token]);
    $sess = $stmtS->fetch();
    if (!$sess || (int)$sess['is_active'] !== 1) {
        echo json_encode(['ok' => false, 'error' => 'Diese Live-Präsentation ist nicht aktiv.']);
        exit;
    }
    if ((int)$sess['voting_locked'] === 1) {
        echo json_encode(['ok' => false, 'error' => 'Die Abstimmung für diese Folie ist aktuell durch den Moderator gesperrt.']);
        exit;
    }

    $sessionId = (int)$sess['id'];
    $slideId = (int)($_POST['slide_id'] ?? 0);
    $stmtSl = $pdo->prepare("SELECT * FROM `menti_slides` WHERE `id` = :slid AND `session_id` = :sid LIMIT 1");
    $stmtSl->execute([':slid' => $slideId, ':sid' => $sessionId]);
    $slide = $stmtSl->fetch();
    if (!$slide) {
        echo json_encode(['ok' => false, 'error' => 'Folie nicht gefunden.']);
        exit;
    }

    $pName = trim((string)($_POST['participant_name'] ?? $savedParticipantName));
    $pName = mb_substr($pName, 0, 60, 'UTF-8');
    if ($pName !== '') {
        $_SESSION['menti_participant_name'] = $pName;
    }

    if ($pAction === 'submit_vote') {
        $slideType = (string)$slide['slide_type'];

        // Bei allen Typen außer Q&A und Offene Antworten nur 1 Einreichung pro Teilnehmer & Folie;
        // bei Q&A und Offenen Antworten max. 10 Beiträge pro Teilnehmer & Folie (DoS-/Spam-Schutz)
        $stmtChk = $pdo->prepare("SELECT COUNT(*) FROM `menti_responses` WHERE `slide_id` = :slid AND `participant_token` = :ptok");
        $stmtChk->execute([':slid' => $slideId, ':ptok' => $participantToken]);
        $mySlideRespCount = (int)$stmtChk->fetchColumn();

        if (!in_array($slideType, ['qa', 'open'], true) && $mySlideRespCount >= 1) {
            echo json_encode(['ok' => false, 'error' => 'Du hast für diese Folie bereits abgestimmt.']);
            exit;
        }
        if (in_array($slideType, ['qa', 'open'], true) && $mySlideRespCount >= 10) {
            echo json_encode(['ok' => false, 'error' => 'Maximal 10 Beiträge pro Teilnehmer auf dieser Folie erreicht.']);
            exit;
        }

        $answerVal = '';
        $points = 0;

        if ($slideType === 'choice' || $slideType === 'quiz') {
            $choiceIdx = (int)($_POST['choice_index'] ?? -1);
            $opts = json_decode((string)($slide['options_json'] ?? '[]'), true) ?: [];
            if ($choiceIdx < 0 || $choiceIdx >= count($opts)) {
                echo json_encode(['ok' => false, 'error' => 'Bitte wähle eine gültige Option aus.']);
                exit;
            }
            $answerVal = (string)$choiceIdx;

            if ($slideType === 'quiz' && $slide['correct_option'] !== null && (int)$slide['correct_option'] === $choiceIdx) {
                // Geschwindigkeits-Bonus: Erste richtige Antworten bekommen bis zu 1000 Punkte, danach absteigend (mind. 600)
                $stmtCorrectCnt = $pdo->prepare("SELECT COUNT(*) FROM `menti_responses` WHERE `slide_id` = :slid AND `points` > 0");
                $stmtCorrectCnt->execute([':slid' => $slideId]);
                $priorCorrect = (int)$stmtCorrectCnt->fetchColumn();
                $points = max(600, 1000 - ($priorCorrect * 35));
            }
        } elseif ($slideType === 'wordcloud') {
            $wordsRaw = $_POST['words'] ?? [];
            if (!is_array($wordsRaw)) {
                $wordsRaw = [$wordsRaw];
            }
            $cleanWords = [];
            foreach ($wordsRaw as $w) {
                $cw = trim(preg_replace('/\s+/', ' ', (string)$w) ?? '');
                $cw = mb_substr($cw, 0, 45, 'UTF-8');
                if ($cw !== '') {
                    $cleanWords[] = $cw;
                }
            }
            if (empty($cleanWords)) {
                echo json_encode(['ok' => false, 'error' => 'Bitte gib mindestens einen Begriff ein.']);
                exit;
            }
            $answerVal = json_encode(array_slice($cleanWords, 0, 3), JSON_UNESCAPED_UNICODE);
        } elseif ($slideType === 'scale') {
            $scaleRaw = $_POST['scale_values'] ?? [];
            if (!is_array($scaleRaw)) {
                $scaleRaw = [0 => (int)$scaleRaw];
            }
            $cleanScale = [];
            foreach ($scaleRaw as $k => $v) {
                $cleanScale[(int)$k] = max(1, min(10, (int)$v));
            }
            $answerVal = json_encode($cleanScale);
        } elseif ($slideType === 'open' || $slideType === 'qa') {
            $txt = trim((string)($_POST['text_answer'] ?? ''));
            $txt = mb_substr($txt, 0, 500, 'UTF-8');
            if ($txt === '') {
                echo json_encode(['ok' => false, 'error' => 'Bitte gib einen Text ein.']);
                exit;
            }
            $answerVal = $txt;
        }

        $stmtIns = $pdo->prepare("
            INSERT INTO `menti_responses` (`session_id`, `slide_id`, `participant_token`, `participant_name`, `answer_value`, `points`, `upvotes`, `upvoted_by_json`)
            VALUES (:sid, :slid, :ptok, :pname, :aval, :pts, :upv, :upv_json)
        ");
        $stmtIns->execute([
            ':sid'      => $sessionId,
            ':slid'     => $slideId,
            ':ptok'     => $participantToken,
            ':pname'    => $pName !== '' ? $pName : null,
            ':aval'     => $answerVal,
            ':pts'      => $points,
            ':upv'      => $slideType === 'qa' ? 1 : 0,
            ':upv_json' => $slideType === 'qa' ? json_encode([$participantToken]) : null,
        ]);

        $state = buildMentiLiveState($pdo, $sess, $participantToken);
        echo json_encode(['ok' => true, 'state' => $state], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    if ($pAction === 'toggle_upvote') {
        $respId = (int)($_POST['response_id'] ?? 0);
        $stmtR = $pdo->prepare("SELECT * FROM `menti_responses` WHERE `id` = :rid AND `slide_id` = :slid LIMIT 1");
        $stmtR->execute([':rid' => $respId, ':slid' => $slideId]);
        $rRow = $stmtR->fetch();
        if ($rRow) {
            $upvotedBy = json_decode((string)($rRow['upvoted_by_json'] ?? '[]'), true);
            if (!is_array($upvotedBy)) {
                $upvotedBy = [];
            }
            if (in_array($participantToken, $upvotedBy, true)) {
                $upvotedBy = array_values(array_diff($upvotedBy, [$participantToken]));
            } else {
                $upvotedBy[] = $participantToken;
            }
            $newCount = count($upvotedBy);
            $stmtUpd = $pdo->prepare("UPDATE `menti_responses` SET `upvotes` = :cnt, `upvoted_by_json` = :j WHERE `id` = :rid");
            $stmtUpd->execute([
                ':cnt' => $newCount,
                ':j'   => json_encode($upvotedBy),
                ':rid' => $respId,
            ]);
        }
        $state = buildMentiLiveState($pdo, $sess, $participantToken);
        echo json_encode(['ok' => true, 'state' => $state], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unbekannte Aktion.']);
    exit;
}

// ============================================================================
// 4. Moderator-POST-Aktionen (erfordert eingeloggten Besitzer oder Admin)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireLogin();
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Ungültiges Sicherheits-Token (CSRF).');
        header('Location: menti.php');
        exit;
    }

    $action = (string)$_POST['action'];

    // 4a. Neue Mentimeter-Session anlegen (optional mit 6 fertigen Beispiel-Folien)
    if ($action === 'create_session') {
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $withDemoSlides = isset($_POST['with_demo_slides']) && $_POST['with_demo_slides'] === '1';

        if ($title === '') {
            setFlash('error', 'Bitte gib einen Titel für die Live-Präsentation ein.');
            header('Location: menti.php');
            exit;
        }

        $pin = generateUniqueMentiPin($pdo);
        $token = bin2hex(random_bytes(16));

        $stmtIns = $pdo->prepare("
            INSERT INTO `menti_sessions` (`user_id`, `title`, `description`, `pin_code`, `public_token`, `show_results`, `voting_locked`, `is_active`)
            VALUES (:uid, :title, :desc, :pin, :tok, 1, 0, 1)
        ");
        $stmtIns->execute([
            ':uid'   => $currentUserId,
            ':title' => mb_substr($title, 0, 200, 'UTF-8'),
            ':desc'  => $description !== '' ? $description : null,
            ':pin'   => $pin,
            ':tok'   => $token,
        ]);
        $newSessionId = (int)$pdo->lastInsertId();

        if ($withDemoSlides) {
            $demoSlides = [
                [
                    'type'     => 'wordcloud',
                    'question' => 'Welche 3 Begriffe verbinden Sie spontan mit unserem heutigen Thema?',
                    'subtitle' => 'Mehrfach genannte Wörter wachsen live auf der Leinwand!',
                    'options'  => [],
                    'correct'  => null,
                ],
                [
                    'type'     => 'choice',
                    'question' => 'Welcher Schwerpunkt ist Ihnen für das nächste Quartal am wichtigsten?',
                    'subtitle' => 'Wählen Sie Ihren Favoriten auf dem Smartphone',
                    'options'  => ['🚀 Neue Funktionen & Innovation', '🛡️ IT-Sicherheit & Datenschutz', '⚡ Tempo & Vereinfachung', '🤝 Team-Zusammenarbeit'],
                    'correct'  => null,
                ],
                [
                    'type'     => 'scale',
                    'question' => 'Stimmungsbarometer: Wie bewerten Sie die folgenden Aussagen?',
                    'subtitle' => 'Skala von 1 (Stimme gar nicht zu) bis 10 (Stimme voll zu)',
                    'options'  => ['Die Ziele sind klar und verständlich', 'Wir haben die richtigen Werkzeuge im Einsatz', 'Die Kommunikation im Team funktioniert gut'],
                    'correct'  => null,
                ],
                [
                    'type'     => 'quiz',
                    'question' => 'Quiz-Runde: Wie viele Teilnehmer dürfen in unserer MiniCloud gleichzeitig abstimmen?',
                    'subtitle' => 'Schnelligkeit bringt Extrapunkte für die Bestenliste!',
                    'options'  => ['Maximal 10 Teilnehmer', 'Maximal 50 Teilnehmer', 'Unbegrenzt viele & 100 % DSGVO-konform!', 'Nur mit kostenpflichtigem Abo'],
                    'correct'  => 2,
                ],
                [
                    'type'     => 'qa',
                    'question' => 'Haben Sie Fragen oder Themenwünsche an die Runde? (Q&A)',
                    'subtitle' => 'Reichen Sie eigene Fragen ein oder stimmen Sie mit 👍 für bestehende Fragen!',
                    'options'  => [],
                    'correct'  => null,
                ],
            ];
            $firstSlideId = null;
            $stmtSlIns = $pdo->prepare("
                INSERT INTO `menti_slides` (`session_id`, `slide_order`, `slide_type`, `question`, `subtitle`, `options_json`, `correct_option`)
                VALUES (:sid, :ord, :type, :q, :sub, :opts, :corr)
            ");
            foreach ($demoSlides as $idx => $ds) {
                $stmtSlIns->execute([
                    ':sid'  => $newSessionId,
                    ':ord'  => $idx + 1,
                    ':type' => $ds['type'],
                    ':q'    => $ds['question'],
                    ':sub'  => $ds['subtitle'],
                    ':opts' => json_encode($ds['options'], JSON_UNESCAPED_UNICODE),
                    ':corr' => $ds['correct'],
                ]);
                if ($firstSlideId === null) {
                    $firstSlideId = (int)$pdo->lastInsertId();
                }
            }
            if ($firstSlideId !== null) {
                $pdo->prepare("UPDATE `menti_sessions` SET `active_slide_id` = :slid WHERE `id` = :sid")
                    ->execute([':slid' => $firstSlideId, ':sid' => $newSessionId]);
            }
        }

        setFlash('success', 'Live-Präsentation „' . $title . '“ (PIN: ' . formatMentiPin($pin) . ') erfolgreich angelegt.');
        header('Location: menti.php?manage=' . $newSessionId);
        exit;
    }

    // Prüfen, ob Session dem aktuellen Benutzer gehört (oder Admin)
    $sessionId = (int)($_POST['session_id'] ?? 0);
    $stmtOwn = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `id` = :id LIMIT 1");
    $stmtOwn->execute([':id' => $sessionId]);
    $ownedSession = $stmtOwn->fetch();

    if (!$ownedSession || ((int)$ownedSession['user_id'] !== $currentUserId && !$isAdminUser)) {
        if (isset($_POST['ajax_mod'])) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Keine Berechtigung.']);
            exit;
        }
        setFlash('error', 'Keine Berechtigung für diese Live-Präsentation.');
        header('Location: menti.php');
        exit;
    }

    // 4b. AJAX-Moderator-Steuerung im Beamer-Modus (Folie wechseln, Ergebnisse aufdecken, Sperren, Reset)
    if (isset($_POST['ajax_mod'])) {
        header('Content-Type: application/json; charset=UTF-8');

        if ($action === 'set_active_slide') {
            $targetSlideId = (int)($_POST['slide_id'] ?? 0);
            $stmtTarget = $pdo->prepare("SELECT * FROM `menti_slides` WHERE `id` = :slid AND `session_id` = :sid LIMIT 1");
            $stmtTarget->execute([':slid' => $targetSlideId, ':sid' => $sessionId]);
            $targetSlide = $stmtTarget->fetch();
            if ($targetSlide) {
                // Bei einer Quiz-Folie standardmäßig Ergebnisse erst verbergen, damit niemand abschreibt
                $autoShow = ((string)$targetSlide['slide_type'] === 'quiz') ? 0 : 1;
                $pdo->prepare("UPDATE `menti_sessions` SET `active_slide_id` = :slid, `show_results` = :sr, `voting_locked` = 0 WHERE `id` = :sid")
                    ->execute([':slid' => $targetSlideId, ':sr' => $autoShow, ':sid' => $sessionId]);
            }
        } elseif ($action === 'toggle_results') {
            $newVal = (int)$ownedSession['show_results'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE `menti_sessions` SET `show_results` = :sr WHERE `id` = :sid")
                ->execute([':sr' => $newVal, ':sid' => $sessionId]);
        } elseif ($action === 'toggle_lock') {
            $newVal = (int)$ownedSession['voting_locked'] === 1 ? 0 : 1;
            $pdo->prepare("UPDATE `menti_sessions` SET `voting_locked` = :vl WHERE `id` = :sid")
                ->execute([':vl' => $newVal, ':sid' => $sessionId]);
        } elseif ($action === 'clear_slide_responses') {
            $targetSlideId = (int)($_POST['slide_id'] ?? $ownedSession['active_slide_id']);
            $pdo->prepare("DELETE FROM `menti_responses` WHERE `session_id` = :sid AND `slide_id` = :slid")
                ->execute([':sid' => $sessionId, ':slid' => $targetSlideId]);
        }

        $stmtFresh = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `id` = :id LIMIT 1");
        $stmtFresh->execute([':id' => $sessionId]);
        $freshSess = $stmtFresh->fetch();
        $state = buildMentiLiveState($pdo, $freshSess, $participantToken);
        echo json_encode(['ok' => true, 'state' => $state], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit;
    }

    // 4c. Folie hinzufügen
    if ($action === 'add_slide') {
        $slideType = (string)($_POST['slide_type'] ?? 'choice');
        if (!in_array($slideType, ['wordcloud', 'choice', 'scale', 'open', 'qa', 'quiz'], true)) {
            $slideType = 'choice';
        }
        $question = trim((string)($_POST['question'] ?? ''));
        $subtitle = trim((string)($_POST['subtitle'] ?? ''));
        $rawOptions = (string)($_POST['options_text'] ?? '');
        $correctOption = isset($_POST['correct_option']) && $_POST['correct_option'] !== '' ? (int)$_POST['correct_option'] : null;

        if ($question === '') {
            setFlash('error', 'Bitte gib eine Frage bzw. Überschrift für die Folie ein.');
            header('Location: menti.php?manage=' . $sessionId);
            exit;
        }

        $optionsList = [];
        foreach (preg_split('/\r\n|\r|\n/', $rawOptions) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $optionsList[] = mb_substr($trimmed, 0, 180, 'UTF-8');
            }
        }

        if (in_array($slideType, ['choice', 'quiz'], true) && count($optionsList) < 2) {
            $optionsList = ['Option A', 'Option B', 'Option C'];
        } elseif ($slideType === 'scale' && empty($optionsList)) {
            $optionsList = ['Stimme der Aussage zu'];
        }

        if ($slideType === 'quiz' && ($correctOption === null || $correctOption < 0 || $correctOption >= count($optionsList))) {
            $correctOption = 0;
        }

        $stmtMaxOrd = $pdo->prepare("SELECT COALESCE(MAX(`slide_order`), 0) FROM `menti_slides` WHERE `session_id` = :sid");
        $stmtMaxOrd->execute([':sid' => $sessionId]);
        $nextOrder = (int)$stmtMaxOrd->fetchColumn() + 1;

        $stmtInsSl = $pdo->prepare("
            INSERT INTO `menti_slides` (`session_id`, `slide_order`, `slide_type`, `question`, `subtitle`, `options_json`, `correct_option`)
            VALUES (:sid, :ord, :type, :q, :sub, :opts, :corr)
        ");
        $stmtInsSl->execute([
            ':sid'  => $sessionId,
            ':ord'  => $nextOrder,
            ':type' => $slideType,
            ':q'    => mb_substr($question, 0, 300, 'UTF-8'),
            ':sub'  => $subtitle !== '' ? mb_substr($subtitle, 0, 300, 'UTF-8') : null,
            ':opts' => json_encode($optionsList, JSON_UNESCAPED_UNICODE),
            ':corr' => $slideType === 'quiz' ? $correctOption : null,
        ]);
        $newSlideId = (int)$pdo->lastInsertId();

        if (empty($ownedSession['active_slide_id'])) {
            $pdo->prepare("UPDATE `menti_sessions` SET `active_slide_id` = :slid WHERE `id` = :sid")
                ->execute([':slid' => $newSlideId, ':sid' => $sessionId]);
        }

        setFlash('success', 'Neue Folie wurde zur Präsentation hinzugefügt.');
        header('Location: menti.php?manage=' . $sessionId);
        exit;
    }

    // 4d. Folie löschen
    if ($action === 'delete_slide') {
        $slideId = (int)($_POST['slide_id'] ?? 0);
        $pdo->prepare("DELETE FROM `menti_slides` WHERE `id` = :slid AND `session_id` = :sid")
            ->execute([':slid' => $slideId, ':sid' => $sessionId]);

        if ((int)$ownedSession['active_slide_id'] === $slideId) {
            $stmtFirst = $pdo->prepare("SELECT `id` FROM `menti_slides` WHERE `session_id` = :sid ORDER BY `slide_order` ASC, `id` ASC LIMIT 1");
            $stmtFirst->execute([':sid' => $sessionId]);
            $nextActive = $stmtFirst->fetchColumn();
            $pdo->prepare("UPDATE `menti_sessions` SET `active_slide_id` = :slid WHERE `id` = :sid")
                ->execute([':slid' => $nextActive !== false ? (int)$nextActive : null, ':sid' => $sessionId]);
        }

        setFlash('success', 'Folie wurde gelöscht.');
        header('Location: menti.php?manage=' . $sessionId);
        exit;
    }

    // 4e. Alle Antworten einer Session zurücksetzen
    if ($action === 'reset_all_responses') {
        $pdo->prepare("DELETE FROM `menti_responses` WHERE `session_id` = :sid")
            ->execute([':sid' => $sessionId]);
        setFlash('success', 'Alle bisherigen Publikums-Antworten dieser Präsentation wurden zurückgesetzt.');
        header('Location: menti.php?manage=' . $sessionId);
        exit;
    }

    // 4f. Ganze Präsentation löschen
    if ($action === 'delete_session') {
        $pdo->prepare("DELETE FROM `menti_sessions` WHERE `id` = :sid")
            ->execute([':sid' => $sessionId]);
        setFlash('success', 'Live-Präsentation wurde gelöscht.');
        header('Location: menti.php');
        exit;
    }
}

$slideTypeMeta = [
    'wordcloud' => ['icon' => '☁️', 'label' => 'Wortwolke (Word Cloud)', 'badge' => 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300'],
    'choice'    => ['icon' => '📊', 'label' => 'Multiple Choice (Balkendiagramm)', 'badge' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300'],
    'scale'     => ['icon' => '🎚️', 'label' => 'Skala / Stimmungsbarometer (1–10)', 'badge' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'],
    'open'      => ['icon' => '💬', 'label' => 'Offene Antworten (Pinnwand)', 'badge' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'],
    'qa'        => ['icon' => '🙋', 'label' => 'Q&A Publikumsfragen (mit 👍 Upvote)', 'badge' => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'],
    'quiz'      => ['icon' => '🏆', 'label' => 'Live-Quiz (mit Punkten & Bestenliste)', 'badge' => 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300'],
];

// ============================================================================
// ANSICHT A: 📱 Smartphone-Teilnehmer-Ansicht (menti.php?join=<token>)
// ============================================================================
if (isset($_GET['join'])) {
    $joinToken = trim((string)$_GET['join']);
    $stmtJoin = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `public_token` = :tok LIMIT 1");
    $stmtJoin->execute([':tok' => $joinToken]);
    $joinSession = $stmtJoin->fetch();

    if (!$joinSession) {
        setFlash('error', 'Diese Live-Präsentation wurde nicht gefunden.');
        header('Location: menti.php');
        exit;
    }

    $initialState = buildMentiLiveState($pdo, $joinSession, $participantToken);
    $pageTitle = 'Live-Abstimmung: ' . (string)$joinSession['title'];
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="max-w-lg mx-auto py-2 px-1">
        <!-- Kopfbereich der Smartphone-Teilnahmeseite -->
        <div class="mc-card bg-gradient-to-br from-indigo-600 via-brand-600 to-violet-700 text-white rounded-2xl p-4 sm:p-5 shadow-md mb-4">
            <div class="flex items-center justify-between gap-2 text-xs font-semibold text-indigo-100 mb-1">
                <span class="inline-flex items-center gap-1.5">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    🎤 Live-Mentimeter
                </span>
                <span class="px-2.5 py-0.5 rounded-full bg-white/15 font-mono tracking-wider">
                    PIN: <?= e(formatMentiPin((string)$joinSession['pin_code'])) ?>
                </span>
            </div>
            <h1 class="text-lg sm:text-xl font-bold leading-snug"><?= e((string)$joinSession['title']) ?></h1>
            <div class="mt-3 flex items-center gap-2">
                <label for="menti-participant-name" class="text-xs text-indigo-100 shrink-0">Dein Name (optional):</label>
                <input type="text" id="menti-participant-name" value="<?= e($savedParticipantName) ?>" placeholder="z. B. Vorname / Spitzname"
                       class="w-full px-3 py-1.5 rounded-xl bg-white/15 border border-white/25 text-white placeholder-indigo-200 text-xs sm:text-sm focus:outline-none focus:ring-2 focus:ring-white/60">
            </div>
        </div>

        <!-- Dynamischer Folien-Container (wechselt automatisch mit dem Moderator mit!) -->
        <div id="menti-voter-stage" class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs min-h-[280px]">
            <div class="text-center py-10 text-slate-400 text-sm">Lade aktuelle Folie…</div>
        </div>

        <p class="text-center text-[11px] text-slate-400 dark:text-slate-500 mt-3">
            🔄 Synchronisiert automatisch in Echtzeit, sobald vorne auf die nächste Folie geschaltet wird.
        </p>
    </div>

    <script>
    (function() {
        const token = <?= json_encode($joinToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let currentState = <?= json_encode($initialState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let renderedSlideKey = '';
        const stageEl = document.getElementById('menti-voter-stage');
        const nameInput = document.getElementById('menti-participant-name');

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str == null ? '' : String(str);
            return d.innerHTML;
        }

        function buildSlideKey(st) {
            if (!st || !st.active_slide) return 'empty';
            const sl = st.active_slide;
            return [
                sl.id,
                st.has_voted ? '1' : '0',
                st.voting_locked ? '1' : '0',
                st.show_results ? '1' : '0',
                sl.type === 'qa' ? JSON.stringify(sl.aggregated.qa_list) : '',
                st.show_results ? JSON.stringify(sl.aggregated) : ''
            ].join('|');
        }

        function renderVoterStage(force) {
            const key = buildSlideKey(currentState);
            if (!force && key === renderedSlideKey) return;
            renderedSlideKey = key;

            if (!currentState || !currentState.active_slide) {
                stageEl.innerHTML = '<div class="text-center py-12"><div class="text-4xl mb-2">⏳</div><p class="font-semibold text-slate-700 dark:text-slate-200">Die Präsentation hat noch keine aktive Folie.</p><p class="text-xs text-slate-400 mt-1">Bitte warte einen Moment, bis der Moderator startet.</p></div>';
                return;
            }

            const sl = currentState.active_slide;
            const locked = currentState.voting_locked;
            const hasVoted = currentState.has_voted;

            let html = `
                <div class="flex items-center justify-between gap-2 text-xs text-slate-400 dark:text-slate-500 mb-2">
                    <span class="font-semibold uppercase tracking-wider">Folie ${currentState.active_index + 1} von ${currentState.slide_count}</span>
                    <span>👥 ${currentState.slide_voters} Teilnahmen</span>
                </div>
                <h2 class="text-base sm:text-lg font-bold text-slate-900 dark:text-white leading-snug mb-1">${esc(sl.question)}</h2>
                ${sl.subtitle ? `<p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mb-4">${esc(sl.subtitle)}</p>` : '<div class="mb-4"></div>'}
            `;

            if (locked && sl.type !== 'qa') {
                html += `<div class="p-4 rounded-xl bg-amber-50 dark:bg-amber-950/50 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 text-xs sm:text-sm font-medium mb-4">🔒 Die Abstimmung für diese Folie ist aktuell geschlossen.</div>`;
            }

            // Eingabeformular (sofern noch nicht abgestimmt oder bei Q&A / Offene Antworten)
            if (!locked && (!hasVoted || sl.type === 'qa' || sl.type === 'open')) {
                if (sl.type === 'choice' || sl.type === 'quiz') {
                    html += `<div class="space-y-2.5">`;
                    sl.options.forEach((opt, idx) => {
                        html += `
                            <button type="button" data-choice-idx="${idx}"
                                    class="menti-choice-btn w-full text-left p-3.5 sm:p-4 rounded-xl border-2 border-slate-200 dark:border-slate-700 hover:border-brand-500 dark:hover:border-brand-400 hover:bg-brand-50/50 dark:hover:bg-brand-950/30 font-semibold text-sm sm:text-base text-slate-800 dark:text-slate-100 transition-all flex items-center gap-3">
                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-brand-100 dark:bg-brand-950 text-brand-700 dark:text-brand-300 text-xs font-bold shrink-0">${String.fromCharCode(65 + idx)}</span>
                                <span>${esc(opt)}</span>
                            </button>
                        `;
                    });
                    html += `</div>`;
                } else if (sl.type === 'wordcloud') {
                    html += `
                        <form id="menti-vote-form" class="space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">1. Begriff *</label>
                                <input type="text" name="word1" required maxlength="45" placeholder="Begriff eingeben…"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">2. Begriff (optional)</label>
                                <input type="text" name="word2" maxlength="45" placeholder="Weiterer Begriff…"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">3. Begriff (optional)</label>
                                <input type="text" name="word3" maxlength="45" placeholder="Weiterer Begriff…"
                                       class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white">
                            </div>
                            <button type="submit" class="w-full py-3 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-sm shadow-sm transition-colors">
                                ☁️ Begriffe absenden
                            </button>
                        </form>
                    `;
                } else if (sl.type === 'scale') {
                    const statements = sl.options && sl.options.length ? sl.options : ['Bewertung (1 bis 10)'];
                    html += `<form id="menti-vote-form" class="space-y-4">`;
                    statements.forEach((stLabel, idx) => {
                        html += `
                            <div class="p-3.5 rounded-xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700">
                                <div class="flex items-center justify-between gap-2 mb-2">
                                    <span class="text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200">${esc(stLabel)}</span>
                                    <span id="scale-val-${idx}" class="px-2.5 py-0.5 rounded-lg bg-brand-600 text-white font-bold text-xs">5</span>
                                </div>
                                <input type="range" min="1" max="10" step="1" value="5" data-scale-idx="${idx}"
                                       oninput="document.getElementById('scale-val-${idx}').textContent = this.value"
                                       class="menti-scale-slider w-full accent-brand-600 cursor-pointer">
                                <div class="flex justify-between text-[11px] text-slate-400 mt-1">
                                    <span>1 (Stimme nicht zu)</span>
                                    <span>10 (Stimme voll zu)</span>
                                </div>
                            </div>
                        `;
                    });
                    html += `
                        <button type="submit" class="w-full py-3 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-sm shadow-sm transition-colors">
                            🎚️ Bewertung absenden
                        </button>
                    </form>`;
                } else if (sl.type === 'open' || sl.type === 'qa') {
                    html += `
                        <form id="menti-vote-form" class="space-y-3 mb-4">
                            <textarea name="text_answer" rows="3" required maxlength="500"
                                      placeholder="${sl.type === 'qa' ? 'Deine Frage an den Vortragenden eingeben…' : 'Deine Antwort / Idee eingeben…'}"
                                      class="w-full px-3.5 py-2.5 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-sm text-slate-900 dark:text-white"></textarea>
                            <button type="submit" class="w-full py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-sm shadow-sm transition-colors">
                                ${sl.type === 'qa' ? '🙋 Frage einreichen' : '💬 Beitrag absenden'}
                            </button>
                        </form>
                    `;
                }
            } else if (hasVoted && sl.type !== 'qa' && sl.type !== 'open') {
                html += `
                    <div class="p-4 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800/70 text-center mb-4">
                        <div class="text-2xl mb-1">✅</div>
                        <p class="text-sm font-bold text-emerald-900 dark:text-emerald-200">Deine Stimme ist eingegangen!</p>
                        <p class="text-xs text-emerald-700 dark:text-emerald-300/80 mt-0.5">Sobald vorne weitergeschaltet wird, erscheint hier automatisch die nächste Frage.</p>
                    </div>
                `;
            }

            // Bei Q&A immer die Fragenliste samt 👍 Upvote-Buttons auf dem Handy anzeigen
            if (sl.type === 'qa') {
                const qList = sl.aggregated.qa_list || [];
                html += `<div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-800 space-y-2.5">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Eingereichte Fragen (${qList.length}) – Jetzt mit 👍 abstimmen:</h3>`;
                if (qList.length === 0) {
                    html += `<p class="text-xs text-slate-400 py-3 text-center">Noch keine Fragen eingereicht – stelle die erste Frage!</p>`;
                } else {
                    qList.forEach(q => {
                        html += `
                            <div class="p-3 rounded-xl border ${q.upvoted_me ? 'border-brand-400 bg-brand-50/40 dark:bg-brand-950/30' : 'border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/40'} flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-xs sm:text-sm font-medium text-slate-900 dark:text-white break-words">${esc(q.text)}</p>
                                    <span class="text-[11px] text-slate-400">von ${esc(q.name)} • ${esc(q.time)}</span>
                                </div>
                                <button type="button" data-upvote-id="${q.id}"
                                        class="menti-upvote-btn shrink-0 inline-flex items-center gap-1 px-2.5 py-1.5 rounded-xl text-xs font-bold transition-colors ${q.upvoted_me ? 'bg-brand-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border border-slate-200 dark:border-slate-700'}">
                                    👍 <span>${q.upvotes}</span>
                                </button>
                            </div>
                        `;
                    });
                }
                html += `</div>`;
            }

            // Live-Zwischenstand auf dem Smartphone zeigen, wenn Ergebnisse freigegeben sind und abgestimmt wurde
            if (currentState.show_results && hasVoted && (sl.type === 'choice' || sl.type === 'quiz')) {
                html += `<div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-800 space-y-2">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Live-Ergebnis:</h3>`;
                (sl.aggregated.choices || []).forEach(ch => {
                    html += `
                        <div class="space-y-1">
                            <div class="flex justify-between text-xs font-semibold text-slate-700 dark:text-slate-200">
                                <span>${ch.is_correct ? '✅ ' : ''}${esc(ch.label)}</span>
                                <span>${ch.percent}% (${ch.count})</span>
                            </div>
                            <div class="w-full h-2.5 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                                <div class="h-full rounded-full ${ch.is_correct ? 'bg-emerald-500' : 'bg-brand-600'}" style="width: ${ch.percent}%"></div>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }

            stageEl.innerHTML = html;
            bindVoterEvents();
        }

        function bindVoterEvents() {
            const sl = currentState.active_slide;
            if (!sl) return;

            document.querySelectorAll('.menti-choice-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const idx = btn.getAttribute('data-choice-idx');
                    const fd = new FormData();
                    fd.append('participant_action', 'submit_vote');
                    fd.append('token', token);
                    fd.append('slide_id', sl.id);
                    fd.append('participant_name', nameInput ? nameInput.value : '');
                    fd.append('choice_index', idx);
                    sendParticipantPost(fd);
                });
            });

            const voteForm = document.getElementById('menti-vote-form');
            if (voteForm) {
                voteForm.addEventListener('submit', e => {
                    e.preventDefault();
                    const fd = new FormData();
                    fd.append('participant_action', 'submit_vote');
                    fd.append('token', token);
                    fd.append('slide_id', sl.id);
                    fd.append('participant_name', nameInput ? nameInput.value : '');

                    if (sl.type === 'wordcloud') {
                        ['word1', 'word2', 'word3'].forEach(wn => {
                            const inp = voteForm.querySelector(`[name="${wn}"]`);
                            if (inp && inp.value.trim() !== '') {
                                fd.append('words[]', inp.value.trim());
                            }
                        });
                    } else if (sl.type === 'scale') {
                        voteForm.querySelectorAll('.menti-scale-slider').forEach(rng => {
                            fd.append(`scale_values[${rng.getAttribute('data-scale-idx')}]`, rng.value);
                        });
                    } else if (sl.type === 'open' || sl.type === 'qa') {
                        const ta = voteForm.querySelector('[name="text_answer"]');
                        fd.append('text_answer', ta ? ta.value : '');
                        if (ta) ta.value = '';
                    }
                    sendParticipantPost(fd);
                });
            }

            document.querySelectorAll('.menti-upvote-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    const rid = btn.getAttribute('data-upvote-id');
                    const fd = new FormData();
                    fd.append('participant_action', 'toggle_upvote');
                    fd.append('token', token);
                    fd.append('slide_id', sl.id);
                    fd.append('response_id', rid);
                    sendParticipantPost(fd);
                });
            });
        }

        function sendParticipantPost(fd) {
            fetch('menti.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.state) {
                        currentState = res.state;
                        renderVoterStage(true);
                    } else if (res.error) {
                        alert(res.error);
                    }
                })
                .catch(() => {});
        }

        function pollState() {
            fetch('menti.php?ajax_state=1&token=' + encodeURIComponent(token))
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.state) {
                        currentState = res.state;
                        renderVoterStage(false);
                    }
                })
                .catch(() => {});
        }

        renderVoterStage(true);
        setInterval(pollState, 2000);
    })();
    </script>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Ab hier benötigen Beamer-Präsentation und Studio-Verwaltung ein Login
requireLogin();
$csrfToken = getCsrfToken();

// ============================================================================
// ANSICHT B: 🖥️ Beamer- / Vollbild-Präsentations-Modus (menti.php?present=<id>)
// ============================================================================
if (isset($_GET['present'])) {
    $presentId = (int)$_GET['present'];
    $stmtPres = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `id` = :id LIMIT 1");
    $stmtPres->execute([':id' => $presentId]);
    $presSession = $stmtPres->fetch();

    if (!$presSession || ((int)$presSession['user_id'] !== $currentUserId && !$isAdminUser)) {
        setFlash('error', 'Präsentation nicht gefunden oder keine Berechtigung.');
        header('Location: menti.php');
        exit;
    }

    $joinUrl = getBaseUrl() . '/menti.php?join=' . urlencode((string)$presSession['public_token']);
    $shortJoinUrl = preg_replace('#^https?://#i', '', getBaseUrl()) . '/menti.php';
    $qrSmallSvg = renderQrCodeSvg($joinUrl, 96);
    $qrLargeSvg = renderQrCodeSvg($joinUrl, 340);
    $initialState = buildMentiLiveState($pdo, $presSession, $participantToken);

    $pageTitle = 'Präsentation: ' . (string)$presSession['title'];
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div id="menti-beamer-wrapper" class="max-w-7xl mx-auto">
        <!-- Obere Beitritts-Leiste (wie beim Original-Mentimeter) -->
        <div class="mc-card bg-slate-900 text-white rounded-2xl border border-slate-800 p-4 sm:p-5 shadow-lg mb-4 flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <a href="menti.php?manage=<?= (int)$presSession['id'] ?>" class="px-3 py-1.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-semibold transition-colors">
                    &larr; Folien-Editor
                </a>
                <div>
                    <div class="text-xs text-indigo-300 font-semibold uppercase tracking-wider">🎤 Live-Mentimeter</div>
                    <h1 class="text-base sm:text-xl font-bold text-white"><?= e((string)$presSession['title']) ?></h1>
                </div>
            </div>

            <!-- Zentraler PIN-Banner -->
            <div class="flex flex-wrap items-center justify-center gap-3 bg-slate-800/90 border border-slate-700 px-4 py-2.5 rounded-2xl">
                <div class="text-xs sm:text-sm text-slate-300">
                    Mitmachen auf <strong class="text-white font-mono"><?= e($shortJoinUrl) ?></strong> mit Code:
                </div>
                <span class="px-3.5 py-1 rounded-xl bg-indigo-600 text-white font-mono font-extrabold text-lg sm:text-2xl tracking-widest shadow-xs">
                    <?= e(formatMentiPin((string)$presSession['pin_code'])) ?>
                </span>
            </div>

            <!-- Rechts: QR-Code (Klick öffnet Riesen-QR für große Räume) -->
            <button type="button" onclick="document.getElementById('menti-qr-modal').classList.remove('hidden')"
                    title="Klicken zum Vergrößern für den Beamer (Taste Q)"
                    class="flex items-center gap-3 px-3 py-1.5 rounded-2xl bg-white/10 hover:bg-white/15 border border-white/15 transition-colors cursor-pointer shrink-0">
                <div class="w-14 h-14 flex items-center justify-center overflow-hidden rounded-lg bg-white p-0.5">
                    <?= $qrSmallSvg ?>
                </div>
                <div class="text-left">
                    <div class="text-xs font-bold text-white">📱 QR-Code</div>
                    <div class="text-[11px] text-indigo-200">Groß einblenden (Q)</div>
                </div>
            </button>
        </div>

        <!-- Haupt-Bühne für Diagramme, Wortwolke, Skala, Q&A & Quiz -->
        <div id="menti-beamer-stage" class="mc-card bg-white dark:bg-slate-900 rounded-3xl border border-slate-200 dark:border-slate-800 p-6 sm:p-10 shadow-md min-h-[470px] flex flex-col justify-between relative">
            <div class="text-center py-20 text-slate-400">Lade Präsentations-Bühne…</div>
        </div>

        <!-- Untere Moderatoren-Steuerleiste -->
        <div class="mt-4 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-3 sm:p-4 shadow-sm flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button type="button" id="btn-prev-slide" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-bold transition-colors disabled:opacity-40">
                    &larr; Vorherige Folie
                </button>
                <span id="beamer-slide-counter" class="px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800 text-xs font-bold text-slate-600 dark:text-slate-300">
                    1 / 1
                </span>
                <button type="button" id="btn-next-slide" class="px-4 py-2 rounded-xl bg-brand-600 hover:bg-brand-700 text-white text-xs sm:text-sm font-bold transition-colors disabled:opacity-40">
                    Nächste Folie &rarr;
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="btn-toggle-results" class="px-3.5 py-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 text-xs font-bold transition-colors">
                    👁️ Ergebnisse sichtbar (R)
                </button>
                <button type="button" id="btn-toggle-lock" class="px-3.5 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-700 dark:text-slate-300 text-xs font-bold transition-colors">
                    🔓 Abstimmung offen (L)
                </button>
                <button type="button" id="btn-toggle-leaderboard" class="px-3.5 py-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 hover:bg-amber-100 text-amber-800 dark:text-amber-300 border border-amber-200 dark:border-amber-800 text-xs font-bold transition-colors">
                    🏆 Bestenliste (B)
                </button>
                <button type="button" id="btn-clear-slide" class="px-3 py-2 rounded-xl bg-rose-50 dark:bg-rose-950/50 hover:bg-rose-100 text-rose-700 dark:text-rose-300 text-xs font-semibold transition-colors" title="Antworten dieser Folie zurücksetzen">
                    🗑️ Reset
                </button>
                <button type="button" id="btn-fullscreen" class="px-3.5 py-2 rounded-xl bg-slate-900 dark:bg-slate-700 text-white text-xs font-bold transition-colors">
                    ⛶ Vollbild (F)
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Riesen-QR-Code für große Veranstaltungsräume -->
    <div id="menti-qr-modal" class="hidden fixed inset-0 z-50 bg-slate-950/85 backdrop-blur-sm flex items-center justify-center p-4" onclick="if(event.target===this)this.classList.add('hidden')">
        <div class="bg-white dark:bg-slate-900 rounded-3xl p-6 sm:p-10 max-w-xl w-full text-center shadow-2xl border border-slate-200 dark:border-slate-800">
            <div class="text-xs font-bold uppercase tracking-widest text-brand-600 dark:text-brand-400 mb-1">Jetzt mit dem Smartphone scannen &amp; abstimmen</div>
            <h2 class="text-xl sm:text-2xl font-extrabold text-slate-900 dark:text-white mb-4"><?= e((string)$presSession['title']) ?></h2>
            <div class="inline-flex items-center justify-center p-4 rounded-3xl bg-white border-2 border-slate-200 shadow-inner mb-5">
                <?= $qrLargeSvg ?>
            </div>
            <div class="p-4 rounded-2xl bg-slate-100 dark:bg-slate-800 mb-5">
                <div class="text-xs text-slate-500 dark:text-slate-400">Oder im Browser öffnen: <strong class="text-slate-900 dark:text-white font-mono"><?= e($shortJoinUrl) ?></strong></div>
                <div class="text-2xl sm:text-4xl font-mono font-black text-indigo-600 dark:text-indigo-400 tracking-widest mt-1">
                    PIN: <?= e(formatMentiPin((string)$presSession['pin_code'])) ?>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('menti-qr-modal').classList.add('hidden')"
                    class="px-6 py-2.5 rounded-xl bg-slate-900 dark:bg-white text-white dark:text-slate-900 font-bold text-sm">
                Schließen (Esc)
            </button>
        </div>
    </div>

    <script>
    (function() {
        const sessionId = <?= (int)$presSession['id'] ?>;
        const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let state = <?= json_encode($initialState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        let showLeaderboardOverlay = false;

        const stageEl = document.getElementById('menti-beamer-stage');
        const counterEl = document.getElementById('beamer-slide-counter');
        const btnPrev = document.getElementById('btn-prev-slide');
        const btnNext = document.getElementById('btn-next-slide');
        const btnResults = document.getElementById('btn-toggle-results');
        const btnLock = document.getElementById('btn-toggle-lock');
        const btnLeader = document.getElementById('btn-toggle-leaderboard');
        const btnClear = document.getElementById('btn-clear-slide');
        const btnFull = document.getElementById('btn-fullscreen');

        const barColors = [
            'bg-indigo-600', 'bg-emerald-500', 'bg-amber-500',
            'bg-rose-500', 'bg-sky-500', 'bg-purple-600', 'bg-teal-500'
        ];
        const wordColors = [
            'text-indigo-600 dark:text-indigo-400',
            'text-emerald-600 dark:text-emerald-400',
            'text-rose-600 dark:text-rose-400',
            'text-amber-600 dark:text-amber-400',
            'text-sky-600 dark:text-sky-400',
            'text-purple-600 dark:text-purple-400'
        ];

        function esc(str) {
            const d = document.createElement('div');
            d.textContent = str == null ? '' : String(str);
            return d.innerHTML;
        }

        function renderBeamer() {
            if (!state || !state.active_slide) {
                stageEl.innerHTML = `<div class="text-center py-20"><div class="text-5xl mb-3">🎤</div><h2 class="text-xl font-bold text-slate-800 dark:text-white">Noch keine Folien angelegt</h2><p class="text-sm text-slate-500 mt-1">Fügen Sie im Folien-Editor Fragen hinzu.</p></div>`;
                return;
            }

            const sl = state.active_slide;
            counterEl.textContent = `${state.active_index + 1} / ${state.slide_count}`;
            btnPrev.disabled = !state.prev_slide_id;
            btnNext.disabled = !state.next_slide_id;

            btnResults.innerHTML = state.show_results ? '👁️ Ergebnisse sichtbar (R)' : '🙈 Ergebnisse verdeckt (R)';
            btnLock.innerHTML = state.voting_locked ? '🔒 Abstimmung gesperrt (L)' : '🔓 Abstimmung offen (L)';

            // Falls Bestenlisten-Ansicht aktiv ist
            if (showLeaderboardOverlay) {
                const lb = state.leaderboard || [];
                let lbHtml = `
                    <div>
                        <div class="flex items-center justify-between gap-4 mb-6">
                            <div>
                                <span class="px-3 py-1 rounded-full bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300 text-xs font-bold">🏆 Quiz-Bestenliste (Leaderboard)</span>
                                <h2 class="text-2xl sm:text-4xl font-extrabold text-slate-900 dark:text-white mt-2">Zwischenstand nach Punkten</h2>
                            </div>
                            <button type="button" onclick="window._toggleMentiLb()" class="px-4 py-2 rounded-xl bg-slate-200 dark:bg-slate-800 text-xs font-bold">Zurück zur Folie</button>
                        </div>
                `;
                if (lb.length === 0) {
                    lbHtml += `<div class="text-center py-16 text-slate-400 text-base">Noch keine Quiz-Punkte vergeben.</div>`;
                } else {
                    lbHtml += `<div class="grid grid-cols-1 md:grid-cols-2 gap-3">`;
                    lb.forEach(item => {
                        const medal = item.rank === 1 ? '🥇' : (item.rank === 2 ? '🥈' : (item.rank === 3 ? '🥉' : `#${item.rank}`));
                        lbHtml += `
                            <div class="p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/60 flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <span class="text-2xl font-black w-10 text-center">${medal}</span>
                                    <span class="text-base sm:text-lg font-bold text-slate-900 dark:text-white">${esc(item.name)}</span>
                                </div>
                                <span class="px-3.5 py-1 rounded-xl bg-amber-500 text-white font-mono font-extrabold text-sm sm:text-base">${item.points} Pkt.</span>
                            </div>
                        `;
                    });
                    lbHtml += `</div>`;
                }
                lbHtml += `</div>`;
                stageEl.innerHTML = lbHtml;
                return;
            }

            let html = `
                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                        <span class="px-3 py-1 rounded-full bg-indigo-50 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 text-xs font-bold">
                            Folie ${state.active_index + 1} von ${state.slide_count}
                        </span>
                        <div class="flex items-center gap-2">
                            ${state.voting_locked ? '<span class="px-3 py-1 rounded-full bg-amber-100 text-amber-800 text-xs font-bold">🔒 Gesperrt</span>' : ''}
                            <span class="px-3.5 py-1 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-bold">
                                👥 ${state.slide_voters} Teilnahmen (${state.total_responses} Stimmen)
                            </span>
                        </div>
                    </div>
                    <h2 class="text-2xl sm:text-4xl font-extrabold text-slate-900 dark:text-white leading-tight">${esc(sl.question)}</h2>
                    ${sl.subtitle ? `<p class="text-sm sm:text-lg text-slate-500 dark:text-slate-400 mt-1">${esc(sl.subtitle)}</p>` : ''}
                </div>
            `;

            // Wenn Ergebnisse verdeckt sind (z. B. während die Quiz-Frage läuft)
            if (!state.show_results) {
                html += `
                    <div class="my-auto py-16 text-center">
                        <div class="inline-flex items-center justify-center w-20 h-20 rounded-3xl bg-indigo-50 dark:bg-indigo-950/70 text-4xl mb-4 animate-bounce">🙈</div>
                        <h3 class="text-xl sm:text-2xl font-bold text-slate-800 dark:text-slate-100">Stimmen werden verdeckt gesammelt…</h3>
                        <p class="text-sm sm:text-base text-slate-500 dark:text-slate-400 mt-1">Bereits <strong>${state.slide_voters}</strong> Personen haben abgestimmt. Klicken Sie unten auf <strong>„👁️ Ergebnisse sichtbar (R)“</strong>, um das Ergebnis live aufzudecken!</p>
                    </div>
                    <div></div>
                `;
                stageEl.innerHTML = html;
                return;
            }

            // Ergebnisse je nach Typ rendern
            if (sl.type === 'choice' || sl.type === 'quiz') {
                const choices = sl.aggregated.choices || [];
                html += `<div class="my-auto py-6 space-y-4">`;
                choices.forEach((ch, idx) => {
                    const colorClass = sl.type === 'quiz'
                        ? (ch.is_correct ? 'bg-emerald-500' : 'bg-slate-400 dark:bg-slate-600')
                        : barColors[idx % barColors.length];
                    html += `
                        <div class="space-y-1.5">
                            <div class="flex items-center justify-between gap-3 text-base sm:text-xl font-bold text-slate-800 dark:text-slate-100">
                                <span class="flex items-center gap-2.5">
                                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-xl bg-slate-100 dark:bg-slate-800 text-sm font-extrabold">${String.fromCharCode(65 + idx)}</span>
                                    <span>${esc(ch.label)}</span>
                                    ${ch.is_correct ? '<span class="px-2.5 py-0.5 rounded-lg bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 text-xs font-extrabold">✅ Richtige Antwort</span>' : ''}
                                </span>
                                <span class="font-mono text-base sm:text-xl">${ch.count} (${ch.percent}%)</span>
                            </div>
                            <div class="w-full h-7 sm:h-9 rounded-2xl bg-slate-100 dark:bg-slate-800 overflow-hidden p-1">
                                <div class="h-full rounded-xl ${colorClass} transition-all duration-500" style="width: ${Math.max(ch.count > 0 ? 3 : 0, ch.percent)}%"></div>
                            </div>
                        </div>
                    `;
                });
                html += `</div><div></div>`;
            } else if (sl.type === 'wordcloud') {
                const words = sl.aggregated.words || [];
                if (words.length === 0) {
                    html += `<div class="my-auto py-16 text-center text-slate-400 text-lg">Warte auf die ersten Begriffe für die Wortwolke…</div><div></div>`;
                } else {
                    html += `<div class="my-auto py-8 flex flex-wrap items-center justify-center gap-x-6 gap-y-4 max-w-5xl mx-auto">`;
                    words.forEach((w, idx) => {
                        const sizeRem = (1.15 + (w.weight * 2.35)).toFixed(2);
                        const colorCls = wordColors[idx % wordColors.length];
                        html += `
                            <span class="inline-flex items-center gap-1.5 font-extrabold ${colorCls} transition-all duration-300 hover:scale-105"
                                  style="font-size: ${sizeRem}rem; line-height: 1.15;">
                                <span>${esc(w.text)}</span>
                                ${w.count > 1 ? `<sup class="text-xs font-mono px-1.5 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">${w.count}×</sup>` : ''}
                            </span>
                        `;
                    });
                    html += `</div><div></div>`;
                }
            } else if (sl.type === 'scale') {
                const scales = sl.aggregated.scales || [];
                html += `<div class="my-auto py-6 space-y-5">`;
                scales.forEach((sc, idx) => {
                    const colorClass = barColors[idx % barColors.length];
                    html += `
                        <div class="p-4 sm:p-5 rounded-2xl bg-slate-50 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-800">
                            <div class="flex items-center justify-between gap-4 mb-2">
                                <span class="text-base sm:text-xl font-bold text-slate-800 dark:text-slate-100">${esc(sc.label)}</span>
                                <span class="px-4 py-1 rounded-xl ${colorClass} text-white font-mono font-extrabold text-lg sm:text-xl shrink-0">
                                    Ø ${sc.average.toFixed(1)} / 10
                                </span>
                            </div>
                            <div class="w-full h-5 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                                <div class="h-full rounded-full ${colorClass} transition-all duration-500" style="width: ${sc.percent}%"></div>
                            </div>
                            <div class="flex justify-between text-xs text-slate-400 mt-1.5">
                                <span>1 (Stimme gar nicht zu)</span>
                                <span>${sc.count} Bewertungen</span>
                                <span>10 (Stimme voll zu)</span>
                            </div>
                        </div>
                    `;
                });
                html += `</div><div></div>`;
            } else if (sl.type === 'open') {
                const list = sl.aggregated.open_list || [];
                if (list.length === 0) {
                    html += `<div class="my-auto py-16 text-center text-slate-400 text-lg">Warte auf die ersten Wortmeldungen…</div><div></div>`;
                } else {
                    html += `<div class="my-6 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 max-h-[420px] overflow-y-auto pr-1">`;
                    list.forEach(item => {
                        html += `
                            <div class="p-4 rounded-2xl bg-amber-50/70 dark:bg-slate-800 border border-amber-200/80 dark:border-slate-700 shadow-xs flex flex-col justify-between">
                                <p class="text-sm sm:text-base font-medium text-slate-900 dark:text-white break-words leading-relaxed">${esc(item.text)}</p>
                                <div class="mt-3 pt-2 border-t border-amber-200/50 dark:border-slate-700 flex justify-between text-xs text-slate-400">
                                    <span>👤 ${esc(item.name)}</span>
                                    <span>${esc(item.time)}</span>
                                </div>
                            </div>
                        `;
                    });
                    html += `</div><div></div>`;
                }
            } else if (sl.type === 'qa') {
                const list = sl.aggregated.qa_list || [];
                if (list.length === 0) {
                    html += `<div class="my-auto py-16 text-center text-slate-400 text-lg">Noch keine Publikumsfragen eingegangen…</div><div></div>`;
                } else {
                    html += `<div class="my-6 space-y-3 max-h-[420px] overflow-y-auto pr-1">`;
                    list.forEach((item, idx) => {
                        html += `
                            <div class="p-4 sm:p-5 rounded-2xl border ${idx === 0 && item.upvotes > 1 ? 'border-brand-400 bg-brand-50/40 dark:bg-brand-950/30' : 'border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50'} flex items-center justify-between gap-4">
                                <div>
                                    <p class="text-base sm:text-xl font-bold text-slate-900 dark:text-white break-words">${esc(item.text)}</p>
                                    <span class="text-xs text-slate-400">Frage von ${esc(item.name)} • ${esc(item.time)} Uhr</span>
                                </div>
                                <div class="shrink-0 px-4 py-2 rounded-2xl bg-brand-600 text-white font-extrabold text-base sm:text-lg flex items-center gap-1.5 shadow-xs">
                                    👍 <span>${item.upvotes}</span>
                                </div>
                            </div>
                        `;
                    });
                    html += `</div><div></div>`;
                }
            }

            stageEl.innerHTML = html;
        }

        function sendModAction(actionName, extra = {}) {
            const fd = new FormData();
            fd.append('ajax_mod', '1');
            fd.append('csrf_token', csrfToken);
            fd.append('session_id', sessionId);
            fd.append('action', actionName);
            Object.keys(extra).forEach(k => fd.append(k, extra[k]));

            fetch('menti.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.state) {
                        state = res.state;
                        showLeaderboardOverlay = false;
                        renderBeamer();
                    }
                })
                .catch(() => {});
        }

        window._toggleMentiLb = function() {
            showLeaderboardOverlay = !showLeaderboardOverlay;
            renderBeamer();
        };

        btnPrev.addEventListener('click', () => {
            if (state.prev_slide_id) sendModAction('set_active_slide', { slide_id: state.prev_slide_id });
        });
        btnNext.addEventListener('click', () => {
            if (state.next_slide_id) sendModAction('set_active_slide', { slide_id: state.next_slide_id });
        });
        btnResults.addEventListener('click', () => sendModAction('toggle_results'));
        btnLock.addEventListener('click', () => sendModAction('toggle_lock'));
        btnLeader.addEventListener('click', () => window._toggleMentiLb());
        btnClear.addEventListener('click', () => {
            if (state.active_slide && confirm('Alle bisherigen Stimmen für diese Folie wirklich zurücksetzen?')) {
                sendModAction('clear_slide_responses', { slide_id: state.active_slide.id });
            }
        });
        btnFull.addEventListener('click', () => {
            const wrap = document.getElementById('menti-beamer-wrapper');
            if (!document.fullscreenElement) {
                (wrap.requestFullscreen || document.documentElement.requestFullscreen).call(document.documentElement);
            } else if (document.exitFullscreen) {
                document.exitFullscreen();
            }
        });

        document.addEventListener('keydown', e => {
            if (['INPUT', 'TEXTAREA'].includes((e.target && e.target.tagName) || '')) return;
            if (e.key === 'ArrowRight' && state.next_slide_id) {
                sendModAction('set_active_slide', { slide_id: state.next_slide_id });
            } else if (e.key === 'ArrowLeft' && state.prev_slide_id) {
                sendModAction('set_active_slide', { slide_id: state.prev_slide_id });
            } else if (e.key === 'r' || e.key === 'R') {
                sendModAction('toggle_results');
            } else if (e.key === 'l' || e.key === 'L') {
                sendModAction('toggle_lock');
            } else if (e.key === 'b' || e.key === 'B') {
                window._toggleMentiLb();
            } else if (e.key === 'q' || e.key === 'Q') {
                document.getElementById('menti-qr-modal').classList.toggle('hidden');
            } else if (e.key === 'Escape') {
                document.getElementById('menti-qr-modal').classList.add('hidden');
            }
        });

        function pollBeamer() {
            fetch('menti.php?ajax_state=1&session_id=' + sessionId)
                .then(r => r.json())
                .then(res => {
                    if (res.ok && res.state) {
                        state = res.state;
                        renderBeamer();
                    }
                })
                .catch(() => {});
        }

        renderBeamer();
        setInterval(pollBeamer, 2000);
    })();
    </script>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// ============================================================================
// ANSICHT C: 🎛️ Studio / Folien-Editor & Übersicht (menti.php)
// ============================================================================
$manageSessionId = isset($_GET['manage']) ? (int)$_GET['manage'] : 0;
$managedSession = null;
$managedSlides = [];

if ($manageSessionId > 0) {
    $stmtM = $pdo->prepare("SELECT * FROM `menti_sessions` WHERE `id` = :id LIMIT 1");
    $stmtM->execute([':id' => $manageSessionId]);
    $rowM = $stmtM->fetch();
    if ($rowM && ((int)$rowM['user_id'] === $currentUserId || $isAdminUser)) {
        $managedSession = $rowM;
        $stmtSl = $pdo->prepare("
            SELECT s.*,
                   (SELECT COUNT(*) FROM `menti_responses` r WHERE r.`slide_id` = s.`id`) AS `response_count`
            FROM `menti_slides` s
            WHERE s.`session_id` = :sid
            ORDER BY s.`slide_order` ASC, s.`id` ASC
        ");
        $stmtSl->execute([':sid' => $manageSessionId]);
        $managedSlides = $stmtSl->fetchAll();
    }
}

$stmtAll = $pdo->prepare("
    SELECT m.*,
           (SELECT COUNT(*) FROM `menti_slides` s WHERE s.`session_id` = m.`id`) AS `slide_count`,
           (SELECT COUNT(DISTINCT r.`participant_token`) FROM `menti_responses` r WHERE r.`session_id` = m.`id`) AS `participant_count`
    FROM `menti_sessions` m
    WHERE m.`user_id` = :uid
    ORDER BY m.`created_at` DESC
");
$stmtAll->execute([':uid' => $currentUserId]);
$mySessions = $stmtAll->fetchAll();

$pageTitle = '🎤 Live-Mentimeter & Publikums-Voting';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto space-y-6">
    <!-- Kopfbereich + Schneller PIN-Beitritt -->
    <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 sm:p-6 shadow-xs flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl bg-indigo-100 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400">🎤</span>
                <span>Live-Mentimeter &amp; Interaktive Präsentationen</span>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-1">
                Echtzeit-Wortwolken, Live-Balkendiagramme, Stimmungs-Skalen, Q&amp;A mit Upvoting und Quiz-Bestenlisten – 100 % lokal per QR-Code &amp; PIN.
            </p>
        </div>

        <!-- Direkt mit 6-stelligem PIN teilnehmen -->
        <form method="get" action="menti.php" class="flex items-center gap-2 shrink-0">
            <input type="text" name="pin" required maxlength="10" placeholder="6-stelliger PIN (z.B. 482 915)"
                   class="w-48 sm:w-56 px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-slate-50 dark:bg-slate-800 text-xs sm:text-sm font-mono text-slate-900 dark:text-white">
            <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs sm:text-sm font-bold transition-colors shrink-0">
                📱 Mit PIN teilnehmen
            </button>
        </form>
    </div>

    <?php if ($managedSession !== null): ?>
        <?php
        $joinUrl = getBaseUrl() . '/menti.php?join=' . urlencode((string)$managedSession['public_token']);
        $qrSvg = renderQrCodeSvg($joinUrl, 130);
        ?>
        <!-- Detail- & Folien-Editor für die ausgewählte Präsentation -->
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-indigo-200 dark:border-indigo-900/70 p-5 sm:p-6 shadow-xs space-y-5">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-200 dark:border-slate-800">
                <div>
                    <div class="flex items-center gap-2 text-xs font-semibold text-indigo-600 dark:text-indigo-400 mb-1">
                        <a href="menti.php" class="hover:underline">&larr; Alle Präsentationen</a>
                        <span>&bull;</span>
                        <span class="font-mono">PIN: <?= e(formatMentiPin((string)$managedSession['pin_code'])) ?></span>
                    </div>
                    <h2 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-white"><?= e((string)$managedSession['title']) ?></h2>
                    <?php if (!empty($managedSession['description'])): ?>
                        <p class="text-xs sm:text-sm text-slate-500 dark:text-slate-400 mt-0.5"><?= e((string)$managedSession['description']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    <a href="menti.php?present=<?= (int)$managedSession['id'] ?>"
                       class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs sm:text-sm font-bold shadow-sm transition-colors">
                        🖥️ Beamer-Präsentation starten
                    </a>
                    <a href="menti.php?join=<?= urlencode((string)$managedSession['public_token']) ?>" target="_blank"
                       class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors">
                        📱 Smartphone-Ansicht testen
                    </a>
                    <button type="button" onclick="navigator.clipboard.writeText(<?= e(json_encode($joinUrl)) ?>); alert('Teilnahme-Link in die Zwischenablage kopiert!');"
                            class="inline-flex items-center gap-1.5 px-3.5 py-2.5 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 text-slate-800 dark:text-slate-200 text-xs sm:text-sm font-semibold transition-colors">
                        🔗 Gäste-Link kopieren
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Linke Spalte (2/3): Vorhandene Folien -->
                <div class="lg:col-span-2 space-y-3">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">
                            📑 Folien dieser Präsentation (<?= count($managedSlides) ?>)
                        </h3>
                        <?php if (!empty($managedSlides)): ?>
                            <form method="post" action="menti.php" onsubmit="return confirm('Alle bisherigen Publikums-Antworten dieser Präsentation wirklich löschen?');">
                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                <input type="hidden" name="action" value="reset_all_responses">
                                <input type="hidden" name="session_id" value="<?= (int)$managedSession['id'] ?>">
                                <button type="submit" class="text-xs font-semibold text-rose-600 dark:text-rose-400 hover:underline">
                                    🔄 Alle Antworten zurücksetzen
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($managedSlides)): ?>
                        <div class="p-8 rounded-2xl border border-dashed border-slate-300 dark:border-slate-700 text-center text-slate-400 text-sm">
                            Noch keine Folien vorhanden. Fügen Sie rechts Ihre erste Wortwolke, Abstimmung oder Quiz-Frage hinzu!
                        </div>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($managedSlides as $idx => $sl): ?>
                                <?php
                                $sType = (string)$sl['slide_type'];
                                $meta = $slideTypeMeta[$sType] ?? $slideTypeMeta['choice'];
                                $opts = json_decode((string)($sl['options_json'] ?? '[]'), true) ?: [];
                                $isActiveSlide = ((int)$managedSession['active_slide_id'] === (int)$sl['id']);
                                ?>
                                <div class="p-4 rounded-2xl border <?= $isActiveSlide ? 'border-indigo-500 bg-indigo-50/30 dark:bg-indigo-950/20' : 'border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40' ?> flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                    <div class="space-y-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="px-2 py-0.5 rounded-lg bg-slate-200 dark:bg-slate-700 text-slate-800 dark:text-slate-200 text-xs font-bold">
                                                Folie <?= $idx + 1 ?>
                                            </span>
                                            <span class="px-2.5 py-0.5 rounded-lg text-xs font-semibold <?= $meta['badge'] ?>">
                                                <?= $meta['icon'] ?> <?= e($meta['label']) ?>
                                            </span>
                                            <?php if ($isActiveSlide): ?>
                                                <span class="px-2.5 py-0.5 rounded-lg bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 text-xs font-bold">
                                                    🟢 Aktuell auf Beamer &amp; Handys aktiv
                                                </span>
                                            <?php endif; ?>
                                            <span class="text-xs text-slate-400">👥 <?= (int)$sl['response_count'] ?> Antworten</span>
                                        </div>
                                        <h4 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white"><?= e((string)$sl['question']) ?></h4>
                                        <?php if (!empty($opts)): ?>
                                            <div class="flex flex-wrap gap-1.5 pt-1">
                                                <?php foreach ($opts as $oIdx => $oText): ?>
                                                    <span class="px-2 py-0.5 rounded-md bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-[11px] text-slate-600 dark:text-slate-300">
                                                        <?php if ($sType === 'quiz' && $sl['correct_option'] !== null && (int)$sl['correct_option'] === $oIdx): ?>✅ <?php endif; ?>
                                                        <?= e((string)$oText) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <form method="post" action="menti.php" onsubmit="return confirm('Diese Folie samt Antworten löschen?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete_slide">
                                            <input type="hidden" name="session_id" value="<?= (int)$managedSession['id'] ?>">
                                            <input type="hidden" name="slide_id" value="<?= (int)$sl['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 rounded-xl bg-rose-50 dark:bg-rose-950/60 hover:bg-rose-100 text-rose-600 dark:text-rose-300 text-xs font-semibold">
                                                🗑️
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Rechte Spalte (1/3): Neue Folie hinzufügen + QR-Code -->
                <div class="space-y-4">
                    <form method="post" action="menti.php" class="p-4 sm:p-5 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-800 space-y-3.5">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                        <input type="hidden" name="action" value="add_slide">
                        <input type="hidden" name="session_id" value="<?= (int)$managedSession['id'] ?>">

                        <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white">➕ Neue Folie hinzufügen</h3>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Folientyp *</label>
                            <select name="slide_type" id="add-slide-type" onchange="window._updateSlideFormHint(this.value)"
                                    class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs sm:text-sm text-slate-900 dark:text-white">
                                <option value="wordcloud">☁️ Wortwolke (Word Cloud)</option>
                                <option value="choice">📊 Multiple Choice (Live-Balkendiagramm)</option>
                                <option value="scale">🎚️ Skala / Stimmungsbarometer (1–10)</option>
                                <option value="open">💬 Offene Antworten (Ideen-Pinnwand)</option>
                                <option value="qa">🙋 Q&amp;A Publikumsfragen (mit 👍 Upvoting)</option>
                                <option value="quiz">🏆 Live-Quiz (mit Punkten &amp; Bestenliste)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Frage / Überschrift *</label>
                            <input type="text" name="question" required maxlength="300" placeholder="z. B. Was verbinden Sie mit…?"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs sm:text-sm text-slate-900 dark:text-white">
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Untertitel / Hinweis (optional)</label>
                            <input type="text" name="subtitle" maxlength="300" placeholder="z. B. Bis zu 3 Begriffe eingeben"
                                   class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs sm:text-sm text-slate-900 dark:text-white">
                        </div>

                        <div id="slide-options-wrap" class="hidden">
                            <label id="slide-options-label" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
                                Antwortmöglichkeiten (eine pro Zeile)
                            </label>
                            <textarea name="options_text" rows="4" placeholder="Option 1&#10;Option 2&#10;Option 3"
                                      class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs sm:text-sm text-slate-900 dark:text-white"></textarea>
                        </div>

                        <div id="slide-correct-wrap" class="hidden">
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
                                Welche Zeile ist die richtige Quiz-Antwort?
                            </label>
                            <select name="correct_option" class="w-full px-3 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-xs sm:text-sm text-slate-900 dark:text-white">
                                <option value="0">1. Zeile (Option A)</option>
                                <option value="1">2. Zeile (Option B)</option>
                                <option value="2">3. Zeile (Option C)</option>
                                <option value="3">4. Zeile (Option D)</option>
                                <option value="4">5. Zeile (Option E)</option>
                            </select>
                        </div>

                        <button type="submit" class="w-full py-2.5 rounded-xl bg-brand-600 hover:bg-brand-700 text-white font-bold text-xs sm:text-sm shadow-xs transition-colors">
                            ➕ Folie speichern
                        </button>
                    </form>

                    <!-- QR-Code-Box -->
                    <div class="p-4 rounded-2xl bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-800 text-center space-y-2">
                        <div class="text-xs font-bold text-slate-700 dark:text-slate-200">📱 Lokaler QR-Code &amp; PIN</div>
                        <div class="inline-flex p-2 bg-white rounded-xl border border-slate-200">
                            <?= $qrSvg ?>
                        </div>
                        <div class="font-mono font-extrabold text-base text-indigo-600 dark:text-indigo-400">
                            PIN: <?= e(formatMentiPin((string)$managedSession['pin_code'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        window._updateSlideFormHint = function(val) {
            const optWrap = document.getElementById('slide-options-wrap');
            const corrWrap = document.getElementById('slide-correct-wrap');
            const optLbl = document.getElementById('slide-options-label');
            if (val === 'choice' || val === 'quiz') {
                optWrap.classList.remove('hidden');
                optLbl.textContent = 'Antwortmöglichkeiten (eine pro Zeile)';
            } else if (val === 'scale') {
                optWrap.classList.remove('hidden');
                optLbl.textContent = 'Zu bewertende Aussagen (eine pro Zeile, Skala 1–10)';
            } else {
                optWrap.classList.add('hidden');
            }
            if (val === 'quiz') {
                corrWrap.classList.remove('hidden');
            } else {
                corrWrap.classList.add('hidden');
            }
        };
        </script>
    <?php endif; ?>

    <!-- Neue Live-Präsentation erstellen + Liste meiner Präsentationen -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4 self-start">
            <h2 class="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                <span>➕ Neue Live-Präsentation</span>
            </h2>
            <form method="post" action="menti.php" class="space-y-3.5">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="create_session">

                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Titel der Veranstaltung / Sitzung *</label>
                    <input type="text" name="title" required maxlength="200" placeholder="z. B. Team-Workshop / Vereinsabend"
                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Kurzbeschreibung (optional)</label>
                    <input type="text" name="description" maxlength="250" placeholder="Optionaler Hinweis"
                           class="w-full px-3.5 py-2 rounded-xl border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-xs sm:text-sm text-slate-900 dark:text-white">
                </div>

                <label class="flex items-start gap-2.5 p-3 rounded-xl bg-indigo-50/70 dark:bg-indigo-950/40 border border-indigo-200/80 dark:border-indigo-800/60 cursor-pointer text-xs text-slate-700 dark:text-slate-200">
                    <input type="checkbox" name="with_demo_slides" value="1" checked class="mt-0.5 rounded text-indigo-600">
                    <span><strong>Sofort-Start:</strong> 5 fertige Beispiel-Folien (Wortwolke, Balken-Voting, Skala, Quiz &amp; Q&amp;A) gleich mit anlegen</span>
                </label>

                <button type="submit" class="w-full py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm shadow-sm transition-colors">
                    🎤 Live-Mentimeter erstellen
                </button>
            </form>
        </div>

        <div class="lg:col-span-2 mc-card bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-5 shadow-xs space-y-4">
            <h2 class="text-base font-bold text-slate-900 dark:text-white">📋 Meine Live-Präsentationen (<?= count($mySessions) ?>)</h2>

            <?php if (empty($mySessions)): ?>
                <div class="p-10 text-center text-slate-400 text-sm border border-dashed border-slate-200 dark:border-slate-800 rounded-2xl">
                    Noch keine Live-Präsentation erstellt. Legen Sie links mit einem Klick Ihre erste Mentimeter-Session an!
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($mySessions as $ms): ?>
                        <div class="p-4 rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/60 dark:bg-slate-800/40 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2 mb-1">
                                    <span class="px-2.5 py-0.5 rounded-lg bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-mono text-xs font-bold">
                                        PIN: <?= e(formatMentiPin((string)$ms['pin_code'])) ?>
                                    </span>
                                    <span class="text-xs text-slate-500">📑 <?= (int)$ms['slide_count'] ?> Folien</span>
                                    <span class="text-xs text-slate-500">👥 <?= (int)$ms['participant_count'] ?> Teilnehmer</span>
                                </div>
                                <h3 class="text-sm sm:text-base font-bold text-slate-900 dark:text-white"><?= e((string)$ms['title']) ?></h3>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 shrink-0">
                                <a href="menti.php?present=<?= (int)$ms['id'] ?>"
                                   class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold transition-colors">
                                    🖥️ Präsentieren
                                </a>
                                <a href="menti.php?manage=<?= (int)$ms['id'] ?>"
                                   class="px-3.5 py-2 rounded-xl bg-slate-200/80 dark:bg-slate-800 hover:bg-slate-300 text-slate-800 dark:text-slate-200 text-xs font-semibold transition-colors">
                                    ✏️ Folien (<?= (int)$ms['slide_count'] ?>)
                                </a>
                                <form method="post" action="menti.php" onsubmit="return confirm('Präsentation wirklich löschen?');">
                                    <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_session">
                                    <input type="hidden" name="session_id" value="<?= (int)$ms['id'] ?>">
                                    <button type="submit" class="px-2.5 py-2 rounded-xl bg-rose-50 dark:bg-rose-950/50 hover:bg-rose-100 text-rose-600 dark:text-rose-300 text-xs font-semibold">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
