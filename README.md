# Teamverwaltung

Webanwendung zur Verwaltung eines Teams: Mitglieder, Ränge/Berechtigungen, Teams,
Besprechungen mit RSVP sowie eine tiefe Discord-Integration (Login, automatische
Rollenvergabe, Mitglieder-Sync, Ankündigungen und Discord-Events für Besprechungen).
Dazu eine öffentliche Bewerbungsseite mit Stellenausschreibungen, Bewerbungsformular,
Bewerbungsgespräch-Terminbuchung und internem Review-Dashboard (siehe unten).

Technisch: reines PHP (keine Frameworks/Composer nötig) mit MySQL/MariaDB-Datenbank
(z. B. über phpMyAdmin verwaltet). Alle Zugangsdaten und Discord-Einstellungen liegen
ausschließlich in der `.env`-Datei.

## Voraussetzungen

- PHP 8.1 oder neuer
- Aktivierte PHP-Erweiterungen: `pdo_mysql`, `curl`, `mbstring` (in den meisten
  Standard-Installationen von XAMPP/Laragon/WAMP bzw. bei Shared-Hosting mit
  phpMyAdmin bereits aktiv)
- Eine MySQL/MariaDB-Datenbank (z. B. über phpMyAdmin angelegt)

## Einrichtung

1. `.env.example` nach `.env` kopieren.
2. `APP_SECRET_KEY` als erste Zeile setzen — zufälligen Wert erzeugen, z. B.
   `php -r "echo bin2hex(random_bytes(32));"` oder `openssl rand -hex 32`. Dieser
   Key bleibt selbst unverschlüsselt in der `.env`, schützt aber alle mit `ENC:`
   verschlüsselten Werte (siehe Abschnitt *Verschlüsselung* unten).
3. Datenbank-Zugangsdaten eintragen (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
   `DB_PASS`) — die Datenbank selbst über phpMyAdmin anlegen, **Tabellen müssen nicht
   manuell erstellt werden**: Die App legt beim ersten Aufruf automatisch alle
   benötigten Tabellen an (`src/Database.php`).
4. `APP_URL` setzen (Basis-URL der App, ohne abschließenden Slash), z. B.
   `http://localhost:8000` lokal oder `https://team.deine-domain.tld` produktiv.
5. Optional Discord-Zugangsdaten eintragen (siehe unten).
6. Passwörter/Tokens optional mit `php encrypt_env.php "wert"` verschlüsseln und als
   `ENC:...` eintragen (siehe *Verschlüsselung* unten).
7. Die `.env` **niemals committen** — sie ist bereits in `.gitignore` eingetragen.

## Verschlüsselung sensibler Werte in der .env

Werte wie `DB_PASS`, `DISCORD_CLIENT_SECRET`, `DISCORD_BOT_TOKEN` oder
`DISCORD_WEBHOOK_URL` können statt im Klartext auch verschlüsselt in der `.env`
stehen (Präfix `ENC:`, AES-256-CBC, Schlüssel aus `APP_SECRET_KEY`) — analog zum
Muster im Schwester-Projekt `ViennaStateRP/Website`.

```bash
php encrypt_env.php "meinGeheimesPasswort"
# → ENC:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx==
```

Den Ergebniswert 1:1 in die `.env` eintragen, z. B. `DB_PASS=ENC:...`. Klartext und
`ENC:`-Werte können in derselben `.env` gemischt werden — nicht-sensible Werte wie
`DISCORD_CLIENT_ID` oder `DISCORD_GUILD_ID` müssen nicht verschlüsselt werden.

Noch sicherer: `APP_SECRET_KEY` selbst aus der `.env` entfernen und stattdessen in
eine Datei **außerhalb** des Projektverzeichnisses legen, z. B.
`/home/<site-user>/teamverwaltung.key` (eine Ebene über dem Ordner, den CloudPanel
als Document Root nutzt). Pfad optional über `APP_KEY_FILE` überschreiben. Damit
steht selbst der Master-Key nicht mehr in einer Datei, die je nach Deployment mit
kopiert/gebackupt wird.

## Lokal starten

```bash
php -S localhost:8000 router.php
```

Dann `http://localhost:8000` öffnen. Beim ersten Aufruf wird ein
Ersteinrichtungs-Formular angezeigt, um das erste Administrator-Konto anzulegen.
`router.php` ist nur für den eingebauten Dev-Server nötig (der wertet, anders als
Apache, keine `.htaccess` aus) und blockiert dort direkte Zugriffe auf `.env`,
`src/` und `includes/`.

