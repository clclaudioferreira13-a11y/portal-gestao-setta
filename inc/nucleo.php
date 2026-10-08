<?php
/* Portal de Gestão — Grupo Setta · núcleo da API (configuração, banco, HTTP, sessão, log). PHP 7.4+ */
if (!defined('SETTA_API')) { http_response_code(403); exit; }

final class ErroApi extends Exception {
    public $http; public $extra;
    public function __construct(int $http, string $msg, array $extra = []) { parent::__construct($msg); $this->http = $http; $this->extra = $extra; }
}
function falhar(int $http, string $msg, array $extra = []): void { throw new ErroApi($http, $msg, $extra); }

/* ---------------- configuração ---------------- */
function cfg(?string $chave = null) {
    static $c = null;
    if ($c === null) {
        $arq = dirname(__DIR__) . '/config.php';
        if (!is_file($arq)) falhar(503, 'Configuração pendente: arquivo config.php não encontrado no servidor.');
        require_once __DIR__ . '/ambiente.php';
        $c = require $arq;
        if (!is_array($c)) falhar(503, 'Configuração inválida: config.php precisa retornar um array.');
    }
    return $chave === null ? $c : ($c[$chave] ?? null);
}
function configurado(): bool {
    $c = cfg(); $db = $c['db'] ?? [];
    foreach (['host', 'nome', 'usuario'] as $k) { if (empty($db[$k]) || strpos((string)$db[$k], 'TROQUE_') === 0) return false; }
    if ($db['senha'] === null || strpos((string)$db['senha'], 'TROQUE_') === 0) return false;
    return true;
}
function tabela(string $nome): string { return preg_replace('/[^a-z0-9_]/i', '', (string)cfg('prefixo')) . $nome; }

