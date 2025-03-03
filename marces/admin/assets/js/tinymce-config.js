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
        menubar: true,
        
        // Diese Einstellungen sind für die Pfadauflösung entscheidend
        document_base_url: baseUrl,
        convert_urls: false,
        relative_urls: false,
        remove_script_host: false,
        
        plugins: [
            'advlist autolink lists link image charmap print preview anchor',
            'searchreplace visualblocks code fullscreen',
            'insertdatetime media table paste code help wordcount',
            'emoticons hr imagetools nonbreaking quickbars'
        ],
        toolbar: 'undo redo | formatselect styleselect | ' +
            'bold italic underline strikethrough forecolor backcolor | alignleft aligncenter ' +
            'alignright alignjustify | bullist numlist outdent indent | ' +
            'link image media emoticons hr | removeformat fullscreen | help',
        toolbar_mode: 'sliding',
        content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:14px }',
        
        // Verbesserte Medien-Upload-Konfiguration
        images_upload_url: 'upload-handler.php',
        automatic_uploads: true,
        images_reuse_filename: false,
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
        
        // Bild-Upload-Callback für Fortschrittsanzeige usw.
        images_upload_handler: function (blobInfo, success, failure) {
            var xhr, formData;
            
            xhr = new XMLHttpRequest();
            xhr.withCredentials = false;
            xhr.open('POST', 'upload-handler.php');
            
            xhr.onload = function() {
                var json;
                
                if (xhr.status != 200) {
                    failure('HTTP-Fehler: ' + xhr.status);
                    return;
                }
                
                try {
                    json = JSON.parse(xhr.responseText);
                } catch (e) {
                    failure('Ungültige JSON-Antwort: ' + xhr.responseText);
                    return;
                }
                
                if (!json || typeof json.location != 'string') {
                    failure('Ungültige JSON-Antwort: ' + xhr.responseText);
                    return;
                }
                
                success(json.location);
            };
            
            xhr.onerror = function () {
                failure('Bild-Upload fehlgeschlagen. Bitte versuchen Sie es später erneut.');
            };
            
            formData = new FormData();
            formData.append('file', blobInfo.blob(), blobInfo.filename());
            
            xhr.send(formData);
        },
        
        // Paste aus Word und anderen Quellen bereinigen
        paste_data_images: true,
        paste_as_text: false,
        paste_word_valid_elements: 'b,strong,i,em,h1,h2,h3,h4,h5,h6,p,ol,ul,li,a[href],img[src]',
        paste_webkit_styles: 'color font-size',
        paste_retain_style_properties: 'color font-size',
        
        // Deutsch als Standardsprache
        language_url: 'https://cdn.tiny.cloud/1/kde6p4uv99x4u39s6lbw4q767ejmg9p7hjyjy1yes5y7oa41/tinymce/5/langs/de.js'
    });
}