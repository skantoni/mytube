#!/usr/bin/env python3
"""
NudeNet video content moderation script for MyTube.

Uso: python3 analyze_video.py /caminho/para/video.mp4

Saída: JSON para stdout
  {"status": "clean"|"nsfw"|"error"|"unavailable", "score": 0.0-1.0, ...}

Códigos de saída:
  0 = análise concluída (limpo ou nsfw)
  1 = erro de execução
  2 = NudeNet não está instalado (fallback para revisão manual)
"""

import sys
import os
import json
import subprocess
import tempfile
import shutil


# Classes NSFW que devem causar rejeição do vídeo
NSFW_CLASSES = {
    'EXPOSED_BREAST_F',
    'EXPOSED_GENITALIA_F',
    'EXPOSED_GENITALIA_M',
    'EXPOSED_ANUS',
    'EXPOSED_BUTTOCKS',
}

# Score mínimo para considerar uma deteção como positiva
# (0.40 reduz falsos negativos — conteúdo duvidoso vai para revisão manual)
NSFW_THRESHOLD = 0.40

# Número de frames a extrair para análise
# (12 frames cobre melhor vídeos longos e conteúdo NSFW breve)
NUM_FRAMES = 12


def find_binary(names: list) -> str | None:
    for name in names:
        found = shutil.which(name)
        if found:
            return found
    return None


def extract_frames(video_path: str, output_dir: str) -> list:
    """Extrai frames igualmente espaçados do vídeo usando ffprobe + ffmpeg."""
    ffprobe = find_binary(['ffprobe', '/usr/bin/ffprobe', '/usr/local/bin/ffprobe'])
    ffmpeg  = find_binary(['ffmpeg',  '/usr/bin/ffmpeg',  '/usr/local/bin/ffmpeg'])

    if not ffprobe or not ffmpeg:
        raise RuntimeError("ffmpeg/ffprobe não encontrado no PATH")

    # Obter duração do vídeo
    probe_cmd = [
        ffprobe, '-v', 'error',
        '-show_entries', 'format=duration',
        '-of', 'json',
        video_path,
    ]
    probe_result = subprocess.run(probe_cmd, capture_output=True, text=True, timeout=30)
    if probe_result.returncode != 0:
        raise RuntimeError(f"ffprobe falhou: {probe_result.stderr[:200]}")

    probe_data = json.loads(probe_result.stdout)
    duration = float(probe_data.get('format', {}).get('duration') or 30.0)

    # Extrair frames em intervalos regulares (evitar primeiros/últimos 5%)
    frame_paths = []
    usable_duration = duration * 0.90
    start_offset   = duration * 0.05
    interval = usable_duration / (NUM_FRAMES + 1)

    for i in range(1, NUM_FRAMES + 1):
        timestamp  = start_offset + interval * i
        frame_path = os.path.join(output_dir, f'frame_{i:03d}.jpg')

        cmd = [
            ffmpeg, '-ss', f'{timestamp:.3f}',
            '-i', video_path,
            '-vframes', '1',
            '-q:v', '2',
            '-y', frame_path,
        ]
        try:
            subprocess.run(cmd, capture_output=True, timeout=20)
            if os.path.exists(frame_path) and os.path.getsize(frame_path) > 100:
                frame_paths.append(frame_path)
        except subprocess.TimeoutExpired:
            continue

    return frame_paths


def analyze_frames_with_nudenet(frame_paths: list) -> dict:
    """Corre NudeNet em cada frame e devolve o score mais alto encontrado."""
    try:
        from nudenet import NudeDetector  # type: ignore
    except ImportError:
        return {'available': False, 'error': 'nudenet não está instalado (pip install nudenet)'}

    detector    = NudeDetector()
    max_score   = 0.0
    detections  = []

    for frame_path in frame_paths:
        try:
            results = detector.detect(frame_path)
            for det in (results or []):
                label = det.get('class', '')
                score = float(det.get('score', 0.0))
                if label in NSFW_CLASSES and score >= NSFW_THRESHOLD:
                    max_score = max(max_score, score)
                    detections.append({
                        'class': label,
                        'score': round(score, 3),
                        'frame': os.path.basename(frame_path),
                    })
        except Exception as frame_err:
            import sys
            print(f"WARN: erro ao analisar {os.path.basename(frame_path)}: {frame_err}", file=sys.stderr)
            continue

    return {
        'available': True,
        'max_score': round(max_score, 3),
        'is_nsfw':   max_score >= NSFW_THRESHOLD,
        'detections': detections,
    }


def main() -> None:
    if len(sys.argv) < 2:
        print(json.dumps({'status': 'error', 'error': 'Caminho do vídeo não fornecido'}))
        sys.exit(1)

    video_path = sys.argv[1]
    if not os.path.isfile(video_path):
        print(json.dumps({'status': 'error', 'error': f'Ficheiro não encontrado: {video_path}'}))
        sys.exit(1)

    temp_dir = tempfile.mkdtemp(prefix='mytube_mod_')

    try:
        # 1. Extrair frames
        frame_paths = extract_frames(video_path, temp_dir)

        if not frame_paths:
            print(json.dumps({
                'status': 'error',
                'error':  'Não foi possível extrair frames do vídeo',
            }))
            sys.exit(1)

        # 2. Analisar com NudeNet
        result = analyze_frames_with_nudenet(frame_paths)

        if not result['available']:
            # NudeNet não instalado — sair com código 2 para fallback manual
            print(json.dumps({
                'status': 'unavailable',
                'error':  result.get('error', 'NudeNet indisponível'),
            }))
            sys.exit(2)

        # 3. Devolver resultado
        output = {
            'status':          'nsfw' if result['is_nsfw'] else 'clean',
            'score':           result['max_score'],
            'frames_analyzed': len(frame_paths),
            'detections':      result['detections'],
        }
        print(json.dumps(output))
        sys.exit(0)

    except Exception as exc:
        print(json.dumps({'status': 'error', 'error': str(exc)}))
        sys.exit(1)

    finally:
        shutil.rmtree(temp_dir, ignore_errors=True)


if __name__ == '__main__':
    main()
