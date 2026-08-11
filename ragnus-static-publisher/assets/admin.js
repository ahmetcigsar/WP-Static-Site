(function () {
    'use strict';

    const config = window.RagnusStaticPublisherAdmin;
    const hideContentInput = document.querySelector('#ragstat-hide-wp-content');
    const hideContentPrefixes = document.querySelectorAll('[data-ragstat-content-prefix]');
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
    const root = document.querySelector('[data-ragstat-status-root]');
    if (!config || !root) {
        return;
    }

    const stateLabels = {
        queued: 'Kuyrukta',
        running: 'Çalışıyor',
        completed: 'Tamamlandı',
        failed: 'Başarısız'
    };
    const stateElement = root.querySelector('#ragstat-status-state');
    const progressCell = root.querySelector('#ragstat-progress-cell');
    const messageElement = root.querySelector('#ragstat-status-message');
    const jobElement = root.querySelector('#ragstat-job-id');
    const urlCountElement = root.querySelector('#ragstat-url-count');
    const currentUrlRow = root.querySelector('#ragstat-current-url-row');
    const currentUrlElement = root.querySelector('#ragstat-current-url');
    const lastCompletedElement = root.querySelector('#ragstat-last-completed');
    const errorRow = root.querySelector('#ragstat-error-row');
    const errorElement = root.querySelector('#ragstat-error');
    const noticeElement = root.querySelector('#ragstat-runtime-notice');
    const submitButton = root.querySelector('.ragstat-actions [type="submit"]');
    const downloadButton = root.querySelector('#ragstat-download');
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
            status.className = 'ragstat-progress-status';
            const icon = document.createElement('span');
            icon.className = 'dashicons ' + (state === 'completed' ? 'dashicons-yes-alt' : 'dashicons-dismiss');
            icon.setAttribute('aria-hidden', 'true');
            const label = document.createElement('strong');
            label.textContent = state === 'completed' ? 'Tamamlandı' : 'Başarısız';
            status.append(icon, label);
            progressCell.append(status);
            return;
        }

        const progressBar = document.createElement('div');
        progressBar.className = 'ragstat-progress ' + (state === 'queued' || state === 'running' ? 'is-active' : '');
        progressBar.setAttribute('role', 'progressbar');
        progressBar.setAttribute('aria-label', 'Statik oluşturma ilerlemesi');
        progressBar.setAttribute('aria-valuemin', '0');
        progressBar.setAttribute('aria-valuemax', '100');
        progressBar.setAttribute('aria-valuenow', String(progress));
        const bar = document.createElement('div');
        bar.className = 'ragstat-progress__bar';
        bar.style.width = progress + '%';
        const percentage = document.createElement('span');
        percentage.className = 'ragstat-progress-percent';
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

        replaceText(stateElement, stateLabels[state], 'Henüz Çalışmadı');
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
            submitButton.value = canRetry ? 'Yeniden Dene' : 'Statik Site Oluştur';
        }
        if (downloadButton && state === 'completed') {
            downloadButton.hidden = false;
        }

        if (state === 'queued') {
            startPendingJob(String(status.job_id || ''));
        }

        return active;
    }

    function startPendingJob(jobId) {
        if (!jobId || startedJobs.has(jobId) || !config.runnerUrl || !config.runnerNonce) {
            return;
        }
        startedJobs.add(jobId);
        const body = new window.URLSearchParams({
            action: 'ragnus_static_run_pending',
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
                showNotice('İlerleme bilgisi alınamıyor. İnternet bağlantısını veya WordPress REST API erişimini kontrol edin.');
            }
            schedulePoll();
        }
    }

    pollStatus();
    window.addEventListener('pagehide', function () {
        window.clearTimeout(pollTimer);
    });
}());
