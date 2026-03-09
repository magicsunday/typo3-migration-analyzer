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
    var isRunning = false;

    function updateProgress(progress) {
        if (bulkProgress) {
            bulkProgress.style.width = progress.percent + '%';
            bulkProgress.textContent = progress.percent + '%';
        }

        if (bulkStatus) {
            bulkStatus.textContent = progress.analyzed + ' / ' + progress.total + ' analysiert';
        }
    }

    function runNextAnalysis() {
        if (!isRunning || !bulkStartBtn) {
            return;
        }

        fetch(bulkStartBtn.dataset.url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.progress) {
                    updateProgress(data.progress);
                }

                if (data.complete || !isRunning) {
                    stopBulk();
                    return;
                }

                // Rate limiting: 1 second between calls
                setTimeout(runNextAnalysis, 1000);
            })
            .catch(function (error) {
                bulkStatus.textContent = 'Fehler: ' + error.message;
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
    }

    if (bulkStartBtn) {
        bulkStartBtn.addEventListener('click', function () {
            isRunning = true;
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
