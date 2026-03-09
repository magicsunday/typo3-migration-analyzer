(function () {
    'use strict';

    // --- Provider toggle: filter models by provider ---

    var providerRadios = document.querySelectorAll('[data-provider-toggle]');
    var modelSelect = document.getElementById('model-id');

    function filterModelsByProvider() {
        if (!modelSelect) {
            return;
        }

        var selected = document.querySelector('[data-provider-toggle]:checked');

        if (!selected) {
            return;
        }

        var provider = selected.value;
        var currentModel = modelSelect.value;
        var firstVisible = null;
        var currentStillVisible = false;

        Array.from(modelSelect.options).forEach(function (option) {
            var optionProvider = option.dataset.provider;
            var visible = optionProvider === provider;
            option.hidden = !visible;
            option.disabled = !visible;

            if (visible && firstVisible === null) {
                firstVisible = option;
            }

            if (visible && option.value === currentModel) {
                currentStillVisible = true;
            }
        });

        if (!currentStillVisible && firstVisible) {
            firstVisible.selected = true;
        }
    }

    providerRadios.forEach(function (radio) {
        radio.addEventListener('change', filterModelsByProvider);
    });

    filterModelsByProvider();

    // --- API key visibility toggle ---

    var apiKeyInput = document.getElementById('api-key');
    var toggleBtn = document.getElementById('toggle-api-key');

    if (toggleBtn && apiKeyInput) {
        toggleBtn.addEventListener('click', function () {
            var isPassword = apiKeyInput.type === 'password';
            apiKeyInput.type = isPassword ? 'text' : 'password';
            toggleBtn.querySelector('i').className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    }

    // --- Reset prompt to default ---

    var resetBtn = document.getElementById('reset-prompt');
    var promptTextarea = document.getElementById('analysis-prompt');

    if (resetBtn && promptTextarea) {
        resetBtn.addEventListener('click', function () {
            promptTextarea.value = resetBtn.dataset.defaultPrompt;
        });
    }

    // --- Bulk analysis ---

    var bulkStartBtn = document.getElementById('bulk-start');
    var bulkStopBtn = document.getElementById('bulk-stop');
    var bulkProgress = document.getElementById('bulk-progress');
    var bulkStatus = document.getElementById('bulk-status');
    var bulkCurrent = document.getElementById('bulk-current');
    var bulkErrors = document.getElementById('bulk-errors');
    var bulkErrorCount = document.getElementById('bulk-error-count');
    var bulkErrorList = document.getElementById('bulk-error-list');
    var isRunning = false;
    var errorCount = 0;
    var failedFilenames = [];

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));

        return div.innerHTML;
    }

    function updateProgress(progress) {
        if (bulkProgress) {
            bulkProgress.style.width = progress.percent + '%';
            bulkProgress.textContent = progress.percent + '%';
        }

        if (bulkStatus) {
            bulkStatus.textContent = progress.analyzed + ' / ' + progress.total + ' analysiert';
        }
    }

    function showCurrentFile(filename) {
        if (bulkCurrent && filename) {
            bulkCurrent.textContent = filename;
            bulkCurrent.title = filename;
            bulkCurrent.classList.remove('d-none');
        }
    }

    function addError(filename, message) {
        errorCount++;
        failedFilenames.push(filename);

        if (bulkErrors) {
            bulkErrors.classList.remove('d-none');
        }

        if (bulkErrorCount) {
            bulkErrorCount.textContent = errorCount;
        }

        if (bulkErrorList) {
            var entry = document.createElement('div');
            entry.className = 'text-danger mb-1 border-bottom pb-1';
            entry.innerHTML = '<code class="text-break">' + escapeHtml(filename) + '</code><br>' +
                '<span class="text-muted">' + escapeHtml(message) + '</span>';
            bulkErrorList.appendChild(entry);
        }
    }

    function runNextAnalysis() {
        if (!isRunning || !bulkStartBtn) {
            return;
        }

        var body = new FormData();
        failedFilenames.forEach(function (name) {
            body.append('skip[]', name);
        });

        fetch(bulkStartBtn.dataset.url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        })
            .then(function (response) {
                if (!response.ok) {
                    return response.json().catch(function () {
                        return { error: 'Server error: ' + response.status };
                    });
                }
                return response.json();
            })
            .then(function (data) {
                if (data.progress) {
                    updateProgress(data.progress);
                }

                if (data.filename) {
                    showCurrentFile(data.filename);
                }

                if (data.error && data.filename) {
                    addError(data.filename, data.error);
                }

                if (data.complete || !isRunning) {
                    stopBulk();
                    return;
                }

                // Rate limiting: 1 second between calls
                setTimeout(runNextAnalysis, 1000);
            })
            .catch(function (error) {
                if (bulkStatus) {
                    bulkStatus.textContent = 'Fehler: ' + error.message;
                }
                stopBulk();
            });
    }

    function stopBulk() {
        isRunning = false;

        if (bulkStartBtn) {
            bulkStartBtn.disabled = false;
            bulkStartBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Analyse starten';
        }

        if (bulkStopBtn) {
            bulkStopBtn.classList.add('d-none');
        }

        if (bulkCurrent) {
            bulkCurrent.classList.add('d-none');
        }
    }

    if (bulkStartBtn) {
        bulkStartBtn.addEventListener('click', function () {
            isRunning = true;
            errorCount = 0;
            failedFilenames = [];

            if (bulkErrors) {
                bulkErrors.classList.add('d-none');
            }

            if (bulkErrorList) {
                bulkErrorList.innerHTML = '';
            }

            bulkStartBtn.disabled = true;
            bulkStartBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Läuft...';

            if (bulkStopBtn) {
                bulkStopBtn.classList.remove('d-none');
            }

            runNextAnalysis();
        });
    }

    if (bulkStopBtn) {
        bulkStopBtn.addEventListener('click', stopBulk);
    }
})();
