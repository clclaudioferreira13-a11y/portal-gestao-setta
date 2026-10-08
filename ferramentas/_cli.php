<?php
/* Portal de Gestão — Grupo Setta · base das ferramentas de LINHA DE COMANDO (SSH/terminal do servidor).
   Nunca executam pelo navegador (bloqueadas pelo .htaccess e por esta verificação). Usam as mesmas credenciais do .env. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Somente linha de comando.'); }
define('SETTA_API', '1.1.0-cli');
error_reporting(E_ALL);
$raiz = dirname(__DIR__);
require $raiz . '/inc/ambiente.php';
require $raiz . '/inc/nucleo.php';
require $raiz . '/inc/esquema.php';
require $raiz . '/inc/regras.php';
require $raiz . '/inc/acoes.php';
function saida(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function erro_fatal(string $s, int $cod = 1): void { fwrite(STDERR, 'ERRO: ' . $s . PHP_EOL); exit($cod); }
/* executa um bloco convertendo erros da API em mensagens de terminal (o detalhe técnico é seguro aqui: o terminal é do servidor) */
function executar_cli(callable $fn): void {
    try { $fn(); }
    catch (ErroApi $e) { erro_fatal($e->getMessage()); }
    catch (PDOException $e) { erro_fatal((function_exists('mensagem_erro_conexao') ? mensagem_erro_conexao($e) : 'Erro no banco') . ' [detalhe técnico: ' . preg_replace('/(password|pwd)\s*=\s*[^;\s]+/i', '$1=***', $e->getMessage()) . ']'); }
    catch (Throwable $e) { erro_fatal(get_class($e) . ': ' . $e->getMessage()); }
}
