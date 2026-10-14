<?php
declare(strict_types=1);

require_once __DIR__ . '/inspector-http.php';

/**
 * Website inspector: speed, SEO, structured data (schema) and technical checks for one URL, with a PDF report.
 * Developer tool at /inspector/ (not in the navigation), limited to the inspector.use permission.
 *
 * An audit runs in steps so no single request is long: inspector_crawl() (the page and its site files),
 * inspector_pagespeed() for mobile and desktop (Google PageSpeed Insights / Lighthouse), then inspector_finish()
 * merges everything into checks and scores. Each check is
 *   ['id', 'title', 'status' => pass|warn|fail|info, 'message', 'impact' => high|medium|low, 'details' => string[]]
 */

const INSPECTOR_CATEGORIES = [
    'performance' => 'Speed & performance',
    'seo' => 'On-page SEO',
    'schema' => 'Structured data (schema)',
    'technical' => 'Technical & security',
];

/** Schema.org types we validate: [required properties, recommended properties]. "a|b" means either is enough. */
const INSPECTOR_SCHEMA_RULES = [
    'Organization' => [['name', 'url'], ['logo', 'sameAs', 'contactPoint']],
    'LocalBusiness' => [['name', 'address'], ['telephone', 'openingHoursSpecification|openingHours', 'geo', 'image', 'url', 'priceRange']],
    'WebSite' => [['name', 'url'], ['potentialAction']],
    'WebPage' => [['name|headline'], ['description', 'url']],
    'Product' => [['name', 'offers|review|aggregateRating'], ['image', 'description', 'brand', 'sku', 'aggregateRating', 'review']],
    'Offer' => [['price|priceSpecification', 'priceCurrency'], ['availability', 'url']],
    'AggregateOffer' => [['lowPrice', 'priceCurrency'], ['highPrice', 'offerCount']],
    'Article' => [['headline'], ['image', 'datePublished', 'dateModified', 'author', 'publisher']],
    'BreadcrumbList' => [['itemListElement'], []],
    'FAQPage' => [['mainEntity'], []],
    'Question' => [['name', 'acceptedAnswer'], []],
    'Event' => [['name', 'startDate', 'location'], ['endDate', 'eventStatus', 'image', 'offers', 'organizer', 'description']],
    'Review' => [['reviewRating', 'author'], ['itemReviewed']],
    'AggregateRating' => [['ratingValue', 'ratingCount|reviewCount'], ['bestRating']],
    'VideoObject' => [['name', 'thumbnailUrl', 'uploadDate'], ['description', 'contentUrl|embedUrl', 'duration']],
    'Recipe' => [['name', 'image'], ['recipeIngredient', 'recipeInstructions', 'author', 'totalTime']],
    'JobPosting' => [['title', 'description', 'datePosted', 'hiringOrganization', 'jobLocation|applicantLocationRequirements'], ['validThrough', 'employmentType', 'baseSalary']],
    'Person' => [['name'], ['url', 'sameAs']],
    'ItemList' => [['itemListElement'], []],
];
// Sub-types validated with their parent's rules
const INSPECTOR_SCHEMA_ALIASES = [
    'BlogPosting' => 'Article', 'NewsArticle' => 'Article', 'TechArticle' => 'Article',
    'Corporation' => 'Organization', 'OnlineStore' => 'Organization', 'OnlineBusiness' => 'Organization',
    'Store' => 'LocalBusiness', 'Restaurant' => 'LocalBusiness', 'ProfessionalService' => 'LocalBusiness', 'MedicalBusiness' => 'LocalBusiness',
    'Dentist' => 'LocalBusiness', 'AutoDealer' => 'LocalBusiness', 'RealEstateAgent' => 'LocalBusiness', 'LegalService' => 'LocalBusiness',
    'HomeAndConstructionBusiness' => 'LocalBusiness', 'FoodEstablishment' => 'LocalBusiness', 'LodgingBusiness' => 'LocalBusiness',
    'Hotel' => 'LocalBusiness', 'ClothingStore' => 'LocalBusiness', 'ElectronicsStore' => 'LocalBusiness', 'HealthAndBeautyBusiness' => 'LocalBusiness',
    'ProductGroup' => 'Product', 'CollectionPage' => 'WebPage', 'AboutPage' => 'WebPage', 'ContactPage' => 'WebPage', 'ItemPage' => 'WebPage',
];

/** "example.com" → "https://example.com/"; returns [normalizedUrl, error]. */
function inspector_normalize_url(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return [null, 'Enter a website address.'];
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !str_contains($p['host'], '.')) {
        return [null, 'That does not look like a website address.'];
    }
    if (empty($p['path'])) {
        $url .= '/';
    }
    if (strlen($url) > 2000) {
        return [null, 'That address is too long.'];
    }
    $target = inspector_target($url);
    return is_string($target) ? [null, $target] : [$url, null];
}

function inspector_check(string $id, string $title, string $status, string $message, string $impact = 'medium', array $details = []): array
{
    return ['id' => $id, 'title' => $title, 'status' => $status, 'message' => $message, 'impact' => $impact, 'details' => array_values(array_slice($details, 0, 25))];
}

/** Collapse whitespace and trim, for text pulled out of HTML. */
function inspector_text(?string $s): string
{
    return trim(preg_replace('/\s+/u', ' ', (string) $s));
}

function inspector_fmt_ms(float|int $ms): string
{
    return $ms >= 1000 ? round($ms / 1000, 2) . ' s' : round($ms) . ' ms';
}

/* ---------------------------------------------------------------------------
 * Step 1: crawl the page and its site files
 * ------------------------------------------------------------------------- */

