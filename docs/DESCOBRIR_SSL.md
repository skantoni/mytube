# 🔍 DESCOBRIR CONFIGURAÇÃO SSL ATUAL

Execute estes comandos no VPS para descobrir como o SSL está configurado:

## 1. Ver configuração completa atual:
```bash
sudo cat /etc/nginx/sites-available/mytube.social
```

## 2. Extrair apenas linhas SSL:
```bash
sudo cat /etc/nginx/sites-available/mytube.social | grep ssl
```

## 3. Verificar se usa Cloudflare:
```bash
# Se aparecer algo como "Cloudflare Origin CA", está usando Cloudflare SSL
sudo cat /etc/nginx/sites-available/mytube.social | grep -i cloudflare
```

## 4. Procurar certificados no sistema:
```bash
# Procurar arquivos .crt e .key
sudo find /etc -name "*.crt" -o -name "*.pem" 2>/dev/null | grep -v letsencrypt
```

## 5. Verificar certificado ativo:
```bash
# Ver qual certificado está em uso
echo | openssl s_client -servername mytube.social -connect mytube.social:443 2>/dev/null | openssl x509 -noout -subject -issuer
```

---

## 📋 Depois de executar os comandos acima:

**Envia-me a saída do comando 1 (configuração completa)** ou pelo menos:
- As linhas que contêm `ssl_certificate`
- As linhas que contêm `ssl_certificate_key`
- Se tiver: `ssl_protocols`, `ssl_ciphers`, `ssl_dhparam`, etc.

Com isso vou ajustar o `nginx-config-complete.conf` com os caminhos corretos!

---

## 🔎 Possíveis cenários:

### Cenário A: Cloudflare Origin Certificate
```nginx
ssl_certificate /etc/ssl/cloudflare/cert.pem;
ssl_certificate_key /etc/ssl/cloudflare/key.pem;
```

### Cenário B: Certificado auto-assinado
```nginx
ssl_certificate /etc/ssl/certs/nginx-selfsigned.crt;
ssl_certificate_key /etc/ssl/private/nginx-selfsigned.key;
```

### Cenário C: Outro provedor de SSL
```nginx
ssl_certificate /caminho/customizado/certificate.crt;
ssl_certificate_key /caminho/customizado/private.key;
```

Qual é o teu caso?