/* ---------------- banco (PDO, queries sempre parametrizadas) ---------------- */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    if (!configurado()) falhar(503, 'Configuração pendente: as variáveis DB_HOST, DB_NAME, DB_USER e DB_PASSWORD não foram encontradas (arquivo .env ou variáveis de ambiente do servidor).');
    $d = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], (int)($d['porta'] ?? 3306), $d['nome']);
    try {
        $pdo = new PDO($dsn, $d['usuario'], $d['senha'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");
    } catch (PDOException $e) {
        registrar_erro_tecnico('conexao', $e);
        falhar(503, 'Banco de dados indisponível: ' . (function_exists('mensagem_erro_conexao') ? mensagem_erro_conexao($e) : 'não foi possível conectar ao MySQL.') . ' Nada foi gravado.', ['codigo' => 'banco']);
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute($p); return $st; }
function agora(): string { return gmdate('Y-m-d H:i:s'); }
function tabelas_existem(): bool {
    try { q('SELECT 1 FROM ' . tabela('registros') . ' LIMIT 1'); q('SELECT 1 FROM ' . tabela('meta') . ' LIMIT 1'); return true; }
    catch (PDOException $e) { return false; }
}
function meta(string $chave, ?string $valor = null): ?string {
    if ($valor !== null) {
        q('INSERT INTO ' . tabela('meta') . ' (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)', [$chave, $valor]);
        return $valor;
    }
    $v = q('SELECT valor FROM ' . tabela('meta') . ' WHERE chave = ?', [$chave])->fetchColumn();
    return $v === false ? null : (string)$v;
}
function nova_marca(): string { q('UPDATE ' . tabela('meta') . " SET valor = CAST(valor AS UNSIGNED) + 1 WHERE chave = 'marca'"); return (string)meta('marca'); }
function instalado(): bool { return meta('instalado_em') !== null; }

/* ---------------- HTTP ---------------- */
function https_ativo(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}
function cabecalhos(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: SAMEORIGIN');
}
function responder(array $dados, int $http = 200): void {
    http_response_code($http);
    echo json_encode(['ok' => true] + $dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function responder_erro(int $http, string $msg, array $extra = []): void {
    if (!headers_sent()) { http_response_code($http); cabecalhos(); }
    echo json_encode(['ok' => false, 'http' => $http, 'erro' => $msg] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
/* ---------------- CORS: a interface pode ficar em outro domínio (ex.: GitHub Pages) e chamar esta API ----------------
   Somente as origens listadas em SETTA_CORS_ORIGENS (separadas por vírgula, ex.: https://usuario.github.io) são aceitas. */
function origens_permitidas(): array {
    $v = (string)env('SETTA_CORS_ORIGENS', '');
    return array_values(array_filter(array_map(function ($o) { return strtolower(rtrim(trim($o), '/')); }, explode(',', $v))));
}
function origem_autorizada(string $o): bool {
    $o = strtolower(rtrim($o, '/'));
    if ($o === '') return true;                                                // navegador não informou (mesma origem)
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '' && (preg_replace('#^https?://#', '', $o) === $host)) return true;   // mesma origem
    return in_array($o, origens_permitidas(), true);
}
function aplicar_cors(): void {
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o !== '' && origem_autorizada($o)) {
        header('Access-Control-Allow-Origin: ' . $o);
        header('Vary: Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Setta-Token, X-Setta-Sessao, If-Match, X-HTTP-Method-Override');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Expose-Headers: ETag');
        header('Access-Control-Max-Age: 600');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code($o !== '' && origem_autorizada($o) ? 204 : 403); exit; }
}
function exigir_post(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') falhar(405, 'Método não permitido.');
    if (!origem_autorizada((string)($_SERVER['HTTP_ORIGIN'] ?? ''))) falhar(403, 'Origem não autorizada a usar esta API (configure SETTA_CORS_ORIGENS no servidor).');
    // proteção CSRF: formulários de outros sites não conseguem enviar este cabeçalho
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'SettaPortal') falhar(400, 'Requisição inválida (cabeçalho de origem ausente).');
}
function exigir_csrf(): void {
    $t = (string)($_SERVER['HTTP_X_SETTA_TOKEN'] ?? '');
    if ($t === '' || empty($_SESSION['csrf']) || !hash_equals((string)$_SESSION['csrf'], $t)) falhar(401, 'Sessão expirada ou inválida. Entre novamente.', ['codigo' => 'sessao']);
}
function ler_json(): array {
    $bruto = file_get_contents('php://input');
    if ($bruto === false || $bruto === '') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) falhar(413, 'Dados maiores que o limite do servidor (post_max_size). Reduza o arquivo ou ajuste o .user.ini.');
        falhar(400, 'Requisição sem dados.');
    }
    $d = json_decode($bruto, true);
    if (!is_array($d)) falhar(400, 'JSON inválido na requisição.');
    return $d;
}
function ip_cliente(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/* ---------------- sessão PHP ---------------- */
function iniciar_sessao(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    session_name('SETTA_SID');
    $entreDominios = origens_permitidas() && https_ativo();   // interface em outro domínio: cookie precisa de SameSite=None + Secure
    session_set_cookie_params(['lifetime' => 0, 'path' => $path, 'secure' => https_ativo(), 'httponly' => true, 'samesite' => $entreDominios ? 'None' : 'Lax']);
    ini_set('session.use_strict_mode', '1');                   // id desconhecido nunca é aceito (sem fixação de sessão)
    ini_set('session.gc_maxlifetime', (string)(max(30, (int)cfg('sessao_minutos')) * 60));
    // interface em outro domínio: navegadores que bloqueiam cookies de terceiros (ex.: Safari) enviam a sessão no cabeçalho
    $hdr = (string)($_SERVER['HTTP_X_SETTA_SESSAO'] ?? '');
    if ($hdr !== '' && preg_match('/^[A-Za-z0-9,-]{22,128}$/', $hdr)) session_id($hdr);
    session_start();
}
function sessao_id(): string { return session_status() === PHP_SESSION_ACTIVE ? (string)session_id() : ''; }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return (string)$_SESSION['csrf']; }

/* ---------------- log técnico ---------------- */
function log_api(string $acao, string $status, int $http, string $msg = '', ?string $ns = null, ?string $store = null, ?string $reg = null): void {
    try {
        if (!configurado()) return;
        q('INSERT INTO ' . tabela('log') . ' (data, usuario, ip, acao, ns, store, registro, status, http, mensagem) VALUES (?,?,?,?,?,?,?,?,?,?)',
          [agora(), $_SESSION['login'] ?? null, ip_cliente(), substr($acao, 0, 30), $ns, $store, $reg !== null ? substr($reg, 0, 100) : null, $status, $http, substr($msg, 0, 500)]);
    } catch (Throwable $e) { /* o log nunca derruba a operação principal */ }
}
function registrar_erro_tecnico(string $onde, Throwable $e): void {
    $dir = rtrim((string)cfg('dir_dados'), '/\\') . '/logs';
    $linha = '[' . gmdate('c') . "] [$onde] " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . PHP_EOL;
    if (is_dir($dir) && is_writable($dir)) @file_put_contents($dir . '/api-erros.log', $linha, FILE_APPEND | LOCK_EX);
    error_log('SETTA-API ' . trim($linha));
}

/* ---------------- arquivos em disco ---------------- */
function dir_dados(string $sub): string {
    $base = rtrim((string)cfg('dir_dados'), '/\\');
    $d = $base . '/' . $sub;
    if (!is_dir($d) && !@mkdir($d, 0750, true)) falhar(500, "A pasta de dados ($sub) não existe e não pôde ser criada. Verifique as permissões de gravação da pasta dados/.");
    if (!is_writable($d)) falhar(500, "A pasta de dados ($sub) não tem permissão de gravação para o PHP.");
    return $d;
}
function nome_arquivo_seguro(string $ns, string $store, string $id): string {
    return preg_replace('/[^a-z0-9]/', '', strtolower($ns)) . '_' . hash('sha256', $ns . '|' . $store . '|' . $id) . '.bin';   // nome curto e sem dados do usuário
}
