<?php
/**
 * @var list<array<string, mixed>> $pending
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var bool $ok
 * @var string|null $error
 */
?>
<div class="admin-card ota-review-card">
    <div class="admin-card-title">
        <span>✅ Revisão de Verificações OTA</span>
        <span class="admin-card-tag"><?= count($pending) ?> em análise</span>
    </div>
    <p class="admin-muted">Verificações do Booking.com e Vrbo ficam retidas aqui antes de liberar o selo público e os créditos.</p>
    <div class="ota-review-checklist">
        <strong>Checklist:</strong>
        1) Abra o anúncio público, 2) Confirme se a data destacada está indisponível no calendário, 3) Confirme se fotos/título batem com o imóvel no CasaLivre.
    </div>

    <?php if (!$pending): ?>
        <div class="admin-empty-state">Nenhuma verificação aguardando revisão.</div>
    <?php else: ?>
        <div class="admin-table-container">
            <table class="admin-table ota-review-table">
                <thead>
                    <tr>
                        <th>Imóvel (CasaLivre)</th>
                        <th>Plataforma</th>
                        <th>Anúncios (CasaLivre × Público)</th>
                        <th>Data para conferir</th>
                        <th>Meta iCal</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $row): ?>
                    <?php
                    $casalivreUrl = trim((string) ($row['casalivre_url'] ?? ''));
                    if ($casalivreUrl === '' && preg_match('/^[a-f0-9]{16}$/', trim((string) ($row['public_hash'] ?? ''))) === 1) {
                        $casalivreUrl = '/imoveis/' . trim((string) $row['public_hash']);
                    }
                    $externalUrl = trim((string) ($row['property_url'] ?? ''));
                    ?>
                    <tr>
                        <td>
                            <strong><?= e((string) ($row['property_title'] ?? '')) ?></strong>
                            <?php if (!empty($row['has_url_import'])): ?>
                                <span class="ota-url-import-badge" title="<?= e(t('ota.import.admin_warning')) ?>"><?= e(t('ota.import.admin_badge')) ?></span>
                            <?php endif; ?><br>
                            <span class="admin-muted"><?= e((string) ($row['property_city'] ?? '')) ?></span><br>
                            <span class="admin-muted"><?= e((string) ($row['user_name'] ?? '')) ?> · <?= e((string) ($row['email'] ?? '')) ?></span>
                            <?php if (!empty($row['has_url_import'])): ?>
                                <p class="ota-url-import-warning"><?= e(t('ota.import.admin_warning')) ?></p>
                            <?php endif; ?>
                        </td>
                        <td><span class="admin-card-tag"><?= e((string) ($row['platform_label'] ?? '')) ?></span></td>
                        <td>
                            <div class="ota-links-pair">
                                <div class="ota-link-box">
                                    <span class="ota-link-label">CasaLivre</span>
                                    <?php if ($casalivreUrl !== ''): ?>
                                        <a href="<?= e($casalivreUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($casalivreUrl) ?></a>
                                    <?php else: ?>
                                        <span class="admin-muted">—</span>
                                    <?php endif; ?>
                                </div>
                                <div class="ota-link-box">
                                    <span class="ota-link-label"><?= e((string) ($row['platform_label'] ?? 'Público')) ?></span>
                                    <?php if ($externalUrl !== ''): ?>
                                        <a href="<?= e($externalUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($externalUrl) ?></a>
                                    <?php else: ?>
                                        <span class="admin-muted">—</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="ota-link-meta">
                                <span class="admin-muted"><?= e((string) ($row['ical_url_masked'] ?? '')) ?></span>
                                <?php if (!empty($row['canonical_listing_id'])): ?>
                                    <br><span class="admin-muted"><?= e((string) $row['canonical_listing_id']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <span class="ota-challenge-pill"><?= e((string) ($row['challenge_date_br'] ?? '')) ?></span>
                        </td>
                        <td><span class="admin-muted"><?= e((string) ($row['ics_metadata'] ?? '')) ?></span></td>
                        <td>
                            <form method="post" action="/admin/verificacoes/<?= (int) $row['id'] ?>/aprovar" data-confirm-approve="Confirmar que a data destacada está indisponível e aprovar este anúncio?">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <button class="admin-btn admin-btn-primary admin-btn-sm" type="submit">Aprovar</button>
                            </form>
                            <form method="post" action="/admin/verificacoes/<?= (int) $row['id'] ?>/rejeitar" class="ota-reject-form">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <select data-reject-preset aria-label="Motivo rápido">
                                    <option value="">Selecione um motivo...</option>
                                    <option value="Data do desafio não está bloqueada no anúncio público">Data do desafio não está bloqueada no anúncio público</option>
                                    <option value="Anúncio público não corresponde ao imóvel">Anúncio público não corresponde ao imóvel</option>
                                    <option value="Outro motivo">Outro motivo</option>
                                </select>
                                <input name="review_note" maxlength="500" placeholder="Motivo da rejeição" data-reject-note required>
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

<script src="<?= e(asset_url('/assets/pages/admin-verificacoes.js')) ?>"></script>
