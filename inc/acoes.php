<?php
/* Portal de Gestão — Grupo Setta · ações da API (endpoints). Toda gravação é transacional e só responde "ok" depois do COMMIT. */
if (!defined('SETTA_API')) { http_response_code(403); exit; }

/* ======================= gravação de um registro (usada por gravar, instalar e restaurar) ======================= */
/* separa o conteúdo do arquivo (tabelas de arquivo) e grava em disco num arquivo temporário; vira definitivo após o COMMIT */
function preparar_conteudo(string $ns, string $store, string $id, array &$d, ?array $conteudoExterno, array &$pend): ?array {
    $info = blob_info($ns, $store); if (!$info) return null;
    [$campo, $formato] = $info;
    if ($conteudoExterno !== null) $v = $conteudoExterno['v'];
    elseif (array_key_exists($campo, $d)) $v = $d[$campo];
    else return null;                                  // sem conteúdo novo: o arquivo atual é mantido
    unset($d[$campo]);
    if ($formato === 'texto') {
        if (!is_string($v)) falhar(422, 'Conteúdo de arquivo inválido.');
        $bytes = $v; $tipo = 'text/plain';
    } else {
        if (!is_array($v) || !isset($v['__b64'])) falhar(422, 'Conteúdo de arquivo inválido.');
        $bytes = base64_decode((string)$v['__b64'], true);
        if ($bytes === false) falhar(422, 'Arquivo corrompido no envio (base64 inválido).');
        $tipo = substr((string)($v['tipo'] ?? ''), 0, 150);
    }
    $maxMb = max(1, (int)cfg('max_arquivo_mb'));
    $lim = (int)($maxMb * 1024 * 1024 * ($formato === 'texto' ? 1.37 : 1));
    if (strlen($bytes) > $lim) falhar(413, "Arquivo maior que o limite configurado no servidor ({$maxMb} MB).");
    $final = dir_dados('arquivos') . '/' . nome_arquivo_seguro($ns, $store, $id);
    $tmp = $final . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) falhar(500, 'Não foi possível gravar o arquivo no servidor (permissão da pasta dados/arquivos).');
    $pend[] = ['tmp' => $tmp, 'final' => $final];
    $meta = ['formato' => $formato, 'tipo' => $tipo, 'tamanho' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'arquivo' => basename($final)];
    $d['__arquivo'] = ['formato' => $formato, 'tipo' => $tipo, 'tamanho' => $meta['tamanho'], 'sha256' => $meta['sha256']];
    return $meta;
}
function concluir_arquivos(array $pend, bool $ok): void {
    foreach ($pend as $p) {
        if ($ok) { if (!@rename($p['tmp'], $p['final'])) { @copy($p['tmp'], $p['final']); @unlink($p['tmp']); } }
        else @unlink($p['tmp']);
    }
}
function para_lixeira(string $ns, string $store, ?string $id, string $operacao, ?array $u): void {
    $sql = 'INSERT INTO ' . tabela('lixeira') . ' (ns, store, id, empresa_id, dados, rev, operacao, usuario, data) SELECT ns, store, id, empresa_id, dados, rev, ?, ?, ? FROM '
         . tabela('registros') . ' WHERE ns = ? AND store = ?' . ($id !== null ? ' AND id = ?' : '');
    $p = [$operacao, $u['login'] ?? 'sistema', agora(), $ns, $store]; if ($id !== null) $p[] = $id;
    q($sql, $p);
}
/* grava (insere/atualiza) um registro já autorizado. Retorna a nova revisão. */
function escrever(string $ns, string $store, string $id, array $d, ?array $ex, ?array $u, ?array $metaArq): int {
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) falhar(422, 'Registro com dados que não podem ser gravados (JSON inválido).');
    if (strlen($json) > 16 * 1024 * 1024) falhar(413, 'Registro grande demais para gravação (limite de 16 MB por registro).');
    $emp = empresa_de($ns, $store, $d); $quem = $u['login'] ?? 'sistema'; $t = agora();
    if ($ex) {
        q('UPDATE ' . tabela('registros') . ' SET empresa_id = ?, dados = ?, rev = rev + 1, atualizado_em = ?, atualizado_por = ? WHERE ns = ? AND store = ? AND id = ?',
          [$emp, $json, $t, $quem, $ns, $store, $id]);
        $rev = (int)$ex['rev'] + 1;
    } else {
        q('INSERT INTO ' . tabela('registros') . ' (ns, store, id, empresa_id, dados, rev, criado_em, criado_por, atualizado_em, atualizado_por) VALUES (?,?,?,?,?,1,?,?,?,?)',
          [$ns, $store, $id, $emp, $json, $t, $quem, $t, $quem]);
        $rev = 1;
    }
    if ($metaArq) {
        q('INSERT INTO ' . tabela('arquivos') . ' (ns, store, id, formato, tipo, tamanho, sha256, arquivo, criado_em, criado_por) VALUES (?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE formato = VALUES(formato), tipo = VALUES(tipo), tamanho = VALUES(tamanho), sha256 = VALUES(sha256), arquivo = VALUES(arquivo)',
          [$ns, $store, $id, $metaArq['formato'], $metaArq['tipo'], $metaArq['tamanho'], $metaArq['sha256'], $metaArq['arquivo'], $t, $quem]);
    }
    return $rev;
}
/* senha do cadastro de usuários: nunca vem do servidor para o navegador; ao gravar, o hash do navegador vira bcrypt */
function tratar_senha_usuario(array &$d, ?array $ex, bool $restauracao, ?array $anteriorRestauracao = null): void {
    $s = $d['senha'] ?? null;
    if (is_string($s) && $s !== '') {
        if (!$restauracao && preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', $s)) falhar(422, 'Formato de senha inválido.');
        // senhas padrão das versões antigas (base local) → troca obrigatória no próximo acesso
        foreach (['admin123', '1234'] as $padrao) {
            if (hash_equals(hash_cliente($padrao), $s) || hash_equals(hash_cliente_alternativo($padrao), $s)) { $d['mustReset'] = true; break; }
        }
        $d['senha'] = guardar_senha($s);
        return;
    }
    unset($d['senha']);
    $antiga = $ex['d']['senha'] ?? ($anteriorRestauracao['senha'] ?? null);
    if ($antiga) $d['senha'] = $antiga;
}

/* ======================= ESTADO ======================= */
function acao_estado(): array {
    $r = ['versao_api' => SETTA_API, 'configurado' => configurado(), 'tabelas' => false, 'instalado' => false, 'sessao' => null,
          'https' => https_ativo(), 'limites' => ['post_max_size' => ini_get('post_max_size'), 'upload_max_filesize' => ini_get('upload_max_filesize'), 'max_arquivo_mb' => (int)cfg('max_arquivo_mb')]];
    if (!$r['configurado']) return $r;
    db();
    $r['tabelas'] = tabelas_existem();
    if (!$r['tabelas']) return $r;
    $r['instalado'] = instalado();
    $r['marca'] = meta('marca');
    $u = $r['instalado'] ? usuario_atual(false) : null;
    if ($u) { $r['sessao'] = ['usuario' => sem_senha($u), 'csrf' => csrf(), 'sessao_id' => sessao_id(), 'empresas' => empresas_permitidas($u)]; }
    return $r;
}

/* ======================= INSTALAÇÃO INICIAL (uma única vez) ======================= */
function acao_instalar(array $req): array {
    if (!configurado()) falhar(503, 'Configuração pendente: as variáveis DB_* não foram encontradas (.env).');
    db();
    $chaveCfg = (string)cfg('chave_instalacao');
    if (strlen($chaveCfg) < 12 || strpos($chaveCfg, 'TROQUE_') === 0) falhar(503, 'Defina SETTA_CHAVE_INSTALACAO (12 caracteres ou mais) no .env do servidor.');
    if (!hash_equals($chaveCfg, (string)($req['chave'] ?? ''))) {
        if (tabelas_existem()) { limitar_tentativas('__instalacao__'); registrar_tentativa('__instalacao__', false); }
        falhar(403, 'Chave de instalação incorreta.'); }
    // banco vazio: cria tabelas e visões (idempotente; nunca apaga nada)
    $esquema = null;
    if (!tabelas_existem() || (int)(meta('schema_versao') ?? 0) < ESQUEMA_VERSAO) $esquema = aplicar_esquema();
    if (instalado()) falhar(409, 'O portal já está instalado neste servidor.');
    limitar_tentativas('__instalacao__');
    $adm = $req['admin'] ?? []; $login = trim((string)($adm['login'] ?? '')); $senha = (string)($adm['senha'] ?? '');
    if ($login === '' || strlen($senha) < 8) falhar(422, 'Informe o login do administrador e uma senha com pelo menos 8 caracteres.');
    $regs = $req['registros'] ?? null;
    if (!is_array($regs) || empty($regs['portal']['usuarios'])) falhar(422, 'Base inicial ausente ou incompleta.');
    $duplicidades = deduplicar($regs);
    $pdo = db(); $pend = []; $total = 0; $adminId = null;
    $pdo->beginTransaction();
    try {
        foreach ($regs as $ns => $stores) {
            validar_ns((string)$ns); if (!is_array($stores)) continue;
            foreach ($stores as $store => $lista) {
                validar_store((string)$ns, (string)$store); if (!is_array($lista)) continue;
                foreach ($lista as $d) {
                    if (!is_array($d)) continue; $id = validar_id($d['id'] ?? null);
                    if ($ns === 'portal' && $store === 'usuarios') {
                        if (($d['perfil'] ?? '') === 'Administrador' && strtolower((string)($d['login'] ?? '')) === strtolower($login)) {
                            $d['senha'] = password_hash(hash_cliente($senha), PASSWORD_DEFAULT); $d['mustReset'] = false; $d['status'] = 'Ativo'; $adminId = $id;
                        } else tratar_senha_usuario($d, null, true);
                    }
                    $meta = preparar_conteudo((string)$ns, (string)$store, $id, $d, null, $pend);
                    escrever((string)$ns, (string)$store, $id, $d, linha((string)$ns, (string)$store, $id, true), ['login' => 'instalacao'], $meta);
                    $total++;
                }
            }
        }
        if (!$adminId) falhar(422, "A base inicial não contém um Administrador com o login \"$login\".");
        meta('instalado_em', gmdate('c')); meta('marca', '1');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); concluir_arquivos($pend, false); throw $e; }
    concluir_arquivos($pend, true);
    registrar_tentativa('__instalacao__', true);
    session_regenerate_id(true);
    $_SESSION = ['uid' => $adminId, 'login' => $login, 'ultimo' => time()];
    log_api('instalar', 'sucesso', 200, "$total registro(s)" . ($duplicidades ? '; ' . implode(' | ', $duplicidades) : ''));
    $u = usuario_atual();
    return ['registros' => $total, 'duplicidades' => $duplicidades, 'esquema' => $esquema,
            'sessao' => ['usuario' => sem_senha($u), 'csrf' => csrf(), 'sessao_id' => sessao_id(), 'empresas' => empresas_permitidas($u)], 'marca' => meta('marca')];
}

