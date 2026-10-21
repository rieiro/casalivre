# CasaLivre: casos de uso de administração (PROPOSTA v0, 16/10/2026)

Esta é uma proposta para o Produto; ela não altera `casos-de-uso.md`. Referências: spec-02, spec-04 e spec-05.
Marque cada caso com: [ ] não implementado · [~] parcial · [x] implementado

## Árvore de gestão (spec-04)
- UC-A01 Ver a árvore com busca, expandir e recolher
- UC-A02 Criar nó filho (admin do nó pai)
- UC-A03 Renomear e alterar o rótulo de tipo de um nó
- UC-A04 Configurar valores herdados e sobrescrever por nó (prazo, taxa, limites)
- UC-A05 Convidar membros, atribuir papéis e delegações
- UC-A06 Mover nó com prévia de impacto (só admin_plataforma)
- UC-A07 Desativar e reativar nó

## Canais de vitrine (spec-04)
- UC-A08 Criar e editar canal (slug, domínio, DNS/SSL, nó dono)
- UC-A09 Escolher o tema do canal entre os temas do repositório (lista do manifesto), com prévia somente leitura; sem edição de tema nem upload de logo (decisão 2026-10-21)
- UC-A10 Vincular imóveis ao canal (lote, por imóvel): o marketplace casalivre vem ligado por padrão; os demais canais só com o consentimento do proprietário (pedido aceito ou adesão do proprietário), sem inclusão automática; remover do canal sem consentimento, com aviso ao proprietário e registro no log
- UC-A11 Definir o acréscimo percentual do canal com prévia de preço
- UC-A12 Publicar e despublicar canal

## Auditoria (spec-04)
- UC-A13 Consultar e filtrar o log de auditoria
- UC-A14 Exportar o log (com registro da exportação)

## Recebedores, split, ledger e termos (spec-05)
- UC-A15 Cadastrar recebedor PF ou PJ no nó (CPF/CNPJ, regime tributário)
- UC-A16 Enviar documentos e acompanhar o KYC (pendente, em análise, aprovado, recusado)
- UC-A17 Cadastrar ou trocar a chave Pix e os dados bancários (OTP, carência)
- UC-A18 Criar e editar regras de split em versões com vigência
- UC-A19 Simular o split com uma reserva de exemplo
- UC-A20 Consultar o ledger e as contas a receber por reserva
- UC-A21 Consultar o extrato do fundo regional
- UC-A22 Exportar o ledger e o extrato
- UC-A23 Gerir versões dos 3 termos (rascunho, publicação, vigência, diff)
- UC-A24 Exigir um novo aceite e consultar os aceites por reserva

## Faturamento do piloto (spec-05, apêndice A)
- UC-A25 Fechar o mês, gerar a fatura de comissão e dar baixa no pagamento

## Backoffice (spec-10)
- UC-A26 Operar as filas do backoffice (assumir, decidir, escalar, auditar acessos)
