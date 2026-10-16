# Política de privacidade da casalivre

> **MINUTA, versão 0.1, de 16/10/2026. Não publicar.**
> Primeira versão escrita pelo redator jurídico (bot) com base em `juridico/recomendacoes-sem-orientacao-externa.md` (seção 5, item E, e seção 6) e `juridico/parecer-J1-J35.md` (J1 a J35 e itens 47 a 49), conferida com as specs 01 a 10. **Minuta do Jurídico; Rafael optou por seguir sem revisão externa (ver `juridico/recomendacoes-sem-orientacao-externa.md`).**
> Antes de publicar: preencher os campos **[PREENCHER]**, resolver os pontos **[CONFERIR]**, concluir o RIPD da selfie (LGPD, art. 38) e os testes de legítimo interesse (LIA, art. 10) da lista de bloqueio, da avaliação de hóspedes e da moderação, e obter o "ok" do Rafael.

**Versão:** [PREENCHER] · **Vigente a partir de:** [PREENCHER] · **Última atualização:** [PREENCHER]

---

## 1. Resumo

- A casalivre trata seus dados para que você possa **buscar, reservar e se hospedar**, para **prevenir fraude** e para **cumprir a lei**.
- **Não vendemos dados pessoais.**
- A **selfie** dos recebedores é usada **só para prevenir fraude** e é **apagada 30 dias depois da decisão** de verificação.
- O **registro de acesso** (data, hora, IP e porta) é guardado por **6 meses**, como manda o Marco Civil da Internet, com o IP **cifrado**.
- Dados para a **portaria** do condomínio são pedidos **só quando o condomínio exige** e apagados até **90 dias depois do check-out**.
- O **assistente de IA** responde de forma automatizada, e você pode pedir atendimento humano.
- Você pode exercer seus direitos pelo canal do encarregado (item 12).

## 2. Quem é o controlador e como falar com o encarregado

2.1. **Controlador:** **[PREENCHER: razão social]**, CNPJ **[PREENCHER]**, com sede em **[PREENCHER: endereço]** ("casalivre").

2.2. **Encarregado pelo tratamento de dados (DPO):** **[PREENCHER: nome do encarregado ou da empresa contratada]**, e-mail **[PREENCHER: e-mail do encarregado]**, formulário **[PREENCHER: link]** (LGPD, art. 41). [CONFERIR: se a casalivre se enquadrar como agente de tratamento de pequeno porte, a indicação do encarregado pode ser dispensada pela Res. CD/ANPD 2/2022, mas o canal de comunicação com o titular continua obrigatório. A recomendação é indicar o encarregado.]

2.3. Esta política vale para o marketplace e para **todos os canais** operados pela casalivre, inclusive os que têm marca de parceiros.

## 3. A quem esta política se aplica

- **Visitantes:** quem navega, busca ou usa o assistente de IA.
- **Hóspedes:** quem faz solicitação ou reserva, e os **acompanhantes** cujos dados o hóspede informa.
- **Anfitriões, proprietários, gestores e operadores:** quem anuncia ou administra imóveis.
- **Recebedores:** pessoas e empresas que recebem pagamentos (proprietários, prestadores de limpeza, franqueados) e passam pela verificação de identidade (KYC).
- **Franqueados (corretores):** corretores do CRECI que atuam como suporte local.

## 4. Quem mais trata os seus dados (papéis)

4.1. **O anfitrião e o proprietário.** Para executar a locação, o anfitrião recebe os dados necessários da reserva (nome, datas, hóspedes, mensagens, contatos depois da confirmação e, se houver, a lista da portaria). Ao usar esses dados para a locação, o anfitrião age como **controlador independente** e responde pelo que fizer com eles fora da plataforma. [CONFERIR: o enquadramento do anfitrião como controlador independente ou como controlador conjunto em alguns fluxos.]

4.2. **O condomínio.** Quando a portaria exige, o condomínio recebe nome e documento dos hóspedes e é **controlador independente** do que recebe, aplicando o próprio regulamento.