/* ======================= LOGIN / LOGOUT ======================= */
function limitar_tentativas(string $login): void {
    $max = max(3, (int)cfg('max_tentativas_login')); $desde = gmdate('Y-m-d H:i:s', time() - 900);
    $n1 = (int)q('SELECT COUNT(*) FROM ' . tabela('login_tentativas') . ' WHERE login = ? AND sucesso = 0 AND data > ?', [$login, $desde])->fetchColumn();
    $n2 = (int)q('SELECT COUNT(*) FROM ' . tabela('login_tentativas') . ' WHERE ip = ? AND sucesso = 0 AND data > ?', [ip_cliente(), $desde])->fetchColumn();
    if ($n1 >= $max || $n2 >= $max * 4) falhar(429, 'Muitas tentativas sem sucesso. Aguarde 15 minutos e tente novamente.');
}
function registrar_tentativa(string $login, bool $ok): void {
    q('INSERT INTO ' . tabela('login_tentativas') . ' (login, ip, data, sucesso) VALUES (?,?,?,?)', [substr($login, 0, 150), ip_cliente(), agora(), $ok ? 1 : 0]);
}
function acao_login(array $req): array {
    if (!instalado()) falhar(409, 'O portal ainda não foi instalado neste servidor.', ['codigo' => 'nao_instalado']);
    $login = strtolower(trim((string)($req['login'] ?? ''))); $senha = (string)($req['senha'] ?? '');
    if ($login === '' || $senha === '') falhar(422, 'Informe usuário e senha.');
    limitar_tentativas($login);
    $achado = null;
    foreach (todos('portal', 'usuarios') as $r) { if (strtolower((string)($r['d']['login'] ?? '')) === $login) { $achado = $r; break; } }
    if (!$achado || !senha_confere($senha, (string)($achado['d']['senha'] ?? ''))) {
        registrar_tentativa($login, false); log_api('login', 'negado', 401, $login);
        falhar(401, 'Usuário ou senha inválidos.', ['codigo' => 'credenciais']);
    }
    $u = $achado['d'];
    if (($u['status'] ?? '') !== 'Ativo') { registrar_tentativa($login, false); falhar(403, 'Este usuário está inativo. Procure um administrador.'); }
    if (!e_admin($u) && !empresas_permitidas($u)) falhar(403, 'Seu usuário não está vinculado a nenhuma empresa ativa. Procure um administrador.');
    // hash antigo (SHA-256 da base local) → bcrypt
    if (!preg_match('/^\$(2y|2a|2b|argon2i|argon2id)\$/', (string)$u['senha'])) {
        $u['senha'] = password_hash(hash_cliente($senha), PASSWORD_DEFAULT);
        escrever('portal', 'usuarios', (string)$achado['id'], $u, linha('portal', 'usuarios', (string)$achado['id']), ['login' => $login], null);
    }
    registrar_tentativa($login, true);
    session_regenerate_id(true);
    $_SESSION = ['uid' => (string)$achado['id'], 'login' => (string)($u['login'] ?? $login), 'ultimo' => time()];
    log_api('login', 'sucesso', 200, $login);
    return ['sessao' => ['usuario' => sem_senha($u), 'csrf' => csrf(), 'sessao_id' => sessao_id(), 'empresas' => empresas_permitidas($u)], 'marca' => meta('marca')];
}
function acao_logout(): array {
    log_api('logout', 'sucesso', 200);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $p = session_get_cookie_params(); setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']); }
    session_destroy();
    return [];
}

