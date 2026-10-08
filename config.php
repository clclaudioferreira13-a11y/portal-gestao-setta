<?php
/* =====================================================================
   Portal de Gestão — Grupo Setta · CONFIGURAÇÃO DO SERVIDOR
   ---------------------------------------------------------------------
   Este arquivo NÃO contém credenciais: tudo vem de variáveis de ambiente
   (arquivo .env fora do Git e bloqueado ao navegador — ver .env.example e
   README-LOCALWEB.md). Pode ser versionado com segurança.
   ===================================================================== */
if (!defined('SETTA_API')) { http_response_code(403); exit; }
require_once __DIR__ . '/inc/ambiente.php';

return [
    'db' => [
        'host'    => env('DB_HOST'),
        'porta'   => (int)env('DB_PORT', 3306),
        'nome'    => env('DB_NAME'),
        'usuario' => env('DB_USER'),
        'senha'   => env('DB_PASSWORD'),
    ],

    // Chave exigida UMA ÚNICA VEZ na instalação inicial (e para testar a conexão antes dela)
    'chave_instalacao' => env('SETTA_CHAVE_INSTALACAO'),

    // Prefixo das tabelas e visões
    'prefixo' => env('DB_PREFIXO', 'setta_'),

    // Sessão: minutos de inatividade até exigir novo login
    'sessao_minutos' => (int)env('SETTA_SESSAO_MINUTOS', 480),

    // Pasta de dados (anexos, cópias de segurança e logs). Precisa permitir gravação pelo PHP.
    'dir_dados' => env('SETTA_DIR_DADOS', __DIR__ . '/dados'),

    // Tamanho máximo de um arquivo anexado (MB). Precisa caber em post_max_size (.user.ini).
    'max_arquivo_mb' => (int)env('SETTA_MAX_ARQUIVO_MB', 40),

    // true = recusa chamadas sem HTTPS (ative depois que o certificado SSL estiver ativo)
    'exigir_https' => env_bool('SETTA_EXIGIR_HTTPS', false),

    // Tentativas de login com senha errada antes de bloquear por 15 minutos
    'max_tentativas_login' => (int)env('SETTA_MAX_TENTATIVAS_LOGIN', 5),

    // Somente diagnóstico: inclui o detalhe técnico do erro na resposta (NUNCA em produção)
    'debug' => env_bool('SETTA_DEBUG', false),
];