4.3. **O prestador de limpeza** recebe só o necessário para prestar o serviço (datas e, se preciso, o nome de quem reservou). [CONFERIR com Produto: as specs não detalham o que o prestador vê.]

4.4. **Operadores da casalivre:** empresas que tratam dados em nome da casalivre, como hospedagem e infraestrutura, envio de mensagens (WhatsApp, SMS e e-mail) e o fornecedor do assistente de IA. Eles têm contrato com cláusulas de proteção de dados (LGPD, art. 39).

## 5. Quais dados tratamos, para quê, com qual base legal e por quanto tempo

As bases legais são as do art. 7º da LGPD (dados pessoais) e do art. 11 (dados sensíveis). "Legítimo interesse" (art. 7º, IX) é usado só com teste de balanceamento documentado (art. 10).

### 5.1. Visitantes e navegação

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| **Registro de acesso**: data, hora, endereço IP e porta de origem, inclusive de acessos sem login | Cumprir o Marco Civil e permitir a identificação por ordem judicial | Obrigação legal (art. 7º, II; Marco Civil, art. 15) | **6 meses**, com o IP e a porta **cifrados de forma reversível**; mais tempo só por pedido de autoridade (art. 15, § 2º), ordem judicial ou legal hold de um caso concreto |
| Buscas e páginas vistas (cidade, raio, filtros, imóvel visto, canal de origem) | Funcionar a busca, segurança e métricas do produto | Legítimo interesse (art. 7º, IX) | Métricas **sem dado pessoal**. [CONFERIR: o log de atividades atual (`storage/logs/activity.sqlite`) grava cidade e raio da busca e o e-mail no login; definir se entra na classe "acesso" (6 meses).] |
| Favoritos sem login | Salvar imóveis | Armazenado **só no seu dispositivo**, com os códigos dos imóveis | Até você apagar ou entrar na conta |
| Cookies de sessão e segurança | Manter o login e proteger contra ataques (CSRF) | Execução de contrato e legítimo interesse (art. 7º, V e IX) | Pelo tempo da sessão. [CONFERIR: inventário de cookies e uso de ferramentas de análise; as specs não preveem cookies de publicidade.] |

### 5.2. Conta, identificação e reserva do hóspede

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| Nome, e-mail, celular e CPF | Criar a conta, identificar quem reserva, evitar duplicidade e fraude, emitir o Comprovante de reserva | Execução de contrato e procedimentos preliminares (art. 7º, V); legítimo interesse na prevenção à fraude (art. 7º, IX) | Enquanto a conta existir; na exclusão, 30 dias de carência (ou exclusão imediata) e depois apagados, salvo o que tiver guarda legal (item 9). O CPF é validado só pelo dígito verificador; **o CPF não é enviado à BrasilAPI** |
| Código de confirmação (OTP) | Confirmar que o e-mail ou celular é seu | Execução de contrato (art. 7º, V) | Guardado como hash, vale 10 minutos |
| Dados da reserva: imóvel, datas, hóspedes por faixa etária, pets, valores e composição do preço (snapshot), política aplicada, mensagem ao anfitrião, status e histórico | Executar a reserva, o cancelamento, o reembolso e a disputa | Execução de contrato (art. 7º, V); exercício regular de direitos (art. 7º, VI) | Pela duração do contrato e mais **5 anos** (CDC, art. 27). Os lançamentos financeiros seguem a guarda fiscal (abaixo) |
| Aceites: versão e hash de cada termo, data, hora, canal e IP do aceite | Provar o que foi aceito | Exercício regular de direitos (art. 7º, VI) | Duração do contrato e mais **5 anos** |
| Lançamentos financeiros e fiscais (valores, pagamento marcado, comprovante anexado, reembolsos, faturas) | Contabilidade, obrigações fiscais e prova | Obrigação legal (art. 7º, II) e exercício de direitos (art. 7º, VI) | **5 anos contados de 1º de janeiro do ano seguinte** (na prática, 6 anos corridos), ou até o fim de processo fiscal |
| Declaração do adulto responsável pelos menores | Proteger crianças e adolescentes (ECA, arts. 82 e 83) | Execução de contrato e melhor interesse do menor (arts. 7º, V, e 14) | Com a reserva |

