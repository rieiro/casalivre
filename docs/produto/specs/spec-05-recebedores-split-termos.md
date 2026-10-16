# CasaLivre: Spec 05, recebedores, split, ledger e termos (v1, 16/10/2026, alvo S6 (P2b), meta WCAG 2.2 AA)

Base: `backlog.md` P2b (critérios 1 a 10), `sprints.md` (S6), `roadmap.md` v0.2 (split = f(árvore, canal, configuração), ledger de partidas dobradas, termos versionados) e as specs 02 a 04.
**Casos de uso:** UC-A15 a UC-A24 (`casos-de-uso-admin-proposta.md`), UC-P29, UC-P30 e UC-P31.
**Layout:** desktop-first. No mobile, só consulta (extrato, status de KYC, aceites), e as ações mostram "Disponível no computador". Valores em centavos inteiros, exibidos como "R$ 1.234,56". Parâmetros sempre em dado, nunca fixos. **Revisão obrigatória de Segurança** (CPF/CNPJ, KYC, Pix, LGPD, dado fiscal).

## 0. Permissões
| Ação | admin_plataforma | admin_org (escopo) | gestor | operador | proprietário |
|---|---|---|---|---|---|
| Cadastrar recebedor no nó | ✓ | ✓ | com delegação | — | ✓ o próprio |
| Ver documentos KYC (05-2) | ✓ completo, com log de cada acesso | — (só status e validações) | — | — | — |
| Ver CPF/CNPJ e chave Pix | mascarado | mascarado | mascarado | — | completo (o próprio) |
| Trocar chave Pix | — (só ver) | — (só ver) | só com delegação explícita; nunca sozinho a do proprietário | — | ✓ |
| Analisar e aprovar KYC | ✓ | pré-verifica (território) | pré-verifica (território) | — | — |
| Editar regras de split | ✓ | ✓ escopo, se tiver permissão | ver | — | ver as suas |
| Ver ledger e contas a receber | ✓ | ✓ escopo | ✓ escopo | — | ✓ suas reservas |
| Extrato do fundo regional | ✓ | ✓ o do seu nó | ver resumo | — | — |
| Administrar termos | ✓ | — | — | — | — |
Itens sem permissão ficam ocultos se o papel nunca terá acesso, ou desabilitados com explicação quando dependem de delegação (spec-02).

## 1. Recebedores (no nó da árvore): UC-A15 a UC-A17
**Objetivo:** cadastrar quem recebe, com identidade verificada e chave segura.
**Lista do nó:** nome, PF ou PJ, documento mascarado (`***.456.789-**`), tipo (proprietário, gestor/franqueado, parceiro, casalivre, fundo regional, prestador de limpeza, afiliado, eventual), selo de **KYC** e selo da chave.
**Formulário:** PF (CPF) ou PJ (CNPJ), com máscara e validação dos dígitos. Também nome ou razão social, **regime tributário** (MEI, Simples, Lucro Presumido, Lucro Real; para a NFS-e futura), e os dados bancários (banco, agência, conta) e/ou a **chave Pix**.
**KYC no piloto** *[E]*:
- A análise é **manual**, e **só o admin_plataforma aprova**. O franqueado (admin_org ou gestor do território) **não tem acesso aos documentos**: vê só o **status** e o **resultado das validações automáticas** (dígito, Receita para CNPJ, titular da chave) dos recebedores do território, e não aprova *[E] (Negócios); acesso restrito [decidido, Segurança 05-2; parecer J4, art. 46]*.
- **SLA de 2 dias úteis.** A tela mostra "Em análise · resposta até [data]".
- **Validações automáticas antes da análise:** dígito verificador do CPF/CNPJ, **situação na Receita só para CNPJ** (BrasilAPI: "Situação: ativa"; **o CPF não é enviado à BrasilAPI**: no piloto vale só o dígito verificador mais o KYC manual, [decidido, parecer J7]) e **titular da chave Pix igual ao documento**. Uma falha aqui volta direto para Pendente, com o motivo.
- **Estados:** Pendente, Em análise, **Pendência** (com o motivo, como "Comprovante com mais de 90 dias", e o botão "Reenviar documentos"), Aprovado e Recusado. O status grava a **fonte da verificação** ("manual" ou "PSP"), porque o PSP assume o KYC na P6.
- **Documentos por tipo** *[E]*:
| Tipo | Documentos |
|---|---|
| PF | documento com foto, selfie, comprovante de endereço de até 90 dias, conta ou chave Pix no mesmo CPF |
| PJ | cartão CNPJ, contrato social, documento com foto e selfie do sócio-administrador, conta ou chave no CNPJ |
| MEI | CCMEI, documento com foto e selfie do titular, conta ou chave no CNPJ ou no CPF do titular |
| Corretor-franqueado e parceiro | o do tipo dele (PF ou PJ), mais o **campo CRECI com status de verificação** (**Verificado**, **Em verificação**, **Não verificado**, **Inativo**; *confirmados pelo Produto*). **Só "Verificado" permite operar como franqueado ou parceiro**: com os outros status, o recebedor não pode ser vinculado como franqueado/parceiro e a tela mostra o bloqueio com o motivo *(requisito de tela, Produto; regras fiscais com o Jurídico e Tributário, A.5)*. Do CRECI, guarda-se só o **número, a data e o resultado da consulta** *[decidido, parecer J8]* |
| Prestador de limpeza | simplificado: PF ou MEI |
O **vínculo do proprietário com o imóvel** (escritura, IPTU ou contrato) não faz parte do KYC: é pedido na publicação do anúncio (spec-03).
O upload dos documentos usa o mesmo componente do comprovante. **Só um recebedor Aprovado pode ser vinculado a um imóvel publicado** (spec-03). **No piloto, publicar com pagamento por fora exige também a chave Pix vinculada a esse recebedor Aprovado** ([decidido, parecer item 17]), e o hóspede vê o canal "Denunciar anúncio ou pedido de pagamento suspeito" no checkout (spec-01) e no detalhe da reserva (spec-09).
**Chave Pix (regras da spec-02, seção 4):** o titular precisa ser igual ao CPF/CNPJ do recebedor (senão, "A chave não pertence a [nome]"); **OTP** para quem altera; **aviso imediato ao proprietário**; **carência de 48 h** com o status **"Pendente até [dd/mm, hh:mm]"**, e só depois a chave vale para **reservas novas**; trilha de auditoria (quem, quando, IP, valor anterior e novo, mascarados). O gestor só altera com delegação explícita e nunca sozinho a chave do proprietário. Durante a carência, o proprietário pode clicar em "Não reconhece? Cancelar alteração". **Complementos 05-5 e 05-6** (seção de Segurança): senha antes do OTP, OTP no canal já cadastrado, bloqueio de 48 h depois de trocar contato ou senha, 1 troca pendente por vez, OTP do proprietário quando quem troca é o gestor, e **troca desabilitada até a D3, sem paliativo**.
**Estados:** CPF ou CNPJ inválido (erro inline); **documento duplicado** ("Não foi possível cadastrar. Fale com o suporte.", uma mensagem genérica por Segurança); OTP incorreto ou expirado; KYC recusado.
**Critérios de aceite**
1. Um CPF ou CNPJ com dígito inválido é recusado inline. Um documento duplicado mostra só a mensagem genérica.
2. Sem OTP, a chave não é salva (o servidor responde 403). Com um titular diferente, a chave é recusada (422).
3. Uma chave em "Pendente até" não aparece em nenhuma instrução de pagamento. Reservas existentes mantêm a chave do snapshot.
4. O proprietário recebe o aviso em toda alteração e pode cancelar durante a carência.
5. O gestor sem delegação vê o item desabilitado com a explicação, e o servidor responde 403.
6. Um recebedor que não está Aprovado não pode ser vinculado a um anúncio publicado, e o checklist da spec-03 aponta isso.
7. O franqueado vê só o status e o resultado das validações, sem nenhum documento, e não vê o botão Aprovar. O status mostra a fonte (manual ou PSP) e o prazo do SLA.
8. Uma falha nas validações automáticas (dígito, Receita para CNPJ, titular da chave) volta para Pendente com o motivo, antes de chegar à análise. Nenhuma requisição à BrasilAPI contém CPF (J7).

