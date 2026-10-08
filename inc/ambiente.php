<?php
/* Portal de Gestão — Grupo Setta · variáveis de ambiente (credenciais NUNCA ficam no código nem em arquivos públicos).
   Ordem de leitura (o primeiro valor encontrado vale):
     1) variáveis de ambiente reais do servidor (getenv / $_SERVER), quando o painel permitir defini-las;
     2) arquivo indicado em SETTA_ENV_FILE;
     3) ../.env  (uma pasta ACIMA do portal — recomendado quando o portal está na raiz do public_html);
     4) ./.env   (na pasta do portal — protegido pelo .htaccess: o navegador recebe 403). */
if (!defined('SETTA_API')) { http_response_code(403); exit; }

function env_arquivos(): array {
    $raiz = dirname(__DIR__);
    $lista = [];
    $indicado = getenv('SETTA_ENV_FILE');
    if ($indicado) $lista[] = $indicado;
    $lista[] = dirname($raiz) . '/.env';
    $lista[] = $raiz . '/.env';
    return $lista;
}
function env_ler_arquivo(string $arq): array {
    $out = [];
    $linhas = @file($arq, FILE_IGNORE_NEW_LINES);
    if ($linhas === false) return $out;
    foreach ($linhas as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#') continue;
        if (strpos($l, 'export ') === 0) $l = trim(substr($l, 7));
        $p = strpos($l, '='); if ($p === false) continue;
        $k = trim(substr($l, 0, $p)); $v = trim(substr($l, $p + 1));
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $k)) continue;
        $n = strlen($v);
        if ($n >= 2 && $v[0] === "'" && $v[$n - 1] === "'") $v = substr($v, 1, -1);                         // literal, sem escapes
        elseif ($n >= 2 && $v[0] === '"' && $v[$n - 1] === '"') $v = stripcslashes(substr($v, 1, -1));      // aceita \" \\ \n
        else { $h = strpos($v, ' #'); if ($h !== false) $v = rtrim(substr($v, 0, $h)); }                     // comentário no fim da linha
        $out[$k] = $v;
    }
    return $out;
}
function env_todas(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = []; $origem = null;
    foreach (env_arquivos() as $arq) {
        if (is_file($arq) && is_readable($arq)) { $cache = env_ler_arquivo($arq); $origem = $arq; break; }
    }
    $cache['__ORIGEM__'] = $origem;
    return $cache;
}
/* valor de uma variável: ambiente real do servidor tem prioridade sobre o arquivo .env */
function env(string $k, $padrao = null) {
    $v = getenv($k);
    if ($v === false && isset($_SERVER[$k]) && is_string($_SERVER[$k])) $v = $_SERVER[$k];
    if ($v !== false && $v !== '') return $v;
    $a = env_todas();
    return array_key_exists($k, $a) && $a[$k] !== '' ? $a[$k] : $padrao;
}
function env_bool(string $k, bool $padrao = false): bool {
    $v = env($k, null); if ($v === null) return $padrao;
    return in_array(strtolower((string)$v), ['1', 'true', 'sim', 'yes', 'on'], true);
}
/* onde a configuração foi encontrada (para o diagnóstico — nunca devolve valores) */
function env_origem(): string {
    $o = env_todas()['__ORIGEM__'] ?? null;
    if (getenv('DB_HOST') !== false) return 'variáveis de ambiente do servidor';
    if (!$o) return 'nenhum .env encontrado';
    return dirname(realpath($o) ?: $o) === dirname(__DIR__) ? '.env na pasta do portal' : '.env fora da pasta pública';
}
