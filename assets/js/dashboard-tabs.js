(() => {
    const tabList = document.querySelector('[data-dashboard-tabs]');
    if (!tabList) {
        return;
    }

    const tabs = Array.from(tabList.querySelectorAll('[role="tab"]'));

    function activateTab(tab, moveFocus = false) {
        tabs.forEach(candidate => {
            const selected = candidate === tab;
            candidate.setAttribute('aria-selected', String(selected));
            candidate.tabIndex = selected ? 0 : -1;
            const panel = document.getElementById(candidate.getAttribute('aria-controls'));
            if (panel) {
                panel.hidden = !selected;
            }
        });
        if (moveFocus) {
            tab.focus();
        }
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => activateTab(tab));
    });

    tabList.addEventListener('keydown', event => {
        const currentIndex = tabs.indexOf(document.activeElement);
        if (currentIndex < 0) {
            return;
        }

        let nextIndex = currentIndex;
        if (event.key === 'ArrowRight') {
            nextIndex = (currentIndex + 1) % tabs.length;
        } else if (event.key === 'ArrowLeft') {
            nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
        } else if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = tabs.length - 1;
        } else {
            return;
        }

        event.preventDefault();
        activateTab(tabs[nextIndex], true);
    });
})();
