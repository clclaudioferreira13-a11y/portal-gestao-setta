<?php
/* Portal de Gestão — Grupo Setta · API REST por módulo (mesmas regras do portal: sessão, permissões, empresa, validação, revisão).
   ─────────────────────────────────────────────────────────────────────────────────────────────────────────────
   GET    /api/{modulo}/{entidade}                  lista (filtros: ?q=texto&empresa_id=…&limite=100&inicio=0)
   GET    /api/{modulo}/{entidade}/{id}             um registro (cabeçalho ETag = revisão)
   GET    /api/{modulo}/{entidade}/{id}/arquivo     conteúdo do arquivo anexado (entidades de arquivo)
   POST   /api/{modulo}/{entidade}                  cria (id opcional; id repetido → 409 duplicado)
   PUT    /api/{modulo}/{entidade}/{id}             atualiza (exige If-Match: <revisão>; alteração concorrente → 409)
   DELETE /api/{modulo}/{entidade}/{id}             exclui (cópia vai para a lixeira; If-Match opcional)
   GET  /api/estado · POST /api/login · POST /api/logout · GET|POST /api/teste-conexao · GET /api/diagnostico · GET /api/backup
   Módulos: portal (gestao) · solicitacoes · planos · comunicado. Entidades: as mesmas do sistema (ver README).
   Servidores que bloqueiam PUT/DELETE: use POST com o cabeçalho X-HTTP-Method-Override. */
if (!defined('SETTA_API')) { http_response_code(403); exit; }

const MODULOS_REST = ['portal' => 'portal', 'gestao' => 'portal', 'solicitacoes' => 'sp', 'sp' => 'sp', 'planos' => 'pa', 'pa' => 'pa', 'comunicado' => 'ci', 'ci' => 'ci'];