## 2. Regras de split: UC-A18, UC-A19
**Objetivo:** configurar quem recebe quanto, por nó, imóvel e canal, sem deploy e sem mexer no passado.
**Lista:** regras por escopo (nó, imóvel) e por **canal**, cada uma com a versão, a vigência e o status (**Rascunho**, **Agendada**, **Vigente**, **Encerrada**).
**Editor:** linhas de regra, cada uma com o recebedor, o **emissor da cobrança** (casalivre, franqueado/parceiro ou outro recebedor; configurável por modelo; é ele quem fatura a parcela, apêndice A), o **tipo** (**percentual**, **valor fixo**, **sobre item**: diárias, limpeza ou taxa de serviço) e o **mínimo** opcional. Também o canal (todos ou um específico) e a **vigência a partir de** [data] (pode ser futura).
**Versionamento:** **uma versão vigente nunca é editada.** "Editar" cria uma nova versão em rascunho ("Nova versão a partir da v3"). Publicar com data futura deixa a versão como Agendada. As reservas gravam a versão aplicada (snapshot) e não mudam com versões novas.
**Validação:** a soma dos percentuais sobre a mesma base não pode passar de 100% ("Os percentuais somam 104%. O máximo é 100%."). Há também o aviso de **conflito** quando duas regras cobrem o mesmo escopo, canal e vigência ("Já existe uma regra vigente para este canal a partir de 01/11"), e a validação dos mínimos maiores que o valor.
**Simulador:** escolher uma reserva de exemplo (imóvel, canal, datas, valor) ou digitar os valores. O resultado é uma tabela recebedor → valor (diárias, limpeza fora do split, taxa), com **total = soma, saldo zero** ("Para R$ 1.000: proprietário R$ 940,00 · casalivre R$ 60,00 · diferença R$ 0,00").
**Arredondamento** *[E]*: as parcelas dos outros recebedores são arredondadas para baixo, e o **centavo que sobra vai para o proprietário**. Exemplo: com R$ 333,33 e 94/6, a casalivre recebe R$ 19,99 (6% = 19,9998) e o proprietário R$ 313,34, total R$ 333,33 e diferença R$ 0,00.
**Emissor nos cenários:** no **franquia, canal direto** (900/40/30/30), **o corretor (4%, R$ 40) mais o fundo (3%, R$ 30) = R$ 70 são cobrados pelo franqueado**, que repassa os R$ 30 ao fundo. O **royalty (3%, R$ 30)** aparece **só na visão e no extrato do franqueado**, nunca na visão do proprietário *(requisito de tela, Produto; regras fiscais com o Jurídico e Tributário)*. O simulador mostra a coluna "Emissor" e um subtotal por emissor.
**Cenários de canal OTA** *[E]*: franquia via OTA, com R$ 1.000 de diárias. Corretor 4% = R$ 40,00, royalty 2% = R$ 20,00 (a variação de 3% depende da D12), fundo 2% = R$ 20,00 e proprietário R$ 920,00. A taxa da OTA é cobrada pela própria OTA, fora do split.
**Taxa de serviço do hóspede maior que 0%** *[E]*: o simulador mostra a **base de cálculo**. Exemplo: "Taxa de serviço 5% sobre as diárias (R$ 1.000,00) = R$ 50,00; a limpeza não entra na base." O hóspede paga R$ 1.050,00 mais a limpeza, e os R$ 50,00 vão ao recebedor configurado.
**Critérios de aceite**
1. Percentuais acima de 100% são bloqueados (422), com a mensagem.
2. Uma regra de valor fixo e uma com mínimo calculam certo no simulador (valores conhecidos), e o simulador sempre fecha em zero.
3. Os 4 cenários da D2 aparecem como modelos iniciais e reproduzem 940/60, 930/40/30, 900/40/30/30 e 900/50/50. Também estão disponíveis o cenário OTA (40/20/20/920), o caso de R$ 333,33 (que fecha em zero, com o centavo para o proprietário) e o caso com taxa de serviço, mostrando a base.
4. Uma versão vigente não tem botão de edição. A edição gera uma nova versão, e as reservas antigas mantêm a versão delas.
5. A mesma reserva em canais diferentes aplica a regra de cada canal no simulador.
6. Trocar um percentual e publicar muda o resultado das reservas novas sem deploy.

## 3. Ledger e contas a receber: UC-A20, UC-A22, UC-P30
**Objetivo:** ver, por reserva, quanto cada um tem a receber e o que já foi recebido.
**Visão por reserva:** código, canal, imóvel, datas e os lançamentos (partidas dobradas) por recebedor e por item. Os status são **Previsto** (reserva pendente), **A receber**, **Recebido** e **Estornado**. O link "Marcar como pago" leva à spec-02, com as confirmações **separadas de diária e de limpeza**, e cada uma gera o seu lançamento.
**Visão geral:** tabela com filtros (período, nó, canal, recebedor, status, tipo: comissão, taxa de serviço, mensalidade, conta a receber), com os totais por status no topo. **Exportar** *[E]*: em **CSV e PDF**, com o extrato e o **informe anual para o IR** do proprietário. OFX fica como porta futura. Cada recebedor exporta só o próprio extrato. O admin_org e o gestor exportam o escopo do nó, inclusive o extrato do fundo regional. O admin_plataforma exporta tudo, e o operador não exporta nada. Toda exportação fica no log.
**Imutável:** sem editar nem excluir. Uma correção só entra por **estorno** com justificativa ("Estornar lançamento" gera uma linha inversa).
**Estados:** sem lançamentos ("Nenhum lançamento no período"); carregando; erro; lançamento estornado (selo com texto e link para o estorno).
**Critérios de aceite**
1. Nenhuma linha pode ser editada ou apagada. O estorno cria uma linha nova vinculada à original.
2. Marcar a diária e a limpeza gera dois lançamentos independentes, que mudam de A receber para Recebido.
3. É possível registrar uma mensalidade (SaaS) ou uma conta a receber sem reserva (teste de porta).
4. A soma de cada reserva fecha em zero.
5. A exportação respeita o escopo e fica no log.

