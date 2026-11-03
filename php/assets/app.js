/**
 * MLBD archive console — page transitions, loading states, sync console.
 *
 * Each sync request scrapes a small batch on the server, so the browser is the
 * scheduler: it calls /library/sync/step until the run reports a terminal
 * state. Reloading the page resumes automatically.
 */
(function () {
    'use strict';

    const root = document.documentElement;
    const veil = document.querySelector('[data-scrape-veil]');
    const skeleton = document.querySelector('[data-skeleton]');

    // Declared before any helper runs: the rail poller reads it on pages that
    // have no sync console, so a later ``let`` would hit the temporal dead zone.
    let stepping = false;

    // ---------------------------------------------------------------------
    // Route progress + scrape veil
    // ---------------------------------------------------------------------

    function startRoute() {
        root.classList.add('is-routing');
    }

    function endRoute() {
        root.classList.remove('is-routing');
    }

    function showSkeleton() {
        if (skeleton) {
            skeleton.hidden = false;
        }
    }

    function showVeil() {
        if (!veil) {
            return;
        }
        veil.hidden = false;
        veil.removeAttribute('aria-hidden');
    }

    function hideVeil() {
        if (!veil) {
            return;
        }
        veil.hidden = true;
        veil.setAttribute('aria-hidden', 'true');
    }

    const finder = document.querySelector('[data-finder]');
    if (finder) {
        finder.addEventListener('submit', function () {
            startRoute();
            showSkeleton();
            const results = document.querySelector('.results');
            if (results) {
                results.style.opacity = '0.35';
            }
        });
    }

    document.addEventListener('click', function (event) {
        const link = event.target.closest('a');
        if (!link || event.defaultPrevented) {
            return;
        }
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
            return;
        }
        if (link.target === '_blank' || link.hasAttribute('download')) {
            return;
        }

        const href = link.getAttribute('href') || '';
        if (href === '' || href.startsWith('#') || href.startsWith('http') && link.host !== location.host) {
            return;
        }

        if (link.hasAttribute('data-scrape')) {
            showVeil();
        }
        startRoute();
    });

    window.addEventListener('pageshow', function () {
        endRoute();
        hideVeil();
        if (skeleton) {
            skeleton.hidden = true;
        }
    });

    window.addEventListener('pagehide', function () {
        endRoute();
    });

    // ---------------------------------------------------------------------
    // Soft image loading (posters fade in over their skeleton)
    // ---------------------------------------------------------------------

    document.querySelectorAll('img[data-soft]').forEach(function (img) {
        const mark = function () {
            img.classList.add('is-loaded');
        };
        if (img.complete && img.naturalWidth > 0) {
            mark();
        } else {
            img.addEventListener('load', mark, { once: true });
            img.addEventListener('error', function () {
                img.style.display = 'none';
            }, { once: true });
        }
    });

    // ---------------------------------------------------------------------
    // Sync console
    // ---------------------------------------------------------------------

    const panel = document.querySelector('[data-sync]');
    if (!panel) {
        startRailPoll();
        return;
    }

    const startButton = panel.querySelector('[data-sync-start]');
    const cancelButton = panel.querySelector('[data-sync-cancel]');
    const optionsForm = panel.querySelector('[data-sync-options]');
    const bar = panel.querySelector('[data-sync-bar]');
    const percentLabel = panel.querySelector('[data-sync-percent]');
    const message = panel.querySelector('[data-sync-message]');
    const current = panel.querySelector('[data-sync-current]');
    const processed = panel.querySelector('[data-sync-total]');
    const counters = {
        saved: panel.querySelector('[data-counter="saved"] .counter-value'),
        empty: panel.querySelector('[data-counter="empty"] .counter-value'),
        failed: panel.querySelector('[data-counter="failed"] .counter-value'),
        skipped: panel.querySelector('[data-counter="skipped"] .counter-value')
    };

    let state = {};
    try {
        state = JSON.parse(panel.dataset.state || '{}');
    } catch (error) {
        state = {};
    }

    function api(path, body) {
        return fetch('?r=' + encodeURIComponent(path), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(body || {})
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'The server returned an invalid response.' };
            });
        });
    }

    function number(value) {
        return Number(value || 0).toLocaleString();
    }

    function formatEta(seconds) {
        seconds = Number(seconds) || 0;
        if (seconds < 60) {
            return seconds + 's';
        }
        if (seconds < 3600) {
            return Math.round(seconds / 60) + 'm';
        }
        return Math.round(seconds / 3600) + 'h';
    }

    function render(next) {
        state = next || {};
        const percent = Math.max(0, Math.min(100, Number(state.percent || 0)));

        panel.classList.toggle('is-live', !!state.running);

        if (bar) {
            bar.style.width = percent + '%';
        }
        if (percentLabel) {
            percentLabel.textContent = (percent > 0 && percent < 1 ? '<1' : Math.round(percent)) + '%';
        }
        if (message) {
            message.textContent = state.message || (state.running ? 'Running…' : 'Idle');
        }
        if (processed) {
            processed.textContent = number(state.processed) + '/' + number(state.total);
        }
        if (counters.saved) {
            counters.saved.textContent = number(state.saved);
        }
        if (counters.empty) {
            counters.empty.textContent = number(state.empty);
        }
        if (counters.failed) {
            counters.failed.textContent = number(state.failed);
        }
        if (counters.skipped) {
            counters.skipped.textContent = number(state.skipped);
        }
        if (current) {
            if (state.running && state.current) {
                current.textContent = 'now scraping · ' + state.current +
                    (state.eta_seconds ? ' · ~' + formatEta(state.eta_seconds) + ' left' : '');
            } else {
                current.textContent = '';
            }
        }

        if (startButton) {
            startButton.disabled = !!state.running;
            startButton.textContent = state.running ? 'Syncing…' : 'Start sync';
        }
        if (cancelButton) {
            cancelButton.disabled = !state.running;
        }

        updateRail(state);
    }

    function sleep(ms) {
        return new Promise(function (resolve) {
            window.setTimeout(resolve, ms);
        });
    }

    async function stepUntilDone() {
        if (stepping) {
            return;
        }
        stepping = true;

        try {
            while (state && state.running) {
                const response = await api('/library/sync/step');
                if (!response.ok) {
                    render(Object.assign({}, state, {
                        running: false,
                        message: (response.error || 'Sync failed.') + ' Press Start sync to resume.'
                    }));
                    break;
                }
                render(response.data);
                if (state.running) {
                    await sleep(200);
                }
            }
        } catch (error) {
            render(Object.assign({}, state, {
                running: false,
                message: 'Network error: ' + error.message + ' Press Start sync to resume.'
            }));
        } finally {
            stepping = false;
        }
    }

    function readOptions() {
        const options = {};
        if (optionsForm) {
            const category = optionsForm.querySelector('[name="category"]');
            if (category) {
                options.category = category.value;
            }
            ['include_adult', 'force', 'retry_empty', 'probe'].forEach(function (name) {
                const input = optionsForm.querySelector('[name="' + name + '"]');
                options[name] = !!(input && input.checked);
            });
        }
        return options;
    }

    if (startButton) {
        startButton.addEventListener('click', async function () {
            startButton.disabled = true;
            try {
                const response = await api('/library/sync', readOptions());
                if (!response.ok) {
                    render(Object.assign({}, state, {
                        running: false,
                        message: response.error || 'Could not start the sync.'
                    }));
                    return;
                }
                render(response.data);
                await stepUntilDone();
            } catch (error) {
                render(Object.assign({}, state, {
                    running: false,
                    message: 'Network error: ' + error.message
                }));
            }
        });
    }

    if (cancelButton) {
        cancelButton.addEventListener('click', async function () {
            cancelButton.disabled = true;
            try {
                const response = await api('/library/sync/cancel');
                if (response.ok) {
                    render(response.data);
                }
            } catch (error) {
                cancelButton.disabled = false;
            }
        });
    }

    render(state);
    if (state.running) {
        stepUntilDone();
    }

    // ---------------------------------------------------------------------
    // Rail sync status (kept in sync while the console runs or cron works)
    // ---------------------------------------------------------------------

    function railLine() {
        return document.querySelector('[data-rail-line]');
    }

    function updateRail(next) {
        const rail = document.querySelector('[data-rail-status]');
        if (!rail || !next) {
            return;
        }
        rail.classList.toggle('is-live', !!next.running);
        const line = railLine();
        if (!line) {
            return;
        }
        if (next.running) {
            line.textContent = Math.round(Number(next.percent || 0)) + '% · ' +
                number(next.processed) + '/' + number(next.total);
            return;
        }
        const status = next.status || 'idle';
        line.textContent = status === 'finished'
            ? 'last run finished'
            : (status === 'failed' ? 'last run stopped'
                : (status === 'cancelled' ? 'last run cancelled' : 'idle'));
    }

    function startRailPoll() {
        const rail = document.querySelector('[data-rail-status]');
        if (!rail) {
            return;
        }
        window.setInterval(async function () {
            if (document.hidden) {
                return;
            }
            // The console owns the state while it is stepping.
            if (stepping) {
                return;
            }
            try {
                const response = await api('/library/sync/status');
                if (response.ok && response.data) {
                    updateRail(response.data);
                }
            } catch (error) {
                // Status polling is best effort.
            }
        }, 20000);
    }

    startRailPoll();
})();
