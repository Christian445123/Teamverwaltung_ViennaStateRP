<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

class DB
{
    // Bei jeder inhaltlichen Änderung an migrate() (neue Tabelle/Spalte/Backfill) hochzählen.
    private const SCHEMA_VERSION = 7;

    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    private static function connect(): PDO
    {
        Env::load();

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'teamverwaltung');
        $user = Env::get('DB_USER', '');
        $pass = Env::get('DB_PASS', '');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec("SET NAMES {$charset} COLLATE utf8mb4_unicode_ci");

        return $pdo;
    }

    private static function migrate(PDO $db): void
    {
        // Schema-Versionsprüfung: alle Checks/Backfills unten liefen bisher bei JEDEM Request
        // erneut (mehrere CREATE TABLE IF NOT EXISTS, SHOW COLUMNS, Backfill-Schleifen über
        // alle Ränge) — für die meisten Requests unauffällig, aber für den Discord Interactions
        // Endpoint (discord_interactions.php) zu langsam: Discord verlangt eine Antwort
        // innerhalb von 3 Sekunden, sonst zeigt der Client "hat nicht rechtzeitig reagiert".
        // Ab jetzt läuft die komplette Migration nur einmal (bis SCHEMA_VERSION erhöht wird),
        // danach kostet dieser Aufruf nur noch die eine Abfrage unten.
        $db->exec("CREATE TABLE IF NOT EXISTS schema_meta (
            id TINYINT UNSIGNED NOT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $currentVersion = (int) ($db->query("SELECT version FROM schema_meta WHERE id = 1")->fetchColumn() ?: 0);
        if ($currentVersion >= self::SCHEMA_VERSION) {
            return;
        }

        // Für ausgewählte Discord-Einstellungen (Webhooks, Ankündigungs-Channel-ID) per UI
        // überschreibbar statt nur in der .env (siehe Settings::DB_OVERRIDABLE) — Werte hier
        // haben Vorrang vor der .env, ein leeres/fehlendes Feld fällt auf die .env zurück.
        // Sensible Werte werden wie in der .env optional mit "ENC:"-Präfix verschlüsselt
        // gespeichert (Env::encrypt()/decryptIfNeeded()), echte Kern-Zugangsdaten (Bot-Token,
        // Client-Secret, Public Key) bleiben bewusst ausschließlich in der .env.
        $db->exec("CREATE TABLE IF NOT EXISTS app_settings (
            `key` VARCHAR(100) NOT NULL,
            value TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS ranks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            level INT NOT NULL DEFAULT 0,
            color VARCHAR(7) NOT NULL DEFAULT '#5865F2',
            permissions TEXT NOT NULL,
            discord_role_id VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS teams (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            discord_role_id VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(100) NULL,
            password_hash VARCHAR(255) NULL,
            display_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NULL,
            discord_id VARCHAR(32) NULL,
            discord_username VARCHAR(100) NULL,
            discord_avatar VARCHAR(100) NULL,
            rank_id INT UNSIGNED NULL,
            team_id INT UNSIGNED NULL,
            is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            is_high_team TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_username (username),
            UNIQUE KEY uniq_discord_id (discord_id),
            KEY idx_rank (rank_id),
            KEY idx_team (team_id),
            CONSTRAINT fk_users_rank FOREIGN KEY (rank_id) REFERENCES ranks(id) ON DELETE SET NULL,
            CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // is_high_team / is_team wurden nachträglich ergänzt — auf bereits bestehenden
        // Installationen per ALTER nachziehen (CREATE TABLE IF NOT EXISTS greift dort nicht mehr).
        $existingUserCols = $db->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_high_team', $existingUserCols, true)) {
            $db->exec("ALTER TABLE `users` ADD COLUMN `is_high_team` TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!in_array('is_team', $existingUserCols, true)) {
            $db->exec("ALTER TABLE `users` ADD COLUMN `is_team` TINYINT(1) NOT NULL DEFAULT 0");
        }

        // Mehrfach-Teams pro Mitglied: users.team_id blieb aus Kompatibilität in der Tabelle
        // stehen, ist aber nicht mehr die Quelle der Wahrheit — user_teams löst es ab (n:m).
        // Bestehende single-team-Zuordnungen werden einmalig übernommen.
        $db->exec("CREATE TABLE IF NOT EXISTS user_teams (
            user_id INT UNSIGNED NOT NULL,
            team_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, team_id),
            KEY idx_ut_team (team_id),
            CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_ut_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->exec("INSERT IGNORE INTO user_teams (user_id, team_id) SELECT id, team_id FROM users WHERE team_id IS NOT NULL");

        $db->exec("CREATE TABLE IF NOT EXISTS discord_extra_roles (
            slug VARCHAR(20) NOT NULL,
            label VARCHAR(50) NOT NULL,
            discord_role_id VARCHAR(32) NULL,
            auto_assign TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $extraRolesCount = (int) $db->query("SELECT COUNT(*) c FROM discord_extra_roles")->fetch()['c'];
        if ($extraRolesCount === 0) {
            $stmt = $db->prepare("INSERT INTO discord_extra_roles (slug, label, discord_role_id, auto_assign) VALUES (?, ?, ?, ?)");
            // "Team": Mitgliedschaft wird ausschließlich manuell in Discord gepflegt, NIE von der
            // Teamverwaltung vergeben/entfernt (auto_assign=0) — dient nur als Voraussetzung:
            // Rang-Rollen werden über das Dashboard nur an Personen mit dieser Rolle zugewiesen,
            // und nur Personen mit dieser Rolle werden überhaupt synchronisiert (Mitglieder-Import,
            // Discord→Rang-Sync). Bekannte Rollen-ID direkt vorbelegt, per UI unter
            // Discord & Einstellungen änderbar.
            $stmt->execute(['team', 'Team', '1520871424871633036', 0]);
            // "High-Team": wird NIE automatisch vergeben/entfernt (nur manuell in Discord gepflegt),
            // aber der aktuelle Status wird beim Rollen-Sync gelesen und in users.is_high_team gespiegelt.
            $stmt->execute(['high_team', 'High-Team', null, 0]);
        }

        // "Team" wurde ursprünglich automatisch vergeben (auto_assign=1) — das ist jetzt bewusst
        // deaktiviert (siehe oben), Bestandsinstallationen einmalig nachziehen. Die Rollen-ID wird
        // nur nachgetragen, falls noch keine gesetzt ist (ein bereits per UI gewählter Wert bleibt
        // unangetastet).
        $db->exec("UPDATE discord_extra_roles SET auto_assign = 0 WHERE slug = 'team'");
        $db->prepare("UPDATE discord_extra_roles SET discord_role_id = ? WHERE slug = 'team' AND discord_role_id IS NULL")
            ->execute(['1520871424871633036']);

        // Zusatzrollen (Discord-Zusatzrechte): rein informative Kennzeichnungen ohne jede
        // Auswirkung auf Teamverwaltung-Berechtigungen — werden ausschließlich manuell in
        // Discord gepflegt und nur gelesen/gespiegelt (nie von der Teamverwaltung vergeben),
        // exakt wie "Team"/"High-Team". Mehrere gleichzeitig pro Mitglied möglich.
        $db->exec("CREATE TABLE IF NOT EXISTS discord_perm_tags (
            slug VARCHAR(30) NOT NULL,
            label VARCHAR(50) NOT NULL,
            discord_role_id VARCHAR(32) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $permTagsCount = (int) $db->query("SELECT COUNT(*) c FROM discord_perm_tags")->fetch()['c'];
        if ($permTagsCount === 0) {
            $stmt = $db->prepare("INSERT INTO discord_perm_tags (slug, label, sort_order) VALUES (?, ?, ?)");
            $wantedPermTags = [
                ['administrator', 'Administrator'],
                ['manage_roles', 'Rollen verwalten'],
                ['ban', 'Ban'],
                ['kick', 'Kick'],
                ['timeout', 'Timeout'],
                ['mute', 'Mute'],
                ['move', 'Move'],
            ];
            foreach ($wantedPermTags as $i => [$slug, $label]) {
                $stmt->execute([$slug, $label, $i]);
            }
        }

        // Aktueller Stand pro Mitglied: welche der obigen Zusatzrollen es GERADE auf Discord
        // hat (voller Spiegel, kein Additiv/Nie-runterstufen wie beim Rang — reine Momentaufnahme).
        $db->exec("CREATE TABLE IF NOT EXISTS user_perm_tags (
            user_id INT UNSIGNED NOT NULL,
            tag_slug VARCHAR(30) NOT NULL,
            PRIMARY KEY (user_id, tag_slug),
            CONSTRAINT fk_upt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS meetings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            location VARCHAR(255) NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NULL,
            team_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            discord_event_id VARCHAR(32) NULL,
            discord_channel_id VARCHAR(32) NULL,
            discord_message_id VARCHAR(32) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_start (start_time),
            KEY idx_meetings_team (team_id),
            CONSTRAINT fk_meetings_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
            CONSTRAINT fk_meetings_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS meeting_attendees (
            meeting_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            responded_at DATETIME NULL,
            attended TINYINT(1) NULL,
            PRIMARY KEY (meeting_id, user_id),
            KEY idx_ma_user (user_id),
            CONSTRAINT fk_ma_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
            CONSTRAINT fk_ma_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // attended wurde nachträglich ergänzt (NULL = nicht erfasst, 1 = anwesend, 0 = nicht
        // anwesend) — echte Anwesenheit, unabhängig von der vorherigen Zu-/Absage.
        $existingAttendeeCols = $db->query("SHOW COLUMNS FROM `meeting_attendees`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('attended', $existingAttendeeCols, true)) {
            $db->exec("ALTER TABLE `meeting_attendees` ADD COLUMN `attended` TINYINT(1) NULL");
        }

        // Öffentliche Bewerbungsseite: Stellenausschreibungen, Bewerbungen samt individueller
        // Zusatzfragen, und Bewerbungsgespräch-Terminslots (Self-Service-Buchung ohne Login).
        $db->exec("CREATE TABLE IF NOT EXISTS job_postings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            slug VARCHAR(220) NOT NULL,
            description TEXT NULL,
            team_id INT UNSIGNED NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_slug (slug),
            KEY idx_jp_status (status),
            CONSTRAINT fk_jp_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
            CONSTRAINT fk_jp_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // "Dein Profil"-Anforderungstext und Banner-Bild wurden nachträglich ergänzt (Vorbild:
        // GalaxyBot-Stellenanzeigen-Layout) — auf bereits bestehenden Installationen per ALTER
        // nachziehen, da CREATE TABLE IF NOT EXISTS dort nicht mehr greift.
        $existingJobPostingCols = $db->query("SHOW COLUMNS FROM `job_postings`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('requirements', $existingJobPostingCols, true)) {
            $db->exec("ALTER TABLE `job_postings` ADD COLUMN `requirements` TEXT NULL AFTER `description`");
        }
        if (!in_array('image_url', $existingJobPostingCols, true)) {
            $db->exec("ALTER TABLE `job_postings` ADD COLUMN `image_url` VARCHAR(500) NULL AFTER `requirements`");
        }

        $db->exec("CREATE TABLE IF NOT EXISTS job_posting_questions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            posting_id INT UNSIGNED NOT NULL,
            label VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_jpq_posting (posting_id),
            CONSTRAINT fk_jpq_posting FOREIGN KEY (posting_id) REFERENCES job_postings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS applications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            posting_id INT UNSIGNED NOT NULL,
            applicant_name VARCHAR(150) NOT NULL,
            applicant_age INT UNSIGNED NULL,
            discord_tag VARCHAR(100) NULL,
            motivation TEXT NULL,
            availability TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'pending',
            booking_token VARCHAR(64) NOT NULL,
            notes TEXT NULL,
            created_user_id INT UNSIGNED NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_booking_token (booking_token),
            KEY idx_app_posting (posting_id),
            KEY idx_app_status (status),
            CONSTRAINT fk_app_posting FOREIGN KEY (posting_id) REFERENCES job_postings(id) ON DELETE CASCADE,
            CONSTRAINT fk_app_created_user FOREIGN KEY (created_user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_app_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS application_answers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            application_id INT UNSIGNED NOT NULL,
            question_id INT UNSIGNED NOT NULL,
            answer TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_app_question (application_id, question_id),
            CONSTRAINT fk_aa_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE,
            CONSTRAINT fk_aa_question FOREIGN KEY (question_id) REFERENCES job_posting_questions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Freie Zeitslots pro Ausschreibung, aus denen sich Bewerber:innen nach einer Einladung
        // per Buchungslink selbst einen Termin aussuchen (application_id = NULL → noch offen).
        $db->exec("CREATE TABLE IF NOT EXISTS interview_slots (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            posting_id INT UNSIGNED NOT NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NULL,
            application_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_is_posting_start (posting_id, start_time),
            CONSTRAINT fk_is_posting FOREIGN KEY (posting_id) REFERENCES job_postings(id) ON DELETE CASCADE,
            CONSTRAINT fk_is_application FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE SET NULL,
            CONSTRAINT fk_is_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Ränge exakt wie die Discord-Rollen benennen (Namen/Reihenfolge/Farben angelehnt an
        // die Rollen-Hierarchie von ViennaStateRP/Website: includes/permissions.php).
        // Namensbasiert & idempotent: fügt nur fehlende Ränge hinzu, rührt bestehende (inkl.
        // bereits zugewiesener discord_role_id oder Mitglieder) nicht an.
        $wantedRanks = [
            ['Test-Supporter',      10, '#4ade80', ['meetings.respond']],
            ['Supporter',           20, '#22c55e', ['meetings.respond']],
            ['Moderator',           30, '#7c3aed', ['meetings.respond']],
            ['Test-Developer',      40, '#06b6d4', ['meetings.respond']],
            ['Developer',           50, '#0ea5e9', ['meetings.respond']],
            ['Test-Admin',          60, '#f97316', ['meetings.manage', 'meetings.respond', 'meetings.view_attendance', 'meetings.respond_for_others']],
            ['Admin',               70, '#ef4444', ['members.manage', 'meetings.manage', 'meetings.respond', 'meetings.view_attendance', 'meetings.respond_for_others']],
            ['Stv. Teamleitung',    80, '#e879f9', ['members.manage', 'meetings.manage', 'meetings.respond', 'meetings.view_attendance', 'meetings.respond_for_others', 'teams.manage']],
            ['Teamleitung',         90, '#d946ef', ['members.manage', 'meetings.manage', 'meetings.respond', 'meetings.view_attendance', 'meetings.respond_for_others', 'teams.manage']],
            ['Stv. Projektleitung', 100, '#fb923c', ['members.manage', 'meetings.manage', 'meetings.respond', 'meetings.view_attendance', 'meetings.respond_for_others', 'teams.manage', 'ranks.manage']],
            ['Projektleitung',      110, '#f59e0b', ['members.manage', 'meetings.manage', 'meetings.respond', 'meetings.view_attendance', 'meetings.respond_for_others', 'teams.manage', 'ranks.manage', 'discord.manage']],
            ['Owner',               120, '#e8b86d', ['members.manage', 'meetings.manage', 'meetings.respond', 'meetings.view_attendance', 'meetings.respond_for_others', 'teams.manage', 'ranks.manage', 'discord.manage']],
        ];

        $existingNames = $db->query("SELECT name FROM ranks")->fetchAll(PDO::FETCH_COLUMN);
        $insertRank = $db->prepare("INSERT INTO ranks (name, level, color, permissions, discord_role_id) VALUES (?, ?, ?, ?, NULL)");
        foreach ($wantedRanks as [$name, $level, $color, $perms]) {
            if (!in_array($name, $existingNames, true)) {
                $insertRank->execute([$name, $level, $color, json_encode($perms)]);
            }
        }

        // "meetings.respond" wurde nachträglich als eigene Berechtigung eingeführt (vorher
        // konnte jeder eingeloggte Nutzer ungeachtet seines Rangs antworten). Bei bereits
        // bestehenden Rängen wird sie einmalig ergänzt, damit niemand durch die Umstellung
        // stillschweigend die Antwort-Möglichkeit verliert — danach frei über die
        // Rollenverwaltung einschränkbar.
        // "meetings.view_attendance" ist bewusst NICHT für alle Ränge gedacht (anders als
        // meetings.respond oben) — wer bereits meetings.manage hat, bekommt sie zusätzlich
        // automatisch, alle anderen bestehenden Ränge bleiben unverändert ohne diese Sicht.
        foreach ($db->query("SELECT id, permissions FROM ranks")->fetchAll() as $r) {
            $perms = json_decode($r['permissions'] ?? '[]', true) ?: [];
            $changed = false;
            if (!in_array('meetings.respond', $perms, true)) {
                $perms[] = 'meetings.respond';
                $changed = true;
            }
            if (in_array('meetings.manage', $perms, true) && !in_array('meetings.view_attendance', $perms, true)) {
                $perms[] = 'meetings.view_attendance';
                $changed = true;
            }
            if (in_array('meetings.manage', $perms, true) && !in_array('meetings.respond_for_others', $perms, true)) {
                $perms[] = 'meetings.respond_for_others';
                $changed = true;
            }
            // Bewerbungsverwaltung ist neu und knüpft an die gleiche Vertrauensstufe wie die
            // Mitgliederverwaltung an (wer Mitglieder anlegen darf, darf auch über Bewerbungen
            // entscheiden, aus denen ja neue Mitglieder entstehen).
            if (in_array('members.manage', $perms, true) && !in_array('applications.manage', $perms, true)) {
                $perms[] = 'applications.manage';
                $changed = true;
            }
            if ($changed) {
                $db->prepare("UPDATE ranks SET permissions = ? WHERE id = ?")->execute([json_encode($perms), $r['id']]);
            }
        }

        // Alte Platzhalter-Ränge aus der ersten Version aufräumen, aber nur, wenn ihnen
        // niemand (mehr) zugewiesen ist — bestehende Zuordnungen werden nie angefasst.
        $legacyNames = ['Administrator', 'Teamleiter', 'Mitglied'];
        foreach ($legacyNames as $legacyName) {
            $stmt = $db->prepare("SELECT id FROM ranks WHERE name = ?");
            $stmt->execute([$legacyName]);
            $legacyId = $stmt->fetchColumn();
            if ($legacyId === false) continue;
            $countStmt = $db->prepare("SELECT COUNT(*) c FROM users WHERE rank_id = ?");
            $countStmt->execute([$legacyId]);
            if ((int) $countStmt->fetch()['c'] === 0) {
                $db->prepare("DELETE FROM ranks WHERE id = ?")->execute([$legacyId]);
            }
        }

        // Bekannte Discord-Rollen-IDs für die Bewerbungs-Benachrichtigung (Ping bei neuer
        // Bewerbung, siehe DiscordClient::announceNewApplication()) direkt vorbelegen — analog
        // zur "Team"-Rolle oben. Nur nachgetragen, falls noch keine Rollen-ID gesetzt ist, ein
        // bereits per UI gewählter Wert bleibt unangetastet.
        $knownRankRoleIds = [
            'Teamleitung' => '1520815251463868549',
            'Stv. Teamleitung' => '1537496171877236947',
        ];
        $updateRankRole = $db->prepare("UPDATE ranks SET discord_role_id = ? WHERE name = ? AND discord_role_id IS NULL");
        foreach ($knownRankRoleIds as $rankName => $roleId) {
            $updateRankRole->execute([$roleId, $rankName]);
        }

        $db->prepare("INSERT INTO schema_meta (id, version) VALUES (1, ?) ON DUPLICATE KEY UPDATE version = VALUES(version)")
            ->execute([self::SCHEMA_VERSION]);
    }
}
