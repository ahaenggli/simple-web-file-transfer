## Features
* (Anonymes) Hochladen von Dateien und Ordnern (inkl. Unterordner) 
* (Anonymes) Löschen von Dateien und Ordnern (inkl. Unterordner) 
* (Anonymes) Herunterladen von Dateien (sofern Downloadtoken vorhanden) 
* SSO-Login mit Synology NAS
* Uploadlinks generieren falls eingeloggt
* Downloadlinks generieren falls eingeloggt

## "Doku"
Eingeloggt ($_USER is not NULL):
* Logout-Button
* Ordner erstellen
* Dateien und Ordner hochladen
* Uploadlinks generieren/sehen (für Gäste) 
* Downloadlinks generieren/sehen (für Gäste)
* Dateien/Ordner löschen
* Download nur via Downloadlink möglich

Gast ($_USER is NULL):
* Login-Button
* Uploadlink vorhanden: 
  - Hochladen von Dateien und Ordnern (inkl. Unterordner) 
  - Löschen von Dateien und Ordnern (inkl. Unterordner) 
* ohne Uploadlink: kein Upload/Löschen möglich
* Download nur via Downloadlink möglich
 
## ToDo's
* Einheitliches Wording in Source
  - Uploadlink anstelle (Upload/Folder)token
  - Downloadlink anstelle Downloadtoken 
* Foldertoken erneuern (inhalt belassen)
* File/Folderliste alphabetisch sortieren
* $.ajax durch XMLHttpRequest ersetzen (möglichst auf jQuery verzichten) 
* OWASP Top 10 prüfen/absichern
