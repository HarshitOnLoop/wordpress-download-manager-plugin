(function () {
    'use strict';

    var form = document.getElementById('cdm-builder-form');
    var stage = document.getElementById('cdm-preview-stage');
    var customStyle = document.getElementById('cdm-preview-custom');
    if (!form || !stage) return;

    var previewForm = stage.querySelector('.cdm-form');
    var shadows = {
        none: 'none',
        soft: '0 10px 30px rgba(15,23,42,.08)',
        strong: '0 20px 50px rgba(15,23,42,.22)'
    };

    var presets = {
        modern:  {primary_color: '#2563eb', bg_color: '#ffffff', text_color: '#111827', border_color: '#d1d5db', input_bg: '#ffffff', radius: 10, padding: 28, shadow: 'soft', button_style: 'solid', btn_full: true, align: 'left'},
        minimal: {primary_color: '#111827', bg_color: '#ffffff', text_color: '#111827', border_color: '#e5e7eb', input_bg: '#ffffff', radius: 2, padding: 24, shadow: 'none', button_style: 'solid', btn_full: true, align: 'left'},
        dark:    {primary_color: '#6366f1', bg_color: '#111827', text_color: '#f9fafb', border_color: '#374151', input_bg: '#1f2937', radius: 12, padding: 30, shadow: 'strong', button_style: 'solid', btn_full: true, align: 'left'},
        soft:    {primary_color: '#7c3aed', bg_color: '#faf5ff', text_color: '#3b0764', border_color: '#e9d5ff', input_bg: '#ffffff', radius: 24, padding: 32, shadow: 'soft', button_style: 'solid', btn_full: true, align: 'center'},
        bold:    {primary_color: '#dc2626', bg_color: '#ffffff', text_color: '#111111', border_color: '#111111', input_bg: '#ffffff', radius: 0, padding: 28, shadow: 'strong', button_style: 'solid', btn_full: true, align: 'left'}
    };

    // Keeps only <a href>, <strong>, <em> so the consent label preview matches what the server allows.
    function safeHtml(str) {
        var doc = new DOMParser().parseFromString(str, 'text/html');
        doc.body.querySelectorAll('*').forEach(function (node) {
            if (!/^(A|STRONG|EM)$/.test(node.tagName)) {
                node.replaceWith(document.createTextNode(node.textContent));
                return;
            }
            Array.prototype.slice.call(node.attributes).forEach(function (attr) {
                if (!(node.tagName === 'A' && attr.name === 'href')) node.removeAttribute(attr.name);
            });
        });
        return doc.body.innerHTML;
    }

    function field(key) {
        return form.elements['settings[' + key + ']'];
    }

    function value(key) {
        var el = field(key);
        if (!el) return '';
        return el.type === 'checkbox' ? el.checked : el.value;
    }

    function setValue(key, val) {
        var el = field(key);
        if (!el) return;
        if (el.type === 'checkbox') el.checked = !!val;
        else el.value = val;
    }

    function num(key, min, max, fallback) {
        var n = parseInt(value(key), 10);
        if (isNaN(n)) n = fallback;
        return Math.min(max, Math.max(min, n));
    }

    function apply() {
        var s = stage.style;
        s.setProperty('--cdm-primary', value('primary_color'));
        s.setProperty('--cdm-bg', value('bg_color'));
        s.setProperty('--cdm-text', value('text_color'));
        s.setProperty('--cdm-border', value('border_color'));
        s.setProperty('--cdm-input-bg', value('input_bg'));
        s.setProperty('--cdm-radius', num('radius', 0, 60, 10) + 'px');
        s.setProperty('--cdm-pad', num('padding', 8, 80, 28) + 'px');
        s.setProperty('--cdm-max', num('max_width', 280, 1000, 520) + 'px');
        s.setProperty('--cdm-fs', num('font_size', 12, 22, 15) + 'px');
        s.setProperty('--cdm-shadow', shadows[value('shadow')] || shadows.soft);
        s.setProperty('--cdm-bar', value('progress_bar_color'));
        s.setProperty('--cdm-bar-h', num('progress_bar_height', 4, 30, 10) + 'px');

        previewForm.classList.remove('cdm-align-left', 'cdm-align-center', 'cdm-btn-solid', 'cdm-btn-outline');
        previewForm.classList.add('cdm-align-' + (value('align') === 'center' ? 'center' : 'left'));
        previewForm.classList.add(value('button_style') === 'outline' ? 'cdm-btn-outline' : 'cdm-btn-solid');
        previewForm.classList.toggle('cdm-btn-full', !!value('btn_full'));

        var title = previewForm.querySelector('.cdm-title');
        var desc = previewForm.querySelector('.cdm-description');
        if (title) {
            title.textContent = value('form_title');
            title.hidden = !value('show_title') || !value('form_title');
        }
        if (desc) {
            desc.textContent = value('form_description');
            desc.hidden = !value('show_description') || !value('form_description');
        }
        var submitText = previewForm.querySelector('.cdm-submit-text');
        if (submitText) submitText.textContent = value('submit_text');

        previewForm.querySelectorAll('.cdm-field').forEach(function (wrap) {
            var key = wrap.getAttribute('data-field');
            var enabled = key === 'email' ? true : !!value(key + '_enabled');
            var required = key === 'email' ? true : !!value(key + '_required');
            wrap.hidden = !enabled;

            if (key === 'consent') {
                var text = wrap.querySelector('.cdm-checkbox-text');
                if (text) text.innerHTML = safeHtml(String(value('consent_label')));
                return;
            }
            var label = wrap.querySelector('.cdm-label-text');
            if (label) label.textContent = value(key + '_label');
            var star = wrap.querySelector('.cdm-required');
            if (star) star.hidden = !required;
            var input = wrap.querySelector('input, textarea');
            if (input) input.placeholder = value(key + '_placeholder');
        });

        var offerText = previewForm.querySelector('.cdm-etsy-text');
        if (offerText) offerText.textContent = value('etsy_offer_text');
        var etsyBtn = previewForm.querySelector('.cdm-etsy-button');
        if (etsyBtn) etsyBtn.textContent = value('etsy_button_text');

        if (customStyle) customStyle.textContent = value('form_custom_css') + '\n' + value('progress_custom_css');

        var box = previewForm.querySelector('.cdm-progress');
        if (box && !demoRunning) {
            box.hidden = !value('progress_enabled');
            box.setAttribute('data-text', value('progress_text'));
            box.querySelector('.cdm-progress-text').textContent = fmt(String(value('progress_text')), num('progress_seconds', 1, 600, 5), 40);
            box.querySelector('.cdm-progress-fill').style.width = '40%';
        }
    }

    var demoRunning = false;

    function fmt(template, seconds, percent) {
        return template.replace(/\{seconds\}/g, seconds).replace(/\{percent\}/g, Math.round(percent));
    }

    // Plays the progress bar (max 8s) so the custom CSS can be checked while it moves.
    function playDemo() {
        if (demoRunning) return;
        if (!value('progress_enabled')) { window.alert('Enable the progress bar first.'); return; }
        var box = previewForm.querySelector('.cdm-progress');
        if (!box) return;
        demoRunning = true;
        var total = Math.min(8, num('progress_seconds', 1, 600, 5)) * 1000;
        var template = String(value('progress_text'));
        var end = Date.now() + total;
        box.hidden = false;
        var id = setInterval(function () {
            var left = Math.max(0, end - Date.now());
            var percent = Math.min(100, (1 - left / total) * 100);
            box.querySelector('.cdm-progress-text').textContent = fmt(template, Math.ceil(left / 1000), percent);
            box.querySelector('.cdm-progress-fill').style.width = percent + '%';
            if (left <= 0) { clearInterval(id); demoRunning = false; apply(); }
        }, 100);
    }

    var demoButton = document.getElementById('cdm-progress-demo');
    if (demoButton) demoButton.addEventListener('click', playDemo);

    form.addEventListener('input', apply);
    form.addEventListener('change', apply);

    document.querySelectorAll('[data-cdm-preset]').forEach(function (button) {
        button.addEventListener('click', function () {
            var preset = presets[button.getAttribute('data-cdm-preset')];
            if (!preset) return;
            Object.keys(preset).forEach(function (key) { setValue(key, preset[key]); });
            apply();
        });
    });

    // The preview is for looking only.
    stage.addEventListener('submit', function (event) { event.preventDefault(); });
    stage.addEventListener('click', function (event) {
        if (event.target.closest('a')) event.preventDefault();
    });

    apply();
})();
