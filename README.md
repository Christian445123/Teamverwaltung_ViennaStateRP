# Teamverwaltung

Webanwendung zur Verwaltung eines Teams: Mitglieder, Ränge/Berechtigungen, Teams,
Besprechungen mit RSVP sowie eine tiefe Discord-Integration (Login, automatische
Rollenvergabe, Mitglieder-Sync, Ankündigungen und Discord-Events für Besprechungen).

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
eine Datei **außerhalb** des Document Roots legen, z. B.
`/home/<site>/teamverwaltung.key` (eine Ebene über `public/`, wenn `DOCUMENT_ROOT`
auf `public/` zeigt). Pfad optional über `APP_KEY_FILE` überschreiben. Damit steht
selbst der Master-Key nicht mehr in einer Datei, die je nach Deployment mit
kopiert/gebackupt wird.

## Lokal starten

```bash
php -S localhost:8000 -t public
```

Dann `http://localhost:8000` öffnen. Beim ersten Aufruf wird ein
Ersteinrichtungs-Formular angezeigt, um das erste Administrator-Konto anzulegen.

## Deployment (Apache/Nginx/XAMPP)

Das Document Root muss auf den Ordner `public/` zeigen — die Ordner `src/`,
`includes/` sowie die `.env` liegen bewusst außerhalb und sind damit nicht direkt
über den Browser erreichbar. `public/.htaccess` ist ein reines Apache-Feature —
unter nginx/CloudPanel (siehe unten) wirkungslos, aber auch nicht nötig, weil die
geschützten Dateien dort ohnehin außerhalb des Document Roots liegen.

## Deployment mit CloudPanel + Cloudflare (wie bei `ViennaStateRP/Website`)

CloudPanel nutzt intern nginx + PHP-FPM (kein Apache, `.htaccess` wird nicht
ausgewertet). Wichtig beim Anlegen der Seite in CloudPanel:

1. **Site-Typ**: PHP-Seite in CloudPanel anlegen (Domain `teamverwaltung.viennastaterp.at`),
   passende PHP-Version wählen (8.1+).
2. Projektdateien in das von CloudPanel vorgegebene Verzeichnis hochladen/deployen
   (üblicherweise `/home/<site-user>/htdocs/<domain>/`).
3. **Document Root auf `public/` umstellen** — das ist der häufigste Grund für ein
   „403 Forbidden“ direkt von nginx (leeres/kein `index.php` im eigentlichen
   Root, da `index.php` bei uns unter `public/` liegt): In CloudPanel unter
   *Sites → (Seite auswählen) → Vhost* die `root`-Direktive der PHP-Site auf
   `.../htdocs/<domain>/public` anpassen (CloudPanel erlaubt das Bearbeiten des
   Vhost-Templates pro Seite).
4. Datei-Eigentümer prüfen: Alle hochgeladenen Dateien müssen dem Linux-Benutzer der
   CloudPanel-Site gehören (nicht `root`), sonst liefert nginx ebenfalls 403 —
   ggf. mit `chown -R <site-user>:<site-user> .` im Site-Verzeichnis korrigieren.
5. `.env` außerhalb `public/` ins Site-Verzeichnis legen (nicht committen, siehe
   oben) und mit den echten Zugangsdaten befüllen.
6. **Cloudflare**: DNS-Eintrag zeigt bereits auf den Server; SSL/TLS-Modus in
   Cloudflare auf *Full* oder *Full (strict)* stellen, damit der Origin-Request von
   Cloudflare zu CloudPanel per HTTPS funktioniert (bei *Flexible* + erzwungenem
   HTTPS auf dem Origin kann es zu Redirect-Loops kommen).

Ein „403 Forbidden“ direkt von nginx (nicht von Cloudflare) bedeutet: die Anfrage
kommt am Server an, aber nginx findet keine ausführbare Datei am konfigurierten
Root — meistens Punkt 3 oder 4 oben.

## Discord-Integration einrichten

Alle Werte werden in der `.env` eingetragen (siehe `.env.example`), nicht über die
Weboberfläche — Status und Anleitung dazu auch direkt in der App unter
**Discord & Einstellungen**.

