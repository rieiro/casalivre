<?php
/**
 * @var array<string, array<string, string>> $translations
 * @var array<string, int> $prefixes
 * @var string|null $current_search
 * @var string|null $current_prefix
 * @var array<string, mixed> $user
 * @var string $csrf
 * @var string|null $ok
 * @var string|null $error
 */
?>

<!-- FILTER TOOLBAR (Osclass translation tool style) -->
<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
        <form method="get" action="/admin/traducoes" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
            <input type="text" name="search" value="<?= e($current_search ?? '') ?>" placeholder="Search term or key..." class="admin-input" style="width: 240px; font-size: 0.85rem;">

            <select name="prefix" class="admin-select" style="font-size: 0.85rem; width: auto;">
                <option value="">All Modules</option>
                <?php foreach ($prefixes as $prefix => $count): ?>
                    <option value="<?= e($prefix) ?>" <?= $current_prefix === $prefix ? 'selected' : '' ?>>
                        <?= e($prefix) ?> (<?= e((string)$count) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.5rem 1rem;">
                <span>🔍</span>
                <span>Search</span>
            </button>
            <?php if ($current_search !== null || $current_prefix !== null): ?>
                <a href="/admin/traducoes" class="admin-btn admin-btn-secondary" style="padding: 0.5rem 0.85rem; text-decoration: none;">
                    Clear
                </a>
            <?php endif; ?>
        </form>

        <div style="font-size: 0.85rem; color: var(--admin-text-muted);">
            Showing: <strong><?= count($translations) ?></strong> translation keys
        </div>
    </div>
</div>

<!-- TRANSLATION MATRIX -->
<div class="admin-card">
    <div class="admin-card-title">
        <div style="display: flex; align-items: center; gap: 0.6rem;">
            <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 6px; background: #eff6ff; color: #2563eb; font-size: 0.95rem;">🌐</span>
            <span>Multi-Language Translation Matrix</span>
        </div>
        <span style="font-size: 0.8rem; font-weight: 500; color: var(--admin-text-muted); background: #f8fafc; padding: 0.25rem 0.65rem; border-radius: 9999px; border: 1px solid var(--admin-border);">
            pt-BR &bull; en &bull; es (O(1) cache with atomic invalidation)
        </span>
    </div>

    <?php if (empty($translations)): ?>
        <div style="text-align: center; padding: 2.5rem 1rem; color: var(--admin-text-muted);">
            <div style="font-size: 2rem; margin-bottom: 0.5rem;">🔍</div>
            <div style="font-weight: 600; color: var(--admin-text-main); margin-bottom: 0.25rem;">No Translations Found</div>
            <div style="font-size: 0.85rem;">No translation entries found matching the specified search or filter.</div>
        </div>
    <?php else: ?>
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width: 22%;">Key (tkey)</th>
                        <th style="width: 24%;">🇧🇷 Portuguese (pt-BR)</th>
                        <th style="width: 24%;">🇺🇸 English (en)</th>
                        <th style="width: 24%;">🇪🇸 Spanish (es)</th>
                        <th style="width: 6%; text-align: center;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($translations as $tkey => $vals): ?>
                        <tr>
                            <form method="post" action="/admin/traducoes">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="tkey" value="<?= e($tkey) ?>">

                                <td>
                                    <strong class="admin-code" style="display: inline-block; word-break: break-all;"><?= e($tkey) ?></strong>
                                </td>
                                <td>
                                    <input type="text" name="translations[pt-BR]" value="<?= e($vals['pt-BR'] ?? '') ?>" class="admin-input" style="padding: 0.4rem 0.6rem; font-size: 0.825rem;">
                                </td>
                                <td>
                                    <input type="text" name="translations[en]" value="<?= e($vals['en'] ?? '') ?>" class="admin-input" style="padding: 0.4rem 0.6rem; font-size: 0.825rem;">
                                </td>
                                <td>
                                    <input type="text" name="translations[es]" value="<?= e($vals['es'] ?? '') ?>" class="admin-input" style="padding: 0.4rem 0.6rem; font-size: 0.825rem;">
                                </td>
                                <td style="text-align: center;">
                                    <button type="submit" class="admin-btn admin-btn-primary admin-btn-sm" style="padding: 0.35rem 0.75rem;">
                                        Save
                                    </button>
                                </td>
                            </form>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
