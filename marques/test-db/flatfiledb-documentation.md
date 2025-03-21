# FlatFileDB Dokumentation

## 1. Überblick und Vorbereitung

FlatFileDB ist eine einfache, dateibasierte Datenbank, die Datensätze in JSON-Lines-Dateien speichert. Zu den wichtigsten Features gehören:

- **CRUD-Operationen**: Datensätze einfügen, aktualisieren, löschen und abrufen.
- **Index-Verwaltung**: Ein interner Index ordnet Datensatz-IDs den Byte-Offets in der Datei zu. Damit bleibt der Zugriff auch bei großen Dateien effizient.
- **Transaktions-Logging**: Jede Operation wird in einem Log festgehalten, was besonders bei Fehlern oder zur Nachvollziehbarkeit hilfreich ist.
- **Kompaktierung**: Überflüssige (gelöschte oder veraltete) Datensätze können aus der Datei entfernt und der Index neu aufgebaut werden.

Bevor du startest, solltest du folgende Dateien in deinem Projekt haben:
- FlatFileDB.php (enthält alle Klassen: FlatFileDatabase, FlatFileTableEngine, etc.)
- Eine Datei, in der du deine Anwendungslogik schreibst (z. B. testdb.php)

## 2. Einbinden und Initialisieren der Datenbank

Zuerst bindest du die Datenbank-Klassen ein und erstellst eine Instanz der Datenbank. Dabei kannst du optional festlegen, ob der Index nach jedem Schreibvorgang automatisch in die Datei geschrieben werden soll (Parameter autoCommitIndex).

Beispiel:

```php
<?php
// testdb.php

// Fehleranzeige aktivieren (nur in der Entwicklung)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// FlatFileDB einbinden
require_once 'FlatFileDB.php'; // Enthält alle Klassen (Namespace: FlatFileDB)

// Nutzung der Klassen per "use"
use FlatFileDB\FlatFileDatabase;
use FlatFileDB\FlatFileDBConstants;

// Erstelle eine Datenbankinstanz und wähle z. B. autoCommitIndex = false (du kannst das später über commitAllIndexes() manuell auslösen)
$db = new FlatFileDatabase(FlatFileDBConstants::DEFAULT_BASE_DIR, false);

// Registriere Tabellen – im Beispiel registrieren wir die Tabellen "users" und "products"
$db->registerTables(['users', 'products']);
```

## 3. Definieren des Schemas

Für jede Tabelle kannst du ein Schema festlegen. Das Schema definiert Pflichtfelder und die erwarteten Datentypen. Das ist besonders hilfreich, um sicherzustellen, dass nur valide Datensätze in die Datenbank gelangen.

Beispiel:

```php
// Für die Tabelle "users" definieren wir, dass 'name' und 'email' Pflichtfelder sind.
// Außerdem erwarten wir, dass 'name' und 'email' Strings sind und 'age' als Integer.
$db->table('users')->setSchema(
    ['name', 'email'],
    ['name' => 'string', 'email' => 'string', 'age' => 'int']
);
```

## 4. CRUD-Operationen

### a) Datensatz einfügen (Insert)

Mit der Methode insertRecord() fügst du einen neuen Datensatz ein. Wichtig ist, dass die ID eindeutig sein muss.

Beispiel:

```php
// Neuen Benutzer einfügen
$success = $db->table('users')->insertRecord('user123', [
    'name'  => 'Alice Johnson',
    'email' => 'alice@example.com',
    'age'   => 32
]);

if ($success) {
    echo "Benutzer erfolgreich eingefügt.";
} else {
    echo "Fehler: Ein Benutzer mit dieser ID existiert bereits.";
}

// Nach dem Schreibvorgang wird der Index manuell commitet,
// damit beim Neuladen der Seite die aktuelle Index-Datei genutzt wird.
$db->commitAllIndexes();
```

### b) Datensatz aktualisieren (Update)

Mit updateRecord() wird ein bestehender Datensatz aktualisiert. Dabei werden alte Datensätze als gelöscht markiert und ein neuer Eintrag wird angehängt.

Beispiel:

