<?php
declare(strict_types=1);

namespace Marques\Core;

// Use-Statements für Klarheit hinzufügen
use Marques\Filesystem\PathRegistry;
use Marques\Filesystem\PathResolver;
use RuntimeException;
use InvalidArgumentException;

// Dummy opcache_invalidate Funktion bleibt bestehen
if (!function_exists('opcache_invalidate')) {
    function opcache_invalidate(string $filename, bool $force = false): bool {
        // Dummy-Funktion: Nichts tun, da OPcache nicht verfügbar ist.
        return true;
    }
}

class Cache {
    protected string    $cacheDir;
    protected bool      $useOpcache;
    protected bool      $enabled;
    protected array     $memoryCache = [];
    protected bool      $useIndex;
    protected array     $index = [];
    protected string    $indexFile;
    protected bool      $batchMode = false;
    protected array     $batchIndexUpdates = [];
    // md5Cache ist sinnvoll für getCacheFilePath, aber muss bei clear() geleert werden.
    protected array     $md5Cache = [];
    protected int       $totalRequests = 0;
    protected int       $cacheHits = 0;
    protected float     $totalAccessTime = 0.0;

    protected const DEFAULT_TTL = 3600;
    protected const TEMPLATE_TTL = 3600;
    protected const ASSET_TTL = 86400;
    protected array $defaultTtlMapping = [
        'template_' => self::TEMPLATE_TTL,
        'asset_'    => self::ASSET_TTL,
        'default'   => self::DEFAULT_TTL,
    ];

