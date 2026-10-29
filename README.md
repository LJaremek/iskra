# Iskra — quizy na żywo

Prosta przeglądarkowa gra w PHP i SQLite. Bez frameworka, Composera, Node, zewnętrznych usług i zadań cron.

## Uruchomienie na Linuxie

Wymagania: **PHP 8.1+**, rozszerzenia **PDO SQLite**, **fileinfo**, standardowe **session** i **filter**. Apache lub nginx + PHP-FPM do stałego hostowania. Przykład dla Ubuntu/Debiana:

```sh
sudo apt install php-cli php-sqlite3
git clone https://github.com/LJaremek/iskra.git
cd iskra
php -S 0.0.0.0:8080 -t public router.php
```

1. Wejdź na **http://localhost:8080/admin** i załóż konto administratora (hasło minimum 12 znaków, maksimum 72 bajty). Zrób to przed udostępnieniem aplikacji innym.
2. Utwórz quiz. Dodaj pytania z 2–4 odpowiedziami, jedną poprawną i czasem 5–180 sekund (domyślnie 10 sekund).
3. Kliknij „Rozpocznij grę”. Udostępnij graczom adres **http://ADRES-SERWERA:8080/ng** oraz wyświetlony trzycyfrowy kod.
4. Gracze podają kod i nazwę, bez zakładania kont. Po dołączeniu wszystkich prowadzący zaczyna grę.
5. Przed każdym pytaniem trwa 5-sekundowe odliczanie. Po zamknięciu odpowiedzi przez 15 sekund widać rozkład głosów, poprawną odpowiedź i ranking z przyrostem punktów; następne pytanie rusza automatycznie.
6. Po ostatnim pytaniu i 15 sekundach wyników pojawia się podium top 3 oraz pełna tabela rankingu. Historia w panelu pokazuje odpowiedzi i czas każdego gracza dla poszczególnych uruchomień.

Na telefonach użyj adresu IP/nazwy serwera dostępnej z ich sieci; `localhost` na telefonie wskazuje sam telefon. Nie używaj wbudowanego serwera PHP jako serwera produkcyjnego.

## Punktacja i zasady

- Poprawna odpowiedź: `round(1000 - 500 × czas_odpowiedzi / limit_czasu)`, czyli 500–1000 punktów. Szybciej = więcej punktów.
- Błędna odpowiedź lub brak odpowiedzi: 0 punktów.
- Jedna niezmienialna odpowiedź na gracza i pytanie. Czas, punkty i poprawność rozstrzyga serwer.
- Domyślny czas nowego pytania to 10 sekund. Przed każdym pytaniem obowiązuje 5 sekund odliczania; pytanie i odpowiedzi są wtedy ukryte także w API.
- Po pytaniu wyniki są wyświetlane przez 15 sekund. Serwer automatycznie rozpoczyna odliczanie kolejnego pytania albo kończy grę po ostatnim. Nie wymaga to otwartego ekranu prowadzącego. Prowadzący może pominąć pozostały czas przyciskiem „Następne pytanie”, a po ostatnim pytaniu — „Pokaż podium teraz”.
- Wyniki rundy pokazują liczbę głosów na każdą opcję, poprawną odpowiedź i liczbę osób bez odpowiedzi. W rankingu przyrost punktów jest wyświetlany jako `+873 (2421)` — punkty za rundę i suma.
- Poprawna odpowiedź, zdobyte punkty i statystyki głosów pozostają ukryte w API do końca czasu.
- Remisy rozstrzygają kolejno: więcej poprawnych odpowiedzi, krótszy łączny czas zapisanych odpowiedzi, kolejność dołączenia.
- Dołączanie nowych graczy jest możliwe w poczekalni. Ta sama przeglądarka zachowuje tożsamość po odświeżeniu, również w trakcie gry. Inna przeglądarka lub usunięcie ciasteczek oznacza nową tożsamość.
- Nazwy są unikalne w rozgrywce (wielkość liter ASCII i polskich liter nie ma znaczenia).
- Maksymalnie 200 graczy w rozgrywce. To limit aplikacji, a nie potwierdzona wydajność na każdym hostingu.
- Aktywne gry otrzymują niepowtarzające się kody 100–999. Stare niezakończone gry wygasają po 12 godzinach; nie potrzeba crona.
- Każde uruchomienie zapisuje kopię quizu. Edycja albo usunięcie quizu nie zmienia rozgrywki ani historii.
- Zdjęcia pytań i odpowiedzi: JPG, PNG, WebP, GIF; maksymalnie 5 MB i 6000 × 6000 px. Odpowiedź może być samym zdjęciem. Zdjęcia są publicznymi plikami; nie dodawaj materiałów poufnych.

## Konfiguracja produkcyjna

**Jeśli możesz ustawić katalog główny witryny, wskaż `public/`.** Na hostingu z adresem w rodzaju `/~uzytkownik/integracja/public/` aplikacja sama rozpoznaje prefiks i dodaje go do linków, przekierowań, API oraz zasobów. Zachowaj całą strukturę katalogów i dołączone pliki `.htaccess`, także w `app/`, `storage/` i `tests/`: blokują bezpośredni dostęp do tych katalogów przez Apache. Baza i kod aplikacji nie mogą być pobierane przez HTTP.

Proces PHP potrzebuje prawa zapisu do `storage/` (baza, pliki WAL/SHM) i `public/uploads/`. Ustaw właściciela na użytkownika PHP, np. `www-data`; nie ustawiaj `chmod 777`.

