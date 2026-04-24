#!/bin/bash
# Instalar dependências NudeNet para moderação de conteúdo MyTube
# Executar na raiz do projecto: bash moderation/install.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VENV_DIR="$SCRIPT_DIR/venv"

echo "=== Instalação NudeNet para MyTube ==="

# Verificar Python3
if ! command -v python3 &>/dev/null; then
    echo "ERRO: Python3 não encontrado. Instale com: apt install python3 python3-full"
    exit 1
fi

PYTHON_VERSION=$(python3 --version 2>&1)
echo "Python encontrado: $PYTHON_VERSION"

# Instalar python3-venv se necessário
if ! python3 -m venv --help &>/dev/null; then
    echo "Instalando python3-full (necessário para venv)..."
    apt-get install -y python3-full 2>/dev/null || apt-get install -y python3-venv 2>/dev/null
fi

# Criar ambiente virtual isolado em moderation/venv/
# Validar se o venv existente está íntegro (tem bin/pip)
if [ -d "$VENV_DIR" ] && [ ! -f "$VENV_DIR/bin/pip" ]; then
    echo "Venv incompleto detectado — a recriar..."
    rm -rf "$VENV_DIR"
fi

if [ ! -d "$VENV_DIR" ]; then
    echo "Criando ambiente virtual em $VENV_DIR ..."
    python3 -m venv "$VENV_DIR"
else
    echo "Ambiente virtual já existe em $VENV_DIR"
fi

# Instalar NudeNet dentro do venv (sem tocar no Python do sistema)
echo "Instalando NudeNet no ambiente virtual..."
"$VENV_DIR/bin/pip" install --upgrade pip -q
"$VENV_DIR/bin/pip" install -r "$SCRIPT_DIR/requirements.txt"

# Verificar instalação e pré-carregar modelo (faz download automático na 1ª execução)
echo "Pré-carregando modelo NudeNet (pode demorar na primeira vez, ~50 MB)..."
"$VENV_DIR/bin/python3" -c "
from nudenet import NudeDetector
d = NudeDetector()
print('NudeNet instalado com sucesso!')
print('Modelo pronto para uso.')
"

echo ""
echo "=== Instalação concluída ==="
echo "Binário Python: $VENV_DIR/bin/python3"
echo "Para testar:   python3 moderation/analyze_video.py /caminho/para/video.mp4"
