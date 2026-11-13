---
titel: Fehlersuche
kurzfassung: Häufige Probleme mit dem NEO Frontend Editor – Anzeichen, Ursache und Lösung.
zielgruppe: betrieb
sprache: de
stand: 2026-11-14
---

# Fehlersuche

Die meisten Probleme sind fehlende Rechte. Der Editor bietet nie einen Knopf an,
den Contao verweigern würde; ein fehlender Knopf bedeutet deshalb fast immer
einen fehlenden Schalter oder ein fehlendes Contao-Recht. Unter
[Rechte](permissions.md) stehen sie je Funktion.

Bei Modulen, dem Layout, Formularfeldern und Kommentaren nennt die Karte
**Info** das fehlende Recht selbst. In anderen Fällen hält Contaos System-Log
(Backend → **System-Log**) verweigerte Aktionen mit dem Grund fest.

## Der Editor

| Anzeichen | Ursache | Lösung |
| --- | --- | --- |
| Kein Knopf **Seite bearbeiten** in der Vorschauleiste | Dem Benutzer fehlt **Frontend-Bearbeitung erlaubt** | Den Schalter in der Benutzergruppe (oder beim Benutzer) setzen. |
| **Seite bearbeiten** lädt die Seite neu, aber der Editor öffnet sich nicht (kein Fehler) | Eine ältere Version der Erweiterung: Die Website setzt `COOKIE_ALLOW_LIST`, und Contaos HTTP-Cache, der auch `preview.php` bearbeitet, entfernte den Schalter des Editors (`contao_frontend_editor`) | Die Erweiterung aktualisieren; sie hält den Schalter selbst an der Liste vorbei. Geht das nicht, `contao_frontend_editor` in `COOKIE_ALLOW_LIST` ergänzen. |
| Der Editor erscheint nicht auf der Website | So vorgesehen: Er funktioniert nur in Contaos Vorschau | Die Vorschau aus dem Backend öffnen. |
| Die Seitenleiste zeigt einen Fehler oder bleibt leer | Die Seite liegt außerhalb der Pagemounts des Benutzers, oder die Sitzung ist abgelaufen | **Pagemounts** prüfen; neu anmelden. |
| Der Bereich der Seite neben der Seitenleiste bleibt leer, oder der Browser meldet, dass die Seite dort nicht angezeigt werden kann | Die Website verbietet, ihre Seiten in einem Rahmen derselben Adresse zu zeigen: Header `X-Frame-Options: DENY` oder `Content-Security-Policy: frame-ancestors 'none'`, gesetzt vom Webserver oder einer Erweiterung (Contaos Managed Edition sendet `SAMEORIGIN`, das erlaubt es) | Dieselbe Adresse erlauben: `X-Frame-Options: SAMEORIGIN` oder `frame-ancestors 'self'`, mindestens für `preview.php`. |
| Ein Link in der Seite öffnet eine andere Website statt des Editors | So vorgesehen: Der Editor zeigt nur Seiten dieser Website | Im Browser zurückgehen. |
| Das Formular in der Seitenleiste bleibt leer (Backend unter eigener Adresse) | Die Adresse oder die Domains der Startpunkte sind nicht eingetragen | **Frontend Editor** → **Adresse des Backends**; bei jedem Startpunkt das Feld **Domainname** ausfüllen. |
| Das Formular in der Seitenleiste zeigt „Forbidden“ oder „Access denied“ | Contao verweigert das Formular auch im Backend | Die Rechte dieses Teils prüfen ([Rechte](permissions.md)); ab Contao 5.7 auch die **Tabellenrechte**. |

## Texte, Bilder, Struktur

| Anzeichen | Ursache | Lösung |
| --- | --- | --- |
| Kein **Bearbeiten** an einem Element, aber **Alle Felder** | Keiner der Texte des Elements lässt sich auf der Seite bearbeiten: Seine Felder enthalten keinen Text, den es zeigt, oder Sie dürfen sie nicht ändern (ein Klick auf einen Text sagt, warum; siehe *Jeder weitere Text eines Teils* in der Bedienungsanleitung) | So vorgesehen; **Alle Felder** nutzen. |
| Kein **Bearbeiten**, kein **Alle Felder** | Es fehlt **Texte und Bilder ändern**, der Elementtyp (**Inhaltselemente**), **Erlaubte Felder** oder `tl_content` → Bearbeiten | Siehe *Texte und Bilder* unter [Rechte](permissions.md). |
| Kein **Element einfügen**, **Duplizieren**, **Löschen** | Es fehlt **Elemente anlegen, verschieben und löschen** oder `tl_content` → Anlegen / Löschen | Siehe *Struktur*. |
| Kein **Ausblenden** | Das Feld **Unsichtbar** (`invisible`) fehlt unter **Erlaubte Felder** | Es ergänzen. |
| Kein **Artikel anlegen** | Es fehlt **Artikel anlegen**, ein Feld von `tl_article` unter **Erlaubte Felder** oder *Artikel bearbeiten* / *Hierarchie der Artikel ändern* an der Seite | Siehe *Artikel anlegen*. |
| Ein Element in einem Akkordeon ist nicht zu finden | Sein Abschnitt ist auf der Seite geschlossen | Die Seitenleiste nutzen: Gruppe „Elemente in: …“. |
| „Speichern fehlgeschlagen. Die Verbindung zum Server ist unterbrochen.“ | Netzwerk oder Sitzung | **Erneut speichern**; wiederholt es sich, neu anmelden. |