    /**
     * Konstruktor.
     *
     * @param PathRegistry|string|null $cacheDir PathRegistry oder Pfad zum Cache-Verzeichnis.
     * @param bool $enabled Ob Caching aktiviert ist.
     * @param bool $useIndex Ob der Index verwendet werden soll.
     *
     * @throws RuntimeException Wenn das Cache-Verzeichnis nicht ermittelt, erstellt oder beschrieben werden kann.
     * @throws InvalidArgumentException Wenn der ermittelte Pfad ungültig ist.
     */
    public function __construct(
        PathRegistry|string|null $cacheDir = null,
        bool $enabled = true,
        bool $useIndex = true
    ) {
        // 1. Pfadquelle bestimmen (DI > Konstante > Default)
        $potentialDir = ''; // Initialisieren
        if ($cacheDir instanceof PathRegistry) {
            try {
                $potentialDir = $cacheDir->getPath('cache');
            } catch (RuntimeException $e) {
                throw new RuntimeException("Cache-Pfad 'cache' konnte nicht aus PathRegistry abgerufen werden: " . $e->getMessage(), 0, $e);
            }
        } elseif ($cacheDir === null) {
            $potentialDir = defined('MARQUES_CACHE_DIR')
                 ? MARQUES_CACHE_DIR
                 // __DIR__ verweist auf das Verzeichnis der *aktuellen* Datei (Cache.php)
                 // Es ist oft besser, einen Pfad relativ zum Projekt-Root zu definieren.
                 // Wenn MARQUES_CACHE_DIR nicht definiert ist, ist dieser Fallback evtl. nicht ideal.
                 // Überlegung: Sollte hier eine Exception geworfen werden, wenn keine Konfiguration vorliegt?
                 // Vorerst beibehalten, aber dokumentieren.
                 : dirname(__DIR__) . '/cache'; // Annahme: __DIR__ ist in Marques/Core, also ein Verzeichnis höher + /cache
        } else {
            $potentialDir = $cacheDir; // Direkte Pfadangabe
        }

        if (empty($potentialDir) || !is_string($potentialDir)) {
             throw new InvalidArgumentException("Der angegebene Cache-Pfad ist ungültig oder leer.");
        }

        // 2. Sicherstellen, dass das Verzeichnis existiert (bevor wir es auflösen)
        //    Dies behandelt den Fall, dass der Pfad neu erstellt werden muss.
        if (!is_dir($potentialDir)) {
            // Versuche, das Verzeichnis rekursiv zu erstellen.
            // @ unterdrückt Fehler, wir prüfen das Ergebnis direkt danach.
            if (!@mkdir($potentialDir, 0755, true) && !is_dir($potentialDir)) {
                // Prüfen, ob das übergeordnete Verzeichnis beschreibbar ist, um eine bessere Fehlermeldung zu geben.
                $parentDir = dirname($potentialDir);
                if (!is_dir($parentDir)) {
                     throw new RuntimeException("Cache-Verzeichnis '{$potentialDir}' konnte nicht erstellt werden, da das übergeordnete Verzeichnis '{$parentDir}' nicht existiert.");
                } elseif (!is_writable($parentDir)) {
                     throw new RuntimeException("Cache-Verzeichnis '{$potentialDir}' konnte nicht erstellt werden. Keine Schreibrechte im übergeordneten Verzeichnis '{$parentDir}'.");
                } else {
                     throw new RuntimeException("Cache-Verzeichnis '{$potentialDir}' konnte aus unbekannten Gründen nicht erstellt werden.");
                }
            }
        }

        // 3. Pfad absolut und kanonisch auflösen (nachdem sichergestellt ist, dass er existiert)
        //    realpath() ist hier ideal, da es symbolischen Links folgt und '..' auflöst.
        $resolvedDir = realpath($potentialDir);
        if ($resolvedDir === false) {
             // Wenn realpath fehlschlägt (z.B. wegen fehlender Rechte auf übergeordnete Verzeichnisse),
             // versuchen wir es mit PathResolver als Fallback (obwohl das Verzeichnis existieren sollte).
             try {
                 // PathResolver braucht ein existentes Basisverzeichnis.
                 $base = realpath(dirname($potentialDir));
                 if ($base === false) {
                      throw new RuntimeException("Basisverzeichnis für Cache '{$potentialDir}' konnte nicht aufgelöst werden.");
                 }
                 $resolvedDir = PathResolver::resolve($base, basename($potentialDir));
             } catch (RuntimeException $e) {
                 throw new RuntimeException("Cache-Verzeichnis-Pfad '{$potentialDir}' konnte nicht kanonisch aufgelöst werden: " . $e->getMessage(), 0, $e);
             }
        }
        $this->cacheDir = $resolvedDir;


        // 4. Schreibbarkeit des aufgelösten Verzeichnisses prüfen
        if (!is_writable($this->cacheDir)) {
            // Berechtigungen prüfen kann helfen beim Debuggen
            $perms = @fileperms($this->cacheDir);
            $permsStr = $perms !== false ? substr(sprintf('%o', $perms), -4) : 'unbekannt';
            throw new RuntimeException("Cache-Verzeichnis '{$this->cacheDir}' ist nicht beschreibbar (Berechtigungen: {$permsStr}).");
        }

        // 5. Restliche Initialisierung
        $this->enabled = $enabled; // Zuerst $enabled setzen

        // OPcache nur prüfen, wenn Caching generell aktiviert ist
        $this->useOpcache = $this->enabled && function_exists('opcache_invalidate');

        $this->useIndex = $useIndex;
        $this->indexFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'cache_index.json';

        if ($this->enabled && $this->useIndex) {
            $this->loadIndex();
        } else {
             // Sicherstellen, dass der Index leer ist, wenn er nicht verwendet wird
             $this->index = [];
        }
    }

    // --- Batch-Operationen für den Index ---
    public function beginIndexBatch(): void {
        if (!$this->enabled || !$this->useIndex) return; // Nur sinnvoll, wenn Index aktiv ist
        $this->batchMode = true;
        $this->batchIndexUpdates = [];
    }

    public function commitIndexBatch(): void {
        if (!$this->enabled || !$this->useIndex || !$this->batchMode) return; // Nur sinnvoll, wenn Index aktiv und im Batch-Modus

        // Zusammenführen der Batch-Updates
        foreach ($this->batchIndexUpdates as $group => $keys) {
            if (!isset($this->index[$group])) {
                $this->index[$group] = [];
            }
            // array_values stellt sicher, dass die Keys nach merge numerisch sind
            $this->index[$group] = array_values(array_unique(array_merge($this->index[$group], $keys)));
        }

        $this->batchIndexUpdates = []; // Leeren *nach* dem Mergen
        $this->batchMode = false;
        $this->saveIndex(); // Index speichern
    }


