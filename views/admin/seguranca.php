<?php
/**
 * @var list<array<string, mixed>> $ips
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string|null $ok
 * @var string|null $error
 */
?>

<div class="admin-grid-split">
    <!-- LEFT: MONITORED & BLOCKED IPS TABLE -->
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <span>🛡️ Monitored & Blocked IP Addresses</span>
                <span class="admin-card-tag">login_rate_limit table</span>
            </div>

            <?php if (empty($ips)): ?>
                <div class="admin-empty-state">
                    <div class="admin-empty-icon">🟢</div>
                    <div class="admin-empty-title">Clean Security Perimeter</div>
                    <div class="admin-empty-desc">No IP addresses currently monitored or blocked for abusive login attempts.</div>
                </div>
            <?php else: ?>
                <div class="admin-table-container">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>Failures</th>
                                <th>Locked Until</th>
                                <th>Security State</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ips as $item): ?>
                                <tr>
                                    <td>
                                        <strong class="admin-code"><?= e($item['ip'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <span class="admin-label"><?= e((string)($item['failures'] ?? 0)) ?></span>
                                    </td>
                                    <td>
                                        <span class="admin-hint"><?= e($item['locked_until'] ? substr((string)$item['locked_until'], 0, 19) : 'Not locked') ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['is_locked'])): ?>
                                            <span class="admin-pill admin-pill-danger">
                                                <span class="admin-dot admin-dot-danger"></span>
                                                <span>LOCKED</span>
                                            </span>
                                        <?php else: ?>
                                            <span class="admin-pill admin-pill-neutral">MONITORED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="/admin/seguranca/desbloquear">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="ip" value="<?= e($item['ip'] ?? '') ?>">
                                            <button type="submit" class="admin-btn admin-btn-secondary admin-btn-sm">
                                                Unblock
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: MANUAL IP BLOCK & SECURITY POLICIES -->
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <span>⛔ Manual IP Block</span>
            </div>

            <form method="post" action="/admin/seguranca/bloquear">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <div class="admin-form-group">
                    <label class="admin-label">
                        IP Address (IPv4 or IPv6)
                    </label>
                    <input type="text" name="ip" placeholder="e.g. 198.51.100.25" required class="admin-input">
                </div>

                <div class="admin-form-group">
                    <label class="admin-label">
                        Suspension Duration
                    </label>
                    <select name="minutes" class="admin-select">
                        <option value="15">15 minutes</option>
                        <option value="60" selected>1 hour</option>
                        <option value="1440">24 hours (1 day)</option>
                        <option value="10080">7 days (1 week)</option>
                        <option value="43200">30 days (1 month)</option>
                        <option value="525600">1 year (permanent)</option>
                    </select>
                </div>

                <button type="submit" class="admin-btn admin-btn-danger admin-btn-block">
                    <span>⛔</span>
                    <span>Apply Immediate Lockout</span>
                </button>
            </form>
        </div>

        <div class="admin-card">
            <div class="admin-card-title">
                <span>📜 Active Security Policies</span>
            </div>
            <ul class="admin-hint">
                <li>Addresses exceeding failure thresholds receive automated lockout protection.</li>
                <li>Unblocking an IP resets its failure counter and clears the lock timestamp immediately.</li>
                <li>Incoming requests from blocked IPs are short-circuited before querying user records.</li>
            </ul>
        </div>
    </div>
</div>
