# Portal de Gestão — Grupo Setta · Publicação e banco MySQL (`pqualidade`)

Versões: **sistema 2.13.0** · API **1.1.0** · estrutura do banco **v2**

## 1. Arquitetura

```
Navegador (index.html)  →  api.php / /api/...  (PHP 7.4+)  →  MySQL "pqualidade"
                        ←  JSON (sem senha, sem string de conexão) ←
```

- **Nenhum acesso direto ao banco.** O navegador só fala com `api.php`. A conexão com o MySQL usa credenciais lidas de variáveis de ambiente (arquivo `.env`), nunca do HTML, do JavaScript nem de arquivos públicos.
- **MySQL é a fonte única dos dados** dos 4 módulos:
  - Portal (Gestão de Documentos, Mapa de Processo, Gestão de Risco, usuários, empresas…);
  - Solicitações à Gestão;
  - Planos de Ação;
  - Comunicado Interno.
- **O navegador só guarda uma cópia temporária** (IndexedDB) do que o servidor já confirmou. Essa cópia é apagada ao sair do sistema.
- **No servidor, a cada requisição, a API faz:**
  - autenticação (sessão PHP);
  - permissões por perfil;
  - segregação por empresa;
  - validação de campos obrigatórios e de duplicidade;
  - controle de alteração simultânea (revisão do registro);
  - log de cada operação.

## 2. Dependências

| Item | Versão |
|---|---|
| PHP | 7.4 ou superior (recomendado 8.1–8.3) |
| Extensões PHP | `pdo_mysql`, `json`, `openssl`, `zlib`, `mbstring` (ou `iconv`) |
| Banco | MySQL 5.7+ ou MariaDB 10.3+ |
| Servidor web | Apache com `.htaccess` e `mod_rewrite` (padrão da Localweb) |
| Navegador | Chrome, Edge, Firefox ou Safari atuais |

- **Sem Node, Composer, build ou compilação.** O frontend é um único `index.html`; o backend é PHP puro.
- **Internet nos navegadores:** os gráficos e o Excel usam bibliotecas públicas do cdnjs (Chart.js, SheetJS, Mammoth, JSZip).

## 3. Variáveis de ambiente (`.env`)

Use o modelo `.env.example`. O arquivo `.env` com a senha real **não vai no ZIP** e **não vai para o Git**: ele está no `.gitignore` e é bloqueado pelo `.htaccess`.

| Variável | Obrigatória | Exemplo / significado |
|---|---|---|
| `DB_HOST` | sim | `pqualidade.mysql.dbaas.com.br` |
| `DB_PORT` | não | `3306` (padrão; a porta 3306 respondeu nesse servidor) |
| `DB_NAME` | sim | `pqualidade` |
| `DB_USER` | sim | `pqualidade` |
| `DB_PASSWORD` | sim | senha do banco, **entre aspas simples** por causa de `@` e `!` |
| `SETTA_CHAVE_INSTALACAO` | sim | chave pedida uma vez na instalação e no teste de conexão antes dela |
| `DB_PREFIXO` | não | `setta_` (prefixo das tabelas e visões) |
| `SETTA_SESSAO_MINUTOS` | não | `480` (inatividade até exigir novo login) |
| `SETTA_MAX_ARQUIVO_MB` | não | `40` |
| `SETTA_MAX_TENTATIVAS_LOGIN` | não | `5` |
| `SETTA_EXIGIR_HTTPS` | não | `true` depois que o SSL estiver ativo |
| `SETTA_DIR_DADOS` | não | caminho absoluto da pasta de dados, se quiser tirá-la do `public_html` |
| `SETTA_DEBUG` | não | `false` (nunca `true` em produção) |

**Onde colocar o `.env`.** A API procura, nesta ordem, e usa o primeiro que encontrar:
1. variáveis de ambiente reais do servidor;
2. o arquivo indicado em `SETTA_ENV_FILE`;
3. `../.env`, **uma pasta acima do portal**. É o recomendado: com o portal na raiz do `public_html`, o `.env` fica fora da área pública;
4. `./.env`, na mesma pasta do `api.php`. Também é bloqueado pelo `.htaccess`.

> Se o portal ficar numa **subpasta** (ex.: `public_html/portal/`), coloque o `.env` dentro da própria pasta do portal (opção 4), **não** no `public_html`.

## 4. Configuração do banco

O banco `pqualidade` já existe na Localweb. **Não é preciso rodar SQL manualmente**: na tela "Instalação do servidor", a API cria as tabelas e visões sozinha. O processo é idempotente e nunca apaga dados.

Alternativas, se preferir:
- **phpMyAdmin:** importar o `db.sql`;
- **SSH:** `php ferramentas/instalar-banco.php`.

