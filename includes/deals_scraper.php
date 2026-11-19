<?php
declare(strict_types=1);

/**
 * MiniCloud - 🏷️ Smarter Scraper & Preis-Parser für Preiswächter (deals.php & deals_worker.php)
 * Unterstützt automatisches Parsing von Shared-Text (z. B. Amazon-App "Titel + Link"),
 * saubere URL-Normalisierung, Bot-Protection-Toleranz (HTTP 503/403) und Fallback-Erkennung.
 */

/**
 * Konvertiert beliebige Preiszeichenketten (deutsch/US) in einen float.
 */
function parsePriceString(?string $raw): ?float
{
    if ($raw === null) return null;
    $raw = trim($raw);
    if ($raw === '') return null;

    // Format: 1.249,99 oder 49,90
    if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})$/', $raw)) {
        return (float)str_replace(['.', ','], ['', '.'], $raw);
    }
    // Format: 1,249.99
    if (preg_match('/^([0-9]{1,3}(?:,[0-9]{3})*\.[0-9]{2})$/', $raw)) {
        return (float)str_replace(',', '', $raw);
    }
    // Format: 1249,99
    if (preg_match('/^([0-9]+),([0-9]{2})$/', $raw, $m)) {
        return (float)($m[1] . '.' . $m[2]);
    }
    // Format: 1249.99
    if (preg_match('/^([0-9]+)\.([0-9]{2})$/', $raw, $m)) {
        return (float)($m[1] . '.' . $m[2]);
    }

    // Allgemeine Bereinigung
    $clean = preg_replace('/[^0-9.,]/', '', $raw);
    if ($clean === null || $clean === '') return null;

    if (strpos($clean, ',') !== false && strpos($clean, '.') !== false) {
        if (strrpos($clean, ',') > strrpos($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
    } elseif (strpos($clean, ',') !== false) {
        $clean = str_replace(',', '.', $clean);
    }

    return is_numeric($clean) && (float)$clean > 0 ? round((float)$clean, 2) : null;
}

/**
 * Bereinigt die Nutzereingabe (z. B. wenn Text & URL zusammen aus einer App kopiert wurden)
 * und extrahiert saubere URL, Domain und ggf. Titel-Hinweise aus der URL-Struktur.
 */
function cleanProductInput(string $input): array
{
    $input = trim($input);
    $url = $input;
    $titleHint = '';

    // Falls z. B. aus App geteilt: "Apple MacBook Air M5 ... https://amzn.eu/d/..."
    if (preg_match('/(https?:\/\/[^\s]+)/i', $input, $m)) {
        $foundUrl = $m[1];
        $surrounding = trim(str_replace($foundUrl, '', $input));
        if ($surrounding !== '') {
            // Unnötige Füllwörter / Zeilenumbrüche am Rand entfernen
            $surrounding = trim(preg_replace('/[\r\n\t]+/', ' ', $surrounding));
            $surrounding = trim(preg_replace('/\s+/', ' ', $surrounding));
            $titleHint = $surrounding;
        }
        $url = $foundUrl;
    }

    $parsed = @parse_url($url);
    $host = strtolower((string)($parsed['host'] ?? ''));
    $domain = preg_replace('/^www\./i', '', $host);

    $shopName = 'Online-Shop';
    if (str_contains($domain, 'amazon.')) {
        $shopName = 'Amazon';
        // Amazon-URL aufräumen & Titel-Slug extrahieren falls vorhanden:
        // Format: /Titel-Des-Produkts/dp/ASIN/... oder /dp/ASIN
        if (preg_match('#amazon\.[a-z.]+/(?:([^/]+)/)?dp/([A-Z0-9]{10})#i', $url, $mAz)) {
            $rawSlug = $mAz[1] ?? '';
            $asin = $mAz[2];
            $cleanSlug = trim(str_replace(['-', '_', '+'], ' ', urldecode(urldecode($rawSlug))));
            if ($cleanSlug !== '' && !in_array(strtolower($cleanSlug), ['dp', 'gp', 'product'], true) && $titleHint === '') {
                $titleHint = $cleanSlug;
            }
            $url = 'https://' . ($host ?: 'www.amazon.de') . '/dp/' . $asin;
        }
    } elseif (str_contains($domain, 'idealo.')) {
        $shopName = 'Idealo';
        if (preg_match('#idealo\.[a-z.]+/preisvergleich/OffersOfProduct/[0-9]+_-(.+?)\.html#i', $url, $mId) && $titleHint === '') {
            $titleHint = ucwords(trim(str_replace(['-', '_'], ' ', urldecode($mId[1]))));
        }
    } elseif (str_contains($domain, 'geizhals.')) {
        $shopName = 'Geizhals';
        if (preg_match('#geizhals\.[a-z.]+/([a-z0-9-]+?)-a[0-9]+\.html#i', $url, $mGh) && $titleHint === '') {
            $titleHint = ucwords(trim(str_replace(['-', '_'], ' ', urldecode($mGh[1]))));
        }
    } elseif (str_contains($domain, 'ebay.')) {
        $shopName = 'eBay';
    } elseif (str_contains($domain, 'otto.')) {
        $shopName = 'Otto';
    } elseif (str_contains($domain, 'mediamarkt.')) {
        $shopName = 'MediaMarkt';
    } elseif (str_contains($domain, 'saturn.')) {
        $shopName = 'Saturn';
    } elseif (str_contains($domain, 'mindfactory.')) {
        $shopName = 'Mindfactory';
    }

    return [
        'url'        => $url,
        'title_hint' => $titleHint,
        'domain'     => $domain,
        'shop_name'  => $shopName,
    ];
}

/**
 * Liest Produktdetails (Name, Preis, Bild, Verfügbarkeit) von einer beliebigen URL aus.
 * Schlägt bei Bot-Sperren (HTTP 503 / 403) nicht fehl, sondern liefert den erkannten
 * Titel & saubere URL zurück mit Hinweis für manuelle Preiseingabe.
 */
function scrapeProductDetails(string $rawInput): array
{
    $cleaned = cleanProductInput($rawInput);
    $url = $cleaned['url'];
    $domain = $cleaned['domain'];
    $shopName = $cleaned['shop_name'];
    $titleHint = $cleaned['title_hint'];

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [
            'ok'          => false,
            'url'         => $url,
            'domain'      => $domain,
            'title'       => $titleHint,
            'price'       => null,
            'image_url'   => null,
            'bot_blocked' => false,
            'error'       => 'Ungültige Webadresse (URL).'
        ];
    }

    $cookieFile = @tempnam(sys_get_temp_dir(), 'mc_deals_c_');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 6,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_ENCODING       => '', // auto gzip / deflate / br
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Upgrade-Insecure-Requests: 1',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: none',
            'Sec-Fetch-User: ?1',
            'sec-ch-ua: "Chromium";v="130", "Google Chrome";v="130", "Not?A_Brand";v="99"',
            'sec-ch-ua-mobile: ?0',
            'sec-ch-ua-platform: "Windows"',
        ],
    ]);

    if ($cookieFile) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }

    $html = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
    $err = curl_error($ch);
    curl_close($ch);

    if ($cookieFile && file_exists($cookieFile)) {
        @unlink($cookieFile);
    }

    // Bot-Block oder Serverfehler erkennen (Amazon 503, Cloudflare 403, Akamai 403, etc.)
    $isBotBlocked = in_array($httpCode, [403, 503], true)
        || ($html !== false && (
            str_contains($html, 'api-services-support@amazon.com')
            || str_contains($html, 'Robot Check')
            || str_contains($html, 'cf-browser-verification')
            || str_contains($html, 'Attention Required! | Cloudflare')
        ));

    if ($html === false || $isBotBlocked || $httpCode >= 400 || strlen($html) < 200) {
        $fallbackTitle = $titleHint !== '' ? $titleHint : ($shopName . ' Produkt (' . date('d.m.Y') . ')');

        return [
            'ok'           => true, // Toleranter Erfolg mit Titel/URL-Extraktion
            'url'          => $finalUrl ?: $url,
            'domain'       => $domain,
            'shop_name'    => $shopName,
            'title'        => $fallbackTitle,
            'price'        => null,
            'currency'     => 'EUR',
            'image_url'    => null,
            'availability' => 'Verfügbar',
            'bot_blocked'  => true,
            'http_code'    => $httpCode,
            'notice'       => 'Dieser Shop (' . $shopName . ') blockiert automatisierte Server-Abfragen mit Bot-Schutz (HTTP ' . ($httpCode ?: '503') . '). Produktdaten wurden übernommen – bitte trage den aktuellen Preis kurz von Hand ein.',
            'error'        => null
        ];
    }

    $title = $titleHint;
    $price = null;
    $imageUrl = null;
    $currency = 'EUR';
    $availability = 'Verfügbar';

    // 1. JSON-LD Schema.org Auswertung (universell für moderne Shops)
    if (preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $ldMatches)) {
        foreach ($ldMatches[1] as $rawJson) {
            $data = @json_decode(trim($rawJson), true);
            if (!is_array($data)) continue;

            $itemsToCheck = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data];
            foreach ($itemsToCheck as $obj) {
                if (!is_array($obj)) continue;
                $type = (string)($obj['@type'] ?? '');
                if (stripos($type, 'Product') !== false || isset($obj['offers'])) {
                    if ($title === '' && !empty($obj['name']) && is_string($obj['name'])) {
                        $title = trim(html_entity_decode($obj['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    }
                    if ($imageUrl === null && !empty($obj['image'])) {
                        $imageUrl = is_array($obj['image']) ? (string)($obj['image'][0] ?? '') : (string)$obj['image'];
                    }

                    // Preis aus offers
                    if (isset($obj['offers'])) {
                        $offers = is_array($obj['offers']) && !isset($obj['offers']['price']) ? $obj['offers'] : [$obj['offers']];
                        foreach ($offers as $off) {
                            if (!is_array($off)) continue;
                            if (isset($off['price'])) {
                                $parsedP = parsePriceString((string)$off['price']);
                                if ($parsedP !== null && ($price === null || $parsedP < $price)) {
                                    $price = $parsedP;
                                }
                            } elseif (isset($off['lowPrice'])) {
                                $parsedP = parsePriceString((string)$off['lowPrice']);
                                if ($parsedP !== null && ($price === null || $parsedP < $price)) {
                                    $price = $parsedP;
                                }
                            }
                            if (!empty($off['priceCurrency'])) {
                                $currency = strtoupper(trim((string)$off['priceCurrency']));
                            }
                            if (!empty($off['availability']) && stripos((string)$off['availability'], 'OutOfStock') !== false) {
                                $availability = 'Ausverkauft';
                            }
                        }
                    }
                }
            }
        }
    }

    // 2. OpenGraph & Meta-Tags
    if (preg_match('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        if ($title === '') $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    if (preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        if ($imageUrl === null) $imageUrl = trim($m[1]);
    }
    if (preg_match('/<meta[^>]+property=["\'](?:product|og):price:amount["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m)) {
        if ($price === null) $price = parsePriceString($m[1]);
    }

    // 3. Fallback: <title> Tag
    if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $rawT = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $cleanT = preg_replace('/(\s*[-|–:]\s*(Amazon\.de|Idealo|Geizhals|Otto|MediaMarkt|Saturn|Alternate|Mindfactory|eBay)).*$/i', '', $rawT);
        $title = $cleanT ?: $rawT;
    }

    // 4. Shop-spezifische HTML-Selektoren für Preis
    if ($price === null) {
        if (str_contains($domain, 'amazon.')) {
            if (preg_match('/<span[^>]*class=["\'][^"\']*a-price-whole[^"\']*["\'][^>]*>([0-9.,]+)<\/span>/i', $html, $mWhole)) {
                $fraction = '00';
                if (preg_match('/<span[^>]*class=["\'][^"\']*a-price-fraction[^"\']*["\'][^>]*>([0-9]{2})<\/span>/i', $html, $mFrac)) {
                    $fraction = $mFrac[1];
                }
                $price = parsePriceString($mWhole[1] . ',' . $fraction);
            } elseif (preg_match('/id=["\']priceblock_(?:ourprice|dealprice)["\'][^>]*>\s*([0-9.,]+)/i', $html, $m)) {
                $price = parsePriceString($m[1]);
            }
        } elseif (str_contains($domain, 'idealo.')) {
            if (preg_match('/class=["\'][^"\']*oopStage-price[^"\']*["\'][^>]*>\s*([0-9.,]+)\s*€/i', $html, $m)) {
                $price = parsePriceString($m[1]);
            }
        } elseif (str_contains($domain, 'geizhals.')) {
            if (preg_match('/<span[^>]*class=["\'][^"\']*gh_price[^"\']*["\'][^>]*>.*?([0-9]{1,4}(?:\.[0-9]{3})*,[0-9]{2})/is', $html, $m)) {
                $price = parsePriceString($m[1]);
            }
        }

        if ($price === null && preg_match('/(?:class=["\'][^"\']*(?:price|preis|kosten|amount)[^"\']*["\'][^>]*>.*?)([0-9]{1,4}(?:[.,][0-9]{3})*[.,][0-9]{2})\s*(?:€|EUR)/is', $html, $m)) {
            $price = parsePriceString($m[1]);
        }
    }

    // Bereinigung
    $title = mb_substr(trim(preg_replace('/\s+/', ' ', $title)), 0, 255);
    if ($title === '') {
        $title = ($titleHint !== '' ? $titleHint : ($shopName . ' Produkt (' . date('d.m.Y') . ')'));
    }

    return [
        'ok'           => true,
        'url'          => $finalUrl ?: $url,
        'domain'       => $domain,
        'shop_name'    => $shopName,
        'title'        => $title,
        'price'        => $price,
        'currency'     => $currency,
        'image_url'    => $imageUrl,
        'availability' => $availability,
        'bot_blocked'  => ($price === null),
        'http_code'    => $httpCode,
        'notice'       => ($price === null ? 'Preis konnte nicht automatisch erkannt werden. Bitte trage den Preis kurz manuell ein.' : null),
        'error'        => null
    ];
}
