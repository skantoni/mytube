#!/bin/bash
# Instalar dependências NudeNet para moderação de conteúdo MyTube
# Executar como root ou com sudo no VPS: bash moderation/install.sh

set -e

echo "=== Instalação NudeNet para MyTube ==="

# Verificar Python3
if ! command -v python3 &>/dev/null; then
    echo "ERRO: Python3 não encontrado. Instale com: apt install python3 python3-pip"
    exit 1
fi

PYTHON_VERSION=$(python3 --version 2>&1)
echo "Python encontrado: $PYTHON_VERSION"

# Instalar pip se necessário
if ! command -v pip3 &>/dev/null; then
    echo "Instalando pip3..."
    apt-get install -y python3-pip 2>/dev/null || true
fi

# Instalar NudeNet
echo "Instalando NudeNet..."
pip3 install -r "$(dirname "$0")/requirements.txt"

# Verificar instalação e pré-carregar modelo (faz download automático na 1ª execução)
echo "Verificando e pré-carregando modelo NudeNet (pode demorar na primeira vez)..."
python3 -c "
from nudenet import NudeDetector
import tempfile, os
# Inicializar detector (faz download do modelo se necessário)
d = NudeDetector()
print('NudeNet instalado com sucesso!')
print('Modelo pronto para uso.')
"

echo ""
echo "=== Instalação concluída ==="
echo "Para testar: python3 moderation/analyze_video.py /caminho/para/video.mp4"
