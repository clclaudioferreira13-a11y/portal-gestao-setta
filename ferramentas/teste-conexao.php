<?php
/* Teste de conexão com o MySQL (sem exibir a senha).   Uso:  php ferramentas/teste-conexao.php */
require __DIR__ . '/_cli.php';
executar_cli(function () {
    if (!configurado()) erro_fatal('Variáveis DB_HOST, DB_NAME, DB_USER e DB_PASSWORD não encontradas (' . env_origem() . ').');
    $d = cfg('db'); $porta = (int)($d['porta'] ?: 3306);
    saida('Configuração lida de: ' . env_origem());
    $t0 = microtime(true);
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $d['host'], $porta, $d['nome']), $d['usuario'], $d['senha'],
                       [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
    } catch (PDOException $e) {
        saida('Conexão com MySQL: FALHOU');
        saida('Banco: ' . $d['nome']);
        saida('Servidor: ' . $d['host'] . ($porta !== 3306 ? ':' . $porta : ''));
        saida('Motivo: ' . mensagem_erro_conexao($e));
        saida('Detalhe técnico: ' . preg_replace('/(password|pwd)\s*=\s*[^;\s]+/i', '$1=***', $e->getMessage()));
        exit(2);
    }
    $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    saida('Conexão com MySQL: OK');
    saida('Banco: ' . $d['nome']);
    saida('Servidor: ' . $d['host'] . ($porta !== 3306 ? ':' . $porta : ''));
    saida('Versão: ' . $ver . ' · tempo: ' . (int)round((microtime(true) - $t0) * 1000) . ' ms');
    $tem = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote(tabela('registros')))->fetchColumn();
    saida('Tabelas do portal: ' . ($tem ? 'criadas' : 'ainda não criadas (rode ferramentas/instalar-banco.php ou faça a instalação pelo navegador)'));
});