    // --- Index-Operationen ---
    protected function loadIndex(): void {
        // Keine Notwendigkeit, $enabled oder $useIndex hier erneut zu prüfen, wird im Konstruktor gehandhabt.
        if (@is_readable($this->indexFile)) { // @is_readable statt file_exists+is_readable
            $content = @file_get_contents($this->indexFile);
            if ($content !== false) {
                 $data = json_decode($content, true);
                 // Prüfen, ob Dekodierung erfolgreich war UND das Ergebnis ein Array ist
                 if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $this->index = $data;
                 } else {
                     error_log("Cache: Index-Datei '{$this->indexFile}' ist korrupt oder kein valides JSON-Array. Wird ignoriert.");
                     $this->index = [];
                 }
            } else {
                 error_log("Cache: Konnte Index-Datei nicht lesen: " . $this->indexFile);
                 $this->index = [];
            }
        } else {
            // Datei existiert nicht oder ist nicht lesbar -> leerer Index
            $this->index = [];
        }
    }

    protected function saveIndex(): void {
        // $enabled wird in set/delete/clearGroup geprüft, hier nur $useIndex
        if ($this->useIndex) {
            // Optimierung: Nur speichern, wenn der Index nicht leer ist oder die Datei existiert (um leere Dateien zu löschen)
            // Diese Optimierung kann aber problematisch sein, wenn man explizit einen leeren Index speichern will.
            // Vorerst beibehalten: Immer speichern, wenn useIndex=true.

            $flags = JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION; // Zero fraction kann bei numerischen Werten wichtig sein
            if (defined('DEBUG') && DEBUG === true) {
                $flags |= JSON_PRETTY_PRINT;
            }

            // Sicherstellen, dass $this->index ein Array ist (sollte immer der Fall sein)
            $indexData = is_array($this->index) ? $this->index : [];

            $encoded = json_encode($indexData, $flags);

            if ($encoded === false) {
                 error_log("Cache: Fehler beim JSON-Kodieren des Index (JSON Error: " . json_last_error_msg() . ")");
                 return;
            }

            // Verwende temporäre Datei und atomares Verschieben für mehr Sicherheit
            $tempFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'index_' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tempFile, $encoded, LOCK_EX) !== false) {
                // Setze korrekte Berechtigungen für die Temp-Datei (optional, aber gut)
                @chmod($tempFile, 0644);
                // Versuche, die temporäre Datei atomar umzubenennen
                if (!@rename($tempFile, $this->indexFile)) {
                    // Wenn rename fehlschlägt (z.B. Rechte, Windows-Locking), lösche die Temp-Datei
                    @unlink($tempFile);
                    error_log("Cache: Fehler beim atomaren Umbenennen der Index-Datei: " . $this->indexFile);
                    // Fallback: Direktes Schreiben versuchen (wie vorher)
                    if (@file_put_contents($this->indexFile, $encoded, LOCK_EX) === false) {
                         error_log("Cache: Fallback-Fehler beim Schreiben der Index-Datei: " . $this->indexFile);
                    }
                }
                // Kein else nötig, rename war erfolgreich
            } else {
                // Schon das Schreiben der Temp-Datei schlug fehl
                error_log("Cache: Fehler beim Schreiben der temporären Index-Datei: " . $tempFile);
                // Temp-Datei löschen, falls sie doch (teilweise) existiert
                if (file_exists($tempFile)) {
                    @unlink($tempFile);
                }
            }
        }
    }

    protected function updateIndexForKey(string $key, array $groups): void {
        // $enabled und $useIndex werden in set() geprüft.
        // Entferne leere Gruppennamen
        $validGroups = array_filter($groups, fn($group) => is_string($group) && $group !== '');
        if (empty($validGroups)) return; // Keine gültigen Gruppen zum Aktualisieren

        if ($this->batchMode) {
            foreach ($validGroups as $group) {
                if (!isset($this->batchIndexUpdates[$group])) {
                    $this->batchIndexUpdates[$group] = [];
                }
                // Füge nur hinzu, wenn noch nicht im Batch für diese Gruppe
                if (!in_array($key, $this->batchIndexUpdates[$group], true)) {
                    $this->batchIndexUpdates[$group][] = $key;
                }
            }
        } else {
             $changed = false;
             foreach ($validGroups as $group) {
                if (!isset($this->index[$group])) {
                    $this->index[$group] = [];
                }
                // Füge nur hinzu, wenn noch nicht im Index für diese Gruppe
                if (!in_array($key, $this->index[$group], true)) {
                    $this->index[$group][] = $key;
                    $changed = true;
                }
             }
             // Nur speichern, wenn sich etwas geändert hat
             if ($changed) {
                  $this->saveIndex();
             }
        }
    }

    protected function removeKeyFromIndex(string $key): void {
        // $enabled und $useIndex werden in delete() geprüft.
        $changed = false;
        foreach ($this->index as $group => $keys) {
            // Suche nach dem Key im Array der Gruppe
            $pos = array_search($key, $keys, true);
            if ($pos !== false) {
                // Entferne den Key aus der Gruppe
                unset($this->index[$group][$pos]);
                // Re-indiziere das Array, um Lücken zu vermeiden (wichtig für JSON)
                $this->index[$group] = array_values($this->index[$group]);
                // Wenn die Gruppe dadurch leer wird, entferne die Gruppe selbst
                if (empty($this->index[$group])) {
                    unset($this->index[$group]);
                }
                $changed = true;
            }
        }
        // Speichere den Index nur, wenn Änderungen vorgenommen wurden
        if ($changed) {
             $this->saveIndex();
        }
    }

    protected function getUniqueIndexKeys(): array {
        $keys = [];
        // Stelle sicher, dass $this->index ein Array ist
        if (is_array($this->index)) {
            foreach ($this->index as $groupKeys) {
                 // Stelle sicher, dass $groupKeys ein Array ist
                if (is_array($groupKeys)) {
                     $keys = array_merge($keys, $groupKeys);
                }
            }
        }
        return array_unique($keys);
    }


    // --- MD5-Cache & Key Validation ---
    protected function getCacheFilePath(string $key): string {
        // Kein $this->validateKey($key) hier, wird in den öffentlichen Methoden gemacht.
        // Der Key für den MD5-Cache ist der Original-Key
        if (!isset($this->md5Cache[$key])) {
            $this->md5Cache[$key] = md5($key);
        }
        // Verwende den gecachten Hash für den Dateinamen
        return $this->cacheDir . DIRECTORY_SEPARATOR . $this->md5Cache[$key] . '.cache';
    }

    protected function validateKey(string $key): string {
        if ($key === '') { // Strenger Vergleich mit '' statt empty()
             throw new InvalidArgumentException("Cache-Schlüssel darf nicht leer sein.");
        }
        // Regex: Erlaube keine problematischen Zeichen wie / \ : * ? " < > | oder Null-Bytes
        // \p{Cc} fängt Steuerzeichen ab (inkl. Null-Byte)
        if (preg_match('#[\\\\/:*?"<>|\p{Cc}]#u', $key)) {
             throw new InvalidArgumentException("Ungültiger Cache-Schlüssel: '{$key}'. Enthält ungültige Zeichen.");
        }
        // Optional: Längenbeschränkung? (z.B. max 255 Zeichen)
        // if (strlen($key) > 255) {
        //     throw new InvalidArgumentException("Cache-Schlüssel ist zu lang (max 255 Zeichen): '{$key}'.");
        // }
        return $key;
    }


    // --- Cache-Statistiken & TTL Mapping ---
    public function getStatistics(): array {
        // Rundung für Lesbarkeit
        $hitRate = $this->totalRequests > 0 ? round($this->cacheHits / $this->totalRequests, 4) : 0.0;
        $avgAccessTime = $this->totalRequests > 0 ? round($this->totalAccessTime / $this->totalRequests, 6) : 0.0;
        return [
            'enabled'        => $this->enabled,
            'use_index'      => $this->useIndex,
            'total_requests' => $this->totalRequests,
            'cache_hits'     => $this->cacheHits,
            'memory_hits'    => $this->cacheHits, // Aktuell sind alle Hits Memory Hits oder File Hits, die zu Memory Hits werden
            'hit_rate'       => $hitRate,
            'avg_access_time'=> $avgAccessTime, // in Sekunden
            'cache_dir_size' => $this->getCacheSize(), // Füge Größe hinzu
            'cache_files'    => $this->getCacheFileCount(), // Füge Anzahl hinzu
            'index_size'     => $this->useIndex && file_exists($this->indexFile) ? @filesize($this->indexFile) : 0,
        ];
    }

    public function setDefaultTtlMapping(array $mapping): void {
        // Überschreibe nur, wenn das Mapping gültig ist
        // Hier könnte man prüfen, ob die Werte Integer sind etc.
        $this->defaultTtlMapping = $mapping;
    }


    // --- Öffentliche API ---

    public function get(string $key): mixed {
        // 1. Validieren & Zeitmessung starten
        $this->validateKey($key);
        $startTime = microtime(true);
        $result = null;
        $hit = false;

        // 2. Prüfen ob aktiviert
        if (!$this->enabled) {
            $this->totalRequests++;
            $this->totalAccessTime += microtime(true) - $startTime;
            return null;
        }

        // 3. Memory Cache prüfen (L1)
        if (array_key_exists($key, $this->memoryCache)) { // array_key_exists ist genauer für null-Werte
            $result = $this->memoryCache[$key];
            $this->cacheHits++;
            $hit = true;
        } else {
            // 4. File Cache prüfen (L2)
            $file = $this->getCacheFilePath($key);
            if (@is_readable($file)) { // @is_readable prüft Existenz und Lesbarkeit
                $content = @file_get_contents($file);
                if ($content !== false) {
                    $data = json_decode($content, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data) && isset($data['expire'], $data['content'])) {
                        // 5. Gültigkeit prüfen
                        if (time() <= $data['expire']) {
                            $result = $data['content'];
                            $this->memoryCache[$key] = $result; // In L1 Cache laden
                            $this->cacheHits++;
                            $hit = true;
                        } else {
                            // 6. Abgelaufen -> Löschen
                            $this->delete($key); // Ruft intern removeKeyFromIndex etc. auf
                        }
                    } else {
                        // 7. Korrupte Datei -> Löschen
                        error_log("Cache: Korrupte Cache-Datei gefunden: " . $file . " (JSON Error: " . json_last_error_msg() . ")");
                        $this->delete($key);
                    }
                } else {
                    error_log("Cache: Konnte Cache-Datei nicht lesen (trotz is_readable): " . $file);
                    // Nicht löschen, könnte temporäres Problem sein
                }
            }
        }

        // 8. Statistiken aktualisieren
        $this->totalRequests++;
        $this->totalAccessTime += microtime(true) - $startTime;

        return $result;
    }

    public function set(string $key, mixed $content, ?int $ttl = null, array $groups = []): bool { // Rückgabetyp bool hinzugefügt
        // 1. Validieren
        $this->validateKey($key);

        // 2. Prüfen ob aktiviert
        if (!$this->enabled) {
            return false; // Nicht erfolgreich, da deaktiviert
        }

        // 3. TTL bestimmen
        $effectiveTtl = $this->resolveTtl($key, $ttl);

        // 4. Gruppen bestimmen (inkl. Prefixes)
        $effectiveGroups = $this->resolveGroups($key, $groups);

        // 5. Daten vorbereiten
        $file = $this->getCacheFilePath($key);
        $data = [
            'expire'  => time() + $effectiveTtl,
            'content' => $content,
            'groups'  => $effectiveGroups, // Bereits bereinigt in resolveGroups
        ];

        // 6. JSON kodieren
        $jsonEncoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($jsonEncoded === false) {
             error_log("Cache: Fehler beim JSON-Kodieren für Key '{$key}' (JSON Error: " . json_last_error_msg() . ")");
             return false; // Nicht erfolgreich
        }

        // 7. Datei schreiben (mit @ und Fehlerprüfung)
        //    Verwende die atomare Schreibweise mit temporärer Datei für die Daten-Datei
        $tempFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'data_' . $this->md5Cache[$key] . '_' . bin2hex(random_bytes(4)) . '.tmp';
        $writeSuccess = false;
        if (@file_put_contents($tempFile, $jsonEncoded, LOCK_EX) !== false) {
             @chmod($tempFile, 0644); // Setze Berechtigungen
             if (@rename($tempFile, $file)) {
                  $writeSuccess = true; // Erfolgreich geschrieben und umbenannt
             } else {
                  @unlink($tempFile); // Umbenennen fehlgeschlagen, lösche Temp-Datei
                  error_log("Cache: Fehler beim atomaren Umbenennen der Cache-Datei: {$file}");
                  // Fallback: Direktes Schreiben (weniger sicher)
                  if (@file_put_contents($file, $jsonEncoded, LOCK_EX) !== false) {
                      $writeSuccess = true;
                  } else {
                      error_log("Cache: Fallback-Fehler beim Schreiben der Cache-Datei: " . $file);
                  }
             }
        } else {
             error_log("Cache: Fehler beim Schreiben der temporären Cache-Datei: " . $tempFile);
             if (file_exists($tempFile)) @unlink($tempFile);
             // Prüfen, ob Verzeichnis beschreibbar ist
             if (!is_writable($this->cacheDir)) {
                  error_log("Cache FEHLER: Cache-Verzeichnis '{$this->cacheDir}' ist nicht mehr beschreibbar!");
                  $this->enabled = false; // Deaktiviere Cache als Vorsichtsmaßnahme
                  // Hier keine Exception werfen, um den Programmfluss nicht zwingend zu unterbrechen, aber false zurückgeben
             }
        }


        // 8. Updates nur bei Erfolg
        if ($writeSuccess) {
            // Memory Cache aktualisieren
            $this->memoryCache[$key] = $content;

            // OPcache invalidieren (falls verwendet)
            if ($this->useOpcache) {
                // opcache_invalidate() sollte nur aufgerufen werden, wenn die Datei existiert
                @opcache_invalidate($file, true);
            }

            // Index aktualisieren (falls verwendet und Gruppen vorhanden)
            if ($this->useIndex && !empty($effectiveGroups)) {
                $this->updateIndexForKey($key, $effectiveGroups);
            }
            return true; // Erfolgreich
        } else {
            // Wenn das Schreiben fehlschlug, sollte der Eintrag auch nicht im Memory Cache sein
            unset($this->memoryCache[$key]);
            return false; // Nicht erfolgreich
        }
    }

    public function delete(string $key): bool { // Rückgabetyp bool hinzugefügt
        $this->validateKey($key);

        // Wenn deaktiviert, passiert nichts (aber erfolgreich im Sinne von "nicht vorhanden")
        if (!$this->enabled) return true;

        $file = $this->getCacheFilePath($key);
        $fileExists = file_exists($file); // Prüfen, bevor wir versuchen zu löschen

        // 1. Aus Memory Cache entfernen
        unset($this->memoryCache[$key]);

        // 2. Aus MD5 Cache entfernen
        unset($this->md5Cache[$key]);

        // 3. Aus Index entfernen (falls verwendet)
        if ($this->useIndex) {
            $this->removeKeyFromIndex($key); // Kümmert sich ums Speichern des Index
        }

        // 4. Datei löschen (nur wenn sie existiert)
        $unlinkSuccess = true;
        if ($fileExists) {
             if (!@unlink($file)) {
                  error_log("Cache: Konnte Cache-Datei nicht löschen: " . $file);
                  $unlinkSuccess = false; // Löschen fehlgeschlagen
             }
        }

        // Gibt true zurück, wenn die Datei entweder nicht existierte oder erfolgreich gelöscht wurde.
        return $unlinkSuccess;
    }

    public function clear(): bool { // Rückgabetyp bool hinzugefügt
        if (!$this->enabled) return true; // Nichts zu tun

        $errorsOccurred = false;

        // 1. Cache-Dateien löschen
        $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
        if ($files !== false) {
            foreach ($files as $file) {
                if (!@unlink($file)) {
                    error_log("Cache: Konnte Datei beim Leeren nicht löschen: " . $file);
                    $errorsOccurred = true;
                }
            }
        }

        // 2. Index-Datei löschen (falls verwendet)
        if ($this->useIndex && file_exists($this->indexFile)) {
            if (!@unlink($this->indexFile)) {
                 error_log("Cache: Konnte Index-Datei beim Leeren nicht löschen: " . $this->indexFile);
                 $errorsOccurred = true;
            }
        }
        // Temporäre Dateien auch löschen (Index und Daten)
        $tempFiles = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.tmp');
         if ($tempFiles !== false) {
            foreach ($tempFiles as $tempFile) {
                if (!@unlink($tempFile)) {
                    error_log("Cache: Konnte temporäre Datei beim Leeren nicht löschen: " . $tempFile);
                    $errorsOccurred = true;
                }
            }
        }


        // 3. Interne Caches leeren
        $this->memoryCache = [];
        $this->md5Cache = [];
        $this->index = []; // Index-Array auch leeren

        // Rückgabe, ob Fehler aufgetreten sind
        return !$errorsOccurred;
    }

    public function clearGroup(string $group): bool { // Rückgabetyp bool hinzugefügt
        $this->validateKey($group); // Gruppen können auch validiert werden
        if (empty($group) || !$this->enabled) {
            return true; // Nichts zu tun oder deaktiviert
        }

        $errorsOccurred = false;

        if ($this->useIndex) {
            // Index verwenden (effizient)
            if (isset($this->index[$group]) && is_array($this->index[$group])) {
                $keysToDelete = $this->index[$group]; // Kopie erstellen
                foreach ($keysToDelete as $key) {
                    // delete() gibt bool zurück, ob erfolgreich
                    if (!$this->delete($key)) {
                        $errorsOccurred = true;
                    }
                }
                // Gruppe sollte nun leer sein und wird durch delete->removeKeyFromIndex entfernt.
                // Kein separates saveIndex nötig.
            }
        } else {
            // Fallback ohne Index (ineffizient)
            error_log("Cache WARNUNG: clearGroup('{$group}') ohne Index ist ineffizient.");
            $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
            if ($files !== false) {
                foreach ($files as $file) {
                    $content = @file_get_contents($file);
                    if ($content === false) continue;
                    $data = json_decode($content, true);
                    // Prüfe auf gültiges JSON und ob die Gruppe im 'groups'-Array enthalten ist
                    if (json_last_error() === JSON_ERROR_NONE && is_array($data) && isset($data['groups']) && is_array($data['groups']) && in_array($group, $data['groups'], true)) {
                         // Versuche zu löschen
                        if (!@unlink($file)) {
                             error_log("Cache WARNUNG: Konnte Cache-Datei im Fallback clearGroup nicht löschen: " . $file);
                             $errorsOccurred = true;
                        }
                        // Memory-Cache Eintrag kann hier nicht gezielt gelöscht werden.
                    }
                }
            }
        }
        return !$errorsOccurred;
    }

    public function bustUrl(string $url): string {
        // Diese Methode bleibt strukturell gleich, nutzt PathResolver für relative Pfade.
        // Kleine Optimierung: parse_url nur einmal aufrufen.
        $trimmedUrl = trim($url);
        if ($trimmedUrl === '') return '';

        $parts = parse_url($trimmedUrl);

        // Nur für lokale Pfade ohne Schema oder mit file:// Schema
        // Und nur wenn ein Pfad vorhanden ist
        if (!isset($parts['path']) || (isset($parts['scheme']) && !in_array($parts['scheme'], ['file', null], true))) {
             return $trimmedUrl; // Externe URL, kein Pfad, oder unbekanntes Schema
        }

        $path = $parts['path'];
        $fullPath = $path; // Annahme: Ist bereits absolut oder wird aufgelöst

        // Prüfen ob Pfad relativ ist (beginnt NICHT mit / oder X:)
        if (!preg_match('#^(/|[a-zA-Z]:[/\\\\])#', $path)) {
             if (isset($_SERVER['DOCUMENT_ROOT']) && is_dir($_SERVER['DOCUMENT_ROOT'])) {
                 $base = $_SERVER['DOCUMENT_ROOT'];
                 try {
                      // Verwende PathResolver::resolve zur sicheren Auflösung
                      $fullPath = PathResolver::resolve($base, $path);
                 } catch (RuntimeException $e) {
                       error_log("Cache::bustUrl - Konnte relativen Pfad nicht auflösen: {$path} (Base: {$base}) - " . $e->getMessage());
                       return $trimmedUrl; // Unverändert zurückgeben bei Fehler
                 }
             } else {
                  // Kein Document Root oder ungültig, kann relativen Pfad nicht auflösen
                  return $trimmedUrl;
             }
        }

        // Zeitstempel holen (@ unterdrückt Fehler, falls Datei nicht existiert oder Rechte fehlen)
        $mtime = @filemtime($fullPath);

        if ($mtime !== false) {
            // Query-String aufbauen
            $query = $parts['query'] ?? '';
            parse_str($query, $queryParams); // Bestehende Query-Parameter parsen
            $queryParams['v'] = $mtime; // Cache-Buster hinzufügen oder überschreiben
            $newQuery = http_build_query($queryParams); // Neuen Query-String bauen

            // URL zusammensetzen
            $newUrl = '';
            if (isset($parts['scheme'])) $newUrl .= $parts['scheme'] . ':';
            if (isset($parts['host'])) $newUrl .= '//' . $parts['host'];
            if (isset($parts['port'])) $newUrl .= ':' . $parts['port'];
            $newUrl .= $parts['path']; // Originalpfad beibehalten
            if ($newQuery !== '') $newUrl .= '?' . $newQuery;
            if (isset($parts['fragment'])) $newUrl .= '#' . $parts['fragment'];

            return $newUrl;
        }

        // Wenn Zeitstempel nicht geholt werden konnte, Original-URL zurückgeben
        return $trimmedUrl;
    }


    // --- Statistik-Hilfsfunktionen ---
    public function getCacheFileCount(): int {
        if (!$this->enabled) return 0;

        if ($this->useIndex) {
            // Zählt die eindeutigen Schlüssel im Index
            return count($this->getUniqueIndexKeys());
        } else {
            // Fallback: Zählt die *.cache Dateien im Verzeichnis
            $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
            return $files === false ? 0 : count($files);
        }
    }

    public function getCacheSize(): int {
         if (!$this->enabled) return 0;
        $size = 0;
        if ($this->useIndex) {
            $uniqueKeys = $this->getUniqueIndexKeys();
            foreach ($uniqueKeys as $key) {
                $file = $this->getCacheFilePath($key);
                // Prüfe Existenz vor filesize
                if (@is_file($file)) { // is_file ist genauer als file_exists
                    $fileSize = @filesize($file);
                    if ($fileSize !== false) {
                         $size += $fileSize;
                    }
                }
            }
        } else {
            $files = glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.cache');
            if ($files !== false) {
                foreach ($files as $file) {
                    // is_file hier nicht nötig, da glob nur Dateien liefern sollte
                    $fileSize = @filesize($file);
                     if ($fileSize !== false) {
                         $size += $fileSize;
                    }
                }
            }
        }
        return $size;
    }

    // --- Private Hilfsfunktionen für TTL und Gruppen ---

    /**
     * Ermittelt die effektive TTL basierend auf Key-Prefix und Standardwerten.
     */
    private function resolveTtl(string $key, ?int $ttl): int {
        if ($ttl !== null) {
            return max(0, $ttl); // Direkte Angabe hat Vorrang (>= 0)
        }

        foreach ($this->defaultTtlMapping as $prefix => $defaultTtl) {
            if ($prefix !== 'default' && str_starts_with($key, $prefix)) { // str_starts_with ist moderner
                return max(0, $defaultTtl); // Prefix gefunden
            }
        }

        // Kein Prefix gematcht, Fallback auf 'default' oder die Klassenkonstante
        return max(0, $this->defaultTtlMapping['default'] ?? self::DEFAULT_TTL);
    }

     /**
     * Bereinigt und ergänzt Gruppen basierend auf Key-Prefix.
     */
    private function resolveGroups(string $key, array $groups): array {
         $effectiveGroups = [];
         // Bereinige initiale Gruppen (nur nicht-leere Strings)
         foreach ($groups as $group) {
             if (is_string($group) && $group !== '') {
                 $effectiveGroups[] = $group;
             }
         }

         // Füge Gruppen basierend auf Prefixes hinzu
         if (str_starts_with($key, 'template_')) {
             $effectiveGroups[] = 'templates';
         } elseif (str_starts_with($key, 'asset_')) {
             $effectiveGroups[] = 'assets';
         }
         // Weitere Prefixes hier hinzufügen...

         // Eindeutig machen und numerisch indizieren
         return array_values(array_unique($effectiveGroups));
     }
}