function rest_revisao(): ?int {
    $h = (string)($_SERVER['HTTP_IF_MATCH'] ?? ($_GET['rev'] ?? ''));
    $h = trim(str_replace(['W/', '"'], '', $h));
    return $h === '' ? null : (int)$h;
}
function rest_saida(array $r): array { $d = $r['d']; $d['_rev'] = $r['rev']; $d['_empresa_id'] = $r['empresa_id']; if (isset($r['meta'])) $d['_meta'] = $r['meta']; return $d; }
function rest_exigir_escrita(): void {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'SettaPortal') falhar(400, 'Requisição inválida (cabeçalho X-Requested-With: SettaPortal ausente).');
    exigir_csrf();
}
function rest(string $rota): void {
    $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($metodo === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) $metodo = strtoupper((string)$_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    $p = array_values(array_filter(explode('/', trim($rota, '/')), 'strlen'));
    $p = array_map('rawurldecode', $p);
    if (!$p) falhar(404, 'Informe o módulo: /api/{modulo}/{entidade}.');
    // rotas gerais
    $geral = ['estado' => 'GET', 'login' => 'POST', 'logout' => 'POST', 'teste-conexao' => '*', 'diagnostico' => 'GET', 'backup' => 'GET'];
    if (isset($geral[$p[0]]) && count($p) === 1) {
        if ($geral[$p[0]] !== '*' && $geral[$p[0]] !== $metodo) falhar(405, 'Método não permitido.');
        switch ($p[0]) {
            case 'estado': responder(acao_estado());
            case 'login': exigir_post(); responder(acao_login(ler_json()));
            case 'logout': exigir_post(); responder(acao_logout());
            case 'teste-conexao': responder(acao_teste_conexao($metodo === 'POST' ? ler_json() : []));
            case 'diagnostico': $u = usuario_atual(); if (!e_admin($u)) falhar(403, 'Somente o Administrador pode ver o diagnóstico.'); responder(acao_diagnostico());
            case 'backup': acao_backup(($_GET['arquivos'] ?? '1') !== '0');
        }
    }
    $ns = MODULOS_REST[$p[0]] ?? null; if (!$ns) falhar(404, 'Módulo inexistente: ' . $p[0] . '.');
    if (!isset($p[1])) falhar(404, 'Informe a entidade: /api/' . $p[0] . '/{entidade}. Entidades: ' . implode(', ', NS_STORES[$ns]) . '.');
    $store = $p[1]; validar_store($ns, $store);
    $id = isset($p[2]) ? validar_id($p[2]) : null;
    $u = usuario_atual();
    if ($metodo === 'GET') {
        if ($id !== null && ($p[3] ?? '') === 'arquivo') responder(acao_arquivo($ns, $store, $id));
        $vis = carregar_visiveis($u, $ns, true)[$store] ?? [];
        if ($id !== null) {
            foreach ($vis as $r) if ((string)$r['id'] === $id) { header('ETag: "' . $r['rev'] . '"'); responder(['modulo' => $p[0], 'entidade' => $store, 'registro' => rest_saida($r), 'rev' => $r['rev']]); }
            falhar(404, 'Registro não encontrado.');                 // inexistente OU de outra empresa: a resposta é a mesma
        }
        $min = function (string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s); };
        $q = $min(trim((string)($_GET['q'] ?? ''))); $emp = (string)($_GET['empresa_id'] ?? '');
        if ($q !== '' || $emp !== '') $vis = array_values(array_filter($vis, function ($r) use ($q, $emp, $min) {
            if ($emp !== '' && (string)$r['empresa_id'] !== $emp) return false;
            return $q === '' || strpos($min((string)json_encode($r['d'], JSON_UNESCAPED_UNICODE)), $q) !== false; }));
        $total = count($vis); $lim = max(1, min(1000, (int)($_GET['limite'] ?? 100))); $ini = max(0, (int)($_GET['inicio'] ?? 0));
        log_api('REST GET', 'sucesso', 200, "$total registro(s)", $ns, $store);
        responder(['modulo' => $p[0], 'entidade' => $store, 'total' => $total, 'inicio' => $ini, 'limite' => $lim, 'registros' => array_map('rest_saida', array_slice($vis, $ini, $lim))]);
    }
    if (!in_array($metodo, ['POST', 'PUT', 'DELETE'], true)) falhar(405, 'Método não permitido.');
    rest_exigir_escrita();
    if ($metodo === 'POST') {
        if ($id !== null) falhar(405, 'Para criar use POST /api/' . $p[0] . '/' . $store . ' (sem id).');
        $d = ler_json(); unset($d['_rev'], $d['_empresa_id'], $d['_meta']);
        $novoId = isset($d['id']) && $d['id'] !== '' ? validar_id($d['id']) : 'r' . bin2hex(random_bytes(8));
        if (linha($ns, $store, $novoId)) falhar(409, 'Registro duplicado: já existe um registro com este identificador.', ['codigo' => 'duplicado']);
        $d['id'] = $novoId;
        [$res, $marca] = executar_lote($u, $ns, [['op' => 'put', 'store' => $store, 'id' => $novoId, 'dados' => $d, 'rev' => 0]], 'REST POST');
        header('ETag: "' . $res[0]['rev'] . '"'); http_response_code(201);
        responder(['id' => $novoId, 'rev' => $res[0]['rev'], 'marca' => $marca], 201);
    }
    if ($id === null) falhar(405, 'Informe o id: /api/' . $p[0] . '/' . $store . '/{id}.');
    $ex = linha($ns, $store, $id);
    if (!$ex || !empresa_ok($u, $ex['empresa_id'])) falhar(404, 'Registro não encontrado.');
    $rev = rest_revisao();
    if ($metodo === 'PUT') {
        if ($rev === null) falhar(428, 'Informe a revisão atual do registro no cabeçalho If-Match (obtida no GET) para evitar sobrescrever alterações de outros usuários.');
        $d = ler_json(); unset($d['_rev'], $d['_empresa_id'], $d['_meta']); $d['id'] = $id;
        [$res, $marca] = executar_lote($u, $ns, [['op' => 'put', 'store' => $store, 'id' => $id, 'dados' => $d, 'rev' => $rev]], 'REST PUT');
        header('ETag: "' . $res[0]['rev'] . '"');
        responder(['id' => $id, 'rev' => $res[0]['rev'], 'marca' => $marca]);
    }
    [$res, $marca] = executar_lote($u, $ns, [['op' => 'del', 'store' => $store, 'id' => $id, 'rev' => $rev ?? 0]], 'REST DELETE');
    responder(['id' => $id, 'excluido' => true, 'marca' => $marca]);
}
