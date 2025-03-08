function setupTagSystem(type, suggestions) {
    const container = document.getElementById(type + 'Container');
    const input = document.getElementById(type + 'Input');
    const hiddenInputId = type === 'category' ? 'categories' : type + 's';
    const hiddenInput = document.getElementById(hiddenInputId);
    const suggestionBox = document.getElementById(type + 'Suggestions');
    let suggestionClickInProgress = false; // Flag, um Klicks auf Vorschläge zu erkennen

    if (!container || !input || !hiddenInput || !suggestionBox) {
        console.error(`Ein Element für das ${type}-System fehlt!`);
        return;
    }

    container.addEventListener('click', function(e) {
        if (e.target.classList.contains('tag-remove')) {
            const value = e.target.dataset.value;
            e.target.parentElement.remove();
            updateHiddenInput();
        }
    });

    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            const value = input.value.trim();
            if (value) {
                addTag(value);
            }
        } else if (e.key === 'Escape') {
            suggestionBox.style.display = 'none';
        }
    });

    input.addEventListener('blur', function() {
        if (!suggestionClickInProgress) { // Nur Tag hinzufügen, wenn kein Vorschlag-Klick in Bearbeitung
            const value = input.value.trim();
            if (value) {
                addTag(value);
            }
            suggestionBox.style.display = 'none';
            input.value = '';
        }
        suggestionClickInProgress = false; // Flag zurücksetzen
    });

    input.addEventListener('focus', showSuggestions);
    input.addEventListener('input', showSuggestions);

    document.addEventListener('click', function(e) {
        if (!container.contains(e.target) && !suggestionBox.contains(e.target) && e.target !== input) {
            suggestionBox.style.display = 'none';
        }
    });

    function showSuggestions() {
        const inputVal = input.value.trim().toLowerCase();
        const filtered = suggestions.filter(item => {
            const itemLower = (typeof item === 'string') ? item.toLowerCase() : '';
            return itemLower.includes(inputVal) && !getValues().includes(item);
        });

        suggestionBox.innerHTML = '';

        if (filtered.length > 0) {
            filtered.forEach(item => {
                const div = document.createElement('div');
                div.className = 'tag-suggestion';
                div.textContent = item;
                div.addEventListener('mousedown', function(e) { // Geändert zu mousedown
                    e.preventDefault(); // Verhindert Fokusverlust vom Eingabefeld
                    suggestionClickInProgress = true; // Setze Flag, dass Vorschlag-Klick erfolgt
                    addTag(item);
                    input.focus(); // Fokus zurück zum Eingabefeld
                });
                suggestionBox.appendChild(div);
            });

            const rect = input.getBoundingClientRect();
            suggestionBox.style.top = (rect.bottom + window.scrollY) + 'px';
            suggestionBox.style.left = (rect.left + window.scrollX) + 'px';
            suggestionBox.style.width = container.offsetWidth + 'px';
            suggestionBox.style.display = 'block';
        } else {
            suggestionBox.style.display = 'none';
        }
    }

    function addTag(value) {
        value = value.trim();
        if (value && !getValues().includes(value)) {
            const tag = document.createElement('div');
            tag.className = 'tag';
            tag.innerHTML = `${value}<span class="tag-remove" data-value="${value}">×</span>`;
            container.insertBefore(tag, input);
            input.value = '';
            updateHiddenInput();
        }
        suggestionBox.style.display = 'none';
    }

    function getValues() {
        const tagElements = container.querySelectorAll('.tag');
        return Array.from(tagElements).map(tag => tag.textContent.replace(/×$/, '').trim());
    }

    function updateHiddenInput() {
        if (hiddenInput) {
            hiddenInput.value = getValues().join(',');
        }
    }
}