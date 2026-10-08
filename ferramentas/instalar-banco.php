<?php
/* Cria/atualiza a estrutura do banco (tabelas + visões relacionais). Idempotente: NUNCA apaga dados.
   Uso:  php ferramentas/instalar-banco.php
   (Equivale a importar o db.sql no phpMyAdmin; a instalação pelo navegador também faz isto sozinha.) */
require __DIR__ . '/_cli.php';
executar_cli(function () {
    $antes = tabelas_existem() ? (meta('schema_versao') ?? '?') : 'sem tabelas';
    $r = aplicar_esquema();
    saida("Estrutura aplicada: {$r['tabelas']} comando(s) de tabela, {$r['visoes']} visão(ões) relacional(is).");
    foreach ($r['avisos'] as $a) saida('Aviso: ' . $a);
    saida("Versão do esquema: $antes → " . ESQUEMA_VERSAO);
    saida('Portal instalado: ' . (instalado() ? 'sim (' . meta('instalado_em') . ')' : 'não — conclua a instalação pelo navegador (tela "Instalação do servidor").'));
    $n = (int)q('SELECT COUNT(*) FROM ' . tabela('registros'))->fetchColumn();
    saida("Registros existentes: $n (nenhum foi alterado).");
});
