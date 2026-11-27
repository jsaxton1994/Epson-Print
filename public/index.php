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
$home          = landing_section('home');
$how           = landing_section('how');
$printerCopy   = landing_section('printer');
$types         = landing_section('types');
$promise       = landing_section('promise');
$faq           = landing_section('faq');
$cta           = landing_section('cta');

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
            <h1><?= e($home['title']) ?><br><span class="grad-text"><?= e($home['accent']) ?></span></h1>
            <p class="hero-lead"><?= nl2br(e($home['lead'])) ?></p>
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
            <?php $trustIcons = ['shield', 'printer', 'layers', 'image']; ?>
            <?php foreach (array_values($home['trust']) as $index => $trustItem): ?>
                <div class="trust-item"><?= icon($trustIcons[$index] ?? 'check') ?> <?= e($trustItem) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="how-it-works">
    <div class="container">
        <div class="section-head reveal">
            <span class="eyebrow"><?= e($how['eyebrow']) ?></span>
            <h2><?= e($how['heading']) ?></h2>
            <p><?= nl2br(e($how['intro'])) ?></p>
        </div>

        <div class="steps">
            <?php $stepIcons = ['upload', 'gear', 'check']; ?>
            <?php $stepDelays = ['', ' reveal-delay-1', ' reveal-delay-2']; ?>
            <?php foreach ($how['steps'] as $index => $step): ?>
                <article class="step-card reveal<?= $stepDelays[$index] ?? '' ?>">
                    <span class="step-num"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    <span class="step-icon"><?= icon($stepIcons[$index] ?? 'check') ?></span>
                    <h3><?= e($step['title']) ?></h3>
                    <p><?= nl2br(e($step['text'])) ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section section-soft" id="printer">
    <div class="container split">
        <div class="split-copy reveal">
            <span class="eyebrow"><?= e($printerCopy['eyebrow']) ?></span>
            <h2><?= e($printerCopy['heading']) ?></h2>
            <p><?= nl2br(e($printerCopy['body'])) ?></p>
            <ul class="check-list">
                <?php foreach ($printerCopy['points'] as $point): ?>
                    <li><?= icon('check') ?><span><?= e($point) ?></span></li>
                <?php endforeach; ?>
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
            <span class="eyebrow"><?= e($types['eyebrow']) ?></span>
            <h2><?= e($types['heading']) ?></h2>
            <p><?= nl2br(e($types['intro'])) ?></p>
        </div>

        <div class="print-types reveal">
            <?php $typeIcons = ['image', 'phone', 'sparkles', 'leaf', 'users', 'mail', 'layers', 'home']; ?>
            <?php foreach (array_values($types['items']) as $index => $item): ?>
                <span class="print-type"><?= icon($typeIcons[$index] ?? 'image') ?> <?= e($item) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" id="promise" style="padding-top:0">
    <div class="container">
        <div class="promise-band reveal">
            <div class="promise-copy">
                <span class="eyebrow light"><?= e($promise['eyebrow']) ?></span>
                <h2><?= e($promise['heading']) ?></h2>
                <p><?= nl2br(e($promise['body'])) ?></p>
            </div>
            <div class="promise-list">
                <?php $promiseIcons = ['shield', 'bolt', 'leaf']; ?>
                <?php foreach (array_values($promise['items']) as $index => $item): ?>
                    <div class="promise-item">
                        <?= icon($promiseIcons[$index] ?? 'check') ?>
                        <div><strong><?= e($item['title']) ?></strong><span><?= e($item['text']) ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<section class="section section-soft" id="faq">
    <div class="container">
        <div class="section-head reveal">
            <span class="eyebrow"><?= e($faq['eyebrow']) ?></span>
            <h2><?= e($faq['heading']) ?></h2>
        </div>

        <div class="faq">
            <?php foreach ($faq['items'] as $item): ?>
                <details class="faq-item reveal">
                    <summary><?= e($item['q']) ?><span class="faq-plus"></span></summary>
                    <div class="faq-body"><p><?= nl2br(e($item['a'])) ?></p></div>
                </details>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="section" style="padding-top:0">
    <div class="container">
        <div class="cta-band reveal">
            <span class="eyebrow"><?= e($cta['eyebrow']) ?></span>
            <h2><?= e($cta['heading']) ?></h2>
            <p><?= nl2br(e($cta['body'])) ?></p>
            <div class="cta-actions">
                <a class="btn btn-primary btn-lg" href="<?= e($startUrl) ?>"><?= icon('image') ?> Print a photo free</a>
                <a class="btn btn-ghost btn-lg" href="<?= e(url('public/login.php')) ?>">Sign in</a>
            </div>
            <span class="cta-note">No card details · up to <?= (int) MAX_PHOTOS_PER_ORDER ?> photos per order · uploaded <?= (int) MAX_PHOTOS_PER_BATCH ?> at a time · <?= e(MAX_UPLOAD_MB) ?> MB each</span>
        </div>
    </div>
</section>

<?php site_footer(); ?>
