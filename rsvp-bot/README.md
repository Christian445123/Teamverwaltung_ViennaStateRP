# Teamverwaltung RSVP-Bot

Nimmt Klicks auf die Teilnehmen/Vielleicht/Absagen-Buttons unter Besprechungs-Ankündigungen
entgegen — über Discords **Gateway** (dauerhafte Websocket-Verbindung), nicht über den
HTTP-„Interactions Endpoint" (`../discord_interactions.php`).

## Warum ein eigener Bot-Prozess statt des bisherigen HTTP-Endpoints?

Der HTTP-Interactions-Endpoint verlangt, dass Discord unseren Server bei jedem Klick innerhalb
von 3 Sekunden per HTTPS erreicht — dazwischen liegen Cloudflare, nginx und PHP-FPM, jede Station
ein potenzieller Verzögerungs-/Blockade-Punkt ("VSRP I Server hat nicht rechtzeitig reagiert").
Über die Gateway-Verbindung liefert Discord die Interaktion direkt an diesen bereits laufenden,
dauerhaft verbundenen Prozess — es gibt gar keinen HTTP-Request von Discord an unseren Server für
diesen Klick, also auch keine der oben genannten Verzögerungsquellen. Exakt der gleiche,
bewährte Mechanismus wie bei `Discordbot_Follower` und `Discordbot_Ticket`.

## Voraussetzung: Interactions Endpoint URL im Discord Developer Portal LEEREN

**Das ist der wichtigste Schritt — ohne ihn bringt der Bot nichts:**

Solange bei der Anwendung ("VSRP I Server") unter **General Information → Interactions Endpoint
URL** eine URL eingetragen ist, liefert Discord **alle** Interaktionen (inkl. Button-Klicks)
ausschließlich per HTTP dorthin aus — unabhängig davon, ob zusätzlich eine Gateway-Verbindung
besteht. Erst wenn dieses Feld **leer** ist, liefert Discord Interaktionen per Gateway an diesen
Bot aus.

1. [Discord Developer Portal](https://discord.com/developers/applications) → Anwendung "VSRP I
   Server" öffnen.
2. **General Information** → Feld **Interactions Endpoint URL** leeren → **Save Changes**.

(`discord_interactions.php` bleibt im Projekt bestehen, ist danach aber inaktiv — nichts weiter
zu tun, keine Löschung nötig.)

## Einrichtung auf dem Server

```bash
cd rsvp-bot
npm install
cp .env.example .env    # falls noch nicht vorhanden
# .env ausfüllen: DISCORD_BOT_TOKEN + DB_PASS identisch zu ../.env (dort Klartext eintragen,
# nicht den ENC:-Wert), Rest ist meist schon korrekt (gleiche DB, gleiche Guild).
pm2 start ecosystem.config.js
pm2 save   # sorgt dafür, dass PM2 den Prozess nach einem Server-Neustart automatisch wieder startet
```

Danach zur Kontrolle:

```bash
pm2 logs teamverwaltung-rsvp-bot
```

Dort sollte `[rsvp-bot] bereit als VSRP I Server#....` erscheinen.

## Neustart nach Code-Änderungen

Der übliche **„🚀 Jetzt deployen"**-Button in der Teamverwaltung (unter *Discord &
Einstellungen*) zieht per `git pull` auch Änderungen an diesem Ordner — der laufende Bot-Prozess
merkt davon aber nichts automatisch (anders als PHP-Dateien, die pro Request neu eingelesen
werden). Ein automatischer Neustart aus PHP heraus wurde bewusst wieder entfernt: die
Shell-Umgebung von PHP-FPM unterscheidet sich zu stark von einer interaktiven SSH-Session
(`$HOME`/`$PATH`/ggf. nvm-Setup in `.bashrc`), als dass PM2 dabei zuverlässig denselben Daemon
gefunden hätte wie die SSH-Session, in der der Bot ursprünglich gestartet wurde. Nach Änderungen
an `rsvp-bot/` deshalb manuell per SSH:

```bash
cd rsvp-bot && pm2 restart teamverwaltung-rsvp-bot
```

## Was der Bot NICHT übernimmt

Alles andere rund um Besprechungen (Ankündigung posten, bei Absage die Ankündigung bearbeiten,
Discord Scheduled Events) läuft weiterhin ganz normal über die PHP-Anwendung
(`src/DiscordClient.php`) mit dem Bot-Token — dieser Prozess kümmert sich ausschließlich um die
eingehenden Button-Klicks. Beide nutzen denselben Bot-Token und dieselbe Datenbank, laufen aber
als zwei unabhängige Prozesse (PHP pro Web-Request, dieser Bot dauerhaft).
