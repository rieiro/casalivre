<section class="wrap">
    <div class="toolbar">
        <h1>Painel do Administrador</h1>
        <div>
            <a class="button" href="/admin/tema">Tema da plataforma</a>
            <a class="button" href="/admin/grupos">Grupos</a>
            <a class="primary" href="/imoveis/novo">Cadastrar Imóvel</a>
        </div>
    </div>
    <p class="muted">Gerenciamento direto de propriedades e geração de links de pagamento imediato.</p>

    <div class="grid mt-16">
        <?php foreach ($properties as $prop): ?>
            <article class="card">
                <?php if (!empty($prop["photo"])): ?>
                    <img src="<?= e(photo_url($prop["photo"])) ?>" alt="Foto de <?= e($prop["title"]) ?>" loading="lazy">
                <?php else: ?>
                    <img src="<?= e(photo_url(null)) ?>" alt="Sem foto" loading="lazy">
                <?php endif; ?>
                <div class="body">
                    <h2><?= e($prop["title"]) ?></h2>
                    <p class="muted"><?= e($prop["city"]) ?> · até <?= (int) $prop["max_guests"] ?> hóspedes</p>
                    <p><strong>Diária base:</strong> <?= money((int) $prop["nightly_price"]) ?> · <strong>Limpeza:</strong> <?= money((int) ($prop["cleaning_fee"] ?? 0)) ?></p>
                    
                    <div class="cover-actions mt-12">
                        <a class="primary" href="/admin/imoveis/<?= e(property_ref($prop)) ?>/cotacao">⚡ Gerar Link de Pagamento</a>
                        <a href="/imoveis/<?= e(property_ref($prop)) ?>/calendario">Ver Calendário</a>
                        <a href="/imoveis/<?= e(property_ref($prop)) ?>/editar">Fotos & Detalhes</a>
                        <a href="/imoveis/<?= e(property_ref($prop)) ?>" target="_blank">Página Pública ↗</a>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
