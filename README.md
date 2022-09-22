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
* einen ChurchTools-User mit Berechtigungen für das Ressourcen-Modul. Von diesem User wird das Login-Token benötigt.

## Software-Installation
Über das Modul-Control folgende URL hinzufügen:
https://github.com/churchtools/ip-symcon

## Einrichten der Instanzen in IP-Symcon
Unter "Instanz hinzufügen" zunächst das "ChurchTools Gateway" (Hersteller: ChurchTools Innovations GmbH) hinzufügen. Anschließend die Konfiguration des Gateways öffnen und die Zugangsdaten (URL der eigenen ChurchTools-Installation und Login-Token) eintragen. Anschließend können ausgewählte Räume angelegt werden.

## Historie
### Version 1.0
* Initiale Version

