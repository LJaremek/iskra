# Iskra — quizy na żywo

Prosta aplikacja przeglądarkowa w PHP. Dane są zapisywane jako JSON z blokadami i atomową podmianą pliku. Nie potrzeba PDO, SQLite, MySQL, Composera, Node, GD ani fileinfo.

## Uruchomienie

Wymagania: PHP 8.1+ ze standardowymi funkcjami JSON, sesji, filter i obsługą plików.
Proces PHP musi móc zapisywać w storage/ oraz public/uploads/.

```sh
git clone https://github.com/LJaremek/iskra.git
cd iskra
sh scripts/permissions.sh
php -S 0.0.0.0:8080 -t public router.php
```

Wbudowany serwer PHP służy do testów lokalnych. Na serwerze docelowym użyj udostępnionego przez hosting Apache lub nginx z PHP.

1. Wejdź na /public/ w adresie hostingu lub na http://localhost:8080/ w teście lokalnym.
2. Kliknij „Panel prowadzącego” i załóż pierwsze konto administratora (hasło minimum 12 znaków, maksimum 72 bajty). Zrób to przed udostępnieniem strony innym.
3. Utwórz quiz, dodaj pytania z 2–4 odpowiedziami i zaznacz jedną lub kilka poprawnych.
4. Uruchom rozgrywkę. Gracze podają kod i nazwę, bez zakładania kont. Mogą też zeskanować QR w poczekalni prowadzącego — kod gry będzie już wpisany.
5. Prowadzący uruchamia grę; kolejne rundy przechodzą automatycznie.

Konto administratora jest jedno. Po jego utworzeniu rejestracja jest zamknięta.

## Hosting bez mod_rewrite

Domyślnie linki panelu i API korzystają z index.php?route=..., więc nie wymagają przepisywania adresów przez Apache. Prefiks podfolderu jest rozpoznawany automatycznie.

