# Checklist de Segurança - MyTube

## ✅ Correções Implementadas (18/04/2026)

### 1. Sistema de Email Único
- ✅ **1 conta por email** (padrão mais seguro)
- ✅ Script de migração criado ([fix_duplicate_emails.php](fix_duplicate_emails.php))
- ✅ Constraint UNIQUE será adicionado automaticamente pelo script
- ✅ Lógica de "3 contas por email" removida de [login.php](login.php)

### 2. Reset de Senha Seguro
- ✅ **Usa `user_id` em vez de `email`** para atualização
- ✅ [reset_password.php](api/reset_password.php) - atualiza apenas 1 conta específica
- ✅ [send_reset_code.php](api/send_reset_code.php) - rate limiting por usuário
- ✅ [verify_reset_code.php](api/verify_reset_code.php) - validação por user_id
- ✅ Códigos de reset expiram em 15 minutos
- ✅ Rate limiting: máximo 3 códigos por hora

### 3. Login Seguro
- ✅ **Proteção contra timing attacks** - tempo constante de processamento
- ✅ `password_verify()` sempre executado (mesmo se usuário não existir)
- ✅ Mensagens de erro genéricas (não revela se usuário existe)
- ✅ `session_regenerate_id()` após login bem-sucedido
- ✅ Prepared statements (proteção SQL injection)
- ✅ Log de tentativas falhadas para análise

### 4. Cadastro Seguro
- ✅ Validação de email com `filter_var()`
- ✅ Validação de formato de email (requer @ e domínio)
- ✅ Verificação de código de 6 dígitos
- ✅ Username: 3-12 caracteres, apenas alphanumeric + - _
- ✅ Senha: mínimo 6 caracteres
- ✅ `password_hash()` com PASSWORD_DEFAULT
- ✅ Proteção contra email duplicado (constraint UNIQUE)
- ✅ Proteção contra username duplicado

### 5. Gestão de Senhas
- ✅ [change_password.php](api/change_password.php) - requer senha atual
- ✅ Verifica se nova senha é diferente da atual
- ✅ Hash com `PASSWORD_DEFAULT` (bcrypt)
- ✅ Atualiza apenas conta do usuário logado

## 🔒 Camadas de Segurança

### SQL Injection
- ✅ **100% Prepared Statements** em todos os arquivos
- ✅ Nenhuma concatenação de SQL com dados do usuário
- ✅ Parâmetros sempre bindados com `?`

### Password Security
- ✅ `password_hash()` com PASSWORD_DEFAULT
- ✅ `password_verify()` para comparação
- ✅ Nunca armazena senhas em plain text
- ✅ Hash de senha sempre no servidor

### Session Security
- ✅ `session_regenerate_id(true)` após login
- ✅ Limpeza de sessão `$_SESSION = []` antes de popular
- ✅ Validação de sessão em todas as APIs protegidas

### Email Verification
- ✅ Código de 6 dígitos com expiração (15min)
- ✅ Rate limiting: 3 códigos por hora
- ✅ Códigos marcados como `used` após uso
- ✅ Validação de formato de código `^\d{6}$`

### Timing Attacks
- ✅ **Novo**: Login com tempo constante
- ✅ Hash dummy quando usuário não existe
- ✅ Mensagens de erro genéricas

## 📋 Próximos Passos Recomendados

### Prioridade Alta 🔴
1. **Execute o script de migração**:
   ```
   http://localhost/my/fix_duplicate_emails.php
   ```
2. **Backup do banco de dados** antes da migração
3. **Testar reset de senha** após migração

### Prioridade Média 🟡
4. Adicionar CSRF tokens em formulários
5. Implementar rate limiting no login (ex: 5 tentativas/minuto por IP)
6. Adicionar CAPTCHA após X tentativas falhadas
7. Implementar 2FA (autenticação em dois fatores)

### Prioridade Baixa 🟢
8. Política de senha mais forte (maiúsculas, números, símbolos)
9. Expiração de senha após X dias
10. Histórico de senhas (não permitir reutilização)
11. Notificação por email em login de novo dispositivo

## 🚨 Verificações de Segurança

### Teste Manual
- [ ] Tentar criar conta com email duplicado (deve falhar)
- [ ] Resetar senha e verificar se afeta apenas 1 conta
- [ ] Testar código de reset expirado (após 15min)
- [ ] Testar rate limiting (4+ códigos em 1 hora)
- [ ] Login com credenciais erradas (timing similar ao sucesso)

### Monitoramento
- [ ] Verificar logs de tentativas falhadas em `error_log`
- [ ] Monitorar tentativas de SQL injection
- [ ] Alertas de múltiplas tentativas falhadas do mesmo IP

## 📊 Arquivos Modificados

### Novos Arquivos
- ✅ `fix_duplicate_emails.php` - Script de migração
- ✅ `FIX_EMAIL_DUPLICADO.md` - Documentação da correção
- ✅ `SECURITY_CHECKLIST.md` - Este arquivo

### Arquivos Modificados
- ✅ `login.php` - Removida lógica de 3 emails + timing attack protection
- ✅ `api/reset_password.php` - Usa user_id em vez de email
- ✅ `api/send_reset_code.php` - Rate limiting por user_id
- ✅ `api/verify_reset_code.php` - Removido reset_email da sessão

### Arquivos Verificados (OK)
- ✅ `api/change_password.php` - Já usa user_id corretamente
- ✅ `api/send_email_verification.php` - Seguro (email para verificação)

## 🛡️ Compliance & Best Practices

### OWASP Top 10 (2021)
- ✅ A01: Broken Access Control - Sessions validadas
- ✅ A02: Cryptographic Failures - Senhas hasheadas
- ✅ A03: Injection - Prepared statements
- ✅ A04: Insecure Design - Email único, rate limiting
- ✅ A05: Security Misconfiguration - Erros genéricos
- 🟡 A07: Authentication Failures - Pode melhorar com 2FA

### GDPR Considerations
- ✅ Email único facilita exercício de direitos (delete, export)
- ✅ Dados de apenas 1 conta por email
- ✅ Logs de segurança (tentativas falhadas)

## 📞 Suporte

Em caso de problemas de segurança:
1. Verificar logs PHP: `c:\xampp\php\logs\php_error_log`
2. Verificar logs Apache: `c:\xampp\apache\logs\error.log`
3. Testar em ambiente local antes de produção

---
**Última atualização**: 18 de Abril de 2026
**Responsável**: Sistema de Segurança MyTube
**Status**: ✅ Pronto para migração
