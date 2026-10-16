# CasaLivre: Spec 09, área do hóspede e cancelamento (v1, 16/10/2026, alvo S7 (núcleo da P2), meta WCAG 2.2 AA)

Fontes: `decisoes-rafael.md` (D13: limpeza paga direto ao prestador, aprovada; D14: textos dos 3 termos, redigidos pelo jurídico antes da S7), `pagamentos.md` §5 (máquina de estados) e §6 (cancelamento e reembolso; CDC art. 49 com "tema não pacificado no STJ"), `backlog.md` P2 (critérios 2, 10, 20, 21 e 23), e as specs 01 (estados, pagamento por fora, portaria), 02 (força maior, confiabilidade), 06 (portaria) e 07 (composição e snapshot do preço). A spec usa só tokens de tema.
**Casos de uso:** UC-H18 a UC-H24 e UC-C10. A alteração de datas (UC-H21) fica como porta futura.

## 1. Minhas viagens
**Objetivo:** achar a reserva e saber o próximo passo em segundos.
**Abas:** **Próximas** (Aguardando aprovação, Aguardando pagamento, Confirmada e Em estadia), **Passadas** (Concluída, com "Avaliar" por 14 dias; spec-07) e **Canceladas** (Cancelada, Recusada e Expirada).
**Card:** foto, imóvel, bairro (o endereço só aparece em Confirmada), datas, **selo de status** (texto e ícone; spec-01, Viagens), a **próxima ação** ("Pagar até 17/10, 10:30 · faltam 3 h", "Enviar lista da portaria até 18/12", "Avaliar até 30/12") e o código.
**Layout:** no mobile, a aba Viagens com as 3 sub-abas e uma lista de cards. No desktop, uma lista à esquerda e o detalhe à direita.
**Estados:** vazio em Próximas ("Nenhuma viagem marcada." + "Explorar imóveis"); vazio em Passadas ou Canceladas (texto curto); carregando (skeleton); erro ("Não carregamos suas viagens. Tentar novamente"); banner de bloqueio antibloqueio, quando houver (spec-01, seção 5b).
**Critérios de aceite:** (1) cada reserva aparece em uma só aba, conforme o estado; (2) a próxima ação tem a data absoluta e o tempo restante; (3) uma reserva recusada mostra só a mensagem neutra e "Ver parecidos" (spec-01, seção 5b).

## 2. Detalhe da reserva
**Canal de denúncia sempre visível** ([decidido, parecer item 17]): "Denunciar anúncio ou pedido de pagamento suspeito" no topo do detalhe.
**Topo:** status, código, o **rótulo de origem** (Pedido, Imediata, OTA ou Manual; spec-06, 3b) e a **linha do tempo** (Enviada → Aprovada → Paga → Confirmada → Em estadia → Concluída), com a etapa atual marcada em texto.
**Prazos:** a aprovação e o pagamento seguem a regra **24 h, ou 4 h se o check-in for em menos de 72 h** (P2-23). Cada prazo mostra o texto absoluto e o relativo, e só é anunciado quando falta 1 h e quando expira.
**Pagamento por fora (piloto D11):** em Aguardando pagamento, aparecem as duas linhas da spec-01: **Estadia** (valor e chave do recebedor do imóvel) e **Limpeza** (valor e chave do prestador; D13). Cada uma tem "Copiar chave", com o aviso "Pagamento direto ao anfitrião, sem a proteção casalivre". Também aparece o status parcial ("Estadia recebida · Limpeza pendente").
**Composição:** o snapshot da spec-07, B3, com "Preço garantido em [data]".
**Comprovante de reserva** ([decidido, parecer J34]; **nunca "recibo"**): depois da Confirmada, o botão "Ver comprovante de reserva" mostra um resumo com o código, as datas, as linhas, o total e "pagamento informado como feito diretamente ao anfitrião em [data]; não é recibo nem documento fiscal". Também traz o nome de quem reservou, o nome do anfitrião, a política aplicada e a data de emissão. Ele é exportável em PDF.
**Contatos:** seguem a regra da spec-08 (no piloto, liberados na confirmação).
**Ações por estado:**
| Estado | Ações do hóspede |
|---|---|
| Aguardando aprovação | Cancelar solicitação (sem custo), Mensagens |
| Aguardando pagamento | Copiar chave, Cancelar sem custo (spec-01) |
| Confirmada | Lista da portaria, Ver comprovante de reserva, Cancelar reserva (com simulação), Mensagens, "Alterar datas" (desabilitado: "Em breve") |
| Em estadia | Relatar problema, Mensagens |
| Concluída | Avaliar, Ver comprovante de reserva |
**Critérios de aceite:** (1) os prazos exibidos seguem a regra 24 h / 4 h; (2) as linhas do pagamento mostram os valores e as chaves do snapshot, e uma chave "Pendente até" nunca aparece (spec-05); (3) o total é igual ao do checkout; (4) só aparecem as ações válidas para o estado.

