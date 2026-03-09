(function () {
    'use strict';

    var resultContainer = document.getElementById('llm-result');

    function showSpinner() {
        if (!resultContainer) {
            return;
        }

        resultContainer.innerHTML =
            '<div class="text-center py-3">' +
            '<div class="spinner-border spinner-border-sm text-typo3" role="status"></div>' +
            '<p class="text-muted small mt-2 mb-0">Analyse läuft...</p>' +
            '</div>';
    }

    function renderResult(data) {
        if (!resultContainer) {
            return;
        }

        var scoreBadge = data.score <= 2 ? 'text-bg-success' :
            data.score === 3 ? 'text-bg-warning' : 'text-bg-danger';

        var gradeBadge, gradeLabel;

        if (data.automationGrade === 'full') {
            gradeBadge = 'text-bg-success';
            gradeLabel = 'Full Automation';
        } else if (data.automationGrade === 'partial') {
            gradeBadge = 'text-bg-warning';
            gradeLabel = 'Partial Automation';
        } else {
            gradeBadge = 'text-bg-danger';
            gradeLabel = 'Manual';
        }

        var html = '<div class="d-flex gap-2 mb-2">' +
            '<span class="badge ' + scoreBadge + '">Score ' + data.score + '/5</span>' +
            '<span class="badge ' + gradeBadge + '">' + gradeLabel + '</span>' +
            '</div>' +
            '<p class="small mb-2">' + escapeHtml(data.summary) + '</p>';

        if (data.migrationSteps && data.migrationSteps.length > 0) {
            html += '<h6 class="small fw-bold text-muted mt-3 mb-1">Migrationsschritte</h6><ol class="small mb-2">';
            data.migrationSteps.forEach(function (step) {
                html += '<li>' + escapeHtml(step) + '</li>';
            });
            html += '</ol>';
        }

        if (data.affectedAreas && data.affectedAreas.length > 0) {
            html += '<div class="mt-2">';
            data.affectedAreas.forEach(function (area) {
                html += '<span class="badge bg-light text-muted border me-1">' + escapeHtml(area) + '</span>';
            });
            html += '</div>';
        }

        html += '<div class="text-muted small mt-3 border-top pt-2">' +
            '<i class="bi bi-cpu me-1"></i>' + escapeHtml(data.modelId) + '<br>' +
            '<i class="bi bi-lightning me-1"></i>' + (data.tokensInput + data.tokensOutput) + ' Tokens, ' + data.durationMs + 'ms' +
            '</div>';

        resultContainer.innerHTML = html;
    }

    function showError(message) {
        if (!resultContainer) {
            return;
        }

        resultContainer.innerHTML =
            '<div class="alert alert-danger small mb-0">' +
            '<i class="bi bi-exclamation-triangle me-1"></i>' + escapeHtml(message) +
            '</div>';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));

        return div.innerHTML;
    }

    function triggerAnalysis(url, force) {
        showSpinner();

        var body = new FormData();

        if (force) {
            body.append('force', '1');
        }

        fetch(url, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body,
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.error) {
                    showError(data.error);
                    return;
                }

                renderResult(data);
            })
            .catch(function (error) {
                showError(error.message);
            });
    }

    // "Jetzt analysieren" button
    var analyzeNowBtn = document.getElementById('llm-analyze-now');

    if (analyzeNowBtn) {
        analyzeNowBtn.addEventListener('click', function () {
            triggerAnalysis(analyzeNowBtn.dataset.url, false);
        });
    }

    // "Re-Analyse" button
    var reanalyzeBtn = document.getElementById('llm-reanalyze');

    if (reanalyzeBtn) {
        reanalyzeBtn.addEventListener('click', function () {
            triggerAnalysis(reanalyzeBtn.dataset.url, true);
        });
    }
})();
