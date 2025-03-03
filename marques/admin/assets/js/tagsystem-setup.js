// Global verfügbare Funktion für Tag-System
function setupTagSystem(type, suggestions) {
    const container = document.getElementById(type + 'Container');
    const input = document.getElementById(type + 'Input');

    // Spezielle Behandlung für "category" (wird zu "categories")
    const hiddenInputId = type === 'category' ? 'categories' : type + 's';
    const hiddenInput = document.getElementById(hiddenInputId);
    console.log(`${type} - Hidden-Input-ID:`, hiddenInputId);

    const suggestionBox = document.getElementById(type + 'Suggestions');
    
    // Debugging: Prüfen, ob Elemente vorhanden sind
    console.log(`${type} Setup - Elemente gefunden:`, {
        container: !!container,
        input: !!input,
        hiddenInput: !!hiddenInput,
        suggestionBox: !!suggestionBox
    });
    
    // Überprüfen, ob alle Elemente existieren
    if (!container || !input || !hiddenInput || !suggestionBox) {
        console.error(`Ein Element für das ${type}-System fehlt!`);
        return; // Abbrechen, wenn ein Element fehlt
    }
    
    // Event-Listener für Entfernen von Tags
    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('tag-remove')) {
            const value = e.target.dataset.value;
            e.target.parentElement.remove();
            updateHiddenInput();
            console.log(`${type} entfernt:`, value);
        }
    });
    
    // Tastaturereignisse für Eingabefeld
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            const value = input.value.trim();
            if (value) {
                addTag(value);
                console.log(`${type} hinzugefügt:`, value);
            }
        }
    });

    // Füge zusätzlich einen blur-Event-Listener hinzu, der auch einen Tag/Kategorie hinzufügt:
    input.addEventListener('blur', function() {
        const value = input.value.trim();
        if (value) {
            addTag(value);
            console.log(`${type} beim Verlassen des Feldes hinzugefügt:`, value);
        }
        suggestionBox.style.display = 'none';
    });
    
    // Fokus- und Blur-Ereignisse
    input.addEventListener('focus', function() {
        showSuggestions();
    });
    
    input.addEventListener('input', function() {
        showSuggestions();
    });
    
    document.addEventListener('click', function(e) {
        if (!container.contains(e.target) && !suggestionBox.contains(e.target)) {
            suggestionBox.style.display = 'none';
        }
    });
    
    // Vorschläge anzeigen
    function showSuggestions() {
        const inputVal = input.value.trim().toLowerCase();
        
        // Debugging
        console.log(`${type} Suggestions - Eingabe:`, inputVal);
        console.log(`${type} Suggestions - Verfügbare Werte:`, suggestions);
        
        // Vorschläge filtern
        const filtered = suggestions.filter(item => {
            const itemLower = (typeof item === 'string') ? item.toLowerCase() : '';
            return itemLower.includes(inputVal) && !getValues().includes(item);
        });
        
        console.log(`${type} Suggestions - Gefilterte Werte:`, filtered);
        
        // Vorschlagbox aktualisieren
        suggestionBox.innerHTML = '';
        
        if (filtered.length > 0) {
            filtered.forEach(item => {
                const div = document.createElement('div');
                div.className = 'tag-suggestion';
                div.textContent = item;
                div.addEventListener('click', function() {
                    addTag(item);
                    console.log(`${type} aus Vorschlag ausgewählt:`, item);
                });
                suggestionBox.appendChild(div);
            });
            
            // Position der Vorschlagbox
            const rect = input.getBoundingClientRect();
            suggestionBox.style.top = (rect.bottom + window.scrollY) + 'px';
            suggestionBox.style.left = (rect.left + window.scrollX) + 'px';
            suggestionBox.style.width = container.offsetWidth + 'px';
            suggestionBox.style.display = 'block';
        } else {
            suggestionBox.style.display = 'none';
        }
    }
    
    // Tag hinzufügen
    function addTag(value) {
        value = value.trim();
        if (value && !getValues().includes(value)) {
            console.log(`${type} - Füge hinzu:`, value);
            const tag = document.createElement('div');
            tag.className = 'tag';
            tag.innerHTML = `${value}<span class="tag-remove" data-value="${value}">×</span>`;
            container.insertBefore(tag, input);
            input.value = '';
            updateHiddenInput();
        }
        suggestionBox.style.display = 'none';
    }
    
    // Aktuelle Tags/Kategorien erhalten
    function getValues() {
        const tagElements = container.querySelectorAll('.tag');
        const values = Array.from(tagElements).map(tag => {
            // Nimm nur den Textinhalt ohne das "×"
            const text = tag.textContent || '';
            return text.replace(/×$/, '').trim();
        });
        console.log(`${type} - Aktuelle Werte:`, values);
        return values;
    }
    
    // Hidden-Input aktualisieren
    function updateHiddenInput() {
        const values = getValues();
        if (hiddenInput) {
            hiddenInput.value = values.join(',');
            console.log(`${type} - Hidden-Input aktualisiert:`, hiddenInput.value);
        } else {
            console.error(`${type} - Hidden-Input nicht gefunden!`);
        }
    }
}