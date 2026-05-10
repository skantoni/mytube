/**
 * MyTube Image Compressor
 * ========================
 * Comprime imagens no cliente antes do upload, exatamente como o WhatsApp faz:
 *  - Lê a orientação EXIF para corrigir rotação (fotos de iPhone ficam de lado sem isso)
 *  - Redimensiona para no máximo MAX_DIMENSION px (lado maior)
 *  - Converte para JPEG com qualidade JPEG_QUALITY
 *  - Remove todos os metadados EXIF (GPS, câmera, etc.) — Canvas não preserva EXIF
 *
 * Resultado típico: foto de 10 MB de iPhone → ~80–150 KB
 *
 * Uso:
 *   const compressedFile = await ImageCompressor.compress(originalFile);
 *   // compressedFile é um File object pronto para FormData
 *
 *   // Para processar vários inputs de uma vez:
 *   await ImageCompressor.attachToForm(formElement);
 */

const ImageCompressor = (() => {
    // ── Configuração (equivalente ao WhatsApp) ─────────────────────────────
    const MAX_DIMENSION  = 1280;   // px — lado maior (WhatsApp usa 1600, nós usamos 1280)
    const JPEG_QUALITY   = 0.78;   // 0–1  (WhatsApp usa ~0.7–0.8)
    const MAX_SIZE_BYTES = 5 * 1024 * 1024; // só comprime se > 5 MB (evita re-comprimir WebPs pequenos)
    const SKIP_TYPES     = ['image/gif'];   // GIF: não comprimir (perderia animação)

    // ── Leitura de orientação EXIF ─────────────────────────────────────────
    // Fotos de iPhone chegam com orientação 6 (90°) ou 8 (270°) no EXIF.
    // O Canvas desenha sem considerar isso, então corrigimos manualmente.
    function readExifOrientation(file) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = (e) => {
                const view = new DataView(e.target.result);
                // Verifica assinatura JPEG (0xFFD8)
                if (view.getUint16(0) !== 0xFFD8) { resolve(1); return; }

                let offset = 2;
                while (offset < view.byteLength) {
                    const marker = view.getUint16(offset);
                    offset += 2;
                    if (marker === 0xFFE1) {          // APP1 — pode conter EXIF
                        const exifHeader = view.getUint32(offset + 2, false);
                        if (exifHeader !== 0x45786966) { resolve(1); return; } // não é EXIF
                        const littleEndian = view.getUint16(offset + 10) === 0x4949;
                        const ifdOffset    = view.getUint32(offset + 14, littleEndian);
                        const entries      = view.getUint16(offset + 10 + ifdOffset, littleEndian);
                        for (let i = 0; i < entries; i++) {
                            const tag = view.getUint16(offset + 10 + ifdOffset + 2 + i * 12, littleEndian);
                            if (tag === 0x0112) { // Orientation
                                resolve(view.getUint16(offset + 10 + ifdOffset + 2 + i * 12 + 8, littleEndian));
                                return;
                            }
                        }
                        resolve(1); return;
                    } else if ((marker & 0xFF00) !== 0xFF00) {
                        break;
                    } else {
                        offset += view.getUint16(offset);
                    }
                }
                resolve(1);
            };
            reader.onerror = () => resolve(1);
            // Lê só os primeiros 64 KB — suficiente para o segmento EXIF
            reader.readAsArrayBuffer(file.slice(0, 65536));
        });
    }

    // ── Compressão principal ───────────────────────────────────────────────
    async function compress(file, options = {}) {
        const maxDim     = options.maxDimension || MAX_DIMENSION;
        const quality    = options.quality      || JPEG_QUALITY;
        const skipTypes  = options.skipTypes    || SKIP_TYPES;

        // Não processar tipos que devem ser ignorados
        if (skipTypes.includes(file.type)) return file;

        // Não processar arquivos que não são imagem
        if (!file.type.startsWith('image/')) return file;

        // Arquivos já pequenos: não recomprimir (evita degradação desnecessária)
        if (file.size <= MAX_SIZE_BYTES && file.type === 'image/webp') return file;

        return new Promise(async (resolve) => {
            // 1. Ler orientação EXIF antes de qualquer coisa
            const orientation = await readExifOrientation(file);

            // 2. Carregar imagem no elemento <img>
            const img = new Image();
            const objectUrl = URL.createObjectURL(file);

            img.onload = () => {
                URL.revokeObjectURL(objectUrl);

                let { naturalWidth: w, naturalHeight: h } = img;

                // 3. Calcular novo tamanho mantendo proporção
                if (w > maxDim || h > maxDim) {
                    if (w >= h) { h = Math.round(h * maxDim / w); w = maxDim; }
                    else        { w = Math.round(w * maxDim / h); h = maxDim; }
                }

                // 4. Criar canvas com rotação corrigida
                const canvas = document.createElement('canvas');
                const ctx    = canvas.getContext('2d');

                // Orientações 5–8 têm largura e altura trocadas
                const rotated = orientation >= 5 && orientation <= 8;
                canvas.width  = rotated ? h : w;
                canvas.height = rotated ? w : h;

                // Aplicar transformação de orientação EXIF
                switch (orientation) {
                    case 2: ctx.transform(-1, 0, 0, 1, w, 0); break;
                    case 3: ctx.transform(-1, 0, 0, -1, w, h); break;
                    case 4: ctx.transform(1, 0, 0, -1, 0, h); break;
                    case 5: ctx.transform(0, 1, 1, 0, 0, 0); break;
                    case 6: ctx.transform(0, 1, -1, 0, h, 0); break;
                    case 7: ctx.transform(0, -1, -1, 0, h, w); break;
                    case 8: ctx.transform(0, -1, 1, 0, 0, w); break;
                    default: break; // orientação normal (1)
                }

                ctx.drawImage(img, 0, 0, w, h);

                // 5. Exportar como JPEG (Canvas descarta EXIF automaticamente)
                canvas.toBlob(
                    (blob) => {
                        if (!blob) { resolve(file); return; } // fallback se Canvas falhar

                        // Manter o nome original mas com extensão .jpg
                        const baseName = file.name.replace(/\.[^.]+$/, '');
                        const newFile  = new File([blob], baseName + '.jpg', {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        });

                        // Só usar versão comprimida se for realmente menor
                        resolve(newFile.size < file.size ? newFile : file);
                    },
                    'image/jpeg',
                    quality
                );
            };

            img.onerror = () => {
                URL.revokeObjectURL(objectUrl);
                resolve(file); // fallback: enviar arquivo original
            };

            img.src = objectUrl;
        });
    }

    // ── Utilitário: substituir arquivo num <input type="file"> ─────────────
    // Cria um DataTransfer para simular um novo ficheiro no input
    function replaceInputFile(input, newFile) {
        try {
            const dt = new DataTransfer();
            dt.items.add(newFile);
            input.files = dt.files;
        } catch (e) {
            // DataTransfer não suportado (Safari < 14.1) — guardar o ficheiro no dataset
            input._compressedFile = newFile;
        }
    }

    // ── Comprime todos os inputs de imagem de um FormData ─────────────────
    // Chamado antes de um fetch/XHR para injetar as imagens comprimidas
    async function compressFormData(formData, inputsMap) {
        const newFormData = new FormData();
        const promises    = [];

        for (const [key, value] of formData.entries()) {
            if (value instanceof File && value.type.startsWith('image/') && inputsMap[key]) {
                promises.push(
                    compress(value).then((compressed) => ({ key, compressed }))
                );
            } else {
                newFormData.append(key, value);
            }
        }

        const results = await Promise.all(promises);
        for (const { key, compressed } of results) {
            newFormData.append(key, compressed);
        }

        return newFormData;
    }

    // ── Intercepta o submit de um <form> para comprimir antes de enviar ───
    // Uso: ImageCompressor.attachToForm(document.getElementById('editForm'), ['profile_picture','name_icon'])
    function attachToForm(form, imageInputNames) {
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            // Verificar se há algum input de imagem com ficheiro selecionado
            const inputs = imageInputNames
                .map((name) => form.querySelector(`[name="${name}"]`))
                .filter((el) => el && el.files && el.files.length > 0);

            if (inputs.length === 0) return; // nenhuma imagem — continuar normalmente

            e.preventDefault(); // pausar envio

            // Mostrar indicador de compressão
            const submitBtn = form.querySelector('[type="submit"]');
            let   originalContent = '';
            if (submitBtn) {
                originalContent = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-compress-arrows-alt fa-spin"></i> A comprimir...';
            }

            try {
                for (const input of inputs) {
                    const original   = input.files[0];
                    const compressed = await compress(original);
                    replaceInputFile(input, compressed);

                    // Log de debug (apenas em desenvolvimento)
                    if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                        const ratio = ((1 - compressed.size / original.size) * 100).toFixed(1);
                        console.log(
                            `[ImageCompressor] ${original.name}: ` +
                            `${(original.size / 1024 / 1024).toFixed(2)} MB → ` +
                            `${(compressed.size / 1024).toFixed(0)} KB ` +
                            `(-${ratio}%)`
                        );
                    }
                }
            } finally {
                if (submitBtn) {
                    submitBtn.innerHTML = originalContent;
                    submitBtn.disabled  = false;
                }
                form.submit(); // retomar envio com ficheiros comprimidos
            }
        });
    }

    // ── API pública ────────────────────────────────────────────────────────
    return { compress, replaceInputFile, attachToForm, compressFormData };
})();

// Disponibilizar globalmente
window.ImageCompressor = ImageCompressor;