```php
// Bestehenden Benutzer aktualisieren
$success = $db->table('users')->updateRecord('user123', [
    'name'  => 'Alice J.',
    'email' => 'alice_j@example.com',
    'age'   => 33
]);

if ($success) {
    echo "Benutzer erfolgreich aktualisiert.";
} else {
    echo "Fehler: Benutzer wurde nicht gefunden.";
}

// Index aktualisieren
$db->commitAllIndexes();
```

### c) Datensatz löschen (Delete)

Mit deleteRecord() wird ein Datensatz gelöscht, indem der Datensatz als gelöscht markiert wird. Auch hier wird der Index angepasst.

Beispiel:

```php
// Benutzer löschen
$success = $db->table('users')->deleteRecord('user123');

if ($success) {
    echo "Benutzer erfolgreich gelöscht.";
} else {
    echo "Fehler: Benutzer konnte nicht gefunden werden.";
}

// Index speichern
$db->commitAllIndexes();
```

### d) Datensatz abrufen (Select)

Um einen einzelnen Datensatz abzurufen, nutzt du selectRecord(), während selectAllRecords() alle aktiven (nicht gelöschten) Datensätze liefert.

Beispiel:

```php
// Einzelnen Benutzer abrufen
$user = $db->table('users')->selectRecord('user123');
if ($user) {
    print_r($user);
} else {
    echo "Benutzer nicht gefunden.";
}

// Alle aktiven Benutzer abrufen
$allUsers = $db->table('users')->selectAllRecords();
foreach ($allUsers as $user) {
    echo "ID: {$user['id']}, Name: {$user['name']}<br>";
}
```

## 5. Weitere Funktionen

### a) Index-Management

**commitAllIndexes()**:
- Diese Methode speichert alle in-Memory-Indizes in die jeweiligen Index-Dateien.
- Nutzen: Rufe sie nach jeder Schreiboperation (Insert, Update, Delete) auf, wenn du autoCommitIndex auf false gesetzt hast.

**compactTable()**:
- Dieser Vorgang „säubert" die Datendatei, indem veraltete und gelöschte Einträge entfernt werden und der Index neu aufgebaut wird.
- Nutzen: Führe die Kompaktierung eher manuell oder periodisch aus, da dieser Prozess relativ aufwändig ist.

Beispiel:

```php
$db->table('users')->compactTable();
echo "Tabelle 'users' wurde kompaktiert.";
```

### b) Backup und Datenbank leeren

**Backup erstellen**:
- Mit createBackup($backupDir) kannst du alle Tabellen sichern.

Beispiel:

```php
$backupResults = $db->createBackup(FlatFileDBConstants::DEFAULT_BACKUP_DIR);
echo "Backup wurde erstellt.";
```

**Datenbank leeren**:
- Mit clearDatabase() werden alle Daten, Indizes und Logs gelöscht.

Beispiel:

```php
$db->clearDatabase();
echo "Die Datenbank wurde geleert.";
```

## 6. Einbinden in dein HTML-Interface

Typischerweise kombinierst du die oben genannten Operationen mit einem HTML-Formular, um Benutzerinteraktionen zu ermöglichen. Ein Beispiel-Workflow könnte folgendermaßen aussehen:

**Formular über POST abschicken**:
- Jede Aktion (Insert, Update, Delete, Suche, Backup, Kompaktierung) wird über ein verstecktes Feld action definiert, z. B.:

```html
<form method="post">
    <input type="hidden" name="action" value="insert_user">
    <!-- Weitere Felder für Benutzer-ID, Name, etc. -->
    <button type="submit">Benutzer hinzufügen</button>
</form>
```

**PHP-Logik ausführen**:
- Im PHP-Code liest du den Wert von $_POST['action'] aus und führst den entsprechenden Case im Switch-Statement aus (wie in den Beispielen weiter oben).

**Feedback und Aktualisierung**:
- Nach der Operation commitest du den Index (oder führst ggf. eine Kompaktierung durch) und gibst eine Erfolgsmeldung zurück. Bei einem Seitenreload werden die aktuellen Daten aus der Datei (bzw. aus dem persistierten Index) geladen.
