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
        <span>⚙️ Platform Technical Parameters</span>
        <span class="admin-card-tag">Runtime matrix applied with zero database read latency</span>
    </div>

    <form method="post" action="/admin/configuracoes">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <!-- SECURITY & AUTHENTICATION -->
        <div class="admin-form-section">
            <h3 class="admin-form-section-title">
                <span>🛡️</span>
                <span>Security & Authentication</span>
            </h3>
            <div class="admin-form-grid">
                <div class="admin-form-box">
                    <label class="admin-label">
                        Max Failed Logins per IP
                    </label>
                    <input type="number" name="login_ip_max_failures" value="<?= e((string)($settings['login_ip_max_failures'] ?? 5)) ?>" min="1" max="100" class="admin-input">
                    <span class="admin-hint">Blocks further attempts from an IP address after N consecutive failed attempts.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        IP Lockout Duration (minutes)
                    </label>
                    <input type="number" name="login_ip_lock_minutes" value="<?= e((string)($settings['login_ip_lock_minutes'] ?? 15)) ?>" min="1" max="1440" class="admin-input">
                    <span class="admin-hint">Duration that an IP address remains temporarily suspended.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        User Session Lifetime (minutes)
                    </label>
                    <input type="number" name="session_minutes" value="<?= e((string)($settings['session_minutes'] ?? 60)) ?>" min="5" max="10080" class="admin-input">
                    <span class="admin-hint">Maximum idle duration before an authenticated session expires.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        Minimum Password Length
                    </label>
                    <input type="number" name="password_min_length" value="<?= e((string)($settings['password_min_length'] ?? 8)) ?>" min="6" max="64" class="admin-input">
                    <span class="admin-hint">Minimum characters required for new account passwords.</span>
                </div>
            </div>
        </div>

        <!-- CALENDAR SYNCHRONIZATION -->
        <div class="admin-form-section">
            <h3 class="admin-form-section-title">
                <span>📅</span>
                <span>Calendar Synchronization (iCal)</span>
            </h3>
            <div class="admin-form-grid">
                <div class="admin-form-box">
                    <label class="admin-label">
                        Sync Interval (minutes)
                    </label>
                    <input type="number" name="calendar_sync_interval_min" value="<?= e((string)($settings['calendar_sync_interval_min'] ?? 30)) ?>" min="5" max="1440" class="admin-input">
                    <span class="admin-hint">Interval between automated recurring background calendar sync passes.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        Consecutive Failure Tolerance
                    </label>
                    <input type="number" name="calendar_sync_fail_stop" value="<?= e((string)($settings['calendar_sync_fail_stop'] ?? 16)) ?>" min="1" max="100" class="admin-input">
                    <span class="admin-hint">Permanently halts automatic sync after N consecutive connection errors.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        iCal Connection Timeout (seconds)
                    </label>
                    <input type="number" name="calendar_fetch_timeout_sec" value="<?= e((string)($settings['calendar_fetch_timeout_sec'] ?? 20)) ?>" min="3" max="60" class="admin-input">
                    <span class="admin-hint">Max connection wait duration for external iCal feed URLs.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        Global Calendar Horizon (months)
                    </label>
                    <input type="number" name="calendar_months" value="<?= e((string)($settings['calendar_months'] ?? 12)) ?>" min="1" max="36" class="admin-input">
                    <span class="admin-hint">Maximum forward availability horizon generated for properties.</span>
                </div>
            </div>
        </div>

        <!-- MEDIA, WEBP & QUOTES -->
        <div class="admin-form-section">
            <h3 class="admin-form-section-title">
                <span>🖼️</span>
                <span>Media, WebP & Quotes</span>
            </h3>
            <div class="admin-form-grid">
                <div class="admin-form-box">
                    <label class="admin-label">
                        WebP Compression Quality (50 to 95)
                    </label>
                    <input type="number" name="photo_webp_quality" value="<?= e((string)($settings['photo_webp_quality'] ?? 80)) ?>" min="50" max="95" class="admin-input">
                    <span class="admin-hint">Balance between visual quality and byte compression.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        Max Photos per Property
                    </label>
                    <input type="number" name="max_photos_per_property" value="<?= e((string)($settings['max_photos_per_property'] ?? 40)) ?>" min="1" max="100" class="admin-input">
                    <span class="admin-hint">Maximum images permitted in each property gallery.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        Pending Quote Hold Duration (minutes)
                    </label>
                    <input type="number" name="quote_hold_minutes" value="<?= e((string)($settings['quote_hold_minutes'] ?? 30)) ?>" min="5" max="1440" class="admin-input">
                    <span class="admin-hint">Temporary lock duration on calendar dates during guest checkout.</span>
                </div>

                <div class="admin-form-box">
                    <label class="admin-label">
                        Search Results per Page
                    </label>
                    <input type="number" name="results_per_page" value="<?= e((string)($settings['results_per_page'] ?? 20)) ?>" min="5" max="100" class="admin-input">
                    <span class="admin-hint">Pagination size for the public marketplace directory.</span>
                </div>
            </div>
        </div>

        <!-- OPERATIONS & MAINTENANCE -->
        <div class="admin-form-section">
            <h3 class="admin-form-section-title">
                <span>⚙️</span>
                <span>Operations & Maintenance</span>
            </h3>
            <div class="admin-checkbox-card">
                <label class="admin-checkbox-label">
                    <input type="checkbox" name="maintenance_mode" value="1" <?= !empty($settings['maintenance_mode']) ? 'checked' : '' ?> class="admin-checkbox-input">
                    <span>Enable Maintenance Mode</span>
                </label>
                <span class="admin-hint">
                    When active, public traffic is suspended with a friendly service notification. Administrators remain authenticated.
                </span>
            </div>
        </div>

        <div class="admin-form-actions">
            <button type="submit" class="admin-btn admin-btn-primary">
                <span>💾</span>
                <span>Save All Settings</span>
            </button>
        </div>
    </form>
</div>
