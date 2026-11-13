---
titel: Installation
kurzfassung: Voraussetzungen, Installation mit dem Contao Manager oder mit Composer, Aktualisieren und Entfernen des NEO Frontend Editors.
zielgruppe: betrieb
sprache: de
stand: 2026-11-12
---

# Installation

## Voraussetzungen

| Was | Gebraucht für |
| --- | --- |
| Contao 5.3 bis 6.0 (getestet mit 5.3, 5.4, 5.5, 5.6, 5.7 und 6.0) | alles |
| PHP 8.2 oder neuer (Contao 5.7: PHP 8.3, Contao 6: PHP 8.4 oder neuer) | alles |
| `neo-digital/contao-basic-bundle` | alles; wird automatisch installiert. Es enthält die Seite *Zugänge und Lizenzen*, die sich die Erweiterungen der Familie teilen. |
| `scssphp/scssphp` | Stil der Seite (Pro); wird automatisch installiert |
| `terminal42/contao-changelanguage` | Sprachversionen (Plus); selbst installieren, wenn die Website mehrere Sprachen hat |
| Ein Übersetzungsdienst: eine Azure-Translator-Ressource (Schlüssel, Region), ein Konto für die DeepL API (Free oder Pro) oder die KI-Verbindung | Übersetzungsvorschläge (Plus) |
| Eine KI-Verbindung (Route und Schlüssel) unter *Zugänge und Lizenzen* | Schreibhilfe (Pro) |
| Contaos Kommentar-Bundle | Kommentare auf der Seite bearbeiten (nur, wenn die Website Kommentare nutzt) |

Der Editor läuft in Contaos Frontend-Vorschau. Auf dem Server muss sonst nichts
eingerichtet werden.

## Installation mit dem Contao Manager

1. Den Contao Manager öffnen.
2. Nach `neo-digital/contao-frontendeditor-bundle` suchen.
3. Auf **Hinzufügen** und danach auf **Änderungen anwenden** klicken.
4. Wenn der Contao Manager danach fragt, die Datenbank aktualisieren
   (**Datenbank aktualisieren**) und alle vorgeschlagenen Änderungen ausführen.

## Installation mit Composer

```bash
composer require neo-digital/contao-frontendeditor-bundle
php vendor/bin/contao-console contao:migrate
```

`contao:migrate` legt die Tabellen der Erweiterung an. Composer kopiert
außerdem die öffentlichen Dateien (Skript, Stile, die Barrierefreiheits-Prüfung)
nach `public/bundles/`. Ist das nicht geschehen, diesen Befehl ausführen:

```bash
php vendor/bin/contao-console assets:install
```

## Nach der Installation

Der Editor ist noch für niemanden sichtbar. Weiter mit der
[Einrichtung](setup.md): Eine Benutzergruppe braucht zuerst den Schalter
**Frontend-Bearbeitung erlaubt**.

## Aktualisieren

1. Das Paket mit dem Contao Manager aktualisieren oder mit
   `composer update neo-digital/contao-frontendeditor-bundle`.
2. Die Datenbank aktualisieren (`contao:migrate`). Aktualisierungen fügen nur
   Tabellen und Felder hinzu; nichts, was eingestellt wurde, wird entfernt.
3. `CHANGELOG.md` der neuen Version lesen. Braucht eine Version neue Rechte,
   steht das dort.

## Entfernen

1. Das Paket mit dem Contao Manager entfernen oder mit
   `composer remove neo-digital/contao-frontendeditor-bundle`.
2. Die Datenbank aktualisieren. Contao bietet dann an, die Tabellen der
   Erweiterung zu löschen (Verknüpfungen zwischen Sprachversionen,
   Fokus-Suchbegriffe, Stile der Seiten, Einstellungen). Nur löschen, wenn die
   Erweiterung nicht wieder installiert werden soll.

Inhalte, die mit dem Editor geändert wurden, bleiben erhalten, weil sie in
Contaos eigenen Feldern gespeichert sind. Stile der Seiten (Pro) werden nach dem
Entfernen der Erweiterung nicht mehr ausgeliefert.
