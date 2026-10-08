<?php
/* =====================================================================
   Portal de Gestão — Grupo Setta · API de persistência (PHP 7.4+ / MySQL 5.7+ / MariaDB 10.3+)
   Frontend (index.html) → api.php → MySQL → api.php → Frontend   (o navegador NUNCA fala com o MySQL)
   · Portal:  api.php?acao=estado | instalar | login | logout | sincronizar | gravar | arquivo | backup | restaurar | teste_conexao | diagnostico
   · REST:    /api/{modulo}/{entidade}[/{id}]  (GET, POST, PUT, DELETE) — ver inc/rest.php
   Credenciais: variáveis de ambiente / .env (inc/ambiente.php). Respostas sempre em JSON:
   {"ok":true,...} ou {"ok":false,"http":código,"erro":"mensagem"} — sem senha, string de conexão ou detalhe interno.
   ===================================================================== */
declare(strict_types=1);
define('SETTA_API', '1.1.0');
error_reporting(E_ALL);
ini_set('display_errors', '0');                    // erros nunca vazam como HTML: viram JSON + log técnico

require __DIR__ . '/inc/ambiente.php';
require __DIR__ . '/inc/nucleo.php';
require __DIR__ . '/inc/esquema.php';
require __DIR__ . '/inc/regras.php';
require __DIR__ . '/inc/acoes.php';
require __DIR__ . '/inc/rest.php';

set_error_handler(function ($nivel, $msg, $arq, $lin) {
    if (!(error_reporting() & $nivel)) return false;
    throw new ErrorException($msg, 0, $nivel, $arq, $lin);
});

cabecalhos();
$rota = isset($_GET['rota']) ? (string)$_GET['rota'] : null;
$acao = $rota !== null ? 'REST ' . ($_SERVER['REQUEST_METHOD'] ?? '') : (string)($_GET['acao'] ?? '');
try {
    aplicar_cors();                                 // interface publicada em outro domínio (ex.: GitHub Pages) — só origens autorizadas
    if (cfg('exigir_https') && !https_ativo()) falhar(403, 'Acesso permitido somente por HTTPS.');
    iniciar_sessao();
    // versão nova publicada: atualiza a estrutura do banco sozinha (idempotente, nunca apaga dados)
    if (configurado() && $acao !== 'teste_conexao' && $rota !== 'teste-conexao') garantir_esquema();
    if ($rota !== null) { rest($rota); }
    switch ($acao) {
        case 'estado':        responder(acao_estado());
        case 'instalar':      exigir_post(); responder(acao_instalar(ler_json()));
        case 'login':         exigir_post(); responder(acao_login(ler_json()));
        case 'logout':        exigir_post(); responder(acao_logout());
        case 'sincronizar':   responder(acao_sincronizar((string)($_GET['ns'] ?? '')));
        case 'gravar':        exigir_post(); exigir_csrf(); responder(acao_gravar(ler_json()));
        case 'arquivo':       responder(acao_arquivo((string)($_GET['ns'] ?? ''), (string)($_GET['store'] ?? ''), (string)($_GET['id'] ?? '')));
        case 'backup':        acao_backup(($_GET['arquivos'] ?? '1') !== '0');
        case 'restaurar':     exigir_post(); exigir_csrf(); responder(acao_restaurar(ler_json()));
        case 'teste_conexao': exigir_post(); responder(acao_teste_conexao(ler_json()));
        case 'diagnostico':
            // antes da instalação qualquer pessoa vê o diagnóstico (sem dados nem credenciais); depois, somente o Administrador
            if (configurado() && tabelas_existem() && instalado()) { $u = usuario_atual(); if (!e_admin($u)) falhar(403, 'Somente o Administrador pode ver o diagnóstico.'); }
            responder(acao_diagnostico());
        default:              falhar(404, 'Ação inexistente.');
    }
} catch (ErroApi $e) {
    responder_erro($e->http, $e->getMessage(), $e->extra);
} catch (PDOException $e) {
    registrar_erro_tecnico('banco:' . $acao, $e);
    log_api($acao, 'erro', 503, 'PDO: ' . $e->getCode());
    responder_erro(503, 'Erro no banco de dados. A operação NÃO foi gravada.' . (cfg('debug') ? ' [' . $e->getMessage() . ']' : ''), ['codigo' => 'banco']);
} catch (Throwable $e) {
    registrar_erro_tecnico('api:' . $acao, $e);
    log_api($acao, 'erro', 500, get_class($e));
    responder_erro(500, 'Erro interno no servidor. A operação NÃO foi gravada.' . (cfg('debug') ? ' [' . $e->getMessage() . ']' : ''), ['codigo' => 'interno']);
}