## Seiten und Beiträge (Plus)

| Anzeichen | Ursache | Lösung |
| --- | --- | --- |
| Keine Ansichten **Beiträge**, **Module**, **Sprachen** | Keine Plus-Lizenz für die Domain, oder die Schalter fehlen | Die Lizenz eintragen; die Schalter setzen. |
| Kein **Neue Seite** oder keine Knöpfe im Seitenbaum | Es fehlen *Seite bearbeiten* und *Hierarchie der Seiten ändern* an der übergeordneten Seite, oder **Seiten anlegen und veröffentlichen (Plus)** | Siehe *Seiten*. |
| Der Seitenbaum für eine neue Seite zeigt nur eine Website | So vorgesehen: Eine Seite wird in der Website und Sprache der offenen Seite angelegt | Zuerst eine Seite der anderen Website öffnen. |
| Ein Beitrag öffnet sich als „Nicht gefunden“ | Er ist nicht veröffentlicht, und die Vorschauleiste steht auf „Nicht veröffentlicht: verstecken“ | Auf „anzeigen“ stellen. |
| Eine Beitragsart oder ein Archiv fehlt | Es fehlen das Backend-Modul, das Archiv (**Erlaubte Archive**) oder der Schalter **Beiträge bearbeiten (Plus)**; oder die Weiterleitungsseite des Archivs gehört zu einer anderen Website | Siehe *Beiträge* und [Mehrere Websites und Sprachen](websites-and-languages.md). |

## Sprachen (Plus)

