#!/bin/sh
set -eu
project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
if [ ! -f "$project_root/app/store.php" ] || [ ! -f "$project_root/public/index.php" ]; then
    echo "Nie znaleziono katalogu aplikacji." >&2
    exit 1
fi
# Kod i katalogi: 705. Nie zmieniaj metadanych Git ani lokalnych środowisk.
find "$project_root" \( -path "$project_root/.git" -o -path "$project_root/.runtime" -o -path "$project_root/.test-data" -o -path "$project_root/storage" -o -path "$project_root/public/uploads" \) -prune -o \( -type d -o -type f \) -exec chmod 705 {} +
chmod 705 "$project_root/storage" "$project_root/public/uploads"
chmod 705 "$project_root/storage/.htaccess" "$project_root/storage/.gitkeep" "$project_root/public/uploads/.htaccess" "$project_root/public/uploads/.gitkeep"
# Prywatne dane i blokady są czytelne wyłącznie dla właściciela procesu PHP.
find "$project_root/storage" -type f ! -name .htaccess ! -name .gitkeep -exec chmod 600 {} +
# Zdjęcia są publicznymi zasobami, bez potrzeby ustawiania bitu wykonywalności.
find "$project_root/public/uploads" -type f ! -name .htaccess ! -name .gitkeep -exec chmod 644 {} +
echo "Gotowe: kod i katalogi 705, dane 600, zdjęcia 644."
