# Correção: Sistema de Email Único

## Problema Identificado

Dois problemas críticos foram encontrados no sistema:

1. **Múltiplas contas com mesmo email**: O sistema permitia até 3 contas com o mesmo email
2. **Reset de senha afeta múltiplas contas**: Quando um usuário resetava a senha, TODAS as contas com aquele email tinham a senha alterada

## Solução Implementada

### 1. Script de Migração (`fix_duplicate_emails.php`)

Criado script para:
- Identificar todos os emails duplicados
- Manter apenas a conta mais antiga (menor ID) para cada email
- Migrar todos os dados (vídeos, comentários, likes, seguidores) para a conta principal
- Deletar contas duplicadas
- Garantir constraint UNIQUE no campo email

**Como executar:**
```bash
# Acesse via navegador:
http://localhost/my/fix_duplicate_emails.php

# Ou via terminal:
cd c:\xampp\htdocs\my
php fix_duplicate_emails.php
```

### 2. Correções no Reset de Senha

#### `api/reset_password.php`
- **Antes**: `UPDATE users SET password = ? WHERE email = ?`
- **Depois**: `UPDATE users SET password = ? WHERE id = ?`
- Agora reseta apenas a senha do usuário específico, não de todos com o mesmo email

#### `api/send_reset_code.php`
- Rate limiting agora usa `user_id` em vez de `email`
- Invalidação de códigos usa `user_id` em vez de `email`

#### `api/verify_reset_code.php`
- Removida variável de sessão `reset_email` (não mais necessária)

### 3. Remoção da Lógica de Múltiplos Emails (`login.php`)

- **Removido**: Verificação que permitia até 3 contas por email
- **Adicionado**: Tratamento de erro específico para email duplicado
- Agora o banco de dados (constraint UNIQUE) impede emails duplicados

## Estrutura do Banco de Dados

A tabela `users` deve ter:
```sql
UNIQUE KEY `email` (`email`)
```

O script de migração verifica e adiciona isso automaticamente.

## Ordem de Execução

1. **Execute o script de migração primeiro:**
   ```
   http://localhost/my/fix_duplicate_emails.php
   ```
   
2. **Verifique o resultado:**
   - O script mostrará quantos emails duplicados foram encontrados
   - Quantos dados foram migrados
   - Se o constraint UNIQUE foi adicionado

3. **Teste o sistema:**
   - Tente criar uma nova conta com email existente (deve dar erro)
   - Teste o reset de senha (deve afetar apenas uma conta)

## Arquivos Modificados

- ✅ `fix_duplicate_emails.php` (NOVO - script de migração)
- ✅ `api/reset_password.php` (usa user_id em vez de email)
- ✅ `api/send_reset_code.php` (rate limiting por user_id)
- ✅ `api/verify_reset_code.php` (removido reset_email da sessão)
- ✅ `login.php` (removida lógica de limite de 3 emails)

## Segurança

✅ **Antes**: Resetar senha afetava múltiplas contas  
✅ **Depois**: Reset de senha afeta APENAS a conta específica do usuário

✅ **Antes**: Possível criar múltiplas contas com mesmo email  
✅ **Depois**: Email único por conta (enforçado pelo banco de dados)

## Notas Importantes

- ⚠️ **Backup**: Faça backup do banco de dados antes de executar o script de migração
- ⚠️ **Dados**: O script migra vídeos, comentários, likes e seguidores para a conta mais antiga
- ⚠️ **Irreversível**: Após a migração, as contas duplicadas serão deletadas permanentemente
- ✅ **Transação**: O script usa transação SQL, então reverte tudo em caso de erro

## Testado em

- PHP 7.4+
- MySQL 5.7+
- XAMPP (Windows)

Data: 18/04/2026