## 4. Extrato do fundo regional: UC-A21
**Origem dos valores:** as entradas vêm dos **repasses feitos pelo franqueado** (o fundo não recebe da casalivre). *(Negócios; S8+)*
- **Extrato em tempo real por franqueado da região:** cada repasse do franqueado e cada saída aparece na hora, com o saldo atualizado.
- **Relatório trimestral (PDF e CSV):** **saldo inicial**; **entradas por franqueado e por reserva**; **despesas de mídia com as notas fiscais anexadas**; **resultado das campanhas** (investimento, cliques, reservas atribuídas ao canal direto, custo por reserva); **saldo final**.
- **Relatório anual consolidado.**
- Prazo: **S8 ou depois**.
- Os relatórios são gerados no servidor, ficam no log e seguem as regras de exportação da seção 3.
**Quem vê:** o admin_plataforma vê todos, o admin_org vê o do seu nó, e o gestor vê só o resumo.
**Layout:** saldo atual em destaque ("Saldo: R$ 12.340,00 em 16/10"), filtro de período, e uma tabela de entradas e saídas (data, origem/reserva, descrição, valor, saldo). A exportação segue a regra da seção 3: o admin_org e o gestor exportam o escopo do nó, e o admin_plataforma exporta tudo.
**Critérios de aceite:** o saldo bate com a soma dos lançamentos do período; o filtro de período atualiza o saldo e anuncia o novo valor; nenhum dado pessoal de hóspede aparece.

## 5. Termos: UC-A23, UC-A24
**Termos:** **Contrato de locação por temporada**, **Termo de serviço de limpeza** e **Termos de uso da plataforma**. Os textos são o placeholder "[Texto, D14]".
**Versões:** cada uma com status (**Rascunho**, **Publicada/Agendada**, **Vigente**, **Encerrada**), vigência a partir de [data], autor e notas. Há um **diff entre versões** (lado a lado, com as mudanças marcadas por texto e não só por cor: "+ incluído", "− removido").
**Novo aceite:** ao publicar, a pessoa escolhe se a mudança **exige novo aceite** (de hóspedes com reservas futuras ou de anfitriões) ou se vale só para reservas novas.
**Aceites por reserva:** em cada reserva, os 3 termos com a versão, a data e hora e a origem (canal) do aceite. A busca é por código de reserva.
**Critérios de aceite**
1. Publicar uma nova versão não altera os aceites já gravados.
2. O checkout (spec-01) sempre usa a versão vigente no momento e grava a versão aceita.
3. Uma versão vigente não é editada; a edição gera um rascunho.
4. O diff fica legível por leitor de tela, com as mudanças anunciadas como "incluído" e "removido".

## 6. WCAG 2.2 AA
- Tabelas de dados com `<caption>`, cabeçalhos de linha e de coluna, e ordenação anunciada (`aria-sort`) (1.3.1).
- Valores em formato brasileiro ("R$ 1.234,56"), alinhados à direita e com algarismos tabulares. Para o leitor de tela, o valor é "mil duzentos e trinta e quatro reais e cinquenta e seis centavos". Valores negativos e estornos usam o sinal "−" e o texto "estorno", não só cor (1.4.1).
- Totais e saldos atualizados são anunciados por role=status (4.1.3).
- Erros de validação (soma acima de 100%, conflito, CPF) em texto, com um resumo e links (3.3.1, 3.3.3). Publicar uma regra ou um termo pede confirmação (3.3.4).
- OTP com `autocomplete="one-time-code"` e possibilidade de colar (3.3.8).
- Dados sensíveis mascarados, com "Mostrar" só para quem pode e com registro no log.
- Sem rolagem horizontal a 320 px na consulta mobile: as tabelas viram cards (1.4.10).

## Requisitos de Segurança/LGPD (parecer de 16/10/2026)
Fonte: `/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`. O texto técnico de cada achado foi **transcrito sem alteração**. O mecanismo é com Arquitetura.
**Decisões de tela (Produto):** (a) Nova linha na tabela de permissões, **"Ver documentos KYC"**: só o **admin_plataforma** vê o documento completo. **O franqueado não tem acesso aos documentos**, só ao status e ao resultado das validações *[decidido, Segurança 05-2; parecer J4]*. Toda visualização fica no log (05-2). (b) **Aviso na captura da selfie, sem pedido de consentimento** ([decidido, parecer J4]: art. 11 II "g"): "Sua selfie é um dado biométrico. Usamos só para prevenir fraude e confirmar sua identidade. Só a equipe de verificação da casalivre vê, e ela é apagada 30 dias depois da decisão. [Saiba mais]" (05-1). Não há checkbox de consentimento. (c) **Upload só de JPEG, PNG ou PDF**, com EXIF e GPS removidos. A mensagem de recusa é "Formato não aceito. Envie JPEG, PNG ou PDF." (05-4). (d) **Troca de chave Pix** (05-5): **senha antes do OTP**; o **OTP vai para o canal já cadastrado do titular**; **trocar e-mail, celular ou senha bloqueia a troca da chave por 48 h** ("Por segurança, você poderá alterar a chave em [data]"); o aviso vai por todos os canais, com **"Não reconhece? Cancelar" funcionando sem login**; **só 1 troca pendente por vez** ("Já existe uma alteração pendente até [data]"); **a troca feita pelo gestor exige o OTP do proprietário** ("Aguardando confirmação do proprietário"). (e) A **troca de chave Pix fica desabilitada até a D3**, **sem o paliativo do link** (05-6): "Disponível quando os avisos por e-mail e WhatsApp estiverem ativos. Para corrigir uma chave agora, fale com o suporte." **Até a D3, uma correção de chave só é feita pelo suporte do admin_plataforma**, com **chamada de vídeo ao vivo**, segregação entre quem atende e quem aprova, carência de 7 dias e no máximo 1 correção a cada 30 dias (ver "Refinamento", P3) *[E] [pendente: Jurídico e Tributário + Segurança]*. A tela do suporte tem os campos: motivo, data e hora da videochamada, resultado da verificação e quem fez. O aviso ao proprietário segue a regra quando houver canal. (f) **Falha da BrasilAPI** leva o recebedor para **análise manual** ("Não conseguimos consultar a Receita agora. Seu cadastro segue para análise manual."), e nunca há aprovação automática (05-8). (g) **"Mostrar" um dado mascarado exige um motivo**, e fica no log (05-10). (h) **Exportar o escopo inteiro exige reautenticação** (05-9). (i) **Retenção** *[E] [pendente: Jurídico e Tributário + Segurança]* (05-3): a **selfie e a prova de vida** são **apagadas 30 dias depois da decisão**. Os **documentos de identidade e da empresa** ficam **cifrados durante o relacionamento e por mais 5 anos contados do `ended_at`** (os recusados não; ver "Refinamento", P1), acessíveis **só ao admin_plataforma**, com **log de cada acesso**. Do **CRECI**, fica só o número, a data e o resultado da consulta.

