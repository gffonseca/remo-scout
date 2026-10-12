#!/bin/bash
# Instala/atualiza o coletor local no Mac (launchd, diário 06:20). Não grava o token: ele fica em
# ~/.config/remo-scout/coletor.env (chmod 600), criado à parte.
set -euo pipefail
DIR="$HOME/remo-scout-coletor"
SRC="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$DIR" "$HOME/.config/remo-scout" "$HOME/Library/LaunchAgents"
cp "$SRC/remo_scout_coletor.py" "$DIR/"
sed "s#__HOME__#$HOME#g" "$SRC/br.com.remo.scout.coletor.plist" > "$HOME/Library/LaunchAgents/br.com.remo.scout.coletor.plist"
chmod 700 "$HOME/.config/remo-scout"
[ -f "$HOME/.config/remo-scout/coletor.env" ] && chmod 600 "$HOME/.config/remo-scout/coletor.env" || echo "AVISO: crie ~/.config/remo-scout/coletor.env com REMO_SCOUT_URL e REMO_SCOUT_TOKEN"
launchctl bootout "gui/$(id -u)/br.com.remo.scout.coletor" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$HOME/Library/LaunchAgents/br.com.remo.scout.coletor.plist"
echo "Coletor instalado em $DIR (launchd br.com.remo.scout.coletor, diário 06:20). Log: $DIR/coletor.log"