/* ======================= SINCRONIZAR (leitura de um módulo inteiro, já filtrada) ======================= */
/* registros de um módulo que o usuário pode ver (empresa, permissões); usado pela sincronização e pela API REST */
function carregar_visiveis(array $u, string $ns, bool $comArquivos = false): array {
    $porStore = []; foreach (NS_STORES[$ns] as $s) { if ($comArquivos || !blob_info($ns, $s)) $porStore[$s] = []; }
    foreach (q('SELECT store, id, empresa_id, dados, rev, criado_em, criado_por, atualizado_em, atualizado_por FROM ' . tabela('registros') . ' WHERE ns = ?', [$ns]) as $r) {
        if (!isset($porStore[$r['store']])) continue;
        $d = json_decode((string)$r['dados'], true); if (!is_array($d)) continue;
        $porStore[$r['store']][] = ['id' => $r['id'], 'empresa_id' => $r['empresa_id'], 'rev' => (int)$r['rev'], 'd' => $d,
            'meta' => ['criado_em' => $r['criado_em'], 'criado_por' => $r['criado_por'], 'atualizado_em' => $r['atualizado_em'], 'atualizado_por' => $r['atualizado_por']]];
    }
    return filtrar_visiveis($u, $ns, $porStore);
}
function acao_sincronizar(string $ns): array {
    $u = usuario_atual(); validar_ns($ns);
    $marca = meta('marca');
    $porStore = carregar_visiveis($u, $ns);
    $regs = []; $revs = [];
    foreach ($porStore as $s => $lst) {
        $regs[$s] = []; $revs[$s] = new stdClass();
        foreach ($lst as $r) { $regs[$s][] = $r['d']; $revs[$s]->{$r['id']} = $r['rev']; }
    }
    // revisões das tabelas de arquivo (para o controle de concorrência), sem o conteúdo
    foreach (NS_STORES[$ns] as $s) {
        if (!blob_info($ns, $s)) continue; $revs[$s] = new stdClass();
        foreach (q('SELECT id, rev FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ?', [$ns, $s]) as $r) $revs[$s]->{$r['id']} = (int)$r['rev'];
    }
    return ['ns' => $ns, 'registros' => $regs, 'revs' => $revs, 'marca' => $marca, 'empresas' => empresas_permitidas($u)];
}

