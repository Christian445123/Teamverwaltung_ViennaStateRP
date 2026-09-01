# Teamverwaltung

Webanwendung zur Verwaltung eines Teams: Mitglieder, Ränge/Berechtigungen, Teams,
Besprechungen mit RSVP sowie eine tiefe Discord-Integration (Login, automatische
Rollenvergabe, Mitglieder-Sync, Ankündigungen und Discord-Events für Besprechungen).

Technisch: reines PHP (keine Frameworks/Composer nötig) mit SQLite-Datenbank.

## Voraussetzungen

- PHP 8.1 oder neuer
- Aktivierte PHP-Erweiterungen: `pdo_sqlite`, `curl`, `mbstring` (in den meisten
  Standard-Installationen von XAMPP/Laragon/WAMP bereits aktiv)

## Lokal starten

```bash
php -S localhost:8000 -t public
```

Dann `http://localhost:8000` öffnen. Beim ersten Aufruf wird ein
Ersteinrichtungs-Formular angezeigt, um das erste Administrator-Konto anzulegen.

## Deployment (Apache/Nginx/XAMPP)

Das Document Root muss auf den Ordner `public/` zeigen — die Ordner `src/`, `includes/`
und `storage/` (enthält die SQLite-Datenbank) liegen bewusst außerhalb und sind damit
nicht direkt über den Browser erreichbar.

Die Datenbankdatei `storage/app.sqlite` wird beim ersten Aufruf automatisch angelegt.
Der Webserver-Benutzer benötigt Schreibrechte auf den Ordner `storage/`.

## Discord-Integration einrichten

1. Im [Discord Developer Portal](https://discord.com/developers/applications) eine neue
   Anwendung erstellen.
2. Unter **OAuth2 → General**: *Client ID* und *Client Secret* kopieren.
3. Unter **OAuth2 → Redirects** genau folgende URL eintragen (in der App unter
   *Einstellungen → App-URL* muss dieselbe Basis-URL hinterlegt sein):
   `https://deine-domain.tld/discord_callback.php`
4. Unter **Bot** einen Bot erstellen, das *Bot Token* kopieren, und die
   **Server Members Intent** aktivieren (wird für den Mitglieder-Abgleich benötigt).
5. Den Bot über den OAuth2-URL-Generator (Scopes: `bot`) mit folgenden Berechtigungen
   auf den eigenen Server einladen: *Manage Roles*, *Manage Events*, *Send Messages*.
   Wichtig: Die Bot-Rolle muss in der Rollen-Hierarchie **über** allen Rollen stehen,
   die automatisch vergeben werden sollen.
6. Die Server (Guild) ID kopieren (Rechtsklick auf den Servernamen in Discord mit
   aktiviertem Entwicklermodus).
7. Alle Werte in der Teamverwaltung unter **Discord & Einstellungen** eintragen und
   speichern.

Danach stehen zur Verfügung:

- **Login mit Discord** (OAuth2) auf der Anmeldeseite bzw. Verknüpfung im eigenen Profil
- **Mitglieder aus Discord synchronisieren** (importiert Server-Mitglieder als
  Teamverwaltung-Mitglieder)
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
- `discord.manage` – Discord-Einstellungen & Sync verwalten

Das bei der Ersteinrichtung angelegte Konto ist immer Super-Administrator und hat
unabhängig vom zugewiesenen Rang alle Rechte.

## Projektstruktur

```
bootstrap.php        Zentrales Bootstrapping (Session, DB, Autoload der Klassen)
src/                  PHP-Klassen (DB, Auth, Perm, Settings, DiscordClient) – nicht öffentlich
includes/             Layout-Header/Footer – nicht öffentlich
public/               Document Root: alle aufrufbaren Seiten + Assets
storage/              SQLite-Datenbank (wird automatisch angelegt) – nicht öffentlich
```
