/**
 * Angeo Robots.txt AEO — admin dashboard JS
 *
 * Loaded via the layout file and bound to elements by `data-*` attributes
 * passed from the PHTML. No inline event handlers, no inline scripts — CSP-clean.
 *
 * @since 2.0.0
 */
define(['jquery', 'mage/url'], function ($, urlBuilder) {
    'use strict';

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

        function showAlert(type, message) {
            var area = document.getElementById('angeo-result-area');
            if (!area) {
                return;
            }
            area.innerHTML = '<div class="angeo-alert ' + type + '">' + message + '</div>';
            area.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function clearAlert() {
            var area = document.getElementById('angeo-result-area');
            if (area) {
                area.innerHTML = '';
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
                                cell.textContent = '✓ present';
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

                    if (data.pass) {
                        showAlert('success', '✓ All enabled AI crawler rules are present in the live robots.txt.');
                    } else {
                        showAlert('warn',
                            '⚠ Missing entries: <strong>' + (data.missing || []).join(', ') + '</strong>. ' +
                            'Save config and flush cache, then re-validate. ' +
                            'On Adobe Commerce Cloud, also purge the Fastly CDN cache.'
                        );
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
                        showAlert('warn',
                            '⚠ Live robots.txt is missing: <strong>' + data.missing.join(', ') + '</strong>. ' +
                            'The preview shows what it will look like after the module serves it.'
                        );
                    } else if (data.present && data.present.length > 0) {
                        showAlert('success', '✓ Live robots.txt already contains all enabled AI bot rules.');
                    }
                });
            });
        }
    };
});