function inspector_crawl(string $url): array
{
    $main = inspector_fetch_many(['page' => ['url' => $url, 'max_bytes' => 6_000_000, 'timeout' => 25]])['page'];
    $sslError = null;
    if ($main['error'] && in_array($main['curl_errno'] ?? 0, [35, 51, 58, 60], true)) {
        // Certificate problem: report it, but still audit the page
        $sslError = $main['error'];
        $main = inspector_fetch_many(['page' => ['url' => $url, 'max_bytes' => 6_000_000, 'timeout' => 25, 'insecure' => true]])['page'];
    }
    if ($main['error']) {
        throw new RuntimeException('Could not load the page: ' . $main['error']);
    }
    $final = $main['final_url'];
    $fp = parse_url($final);
    $host = strtolower($fp['host']);
    $origin = $fp['scheme'] . '://' . $fp['host'] . (isset($fp['port']) ? ':' . $fp['port'] : '');
    $isHttps = $fp['scheme'] === 'https';
    $insecure = $sslError !== null;

    // ---- Parse the HTML
    $html = $main['body'];
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    $attr = fn(string $q, string $a) => array_map(fn(DOMElement $n) => trim($n->getAttribute($a)), iterator_to_array($xp->query($q) ?: []));
    $meta = function (string $key) use ($xp): ?string {
        $q = "//meta[translate(@name,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$key' or translate(@property,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')='$key']";
        $n = $xp->query($q)->item(0);
        return $n instanceof DOMElement ? inspector_text($n->getAttribute('content')) : null;
    };

    $titles = array_map(fn($n) => inspector_text($n->textContent), iterator_to_array($xp->query('//head/title|//title')));
    $descriptions = $attr("//meta[translate(@name,'DESCRIPTION','description')='description']", 'content');
    $headings = [];
    foreach ($xp->query('//h1|//h2|//h3|//h4|//h5|//h6') as $h) {
        $headings[] = [(int) substr($h->nodeName, 1), mb_substr(inspector_text($h->textContent), 0, 120)];
    }
    $canonical = $attr("//link[translate(@rel,'CANONICAL','canonical')='canonical']", 'href');
    $robotsMeta = strtolower((string) $meta('robots'));
    $lang = $xp->query('//html')->item(0)?->getAttribute('lang') ?? '';
    $viewport = $meta('viewport');
    $charset = $xp->query('//meta[@charset]')->length > 0 || preg_match('/charset=/i', (string) inspector_header($main, 'content-type'));
    $hreflang = $attr("//link[@rel='alternate'][@hreflang]", 'hreflang');
    $favicon = $attr("//link[contains(translate(@rel,'ICON','icon'),'icon')]", 'href');

    foreach ($xp->query('//script|//style|//noscript|//template|//svg') as $n) {
        $n->parentNode?->removeChild($n);
    }
    $bodyNode = $xp->query('//body')->item(0);
    $bodyText = $bodyNode ? inspector_text($bodyNode->textContent) : '';
    $wordCount = $bodyText === '' ? 0 : count(preg_split('/\s+/u', $bodyText));

    // Reload (scripts were stripped above) for script/link/image inspection
    $doc2 = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc2->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    $xp2 = new DOMXPath($doc2);

    $headScripts = $xp2->query('//head/script[@src]');
    $blockingScripts = [];
    foreach ($headScripts as $s) {
        if (!$s->hasAttribute('async') && !$s->hasAttribute('defer') && strtolower($s->getAttribute('type')) !== 'module') {
            $blockingScripts[] = $s->getAttribute('src');
        }
    }
    $scripts = array_map(fn($n) => $n->getAttribute('src'), iterator_to_array($xp2->query('//script[@src]')));
    $stylesheets = array_map(fn($n) => $n->getAttribute('href'), iterator_to_array($xp2->query("//link[contains(translate(@rel,'STYLESHEET','stylesheet'),'stylesheet')]")));
    $inlineScriptBytes = 0;
    foreach ($xp2->query('//script[not(@src)]') as $s) {
        if (!in_array(strtolower($s->getAttribute('type')), ['application/ld+json', 'application/json'], true)) {
            $inlineScriptBytes += strlen($s->textContent);
        }
    }

    // Images
    $images = [];
    foreach ($xp2->query('//img') as $img) {
        $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
        if (!$src && $img->getAttribute('srcset')) {
            $src = trim(explode(' ', trim(explode(',', $img->getAttribute('srcset'))[0]))[0]);
        }
        $images[] = [
            'src' => $src,
            'abs' => $src ? inspector_absolute_url($src, $final) : null,
            'alt' => $img->hasAttribute('alt') ? $img->getAttribute('alt') : null,
            'dims' => $img->hasAttribute('width') && $img->hasAttribute('height'),
            'lazy' => strtolower($img->getAttribute('loading')) === 'lazy',
            'srcset' => $img->hasAttribute('srcset'),
        ];
    }
    $pictureSources = $xp2->query("//picture/source[contains(@type,'webp') or contains(@type,'avif')]")->length;

    // Links
    $internal = $external = [];
    $nofollow = 0;
    foreach ($xp2->query('//a[@href]') as $a) {
        $abs = inspector_absolute_url($a->getAttribute('href'), $final);
        if (!$abs) {
            continue;
        }
        if (str_contains(strtolower($a->getAttribute('rel')), 'nofollow')) {
            $nofollow++;
        }
        $lh = strtolower((string) parse_url($abs, PHP_URL_HOST));
        if ($lh === $host || preg_replace('/^www\./', '', $lh) === preg_replace('/^www\./', '', $host)) {
            $internal[$abs] = true;
        } else {
            $external[$abs] = true;
        }
    }

    // Mixed content
    $mixed = [];
    if ($isHttps) {
        foreach (['//script[@src]' => 'src', '//img[@src]' => 'src', '//iframe[@src]' => 'src', '//source[@src]' => 'src', '//video[@src]' => 'src', '//audio[@src]' => 'src', "//link[contains(translate(@rel,'STYLESHEET','stylesheet'),'stylesheet')]" => 'href'] as $q => $a) {
            foreach ($xp2->query($q) as $n) {
                if (stripos(trim($n->getAttribute($a)), 'http://') === 0) {
                    $mixed[] = trim($n->getAttribute($a));
                }
            }
        }
    }
    $deprecated = [];
    foreach (['font', 'center', 'marquee', 'blink', 'frameset', 'frame', 'big', 'strike', 'tt'] as $t) {
        if ($xp2->query("//$t")->length) {
            $deprecated[] = "<$t>";
        }
    }

    // Structured data
    $schema = inspector_extract_schema($xp2);

    // ---- Second round in parallel: site files, probes, assets and links
    $requests = [
        'robots' => ['url' => $origin . '/robots.txt', 'max_bytes' => 500_000, 'insecure' => $insecure],
        'probe404' => ['url' => $origin . '/' . bin2hex(random_bytes(6)) . '-inspector-404-check', 'max_bytes' => 200_000, 'insecure' => $insecure],
    ];
    if ($isHttps) {
        $requests['http'] = ['url' => 'http://' . $host . '/', 'max_bytes' => 100_000, 'timeout' => 10];
    }
    $altHost = str_starts_with($host, 'www.') ? substr($host, 4) : 'www.' . $host;
    if (!is_string(inspector_target($fp['scheme'] . '://' . $altHost . '/'))) {
        $requests['althost'] = ['url' => $fp['scheme'] . '://' . $altHost . '/', 'max_bytes' => 100_000, 'timeout' => 10, 'insecure' => true];
    }
    // Static assets: caching headers and image weight (HEAD)
    $assets = [];
    foreach (array_slice(array_filter(array_map(fn($u) => inspector_absolute_url($u, $final), [...$stylesheets, ...$scripts])), 0, 8) as $i => $u) {
        $assets["asset$i"] = $u;
        $requests["asset$i"] = ['url' => $u, 'method' => 'HEAD', 'timeout' => 10, 'insecure' => $insecure];
    }
    $imgUrls = array_values(array_unique(array_filter(array_column($images, 'abs'))));
    foreach (array_slice($imgUrls, 0, 12) as $i => $u) {
        $requests["img$i"] = ['url' => $u, 'method' => 'HEAD', 'timeout' => 10, 'insecure' => $insecure];
    }
    // Links: internal first, up to 30 in total
    $linkSample = array_slice([...array_keys($internal), ...array_slice(array_keys($external), 0, 10)], 0, 30);
    foreach ($linkSample as $i => $u) {
        $requests["link$i"] = ['url' => $u, 'method' => 'HEAD', 'timeout' => 10, 'insecure' => true];
    }
    $r2 = inspector_fetch_many($requests);

    // Some servers refuse HEAD: retry those links with a small GET
    $retry = [];
    foreach ($linkSample as $i => $u) {
        $s = $r2["link$i"]['status'];
        if (in_array($s, [403, 405, 400, 501], true) || ($r2["link$i"]['error'] && !str_contains((string) $r2["link$i"]['error'], 'private'))) {
            $retry["link$i"] = ['url' => $u, 'method' => 'GET', 'max_bytes' => 65_536, 'timeout' => 12, 'insecure' => true];
        }
    }
    if ($retry) {
        $r2 = array_merge($r2, inspector_fetch_many($retry));
    }

    // Sitemaps: from robots.txt, else /sitemap.xml
    $robotsTxt = $r2['robots']['status'] === 200 && !str_contains(strtolower((string) inspector_header($r2['robots'], 'content-type')), 'html') ? $r2['robots']['body'] : null;
    $sitemapUrls = [];
    if ($robotsTxt !== null && preg_match_all('/^\s*sitemap:\s*(\S+)/im', $robotsTxt, $m)) {
        $sitemapUrls = array_slice(array_unique($m[1]), 0, 3);
    }
    $sitemapFromRobots = (bool) $sitemapUrls;
    if (!$sitemapUrls) {
        $sitemapUrls = [$origin . '/sitemap.xml', $origin . '/sitemap_index.xml'];
    }
    $sm = inspector_fetch_many(array_map(fn($u) => ['url' => $u, 'max_bytes' => 5_000_000, 'timeout' => 15, 'insecure' => $insecure], $sitemapUrls));
    $sitemap = null;
    foreach ($sm as $res) {
        if ($res['status'] === 200 && preg_match('/<(urlset|sitemapindex)[\s>]/i', $res['body'])) {
            $sitemap = [
                'url' => $res['final_url'],
                'type' => stripos($res['body'], '<sitemapindex') !== false ? 'index' : 'urlset',
                'count' => preg_match_all('/<loc>/i', $res['body']),
            ];
            break;
        }
    }

    $cert = $isHttps ? inspector_certificate($host, (string) $main['ip']) : null;

    return [
        'url' => $url,
        'final_url' => $final,
        'host' => $host,
        'fetched_at' => now(),
        'ssl_error' => $sslError,
        'fetch' => [
            'status' => $main['status'],
            'redirects' => $main['redirects'],
            'time' => $main['time'],
            'total_time' => round($main['total_time'] * 1000),
            'transfer_size' => $main['size'],
            'html_size' => strlen($html),
            'http_version' => $main['http_version'],
            'encoding' => strtolower((string) inspector_header($main, 'content-encoding')),
            'server' => inspector_header($main, 'server'),
            'powered_by' => inspector_header($main, 'x-powered-by'),
            'ip' => $main['ip'],
            'headers' => array_map(fn($v) => implode(', ', $v), array_intersect_key($main['headers'], array_flip([
                'content-type', 'cache-control', 'strict-transport-security', 'content-security-policy', 'x-content-type-options',
                'x-frame-options', 'referrer-policy', 'permissions-policy', 'x-robots-tag', 'server', 'x-powered-by', 'content-encoding',
            ]))),
            'cookies' => $main['headers']['set-cookie'] ?? [],
        ],
        'page' => [
            'titles' => $titles,
            'descriptions' => $descriptions,
            'headings' => array_slice($headings, 0, 80),
            'canonical' => $canonical,
            'robots_meta' => $robotsMeta,
            'lang' => $lang,
            'viewport' => $viewport,
            'charset' => (bool) $charset,
            'doctype' => (bool) preg_match('/^\s*(<!--.*?-->\s*)*<!doctype\s+html/is', $html),
            'hreflang' => $hreflang,
            'favicon' => (bool) $favicon,
            'og' => array_filter(['title' => $meta('og:title'), 'description' => $meta('og:description'), 'image' => $meta('og:image'), 'url' => $meta('og:url'), 'type' => $meta('og:type')]),
            'twitter' => array_filter(['card' => $meta('twitter:card'), 'title' => $meta('twitter:title'), 'image' => $meta('twitter:image')]),
            'word_count' => $wordCount,
            'scripts' => count($scripts),
            'stylesheets' => count($stylesheets),
            'blocking_scripts' => array_slice($blockingScripts, 0, 20),
            'inline_script_kb' => round($inlineScriptBytes / 1024, 1),
            'images' => $images,
            'picture_modern_sources' => $pictureSources,
            'links_internal' => count($internal),
            'links_external' => count($external),
            'links_nofollow' => $nofollow,
            'mixed_content' => array_slice(array_unique($mixed), 0, 20),
            'deprecated_tags' => $deprecated,
        ],
        'schema' => $schema,
        'robots' => [
            'status' => $r2['robots']['status'],
            'found' => $robotsTxt !== null,
            'disallow_all' => $robotsTxt !== null && inspector_robots_blocks_all($robotsTxt),
            'sitemap_listed' => $sitemapFromRobots,
            'excerpt' => $robotsTxt !== null ? mb_substr($robotsTxt, 0, 1500) : null,
        ],
        'sitemap' => $sitemap,
        'probe404' => ['status' => $r2['probe404']['status'], 'error' => $r2['probe404']['error']],
        'http_redirect' => isset($r2['http']) ? ['final_url' => $r2['http']['final_url'], 'status' => $r2['http']['status'], 'error' => $r2['http']['error']] : null,
        'alt_host' => isset($r2['althost']) ? ['url' => $r2['althost']['url'], 'final_url' => $r2['althost']['final_url'], 'status' => $r2['althost']['status'], 'error' => $r2['althost']['error']] : null,
        'assets' => array_map(fn($k) => [
            'url' => $assets[$k], 'status' => $r2[$k]['status'],
            'cache_control' => inspector_header($r2[$k], 'cache-control'), 'expires' => inspector_header($r2[$k], 'expires'),
            'encoding' => inspector_header($r2[$k], 'content-encoding'),
        ], array_keys($assets)),
        'image_heads' => array_values(array_map(fn($i) => [
            'url' => $r2["img$i"]['final_url'], 'status' => $r2["img$i"]['status'],
            'bytes' => (int) (inspector_header($r2["img$i"], 'content-length') ?? 0),
            'type' => inspector_header($r2["img$i"], 'content-type'),
        ], array_keys(array_slice($imgUrls, 0, 12)))),
        'links' => array_map(fn($i) => [
            'url' => $linkSample[$i], 'status' => $r2["link$i"]['status'], 'error' => $r2["link$i"]['error'],
            'internal' => isset($internal[$linkSample[$i]]),
        ], array_keys($linkSample)),
        'certificate' => $cert,
    ];
}