/* ======================= GRAVAR (lote transacional: put / del / clear) ======================= */
function acao_gravar(array $req): array {
    $u = usuario_atual();
    $ns = (string)($req['ns'] ?? ''); validar_ns($ns);
    $ops = $req['ops'] ?? null;
    if (!is_array($ops) || !$ops) falhar(400, 'Nenhuma operação informada.');
    if (count($ops) > 5000) falhar(413, 'Lote com operações demais (máximo 5000 por envio).');
    [$res, $marca] = executar_lote($u, $ns, $ops, 'gravar');
    return ['resultados' => $res, 'marca' => $marca, 'marca_antes' => (string)((int)$marca - 1)];
}
/* lote transacional usado pelo portal (acao=gravar) e pela API REST: tudo ou nada; só responde depois do COMMIT */
function executar_lote(array $u, string $ns, array $ops, string $origem): array {
    $pdo = db(); $pend = []; $res = [];
    $pdo->beginTransaction();
    try {
        foreach ($ops as $op) { if (!is_array($op)) falhar(400, 'Operação inválida.'); $res[] = executar_op($u, $ns, $op, $pend); }
        $marca = nova_marca();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); concluir_arquivos($pend, false);
        $st = 'erro';
        if ($e instanceof ErroApi) $st = $e->http === 409 ? ((($e->extra['codigo'] ?? '') === 'duplicado') ? 'duplicado' : 'conflito') : ($e->http === 422 ? 'invalido' : 'negado');
        log_api($origem, $st, $e instanceof ErroApi ? $e->http : 500, $e->getMessage(), $ns, (string)($ops[0]['store'] ?? ''), (string)($ops[0]['id'] ?? ''));
        throw $e;
    }
    concluir_arquivos($pend, true);
    foreach ($res as $r) log_api($origem . ':' . $r['op'], 'sucesso', 200, '', $ns, $r['store'], $r['id'] ?? null);
    return [$res, $marca];
}