## 3. Lista da portaria
Quando o imóvel exige (spec-03), aparece o formulário com a **finalidade** (spec-01) e só os campos exigidos pela portaria (por pessoa: nome e documento; foto, só se o imóvel exigir). Ele é preenchido por faixa de hóspede. Para menores, aparece o aviso do ECA.
**Prazo:** 48 h antes do check-in, configurável, com lembretes em 72 h e 24 h. Em atraso, aparece "A portaria pode recusar a entrada sem a lista", e a reserva **não é cancelada** (spec-06).
**Estados:** Pendente, Enviada ("Lista enviada em [data], 4 pessoas"; editável até o prazo) e Atrasada. Retenção e acesso seguem a spec-06 (90 dias depois do check-out; 7 dias se cancelada).
**Critérios de aceite:** (1) o formulário pede só os campos marcados pelo imóvel; (2) enviar mostra a confirmação e o status muda; (3) a lista atrasada não cancela a reserva.

## 4. Cancelamento pelo hóspede
**Entrada:** "Cancelar reserva" abre uma tela (não um modal pequeno) com a **simulação do reembolso** pela **política gravada na reserva** (snapshot), e não pela política atual do imóvel.
**Regras (pagamentos.md §6, spec-03):**
- **Aguardando aprovação ou pagamento:** sem custo, e as datas são liberadas.
- **CDC, 7 dias a partir da reserva:** reembolso de 100% **se o cancelamento for em até 7 dias da reserva E antes do check-in**, sem exceção para check-in próximo ([decidido, parecer J35]). Depois do início da estadia, não se aplica.
- Depois disso, vale a política: **Flexível** (100% até 24 h antes; depois, a 1ª diária não volta), **Moderada** (100% até 5 dias antes; depois, 50%) ou **Rígida** (100% até 14 dias antes, 50% entre 14 e 7 dias, 0% com menos de 7 dias).
- **Sempre:** a limpeza e a taxa não usadas são devolvidas.
**Simulação (exemplo, Moderada, cancelando 3 dias antes):**
- "Diárias: R$ 2.000 → reembolso de 50% = R$ 1.000"
- "Limpeza (não usada): R$ 250 → R$ 250"
- "Taxa de serviço: não se aplica (0%)"
- **"Você recebe: R$ 1.250"**
Aparece também a regra aplicada ("Política Moderada: 50% com menos de 5 dias") e a linha do tempo das datas-limite desta reserva.
**Motivo** (opcional, lista fixa) e **confirmação** ("Cancelar e receber R$ 1.250?").
**Reembolso no piloto (pagamento por fora)** *[E] (Negócios)*:
- O **anfitrião tem 5 dias úteis** para devolver e **anexar o comprovante Pix com o ID da transação** (a limpeza segue a mesma regra, pelo prestador).
- O hóspede vê "Reembolso de R$ 1.250 até [data] · aguardando o anfitrião". Quando o comprovante chega, ele escolhe **"Recebi"** ou **"Não recebi / contestar"**. **Se não responder em 72 h, a devolução é considerada feita.**
- **Se o anfitrião perder o prazo:**
  - abre-se uma **disputa automática**;
  - os anúncios dele **deixam de aceitar reservas novas**;
  - **a comissão continua devida**;
  - o proprietário **perde o pagamento por fora**.