Dołączanie ma fizyczny endpoint **public/ng/index.php**. Dzięki temu adres **.../public/ng/** działa także bez mod_rewrite. Awaryjny adres to **.../public/index.php?route=/ng**.

Przycisk „Wróć” na ekranie błędu jest linkiem wewnątrz aplikacji; nie korzysta z historii przeglądarki.

Jeżeli na własnym serwerze masz poprawnie skonfigurowane reguły rewrite, możesz ustawić zmienną środowiskową QUIZ_PRETTY_URLS=1, aby używać krótszych adresów. Nie jest to wymagane.

## Rozgrywka

- Nowe pytanie ma domyślnie 10 sekund; można ustawić 5–180 sekund.
- Przed każdym pytaniem trwa 5 sekund odliczania. Treść i odpowiedzi są wtedy ukryte także w API.
- Wyniki pytania są widoczne przez 15 sekund. Potem automatycznie rusza kolejna runda albo ranking końcowy.
- Admin może skrócić oczekiwanie przyciskiem „Następne pytanie” lub „Pokaż podium teraz”.
- Wyniki pokazują głosy na każdą odpowiedź, wszystkie poprawne opcje i liczbę osób bez odpowiedzi.
- Ranking pokazuje przyrost i sumę, np. +873 (2421). Finał to podium top 3 i tabela wszystkich graczy.
- Poprawna odpowiedź daje round(1000 - 500 × czas_odpowiedzi / limit_czasu) punktów. Błędna odpowiedź lub brak odpowiedzi daje 0.
- Admin oznacza jedną lub kilka poprawnych opcji. Gracz wybiera jedną — dowolna poprawna opcja daje punkty według tego samego wzoru, bez dzielenia punktów.
- Jednej odpowiedzi nie można zmienić. Czas i poprawność rozstrzyga serwer.
- Remisy rozstrzygają: więcej poprawnych odpowiedzi, krótszy łączny czas, kolejność dołączenia.
- Gracze dołączają w poczekalni. Odświeżenie zachowuje gracza w tej samej przeglądarce.
- Nazwy są unikalne w rozgrywce; wielkość liter ASCII i polskich liter nie ma znaczenia.
- Każde uruchomienie zapisuje kopię quizu. Edycja lub usunięcie quizu nie zmienia historii.
- Maksymalnie 200 graczy to limit aplikacji, nie gwarancja wydajności hostingu.
- Kody aktywnych gier są unikalne, od 100 do 999. Rozgrywki wygasają po 12 godzinach.
- Zdjęcia pytań i odpowiedzi: JPG, PNG, WebP i GIF, do 5 MB i 6000 × 6000 px.

## Kody QR

Poczekalnia prowadzącego pokazuje QR i link do konkretnej rozgrywki.
Adres ma postać .../public/ng/?code=123. Po zeskanowaniu gracz wpisuje nazwę
i klika „Dołącz do gry”. Gracze nadal dołączają tylko w poczekalni.

Na stronie dołączania opcja „Pokaż QR strony dołączania” udostępnia ogólny
adres bez kodu gry. QR uwzględniają domenę i podfolder aktualnie otwartej strony.
Przy testach telefonem otwórz panel pod adresem sieciowym komputera —
QR z localhost wskazywałby telefon.

Kody powstają w przeglądarce jako SVG, bez zewnętrznego API ani rozszerzeń PHP.
Generator qrcode-generator 2.0.4 (MIT) jest dołączony lokalnie w
public/assets/vendor/ wraz z licencją i wskazaniem źródła.

## Dane i prawa dostępu

Domyślne dane: **storage/quiz.json.php**. Wewnątrz znajduje się JSON poprzedzony osłoną PHP, która kończy bezpośrednie żądanie HTTP kodem 404. Osłona nie pozwala pobrać danych również wtedy, gdy hosting ignoruje .htaccess. Nie usuwaj tej pierwszej linii.

Osobny plik quiz.json.php.lock zapewnia blokadę flock. Zapis powstaje w pliku tymczasowym i jest podmieniany przez rename w tym samym katalogu. Odczyt nie widzi połowy zapisu; zapisy równoczesnych żądań są serializowane. Uszkodzony plik nie jest automatycznie zerowany.

Opcjonalna zmienna QUIZ_DATA ustawia absolutną ścieżkę do tego pliku danych. System plików musi obsługiwać blokady i atomową zmianę nazwy. JSON jest rozwiązaniem do małych i średnich rozgrywek; plik z historią rośnie z liczbą gier. Przed dużym wydarzeniem sprawdź wydajność na docelowym hostingu.

Po git pull wykonaj:

```sh
sh scripts/permissions.sh
```

Skrypt nadaje 705 kodowi i katalogom, 600 prywatnym danym oraz 644 publicznym zdjęciom. Pomija .git i lokalne środowiska. Git zapisuje tylko bit wykonywalności, a nie pełny tryb 705, dlatego skrypt trzeba wykonać na serwerze.

Przy trybie prywatnym 705 katalog storage/ musi należeć do użytkownika, jako który działa PHP — tylko właściciel ma prawo zapisu.

### Wspólny dostęp deweloperów do danych

Jeżeli pliki tworzy www-data, nie trzeba logować się na to konto. PHP i wskazani
deweloperzy muszą należeć do jednej, przeznaczonej dla projektu grupy systemowej.
Jej utworzenie i dodanie użytkowników wymaga pomocy administratora hostingu.
Po dodaniu do grupy deweloperzy powinni ponownie zalogować się do SSH/SFTP.

Właściciel storage/ (lub administrator hostingu) włącza dostęp poleceniem:

    sh scripts/permissions.sh --shared-data WSPOLNA_GRUPA

WSPOLNA_GRUPA to istniejąca, prywatna grupa projektu obejmująca użytkownika PHP
i uprawnionych deweloperów. Nie wybieraj ogólnej grupy wszystkich użytkowników
hostingu. Katalog storage/ otrzymuje 2770: zapis dla właściciela i grupy oraz
dziedziczenie grupy przez nowe pliki. Dane i blokada otrzymują 660.
Pozostali użytkownicy nie dostają dostępu do danych.

Pliki należące do www-data, których deweloper nie może zmienić przez chmod,
otrzymają właściwą grupę i prawa przy następnym żądaniu aplikacji jako www-data.
PHP musi być członkiem wybranej grupy. Jeśli PHP ma zablokowane chgrp, grupę
istniejących plików musi wcześniej ustawić administrator hostingu.
Jeżeli również storage/ należy do www-data, samo włączenie trybu wymaga
administratora hostingu — git pull nie zmienia właściciela ani grup systemowych.

Aplikacja utrzymuje 660 i wspólną grupę również po atomowej podmianie pliku.
Kolejne zwykłe wywołania scripts/permissions.sh zachowują ten tryb.
Deweloperzy potrzebują też prawa przejścia przez katalogi nadrzędne.

Zawartość quizów, historia, konto i osłona PHP pozostają bez zmian.
Nie edytuj ręcznie pliku podczas gry: kopie i edycje wykonuj pod blokadą
quiz.json.php.lock. Plik zawiera również konto administratora i historię.
Nie ustawiaj praw 666 ani 777.

Zdjęcia są w public/uploads/. Są publicznymi zasobami. Pozostają na dysku po usunięciu pytania, bo może używać ich historia.

## Konfiguracja PHP

```ini
upload_max_filesize = 5M
post_max_size = 30M
max_file_uploads = 8
session.gc_maxlifetime = 43200
display_errors = Off
log_errors = On
```

Sesje PHP muszą mieć zapisywalny katalog. Dla nginx ustaw także client_max_body_size 30m. Włącz HTTPS; za reverse proxy przekaż HTTPS=on w zaufanej konfiguracji serwera.

Jeżeli możesz ustawić document root, wskaż public/. Na hostingu z podfolderami zachowaj całą strukturę projektu i pliki .htaccess, także blokady w app/, storage/, scripts/ i tests/.

## Przejście ze starszej bazy SQLite

Wymagany jest tylko Python 3 z jego standardową biblioteką sqlite3, a nie rozszerzenie PHP:

```sh
python3 scripts/migrate_sqlite.py
```

Polecenie tworzy chroniony plik JSON, przenosząc quizy, pytania, konta i historię. Nie nadpisuje istniejącego JSON i nie usuwa źródłowej bazy. Jeżeli dawny plik SQLite leży w katalogu udostępnianym przez WWW, zachowaj jego kopię poza tym katalogiem.

Przy wykryciu dawnej bazy i braku JSON aplikacja prosi o migrację, zamiast pokazywać pustą instalację.

## Testy

```sh
python3 tests/smoke.py
python3 tests/store_integrity.py
```

Python jest wymagany tylko do testów i opcjonalnej migracji. Zmienna PHP_BIN może wskazywać interpreter, a PHP_ARGS jego dodatkowe argumenty. Testy HTTP sprawdzają root i podfolder, z rewrite i bez niego. Test integralności sprawdza równoczesny zapis, atomowy odczyt, cofnięcie transakcji i ochronę uszkodzonego pliku. Testy nie zmieniają danych aplikacji.

## Aktualizacje

```sh
git pull --ff-only
sh scripts/permissions.sh
```

Dane, zdjęcia, archiwa i lokalne sesje są ignorowane przez Git. Dalsze instrukcje są w DEPLOY.md.
