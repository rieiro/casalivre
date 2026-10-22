# CasaLivre: especificação de telas (v1, 14/10/2026, meta WCAG 2.2 AA)

## 1. Busca com mapa (MapLibre GL + PMTiles, estilo Protomaps light)

**Objetivo:** comparar imóveis por localização e preço total do período.

**Desktop**
- Lista (60%, cards em 2 colunas) e mapa (40%) fixo ao rolar.
- Card e pin ficam destacados juntos. Cards além dos 300 pins não destacam nada no mapa (piloto).
- "Buscar ao mover o mapa" vem ligado, com espera de ~500 ms. Desligado, aparece o botão "Buscar nesta área". A map search replaces the place: the location field shows "Map area" with × to return to the last place (`spec-busca-localidade-resolvida.md`, UX item 6).

**Mobile**
- Lista em coluna única.
- Botão flutuante "Mapa"/"Lista" acima da barra de abas.
- Mapa em tela cheia, com carrossel de cards sincronizado com os pins.
- "Buscar ao mover o mapa" is OFF by default on mobile; the "Buscar nesta área" button appears after a pan or zoom.

**Componentes**
- Card: fotos, favoritar, título, nota e "R$ X total · N noites · taxas inclusas".
- Pin de preço: estados normal, selecionado (invertido) e visitado.
- Cluster: mostra a contagem e expande com toque, clique ou zoom. É o agrupamento, no cliente, dos pins desenhados (até 300); nunca absorve pins patrocinados.
- Bolha de contagem (301 a 5.000 candidatos, ADR 001 §12.7b): quadrado arredondado com o número e um "+", abaixo dos pins e clusters; mostra os imóveis que não viraram pin e, ao toque, aproxima até os limites da célula e busca. Com datas, a contagem é aproximada ("~N"). Detalhes em [spec-busca-ordenacao-filtros.md](spec-busca-ordenacao-filtros.md) §7.4.
- Pin patrocinado: o pin com `sponsored` mostra sempre a etiqueta "Patrocinado" acima do preço, em todos os estados e zooms; conta dentro dos 300 pins.
- Popup (desktop): foto, título, nota, preço total e "Ver detalhes".
- Atribuição: "© OpenStreetMap contributors · Protomaps", sempre visível.

**Estados**
- Carregando: skeleton de 6 cards e "Carregando mapa…".
- O mapa só carrega quando necessário.
- Sem WebGL: volta para a lista com aviso.
- Vazio: Limpar filtros, Ampliar área e Datas flexíveis.
- Erro: Tentar novamente.
- Atualizando: "Atualizando resultados…", mantendo a lista antiga na tela.

**Acessibilidade**
- A lista é a alternativa ao mapa pelo teclado. No mapa, setas movem, +/− dão zoom, os pins são focáveis, o Enter abre o popup e o foco volta ao pin quando ele fecha.
- 2.5.7: zoom, "Buscar nesta área" e anterior/próximo substituem o arrastar.
- 2.5.8: alvos de no mínimo 24 px (padrão 44pt/48dp).
- 4.1.3: aria-live anuncia a contagem e as atualizações.
- 1.4.3: contraste de 4,5:1. O estado selecionado não depende só de cor.
- Respeitar prefers-reduced-motion.
- Fotos com texto alternativo.

## 2. Upload de fotos do anfitrião

**Objetivo:** enviar, ordenar e escolher a capa sem perder trabalho.

**Layout**
- Desktop: zona "Arraste fotos aqui ou Selecionar arquivos" e grade de 3 a 4 colunas. A 1ª foto fica maior, com o selo Capa.
- Mobile: Galeria e Tirar foto, grade de 2 colunas e Concluir fixo no rodapé.

**Limites**
- No máximo 30 envios a cada 10 minutos. Ao atingir o limite, mostrar "Você enviou muitas fotos agora. Tente de novo em X min." (anunciado por role=status). As fotos pendentes ficam na fila.
- Saída em WebP, em 3 tamanhos, gerados durante o "Processando…".
- Formatos de entrada a definir pelo Bot Programador.

**Estados de cada foto**
- Enviando: % e Cancelar.
- "Processando…"
- Pronta: capa, mover, texto alternativo e remover.
- "Falhou, reenviar": Reenviar e Remover.
- Arquivo recusado.

**Regras**
- A capa fica sempre na 1ª posição.
- Os envios são em paralelo e uma falha não trava os outros.
- Remover tem Desfazer por 5 s.
- Contador "3 de 5 recomendadas".
- Salvamento automático com o status "Salvo agora", "Salvando…" ou "Sem conexão".

**Acessibilidade**
- 2.5.7: botões Selecionar, Mover para a esquerda/direita e Definir como capa substituem o arrastar.
- 2.5.8: alvos de no mínimo 24 px.
- 4.1.3: aria-live ("Foto 3 de 8 enviada", "Movida para a posição 2"). Os erros usam role=alert.
- Foco: continua na foto movida.
- Rótulo da miniatura: "Foto N de M, capa".
- 1.4.3: contraste de 4,5:1 para texto e 3:1 para ícones e barras. O erro tem ícone e texto.
- Sem animação ao reordenar.
