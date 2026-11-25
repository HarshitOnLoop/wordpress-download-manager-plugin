(function () {
    'use strict';

    var lastFocus = null;

    function text(key, fallback) {
        return (window.CDM_Ajax && CDM_Ajax.i18n && CDM_Ajax.i18n[key]) || fallback;
    }

    function openModal(resource) {
        var modal = document.getElementById('cdm-modal');
        var content = document.getElementById('cdm-modal-content');
        if (!modal || !content) return;

        var template = null;
        document.querySelectorAll('.cdm-popup-template').forEach(function (item) {
            if (item.dataset.resource === resource) template = item;
        });
        content.innerHTML = '';
        if (template) content.appendChild(template.content.cloneNode(true));

        lastFocus = document.activeElement;
        modal.classList.add('cdm-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('cdm-modal-active');
        var first = content.querySelector('input:not([type="hidden"]):not(.cdm-honeypot), textarea');
        if (first) first.focus();
    }

    function closeModal() {
        var modal = document.getElementById('cdm-modal');
        if (!modal) return;
        modal.classList.remove('cdm-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('cdm-modal-active');
        if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.cdm-open-popup');
        if (button) {
            event.preventDefault();
            openModal(button.dataset.resource);
            return;
        }
        if (event.target.closest('[data-cdm-close]')) closeModal();
    });

    document.addEventListener('keydown', function (event) {
        var modal = document.getElementById('cdm-modal');
        if (event.key === 'Escape' && modal && modal.classList.contains('cdm-open')) closeModal();
    });

    /* ---------- sending progress bar ---------- */

    function progressText(template, secondsLeft, percent) {
        return template.replace(/\{seconds\}/g, secondsLeft).replace(/\{percent\}/g, Math.round(percent));
    }

    // Shows the bar for `seconds`, then calls done(). The bar stays visible until hideProgress().
    function runProgress(form, seconds, done) {
        var box = form.querySelector('.cdm-progress');
        if (!box) { done(); return; }
        var template = box.getAttribute('data-text') || 'Sending your download link...';
        var label = box.querySelector('.cdm-progress-text');
        var fill = box.querySelector('.cdm-progress-fill');
        var total = seconds * 1000;
        var end = Date.now() + total;
        var timer = null;

        form.classList.add('cdm-processing');
        box.hidden = false;

        function tick() {
            var left = Math.max(0, end - Date.now());
            var percent = Math.min(100, (1 - left / total) * 100);
            if (label) label.textContent = progressText(template, Math.ceil(left / 1000), percent);
            if (fill) fill.style.width = percent + '%';
            if (left <= 0) {
                clearInterval(timer);
                done();
            }
        }
        tick();
        if (Date.now() < end) timer = setInterval(tick, 100);
    }

    function hideProgress(form) {
        var box = form.querySelector('.cdm-progress');
        if (box) box.hidden = true;
        var fill = form.querySelector('.cdm-progress-fill');
        if (fill) fill.style.width = '0%';
        form.classList.remove('cdm-processing');
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest ? event.target.closest('.cdm-form') : null;
        if (!form) return;
        event.preventDefault();
        if (form.classList.contains('cdm-loading') || form.classList.contains('cdm-processing')) return;

        var message = form.querySelector('.cdm-message');
        var submit = form.querySelector('[type="submit"]');
        var offer = form.querySelector('.cdm-limit-offer');
        var etsyButton = form.querySelector('.cdm-etsy-button');

        function show(type, msg) {
            message.className = 'cdm-message cdm-' + type;
            message.textContent = msg;
        }

        // Client-side validation (the server validates again).
        var missing = false;
        var badEmail = false;
        var firstInvalid = null;
        form.querySelectorAll('.cdm-field [name]').forEach(function (el) {
            el.removeAttribute('aria-invalid');
            var empty = el.type === 'checkbox' ? !el.checked : !el.value.trim();
            if (el.required && empty) {
                missing = true;
                el.setAttribute('aria-invalid', 'true');
                firstInvalid = firstInvalid || el;
            } else if (el.type === 'email' && el.value.trim() && !el.validity.valid) {
                badEmail = true;
                el.setAttribute('aria-invalid', 'true');
                firstInvalid = firstInvalid || el;
            }
        });
        if (missing || badEmail) {
            show('error', missing ? text('missing', 'Please complete all required fields.') : text('invalid_email', 'Please enter a valid email address.'));
            if (firstInvalid) firstInvalid.focus();
            return;
        }

        message.className = 'cdm-message';
        message.textContent = '';
        if (offer) offer.hidden = true;

        // Collect the data now, so it is what the visitor entered when they clicked.
        var body = new URLSearchParams();
        body.set('action', 'cdm_submit');
        body.set('resource', form.dataset.resource || '');
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.disabled || el.type === 'submit') return;
            if (el.type === 'checkbox') {
                if (el.checked) body.set(el.name, '1');
            } else {
                body.set(el.name, el.value);
            }
        });

        // Sends the data to the server (which delivers it to the webhook).
        function send() {
            form.classList.add('cdm-loading');
            submit.disabled = true;

            fetch(CDM_Ajax.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: body.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (result) {
                var data = result && result.data ? result.data : {};
                show(result && result.success ? 'success' : 'error', data.message || text('error', 'An unexpected error occurred.'));
                if (result && result.success) {
                    form.querySelectorAll('input:not([type="hidden"]), textarea').forEach(function (el) {
                        if (el.type === 'checkbox') el.checked = false;
                        else el.value = '';
                    });
                } else if (data.etsy_url && etsyButton && offer) {
                    etsyButton.href = data.etsy_url;
                    offer.hidden = false;
                }
            }).catch(function () {
                show('error', text('error', 'An unexpected error occurred.'));
            }).finally(function () {
                hideProgress(form);
                form.classList.remove('cdm-loading');
                submit.disabled = false;
            });
        }

        // Asks the server first whether this visitor may download (limit, allowed hours).
        // Over the limit: show the message right away, with no progress bar.
        function precheck(proceed) {
            var check = new URLSearchParams();
            check.set('action', 'cdm_check');
            check.set('resource', form.dataset.resource || '');
            check.set('email', body.get('email') || '');
            check.set('name', body.get('name') || '');
            form.classList.add('cdm-loading');
            submit.disabled = true;

            fetch(CDM_Ajax.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                body: check.toString()
            }).then(function (response) {
                return response.json();
            }).then(function (result) {
                form.classList.remove('cdm-loading');
                if (result && result.success === false) {
                    var data = result.data || {};
                    show('error', data.message || text('error', 'An unexpected error occurred.'));
                    if (data.etsy_url && etsyButton && offer) {
                        etsyButton.href = data.etsy_url;
                        offer.hidden = false;
                    }
                    submit.disabled = false;
                    return;
                }
                proceed();
            }).catch(function () {
                // If the check itself fails, carry on; the real submit validates everything again.
                form.classList.remove('cdm-loading');
                proceed();
            });
        }

        var seconds = parseInt(form.getAttribute('data-progress'), 10);
        if (seconds > 0) {
            // Check first, then show the progress bar; the data is sent only when it finishes.
            precheck(function () {
                submit.disabled = true;
                runProgress(form, seconds, send);
            });
        } else {
            send();
        }
    });
})();