O usuário `pqualidade` precisa destes privilégios no banco, que o dono do banco DBaaS normalmente já tem:
- `SELECT`, `INSERT`, `UPDATE`, `DELETE`;
- `CREATE`, `ALTER`, `INDEX`, `REFERENCES`;
- `CREATE VIEW`. Sem ele as visões relacionais não são criadas, mas o sistema funciona normalmente.

**Acesso de fora:** o servidor do banco precisa aceitar conexões vindas do servidor do site. Na Localweb, site e DBaaS na mesma conta normalmente já se conectam. Se o teste de conexão disser "Não foi possível alcançar o servidor do banco", libere o acesso no painel do DBaaS.

## 5. Instalação, passo a passo

1. **Enviar os arquivos.** Envie todo o conteúdo da pasta `PORTAL-GESTAO-SETTA/` para o `public_html/` (ou uma subpasta), incluindo os arquivos ocultos `.htaccess`, `.user.ini` e `.gitignore`.
2. **Criar o `.env`.** Use o `.env` entregue à parte, já preenchido; se não o tiver, copie o `.env.example` para `.env` e preencha. Envie-o para **uma pasta acima** do `public_html` (ou para a pasta do portal, se o portal estiver numa subpasta).
3. **Permissões:**
   - `dados/`, `dados/arquivos/`, `dados/backups/` e `dados/logs/` com 755 (ou 775 se o diagnóstico pedir);
   - demais arquivos com 644;
   - `.env` com 600 ou 640.
4. **PHP:** no painel, selecione PHP 8.1–8.3 para o domínio.
5. **Testar a conexão:**
   - abra o site; aparece a tela **Instalação do servidor**;
   - digite a chave de instalação e clique em **Testar conexão com o banco**;
   - o resultado esperado é:
     ```
     Conexão com MySQL: OK
     Banco: pqualidade
     Servidor: pqualidade.mysql.dbaas.com.br
     ```
   - pela linha de comando (SSH), o equivalente é `php ferramentas/teste-conexao.php`.
6. **Instalar:**
   - informe o login e a senha do administrador (mínimo 8 caracteres) e clique em **Instalar e entrar**;
   - as tabelas e visões são criadas e a base inicial é gravada no MySQL;
   - se este navegador já tiver dados da versão antiga **no mesmo endereço**, marque "Enviar ao servidor os dados que já existem neste navegador".
7. **HTTPS:** ative o SSL no painel. Depois disso:
   - remova o `#` das duas linhas "HTTPS obrigatório" do `.htaccess`;
   - use `SETTA_EXIGIR_HTTPS=true` no `.env`.

## 6. Comandos

| Objetivo | Comando |
|---|---|
| Instalar dependências | nenhum (não há pacotes a instalar) |
| Build | nenhum (frontend estático, backend PHP interpretado) |
| Executar localmente | `php -S 127.0.0.1:8080` na pasta do portal (o servidor embutido não lê `.htaccess`: use só para testes) |
| Produção | enviar os arquivos para o Apache da Localweb (seção 5) |
| Testar a conexão (SSH) | `php ferramentas/teste-conexao.php` |
| Criar/atualizar tabelas e visões (SSH) | `php ferramentas/instalar-banco.php` |
| Gerar o `db.sql` | `php ferramentas/gerar-db-sql.php` |
| Migrar um backup JSON para o MySQL (SSH) | `php ferramentas/importar-backup.php arquivo.json --confirmar` |

A pasta `ferramentas/` só funciona pela linha de comando: o navegador recebe 403.

## 7. Estrutura do banco

| Tabela | Conteúdo |
|---|---|
| `setta_registros` | **Todos os registros dos 4 módulos.** Uma linha por registro: chave `(ns, store, id)` = módulo, entidade, identificador. Colunas: `empresa_id` (segregação, indexada), `rev` (revisão), `criado_em`, `criado_por`, `atualizado_em`, `atualizado_por`, `dados` (registro completo em JSON). |
| `setta_arquivos` | Metadados dos anexos (tipo, tamanho, SHA-256). FK para `setta_registros` com `ON DELETE CASCADE`. O conteúdo fica em `dados/arquivos/`. |
| `setta_lixeira` | Cópia de todo registro excluído ou substituído (exclusão, limpeza, restauração). |
| `setta_log` | Log técnico de cada operação: data/hora, usuário, IP, endpoint, módulo, entidade, registro, status, código HTTP e mensagem. **Nunca contém senha.** |
| `setta_login_tentativas` | Tentativas de login (bloqueio de 15 min após erros repetidos). |
| `setta_meta` | Versão da estrutura, data da instalação, marca de sincronização. |

**Por que JSON + visões, e não uma tabela por campo.** Os módulos guardam estruturas aninhadas e que evoluem a cada versão: linhas do SIPOC, controles e avaliações de risco, ciclos do fluxo documental, campos dinâmicos das solicitações. Uma tabela canônica com o registro completo evita reescrever o sistema e perder dados a cada evolução.

