(function () {
    'use strict';

    const config = window.WextStaticPublisherAdmin;
    const i18n = config && config.i18n ? config.i18n : {};
    const hideContentInput = document.querySelector('#wextstat-hide-wp-content');
    const hideContentPrefixes = document.querySelectorAll('[data-wextstat-content-prefix]');
    if (hideContentInput && hideContentPrefixes.length) {
        const updateHidePrefixes = function () {
            const prefix = hideContentInput.value.trim() || 'wp-content';
            hideContentPrefixes.forEach(function (element) {
                element.textContent = prefix + '/';
            });
        };
        hideContentInput.addEventListener('input', updateHidePrefixes);
        updateHidePrefixes();
    }

    const confirmModal = document.querySelector('[data-wextstat-confirm-modal]');
    if (confirmModal) {
        const confirmMessage = confirmModal.querySelector('#wextstat-confirm-message');
        const confirmButton = confirmModal.querySelector('[data-wextstat-confirm-accept]');
        const defaultConfirmLabel = confirmButton.textContent;
        const cancelButtons = confirmModal.querySelectorAll('[data-wextstat-confirm-cancel]');
        let pendingButton = null;
        let previousFocus = null;

        const closeConfirmModal = function () {
            confirmModal.hidden = true;
            document.body.classList.remove('wextstat-modal-open');
            pendingButton = null;
            if (previousFocus) {
                previousFocus.focus();
            }
            previousFocus = null;
        };

        const openConfirmModal = function (button) {
            pendingButton = button;
            previousFocus = document.activeElement;
            confirmMessage.textContent = button.dataset.wextstatConfirm || '';
            confirmButton.textContent = button.dataset.wextstatConfirmLabel || defaultConfirmLabel;
            confirmModal.hidden = false;
            document.body.classList.add('wextstat-modal-open');
            confirmButton.focus();
        };

        document.addEventListener('click', function (event) {
            const button = event.target.closest('[data-wextstat-confirm]');
            if (!button) {
                return;
            }

            const requiredAction = button.dataset.wextstatConfirmAction;
            const selectedAction = button.form && button.form.elements.archive_bulk_action
                ? button.form.elements.archive_bulk_action.value
                : '';
            if (requiredAction && selectedAction !== requiredAction) {
                return;
            }

            event.preventDefault();
            openConfirmModal(button);
        });

        cancelButtons.forEach(function (button) {
            button.addEventListener('click', closeConfirmModal);
        });

        confirmButton.addEventListener('click', function () {
            const button = pendingButton;
            if (!button || !button.form) {
                closeConfirmModal();
                return;
            }
            if (button.hasAttribute('data-wextstat-clear-bulk-action') && button.form.elements.archive_bulk_action) {
                button.form.elements.archive_bulk_action.value = '';
            }
            confirmModal.hidden = true;
            document.body.classList.remove('wextstat-modal-open');
            pendingButton = null;
            previousFocus = null;
            confirmButton.textContent = defaultConfirmLabel;
            button.form.requestSubmit(button);
        });

        confirmModal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeConfirmModal();
                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const focusable = Array.from(confirmModal.querySelectorAll('button:not([disabled])'));
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    }

    const seoPluginCards = Array.from(document.querySelectorAll('[data-wextstat-seo-card]'));
    if (seoPluginCards.length) {
        const setSeoCardExpanded = function (card, expanded) {
            const toggle = card.querySelector('[data-wextstat-seo-toggle]');
            const panel = card.querySelector('[data-wextstat-seo-panel]');
            if (!toggle || !panel) {
                return;
            }
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            panel.hidden = !expanded;
            card.classList.toggle('is-expanded', expanded);
        };

        const updateSeoOutputCount = function (card) {
            const count = card.querySelector('[data-wextstat-output-count]');
            const outputs = Array.from(card.querySelectorAll('[data-wextstat-seo-output]'));
            if (!count || !outputs.length) {
                return;
            }
            const selected = outputs.filter(function (input) {
                return input.checked;
            }).length;
            const selectedElement = count.querySelector('strong');
            if (selectedElement) {
                selectedElement.textContent = String(selected);
            }
        };

        seoPluginCards.forEach(function (card) {
            const toggle = card.querySelector('[data-wextstat-seo-toggle]');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    const willExpand = toggle.getAttribute('aria-expanded') !== 'true';
                    seoPluginCards.forEach(function (otherCard) {
                        setSeoCardExpanded(otherCard, willExpand && otherCard === card);
                    });
                });
            }
            card.querySelectorAll('[data-wextstat-seo-output]').forEach(function (input) {
                input.addEventListener('change', function () {
                    updateSeoOutputCount(card);
                });
            });
            updateSeoOutputCount(card);
        });
    }

    const root = document.querySelector('[data-wextstat-status-root]');
    if (!config || !root) {
        return;
    }

    const stateLabels = {
        queued: i18n.queued || 'Queued',
        running: i18n.running || 'Running',
        completed: i18n.completed || 'Completed',
        failed: i18n.failed || 'Failed'
    };
    const stateElement = root.querySelector('#wextstat-status-state');
    const progressCell = root.querySelector('#wextstat-progress-cell');
    const messageElement = root.querySelector('#wextstat-status-message');
    const jobElement = root.querySelector('#wextstat-job-id');
    const urlCountElement = root.querySelector('#wextstat-url-count');
    const currentUrlRow = root.querySelector('#wextstat-current-url-row');
    const currentUrlElement = root.querySelector('#wextstat-current-url');
    const lastCompletedElement = root.querySelector('#wextstat-last-completed');
    const errorRow = root.querySelector('#wextstat-error-row');
    const errorElement = root.querySelector('#wextstat-error');
    const noticeElement = root.querySelector('#wextstat-runtime-notice');
    const deploymentStateElement = root.querySelector('#wextstat-deployment-state');
    const deploymentJobElement = root.querySelector('#wextstat-deployment-job-id');
    const deploymentUpdatedElement = root.querySelector('#wextstat-deployment-updated');
    const deploymentUrlRow = root.querySelector('#wextstat-deployment-url-row');
    const deploymentUrlElement = root.querySelector('#wextstat-deployment-url');
    const deploymentErrorRow = root.querySelector('#wextstat-deployment-error-row');
    const deploymentErrorElement = root.querySelector('#wextstat-deployment-error');
    const submitButton = root.querySelector('.wextstat-actions [type="submit"]');
    const downloadButton = root.querySelector('#wextstat-download');
    let pollTimer = null;
    let failedPolls = 0;
    const startedJobs = new Set();

    function replaceText(element, value, fallback) {
        if (element) {
            element.textContent = value === undefined || value === null || value === '' ? fallback : String(value);
        }
    }

    function renderProgress(state, progress) {
        if (!progressCell) {
            return;
        }

        progressCell.replaceChildren();
        if (state === 'completed' || state === 'failed') {
            const status = document.createElement('span');
            status.className = 'wextstat-progress-status';
            const icon = document.createElement('span');
            icon.className = 'dashicons ' + (state === 'completed' ? 'dashicons-yes-alt' : 'dashicons-dismiss');
            icon.setAttribute('aria-hidden', 'true');
            const label = document.createElement('strong');
            label.textContent = state === 'completed' ? stateLabels.completed : stateLabels.failed;
            status.append(icon, label);
            progressCell.append(status);
            return;
        }

        const progressBar = document.createElement('div');
        progressBar.className = 'wextstat-progress ' + (state === 'queued' || state === 'running' ? 'is-active' : '');
        progressBar.setAttribute('role', 'progressbar');
        progressBar.setAttribute('aria-label', i18n.progressLabel || 'Static rendering progress');
        progressBar.setAttribute('aria-valuemin', '0');
        progressBar.setAttribute('aria-valuemax', '100');
        progressBar.setAttribute('aria-valuenow', String(progress));
        const bar = document.createElement('div');
        bar.className = 'wextstat-progress__bar';
        bar.style.width = progress + '%';
        const percentage = document.createElement('span');
        percentage.className = 'wextstat-progress-percent';
        percentage.textContent = progress + '%';
        progressBar.append(bar);
        progressCell.append(progressBar, percentage);
    }

    function showNotice(message) {
        if (!noticeElement) {
            return;
        }
        const paragraph = noticeElement.querySelector('p');
        replaceText(paragraph, message, '');
        noticeElement.hidden = !message;
    }

    function renderStatus(status) {
        const state = String(status.state || '');
        const progress = Math.max(0, Math.min(100, Number.parseInt(status.progress, 10) || 0));
        const active = state === 'queued' || state === 'running';
        const canRetry = state === 'queued' && Boolean(status.stalled);

        replaceText(stateElement, stateLabels[state], i18n.notRun || 'Not run yet');
        renderProgress(state, progress);
        replaceText(messageElement, status.status_message, '—');
        replaceText(jobElement, status.job_id, '—');
        replaceText(urlCountElement, status.url_count, '0');
        replaceText(lastCompletedElement, status.last_completed_display, '—');

        const currentUrl = String(status.current_url || '');
        replaceText(currentUrlElement, currentUrl, '');
        if (currentUrlRow) {
            currentUrlRow.hidden = currentUrl === '';
        }

        const error = String(status.error || '');
        replaceText(errorElement, error, '');
        if (errorRow) {
            errorRow.hidden = error === '';
        }

        showNotice(String(status.runtime_notice || ''));
        if (submitButton) {
            submitButton.disabled = active && !canRetry;
            const primaryAction = config.cloudflareConfigured
                ? (i18n.deployCloudflare || 'Deploy to Cloudflare')
                : (i18n.create || 'Create Static Site');
            submitButton.value = canRetry ? (i18n.retry || 'Retry') : primaryAction;
        }
        if (downloadButton && state === 'completed') {
            downloadButton.hidden = false;
        }

        const deployment = status.deployment && typeof status.deployment === 'object' ? status.deployment : {};
        const deploymentState = String(deployment.state || '');
        const deploymentLabels = {
            waiting: i18n.waiting || 'Waiting',
            dispatched: stateLabels.queued,
            deploying: stateLabels.running,
            completed: stateLabels.completed,
            failed: stateLabels.failed
        };
        replaceText(deploymentStateElement, deploymentLabels[deploymentState], i18n.notDeployed || 'Not deployed yet');
        replaceText(deploymentJobElement, deployment.job_id, '—');
        replaceText(deploymentUpdatedElement, deployment.updated_display, '—');
        const deploymentUrl = String(deployment.deployment_url || '');
        if (deploymentUrlElement) {
            deploymentUrlElement.textContent = deploymentUrl;
            deploymentUrlElement.href = deploymentUrl;
        }
        if (deploymentUrlRow) {
            deploymentUrlRow.hidden = deploymentUrl === '';
        }
        const deploymentError = String(deployment.error || '');
        replaceText(deploymentErrorElement, deploymentError, '');
        if (deploymentErrorRow) {
            deploymentErrorRow.hidden = deploymentError === '';
        }

        if (state === 'queued') {
            startPendingJob(String(status.job_id || ''));
        }

        return active || ['waiting', 'dispatched', 'deploying'].includes(deploymentState);
    }

    function startPendingJob(jobId) {
        if (!jobId || startedJobs.has(jobId) || !config.runnerUrl || !config.runnerNonce) {
            return;
        }
        startedJobs.add(jobId);
        const body = new window.URLSearchParams({
            action: 'wext_static_run_pending',
            nonce: config.runnerNonce
        });
        window.fetch(config.runnerUrl, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString()
        }).catch(function () {
            startedJobs.delete(jobId);
        });
    }

    function schedulePoll() {
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(pollStatus, Number(config.pollInterval) || 2000);
    }

    async function pollStatus() {
        try {
            const response = await window.fetch(config.statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json',
                    'X-WP-Nonce': config.nonce
                }
            });
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const status = await response.json();
            failedPolls = 0;
            if (renderStatus(status)) {
                schedulePoll();
            }
        } catch (error) {
            failedPolls += 1;
            if (failedPolls >= 3) {
                showNotice(i18n.pollError || 'Progress information is unavailable. Check your internet connection or WordPress REST API access.');
            }
            schedulePoll();
        }
    }

    pollStatus();
    window.addEventListener('pagehide', function () {
        window.clearTimeout(pollTimer);
    });
}());
