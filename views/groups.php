<section class="panel">
    <h1>Grupos</h1>
    <?php if (!empty($ok)): ?><p class="notice" role="status"><?= e($ok) ?></p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <h2>Convites para os seus imóveis</h2>
    <?php if ($invites === []): ?><p class="muted">Nenhum convite.</p><?php endif; ?>
    <ul>
        <?php foreach ($invites as $i): ?>
            <li><?= e($i['title']) ?>: convite do grupo <strong><?= e($i['name']) ?></strong> (<?= e($i['slug']) ?>)
                <form method="post" action="/imoveis/<?= e(property_ref($i)) ?>/grupo/aceitar">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="grupo" value="<?= e($i['slug']) ?>">
                    <button type="submit">Aceitar</button>
                </form>
                <form method="post" action="/imoveis/<?= e(property_ref($i)) ?>/grupo/recusar">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="grupo" value="<?= e($i['slug']) ?>">
                    <button class="danger" type="submit">Recusar</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <h2>Seus imóveis em grupos</h2>
    <?php if ($linked === []): ?><p class="muted">Nenhum imóvel em grupo.</p><?php endif; ?>
    <ul>
        <?php foreach ($linked as $l): ?>
            <li><?= e($l['title']) ?>: grupo <strong><?= e($l['name']) ?></strong>
                <form method="post" action="/imoveis/<?= e(property_ref($l)) ?>/grupo/sair">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <button class="danger" type="submit">Tirar do grupo</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($groups !== []): ?>
        <h2>Grupos de que você participa</h2>
        <ul>
            <?php foreach ($groups as $g): ?>
                <li><a href="/grupos/<?= e($g['slug']) ?>"><?= e($g['name']) ?></a> <span class="muted">(<?= e($g['role']) ?>)</span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
