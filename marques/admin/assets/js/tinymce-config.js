function initTinyMCE(selector) {
    selector = selector || '.content-editor';
    
    // Basisverzeichnis der Website bestimmen
    var baseUrl = window.location.protocol + '//' + window.location.host;
    var pathParts = window.location.pathname.split('/');
    
    // entfernt "admin" und die aktuelle Datei aus dem Pfad
    if (pathParts.includes('admin')) {
        pathParts = pathParts.slice(0, pathParts.indexOf('admin'));
    }
    
    baseUrl += pathParts.join('/');
    
    tinymce.init({
        selector: selector,
        height: 500,
        language: 'de',
        menubar: false, // Menüleiste deaktivieren
        promotion: false, // Upgrade-Button entfernen
        license_key: 'gpl', // GPL-Lizenz angeben
        
        // Diese Einstellungen sind für die Pfadauflösung entscheidend
        document_base_url: baseUrl,
        convert_urls: false,
        relative_urls: false,
        remove_script_host: false,
        
        plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview', 'anchor',
            'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount',
            'emoticons', 'nonbreaking', 'quickbars'
        ],
        toolbar: 'undo redo | formatselect styleselect | ' +
            'bold italic underline strikethrough forecolor backcolor | alignleft aligncenter ' +
            'alignright alignjustify | bullist numlist outdent indent | ' +
            'link image media emoticons hr | removeformat fullscreen | help',
        toolbar_mode: 'sliding',
        content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
        
        // Medien-Upload-Konfiguration
        images_upload_url: 'upload-handler.php',
        automatic_uploads: true,
        images_reuse_filename: true,
        file_picker_types: 'image',
        
        // Eigener Media Browser
        file_picker_callback: function(callback, value, meta) {
            // Nur für Bilder verwenden
            if (meta.filetype === 'image') {
                var width = window.innerWidth - 100;
                var height = window.innerHeight - 100;
                
                tinymce.activeEditor.windowManager.openUrl({
                    url: 'media.php?tinymce=1',
                    title: 'Medienbibliothek',
                    width: width,
                    height: height,
                    onMessage: function(api, message) {
                        if (message.mceAction === 'insertMedia') {
                            callback(message.content, { alt: message.alt });
                            api.close();
                        }
                    }
                });
            }
        },
        
        // Bild-Einstellungen
        image_caption: true,
        image_advtab: true,
        image_title: true,
        image_dimensions: true,
        image_class_list: [
            {title: 'Ohne Klasse', value: ''},
            {title: 'Responsive', value: 'img-fluid'},
            {title: 'Links ausgerichtet', value: 'float-left mr-3'},
            {title: 'Rechts ausgerichtet', value: 'float-right ml-3'}
        ],
        
        // Erweiterte Stile
        style_formats: [
            { title: 'Überschriften', items: [
                { title: 'Überschrift 1', format: 'h1' },
                { title: 'Überschrift 2', format: 'h2' },
                { title: 'Überschrift 3', format: 'h3' },
                { title: 'Überschrift 4', format: 'h4' },
                { title: 'Überschrift 5', format: 'h5' },
                { title: 'Überschrift 6', format: 'h6' }
            ]},
            { title: 'Inline', items: [
                { title: 'Fett', format: 'bold' },
                { title: 'Kursiv', format: 'italic' },
                { title: 'Unterstrichen', format: 'underline' },
                { title: 'Durchgestrichen', format: 'strikethrough' },
                { title: 'Code', format: 'code' }
            ]},
            { title: 'Blöcke', items: [
                { title: 'Paragraph', format: 'p' },
                { title: 'Blockzitat', format: 'blockquote' },
                { title: 'Code-Block', format: 'pre' }
            ]},
            { title: 'Ausrichtung', items: [
                { title: 'Links', format: 'alignleft' },
                { title: 'Zentriert', format: 'aligncenter' },
                { title: 'Rechts', format: 'alignright' },
                { title: 'Blocksatz', format: 'alignjustify' }
            ]}
        ],
        
        // Überarbeiteter Bild-Upload-Handler mit Promise-Rückgabe
        images_upload_handler: function (blobInfo, progress, failure) {
            return new Promise(function(resolve, reject) {
                // Überprüfen, ob das Bild bereits hochgeladen wurde (basierend auf blobUri)
                var blobUri = blobInfo.blobUri();
                var images = document.querySelectorAll('img[src^="blob:"]');
                var imageExists = false;
                var existingUrl = '';
                
                // Nach Bildern suchen, die bereits hochgeladen wurden
                for (var i = 0; i < images.length; i++) {
                    var img = images[i];
                    var imgSrc = img.getAttribute('src');
                    var imgData = img.getAttribute('data-mce-uploaded');
                    
                    if (imgSrc === blobUri && imgData) {
                        imageExists = true;
                        existingUrl = imgData;
                        break;
                    }
                }
                
                // Wenn das Bild bereits hochgeladen wurde, die bestehende URL verwenden
                if (imageExists && existingUrl) {
                    resolve(existingUrl);
                    return;
                }
                
                var xhr, formData;
                
                xhr = new XMLHttpRequest();
                xhr.withCredentials = false;
                xhr.open('POST', 'upload-handler.php');
                
                if (progress) {
                    xhr.upload.onprogress = function (e) {
                        progress(e.loaded / e.total * 100);
                    };
                }
                
                xhr.onload = function() {
                    var json;
                    
                    if (xhr.status < 200 || xhr.status >= 300) {
                        reject('HTTP-Fehler: ' + xhr.status);
                        return;
                    }
                    
                    try {
                        json = JSON.parse(xhr.responseText);
                    } catch (e) {
                        reject('Ungültige JSON-Antwort: ' + xhr.responseText);
                        return;
                    }
                    
                    if (!json || typeof json.location != 'string') {
                        reject('Ungültige JSON-Antwort: ' + xhr.responseText);
                        return;
                    }
                    
                    // Das hochgeladene Bild markieren
                    var uploadedImages = document.querySelectorAll('img[src="' + blobUri + '"]');
                    for (var i = 0; i < uploadedImages.length; i++) {
                        uploadedImages[i].setAttribute('data-mce-uploaded', json.location);
                    }
                    
                    resolve(json.location);
                };
                
                xhr.onerror = function () {
                    reject('Bild-Upload fehlgeschlagen. Bitte versuchen Sie es später erneut.');
                };
                
                formData = new FormData();
                formData.append('file', blobInfo.blob(), blobInfo.filename());
                
                xhr.send(formData);
            });
        },
        
        // Paste aus Word und anderen Quellen bereinigen
        paste_data_images: true,
        paste_as_text: false,
        
        // Setup-Funktion für zusätzliche Event-Handler
        setup: function(editor) {
            // Bilder nach dem Einfügen markieren, um doppelte Uploads zu vermeiden
            editor.on('NodeChange', function(e) {
                if (e.element.nodeName === 'IMG') {
                    var img = e.element;
                    var src = img.getAttribute('src');
                    
                    // Wenn es ein Blob-Bild ist, das bereits hochgeladen wurde, die src aktualisieren
                    if (src && src.startsWith('blob:') && img.hasAttribute('data-mce-uploaded')) {
                        var uploadedSrc = img.getAttribute('data-mce-uploaded');
                        if (uploadedSrc) {
                            img.setAttribute('src', uploadedSrc);
                            img.removeAttribute('data-mce-src');
                        }
                    }
                }
            });
        }
    });
}