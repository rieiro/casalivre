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
<div class="admin-card">
    <div class="admin-card-title">
        <span>⚡ Background Daemon Supervision</span>
        <span class="admin-card-tag">Runit /var/service</span>
    </div>
    <div class="admin-daemon-list">
        <?php foreach ($services as $name => $running): ?>
            <div class="admin-daemon-badge <?= $running ? 'admin-daemon-online' : 'admin-daemon-stopped' ?>">
                <span class="admin-dot <?= $running ? 'admin-dot-ok' : 'admin-dot-danger' ?>"></span>
                <span class="admin-daemon-name"><?= e($name) ?></span>
                <span class="<?= $running ? 'admin-daemon-state-online' : 'admin-daemon-state-stopped' ?>">
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
                    <form method="post" action="/admin/filas/reprocessar-imagens">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary admin-btn-sm">
                            🔄 Retry Failed
                        </button>
                    </form>
                </div>
            </div>

            <div class="admin-queue-stats">
                <div class="admin-queue-box admin-queue-box-total">
                    <div class="admin-queue-label">Total</div>
                    <div class="admin-queue-num"><?= e((string)($image_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div class="admin-queue-box admin-queue-box-pending">
                    <div class="admin-queue-label">Pending</div>
                    <div class="admin-queue-num"><?= e((string)($image_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div class="admin-queue-box <?= ($image_queue['stats']['failed'] ?? 0) > 0 ? 'admin-queue-box-failed' : 'admin-queue-box-failed-clean' ?>">
                    <div class="admin-queue-label">Failed</div>
                    <div class="admin-queue-num"><?= e((string)($image_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 class="admin-form-section-title">
                <span>Recent Tasks</span>
            </h4>
            <?php if (empty($image_queue['recent'])): ?>
                <div class="admin-empty-state">
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
                                    <td><span class="admin-code"><?= e((string)($item['photo_id'] ?? '')) ?></span></td>
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
                                    <td><span class="admin-label"><?= e((string)($item['attempts'] ?? 0)) ?></span></td>
                                    <td><span class="admin-code"><?= e(substr((string)($item['last_error'] ?? '-'), 0, 40)) ?></span></td>
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
                <span>✉️ Email Dispatch Queue (SMTP)</span>
                <div>
                    <form method="post" action="/admin/filas/reprocessar-emails">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <button type="submit" class="admin-btn admin-btn-secondary admin-btn-sm">
                            🔄 Retry Failed
                        </button>
                    </form>
                </div>
            </div>

            <div class="admin-queue-stats">
                <div class="admin-queue-box admin-queue-box-total">
                    <div class="admin-queue-label">Total</div>
                    <div class="admin-queue-num"><?= e((string)($mail_queue['stats']['total'] ?? 0)) ?></div>
                </div>
                <div class="admin-queue-box admin-queue-box-pending">
                    <div class="admin-queue-label">Pending</div>
                    <div class="admin-queue-num"><?= e((string)($mail_queue['stats']['pending'] ?? 0)) ?></div>
                </div>
                <div class="admin-queue-box <?= ($mail_queue['stats']['failed'] ?? 0) > 0 ? 'admin-queue-box-failed' : 'admin-queue-box-failed-clean' ?>">
                    <div class="admin-queue-label">Failed</div>
                    <div class="admin-queue-num"><?= e((string)($mail_queue['stats']['failed'] ?? 0)) ?></div>
                </div>
            </div>

            <h4 class="admin-form-section-title">
                <span>Recent Dispatches</span>
            </h4>
            <?php if (empty($mail_queue['recent'])): ?>
                <div class="admin-empty-state">
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
                                    <td><span class="admin-sysinfo-val"><?= e((string)($item['recipient'] ?? '')) ?></span></td>
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
                                    <td><span class="admin-label"><?= e((string)($item['attempts'] ?? 0)) ?></span></td>
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
                <span>⚙️ Manual Execution & Workers</span>
            </div>
            <p class="admin-hint">
                Workers operate continuously under the <code>casalivre-queue-worker</code> supervisor daemon.
            </p>
            <div class="admin-cli-box">
                <span class="admin-cli-comment"># Check status</span><br>
                <span class="admin-cli-cmd">sv status</span> casalivre-queue-worker<br><br>
                <span class="admin-cli-comment"># Process pending tasks once</span><br>
                <span class="admin-cli-cmd">php</span> scripts/queue-worker.php all --once
            </div>
            <p class="admin-hint">
                Use retry buttons if transient connection failures have exhausted maximum attempts.
            </p>
        </div>
    </div>
</div>