### 5.3. Portaria do condomínio e menores

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| Nome e documento de cada hóspede, inclusive menores; foto do documento **só se o condomínio exigir** | Controle de acesso ao condomínio, sem o qual o hóspede não entra | Execução de contrato (art. 7º, V); subsidiariamente, legítimo interesse na segurança do condomínio (art. 7º, IX); melhor interesse do menor (art. 14) | **Até 90 dias depois do check-out**; **7 dias** se a reserva for cancelada; mais tempo só com incidente aberto. Depois dos primeiros 7 dias, o acesso fica restrito ao proprietário e à casalivre, com motivo registrado |

- **Coletamos só os campos que a portaria daquele imóvel exige.** A portaria recebe nome e documento, **sem foto**, por um **link autenticado de validade curta**, e nunca por cópia em e-mail ou WhatsApp.
- A lista é vista só pelo proprietário e pelas pessoas que ele autorizou a cuidar do check-in. A casalivre só abre com motivo registrado.
- **Dados de crianças e adolescentes** (LGPD, art. 14) são tratados **só quando a portaria exige**, no **melhor interesse** do menor (segurança e acesso), e informados pelo adulto que faz a reserva. [CONFERIR: o art. 14, § 1º, exige consentimento específico de um dos pais para dados de crianças; o parecer J11 entende que se aplicam as bases dos arts. 7º e 11, com apoio no Enunciado CD/ANPD nº 1/2023. Conferir o enunciado no site da ANPD antes de publicar.]
- A locação por temporada **não exige** a ficha nacional de registro de hóspedes (FNRH) dos meios de hospedagem (Lei 11.771/2008, arts. 23 e 26). [CONFERIR: normas municipais de Cabo Frio, Búzios e Arraial do Cabo.]

### 5.4. Mensagens

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| Mensagens entre hóspede e anfitrião | Combinar a estadia e servir de prova em disputas | Execução de contrato (art. 7º, V); exercício de direitos (art. 7º, VI) | **12 meses depois do check-out**; **90 dias** depois da última mensagem quando não houve reserva; mais tempo só com legal hold |
| Tipo e quantidade de contatos ocultados | Prevenir a negociação fora da plataforma e o golpe | Legítimo interesse (art. 7º, IX) | Com a conversa. **O texto original dos contatos ocultados não é guardado** |

- **Mensagens podem ser revisadas para segurança.** Só a moderação da casalivre lê uma conversa, e só quando há denúncia, disputa ou sinal automático de fraude ligado a ela, com motivo registrado (art. 7º, IX). Os participantes são avisados; durante uma investigação de fraude, o aviso pode ser adiado, e o adiamento fica registrado.
- Se você excluir a conta, o texto das suas mensagens continua com a outra parte até o fim da guarda, com o autor "Usuário removido", porque também é registro da outra parte.

### 5.5. Avaliações

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| Avaliação pública do imóvel e do anfitrião (notas, texto, primeiro nome, mês e ano) | Informar outros hóspedes | Legítimo interesse (art. 7º, IX) | Enquanto o anúncio existir [CONFERIR: prazo não definido nas specs]; na exclusão da conta, fica com o autor "Usuário removido" |
| **Avaliação do hóspede feita pelo anfitrião** (não pública) | Permitir que anfitriões avaliem o risco de um pedido | Legítimo interesse (art. 7º, IX), com teste de balanceamento e RIPD | Vista **só pelos anfitriões aos quais você pede reserva, e só enquanto o pedido estiver pendente**; entra no resumo por **24 meses**; apagada ou desvinculada se você excluir a conta |
| Registro de quem viu as avaliações do hóspede | Transparência e segurança | Obrigação legal e legítimo interesse (art. 7º, II e IX) | **6 meses** (registro de acesso) |

