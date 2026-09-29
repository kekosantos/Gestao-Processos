# Deploy do LexCloud no Fly.io

## Pré-requisitos

- Conta Fly.io e `flyctl` instalado no computador usado para a implantação.
- MySQL compatível externo, com TLS, usuário exclusivo e database preparado. Esta imagem não executa nem persiste um servidor MySQL local.
- ZIP extraído. Execute os comandos a seguir na pasta raiz que contém `Dockerfile` e `fly.toml`.

## 1. Ajustar configuração e banco

1. Edite `fly.toml`: troque `app = "lexcloud-seu-nome-unico"` por um nome ainda livre e confirme a região. O valor padrão `gru` é São Paulo; escolha outra região se for melhor para a localização do banco/usuários.
2. Crie um database vazio `lexcloud` no seu provedor e um usuário de aplicação com privilégios mínimos, incluindo criação de tabelas para a primeira migração.
3. Copie `deploy/fly-secrets.example` para `deploy/fly-secrets.local` e substitua os placeholders pela URL real de conexão TLS, sem aspas:

   ```bash
   cp deploy/fly-secrets.example deploy/fly-secrets.local
   # Edite deploy/fly-secrets.local em um editor local seguro.
   ```

   Exemplo de formato: `mysqls://USUARIO:SENHA_URL_ENCODED@HOST:4000/lexcloud`. Codifique caracteres especiais da senha para URL. O arquivo `deploy/fly-secrets.local` está excluído do Git. Não inclua o segredo no código, no `fly.toml` ou em mensagens.

## 2. Criar o app e gravar o segredo

No terminal autenticado com `fly auth login`, a partir da raiz do projeto:

```bash
fly launch --no-deploy --name lexcloud-seu-nome-unico
fly secrets import < deploy/fly-secrets.local
```

Use exatamente o mesmo nome definido em `fly.toml`. O comando `fly secrets import` recebe `NOME=VALOR` pela entrada padrão e armazena o valor cifrado no vault do app. A senha aparece apenas no seu arquivo local e no ambiente de execução da aplicação; nunca a imprima em logs.

## 3. Publicar

```bash
fly deploy
fly status
fly logs
```

O Fly constrói o `Dockerfile`. Antes de atualizar as Machines, `release_command = "php bin/migrate.php"` aplica a migração aditiva uma vez. Se a migração ou a verificação de disponibilidade falhar, pare e corrija conexão TLS, URL, grants do usuário ou allowlist de rede do banco antes de reenviar.

A configuração usa HTTPS forçado, listener interno `8080`, health check HTTP em `/api/health`, 512 MB de memória e mantém no mínimo uma Machine ativa na região primária. Confira custos e cotas do Fly antes de operar continuamente.

## Sessões: mantenha uma Machine

As sessões atuais do PHP são arquivos locais e não são compartilhadas entre Machines. Confirme que há somente uma antes de receber usuários:

```bash
fly scale count 1
fly status
```

O primeiro deploy pode provisionar Machines para redundância. Não escale horizontalmente até implementar sessão compartilhada e testar a distribuição das requisições. Reinícios/novos deploys podem exigir que usuários façam login novamente.

## PWA e celular

Abra a URL HTTPS do app. Android/Chrome oferece **Instalar app/Adicionar à tela inicial**; iOS/iPadOS: Safari → **Compartilhar → Adicionar à Tela de Início**. O layout é responsivo e instala como app, mas a tela offline não permite consultar ou editar informações jurídicas; o service worker não guarda API, sessão nem documentos.

## Diagnóstico e segurança

- Verifique `fly logs` sem copiar segredos para chamados públicos.
- `GET /api/health` deve retornar 200; 503 significa que algum pré-requisito (banco, TLS, extensões ou limites de upload) não está pronto.
- Atualize um segredo no Fly apenas pelos comandos documentados de `fly secrets`; uma atualização de segredo pode reiniciar as Machines.
- Planeje backup/restauração do MySQL, domínio, LGPD, recuperação de acesso, alertas e rotação de credenciais antes de inserir documentos reais.
- O seed de demonstração permanece bloqueado em `APP_ENV=production`; não use dados do escritório em ambientes de demonstração.

## Referências oficiais (consultadas em 28/09/2026)

- [Fly.io: criar app](https://fly.io/docs/launch/create/) — `fly launch --no-deploy` e app name.
- [Fly.io: deploy](https://fly.io/docs/launch/deploy/) — build do Dockerfile, release command e Machines.
- [Fly.io: configuração `fly.toml`](https://fly.io/docs/reference/configuration/) — HTTP service, região, health checks, `auto_stop_machines` e `release_command`.
- [Fly.io: secrets](https://fly.io/docs/apps/secrets/) e [fly secrets import](https://fly.io/docs/flyctl/secrets-import/) — segredos cifrados e entrada `NAME=VALUE`.
- [TiDB Cloud: conexão Starter/Essential](https://docs.pingcap.com/tidbcloud/connect-to-tidb-cluster-serverless/) e [conexões TLS](https://docs.pingcap.com/tidbcloud/secure-connections-to-serverless-clusters/) — protocolo MySQL e exigência de TLS.
