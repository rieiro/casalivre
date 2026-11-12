<?php
/**
 * @var array<string, mixed> $settings
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string|null $ok
 * @var string|null $error
 */
?>

<div class="admin-card">
    <div class="admin-card-title">
        <span>Platform Technical Parameters</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Runtime parameter matrix applied with zero database read latency</span>
    </div>

    <form method="post" action="/admin/configuracoes">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            🛡️ Security & Authentication
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Max Failed Logins per IP
                </label>
                <input type="number" name="login_ip_max_failures" value="<?= e((string)($settings['login_ip_max_failures'] ?? 5)) ?>" min="1" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Blocks further attempts from the IP address after N consecutive failed attempts.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    IP Lockout Duration (minutes)
                </label>
                <input type="number" name="login_ip_lock_minutes" value="<?= e((string)($settings['login_ip_lock_minutes'] ?? 15)) ?>" min="1" max="1440" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Duration that an IP address remains temporarily suspended.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    User Session Lifetime (minutes)
                </label>
                <input type="number" name="session_minutes" value="<?= e((string)($settings['session_minutes'] ?? 60)) ?>" min="5" max="10080" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Maximum idle duration before an authenticated session expires.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Minimum Password Length
                </label>
                <input type="number" name="password_min_length" value="<?= e((string)($settings['password_min_length'] ?? 8)) ?>" min="6" max="64" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Minimum characters required for new account passwords.</span>
            </div>
        </div>

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            📅 Calendar Synchronization (iCal)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Sync Interval (minutes)
                </label>
                <input type="number" name="calendar_sync_interval_min" value="<?= e((string)($settings['calendar_sync_interval_min'] ?? 30)) ?>" min="5" max="1440" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Interval between automated recurring background calendar sync passes.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Consecutive Failure Tolerance
                </label>
                <input type="number" name="calendar_sync_fail_stop" value="<?= e((string)($settings['calendar_sync_fail_stop'] ?? 16)) ?>" min="1" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Permanently halts automatic sync after N consecutive connection errors.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    iCal Connection Timeout (seconds)
                </label>
                <input type="number" name="calendar_fetch_timeout_sec" value="<?= e((string)($settings['calendar_fetch_timeout_sec'] ?? 20)) ?>" min="3" max="60" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Max connection wait duration for external iCal feed URLs.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Global Calendar Horizon (months)
                </label>
                <input type="number" name="calendar_months" value="<?= e((string)($settings['calendar_months'] ?? 12)) ?>" min="1" max="36" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Maximum forward availability horizon generated for properties.</span>
            </div>
        </div>

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            🖼️ Media, WebP & Quotes
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem;">
            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    WebP Compression Quality (50 to 95)
                </label>
                <input type="number" name="photo_webp_quality" value="<?= e((string)($settings['photo_webp_quality'] ?? 80)) ?>" min="50" max="95" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Balance between visual quality and byte compression.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Max Photos per Property
                </label>
                <input type="number" name="max_photos_per_property" value="<?= e((string)($settings['max_photos_per_property'] ?? 40)) ?>" min="1" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Maximum images permitted in each property gallery.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Pending Quote Hold Duration (minutes)
                </label>
                <input type="number" name="quote_hold_minutes" value="<?= e((string)($settings['quote_hold_minutes'] ?? 30)) ?>" min="5" max="1440" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Temporary lock duration on calendar dates during guest checkout.</span>
            </div>

            <div>
                <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                    Search Results per Page
                </label>
                <input type="number" name="results_per_page" value="<?= e((string)($settings['results_per_page'] ?? 20)) ?>" min="5" max="100" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                <span style="font-size: 0.75rem; color: var(--admin-text-muted);">Pagination size for the public marketplace directory.</span>
            </div>
        </div>

        <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 1.5rem;">
            ⚙️ Operations & Maintenance
        </h3>
        <div style="margin-bottom: 1.5rem;">
            <label style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; font-weight: 500; cursor: pointer;">
                <input type="checkbox" name="maintenance_mode" value="1" <?= !empty($settings['maintenance_mode']) ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                <span>Enable Maintenance Mode (suspends public traffic with friendly service notification)</span>
            </label>
        </div>

        <div style="border-top: 1px solid var(--admin-border); padding-top: 1.25rem; display: flex; justify-content: flex-end;">
            <button type="submit" class="admin-btn admin-btn-primary" style="padding: 0.65rem 1.5rem;">
                Save All Settings
            </button>
        </div>
    </form>
</div>
