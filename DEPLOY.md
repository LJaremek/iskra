# Wdrożenie Iskry na serwer

## Zawartość i wymagania
Paczka zawiera aktualny kod aplikacji i testy. Baza SQLite utworzy się
automatycznie przy pierwszym uruchomieniu. Konto admina należy założyć na serwerze.
Nie ma domyślnego hasła ani publicznej rejestracji kolejnych kont.

Wymagania: PHP 8.1+, PDO SQLite, fileinfo oraz standardowe session i filter.
Produkcja: Apache z PHP lub nginx z PHP-FPM. Composer, Node, cron i WebSockety
nie są potrzebne. Python 3 jest potrzebny tylko do opcjonalnych testów.

## Instalacja
1. Sklonuj repozytorium lub rozpakuj paczkę na serwerze:
       git clone https://github.com/LJaremek/iskra.git
   Katalog repozytorium będzie nosił nazwę iskra/. W przykładach poniżej
   free_kahoot/ oznacza katalog główny aplikacji; dostosuj go do nazwy swojego katalogu.
2. Jeśli masz własny vhost, ustaw document root na free_kahoot/public/.
   Na hostingu z podfolderami wejdź pod pełny adres tego katalogu, np.
   https://domena.example/~uzytkownik/integracja/public/.
   Prefiks URL jest rozpoznawany automatycznie; nie wpisuj go ręcznie w kodzie.
   Przykłady konfiguracji Apache i nginx znajdują się w README.md.
   Prześlij również ukryte pliki .htaccess, w tym blokady w app/, storage/ i tests/.
   Nie przenoś samego index.php bez pozostałych katalogów.
3. Zapewnij procesowi PHP prawo zapisu do storage/ i public/uploads/.
   Użytkownik PHP to często www-data, ale sprawdź konfigurację serwera.
   Przykład, wykonywany w katalogu free_kahoot/:
       sudo chown -R www-data:www-data storage public/uploads
       sudo chmod 750 storage
       sudo chmod 755 public/uploads
4. W konfiguracji PHP ustaw:
       upload_max_filesize = 5M
       post_max_size = 30M
       max_file_uploads = 8
       session.gc_maxlifetime = 43200
       display_errors = Off
       log_errors = On
   Sesje PHP muszą mieć zapisywalny katalog. W nginx ustaw client_max_body_size 30m.
5. Włącz HTTPS. Za reverse proxy ustaw przekazywanie HTTPS w zaufanej konfiguracji
   serwera, tak aby PHP widziało HTTPS=on.
6. Przed publicznym udostępnieniem wejdź na /admin i utwórz pierwsze konto admina.
7. Utwórz quiz i rozpocznij grę. Gracze dołączają przez /ng, podając kod i nazwę.

## Aktualny przebieg rozgrywki
- Nowe pytania mają domyślnie 10 sekund; czas można edytować.
- Przed każdym pytaniem jest 5 sekund odliczania.
- Wyniki pytania trwają 15 sekund, potem gra przechodzi dalej automatycznie.
- Prowadzący może pominąć oczekiwanie przyciskiem „Następne pytanie”,
  a po ostatnim pytaniu przyciskiem „Pokaż podium teraz”.
- Wyniki pokazują rozkład głosów, poprawną opcję i punkty za rundę oraz sumę.
- Na końcu jest podium top 3 i tabela wszystkich graczy.

## Test przed udostępnieniem
Jeżeli na serwerze jest Python 3, można wykonać:
    python3 tests/smoke.py
Test korzysta z osobnej, tymczasowej bazy i nie zmienia bazy aplikacji.
Następnie sprawdź na docelowym serwerze logowanie, upload zdjęcia i dołączenie
gracza z drugiej przeglądarki. Testy obciążenia zależą od docelowego hostingu.

## Dane i aktualizacje
Baza: storage/quiz.sqlite. Zdjęcia: public/uploads/.
Te dane zachowuj przy kolejnych aktualizacjach aplikacji.
Kopie aktywnej bazy wykonuj mechanizmem backupu SQLite; szczegóły w README.md.
Nie kopiuj ustawień sieci WSL — na serwerze Linux nie są potrzebne.


## Wgranie poprawki do już działającej instalacji
Nadpisz kod i pliki .htaccess, zachowując istniejącą bazę storage/quiz.sqlite,
pliki WAL/SHM oraz dodane zdjęcia w public/uploads/. Nie kasuj bazy przy aktualizacji.
Odśwież ekrany prowadzącego i graczy po zmianie plików JavaScript.

Jeżeli pozostaje błąd 500, odczytaj jego pełny tekst. Brak pdo_sqlite oraz
brak uprawnień do zapisu bazy są teraz opisane wprost. Inne błędy wymagają
sprawdzenia logu PHP z tej samej godziny. Nie publikuj phpinfo() ani zawartości logów.


## Aktualizacja z repozytorium
Po wypchnięciu zmian przez dewelopera wykonaj na serwerze w katalogu repozytorium:
    git pull --ff-only
Baza i przesłane zdjęcia są ignorowane przez Git i nie są nadpisywane przez pull.
Nie zapisuj własnych zmian kodu na serwerze, jeśli mają być zarządzane w repozytorium.
