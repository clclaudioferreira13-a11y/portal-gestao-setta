<?php
/* Portal de Gestão — Grupo Setta · regras de negócio do servidor: módulos, isolamento por empresa, permissões e visibilidade.
   O navegador NUNCA é a fonte da verdade: usuário, perfil e empresas são relidos do banco a cada requisição. */
if (!defined('SETTA_API')) { http_response_code(403); exit; }

/* módulos (ns) e suas tabelas lógicas — iguais às do index.html */
const NS_STORES = [
    'portal' => ['empresas', 'setores', 'tipos', 'status', 'responsaveis', 'documentos', 'versoes', 'auditoria', 'config', 'usuarios', 'arquivos',
                 'arquivosBlob', 'revisoes', 'historico', 'notificacoes', 'docValidadores', 'docAprovadores', 'pastas',
                 'mpProcessos', 'mpSubprocessos', 'mpVersoes', 'grRiscos'],
    'sp'     => ['users', 'deps', 'tipos', 'reqs', 'msgs', 'anexos', 'ativs', 'versoes', 'hist', 'valid', 'notifs', 'audit', 'config', 'files'],
    'pa'     => ['usuarios', 'setores', 'diretrizes', 'planos', 'acoes', 'evidencias', 'arquivos', 'historico', 'notificacoes', 'solicitacoes', 'revisoes', 'config'],
    'ci'     => ['rascunhos'],
];
/* tabelas de conteúdo de arquivo: [campo do conteúdo, formato]. O conteúdo vai para dados/arquivos/, não para o JSON. */
const BLOB_STORES = ['portal/arquivosBlob' => ['data', 'texto'], 'sp/files' => ['blob', 'binario'], 'pa/arquivos' => ['blob', 'binario']];
/* portal: tabelas vinculadas a uma empresa (mesma lista EMP_STORES do index.html) */
const PORTAL_EMPRESA = ['setores', 'documentos', 'versoes', 'revisoes', 'historico', 'docValidadores', 'docAprovadores', 'arquivos', 'pastas',
                        'notificacoes', 'auditoria', 'mpProcessos', 'mpSubprocessos', 'mpVersoes', 'grRiscos'];
const PORTAL_SO_ADMIN = ['empresas', 'setores', 'tipos', 'status', 'responsaveis', 'config'];          // cadastros mestres
const PORTAL_DOCUMENTAL = ['documentos', 'versoes', 'revisoes', 'docValidadores', 'docAprovadores', 'arquivos', 'arquivosBlob', 'pastas'];
const SO_INSERIR = ['portal/auditoria', 'sp/audit'];                                                  // trilhas de auditoria: nunca alteradas/excluídas
const USUARIO_CAMPOS_PROPRIOS = ['senha', 'mustReset', 'atualizadoEm'];                              // o que o próprio usuário pode alterar no seu cadastro
const NIVEL_MAPA = ['consulta' => 1, 'editor' => 2, 'gestor' => 3, 'admin' => 4];
const NIVEL_RISCO = ['consulta' => 1, 'editor' => 2, 'grc' => 3, 'admin' => 4];