| Anzeichen | Ursache | Lösung |
| --- | --- | --- |
| Kein Knopf **Sprachen** | Keine verknüpften Sprachversionen (changelanguage), der Schalter **Sprachversionen bearbeiten (Plus)** fehlt, oder keine Plus-Lizenz | Die Seiten verknüpfen; den Schalter setzen. |
| Kein Schritt **Sprachversionen anlegen** nach einer neuen Seite | Nur eine Sprache, Sprachen nicht mit changelanguage verknüpft, keine *Reguläre Seite*, oder die Seite gibt es schon in jeder Sprache | Siehe [Seiten](usage/pages.md#sprachversionen-gleich-mit-anlegen). |
| Eine Zeile von **Sprachversionen anlegen** sagt „Für … fehlt das Recht, an dieser Stelle eine Seite anzulegen.“ | Es fehlen *Seite bearbeiten* / *Hierarchie der Seiten ändern* an der übergeordneten Seite, der Pagemount dieser Sprache oder das Feldrecht Seitenname | Siehe *Sprachversionen* unter [Rechte](permissions.md). |
| Eine Sprachversion konnte wegen ihres Alias nicht angelegt werden | Den Alias gibt es in dieser Sprache schon | **Erneut versuchen** mit einem anderen Alias. |
| „Für Sprachversionen braucht es die Erweiterung changelanguage.“ | changelanguage ist nicht installiert | `terminal42/contao-changelanguage` installieren. |
| „Für Übersetzungen fehlt ein Übersetzungsdienst: in den Einstellungen … wählen …“ | Kein Übersetzungsdienst eingerichtet, oder der gewählte hat seinen Zugang verloren | Unter *Zugänge und Lizenzen* Azure Translator, DeepL oder die KI-Verbindung einrichten und in den Einstellungen des Editors wählen. |
| „… ist gerade nicht erreichbar“ (mit dem Namen des Dienstes) | Der Server der Website erreicht den Dienst nicht | **Verbindung prüfen** im Abschnitt des Dienstes; der Server muss ausgehende HTTPS-Verbindungen aufbauen dürfen. |
| „Azure Translator hat die Übersetzung abgelehnt …“ | Schlüssel oder Region falsch, oder die Ressource ist keine Translator-Ressource | Im Azure-Portal prüfen; **Verbindung prüfen**. |
| „DeepL hat die Übersetzung abgelehnt …“ | Schlüssel falsch, die Zeichen des Abrechnungszeitraums aufgebraucht, oder DeepL bietet eine der beiden Sprachen nicht an | **Verbindung prüfen** im Abschnitt **DeepL** zeigt den Grund. |
| „KI-Modell hat die Übersetzung abgelehnt …“ | Schlüssel oder Modell falsch, oder das Modell lieferte keine brauchbare Antwort | **Verbindung prüfen** im Abschnitt **KI-Verbindung**; ein anderes Modell wählen. |
| Ein Dienst lässt sich unter **Übersetzungsdienst** nicht wählen | Sein Zugang ist nicht eingerichtet | Dem Link unter dem Feld zu seinem Abschnitt folgen. |
| **Modell für Übersetzungen** oder die Einstellungen der Schreibhilfe sind gesperrt | Die KI-Verbindung hat keinen Schlüssel | Dem Link am Feld folgen und den Schlüssel eintragen. |

## Pro

| Anzeichen | Ursache | Lösung |
| --- | --- | --- |
| Keine **Schreibhilfe** in den Textwerkzeugen | Keine Pro-Lizenz | Die Lizenz eintragen. |
| „Für die Schreibhilfe fehlen die KI-Verbindung oder das Modell.“ | Route, Schlüssel oder Modell fehlen | *Zugänge und Lizenzen* → **KI**; **Frontend Editor** → **Modell**. |
| „Die Schreibhilfe hat für heute ihre Grenze erreicht.“ | **Anfragen pro Tag** aufgebraucht | Die Grenze erhöhen oder bis morgen warten. |
| „Die Prüfung konnte nicht laufen.“ | Die Prüfdatei `bundles/neofrontendeditor/axe/axe.min.js` lässt sich nicht laden | `php vendor/bin/contao-console assets:install` ausführen; prüfen, ob eine Content-Security-Policy der Website sie sperrt. |
| Keine **Bildschirmgröße** | Das Fenster ist 768 px breit oder schmäler, oder keine Pro-Lizenz | Ein breiteres Fenster nutzen. |
| Der Fokus-Suchbegriff lässt sich nicht speichern | *Seite bearbeiten* fehlt für die Seite | Siehe *Prüfungen und Schreibhilfe*. |
| Kein **Stil der Seite (CSS)** | Es fehlen der Schalter **Stil der Seiten bearbeiten (Pro)**, das Backend-Modul **Stile der Seiten** oder *Seite bearbeiten* | Siehe *Stil der Seite*. |
| Der Stil der Seite wirkt nicht | Ein genauerer Selektor des Layouts gewinnt | Einen genaueren Selektor verwenden. |

## Templates und Module (Webdesigner)

| Anzeichen | Ursache | Lösung |
| --- | --- | --- |
| Kein **Info**, keine Rahmen um Module | Der Schalter **Templates und Module (für Webdesigner)** fehlt | Den Schalter setzen. |
| **Modul bearbeiten**, **Feld bearbeiten**, **Kommentar bearbeiten** oder **Layout bearbeiten** fehlt | Contao erlaubt die Änderung nicht | **Info** am Teil (beim Layout: **Templates der Seite**) nennt das fehlende Recht. |
| **Im Template Studio öffnen** fehlt | Das Backend-Modul **Templates** fehlt | Es unter **Backend-Module** ergänzen. |
| Ein Formularfeld hat keinen Rahmen | Versteckte Felder bekommen keinen Rahmen, ebenso wenig Felder von Formularen, die nicht mit Contaos Formulargenerator gebaut sind | So vorgesehen. |
| Ein Text, den ein Element aus einer anderen Tabelle zeigt (ein Eintrag eines Katalogs), lässt sich laut Hinweis hier nicht ändern | Das Feld des Elements, das den Datensatz wählt, hat in seinem DCA keine Verknüpfung; der Editor kennt den Datensatz daher nicht | Im DCA des Feldes `'foreignKey' => 'tl_tabelle.title'` und `'relation' => ['type' => 'hasOne', 'load' => 'lazy']` setzen (wie Contaos eigene Felder). Die Datensätze und ihre Einträge (Kind-Tabelle) werden dann mit dem Element bearbeitet und übersetzt. |

## Immer noch nicht gelöst?

1. Mit einem Administrator anmelden. Funktioniert die Funktion dort, ist es ein
   Recht; die Gruppe mit [Rechte](permissions.md) vergleichen.
2. Denselben Datensatz mit dem Benutzer im Backend öffnen. Verweigert auch das
   Backend ihn, liegt die Ursache in Contaos Rechten, nicht im Editor.
3. Im **System-Log** des Backends nachsehen.
