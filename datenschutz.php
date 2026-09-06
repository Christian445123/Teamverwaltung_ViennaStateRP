<?php
require __DIR__ . '/bootstrap.php';
// Bewusst kein Auth::requireLogin() — die Datenschutzerklärung muss frei zugänglich sein.

$pageTitle = 'Datenschutzerklärung';
require __DIR__ . '/includes/header.php';
?>
<div class="legal-card">
  <div class="legal-nav">
    <a href="<?= url('impressum.php') ?>">Impressum</a>
    <a href="<?= url('datenschutz.php') ?>" class="active">Datenschutzerklärung</a>
  </div>

  <h1>Datenschutzerklärung</h1>
  <p class="legal-sub">
    Gemäß Art. 13 &amp; 14 DSGVO (Datenschutz-Grundverordnung, EU 2016/679) informieren wir
    über die Verarbeitung personenbezogener Daten in dieser Teamverwaltung.
  </p>

  <div class="highlight-box">
    <strong>Kurzfassung:</strong> Diese Anwendung ist überwiegend ein internes Werkzeug für
    ViennaStateRP-Teammitglieder. Öffentlich zugänglich ist ausschließlich die Bewerbungsseite
    für offene Stellen im Team. Es gibt keine Werbung, kein Tracking und keine
    Analyse-Cookies — nur einen technisch notwendigen Session-Cookie zur Anmeldung bzw. zur
    Terminbuchung. Daten werden ausschließlich zur Teamorganisation und -rekrutierung
    (Mitglieder, Ränge, Besprechungen, Anwesenheit, Bewerbungen) verarbeitet und nicht
    verkauft oder zu Werbezwecken weitergegeben.
  </div>

  <h2>1. Verantwortlicher</h2>
  <address>
    <strong>ViennaStateRP</strong><br>
    Klammstraße 44<br>
    6020 Innsbruck, Österreich<br>
    E-Mail: <a href="mailto:support@viennastaterp.at">support@viennastaterp.at</a>
  </address>

  <h2>2. Charakter dieser Anwendung</h2>
  <p>
    Mit Ausnahme dieser Seite, des Impressums, der Anmeldeseite sowie der Bewerbungsseite
    (<code>careers.php</code> und die davon verlinkten Bewerbungs-/Terminbuchungsseiten) ist
    die Teamverwaltung ausschließlich für angemeldete ViennaStateRP-Teammitglieder zugänglich.
    Über die Bewerbungsseite kann sich jede Person ohne Anmeldung auf offene Stellen im
    ViennaStateRP-Team bewerben und einen Bewerbungsgespräch-Termin buchen — siehe
    Abschnitt 3g für die dabei verarbeiteten Daten.
  </p>

  <h2>3. Welche Daten wir verarbeiten</h2>

  <h3>a) Server-Logfiles</h3>
  <p>
    Beim Aufruf der Anwendung verarbeitet unser Webserver technisch bedingt und automatisch
    folgende Daten: IP-Adresse, Datum und Uhrzeit des Zugriffs, aufgerufene Adresse,
    HTTP-Statuscode und User-Agent (Browser/Betriebssystem). Diese Daten sind für den
    sicheren und stabilen Betrieb erforderlich (Art. 6 Abs. 1 lit. f DSGVO – berechtigtes
    Interesse an Betriebssicherheit) und werden im Regelfall nur kurzzeitig zur
    Fehleranalyse vorgehalten und danach automatisch durch die Log-Rotation unseres
    Hosting-Anbieters gelöscht.
  </p>

  <h3>b) Konto- und Profildaten</h3>
  <p>
    Für jedes Teammitglied speichern wir: Anzeigename, optional E-Mail-Adresse, optional
    Benutzername mit Passwort (als Hash, siehe Abschnitt „Datensicherheit" — das
    Klartext-Passwort ist uns nicht bekannt), sowie zugewiesenen Rang und Team(s).
    Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an einer
    funktionierenden Teamorganisation) bzw. bei Mitarbeit im Team Art. 6 Abs. 1 lit. b DSGVO.
  </p>

  <h3>c) Login mit Discord (OAuth2)</h3>
  <p>
    Verknüpfst du dein Konto mit „Mit Discord anmelden", übermittelt Discord nach
    erfolgreicher Anmeldung deine Discord-Nutzer-ID, deinen Discord-Benutzernamen und deinen
    Avatar an uns (angefragter Berechtigungsumfang: ausschließlich <code>identify</code> —
    wir fragen bewusst keine E-Mail-Adresse über Discord ab, da wir sie nicht benötigen).
    Diese Daten werden gespeichert, um dich anzumelden und deinen Discord-Account mit deinem
    Teamverwaltung-Konto zu verknüpfen. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO.
    Weitere Informationen zur Datenverarbeitung durch Discord findest du in der
    <a href="https://discord.com/privacy" target="_blank" rel="noopener">Datenschutzerklärung von Discord</a>
    (Discord Inc. / Discord Netherlands B.V.) — dabei kann es zu einer Datenübermittlung in
    die USA kommen; nähere Angaben zur Rechtsgrundlage dieser Drittlandübermittlung macht
    Discord in der eigenen Datenschutzerklärung.
  </p>

  <h3>d) Discord-Rollen-Abgleich (Bot)</h3>
  <p>
    Ist ein Discord-Bot konfiguriert, gleicht die Teamverwaltung zusätzlich ab, welche
    Discord-Rollen ein verknüpftes Mitglied aktuell auf unserem Discord-Server hat
    (z. B. „Team", „High-Team" sowie optionale Zusatzrollen wie „Administrator" oder „Ban").
    Diese Rollen-Zuordnungen werden gespiegelt, um Rang und Berechtigungen automatisch
    aktuell zu halten. Umgekehrt kann die Teamverwaltung auch selbst Discord-Rollen setzen,
    die dem Rang/Team eines Mitglieds entsprechen. Rechtsgrundlage: Art. 6 Abs. 1 lit. f
    DSGVO (berechtigtes Interesse an konsistenter Rechteverwaltung).
  </p>

  <h3>e) Besprechungen &amp; Anwesenheit</h3>
  <p>
    Für Teambesprechungen speichern wir, ob ein Mitglied zugesagt, abgesagt oder mit
    „vielleicht" geantwortet hat, sowie – nachträglich durch Teamleitung erfasst – ob es
    tatsächlich anwesend war. Aus diesen Daten wird eine Anwesenheitsstatistik pro Mitglied
    gebildet. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse an
    planbarer und nachvollziehbarer Teamarbeit).
  </p>

  <h3>f) Notizen &amp; Änderungsprotokoll</h3>
  <p>
    Teamleitung kann zu einem Mitglied ein Freitext-Notizfeld pflegen; hier sollen nur
    sachbezogene, teamrelevante Informationen hinterlegt werden. Zusätzlich protokolliert
    ein internes Änderungsprotokoll administrative Aktionen (z. B. Ranganpassungen), um
    Änderungen nachvollziehbar zu machen. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO.
  </p>

  <h3>g) Bewerbungsdaten (öffentliche Bewerbungsseite)</h3>
  <p>
    Bewirbst du dich über unsere öffentliche Bewerbungsseite auf eine Stelle im
    ViennaStateRP-Team, verarbeiten wir die von dir freiwillig im Formular angegebenen Daten:
    Name, optional Alter, Discord-Tag, dein Motivationstext, optionale Angaben zu deiner
    zeitlichen Verfügbarkeit sowie Antworten auf etwaige stellenspezifische Zusatzfragen.
    Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Maßnahmen auf deine Anfrage vor Eingehung
    einer ehrenamtlichen Mitarbeit im Team) bzw. hilfsweise Art. 6 Abs. 1 lit. f DSGVO
    (berechtigtes Interesse an der Personalauswahl).
  </p>
  <p>
    Lädt das Team dich zu einem Bewerbungsgespräch ein, erhältst du einen individuellen,
    nicht erratbaren Buchungslink (manuell per Discord-Nachricht oder E-Mail übermittelt, es
    gibt keinen automatischen Versand), über den du dir ohne Anmeldung einen freien
    Gesprächstermin aus den vom Team angelegten Zeitfenstern aussuchen kannst; dabei wird der
    gewählte Termin mit deiner Bewerbung verknüpft gespeichert.
  </p>
  <p>
    Nur Teammitglieder mit der Berechtigung „Bewerbungen verwalten" können eingegangene
    Bewerbungen einsehen und bearbeiten. Nimmt das Team deine Bewerbung an, wird automatisch
    ein Mitgliedskonto mit den von dir angegebenen Daten (Name, Discord-Tag) sowie dem Rang
    und ggf. Team der Ausschreibung angelegt; ab diesem Zeitpunkt gelten für dich die
    Abschnitte 3b–3f dieser Erklärung. Bei Ablehnung bleiben die Bewerbungsdaten zu
    Dokumentationszwecken gespeichert, sofern du nicht ihre Löschung verlangst (siehe
    Abschnitt 7).
  </p>

  <h2>4. Cookies</h2>
  <p>
    Diese Anwendung setzt ausschließlich einen technisch notwendigen Session-Cookie
    (<code>PHPSESSID</code>) zur Anmeldung. Er enthält keine personenbezogenen Daten,
    sondern nur eine zufällige Sitzungs-ID, und läuft beim Schließen des Browsers bzw. nach
    Ablauf der Sitzung ab. Da er unbedingt erforderlich ist, ist dafür keine Einwilligung
    nötig (Art. 6 Abs. 1 lit. f DSGVO bzw. § 165 Abs. 3 TKG 2021). Es werden keine
    Tracking-, Marketing- oder Analyse-Cookies gesetzt.
  </p>

  <h2>5. Empfänger &amp; Auftragsverarbeitung</h2>
  <ul>
    <li><strong>Hosting-Anbieter:</strong> Unser Server-Betreiber hat technischen Zugriff auf
      Server und Datenbank im Rahmen der Auftragsverarbeitung.</li>
    <li><strong>Discord:</strong> im Rahmen von OAuth2-Login und Bot-Rollen-Abgleich (siehe
      Abschnitt 3c/3d).</li>
    <li>Eine darüber hinausgehende Weitergabe an Dritte erfolgt nicht, außer bei gesetzlicher
      Verpflichtung gegenüber Behörden.</li>
  </ul>

  <h2>6. Speicherdauer</h2>
  <ul>
    <li><strong>Server-Logs:</strong> kurzfristig, im Rahmen der Log-Rotation unseres
      Hosting-Anbieters</li>
    <li><strong>Sitzungsdaten:</strong> bis Sitzungsende bzw. Abmeldung</li>
    <li><strong>Konto-/Besprechungsdaten:</strong> so lange die Mitgliedschaft im Team
      besteht; bei Deaktivierung eines Mitglieds auf Anfrage löschbar, soweit keine
      berechtigten Interessen (z. B. Nachvollziehbarkeit im Änderungsprotokoll) entgegenstehen</li>
    <li><strong>Bewerbungsdaten:</strong> bis zur Entscheidung über die Bewerbung sowie
      danach für eine angemessene Nachvollziehbarkeitsfrist; auf Anfrage vorzeitig löschbar,
      bei Annahme gehen die relevanten Daten in das neu angelegte Mitgliedskonto über</li>
  </ul>

  <h2>7. Deine Rechte</h2>
  <p>Gemäß DSGVO stehen dir folgende Rechte zu:</p>
  <ul>
    <li><strong>Auskunft (Art. 15 DSGVO):</strong> Auskunft über deine gespeicherten Daten.</li>
    <li><strong>Berichtigung (Art. 16 DSGVO):</strong> Korrektur unrichtiger Daten.</li>
    <li><strong>Löschung (Art. 17 DSGVO):</strong> Löschung deiner Daten, soweit keine
      berechtigten Interessen entgegenstehen.</li>
    <li><strong>Einschränkung (Art. 18 DSGVO):</strong> Einschränkung der Verarbeitung.</li>
    <li><strong>Datenübertragbarkeit (Art. 20 DSGVO):</strong> Erhalt deiner Daten in einem
      maschinenlesbaren Format.</li>
    <li><strong>Widerspruch (Art. 21 DSGVO):</strong> Widerspruch gegen Verarbeitung auf
      Basis berechtigter Interessen.</li>
  </ul>
  <p>
    Zur Ausübung deiner Rechte wende dich per E-Mail an
    <a href="mailto:support@viennastaterp.at">support@viennastaterp.at</a>.
  </p>

  <h2>8. Beschwerderecht bei der Aufsichtsbehörde</h2>
  <p>Du hast das Recht, Beschwerde bei einer Datenschutz-Aufsichtsbehörde einzulegen. In Österreich ist dies die:</p>
  <address>
    <strong>Datenschutzbehörde (DSB)</strong><br>
    Barichgasse 40–42<br>
    1030 Wien, Österreich<br>
    <a href="https://www.dsb.gv.at" target="_blank" rel="noopener">www.dsb.gv.at</a>
  </address>

  <h2>9. Datensicherheit</h2>
  <p>
    Diese Anwendung wird über HTTPS (TLS) ausgeliefert. Passwörter werden ausschließlich als
    Hash (PHP <code>password_hash</code>) gespeichert, niemals im Klartext. Datenbankzugriffe
    erfolgen ausschließlich über Prepared Statements (Schutz vor SQL-Injection). Sensible
    Zugangsdaten in der Serverkonfiguration können zusätzlich AES-256-verschlüsselt
    hinterlegt werden. Formulare sind durch CSRF-Token geschützt, Sitzungscookies sind
    HttpOnly.
  </p>

  <h2>10. Änderungen dieser Erklärung</h2>
  <p>
    Wir passen diese Datenschutzerklärung an, sobald sich die Datenverarbeitung in dieser
    Anwendung ändert (z. B. neue Funktionen). Es gilt jeweils die zuletzt aktualisierte
    Fassung.
  </p>

  <p style="margin-top:32px;font-size:12px;">Letzte Aktualisierung: <?= date('d.m.Y') ?></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
