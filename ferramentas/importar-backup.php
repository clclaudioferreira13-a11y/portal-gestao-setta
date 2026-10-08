<?php
/* Migração/importação pela linha de comando: grava no MySQL o conteúdo de um backup JSON.
   Aceita: (a) backup completo do servidor  — GET /api/backup  (formato "setta-servidor", todos os módulos + arquivos)
           (b) backup JSON do botão "Backup do módulo" (formato "setta-gd": Gestão de Documentos e cadastros do portal)
   Para o arquivo .backup criptografado (Backup e Restauração do Sistema) use a tela do portal: Geral → Backup e Restauração.
   Antes de gravar: trata duplicidades, guarda cópia do estado atual em dados/backups/ e grava tudo numa única transação.
   Uso:  php ferramentas/importar-backup.php caminho/do/backup.json [--confirmar] */
require __DIR__ . '/_cli.php';
executar_cli(function () use ($argv) {
    $arq = $argv[1] ?? '';
    if ($arq === '' || !is_file($arq)) erro_fatal('Informe o arquivo de backup: php ferramentas/importar-backup.php backup.json --confirmar');
    $j = json_decode((string)file_get_contents($arq), true);
    if (!is_array($j)) erro_fatal('Arquivo inválido: não é um JSON legível.');
    if (($j['formato'] ?? '') === 'setta-servidor') { $regs = $j['registros'] ?? []; $arquivos = $j['arquivos'] ?? []; $tipo = 'backup completo do servidor'; }
    elseif (($j['_app'] ?? '') === 'setta-gd' && is_array($j['stores'] ?? null)) {
        if (($j['_escopo'] ?? '') === 'empresa') erro_fatal('Este backup contém só parte das empresas e não pode substituir a base do grupo.');
        $regs = ['portal' => $j['stores']]; $arquivos = []; $tipo = 'backup JSON do portal (Gestão de Documentos e cadastros)';
    } else erro_fatal('Formato não reconhecido (esperado: backup completo do servidor ou backup JSON do portal).');
    $cont = []; foreach ($regs as $ns => $st) foreach ((array)$st as $s => $l) $cont[] = "$ns/$s=" . count((array)$l);
    saida("Arquivo: $tipo"); saida('Conteúdo: ' . implode(', ', $cont));
    if (!in_array('--confirmar', $argv, true)) { saida('Nada foi gravado. Repita com --confirmar para substituir estes módulos no banco (uma cópia do estado atual é guardada antes).'); return; }
    if (!tabelas_existem()) aplicar_esquema();
    $r = restaurar_nucleo(['id' => '', 'login' => 'ferramenta-cli', 'perfil' => 'Administrador'], ['registros' => $regs, 'arquivos' => $arquivos]);
    saida("Gravados: {$r['registros']} registro(s) em " . implode(', ', $r['modulos']) . '.');
    foreach ($r['duplicidades'] as $d) saida('Duplicidade tratada: ' . $d);
    saida('Cópia do estado anterior: dados/backups/' . $r['copia_anterior']);
    if (!instalado()) { meta('instalado_em', gmdate('c')); saida('Portal marcado como instalado (os usuários do backup já podem entrar).'); }
});
