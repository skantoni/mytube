# 🎨 Imagens Necessárias para SEO e Google Rich Snippets

Para aparecer no Google com fotos bonitas como você viu na imagem, preciso das seguintes imagens:

## 📸 Imagens Obrigatórias

### 1. **Open Graph Image** (para compartilhamento e Google)
- **Nome:** `og-image.jpg`
- **Local:** `assets/images/og-image.jpg`
- **Tamanho:** 1200x630 pixels
- **Descrição:** Imagem principal que aparece quando compartilha no Facebook, WhatsApp, etc.
- **Conteúdo sugerido:** Logo do MyTube + texto "Rede Social de Vídeos" + screenshots do app

### 2. **Screenshots para Google** (aparecem nos resultados de busca)

Criar pasta: `assets/images/screenshots/`

#### Desktop:
- **Nome:** `desktop-1.jpg`, `desktop-2.jpg`
- **Tamanho:** 1280x720 pixels ou 1920x1080 pixels
- **Quantidade:** 2-3 imagens
- **Exemplos:**
  - Feed de vídeos no desktop
  - Página de perfil
  - Chat/mensagens

#### Mobile:
- **Nome:** `mobile-1.jpg`, `mobile-2.jpg`  
- **Tamanho:** 750x1334 pixels (iPhone) ou 1080x1920 pixels (Android)
- **Quantidade:** 2-3 imagens
- **Exemplos:**
  - Feed mobile
  - Perfil mobile
  - Upload de vídeo

#### Tablet (Opcional):
- **Nome:** `tablet-1.jpg`
- **Tamanho:** 1024x768 pixels
- **Quantidade:** 1-2 imagens

## 🛠️ Como Criar as Imagens

### Método 1: Screenshots Reais
1. Abra o site no navegador
2. Pressione `F12` para abrir DevTools
3. Use o modo responsivo para ajustar o tamanho exato
4. Tire print (Windows: `Win + Shift + S`)

### Método 2: Ferramenta Online
Use: https://www.screely.com/ ou https://www.mockuphone.com/
- Faz screenshots bonitos com moldura de dispositivo
- Exporta no tamanho certo

### Método 3: Canva (Recomendado para og-image)
1. Acesse canva.com
2. Crie design 1200x630px
3. Adicione logo + texto + screenshots
4. Exporte como JPG

## 📝 Checklist

Depois de criar as imagens:

- [ ] `assets/images/og-image.jpg` (1200x630px)
- [ ] `assets/images/screenshots/desktop-1.jpg`
- [ ] `assets/images/screenshots/desktop-2.jpg`
- [ ] `assets/images/screenshots/mobile-1.jpg`
- [ ] `assets/images/screenshots/mobile-2.jpg`

Quando tiver as imagens prontas, me avise que eu atualizo o manifest.json e sitemap.xml!

## 🎯 Dica Importante

O Google demora alguns dias para rastrear e mostrar as imagens novas. Seja paciente!

Após adicionar as imagens:
1. Teste no Google Rich Results Test: https://search.google.com/test/rich-results
2. Teste Open Graph: https://developers.facebook.com/tools/debug/
