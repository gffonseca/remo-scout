#!/bin/bash
# Instala/atualiza o coletor local no Mac (launchd: CBF diário 06:20 + tarefas sob demanda a cada 15 min). Não grava o token: ele fica em
# ~/.config/remo-scout/coletor.env (chmod 600), criado à parte.
set -euo pipefail
DIR="$HOME/remo-scout-coletor"
SRC="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$DIR" "$HOME/.config/remo-scout" "$HOME/Library/LaunchAgents"
cp "$SRC/remo_scout_coletor.py" "$DIR/"
for L in br.com.remo.scout.coletor br.com.remo.scout.coletor.tarefas; do
  sed "s#__HOME__#$HOME#g" "$SRC/$L.plist" > "$HOME/Library/LaunchAgents/$L.plist"
done
chmod 700 "$HOME/.config/remo-scout"
[ -f "$HOME/.config/remo-scout/coletor.env" ] && chmod 600 "$HOME/.config/remo-scout/coletor.env" || echo "AVISO: crie ~/.config/remo-scout/coletor.env com REMO_SCOUT_URL e REMO_SCOUT_TOKEN"
for L in br.com.remo.scout.coletor br.com.remo.scout.coletor.tarefas; do
  launchctl bootout "gui/$(id -u)/$L" 2>/dev/null || true
  launchctl bootstrap "gui/$(id -u)" "$HOME/Library/LaunchAgents/$L.plist"
done
echo "Coletor instalado em $DIR: CBF diário 06:20 (coletor.log) e tarefas sob demanda a cada 15 min (tarefas.log)."
