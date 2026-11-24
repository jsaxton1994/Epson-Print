<?php
/**
 * Sign in — shared by customers and administrators.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (current_user() !== null) {
    redirect(current_user()['role'] === 'admin' ? 'admin/index.php' : 'customer/index.php');
}

$email = '';

if (is_post()) {
    verify_csrf();

    $email    = mb_strtolower(input('email'));
    $password = (string) ($_POST['password'] ?? '');

    if (login_locked_out()) {
        flash('error', 'Too many attempts. Please wait a few minutes and try again.');
    } elseif ($email === '' || $password === '') {
        flash('error', 'Enter your email address and password.');
    } else {
        $account = attempt_login($email, $password);

        if ($account !== null) {
            login_user($account);
            flash('success', 'Welcome back, ' . $account['name'] . '.');
            redirect($account['role'] === 'admin' ? 'admin/index.php' : 'customer/index.php');
        }

        flash('error', 'That email and password do not match our records.');
    }
}

site_head('Sign in', ['active' => '']);
?>

<section class="auth-section">
    <div class="container auth-grid">
        <div class="auth-card reveal">
            <a class="brand" href="<?= e(url('public/index.php')) ?>">
                <span class="brand-mark"><?= icon('printer') ?></span>
                <span class="brand-name"><?= e(site_name()) ?><span class="brand-dot">.</span></span>
            </a>

            <h1>Welcome back</h1>
            <p class="muted">Sign in to upload photos and follow your print queue.</p>

            <form method="post" class="form-stack" data-login-form data-submit-once>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="email">Email address</label>
                    <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>"
                           placeholder="you@example.com" autocomplete="email" required autofocus>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input class="input" type="password" id="password" name="password"
                               placeholder="Your password" autocomplete="current-password" required>
                        <button class="input-action pw-toggle" type="button" data-toggle-password
                                aria-pressed="false" aria-label="Show password">
                            <?= icon('eye', 'icon-on') ?><?= icon('eye-off', 'icon-off') ?>
                        </button>
                    </div>
                </div>

                <button class="btn btn-primary btn-lg btn-block auth-submit" type="submit">Sign in</button>
            </form>

            <p class="auth-switch">New here? <a href="<?= e(url('public/register.php')) ?>">Create a free account</a></p>
        </div>

        <aside class="auth-side reveal reveal-delay-1">
            <span class="eyebrow light">Demo accounts</span>
            <h2>Try it in one click</h2>
            <p>The demo data is already installed. Pick a role and you are signed in — no typing needed.</p>

            <form class="demo-card" method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="customer@me.com">
                <input type="hidden" name="password" value="123">
                <div>
                    <span class="demo-role">Customer</span>
                    <strong>customer@me.com</strong>
                    <span class="mono">password 123</span>
                </div>
                <button class="btn btn-light btn-sm" type="submit"><?= icon('arrow-right', 'btn-icon') ?> Log in</button>
            </form>

            <form class="demo-card" method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="email" value="admin@me.com">
                <input type="hidden" name="password" value="123">
                <div>
                    <span class="demo-role">Administrator</span>
                    <strong>admin@me.com</strong>
                    <span class="mono">password 123</span>
                </div>
                <button class="btn btn-light btn-sm" type="submit"><?= icon('arrow-right', 'btn-icon') ?> Log in</button>
            </form>

            <ul class="auth-points">
                <li><?= icon('shield') ?><span>No card details, credits or page allowance</span></li>
                <li><?= icon('image') ?><span>Your photos stay private to you and the admins</span></li>
                <li><?= icon('layers') ?><span>Live order status from queue to printed</span></li>
            </ul>
        </aside>
    </div>
</section>

<?php site_footer(); ?>
