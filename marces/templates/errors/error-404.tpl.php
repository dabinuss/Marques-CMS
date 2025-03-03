<div class="marces-error">
    <h1 class="marces-error-title">404 - Seite nicht gefunden</h1>
    <p class="marces-error-message"><?php echo marces_escape_html($content ?? 'Die gesuchte Seite konnte nicht gefunden werden.'); ?></p>
    <p><a href="<?php echo marces_site_url(); ?>">Zurück zur Startseite</a></p>
</div>