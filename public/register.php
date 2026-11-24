<?php
/**
 * Create a customer account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

if (current_user() !== null) {
    redirect(current_user()['role'] === 'admin' ? 'admin/index.php' : 'customer/index.php');
}

$errors = [];
$name   = '';
$email  = '';
$phone  = '';

if (is_post()) {
    verify_csrf();

    $name     = input('name');
    $email    = mb_strtolower(input('email'));
    $phone    = input('phone');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirm'] ?? '');

    if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $errors['name'] = 'Please enter your name (2–120 characters).';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif ((int) db_val('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) > 0) {
        $errors['email'] = 'An account with that email already exists. Try signing in.';
    }

    if (mb_strlen($phone) > 40) {
        $errors['phone'] = 'Please keep the phone number under 40 characters.';
    }

    if (strlen($password) < 6) {
        $errors['password'] = 'Use at least 6 characters for your password.';
    } elseif (strlen($password) > 100) {
        $errors['password'] = 'Please use a password under 100 characters.';
    } elseif ($password !== $confirm) {
        $errors['password_confirm'] = 'The two passwords do not match.';
    }

    if ($errors === []) {
        $userId = db_insert('users', [
            'name'          => $name,
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'phone'         => $phone !== '' ? $phone : null,
            'role'          => 'customer',
            'status'        => 'active',
            'created_at'    => now(),
        ]);

        $account = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($account !== null) {
            login_user($account);
        }

        flash('success', 'Your account is ready. Upload your first photo.');
        redirect('customer/new-order.php');
    }

    flash('error', 'Please check the highlighted fields and try again.');
}

site_head('Create account', ['active' => '']);
?>

<section class="auth-section">
    <div class="container auth-grid">
        <div class="auth-card reveal">
            <a class="brand" href="<?= e(url('public/index.php')) ?>">
                <span class="brand-mark"><?= icon('printer') ?></span>
                <span class="brand-name"><?= e(site_name()) ?><span class="brand-dot">.</span></span>
            </a>

            <h1>Create your free account</h1>
            <p class="muted">An email address, a password and you are printing.</p>

            <form method="post" class="form-stack" data-submit-once novalidate>
                <?= csrf_field() ?>

                <div class="field">
                    <label for="name">Full name</label>
                    <input class="input" type="text" id="name" name="name" value="<?= e($name) ?>"
                           placeholder="Alex Morgan" autocomplete="name" required autofocus>
                    <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>"
                           placeholder="you@example.com" autocomplete="email" required>
                    <?php if (isset($errors['email'])): ?><p class="field-error"><?= e($errors['email']) ?></p><?php endif; ?>
                </div>

                <div class="field">
                    <label for="phone">Phone <span class="optional">optional</span></label>
                    <input class="input" type="tel" id="phone" name="phone" value="<?= e($phone) ?>"
                           placeholder="07700 900123" autocomplete="tel">
                    <?php if (isset($errors['phone'])): ?><p class="field-error"><?= e($errors['phone']) ?></p><?php endif; ?>
                </div>

                <div class="form-grid">
                    <div class="field">
                        <label for="password">Password</label>
                        <div class="input-wrap">
                            <input class="input" type="password" id="password" name="password"
                                   placeholder="At least 6 characters" autocomplete="new-password" required>
                            <button class="input-action pw-toggle" type="button" data-toggle-password
                                    aria-pressed="false" aria-label="Show password">
                                <?= icon('eye', 'icon-on') ?><?= icon('eye-off', 'icon-off') ?>
                            </button>
                        </div>
                        <?php if (isset($errors['password'])): ?><p class="field-error"><?= e($errors['password']) ?></p><?php endif; ?>
                    </div>

                    <div class="field">
                        <label for="password_confirm">Confirm password</label>
                        <div class="input-wrap">
                            <input class="input" type="password" id="password_confirm" name="password_confirm"
                                   placeholder="Repeat it" autocomplete="new-password" required>
                            <button class="input-action pw-toggle" type="button" data-toggle-password
                                    aria-pressed="false" aria-label="Show password">
                                <?= icon('eye', 'icon-on') ?><?= icon('eye-off', 'icon-off') ?>
                            </button>
                        </div>
                        <?php if (isset($errors['password_confirm'])): ?><p class="field-error"><?= e($errors['password_confirm']) ?></p><?php endif; ?>
                    </div>
                </div>

                <button class="btn btn-primary btn-lg btn-block auth-submit" type="submit">Create free account</button>
            </form>

            <p class="auth-switch">Already have an account? <a href="<?= e(url('public/login.php')) ?>">Sign in</a></p>
        </div>

        <aside class="auth-side reveal reveal-delay-1">
            <span class="eyebrow light">What you get</span>
            <h2>One small account. Zero cost.</h2>
            <p>There is nothing to buy and nothing to renew. Your account only exists so we know whose photo is whose.</p>

            <ul class="auth-points" style="margin-top:6px">
                <li><?= icon('upload') ?><span>Unlimited free uploads, <?= e(MAX_UPLOAD_MB) ?> MB per photo</span></li>
                <li><?= icon('printer') ?><span>4 × 6 borderless prints, 5 × 7 and A4 too</span></li>
                <li><?= icon('layers') ?><span>A live queue you can check from any device</span></li>
                <li><?= icon('shield') ?><span>Private storage that is never served to the public</span></li>
                <li><?= icon('bolt') ?><span>Cancel a job yourself while it is still waiting</span></li>
            </ul>
        </aside>
    </div>
</section>

<?php site_footer(); ?>
