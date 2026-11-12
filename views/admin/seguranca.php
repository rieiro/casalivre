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
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <span>Monitored & Blocked IP Addresses</span>
                <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Table login_rate_limit</span>
            </div>

            <?php if (empty($ips)): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.9rem;">No IP addresses currently monitored or blocked.</p>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>IP Address</th>
                                <th>Failures</th>
                                <th>Locked Until</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ips as $item): ?>
                                <tr>
                                    <td>
                                        <strong class="admin-code"><?= e($item['ip'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <span style="font-weight: 600;"><?= e((string)($item['failures'] ?? 0)) ?></span>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--admin-text-muted);">
                                        <?= e($item['locked_until'] ? substr((string)$item['locked_until'], 0, 19) : 'Not locked') ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['is_locked'])): ?>
                                            <span class="admin-pill admin-pill-danger">LOCKED</span>
                                        <?php else: ?>
                                            <span class="admin-pill admin-pill-neutral">MONITORED</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" action="/admin/seguranca/desbloquear" style="display: inline;">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="ip" value="<?= e($item['ip'] ?? '') ?>">
                                            <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
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

    <div>
        <div class="admin-card">
            <div class="admin-card-title">Manual IP Block</div>
            <form method="post" action="/admin/seguranca/bloquear">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                        IP Address (IPv4 or IPv6)
                    </label>
                    <input type="text" name="ip" placeholder="e.g. 198.51.100.25" required style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem;">
                        Suspension Duration
                    </label>
                    <select name="minutes" style="width: 100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.9rem;">
                        <option value="15">15 minutes</option>
                        <option value="60" selected>1 hour</option>
                        <option value="1440">24 hours (1 day)</option>
                        <option value="10080">7 days (1 week)</option>
                        <option value="43200">30 days (1 month)</option>
                        <option value="525600">1 year (permanent)</option>
                    </select>
                </div>

                <button type="submit" class="admin-btn admin-btn-primary" style="width: 100%; justify-content: center; background: #dc2626;">
                    ⛔ Apply Immediate Lockout
                </button>
            </form>
        </div>

        <div class="admin-card">
            <div class="admin-card-title">Active Security Policies</div>
            <ul style="font-size: 0.825rem; color: var(--admin-text-muted); padding-left: 1.25rem; margin: 0; line-height: 1.6;">
                <li>Addresses exceeding failure thresholds receive automated lockout protection.</li>
                <li>Unblocking an IP resets its failure counter and clears the lock timestamp.</li>
                <li>Incoming requests from blocked IPs are short-circuited before querying users.</li>
            </ul>
        </div>
    </div>
</div>
