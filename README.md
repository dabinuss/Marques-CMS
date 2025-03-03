# Marces CMS

Willkommen zum **Marces CMS** – einem modularen, flexiblen und dateibasierten (Flat File) Content-Management-System, das in mehreren Entwicklungsphasen entsteht. 🎉

## Überblick

Marces CMS ist darauf ausgelegt, die Basis für ein modernes, benutzerfreundliches CMS zu legen – und das ganz ohne herkömmliche Datenbank! Mit einer sauberen Architektur und einem modularen Aufbau wollen wir die Erstellung und Verwaltung von Inhalten so angenehm wie möglich gestalten. Die Entwicklung erfolgt in klar definierten Phasen, die jeweils wichtige Kernfunktionen und erweiterte Features hinzufügen.

## Anforderungen

Marces benötigt keinerlei Datenbanken, Pakete oder Serverumgebungen. Es funktioniert auf einfachen Webhosting Services. Deshalb gibt es auch keinerlei Anforderungen.
- **Keine Anforderungen** Außer Webspace

## Installation

Das CMS befindet sich noch in Entwicklung. Zum Testen das Projekt unter /marces/ einfach hochladen und im Browser öffnen. Unter /config/users.config.php/ kann man Nutzer anlegen sowie ändern. Beachte das Passwörter via PHP als Passwort Hash gespeichert werden müssen.

## Entwicklungsphasen

### Phase 1: Grundstruktur und Kern

In dieser Phase wurden die Basisarchitektur und grundlegenden Funktionen implementiert:

- **Projektstruktur:** Einrichtung von Ordnern und Dateien
- **Kernmodule:** 
  - **Router:** Für die Verarbeitung von URLs
  - **Content-Parser:** Für die Verarbeitung von Markdown-Inhalten
  - **Template-Engine:** Zur Darstellung der Inhalte
- **Konfigurationsdateien:** Erstellung und Verwaltung der Systemeinstellungen
- **Templates und Partials:** Entwicklung wiederverwendbarer Template-Komponenten
- **Assets:** Aufbau von CSS/JS-Ressourcen
- **Beispielinhalte:** Erste statische Inhalte zur Demonstration
- **Admin-Bereich:** Grundlegende Struktur für administrative Aufgaben

### Phase 2: Plan und Komponenten

In dieser Phase kommen erweiterte Funktionalitäten hinzu, die Interaktivität und Sicherheit verbessern:

- **Sichere Authentifizierung:** Login-System mit Passwort-Hashing, Session-Management & Zugriffskontrollen
- **Admin-Dashboard:** Übersichtliche Startseite, Navigation zu allen Verwaltungsbereichen
- **Content-Management:** Erstellen, Bearbeiten und Löschen von Seiten, Verwaltung von Blog-Beiträgen, Versionsmanagement für Inhalte
- **TinyMCE-Integration:** WYSIWYG-Editor tinyMCE für einfache Inhaltserstellung, Markdown-Unterstützung
- **Medienverwaltung:** Medien-Upload, Medienbibliothek, Integration in den Editor

### Phase 3: Erweiterung

In der dritten Phase planen wir, das CMS weiter zu verfeinern und zusätzliche Features zu integrieren:

- **Erweiterte Funktionen:** Tags, Kategorien, etc.
- **Caching-System:** Verbesserung der Performance
- **SEO-Funktionen:** Optimierung für Suchmaschinen
- **Benutzerrollen und -berechtigungen:** Fein abgestimmte Zugriffskontrolle
- **Navigationsverwaltung:** Verwalten von Links in der Navigation

### Phase 4: Optimierung

- **Fehlerbehebungen:** Große Fehlersuche
- **Leistungsoptimierung:** Effizienteres Systemverhalten

## Feature-Übersicht