Você vê as avaliações que recebeu, pode responder e contestar cada uma e, a pedido, recebe a lista dos anfitriões que as viram nos últimos 6 meses (art. 18, VII). Você pode se opor a esse tratamento (art. 18, § 2º); o pedido é analisado e pode ser negado se o interesse legítimo prevalecer, com a resposta fundamentada. **A avaliação não bloqueia reservas de forma automática.**

### 5.6. Disputas, denúncias e moderação

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| Relato, categoria, fotos (sem metadados de localização) e mensagens da disputa | Resolver o problema e servir de prova | Execução de contrato e exercício de direitos (art. 7º, V e VI) | Fotos e mensagens: **12 meses depois da resolução**. Resultado e registro da decisão: **5 anos**. Do original da foto, guarda-se só o **hash SHA-256** e a data do upload; o original cifrado só em disputa de valor relevante, chargeback ou ação |
| Denúncias e decisões de moderação | Segurança da plataforma | Legítimo interesse (art. 7º, IX) | **5 anos** (registro administrativo). Texto removido por conter dado pessoal sai do registro depois da decisão |
| **Declaração com o histórico da reserva** para o Procon | Atender você quando o reembolso não for feito | Exercício de direitos e acesso do titular (arts. 7º, VI, 18, II, e 19) | Entregue a você; mantida com a disputa. Do anfitrião, traz só o nome e os dados do anúncio |

### 5.7. Assistente de IA

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| Perguntas e respostas trocadas com o assistente, e o contexto da busca ou da reserva usado para responder | Ajudar na busca, tirar dúvidas e encaminhar ao atendimento humano | Procedimentos preliminares e execução de contrato (art. 7º, V); legítimo interesse na melhoria do atendimento (art. 7º, IX) [CONFERIR] | [PREENCHER: prazo de guarda das conversas com o assistente; sugestão: igual às mensagens sem reserva, 90 dias] |

- As respostas são **geradas de forma automatizada**. Você pode pedir **atendimento humano** a qualquer momento.
- O assistente **não toma decisões** sobre aprovação, cancelamento, reembolso, disputa ou bloqueio. Se uma decisão unicamente automatizada vier a afetar seus interesses, você pode pedir revisão por uma pessoa (LGPD, art. 20).
- **Não informe** ao assistente senhas, códigos, dados de cartão, documentos ou dados de saúde.
- Fornecedor do modelo de IA: **[PREENCHER: nome do fornecedor e país onde processa os dados]**. [PREENCHER: se o fornecedor pode ou não usar as conversas para treinar modelos. Recomendação: contratar com proibição de uso para treinamento.]

### 5.8. Anfitriões, proprietários e equipes

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| Nome, e-mail, celular, papel e organização | Gerir a conta e as permissões | Execução de contrato (art. 7º, V) | Enquanto houver vínculo; depois, conforme o item 9 |
| Prova de vínculo com o imóvel (escritura, IPTU, contrato ou autorização) | Evitar anúncio falso | Execução de contrato e legítimo interesse (art. 7º, V e IX) | Com o anúncio e mais 5 anos [CONFERIR: prazo não definido nas specs] |
| Registro de ações administrativas (anúncios, calendário, árvore, canais, equipe) | Auditoria e prova | Exercício de direitos (art. 7º, VI) | **5 anos**. CPF, CNPJ e chave Pix aparecem mascarados no registro |
| Taxa de cancelamento, confiabilidade e comprovação de força maior | Executar o contrato do anfitrião | Execução de contrato (art. 7º, V) | Duração do contrato e mais 5 anos |

### 5.9. Recebedores: verificação de identidade (KYC)