/* ======================= TESTE DE CONEXÃO (nunca devolve a senha; o erro técnico real vai só para o log do servidor) ======================= */
function mensagem_erro_conexao(PDOException $e): string {
    $c = (int)($e->errorInfo[1] ?? 0); $m = $e->getMessage();
    if ($c === 0 && preg_match('/\[(\d{4})\]/', $m, $mm)) $c = (int)$mm[1];
    if ($c === 1044 || stripos($m, 'to database') !== false) return 'O usuário do banco não tem acesso ao banco informado em DB_NAME (ou esse banco não existe).';
    if ($c === 1049 || stripos($m, 'Unknown database') !== false) return 'O banco informado em DB_NAME não existe neste servidor.';
    if ($c === 1045 || stripos($m, 'Access denied') !== false) return 'O banco recusou o usuário ou a senha (verifique DB_USER e DB_PASSWORD).';
    if (in_array($c, [2002, 2003, 2005], true) || stripos($m, 'getaddrinfo') !== false || stripos($m, 'refused') !== false) return 'Não foi possível alcançar o servidor do banco (verifique DB_HOST e DB_PORT, e se o servidor aceita conexões deste site).';
    if (in_array($c, [2006, 2013], true) || stripos($m, 'timed out') !== false) return 'O servidor do banco não respondeu a tempo (tempo esgotado).';
    return 'Falha na conexão com o banco de dados.';
}
function acao_teste_conexao(array $req): array {
    if (!configurado()) falhar(503, 'Configuração pendente: as variáveis DB_HOST, DB_NAME, DB_USER e DB_PASSWORD não foram encontradas.', ['codigo' => 'config']);
    // autorização: Administrador logado OU a chave de instalação (usada antes de existir qualquer usuário)
    $u = null; try { $u = usuario_atual(false); } catch (Throwable $e) { $u = null; }
    if (!e_admin($u)) {
        $chave = (string)cfg('chave_instalacao');
        if (strlen($chave) < 12 || !hash_equals($chave, (string)($req['chave'] ?? ''))) falhar(403, 'Somente o Administrador (ou quem tem a chave de instalação) pode testar a conexão.');
    }
    $d = cfg('db'); $porta = (int)($d['porta'] ?: 3306);
    $base = ['banco' => (string)$d['nome'], 'servidor' => (string)$d['host'], 'porta' => $porta, 'configuracao' => env_origem()];
    $t0 = microtime(true);
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], $porta, $d['nome']), $d['usuario'], $d['senha'],
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
        $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $tem = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote(tabela('registros')))->fetchColumn();
        log_api('teste_conexao', 'sucesso', 200, "MySQL $ver em {$ms} ms");
        return $base + ['conexao' => 'OK', 'versao_mysql' => $ver, 'tempo_ms' => $ms, 'tabelas_criadas' => $tem,
            'linhas' => ['Conexão com MySQL: OK', 'Banco: ' . $base['banco'], 'Servidor: ' . $base['servidor'] . ($porta !== 3306 ? ':' . $porta : ''), 'Versão: ' . $ver, 'Tabelas do portal: ' . ($tem ? 'criadas' : 'ainda não criadas (serão criadas na instalação)')]];
    } catch (PDOException $e) {
        registrar_erro_tecnico('teste_conexao', $e);          // erro técnico real somente no log do servidor (dados/logs/api-erros.log)
        falhar(503, mensagem_erro_conexao($e), $base + ['codigo' => 'banco', 'conexao' => 'FALHOU',
            'linhas' => ['Conexão com MySQL: FALHOU', 'Banco: ' . $base['banco'], 'Servidor: ' . $base['servidor'] . ($porta !== 3306 ? ':' . $porta : ''), 'Motivo: ' . mensagem_erro_conexao($e)]]);
    }
    return [];
}
function executar_op(array $u, string $ns, array $op, array &$pend): array {
    $tipo = (string)($op['op'] ?? ''); $store = (string)($op['store'] ?? ''); validar_store($ns, $store);
    if ($tipo === 'clear') {
        if (!e_admin($u)) falhar(403, 'Somente o Administrador pode limpar uma tabela inteira.');
        para_lixeira($ns, $store, null, 'limpar', $u);
        q('DELETE FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ?', [$ns, $store]);
        return ['op' => 'clear', 'store' => $store];
    }
    $id = validar_id($op['id'] ?? null);
    $ex = linha($ns, $store, $id, true);
    $revCli = (int)($op['rev'] ?? 0);
    $conflito = function (string $msg) use ($ex, $store, $id) {
        falhar(409, $msg, ['codigo' => 'conflito', 'store' => $store, 'id' => $id, 'rev_servidor' => $ex ? (int)$ex['rev'] : 0,
                            'atualizado_por' => $ex['atualizado_por'] ?? null, 'atualizado_em' => $ex['atualizado_em'] ?? null]);
    };
    if ($tipo === 'put') {
        $d = $op['dados'] ?? null;
        if (!is_array($d) || (string)($d['id'] ?? '') !== $id) falhar(422, 'Registro inválido: o identificador não confere.');
        if (in_array($ns . '/' . $store, SO_INSERIR, true) && $ex) {
            if (iguais($ex['d'], $d)) return ['op' => 'put', 'store' => $store, 'id' => $id, 'rev' => (int)$ex['rev']];   // reenvio idêntico
            if (!e_admin($u)) falhar(403, 'Registros de auditoria não podem ser alterados.');
        }
        if ($ex && $revCli !== (int)$ex['rev'])
            $conflito('Este registro foi alterado por outro usuário (' . ($ex['atualizado_por'] ?? '?') . ') depois que você abriu a tela. Atualize a página para ver a versão atual.');
        if (!$ex && $revCli > 0) $conflito('Este registro foi excluído por outro usuário. Atualize a página.');
        autorizar_gravacao($u, $ns, $store, 'put', $d, $ex);
        validar_registro($ns, $store, $id, $d);
        if ($ns === 'portal' && $store === 'usuarios') tratar_senha_usuario($d, $ex, false);
        $meta = preparar_conteudo($ns, $store, $id, $d, null, $pend);
        if (!$meta && $ex && isset($ex['d']['__arquivo']) && blob_info($ns, $store)) $d['__arquivo'] = $ex['d']['__arquivo'];
        $rev = escrever($ns, $store, $id, $d, $ex, $u, $meta);
        if ($ns === 'portal' && $store === 'usuarios') garantir_admin_ativo();
        return ['op' => 'put', 'store' => $store, 'id' => $id, 'rev' => $rev];
    }
    if ($tipo === 'del') {
        if (!$ex) return ['op' => 'del', 'store' => $store, 'id' => $id, 'rev' => 0, 'inexistente' => true];
        if ($revCli > 0 && $revCli !== (int)$ex['rev']) $conflito('Este registro foi alterado por outro usuário antes da exclusão. Atualize a página.');
        autorizar_gravacao($u, $ns, $store, 'del', null, $ex);
        para_lixeira($ns, $store, $id, 'excluir', $u);
        q('DELETE FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ? AND id = ?', [$ns, $store, $id]);
        if ($ns === 'portal' && $store === 'usuarios') garantir_admin_ativo();
        return ['op' => 'del', 'store' => $store, 'id' => $id, 'rev' => 0];
    }
    falhar(400, 'Operação desconhecida: ' . $tipo);
    return [];
}

