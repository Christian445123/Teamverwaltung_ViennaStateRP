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
2. Datenbank-Zugangsdaten eintragen (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
   `DB_PASS`) — die Datenbank selbst über phpMyAdmin anlegen, **Tabellen müssen nicht
   manuell erstellt werden**: Die App legt beim ersten Aufruf automatisch alle
   benötigten Tabellen an (`src/Database.php`).
3. `APP_URL` setzen (Basis-URL der App, ohne abschließenden Slash), z. B.
   `http://localhost:8000` lokal oder `https://team.deine-domain.tld` produktiv.
4. Optional Discord-Zugangsdaten eintragen (siehe unten).
5. Die `.env` **niemals committen** — sie ist bereits in `.gitignore` eingetragen.

## Lokal starten

```bash
php -S localhost:8000 -t public
```

Dann `http://localhost:8000` öffnen. Beim ersten Aufruf wird ein
Ersteinrichtungs-Formular angezeigt, um das erste Administrator-Konto anzulegen.

## Deployment (Apache/Nginx/XAMPP)

Das Document Root muss auf den Ordner `public/` zeigen — die Ordner `src/`,
`includes/` sowie die `.env` liegen bewusst außerhalb und sind damit nicht direkt
über den Browser erreichbar.

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
bootstrap.php   Zentrales Bootstrapping (Session, .env, DB, Klassen)
src/            PHP-Klassen (Env, DB, Auth, Perm, Settings, DiscordClient) – nicht öffentlich
includes/       Layout-Header/Footer – nicht öffentlich
public/         Document Root: alle aufrufbaren Seiten + Assets
.env            Zugangsdaten (DB + Discord) – NICHT committen
.env.example    Vorlage für .env
```
