<?php
/**
 * Landing page copy: editable text for every marketing section, with the
 * defaults below used until an administrator saves changes.
 *
 * Each section is one settings row of JSON (`landing_home`, `landing_how`,
 * `landing_printer`, `landing_types`, `landing_promise`, `landing_faq`,
 * `landing_cta`). A missing field falls back to these defaults, so an older
 * or partial row still renders a complete page.
 */

declare(strict_types=1);

/**
 * The paper sizes the customer can choose right now, written for prose:
 * "4 × 6 in, 5 × 7 in or A4".
 */
function landing_paper_choices(): string
{
    $labels = array_map('paper_size_label', enabled_paper_sizes());

    if ($labels === []) {
        return '4 × 6';
    }

    if (count($labels) === 1) {
        return $labels[0];
    }

    $last = array_pop($labels);

    return implode(', ', $labels) . ' or ' . $last;
}

/**
 * The copy the landing page shows out of the box.
 *
 * @return array<string,array<string,mixed>>
 */
function landing_defaults(): array
{
    return [
        'home' => [
            'title'  => 'Your photos.',
            'accent' => 'Printed free.',
            'lead'   => 'Select up to ' . MAX_PHOTOS_PER_ORDER . ' photos — they upload ' . MAX_PHOTOS_PER_BATCH
                . ' at a time — choose a print size and finish, and they go straight to our Epson ET-2926 on real photo paper.'
                . ' No credits, no subscriptions, no card details.',
            'trust'  => [
                'No payments, ever',
                'Real photo paper',
                'Track every print',
                'Private photo storage',
            ],
        ],
        'how' => [
            'eyebrow' => 'Three easy steps',
            'heading' => 'From camera roll to photo print',
            'intro'   => 'No drivers to install and no app to download. Everything happens in your browser, on any device.',
            'steps'   => [
                [
                    'title' => 'Upload your photos',
                    'text'  => 'Select up to ' . MAX_PHOTOS_PER_ORDER . ' photos at once — JPG, PNG, HEIC, WebP, GIF, BMP, TIFF and more.'
                        . ' They upload ' . MAX_PHOTOS_PER_BATCH . ' at a time, so a big album never needs one huge request.'
                        . ' Up to ' . MAX_UPLOAD_MB . ' MB each.',
                ],
                [
                    'title' => 'Pick 4 × 6 & finish',
                    'text'  => 'Choose ' . landing_paper_choices() . ', borderless or a white frame, colour and quality. Every photo prints once.',
                ],
                [
                    'title' => 'Watch it print',
                    'text'  => 'Your dashboard shows the queue and the live status. We send the photo to the ET-2926 and mark it printed.',
                ],
            ],
        ],
        'printer' => [
            'eyebrow' => 'The hardware',
            'heading' => 'Real ink on real photo paper',
            'body'    => "Every photo travels in Epson Connect's photo mode to an Epson ET-2926 EcoTank sitting on a desk."
                . ' That means a proper borderless 4 × 6 print from the rear feeder — and refillable ink tanks instead of cartridges,'
                . ' so free photo printing can actually keep going.',
            'points'  => [
                '4 × 6 in borderless prints on Epson photo paper',
                '5 × 7 in and A4 when you want the photo bigger',
                'High-quality photo mode through the Epson Connect API v2',
                '4-colour EcoTank ink with refillable bottles',
            ],
        ],
        'types' => [
            'eyebrow' => 'Made for the photos on your phone',
            'heading' => 'If you can take it, print it',
            'intro'   => 'A free photo lab for the pictures that never make it off your camera roll.',
            'items'   => [
                '4 × 6 snapshots',
                'Phone camera roll',
                'Holiday photos',
                'Pet portraits',
                'Family photos',
                'Thank-you prints',
                'Mini wall prints',
                'Fridge favourites',
            ],
        ],
        'promise' => [
            'eyebrow' => 'The promise',
            'heading' => 'Free photos, actually',
            'body'    => 'No card halfway through, no print allowance, no printer hidden behind a paywall.'
                . ' Upload, choose 4 × 6, print — and that is the whole product.',
            'items'   => [
                [
                    'title' => 'No payments, no credits',
                    'text'  => '4 × 6, borderless, high quality and reprints are all included.',
                ],
                [
                    'title' => 'No account fees',
                    'text'  => 'Signing up takes an email address and a password. Nothing else.',
                ],
                [
                    'title' => 'Kinder on ink',
                    'text'  => 'EcoTank refillable ink keeps running costs low, so free stays free.',
                ],
            ],
        ],
        'cta' => [
            'eyebrow' => 'Ready when you are',
            'heading' => 'Your favourite photo is one upload away',
            'body'    => 'Create a free account, pick a photo and follow it all the way to photo paper.',
        ],
        'faq' => [
            'eyebrow' => 'Questions',
            'heading' => 'The bits people ask about',
            'items'   => [
                [
                    'q' => 'Is it really free, or is there a catch?',
                    'a' => 'It is really free. There is no payment form anywhere on the site, no credits to top up and no print allowance. Photos print on the house, 4 × 6 included.',
                ],
                [
                    'q' => 'What photos can I print?',
                    'a' => 'Up to ' . MAX_PHOTOS_PER_ORDER . ' photos per order — JPG, PNG, HEIC, WebP, GIF, BMP, TIFF, AVIF and similar.'
                        . ' You can select them all at once; they upload automatically in batches of up to ' . MAX_PHOTOS_PER_BATCH
                        . ', ' . MAX_UPLOAD_MB . ' MB each. Choose ' . landing_paper_choices() . ', borderless or with a white border, colour or'
                        . ' black and white, and standard or high quality. Every photo prints once — ask the print desk if you ever need a reprint.',
                ],
                [
                    'q' => 'Will 4 × 6 crop my photo?',
                    'a' => '4 × 6 is a 3:2 shape, so a photo taken in that ratio fits perfectly. Taller or wider photos are fitted to the paper and the edges may be trimmed. Add a note if you want a particular part kept in frame.',
                ],
                [
                    'q' => 'How long does a print take?',
                    'a' => 'Photos are printed in the order they arrive, usually the same day. Your dashboard shows whether a print is in the queue, printing or finished, so you never have to guess.',
                ],
                [
                    'q' => 'Is my photo private?',
                    'a' => 'Your photos are stored outside the public website folder and only administrators can open them. Other customers cannot see your photos or your orders. Ask us and we will delete a photo and its order for you.',
                ],
                [
                    'q' => 'Can I print from my phone?',
                    'a' => 'Yes. The whole site works in a mobile browser — choose a photo from your camera roll, set 4 × 6 and send it. No Epson app or Epson account is needed; the print desk handles the printer for you.',
                ],
                [
                    'q' => 'What paper do you print on?',
                    'a' => 'Epson photo paper in the rear feeder, which gives the best colour and a borderless 4 × 6 finish. If your photo needs something specific — matte, a white border, a bigger size — add it to the order notes.',
                ],
            ],
        ],
    ];
}