- **05-1** (**Alta**; no parecer: spec-05:24-38): **A selfie (e a comparação dela com a foto do documento) é dado biométrico, portanto dado pessoal sensível (LGPD art. 5º II, art. 11).** A spec não diz nada de base legal, armazenamento, acesso nem retenção. Exigir: (a) base no art. 11 II (a validar com jurídico, provavelmente "prevenção à fraude e segurança do titular", al. g), e transparência no aviso de privacidade; (b) arquivos **fora do webroot** (`storage/kyc/`, nunca em `public/`), com nome aleatório, servidos só por um controlador autenticado, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` e `Cache-Control: no-store`; (c) **cifrados em repouso** no nível da aplicação (libsodium `secretstream`/`crypto_aead`, chave fora do repo e do DB, em arquivo 0400 ou variável de ambiente, com rotação documentada); (d) RIPD/DPIA (art. 38).
- **05-2** (**Alta**; no parecer: spec-05:8-17, :25): **Acesso aos documentos KYC não está definido.** A tabela de permissões (spec-05:8-17) não tem a linha "ver documentos KYC". Proposta: só o admin_plataforma vê o documento completo. O franqueado que pré-verifica (spec-05:25) vê só o necessário (pré-visualização com marca d'água "uso CasaLivre KYC + nome + data"), ou nada, a decidir. Cada visualização e download fica no log (autor, recebedor, motivo). Sem download em lote.
- **05-3** (**Alta**; no parecer: spec-05:28): **Retenção dos documentos KYC não definida.** Proposta: selfie e documentos **eliminados X dias após a decisão** (ex.: 30 dias depois de Aprovado/Recusado; a validar com jurídico e Negócios), mantendo só o resultado (status, fonte manual/PSP, data, quem aprovou, hash do arquivo). Na P6, quando o PSP assumir o KYC, apagar os documentos guardados. Recusados: purga em 30 dias. Exclusão de conta: entra na purga.
- **05-4** (Média; no parecer: spec-05:38): Upload KYC "usa o mesmo componente do comprovante" (spec-05:38): exigir lista de tipos permitidos (JPEG/PNG/PDF, verificados por magic bytes), limite de tamanho, **remoção de EXIF/GPS** nas imagens, PDF sem renderizar no servidor (ou isolado), sem SVG nem HTML. Antivírus opcional.
- **05-5** (**Alta**; no parecer: spec-05:12, :39, :43-45): **Troca de chave Pix, anti-takeover.** O OTP vai **para o canal do proprietário/titular já cadastrado**, nunca para um contato alterado na mesma sessão. Exigir também: (a) **reautenticação** (senha) antes do OTP; (b) uma troca de e-mail/celular/senha da conta **reinicia** a carência ou bloqueia a troca da chave por 48 h (padrão de takeover: muda o contato e depois a chave); (c) aviso por **todos** os canais (e-mail e celular), com o link "Não reconhece? Cancelar", que funciona **sem login** via token de uso único e então trava a conta para revisão; (d) OTP de 6 dígitos, validade de 5 a 10 min, no máximo 5 tentativas, rate limit por usuário e IP, guardado como hash, uso único; (e) no máximo 1 troca pendente por recebedor; (f) **quando o gestor altera com delegação, a confirmação do proprietário é obrigatória**, e o gestor sozinho não basta (a spec diz "nunca sozinho", spec-05:12, :39; explicitar que o OTP vai para o proprietário).
- **05-6** (Média; no parecer: spec-05:39; spec-06:92): **Dependência da D3:** sem um provedor de e-mail/SMS (spec-06:92), não há OTP nem aviso imediato. **Condição: a troca de chave Pix fica desabilitada até a D3**. Não aceitar um paliativo de link copiável para Pix.
- **05-7** (Média; no parecer: spec-05:27, :39): "Titular da chave igual ao documento" (spec-05:27, :39): definir a **fonte** dessa verificação. A consulta DICT só é feita por um PSP ou instituição. Se a validação for manual (pelo comprovante), documentar isso. Trocar para a chave de outro titular deve ser impossível na API, não só na UI (422, aceite 2).
- **05-8** (Média; no parecer: spec-05:27, :109): **BrasilAPI:** é uma chamada externa. Exigir: host fixo `https://brasilapi.com.br` (sem URL vinda do usuário, sem seguir redirect para outro host, regras de SSRF do projeto), timeout curto, enviar só o CNPJ/CPF necessário, tratar a indisponibilidade como "em análise manual" (nunca aprovar automaticamente), e logar sem o documento completo. Avaliar com o jurídico o compartilhamento de CPF com um terceiro (consulta de CPF pode exigir data de nascimento; confirmar o que a API aceita).
- **05-9** (Média; no parecer: spec-05:72, :84-85): **Exportação do ledger e do informe de IR** (spec-05:72): CSV injection (mesma regra de 04-4), escopo verificado no servidor por recebedor e nó, **dados pessoais mínimos** (CPF/CNPJ mascarado, exceto no informe de IR do próprio titular), PDF gerado sem buscar recursos externos (sem SSRF no gerador), reautenticação para o export do escopo inteiro, log de cada exportação (já previsto), sem guardar o arquivo em disco público. O extrato do fundo não tem dado de hóspede (spec-05:85, ok).
- **05-10** (Média; no parecer: spec-05:11, :104): O "Mostrar" dado mascarado (spec-05:104) dá aos admins um caminho para ver o CPF completo, o que contradiz a tabela (spec-05:11: admins sempre mascarado). Definir quem pode e exigir o motivo, com registro no log.
- **05-11** (Baixa; no parecer: spec-05:40): "Documento duplicado" com mensagem genérica (spec-05:40) está correto contra enumeração. Garantir o mesmo tempo de resposta e alertar o admin_plataforma (possível fraude).
- **05-12** (Baixa; no parecer: spec-05:23): CPF/CNPJ e dados bancários em repouso: cifrar a coluna (ou no mínimo guardar o hash para a unicidade mais o valor cifrado), porque o vazamento do DB/backup expõe tudo. O regime tributário é um dado fiscal, sem ser sensível.
- **05-13** (Info; no parecer: spec-05:73, :91): Ledger imutável com estorno (spec-05:73) está correto. Garantir no PG com REVOKE UPDATE/DELETE para o usuário da app nas tabelas do ledger (para Arquitetura: o mecanismo). Termos e aceites (spec-05:87-96): guardar versão, hash do texto, data e IP do aceite. O IP segue a regra de 6 meses, ou fica com o aceite pelo prazo do contrato (validar com jurídico).

