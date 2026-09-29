# Segurança, privacidade e limites do MVP

## Modelo de acesso

- Cada conta de escritório possui `tenant_id`; tabelas operacionais o carregam e consultas filtram por ele, inclusive leitura por ID.
- Chaves estrangeiras compostas associam processos, clientes e equipe dentro do mesmo tenant.
- A administração global da plataforma é provisionada à parte e retorna contagens/metadados do tenant, não clientes, documentos, financeiro ou conteúdo de processos.
- APIs privadas enviam `Cache-Control: private, no-store`; cookies de sessão são `HttpOnly`, `SameSite=Lax` e `Secure` em HTTPS. Mutations exigem token CSRF. Senhas usam o algoritmo recomendado por `password_hash`; enquanto a senha for temporária, somente sessão, logout e alteração de senha são permitidos, inclusive antes de qualquer download.
- Papéis controlam páginas, ações e endpoints: somente titular, administradores ou financeiro recebem detalhes financeiros; relatórios de consulta omitem valores financeiros. O apoio pode se autoatribuir tarefas sem consultar a lista privada da equipe.
- Upload aceita PDF, DOCX, PNG, JPG e TXT, até 12 MiB por controller, com limites compatíveis no container. Documentos são BLOB MySQL e o download verifica sessão, conclusão da troca obrigatória de senha e `tenant_id`.

## Antes de cadastrar documentos reais

Este código é um MVP e **não** substitui revisão de segurança, contrato de hospedagem, política interna, DPIA/RIPD quando aplicável, cópias de segurança, retenção e resposta a incidente. Documentos e notas podem conter dados sensíveis: valide usuários, papéis, proteção e recuperação do banco com o responsável de segurança e de proteção de dados do escritório. Não envie documentos reais para a preview.

A aplicação não promete criptografia de campo adicional, MFA, log de auditoria imutável, recuperação point-in-time, backup automático gerenciado, armazenamento externo de arquivos, e-mail, assinatura eletrônica nem certificado/integração judicial. Conteúdo e trilha estão sujeitos à disponibilidade e política do banco do host.

## Uso jurídico

Prazos, status e compromisso são registrados manualmente. Confirme todos os prazos e andamentos em fonte oficial e com o responsável do caso. Não há consulta processual automática, scraping, monitoramento de intimações, parecer jurídico por IA, medição de êxito ou geração autônoma de petição.

## Compatibilidade com Projuris

Não existe uma integração com Projuris nem importador validado nesta entrega. Antes de migrar, inventarie exports disponíveis, teste mapeamento em ambiente de teste com dados minimizados, revise anexos/IDs e confira titularidade de cada tenant. Não importe dados de produção sem um plano de retorno/backup e aprovação do responsável pelo escritório.
