-- =====================================================================
-- Portal de Gestão — Grupo Setta · ESTRUTURA DO BANCO (MySQL 5.7+ / MariaDB 10.3+)
-- Gerado por ferramentas/gerar-db-sql.php a partir de inc/esquema.php (versão do esquema: 2).
-- Idempotente: pode ser executado de novo sem apagar dados. A instalação pelo navegador cria tudo isto sozinha.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS setta_registros (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS setta_arquivos (
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
  CONSTRAINT fk_setta_arquivos_registro FOREIGN KEY (ns, store, id) REFERENCES setta_registros (ns, store, id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS setta_lixeira (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS setta_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS setta_login_tentativas (
  seq     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  login   VARCHAR(150) NOT NULL,
  ip      VARCHAR(45)  NOT NULL,
  data    DATETIME     NOT NULL,
  sucesso TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (seq),
  KEY ix_tent_login (login, data),
  KEY ix_tent_ip (ip, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS setta_meta (
  chave VARCHAR(40) NOT NULL,
  valor TEXT        NULL,
  PRIMARY KEY (chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO setta_meta (chave, valor) VALUES ('schema_versao', '1'), ('marca', '0');

-- Visões relacionais por entidade (consulta direta no MySQL; a senha dos usuários nunca aparece)
CREATE OR REPLACE VIEW setta_vw_empresas AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nomeFantasia')) AS `nome_fantasia`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.razaoSocial')) AS `razao_social`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.cnpj')) AS `cnpj`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.sigla')) AS `sigla`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.codigo')) AS `codigo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'empresas';
CREATE OR REPLACE VIEW setta_vw_unidades_negocio AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.sigla')) AS `sigla`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'setores';
CREATE OR REPLACE VIEW setta_vw_usuarios AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.login')) AS `login`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.email')) AS `email`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.cargo')) AS `cargo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.perfil')) AS `perfil`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.setor')) AS `setor`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.empresaPadraoId')) AS `empresa_padrao_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.visaoCorporativa')) AS `visao_corporativa`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.mustReset')) AS `deve_trocar_senha`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'usuarios';
CREATE OR REPLACE VIEW setta_vw_tipos_documento AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.sigla')) AS `sigla`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'tipos';
CREATE OR REPLACE VIEW setta_vw_documentos AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.codigo')) AS `codigo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.titulo')) AS `titulo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.tipo')) AS `tipo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.setor')) AS `unidade_negocio`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.unidade')) AS `sigla_empresa`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.versao')) AS `versao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.dataEmissao')) AS `data_emissao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.dataUltimaRevisao')) AS `data_ultima_revisao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavelId')) AS `responsavel_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavelNome')) AS `responsavel_nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.versaoVigenteId')) AS `versao_vigente_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.origem')) AS `origem`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'documentos';
CREATE OR REPLACE VIEW setta_vw_documento_versoes AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.documentoId')) AS `documento_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.numero')) AS `numero`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.versao')) AS `versao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.vigente')) AS `vigente`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.tipoAlteracao')) AS `tipo_alteracao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.descricao')) AS `descricao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.arquivoNome')) AS `arquivo_nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.arquivoBlobId')) AS `arquivo_blob_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.data')) AS `data`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'versoes';
CREATE OR REPLACE VIEW setta_vw_documento_historico AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.documentoId')) AS `documento_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.versaoId')) AS `versao_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.acao')) AS `acao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.statusAnterior')) AS `status_anterior`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.novoStatus')) AS `novo_status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.usuario')) AS `usuario`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.usuarioId')) AS `usuario_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.dataHora')) AS `data_hora`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.observacao')) AS `observacao`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'historico';
CREATE OR REPLACE VIEW setta_vw_documento_revisoes AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.documentoId')) AS `documento_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'revisoes';
CREATE OR REPLACE VIEW setta_vw_documento_validadores AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.documentoId')) AS `documento_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.usuarioId')) AS `usuario_id`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'docValidadores';
CREATE OR REPLACE VIEW setta_vw_documento_aprovadores AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.documentoId')) AS `documento_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.usuarioId')) AS `usuario_id`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'docAprovadores';
CREATE OR REPLACE VIEW setta_vw_pastas_publicados AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.paiId')) AS `pai_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nivel')) AS `nivel`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.ordem')) AS `ordem`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'pastas';
CREATE OR REPLACE VIEW setta_vw_mapa_processos AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.setorId')) AS `unidade_negocio_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.codigo')) AS `codigo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.objetivo')) AS `objetivo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavelId')) AS `responsavel_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.versao')) AS `versao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.removido')) AS `removido`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'mpProcessos';
CREATE OR REPLACE VIEW setta_vw_mapa_subprocessos AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.processoId')) AS `processo_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.codigo')) AS `codigo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavelId')) AS `responsavel_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.versao')) AS `versao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.removido')) AS `removido`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'mpSubprocessos';
CREATE OR REPLACE VIEW setta_vw_riscos AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.codigo')) AS `codigo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.setorId')) AS `unidade_negocio_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.processoId')) AS `processo_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.subprocessoId')) AS `subprocesso_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.tipo')) AS `tipo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.descricao')) AS `descricao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.prob')) AS `probabilidade`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.imp')) AS `impacto`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.ri')) AS `risco_inerente`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.classeRI')) AS `classe_inerente`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.eficacia')) AS `eficacia`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.rr')) AS `risco_residual`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.classeRR')) AS `classe_residual`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.resposta')) AS `resposta`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavelId')) AS `responsavel_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.dataAvaliacao')) AS `data_avaliacao`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'grRiscos';
CREATE OR REPLACE VIEW setta_vw_auditoria AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.entidade')) AS `entidade`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.registroId')) AS `registro_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.acao')) AS `acao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.campo')) AS `campo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.valorAnterior')) AS `valor_anterior`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.valorNovo')) AS `valor_novo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.usuario')) AS `usuario`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.dataHora')) AS `data_hora`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'portal' AND r.store = 'auditoria';
CREATE OR REPLACE VIEW setta_vw_solicitacoes AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.numero')) AS `numero`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.titulo')) AS `titulo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.tipo_id')) AS `tipo_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.prioridade')) AS `prioridade`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.setor_id')) AS `unidade_negocio_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.solicitante_id')) AS `solicitante_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavel_id')) AS `responsavel_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.data_abertura')) AS `data_abertura`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.prazo')) AS `prazo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.data_conclusao')) AS `data_conclusao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.documento_id')) AS `documento_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.glpi_numero')) AS `glpi_numero`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'sp' AND r.store = 'reqs';
CREATE OR REPLACE VIEW setta_vw_solicitacao_tipos AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.sla_dias')) AS `sla_dias`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.ativo')) AS `ativo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.gera_documento')) AS `gera_documento`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'sp' AND r.store = 'tipos';
CREATE OR REPLACE VIEW setta_vw_solicitacao_historico AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.req_id')) AS `solicitacao_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.acao')) AS `acao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status_anterior')) AS `status_anterior`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.novo_status')) AS `novo_status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.usuario_id')) AS `usuario_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.data')) AS `data`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.comentario')) AS `comentario`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'sp' AND r.store = 'hist';
CREATE OR REPLACE VIEW setta_vw_solicitacao_anexos AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.req_id')) AS `solicitacao_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome')) AS `nome`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.tipo')) AS `tipo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.tamanho')) AS `tamanho`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.file_id')) AS `arquivo_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.usuario_id')) AS `usuario_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.data')) AS `data`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.excluido')) AS `excluido`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'sp' AND r.store = 'anexos';
CREATE OR REPLACE VIEW setta_vw_planos_acao AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.codigo')) AS `codigo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.motivacao')) AS `motivacao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.setor_id')) AS `unidade_negocio_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavel_id')) AS `responsavel_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.meta')) AS `meta`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status_manual')) AS `status_manual`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.situacao_aprovacao')) AS `situacao_aprovacao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.data_emissao')) AS `data_emissao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.proxima_revisao')) AS `proxima_revisao`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'pa' AND r.store = 'planos';
CREATE OR REPLACE VIEW setta_vw_plano_acoes AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.plano_id')) AS `plano_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.numero')) AS `numero`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.descricao')) AS `descricao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.responsavel_id')) AS `responsavel_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.prazo_atual')) AS `prazo_atual`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.status')) AS `status`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.percentual')) AS `percentual`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.data_conclusao')) AS `data_conclusao`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.excluida')) AS `excluida`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'pa' AND r.store = 'acoes';
CREATE OR REPLACE VIEW setta_vw_plano_evidencias AS SELECT r.id, r.empresa_id, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.acao_id')) AS `acao_id`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.nome_arquivo')) AS `nome_arquivo`, JSON_UNQUOTE(JSON_EXTRACT(r.dados, '$.descricao')) AS `descricao`, r.rev, r.criado_em, r.criado_por, r.atualizado_em, r.atualizado_por FROM setta_registros r WHERE r.ns = 'pa' AND r.store = 'evidencias';

UPDATE setta_meta SET valor = '2' WHERE chave = 'schema_versao';
