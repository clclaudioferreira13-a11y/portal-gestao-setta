# Portal de Gestão — Grupo Setta

O Portal reúne num só lugar:
- Gestão de Documentos;
- Mapa de Processo (SIPOC);
- Gestão de Risco;
- Planos de Ação;
- Solicitações à Gestão;
- Comunicado Interno.

## Como está publicado

| Parte | Onde roda | O que é |
|---|---|---|
| **Interface** | GitHub Pages (deploy automático a cada push na `main`) | `index.html` + `config.js` + `404.html` |
| **API e dados** | Hospedagem PHP (Localweb) | `api.php`, `inc/`, banco MySQL `pqualidade` |

O GitHub Pages hospeda **apenas arquivos estáticos**. Ele não executa PHP e não grava dados. Por isso, todos os dados ficam no **MySQL**, acessado somente pela API na Localweb; o navegador nunca fala direto com o banco.

## Configuração (uma vez)

1. **API na Localweb.** Publique a API conforme o [README-LOCALWEB.md](README-LOCALWEB.md). No `.env` do servidor, autorize o endereço do Pages:
   ```
   SETTA_CORS_ORIGENS=https://SEU-USUARIO.github.io
   ```
2. **Endereço da API no GitHub.** No repositório: **Settings → Secrets and variables → Actions → Variables → New repository variable**:
   - nome: `SETTA_API_URL`
   - valor: `https://SEU-DOMINIO-LOCALWEB/api.php`
3. **Rodar o deploy de novo:** **Actions → Deploy GitHub Pages → Run workflow**.

Sem o passo 2, o Portal abre no Pages e avisa que a API ainda não foi configurada.

## Segurança

- **Credenciais:** nenhuma credencial é versionada. O `.env` está no `.gitignore`, e o workflow falha se encontrar `.env` ou senha em arquivo versionado.
- **Credenciais do banco:** ficam somente no servidor da API.
- **Acesso à API:** a API só aceita chamadas das origens autorizadas (`SETTA_CORS_ORIGENS`) e exige login. As permissões e a separação por empresa são conferidas no servidor.