## Projektlayout & warum es keinen `public/`-Unterordner gibt

Anders als in einer ersten Version liegen alle aufrufbaren Seiten (`index.php`,
`login.php`, `meetings.php`, …) direkt im Projekt-Root — genau wie im
Schwester-Projekt `ViennaStateRP/Website`. Das hat einen konkreten Grund:

CloudPanel legt für eine neue PHP-Site **standardmäßig das Projekt-Root selbst**
als Document Root fest. Ein `public/`-Unterordner als Document Root würde
bedeuten, dass in CloudPanel manuell das Vhost-Template der Seite bearbeitet und
die `root`-Direktive angepasst werden muss — ein Schritt, der leicht vergessen
wird und der genau zu dem „403 Forbidden“ (nginx findet kein `index.php` im
Root, weil das echte `index.php` unter `public/` liegt) geführt hat, das zuerst
aufgetreten ist. `ViennaStateRP/Website` funktioniert unverändert, weil dort
exakt dieses CloudPanel-Standardverhalten genutzt wird — Teamverwaltung macht es
jetzt genauso.

Schutz für nicht-öffentliche Dateien läuft dadurch nicht mehr über die
Document-Root-Trennung, sondern über zwei unabhängige Mechanismen, die beide
ohne CloudPanel-Sonderkonfiguration auskommen:

- **`.env`** ist eine Dotfile — die von CloudPanel/nginx standardmäßig ausgelieferte
  Konfiguration blockiert Anfragen auf Dateien mit führendem Punkt (genau wie bei
  `ViennaStateRP/Website`).
- **`src/` und `includes/`** enthalten reine Klassen-/Funktionsdefinitionen ohne
  Ausgabe und werden zusätzlich durch einen `APP_BOOTSTRAPPED`-Guard geschützt:
  jede Datei dort prüft am Anfang `defined('APP_BOOTSTRAPPED')` und bricht sonst
  ab. Dieses Flag wird ausschließlich in `bootstrap.php` gesetzt — ein direkter
  Aufruf wie `/src/Database.php` liefert dadurch immer einen 403, unabhängig
  davon, ob der Webserver `.htaccess`/Rewrite-Regeln auswertet oder nicht.

`.htaccess` (nur unter Apache wirksam) und `router.php` (nur für den lokalen
`php -S`-Server) bilden dieselben Regeln zusätzlich auf Webserver-Ebene nach —
reine Defense-in-Depth, kein Ersatz für die beiden Punkte oben.

## Deployment mit CloudPanel + Cloudflare (wie bei `ViennaStateRP/Website`)

1. **Site-Typ**: PHP-Seite in CloudPanel anlegen (Domain `teamverwaltung.viennastaterp.at`),
   passende PHP-Version wählen (8.1+). Document Root **nicht** anpassen — der
   CloudPanel-Standard (Projekt-Root) ist bereits korrekt.
2. Projektdateien in das von CloudPanel vorgegebene Verzeichnis hochladen/deployen
   (üblicherweise `/home/<site-user>/htdocs/<domain>/`).
3. Datei-Eigentümer prüfen: Alle hochgeladenen Dateien müssen dem Linux-Benutzer der
   CloudPanel-Site gehören (nicht `root`), sonst liefert nginx 403 —
   ggf. mit `chown -R <site-user>:<site-user> .` im Site-Verzeichnis korrigieren.
   Das ist nach einem 403 (bei korrektem Document Root) die wahrscheinlichste
   Ursache.
4. `.env` ins Site-Verzeichnis legen (nicht committen, siehe oben) und mit den
   echten Zugangsdaten befüllen.
5. **Cloudflare**: DNS-Eintrag zeigt bereits auf den Server; SSL/TLS-Modus in
   Cloudflare auf *Full* oder *Full (strict)* stellen, damit der Origin-Request von
   Cloudflare zu CloudPanel per HTTPS funktioniert (bei *Flexible* + erzwungenem
   HTTPS auf dem Origin kann es zu Redirect-Loops kommen).

## Discord-Integration einrichten