/** True when robots.txt blocks every crawler from the whole site ("User-agent: *" + "Disallow: /"). */
function inspector_robots_blocks_all(string $txt): bool
{
    $agent = null;
    foreach (preg_split('/\R/', $txt) as $line) {
        $line = trim(preg_replace('/#.*$/', '', $line));
        if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
            $agent = trim($m[1]);
        } elseif ($agent === '*' && preg_match('/^disallow:\s*\/\s*$/i', $line)) {
            return true;
        }
    }
    return false;
}

/** JSON-LD, microdata and RDFa found on the page, with validation problems per entity. */
function inspector_extract_schema(DOMXPath $xp): array
{
    $blocks = $errors = $entities = [];
    foreach ($xp->query("//script[translate(@type,'APLICTONJSD+','aplictonjsd+')='application/ld+json']") as $i => $s) {
        $raw = trim(preg_replace('#^\s*(<!--|<!\[CDATA\[)|(-->|\]\]>)\s*$#', '', $s->textContent));
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $errors[] = 'Block ' . ($i + 1) . ': invalid JSON (' . json_last_error_msg() . ')';
            $blocks[] = mb_substr($raw, 0, 4000);
            continue;
        }
        $blocks[] = mb_substr(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 6000);
        $hasContext = str_contains(strtolower(json_encode($data['@context'] ?? ($data[0]['@context'] ?? ''))), 'schema.org');
        if (!$hasContext) {
            $errors[] = 'Block ' . ($i + 1) . ': missing "@context": "https://schema.org"';
        }
        $roots = array_is_list($data) ? $data : (isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : [$data]);
        foreach ($roots as $node) {
            if (is_array($node)) {
                inspector_collect_entities($node, $entities, '');
            }
        }
    }
    $microdata = array_values(array_unique(array_map(fn($n) => preg_replace('#^https?://schema\.org/#i', '', trim($n->getAttribute('itemtype'))), iterator_to_array($xp->query('//*[@itemscope][@itemtype]')))));
    $rdfa = array_values(array_unique(array_map(fn($n) => trim($n->getAttribute('typeof')), iterator_to_array($xp->query('//*[@typeof]')))));
    return [
        'jsonld_count' => count($blocks),
        'blocks' => array_slice($blocks, 0, 10),
        'errors' => $errors,
        'entities' => array_slice($entities, 0, 60),
        'microdata' => array_slice($microdata, 0, 20),
        'rdfa' => array_slice($rdfa, 0, 20),
    ];
}

