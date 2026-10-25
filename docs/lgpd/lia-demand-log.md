# Teste de balanceamento (LIA): registro de demanda da busca

ADR 001 §11 P7. Data: 2026-10-16; revisado em 2026-10-25 (ADR 001 §11.3: tipo `search`). Responsável: Jurídico. Base: LGPD (Lei 13.709/2018), art. 7º, IX, e art. 10.
Fontes: parecer `/workspace/casalivre/juridico/adr001-p7-demand-log-legal.md`; veredito de Segurança `/workspace/casalivre-seguranca-evidence/adr001-demand-log/verdict.md` (itens 1-7).
Referência: ANPD, *Guia Orientativo – Hipóteses legais de tratamento de dados pessoais – Legítimo Interesse*, versão 1.0, fev. 2024 (lançado em 02/02/2024; página atualizada em 23/01/2025), modelo em três fases: finalidade; necessidade; balanceamento e salvaguardas. Conferir nova versão em gov.br/anpd antes de revisar este documento.

## 1. Finalidade (art. 10, I)
Conhecer os termos de busca sem resultado e a contagem diária de buscas por `place_id`, por localidade e dia, para orientar a captação de anúncios e melhorar a oferta. Interesse legítimo, concreto e atual do controlador; benefício indireto ao titular (mais oferta onde há procura). Dados pessoais sensíveis não são tratados.

## 2. Necessidade (art. 6º, III; art. 10, §1º)
- Só o texto da busca sem resultado e o `place_id`; nenhuma conta, IP, sessão, localização do usuário ou horário mais fino que o dia (item 4).
- O texto é reduzido antes da contagem: normalizado, limitado a 64 caracteres e descartado se contiver e-mail, URL, telefone, CPF/CNPJ ou 6+ dígitos (item 1).
- Hash não é usado: seria reversível por dicionário e inútil para a análise (item 3).
- Para `search`: apenas `place_id`, dia e contagem.

## 3. Balanceamento
- Expectativa do titular: registrar buscas para melhorar o serviço é uso esperado em um site de busca, desde que informado (art. 10, §2º; linha "Busca" da política de privacidade).
- Risco: texto livre pode conter dado de terceiro. Mitigado pela redação (item 1) e pelo limiar k (item 2). Risco residual baixo: termo frequente com nome de pessoa (ex.: "casa do fulano"); se encontrado, apagar manualmente.
- Impacto: nenhuma decisão sobre o titular é tomada com esses dados.
- Conclusão: o interesse não se sobrepõe aos direitos e liberdades do titular, com as salvaguardas abaixo.

## 4. Salvaguardas (veredito de Segurança, itens 1-7)
1. Redação antes da contagem (HINCRBY).
2. Limiar: persistir só termos com contagem diária >= 5; descartar os demais.
3. Sem hash; texto reduzido + limiar.
4. Sem identificadores nem granularidade menor que o dia.
5. Guarda: chave Valkey `cl:demand:{date}` com EXPIRE 172800 (48 h); tabela PG por 12 meses, depois apagar ou consolidar em top-N mensal.
6. Mesma redação nos parâmetros de busca do `activity_log`; log de acesso do nginx com 14 dias; não incluir query string em logs de guarda mais longa.
7. Tabela PG legível só pelo papel BI/admin; INSERT/UPDATE só pelo worker de consolidação.

## 5. Fase transitória vs. agregado persistido
- Fase transitória (texto bruto na requisição, contagens Valkey de 48 h, `activity_log`, log do nginx): **é dado pessoal** potencialmente; coberta por este LIA. Não chamar de "anônima".
- Agregado persistido (texto reduzido, contagem diária >= 5, sem usuário/IP/sessão, por dia): muito provavelmente **dado anonimizado** (art. 5º, III; art. 12, caput e §1º — reidentificação não possível por meios técnicos razoáveis), fora do escopo da LGPD. A guarda de 12 meses é escolha de negócio.
- Agregado `search` (dia, `place_id` e contagem, sem usuário/IP/sessão): também muito provavelmente **dado anonimizado** (art. 5º, III; art. 12), com a mesma guarda de 12 meses.
- **Proibido** cruzar a tabela com outros dados (logs, contas, reservas) para identificar usuários: pelo art. 12, §2º, isso a torna dado pessoal novamente.

## 6. Direitos do titular e oposição (art. 18; art. 18, §2º)
- Transparência pela linha "Busca" da política de privacidade.
- Oposição: pelo canal do encarregado; como o agregado não identifica ninguém, o atendimento se limita à fase transitória (que expira em até 48 h / 14 dias no nginx). Não há como localizar termos de um titular no agregado, o que deve ser explicado na resposta (art. 18, §4º).

## 7. Revisão
Revisar se mudar finalidade, campos, limiar, guarda, se houver novos campos no registro `search` ou se houver cruzamento com outras bases.