Kern-Zugangsdaten (Client ID/Secret, Bot-Token, Public Key, Guild ID) werden in der `.env`
eingetragen (siehe `.env.example`) — Status und Anleitung dazu auch direkt in der App unter
**Discord & Einstellungen**. Die vier Webhook-URLs (Besprechungs-Ankündigung, Aktivitäts-Log,
Bewerbungs-Benachrichtigung, Development-/Fehler-Log) sowie die Ankündigungs-Channel-ID lassen
sich **zusätzlich direkt im Panel** pflegen (Karte "Webhooks & Kanäle" unter *Discord &
Einstellungen*, Tabelle `app_settings`) — praktischer als nach jeder Änderung die `.env` auf
jedem Server manuell zu bearbeiten. Ein dort gesetzter Wert hat Vorrang vor der `.env`; leeres
Feld speichern setzt den Override zurück. Werte werden dabei genau wie in der `.env` optional
mit `APP_SECRET_KEY` verschlüsselt (`Env::encrypt()`/`decryptIfNeeded()`, siehe `Settings::set()`).
Bewusst **nicht** per UI editierbar bleiben die echten Kern-Zugangsdaten (Client-Secret,
Bot-Token, Public Key) — die stehen ausschließlich in der `.env`.

1. Im [Discord Developer Portal](https://discord.com/developers/applications) eine neue
   Anwendung erstellen.
2. Unter **OAuth2 → General**: *Client ID* und *Client Secret* in `DISCORD_CLIENT_ID`
   / `DISCORD_CLIENT_SECRET` eintragen.
3. Unter **OAuth2 → Redirects** genau folgende URL eintragen (ergibt sich aus
   `APP_URL`): `{APP_URL}/discord_callback.php`
4. Unter **General Information** den *Public Key* in `DISCORD_PUBLIC_KEY` eintragen
   und dort die *Interactions Endpoint URL* auf `{APP_URL}/discord_interactions.php`
   setzen (Discord prüft die URL beim Speichern sofort — `DISCORD_PUBLIC_KEY` muss
   also vorher gesetzt und die `.env` bereits deployt sein). Aktiviert Zu-/Absage-
   Buttons direkt unter Besprechungs-Ankündigungen, ganz ohne Portal-Login.
5. Unter **Bot** einen Bot erstellen, das *Bot Token* in `DISCORD_BOT_TOKEN`
   eintragen, und die **Server Members Intent** aktivieren (wird für den
   Mitglieder-Abgleich benötigt).
6. Den Bot über den OAuth2-URL-Generator (Scopes: `bot`) mit folgenden Berechtigungen
   auf den eigenen Server einladen: *Manage Roles*, *Manage Events*, *Send Messages*.
   Wichtig: Die Bot-Rolle muss in der Rollen-Hierarchie **über** allen Rollen stehen,
   die automatisch vergeben werden sollen.
7. Die Server (Guild) ID in `DISCORD_GUILD_ID` eintragen (Rechtsklick auf den
   Servernamen in Discord mit aktiviertem Entwicklermodus).
8. Optional `DISCORD_WEBHOOK_URL` (bevorzugt) oder `DISCORD_ANNOUNCE_CHANNEL_ID` für
   Besprechungs-Ankündigungen setzen.
9. Unter **Discord & Einstellungen** in der App die Discord-Rollen für „Team" und
   optional „High-Team" zuordnen (siehe unten) — „Team" bestimmt, wer per
   „Mitglieder synchronisieren" importiert wird und wer auf Besprechungen
   antworten darf.

Danach stehen zur Verfügung:

- **Login mit Discord** (OAuth2) auf der Anmeldeseite bzw. Verknüpfung im eigenen Profil
  — der Button ist immer sichtbar, auch bei der Ersteinrichtung (erster Discord-Login
  wird automatisch Super-Administrator).
- **Mitglieder aus Discord synchronisieren**: importiert nur Server-Mitglieder mit der
  Discord-Rolle „Team" als Teamverwaltung-Mitglieder (Button unter *Discord &
  Einstellungen*; ohne zugeordnete „Team"-Rolle werden alle Server-Mitglieder importiert).
- **„Team"-Rolle ist Voraussetzung, keine automatische Vergabe**: die Teamverwaltung
  vergibt/entfernt die „Team"-Rolle selbst nie — die wird ausschließlich manuell in
  Discord gepflegt. Sie ist stattdessen die Bedingung dafür, dass eine Person überhaupt
  synchronisiert wird (Import, Rang-Vergabe, Discord→Rang-Pull, RSVP-Berechtigung).
  Mitglieder ohne diese Rolle bleiben von allen Sync-Vorgängen unberührt.
- **Automatische Rollenvergabe in beide Richtungen** (jeweils nur für Mitglieder mit
  der „Team"-Rolle):
  - *Rang/Team → Discord*: jedem Rang und jedem Team kann eine Discord-Rolle zugeordnet
    werden (unter *Ränge* bzw. *Teams*); bei Änderung des Rangs/Teams eines Mitglieds
    (oder manuell per Klick) werden die zugeordneten Discord-Rollen gesetzt — aber nur,
    wenn das Mitglied auf Discord bereits die „Team"-Rolle hat. Es werden ausschließlich
    Rollen angefasst, die einem Rang/Team zugeordnet sind — alle anderen Discord-Rollen
    eines Mitglieds bleiben unangetastet.
  - *Discord → Rang*: beim Discord-Login (und per Massen-Sync) wird der Rang anhand der
    aktuellen Discord-Rollen ggf. hochgestuft, nie automatisch heruntergestuft.
  - „Team" und „High-Team" sind die beiden einzigen Zuteilungsrollen — ein Mitglied kann
    beide gleichzeitig haben, beide werden nie automatisch vergeben/entfernt, nur gelesen
    und als Badge angezeigt (`users.is_team` / `users.is_high_team`). Hat jemand
    „High-Team", aber (noch) nicht „Team", wird „Team" automatisch über den Bot
    nachgetragen (siehe oben) — die einzige Ausnahme.
  - Zusätzlich gibt es **Zusatzrollen** (Discord-Zusatzrechte, z. B. Administrator,
    Rollen verwalten, Ban, Kick, Timeout, Mute, Move) unter *Discord & Einstellungen* —
    reine Kennzeichnungen ohne jede Auswirkung auf Teamverwaltung-Berechtigungen, mehrere
    gleichzeitig pro Mitglied möglich, ebenfalls nie von der Teamverwaltung vergeben,
    nur gelesen und als Badge angezeigt (Tabellen `discord_perm_tags` / `user_perm_tags`).
- **Besprechungen**: beim Erstellen können optional eine Ankündigung in einen Discord-
  Channel (per Webhook oder Bot) gepostet und ein natives Discord Scheduled Event
  erstellt werden, das bei Bearbeitung/Absage automatisch mit aktualisiert wird. Die
  Ankündigung ist ein Embed (Vom/Bis zum/Ort/Thema/Inhalt) und pingt automatisch die
  Discord-Rollen aller eingeladenen Ränge (plus Team-Rolle bei team-gebundenen
  Besprechungen). Ein „Zum Meeting"-Button verlinkt immer ins Dashboard.
- **Teilnehmen/Absagen direkt in Discord, ohne Portal-Login**: hat die Ankündigungs-Nachricht
  zusätzlich Teilnehmen/Vielleicht/Absagen-Buttons. Verarbeitet wird ein Klick über den
  eigenständigen **`rsvp-bot/`**-Prozess (siehe `rsvp-bot/README.md`) — ein dauerhaft über
  Discords Gateway verbundener Node-Bot, kein HTTP-Callback von Discord an unseren Server
  nötig. Das ist bewusst kein Zufall: die frühere Variante über den HTTP-„Interactions
  Endpoint" (`discord_interactions.php`, jetzt inaktiv, Datei bleibt aus Referenzgründen im
  Projekt) verlangt eine Antwort von unserem Server innerhalb von 3 Sekunden — mit
  Cloudflare/nginx/PHP-FPM dazwischen ein unnötig fragiler Weg (siehe „hat nicht rechtzeitig
  reagiert"-Fehler). Der Bot legt bei Bedarf automatisch ein minimales Mitgliedskonto an,
  prüft die „Team"-Rolle sowie die Berechtigung `meetings.respond`, und antwortet ephemeral
  (nur für den Klickenden sichtbar) — exakt dieselbe Logik wie zuvor, nur über einen robusteren
  Zustellweg, analog zu `Discordbot_Follower`/`Discordbot_Ticket`. **Voraussetzung**:
  `rsvp-bot/` läuft (siehe dortige README) UND die *Interactions Endpoint URL* im Discord
  Developer Portal ist **leer** (sonst liefert Discord alles weiterhin per HTTP aus, egal ob
  der Bot per Gateway verbunden ist). `DISCORD_BOT_TOKEN` + `DISCORD_ANNOUNCE_CHANNEL_ID`
  bleiben trotzdem nötig, damit die Ankündigung überhaupt vom Bot (statt nur per Webhook)
  gepostet wird — ein reiner Kanal-Webhook liefert Klicks auf eigene Custom-IDs nicht
  zuverlässig aus.

Discord-Funktionen sind komplett optional — ohne Konfiguration funktioniert die
Teamverwaltung als reine Web-App mit Benutzername/Passwort-Login.

- **Anwesenheitserfassung**: unabhängig von der Zu-/Absage kann bei jedem Teilnehmer einer
  Besprechung (Berechtigung `meetings.manage`) nachträglich erfasst werden, ob er
  tatsächlich anwesend war. Wer die Berechtigung `meetings.view_attendance` hat, sieht die
  Zu-/Absagen-Liste einer Besprechung sowie eine Anwesenheitsstatistik pro Mitglied
  (X von Y erfassten Besprechungen, in %) im Mitglied-Formular; jedes Mitglied sieht seine
  eigene Statistik zusätzlich im eigenen Profil, unabhängig von der Berechtigung.
- **Stellvertreter-Antworten**: mit der Berechtigung `meetings.respond_for_others` kann man
  auf der Besprechungsseite für andere Mitglieder zu-/absagen (z. B. wenn die Abmeldung
  über Discord-Chat oder eine andere Plattform reinkommt statt über einen der offiziellen
  Wege).
- **Absage aktualisiert die Discord-Ankündigung**: statt die alte Nachricht unverändert
  stehen zu lassen, wird sie beim Absagen bearbeitet — großes rotes „❌ BESPRECHUNG
  ABGESAGT"-Embed, die Zu-/Absage-Buttons werden entfernt (der „Zum Meeting"-Link bleibt).
  Funktioniert unabhängig davon, ob ursprünglich per Bot-Kanal oder Webhook gepostet wurde.
- **Teilnehmer werden manuell ausgewählt**: beim Anlegen/Bearbeiten einer Besprechung gibt
  es eine Checkbox-Liste aller aktiven Mitglieder statt automatischer Team-Zuordnung — bei
  neuen Besprechungen initial alle angehakt, bei bestehenden die aktuelle Teilnehmerliste.
  Das Team-Feld im Formular dient nur noch als Grundlage für den Discord-Rollen-Ping in der
  Ankündigung.
- **Mitglieder können mehreren Teams angehören** (`user_teams`, n:m) — ersetzt die frühere
  1:1-Zuordnung; beim Discord-Rollen-Push werden alle zugeordneten Team-Rollen gesetzt.
- **Anwesenheits-Übersicht in der Mitgliederliste** (Berechtigung `meetings.view_attendance`):
  eigene Spalte zeigt pro Mitglied auf einen Blick, bei wie vielen erfassten Besprechungen es
  anwesend war (X/Y, in %) — die gleiche Statistik, die bisher nur einzeln im Mitglied-Formular
  bzw. im eigenen Profil einsehbar war, jetzt für das ganze Team auf einer Seite vergleichbar.
- **Schnelle Beförderung/Degradierung direkt in der Mitgliederliste** (`members.php`,
  Berechtigung `members.manage`): Rang-Dropdown pro Zeile, ändert bei Auswahl sofort den Rang
  (kein Umweg über das volle Mitglied-Formular nötig). Setzt automatisch die passende
  Discord-Rolle (aus `ranks.discord_role_id`, unter *Ränge* pro Rang festgelegt) und schreibt
  einen eigenen Eintrag ("🎉 … befördert" / "⬇️ … degradiert") ins Aktivitäts-Log inkl.
  Discord-Benachrichtigung.
- **Aus dem Team werfen** (`member_form.php`, Gefahrenzone): deaktiviert das Konto, entfernt
  Rang & alle Team-Zuordnungen und räumt die davon abhängigen Discord-Rollen auf (die
  "Team"-Rolle selbst bleibt wie überall unangetastet, rein manuelle Discord-Pflege).
- **Sperren/Entsperren** (`member_form.php`, unabhängig vom Aktiv-Status, reversibel):
  blockiert Login (Passwort und Discord) sowie Zu-/Absagen auf Besprechungen (Portal, Discord-
  Button, RSVP-Bot) und Neubewerbungen über die öffentliche Bewerbungsseite (best-effort per
  Discord-Tag-Abgleich). Setzt automatisch die dafür konfigurierte Discord-Rolle
  (`discord_extra_roles`, Slug `banned`) — bekannte Rollen-ID direkt vorbelegt, unter *Discord
  &amp; Einstellungen* änderbar. Eine laufende Session bricht beim Sperren sofort ab, nicht
  erst beim nächsten Login.

## Bewerbungs-Benachrichtigung in Discord

Optional postet jede neu eingegangene Bewerbung (`careers_apply.php`) ein Embed in einen
Discord-Kanal: `DISCORD_APPLICATIONS_WEBHOOK_URL` in der `.env` setzen. Gepingt werden die
Discord-Rollen der Ränge **„Teamleitung"** und **„Stv. Teamleitung"** — die Rollen-IDs kommen
aus `ranks.discord_role_id` (unter *Ränge* einstellbar, nicht in der `.env`), die Migration
trägt bekannte IDs einmalig vor, sofern dort noch keine gesetzt ist. Ohne Zuordnung wird nur
ohne Ping gepostet, ohne Webhook passiert gar nichts.

## Development-/Fehler-Log in Discord

Optional werden unerwartete technische Fehler — uncaught Exceptions, PHP-Warnings/-Errors,
Fatal Errors — automatisch in einen eigenen Discord-Kanal gepostet, getrennt vom
Aktivitäts-Log (das ist für normale Nutzeraktionen, nicht für Bugs): `DISCORD_DEV_LOG_WEBHOOK_URL`
in der `.env` setzen. Die Handler dafür (`set_exception_handler`, `set_error_handler`,
`register_shutdown_function`) werden in `bootstrap.php` registriert, noch vor der ersten
DB-Verbindung — selbst ein DB-Verbindungsfehler wird also noch gemeldet. Läuft immer
zusätzlich zum normalen PHP-Error-Log, nie stattdessen, und blockiert bei einem Problem mit
dem Webhook selbst nie die Anwendung (best-effort, 4s Timeout, Exceptions werden verschluckt).

## Aktivitäts-Log in Discord

Optional lässt sich jede protokollierte Aktion zusätzlich als Embed in einen Discord-Kanal
posten (z. B. „📳║teambot-log"): `DISCORD_LOG_WEBHOOK_URL` in der `.env` setzen (Kanal-
Einstellungen → Integrationen → Webhooks → Neuer Webhook). Ohne diese Variable läuft alles
wie bisher, nur ohne Discord-Benachrichtigung — reines Opt-in.

`audit_log()` (`src/helpers.php`) ist die einzige Stelle, die sowohl in die interne
`audit_log`-Tabelle schreibt als auch (best-effort, 4s Timeout, blockiert nie die eigentliche
Aktion) an `DiscordClient::postLogEvent()` weiterreicht. Bereits verdrahtet für: Mitglieder-
anlage/-bearbeitung/-deaktivierung, Team- und Rang-Verwaltung (inkl. Berechtigungsänderungen),
Besprechungen (anlegen/bearbeiten/absagen/löschen), Zu-/Absagen (Portal, Discord-Button,
Stellvertretung), Anwesenheitserfassung, Discord-Sync-Aktionen, Bewerbungs-Workflow (neue
Bewerbung, Gesprächseinladung, Terminbuchung, Annahme/Ablehnung), Stellenausschreibungen
(anlegen/bearbeiten/öffnen/schließen/löschen), Deployments sowie Logins (Benutzername/Passwort,
Discord, Discord-Verknüpfung, Ersteinrichtung). Weitere Aktionen lassen sich genau gleich per
`audit_log('bereich.aktion', 'Beschreibung')` ergänzen.

## Deployment per Klick (git pull)

Unter **Discord & Einstellungen** gibt es — nach dem Vorbild des
Discordbot_Follower-Webpanels ("Deployen (git pull)") — einen Button
**„🚀 Jetzt deployen"**: holt per `git fetch` + Fast-Forward-Merge den neuesten
Stand vom Remote-Branch direkt ins Live-Verzeichnis. Genau wie beim
Follower-Bot **fast-forward-only**: Gibt es auf dem Server lokale Änderungen,
bricht der Vorgang sauber ab (Fehlermeldung statt stillem Überschreiben) und
verlangt manuellen Eingriff. Für PHP-Dateien entfällt ein Prozess-Neustart —
werden pro Request neu eingelesen, ein Deploy wirkt also sofort. Ändert sich dabei
etwas unter `rsvp-bot/` (siehe unten), wird zusätzlich automatisch
`pm2 restart teamverwaltung-rsvp-bot` ausgeführt (best-effort — falls PM2 dafür
noch nicht eingerichtet ist, erscheint stattdessen ein Hinweis zum manuellen
Neustart, der eigentliche Deploy gilt trotzdem als erfolgreich).

Jeder Klick wird zusätzlich zum Aktivitäts-Log auch in den Development-/Fehler-Log-Kanal
gepostet (grün bei Erfolg, rot bei Fehlschlag mit dem Grund) — so ist im gleichen Kanal wie
technische Fehler auch sofort sichtbar, wann zuletzt deployt wurde und ob es geklappt hat.

**Voraussetzung:** Das von CloudPanel vorgegebene Site-Verzeichnis muss ein
Git-Checkout dieses Repos mit konfiguriertem `origin`-Remote sein (statt nur
hochgeladener Dateien, siehe Deployment-Abschnitt oben) — z. B. einmalig per
`git clone <repo-url> .` ins Site-Verzeichnis (Achtung: `.env` danach erneut
anlegen, `.gitignore` schließt sie aus). Außerdem müssen `git` sowie PHPs
`proc_open()` auf dem Server verfügbar sein (bei manchen Shared-Hosting-Setups
über `disable_functions` deaktiviert — der Button zeigt in dem Fall eine klare
Fehlermeldung statt eines Serverfehlers). Jede Nutzung wird zusätzlich im
internen Änderungsprotokoll (`audit_log`) festgehalten.

## Öffentliche Bewerbungsseite

Unter `careers.php` (kein Login nötig) werden alle offenen Stellenausschreibungen
gelistet. Der komplette Ablauf:

1. **Ausschreibungen verwalten** (`job_postings.php`, Berechtigung
   `applications.manage`): Titel, Beschreibung, optionales Team (wird bei Annahme
   automatisch dem neuen Mitglied zugeordnet) und optionale Zusatzfragen (eine pro
   Zeile) — diese werden Bewerber:innen zusätzlich zu Name, Alter, Discord-Tag,
   Motivation und Verfügbarkeit gestellt. Jede Ausschreibung bekommt automatisch einen
   eindeutigen Slug für die öffentliche URL.
2. **Bewerben** (`careers_apply.php?job=<slug>`): öffentliches Formular ohne Login,
   landet als Bewerbung mit Status „Neu" im internen Dashboard.
3. **Bewerbungen sichten** (`applications.php`, `application_view.php`, gleiche
   Berechtigung): Filter nach Stelle/Status, pro Bewerbung Motivation/Antworten
   einsehen, interne Notiz hinterlegen, und entweder **zum Gespräch einladen**,
   **ablehnen** oder direkt **annehmen**.
4. **Bewerbungsgespräch-Termine** (im Bearbeiten-Formular einer Ausschreibung): das
   Team legt freie Zeitslots an. Nach einer Einladung erscheint auf der
   Bewerbungs-Detailseite ein individueller, nicht erratbarer Buchungslink
   (`careers_booking.php?token=…`) — es gibt **keinen automatischen Versand**, der
   Link muss manuell (z. B. per Discord-DM) weitergegeben werden. Über den Link wählt
   sich die Person selbst einen der offenen Slots (Mini-Calendly-Prinzip, race-safe:
   ein Slot kann nicht doppelt gebucht werden).
5. **Annahme**: legt automatisch ein aktives Mitgliedskonto an (niedrigster Rang,
   zugeordnetes Team der Ausschreibung falls gesetzt). Ist ein Discord-Bot
   konfiguriert, wird zusätzlich versucht, den eingegebenen Discord-Tag eindeutig
   einem Server-Mitglied zuzuordnen (`DiscordClient::findGuildMemberByTag`) — nur bei
   genau einem Treffer, damit ein späterer Discord-Login der Person automatisch mit
   diesem Konto verknüpft wird, statt ein zweites anzulegen. Ohne eindeutigen Treffer
   bleibt das Konto ohne `discord_id` und muss manuell verknüpft werden.

## Impressum & Datenschutzerklärung

`impressum.php` und `datenschutz.php` sind ohne Login erreichbar (Pflicht nach § 5 ECG/TMG)
und im Footer jeder Seite verlinkt. Betreiberdaten (ViennaStateRP, Anschrift, Kontakt,
zuständige Datenschutzbehörde) sind identisch zu denen im Schwester-Projekt
`ViennaStateRP/Website` übernommen — dieselbe Organisation. Die Datenschutzerklärung
beschreibt die tatsächlichen Datenflüsse dieser Anwendung (Server-Logs, Discord-OAuth mit
minimalem `identify`-Scope, Discord-Rollen-Abgleich, Besprechungs-/Anwesenheitsdaten,
Notizen, Änderungsprotokoll) und braucht **keinen Cookie-Banner**, da nur ein technisch
notwendiger Session-Cookie gesetzt wird (kein Tracking/Analytics).

**Wichtig, bevor das live geht:** Das ist eine sorgfältig auf diese Anwendung zugeschnittene
Vorlage, aber keine Rechtsberatung. Vor dem produktiven Einsatz prüfen/anpassen:
- Ob die übernommenen Betreiberdaten weiterhin aktuell sind.
- Den Namen des tatsächlichen Hosting-Anbieters (aktuell generisch als „unser
  Hosting-Anbieter" formuliert) und ob ein Auftragsverarbeitungsvertrag (AVV) mit ihm
  besteht.
- Die tatsächliche Log-Aufbewahrungsdauer bei eurem Hoster/CloudPanel-Setup.
- Ob Discords aktuelle Datenschutzbedingungen weiterhin so referenziert werden können.
Im Zweifel von einer Juristin/einem Datenschutzbeauftragten gegenlesen lassen.

## Berechtigungen

Berechtigungen werden über **Ränge** vergeben (Verwaltung unter *Ränge*) — jede
Berechtigung ist unabhängig pro Rang als Checkbox togglebar, keine ist fest verdrahtet:

- `members.manage` – Mitglieder anlegen/bearbeiten/deaktivieren
- `meetings.manage` – Besprechungen anlegen/bearbeiten/absagen
- `meetings.respond` – auf Besprechungen antworten (Zu-/Vielleicht/Absagen), sowohl im
  Portal als auch über die Discord-Buttons
- `meetings.view_attendance` – Zu-/Absagen-Liste & Anwesenheitsstatistik einsehen,
  Anwesenheit erfassen (zusammen mit `meetings.manage`)
- `meetings.respond_for_others` – für andere Mitglieder zu-/absagen (Stellvertretung)
- `teams.manage` – Teams verwalten
- `ranks.manage` – Ränge & Berechtigungen verwalten
- `discord.manage` – Discord-Sync auslösen (Einstellungen selbst kommen aus `.env`)
- `applications.manage` – Stellenausschreibungen verwalten, Bewerbungen sichten/annehmen/ablehnen

Neue Berechtigungen fügt man in `src/Perm.php` (`Perm::all()`) hinzu — der Rang-Editor
(`ranks.php`) und alle Prüfungen (`Perm::has()`) sind vollständig generisch und zeigen
jede dort gelistete Berechtigung automatisch als Checkbox an.

Das bei der Ersteinrichtung angelegte Konto ist immer Super-Administrator und hat
unabhängig vom zugewiesenen Rang alle Rechte.

## Projektstruktur

```
bootstrap.php     Zentrales Bootstrapping (Session, .env, DB, Klassen, setzt APP_BOOTSTRAPPED)
index.php, login.php, meetings.php, …   Aufrufbare Seiten, liegen direkt im Root (wie bei Website)
discord_interactions.php   Frueherer Discord-Button-HTTP-Endpunkt, inaktiv seit rsvp-bot/ (siehe dort)
rsvp-bot/         Eigenstaendiger Node-Prozess: nimmt RSVP-Button-Klicks per Discord-Gateway entgegen (siehe rsvp-bot/README.md)
assets/           CSS/JS, öffentlich
src/              PHP-Klassen (Env, DB, Auth, Perm, Settings, DiscordClient) – per APP_BOOTSTRAPPED-Guard geschützt
includes/         Layout-Header/Footer – per APP_BOOTSTRAPPED-Guard geschützt
router.php        Nur für "php -S" im lokalen Dev-Betrieb (bildet .htaccess-Regeln nach)
.env              Zugangsdaten (DB + Discord, teils ENC:-verschlüsselt) – NICHT committen
.env.example      Vorlage für .env
encrypt_env.php   CLI-Tool zum Verschlüsseln einzelner Werte für die .env
```