| Dado | Finalidade | Base legal | Guarda |
|---|---|---|---|
| **Selfie e prova de vida** (dado biométrico, portanto sensível) | Comparar com a foto do documento e **prevenir fraude** no cadastro | **Prevenção à fraude e segurança do titular em processos de identificação e autenticação de cadastro** (art. 11, II, "g"), **sem necessidade de consentimento** | **Apagada 30 dias depois da decisão** (aprovação ou recusa); fica só o resultado e o hash |
| Documento com foto, comprovante de endereço (até 90 dias), cartão CNPJ, contrato social, CCMEI | Verificar identidade e o direito de receber | Execução de contrato e exercício de direitos (art. 7º, V, VI e IX) | Aprovados: durante o relacionamento e mais **5 anos** contados do fim do relacionamento [CONFERIR: decisão do Rafael entre relação + 5 anos (spec-05) e relação + 2 anos (recomendação do parecer J5)]. Recusados: imagens apagadas em **30 dias** |
| Resultado da verificação (status, fonte, data, quem aprovou, hash, nome, CPF/CNPJ cifrado) | Prova e prevenção à fraude | Exercício de direitos e legítimo interesse (art. 7º, VI e IX) | Aprovados: relacionamento + **5 anos**. Recusados: **6 meses**, ou **5 anos** se a recusa for por fraude |
| CPF/CNPJ, regime tributário, banco, agência, conta e chave Pix (titular igual ao CPF/CNPJ) | Exibir a chave correta ao hóspede, emitir extratos e informes | Execução de contrato e obrigação legal (art. 7º, II e V) | Com o relacionamento; os registros financeiros seguem a guarda fiscal (6 anos) |
| CPF/CNPJ do **tomador das notas fiscais** (proprietário e, no royalty, o franqueado) | Emitir NFS-e, extratos, informe de IR e DIMOB | Obrigação legal (art. 7º, II) | Num **cadastro fiscal separado** (`fiscal_parties`), **cifrado** e mascarado em telas e faturas, até **1º de janeiro do ano do último pagamento + 7**, salvo legal hold |
| CRECI do franqueado (número, situação e data da consulta) | Confirmar que o corretor está habilitado | Legítimo interesse e dado tornado público (art. 7º, IX e § 4º; Lei 6.530/1978) | Com o relacionamento |

- **Aviso na coleta:** antes de abrir a câmera, você vê: "Sua selfie é um dado biométrico. Usamos só para prevenir fraude e confirmar sua identidade. Só a equipe de verificação da casalivre vê, e ela é apagada 30 dias depois da decisão." Esse uso pode deixar de ser feito se prevalecerem direitos e liberdades fundamentais seus que exijam a proteção dos dados (art. 11, II, "g").
- **Só a equipe de verificação da casalivre vê os documentos**, com registro de cada acesso. O franqueado vê só o status e o resultado das validações automáticas.
- Os arquivos são **cifrados**, guardados fora da área pública do site, sem metadados de localização (EXIF/GPS), e nunca usados para outra finalidade.
- **A videochamada de suporte para correção de chave Pix não é gravada.**
- O **CNPJ** é consultado na BrasilAPI (dado público da Receita Federal). **O CPF não é enviado à BrasilAPI.**

## 6. Lista de bloqueio

6.1. Para impedir que quem foi suspenso ou cometeu fraude volte com um novo cadastro, a casalivre mantém uma **lista de bloqueio** com o **CPF e o telefone pseudonimizados por HMAC com chave secreta**. Esse dado continua sendo pessoal e é protegido como tal (LGPD, arts. 12 e 13, § 4º).

6.2. **Base legal:** legítimo interesse na segurança da plataforma, dos anfitriões e dos hóspedes (art. 7º, IX), com teste de balanceamento documentado (art. 10).

6.3. **Guarda:** pelo **tempo da penalidade** (por exemplo, 7 dias depois de expirações seguidas) ou, em **fraude comprovada, 5 anos**. **Nunca é permanente.** A purga é automática no vencimento.

6.4. **Seus direitos:** um cadastro bloqueado vê uma mensagem neutra e o botão "Pedir revisão". A revisão é feita **por uma pessoa** e respondida em até **15 dias corridos**, e você pode se opor ao tratamento (art. 18, § 2º, e art. 20). O pedido, a decisão, o motivo e quem decidiu ficam guardados por **5 anos**. A lista **não é compartilhada** com terceiros e não é usada para reidentificar contas excluídas.

## 7. Com quem compartilhamos