/** Walk a JSON-LD node, validating every typed entity against INSPECTOR_SCHEMA_RULES. */
function inspector_collect_entities(array $node, array &$entities, string $path): void
{
    $types = (array) ($node['@type'] ?? []);
    if ($types) {
        $type = (string) $types[0];
        $ruleKey = isset(INSPECTOR_SCHEMA_RULES[$type]) ? $type : (INSPECTOR_SCHEMA_ALIASES[$type] ?? null);
        $missingReq = $missingRec = [];
        if ($ruleKey) {
            [$req, $rec] = INSPECTOR_SCHEMA_RULES[$ruleKey];
            $has = function (string $spec) use ($node): bool {
                foreach (explode('|', $spec) as $p) {
                    $v = $node[$p] ?? null;
                    if ($v !== null && $v !== '' && $v !== []) {
                        return true;
                    }
                }
                return false;
            };
            $missingReq = array_values(array_filter($req, fn($p) => !$has($p)));
            $missingRec = array_values(array_filter($rec, fn($p) => !$has($p)));
            // Breadcrumb items need position + name (+ item, except on the last one)
            if ($ruleKey === 'BreadcrumbList' && is_array($node['itemListElement'] ?? null)) {
                foreach (array_values($node['itemListElement']) as $i => $li) {
                    foreach (['position', 'name'] as $p) {
                        if (is_array($li) && empty($li[$p]) && empty($li['item'][$p] ?? null)) {
                            $missingReq[] = "itemListElement[$i].$p";
                        }
                    }
                }
            }
        }
        $entities[] = [
            'type' => implode(', ', array_map('strval', $types)),
            'path' => $path,
            'known' => (bool) $ruleKey,
            'name' => is_string($node['name'] ?? null) ? mb_substr($node['name'], 0, 80) : (is_string($node['headline'] ?? null) ? mb_substr($node['headline'], 0, 80) : ''),
            'missing_required' => array_slice(array_unique($missingReq), 0, 10),
            'missing_recommended' => $missingRec,
        ];
    }
    foreach ($node as $k => $v) {
        if (is_array($v) && !str_starts_with((string) $k, '@')) {
            if (array_is_list($v)) {
                foreach (array_slice($v, 0, 20) as $i => $item) {
                    if (is_array($item)) {
                        inspector_collect_entities($item, $entities, ltrim($path . '.' . $k . '[' . $i . ']', '.'));
                    }
                }
            } else {
                inspector_collect_entities($v, $entities, ltrim("$path.$k", '.'));
            }
        }
        if (count($entities) >= 60) {
            return;
        }
    }
}

/* ---------------------------------------------------------------------------
 * Step 2: Google PageSpeed Insights (Lighthouse), mobile or desktop
 * ------------------------------------------------------------------------- */

function inspector_pagespeed(string $url, string $strategy): array
{
    $query = http_build_query(['url' => $url, 'strategy' => $strategy, 'key' => PAGESPEED_API_KEY ?: null]);
    foreach (['PERFORMANCE', 'SEO', 'ACCESSIBILITY', 'BEST_PRACTICES'] as $c) {
        $query .= '&category=' . $c;
    }
    $res = inspector_fetch_many(['psi' => ['url' => 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $query, 'max_bytes' => 25_000_000, 'timeout' => 110]])['psi'];
    $data = json_decode($res['body'], true);
    if ($res['error'] || !is_array($data) || empty($data['lighthouseResult'])) {
        $msg = $data['error']['message'] ?? $res['error'] ?? ('HTTP ' . $res['status']);
        if ($res['status'] === 429) {
            $msg = 'Google’s shared PageSpeed quota is used up. Add a free PAGESPEED_API_KEY in .env.';
        }
        return ['error' => mb_substr((string) $msg, 0, 300)];
    }
    return inspector_parse_pagespeed($data, $url, $strategy);
}

/** Compact summary of a PageSpeed Insights response (its lighthouseResult is a standard Lighthouse report). */
function inspector_parse_pagespeed(array $data, string $url, string $strategy): array
{
    $lh = $data['lighthouseResult'];
    $audit = fn(string $id) => $lh['audits'][$id] ?? null;
    $metrics = [];
    foreach (['first-contentful-paint', 'largest-contentful-paint', 'total-blocking-time', 'cumulative-layout-shift', 'speed-index', 'interactive', 'server-response-time'] as $id) {
        if ($a = $audit($id)) {
            $metrics[$id] = ['title' => $a['title'] ?? $id, 'value' => $a['numericValue'] ?? null, 'display' => $a['displayValue'] ?? '', 'score' => $a['score'] ?? null];
        }
    }
    $scores = [];
    $failing = [];
    foreach ($lh['categories'] ?? [] as $cid => $cat) {
        $scores[$cid] = isset($cat['score']) ? (int) round($cat['score'] * 100) : null;
        $list = [];
        foreach ($cat['auditRefs'] ?? [] as $ref) {
            $a = $audit($ref['id']);
            if (!$a || ($ref['weight'] ?? 0) === 0 && $cid === 'performance' || in_array($a['scoreDisplayMode'] ?? '', ['notApplicable', 'informative', 'manual', 'error'], true)) {
                continue;
            }
            if (isset($a['score']) && $a['score'] !== null && $a['score'] < 0.9) {
                $list[] = ['title' => strip_tags((string) $a['title']), 'display' => $a['displayValue'] ?? '', 'score' => $a['score']];
            }
        }
        usort($list, fn($x, $y) => $x['score'] <=> $y['score']);
        $failing[$cid] = array_slice($list, 0, 12);
    }
    $opportunities = [];
    foreach ($lh['audits'] as $id => $a) {
        $savings = $a['details']['overallSavingsMs'] ?? ($a['metricSavings']['LCP'] ?? 0);
        if (($a['details']['type'] ?? '') === 'opportunity' && $savings > 0 && ($a['score'] ?? 1) < 0.9) {
            $opportunities[] = ['title' => strip_tags((string) $a['title']), 'savings_ms' => (int) $savings, 'display' => $a['displayValue'] ?? ''];
        }
    }
    usort($opportunities, fn($x, $y) => $y['savings_ms'] <=> $x['savings_ms']);
    $field = null;
    if (!empty($data['loadingExperience']['metrics'])) {
        $field = ['overall' => $data['loadingExperience']['overall_category'] ?? null, 'origin_fallback' => !empty($data['loadingExperience']['origin_fallback']), 'metrics' => []];
        foreach ($data['loadingExperience']['metrics'] as $k => $m) {
            $field['metrics'][$k] = ['p75' => $m['percentile'] ?? null, 'category' => $m['category'] ?? null];
        }
    }
    return [
        'strategy' => $strategy,
        'scores' => $scores,
        'metrics' => $metrics,
        'opportunities' => array_slice($opportunities, 0, 10),
        'failing' => $failing,
        'field' => $field,
        'final_url' => $lh['finalDisplayedUrl'] ?? $lh['finalUrl'] ?? $url,
        'lighthouse_version' => $lh['lighthouseVersion'] ?? '',
        'screenshot' => $audit('final-screenshot')['details']['data'] ?? null,
    ];
}

/* ---------------------------------------------------------------------------
 * Step 3: checks and scores
 * ------------------------------------------------------------------------- */

