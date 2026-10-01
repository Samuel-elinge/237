<?php
/**
 * includes/schema.php
 * Structured data (JSON-LD) helpers for 237Biz.
 * Include after config.php, call the relevant function, echo the result
 * inside a <script type="application/ld+json"> tag in <head> or before </body>.
 */

/**
 * Sitewide Organization schema — include once on every page (homepage especially).
 */
function schemaOrganization(): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'Organization',
        'name'     => '237Biz',
        'url'      => SITE_URL,
        'logo'     => SITE_URL . '/assets/img/logo.png',
        'description' => 'Cameroon business directory — find verified businesses, services, restaurants, hotels and professionals.',
        'sameAs'   => array_filter([
            defined('FACEBOOK_URL') ? FACEBOOK_URL : null,
        ]),
    ];
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * WebSite schema with SearchAction — enables Google sitelinks search box.
 */
function schemaWebsite(): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'WebSite',
        'name'     => '237Biz',
        'url'      => SITE_URL,
        'potentialAction' => [
            '@type'       => 'SearchAction',
            'target'      => [
                '@type'       => 'EntryPoint',
                'urlTemplate' => SITE_URL . '/listings?q={search_term_string}',
            ],
            'query-input' => 'required name=search_term_string',
        ],
    ];
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * LocalBusiness schema for a single listing.
 * Pass the listing row (array) as returned from the `listings` JOIN query,
 * plus the resolved category/location names and average rating if available.
 */
function schemaLocalBusiness(array $l, string $catName, string $locName, float $avgRating = 0, int $reviewCount = 0): string {
    $data = [
        '@context' => 'https://schema.org',
        '@type'    => 'LocalBusiness',
        'name'     => $l['title'],
        'description' => mb_substr(strip_tags($l['description'] ?? ''), 0, 300),
        'url'      => SITE_URL . '/listing/' . $l['slug'],
    ];

    if (!empty($l['logo']))        $data['image']      = UPLOAD_URL . $l['logo'];
    if (!empty($l['phone']))       $data['telephone']  = $l['phone'];
    if (!empty($l['email']))       $data['email']      = $l['email'];
    if (!empty($l['website']))     $data['sameAs']      = array_values(array_filter([
        $l['website'] ?? null,
        $l['facebook'] ?? null,
        $l['instagram'] ?? null,
        $l['tiktok'] ?? null,
    ]));

    if (!empty($l['address']) || !empty($locName)) {
        $data['address'] = [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $l['address'] ?? '',
            'addressLocality' => $locName,
            'addressCountry'  => 'CM',
        ];
    }

    if (!empty($l['lat']) && !empty($l['lng'])) {
        $data['geo'] = [
            '@type'     => 'GeoCoordinates',
            'latitude'  => (float)$l['lat'],
            'longitude' => (float)$l['lng'],
        ];
    }

    if ($catName) {
        $data['additionalType'] = $catName;
    }

    if ($avgRating > 0 && $reviewCount > 0) {
        $data['aggregateRating'] = [
            '@type'       => 'AggregateRating',
            'ratingValue' => $avgRating,
            'reviewCount' => $reviewCount,
        ];
    }

    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * BreadcrumbList schema. Pass an ordered array of ['name' => ..., 'url' => ...].
 * The last item should be the current page (url can be omitted/null for it).
 */
function schemaBreadcrumb(array $items): string {
    $list = [];
    foreach ($items as $i => $item) {
        $entry = [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'name'     => $item['name'],
        ];
        if (!empty($item['url'])) $entry['item'] = $item['url'];
        $list[] = $entry;
    }
    $data = [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => $list,
    ];
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * FAQPage schema. Pass an array of ['question' => ..., 'answer' => ...].
 * Use on the listing page when FAQs exist, and on any static FAQ page.
 */
function schemaFaq(array $faqs): string {
    if (!$faqs) return '';
    $entities = array_map(fn($f) => [
        '@type' => 'Question',
        'name'  => $f['question'],
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text'  => $f['answer'],
        ],
    ], $faqs);

    $data = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => $entities,
    ];
    return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Echo a JSON-LD string inside the required script tag. Skips output if empty.
 */
function printSchema(string $json): void {
    if (!$json) return;
    echo '<script type="application/ld+json">' . $json . '</script>' . "\n";
}
