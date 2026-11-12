<section class="panel">
    <p><a href="/admin/grupos">Groups</a></p>
    <h1><?= e($group['name']) ?> <span class="muted">(<?= e($group['slug']) ?>)</span></h1>
    <p>Storefront: <a class="group-storefront" href="<?= e($vitrine_url) ?>"><?= e($vitrine_url) ?></a></p>
    <?php if (!empty($ok)): ?><p class="notice" role="status"><?= e($ok) ?></p><?php endif; ?>
    <?php if (!empty($error)): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Name <input name="nome" required maxlength="80" value="<?= e($group['name']) ?>"></label>
        <label>Theme
            <select name="tema">
                <?php foreach ($temas as $nome): ?>
                    <option value="<?= e($nome) ?>" <?= $nome === $group['theme'] ? 'selected' : '' ?>><?= e(Theme::brand($nome)['rotulo']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Save</button>
    </form>

    <h2>Domains</h2>
    <?php if ($domains === []): ?><p class="muted">No domains registered.</p><?php endif; ?>
    <ul>
        <?php foreach ($domains as $dr): $d = (string) $dr['domain']; ?>
            <li><?= e($d) ?>
                <?php if ($dr['verified_at'] !== null): ?>
                    <span class="notice">verified (last confirmed at <?= e((string) $dr['verified_at']) ?> UTC)</span>
                    <?php if ((int) $dr['fail_count'] > 0): ?><span class="error"><?= (int) $dr['fail_count'] ?> consecutive check(s) without TXT record; after <?= DomainReverification::MAX_FAILS ?> attempts or <?= DomainReverification::GRACE_HOURS ?>h without success verification will be revoked</span><?php endif; ?>
                <?php else: ?>
                    <span class="muted">unverified (the domain will only use the group theme after verification)</span>
                <?php endif; ?>
                <?php if ($dr['verify_token'] !== '' && $dr['last_check_at'] === null): ?>
                    <span class="muted">check pending: scheduled check at <?= e(DomainReverification::checkDeadline()) ?></span>
                <?php elseif ($dr['verify_token'] !== '' && $dr['verified_at'] === null): ?>
                    <span class="muted">last checked at <?= e((string) $dr['last_check_at']) ?> UTC: TXT not found</span>
                <?php endif; ?>
                <?php if ($dr['verify_token'] !== ''): ?>
                    <p class="muted">DNS TXT Record: name <code><?= e(DomainVerification::txtName($d)) ?></code>, value <code><?= e(DomainVerification::txtValue((string) $dr['verify_token'])) ?></code></p>
                <?php endif; ?>
                <?php foreach (['verificar' => ['', 'Verify now'], 'token' => ['', 'Generate new token'], 'remover' => ['danger', 'Remove']] as $acao => [$classe, $rotulo]): ?>
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
        <label>Domain (e.g., mysite.com) <input name="dominio" required maxlength="253"></label>
        <button type="submit">Add Domain</button>
    </form>

    <h2>Members</h2>
    <?php if ($members === []): ?><p class="muted">No members.</p><?php endif; ?>
    <ul>
        <?php foreach ($members as $mb): ?>
            <li><?= e($mb['name']) ?> (<?= e($mb['email']) ?>) · <?= e($mb['role']) ?>
                <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>/membros/remover">
                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="usuario" value="<?= (int) $mb['user_id'] ?>">
                    <button class="danger" type="submit">Remove</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
    <form method="post" action="/admin/grupos/<?= e($group['slug']) ?>/membros">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label>Account Email <input type="email" name="email" required maxlength="254"></label>
        <label>Role
            <select name="papel">
                <option value="gestor">manager (invites and removes properties)</option>
                <option value="membro">member (read-only)</option>
            </select>
        </label>
        <button type="submit">Add or Change Role</button>
    </form>

    <h2>Group Properties</h2>
    <?php if ($properties === []): ?><p class="muted">No properties in group.</p><?php endif; ?>
    <ul>
        <?php foreach ($properties as $p): ?>
            <li><a href="/imoveis/<?= e(property_ref($p)) ?>"><?= e($p['title']) ?></a> <span class="muted"><?= e($p['city']) ?></span></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($invites !== []): ?>
        <h2>Pending Invitations</h2>
        <ul>
            <?php foreach ($invites as $p): ?>
                <li><a href="/imoveis/<?= e(property_ref($p)) ?>"><?= e($p['title']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
