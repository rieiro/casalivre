<?php
/**
 * @var array<string, mixed> $image_queue
 * @var array<string, mixed> $mail_queue
 * @var array<string, bool> $services
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string|null $ok
 * @var string|null $error
 */
?>

<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div class="admin-card-title">
        <span>Background Daemon Supervision</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">Runit /var/service</span>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
        <?php foreach ($services as $name => $running): ?>
            <div style="display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.45rem 0.85rem; background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.85rem;">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span style="font-weight: 500;"><?= e($name) ?></span>
                <span style="font-size: 0.75rem; color: <?= $running ? 'var(--admin-success)' : 'var(--admin-danger)' ?>;">
                    <?= $running ? 'ONLINE' : 'STOPPED' ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="admin-grid-split">
    <div>
        <!-- IMAGE QUEUE -->
        <div class="admin-card">
            <div class="admin-card-title">
                <span>🖼️ Image Optimization Queue (WebP)</span>
                <div>
                    <form method="post" action="/admin/filas/reprocessar-imagens" style="display: inline;">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                            🔄 Retry Failed
                        </button>
                    </form>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Total</div>
                    <div style="font-size: 1.5rem; font-weight: 700;"><?= e((string)($image_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Pending</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2563eb;"><?= e((string)($image_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Failed</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;"><?= e((string)($image_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); margin-bottom: 0.5rem;">
                Recent Tasks
            </h4>
            <?php if (empty($image_queue['recent'])): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.85rem;">No recent tasks in the image queue.</p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Photo ID</th>
                            <th>Status</th>
                            <th>Attempts</th>
                            <th>Last Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($image_queue['recent'] as $item): ?>
                            <tr>
                                <td>#<?= e((string)($item['id'] ?? '')) ?></td>
                                <td><?= e((string)($item['photo_id'] ?? '')) ?></td>
                                <td>
                                    <?php
                                        $st = (string)($item['status'] ?? '');
                                        $pill = match($st) {
                                            'pending' => 'admin-pill-neutral',
                                            'processing' => 'admin-pill-warn',
                                            'failed' => 'admin-pill-danger',
                                            default => 'admin-pill-ok',
                                        };
                                    ?>
                                    <span class="admin-pill <?= $pill ?>"><?= e($st) ?></span>
                                </td>
                                <td><?= e((string)($item['attempts'] ?? 0)) ?></td>
                                <td style="font-size: 0.8rem; color: #ef4444;"><?= e(substr((string)($item['last_error'] ?? '-'), 0, 40)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- EMAIL QUEUE -->
        <div class="admin-card">
            <div class="admin-card-title">
                <span>✉️ Email Dispatch Queue (SMTP)</span>
                <div>
                    <form method="post" action="/admin/filas/reprocessar-emails" style="display: inline;">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">
                            🔄 Retry Failed
                        </button>
                    </form>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Total</div>
                    <div style="font-size: 1.5rem; font-weight: 700;"><?= e((string)($mail_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Pending</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #2563eb;"><?= e((string)($mail_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem 1rem; flex: 1;">
                    <div style="font-size: 0.75rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 600;">Failed</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #ef4444;"><?= e((string)($mail_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-text-muted); margin-bottom: 0.5rem;">
                Recent Dispatches
            </h4>
            <?php if (empty($mail_queue['recent'])): ?>
                <p style="color: var(--admin-text-muted); font-size: 0.85rem;">No recent emails in queue.</p>
            <?php else: ?>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Recipient</th>
                            <th>Status</th>
                            <th>Attempts</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mail_queue['recent'] as $item): ?>
                            <tr>
                                <td>#<?= e((string)($item['id'] ?? '')) ?></td>
                                <td><span class="admin-code"><?= e((string)($item['type'] ?? '')) ?></span></td>
                                <td style="font-size: 0.8rem;"><?= e((string)($item['recipient'] ?? '')) ?></td>
                                <td>
                                    <?php
                                        $mst = (string)($item['status'] ?? '');
                                        $mpill = match($mst) {
                                            'pending' => 'admin-pill-neutral',
                                            'failed' => 'admin-pill-danger',
                                            default => 'admin-pill-ok',
                                        };
                                    ?>
                                    <span class="admin-pill <?= $mpill ?>"><?= e($mst) ?></span>
                                </td>
                                <td><?= e((string)($item['attempts'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <div class="admin-card">
            <div class="admin-card-title">Manual Execution & Workers</div>
            <p style="font-size: 0.85rem; color: var(--admin-text-muted); line-height: 1.5;">
                Workers operate continuously under the <code>casalivre-queue-worker</code> supervisor daemon.
            </p>
            <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: 0.375rem; padding: 0.75rem; font-size: 0.8rem; font-family: monospace; margin-bottom: 1rem;">
                sv status casalivre-queue-worker<br>
                php scripts/queue-worker.php all --once
            </div>
            <p style="font-size: 0.8rem; color: var(--admin-text-muted);">
                Use retry buttons if transient connection failures have exhausted maximum attempts.
            </p>
        </div>
    </div>
</div>
