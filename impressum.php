<?php
require __DIR__ . '/bootstrap.php';
// Bewusst kein Auth::requireLogin() — das Impressum muss laut § 5 ECG / § 5 TMG ohne
// Anmeldung erreichbar sein, auch wenn der Rest der Teamverwaltung zugangsbeschränkt ist.

$pageTitle = 'Impressum';
require __DIR__ . '/includes/header.php';
?>
<div class="legal-card">
  <div class="legal-nav">
    <a href="<?= url('impressum.php') ?>" class="active">Impressum</a>
    <a href="<?= url('datenschutz.php') ?>">Datenschutzerklärung</a>
  </div>

  <h1>Impressum</h1>
  <p class="legal-sub">Angaben gemäß § 5 ECG (E-Commerce-Gesetz, Österreich) und § 14 UGB</p>

  <h2>Betreiber &amp; Verantwortlicher</h2>
  <address>
    <strong>ViennaStateRP</strong><br>
    Klammstraße 44<br>
    6020 Innsbruck<br>
    Österreich
  </address>

  <h2>Kontakt</h2>
  <p>
    E-Mail: <a href="mailto:support@viennastaterp.at">support@viennastaterp.at</a>
  </p>

  <h2>Zweck dieser Anwendung</h2>
  <p>
    Diese Teamverwaltung ist ein internes, nicht öffentlich zugängliches Werkzeug zur
    Organisation des ViennaStateRP-Teams (Mitgliederverwaltung, Rang-/Rechtevergabe,
    Besprechungsplanung und Anwesenheitserfassung). Sie richtet sich ausschließlich an
    Teammitglieder von ViennaStateRP und steht in keiner Verbindung zu Rockstar Games,
    Take-Two Interactive, FiveM/Cfx.re oder Discord Inc.
  </p>

  <h2>Haftungsausschluss</h2>
  <p>
    Die Inhalte dieser Anwendung wurden mit größtmöglicher Sorgfalt erstellt. Für die
    Richtigkeit, Vollständigkeit und Aktualität übernehmen wir jedoch keine Gewähr. Als
    Diensteanbieter sind wir für eigene Inhalte nach § 18 ECG verantwortlich. Für verlinkte
    externe Seiten (insbesondere Discord) übernehmen wir keine Haftung; für deren Inhalte
    sind ausschließlich die jeweiligen Betreiber verantwortlich.
  </p>

  <h2>Streitschlichtung</h2>
  <p>
    Die Europäische Kommission stellt eine Plattform zur Online-Streitbeilegung (OS) bereit:
    <a href="https://ec.europa.eu/consumers/odr/" target="_blank" rel="noopener">https://ec.europa.eu/consumers/odr/</a>.
    Da es sich um kein Verbrauchergeschäft handelt (interne Teamorganisation, kein
    Vertragsabschluss mit Endkunden), besteht keine Verpflichtung zur Teilnahme an einem
    Streitbeilegungsverfahren.
  </p>

  <p style="margin-top:32px;font-size:12px;">Letzte Aktualisierung: <?= date('d.m.Y') ?></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
