(() => {
    let idCounter = 0;

    function createId(prefix) {
        let id;
        do {
            id = `${prefix}-${++idCounter}`;
        } while (document.getElementById(id));
        return id;
    }

    function enhanceSelect(select) {
        if (
            select.dataset.searchableEnhanced === 'true'
            || select.dataset.searchable === 'true'
            || select.closest('.dealer-combobox')
        ) {
            return;
        }

        const labels = Array.from(select.labels || []);
        const label = labels.map(item => item.textContent).join(' ').replace(/\s+/g, ' ').trim()
            || select.getAttribute('aria-label')
            || select.name.replace(/[\[\]_]+/g, ' ').trim()
            || 'dropdown';
        const searchId = createId('searchable-select');
        const listId = `${searchId}-options`;
        const wrapper = document.createElement('div');
        wrapper.className = 'searchable-select';

        const control = document.createElement('div');
        control.className = 'searchable-select__control';

        const search = document.createElement('input');
        search.id = searchId;
        search.type = 'text';
        search.className = 'searchable-select__search';
        search.placeholder = `Select or search ${label.toLocaleLowerCase()}`;
        search.setAttribute('role', 'combobox');
        search.setAttribute('aria-autocomplete', 'list');
        search.setAttribute('aria-haspopup', 'listbox');
        search.setAttribute('aria-controls', listId);
        search.setAttribute('aria-expanded', 'false');
        search.setAttribute('aria-required', String(select.required));
        if (select.hasAttribute('aria-describedby')) {
            search.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
        }
        search.autocomplete = 'off';

        const labelledBy = labels.map(item => {
            if (!item.id) {
                item.id = createId('searchable-select-label');
            }
            item.htmlFor = search.id;
            return item.id;
        });
        if (labelledBy.length > 0) {
            search.setAttribute('aria-labelledby', labelledBy.join(' '));
        } else {
            search.setAttribute('aria-label', `Search ${label} options`);
        }

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'searchable-select__toggle';
        toggle.setAttribute('aria-label', `Show ${label.toLocaleLowerCase()} options`);
        toggle.setAttribute('aria-expanded', 'false');
        toggle.innerHTML = '<span aria-hidden="true">⌄</span>';

        const listbox = document.createElement('div');
        listbox.id = listId;
        listbox.className = 'searchable-select__options';
        listbox.setAttribute('role', 'listbox');
        if (labelledBy.length > 0) {
            listbox.setAttribute('aria-labelledby', labelledBy.join(' '));
        } else {
            listbox.setAttribute('aria-label', label);
        }
        if (select.multiple) {
            listbox.setAttribute('aria-multiselectable', 'true');
        }
        listbox.hidden = true;

        const required = select.required;
        search.required = false;
        search.disabled = select.disabled;
        toggle.disabled = select.disabled;
        select.dataset.searchableRequired = String(required);
        select.required = false;
        select.dataset.searchableEnhanced = 'true';
        select.hidden = true;
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        const parent = select.parentNode;
        parent.insertBefore(wrapper, select);
        control.append(search, toggle);
        wrapper.append(control, listbox, select);

        let filteredOptions = [];
        let activeIndex = -1;
        let isRequired = required;

        function selectedDisplayValue() {
            return Array.from(select.selectedOptions, option => option.textContent.trim()).join(', ');
        }

        function hasSelection() {
            return select.multiple
                ? Array.from(select.selectedOptions).some(option => option.value !== '')
                : select.value !== '';
        }

        function updateValidity() {
            search.setCustomValidity(
                isRequired && !hasSelection() ? `Select ${label.toLocaleLowerCase()} from the list.` : ''
            );
        }

        function renderOptions(query = '') {
            const normalizedQuery = query.trim().toLocaleLowerCase();
            filteredOptions = Array.from(select.options).filter(option =>
                option.textContent.trim().toLocaleLowerCase().includes(normalizedQuery)
            );
            activeIndex = -1;
            search.removeAttribute('aria-activedescendant');
            listbox.replaceChildren();

            if (filteredOptions.length === 0) {
                const emptyOption = document.createElement('div');
                emptyOption.className = 'searchable-select__option searchable-select__option--empty';
                emptyOption.setAttribute('role', 'option');
                emptyOption.setAttribute('aria-disabled', 'true');
                emptyOption.textContent = `No ${label.toLocaleLowerCase()} found`;
                listbox.append(emptyOption);
                return;
            }

            filteredOptions.forEach((option, index) => {
                const item = document.createElement('div');
                item.id = `${listId}-option-${index}`;
                item.className = 'searchable-select__option';
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                const optgroup = option.closest('optgroup');
                const disabled = option.disabled || Boolean(optgroup?.disabled);
                if (disabled) {
                    item.setAttribute('aria-disabled', 'true');
                }

                const text = document.createElement('span');
                text.textContent = option.textContent.trim();
                item.append(text);
                if (select.multiple) {
                    const check = document.createElement('span');
                    check.className = 'searchable-select__check';
                    check.setAttribute('aria-hidden', 'true');
                    check.textContent = option.selected ? '✓' : '';
                    item.append(check);
                }

                item.addEventListener('pointerdown', event => event.preventDefault());
                item.addEventListener('click', () => {
                    if (disabled) {
                        return;
                    }
                    if (select.multiple) {
                        option.selected = !option.selected;
                    } else {
                        select.value = option.value;
                        search.value = option.textContent.trim();
                    }
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    if (select.multiple) {
                        renderOptions(search.value);
                    } else {
                        closeOptions();
                    }
                });
                listbox.append(item);
            });
        }

        function openOptions(clearSearch = true) {
            if (search.disabled) {
                return;
            }
            if (clearSearch) {
                search.value = '';
            }
            listbox.hidden = false;
            search.setAttribute('aria-expanded', 'true');
            toggle.setAttribute('aria-expanded', 'true');
            renderOptions(search.value);
        }

        function closeOptions() {
            listbox.hidden = true;
            search.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-expanded', 'false');
            search.removeAttribute('aria-activedescendant');
            search.value = selectedDisplayValue();
            activeIndex = -1;
        }

        function activateOption(index) {
            if (filteredOptions.length === 0) {
                return;
            }
            activeIndex = Math.max(0, Math.min(index, filteredOptions.length - 1));
            const activeItem = listbox.children[activeIndex];
            Array.from(listbox.children).forEach(item => item.classList.remove('is-active'));
            activeItem.classList.add('is-active');
            search.setAttribute('aria-activedescendant', activeItem.id);
            activeItem.scrollIntoView({ block: 'nearest' });
        }

        function syncFromSelect() {
            updateValidity();
            if (listbox.hidden) {
                search.value = selectedDisplayValue();
            }
            renderOptions(listbox.hidden ? '' : search.value);
        }

        search.addEventListener('focus', () => {
            if (listbox.hidden) {
                openOptions();
            }
        });
        search.addEventListener('input', () => {
            if (listbox.hidden) {
                openOptions(false);
            }
            if (!select.multiple && select.value !== '' && search.value.trim() !== selectedDisplayValue()) {
                select.value = '';
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            renderOptions(search.value);
            updateValidity();
        });
        search.addEventListener('invalid', () => openOptions());
        search.addEventListener('keydown', event => {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                if (listbox.hidden) {
                    openOptions();
                }
                activateOption(activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                if (listbox.hidden) {
                    openOptions();
                    activateOption(filteredOptions.length - 1);
                } else {
                    activateOption(activeIndex < 0 ? filteredOptions.length - 1 : activeIndex - 1);
                }
            } else if (event.key === 'Enter' && !listbox.hidden) {
                event.preventDefault();
                if (activeIndex >= 0) {
                    listbox.children[activeIndex].click();
                } else if (filteredOptions.length === 1) {
                    listbox.children[0].click();
                }
            } else if (event.key === 'Escape' && !listbox.hidden) {
                event.preventDefault();
                closeOptions();
            } else if (event.key === 'Tab') {
                closeOptions();
            }
        });
        toggle.addEventListener('pointerdown', event => event.preventDefault());
        toggle.addEventListener('click', () => {
            if (listbox.hidden) {
                openOptions();
                search.focus();
            } else {
                closeOptions();
            }
        });
        select.addEventListener('change', syncFromSelect);
        select.form?.addEventListener('reset', () => {
            window.setTimeout(() => {
                closeOptions();
                updateValidity();
            }, 0);
        });
        document.addEventListener('pointerdown', event => {
            if (!wrapper.contains(event.target) && !listbox.hidden) {
                closeOptions();
            }
        });

        const stateObserver = new MutationObserver(mutations => {
            let shouldRenderOptions = false;
            for (const mutation of mutations) {
                if (mutation.type === 'attributes' && mutation.target === select) {
                    if (mutation.attributeName === 'data-searchable-required' || mutation.attributeName === 'required') {
                        isRequired = select.dataset.searchableRequired === 'true';
                        select.required = false;
                        search.setAttribute('aria-required', String(isRequired));
                        updateValidity();
                    } else if (mutation.attributeName === 'disabled') {
                        search.disabled = select.disabled;
                        toggle.disabled = select.disabled;
                    }
                }
                shouldRenderOptions = true;
            }
            if (shouldRenderOptions) {
                syncFromSelect();
            }
        });
        stateObserver.observe(select, {
            attributes: true,
            attributeFilter: ['data-searchable-required', 'required', 'disabled', 'label', 'value', 'selected'],
            childList: true,
            characterData: true,
            subtree: true
        });

        search.value = selectedDisplayValue();
        updateValidity();
        renderOptions();
    }

    function enhanceSelects(root) {
        if (root instanceof HTMLSelectElement) {
            enhanceSelect(root);
        }
        root.querySelectorAll?.('select').forEach(enhanceSelect);
    }

    enhanceSelects(document);
    const observer = new MutationObserver(mutations => {
        for (const mutation of mutations) {
            mutation.addedNodes.forEach(node => {
                if (node instanceof HTMLElement) {
                    enhanceSelects(node);
                }
            });
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
})();