/** All checks by category, from the crawl and the two PageSpeed runs. */
function inspector_build_checks(array $c, ?array $mobile, ?array $desktop): array
{
    $out = array_fill_keys(array_keys(INSPECTOR_CATEGORIES), []);
    $add = function (string $cat, array $check) use (&$out) {
        $out[$cat][] = $check;
    };
    $f = $c['fetch'];
    $p = $c['page'];
    $isHttps = str_starts_with($c['final_url'], 'https://');
    $isHome = in_array(parse_url($c['final_url'], PHP_URL_PATH) ?: '/', ['/', ''], true);

    /* ---- Performance ---- */
    $ttfb = (float) ($f['time']['ttfb'] ?? 0);
    $add('performance', inspector_check('ttfb', 'Server response time (TTFB)', $ttfb <= 600 ? 'pass' : ($ttfb <= 1500 ? 'warn' : 'fail'),
        'First byte after ' . inspector_fmt_ms($ttfb) . ' (measured from our server; aim for under 600 ms).', 'high'));
    $kb = $f['html_size'] / 1024;
    $add('performance', inspector_check('html_size', 'HTML document size', $kb <= 150 ? 'pass' : ($kb <= 400 ? 'warn' : 'fail'),
        round($kb) . ' KB of HTML (' . round($f['transfer_size'] / 1024) . ' KB transferred).', 'medium'));
    $add('performance', inspector_check('compression', 'Text compression', in_array($f['encoding'], ['gzip', 'br', 'zstd', 'deflate'], true) ? 'pass' : 'fail',
        $f['encoding'] ? 'The page is served with ' . $f['encoding'] . ' compression.' : 'The HTML is not compressed. Enable Brotli or gzip on the server/CDN.', 'high'));
    $add('performance', inspector_check('http_version', 'Modern HTTP protocol', in_array($f['http_version'], ['HTTP/2', 'HTTP/3'], true) ? 'pass' : 'warn',
        ($f['http_version'] ?: 'Unknown protocol') . (in_array($f['http_version'], ['HTTP/2', 'HTTP/3'], true) ? ' lets the browser load files in parallel.' : '. Enable HTTP/2 or HTTP/3 for faster parallel loading.'), 'medium'));
    $blocking = count($p['blocking_scripts']);
    $add('performance', inspector_check('render_blocking', 'Render-blocking scripts', $blocking === 0 ? 'pass' : ($blocking <= 2 ? 'warn' : 'fail'),
        $blocking ? "$blocking script(s) in <head> load without async/defer and delay the first paint." : 'No blocking scripts in <head>.', 'high', $p['blocking_scripts']));
    $add('performance', inspector_check('requests', 'JavaScript and CSS files', $p['scripts'] <= 20 && $p['stylesheets'] <= 8 ? 'pass' : ($p['scripts'] <= 40 && $p['stylesheets'] <= 15 ? 'warn' : 'fail'),
        $p['scripts'] . ' script files and ' . $p['stylesheets'] . ' stylesheets' . ($p['inline_script_kb'] > 50 ? ', plus ' . $p['inline_script_kb'] . ' KB of inline JavaScript' : '') . '.', 'medium'));

    $imgs = $p['images'];
    $nImg = count($imgs);
    if ($nImg) {
        $noDims = count(array_filter($imgs, fn($i) => !$i['dims']));
        $add('performance', inspector_check('img_dimensions', 'Image width/height attributes', $noDims === 0 ? 'pass' : ($noDims / $nImg <= 0.3 ? 'warn' : 'fail'),
            $noDims ? "$noDims of $nImg images have no width/height, which causes layout shift (CLS)." : "All $nImg images reserve their space.", 'medium',
            array_map(fn($i) => $i['src'], array_slice(array_filter($imgs, fn($i) => !$i['dims']), 0, 10))));
        $lazy = count(array_filter($imgs, fn($i) => $i['lazy']));
        $add('performance', inspector_check('img_lazy', 'Lazy-loaded images', $nImg <= 3 || $lazy >= ($nImg - 3) * 0.5 ? 'pass' : 'warn',
            "$lazy of $nImg images use loading=\"lazy\". Off-screen images should load lazily (keep the hero image eager).", 'low'));
        $modern = count(array_filter($imgs, fn($i) => preg_match('/\.(webp|avif|svg)(\?|$)/i', (string) $i['src']))) + (int) $p['picture_modern_sources'];
        $heads = array_filter($c['image_heads'], fn($h) => $h['status'] === 200);
        $modern += count(array_filter($heads, fn($h) => preg_match('#image/(webp|avif|svg)#', (string) $h['type']) && !preg_match('/\.(webp|avif|svg)(\?|$)/i', $h['url'])));
        $add('performance', inspector_check('img_formats', 'Modern image formats', $modern >= $nImg * 0.6 ? 'pass' : ($modern > 0 ? 'warn' : 'fail'),
            "About $modern of $nImg images use WebP, AVIF or SVG. Serve WebP/AVIF for smaller files.", 'medium'));
        $heavy = array_filter($heads, fn($h) => $h['bytes'] > 300_000);
        if ($heads) {
            $add('performance', inspector_check('img_weight', 'Image file sizes', !$heavy ? 'pass' : (count($heavy) <= 2 ? 'warn' : 'fail'),
                $heavy ? count($heavy) . ' of the ' . count($heads) . ' images checked are over 300 KB.' : 'None of the ' . count($heads) . ' images checked are over 300 KB.', 'high',
                array_map(fn($h) => round($h['bytes'] / 1024) . ' KB — ' . $h['url'], $heavy)));
        }
    }
    $assets = array_filter($c['assets'], fn($a) => $a['status'] === 200);
    if ($assets) {
        $uncached = array_filter($assets, function ($a) {
            if (preg_match('/max-age=(\d+)/', (string) $a['cache_control'], $m)) {
                return (int) $m[1] < 7 * 86400;
            }
            return !$a['expires'] || strtotime((string) $a['expires']) < time() + 7 * 86400;
        });
        $add('performance', inspector_check('asset_cache', 'Browser caching of CSS/JS', !$uncached ? 'pass' : (count($uncached) <= count($assets) / 2 ? 'warn' : 'fail'),
            $uncached ? count($uncached) . ' of ' . count($assets) . ' static files are cached for less than 7 days.' : 'Static files are cached for at least 7 days.', 'medium',
            array_map(fn($a) => ($a['cache_control'] ?: 'no cache-control') . ' — ' . $a['url'], $uncached)));
    }
    foreach (['mobile' => $mobile, 'desktop' => $desktop] as $label => $psi) {
        if (!$psi || isset($psi['error'])) {
            continue;
        }
        $score = $psi['scores']['performance'] ?? null;
        if ($score !== null) {
            $add('performance', inspector_check("psi_$label", 'Lighthouse performance (' . $label . ')', $score >= 90 ? 'pass' : ($score >= 50 ? 'warn' : 'fail'),
                "Google Lighthouse scores the $label page $score/100.", 'high', array_map(fn($o) => $o['title'] . ($o['display'] ? ' — ' . $o['display'] : ''), array_slice($psi['opportunities'], 0, 6))));
        }
    }
    if ($mobile && !isset($mobile['error'])) {
        $m = $mobile['metrics'];
        $thr = [
            'largest-contentful-paint' => ['Largest Contentful Paint (mobile)', 2500, 4000, 'ms'],
            'cumulative-layout-shift' => ['Cumulative Layout Shift (mobile)', 0.1, 0.25, ''],
            'total-blocking-time' => ['Total Blocking Time (mobile)', 200, 600, 'ms'],
            'first-contentful-paint' => ['First Contentful Paint (mobile)', 1800, 3000, 'ms'],
            'speed-index' => ['Speed Index (mobile)', 3400, 5800, 'ms'],
        ];
        foreach ($thr as $id => [$title, $good, $poor, $unit]) {
            if (!isset($m[$id]['value'])) {
                continue;
            }
            $v = (float) $m[$id]['value'];
            $add('performance', inspector_check("cwv_$id", $title, $v <= $good ? 'pass' : ($v <= $poor ? 'warn' : 'fail'),
                ($m[$id]['display'] ?: round($v, 3)) . ' (good: ' . ($unit === 'ms' ? inspector_fmt_ms($good) : $good) . ' or less).', in_array($id, ['largest-contentful-paint', 'cumulative-layout-shift', 'total-blocking-time'], true) ? 'high' : 'medium'));
        }
        if ($mobile['field']) {
            $cat = $mobile['field']['overall'];
            $add('performance', inspector_check('field_cwv', 'Core Web Vitals of real visitors (Chrome UX Report)', $cat === 'FAST' ? 'pass' : ($cat === 'AVERAGE' ? 'warn' : ($cat === 'SLOW' ? 'fail' : 'info')),
                'Real-user experience over the last 28 days' . ($mobile['field']['origin_fallback'] ? ' (whole site)' : '') . ': ' . strtolower((string) $cat) . '.', 'high'));
        }
    }

    /* ---- SEO ---- */
    $title = $p['titles'][0] ?? '';
    $tl = mb_strlen($title);
    $add('seo', inspector_check('title', 'Title tag', $title === '' ? 'fail' : ($tl >= 30 && $tl <= 65 ? 'pass' : 'warn'),
        $title === '' ? 'The page has no <title>.' : "“{$title}” ($tl characters; aim for 30–65).", 'high'));
    if (count($p['titles']) > 1) {
        $add('seo', inspector_check('title_multi', 'Single title tag', 'warn', count($p['titles']) . ' <title> tags found; keep one.', 'low'));
    }
    $desc = $p['descriptions'][0] ?? '';
    $dl = mb_strlen($desc);
    $add('seo', inspector_check('description', 'Meta description', $desc === '' ? 'fail' : ($dl >= 70 && $dl <= 160 ? 'pass' : 'warn'),
        $desc === '' ? 'No meta description. Search engines will pick text from the page.' : "$dl characters (aim for 70–160): “" . mb_substr($desc, 0, 160) . '”', 'high'));
    $h1s = array_values(array_filter($p['headings'], fn($h) => $h[0] === 1));
    $add('seo', inspector_check('h1', 'H1 heading', count($h1s) === 1 ? 'pass' : (count($h1s) === 0 ? 'fail' : 'warn'),
        count($h1s) === 0 ? 'The page has no H1.' : (count($h1s) === 1 ? '“' . $h1s[0][1] . '”' : count($h1s) . ' H1 headings; use one main H1.'), 'high', array_map(fn($h) => $h[1], $h1s)));
    $skips = [];
    $prevLevel = 0;
    foreach ($p['headings'] as [$lvl, $text]) {
        if ($prevLevel && $lvl > $prevLevel + 1) {
            $skips[] = "H$prevLevel → H$lvl: “{$text}”";
        }
        $prevLevel = $lvl;
    }
    $add('seo', inspector_check('heading_order', 'Heading structure', $skips ? 'warn' : 'pass',
        $skips ? count($skips) . ' heading level(s) skipped.' : count($p['headings']) . ' headings in a logical order.', 'low', $skips));
    $canon = $p['canonical'][0] ?? '';
    $canonAbs = $canon ? inspector_absolute_url($canon, $c['final_url']) : null;
    $add('seo', inspector_check('canonical', 'Canonical URL', !$canon ? 'warn' : (rtrim((string) $canonAbs, '/') === rtrim($c['final_url'], '/') ? 'pass' : 'info'),
        !$canon ? 'No canonical link. Add <link rel="canonical"> to avoid duplicate-content issues.' : ($canonAbs === $c['final_url'] || rtrim((string) $canonAbs, '/') === rtrim($c['final_url'], '/') ? 'Points to this page.' : "Points to another URL: $canonAbs"), 'medium'));
    $xRobots = strtolower($f['headers']['x-robots-tag'] ?? '');
    $noindex = str_contains($p['robots_meta'], 'noindex') || str_contains($xRobots, 'noindex');
    $add('seo', inspector_check('indexable', 'Indexable by search engines', $noindex ? 'fail' : 'pass',
        $noindex ? 'The page tells search engines not to index it (noindex).' : 'No noindex directive.', 'high'));
    $r = $c['robots'];
    $add('seo', inspector_check('robots_txt', 'robots.txt', !$r['found'] ? 'warn' : ($r['disallow_all'] ? 'fail' : 'pass'),
        !$r['found'] ? 'No robots.txt (HTTP ' . $r['status'] . ').' : ($r['disallow_all'] ? 'robots.txt blocks all crawlers from the whole site.' : 'Found and allows crawling.'), 'medium'));
    $sm = $c['sitemap'];
    $add('seo', inspector_check('sitemap', 'XML sitemap', $sm ? ($r['sitemap_listed'] ? 'pass' : 'warn') : 'fail',
        $sm ? $sm['url'] . ' (' . $sm['count'] . ($sm['type'] === 'index' ? ' sitemaps' : ' URLs') . ')' . ($r['sitemap_listed'] ? '' : '. List it in robots.txt with "Sitemap:".') : 'No XML sitemap found in robots.txt or at /sitemap.xml.', 'medium'));
    $add('seo', inspector_check('lang', 'Language attribute', $p['lang'] !== '' ? 'pass' : 'warn',
        $p['lang'] !== '' ? '<html lang="' . $p['lang'] . '">' : 'Add a lang attribute to <html>.', 'low'));
    $add('seo', inspector_check('viewport', 'Mobile viewport', $p['viewport'] && str_contains($p['viewport'], 'width=device-width') ? 'pass' : 'fail',
        $p['viewport'] ? 'viewport: ' . $p['viewport'] : 'No viewport meta tag; the page will not scale on phones.', 'high'));
    $ogMissing = array_diff(['title', 'description', 'image', 'url'], array_keys($p['og']));
    $add('seo', inspector_check('open_graph', 'Open Graph (social sharing)', !$ogMissing ? 'pass' : (count($ogMissing) < 4 ? 'warn' : 'fail'),
        !$ogMissing ? 'og:title, og:description, og:image and og:url are set.' : 'Missing: og:' . implode(', og:', $ogMissing) . '.', 'low'));
    $add('seo', inspector_check('twitter_card', 'Twitter / X card', isset($p['twitter']['card']) ? 'pass' : 'warn',
        isset($p['twitter']['card']) ? 'twitter:card = ' . $p['twitter']['card'] : 'No twitter:card meta tag.', 'low'));
    if ($nImg) {
        $noAlt = array_filter($imgs, fn($i) => $i['alt'] === null);
        $add('seo', inspector_check('img_alt', 'Image alt text', !$noAlt ? 'pass' : (count($noAlt) / $nImg <= 0.2 ? 'warn' : 'fail'),
            $noAlt ? count($noAlt) . " of $nImg images have no alt attribute." : "All $nImg images have an alt attribute.", 'medium', array_map(fn($i) => (string) $i['src'], array_slice($noAlt, 0, 10))));
    }
    $add('seo', inspector_check('content', 'Content length', $p['word_count'] >= 300 ? 'pass' : ($p['word_count'] >= 150 ? 'warn' : 'fail'),
        number_format($p['word_count']) . ' words of visible text' . ($p['word_count'] < 300 ? ' (thin content; aim for 300+).' : '.'), 'medium'));
    $add('seo', inspector_check('internal_links', 'Internal links', $p['links_internal'] >= 3 ? 'pass' : 'warn',
        $p['links_internal'] . ' internal and ' . $p['links_external'] . ' external links' . ($p['links_nofollow'] ? ' (' . $p['links_nofollow'] . ' nofollow)' : '') . '.', 'low'));
    $broken = array_filter($c['links'], fn($l) => $l['status'] >= 400 || ($l['status'] === 0 && $l['error'] && !str_contains((string) $l['error'], 'private')));
    if ($c['links']) {
        $add('seo', inspector_check('broken_links', 'Broken links', !$broken ? 'pass' : (count($broken) <= 2 ? 'warn' : 'fail'),
            $broken ? count($broken) . ' of ' . count($c['links']) . ' links checked are broken.' : 'All ' . count($c['links']) . ' links checked work.', 'high',
            array_map(fn($l) => ($l['status'] ?: 'error') . ' — ' . $l['url'], $broken)));
    }
    $add('seo', inspector_check('favicon', 'Favicon', $p['favicon'] ? 'pass' : 'warn', $p['favicon'] ? 'A favicon is declared.' : 'No favicon link tag.', 'low'));
    $path = (string) parse_url($c['final_url'], PHP_URL_PATH);
    $urlIssues = array_filter([strlen($c['final_url']) > 115 ? 'long URL' : null, preg_match('/[A-Z]/', $path) ? 'uppercase letters' : null, str_contains($path, '_') ? 'underscores' : null]);
    $add('seo', inspector_check('url', 'SEO-friendly URL', $urlIssues ? 'warn' : 'pass', $urlIssues ? 'URL has ' . implode(', ', $urlIssues) . '.' : 'Short, lowercase URL.', 'low'));
    if ($p['hreflang']) {
        $add('seo', inspector_check('hreflang', 'hreflang tags', 'info', count($p['hreflang']) . ' language versions declared: ' . implode(', ', array_slice($p['hreflang'], 0, 12)) . '.', 'low'));
    }
    if ($mobile && !isset($mobile['error']) && isset($mobile['scores']['seo'])) {
        $s = $mobile['scores']['seo'];
        $add('seo', inspector_check('psi_seo', 'Lighthouse SEO audit', $s >= 90 ? 'pass' : ($s >= 70 ? 'warn' : 'fail'), "Lighthouse SEO score $s/100.", 'medium',
            array_map(fn($a) => $a['title'], $mobile['failing']['seo'] ?? [])));
    }

    /* ---- Schema ---- */
    $s = $c['schema'];
    $hasAny = $s['jsonld_count'] || $s['microdata'] || $s['rdfa'];
    $add('schema', inspector_check('schema_present', 'Structured data present', $hasAny ? 'pass' : 'fail',
        $hasAny ? $s['jsonld_count'] . ' JSON-LD block(s)' . ($s['microdata'] ? ', microdata: ' . implode(', ', $s['microdata']) : '') . ($s['rdfa'] ? ', RDFa: ' . implode(', ', $s['rdfa']) : '') . '.' : 'No schema.org structured data. Rich results in Google need it.', 'high'));
    if ($s['jsonld_count']) {
        $add('schema', inspector_check('schema_syntax', 'JSON-LD syntax', $s['errors'] ? 'fail' : 'pass',
            $s['errors'] ? count($s['errors']) . ' problem(s) in the JSON-LD.' : 'All JSON-LD blocks are valid JSON with a schema.org context.', 'high', $s['errors']));
    }
    $types = array_map(fn($e) => explode(', ', $e['type'])[0], $s['entities']);
    $resolved = array_map(fn($t) => INSPECTOR_SCHEMA_ALIASES[$t] ?? $t, $types);
    $hasOrg = (bool) array_intersect($resolved, ['Organization', 'LocalBusiness']) || in_array('Organization', $s['microdata'], true);
    $add('schema', inspector_check('schema_org', 'Organization / LocalBusiness', $hasOrg ? 'pass' : 'warn',
        $hasOrg ? 'Business identity is described.' : 'Add Organization (or LocalBusiness) schema with name, logo, url and sameAs links.', 'medium'));
    $add('schema', inspector_check('schema_website', 'WebSite', in_array('WebSite', $resolved, true) ? 'pass' : ($isHome ? 'warn' : 'info'),
        in_array('WebSite', $resolved, true) ? 'WebSite schema found.' : 'Add WebSite schema on the home page (site name in Google results).', 'low'));
    if (!$isHome) {
        $add('schema', inspector_check('schema_breadcrumb', 'BreadcrumbList', in_array('BreadcrumbList', $resolved, true) ? 'pass' : 'warn',
            in_array('BreadcrumbList', $resolved, true) ? 'Breadcrumbs are marked up.' : 'Add BreadcrumbList schema for breadcrumb trails in search results.', 'low'));
    }
    foreach ($s['entities'] as $i => $e) {
        if (!$e['known']) {
            continue;
        }
        $label = $e['type'] . ($e['name'] !== '' ? ' “' . $e['name'] . '”' : '') . ($e['path'] ? ' (' . $e['path'] . ')' : '');
        if ($e['missing_required']) {
            $add('schema', inspector_check("entity_$i", $label, 'fail', 'Missing required: ' . implode(', ', $e['missing_required']) . '.', 'high'));
        } elseif ($e['missing_recommended']) {
            $add('schema', inspector_check("entity_$i", $label, 'warn', 'Valid. Recommended to add: ' . implode(', ', $e['missing_recommended']) . '.', 'low'));
        } else {
            $add('schema', inspector_check("entity_$i", $label, 'pass', 'All required and recommended properties are present.', 'low'));
        }
    }
    $unknown = array_unique(array_map(fn($e) => $e['type'], array_filter($s['entities'], fn($e) => !$e['known'])));
    if ($unknown) {
        $add('schema', inspector_check('schema_other', 'Other schema types', 'info', implode(', ', array_slice($unknown, 0, 15)), 'low'));
    }

    /* ---- Technical & security ---- */
    $st = (int) $f['status'];
    $add('technical', inspector_check('status', 'HTTP status', $st >= 200 && $st < 300 ? 'pass' : 'fail', "The page answers HTTP $st.", 'high'));
    $add('technical', inspector_check('https', 'HTTPS', $isHttps ? 'pass' : 'fail', $isHttps ? 'The page is served over HTTPS.' : 'The page is not served over HTTPS.', 'high'));
    if ($c['http_redirect']) {
        $ok = str_starts_with((string) $c['http_redirect']['final_url'], 'https://') && !$c['http_redirect']['error'];
        $add('technical', inspector_check('http_to_https', 'HTTP → HTTPS redirect', $ok ? 'pass' : 'fail',
            $ok ? 'http:// redirects to HTTPS.' : 'http:// does not redirect to HTTPS' . ($c['http_redirect']['error'] ? ' (' . $c['http_redirect']['error'] . ')' : '') . '.', 'high'));
    }
    if ($c['certificate']) {
        $cert = $c['certificate'];
        if (isset($cert['error']) || $c['ssl_error']) {
            $add('technical', inspector_check('ssl', 'SSL certificate', 'fail', 'Certificate problem: ' . ($c['ssl_error'] ?: $cert['error']), 'high'));
        } else {
            $days = $cert['days_left'];
            $add('technical', inspector_check('ssl', 'SSL certificate', !$cert['covers_host'] || $days < 7 ? 'fail' : ($days < 21 ? 'warn' : 'pass'),
                ($cert['covers_host'] ? 'Valid for this host' : 'Does NOT cover ' . $c['host']) . ", issued by {$cert['issuer']}, expires {$cert['valid_to']} ($days days).", 'high'));
        }
    }
    $redirects = count($f['redirects']);
    $add('technical', inspector_check('redirects', 'Redirect chain', $redirects <= 1 ? 'pass' : ($redirects === 2 ? 'warn' : 'fail'),
        $redirects ? "$redirects redirect(s) before the page: " . implode(' → ', array_map(fn($r) => $r['status'], $f['redirects'])) : 'No redirects.', 'medium',
        array_map(fn($r) => $r['status'] . ' ' . $r['url'], $f['redirects'])));
    if ($c['alt_host'] && !$c['alt_host']['error'] && $c['alt_host']['status']) {
        $altFinalHost = strtolower((string) parse_url($c['alt_host']['final_url'], PHP_URL_HOST));
        $ok = $altFinalHost === $c['host'];
        $add('technical', inspector_check('www', 'www / non-www consistency', $ok ? 'pass' : 'warn',
            $ok ? parse_url($c['alt_host']['url'], PHP_URL_HOST) . ' redirects to ' . $c['host'] . '.' : parse_url($c['alt_host']['url'], PHP_URL_HOST) . ' does not redirect to ' . $c['host'] . ' (possible duplicate site).', 'medium'));
    }
    $p404 = (int) $c['probe404']['status'];
    $add('technical', inspector_check('404', 'Missing pages return 404', in_array($p404, [404, 410], true) ? 'pass' : ($p404 >= 500 || $p404 === 0 ? 'fail' : 'warn'),
        in_array($p404, [404, 410], true) ? "A made-up URL correctly returns $p404." : "A made-up URL returns HTTP $p404" . ($p404 >= 200 && $p404 < 400 ? ' (soft 404: search engines may index error pages).' : '.'), 'medium'));
    $hsts = $f['headers']['strict-transport-security'] ?? null;
    if ($isHttps) {
        $add('technical', inspector_check('hsts', 'HSTS header', $hsts ? 'pass' : 'warn', $hsts ? "Strict-Transport-Security: $hsts" : 'No Strict-Transport-Security header.', 'medium'));
    }
    $sec = [
        'content-security-policy' => ['Content-Security-Policy', 'low'],
        'x-content-type-options' => ['X-Content-Type-Options', 'low'],
        'referrer-policy' => ['Referrer-Policy', 'low'],
        'permissions-policy' => ['Permissions-Policy', 'low'],
    ];
    $missingSec = [];
    foreach ($sec as $h => [$label]) {
        if (empty($f['headers'][$h])) {
            $missingSec[] = $label;
        }
    }
    $frame = !empty($f['headers']['x-frame-options']) || str_contains(strtolower($f['headers']['content-security-policy'] ?? ''), 'frame-ancestors');
    if (!$frame) {
        $missingSec[] = 'X-Frame-Options (or CSP frame-ancestors)';
    }
    $add('technical', inspector_check('security_headers', 'Security headers', !$missingSec ? 'pass' : (count($missingSec) <= 2 ? 'warn' : 'fail'),
        $missingSec ? 'Missing: ' . implode(', ', $missingSec) . '.' : 'All key security headers are set.', 'medium', $missingSec));
    $leak = array_filter([
        $f['server'] && preg_match('/\d/', $f['server']) ? 'Server: ' . $f['server'] : null,
        $f['powered_by'] ? 'X-Powered-By: ' . $f['powered_by'] : null,
    ]);
    $add('technical', inspector_check('version_leak', 'Server version disclosure', $leak ? 'warn' : 'pass',
        $leak ? 'Headers reveal software versions: ' . implode('; ', $leak) . '.' : 'No software versions exposed in headers.', 'low'));
    if ($f['cookies']) {
        $weak = array_filter($f['cookies'], fn($ck) => ($isHttps && !preg_match('/;\s*secure/i', $ck)) || !preg_match('/;\s*httponly/i', $ck) || !preg_match('/;\s*samesite/i', $ck));
        $add('technical', inspector_check('cookies', 'Cookie security flags', $weak ? 'warn' : 'pass',
            $weak ? count($weak) . ' of ' . count($f['cookies']) . ' cookies lack Secure, HttpOnly or SameSite.' : 'Cookies set Secure, HttpOnly and SameSite.', 'low',
            array_map(fn($ck) => explode('=', $ck, 2)[0], $weak)));
    }
    if ($isHttps) {
        $add('technical', inspector_check('mixed_content', 'Mixed content', $p['mixed_content'] ? 'fail' : 'pass',
            $p['mixed_content'] ? count($p['mixed_content']) . ' resource(s) load over insecure http://.' : 'All resources load over HTTPS.', 'high', $p['mixed_content']));
    }
    $add('technical', inspector_check('doctype', 'HTML5 doctype', $p['doctype'] ? 'pass' : 'warn', $p['doctype'] ? '<!DOCTYPE html> is declared.' : 'No HTML5 doctype; browsers may use quirks mode.', 'low'));
    $add('technical', inspector_check('charset', 'Character encoding', $p['charset'] ? 'pass' : 'warn', $p['charset'] ? 'Character set declared.' : 'No charset in a meta tag or Content-Type header.', 'low'));
    if ($p['deprecated_tags']) {
        $add('technical', inspector_check('deprecated_html', 'Deprecated HTML', 'warn', 'Obsolete tags used: ' . implode(', ', $p['deprecated_tags']) . '.', 'low'));
    }
    foreach (['best-practices' => 'Lighthouse best practices', 'accessibility' => 'Lighthouse accessibility'] as $cat => $title) {
        if ($mobile && !isset($mobile['error']) && isset($mobile['scores'][$cat])) {
            $sc = $mobile['scores'][$cat];
            $add('technical', inspector_check("psi_$cat", $title, $sc >= 90 ? 'pass' : ($sc >= 70 ? 'warn' : 'fail'), "Score $sc/100.", 'medium',
                array_map(fn($a) => $a['title'], $mobile['failing'][$cat] ?? [])));
        }
    }
    return $out;
}

