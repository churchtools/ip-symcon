# Symcon - ChurchTools Raumbelegungs-Sync

Dieses Modul erlaubt den Abruf von Raumbelegungs-Daten aus ChurchTools, um darauf basierend die Heizung zu steuern.

Features:
* Individuelle Selektion, welche Räume aus ChurchTools als Geräte angelegt werden sollen
* Automatischer Abruf der Raumbelegungen alle 10 Minuten
* Pro Raum wird die Raumbelegung als Variable bereitgestellt, diese wird alle 10 Sekunden aktualisiert
* Je Raum kann ein individueller Heiz-Vorlauf sowie ein vorzeitiges Heiz-Ende konfiguriert werden, die Variable "Heizen" berücksichtigt diese Einstellungen
* Je Raum kann einstellt werden, ob auch Buchungsanfragen wie feste Buchungen behandelt werden sollen

## Voraussetzungen
* IP-Symcon ab Version 6.0
* eine ChurchTools-Installation (siehe https://church.tools)
* einen ChurchTools-User mit Berechtigungen für das Ressourcen-Modul. Von diesem User wird der Login-Token benötigt. In ChurchTools kann der Token unter Personen & Gruppen > Personenliste > "Person A" > Berechtigungen > Login-Token abgerufen werden

## Software-Installation
Im Modul-Store von IP-Symcon nach "ChurchTools" suchen und das Modul "ChurchTools Raumbelegungen" installieren.

Oder über das Modul-Control folgende URL hinzufügen:
https://github.com/churchtools/ip-symcon

## Einrichten der Instanzen in IP-Symcon
Unter "Instanz hinzufügen" zunächst das "ChurchTools Gateway" (Hersteller: ChurchTools Innovations GmbH) hinzufügen. 
Daraufhin öffnet sich automatisch die Konfiguration des Gateways und die Zugangsdaten 
(URL der eigenen ChurchTools-Installation und Login-Token) können eingetragen werden. 

Ist die Verbindung zu ChurchTools erfolgreich, werden die verfügbaren Räume aus ChurchTools abgerufen und angezeigt.
Um die Raumbelegungen nutzen zu können, kann jetzt für jeden Raum eine Raumbelegungs-Instanz in IP-Symcon angelegt werden.
Einfach die entsprechenden Räume markieren und auf hinzufügen klicken.

Die somit erstellten Raumbelegungs-Instanzen werden jeweils in Unterordnern unterhalb vom Ordner IP-Symcon angelegt. Die Unterordner tragen den gleichen Namen wie die Ortsangabe in ChurchTools, also z.B. "EG".

### Raumbelegungs-Variablen
Pro Raum stehen in IP-Symcon sechs Variblen zur Verfügung:
* `Nächste Buchung Titel`: Name der aktuellen bzw. nächsten Buchung
* `Nächste Buchung Start`: Start-Datum und Uhrzeit der aktuellen bzw. nächsten Buchung
* `Nächste Buchung Ende`: Ende-Datum und -Uhrzeit der aktuellen bzw. nächsten Buchung
* `Nächste Buchung Status`: Buchungs-Status der aktuellen bzw. nächsten Buchung (`bestätigt` oder `angefragt`)
* `Raum ist belegt`: Eine Boolean-Variable, die `true` ist, wenn der Raum aktuell belegt ist, basierend auf Start- und Ende-Zeitpunkt der Buchung.
* `Heizen`: Eine Boolean-Variable, die `true` ist, wenn der Raum aktuell belegt ist, unter Berücksichtigung des ggf. eingestellten Heiz-Vorlaufs und des ggf. eingestellten vorzeitigen Heiz-Endes.

Diese Variablen, insbesondere `Heizen`, können über Ereignisse mit anderen Geräten in IP-Symcon verbunden werden, um z.B. Heizthermostate in den Räumen einzustellen.

### Konfigurations-Möglichkeiten der Raumbelegungs-Instanzen
Pro Raum kann ein Heiz-Vorlauf sowie ein vorzeitiges Heiz-Ende konfiguriert werden. Ebenso kann eingestellt werden, ob auch für noch nicht bestätigte Buchungsanfragen der Raum geheizt werden soll.

In dem Einstellungsdialog des Raumes werden die nächsten Buchungen in einer Liste angezeigt.

### Abruf der Raumbelegungen und Aktualisierung der Variablen
Die Buchungen der Räume in ChurchTools werden automatisch vom Gateway alle 10 Minuten abgerufen. Hierbei werden Buchungen für einen Tag im Voraus geladen, so dass auch Netzwerkproblem trotzdem nicht zu kalten Räumen führen. 

Die Raumbelegungs-Variablen für die einzelnen Räume werden basierend auf den gespeicherten Buchungsdaten alle 10 Sekunden aktualisiert.

## Historie
### Version 1.0
* Initiale Version

