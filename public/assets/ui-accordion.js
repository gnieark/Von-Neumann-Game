const panelForTrigger = (button, panelSelector) => {
    const controls = button.getAttribute('aria-controls');
    if (controls) {
        return document.getElementById(controls);
    }

    return button.parentElement ? button.parentElement.querySelector(panelSelector) : null;
};

export const toggleAccordion = (button, scopeSelector, triggerSelector, panelSelector) => {
    const panel = panelForTrigger(button, panelSelector);
    if (!panel) {
        return false;
    }

    const willOpen = button.getAttribute('aria-expanded') !== 'true';
    const scope = button.closest(scopeSelector);
    scope?.querySelectorAll(triggerSelector + '[aria-expanded="true"]').forEach((openButton) => {
        if (openButton === button) {
            return;
        }
        openButton.setAttribute('aria-expanded', 'false');
        const openPanel = panelForTrigger(openButton, panelSelector);
        if (openPanel) {
            openPanel.hidden = true;
        }
    });
    button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    panel.hidden = !willOpen;

    return true;
};

export function bindMetricDetails() {
    document.querySelectorAll('.interactive-metric').forEach((metricNode) => {
        metricNode.addEventListener('click', () => {
            const expanded = metricNode.getAttribute('aria-expanded') === 'true';
            document.querySelectorAll('.interactive-metric[aria-expanded="true"]').forEach((openNode) => {
                if (openNode !== metricNode) {
                    openNode.setAttribute('aria-expanded', 'false');
                }
            });
            metricNode.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        });
    });
}

export const bindAccountMenu = () => {
    const closeAccountMenus = () => {
        document.querySelectorAll('.account-menu-button[aria-expanded="true"]').forEach((button) => {
            button.setAttribute('aria-expanded', 'false');
            button.closest('.account-menu')?.querySelector('.account-menu-panel')?.setAttribute('hidden', '');
        });
    };

    document.querySelectorAll('.account-menu-button').forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const panel = button.closest('.account-menu')?.querySelector('.account-menu-panel');
            const willOpen = button.getAttribute('aria-expanded') !== 'true';
            closeAccountMenus();
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (panel) {
                panel.hidden = !willOpen;
            }
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.account-menu')) {
            closeAccountMenus();
        }
    });

    return closeAccountMenus;
};

export const bindPanelTabs = () => {
    document.querySelectorAll('.panel-tab').forEach((tab) => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.panel-tab').forEach((item) => item.classList.remove('active'));
            document.querySelectorAll('.data-panel').forEach((panel) => panel.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.panelTarget)?.classList.add('active');
        });
    });
};

export const bindRefreshButtons = ({loadCurrentSector, loadMannies, loadProbe}) => {
    document.querySelectorAll('[data-refresh]').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.dataset.refresh === 'sector') {
                loadCurrentSector();
                return;
            }
            if (button.dataset.refresh === 'mannies') {
                loadMannies();
                return;
            }
            loadProbe();
        });
    });
};

export const bindJsonToggles = () => {
    document.querySelectorAll('[data-toggle-json]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.toggleJson || '');
            if (!target) {
                return;
            }

            const willOpen = target.hidden;
            target.hidden = !willOpen;
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            button.textContent = willOpen ? '-' : '+';
            const label = willOpen ? button.dataset.closeLabel : button.dataset.openLabel;
            if (label) {
                button.setAttribute('title', label);
                button.setAttribute('aria-label', label);
            }
        });
    });
};
