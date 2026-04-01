# Sistema de Eventos - MyTube

## �️ ARQUITETURA v2.0 (Event Delegation)

### Princípios:
1. **UM ÚNICO event listener** no container `.tiktok-container`
2. **Event delegation** - identifica o alvo pelo `closest()`
3. **Sem stopPropagation** - deixar eventos fluir naturalmente
4. **Sem listeners duplicados** - flag `eventsBound` previne
5. **Compatível mobile/desktop** - touch events separados

---

## 📊 HIERARQUIA Z-INDEX

```
Camada 10: audio-prompt-overlay (só quando visível)
Camada 4:  video-controls (botão de áudio)
Camada 3:  action-buttons (like, comment, follow, share)
Camada 2:  video-overlay (info, pointer-events: none)
Camada 0:  video (elemento base)
```

---

## 🎯 FLUXO DE EVENTOS

```
Click no container
    │
    ├── closest('.like-btn') → likeVideo()
    ├── closest('.comment-btn') → deixar comments.js
    ├── closest('.follow-btn') → followUser()
    ├── closest('.share-btn') → openShareMenu()
    ├── closest('.delete-btn') → deixar video-delete.js
    ├── closest('.audio-toggle') → toggleAudio()
    ├── closest('.audio-prompt-overlay') → enableAudio + togglePlayPause
    ├── closest('.avatar-link/.username-link') → navegação normal
    └── closest('.video-player/video') → togglePlayPause()
```

---

## ✅ Arquivos ATIVOS:

| Arquivo | Função | Eventos |
|---------|--------|---------|
| tiktok.js | Player principal | Container delegation + Keyboard |
| comments-new.js | Comentários | Próprios listeners |
| video-delete.js | Deletar vídeos | Próprios listeners |
| feed-ajax.js | Infinite scroll | Scroll + Mutation |
| like-sync.js | Sync likes | Polling (sem click) |
| comment-sync.js | Sync comentários | Polling (sem click) |

---

## ❌ Arquivos DESATIVADOS:

- `interactions.js.disabled` - Duplicava likes/follows
- `state-manager.js.disabled` - Event listeners conflitantes

---

## 🔧 Como Funciona o Event Delegation:

```javascript
// Um único listener no container
container.addEventListener('click', (e) => {
    const target = e.target;
    
    // Identifica o que foi clicado
    if (target.closest('.like-btn')) {
        // Processar like
        return;
    }
    
    if (target.closest('.video-player')) {
        // Play/pause
        return;
    }
});
```

### Vantagens:
- ✅ Performance: 1 listener vs N listeners
- ✅ Novos vídeos funcionam automaticamente
- ✅ Sem memory leaks de listeners órfãos
- ✅ Fácil debug: um ponto de entrada
- ✅ Sem conflitos de propagação

---

## 📱 Mobile vs Desktop

```javascript
// Click funciona em ambos
container.addEventListener('click', handler);

// Touch adicional para melhor UX mobile
container.addEventListener('touchend', (e) => {
    if (this.isScrolling) return; // Ignorar durante scroll
    localStorage.setItem('mytube_user_interacted', 'true');
}, { passive: true });
```

---

## 🚫 O QUE NÃO FAZER:

```javascript
// ❌ ERRADO: Múltiplos listeners por elemento
videos.forEach(v => v.addEventListener('click', ...));

// ❌ ERRADO: stopPropagation excessivo
btn.addEventListener('click', (e) => {
    e.stopPropagation(); // Pode quebrar outros sistemas
});

// ❌ ERRADO: Re-adicionar listeners sem limpar
function reload() {
    setupListeners(); // Duplica a cada chamada!
}
```

---

## ✅ O QUE FAZER:

```javascript
// ✅ CORRETO: Event delegation
container.addEventListener('click', (e) => {
    if (e.target.closest('.btn')) { /* ... */ }
});

// ✅ CORRETO: Flag para evitar duplicação
if (this.eventsBound) return;
this.eventsBound = true;

// ✅ CORRETO: Usar on* para substituir listeners
video.onclick = null; // Remove anterior
video.onclick = handler; // Adiciona novo
```

---

## 📝 Histórico:

**Janeiro 2026 - v2.0:**
- Reescrito sistema de eventos com Event Delegation
- Removido stopPropagation desnecessário
- Um único listener no container
- Compatibilidade mobile melhorada
- Arquivos conflitantes desativados