| Destinatário | O que recebe | Por quê |
|---|---|---|
| Anfitrião, proprietário e equipe autorizada do imóvel | Dados da solicitação e da reserva; contatos depois da confirmação; lista da portaria, se houver; resumo das avaliações do hóspede enquanto o pedido estiver pendente | Executar a locação |
| Prestador de limpeza | O necessário para a limpeza [CONFERIR] | Executar o serviço de limpeza |
| Franqueado (corretor) do território | Status das reservas e dos recebedores, sem documentos de KYC; dados das reservas da franquia necessários à fatura dele ao proprietário | Suporte local, mediação e faturamento da parte dele (7%) |
| Condomínio (portaria) | Nome e documento dos hóspedes | Controle de acesso, quando exigido |
| Provedores de infraestrutura e hospedagem | Todos os dados, como operadores | Funcionamento da plataforma. [PREENCHER: fornecedor de nuvem e região dos servidores] |
| Provedor de WhatsApp, SMS e e-mail | Nome, celular, e-mail e o texto do aviso (sem conteúdo de mensagens) | Códigos de confirmação e avisos. [PREENCHER: fornecedor (D3)] |
| Fornecedor do assistente de IA | Conversas com o assistente | Responder às suas perguntas. [PREENCHER] |
| BrasilAPI | **Só o CNPJ** | Verificar a situação na Receita Federal |
| Conselhos de corretores (CRECI) | Número do CRECI | Verificar a habilitação do franqueado |
| Receita Federal, Comitê Gestor do IBS e prefeituras | Dados das operações intermediadas (anfitrião, CPF/CNPJ, imóvel, valores, datas) e NFS-e | Obrigações fiscais da plataforma (LC 214/2025, art. 22), NFS-e e DIMOB [CONFERIR: o alcance no piloto e a obrigatoriedade da DIMOB para a plataforma] |
| Autoridades e Poder Judiciário | O que for requisitado | Cumprir a lei ou ordem judicial. **Registros de acesso só são entregues por ordem judicial** (Marco Civil, art. 10, § 1º) |
| Prestador de serviços de pagamento (PSP), **na fase futura** | Dados do recebedor (CPF/CNPJ) e da transação | Processar o pagamento e cumprir as regras de prevenção à lavagem de dinheiro do PSP, que é controlador independente do KYC que fizer |

## 8. Transferência internacional

[PREENCHER/CONFERIR: indicar se algum fornecedor (nuvem, mensageria, IA) processa dados fora do Brasil, em quais países e com qual mecanismo do art. 33 da LGPD (por exemplo, cláusulas-padrão contratuais aprovadas pela ANPD). Se nenhum dado sair do Brasil, dizer isso aqui.]

## 9. O que fica guardado depois que você exclui a conta

Alguns dados são mantidos depois da exclusão da conta **para cumprir obrigações legais e defender direitos** (LGPD, art. 16, I e II). Eles ficam **pseudonimizados** (desvinculados do seu perfil) e **bloqueados para qualquer outro uso**:

| Dado retido | Prazo |
|---|---|
| Registros de acesso | 6 meses do acesso |
| Lançamentos financeiros, informes de IR e notas fiscais | 5 anos de 1º de janeiro do ano seguinte (na prática, 6 anos) |
| CPF/CNPJ do tomador das notas fiscais (cadastro fiscal) | Até 1º de janeiro do ano do último pagamento + 7 |
| Aceites de termos e dados da reserva | Fim do contrato + 5 anos |
| Documentos e resultado do KYC de recebedores aprovados | Fim do relacionamento + 5 anos [CONFERIR: decisão do Rafael, ver 5.9] |
| Mensagens enviadas (autor "Usuário removido") | Até o fim da guarda da conversa (12 meses depois do check-out ou 90 dias sem reserva) |
| Avaliações públicas (autor "Usuário removido") | Conforme 5.5 |
| Disputas resolvidas | Fotos e mensagens: 12 meses depois da resolução; resultado: 5 anos |
| Lista de bloqueio (HMAC) | Tempo da penalidade, ou 5 anos em fraude |
| Registro do seu pedido de exclusão e da resposta (o que foi apagado, o que ficou e por quê), com e-mail em HMAC | 5 anos |
| Casos com legal hold (processo, Procon, chargeback, ordem judicial) | Até o fim de **todos** os casos |

