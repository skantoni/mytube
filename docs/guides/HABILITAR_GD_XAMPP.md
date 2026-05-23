# Como Habilitar GD no XAMPP (Windows)

## Erro: "Call to undefined function imagecreatefromjpeg()"

### Causa:
A extensão GD (processamento de imagens) não está habilitada no PHP.

### Solução (XAMPP Windows):

1. **Abrir arquivo php.ini:**
   - Painel de controle do XAMPP → Apache → Config → php.ini
   - OU navegar para: `C:\xampp\php\php.ini`

2. **Procurar a linha (Ctrl+F):**
   ```
   ;extension=gd
   ```

3. **Remover o ponto e vírgula (descomentar):**
   ```
   extension=gd
   ```

4. **Salvar o arquivo (Ctrl+S)**

5. **Reiniciar Apache:**
   - Painel XAMPP → Apache → Stop → Start
   - OU: `C:\xampp\apache_stop.bat` e depois `C:\xampp\apache_start.bat`

6. **Verificar se funcionou:**
   - Abrir: http://localhost/my/test_exif_sanitization.php
   - Deve mostrar "✅ Biblioteca GD completa"

### Alternativa: Verificar via phpinfo()

1. Criar arquivo `test_gd.php`:
   ```php
   <?php phpinfo(); ?>
   ```

2. Abrir: http://localhost/test_gd.php

3. Procurar por "GD" na página

4. Se aparecer "GD Support: enabled" → está funcionando

5. Deletar test_gd.php após verificar

### Se não funcionar:

1. Verificar se arquivo `php_gd2.dll` existe em `C:\xampp\php\ext\`

2. Se não existir, reinstalar XAMPP ou baixar extensão separadamente

3. Verificar se arquivo correto está sendo usado:
   ```
   php --ini
   ```

### Nota:
- O código já tem failsafe: se GD não estiver disponível, upload funciona normalmente
- Imagens apenas não terão EXIF removido (não impede funcionamento)
- Recomendado habilitar para máxima segurança
