# Plano de Rotas da API — CasaLivre

**Objetivo:** completar a API JSON para que o frontend possa rodar em servidor separado.  
**Data:** 2026-10-12  
**Status:** implementado (todos os endpoints do contrato)

## Convenções gerais

- Prefixo: `/api`
- Autenticação: sessão atual (depois migrar para token se necessário)
- Respostas sempre `Content-Type: application/json`
- Erros padrão:
  ```json
  { "error": "mensagem", "code": "CODIGO_OPCIONAL" }
  ```
- Códigos HTTP: 200, 201, 400, 401, 403, 404, 422
- Datas: `YYYY-MM-DD` ou ISO 8601 quando houver hora
- Referência de imóvel: hash público de 16 caracteres (`public_hash`)

---

## Já existentes (manter)

| Método | Rota | Descrição |
|--------|------|-----------|
| POST | `/api/sessao` | Login |
| DELETE | `/api/sessao` | Logout |
| POST | `/api/users` | Cadastro |
| GET | `/api/users/eu` | Dados do usuário logado |
| PUT | `/api/users/eu` | Atualizar usuário |
| DELETE | `/api/users/eu` | Excluir conta |
| GET | `/api/casas` | Listar imóveis do usuário |
| POST | `/api/casas` | Criar imóvel |
| GET | `/api/casas/{hash}` | Detalhe do imóvel |
| PUT | `/api/casas/{hash}` | Atualizar imóvel |
| DELETE | `/api/casas/{hash}` | Excluir imóvel |
| POST | `/api/casas/{hash}/fotos` | Upload de fotos |
| DELETE | `/api/casas/{hash}/fotos/{id}` | Excluir foto |
| GET | `/api/cidades?q=` | Autocomplete de cidades |
| GET | `/api/ceps?q=` | Consulta de CEP |

---

## Novas rotas a implementar

### Público / Busca

#### `GET /api/busca`
Busca pública de imóveis.

**Query params:**
- `cidade` (string)
- `checkin` (YYYY-MM-DD)
- `checkout` (YYYY-MM-DD)
- `hospedes` (int)
- `preco_min`, `preco_max` (int)
- `amenidades` (array de strings)
- `score_min` (float)
- `raio` (km, opcional)

**Resposta 200:**
```json
{
  "items": [
    {
      "id": "abc123def4567890",
      "title": "Casa na praia",
      "city": "Florianópolis, SC",
      "nightly_price": 450,
      "photo": "/uploads/...",
      "score": 9.2,
      "quote": { "nights": 3, "total": 1350, "nightly": 450 }
    }
  ],
  "facets": { "amenidades": { "pool": 12, "bbq": 8 } }
}
```

#### `GET /api/imoveis/{hash}`
Detalhe público do imóvel.

**Query params opcionais:** `checkin`, `checkout`

**Resposta 200:**
```json
{
  "id": "abc123def4567890",
  "title": "...",
  "description": "...",
  "city": "...",
  "photos": [{ "id": 1, "path": "...", "is_primary": true }],
  "score": 9.1,
  "quote": { "nights": 2, "total": 900, "nightly": 450 },
  "amenities": ["pool", "wifi"],
  "bedrooms": 2,
  "max_guests": 6
}
```

#### `POST /api/imoveis/{hash}/avaliar`
Enviar avaliação.

**Body:**
```json
{ "score": 9, "author": "João", "comment": "Ótimo" }
```

**Resposta 201:**
```json
{ "ok": true }
```

---

### Autenticação / Conta

#### `POST /api/auth/verificar`
Ativar conta com token.

**Body:** `{ "token": "..." }`

**Resposta 200:**
```json
{ "ok": true, "email": "user@example.com" }
```

#### `POST /api/auth/senha/esqueci`
Solicitar reset de senha.

**Body:** `{ "email": "..." }`

**Resposta 200:**
```json
{ "ok": true }
```

#### `POST /api/auth/senha/redefinir`
Redefinir senha.

**Body:**
```json
{ "token": "...", "password": "...", "password_confirm": "..." }
```

#### `POST /api/idioma`
Trocar idioma da sessão.

**Body:** `{ "locale": "pt-BR" | "en" | "es" }`

---

### Área do proprietário

#### `GET /api/painel`
Lista dos imóveis do usuário logado (com foto principal).

**Resposta 200:**
```json
{
  "items": [
    {
      "id": "abc123...",
      "title": "...",
      "city": "...",
      "photo": "...",
      "status": "active"
    }
  ]
}
```

#### `GET /api/imoveis/{hash}/editar`
Dados completos para o formulário de edição (incluindo fotos).

#### `POST /api/imoveis/{hash}/fotos/capa`
Definir foto de capa.

**Body:** `{ "foto_id": 123 }`

#### `GET /api/imoveis/{hash}/calendario`
Dados do calendário (preços, mínimos, bloqueios).

**Query:** `inicio`, `fim` (YYYY-MM-DD)

**Resposta 200:**
```json
{
  "diaria": { "2026-10-15": 500, "2026-10-16": 500 },
  "minimo_noites": { "2026-10-15": 2 },
  "bloqueado": { "2026-10-15": 0 }
}
```

#### `POST /api/imoveis/{hash}/calendario`
Salvar alterações de calendário.

**Body:** ação, intervalo, preço, mínimo, bloqueio etc. (seguir o formato atual do form)

#### `POST /api/imoveis/{hash}/ical/importar`
Importar feed iCal.

**Body:** `{ "url": "https://..." }`

#### `DELETE /api/imoveis/{hash}/ical/{id}`
Remover feed iCal.

#### `GET /api/imoveis/{hash}/calendario.ics`
Exportar calendário em formato ICS (retorna `text/calendar`).

---

## Ordem sugerida de implementação

1. `GET /api/busca`
2. `GET /api/imoveis/{hash}`
3. `GET /api/painel`
4. Calendário (`GET` + `POST`)
5. Fotos (capa + listagem no editar)
6. Auth extras (verificar, senha)
7. Avaliar + iCal

---

## Notas

- Manter os endpoints atuais de `/api/casas` e `/api/users`.
- As rotas `/cidades` e `/ceps` podem ser movidas para `/api/cidades` e `/api/ceps` (opcional, por consistência).
- Quando o frontend for separado, a autenticação por sessão precisará de ajustes (CORS + cookie `SameSite`/`Secure` ou token).