**Critérios de aceite de Segurança**
1. (05-1) A tela de captura da selfie mostra o aviso de privacidade antes de abrir a câmera. Nenhum documento é acessível por URL pública.
2. (05-2) Só o admin_plataforma vê o documento completo. Cada visualização registra autor, recebedor e motivo. Não existe download em lote.
3. (05-3) 30 dias depois da decisão, a selfie e a prova de vida não existem mais ("Selfie eliminada em [data]"). Os documentos de identidade e da empresa continuam acessíveis só ao admin_plataforma, até o fim do relacionamento + 5 anos, com log de cada acesso.
4. (05-4) Arquivos fora de JPEG, PNG ou PDF são recusados com a mensagem. A imagem guardada não tem EXIF ou GPS.
5. (05-5) A troca pede a senha e depois o OTP no canal já cadastrado. Uma troca de contato ou de senha nas últimas 48 h bloqueia a troca da chave, com a data. "Cancelar" funciona sem login e trava a conta para revisão. Uma segunda troca pendente é recusada. A troca feita pelo gestor só conclui com o OTP do proprietário.
6. (05-6) Sem a D3, o botão de trocar a chave fica desabilitado com a explicação, e não existe link copiável.
7. (05-7) A fonte da verificação de titularidade é mostrada (manual ou PSP). Uma chave de outro titular é recusada pela API (422).
8. (05-8) Com a BrasilAPI indisponível, o status vira "Em análise manual".
9. (05-9) A exportação do escopo inteiro pede reautenticação. O CPF/CNPJ sai mascarado, exceto no informe de IR do próprio titular.
10. (05-10) "Mostrar" pede o motivo, e o evento aparece no log.
11. (05-11) Um documento duplicado mostra a mensagem genérica, e o admin_plataforma recebe um alerta.
12. (05-12, 05-13) Requisitos de armazenamento e de banco, sem impacto de tela. São verificados por Arquitetura e Banco de Dados. Cada aceite de termo mostra versão, data e hash.

**Questões jurídicas** (decididas no `juridico/parecer-J1-J35.md`):
- [decidido, parecer J4] **J4**: A base legal para a selfie/biometria (art. 11 II "g" da LGPD?), se o consentimento é necessário e a redação do aviso. **Validar com jurídico.** → **Decisão:** base no art. 11 II "g", **sem consentimento**; **aviso na coleta** (finalidade, quem vê, prazo de 30 dias); RIPD.
- [decidido, parecer J5] **J5**: O prazo de guarda dos documentos KYC após a decisão e após o fim do vínculo. Existe obrigação de guardar o documento (e não só o resultado)? Pontos a verificar: regras de PLD/COAF para intermediação imobiliária e Resolução COFECI para corretores. **Validar com jurídico.** → **Decisão:** selfie **30 dias**; resultado e metadados por **relação + 5 anos**; imagem do documento por relação + 5 anos (aceitável) ou + 2 anos (recomendado), **decidido com a Segurança: relação + 5 anos** (16/10); recusados: imagens em 30 dias, resultado por 6 meses (5 anos se fraude).
- [decidido, parecer J6] **J6**: Os prazos de guarda do ledger, dos informes de IR e dos comprovantes (fiscal/contábil, normalmente 5 anos; confirmar). → **Decisão:** **5 anos contados de 1º de janeiro do exercício seguinte**; na prática, **6 anos corridos**.
- [decidido, parecer J7] **J7**: O compartilhamento de CPF/CNPJ com a BrasilAPI e, no futuro, com o PSP (operador/controlador, contrato). → **Decisão:** CNPJ na BrasilAPI é permitido; **CPF não**: no piloto, só o dígito verificador + KYC manual; o PSP assume na P6, com contrato.
- [decidido, parecer J8] **J8**: O CRECI e a consulta ao conselho: base legal. → **Decisão:** permitido; guardar só o número, a situação e a data da consulta.

## Refinamento de Segurança: prazos do KYC e correção de Pix pelo suporte (16/10)
Fonte: relatório de Segurança (`/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`), seção "Prazos do Negócios". O texto técnico foi transcrito sem alteração.
**Decisões de tela (substituem as anteriores onde houver conflito)** *[decidido, parecer J5, J20, J22; Segurança]*:
- **Selfie e prova de vida:** apagadas **30 dias depois da decisão** ([decidido, parecer J5; Segurança]) (Aprovado ou Recusado), **ficando só o resultado e o hash**.
- **Legal hold:** qualquer hold ativo **suspende a purga** de todos esses itens; o dado só volta à purga quando todos os holds forem encerrados ([decidido, parecer item 49]). Uma pendência reinicia a contagem (P1-1).
- **Recusados** ([decidido, parecer J5/J20]): as **imagens são apagadas em 30 dias**; o **resultado e o motivo** ficam **6 meses**, ou **5 anos se a recusa for por fraude** (e vai para a lista de bloqueio) (P1-2).
- **Fim do relacionamento:** é um **evento registrado** (`ended_at`: último pagamento, desvinculação ou exclusão), e os **+5 anos contam a partir dele** para as **imagens dos documentos de identidade e da empresa e o resultado do KYC** ([decidido com a Segurança, 16/10; parecer J5 considera aceitável, art. 7º VI e IX]). A tela do recebedor mostra "Relacionamento encerrado em [data] · documentos guardados até [data + 5 anos]" (P1-2). Depois de 90 dias sem relacionamento ativo, os documentos vão para armazenamento frio, acessível só ao admin_plataforma com motivo e log (P1-3).
- **Correção de Pix pelo suporte até a D3** (P3):
  - É usada só quando o titular perdeu o acesso ao canal de OTP.
  - **Chamada de vídeo ao vivo iniciada pela plataforma**, nunca um vídeo gravado.
  - **Quem atende a chamada não aprova a troca.** A segregação aparece na tela: "Atendido por [A] · aguardando aprovação de outro administrador".
  - **Carência de 7 dias** até a chave nova valer, com aviso em todos os canais e "Não reconhece? Cancelar" sem login.
  - **No máximo 1 correção a cada 30 dias** por recebedor. Uma segunda tentativa trava o recebedor para revisão.
  - O vídeo **não é gravado** ([decidido, parecer J22]). Se um dia for gravado: aviso na chamada, 30 dias, cifrado.
  - O log registra quem atendeu, quem aprovou, o motivo, os dados mascarados e o ticket.

**Critérios de aceite:** (1) a selfie some 30 dias depois da decisão e ficam só o resultado e o hash; (1b) com legal hold ativo, nenhum item KYC é purgado; (2) os documentos de um recusado não aparecem com prazo de 5 anos; (3) `ended_at` aparece na tela e o prazo de 5 anos é calculado a partir dele; (4) a correção pelo suporte não conclui com uma só pessoa; (5) a chave corrigida mostra "Pendente até [data + 7 dias]"; (6) uma segunda correção em 30 dias é bloqueada e o recebedor fica em revisão.