/**
 * Trim one editable field to its length limit.
 */
function landing_text(mixed $value, int $max): string
{
    return mb_substr(trim((string) $value), 0, $max);
}

/**
 * The landing copy in use: the saved value for each section, filled in with the
 * defaults for anything missing or malformed.
 *
 * @return array<string,array<string,mixed>>
 */
function landing_content(): array
{
    static $content = null;

    if ($content !== null) {
        return $content;
    }

    $defaults = landing_defaults();
    $content  = $defaults;

    $home = json_decode((string) setting('landing_home', ''), true);

    if (is_array($home)) {
        foreach (['title', 'accent', 'lead'] as $key) {
            if (isset($home[$key]) && is_string($home[$key])) {
                $content['home'][$key] = $home[$key];
            }
        }

        if (isset($home['trust']) && is_array($home['trust']) && count($home['trust']) === 4) {
            $content['home']['trust'] = array_values(array_map('strval', $home['trust']));
        }
    }

    $how = json_decode((string) setting('landing_how', ''), true);

    if (is_array($how)) {
        foreach (['eyebrow', 'heading', 'intro'] as $key) {
            if (isset($how[$key]) && is_string($how[$key])) {
                $content['how'][$key] = $how[$key];
            }
        }

        if (isset($how['steps']) && is_array($how['steps']) && count($how['steps']) === 3) {
            $steps = [];

            foreach (array_values($how['steps']) as $index => $step) {
                $steps[] = [
                    'title' => is_string($step['title'] ?? null) ? $step['title'] : $defaults['how']['steps'][$index]['title'],
                    'text'  => is_string($step['text'] ?? null) ? $step['text'] : $defaults['how']['steps'][$index]['text'],
                ];
            }

            $content['how']['steps'] = $steps;
        }
    }

    $printer = json_decode((string) setting('landing_printer', ''), true);

    if (is_array($printer)) {
        foreach (['eyebrow', 'heading', 'body'] as $key) {
            if (isset($printer[$key]) && is_string($printer[$key])) {
                $content['printer'][$key] = $printer[$key];
            }
        }

        if (isset($printer['points']) && is_array($printer['points']) && count($printer['points']) === 4) {
            $content['printer']['points'] = array_values(array_map('strval', $printer['points']));
        }
    }

    $types = json_decode((string) setting('landing_types', ''), true);

    if (is_array($types)) {
        foreach (['eyebrow', 'heading', 'intro'] as $key) {
            if (isset($types[$key]) && is_string($types[$key])) {
                $content['types'][$key] = $types[$key];
            }
        }

        if (isset($types['items']) && is_array($types['items']) && count($types['items']) === 8) {
            $content['types']['items'] = array_values(array_map('strval', $types['items']));
        }
    }

    $promise = json_decode((string) setting('landing_promise', ''), true);

    if (is_array($promise)) {
        foreach (['eyebrow', 'heading', 'body'] as $key) {
            if (isset($promise[$key]) && is_string($promise[$key])) {
                $content['promise'][$key] = $promise[$key];
            }
        }

        if (isset($promise['items']) && is_array($promise['items']) && count($promise['items']) === 3) {
            $items = [];

            foreach (array_values($promise['items']) as $index => $item) {
                $items[] = [
                    'title' => is_string($item['title'] ?? null) ? $item['title'] : $defaults['promise']['items'][$index]['title'],
                    'text'  => is_string($item['text'] ?? null) ? $item['text'] : $defaults['promise']['items'][$index]['text'],
                ];
            }

            $content['promise']['items'] = $items;
        }
    }

    $cta = json_decode((string) setting('landing_cta', ''), true);

    if (is_array($cta)) {
        foreach (['eyebrow', 'heading', 'body'] as $key) {
            if (isset($cta[$key]) && is_string($cta[$key])) {
                $content['cta'][$key] = $cta[$key];
            }
        }
    }

    $faq = json_decode((string) setting('landing_faq', ''), true);

    if (is_array($faq)) {
        foreach (['eyebrow', 'heading'] as $key) {
            if (isset($faq[$key]) && is_string($faq[$key])) {
                $content['faq'][$key] = $faq[$key];
            }
        }

        if (isset($faq['items']) && is_array($faq['items'])) {
            $items = [];

            foreach ($faq['items'] as $item) {
                $question = is_array($item) ? trim((string) ($item['q'] ?? '')) : '';
                $answer   = is_array($item) ? trim((string) ($item['a'] ?? '')) : '';

                if ($question !== '') {
                    $items[] = ['q' => $question, 'a' => $answer];
                }
            }

            $content['faq']['items'] = $items;
        }
    }

    return $content;
}

