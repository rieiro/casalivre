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
<div class="admin-card">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
        <form method="get" action="/admin/traducoes" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
            <input type="text" name="search" value="<?= e($current_search ?? '') ?>" placeholder="Search term or key..." class="admin-input">

            <select name="prefix" class="admin-select">
                <option value="">All Modules</option>
                <?php foreach ($prefixes as $prefix => $count): ?>
                    <option value="<?= e($prefix) ?>" <?= $current_prefix === $prefix ? 'selected' : '' ?>>
                        <?= e($prefix) ?> (<?= e((string)$count) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="admin-btn admin-btn-secondary">
                <span>🔍</span>
                <span>Search</span>
            </button>
            <?php if ($current_search !== null || $current_prefix !== null): ?>
                <a href="/admin/traducoes" class="admin-btn admin-btn-secondary">
                    Clear
                </a>
            <?php endif; ?>
        </form>

        <div class="admin-hint">
            Showing: <strong><?= count($translations) ?></strong> translation keys
        </div>
    </div>
</div>

<!-- TRANSLATION MATRIX -->
<div class="admin-card">
    <div class="admin-card-title">
        <span>🌐 Multi-Language Translation Matrix</span>
        <span class="admin-card-tag">pt-BR &bull; en &bull; es (O(1) cache with atomic invalidation)</span>
    </div>

    <?php if (empty($translations)): ?>
        <div class="admin-empty-state">
            <div class="admin-empty-icon">🔍</div>
            <div class="admin-empty-title">No Translations Found</div>
            <div class="admin-empty-desc">No translation entries found matching the specified search or filter.</div>
        </div>
    <?php else: ?>
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Key (tkey)</th>
                        <th>🇧🇷 Portuguese (pt-BR)</th>
                        <th>🇺🇸 English (en)</th>
                        <th>🇪🇸 Spanish (es)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($translations as $tkey => $vals): ?>
                        <tr>
                            <form method="post" action="/admin/traducoes">
                                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="tkey" value="<?= e($tkey) ?>">

                                <td>
                                    <strong class="admin-code"><?= e($tkey) ?></strong>
                                </td>
                                <td>
                                    <input type="text" name="translations[pt-BR]" value="<?= e($vals['pt-BR'] ?? '') ?>" class="admin-input">
                                </td>
                                <td>
                                    <input type="text" name="translations[en]" value="<?= e($vals['en'] ?? '') ?>" class="admin-input">
                                </td>
                                <td>
                                    <input type="text" name="translations[es]" value="<?= e($vals['es'] ?? '') ?>" class="admin-input">
                                </td>
                                <td>
                                    <button type="submit" class="admin-btn admin-btn-primary admin-btn-sm">
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