- Nesse caso, o hóspede recebe **orientação sobre o Procon** e uma **declaração da plataforma com o histórico** ([decidido, parecer item 22]: dados da reserva, mensagens relevantes, status, datas e valores; do anfitrião, só o nome e os dados do anúncio; linguagem factual) da reserva (PDF, com as datas, os valores e os comprovantes).
**Critérios de aceite:** (1) o prazo de 5 dias úteis aparece para os dois lados; (2) sem resposta do hóspede em 72 h, o reembolso fica como feito; (3) com o prazo vencido, a disputa é aberta, os anúncios são suspensos para reservas novas e o pagamento por fora é desabilitado; (4) o extrato fica disponível ao hóspede.
**Limites:** cancelamentos em série geram restrição (P2-21; spec-01, seção 5b), com aviso antes de confirmar.
**Estados:** simulando; cancelado ("Reserva cancelada. Reembolso de R$ 1.250: [como]"); erro (nada é cancelado, e aparece "Tentar novamente"); fora do prazo para cancelar (em estadia: "Para problemas durante a estadia, use Relatar problema").
**Critérios de aceite:** (1) a simulação usa a política do snapshot e as datas-limite calculadas para esta reserva; (2) dentro dos 7 dias do CDC, o reembolso é de 100% (P2-10); (3) a limpeza não usada é sempre devolvida; (4) o valor confirmado é igual ao simulado; (5) as datas são liberadas no calendário depois do cancelamento; (6) a ação fica no log.

## 5. Cancelamento pelo anfitrião
- **Hóspede:** "O anfitrião cancelou sua reserva. Você recebe 100% (R$ 2.250)." Também aparecem os imóveis parecidos nas mesmas datas (com prioridade para a mesma organização e o mesmo canal) e um contato de suporte. No piloto, a devolução segue o fluxo de 5 dias úteis do item 4. **O hóspede sempre recebe 100%.**
- **Anfitrião:** antes de confirmar, aparecem as **consequências**, com o **motivo obrigatório** *[E] (Negócios)*:
  - **Sem força maior:**
    - as **datas continuam bloqueadas**, e não podem ser revendidas;
    - o anúncio **perde o destaque na busca por 90 dias**;
    - a partir do **2º cancelamento em 12 meses**, há uma **taxa de 10%, limitada a R$ 300**, parametrizada ([decidido, parecer item 21]; isenta por força maior comprovada; cobrada na fatura P9);
    - com **3 ou mais em 90 dias**, a conta vai para **revisão**.
    A tela diz: "Este é seu 2º cancelamento em 12 meses: taxa de R$ 225 (10%)."
  - **Com força maior** (dano no imóvel, clima, saúde): "Enviar comprovação em até 7 dias". Quem decide é o **admin do nó acima**, com recurso ao admin_plataforma e a regra de conflito de interesse que sobe um nível (spec-02). Enquanto isso, o status é "Comprovação pendente até [data]". Se for aceita, nada disso se aplica.
**Critérios de aceite:** (1) o reembolso ao hóspede é sempre de 100%; (2) sem força maior, as datas ficam bloqueadas e o anúncio perde o destaque por 90 dias; (3) a taxa só se aplica a partir do 2º cancelamento em 12 meses, com o teto de R$ 300; (4) com 3 ou mais em 90 dias, a conta vai para revisão; (5) uma força maior aceita não conta.

## 6. Disputa (o que o hóspede vê)
**Abertura** *[E] (Negócios)*:
- "Relatar problema" fica disponível **até 14 dias depois do check-out**, ou até o fim do prazo de reembolso, o que for maior.
- **Problema no check-in:** precisa ser relatado **em até 24 h depois da entrada**, **com fotos** (upload com as regras do 05-4).
- O formulário tem categoria (limpeza, divergência do anúncio, acesso negado, segurança, reembolso não feito, outro), descrição e fotos.
**Resolução:** o prazo é de **10 dias úteis**.
- **Primeiro, quem media é o admin do nó acima.**
- Com **conflito de interesse**, **sem acordo** ou com **prazo excedido**, o caso sobe para o **admin_plataforma, que decide em definitivo** (spec-10, fila 2.3).
- Os status são **Aberta → Em mediação (nó) → Em decisão (plataforma) → Resolvida** (`em_disputa` → `resolvida`, §5), com o prazo visível e um histórico em texto.
**O que o hóspede vê:** só as próprias mensagens, as decisões e os prazos, nunca dados do anfitrião além do necessário. No piloto, a tela diz "Vamos mediar com o anfitrião. Não há valor retido pela casalivre nesta reserva."
**Critérios de aceite:** (1) a disputa não pode ser aberta fora da janela; (2) um problema de check-in exige foto e fica bloqueado depois de 24 h da entrada; (3) com conflito, sem acordo ou com o prazo de 10 dias úteis vencido, o caso vai para o admin_plataforma; (4) cada mudança é avisada (no painel até a D3) e fica no log.

