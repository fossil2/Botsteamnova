# TestBot für steemnova-1.8-x

TestBot ist ein automatisierter Bot für **steemnova-1.8-x**.  
Der Bot erstellt sich im Spiel und entwickelt sich selbstständig nach definierten Vorgaben.

-  Bot-Erstellung direkt über das Game
-  Baut Planeten bis zur festgelegten Vorgabe
-  Forscht bis zur definierten Zielstufe
-  Spioniert automatisch
-  Baut Verteidigung bis zur Vorgabe
-  Erstellt bis zu **3 Kolonien**, sobald Forschung dies ermöglicht
-  SQL-Erweiterungen zur Steuerung der Bot-Aktionen
-  
 Voraussetzungen
- steemnova-1.8-x
- PHP (empfohlen  8.x)
- MySQL / MariaDB
- Cronjob (für automatische Ausführung empfohlen)

---

Folgende Felder müssen in der `users` Tabelle ergänzt werden:

```sql
ALTER TABLE users
ADD bot_next_spy INT DEFAULT 0,
ADD bot_spy_last_target INT DEFAULT 0,
ADD bot_spy_today_count INT DEFAULT 0,
ADD bot_next_expedition INT DEFAULT 0,
ADD bot_next_colonize INT DEFAULT 0;
