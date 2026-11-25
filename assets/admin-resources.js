(function () {
    'use strict';

    // Searchable single-select ("combobox") used for the Associated Snapshot field.
    document.querySelectorAll('[data-cdm-combo]').forEach(function (root) {
        var hidden = root.querySelector('input[type="hidden"]');
        var input = root.querySelector('.cdm-combo-input');
        var toggle = root.querySelector('.cdm-combo-toggle');
        var list = root.querySelector('.cdm-combo-list');
        var empty = root.querySelector('.cdm-combo-empty');
        var options = Array.prototype.slice.call(root.querySelectorAll('.cdm-combo-option'));
        var active = -1;
        if (!hidden || !input || !list) return;

        function selectedLabel() {
            var match = options.filter(function (o) { return o.dataset.value === hidden.value; })[0];
            return match ? match.dataset.label : '';
        }

        function visible() {
            return options.filter(function (o) { return !o.hidden; });
        }

        function setActive(index) {
            var items = visible();
            options.forEach(function (o) { o.classList.remove('is-active'); });
            if (!items.length) { active = -1; return; }
            active = Math.max(0, Math.min(items.length - 1, index));
            items[active].classList.add('is-active');
            items[active].scrollIntoView({block: 'nearest'});
        }

        function filter(term) {
            term = term.trim().toLowerCase();
            options.forEach(function (o) {
                // The "No Snapshot" option always stays available.
                var text = (o.dataset.label + ' #' + o.dataset.value).toLowerCase();
                o.hidden = term !== '' && o.dataset.value !== '0' && text.indexOf(term) === -1;
            });
            var anyMatch = options.some(function (o) { return !o.hidden && o.dataset.value !== '0'; });
            if (empty) empty.hidden = anyMatch || term === '';
            setActive(term === '' ? -1 : (visible().length > 1 ? 1 : 0));
        }

        function open() {
            if (!list.hidden) return;
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            options.forEach(function (o) {
                o.classList.toggle('is-selected', o.dataset.value === hidden.value);
                o.setAttribute('aria-selected', o.dataset.value === hidden.value ? 'true' : 'false');
            });
        }

        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            options.forEach(function (o) { o.hidden = false; o.classList.remove('is-active'); });
            if (empty) empty.hidden = true;
            input.value = selectedLabel();
            active = -1;
        }

        function choose(option) {
            hidden.value = option.dataset.value;
            input.value = option.dataset.label;
            close();
        }

        input.addEventListener('focus', function () { open(); input.select(); });
        input.addEventListener('click', open);
        input.addEventListener('input', function () { open(); filter(input.value); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); open(); setActive(active + 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); open(); setActive(active - 1); }
            else if (e.key === 'Enter') {
                // Never submit the form from the search box.
                e.preventDefault();
                var items = visible();
                if (!list.hidden && active > -1 && items[active]) choose(items[active]);
            } else if (e.key === 'Escape') { close(); }
            else if (e.key === 'Tab') { close(); }
        });

        list.addEventListener('mousedown', function (e) {
            // mousedown (not click) so the input blur does not close the list first.
            var option = e.target.closest('.cdm-combo-option');
            if (option) { e.preventDefault(); choose(option); }
        });

        if (toggle) {
            toggle.addEventListener('mousedown', function (e) {
                e.preventDefault();
                if (list.hidden) { input.focus(); open(); } else { close(); }
            });
        }

        document.addEventListener('mousedown', function (e) {
            if (!root.contains(e.target)) close();
        });
    });
})();