/** 0–100 from checks: pass = 1, warn = ½, fail = 0, weighted by impact; "info" checks do not count. */
function inspector_score(array $checks): ?int
{
    $w = ['high' => 3, 'medium' => 2, 'low' => 1];
    $total = $got = 0;
    foreach ($checks as $ck) {
        if ($ck['status'] === 'info') {
            continue;
        }
        $weight = $w[$ck['impact']] ?? 1;
        $total += $weight;
        $got += $weight * ['pass' => 1, 'warn' => 0.5, 'fail' => 0][$ck['status']];
    }
    return $total ? (int) round($got / $total * 100) : null;
}

/** Merge the steps into the final result and scores; returns [result, scores]. */
function inspector_finish(array $crawl, ?array $mobile, ?array $desktop): array
{
    $checks = inspector_build_checks($crawl, $mobile, $desktop);
    $scores = [];
    foreach ($checks as $cat => $list) {
        $scores[$cat] = inspector_score($list);
    }
    $valid = array_filter($scores, fn($s) => $s !== null);
    $overall = $valid ? (int) round(array_sum($valid) / count($valid)) : null;
    $counts = ['fail' => 0, 'warn' => 0, 'pass' => 0, 'info' => 0];
    $issues = [];
    foreach ($checks as $cat => $list) {
        foreach ($list as $ck) {
            $counts[$ck['status']]++;
            if (in_array($ck['status'], ['fail', 'warn'], true)) {
                $issues[] = $ck + ['category' => $cat];
            }
        }
    }
    // Priority fixes: failures first, then by impact
    $rank = fn($c) => ($c['status'] === 'fail' ? 0 : 10) + ['high' => 0, 'medium' => 1, 'low' => 2][$c['impact']];
    usort($issues, fn($a, $b) => $rank($a) <=> $rank($b));
    return [[
        'checks' => $checks,
        'scores' => $scores,
        'overall' => $overall,
        'counts' => $counts,
        'priorities' => array_slice($issues, 0, 10),
    ], $scores + ['overall' => $overall]];
}

/* ---------------------------------------------------------------------------
 * Presentation helpers (web report and PDF)
 * ------------------------------------------------------------------------- */

/** Hex colour for a 0–100 score (Lighthouse bands). */
function inspector_score_color(?int $score): string
{
    if ($score === null) {
        return '#94a3b8';
    }
    return $score >= 90 ? '#16a34a' : ($score >= 50 ? '#d97706' : '#dc2626');
}

function inspector_score_label(?int $score): string
{
    if ($score === null) {
        return 'n/a';
    }
    return $score >= 90 ? 'Good' : ($score >= 50 ? 'Needs work' : 'Poor');
}

function inspector_status_label(string $status): string
{
    return ['pass' => 'Passed', 'warn' => 'Warning', 'fail' => 'Failed', 'info' => 'Info'][$status] ?? $status;
}

function find_audit_or_404(int $id): array
{
    $a = q_one('SELECT a.*, u.name creator_name FROM site_audits a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ? AND a.workspace_id = ?', [$id, ws_id()]);
    if (!$a) {
        abort(404, 'Audit not found.');
    }
    return $a;
}