/**
 * One section of landing content.
 *
 * @return array<string,mixed>
 */
function landing_section(string $section): array
{
    return landing_content()[$section] ?? [];
}

/**
 * Read one section's fields out of a Site Settings form submission.
 *
 * @param array<string,mixed> $post
 * @return array<string,mixed>
 */
function landing_from_post(string $section, array $post): array
{
    switch ($section) {
        case 'home':
            return [
                'title'  => landing_text($post['title'] ?? '', 120),
                'accent' => landing_text($post['accent'] ?? '', 120),
                'lead'   => landing_text($post['lead'] ?? '', 700),
                'trust'  => array_map(
                    static fn (mixed $value): string => landing_text($value, 80),
                    array_values(array_slice((array) ($post['trust'] ?? []), 0, 4))
                ),
            ];

        case 'how':
            $steps = [];

            foreach (array_slice((array) ($post['steps'] ?? []), 0, 3) as $step) {
                $steps[] = [
                    'title' => landing_text(is_array($step) ? ($step['title'] ?? '') : '', 120),
                    'text'  => landing_text(is_array($step) ? ($step['text'] ?? '') : '', 600),
                ];
            }

            return [
                'eyebrow' => landing_text($post['eyebrow'] ?? '', 80),
                'heading' => landing_text($post['heading'] ?? '', 120),
                'intro'   => landing_text($post['intro'] ?? '', 500),
                'steps'   => $steps,
            ];

        case 'printer':
            return [
                'eyebrow' => landing_text($post['eyebrow'] ?? '', 80),
                'heading' => landing_text($post['heading'] ?? '', 120),
                'body'    => landing_text($post['body'] ?? '', 800),
                'points'  => array_map(
                    static fn (mixed $value): string => landing_text($value, 160),
                    array_values(array_slice((array) ($post['points'] ?? []), 0, 4))
                ),
            ];

        case 'types':
            return [
                'eyebrow' => landing_text($post['eyebrow'] ?? '', 80),
                'heading' => landing_text($post['heading'] ?? '', 120),
                'intro'   => landing_text($post['intro'] ?? '', 400),
                'items'   => array_map(
                    static fn (mixed $value): string => landing_text($value, 80),
                    array_values(array_slice((array) ($post['items'] ?? []), 0, 8))
                ),
            ];

        case 'promise':
            $items = [];

            foreach (array_slice((array) ($post['items'] ?? []), 0, 3) as $item) {
                $items[] = [
                    'title' => landing_text(is_array($item) ? ($item['title'] ?? '') : '', 120),
                    'text'  => landing_text(is_array($item) ? ($item['text'] ?? '') : '', 300),
                ];
            }

            return [
                'eyebrow' => landing_text($post['eyebrow'] ?? '', 80),
                'heading' => landing_text($post['heading'] ?? '', 120),
                'body'    => landing_text($post['body'] ?? '', 500),
                'items'   => $items,
            ];

        case 'cta':
            return [
                'eyebrow' => landing_text($post['eyebrow'] ?? '', 80),
                'heading' => landing_text($post['heading'] ?? '', 120),
                'body'    => landing_text($post['body'] ?? '', 400),
            ];

        case 'faq':
            $items = [];

            foreach (array_slice((array) ($post['faq'] ?? []), 0, 12) as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $question = landing_text($item['q'] ?? '', 200);

                if ($question !== '') {
                    $items[] = ['q' => $question, 'a' => landing_text($item['a'] ?? '', 1500)];
                }
            }

            return [
                'eyebrow' => landing_text($post['eyebrow'] ?? '', 80),
                'heading' => landing_text($post['heading'] ?? '', 120),
                'items'   => $items,
            ];
    }

    return [];
}

/**
 * Save one section of landing content.
 *
 * @param array<string,mixed> $value
 */
function landing_store(string $section, array $value): void
{
    set_setting(
        'landing_' . $section,
        (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}