/* ======================= ARQUIVO (conteúdo de anexo, sob demanda) ======================= */
function ler_conteudo(string $ns, string $store, array $ex) {
    $meta = $ex['d']['__arquivo'] ?? null;
    if (!$meta) return null;
    $arq = rtrim((string)cfg('dir_dados'), '/\\') . '/arquivos/' . nome_arquivo_seguro($ns, $store, (string)$ex['id']);
    if (!is_file($arq)) { log_api('arquivo', 'erro', 404, 'arquivo ausente em disco', $ns, $store, (string)$ex['id']); falhar(404, 'O conteúdo do arquivo não foi encontrado no servidor.'); }
    $bytes = (string)file_get_contents($arq);
    if (!empty($meta['sha256']) && !hash_equals((string)$meta['sha256'], hash('sha256', $bytes))) { log_api('arquivo', 'erro', 500, 'hash divergente', $ns, $store, (string)$ex['id']); falhar(500, 'O arquivo no servidor está corrompido (hash não confere).'); }
    return ($meta['formato'] ?? '') === 'texto' ? $bytes : ['__b64' => base64_encode($bytes), 'tipo' => (string)($meta['tipo'] ?? '')];
}
function acao_arquivo(string $ns, string $store, string $id): array {
    $u = usuario_atual(); validar_ns($ns); validar_store($ns, $store); $id = validar_id($id);
    if (!blob_info($ns, $store)) falhar(400, 'Tabela sem conteúdo de arquivo.');
    $ex = linha($ns, $store, $id);
    if (!$ex) falhar(404, 'Arquivo não encontrado.');
    if (!arquivo_visivel($u, $ns, $store, $ex)) { log_api('arquivo', 'negado', 403, '', $ns, $store, $id); falhar(403, 'Você não tem acesso a este arquivo.'); }
    $d = $ex['d']; $campo = blob_info($ns, $store)[0];
    $d[$campo] = ler_conteudo($ns, $store, $ex); unset($d['__arquivo']);
    return ['registro' => $d, 'rev' => (int)$ex['rev']];
}

/* ======================= BACKUP COMPLETO (servidor → arquivo) ======================= */
function pode_backup(array $u): bool {
    if (e_admin($u)) return true;
    $perm = (array)($u['permBackup'] ?? []);
    if (!array_intersect(['CRIAR', 'EXPORTAR', 'BAIXAR'], $perm)) return false;
    return count(empresas_ativas()) <= 1 || (($u['visaoCorporativa'] ?? false) === true && count(empresas_permitidas($u)) > 1);
}
function acao_backup(bool $comArquivos): void {
    $u = usuario_atual();
    if (!pode_backup($u)) { log_api('backup', 'negado', 403); falhar(403, 'Você não tem permissão para gerar o backup completo do sistema.'); }
    @set_time_limit(300);
    $admin = e_admin($u);
    $json = function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); };
    http_response_code(200);
    echo '{"ok":true,"formato":"setta-servidor","versao_api":' . $json(SETTA_API) . ',"gerado_em":' . $json(gmdate('c')) . ',"gerado_por":' . $json($u['login'] ?? '') . ',"registros":{';
    $pNs = true;
    foreach (NS_STORES as $ns => $stores) {
        echo ($pNs ? '' : ',') . $json($ns) . ':{'; $pNs = false; $pS = true;
        foreach ($stores as $s) {
            echo ($pS ? '' : ',') . $json($s) . ':['; $pS = false; $pR = true;
            foreach (q('SELECT id, dados FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ? ORDER BY id', [$ns, $s]) as $r) {
                $d = json_decode((string)$r['dados'], true); if (!is_array($d)) continue;
                if (!$admin && (($ns === 'portal' && $s === 'usuarios') || ($ns === 'sp' && $s === 'users'))) unset($d['senha']);
                echo ($pR ? '' : ',') . $json($d); $pR = false;
            }
            echo ']';
        }
        echo '}';
    }
    echo '},"arquivos":{';
    if ($comArquivos) {
        $pA = true;
        foreach (BLOB_STORES as $chave => $info) {
            [$ns, $s] = explode('/', $chave);
            foreach (q('SELECT id, dados FROM ' . tabela('registros') . ' WHERE ns = ? AND store = ?', [$ns, $s]) as $r) {
                $d = json_decode((string)$r['dados'], true); if (!is_array($d) || empty($d['__arquivo'])) continue;
                try { $c = ler_conteudo($ns, $s, ['id' => $r['id'], 'd' => $d]); } catch (ErroApi $e) { continue; }
                echo ($pA ? '' : ',') . $json($chave . '/' . $r['id']) . ':' . $json($c); $pA = false;
            }
        }
    }
    echo '}}';
    log_api('backup', 'sucesso', 200, $comArquivos ? 'com arquivos' : 'sem arquivos');
    exit;
}

