<?php
/* Gera o db.sql a partir de inc/esquema.php (fonte única da estrutura).   Uso:  php ferramentas/gerar-db-sql.php [prefixo] */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('SETTA_API', 'gerador');
require dirname(__DIR__) . '/inc/esquema.php';
$p = preg_replace('/[^a-z0-9_]/i', '', $argv[1] ?? 'setta_');
file_put_contents(dirname(__DIR__) . '/db.sql', esquema_sql_completo($p));
echo "db.sql gerado (prefixo $p, esquema v" . ESQUEMA_VERSAO . ")" . PHP_EOL;
