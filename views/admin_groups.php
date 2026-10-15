<section class="panel">
    <h1>Grupos</h1>
    <?php if (!empty($ok)): ?><p class="notice" role="status"><?= e($ok) ?></p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($groups === []): ?>
        <p class="muted">Nenhum grupo.</p>
    <?php else: ?>
        <ul>
            <?php foreach ($groups as $g): ?>
                <li><a href="/admin/grupos/<?= e($g['slug']) ?>"><?= e($g['name']) ?></a> <span class="muted">(<?= e($g['slug']) ?> · tema <?= e($g['theme']) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <h2>Novo grupo</h2>
    <form method="post" action="/admin/grupos">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Endereço (letras minúsculas, números e hífen)
            <input name="slug" required maxlength="40" pattern="[a-z0-9](?:[a-z0-9\-]{0,38}[a-z0-9])?" value="<?= e(is_string($posted['slug'] ?? null) ? $posted['slug'] : '') ?>">
        </label>
        <label>Nome
            <input name="nome" required maxlength="80" value="<?= e(is_string($posted['nome'] ?? null) ? $posted['nome'] : '') ?>">
        </label>
        <label>Tema
            <select name="tema">
                <?php foreach ($temas as $nome): ?>
                    <option value="<?= e($nome) ?>"><?= e(Theme::brand($nome)['rotulo']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Criar grupo</button>
    </form>
</section>