Para consulta relacional direta no MySQL, a instalação cria **22 visões** com colunas tipadas, empresa e auditoria:

| Grupo | Visões |
|---|---|
| Cadastros | `setta_vw_empresas`, `setta_vw_unidades_negocio`, `setta_vw_usuarios` (sem senha), `setta_vw_tipos_documento` |
| Gestão de Documentos | `setta_vw_documentos`, `setta_vw_documento_versoes`, `setta_vw_documento_historico`, `setta_vw_documento_revisoes`, `setta_vw_documento_validadores`, `setta_vw_documento_aprovadores`, `setta_vw_pastas_publicados` |
| Mapa de Processo | `setta_vw_mapa_processos`, `setta_vw_mapa_subprocessos` |
| Gestão de Risco | `setta_vw_riscos` |
| Auditoria | `setta_vw_auditoria` |
| Solicitações | `setta_vw_solicitacoes`, `setta_vw_solicitacao_tipos`, `setta_vw_solicitacao_historico`, `setta_vw_solicitacao_anexos` |
| Planos de Ação | `setta_vw_planos_acao`, `setta_vw_plano_acoes`, `setta_vw_plano_evidencias` |

Exemplo: `SELECT codigo, titulo, status, empresa_id, atualizado_por FROM setta_vw_documentos;`

**Integridade.** Os relacionamentos entre entidades ficam dentro dos registros. O painel **Geral → Backup e Restauração → Banco de dados (MySQL) → Diagnóstico e integridade** conta as referências órfãs de 17 relacionamentos, entre eles:
- versão → documento;
- processo → unidade de negócio;
- solicitação → tipo;
- ação → plano;
- registro → empresa.

## 8. API

**Portal (usada pelo `index.html`):** `api.php?acao=` seguido de uma destas ações:

| Ação | Função |
|---|---|
| `estado` | situação do servidor e da sessão |
| `instalar` | instalação inicial |
| `login` / `logout` | entrar e sair |
| `sincronizar&ns=` | ler os dados de um módulo |
| `gravar` | gravar um lote (transacional) |
| `arquivo` | conteúdo de um anexo |
| `backup` | backup completo |
| `restaurar` | restauração |
| `teste_conexao` | teste de conexão com o MySQL |
| `diagnostico` | verificações do servidor e integridade |

**REST por módulo:** os mesmos controles de sessão, permissão, empresa, validação e revisão.

| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/{modulo}/{entidade}` | lista visível ao usuário (`?q=` pesquisa, `?empresa_id=`, `?limite=`, `?inicio=`) |
| GET | `/api/{modulo}/{entidade}/{id}` | um registro (ETag = revisão) |
| GET | `/api/{modulo}/{entidade}/{id}/arquivo` | conteúdo do anexo |
| POST | `/api/{modulo}/{entidade}` | cria (201). Id repetido ou valor único repetido → 409 "Registro duplicado" |
| PUT | `/api/{modulo}/{entidade}/{id}` | atualiza; exige `If-Match: <revisão>`. Sem ele → 428; alteração concorrente → 409 |
| DELETE | `/api/{modulo}/{entidade}/{id}` | exclui (cópia na lixeira) |
| GET | `/api/estado` | situação do servidor e da sessão |
| POST | `/api/login`, `/api/logout` | entrar e sair |
| GET/POST | `/api/teste-conexao` | teste de conexão |
| GET | `/api/diagnostico`, `/api/backup` | diagnóstico e backup (Administrador) |

- **Módulos:** `portal` (ou `gestao`), `solicitacoes`, `planos`, `comunicado`.
- **Entidades:** iguais às do sistema. Exemplos: `portal/documentos`, `portal/usuarios`, `portal/setores`, `portal/mpProcessos`, `portal/grRiscos`, `solicitacoes/reqs`, `planos/planos`, `planos/acoes`.
- **Gravações** (POST/PUT/DELETE) exigem os cabeçalhos `X-Requested-With: SettaPortal` e `X-Setta-Token` (devolvido no login).
- **Servidores que bloqueiam PUT ou DELETE:** use POST com o cabeçalho `X-HTTP-Method-Override`.

**Validações do servidor:**
- **Campos obrigatórios:**
  - usuário: login, nome e perfil;
  - empresa: sigla;
  - Unidade de Negócio e tipo de documento: nome e sigla;
  - documento: código e título;
  - processo: código e nome;
  - solicitação: título.
- **Duplicidade:**
  - login;
  - sigla e CNPJ (comparado com ou sem máscara) da empresa;
  - sigla da Unidade de Negócio, por empresa;
  - sigla do tipo de documento;
  - código do documento, por empresa;
  - código do processo.

**Mensagens de erro** (o usuário vê a mensagem; o detalhe técnico fica só no log do servidor):

| Situação | HTTP | Mensagem (resumo) |
|---|---|---|
| banco fora do ar / host inalcançável | 503 | "Banco de dados indisponível: não foi possível alcançar o servidor do banco… Nada foi gravado." |
| usuário ou senha do banco errados | 503 | "O banco recusou o usuário ou a senha…" |
| banco inexistente ou sem acesso | 503 | "O usuário do banco não tem acesso ao banco informado em DB_NAME…" |
| tempo esgotado | 503 / 0 | "O servidor do banco não respondeu a tempo" / "O servidor não respondeu a tempo… NÃO foi confirmada" |
| sem internet | 0 | "Sem conexão com o servidor… NÃO foi gravada" |
| sessão expirada / login | 401 | "Sua sessão expirou… Entre novamente" / "Usuário ou senha inválidos" |
| sem permissão / outra empresa | 403 | "Somente o Administrador…" / "Operação bloqueada: empresa não autorizada…" |
| campo obrigatório | 422 | "Campo obrigatório não informado: …" |
| registro duplicado | 409 | "Registro duplicado: já existe …" |
| alterado por outro usuário | 409 | "Este registro foi alterado por outro usuário… Sua alteração NÃO foi gravada." |

## 9. Multiempresa

- **Isolamento no servidor e no banco.** Os registros por empresa levam `empresa_id` (coluna indexada em `setta_registros` e presente nas visões). A API só devolve e só aceita gravar registros das empresas do usuário, relidas do banco a cada requisição; o valor enviado pelo navegador nunca é aceito sem essa conferência.
- **Registros de outra empresa:** a leitura responde "não encontrado", e a gravação, "operação bloqueada".
- **Administrador:** vê todas as empresas.
- **Visão consolidada do grupo:** somente leitura, para o Administrador e para quem tem "visão corporativa".

## 10. Migração dos dados existentes

| Origem | Como migrar |
|---|---|
| Versão antiga (2.12 ou anterior, dados só no navegador) | Na versão antiga: Geral → Backup e Restauração → **CRIAR BACKUP COMPLETO** (`.backup`). Na versão nova: **RESTAURAR BACKUP**. Inclui todos os módulos, anexos e rascunhos do Comunicado. |
| Mesmo endereço, dados no navegador | Na instalação, marque **"Enviar ao servidor os dados que já existem neste navegador"**. |
| Backup JSON ("Backup do módulo" ou `/api/backup`) | Tela de restauração, ou `php ferramentas/importar-backup.php arquivo.json --confirmar`. |

**Duplicidades.** Antes de gravar, a API trata as duplicidades:
- id repetido: mantém a versão mais recente e informa;
- logins repetidos: recusa, para correção na origem, e nada é alterado.

**Proteção do estado anterior:**
- toda migração ou restauração guarda antes uma cópia do estado anterior em `dados/backups/`;
- os registros substituídos ficam também em `setta_lixeira`.

## 11. Backup e restauração

- **Geral → Backup e Restauração → CRIAR BACKUP COMPLETO.** Gera, **a partir do MySQL**, um arquivo criptografado (AES-256) com todos os módulos e anexos. A restauração grava no MySQL numa única transação e confere contagens e relacionamentos.
- **Backup do módulo (JSON)** e os backups próprios de Solicitações e Planos: também leem e gravam no MySQL.
- **Recomendado também:**
  - backup do banco pelo painel da Localweb (DBaaS);
  - cópia da pasta `dados/arquivos/` por FTP.

## 12. Atualização futura (nova versão)

1. Faça um **backup completo** (seção 11) e um backup do banco pelo painel.
2. Substitua `index.html`, `api.php`, `inc/`, `ferramentas/` e `.htaccess`. **Não** substitua o `.env` nem a pasta `dados/`.
3. Abra o portal. A API compara a versão da estrutura (`setta_meta.schema_versao`) e aplica sozinha o que faltar: novas tabelas e visões, sem apagar nada. A atualização fica registrada em `setta_log`. Pelo SSH também dá: `php ferramentas/instalar-banco.php`.
4. Confira em **Banco de dados (MySQL) → Diagnóstico e integridade**.

## 13. Como verificar erros

| Onde | O quê |
|---|---|
| Console do navegador (F12) | linhas `[SETTA-PERSIST]` com operação, módulo, registro, status, HTTP e mensagem |
| `dados/logs/api-erros.log` | erro técnico real (PHP e MySQL); nunca contém senha |
| `setta_log` | toda operação: data, usuário, IP, endpoint, módulo, entidade, registro, status, HTTP, mensagem |
| `setta_lixeira` | registros excluídos ou substituídos |
| Painel "Banco de dados (MySQL)" | teste de conexão, verificações do servidor, integridade |
