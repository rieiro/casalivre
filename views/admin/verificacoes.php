<?php
/**
 * @var list<array<string, mixed>> $pending
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var bool $ok
 * @var string|null $error
 */
?>
<?php if (!empty($ok)): ?>
    <div class="admin-alert-banner admin-alert-ok">Ação concluída.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="admin-alert-banner admin-alert-warn"><?= e($error) ?></div>
<?php endif; ?>

<div class="admin-card">
    <div class="admin-card-title">
        <span>✅ OTA Verification Review</span>
        <span class="admin-card-tag"><?= count($pending) ?> pending</span>
    </div>
    <p class="muted">Booking.com and Vrbo challenges are held here before the public badge and credits are released.</p>

    <?php if (!$pending): ?>
        <div class="admin-empty-state">No pending OTA verifications.</div>
    <?php else: ?>
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Host / Property</th>
                        <th>Platform</th>
                        <th>Listing</th>
                        <th>Calendar metadata</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $row): ?>
                    <tr>
                        <td>
                            <strong><?= e((string) ($row['user_name'] ?? '')) ?></strong><br>
                            <span class="muted fine"><?= e((string) ($row['email'] ?? '')) ?></span><br>
                            <span class="admin-card-tag"><?= e((string) ($row['property_title'] ?? '')) ?></span>
                        </td>
                        <td><?= e((string) ($row['source_platform'] ?? '')) ?></td>
                        <td>
                            <?php if (!empty($row['property_url'])): ?>
                                <a href="<?= e((string) $row['property_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string) $row['property_url']) ?></a>
                            <?php endif; ?>
                            <br><span class="muted fine"><?= e((string) ($row['ical_url_masked'] ?? '')) ?></span>
                            <br><span class="muted fine"><?= e((string) ($row['canonical_listing_id'] ?? '')) ?></span>
                        </td>
                        <td><span class="muted fine"><?= e((string) ($row['ics_metadata'] ?? '')) ?></span></td>
                        <td>
                            <form method="post" action="/admin/verificacoes/<?= (int) $row['id'] ?>/aprovar">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button class="admin-btn admin-btn-primary admin-btn-sm" type="submit">Aprovar</button>
                            </form>
                            <form method="post" action="/admin/verificacoes/<?= (int) $row['id'] ?>/rejeitar" class="mt-8">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input name="review_note" maxlength="500" placeholder="Motivo da rejeição">
                                <button class="admin-btn admin-btn-secondary admin-btn-sm" type="submit">Rejeitar</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
