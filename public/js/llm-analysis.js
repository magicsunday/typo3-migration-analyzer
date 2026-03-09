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
            '<p class="text-muted small mt-2 mb-0">Analyzing...</p>' +
            '</div>';
    }

    function renderResult(data) {
        if (!resultContainer) {
            return;
        }

        console.debug('LLM renderResult:', {codeMappings: (data.codeMappings || []).length, rectorAssessment: !!data.rectorAssessment});

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

        if (data.reasoning) {
            html += '<p class="small text-muted fst-italic mb-2">' + escapeHtml(data.reasoning) + '</p>';
        }

        if (data.migrationSteps && data.migrationSteps.length > 0) {
            html += '<h6 class="small fw-bold text-muted mt-3 mb-1">Migration Steps</h6><ol class="small mb-2">';
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

        if (data.affectedComponents && data.affectedComponents.length > 0) {
            html += '<div class="mt-2">';
            data.affectedComponents.forEach(function (component) {
                html += '<span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 me-1">' + escapeHtml(component) + '</span>';
            });
            html += '</div>';
        }

        if (data.codeMappings && data.codeMappings.length > 0) {
            html += '<h6 class="small fw-bold text-muted mt-3 mb-1">Code Mappings</h6>';
            html += '<div class="small mb-2">';
            data.codeMappings.forEach(function (mapping) {
                html += '<div class="d-flex align-items-start gap-2 mb-1">';
                html += '<code class="text-danger text-break">' + escapeHtml(mapping.old) + '</code>';
                html += '<i class="bi bi-arrow-right text-muted flex-shrink-0"></i>';
                if (mapping['new']) {
                    html += '<code class="text-success text-break">' + escapeHtml(mapping['new']) + '</code>';
                } else {
                    html += '<span class="text-muted fst-italic">removed</span>';
                }
                html += '<span class="badge bg-light text-muted border ms-auto flex-shrink-0">' + escapeHtml(mapping.type) + '</span>';
                html += '</div>';
            });
            html += '</div>';
        }

        if (data.rectorAssessment) {
            html += '<div class="mt-2 small border-top pt-2">';
            html += '<i class="bi bi-gear me-1"></i><strong>Rector:</strong> ';
            if (data.rectorAssessment.feasible) {
                html += '<span class="text-success">Automatable</span>';
                if (data.rectorAssessment.ruleType) {
                    html += ' <span class="text-muted">via ' + escapeHtml(data.rectorAssessment.ruleType) + '</span>';
                }
            } else {
                html += '<span class="text-danger">Not automatable</span>';
            }
            if (data.rectorAssessment.notes) {
                html += '<br><span class="text-muted">' + escapeHtml(data.rectorAssessment.notes) + '</span>';
            }
            html += '</div>';
        }

        html += '<div class="text-muted small mt-3 border-top pt-2">' +
            '<i class="bi bi-cpu me-1"></i>' + escapeHtml(data.modelId) + '<br>' +
            '<i class="bi bi-lightning me-1"></i>' + (data.tokensInput + data.tokensOutput) + ' Tokens, ' + data.durationMs + 'ms' +
            '</div>';

        resultContainer.innerHTML = html;

        // Show re-analyze button in card header if not already present
        var cardHeader = resultContainer.closest('.card').querySelector('.card-header');

        if (cardHeader && !document.getElementById('llm-reanalyze')) {
            var btn = document.createElement('button');
            btn.className = 'btn btn-sm btn-outline-primary';
            btn.id = 'llm-reanalyze';
            btn.dataset.url = (analyzeNowBtn || reanalyzeBtn).dataset.url;
            btn.innerHTML = '<i class="bi bi-arrow-clockwise"></i>';
            btn.addEventListener('click', function () {
                triggerAnalysis(btn.dataset.url, true);
            });
            cardHeader.appendChild(btn);
        }
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