- **P1-1** (Info): A selfie em 30 dias está coerente com a minimização (05-3). Contar **a partir da decisão** (Aprovado/Recusado), não do upload. Uma pendência reinicia a contagem. Guardar só o resultado (status, fonte, quem aprovou, hash).
- **P1-2** (Média): **Documentos durante a relação + 5 anos:** é um prazo longo para dado de identificação. Precisa de base legal confirmada (J5: PLD/COAF, COFECI, fiscal). Sem obrigação confirmada, guardar **só o resultado e os metadados**, não a imagem. Recusados: não precisam de 5 anos (sugestão: 30 dias, ou 6 meses para fraude; validar). "Fim da relação" precisa de um evento definido no sistema (`receivers.ended_at`: último pagamento, desvinculação ou exclusão).
- **P1-3** (Média): **Controles durante os 5 anos:** cifrados (05-1), fora do webroot, chave separada; depois de 90 dias sem relação ativa, mover para armazenamento frio, acessado só pelo admin_plataforma com motivo e log. Purga automática no vencimento (cron diário, idempotente, log com a contagem). Backups de 35 dias propagam a purga. A exclusão de conta **não apaga** os documentos nesse prazo, mas os bloqueia (art. 16 I), e isso aparece no aviso de privacidade.
- **P1-4** (Baixa): A selfie com 30 dias **conflita** com documentos por 5 anos quando o documento com foto é a prova do KYC. Confirmar que, em caso de contestação depois de 30 dias, basta o documento mais o hash da selfie (J5).
- **P3-1** (**Alta**): Só usar quando o titular perdeu o acesso ao canal de OTP. Exigir: **chamada de vídeo ao vivo** (não um vídeo gravado enviado, que é fácil de falsificar ou deepfake), feita pelo admin_plataforma (não pelo franqueado/gestor), comparando com o documento e a selfie do KYC **ainda guardados** (lembrar que a selfie some em 30 dias, P1: definir com o que comparar), mais perguntas sobre dados que só o titular sabe (reservas recentes, valores).
- **P3-2** (**Alta**): **Dupla aprovação** (quem atende ≠ quem aprova) e **carência maior** (sugestão: 7 dias, contra 48 h), com aviso em **todos** os canais antigos e novos e o link "Não reconhece? Cancelar" (sem login). A nova chave continua com titular igual ao recebedor (422) e só vale para reservas novas. Pagamentos pendentes de valor alto ficam retidos até o fim da carência.
- **P3-3** (Média): **O vídeo é dado biométrico** (art. 11): de preferência **não gravar**. Se gravar, guardar cifrado, com acesso só do admin_plataforma, purga em 30 dias (ou até o fim de uma contestação) e base legal (J4). O log registra: quem atendeu, quem aprovou, o motivo, os dados mascarados antes e depois e o ticket.
- **P3-4** (Média): Rate limit: no máximo 1 correção por suporte a cada 30 dias por recebedor. Uma segunda tentativa trava o recebedor para revisão. Treinar o suporte contra engenharia social (urgência, pressão). Sem exceção por telefone ou WhatsApp.

- [decidido, parecer J20] **J20**: A base legal para guardar os documentos KYC durante a relação + 5 anos (J5) e o tratamento dos recusados. → **Decisão:** ver o J5.
- [decidido, parecer J22] **J22**: A gravação ou não do vídeo do suporte (biometria) e o prazo. → **Decisão:** **não gravar**; se gravar, 30 dias, cifrado e com aviso.

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Rafael] D3. A troca de chave Pix fica desabilitada até lá (05-6).
2. [pendente: Rafael] D2 e D12: números finais do split e do canal OTA (royalty de 2% ou 3%).
3. [pendente: Rafael + Jurídico e Tributário] D14: textos dos 3 termos e o critério para exigir novo aceite.
4. [pendente: Arquitetura e Banco de Dados] Mecanismos do 05-1, 05-12 e 05-13 (cifragem, REVOKE, armazenamento fora do webroot).
5. [pendente: Jurídico e Tributário] Intermediação × corretagem (Lei 6.530) e o papel dos franqueados corretores (parecer itens 5 e 8).
6. [pendente: Negócios] Revisão dos itens [E].

---
## Apêndice A: Fatura mensal de comissão (P9, piloto D11; S8)
Fontes: `backlog.md` P9 (critérios 1 a 3), `roadmap.md` (piloto D11: comissão como conta a receber do proprietário, fatura mensal, vale até a P6), `pagamentos.md` item 5 e `decisoes-rafael.md` D11. Ligado ao ledger da seção 3. Casos de uso: UC-A20, UC-A22, e o UC-A25 proposto (Gerar e dar baixa na fatura mensal), a acrescentar em `casos-de-uso-admin-proposta.md`.

**Objetivo:** cobrar a comissão das reservas pagas "fora" num documento mensal claro, e dar baixa no recebível.

**Decisões** (Negócios [E]; [decidido, parecer itens 13 a 16]; correção do Produto sobre o fundo):
- **Faturas separadas por emissor** (parecer item 13): **a casalivre fatura só a parte dela** (comissão/royalty), e **o franqueado ou parceiro fatura a parte dele**. No modelo franquia, a cobrança do franqueado é de **7%: 4% do corretor + 3% do fundo regional**, com **NFS-e do franqueado**, e é **o franqueado que repassa os 3% ao fundo**. **A casalivre não retém o valor do fundo e não atua como mandatária**: não há subconta segregada nem cobrança "em nome do franqueado".
- **Extrato consolidado do proprietário:** **uma tela mensal** reúne as cobranças **agrupadas por emissor** (ex.: "Franqueado Rede Lagos · R$ 70,00 · Ver fatura"). **Por emissor, a tela mostra: nome, valor e o link da fatura**, além de vencimento, status e pagamento *(requisito de tela, Produto; regras fiscais com o Jurídico e Tributário)*. **Nenhuma tela do proprietário mostra royalty**: o royalty aparece só no extrato do franqueado. O gestor do nó recebe uma cópia da cobrança do nó.
- **NFS-e:** cada emissor emite só sobre a sua parcela; natureza, emissor e tomador por modelo, royalty, fundo, CRECI, IBS/CBS, CPF do tomador e guarda estão na **parte fiscal (A.1 a A.10)**, abaixo.
- **Vencimento em 10 dias.** Em atraso: **multa de 2% + juros de 1% ao mês, pro rata die, + correção pelo IPCA** (item 15).
- **Escalonamento do atraso** (por cobrança, com aviso prévio no contrato):
  - **D+1:** lembrete.
  - **D+5:** aviso ao gestor.
  - **D+15:** os anúncios **deixam de aceitar reservas novas**. As confirmadas são mantidas.
  - **D+30:** o pagamento por fora fica **desabilitado** para esse proprietário.
- **Porta futura:** débito automático e Pix Automático.