/* ======================= RESTAURAR (arquivo → servidor) — somente Administrador ======================= */
function acao_restaurar(array $req): array {
    $u = usuario_atual();
    if (!e_admin($u)) { log_api('restaurar', 'negado', 403); falhar(403, 'Somente o Administrador pode restaurar dados no servidor.'); }
    return restaurar_nucleo($u, $req);
}
/* núcleo da restauração/migração (também usado por ferramentas/importar-backup.php, na linha de comando do servidor) */
function restaurar_nucleo(array $u, array $req): array {
    $regs = $req['registros'] ?? null;
    if (!is_array($regs) || !$regs) falhar(422, 'Arquivo de restauração sem registros.');
    $alvos = array_keys($regs);
    foreach ($alvos as $ns) validar_ns((string)$ns);
    if (in_array('portal', $alvos, true) && empty($regs['portal']['usuarios'])) falhar(422, 'Restauração recusada: o arquivo não contém usuários do portal.');
    $duplicidades = deduplicar($regs);
    $arquivos = is_array($req['arquivos'] ?? null) ? $req['arquivos'] : [];
    @set_time_limit(300);
    // 1) cópia de segurança do estado atual no servidor (dados/backups) antes de qualquer alteração
    $copia = ['gerado_em' => gmdate('c'), 'motivo' => 'antes da restauração', 'por' => $u['login'] ?? '', 'registros' => []];
    foreach ($alvos as $ns) foreach (q('SELECT store, id, empresa_id, dados, rev FROM ' . tabela('registros') . ' WHERE ns = ?', [$ns]) as $r) $copia['registros'][] = $r;
    $txt = json_encode($copia, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $nomeCopia = 'pre-restauracao-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . (function_exists('gzencode') ? '.json.gz' : '.json');
    if (@file_put_contents(dir_dados('backups') . '/' . $nomeCopia, function_exists('gzencode') ? gzencode($txt, 6) : $txt, LOCK_EX) === false)
        falhar(500, 'Não foi possível gravar a cópia de segurança antes da restauração (permissão da pasta dados/backups). Nada foi alterado.');
    $senhasAntes = [];
    foreach (todos('portal', 'usuarios') as $r) $senhasAntes[(string)$r['id']] = ['senha' => $r['d']['senha'] ?? null];
    $operador = linha('portal', 'usuarios', (string)$u['id']);       // quem está restaurando nunca perde o acesso
    $novoUid = null; $preservado = null;
    $pdo = db(); $pend = []; $total = 0;
    $pdo->beginTransaction();
    try {
        foreach ($alvos as $ns) {
            foreach (NS_STORES[$ns] as $s) para_lixeira($ns, $s, null, 'restaurar', $u);
            q('DELETE FROM ' . tabela('registros') . ' WHERE ns = ?', [$ns]);
            foreach ($regs[$ns] as $store => $lista) {
                validar_store($ns, (string)$store); if (!is_array($lista)) continue;
                foreach ($lista as $d) {
                    if (!is_array($d)) continue; $id = validar_id($d['id'] ?? null);
                    if ($ns === 'portal' && $store === 'usuarios') tratar_senha_usuario($d, null, true, $senhasAntes[$id] ?? null);
                    $ext = null; $k = $ns . '/' . $store . '/' . $id;
                    if (array_key_exists($k, $arquivos)) $ext = ['v' => $arquivos[$k]];
                    $arqOrig = is_array($d['__arquivo'] ?? null) ? $d['__arquivo'] : null; unset($d['__arquivo']);
                    $meta = preparar_conteudo($ns, (string)$store, $id, $d, $ext, $pend);
                    if (!$meta && ($bi = blob_info($ns, (string)$store))) {
                        // backup sem conteúdo do arquivo: reaproveita o arquivo que já está no servidor (mesmo id), conferindo o hash
                        $f = rtrim((string)cfg('dir_dados'), '/\\') . '/arquivos/' . nome_arquivo_seguro($ns, (string)$store, $id);
                        if (is_file($f)) {
                            $sha = hash_file('sha256', $f);
                            $meta = ['formato' => $bi[1], 'tipo' => (string)($arqOrig['tipo'] ?? ''), 'tamanho' => filesize($f), 'sha256' => $sha, 'arquivo' => basename($f)];
                            $d['__arquivo'] = ['formato' => $bi[1], 'tipo' => $meta['tipo'], 'tamanho' => $meta['tamanho'], 'sha256' => $sha];
                        }
                    }
                    escrever($ns, (string)$store, $id, $d, linha($ns, (string)$store, $id, true), $u, $meta);
                    $total++;
                }
            }
        }
        if (in_array('portal', $alvos, true) && $operador) {
            // o Administrador que restaura continua com acesso (e com a senha que acabou de usar)
            $atual = linha('portal', 'usuarios', (string)$u['id'], true);
            if (!$atual || ($atual['d']['status'] ?? '') !== 'Ativo' || !e_admin($atual['d'])) {
                $loginOp = strtolower((string)($operador['d']['login'] ?? '')); $mesmo = null;
                foreach (todos('portal', 'usuarios') as $r) { if (strtolower((string)($r['d']['login'] ?? '')) === $loginOp) { $mesmo = $r; break; } }
                if ($mesmo && e_admin($mesmo['d'])) {
                    $d = $mesmo['d']; $d['senha'] = $operador['d']['senha'] ?? ($d['senha'] ?? null); $d['status'] = 'Ativo'; $d['mustReset'] = false;
                    escrever('portal', 'usuarios', (string)$mesmo['id'], $d, linha('portal', 'usuarios', (string)$mesmo['id'], true), $u, null);
                    $novoUid = (string)$mesmo['id']; $preservado = 'O backup tem o administrador "' . $loginOp . '": sua sessão foi transferida para ele e a sua senha atual foi mantida.';
                } elseif ($mesmo) {
                    falhar(422, 'Restauração recusada: o backup tem um usuário "' . $loginOp . '" que não é Administrador (mesmo login de quem está restaurando). Nada foi alterado.');
                } else {
                    escrever('portal', 'usuarios', (string)$u['id'], $operador['d'], $atual, $u, null);
                    $preservado = 'Sua conta de administrador não existia no backup e foi mantida.';
                }
            }
        }
        if (in_array('portal', $alvos, true)) garantir_admin_ativo();
        $marca = nova_marca();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); concluir_arquivos($pend, false);
        log_api('restaurar', 'erro', $e instanceof ErroApi ? $e->http : 500, $e->getMessage());
        throw $e;
    }
    concluir_arquivos($pend, true);
    if ($novoUid) { $_SESSION['uid'] = $novoUid; }
    log_api('restaurar', 'sucesso', 200, "$total registro(s) em " . implode(',', $alvos) . "; cópia anterior: $nomeCopia" . ($preservado ? "; $preservado" : ''));
    return ['registros' => $total, 'modulos' => $alvos, 'copia_anterior' => $nomeCopia, 'marca' => $marca, 'operador' => $preservado,
            'usuario_id' => $novoUid ?? (string)$u['id'], 'duplicidades' => $duplicidades];
}

