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

<div class="admin-card" style="margin-bottom: 1.5rem;">
    <div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
        <form method="get" action="/admin/traducoes" style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center;">
            <input type="text" name="search" value="<?= e($current_search ?? '') ?>" placeholder="Search term or key..." style="padding: 0.45rem 0.75rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.85rem; width: 220px;">

            <select name="prefix" style="padding: 0.45rem 0.65rem; border: 1px solid var(--admin-border); border-radius: 0.375rem; font-size: 0.85rem;">
                <option value="">All Modules</option>
                <?php foreach ($prefixes as $prefix => $count): ?>
                    <option value="<?= e($prefix) ?>" <?= $current_prefix === $prefix ? 'selected' : '' ?>>
                        <?= e($prefix) ?> (<?= e((string)$count) ?>)
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="admin-btn admin-btn-secondary" style="padding: 0.45rem 0.85rem; font-size: 0.85rem;">
                Search
            </button>
            <?php if ($current_search !== null || $current_prefix !== null): ?>
                <a href="/admin/traducoes" class="admin-btn admin-btn-secondary" style="padding: 0.45rem 0.85rem; font-size: 0.85rem; text-decoration: none;">
                    Clear
                </a>
            <?php endif; ?>
        </form>

        <div style="font-size: 0.8rem; color: var(--admin-text-muted);">
            Showing: <strong><?= count($translations) ?></strong> keys
        </div>
    </div>
</div>

<div class="admin-card">
    <div class="admin-card-title">
        <span>Multi-Language Translation Matrix</span>
        <span style="font-size: 0.8rem; font-weight: normal; color: var(--admin-text-muted);">pt-BR &bull; en &bull; es (O(1) cache with atomic invalidation)</span>
    </div>

    <?php if (empty($translations)): ?>
        <p style="color: var(--admin-text-muted); font-size: 0.9rem;">No translations found for the specified filter.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th style="width: 20%;">Key (tkey)</th>
                        <th style="width: 25%;">🇧🇷 Portuguese (pt-BR)</th>
                        <th style="width: 25%;">🇺🇸 English (en)</th>
                        <th style="width: 25%;">🇪🇸 Spanish (es)</th>
                        <th style="width: 5%;">Action</th>
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
                                    <input type="text" name="translations[pt-BR]" value="<?= e($vals['pt-BR'] ?? '') ?>" style="width: 100%; box-sizing: border-box; padding: 0.35rem 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.25rem; font-size: 0.85rem;">
                                </td>
                                <td>
                                    <input type="text" name="translations[en]" value="<?= e($vals['en'] ?? '') ?>" style="width: 100%; box-sizing: border-box; padding: 0.35rem 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.25rem; font-size: 0.85rem;">
                                </td>
                                <td>
                                    <input type="text" name="translations[es]" value="<?= e($vals['es'] ?? '') ?>" style="width: 100%; box-sizing: border-box; padding: 0.35rem 0.5rem; border: 1px solid var(--admin-border); border-radius: 0.25rem; font-size: 0.85rem;">
                                </td>
                                <td>
                                    <button type="submit" class="admin-btn admin-btn-primary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">
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