**Fechamento do mês** (admin_plataforma; o admin_org só vê o escopo dele):
- A prévia **por proprietário × emissor** mostra as reservas `fora` **concluídas** no mês e, para cada parcela, o **emissor da cobrança** (regra do split, seção 2), e o total por emissor. Reservas canceladas antes do check-in **não entram** (P9-3).
- "Fechar mês" abre a confirmação "Gerar 14 faturas da casalivre (R$ 1.140,00). As cobranças dos franqueados são geradas no escopo de cada franqueado.". Depois do fechamento, as reservas do mês ficam travadas. Um ajuste posterior só entra como **lançamento de estorno ou de ajuste** na fatura seguinte.

**Fatura:**
- Conteúdo (uma fatura por emissor): número, emissor, período, proprietário (nome, CPF/CNPJ mascarado), uma tabela por imóvel com as reservas (código, datas, valor da estadia e **só as parcelas cujo emissor é quem fatura**), o total, o vencimento e as instruções de pagamento. A fatura é exportada em PDF e CSV (05-9).
- **Status:** Aberta → Paga / Parcialmente paga / **Vencida** (com o valor atualizado: "R$ 342,00 + multa R$ 6,84 + juros R$ 1,14 = R$ 349,98", e a etapa do escalonamento) / Cancelada (com motivo).
- Uma fatura vencida aparece como alerta no painel Hoje do proprietário e do gestor (spec-06). Os efeitos do D+15 e do D+30 aparecem nos anúncios: "Novas reservas suspensas até a quitação da fatura [n]".

**Baixa:** "Registrar pagamento" abre um modal com valor, data e comprovante opcional (05-4). A baixa gera o **lançamento no ledger que quita o recebível** (P9-2), inclusive com multa e juros. Um pagamento parcial deixa o saldo em aberto. A quitação reverte o D+15 e o D+30 automaticamente. Desfazer a baixa só por estorno com justificativa.

**Estados:** mês sem reservas `fora` ("Nada a faturar em setembro"); fechamento em andamento; erro ao gerar ("Nenhuma fatura foi gerada. Tentar novamente", sendo uma operação atômica); fatura vencida.

**Critérios de aceite**
1. (P9-1) A fatura do mês soma exatamente as contas a receber das reservas `fora` concluídas no mês, e o total é igual à soma das linhas.
2. (P9-2) A baixa gera um lançamento que zera o recebível correspondente. Com uma baixa parcial, o saldo restante aparece.
3. (P9-3) Uma reserva cancelada antes do check-in não aparece na prévia nem na fatura.
4. Fechar o mesmo mês duas vezes não duplica as faturas (idempotência).
5. Um ajuste depois do fechamento entra como linha de ajuste na fatura seguinte, e a fatura fechada não é alterada.
6. Cada papel vê e exporta só as faturas do seu escopo, e toda exportação e toda baixa ficam no log.
7. O D+15 bloqueia só reservas novas e o D+30 desabilita o pagamento por fora. A quitação reverte os dois.
8. O extrato do proprietário lista, por emissor, nome, valor e link da fatura, e nunca uma linha de royalty; o royalty só aparece no extrato do franqueado.
8a. Cada emissor gera só a sua fatura, e o extrato do proprietário mostra as cobranças agrupadas por emissor. A fatura da casalivre não contém a parcela do fundo nem a do franqueado.
9. O valor em atraso inclui multa, juros pro rata die e correção pelo IPCA.
10. Os valores são formatados como "R$ 1.234,56" e lidos por extenso no leitor de tela. A tabela tem caption e cabeçalhos (WCAG 1.3.1).

**Parte fiscal** (substitui o item "NFS-e" das Decisões e a pendência do apêndice; texto do Jurídico, cópia em `docs/produto/juridico/spec-05-apendice-fiscal.md`)

Fontes: `juridico/parecer-J1-J35.md` itens 13 a 16, 50 (J38) e 51 (J39); `juridico/recomendacoes-sem-orientacao-externa.md` §1, §2 e §4; spec-00 §5 e §7. [decidido, parecer J38/J39; Rafael decidiu seguir sem OAB/CRC em 16/10/2026.] O texto de "Faturas separadas por emissor", "Extrato consolidado", vencimento, escalonamento, fechamento, fatura e baixa continua como está.

### A.1 Natureza da atividade
- A casalivre faz **intermediação por plataforma digital** (anúncio, busca, reserva, cobrança da própria comissão). Não faz corretagem no sentido do CC art. 722; nos modelos com corretor, **a corretagem é do franqueado ou do parceiro**, inscrito no CRECI.
- Item da lista da LC 116/2003: **10.05** ("agenciamento, corretagem ou intermediação de bens móveis ou imóveis…"). O código não muda se o CRECI enquadrar a atividade como corretagem.
- NBS: pela tabela de correlação LC 116 × NBS do portal da NFS-e Nacional (nfse.gov.br) [CONFERIR o código].
- ISS no município do estabelecimento do emissor (LC 116 art. 3º), alíquota entre 2% e 5% (art. 8º e 8º-A) [CONFERIR a alíquota no município sede].

### A.2 Quem emite cada NFS-e, por modelo
O **aluguel não gera NFS-e** (locação de bem imóvel não é serviço: Súmula Vinculante 31/STF) e, no piloto, nem passa pela casalivre. Cada emissor da cobrança (seção 2, campo "emissor da cobrança") emite a nota **só da sua parcela**.

| Modelo | Parcela | Emissor | Tomador | Base | LC 116 |
|---|---|---|---|---|---|
| Direto | comissão casalivre | casalivre | proprietário | valor da comissão | 10.05 |
| Parceria | comissão do parceiro | parceiro (CRECI ativo) | proprietário | parcela do parceiro | 10.05 |
| Parceria | comissão casalivre | casalivre | configurável por modelo; padrão: proprietário (parceiro, se o contrato de parceria disser que ele paga a casalivre) | parcela da casalivre | 10.05 |
| Franquia | corretor 4% + fundo 3% = 7% | franqueado | proprietário | 7% | 10.05 |
| Franquia | royalty 3% | casalivre | **franqueado** | 3% (base do contrato de franquia) | **17.08** (franquia) |
| Franquia | repasse do fundo 3% | — (sem NFS-e; repasse com extrato) | fundo regional | — | — |
| Manual | comissão do canal manual, se houver [pendente: Rafael] | casalivre (e franqueado, se a franquia cobrar no manual) | proprietário | valor da comissão | 10.05 |

Sem comissão no canal manual, não há nota. Mensalidade de software, se for adotada, é outro serviço (LC 116 1.03 ou 1.05) [CONFERIR com o município].

