<section class="panel">
    <h1>Platform Visual Theme</h1>
    <?php if (!empty($ok)): ?><p class="notice">Theme saved successfully.</p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/tema">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Theme
            <select name="tema">
                <?php foreach ($temas as $nome => $rotulo): ?>
                    <option value="<?= e($nome) ?>" <?= $nome === $atual ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Save</button>
    </form>
</section>