/* ======================= DIAGNÓSTICO (instalação e suporte) ======================= */
function acao_diagnostico(): array {
    $ver = function ($ok, $msg) { return ['ok' => (bool)$ok, 'detalhe' => $msg]; };
    $c = [];
    $c['php'] = $ver(version_compare(PHP_VERSION, '7.4.0', '>='), 'PHP ' . PHP_VERSION . ' (mínimo 7.4)');
    foreach (['pdo_mysql', 'json', 'openssl', 'zlib'] as $ext) $c['ext_' . $ext] = $ver(extension_loaded($ext), $ext);
    $c['ext_mbstring_ou_iconv'] = $ver(extension_loaded('mbstring') || extension_loaded('iconv'), 'mbstring/iconv');
    $c['configurado'] = $ver(configurado(), 'credenciais do banco: ' . env_origem());
    $c['chave_instalacao'] = $ver(strlen((string)cfg('chave_instalacao')) >= 12, 'SETTA_CHAVE_INSTALACAO definida');
    $base = rtrim((string)cfg('dir_dados'), '/\\');
    foreach (['', '/arquivos', '/backups', '/logs'] as $sub) $c['pasta_dados' . str_replace('/', '_', $sub)] = $ver(is_dir($base . $sub) && is_writable($base . $sub), 'dados' . $sub . ' gravável');
    $c['https'] = $ver(https_ativo(), https_ativo() ? 'HTTPS ativo' : 'sem HTTPS (ative o SSL no painel e depois SETTA_EXIGIR_HTTPS=true no .env)');
    $c['limites'] = $ver(true, 'post_max_size=' . ini_get('post_max_size') . ' · upload_max_filesize=' . ini_get('upload_max_filesize') . ' · memory_limit=' . ini_get('memory_limit') . ' · max_execution_time=' . ini_get('max_execution_time'));
    if (configurado()) {
        try { db(); $c['banco'] = $ver(true, 'conexão MySQL OK (' . (string)db()->getAttribute(PDO::ATTR_SERVER_VERSION) . ')'); $c['tabelas'] = $ver(tabelas_existem(), 'tabelas do db.sql'); }
        catch (Throwable $e) { $c['banco'] = $ver(false, $e->getMessage()); }
        if (!empty($c['tabelas']['ok'])) {
            $c['instalado'] = $ver(instalado(), instalado() ? 'instalado em ' . meta('instalado_em') : 'não instalado');
            $c['registros'] = $ver(true, (string)q('SELECT COUNT(*) FROM ' . tabela('registros'))->fetchColumn() . ' registro(s)');
            $c['esquema'] = $ver((int)(meta('schema_versao') ?? 0) >= ESQUEMA_VERSAO, 'estrutura do banco v' . (meta('schema_versao') ?? '?') . ' (atual: v' . ESQUEMA_VERSAO . ')');
            if (instalado()) $integridade = verificar_integridade();
        }
    }
    return ['verificacoes' => $c, 'integridade' => $integridade ?? null,
            'tudo_ok' => !in_array(false, array_map(function ($x) { return $x['ok']; }, array_diff_key($c, ['https' => 1, 'instalado' => 1])), true)];
}
