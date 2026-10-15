<section class="panel">
    <p><a href="/admin/grupos">Grupos</a></p>
    <h1><?= e($group['name']) ?> <span class="muted">(<?= e($group['slug']) ?>)</span></h1>
    <?php if (!empty($ok)): ?><p class="notice" role="status"><?= e($ok) ?></p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Nome <input name="nome" required maxlength="80" value="<?= e($group['name']) ?>"></label>
        <label>Tema
            <select name="tema">
                <?php foreach ($temas as $nome): ?>
                    <option value="<?= e($nome) ?>" <?= $nome === $group['theme'] ? 'selected' : '' ?>><?= e(Theme::brand($nome)['rotulo']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Salvar</button>
    </form>

    <h2>Domínios</h2>
    <?php if ($domains === []): ?><p class="muted">Nenhum domínio.</p><?php endif; ?>
    <ul>
        <?php foreach ($domains as $dr): $d = (string) $dr['domain']; ?>
            <li><?= e($d) ?>
                <?php if ($dr['verified_at'] !== null): ?>
                    <span class="notice">verificado em <?= e((string) $dr['verified_at']) ?></span>
                <?php else: ?>
                    <span class="muted">não verificado (o domínio só passa a usar o tema do grupo depois da verificação)</span>
                <?php endif; ?>
                <?php if ($dr['verify_token'] !== ''): ?>
                    <p class="muted">Registro DNS TXT: nome <code><?= e(DomainVerification::txtName($d)) ?></code>, valor <code><?= e(DomainVerification::txtValue((string) $dr['verify_token'])) ?></code></p>
                <?php endif; ?>
                <?php foreach (['verificar' => ['', 'Verificar agora'], 'token' => ['', 'Gerar novo token'], 'remover' => ['danger', 'Remover']] as $acao => [$classe, $rotulo]): ?>
                    <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>/dominios/<?= e($acao) ?>">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="dominio" value="<?= e($d) ?>">
                        <button<?= $classe !== '' ? ' class="' . e($classe) . '"' : '' ?> type="submit"><?= e($rotulo) ?></button>
                    </form>
                <?php endforeach; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>/dominios">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Domínio (ex.: imobiliaria.com.br) <input name="dominio" required maxlength="253"></label>
        <button type="submit">Incluir domínio</button>
    </form>

    <h2>Membros</h2>
    <?php if ($members === []): ?><p class="muted">Nenhum membro.</p><?php endif; ?>
    <ul>
        <?php foreach ($members as $mb): ?>
            <li><?= e($mb['name']) ?> (<?= e($mb['email']) ?>) · <?= e($mb['role']) ?>
                <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>/membros/remover">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="usuario" value="<?= (int) $mb['user_id'] ?>">
                    <button class="danger" type="submit">Remover</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>/membros">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>E-mail da conta <input type="email" name="email" required maxlength="254"></label>
        <label>Papel
            <select name="papel">
                <option value="gestor">gestor (convida e remove imóveis)</option>
                <option value="membro">membro (só vê)</option>
            </select>
        </label>
        <button type="submit">Incluir ou trocar papel</button>
    </form>

    <h2>Imóveis do grupo</h2>
    <?php if ($properties === []): ?><p class="muted">Nenhum imóvel.</p><?php endif; ?>
    <ul>
        <?php foreach ($properties as $p): ?>
            <li><a href="/imoveis/<?= e(property_ref($p)) ?>"><?= e($p['title']) ?></a> <span class="muted"><?= e($p['city']) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($invites !== []): ?>
        <h2>Convites pendentes</h2>
        <ul>
            <?php foreach ($invites as $p): ?>
                <li><a href="/imoveis/<?= e(property_ref($p)) ?>"><?= e($p['title']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