### A.3 Royalty
- O royalty de **3%** (2% ou 3% no OTA, D12) é **cobrado do franqueado**, pela casalivre, com NFS-e no **item 17.08**. Nunca é cobrado do proprietário: cobrado dele, deixaria de ser royalty e viraria comissão 10.05, com natureza errada (CTN art. 118; risco alto).
- Na fatura do proprietário **não aparece linha de royalty**. O royalty entra numa **fatura mensal casalivre → franqueado**, calculada sobre as reservas do mês da franquia.
- ISS sobre franquia: STF, RE 603.136 (Tema 300, 2020) [CONFERIR].
- Contrato de franquia e COF (Lei 13.966/2019 art. 2º): royalty, base, vencimento e emissão da NFS-e pela casalivre [CONFERIR o texto do art. 2º].

### A.4 Fundo regional
- O franqueado fatura os **3% do fundo dentro dos 7%** da NFS-e dele (o fundo integra o preço do serviço do franqueado) e **repassa ao fundo** por obrigação do contrato de franquia.
- O repasse vai para uma **conta bancária segregada do fundo**, e o **extrato do repasse** alimenta o extrato do fundo regional (UC-A21; prestação de contas trimestral, formato [pendente: Negócios]).
- A casalivre **não retém** o fundo, não atua como mandatária e não cobra o fundo do proprietário (rejeitado; parecer item 51).
- O tratamento contábil do repasse no franqueado é dele [CONFERIR com o contador do franqueado].

### A.5 CRECI
- **Franqueado e parceiro:** CRECI ativo é obrigatório. No cadastro do recebedor (seção 1), número do CRECI + consulta (J8), com data da consulta e situação. Nova consulta a cada 12 meses e antes do 1º fechamento do ano.
- **CRECI inativo ou não encontrado:** o recebedor fica **bloqueado como emissor da cobrança**. Ele não recebe parcela nova, os anúncios do nó deixam de aceitar reservas novas e o gestor e o admin_plataforma são avisados. As reservas confirmadas são mantidas.
- **casalivre no CRECI-RJ** (PJ com corretor responsável; Lei 6.530/1978 art. 6º [CONFERIR]): [pendente: Rafael]. Risco médio no modelo direto enquanto não houver inscrição.
- A casalivre não usa "imobiliária" nem "corretora" na marca nem nos anúncios.

### A.6 IBS e CBS (EC 132/2023; LC 214/2025)
- **2026:** ano de teste. A NFS-e nacional traz o destaque de CBS 0,9% e IBS 0,1%, compensáveis com PIS/Cofins, sem custo efetivo para quem cumpre as obrigações acessórias [CONFERIR atos da Receita e do Comitê Gestor].
- **2027:** a CBS na alíquota cheia substitui PIS/Cofins em cada nota (comissão, royalty); o IBS segue em 0,1%. Sem redutor para intermediação nem para franquia [CONFERIR anexos da LC 214].
- **2029–2032:** o ISS cai gradualmente e o IBS sobe; em 2033 o ISS acaba.
- **Simples:** IBS/CBS recolhidos pelo DAS, com crédito limitado ao tomador, salvo opção pelo regime regular (LC 214 art. 41).
- **Responsabilidade solidária** da plataforma quando o fornecedor não emite documento fiscal (LC 214 art. 22, redação da LC 227/2026). Por isso o ledger guarda, por reserva e por emissor: anfitrião, documento, valor bruto, parcelas e datas.
- Reverificação: 15/12/2026 e 01/07/2027.

### A.7 CPF do tomador e DIMOB
- O CPF/CNPJ do tomador (proprietário, e franqueado no royalty) fica **só em `fiscal_parties`** (documento cifrado com chave própria e `cpf_hmac` UNIQUE; `users.fiscal_party_id` pode ser NULL). Telas e faturas mostram o documento mascarado.
- **Purga:** em **1º de janeiro do ano do último pagamento + 7** (ex.: último pagamento em 2026 → purga em 01/01/2033). Isso cobre os 6 anos corridos da guarda fiscal e a DIMOB. Um legal hold ativo suspende a purga.
- **DIMOB** (declaração de atividades imobiliárias à Receita; IN RFB 1.115/2010, que alcança a intermediação de locação [CONFERIR se a casalivre é obrigada como plataforma e a redação vigente]): o export anual usa `fiscal_parties`, o imóvel, os valores e as datas do ledger.

### A.8 Guarda das NFS-e
- XML e PDF de cada NFS-e emitida, o espelho da fatura e o comprovante da baixa: **6 anos corridos** (CTN arts. 173 e 174; item 16), estendidos enquanto houver auto de infração, processo ou legal hold.
- Imutáveis depois de emitidos; cancelamento ou substituição só por novo documento vinculado.

### A.9 Critérios de aceite (fiscais)
11. Cada fatura e NFS-e tem um único emissor e contém só as parcelas dele; a NFS-e da casalivre ao proprietário nunca traz parcela de fundo, franqueado ou royalty.
12. A NFS-e da comissão sai com o item 10.05; a do royalty, com 17.08 e o franqueado como tomador. Uma regra que configure royalty com tomador proprietário é recusada ao salvar o modelo de split.
13. O royalty do mês é igual à soma de 3% (ou o percentual da D12) das reservas `fora` concluídas da franquia no mês, numa fatura casalivre → franqueado.
14. O extrato do fundo mostra cada repasse do franqueado com data, valor e referência do extrato bancário; não existe lançamento de fundo recebido pela casalivre.
15. Um recebedor franqueado ou parceiro sem CRECI ativo não pode ser salvo como emissor; quando a consulta periódica mostra CRECI inativo, novas reservas do nó são bloqueadas e o aviso é registrado no log.
16. O CPF/CNPJ do tomador só existe em `fiscal_parties`; não aparece em claro em log, export (exceto DIMOB e informe de IR do próprio titular) nem fatura.
17. O job de purga apaga o registro de `fiscal_parties` em 1º de janeiro do ano do último pagamento + 7 e pula registros com legal hold; o log registra só a contagem.
18. NFS-e, espelho e baixa ficam disponíveis por 6 anos corridos e não podem ser alterados nem apagados pelo usuário da aplicação.
19. O ledger permite exportar, por ano e por anfitrião, documento, valor bruto, parcelas e datas (LC 214 art. 22 e DIMOB).
20. Mês sem reservas `fora`: nenhuma NFS-e é emitida e nenhuma fatura é gerada.

### A.10 Pendências do apêndice (substituem a anterior)
- [pendente: Rafael] Comissão do canal manual (e se a franquia cobra no manual).
- [decidido: Produto] Na parceria, o tomador da NFS-e da casalivre é configurável por modelo. O padrão é o proprietário: cada um fatura a sua parte ao proprietário. Se o contrato de parceria disser que o parceiro paga a casalivre, o tomador passa a ser o parceiro.
- [pendente: Rafael] Inscrição da casalivre no CRECI-RJ com corretor responsável (custo de anuidade e do responsável).
- [CONFERIR] Alíquota do ISS no município sede, código NBS, art. 6º da Lei 6.530, Tema 300 do STF, obrigatoriedade da DIMOB para a plataforma e atos de 2026 do IBS/CBS.
