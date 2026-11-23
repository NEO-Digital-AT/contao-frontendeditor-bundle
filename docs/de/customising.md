---
titel: Anpassen
kurzfassung: Farben, Größen und Texte des Editors.
zielgruppe: entwickler
sprache: de
stand: 2026-11-23
---

# Anpassen

Der Editor übernimmt sein Aussehen aus Contaos Backend: Knöpfe, Icons, Farben
und Schriften kommen aus den Backend-Stylesheets, einschließlich eines
Backend-Skins, der über `contao.backend.custom_css` eingebunden ist, hell und
dunkel.

Besucher bekommen nie etwas vom Editor; nichts auf dieser Seite ändert die
Website für sie.

## Farben und Größen

Im Bearbeiten-Modus zeigt der Editor eine eigene Ansicht: Contaos
Vorschauleiste, die Seitenleiste und daneben die Seite in einem Rahmen. Die Seite
im Rahmen ist die Seite, wie Contao sie ausgibt, mit ihrem Layout und ihren
Stylesheets; der Editor fügt dort seine Rahmen, Typen und Knöpfe in einem Element
`nfe-editor` hinzu. Die Ansicht selbst hat keine Stylesheets der Website.

Einzelne Werte lassen sich mit CSS-Variablen am Element `nfe-editor` ändern, in
einem Stylesheet des Layouts der Website. Sie gelten für das, was der Editor in
der Seite zeigt; die Ansicht (Seitenleiste, Menüs, Karten) übernimmt beim Laden
der Seite die Werte, die von ihren eigenen abweichen:

```css
nfe-editor {
    --nfe-accent: #0b5cad;
    --nfe-panel-width: 420px;
}
```

| Variable | Was sie festlegt | Vorgabe |
| --- | --- | --- |
| `--nfe-accent` | Rahmen, gewählte Zeilen, Fokus | Contaos Akzentfarbe (`--contao`) |
| `--nfe-surface` | Hintergrund von Seitenleiste, Karten und Menüs | `--content-bg` |
| `--nfe-border` | Linien und Ränder | `--content-border` |
| `--nfe-text` | Text | `--text` |
| `--nfe-muted` | Nebentext | `--gray` |
| `--nfe-error` | Fehler, „Bearbeiten nicht freigegeben“ | `--red` |
| `--nfe-hover` | Zeilen unter der Maus | `--hover-row` |
| `--nfe-selected`, `--nfe-selected-border` | Gewählte Zeile | `--active-bg`, `--active-border` |
| `--nfe-radius` | Abgerundete Ecken | `--border-radius` |
| `--nfe-frame-width` | Linienstärke des Rahmens und des Namensschilds am Element | `2px` |
| `--nfe-panel-width` | Breite der Seitenleiste (der Benutzer kann sie trotzdem ziehen) | 360px |
| `--nfe-form-width` | Breite der Seitenleiste mit Contaos Formularen | 480px |

Der Editor folgt dem Aussehen des Backends: ohne Skin sieht er aus wie Contaos
Backend, mit einem Backend-Skin übernimmt er dessen Farben. Die Hauptaktion
einer Zeile (**Speichern**, **Übernehmen**, **Anlegen** …) steht rechts außen,
die weniger wichtigen Knöpfe davor. Contao zeichnet seine eigene Hauptaktion
(den ersten Knopf eines Formulars) wie jeden anderen Knopf; der Editor zeichnet
seine Hauptaktion stattdessen in Contaos Orange (`--nfe-primary`). Ein Skin, der
die Hauptaktion des Backends einfärbt (etwa mit weißer Schrift auf seiner
Akzentfarbe), gibt der Hauptaktion des Editors dieselben Farben
(`--nfe-main-*`). Der offene Reiter eines Elements (sein Typ bei offenem Menü)
ist mit `--nfe-accent` gefüllt und so beschriftet, wie Contao auf seinem Orange
schreibt: weiß im hellen Farbschema, schwarz im dunklen (`--white`).

| Variable | Was sie festlegt | Vorgabe |
| --- | --- | --- |
| `--nfe-on-accent` | Text auf der Akzentfarbe (offener Reiter) | `--white` |
| `--nfe-main-bg`, `--nfe-main-text`, `--nfe-main-border` | Hauptaktion einer Zeile; setzt der Editor nur, wenn der Skin die Hauptaktion des Backends einfärbt | die Knöpfe des Backends |
| `--nfe-primary`, `--nfe-primary-hover`, `--nfe-primary-text` | Hauptaktion einer Zeile, wo das Backend alle Knöpfe gleich zeichnet (Contao ohne Skin) | `#b35400`, `#9a4800`, `#fff` (Contaos Orange, dunkler für lesbare weiße Schrift) |

Den Kontrast der eigenen Farben prüfen (Text mindestens 4,5:1). Contaos eigene
weiße Schrift auf seinem Orange erreicht etwa 2,7:1; der Editor übernimmt sie,
damit er aussieht wie das Backend.

## Texte

Die Texte des Editors sind Symfony-Übersetzungen in
`translations/neo_frontend_editor.<sprache>.yaml` (elf Sprachen). Einzelne
Texte lassen sich wie jede Symfony-Übersetzung überschreiben, zum Beispiel in
`translations/neo_frontend_editor.de.yaml` des Projekts:

```yaml
editor:
    insert_element: 'Inhalt hinzufügen'
```

Die Texte des Backends (Schalter, Einstellungen) sind Contao-Sprachdateien in
`contao/languages/<sprache>/`; sie werden in `contao/languages/` des Projekts
überschrieben.

## Die Sprache des Editors

Der Editor spricht die Sprache des Backend-Benutzers (**Backend-Sprache** in den
Benutzereinstellungen), nicht die Sprache der Seite.
