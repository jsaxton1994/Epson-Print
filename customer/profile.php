<?php
/**
 * Customer profile and password.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$user = require_login();
if ($user['role'] === 'admin') {
    redirect('admin/settings.php');
}

$errors = [];

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'profile') {
        $name  = input('name');
        $phone = input('phone');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Please enter your name (2–120 characters).';
        }
        if (mb_strlen($phone) > 40) {
            $errors['phone'] = 'Please keep the phone number under 40 characters.';
        }

        if ($errors === []) {
            db_update('users', [
                'name'  => $name,
                'phone' => $phone !== '' ? $phone : null,
            ], 'id = ?', [$user['id']]);

            current_user(true);
            flash('success', 'Your details have been updated.');
            redirect('customer/profile.php');
        }

        $user['name']  = $name;
        $user['phone'] = $phone;
    }

    if ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'That is not your current password.';
        } elseif (strlen($new) < 6) {
            $errors['new_password'] = 'Use at least 6 characters.';
        } elseif (strlen($new) > 100) {
            $errors['new_password'] = 'Please use a password under 100 characters.';
        } elseif ($new !== $confirm) {
            $errors['new_password_confirm'] = 'The new passwords do not match.';
        } else {
            db_update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
            current_user(true);
            flash('success', 'Password changed.');
            redirect('customer/profile.php');
        }
    }

    if ($errors !== []) {
        flash('error', 'Please check the highlighted fields.');
    }
}

$totalOrders = (int) db_val('SELECT COUNT(*) FROM orders WHERE user_id = ?', [$user['id']]);
$lastOrder   = db_one('SELECT created_at FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$user['id']]);

app_start('customer', 'profile', 'Profile', 'Your details, password and account facts.');
?>

<div class="profile-layout">
    <div class="stack">
        <section class="panel">
            <div class="profile-hero">
                <span class="avatar avatar-lg"><?= e(initials((string) $user['name'])) ?></span>
                <div>
                    <h3><?= e($user['name']) ?></h3>
                    <p><?= e($user['email']) ?> · Customer since <?= e(format_date($user['created_at'], 'M Y')) ?></p>
                </div>
            </div>

            <div class="panel-body">
                <form method="post" data-submit-once>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="profile">

                    <div class="field-row">
                        <div class="field">
                            <label for="name">Full name</label>
                            <input class="input" type="text" id="name" name="name" value="<?= e($user['name']) ?>" required>
                            <?php if (isset($errors['name'])): ?><p class="field-error"><?= e($errors['name']) ?></p><?php endif; ?>
                        </div>

                        <div class="field">
                            <label for="phone">Phone <span class="optional">optional</span></label>
                            <input class="input" type="tel" id="phone" name="phone" value="<?= e($user['phone']) ?>" placeholder="07700 900123">
                            <?php if (isset($errors['phone'])): ?><p class="field-error"><?= e($errors['phone']) ?></p><?php endif; ?>
                        </div>
                    </div>

                    <div class="field">
                        <label for="email-readonly">Email address</label>
                        <input class="input" type="email" id="email-readonly" value="<?= e($user['email']) ?>" readonly disabled>
                        <p class="help">Your email is your sign-in. Contact support if it needs changing.</p>
                    </div>

                    <button class="btn btn-primary" type="submit">Save details</button>
                </form>

                <div class="settings-section">
                    <h3>Change password</h3>
                    <p>Use at least 6 characters. You will stay signed in on this device.</p>

                    <form method="post" data-submit-once>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="password">

                        <div class="field">
                            <label for="current_password">Current password</label>
                            <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                            <?php if (isset($errors['current_password'])): ?><p class="field-error"><?= e($errors['current_password']) ?></p><?php endif; ?>
                        </div>

                        <div class="field-row">
                            <div class="field">
                                <label for="new_password">New password</label>
                                <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                                <?php if (isset($errors['new_password'])): ?><p class="field-error"><?= e($errors['new_password']) ?></p><?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="new_password_confirm">Confirm new password</label>
                                <input class="input" type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password" required>
                                <?php if (isset($errors['new_password_confirm'])): ?><p class="field-error"><?= e($errors['new_password_confirm']) ?></p><?php endif; ?>
                            </div>
                        </div>

                        <button class="btn btn-soft" type="submit">Update password</button>
                    </form>
                </div>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="panel">
            <div class="panel-head"><h3>Account facts</h3></div>
            <div class="panel-body">
                <div class="profile-facts">
                    <div class="profile-fact"><span>Role</span><strong>Customer</strong></div>
                    <div class="profile-fact"><span>Status</span><strong>Active</strong></div>
                    <div class="profile-fact"><span>Orders</span><strong><?= $totalOrders ?></strong></div>
                    <div class="profile-fact"><span>Last order</span><strong><?= e($lastOrder ? time_ago($lastOrder['created_at']) : 'None yet') ?></strong></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head"><h3>Need a hand?</h3></div>
            <div class="panel-body">
                <p class="muted" style="font-size:.87rem">Ask us to remove a photo, or tell us about a print problem.</p>
                <a class="btn btn-ghost btn-block btn-sm" href="mailto:<?= e(setting('support_email', 'hello@printfree.test')) ?>">
                    <?= icon('mail') ?> <?= e(setting('support_email', 'hello@printfree.test')) ?>
                </a>
            </div>
        </section>
    </div>
</div>

<?php app_end(); ?>