## 7. Porta futura: alteração de datas (UC-H21)
Sem tela na S7. O botão "Alterar datas" fica desabilitado, com a explicação "Em breve. Por enquanto, cancele e faça uma nova reserva, ou fale com o anfitrião." O desenho previsto é uma solicitação ao anfitrião com o novo total recalculado e a aprovação dele (spec-06).

## 8. WCAG 2.2 AA
- As abas de Viagens seguem o padrão Tabs da APG. Os selos de status têm texto e ícone (1.4.1).
- Prazos com texto absoluto e relativo, anunciados só quando falta 1 h e quando expira (4.1.3, 2.2.1).
- A simulação de reembolso é uma tabela ou lista estruturada, com os valores em "R$ 1.234,56" lidos por extenso. O total do reembolso é anunciado por role=status quando muda (1.3.1, 4.1.3).
- Cancelar pede uma confirmação explícita, com o valor (3.3.4). O foco vai para o título do resultado.
- O formulário da portaria e o de disputa têm rótulos visíveis, erros inline em texto e upload com alternativa ao arrastar (3.3.1, 2.5.7).
- Alvos de no mínimo 24 px (2.5.8). O foco não fica coberto pela barra de ações fixa no mobile (2.4.11).

## Requisitos de Segurança/LGPD (parecer de 16/10, spec-09)
Fonte: `/workspace/casalivre-seguranca-evidence/specs-ui-04-06/relatorio.md`. O texto técnico foi transcrito sem alteração.
**Decisões de tela:**
- **Fotos da disputa** (09-1, 09-2): são re-codificadas, com **limite de quantidade e de tamanho** *[E: 10 fotos, 10 MB cada]*. O nome é gerado pelo servidor, o arquivo fica **fora do webroot** e o **EXIF/GPS é removido**. A mensagem de limite é "Até 10 fotos de até 10 MB."
- **Quem vê as fotos** (09-3, decisão do Produto): as partes e o mediador. **Do lado do anfitrião, o proprietário e o gestor com delegação do imóvel**. **Os outros operadores não veem**.
- **Retenção** (09-4): as fotos e as mensagens da disputa ficam **12 meses depois da resolução**; o resultado e o log, **5 anos** (auditoria); legal hold em chargeback, Procon ou ação ([decidido, parecer J33]).
- **Uma só disputa por reserva**, dentro do prazo depois do check-out (09-9). Uma segunda tentativa mostra "Já existe uma disputa para esta reserva." + "Ver disputa".
- **PDF do comprovante** (09-5 a 09-7): é gerado a partir do **snapshot da reserva**, com **acesso autenticado**. O PDF **não traz CPF, chave Pix completa nem contatos**.
- **Cancelamento** (09-8):
  - o reembolso é **calculado no servidor**, com a **versão da simulação**;
  - se o valor mudou desde a simulação, o servidor responde **409**, e a tela mostra "O valor do reembolso mudou para R$ X. Revise antes de confirmar.";
  - a transição de estado é **atômica e idempotente**: um clique duplo gera um só cancelamento.
- **Endereço** (09-10): só aparece com a reserva **Confirmada** e **some 7 dias depois do check-out** ("Endereço disponível até [check-out + 7 dias]"), checado no servidor.

**Critérios de aceite:** (1) uma foto enviada não tem EXIF nem GPS, e um arquivo acima do limite é recusado com a mensagem; (2) um operador sem delegação do imóvel não abre as fotos, e o servidor responde 404/403; (3) 12 meses depois da resolução, as fotos e a disputa são purgadas; (4) uma segunda disputa na mesma reserva é recusada; (5) o PDF exige login e não contém CPF, chave Pix completa nem contatos; (6) com o valor alterado depois da simulação, a resposta é 409 e o novo valor aparece; (7) um clique duplo em cancelar gera um só cancelamento; (8) o endereço não é retornado (nem na tela nem na API) fora de Confirmada e depois da estadia.