/* validação de entrada no SERVIDOR (independente das telas): campos obrigatórios e registros duplicados */
const OBRIGATORIOS = [
    'portal/usuarios'    => ['login' => 'Login', 'nome' => 'Nome', 'perfil' => 'Perfil'],
    'portal/empresas'    => ['sigla' => 'Sigla'],
    'portal/setores'     => ['nome' => 'Nome', 'sigla' => 'Sigla'],
    'portal/tipos'       => ['nome' => 'Nome', 'sigla' => 'Sigla'],
    'portal/documentos'  => ['codigo' => 'Código', 'titulo' => 'Título'],
    'portal/mpProcessos' => ['nome' => 'Nome do processo', 'codigo' => 'Código do processo'],
    'sp/reqs'            => ['titulo' => 'Título'],
];
const UNICOS = [   // [campo, escopo (global | empresa), mensagem]
    'portal/usuarios'    => [['login', 'global', 'já existe um usuário com este login.']],
    'portal/empresas'    => [['sigla', 'global', 'já existe uma empresa com esta sigla.'], ['cnpj', 'global', 'já existe uma empresa com este CNPJ.']],
    'portal/setores'     => [['sigla', 'empresa', 'já existe uma Unidade de Negócio com esta sigla nesta empresa.']],
    'portal/tipos'       => [['sigla', 'global', 'já existe um tipo de documento com esta sigla.']],
    'portal/documentos'  => [['codigo', 'empresa', 'já existe um documento com este código nesta empresa.']],
    'portal/mpProcessos' => [['codigo', 'global', 'já existe um processo com este código.']],
];
function validar_registro(string $ns, string $store, string $id, array $d): void {
    $k = $ns . '/' . $store;
    foreach (OBRIGATORIOS[$k] ?? [] as $campo => $rot) {
        $v = $d[$campo] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) falhar(422, "Campo obrigatório não informado: $rot.", ['codigo' => 'validacao', 'campo' => $campo]);
    }
    foreach (UNICOS[$k] ?? [] as [$campo, $escopo, $msg]) {
        $v = $d[$campo] ?? null;
        if (!is_string($v) && !is_int($v)) continue;
        $v = trim((string)$v); if ($v === '') continue;
        if ($campo === 'cnpj') {   // compara só os dígitos (com ou sem máscara)
            $dig = preg_replace('/\D/', '', $v); if ($dig === '') continue;
            foreach (todos($ns, $store) as $r) if ((string)$r['id'] !== $id && preg_replace('/\D/', '', (string)($r['d']['cnpj'] ?? '')) === $dig) falhar(409, "Registro duplicado: $msg", ['codigo' => 'duplicado', 'campo' => $campo]);
            continue;
        }
        $sql = 'SELECT id FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ? AND id <> ? AND LOWER(JSON_UNQUOTE(JSON_EXTRACT(dados, ?))) = LOWER(?)';
        $p = [$ns, $store, $id, '$.' . $campo, $v];
        if ($escopo === 'empresa') { $sql .= ' AND empresa_id <=> ?'; $p[] = empresa_de($ns, $store, $d); }
        if (q($sql . ' LIMIT 1', $p)->fetchColumn() !== false) falhar(409, "Registro duplicado: $msg", ['codigo' => 'duplicado', 'campo' => $campo]);
    }
}
/* integridade referencial: os relacionamentos vivem dentro dos registros (JSON); o diagnóstico conta referências órfãs */
const RELACOES = [
    ['Versão → Documento', 'portal', 'versoes', 'documentoId', 'portal', 'documentos'],
    ['Histórico → Documento', 'portal', 'historico', 'documentoId', 'portal', 'documentos'],
    ['Validador → Documento', 'portal', 'docValidadores', 'documentoId', 'portal', 'documentos'],
    ['Aprovador → Documento', 'portal', 'docAprovadores', 'documentoId', 'portal', 'documentos'],
    ['Validador → Usuário', 'portal', 'docValidadores', 'usuarioId', 'portal', 'usuarios'],
    ['Documento → Pasta', 'portal', 'documentos', 'pastaId', 'portal', 'pastas'],
    ['Processo → Unidade de Negócio', 'portal', 'mpProcessos', 'setorId', 'portal', 'setores'],
    ['Subprocesso → Processo', 'portal', 'mpSubprocessos', 'processoId', 'portal', 'mpProcessos'],
    ['Risco → Unidade de Negócio', 'portal', 'grRiscos', 'setorId', 'portal', 'setores'],
    ['Risco → Processo', 'portal', 'grRiscos', 'processoId', 'portal', 'mpProcessos'],
    ['Solicitação → Tipo', 'sp', 'reqs', 'tipo_id', 'sp', 'tipos'],
    ['Histórico → Solicitação', 'sp', 'hist', 'req_id', 'sp', 'reqs'],
    ['Anexo → Solicitação', 'sp', 'anexos', 'req_id', 'sp', 'reqs'],
    ['Anexo → Arquivo', 'sp', 'anexos', 'file_id', 'sp', 'files'],
    ['Ação → Plano', 'pa', 'acoes', 'plano_id', 'pa', 'planos'],
    ['Evidência → Ação', 'pa', 'evidencias', 'acao_id', 'pa', 'acoes'],
];
function verificar_integridade(): array {
    $t = tabela('registros'); $out = [];
    foreach (RELACOES as [$nome, $ns1, $s1, $campo, $ns2, $s2]) {
        $ref = "JSON_UNQUOTE(JSON_EXTRACT(c.dados, '$.$campo'))";
        $total = (int)q("SELECT COUNT(*) FROM $t c WHERE c.ns = ? AND c.store = ? AND $ref IS NOT NULL AND $ref NOT IN ('', 'null')", [$ns1, $s1])->fetchColumn();
        $orf = (int)q("SELECT COUNT(*) FROM $t c WHERE c.ns = ? AND c.store = ? AND $ref IS NOT NULL AND $ref NOT IN ('', 'null')
                       AND NOT EXISTS (SELECT 1 FROM $t p WHERE p.ns = ? AND p.store = ? AND p.id = $ref)", [$ns1, $s1, $ns2, $s2])->fetchColumn();
        $out[] = ['relacao' => $nome, 'referencias' => $total, 'orfaos' => $orf];
    }
    $orfEmp = (int)q("SELECT COUNT(*) FROM $t r WHERE r.empresa_id IS NOT NULL AND r.empresa_id <> '__GRUPO__'
                      AND NOT EXISTS (SELECT 1 FROM $t e WHERE e.ns = 'portal' AND e.store = 'empresas' AND e.id = r.empresa_id)")->fetchColumn();
    $totEmp = (int)q("SELECT COUNT(*) FROM $t r WHERE r.empresa_id IS NOT NULL AND r.empresa_id <> '__GRUPO__'")->fetchColumn();
    $out[] = ['relacao' => 'Registro → Empresa (multiempresa)', 'referencias' => $totEmp, 'orfaos' => $orfEmp];
    return $out;
}
/* migração/restauração: trata duplicidades ANTES de gravar (id repetido → mantém a versão mais recente; login repetido → recusa) */
function data_registro(array $d): string {
    foreach (['atualizadoEm', 'updated_at', 'atualizado_em', 'criadoEm', 'created_at', 'criado_em', 'dataHora', 'data'] as $k) if (!empty($d[$k]) && is_string($d[$k])) return $d[$k];
    return '';
}
function deduplicar(array &$regs): array {
    $rel = [];
    foreach ($regs as $ns => $stores) {
        if (!is_array($stores)) continue;
        foreach ($stores as $store => $lista) {
            if (!is_array($lista)) continue;
            $por = []; $dup = 0;
            foreach ($lista as $d) {
                if (!is_array($d) || !isset($d['id'])) { $por[] = $d; continue; }
                $id = 'id:' . (string)$d['id'];
                if (isset($por[$id])) { $dup++; if (strcmp(data_registro($d), data_registro($por[$id])) >= 0) $por[$id] = $d; }
                else $por[$id] = $d;
            }
            if ($dup) $rel[] = "$ns/$store: $dup registro(s) com identificador repetido — mantida a versão mais recente de cada um";
            $regs[$ns][$store] = array_values($por);
        }
    }
    $logins = [];
    foreach (($regs['portal']['usuarios'] ?? []) as $u) { $l = strtolower(trim((string)($u['login'] ?? ''))); if ($l !== '') $logins[$l][] = (string)($u['id'] ?? '?'); }
    $rep = array_keys(array_filter($logins, function ($ids) { return count($ids) > 1; }));
    if ($rep) falhar(422, 'Há usuários diferentes com o mesmo login no conteúdo enviado (' . implode(', ', $rep) . '). Corrija na origem antes de migrar — nenhum dado foi alterado.', ['codigo' => 'duplicado']);
    return $rel;
}

function validar_ns(string $ns): void { if (!isset(NS_STORES[$ns])) falhar(400, 'Módulo inválido.'); }
function validar_store(string $ns, string $store): void { if (!in_array($store, NS_STORES[$ns], true)) falhar(400, "Tabela inválida para o módulo: $store."); }
function validar_id($id): string {
    $id = is_string($id) || is_int($id) ? (string)$id : '';
    if ($id === '' || strlen($id) > 100 || preg_match('/[\x00-\x1F\x7F]/', $id)) falhar(422, 'Identificador de registro inválido.');
    return $id;
}
function blob_info(string $ns, string $store): ?array { return BLOB_STORES[$ns . '/' . $store] ?? null; }

/* ---------------- usuário da sessão (relido do banco a cada requisição) ---------------- */
function linha(string $ns, string $store, string $id, bool $bloquear = false): ?array {
    $r = q('SELECT ns, store, id, empresa_id, dados, rev, atualizado_em, atualizado_por FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ? AND id = ?' . ($bloquear ? ' FOR UPDATE' : ''),
           [$ns, $store, $id])->fetch();
    if (!$r) return null;
    $r['d'] = json_decode((string)$r['dados'], true);
    if (!is_array($r['d'])) $r['d'] = [];
    return $r;
}
function todos(string $ns, string $store): array {
    $out = [];
    foreach (q('SELECT id, empresa_id, dados, rev FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ?', [$ns, $store]) as $r) {
        $d = json_decode((string)$r['dados'], true); if (!is_array($d)) continue;
        $out[] = ['id' => $r['id'], 'empresa_id' => $r['empresa_id'], 'rev' => (int)$r['rev'], 'd' => $d];
    }
    return $out;
}
function usuario_atual(bool $exigir = true): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $uid = $_SESSION['uid'] ?? null;
    $u = null;
    if ($uid) {
        $lim = max(30, (int)cfg('sessao_minutos')) * 60;
        if (time() - (int)($_SESSION['ultimo'] ?? 0) > $lim) {
            $_SESSION = []; session_regenerate_id(true);
            if ($exigir) falhar(401, 'Sua sessão expirou por inatividade. Entre novamente.', ['codigo' => 'sessao']);
        } else {
            $l = linha('portal', 'usuarios', (string)$uid);
            if ($l && ($l['d']['status'] ?? '') === 'Ativo') { $u = $l['d']; $_SESSION['ultimo'] = time(); }
            elseif ($exigir) { $_SESSION = []; falhar(401, 'Seu usuário está inativo ou foi removido. Procure um administrador.', ['codigo' => 'sessao']); }
        }
    }
    if (!$u && $exigir) falhar(401, 'Sessão não iniciada ou expirada. Entre novamente.', ['codigo' => 'sessao']);
    $cache = $u;
    return $u;
}
function e_admin(?array $u): bool { return $u !== null && ($u['perfil'] ?? '') === 'Administrador'; }
function sem_senha(array $u): array { unset($u['senha']); return $u; }
function empresas_ativas(): array {
    $ids = [];
    foreach (todos('portal', 'empresas') as $e) { if (($e['d']['status'] ?? 'Ativo') !== 'Inativo') $ids[] = (string)$e['id']; }
    return $ids;
}
/* empresas que o usuário pode ler/gravar (isolamento). Administrador: todas as cadastradas. */
function empresas_permitidas(array $u): array {
    static $cache = [];
    $k = (string)($u['id'] ?? '');
    if (isset($cache[$k])) return $cache[$k];
    if (e_admin($u)) { $ids = array_map(function ($e) { return (string)$e['id']; }, todos('portal', 'empresas')); }
    else { $ids = array_values(array_intersect(array_map('strval', (array)($u['empresasIds'] ?? [])), empresas_ativas())); }
    return $cache[$k] = $ids;
}
function empresa_ok(array $u, $empresaId): bool {
    if ($empresaId === null || $empresaId === '') return true;   // registro sem vínculo de empresa (cadastros globais)
    return in_array((string)$empresaId, empresas_permitidas($u), true);
}
/* empresa gravada na coluna empresa_id (índice de isolamento) */
function empresa_de(string $ns, string $store, array $d): ?string {
    $v = null;
    if ($ns === 'portal' && in_array($store, PORTAL_EMPRESA, true)) $v = $d['empresaId'] ?? null;
    elseif (($ns === 'sp' && $store === 'reqs') || ($ns === 'pa' && $store === 'planos')) $v = $d['empresa_id'] ?? null;
    return ($v === null || $v === '') ? null : substr((string)$v, 0, 100);
}
function nivel_mapa(array $u): int {
    if (e_admin($u)) return 4;
    $p = $u['perfilMapa'] ?? null; if ($p === null) $p = ($u['perfil'] ?? '') === 'Gestor' ? 'gestor' : 'consulta';
    return NIVEL_MAPA[$p] ?? 0;
}
function nivel_risco(array $u): int {
    if (e_admin($u)) return 4;
    $p = $u['perfilRisco'] ?? null; if ($p === null) $p = ($u['perfil'] ?? '') === 'Gestor' ? 'editor' : 'consulta';
    return NIVEL_RISCO[$p] ?? 0;
}
function so_consulta_documental(array $u): bool {
    if (($u['perfil'] ?? '') !== 'Consulta') return false;
    $pap = array_values(array_diff((array)($u['papeisFluxo'] ?? []), ['consulta']));
    return count($pap) === 0;
}
function canonico($v) {
    if (is_array($v)) { $lista = array_keys($v) === range(0, count($v) - 1); if (!$lista) ksort($v); foreach ($v as $k => $x) $v[$k] = canonico($x); }
    return $v;
}
function iguais($a, $b): bool { return json_encode(canonico($a)) === json_encode(canonico($b)); }

/* ---------------- autorização de gravação ---------------- */
function autorizar_gravacao(array $u, string $ns, string $store, string $op, ?array $novo, ?array $ex): void {
    $admin = e_admin($u);
    $chave = $ns . '/' . $store;
    if (in_array($chave, SO_INSERIR, true)) {
        if ($op === 'del') falhar(403, 'Registros de auditoria não podem ser excluídos.');
    }
    // isolamento por empresa: o registro atual e o novo precisam pertencer a empresas permitidas
    if ($ex && !empresa_ok($u, $ex['empresa_id'])) falhar(403, 'Operação bloqueada: o registro pertence a outra empresa.');
    if ($novo !== null) {
        $emp = empresa_de($ns, $store, $novo);
        if (!empresa_ok($u, $emp)) falhar(403, 'Operação bloqueada: empresa não autorizada para o seu usuário.');
        if ($ns === 'portal' && in_array($store, PORTAL_EMPRESA, true) && $emp === null && !$admin)
            falhar(422, 'Registro sem empresa: selecione uma empresa ativa no portal antes de gravar.');
    }
    if ($ns === 'portal') {
        if (in_array($store, PORTAL_SO_ADMIN, true) && !$admin) falhar(403, 'Somente o Administrador pode alterar este cadastro.');
        if ($store === 'usuarios' && !$admin) {
            if ($op === 'del' || !$ex || (string)$ex['id'] !== (string)$u['id']) falhar(403, 'Somente o Administrador pode alterar cadastros de usuários.');
            $a = $ex['d']; $b = $novo ?? [];
            foreach (USUARIO_CAMPOS_PROPRIOS as $c) { unset($a[$c], $b[$c]); }
            if (!iguais($a, $b)) falhar(403, 'Você só pode alterar a sua própria senha. Perfil, empresas e permissões são definidos pelo Administrador.');
        }
        if (in_array($store, PORTAL_DOCUMENTAL, true) && so_consulta_documental($u)) falhar(403, 'Seu perfil possui permissão somente para consulta de documentos publicados.');
        if (in_array($store, ['mpProcessos', 'mpSubprocessos', 'mpVersoes'], true) && nivel_mapa($u) < ($op === 'del' ? 4 : 2)) falhar(403, 'Você não tem permissão para esta ação no Mapa de Processo.');
        if ($store === 'grRiscos' && nivel_risco($u) < ($op === 'del' ? 4 : 2)) falhar(403, 'Você não tem permissão para esta ação na Gestão de Risco.');
        if ($store === 'notificacoes' && $op === 'del' && !$admin) falhar(403, 'Notificações não podem ser excluídas.');
    }
    if ($ns === 'sp' || $ns === 'pa') {
        $pai = pai_modulo($ns, $store, $novo ?? ($ex['d'] ?? []));
        if ($pai && !empresa_ok($u, $pai['empresa_id'])) falhar(403, 'Operação bloqueada: o registro pertence a outra empresa.');
    }
    if ($ns === 'ci' && (string)($novo['id'] ?? $ex['id'] ?? '') !== (string)$u['id']) falhar(403, 'Cada usuário só grava o próprio rascunho de comunicado.');
}
/* registro "pai" que define a empresa de um registro dos módulos (solicitação / plano) */
function pai_modulo(string $ns, string $store, array $d): ?array {
    if ($ns === 'sp' && $store !== 'reqs' && !empty($d['req_id'])) return linha('sp', 'reqs', (string)$d['req_id']);
    if ($ns === 'pa') {
        if ($store === 'acoes' && !empty($d['plano_id'])) return linha('pa', 'planos', (string)$d['plano_id']);
        if ($store === 'evidencias' && !empty($d['acao_id'])) { $a = linha('pa', 'acoes', (string)$d['acao_id']); return $a && !empty($a['d']['plano_id']) ? linha('pa', 'planos', (string)$a['d']['plano_id']) : null; }
        if (in_array($store, ['historico', 'notificacoes', 'revisoes', 'solicitacoes'], true) && !empty($d['plano_id'])) return linha('pa', 'planos', (string)$d['plano_id']);
    }
    return null;
}

/* ---------------- visibilidade na leitura (sincronização) ---------------- */
function filtrar_visiveis(array $u, string $ns, array $porStore): array {
    $ok = function ($r) use ($u) { return empresa_ok($u, $r['empresa_id']); };
    if ($ns === 'portal') {
        foreach ($porStore as $s => $lst) {
            if (in_array($s, PORTAL_EMPRESA, true)) $porStore[$s] = array_values(array_filter($lst, $ok));
            if ($s === 'usuarios') foreach ($porStore[$s] as $i => $r) $porStore[$s][$i]['d'] = sem_senha($r['d']);
        }
    } elseif ($ns === 'sp') {
        foreach ($porStore['users'] ?? [] as $i => $r) $porStore['users'][$i]['d'] = sem_senha($r['d']);
        $porStore['reqs'] = array_values(array_filter($porStore['reqs'] ?? [], $ok));
        $vis = []; foreach ($porStore['reqs'] as $r) $vis[(string)$r['id']] = 1;
        foreach ($porStore as $s => $lst) {
            if ($s === 'reqs') continue;
            $porStore[$s] = array_values(array_filter($lst, function ($r) use ($vis) { return empty($r['d']['req_id']) || isset($vis[(string)$r['d']['req_id']]); }));
        }
    } elseif ($ns === 'pa') {
        foreach ($porStore['usuarios'] ?? [] as $i => $r) unset($porStore['usuarios'][$i]['d']['senha_hash']);   // hash do login próprio do módulo nunca vai ao navegador
        $porStore['planos'] = array_values(array_filter($porStore['planos'] ?? [], $ok));
        $pv = []; foreach ($porStore['planos'] as $r) $pv[(string)$r['id']] = 1;
        $porStore['acoes'] = array_values(array_filter($porStore['acoes'] ?? [], function ($r) use ($pv) { return empty($r['d']['plano_id']) || isset($pv[(string)$r['d']['plano_id']]); }));
        $av = []; foreach ($porStore['acoes'] as $r) $av[(string)$r['id']] = 1;
        foreach ($porStore as $s => $lst) {
            if (in_array($s, ['historico', 'notificacoes', 'revisoes', 'solicitacoes'], true))
                $porStore[$s] = array_values(array_filter($lst, function ($r) use ($pv) { return empty($r['d']['plano_id']) || isset($pv[(string)$r['d']['plano_id']]); }));
            if ($s === 'evidencias')
                $porStore[$s] = array_values(array_filter($lst, function ($r) use ($av) { return empty($r['d']['acao_id']) || isset($av[(string)$r['d']['acao_id']]); }));
        }
    } elseif ($ns === 'ci') {
        $porStore['rascunhos'] = array_values(array_filter($porStore['rascunhos'] ?? [], function ($r) use ($u) { return (string)$r['id'] === (string)$u['id']; }));
    }
    return $porStore;
}
/* um conteúdo de arquivo só é entregue se algum registro visível o referencia (ou se o próprio usuário o enviou) */
function arquivo_visivel(array $u, string $ns, string $store, array $ex): bool {
    if (e_admin($u)) return true;
    $id = (string)$ex['id'];
    $like = '%"' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $id) . '"%';
    $refs = function (string $rns, array $stores) use ($like) {
        $in = implode(',', array_fill(0, count($stores), '?'));
        return q('SELECT store, id, empresa_id, dados FROM ' . tabela('registros') . " WHERE ns = ? AND store IN ($in) AND dados LIKE ? LIMIT 50", array_merge([$rns], $stores, [$like]))->fetchAll();
    };
    if ($ns === 'portal') {
        $rs = $refs('portal', ['versoes', 'arquivos']);
        foreach ($rs as $r) if (empresa_ok($u, $r['empresa_id'])) return true;
        return !$rs && ($ex['atualizado_por'] ?? null) === ($u['login'] ?? '');
    }
    if ($ns === 'sp') {
        foreach ($refs('sp', ['anexos']) as $r) { $d = json_decode((string)$r['dados'], true) ?: []; $p = pai_modulo('sp', 'anexos', $d); if (!$p || empresa_ok($u, $p['empresa_id'])) return true; }
        return ($ex['atualizado_por'] ?? null) === ($u['login'] ?? '');
    }
    if ($ns === 'pa') {
        $ev = linha('pa', 'evidencias', $id);
        if (!$ev) return ($ex['atualizado_por'] ?? null) === ($u['login'] ?? '');
        $p = pai_modulo('pa', 'evidencias', $ev['d']); return !$p || empresa_ok($u, $p['empresa_id']);
    }
    return false;
}