| Entwicklungsphase | Feature                                                         | Status            |
|-------------------|-----------------------------------------------------------------|-------------------|
| **Phase 1**       | Projektstruktur: Einrichtung von Ordnern und Dateien              | ✅ Fertig         |
| **Phase 1**       | Kernmodul: Router (URL-Verarbeitung)                              | ✅ Fertig         |
| **Phase 1**       | Kernmodul: Content-Parser (Markdown-Verarbeitung)                 | ✅ Fertig         |
| **Phase 1**       | Kernmodul: Template-Engine (Darstellung)                          | ✅ Fertig         |
| **Phase 1**       | Konfigurationsdateien                                             | ✅ Fertig         |
| **Phase 1**       | Templates und Partials                                            | ✅ Fertig         |
| **Phase 1**       | CSS/JS-Assets                                                     | ✅ Fertig         |
| **Phase 1**       | Beispielinhalte                                                   | ✅ Fertig         |
| **Phase 1**       | Grundlegende Admin-Bereich-Struktur                               | ✅ Fertig         |
| **Phase 2**       | Sichere Authentifizierung: Login-System mit Passwort-Hashing        | ✅ Fertig        |
| **Phase 2**       | Sichere Authentifizierung: Session-Management                      | ✅ Fertig         |
| **Phase 2**       | Sichere Authentifizierung: Zugriffskontrollen                      | ✅ Fertig |
| **Phase 2**       | Admin-Dashboard: Übersichtliche Startseite                         | ✅ Fertig         |
| **Phase 2**       | Admin-Dashboard: Navigation zu allen Verwaltungsbereichen          | ✅ Fertig         |
| **Phase 2**       | Content-Management: Seiten erstellen, bearbeiten, löschen          | ✅ Fertig         |
| **Phase 2**       | Content-Management: Blog-Beiträge verwalten                        | ✅ Fertig |
| **Phase 2**       | Content-Management: Versionsmanagement für Inhalte                 | ✅ Fertig         |
| **Phase 2**       | TinyMCE-Integration: WYSIWYG-Editor (TINYMCE)                      | ✅ Fertig         |
| **Phase 2**       | TinyMCE-Integration: Markdown-Unterstützung                        | ✅ Fertig         |
| **Phase 2**       | Medienverwaltung: Medien-Upload                                    | ✅ Fertig         |
| **Phase 2**       | Medienverwaltung: Medienbibliothek                                 | ✅ Fertig         |
| **Phase 2**       | Medienverwaltung: Integration in den Editor                        | ✅ Fertig         |
| **Phase 2**       | Erweiterte Funktionen (z.B. Tags, Kategorien)                        | 🔄 In Bearbeitung     |
| **Phase 3**       | Navigationsverwaltung                                               | ❌ Noch nicht     |
| **Phase 3**       | Systemsettings des CMS                                              | 🔄 In Bearbeitung     |
| **Phase 3**       | Systemsettings des CMS: Einstellbare Blog URLs                      | ✅ Fertig     |
| **Phase 3**       | Caching-System                                                     | ❌ Noch nicht     |
| **Phase 3**       | SEO-Funktionen                                                     | ❌ Noch nicht     |
| **Phase 3**       | Benutzerrollen und -berechtigungen                                 | 🔄 In Bearbeitung     |
| **Phase 4**       | Große Fehlersuche und Korrektur                                    | ❌ Noch nicht     |
| **Phase 4**       | Leistungsoptimierung                                               | ❌ Noch nicht     |

## Mitmachen und Feedback

Wir freuen uns über Beiträge, Anregungen und konstruktives Feedback! Wenn du Ideen hast, wie wir Marces CMS noch besser machen können, oder wenn du einfach mal über die Technik plaudern möchtest – zögere nicht, dich einzubringen. 😊

## Lizenz

Dieses Projekt ist Open Source. Details zur Lizenz findest du in der [LICENSE](LICENSE)-Datei.

---

Viel Spaß beim Erkunden und Mitentwickeln von Marces CMS! Was findest du besonders spannend an einem modularen und dateibasierten CMS? 🤔💬
