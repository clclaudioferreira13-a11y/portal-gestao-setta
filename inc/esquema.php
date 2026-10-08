<?php
/* Portal de Gestão — Grupo Setta · ESTRUTURA DO BANCO (fonte única: db.sql é gerado a partir daqui por ferramentas/gerar-db-sql.php)
   ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
   Modelo:
   · setta_registros — tabela canônica de TODOS os módulos: 1 linha por registro, com chave (ns, store, id), empresa_id
     indexado (segregação multiempresa), revisão (concorrência), datas e usuário de criação/alteração. O conteúdo completo do
     registro fica em `dados` (JSON) porque os módulos têm estruturas aninhadas e evolutivas (SIPOC, controles, fluxos, avaliações).
   · setta_vw_* — VISÕES RELACIONAIS por entidade (documentos, versões, usuários, processos, riscos, solicitações, planos…):
     colunas tipadas extraídas do JSON, para consulta/relatório direto no MySQL sem alterar o sistema.
   · setta_arquivos (FK → registros), setta_lixeira, setta_log, setta_login_tentativas, setta_meta.
   ESQUEMA_VERSAO: ao publicar uma versão nova, garantir_esquema() aplica sozinho o que faltar (idempotente, nunca apaga dados). */
if (!defined('SETTA_API')) { http_response_code(403); exit; }

const ESQUEMA_VERSAO = 2;   // 1: tabelas · 2: visões relacionais

function esquema_tabelas(string $p): array {
    $opt = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
"CREATE TABLE IF NOT EXISTS {$p}registros (
  ns             VARCHAR(16)  NOT NULL COMMENT 'módulo: portal | sp (Solicitações) | pa (Planos de Ação) | ci (Comunicado)',
  store          VARCHAR(40)  NOT NULL COMMENT 'entidade do módulo (documentos, usuarios, reqs, planos…)',
  id             VARCHAR(100) NOT NULL,
  empresa_id     VARCHAR(100) NULL COMMENT 'empresa dona do registro (segregação multiempresa)',
  dados          LONGTEXT     NOT NULL COMMENT 'registro completo (JSON)',
  rev            INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'revisão — controle de alteração simultânea',
  criado_em      DATETIME     NOT NULL,
  criado_por     VARCHAR(100) NULL,
  atualizado_em  DATETIME     NOT NULL,
  atualizado_por VARCHAR(100) NULL,
  PRIMARY KEY (ns, store, id),
  KEY ix_registros_empresa (ns, store, empresa_id),
  KEY ix_registros_atualizado (atualizado_em)
)$opt",
"CREATE TABLE IF NOT EXISTS {$p}arquivos (
  ns         VARCHAR(16)  NOT NULL,
  store      VARCHAR(40)  NOT NULL,
  id         VARCHAR(100) NOT NULL,
  formato    VARCHAR(10)  NOT NULL COMMENT 'texto (base64 do portal) ou binario',
  tipo       VARCHAR(150) NULL,
  tamanho    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  sha256     CHAR(64)     NOT NULL,
  arquivo    VARCHAR(255) NOT NULL COMMENT 'nome do arquivo em dados/arquivos/',
  criado_em  DATETIME     NOT NULL,
  criado_por VARCHAR(100) NULL,
  PRIMARY KEY (ns, store, id),
  CONSTRAINT fk_{$p}arquivos_registro FOREIGN KEY (ns, store, id) REFERENCES {$p}registros (ns, store, id) ON DELETE CASCADE ON UPDATE CASCADE
)$opt",
"CREATE TABLE IF NOT EXISTS {$p}lixeira (
  seq        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ns         VARCHAR(16)  NOT NULL,
  store      VARCHAR(40)  NOT NULL,
  id         VARCHAR(100) NOT NULL,
  empresa_id VARCHAR(100) NULL,
  dados      LONGTEXT     NOT NULL,
  rev        INT UNSIGNED NOT NULL,
  operacao   VARCHAR(20)  NOT NULL COMMENT 'excluir | limpar | restaurar',
  usuario    VARCHAR(100) NULL,
  data       DATETIME     NOT NULL,
  PRIMARY KEY (seq),
  KEY ix_lixeira_registro (ns, store, id),
  KEY ix_lixeira_data (data)
)$opt",
"CREATE TABLE IF NOT EXISTS {$p}log (
  seq      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  data     DATETIME     NOT NULL,
  usuario  VARCHAR(100) NULL,
  ip       VARCHAR(45)  NULL,
  acao     VARCHAR(30)  NOT NULL COMMENT 'endpoint/operação',
  ns       VARCHAR(16)  NULL COMMENT 'módulo',
  store    VARCHAR(40)  NULL,
  registro VARCHAR(100) NULL,
  status   VARCHAR(10)  NOT NULL COMMENT 'sucesso | erro | negado | conflito',
  http     SMALLINT     NOT NULL DEFAULT 200,
  mensagem VARCHAR(500) NULL,
  PRIMARY KEY (seq),
  KEY ix_log_data (data),
  KEY ix_log_usuario (usuario, data)
)$opt",
"CREATE TABLE IF NOT EXISTS {$p}login_tentativas (
  seq     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login   VARCHAR(150) NOT NULL,
  ip      VARCHAR(45)  NOT NULL,
  data    DATETIME     NOT NULL,
  sucesso TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (seq),
  KEY ix_tent_login (login, data),
  KEY ix_tent_ip (ip, data)
)$opt",
"CREATE TABLE IF NOT EXISTS {$p}meta (
  chave VARCHAR(40) NOT NULL,
  valor TEXT        NULL,
  PRIMARY KEY (chave)
)$opt",
"INSERT IGNORE INTO {$p}meta (chave, valor) VALUES ('schema_versao', '1'), ('marca', '0')",
    ];
}