/* ---------------- senhas (o cliente envia o hash SHA-256 "setta::senha"; o servidor guarda bcrypt desse hash) ---------------- */
function hash_cliente(string $senha): string { return hash('sha256', 'setta::' . $senha); }
function hash_cliente_alternativo(string $senha): string {   // mesmo cálculo do fallback do navegador sem crypto.subtle (HTTP sem SSL)
    $s = 'setta::' . $senha; $h = 0;
    $u16 = function_exists('mb_convert_encoding') ? mb_convert_encoding($s, 'UTF-16LE', 'UTF-8') : (string)iconv('UTF-8', 'UTF-16LE', $s);
    for ($i = 0; $i < strlen($u16); $i += 2) {
        $c = ord($u16[$i]) | (ord($u16[$i + 1]) << 8);
        $h = ($h * 31 + $c) & 0xFFFFFFFF;
    }
    return 'f' . dechex($h);
}
function guardar_senha(string $valorCliente): string {
    if (preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $valorCliente)) return $valorCliente;   // já é hash do servidor (restauração)
    return password_hash($valorCliente, PASSWORD_DEFAULT);
}
function senha_confere(string $senha, string $guardada): bool {
    if ($guardada === '') return false;
    $cands = [hash_cliente($senha), hash_cliente_alternativo($senha)];
    if (preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $guardada)) {
        foreach ($cands as $c) if (password_verify($c, $guardada)) return true;
        return false;
    }
    foreach ($cands as $c) if (hash_equals($guardada, $c)) return true;   // hash antigo (base local importada) — convertido para bcrypt no login
    return false;
}
/* sempre deve sobrar ao menos um Administrador ativo com senha */
function garantir_admin_ativo(): void {
    foreach (todos('portal', 'usuarios') as $r) {
        if (($r['d']['perfil'] ?? '') === 'Administrador' && ($r['d']['status'] ?? '') === 'Ativo' && !empty($r['d']['senha'])) return;
    }
    falhar(422, 'Operação recusada: deve existir ao menos um Administrador ativo com senha.');
}
