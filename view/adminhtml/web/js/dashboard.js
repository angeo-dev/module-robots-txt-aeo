/**
 * Angeo Robots.txt AEO — admin dashboard JS
 *
 * Loaded via the layout file and bound to elements by `data-*` attributes
 * passed from the PHTML. No inline event handlers, no inline scripts — CSP-clean.
 *
 * @since 2.0.1 — showAlert() builds DOM nodes via textContent instead of
 *                concatenating server-provided strings into innerHTML. Even
 *                though current payloads are admin-controlled, this removes
 *                the HTML-injection sink entirely.
 */
define(['jquery', 'mage/url'], function ($, urlBuilder) {
    'use strict';

    var ALERT_TYPES = ['success', 'warn', 'error'];

    return function (config) {
        var validateUrl = config.validateUrl;
        var previewUrl  = config.previewUrl;
        var formKey     = config.formKey;

        function setLoading(btnId, spinnerId, loading) {
            var btn = document.getElementById(btnId);
            var sp  = document.getElementById(spinnerId);
            if (!btn || !sp) {
                return;
            }
            btn.disabled = !!loading;
            sp.style.display = loading ? 'inline-block' : 'none';
        }

        /**
         * Render an alert without ever passing dynamic strings through
         * innerHTML. `parts` is an array of {text: string, strong: bool};
         * a plain string is accepted as shorthand for one non-strong part.
         */
        function showAlert(type, parts) {
            var area = document.getElementById('angeo-result-area');
            if (!area) {
                return;
            }

            if (typeof parts === 'string') {
                parts = [{ text: parts, strong: false }];
            }

            var div = document.createElement('div');
            div.className = 'angeo-alert ' +
                (ALERT_TYPES.indexOf(type) !== -1 ? type : 'error');

            parts.forEach(function (part) {
                if (part.strong) {
                    var strong = document.createElement('strong');
                    strong.textContent = part.text;
                    div.appendChild(strong);
                } else {
                    div.appendChild(document.createTextNode(part.text));
                }
            });

            area.replaceChildren(div);
            area.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function clearAlert() {
            var area = document.getElementById('angeo-result-area');
            if (area) {
                area.replaceChildren();
            }
        }

        function post(url, callback) {
            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                data: { form_key: formKey }
            }).done(function (data) {
                callback(data);
            }).fail(function (xhr) {
                callback({ success: false, error: 'Invalid response from server. Status: ' + xhr.status });
            });
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        /**
         * Syntax-highlighted preview. The text is HTML-escaped FIRST; the
         * regex passes below only wrap already-escaped text in styling spans,
         * so no unescaped server content ever reaches innerHTML.
         */
        function renderPreview(target, text) {
            var html = escapeHtml(text);
            html = html.replace(/(# Angeo AEO[^\n]*)/g, '<span class="angeo-syn-header">$1</span>');
            html = html.replace(/(# End Angeo[^\n]*)/g, '<span class="angeo-syn-header">$1</span>');
            html = html.replace(/(# [^\n]*)/g, '<span class="angeo-syn-comment">$1</span>');
            html = html.replace(/(User-agent:|Sitemap:|Crawl-delay:)([^\n]*)/g,
                '<span class="angeo-syn-key">$1</span><span class="angeo-syn-val">$2</span>');
            html = html.replace(/(Allow:|Disallow:)([^\n]*)/g,
                '<span class="angeo-syn-rule">$1</span><span class="angeo-syn-rule-val">$2</span>');
            target.innerHTML = html;
        }

        var validateBtn = document.getElementById('angeo-btn-validate');
        if (validateBtn) {
            validateBtn.addEventListener('click', function () {
                clearAlert();
                setLoading('angeo-btn-validate', 'validate-spinner', true);

                post(validateUrl, function (data) {
                    setLoading('angeo-btn-validate', 'validate-spinner', false);

                    if (!data.success) {
                        showAlert('error', '✗ ' + (data.error || 'Validation failed.'));
                        return;
                    }

                    var tbody = document.getElementById('angeo-bot-tbody');
                    if (tbody) {
                        tbody.querySelectorAll('tr[data-bot]').forEach(function (row) {
                            var ua    = row.getAttribute('data-bot');
                            var cell  = row.querySelector('.angeo-status-badge');
                            var bot   = data.bots ? data.bots.find(function (b) { return b.user_agent === ua; }) : null;
                            if (!bot || !cell) { return; }

                            if (bot.status === 'disabled') {
                                cell.className = 'angeo-status-badge disabled';
                                cell.textContent = 'disabled';
                            } else if (bot.status === 'pass') {
                                cell.className = 'angeo-status-badge pass';
                                cell.textContent = bot.deprecated
                                    ? '✓ present (deprecated)'
                                    : '✓ root allowed';
                            } else if (bot.present && bot.allowed_root === false) {
                                // v3: present but the merged RFC 9309 rules block "/"
                                cell.className = 'angeo-status-badge fail';
                                cell.textContent = '✗ root blocked' +
                                    (bot.matched_rule ? ' (' + bot.matched_rule + ')' : '');
                            } else {
                                cell.className = 'angeo-status-badge fail';
                                cell.textContent = '✗ missing';
                            }
                        });
                    }

                    var scoreBadge = document.getElementById('angeo-score-badge');
                    if (scoreBadge) {
                        scoreBadge.style.display = 'inline-flex';
                        if (data.pass) {
                            scoreBadge.className = 'angeo-badge enabled';
                            scoreBadge.textContent = '✓ All rules present';
                        } else {
                            scoreBadge.className = 'angeo-badge disabled';
                            scoreBadge.textContent = (data.missing || []).length + ' missing';
                        }
                    }

                    if (data.warnings && data.warnings.length > 0) {
                        showAlert('warn', '⚠ ' + data.warnings.join(' '));
                    }

                    if (data.pass) {
                        showAlert('success', '✓ All enabled AI crawler rules are present and allowed at the root.');
                    } else {
                        showAlert('warn', [
                            { text: '⚠ Missing entries: ', strong: false },
                            { text: (data.missing || []).join(', '), strong: true },
                            { text: '. Save config and flush cache, then re-validate. ' +
                                    'On Adobe Commerce Cloud, also purge the Fastly CDN cache.', strong: false }
                        ]);
                    }
                });
            });
        }

        var previewBtn = document.getElementById('angeo-btn-preview');
        if (previewBtn) {
            previewBtn.addEventListener('click', function () {
                clearAlert();
                setLoading('angeo-btn-preview', 'preview-spinner', true);

                post(previewUrl, function (data) {
                    setLoading('angeo-btn-preview', 'preview-spinner', false);

                    if (!data.success) {
                        showAlert('error', '✗ ' + (data.error || 'Preview failed.'));
                        return;
                    }

                    var pre = document.getElementById('angeo-preview-content');
                    if (pre) {
                        renderPreview(pre, data.preview || '');
                    }

                    if (data.missing && data.missing.length > 0) {
                        showAlert('warn', [
                            { text: '⚠ Live robots.txt is missing: ', strong: false },
                            { text: data.missing.join(', '), strong: true },
                            { text: '. The preview shows what it will look like after the module serves it.', strong: false }
                        ]);
                    } else if (data.present && data.present.length > 0) {
                        showAlert('success', '✓ Live robots.txt already contains all enabled AI bot rules.');
                    }
                });
            });
        }
    };
});