**Legal hold:** quando há processo, reclamação, chargeback, incidente ou ordem de autoridade, o apagamento automático do dado fica suspenso enquanto durar o caso. Um dado pode estar em mais de um caso e só volta à purga quando todos forem encerrados. As cópias de segurança (backups) são mantidas por até 35 dias e seguem a mesma purga.

## 10. Segurança

10.1. Adotamos medidas técnicas e administrativas para proteger os dados (LGPD, art. 46), entre elas:
- cifragem de documentos, selfies, CPF/CNPJ, dados bancários e IPs dos registros de acesso;
- acesso por perfil, com mascaramento e registro de cada visualização de dado sensível;
- remoção de metadados das imagens;
- autenticação por código;
- carência e avisos na troca de chave Pix;
- registros de auditoria imutáveis.

10.2. Os IPs dos registros de acesso só são decifrados para cumprir ordem judicial, com dupla aprovação e registro.

10.3. **Incidentes.** Se houver incidente de segurança que possa causar risco ou dano relevante, a casalivre comunicará a ANPD e os titulares afetados (LGPD, art. 48). [CONFERIR: prazo de 3 dias úteis da Res. CD/ANPD 15/2024.]

## 11. Decisões automatizadas

11.1. Algumas restrições são aplicadas automaticamente por regra objetiva, como a suspensão de 7 dias depois de 3 expirações seguidas e o bloqueio de cadastro pela lista de bloqueio.

11.2. Você pode pedir **revisão por uma pessoa** de qualquer decisão automatizada que afete seus interesses (LGPD, art. 20), pelo canal do item 12. A resposta vem em até 15 dias corridos.

## 12. Seus direitos e como exercê-los

12.1. Você pode pedir, a qualquer momento (LGPD, art. 18):
- (a) confirmação de que tratamos seus dados;
- (b) acesso aos dados;
- (c) correção de dados incompletos, inexatos ou desatualizados;
- (d) anonimização, bloqueio ou eliminação de dados desnecessários, excessivos ou tratados em desconformidade com a lei;
- (e) portabilidade, conforme a regulamentação da ANPD;
- (f) eliminação dos dados tratados com consentimento;
- (g) informação sobre com quem compartilhamos seus dados;
- (h) informação sobre a possibilidade de não dar consentimento e suas consequências;
- (i) revogação do consentimento;
- (j) oposição a tratamento feito com base em legítimo interesse, se houver descumprimento da lei (art. 18, § 2º);
- (k) revisão de decisões automatizadas (art. 20).

12.2. **Como pedir:** pela área "Privacidade" da sua conta ou pelo e-mail do encarregado **[PREENCHER]**. Para sua segurança, podemos pedir a confirmação da identidade.

12.3. **Prazos:** a confirmação e o acesso em formato simplificado são imediatos; a **declaração completa** é entregue em até **15 dias** (LGPD, art. 19). Não usamos o prazo em dobro permitido a agentes de pequeno porte.

12.4. **Quando não podemos apagar:** se o dado tiver guarda obrigatória ou for necessário para defesa (item 9), informamos o que ficou retido, por qual motivo e até quando.

12.5. Você também pode reclamar à **Autoridade Nacional de Proteção de Dados (ANPD)** e aos órgãos de defesa do consumidor.

## 13. Comunicações

Os avisos de status, prazos e segurança fazem parte do serviço. Avisos de mensagens e de solicitações dizem só "nova mensagem" ou "nova solicitação", com um link para entrar, **sem o conteúdo**. [CONFERIR: as specs não preveem comunicações de marketing; se forem criadas, definir a base legal e incluir a opção de descadastro.]

## 14. Alterações desta política

Esta política é versionada. Mudanças relevantes são avisadas na plataforma antes de entrarem em vigor. A versão vigente e as anteriores ficam disponíveis em **[PREENCHER: link]**.
