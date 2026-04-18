# 🔐 AÇÕES DE SEGURANÇA OBRIGATÓRIAS

## ⚠️ URGENTE - Execute IMEDIATAMENTE

As credenciais abaixo foram **expostas no código-fonte** e precisam ser **revogadas e regeneradas**:

---

## 1️⃣ Cloudflare R2 - Revogar Chaves de API

### Passo a passo:
1. Acesse: https://dash.cloudflare.com
2. Vá em **R2** > **API Tokens** (ou **Manage R2 API Tokens**)
3. Localize o token com Access Key ID: `16043c8c7a2c0fff97c1dab6b25886e5`
4. Clique em **Delete/Revoke** para invalidar essa chave
5. Crie uma **nova chave de API**:
   - Clique em **Create API Token**
   - Permissões: Admin Read & Write (ou Object Read & Write)
   - Copie a nova **Access Key ID** e **Secret Access Key**
6. Edite o arquivo `.env` e substitua:
   ```
   R2_ACCESS_KEY_ID=NOVA_CHAVE_AQUI
   R2_SECRET_ACCESS_KEY=NOVO_SECRET_AQUI
   ```

---

## 2️⃣ Gmail - Revogar Senha de App

### Passo a passo:
1. Acesse: https://myaccount.google.com/apppasswords
2. Localize a senha de app atual (provavelmente chamada "MyTube" ou similar)
3. Clique em **Remover** para revogá-la
4. Crie uma **nova senha de app**:
   - Nome: MyTube SMTP
   - Copie a senha de 16 caracteres gerada
5. Edite o arquivo `.env` e substitua:
   ```
   MAIL_PASSWORD=nova_senha_de_16_caracteres_aqui
   ```

---

## 3️⃣ Gerar Novo CRON_SECRET

### No Windows PowerShell:
```powershell
-join (1..32 | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })
```

### No Linux/Mac:
```bash
openssl rand -hex 32
```

Copie o resultado e substitua no arquivo `.env`:
```
CRON_SECRET=o_secret_gerado_aqui
```

---

## 4️⃣ Gerar JWT_SECRET (se usar autenticação JWT)

Use o mesmo comando acima para gerar outro secret diferente:
```
JWT_SECRET=outro_secret_aleatorio_aqui
```

---

## 5️⃣ Verificar .gitignore

✅ Confirme que o arquivo `.env` **NÃO será versionado** no Git:

```bash
# Teste se .env está sendo ignorado
git status
```

Se `.env` aparecer como "modified" ou "untracked", algo está errado!

---

## 6️⃣ Remover credenciais do histórico do Git (SE NECESSÁRIO)

Se você já fez commits com credenciais hardcoded:

### Opção 1 - Remover do histórico (avançado):
```bash
git filter-repo --path includes/config.php --invert-paths
git filter-repo --path includes/r2_config.php --invert-paths
git filter-repo --path includes/mail_config.php --invert-paths
```

### Opção 2 - Avisar colaboradores:
Se o repositório é privado e você confia nos colaboradores, pode apenas fazer commit da correção e pedir que todos atualizem.

---

## ✅ Checklist Final

- [ ] Revogado chave R2 antiga
- [ ] Criado nova chave R2 e atualizado `.env`
- [ ] Revogado senha de app do Gmail
- [ ] Criado nova senha de app e atualizado `.env`
- [ ] Gerado novo CRON_SECRET e atualizado `.env`
- [ ] Verificado que `.env` está em `.gitignore`
- [ ] Testado se o site ainda funciona com as novas credenciais
- [ ] (Opcional) Removido credenciais do histórico do Git

---

## 🧪 Testar

Depois de fazer as alterações:

1. Acesse o site: http://localhost/mytube
2. Teste login/logout
3. Teste upload de vídeo (para verificar R2)
4. Teste envio de email (reset de senha)

Se tudo funcionar, as credenciais foram migradas com sucesso! 🎉
