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
        <div style="display: flex; align-items: center; gap: 0.6rem;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #eff6ff; color: #2563eb; font-size: 0.95rem;">⚙️</span>
            <span>Platform Technical Parameters</span>
        </div>
        <span style="font-size: 0.8rem; font-weight: 500; color: var(--admin-text-muted); background: #f8fafc; padding: 0.25rem 0.65rem; border-radius: 9999px; border: 1px solid var(--admin-border);">
            Runtime matrix applied with zero database read latency
        </span>
    </div>

    <form method="post" action="/admin/configuracoes">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <!-- SECURITY & AUTHENTICATION -->
        <div style="margin-bottom: 2rem;">
            <h3 style="font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 0; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>🛡️</span>
                <span>Security & Authentication</span>
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Max Failed Logins per IP
                    </label>
                    <input type="number" name="login_ip_max_failures" value="<?= e((string)($settings['login_ip_max_failures'] ?? 5)) ?>" min="1" max="100" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Blocks further attempts from an IP address after N consecutive failed attempts.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        IP Lockout Duration (minutes)
                    </label>
                    <input type="number" name="login_ip_lock_minutes" value="<?= e((string)($settings['login_ip_lock_minutes'] ?? 15)) ?>" min="1" max="1440" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Duration that an IP address remains temporarily suspended.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        User Session Lifetime (minutes)
                    </label>
                    <input type="number" name="session_minutes" value="<?= e((string)($settings['session_minutes'] ?? 60)) ?>" min="5" max="10080" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Maximum idle duration before an authenticated session expires.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Minimum Password Length
                    </label>
                    <input type="number" name="password_min_length" value="<?= e((string)($settings['password_min_length'] ?? 8)) ?>" min="6" max="64" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Minimum characters required for new account passwords.</span>
                </div>
            </div>
        </div>

        <!-- CALENDAR SYNCHRONIZATION -->
        <div style="margin-bottom: 2rem;">
            <h3 style="font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 0; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>📅</span>
                <span>Calendar Synchronization (iCal)</span>
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Sync Interval (minutes)
                    </label>
                    <input type="number" name="calendar_sync_interval_min" value="<?= e((string)($settings['calendar_sync_interval_min'] ?? 30)) ?>" min="5" max="1440" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Interval between automated recurring background calendar sync passes.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Consecutive Failure Tolerance
                    </label>
                    <input type="number" name="calendar_sync_fail_stop" value="<?= e((string)($settings['calendar_sync_fail_stop'] ?? 16)) ?>" min="1" max="100" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Permanently halts automatic sync after N consecutive connection errors.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        iCal Connection Timeout (seconds)
                    </label>
                    <input type="number" name="calendar_fetch_timeout_sec" value="<?= e((string)($settings['calendar_fetch_timeout_sec'] ?? 20)) ?>" min="3" max="60" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Max connection wait duration for external iCal feed URLs.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Global Calendar Horizon (months)
                    </label>
                    <input type="number" name="calendar_months" value="<?= e((string)($settings['calendar_months'] ?? 12)) ?>" min="1" max="36" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Maximum forward availability horizon generated for properties.</span>
                </div>
            </div>
        </div>

        <!-- MEDIA, WEBP & QUOTES -->
        <div style="margin-bottom: 2rem;">
            <h3 style="font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 0; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>🖼️</span>
                <span>Media, WebP & Quotes</span>
            </h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        WebP Compression Quality (50 to 95)
                    </label>
                    <input type="number" name="photo_webp_quality" value="<?= e((string)($settings['photo_webp_quality'] ?? 80)) ?>" min="50" max="95" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Balance between visual quality and byte compression.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Max Photos per Property
                    </label>
                    <input type="number" name="max_photos_per_property" value="<?= e((string)($settings['max_photos_per_property'] ?? 40)) ?>" min="1" max="100" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Maximum images permitted in each property gallery.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Pending Quote Hold Duration (minutes)
                    </label>
                    <input type="number" name="quote_hold_minutes" value="<?= e((string)($settings['quote_hold_minutes'] ?? 30)) ?>" min="5" max="1440" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Temporary lock duration on calendar dates during guest checkout.</span>
                </div>

                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        Search Results per Page
                    </label>
                    <input type="number" name="results_per_page" value="<?= e((string)($settings['results_per_page'] ?? 20)) ?>" min="5" max="100" class="admin-input">
                    <span style="display: block; font-size: 0.75rem; color: var(--admin-text-muted); margin-top: 0.35rem;">Pagination size for the public marketplace directory.</span>
                </div>
            </div>
        </div>

        <!-- OPERATIONS & MAINTENANCE -->
        <div style="margin-bottom: 2rem;">
            <h3 style="font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); border-bottom: 1px solid var(--admin-border); padding-bottom: 0.5rem; margin-top: 0; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>⚙️</span>
                <span>Operations & Maintenance</span>
            </h3>
            <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 1.25rem;">
                <label style="display: flex; align-items: center; gap: 0.75rem; font-size: 0.9rem; font-weight: 600; cursor: pointer; color: var(--admin-text-main);">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= !empty($settings['maintenance_mode']) ? 'checked' : '' ?> style="width: 20px; height: 20px; accent-color: #2563eb; cursor: pointer;">
                    <span>Enable Maintenance Mode</span>
                </label>
                <p style="margin: 0.4rem 0 0 2rem; font-size: 0.8rem; color: var(--admin-text-muted);">
                    When active, public traffic is suspended with a friendly service notification. Administrators remain authenticated.
                </p>
            </div>
        </div>

        <div style="border-top: 1px solid var(--admin-border); padding-top: 1.5rem; display: flex; justify-content: flex-end; gap: 1rem;">
            <button type="submit" class="admin-btn admin-btn-primary" style="padding: 0.7rem 2rem; font-size: 0.95rem;">
                <span>💾</span>
                <span>Save All Settings</span>
            </button>
        </div>
    </form>
</div>