1. Im [Discord Developer Portal](https://discord.com/developers/applications) eine neue
   Anwendung erstellen.
2. Unter **OAuth2 → General**: *Client ID* und *Client Secret* in `DISCORD_CLIENT_ID`
   / `DISCORD_CLIENT_SECRET` eintragen.
3. Unter **OAuth2 → Redirects** genau folgende URL eintragen (ergibt sich aus
   `APP_URL`): `{APP_URL}/discord_callback.php`
4. Unter **Bot** einen Bot erstellen, das *Bot Token* in `DISCORD_BOT_TOKEN`
   eintragen, und die **Server Members Intent** aktivieren (wird für den
   Mitglieder-Abgleich benötigt).
5. Den Bot über den OAuth2-URL-Generator (Scopes: `bot`) mit folgenden Berechtigungen
   auf den eigenen Server einladen: *Manage Roles*, *Manage Events*, *Send Messages*.
   Wichtig: Die Bot-Rolle muss in der Rollen-Hierarchie **über** allen Rollen stehen,
   die automatisch vergeben werden sollen.
6. Die Server (Guild) ID in `DISCORD_GUILD_ID` eintragen (Rechtsklick auf den
   Servernamen in Discord mit aktiviertem Entwicklermodus).
7. Optional `DISCORD_WEBHOOK_URL` (bevorzugt) oder `DISCORD_ANNOUNCE_CHANNEL_ID` für
   Besprechungs-Ankündigungen setzen.

Danach stehen zur Verfügung:

- **Login mit Discord** (OAuth2) auf der Anmeldeseite bzw. Verknüpfung im eigenen Profil
- **Mitglieder aus Discord synchronisieren** (importiert Server-Mitglieder als
  Teamverwaltung-Mitglieder, Button unter *Discord & Einstellungen*)
- **Automatische Rollenvergabe**: jedem Rang und jedem Team kann eine Discord-Rolle
  zugeordnet werden (unter *Ränge* bzw. *Teams*); bei Änderung des Rangs/Teams eines
  Mitglieds (oder manuell per Klick) werden die zugeordneten Discord-Rollen gesetzt.
  Es werden ausschließlich Rollen angefasst, die einem Rang/Team zugeordnet sind —
  alle anderen Discord-Rollen eines Mitglieds bleiben unangetastet.
- **Besprechungen**: beim Erstellen können optional eine Ankündigung in einen Discord-
  Channel (per Webhook oder Bot) gepostet und ein natives Discord Scheduled Event
  erstellt werden, das bei Bearbeitung/Absage automatisch mit aktualisiert wird.

Discord-Funktionen sind komplett optional — ohne Konfiguration funktioniert die
Teamverwaltung als reine Web-App mit Benutzername/Passwort-Login.

## Berechtigungen

Berechtigungen werden über **Ränge** vergeben (Verwaltung unter *Ränge*):

- `members.manage` – Mitglieder anlegen/bearbeiten/deaktivieren
- `meetings.manage` – Besprechungen anlegen/bearbeiten/absagen
- `teams.manage` – Teams verwalten
- `ranks.manage` – Ränge & Berechtigungen verwalten
- `discord.manage` – Discord-Sync auslösen (Einstellungen selbst kommen aus `.env`)

Das bei der Ersteinrichtung angelegte Konto ist immer Super-Administrator und hat
unabhängig vom zugewiesenen Rang alle Rechte.

## Projektstruktur

```
bootstrap.php     Zentrales Bootstrapping (Session, .env, DB, Klassen)
src/               PHP-Klassen (Env, DB, Auth, Perm, Settings, DiscordClient) – nicht öffentlich
includes/          Layout-Header/Footer – nicht öffentlich
public/            Document Root: alle aufrufbaren Seiten + Assets
.env               Zugangsdaten (DB + Discord, teils ENC:-verschlüsselt) – NICHT committen
.env.example       Vorlage für .env
encrypt_env.php    CLI-Tool zum Verschlüsseln einzelner Werte für die .env
```
