(() => {
    const dialog = document.getElementById('complaint-details-dialog');
    const content = document.getElementById('complaint-details-dialog-content');
    const closeButton = dialog?.querySelector('[data-close-complaint-details]');
    const searchInput = document.getElementById('complaint-search');
    const searchForm = searchInput?.form;
    const scrollParameter = '_dashboard_scroll';

    if (searchForm) {
        searchForm.addEventListener('submit', () => {
            let scrollField = searchForm.querySelector(`input[name="${scrollParameter}"]`);
            if (!scrollField) {
                scrollField = document.createElement('input');
                scrollField.type = 'hidden';
                scrollField.name = scrollParameter;
                searchForm.append(scrollField);
            }
            scrollField.value = String(Math.round(window.scrollY));
        });
    }

    const currentUrl = new URL(window.location.href);
    if (currentUrl.searchParams.has(scrollParameter)) {
        const scrollPosition = Number(currentUrl.searchParams.get(scrollParameter));
        currentUrl.searchParams.delete(scrollParameter);
        history.replaceState(
            history.state,
            '',
            `${currentUrl.pathname}${currentUrl.search}${currentUrl.hash}`
        );
        if (Number.isSafeInteger(scrollPosition) && scrollPosition >= 0) {
            requestAnimationFrame(() => window.scrollTo(0, scrollPosition));
        }
    }

    const searchTerms = (searchInput?.value.trim().split(/\s+/u) || []).filter(Boolean).slice(0, 8);

    function highlightSearchMatches(root) {
        if (searchTerms.length === 0) {
            return;
        }

        const pattern = searchTerms
            .sort((left, right) => right.length - left.length)
            .map(term => term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
            .join('|');
        const expression = new RegExp(`(${pattern})`, 'giu');
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
        const textNodes = [];

        while (walker.nextNode()) {
            textNodes.push(walker.currentNode);
        }

        for (const node of textNodes) {
            const text = node.nodeValue || '';
            const matches = text.matchAll(expression);
            const fragment = document.createDocumentFragment();
            let lastIndex = 0;
            let hasMatches = false;

            for (const match of matches) {
                const matchIndex = match.index ?? 0;
                fragment.append(document.createTextNode(text.slice(lastIndex, matchIndex)));
                const highlight = document.createElement('mark');
                highlight.className = 'search-match';
                highlight.textContent = match[0];
                fragment.append(highlight);
                lastIndex = matchIndex + match[0].length;
                hasMatches = true;
            }

            if (hasMatches) {
                fragment.append(document.createTextNode(text.slice(lastIndex)));
                node.replaceWith(fragment);
            }
        }
    }

    const complaintList = document.querySelector('.complaint-list-panel');
    if (complaintList) {
        highlightSearchMatches(complaintList);
    }

    if (!(dialog instanceof HTMLDialogElement) || !content || !closeButton) {
        return;
    }

    let activeTrigger = null;

    document.addEventListener('click', event => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const trigger = event.target.closest('[data-complaint-details-trigger]');
        if (!trigger) {
            return;
        }

        const templateId = trigger.dataset.complaintDetailsTrigger;
        const template = templateId ? document.getElementById(templateId) : null;
        if (!(template instanceof HTMLTemplateElement)) {
            console.error('Complaint details template was not found.');
            return;
        }

        activeTrigger = trigger;
        activeTrigger.setAttribute('aria-expanded', 'true');
        content.replaceChildren(template.content.cloneNode(true));
        highlightSearchMatches(content);
        dialog.showModal();
        closeButton.focus();
    });

    closeButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', event => {
        event.preventDefault();
        dialog.close();
    });
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            dialog.close();
        }
    });
    dialog.addEventListener('click', event => {
        if (event.target === dialog) {
            dialog.close();
        }
    });
    dialog.addEventListener('close', () => {
        content.replaceChildren();
        if (activeTrigger?.isConnected) {
            activeTrigger.setAttribute('aria-expanded', 'false');
            activeTrigger.focus();
        }
        activeTrigger = null;
    });
})();
