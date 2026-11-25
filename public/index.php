<?php
/**
 * Public landing page.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user     = current_user();
$startUrl = $user === null
    ? url('public/register.php')
    : ($user['role'] === 'admin' ? url('admin/index.php') : url('customer/index.php'));

$printerStatus = epson_status();
$printerOnline = $printerStatus['key'] === 'online';

site_head('', ['active' => 'home']);
?>

<section class="hero">
    <div class="hero-bg" aria-hidden="true">
        <span class="blob blob-1"></span>
        <span class="blob blob-2"></span>
        <span class="blob blob-3"></span>
    </div>

    <div class="container hero-grid">
        <div class="hero-copy">
            <span class="hero-badge<?= $printerOnline ? '' : ' is-off' ?>"><span class="status-dot"></span> <?= $printerOnline ? 'Online now · 4 × 6 photo prints' : '4 × 6 photo prints · ' . ($printerStatus['key'] === 'not_linked' ? 'link pending' : 'setup needed') ?></span>
            <h1>Your photos.<br><span class="grad-text">Printed free.</span></h1>
            <p class="hero-lead">
                Select up to <?= (int) MAX_PHOTOS_PER_ORDER ?> photos — they upload <?= (int) MAX_PHOTOS_PER_BATCH ?> at a time — choose 4 × 6 and borderless, and they
                <?= $printerOnline ? 'go straight to' : 'queue for' ?> our Epson ET-2926 on real photo paper. No credits, no subscriptions, no card details.
            </p>
            <div class="hero-actions">
                <a class="btn btn-primary btn-lg" href="<?= e($startUrl) ?>"><?= icon('image') ?> Print your first photo</a>
                <a class="btn btn-ghost btn-lg" href="#how-it-works">How it works</a>
            </div>
            <div class="hero-stats">
                <div class="hero-stat"><strong>4 × 6</strong><span>Classic photo size</span></div>
                <div class="hero-stat"><strong>100%</strong><span>Free, always</span></div>
                <div class="hero-stat"><strong><?= (int) MAX_PHOTOS_PER_ORDER ?></strong><span>Photos per order</span></div>
            </div>
        </div>

        <div class="hero-visual">
            <div class="print-demo">
                <div class="demo-glow" aria-hidden="true"></div>

                <figure class="print-sheet">
                    <img class="sheet-photo" src="<?= e(asset('img/demo-print.jpg')) ?>"
                         alt="A sample photo print of a lake at sunrise" width="1248" height="832" fetchpriority="high">
                    <figcaption class="sheet-caption">
                        <span class="sheet-line w-70"></span>
                        <span class="sheet-line w-40"></span>
                    </figcaption>
                    <span class="sheet-badge">4 × 6</span>
                </figure>

                <div class="printer<?= $printerOnline ? '' : ' is-off' ?>" aria-hidden="true">
                    <div class="printer-slot"></div>
                    <div class="printer-face">
                        <span class="printer-model">Epson <strong>ET-2926</strong></span>
                        <span class="printer-led"></span>
                    </div>
                    <div class="printer-lines">
                        <span></span><span></span><span></span>
                    </div>
                    <div class="printer-tray"></div>
                </div>

                <div class="float-chip chip-a<?= $printerOnline ? '' : ' is-off' ?>"><?= icon($printerOnline ? 'wifi' : 'printer') ?> <?= $printerOnline ? 'Connected' : 'Not connected' ?></div>
                <div class="float-chip chip-b"><?= icon('image') ?> Photos print here</div>
                <div class="float-chip chip-c"><?= icon('image') ?> 4 × 6 · Borderless</div>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="trust-strip reveal">
            <div class="trust-item"><?= icon('shield') ?> No payments, ever</div>
            <div class="trust-item"><?= icon('printer') ?> Real photo paper</div>
            <div class="trust-item"><?= icon('layers') ?> Track every print</div>
            <div class="trust-item"><?= icon('image') ?> Private photo storage</div>
        </div>
    </div>
</section>

<section class="section" id="how-it-works">
    <div class="container">
        <div class="section-head reveal">
            <span class="eyebrow">Three easy steps</span>
            <h2>From camera roll to photo print</h2>
            <p>No drivers to install and no app to download. Everything happens in your browser, on any device.</p>
        </div>

        <div class="steps">
            <article class="step-card reveal">
                <span class="step-num">01</span>
                <span class="step-icon"><?= icon('upload') ?></span>
                <h3>Upload your photos</h3>
                <p>Select up to <?= (int) MAX_PHOTOS_PER_ORDER ?> photos at once — JPG, PNG, HEIC, WebP, GIF, BMP, TIFF and more. They upload <?= (int) MAX_PHOTOS_PER_BATCH ?> at a time, so a big album never needs one huge request. Up to <?= e(MAX_UPLOAD_MB) ?> MB each.</p>
            </article>

            <article class="step-card reveal reveal-delay-1">
                <span class="step-num">02</span>
                <span class="step-icon"><?= icon('gear') ?></span>
                <h3>Pick 4 × 6 &amp; finish</h3>
                <p>Choose 4 × 6, 5 × 7 or A4, borderless or a white frame, colour and quality. Every photo prints once.</p>
            </article>

            <article class="step-card reveal reveal-delay-2">
                <span class="step-num">03</span>
                <span class="step-icon"><?= icon('check') ?></span>
                <h3>Watch it print</h3>
                <p>Your dashboard shows the queue and the live status. We send the photo to the ET-2926 and mark it printed.</p>
            </article>
        </div>
    </div>
</section>

<section class="section section-soft" id="printer">
    <div class="container split">
        <div class="split-copy reveal">
            <span class="eyebrow">The hardware</span>
            <h2>Real ink on real photo paper</h2>
            <p>
                Every photo travels in Epson Connect's photo mode to an Epson ET-2926 EcoTank sitting on a desk.
                That means a proper borderless 4 × 6 print from the rear feeder — and refillable ink tanks instead
                of cartridges, so free photo printing can actually keep going.
            </p>
            <ul class="check-list">
                <li><?= icon('check') ?><span>4 × 6 in borderless prints on Epson photo paper</span></li>
                <li><?= icon('check') ?><span>5 × 7 in and A4 when you want the photo bigger</span></li>
                <li><?= icon('check') ?><span>High-quality photo mode through the Epson Connect API v2</span></li>
                <li><?= icon('check') ?><span>4-colour EcoTank ink with refillable bottles</span></li>
            </ul>
            <a class="btn btn-ghost" href="<?= e($startUrl) ?>"><?= icon('image') ?> Send it a photo</a>
        </div>

        <div class="printer-card reveal reveal-delay-1">
            <div class="printer-card-head">
                <span class="printer-badge<?= $printerOnline ? '' : ' is-off' ?>"><?= icon($printerOnline ? 'check' : 'printer') ?> <?= e($printerStatus['label']) ?></span>
                <span class="mono">Epson Connect API v2</span>
            </div>
            <h3>Epson ET-2926</h3>
            <p class="printer-sub">EcoTank all-in-one · photo mode</p>

            <div class="spec-grid">
                <div><span>Functions</span><strong>Print · Scan · Copy</strong></div>
                <div><span>Photo sizes</span><strong>4 × 6 · 5 × 7 · A4</strong></div>
                <div><span>Connection</span><strong>Wi-Fi + Epson Connect</strong></div>
                <div><span>Ink</span><strong>4-colour EcoTank</strong></div>
            </div>

            <div class="ink-row">
                <span class="ink-chip"><span class="ink-dot"></span> Black</span>
                <span class="ink-chip"><span class="ink-dot cyan"></span> Cyan</span>
                <span class="ink-chip"><span class="ink-dot magenta"></span> Magenta</span>
                <span class="ink-chip"><span class="ink-dot yellow"></span> Yellow</span>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head reveal">
            <span class="eyebrow">Made for the photos on your phone</span>
            <h2>If you can take it, print it</h2>
            <p>A free photo lab for the pictures that never make it off your camera roll.</p>
        </div>

        <div class="print-types reveal">
            <span class="print-type"><?= icon('image') ?> 4 × 6 snapshots</span>
            <span class="print-type"><?= icon('phone') ?> Phone camera roll</span>
            <span class="print-type"><?= icon('sparkles') ?> Holiday photos</span>
            <span class="print-type"><?= icon('leaf') ?> Pet portraits</span>
            <span class="print-type"><?= icon('users') ?> Family photos</span>
            <span class="print-type"><?= icon('mail') ?> Thank-you prints</span>
            <span class="print-type"><?= icon('layers') ?> Mini wall prints</span>
            <span class="print-type"><?= icon('home') ?> Fridge favourites</span>
        </div>
    </div>
</section>

<section class="section" id="promise" style="padding-top:0">
    <div class="container">
        <div class="promise-band reveal">
            <div class="promise-copy">
                <span class="eyebrow light">The promise</span>
                <h2>Free photos, actually</h2>
                <p>
                    No card halfway through, no print allowance, no printer hidden behind a paywall.
                    Upload, choose 4 × 6, print — and that is the whole product.
                </p>
            </div>
            <div class="promise-list">
                <div class="promise-item">
                    <?= icon('shield') ?>
                    <div><strong>No payments, no credits</strong><span>4 × 6, borderless, high quality and reprints are all included.</span></div>
                </div>
                <div class="promise-item">
                    <?= icon('bolt') ?>
                    <div><strong>No account fees</strong><span>Signing up takes an email address and a password. Nothing else.</span></div>
                </div>
                <div class="promise-item">
                    <?= icon('leaf') ?>
                    <div><strong>Kinder on ink</strong><span>EcoTank refillable ink keeps running costs low, so free stays free.</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="section section-soft" id="faq">
    <div class="container">
        <div class="section-head reveal">
            <span class="eyebrow">Questions</span>
            <h2>The bits people ask about</h2>
        </div>

        <div class="faq">
            <details class="faq-item reveal">
                <summary>Is it really free, or is there a catch?<span class="faq-plus"></span></summary>
                <div class="faq-body"><p>It is really free. There is no payment form anywhere on the site, no credits to top up and no print allowance. Photos print on the house, 4 × 6 included.</p></div>
            </details>

            <details class="faq-item reveal">
                <summary>What photos can I print?<span class="faq-plus"></span></summary>
                <div class="faq-body"><p>Up to <?= (int) MAX_PHOTOS_PER_ORDER ?> photos per order — JPG, PNG, HEIC, WebP, GIF, BMP, TIFF, AVIF and similar. You can select them all at once; they upload automatically in batches of up to <?= (int) MAX_PHOTOS_PER_BATCH ?>, <?= e(MAX_UPLOAD_MB) ?> MB each. Choose 4 × 6, 5 × 7 or A4, borderless or with a white border, colour or black and white, and standard or high quality. Every photo prints once — ask the print desk if you ever need a reprint.</p></div>
            </details>

            <details class="faq-item reveal">
                <summary>Will 4 × 6 crop my photo?<span class="faq-plus"></span></summary>
                <div class="faq-body"><p>4 × 6 is a 3:2 shape, so a photo taken in that ratio fits perfectly. Taller or wider photos are fitted to the paper and the edges may be trimmed. Add a note if you want a particular part kept in frame.</p></div>
            </details>

            <details class="faq-item reveal">
                <summary>How long does a print take?<span class="faq-plus"></span></summary>
                <div class="faq-body"><p>Photos are printed in the order they arrive, usually the same day. Your dashboard shows whether a print is in the queue, printing or finished, so you never have to guess.</p></div>
            </details>

            <details class="faq-item reveal">
                <summary>Is my photo private?<span class="faq-plus"></span></summary>
                <div class="faq-body"><p>Your photos are stored outside the public website folder and only administrators can open them. Other customers cannot see your photos or your orders. Ask us and we will delete a photo and its order for you.</p></div>
            </details>

            <details class="faq-item reveal">
                <summary>Can I print from my phone?<span class="faq-plus"></span></summary>
                <div class="faq-body"><p>Yes. The whole site works in a mobile browser — choose a photo from your camera roll, set 4 × 6 and send it. No Epson app or Epson account is needed; the print desk handles the printer for you.</p></div>
            </details>

            <details class="faq-item reveal">
                <summary>What paper do you print on?<span class="faq-plus"></span></summary>
                <div class="faq-body"><p>Epson photo paper in the rear feeder, which gives the best colour and a borderless 4 × 6 finish. If your photo needs something specific — matte, a white border, a bigger size — add it to the order notes.</p></div>
            </details>
        </div>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="container">
        <div class="cta-band reveal">
            <span class="eyebrow">Ready when you are</span>
            <h2>Your favourite photo is one upload away</h2>
            <p>Create a free account, pick a photo and follow it all the way to photo paper.</p>
            <div class="cta-actions">
                <a class="btn btn-primary btn-lg" href="<?= e($startUrl) ?>"><?= icon('image') ?> Print a photo free</a>
                <a class="btn btn-ghost btn-lg" href="<?= e(url('public/login.php')) ?>">Sign in</a>
            </div>
            <span class="cta-note">No card details · up to <?= (int) MAX_PHOTOS_PER_ORDER ?> photos per order · uploaded <?= (int) MAX_PHOTOS_PER_BATCH ?> at a time · <?= e(MAX_UPLOAD_MB) ?> MB each</span>
        </div>
    </div>
</section>

<?php site_footer(); ?>
