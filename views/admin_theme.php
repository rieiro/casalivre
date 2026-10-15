<section class="panel">
    <h1>Tema da plataforma</h1>
    <?php if (!empty($ok)): ?><p class="notice">Tema salvo.</p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/tema">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Tema
            <select name="tema">
                <?php foreach ($temas as $nome => $rotulo): ?>
                    <option value="<?= e($nome) ?>" <?= $nome === $atual ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Salvar</button>
    </form>
</section>
