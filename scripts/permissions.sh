#!/bin/sh
set -eu
project_root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
if [ ! -f "$project_root/app/store.php" ] || [ ! -f "$project_root/public/index.php" ]; then
    echo "Nie znaleziono katalogu aplikacji." >&2
    exit 1
fi
if [ "$#" -ne 0 ]; then
    if [ "$#" -ne 2 ] || [ "$1" != "--shared-data" ]; then
        echo "Użycie: sh scripts/permissions.sh [--shared-data WSPOLNA_GRUPA]" >&2
        exit 1
    fi
    # Wspólną grupę muszą mieć PHP i uprawnieni deweloperzy.
    chgrp -- "$2" "$project_root/storage"
    chmod 2770 "$project_root/storage"
fi
# Kod i katalogi: 705. Nie zmieniaj metadanych Git ani lokalnych środowisk.
find "$project_root" \( -path "$project_root/.git" -o -path "$project_root/.runtime" -o -path "$project_root/.test-data" -o -path "$project_root/storage" -o -path "$project_root/public/uploads" \) -prune -o \( -type d -o -type f \) -exec chmod 705 {} +
chmod 705 "$project_root/public/uploads"
chmod 705 "$project_root/storage/.htaccess" "$project_root/storage/.gitkeep" "$project_root/public/uploads/.htaccess" "$project_root/public/uploads/.gitkeep"
data_mode=$(stat -c %a "$project_root/storage")
if [ "$((0$data_mode & 02020))" -eq "$((02020))" ]; then
    # Tryb grupowy pozostaje aktywny również po następnym pull i wywołaniu bez flagi.
    chmod 2770 "$project_root/storage"
    data_group=$(stat -c %g "$project_root/storage")
    # Właściciel kodu może być innym użytkownikiem niż właściciel danych PHP.
    find "$project_root/storage" -type f ! -name .htaccess ! -name .gitkeep -user "$(id -un)" -exec chgrp -- "$data_group" {} + -exec chmod 660 {} +
    echo "Dane: katalog 2770, pliki i blokady 660, grupa ID $data_group."
    echo "Plikom należącym do PHP aplikacja ustawi tę grupę i prawa przy kolejnym żądaniu."
else
    chmod 705 "$project_root/storage"
    find "$project_root/storage" -type f ! -name .htaccess ! -name .gitkeep -exec chmod 600 {} +
    echo "Dane prywatne: katalog 705, pliki i blokady 600."
fi
# Zdjęcia są publicznymi zasobami.
find "$project_root/public/uploads" -type f ! -name .htaccess ! -name .gitkeep -exec chmod 644 {} +
echo "Gotowe: kod i zwykłe katalogi 705, zdjęcia 644."
