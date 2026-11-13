---
titel: Mehrere Websites und Sprachen
kurzfassung: Wie der Editor mehrere Websites und die Sprachversionen einer Website auseinanderhält und wie die Benutzergruppen dafür eingerichtet werden.
zielgruppe: betrieb
sprache: de
stand: 2026-11-14
---

# Mehrere Websites und Sprachen

Eine Contao-Installation kann mehrere Websites enthalten (jede mit eigenem
Startpunkt und eigener Domain) und Websites in mehreren Sprachen (ein Startpunkt
je Sprache). Der Editor hält sie auseinander; Contaos Rechte entscheiden, wer wo
arbeiten darf.

## Was der Editor von selbst auseinanderhält

- **Bearbeitet wird immer die Seite, die in der Vorschau offen ist.** Elemente,
  Seiteneinstellungen, Stil der Seite, Layout und Templates gehören zu dieser
  Seite.
- **Neue Seite** (Plus): Der Seitenbaum zeigt nur die Website und Sprache der
  offenen Seite. Eine neue Seite kann nicht in einer anderen Website oder Sprache
  landen.
- **Beiträge** (Plus): Die Ansicht listet nur Archive, deren
  Weiterleitungsseite zur selben Website (Domain) gehört, und Archive, die auf
  der offenen Seite gezeigt werden.
- **Sprachen** (Plus): Nebeneinander stehen nur die Sprachversionen, die mit der
  Erweiterung changelanguage verknüpft sind. Websites mit verschiedenen Domains
  werden nie miteinander verknüpft.
- **Fokus-Suchbegriff** (Pro): Der Befund **Suchbegriff auch auf „{Seite}“**
  zählt nur Seiten derselben Website und Sprache.
- **Lizenz** (Plus, Pro): Die Lizenz gilt für Domains. Jede Website mit eigener
  Domain muss in der Lizenz enthalten sein.

## Die Benutzergruppen einrichten

Jeder Website eine eigene Benutzergruppe geben, damit nichts an der falschen
Stelle landet:

1. Je Website eine Benutzergruppe anlegen, zum Beispiel „Redakteure – Website A“.
2. **Pagemounts**: nur der Startpunkt oder die Startpunkte dieser Website. Bei
   einer Website mit mehreren Sprachen alle Startpunkte ihrer Sprachen, wenn die
   Gruppe alle Sprachen bearbeitet; sonst nur einen.
3. **Erlaubte Archive**, **Erlaubte Kalender**, **Erlaubte FAQ-Kategorien**: nur
   die dieser Website.
4. **Erlaubte Formulare**: nur die dieser Website (nur für Webdesigner nötig).
5. Schalter und Rechte wie unter [Rechte](permissions.md) beschrieben setzen.
6. Jeden Benutzer nur den Gruppen der Websites zuordnen, an denen er arbeitet.

Ein Benutzer sieht dann im Editor wie im Backend nur Seiten und Archive seiner
Websites. In der Vorschau einer Seite außerhalb seiner Pagemounts bietet der
Editor nichts zum Bearbeiten an.

## Mehrere Sprachen einer Website

- Die Seiten der Sprachen mit changelanguage verknüpfen: In den
  Seiteneinstellungen einer Sprachversion die Seite der Hauptsprache
  (Fallback-Sprache) setzen. Artikel lassen sich genauso verknüpfen.
- Beiträge (Nachrichten, Events, FAQ und Beiträge anderer Erweiterungen mit
  demselben Feld) verknüpft changelanguage ebenso: Im Archiv einer
  Sprachversion das Archiv der Hauptsprache setzen (**Hauptarchiv**); dann kann
  jeder Beitrag dieses Archivs seinen Beitrag der Hauptsprache nennen
  (**Hauptbeitrag**). Die Leserseiten der Archive werden wie jede Seite
  verknüpft. Erst dann vergleicht und übersetzt der Editor die Elemente eines
  Beitrags.
- Die Hauptsprache ist die Sprache des Startpunkts, bei dem **Sprachen-Fallback**
  gesetzt ist.
- Die Elemente der Sprachversionen verknüpft der Editor selbst (Ansicht
  **Sprachen** → **Verknüpfen**, **Element übernehmen** oder, für ein Element,
  das zuerst in einer Sprachversion geschrieben wurde, **In (Hauptsprache)
  übernehmen**). Diese Verknüpfungen erscheinen in der Backend-Liste
  **Übersetzungen**.
- Eine neue Seite bekommt ihre Sprachversionen im selben Schritt: Nach dem
  Speichern legt **Sprachversionen anlegen** die Seite in den anderen Sprachen
  an, am selben Platz in jedem Seitenbaum, mit eigenem Namen und Alias, und
  verknüpft sie mit der Seite der Hauptsprache (siehe
  [Seiten](usage/pages.md#sprachversionen-gleich-mit-anlegen)). Die
  Sprachversion in der Hauptsprache wird immer gebraucht und beim Speichern
  einer neuen Seite in einer anderen Sprache automatisch angelegt.

## Häufige Probleme

| Anzeichen | Ursache | Lösung |
| --- | --- | --- |
| Der Knopf **Sprachen** fehlt | Die Seite ist nicht mit Seiten anderer Sprachen verknüpft, oder dem Benutzer fehlt **Sprachversionen bearbeiten (Plus)** | Die Seiten mit changelanguage verknüpfen; den Schalter setzen |
| Eine Sprachversion fehlt unter **Sprachen** | Der Benutzer hat keinen Pagemount für den Startpunkt dieser Sprache | Den Startpunkt unter **Pagemounts** ergänzen |
| Ein Archiv fehlt unter **Beiträge** | Seine Weiterleitungsseite gehört zu einer anderen Website, oder dem Benutzer fehlt das Archiv unter **Erlaubte Archive** | Die **Weiterleitungsseite** des Archivs und die Gruppe prüfen |
| **Neue Seite** zeigt nur einen Startpunkt | So vorgesehen: Eine neue Seite wird in der Website und Sprache der offenen Seite angelegt | Zuerst die Vorschau einer Seite der anderen Website oder Sprache öffnen |