/* visões relacionais: [nome => [ns, store, [coluna => caminho JSON]]] — somente campos que o sistema realmente grava */
function esquema_visoes_def(): array {
    return [
        'vw_empresas'             => ['portal', 'empresas', ['nome' => 'nome', 'nome_fantasia' => 'nomeFantasia', 'razao_social' => 'razaoSocial', 'cnpj' => 'cnpj', 'sigla' => 'sigla', 'codigo' => 'codigo', 'status' => 'status']],
        'vw_unidades_negocio'     => ['portal', 'setores', ['nome' => 'nome', 'sigla' => 'sigla', 'status' => 'status']],
        'vw_usuarios'             => ['portal', 'usuarios', ['login' => 'login', 'nome' => 'nome', 'email' => 'email', 'cargo' => 'cargo', 'perfil' => 'perfil', 'status' => 'status', 'setor' => 'setor',
                                      'empresa_padrao_id' => 'empresaPadraoId', 'visao_corporativa' => 'visaoCorporativa', 'deve_trocar_senha' => 'mustReset']],   // a senha NUNCA aparece nas visões
        'vw_tipos_documento'      => ['portal', 'tipos', ['nome' => 'nome', 'sigla' => 'sigla', 'status' => 'status']],
        'vw_documentos'           => ['portal', 'documentos', ['codigo' => 'codigo', 'titulo' => 'titulo', 'tipo' => 'tipo', 'unidade_negocio' => 'setor', 'sigla_empresa' => 'unidade', 'status' => 'status',
                                      'versao' => 'versao', 'data_emissao' => 'dataEmissao', 'data_ultima_revisao' => 'dataUltimaRevisao', 'responsavel_id' => 'responsavelId',
                                      'responsavel_nome' => 'responsavelNome', 'versao_vigente_id' => 'versaoVigenteId', 'origem' => 'origem']],
        'vw_documento_versoes'    => ['portal', 'versoes', ['documento_id' => 'documentoId', 'numero' => 'numero', 'versao' => 'versao', 'status' => 'status', 'vigente' => 'vigente',
                                      'tipo_alteracao' => 'tipoAlteracao', 'descricao' => 'descricao', 'arquivo_nome' => 'arquivoNome', 'arquivo_blob_id' => 'arquivoBlobId', 'data' => 'data']],
        'vw_documento_historico'  => ['portal', 'historico', ['documento_id' => 'documentoId', 'versao_id' => 'versaoId', 'acao' => 'acao', 'status_anterior' => 'statusAnterior',
                                      'novo_status' => 'novoStatus', 'usuario' => 'usuario', 'usuario_id' => 'usuarioId', 'data_hora' => 'dataHora', 'observacao' => 'observacao']],
        'vw_documento_revisoes'   => ['portal', 'revisoes', ['documento_id' => 'documentoId', 'status' => 'status']],
        'vw_documento_validadores'=> ['portal', 'docValidadores', ['documento_id' => 'documentoId', 'usuario_id' => 'usuarioId']],
        'vw_documento_aprovadores'=> ['portal', 'docAprovadores', ['documento_id' => 'documentoId', 'usuario_id' => 'usuarioId']],
        'vw_pastas_publicados'    => ['portal', 'pastas', ['nome' => 'nome', 'pai_id' => 'paiId', 'nivel' => 'nivel', 'ordem' => 'ordem']],
        'vw_mapa_processos'       => ['portal', 'mpProcessos', ['unidade_negocio_id' => 'setorId', 'codigo' => 'codigo', 'nome' => 'nome', 'objetivo' => 'objetivo', 'responsavel_id' => 'responsavelId',
                                      'status' => 'status', 'versao' => 'versao', 'removido' => 'removido']],
        'vw_mapa_subprocessos'    => ['portal', 'mpSubprocessos', ['processo_id' => 'processoId', 'codigo' => 'codigo', 'nome' => 'nome', 'responsavel_id' => 'responsavelId',
                                      'status' => 'status', 'versao' => 'versao', 'removido' => 'removido']],
        'vw_riscos'               => ['portal', 'grRiscos', ['codigo' => 'codigo', 'unidade_negocio_id' => 'setorId', 'processo_id' => 'processoId', 'subprocesso_id' => 'subprocessoId',
                                      'tipo' => 'tipo', 'descricao' => 'descricao', 'probabilidade' => 'prob', 'impacto' => 'imp', 'risco_inerente' => 'ri', 'classe_inerente' => 'classeRI',
                                      'eficacia' => 'eficacia', 'risco_residual' => 'rr', 'classe_residual' => 'classeRR', 'resposta' => 'resposta', 'status' => 'status',
                                      'responsavel_id' => 'responsavelId', 'data_avaliacao' => 'dataAvaliacao']],
        'vw_auditoria'            => ['portal', 'auditoria', ['entidade' => 'entidade', 'registro_id' => 'registroId', 'acao' => 'acao', 'campo' => 'campo',
                                      'valor_anterior' => 'valorAnterior', 'valor_novo' => 'valorNovo', 'usuario' => 'usuario', 'data_hora' => 'dataHora']],
        'vw_solicitacoes'         => ['sp', 'reqs', ['numero' => 'numero', 'titulo' => 'titulo', 'tipo_id' => 'tipo_id', 'status' => 'status', 'prioridade' => 'prioridade',
                                      'unidade_negocio_id' => 'setor_id', 'solicitante_id' => 'solicitante_id', 'responsavel_id' => 'responsavel_id', 'data_abertura' => 'data_abertura',
                                      'prazo' => 'prazo', 'data_conclusao' => 'data_conclusao', 'documento_id' => 'documento_id', 'glpi_numero' => 'glpi_numero']],
        'vw_solicitacao_tipos'    => ['sp', 'tipos', ['nome' => 'nome', 'sla_dias' => 'sla_dias', 'ativo' => 'ativo', 'gera_documento' => 'gera_documento']],
        'vw_solicitacao_historico'=> ['sp', 'hist', ['solicitacao_id' => 'req_id', 'acao' => 'acao', 'status_anterior' => 'status_anterior', 'novo_status' => 'novo_status',
                                      'usuario_id' => 'usuario_id', 'data' => 'data', 'comentario' => 'comentario']],
        'vw_solicitacao_anexos'   => ['sp', 'anexos', ['solicitacao_id' => 'req_id', 'nome' => 'nome', 'tipo' => 'tipo', 'tamanho' => 'tamanho', 'arquivo_id' => 'file_id',
                                      'usuario_id' => 'usuario_id', 'data' => 'data', 'excluido' => 'excluido']],
        'vw_planos_acao'          => ['pa', 'planos', ['codigo' => 'codigo', 'motivacao' => 'motivacao', 'unidade_negocio_id' => 'setor_id', 'responsavel_id' => 'responsavel_id', 'meta' => 'meta',
                                      'status_manual' => 'status_manual', 'situacao_aprovacao' => 'situacao_aprovacao', 'data_emissao' => 'data_emissao', 'proxima_revisao' => 'proxima_revisao']],
        'vw_plano_acoes'          => ['pa', 'acoes', ['plano_id' => 'plano_id', 'numero' => 'numero', 'descricao' => 'descricao', 'responsavel_id' => 'responsavel_id', 'prazo_atual' => 'prazo_atual',
                                      'status' => 'status', 'percentual' => 'percentual', 'data_conclusao' => 'data_conclusao', 'excluida' => 'excluida']],
        'vw_plano_evidencias'     => ['pa', 'evidencias', ['acao_id' => 'acao_id', 'nome_arquivo' => 'nome_arquivo', 'descricao' => 'descricao']],
    ];
}
function esquema_visoes(string $p): array {
    $sql = [];
    foreach (esquema_visoes_def() as $nome => [$ns, $store, $cols]) {
        $c = [];
        foreach ($cols as $col => $campo) $c[] = "JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.$campo')) AS `$col`";
        $sql[] = "CREATE OR REPLACE VIEW {$p}{$nome} AS SELECT r.id, r.empresa_id, " . implode(', ', $c)
               . ", r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM {$p}registros r WHERE r.ns = '$ns' AND r.store = '$store'";
    }
    return $sql;
}
/* aplica a estrutura que faltar. Tabelas: obrigatórias. Visões: opcionais (exigem privilégio CREATE VIEW) — falha vira aviso. */
function aplicar_esquema(): array {
    $p = preg_replace('/[^a-z0-9_]/i', '', (string)cfg('prefixo'));
    $res = ['tabelas' => 0, 'visoes' => 0, 'avisos' => []];
    foreach (esquema_tabelas($p) as $s) { db()->exec($s); $res['tabelas']++; }
    foreach (esquema_visoes($p) as $s) {
        try { db()->exec($s); $res['visoes']++; }
        catch (PDOException $e) { registrar_erro_tecnico('esquema:visao', $e); $res['avisos'][] = 'Visão não criada (verifique o privilégio CREATE VIEW): ' . substr($s, 25, 40); }
    }
    q('INSERT INTO ' . tabela('meta') . " (chave, valor) VALUES ('schema_versao', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)", [(string)ESQUEMA_VERSAO]);
    return $res;
}
/* chamada no início das requisições: se o banco estiver numa versão anterior do esquema, atualiza (sem apagar nada) */
function garantir_esquema(): void {
    static $ok = false; if ($ok) return;
    if (!configurado()) return;
    if (!tabelas_existem()) return;                       // banco vazio: a criação acontece na instalação (com a chave)
    $v = (int)(meta('schema_versao') ?? 0);
    if ($v < ESQUEMA_VERSAO) { $r = aplicar_esquema(); log_api('esquema', 'sucesso', 200, "atualizado para v" . ESQUEMA_VERSAO . "; {$r['visoes']} visão(ões)" . ($r['avisos'] ? '; ' . implode(' | ', $r['avisos']) : '')); }
    $ok = true;
}
/* gera o db.sql equivalente (para quem preferir executar pelo phpMyAdmin) */
function esquema_sql_completo(string $p = 'setta_'): string {
    $out = "-- =====================================================================\n-- Portal de Gestão — Grupo Setta · ESTRUTURA DO BANCO (MySQL 5.7+ / MariaDB 10.3+)\n"
         . "-- Gerado por ferramentas/gerar-db-sql.php a partir de inc/esquema.php (versão do esquema: " . ESQUEMA_VERSAO . ").\n"
         . "-- Idempotente: pode ser executado de novo sem apagar dados. A instalação pelo navegador cria tudo isto sozinha.\n"
         . "-- =====================================================================\nSET NAMES utf8mb4;\n\n";
    foreach (esquema_tabelas($p) as $s) $out .= $s . ";\n\n";
    $out .= "-- Visões relacionais por entidade (consulta direta no MySQL; a senha dos usuários nunca aparece)\n";
    foreach (esquema_visoes($p) as $s) $out .= $s . ";\n";
    $out .= "\nUPDATE {$p}meta SET valor = '" . ESQUEMA_VERSAO . "' WHERE chave = 'schema_versao';\n";
    return $out;
}
