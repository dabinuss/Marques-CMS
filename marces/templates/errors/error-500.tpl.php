<div class="marces-error">
    <h1 class="marces-error-title">500 - Serverfehler</h1>
    <p class="marces-error-message"><?php echo marces_escape_html($content ?? 'Bei der Verarbeitung Ihrer Anfrage ist ein Fehler aufgetreten.'); ?></p>
    <p><a href="<?php echo marces_site_url(); ?>">Zurück zur Startseite</a></p>
</div>