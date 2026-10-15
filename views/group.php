<section class="panel">
    <p><a href="/grupos">Grupos</a></p>
    <h1><?= e($group['name']) ?></h1>
    <p class="muted">Seu papel: <?= e($role) ?></p>
    <p>Vitrine: <a class="group-storefront" href="<?= e($vitrine_url) ?>"><?= e($vitrine_url) ?></a></p>
    <?php if (!empty($ok)): ?><p class="notice" role="status"><?= e($ok) ?></p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <h2>Imóveis do grupo</h2>
    <?php if ($properties === []): ?><p class="muted">Nenhum imóvel.</p><?php endif; ?>
    <ul>
        <?php foreach ($properties as $p): ?>
            <li><a href="/imoveis/<?= e(property_ref($p)) ?>"><?= e($p['title']) ?></a> <span class="muted"><?= e($p['city']) ?></span>
                <?php if ($role === 'gestor'): ?>
                    <form method="post" action="/grupos/<?= e($group['slug']) ?>/imoveis/remover">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="imovel" value="<?= e(property_ref($p)) ?>">
                        <button class="danger" type="submit">Tirar do grupo</button>
                    </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ($role === 'gestor'): ?>
        <h2>Convidar imóvel</h2>
        <p class="muted">O imóvel só entra no grupo depois do aceite do dono.</p>
        <form method="post" action="/grupos/<?= e($group['slug']) ?>/convites">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label>Código do imóvel (os 16 caracteres do endereço /imoveis/…) <input name="imovel" required maxlength="16" pattern="[a-f0-9]{16}"></label>
            <button type="submit">Convidar</button>
        </form>
        <?php if ($invites !== []): ?>
            <h2>Convites pendentes</h2>
            <ul>
                <?php foreach ($invites as $p): ?>
                    <li><a href="/imoveis/<?= e(property_ref($p)) ?>"><?= e($p['title']) ?></a>
                        <form method="post" action="/grupos/<?= e($group['slug']) ?>/convites/cancelar">
                            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="imovel" value="<?= e(property_ref($p)) ?>">
                            <button class="danger" type="submit">Cancelar</button>
                        </form>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>
