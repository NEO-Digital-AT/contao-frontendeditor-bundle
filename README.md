# NEO Frontend Editor for Contao 5

Edit content directly on the page, in Contao's own front end preview. Headlines,
texts and images are changed where they are shown; every other field opens in
Contao's regular form in a sidebar next to the page. Contao's permissions decide
what each user may do, and every change is saved as a Contao version.

*Deutsche Fassung weiter unten: [Deutsch](#neo-frontend-editor-für-contao-5-deutsch).*

## Features

- Only in Contao's preview, opened with **Frontend Editor** at the top of the
  back end or switched on with **Edit page** in the preview; visitors get the
  page unchanged.
- Every text of the page edited where it is shown and saved into the field it
  comes from: headlines, texts, images, the other text fields of elements and
  front-end modules, rows of lists, cells of tables, records of an element's
  own child table and records it shows from another table through a relation
  of the data container (a FAQ category with its questions, an entry of a
  catalogue), also of other extensions (a source map finds each value,
  also when the template changes it); a click on a text that cannot be edited
  there says why. Contao's form of every element in the sidebar, docked next to
  the page so that nothing covers it.
- Insert, move, duplicate, hide and delete elements, also inside accordions,
  sliders and element groups; create articles.
- For web designers: the templates of every element, front-end modules, layout,
  form fields and comments with their forms.
- **Plus:** pages, posts (news, events, FAQ), the extensions' modules, language
  versions side by side with translation proposals (Azure Translator, DeepL or
  an AI model, marked as machine translation); posts, archives, page settings
  and Contao's front-end modules translated and linked across languages, new
  posts created in every language at once.
- **Pro:** AI writing aid, SEO and accessibility checks, the page in the width
  of a phone or tablet, own CSS or SCSS per page; the modules of the website and
  of other extensions in the sidebar, their records and front-end modules
  translated and linked like Contao's own.
- Contao's permissions apply to every record and field; the extension's
  switches only narrow them.
- Back end and editor in eleven languages.

Basic is free; Plus and Pro need a licence key. Switches and settings of an
edition the licence does not include are not shown in the back end; their
stored values stay.

## Requirements

- Contao 5.3 to 6.0, PHP 8.2 or later (Contao 5.7 needs PHP 8.3, Contao 6 PHP 8.4).
- Installed automatically: `neo-digital/contao-basic-bundle`, `scssphp/scssphp`.
- Language versions (Plus): `terminal42/contao-changelanguage`.

## Installation

### Contao Manager

Search for `neo-digital/contao-frontendeditor-bundle`, add it, apply the changes
and run the database update.

### Composer

```bash
composer require neo-digital/contao-frontendeditor-bundle
php vendor/bin/contao-console contao:migrate
```

Then allow the editor for a user group: **User groups** → section **Frontend
Editor** → **Front end editing allowed**.

## Documentation

The full documentation comes with the extension: [docs/en/README.md](docs/en/README.md).

- [Installation](docs/en/installation.md) – requirements, updating, removing
- [Setup](docs/en/setup.md) – user groups, licence, translation service, AI
- [Permissions](docs/en/permissions.md) – the permissions each function needs
- [Several websites and languages](docs/en/websites-and-languages.md)
- [User manual](docs/en/usage/README.md) – working with the editor, step by step
- [Troubleshooting](docs/en/troubleshooting.md) – common problems and their causes

## Development

The unit tests (PHPUnit 10.5 or 11, folder `tests/`) cover the permissions (the editor's switches only narrow what Contao's voters and field permissions allow), saving of fields with Contao's own widgets (which fields may be written, plain and rich text, the HTML filter, insert tags kept, markers of the source map never saved), the source map, the palettes, the JSON endpoints (error envelope, request token, deny by default), the translation proposals, the writing aid, the page styles (SCSS and CSS checks), the edit mode (visitors get nothing) and the cross-origin headers. They need neither a database server nor a Contao installation: the database is SQLite in memory with the tables of the DCA files.

```bash
composer install
composer test
```

The CI workflow `.github/workflows/tests.yml` runs the tests on PHP 8.2 to 8.5 (PHP 8.2 with Contao 5.3 and PHPUnit 10.5) and checks `composer.json` with `composer validate`. To test against an existing Contao installation instead, point `NEO_TEST_AUTOLOAD` to its `vendor/autoload.php`.

## License

**Licence keys for Plus and Pro:** <https://store.neo-digital.at/extensions/frontend-editor>

See [LICENSE](LICENSE).

The accessibility check uses [axe-core](https://github.com/dequelabs/axe-core)
4.10.2 by Deque Systems, unchanged, under the Mozilla Public License 2.0
(`public/axe/`, with its licence and its translations).

---

# NEO Frontend Editor für Contao 5 (Deutsch)

Inhalte direkt auf der Seite bearbeiten, in Contaos eigener Vorschau.
Überschriften, Texte und Bilder werden dort geändert, wo sie stehen; alle
anderen Felder öffnen sich im normalen Contao-Formular in einer Seitenleiste
neben der Seite. Contaos Rechte entscheiden, wer was darf, und jede Änderung
wird als Contao-Version gespeichert.

## Funktionen

- Nur in Contaos Vorschau, geöffnet mit **Frontend Editor** oben im Backend
  oder eingeschaltet mit **Seite bearbeiten** in der Vorschau; Besucher
  bekommen die Seite unverändert.
- Jeden Text der Seite dort bearbeiten, wo er steht, gespeichert in dem Feld,
  aus dem er kommt: Überschriften, Texte, Bilder, die weiteren Textfelder von
  Elementen und Frontend-Modulen, Zeilen von Listen, Zellen von Tabellen,
  Datensätze der eigenen Kind-Tabelle eines Elements und Datensätze, die es
  über eine Verknüpfung des Data Containers aus einer anderen Tabelle zeigt
  (eine FAQ-Kategorie mit ihren Fragen, ein Eintrag eines Katalogs), auch anderer
  Erweiterungen (eine Quellenkarte findet jeden Wert, auch wenn das Template
  ihn verändert); ein Klick auf einen Text, der sich dort nicht bearbeiten
  lässt, sagt, warum. Contaos Formular jedes Elements in der Seitenleiste,
  angedockt neben der Seite, sodass nichts sie verdeckt.
- Elemente einfügen, verschieben, duplizieren, ausblenden und löschen, auch in
  Akkordeons, Slidern und Elementgruppen; Artikel anlegen.
- Für Webdesigner: die Templates jedes Elements, Frontend-Module, Layout,
  Formularfelder und Kommentare mit ihren Formularen.
- **Plus:** Seiten, Beiträge (Nachrichten, Events, FAQ), die Module der
  Erweiterungen, Sprachversionen nebeneinander mit Übersetzungsvorschlägen
  (Azure Translator, DeepL oder ein KI-Modell, als maschinelle Übersetzung
  gekennzeichnet); Beiträge, Archive, Seiteneinstellungen und Contaos
  Frontend-Module über die Sprachen übersetzt und verknüpft, neue Beiträge
  gleich in allen Sprachen angelegt.
- **Pro:** KI-Schreibhilfe, Prüfung für Suchmaschinen und Barrierefreiheit,
  die Seite in der Breite eines Handys oder Tablets, eigenes CSS oder SCSS je
  Seite; die Module der Website und anderer Erweiterungen in der Seitenleiste,
  ihre Datensätze und Frontend-Module übersetzt und verknüpft wie die von
  Contao.
- Contaos Rechte gelten für jeden Datensatz und jedes Feld; die Schalter der
  Erweiterung schränken sie nur ein.
- Backend und Editor in elf Sprachen.

Basic ist kostenlos; Plus und Pro brauchen einen Lizenzschlüssel. Schalter und
Einstellungen einer Stufe, die die Lizenz nicht enthält, zeigt das Backend
nicht; ihre gespeicherten Werte bleiben.

## Voraussetzungen

- Contao 5.3 bis 6.0, PHP 8.2 oder neuer (Contao 5.7 braucht PHP 8.3, Contao 6 PHP 8.4).
- Wird automatisch installiert: `neo-digital/contao-basic-bundle`,
  `scssphp/scssphp`.
- Sprachversionen (Plus): `terminal42/contao-changelanguage`.

## Installation

### Contao Manager

Nach `neo-digital/contao-frontendeditor-bundle` suchen, hinzufügen, Änderungen
anwenden und die Datenbank aktualisieren.

### Composer

```bash
composer require neo-digital/contao-frontendeditor-bundle
php vendor/bin/contao-console contao:migrate
```

Danach den Editor für eine Benutzergruppe freigeben: **Benutzergruppen** →
Bereich **Frontend Editor** → **Frontend-Bearbeitung erlaubt**.

## Dokumentation

Die vollständige Dokumentation liegt der Erweiterung bei: [docs/de/README.md](docs/de/README.md).

- [Installation](docs/de/installation.md) – Voraussetzungen, Aktualisieren, Entfernen
- [Einrichtung](docs/de/setup.md) – Benutzergruppen, Lizenz, Übersetzungsdienst, KI
- [Rechte](docs/de/permissions.md) – welche Rechte jede Funktion braucht
- [Mehrere Websites und Sprachen](docs/de/websites-and-languages.md)
- [Bedienungsanleitung](docs/de/usage/README.md) – Schritt für Schritt
- [Fehlersuche](docs/de/troubleshooting.md) – häufige Fehler und ihre Ursachen

## Entwicklung

Die Unit-Tests (PHPUnit 10.5 oder 11, Ordner `tests/`) prüfen die Rechte (die Schalter des Editors schränken nur ein, was Contaos Voter und Feldrechte erlauben), das Speichern von Feldern mit Contaos eigenen Widgets (welche Felder geschrieben werden dürfen, Text und formatierter Text, der HTML-Filter, Insert-Tags bleiben erhalten, Markierungen der Quellenzuordnung werden nie gespeichert), die Quellenzuordnung, die Paletten, die JSON-Endpunkte (Fehlerhülle, Anfrage-Token, ohne Recht nichts), die Übersetzungsvorschläge, die Schreibhilfe, den Stil der Seite (Prüfung von SCSS und CSS), den Bearbeitungsmodus (Besucher bekommen nichts) und die Header für andere Adressen. Sie brauchen weder einen Datenbankserver noch eine Contao-Installation: Die Datenbank ist SQLite im Arbeitsspeicher mit den Tabellen aus den DCA-Dateien.

```bash
composer install
composer test
```

Der CI-Ablauf `.github/workflows/tests.yml` führt die Tests unter PHP 8.2 bis 8.5 aus (PHP 8.2 mit Contao 5.3 und PHPUnit 10.5) und prüft `composer.json` mit `composer validate`. Gegen eine bestehende Contao-Installation testen: `NEO_TEST_AUTOLOAD` auf deren `vendor/autoload.php` setzen.

## Lizenz

**Lizenzschlüssel für Plus und Pro:** <https://store.neo-digital.at/extensions/frontend-editor>

Siehe [LICENSE](LICENSE).

Die Barrierefreiheits-Prüfung nutzt [axe-core](https://github.com/dequelabs/axe-core)
4.10.2 von Deque Systems, unverändert, unter der Mozilla Public License 2.0
(`public/axe/`, mit Lizenz und Übersetzungen).
