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

const showDialog = (dialog) => {
    if (!dialog) {
        return;
    }
    dialog.hidden = false;
    if (typeof dialog.showModal === 'function' && !dialog.open) {
        dialog.showModal();
    }
};

export const bindTutorialDialog = ({closeAccountMenus} = {}) => {
    const previewDialog = document.getElementById('tutorial-image-preview-dialog');
    const previewImage = document.getElementById('tutorial-image-preview');
    const closePreview = () => {
        if (!previewDialog) {
            return;
        }
        if (typeof previewDialog.close === 'function' && previewDialog.open) {
            previewDialog.close();
        }
        previewDialog.hidden = true;
        previewImage?.removeAttribute('src');
    };

    document.querySelectorAll('[data-tutorial-image-preview]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!previewDialog || !previewImage) {
                return;
            }
            const image = button.querySelector('img');
            previewImage.src = button.dataset.tutorialImagePreview || image?.src || '';
            previewImage.alt = image?.alt || '';
            showDialog(previewDialog);
        });
    });

    previewDialog?.querySelector('[data-tutorial-image-preview-close]')?.addEventListener('click', closePreview);
    previewDialog?.addEventListener('click', (event) => {
        if (event.target === previewDialog) {
            closePreview();
        }
    });
    previewDialog?.addEventListener('close', () => {
        previewDialog.hidden = true;
    });

    document.querySelectorAll('[data-tutorial-target]').forEach((trigger) => {
        const dialog = document.getElementById(trigger.dataset.tutorialTarget || '');
        const steps = dialog ? Array.from(dialog.querySelectorAll('[data-tutorial-step]')) : [];
        const progress = dialog?.querySelector('[data-tutorial-progress]');
        const nextButton = dialog?.querySelector('[data-tutorial-next]');
        const closeButton = dialog?.querySelector('[data-tutorial-close-final]');
        let currentStep = 0;

        if (!dialog || steps.length === 0 || !nextButton || !closeButton) {
            return;
        }

        const renderStep = () => {
            steps.forEach((step, index) => {
                step.hidden = index !== currentStep;
            });
            if (progress) {
                progress.textContent = String(currentStep + 1) + ' / ' + String(steps.length);
            }
            nextButton.hidden = currentStep >= steps.length - 1;
            closeButton.hidden = currentStep < steps.length - 1;
        };

        const closeDialog = () => {
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            }
            dialog.hidden = true;
        };

        trigger.addEventListener('click', () => {
            closeAccountMenus?.();
            currentStep = 0;
            renderStep();
            showDialog(dialog);
        });

        nextButton.addEventListener('click', () => {
            if (currentStep < steps.length - 1) {
                currentStep += 1;
                renderStep();
            }
        });

        dialog.querySelectorAll('[data-tutorial-close]').forEach((button) => {
            button.addEventListener('click', closeDialog);
        });

        dialog.addEventListener('close', () => {
            dialog.hidden = true;
        });
    });
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
