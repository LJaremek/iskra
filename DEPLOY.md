# Wdrożenie Iskry

## Pierwsze uruchomienie na hostingu

1. Sklonuj repozytorium:
       git clone https://github.com/LJaremek/iskra.git
2. W katalogu repozytorium wykonaj:
       sh scripts/permissions.sh
3. Otwórz pełny adres katalogu public/, np.:
       https://domena.example/~uzytkownik/iskra/public/
4. Kliknij „Panel prowadzącego” i załóż pierwsze konto administratora.
5. Utwórz quiz. Gracze dołączają pod adresem kończącym się na:
       /iskra/public/ng/
   Alternatywnie:
       /iskra/public/index.php?route=/ng

Potrzebne jest PHP 8.1+ ze standardowymi funkcjami plików, JSON, sesji i filter.
PDO, SQLite, MySQL, Composer, Node, GD i fileinfo nie są wymagane.

Domyślny routing przez index.php?route=... działa bez mod_rewrite.
public/ng/index.php jest fizycznym endpointem dołączania. Zachowaj ten podkatalog.

## Uprawnienia

Skrypt nadaje 705 kodowi i katalogom. Prywatne dane i blokady mają 600,
a publiczne zdjęcia 644. Git nie przechowuje dokładnego trybu 705 — po pull
trzeba uruchomić skrypt na serwerze.

W domyślnym trybie storage/ i public/uploads/ muszą należeć do użytkownika
procesu PHP. Przy trybie 705 tylko właściciel może w nich zapisywać.

Gdy dane tworzy www-data i deweloperzy potrzebują SSH/SFTP do plików, administrator
hostingu musi przygotować wspólną prywatną grupę obejmującą PHP i deweloperów.
Nie trzeba umożliwiać logowania jako www-data. Właściciel storage/ lub administrator
następnie uruchamia:
    sh scripts/permissions.sh --shared-data WSPOLNA_GRUPA

To ustawia storage/ na 2770, pliki danych i blokad na 660. Setgid utrzymuje grupę
nowych plików; aplikacja zachowuje dostęp po kolejnych zapisach i atomowej podmianie.
Właściciel kodu nie musi być właścicielem danych: pliki należące do PHP aplikacja
dostosuje podczas następnego żądania, o ile PHP należy do wskazanej grupy i może
wywołać chgrp. W przeciwnym razie administrator musi ustawić grupę istniejących
plików przed aktywacją. Deweloperzy muszą mieć przejście przez katalogi nadrzędne.

Skrypt bez flagi zachowuje raz włączony tryb współdzielony. Nie zmienia zawartości.
Sam pull nie zmienia właściciela plików ani członkostwa w grupach. Szczegóły
i ograniczenia konfiguracji uprawnień opisano w README.md.

## Konfiguracja PHP i HTTP

Ustaw upload_max_filesize=5M, post_max_size=30M, max_file_uploads=8
i session.gc_maxlifetime=43200. Włącz logowanie błędów, wyłącz ich publiczne
wyświetlanie. Sesje muszą mieć zapisywalny katalog.

Jeżeli masz własny vhost, wskaż document root na public/.
Na hostingu z podfolderami zachowaj strukturę repozytorium i ukryte pliki .htaccess.
Włącz HTTPS. Dla nginx ustaw limit body 30m. Za reverse proxy przekaż HTTPS=on
w zaufanej konfiguracji.

## Dane i migracja

Dane tworzą się w storage/quiz.json.php. To JSON z osłoną PHP, która blokuje
bezpośredni odczyt przez HTTP również bez .htaccess. Nie usuwaj osłony.
Plik .lock jest potrzebny do serializacji zapisów. Nie usuwaj go podczas gry.

Jeżeli istnieje starsza baza SQLite, przed wejściem do aplikacji wykonaj:
    python3 scripts/migrate_sqlite.py
Nie jest potrzebne rozszerzenie PHP SQLite. Polecenie nie usuwa starej bazy
i nie nadpisuje istniejącego JSON. Kopię starej bazy przechowuj poza WWW.

## Kolejne aktualizacje

W katalogu repozytorium:
    git pull --ff-only
    sh scripts/permissions.sh

Quizy, pytania, konto i historia są w ignorowanym przez Git pliku
storage/quiz.json.php; zdjęcia w ignorowanym public/uploads/. Zwykły git pull
nie nadpisuje tych danych. Nie używaj git clean -fdx i nie kasuj tych katalogów.
Nie kopiuj przykładowego pliku danych na istniejącą instalację.

Obsługa kilku poprawnych opcji nie wymaga migracji ani ponownego tworzenia quizu.
Stare pytania i historia nadal działają; dotychczasowa poprawna opcja pozostaje
zaznaczona w edytorze. Po pull można zaznaczyć kolejne i zapisać pytanie.
Edycja dotyczy kolejnych uruchomień — już utworzona rozgrywka ma własną kopię pytań.

Aktualizuj między rozgrywkami i odśwież panel oraz przeglądarki graczy po zmianie JS.
Nie zapisuj własnych zmian kodu na serwerze, jeśli kod jest zarządzany przez Git.

## Testy

Lokalnie lub na serwerze z Pythonem 3:
    python3 tests/smoke.py
    python3 tests/store_integrity.py
Nie dotykają danych produkcyjnych. Wykonaj też test dołączania na docelowym
hostingu. Przed dużym wydarzeniem sprawdź jego wydajność.

## Gdy coś nie działa

- Brak /ng/: sprawdź, czy na serwerze istnieje public/ng/index.php i katalog ma
  prawa 705. Użyj index.php?route=/ng, jeśli hosting nie obsługuje DirectoryIndex.
- 500: przeczytaj pełny komunikat. Brak zapisu oznacza problem z właścicielem
  storage/ lub prawami. Pozostałe wyjątki są w logu PHP.
- „Wróć” jest linkiem wewnętrznym. Powinien pozostać pod prefiksem aplikacji.
