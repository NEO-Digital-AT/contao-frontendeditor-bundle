---
titel: Datenschutz
kurzfassung: Was der Editor speichert, was an welchen fremden Dienst geht und was in die Datenschutzerklärung gehört.
zielgruppe: betrieb
sprache: de
stand: 2026-11-12
---

# Datenschutz

Der Editor arbeitet nur für angemeldete Backend-Benutzer in Contaos Vorschau. Er
fügt den Seiten, die Besucher sehen, nie etwas hinzu, außer einem Stil der Seite
(Pro), den ein Webdesigner für eine Seite gespeichert hat. Er verarbeitet keine
Daten von Besuchern.

## Was gespeichert wird

| Was | Wo | Wie lange |
| --- | --- | --- |
| Änderungen an Inhalten, Seiten, Beiträgen | Contaos eigene Tabellen und Contaos Versionen (mit Benutzername), wie bei Änderungen im Backend | so lange Contao die Versionen aufbewahrt |
| Verknüpfungen zwischen den Elementen der Sprachversionen (Plus) | eine Tabelle der Erweiterung | bis die Verknüpfung gelöst wird |
| Fokus-Suchbegriff je Seite (Pro) | eine Tabelle der Erweiterung | bis er geändert wird |
| Stile der Seiten (Pro) | eine Tabelle der Erweiterung und Contaos Versionen | bis sie gelöscht werden |
| Breite und Zustand der Seitenleiste | der Browser des Redakteurs (lokaler Speicher) | bis die Browserdaten gelöscht werden |

## Was an fremde Dienste geht

| Funktion | Dienst | Was gesendet wird | Wann |
| --- | --- | --- | --- |
| Basic | keiner | – | – |
| Lizenz (Plus, Pro) | der Lizenzdienst | Lizenzschlüssel, Erweiterung, Domains der Website, Versionen | beim Aktivieren der Lizenz und einmal am Tag |
| Übersetzungsvorschläge (Plus), Dienst Azure Translator | Microsoft Azure Translator, verarbeitet am gewählten Ort (Vorgabe Europa) | die Texte des Elements (jedes Textfeld, die Texte seiner Einträge, Titel, Alternativtext und Bildunterschrift seiner Bilder, HTML-Code) und die beiden Sprachen; bei einer neuen Seite ihr Name | nur, wenn ein Benutzer auf **Alle Felder übersetzen**, **Dieses Feld übersetzen** oder **Ganze Seite übersetzen** klickt oder die Namen neuer Sprachversionen anfordert |
| Übersetzungsvorschläge (Plus), Dienst DeepL | DeepL SE (Deutschland), DeepL API Free oder Pro je nach Schlüssel | dieselben Texte und die beiden Sprachen | wie oben |
| Übersetzungsvorschläge (Plus), Dienst KI-Modell | die KI-Route unter *Zugänge und Lizenzen*, mit dem Modell aus den Einstellungen | dieselben Texte, die beiden Sprachen und feste Anweisungen für die Übersetzung | wie oben |
| Schreibhilfe (Pro) | die KI-Route, die unter *Zugänge und Lizenzen* eingestellt ist (z. B. eine Route mit Modellen, die in der EU betrieben werden) | die Texte der Seite (Seitentitel, Beschreibung, Texte der Elemente), das umzuschreibende Feld, der Suchbegriff | nur, wenn ein Benutzer auf **Vorschläge erstellen** klickt |
| Prüfungen (Pro) | keiner; sie laufen im Browser des Redakteurs. Die Barrierefreiheits-Prüfung (axe-core) kommt mit der Erweiterung und wird von der Website selbst geladen | – | – |
| Stil der Seite (Pro) | keiner; übersetzt auf dem eigenen Server der Website. Ein Stil darf nichts von anderen Servern laden | – | – |

Es werden keine Benutzernamen und keine Daten von Besuchern gesendet.

## Was der Betreiber der Website tut

- Mit den genutzten Diensten einen Auftragsverarbeitungsvertrag schließen:
  Microsoft (Azure), DeepL, der Anbieter der KI-Route. Bei DeepL API Free darf
  DeepL die Texte für eine begrenzte Zeit zur Verbesserung seines Dienstes
  nutzen; DeepL API Pro löscht sie nach der Übersetzung (Datenschutzerklärung
  von DeepL). Für Texte, die unter einem Auftragsverarbeitungsvertrag bleiben
  müssen, DeepL API Pro, Azure Translator oder eine KI-Route mit einem solchen
  Vertrag nutzen.
- Diese Dienste, wo zutreffend, in der Datenschutzerklärung nennen (den Editor
  nutzen Mitarbeiter, nicht Besucher).
- Vorschläge der KI und Übersetzungsvorschläge sind im Editor als solche
  gekennzeichnet („Maschinelle Übersetzung (DeepL) – bitte prüfen“) und werden
  erst veröffentlicht, wenn eine Person sie geprüft und gespeichert hat.
