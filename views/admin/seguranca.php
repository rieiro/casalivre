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
                <div style="display: flex; align-items: center; gap: 0.6rem;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #fee2e2; color: #dc2626; font-size: 0.95rem;">🛡️</span>
                    <span>Monitored & Blocked IP Addresses</span>
                </div>
                <span style="font-size: 0.8rem; font-weight: 500; color: var(--admin-text-muted); background: #f8fafc; padding: 0.25rem 0.65rem; border-radius: 9999px; border: 1px solid var(--admin-border);">
                    login_rate_limit table
                </span>
            </div>

            <?php if (empty($ips)): ?>
                <div style="text-align: center; padding: 2.5rem 1rem; color: var(--admin-text-muted);">
                    <div style="font-size: 2rem; margin-bottom: 0.5rem;">🟢</div>
                    <div style="font-weight: 600; color: var(--admin-text-main); margin-bottom: 0.25rem;">Clean Security Perimeter</div>
                    <div style="font-size: 0.85rem;">No IP addresses currently monitored or blocked for abusive login attempts.</div>
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
                                        <strong class="admin-code" style="font-size: 0.85rem;"><?= e($item['ip'] ?? '') ?></strong>
                                    </td>
                                    <td>
                                        <span style="font-weight: 700; color: <?= ($item['failures'] ?? 0) >= 3 ? '#dc2626' : '#475569' ?>;">
                                            <?= e((string)($item['failures'] ?? 0)) ?>
                                        </span>
                                    </td>
                                    <td style="font-size: 0.8rem; color: var(--admin-text-muted); font-family: ui-monospace, monospace;">
                                        <?= e($item['locked_until'] ? substr((string)$item['locked_until'], 0, 19) : 'Not locked') ?>
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
                                        <form method="post" action="/admin/seguranca/desbloquear" style="display: inline; margin: 0;">
                                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="ip" value="<?= e($item['ip'] ?? '') ?>">
                                            <button type="submit" class="admin-btn admin-btn-secondary admin-btn-sm" style="padding: 0.25rem 0.65rem;">
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
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>⛔</span>
                    <span>Manual IP Block</span>
                </div>
            </div>

            <form method="post" action="/admin/seguranca/bloquear">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <div style="margin-bottom: 1rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
                        IP Address (IPv4 or IPv6)
                    </label>
                    <input type="text" name="ip" placeholder="e.g. 198.51.100.25" required class="admin-input">
                </div>

                <div style="margin-bottom: 1.25rem;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.35rem; color: var(--admin-text-main);">
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

                <button type="submit" class="admin-btn admin-btn-primary" style="width: 100%; justify-content: center; background: linear-gradient(180deg, #dc2626 0%, #b91c1c 100%);">
                    <span>⛔</span>
                    <span>Apply Immediate Lockout</span>
                </button>
            </form>
        </div>

        <div class="admin-card">
            <div class="admin-card-title">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>📜</span>
                    <span>Active Security Policies</span>
                </div>
            </div>
            <ul style="font-size: 0.825rem; color: var(--admin-text-muted); padding-left: 1.25rem; margin: 0; line-height: 1.6;">
                <li>Addresses exceeding failure thresholds receive automated lockout protection.</li>
                <li>Unblocking an IP resets its failure counter and clears the lock timestamp immediately.</li>
                <li>Incoming requests from blocked IPs are short-circuited before querying user records.</li>
            </ul>
        </div>
    </div>
</div>
