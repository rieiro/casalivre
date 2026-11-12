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

<!-- BACKGROUND DAEMON SUPERVISION -->
<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div class="admin-card-title">
        <div style="display: flex; align-items: center; gap: 0.6rem;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #eff6ff; color: #2563eb; font-size: 0.95rem;">⚡</span>
            <span>Background Daemon Supervision</span>
        </div>
        <span style="font-size: 0.8rem; font-weight: 500; color: var(--admin-text-muted); background: #f8fafc; padding: 0.25rem 0.65rem; border-radius: 9999px; border: 1px solid var(--admin-border);">
            Runit /var/service
        </span>
    </div>
    <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
        <?php foreach ($services as $name => $running): ?>
            <div style="display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.45rem 0.85rem; background: #ffffff; border: 1px solid <?= $running ? '#bbf7d0' : '#fecaca' ?>; border-radius: var(--admin-radius-sm); font-size: 0.85rem; box-shadow: var(--admin-shadow-sm);">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span style="font-weight: 600; font-family: ui-monospace, monospace; color: #1e293b;"><?= e($name) ?></span>
                <span style="font-size: 0.72rem; font-weight: 700; padding: 0.1rem 0.4rem; border-radius: 4px; background: <?= $running ? '#dcfce7' : '#fee2e2' ?>; color: <?= $running ? '#166534' : '#991b1b' ?>;">
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
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>🖼️</span>
                    <span>Image Optimization Queue (WebP)</span>
                </div>
                <div>
                    <form method="post" action="/admin/filas/reprocessar-imagens" style="display: inline; margin: 0;">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary admin-btn-sm">
                            🔄 Retry Failed
                        </button>
                    </form>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 0.85rem 1.15rem; flex: 1;">
                    <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 700; letter-spacing: 0.05em;">Total</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: #0f172a; margin-top: 0.2rem;"><?= e((string)($image_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--admin-radius-sm); padding: 0.85rem 1.15rem; flex: 1;">
                    <div style="font-size: 0.72rem; text-transform: uppercase; color: #1e40af; font-weight: 700; letter-spacing: 0.05em;">Pending</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: #2563eb; margin-top: 0.2rem;"><?= e((string)($image_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div style="background: <?= ($image_queue['stats']['failed'] ?? 0) > 0 ? '#fef2f2' : '#f8fafc' ?>; border: 1px solid <?= ($image_queue['stats']['failed'] ?? 0) > 0 ? '#fecaca' : 'var(--admin-border)' ?>; border-radius: var(--admin-radius-sm); padding: 0.85rem 1.15rem; flex: 1;">
                    <div style="font-size: 0.72rem; text-transform: uppercase; color: <?= ($image_queue['stats']['failed'] ?? 0) > 0 ? '#991b1b' : 'var(--admin-text-muted)' ?>; font-weight: 700; letter-spacing: 0.05em;">Failed</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: <?= ($image_queue['stats']['failed'] ?? 0) > 0 ? '#ef4444' : '#64748b' ?>; margin-top: 0.2rem;"><?= e((string)($image_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--admin-text-muted); margin-bottom: 0.65rem; font-weight: 700;">
                Recent Tasks
            </h4>
            <?php if (empty($image_queue['recent'])): ?>
                <div style="text-align: center; padding: 2rem 1rem; color: var(--admin-text-muted); background: #f8fafc; border-radius: var(--admin-radius-sm); border: 1px solid var(--admin-border);">
                    No recent tasks in the image queue.
                </div>
            <?php else: ?>
                <div class="admin-table-container">
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
                                    <td><strong class="admin-code">#<?= e((string)($item['id'] ?? '')) ?></strong></td>
                                    <td style="font-size: 0.8rem; font-family: ui-monospace, monospace;"><?= e((string)($item['photo_id'] ?? '')) ?></td>
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
                                    <td><span style="font-weight: 600;"><?= e((string)($item['attempts'] ?? 0)) ?></span></td>
                                    <td style="font-size: 0.75rem; color: #ef4444; font-family: ui-monospace, monospace;"><?= e(substr((string)($item['last_error'] ?? '-'), 0, 40)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- EMAIL QUEUE -->
        <div class="admin-card">
            <div class="admin-card-title">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>✉️</span>
                    <span>Email Dispatch Queue (SMTP)</span>
                </div>
                <div>
                    <form method="post" action="/admin/filas/reprocessar-emails" style="display: inline; margin: 0;">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary admin-btn-sm">
                            🔄 Retry Failed
                        </button>
                    </form>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-bottom: 1.25rem;">
                <div style="background: #f8fafc; border: 1px solid var(--admin-border); border-radius: var(--admin-radius-sm); padding: 0.85rem 1.15rem; flex: 1;">
                    <div style="font-size: 0.72rem; text-transform: uppercase; color: var(--admin-text-muted); font-weight: 700; letter-spacing: 0.05em;">Total</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: #0f172a; margin-top: 0.2rem;"><?= e((string)($mail_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div style="background: #eff6ff; border: 1px solid #bfdbfe; border-radius: var(--admin-radius-sm); padding: 0.85rem 1.15rem; flex: 1;">
                    <div style="font-size: 0.72rem; text-transform: uppercase; color: #1e40af; font-weight: 700; letter-spacing: 0.05em;">Pending</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: #2563eb; margin-top: 0.2rem;"><?= e((string)($mail_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div style="background: <?= ($mail_queue['stats']['failed'] ?? 0) > 0 ? '#fef2f2' : '#f8fafc' ?>; border: 1px solid <?= ($mail_queue['stats']['failed'] ?? 0) > 0 ? '#fecaca' : 'var(--admin-border)' ?>; border-radius: var(--admin-radius-sm); padding: 0.85rem 1.15rem; flex: 1;">
                    <div style="font-size: 0.72rem; text-transform: uppercase; color: <?= ($mail_queue['stats']['failed'] ?? 0) > 0 ? '#991b1b' : 'var(--admin-text-muted)' ?>; font-weight: 700; letter-spacing: 0.05em;">Failed</div>
                    <div style="font-size: 1.65rem; font-weight: 800; color: <?= ($mail_queue['stats']['failed'] ?? 0) > 0 ? '#ef4444' : '#64748b' ?>; margin-top: 0.2rem;"><?= e((string)($mail_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--admin-text-muted); margin-bottom: 0.65rem; font-weight: 700;">
                Recent Dispatches
            </h4>
            <?php if (empty($mail_queue['recent'])): ?>
                <div style="text-align: center; padding: 2rem 1rem; color: var(--admin-text-muted); background: #f8fafc; border-radius: var(--admin-radius-sm); border: 1px solid var(--admin-border);">
                    No recent emails in queue.
                </div>
            <?php else: ?>
                <div class="admin-table-container">
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
                                    <td><strong class="admin-code">#<?= e((string)($item['id'] ?? '')) ?></strong></td>
                                    <td><span class="admin-code"><?= e((string)($item['type'] ?? '')) ?></span></td>
                                    <td style="font-size: 0.8rem; font-family: ui-monospace, monospace;"><?= e((string)($item['recipient'] ?? '')) ?></td>
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
                                    <td><span style="font-weight: 600;"><?= e((string)($item['attempts'] ?? 0)) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- RIGHT: MANUAL EXECUTION & CLI COMMANDS -->
    <div>
        <div class="admin-card">
            <div class="admin-card-title">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>⚙️</span>
                    <span>Manual Execution & Workers</span>
                </div>
            </div>
            <p style="font-size: 0.85rem; color: var(--admin-text-muted); line-height: 1.5; margin-top: 0;">
                Workers operate continuously under the <code>casalivre-queue-worker</code> supervisor daemon.
            </p>
            <div style="background: #0f172a; color: #f8fafc; border-radius: var(--admin-radius-sm); padding: 0.85rem; font-size: 0.78rem; font-family: ui-monospace, monospace; line-height: 1.6; margin-bottom: 1rem;">
                <span style="color: #64748b;"># Check status</span><br>
                <span style="color: #38bdf8;">sv status</span> casalivre-queue-worker<br><br>
                <span style="color: #64748b;"># Process pending tasks once</span><br>
                <span style="color: #38bdf8;">php</span> scripts/queue-worker.php all --once
            </div>
            <p style="font-size: 0.8rem; color: var(--admin-text-muted); line-height: 1.5; margin-bottom: 0;">
                Use retry buttons if transient connection failures have exhausted maximum attempts.
            </p>
        </div>
    </div>
</div>