Przykładowe ustawienia PHP:

```ini
upload_max_filesize = 5M
post_max_size = 30M
max_file_uploads = 8
display_errors = Off
log_errors = On
session.cookie_httponly = On
session.cookie_samesite = Lax
```

Limit 30M pozwala wysłać pytanie i cztery odpowiedzi ze zdjęciami w jednym formularzu. Skonfiguruj analogiczny limit body w serwerze HTTP. Pozostaw standardowe przechowywanie sesji PHP w zapisywalnym katalogu i odpowiednio długi czas życia sesji, np. `session.gc_maxlifetime = 43200`.

### Apache

Wskaż `DocumentRoot /sciezka/free_kahoot/public`, włącz `mod_rewrite` i zezwól na `AllowOverride All` dla tego katalogu. Plik `public/.htaccess` przekierowuje przyjazne adresy do `index.php`, a `uploads/.htaccess` blokuje wykonywanie skryptów w uploadach.

### nginx + PHP-FPM

Dostosuj ścieżkę oraz socket PHP:

```nginx
server {
    listen 80;
    server_name quiz.example.com;
    root /var/www/free_kahoot/public;
    index index.php;
    client_max_body_size 30m;

    location / { try_files $uri /index.php$is_args$args; }

    location ^~ /uploads/ {
        try_files $uri =404;
        # Tu nie może być przekazywania plików do PHP.
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ \.php$ { return 404; }
    location ~ /\. { deny all; }
}
```

Włącz HTTPS na serwerze docelowym. Aplikacja ustawia bezpieczne cookie, gdy PHP widzi `HTTPS=on`; za reverse proxy przekaż tę informację w zaufanej konfiguracji serwera. Aplikacja celowo nie ufa nagłówkom proxy dostarczonym przez klienta.

### Dane i kopie zapasowe

Domyślna baza: `storage/quiz.sqlite`. Opcjonalnie ustaw zmienną środowiskową **QUIZ_DB** na absolutną ścieżkę bazy poza katalogiem publicznym. Baza tworzy się automatycznie. Zdjęcia są w `public/uploads/`.

Zrób spójny backup SQLite poleceniem `sqlite3 storage/quiz.sqlite ".backup '/backup/quiz.sqlite'"` oraz zachowaj katalog uploadów. Nie kopiuj wyłącznie głównego pliku aktywnej bazy w trybie WAL.

Przy utracie hasła administratora zatrzymaj aplikację i lokalnie usuń tylko rekord admina (`DELETE FROM admins;`), a następnie przeprowadź konfigurację ponownie przed przywróceniem dostępu publicznego. Quizy i historia zostają zachowane. Nie ma publicznego endpointu resetowania hasła.

Zdjęcia pozostają w uploadach także po usunięciu pytania — mogą nadal być używane w historii. Kod nie usuwa ich automatycznie.

## Pliki

- `public/index.php` — routing, formularze, API.
- `app/bootstrap.php` — SQLite, sesje, walidacja i upload.
- `app/game.php` — silnik gry i ranking.
- `app/views.php` — szablony HTML.
- `public/assets/app.js` — polling i zachowanie interfejsu.
- `public/assets/style.css` — responsywny wygląd.
- `router.php` — router do lokalnego serwera PHP.
- `tests/smoke.py` — test całego przepływu HTTP na odrębnej, tymczasowej bazie, w katalogu głównym i pod prefiksem URL.
- `tests/subdirectory-router.php` — router używany wyłącznie do testowania podfolderów.

## Testy

Testy potrzebują Pythona 3 (wyłącznie standardowa biblioteka) i PHP z wymaganymi rozszerzeniami:

```sh
python3 tests/smoke.py
```

Jeśli PHP jest pod inną ścieżką, ustaw `PHP_BIN`. Dodatkowe argumenty interpretera można przekazać przez `PHP_ARGS`. Test uruchamia własny serwer na wolnym porcie i używa tymczasowej bazy, nie zmieniając danych aplikacji.

Synchronizacja używa zapytań HTTP co około sekundę. Nie są potrzebne WebSockety, demony ani procesy kolejkowe. Przetestuj wydajność na docelowym serwerze przed dużym wydarzeniem.

## Diagnostyka wdrożenia

Przy braku SQLite albo praw zapisu aplikacja wyświetla konkretną informację na ekranie błędu 500. Sprawdź rozszerzenie `pdo_sqlite` w PHP obsługującym stronę (konfiguracja CLI może być inna) oraz prawo zapisu do całego katalogu `storage/` i istniejącej bazy. Pozostałe wyjątki są zapisywane do logu błędów PHP. Na hostingu z podfolderem prawidłowy adres CSS kończy się na `/integracja/public/assets/style.css`, a adres dołączania na `/integracja/public/ng`.


## Aktualizacje przez Git

Kod rozwijamy w repozytorium https://github.com/LJaremek/iskra na gałęzi main.
Po wypchnięciu zmian na serwerze wykonaj w katalogu repozytorium:

```sh
git pull --ff-only
```

Baza SQLite, zdjęcia użytkowników, lokalne środowisko PHP, sesje testowe
i archiwa ZIP są pomijane przez Git. Dane na serwerze zachowuje się między
aktualizacjami. Sprawdź konfigurację PHP i uprawnienia przy pierwszym wdrożeniu
zgodnie z DEPLOY.md.