- **09-1** (**Alta**): **Upload das fotos da disputa** (:62 só cita o 05-4). Exigir: tipo pelo **conteúdo** (magic bytes; JPEG/PNG/WebP/HEIC → JPEG/WebP), **re-encode** sempre (GD/Imagick/vips) e servir só a versão re-codificada; limite de **tamanho** (ex.: 10 MB) **e de pixels** (ex.: 40 MP, checado no cabeçalho **antes** de decodificar) contra decompression bomb; limites do Imagick (`policy.xml`: memory/area/disk/time; formatos desabilitados: MVG, MSL, EPHEMERAL, URL, HTTPS, SVG, PS/PDF); no máximo N fotos por disputa (ex.: 10); nome gerado pelo servidor (aleatório), nome original só como texto escapado; armazenamento **fora do webroot**. Reutilizar o pipeline existente de fotos (PhotoStorage/image-worker) com uma fila própria. · :62
- **09-2** (Média): **EXIF:** a re-codificação remove EXIF, GPS e XMP (sem `-strip` falhar em silêncio: teste que confere a ausência de GPS). Se a foto servir como prova, **antes** do re-encode guardar o **SHA-256 do original** e a data do upload (não o original, salvo exigência: J32). Se o original precisar ser guardado, cifrado (envelope do KYC), com o mesmo prazo e acesso só do admin. A orientação da imagem é aplicada antes de remover o EXIF. · :62
- **09-3** (**Alta**): **Acesso às fotos:** só as partes da reserva (o hóspede que abriu, quem tem papel no imóvel) e o admin que media, servido por **controlador autenticado** com a checagem a cada requisição (disputa → reserva → papel), 404 para os outros (IDs opacos), `Content-Disposition: inline` só para imagem re-codificada com `Content-Type` fixo, `nosniff`, `Cache-Control: private, no-store`; log de cada visualização. A spec diz que o hóspede vê só as próprias mensagens e decisões (:62): o anfitrião vê as fotos? Definir (sugestão: sim, as partes veem as provas uma da outra). · :62
- **09-4** (Média): **Retenção das fotos e da disputa:** sem prazo. Proposta: fotos e mensagens da disputa ficam **12 meses depois de resolvida** (prazo de reclamação a validar, J33), e o resultado e o log seguem a auditoria. **Legal hold** em caso de chargeback, ação ou ordem judicial. Purga automática (cron idempotente). Na exclusão de conta: a disputa aberta **impede** a purga (já no PurgeGuard); a disputa resolvida fica com o autor anonimizado. · :62-63
- **09-5** (**Alta**): **Comprovante em PDF** (:19): geração **no servidor** a partir de dados estruturados (snapshot), sem HTML vindo do usuário. Se usar HTML → PDF (dompdf/wkhtmltopdf/Chromium), escapar todos os campos (nome do imóvel, nome do hóspede, títulos vêm de usuários) e **desligar o acesso remoto** do renderizador (dompdf `isRemoteEnabled=false`, `isPhpEnabled=false`, `chroot` no diretório de assets; wkhtmltopdf `--disable-local-file-access` + allowlist; Chromium sem rede). Fontes e imagens **só locais** (logo do tema lido do disco, não por URL), o que impede SSRF e leitura de arquivo local (`file://`). Preferir uma lib que desenha o PDF direto (sem HTML) se for simples. Sem JavaScript, formulários nem anexos no PDF. Os metadados do PDF (autor/produtor) sem dados pessoais. · :19
- **09-6** (Média): **Acesso ao PDF:** de preferência **só com login** (o hóspede ou quem tem papel na reserva), gerado sob demanda e servido com `Content-Disposition: attachment`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex`. Se houver link compartilhável: token aleatório de ≥ 128 bits, uso limitado, validade curta (ex.: 7 dias), **guardado como hash**, revogável, sem id sequencial na URL, sem cache em disco público, com `Referrer-Policy: no-referrer`. · :19
- **09-7** (Média): **Dados mínimos no comprovante:** código, imóvel (nome e cidade; o endereço completo só se for necessário), datas, linhas e total, "pago diretamente ao anfitrião em [data]". **Sem** CPF completo, chave Pix completa (mascarar), telefone e e-mail; nome do hóspede: o nome de quem reservou (necessário). Rótulo "comprovante de registro, não é recibo fiscal" (:19, J34). · :19
- **09-8** (**Alta**): **Autorização e corrida no cancelamento:** só o hóspede titular da reserva (e o anfitrião no fluxo dele), checado no servidor com o vínculo (IDOR: 404). O **reembolso é calculado no servidor** a partir do snapshot da política e do preço (:38, :54-4), nunca com valores do cliente. A confirmação reenvia só o id e uma **versão/ETag da simulação**: se o valor mudou (virou o dia, outra regra), responder 409 e recalcular. A transição de estado é **atômica** (`UPDATE reservas SET status='cancelada' WHERE id=? AND status IN (...) AND versao=?`, 1 linha ou 409), o que impede cancelar duas vezes, cancelar × aprovar/pagar/check-in ao mesmo tempo e cancelamento do anfitrião × do hóspede. Idempotência por chave de requisição. O momento de referência é **a hora do servidor** (UTC) na transação, nunca o horário do cliente. Log com antes e depois (regra de auditoria). · :38-54, :58
- **09-9** (Média): **Abuso da disputa:** só reservas em estadia ou concluídas (:63-1, servidor), **uma disputa aberta por reserva**, uma janela de prazo depois do check-out (ex.: 14 dias, alinhado às avaliações), rate limit por usuário (ex.: 3 disputas/30 dias) e por IP, limite de fotos e de texto (07-2), disputas em série → sinal na confiabilidade e revisão. Força maior do anfitrião (:58): o comprovante segue as mesmas regras de upload (09-1) e acesso. · :58, :62-63
- **09-10** (Baixa): **Endereço** só em Confirmada (:9, ok), checado no servidor (não só esconder na UI), e escondido de novo depois do check-out + 7 dias (coerente com 08-12). A chave Pix exibida em Aguardando pagamento vem do snapshot e nunca uma "Pendente até" (:29-2, ok; spec-05). · :9, :17, :29
- **09-11** (Info): Lista da portaria (:31-35): coerente com P2 (90 dias, 7 se cancelada) e com a finalidade exibida. Ok. "Cancelamentos em série" (:52): a regra no servidor, com o aviso antes. · :31-35, :52

- [decidido, parecer J32] **J32**: Guardar o original das fotos (com EXIF) ou só o hash como prova na disputa: o que basta como meio de prova. → **Decisão:** **hash SHA-256 do original + data do upload**; o original cifrado só em disputa de valor relevante, chargeback ou ação.
- [decidido, parecer J33] **J33**: O prazo de retenção das disputas e das fotos depois da resolução (prazos de reclamação/CDC) e o legal hold. → **Decisão:** **12 meses depois da resolução** para fotos e mensagens; resultado e log por 5 anos.
- [decidido, parecer J34] **J34**: Chamar o comprovante do piloto de "recibo" e quais dados ele precisa ter (já pendente, :78). → **Decisão:** **não**; o nome é **"Comprovante de reserva"**.
- [decidido, parecer J35] **J35**: A exceção do CDC art. 49 para check-in em menos de 7 dias (:41, :78): afeta a regra de reembolso calculada no servidor. → **Decisão:** o CDC art. 49 **se aplica até o check-in** dentro dos 7 dias; não há exceção.

## Pendências para Produto
Os itens pendentes não bloqueiam o restante da spec.
1. [pendente: Rafael + Jurídico e Tributário] D14: textos finais dos termos e das políticas. As cláusulas mínimas estão no parecer (itens 17 a 22, J35).
2. [pendente: Rafael + Jurídico e Tributário] Proteção ao hóspede no piloto (parecer item 17, risco alto): só abrir o piloto com KYC do anfitrião, chave Pix vinculada e canal de denúncia.
3. [pendente: Rafael] D3: avisos de status, de prazos, de cancelamento e de disputa. Até lá, só em Viagens e no painel.
4. [pendente: Rafael] Aceitar o custo do arrependimento de 7 dias até o check-in (o anfitrião perde a reserva) e embutir no contrato do anfitrião (parecer J35, pendência 3). A regra já está aplicada nas telas.
