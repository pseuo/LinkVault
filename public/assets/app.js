(() => {
    const initialize = () => {
        const requestedScroll = Number(new URL(window.location.href).searchParams.get('scroll'));
        const restoreRequestedScroll = () => {
            if (!Number.isFinite(requestedScroll) || requestedScroll <= 0) return;
            requestAnimationFrame(() => window.scrollTo({top: requestedScroll, behavior: 'auto'}));
        };
        const brandColor = document.documentElement.dataset.brandColor;
        if (/^#[0-9A-Fa-f]{6}$/.test(brandColor || '')) {
            const channels = [1, 3, 5].map((offset) => Number.parseInt(brandColor.slice(offset, offset + 2), 16) / 255);
            const linear = channels.map((channel) => channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4);
            const luminance = .2126 * linear[0] + .7152 * linear[1] + .0722 * linear[2];
            const contrastWithWhite = 1.05 / (luminance + .05);
            const contrastWithInk = (luminance + .05) / .05;

            document.documentElement.style.setProperty('--brand-color', brandColor);
            document.documentElement.style.setProperty('--brand-text', contrastWithWhite >= 4.5 ? brandColor : '#24463d');
            document.documentElement.style.setProperty('--brand-on-accent', contrastWithWhite >= contrastWithInk ? '#ffffff' : '#10231c');
        }
        const siteHeader = document.querySelector('.site-header');
        const primaryNav = document.querySelector('.primary-nav');
        const positionPrimaryNav = () => {
            if (siteHeader instanceof HTMLElement) {
                document.documentElement.style.setProperty('--sticky-header-height', `${Math.ceil(siteHeader.getBoundingClientRect().height)}px`);
            }
            const activeNavItem = primaryNav?.querySelector('[aria-current="page"]');
            if (!(primaryNav instanceof HTMLElement) || !(activeNavItem instanceof HTMLElement)) return;
            requestAnimationFrame(() => {
                const navRect = primaryNav.getBoundingClientRect();
                const itemRect = activeNavItem.getBoundingClientRect();
                const targetLeft = primaryNav.scrollLeft + itemRect.left - navRect.left
                    - (primaryNav.clientWidth - itemRect.width) / 2;
                primaryNav.scrollTo({left: Math.max(0, targetLeft), behavior: 'auto'});
            });
        };
        positionPrimaryNav();
        if (siteHeader instanceof HTMLElement && 'ResizeObserver' in window) {
            new ResizeObserver(positionPrimaryNav).observe(siteHeader);
        }
        document.querySelectorAll('table').forEach((table) => {
            const headers = [...table.querySelectorAll('thead th')].map((header) => header.textContent?.trim() || '');
            if (!headers.length) return;
            table.querySelectorAll('tbody tr').forEach((row) => {
                [...row.querySelectorAll(':scope > td')].forEach((cell, index) => {
                    if (!cell.dataset.label && headers[index]) cell.dataset.label = headers[index];
                });
            });
        });
        document.querySelectorAll('form').forEach((form) => {
            const oneTimeToggle = form.querySelector('input[name="is_one_time"]');
            const oneTimeMode = form.querySelector('select[name="one_time_mode"]');
            const oneTimeModeField = oneTimeMode?.closest('label');
            if (!(oneTimeToggle instanceof HTMLInputElement)
                || !(oneTimeMode instanceof HTMLSelectElement)
                || !(oneTimeModeField instanceof HTMLElement)) return;
            const updateOneTimeMode = () => {
                oneTimeMode.disabled = !oneTimeToggle.checked;
                oneTimeModeField.hidden = !oneTimeToggle.checked;
            };
            form.addEventListener('change', updateOneTimeMode);
            updateOneTimeMode();
        });
        const capabilityButtons = [...document.querySelectorAll('button[data-capability-mode]')];
        const advancedCapabilities = [...document.querySelectorAll(
            '[data-advanced-capability], .preset-toolbar, .preset-list, .advanced-create, .list-panel > .saved-filter-bar, .data-tools'
        )];
        let capabilityMode = 'basic';
        try {
            capabilityMode = localStorage.getItem('linkvault-capability-mode') === 'advanced' ? 'advanced' : 'basic';
        } catch (error) {
        }
        const updateCapabilityMode = (mode) => {
            capabilityMode = mode === 'advanced' ? 'advanced' : 'basic';
            document.documentElement.dataset.capabilityMode = capabilityMode;
            capabilityButtons.forEach((button) => {
                const active = button.dataset.capabilityMode === capabilityMode;
                button.classList.toggle('selected', active);
                button.setAttribute('aria-pressed', String(active));
            });
            advancedCapabilities.forEach((element) => {
                element.hidden = capabilityMode !== 'advanced' && !element.hasAttribute('aria-current');
            });
            positionPrimaryNav();
            try {
                localStorage.setItem('linkvault-capability-mode', capabilityMode);
            } catch (error) {
            }
        };
        capabilityButtons.forEach((button) => button.addEventListener('click', () => updateCapabilityMode(button.dataset.capabilityMode)));
        updateCapabilityMode(capabilityMode);
        const commandDialog = document.querySelector('[data-command-dialog]');
        const commandQuery = document.querySelector('[data-command-query]');
        const commandItems = [...document.querySelectorAll('[data-command-item]')];
        const filterCommands = () => {
            const query = commandQuery instanceof HTMLInputElement ? commandQuery.value.trim().toLowerCase() : '';
            commandItems.forEach((item) => {
                const terms = `${item.textContent || ''} ${item.dataset.commandTerms || ''}`.toLowerCase();
                item.hidden = query !== '' && !terms.includes(query);
            });
        };
        const openCommands = () => {
            if (!(commandDialog instanceof HTMLDialogElement)) return;
            commandDialog.showModal();
            if (commandQuery instanceof HTMLInputElement) {
                commandQuery.value = '';
                filterCommands();
                commandQuery.focus();
            }
        };
        const closeCommands = () => {
            if (commandDialog instanceof HTMLDialogElement) commandDialog.close();
        };
        document.querySelector('[data-command-open]')?.addEventListener('click', openCommands);
        document.querySelector('[data-command-close]')?.addEventListener('click', closeCommands);
        commandQuery?.addEventListener('input', filterCommands);
        commandDialog?.addEventListener('click', (event) => {
            if (event.target === commandDialog) closeCommands();
        });
        document.addEventListener('keydown', (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                commandDialog instanceof HTMLDialogElement && commandDialog.open ? closeCommands() : openCommands();
            }
            const eventTarget = event.target;
            const isEditing = eventTarget instanceof HTMLInputElement
                || eventTarget instanceof HTMLTextAreaElement
                || eventTarget instanceof HTMLSelectElement
                || (eventTarget instanceof HTMLElement && eventTarget.isContentEditable);
            if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey && !isEditing
                && !(commandDialog instanceof HTMLDialogElement && commandDialog.open)) {
                const linkSearch = document.querySelector('.filter-form input[type="search"][name="q"]');
                if (linkSearch instanceof HTMLInputElement) {
                    event.preventDefault();
                    linkSearch.focus();
                    linkSearch.select();
                }
            }
            if (event.key === 'Escape' && commandDialog instanceof HTMLDialogElement && commandDialog.open) closeCommands();
            if (event.key === 'Enter' && commandDialog instanceof HTMLDialogElement && commandDialog.open) {
                const firstVisible = commandItems.find((item) => !item.hidden);
                if (firstVisible instanceof HTMLAnchorElement) firstVisible.click();
            }
        });
        document.querySelectorAll('button[name="repair_action"][value="fallback"]').forEach((button) => {
            button.textContent = '设为备用';
        });
        document.querySelector('[data-cancel-access]')?.addEventListener('click', () => {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.assign(new URL('.', window.location.href).href);
            }
        });

        const createLinkForm = document.getElementById('create-link-form');
        const presetSelect = document.querySelector('[data-link-preset]');
        const applyPreset = document.querySelector('[data-apply-preset]');
        const presetError = document.querySelector('[data-preset-error]');
        const selectedPresetValues = () => {
            if (!(presetSelect instanceof HTMLSelectElement)) return null;
            const raw = presetSelect.selectedOptions[0]?.dataset.values;
            if (!raw) return null;
            try {
                const values = JSON.parse(raw);
                return values && typeof values === 'object' ? values : null;
            } catch (error) {
                return null;
            }
        };
        presetSelect?.addEventListener('change', () => {
            if (applyPreset instanceof HTMLButtonElement) applyPreset.disabled = !selectedPresetValues();
            if (presetError instanceof HTMLElement) presetError.hidden = true;
        });
        applyPreset?.addEventListener('click', () => {
            if (!(createLinkForm instanceof HTMLFormElement)) return;
            const values = selectedPresetValues();
            if (!values) return;
            const domainControl = createLinkForm.elements.namedItem('short_domain_id');
            const presetDomainId = Number(values.short_domain_id) || 0;
            if (presetDomainId > 0 && (!(domainControl instanceof HTMLSelectElement)
                || !Array.from(domainControl.options).some((option) => Number(option.value) === presetDomainId))) {
                if (presetError instanceof HTMLElement) {
                    presetError.textContent = '该预设引用的短链域名已停用或退役，请更新预设后再应用。';
                    presetError.hidden = false;
                }
                return;
            }
            if (presetError instanceof HTMLElement) presetError.hidden = true;
            Object.entries(values).forEach(([name, value]) => {
                if (name === 'expires_days') return;
                const control = createLinkForm.elements.namedItem(name);
                if (control instanceof HTMLInputElement && control.type === 'checkbox') {
                    control.checked = value === true || value === '1';
                } else if (control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement) {
                    control.value = name === 'short_domain_id' && Number(value) === 0 ? '' : String(value ?? '');
                }
            });
            const expirationInput = createLinkForm.querySelector('[data-expiration-input]');
            if (expirationInput instanceof HTMLInputElement) {
                const days = Math.max(0, Number(values.expires_days) || 0);
                if (days === 0) {
                    expirationInput.value = '';
                } else {
                    const date = new Date(Date.now() + days * 86400000);
                    expirationInput.value = [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-')
                        + 'T' + [String(date.getHours()).padStart(2, '0'), String(date.getMinutes()).padStart(2, '0')].join(':');
                }
            }
            const advanced = createLinkForm.querySelector('.advanced-create');
            if (advanced instanceof HTMLDetailsElement) advanced.open = true;
            createLinkForm.dispatchEvent(new Event('change', {bubbles: true}));
        });
        document.querySelectorAll('[data-save-preset]').forEach((form) => {
            form.addEventListener('submit', () => {
                if (!(createLinkForm instanceof HTMLFormElement)) return;
                form.querySelectorAll('[data-preset-value]').forEach((target) => {
                    if (!(target instanceof HTMLInputElement)) return;
                    const source = createLinkForm.elements.namedItem(target.dataset.presetValue || '');
                    if (source instanceof HTMLInputElement && source.type === 'checkbox') {
                        target.value = source.checked ? '1' : '';
                    } else if (source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement || source instanceof HTMLSelectElement) {
                        target.value = source.value;
                    }
                });
            });
        });
        document.querySelector('[data-save-preset-trigger]')?.addEventListener('click', () => {
            const form = document.querySelector('[data-save-preset]');
            const name = document.querySelector('[data-preset-name]');
            const days = document.querySelector('[data-preset-days]');
            if (!(form instanceof HTMLFormElement) || !(name instanceof HTMLInputElement)
                || !(days instanceof HTMLInputElement) || !name.reportValidity() || !days.reportValidity()) return;
            const submitName = form.querySelector('[data-preset-submit-name]');
            const submitDays = form.querySelector('[data-preset-submit-days]');
            if (submitName instanceof HTMLInputElement) submitName.value = name.value;
            if (submitDays instanceof HTMLInputElement) submitDays.value = days.value;
            form.requestSubmit();
        });
        const themeToggle = document.querySelector('[data-theme-toggle]');
        const themeColor = document.querySelector('[data-theme-color]');
        const updateThemeToggle = (theme) => {
            const isDark = theme === 'dark';
            const label = isDark ? '切换浅色模式' : '切换深色模式';
            themeColor?.setAttribute('content', isDark ? '#191d1b' : '#ffffff');
            if (!themeToggle) return;
            themeToggle.setAttribute('aria-pressed', String(isDark));
            themeToggle.setAttribute('aria-label', label);
            themeToggle.title = label;
        };

        updateThemeToggle(document.documentElement.dataset.theme || 'light');
        themeToggle?.addEventListener('click', () => {
            const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = theme;
            updateThemeToggle(theme);
            try {
                localStorage.setItem('linkvault-theme', theme);
            } catch (error) {
            }
        });

        const openHashRunbook = () => {
            if (!window.location.hash) return;
            let target = null;
            try {
                target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
            } catch (error) {
                return;
            }
            const details = target instanceof HTMLDetailsElement ? target : target?.closest('details');
            if (details instanceof HTMLDetailsElement) details.open = true;
        };
        openHashRunbook();
        window.addEventListener('hashchange', openHashRunbook);

        const localTimeFormatter = new Intl.DateTimeFormat(undefined, {
            year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false, timeZoneName: 'short'
        });
        const localTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '浏览器本地时间';
        const padDatePart = (value) => String(value).padStart(2, '0');
        const formatLocalTime = (element) => {
            const date = new Date(element.dateTime);
            if (!Number.isNaN(date.getTime())) element.textContent = localTimeFormatter.format(date);
        };
        document.querySelectorAll('[data-timezone-label]').forEach((label) => { label.textContent = localTimezone; });
        document.querySelectorAll('[data-analytics-filter]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) return;
            const timezoneInput = form.querySelector('[data-analytics-timezone]');
            const rangeInput = form.querySelector('[data-analytics-range]');
            const customFields = [...form.querySelectorAll('[data-custom-range]')];
            const url = new URL(window.location.href);
            if (!url.searchParams.has('timezone') && localTimezone !== '浏览器本地时间') {
                url.searchParams.set('timezone', localTimezone);
                window.location.replace(url.href);
                return;
            }
            if (timezoneInput instanceof HTMLInputElement) {
                timezoneInput.value = form.dataset.timezoneCurrent || localTimezone;
            }
            const updateCustomRange = () => {
                const custom = rangeInput instanceof HTMLSelectElement && rangeInput.value === 'custom';
                customFields.forEach((field) => {
                    field.hidden = !custom;
                    field.querySelectorAll('input').forEach((input) => { input.disabled = !custom; });
                });
            };
            rangeInput?.addEventListener('change', updateCustomRange);
            updateCustomRange();
        });
        document.querySelectorAll('[data-local-time]').forEach(formatLocalTime);
        document.querySelectorAll('[data-analytics-export-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) return;
            const status = document.querySelector('[data-analytics-export-status]');
            let pollTimer = 0;
            const setStatus = (text, failed = false) => {
                if (!(status instanceof HTMLElement)) return;
                status.hidden = false;
                status.classList.toggle('warning', failed);
                status.replaceChildren(document.createTextNode(text));
            };
            const poll = async (url) => {
                try {
                    const response = await fetch(url, {
                        headers: {'X-Requested-With': 'XMLHttpRequest'},
                        credentials: 'same-origin',
                    });
                    const payload = await response.json();
                    if (payload.redirect) {
                        window.location.assign(payload.redirect);
                        return;
                    }
                    if (!response.ok) throw new Error(payload.error || '无法查询导出状态');
                    if (payload.status === 'completed' && payload.download_url) {
                        if (!(status instanceof HTMLElement)) return;
                        const link = document.createElement('a');
                        link.className = 'button button-secondary button-small';
                        link.href = payload.download_url;
                        link.textContent = `下载 CSV（${Number(payload.rows || 0).toLocaleString()} 行）`;
                        status.replaceChildren(document.createTextNode('导出已完成。'), link);
                        return;
                    }
                    if (['failed', 'expired'].includes(payload.status)) {
                        setStatus(payload.status === 'expired' ? '导出文件已过期，请重新生成。' : (payload.error || '导出失败。'), true);
                        return;
                    }
                    setStatus(payload.status === 'running' ? '正在生成导出文件…' : '导出任务已排队…');
                    pollTimer = window.setTimeout(() => poll(url), 1500);
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : '导出状态查询失败。', true);
                }
            };
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                window.clearTimeout(pollTimer);
                const submitter = event.submitter;
                const data = new FormData(form);
                if (submitter instanceof HTMLButtonElement) data.set('report', submitter.value);
                form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
                setStatus('正在创建导出任务…');
                try {
                    const response = await fetch(form.action, {
                        method: 'POST', body: data, credentials: 'same-origin',
                        headers: {'X-Requested-With': 'XMLHttpRequest'},
                    });
                    const payload = await response.json();
                    if (payload.redirect) {
                        window.location.assign(payload.redirect);
                        return;
                    }
                    if (!response.ok || !payload.status_url) throw new Error(payload.error || '无法创建导出任务');
                    setStatus('导出任务已排队…');
                    pollTimer = window.setTimeout(() => poll(payload.status_url), 500);
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : '无法创建导出任务。', true);
                } finally {
                    form.querySelectorAll('button').forEach((button) => { button.disabled = false; });
                }
            });
        });
        const overviewPlaceholder = document.querySelector('[data-overview-url]');
        if (overviewPlaceholder instanceof HTMLElement) {
            const retryOverview = (event) => {
                event.preventDefault();
                const status = event.currentTarget;
                if (status instanceof HTMLElement) {
                    status.removeEventListener('click', retryOverview);
                    status.removeEventListener('keydown', retryOverviewByKeyboard);
                    status.removeAttribute('role');
                    status.removeAttribute('tabindex');
                    status.textContent = '正在重试…';
                }
                loadOverview();
            };
            const retryOverviewByKeyboard = (event) => {
                if (event.key === 'Enter' || event.key === ' ') retryOverview(event);
            };
            const loadOverview = async () => {
                try {
                    const response = await fetch(overviewPlaceholder.dataset.overviewUrl || '', {
                        headers: {'X-Requested-With': 'XMLHttpRequest'},
                        credentials: 'same-origin',
                    });
                    if (!response.ok || response.redirected) throw new Error('overview request failed');
                    const template = document.createElement('template');
                    template.innerHTML = await response.text();
                    const content = template.content.querySelector('[data-overview-content]');
                    if (!(content instanceof HTMLElement)) throw new Error('invalid overview response');
                    const total = document.querySelector('[data-overview-total]');
                    if (total instanceof HTMLElement) total.textContent = content.dataset.recentClicksTotal || '0';
                    overviewPlaceholder.replaceWith(content);
                    content.querySelectorAll('[data-local-time]').forEach(formatLocalTime);
                    restoreRequestedScroll();
                } catch (error) {
                    const status = overviewPlaceholder.querySelector('.stats-heading .muted');
                    if (status instanceof HTMLElement) {
                        status.textContent = '加载失败，点击重试';
                        status.tabIndex = 0;
                        status.setAttribute('role', 'button');
                        status.addEventListener('click', retryOverview);
                        status.addEventListener('keydown', retryOverviewByKeyboard);
                    }
                }
            };
            loadOverview();
        }
        const invalidControls = [...document.querySelectorAll('[aria-invalid="true"]')];
        invalidControls.forEach((control, index) => {
            const field = control.closest('label, fieldset');
            const error = field?.querySelector('.field-error');
            if (!error) return;
            if (!error.id) error.id = `field-error-${index + 1}`;
            const describedBy = new Set((control.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean));
            describedBy.add(error.id);
            control.setAttribute('aria-describedby', [...describedBy].join(' '));
        });
        document.querySelectorAll('[data-expiration-input][data-utc-value], [data-start-input][data-utc-value]').forEach((input) => {
            if (!input.dataset.utcValue) return;
            const date = new Date(input.dataset.utcValue);
            if (Number.isNaN(date.getTime())) return;
            input.value = [date.getFullYear(), padDatePart(date.getMonth() + 1), padDatePart(date.getDate())].join('-')
                + 'T' + [padDatePart(date.getHours()), padDatePart(date.getMinutes())].join(':');
        });
        const setHiddenFormValue = (form, name, value) => {
            let input = form.querySelector(`input[type="hidden"][name="${name}"]`);
            if (!(input instanceof HTMLInputElement)) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                form.append(input);
            }
            input.value = value;
        };
        const draftPrefix = 'linkvault-form-draft:';
        const authResumeKey = 'linkvault-auth-resume';
        const draftTtlMs = 30 * 60 * 1000;
        const draftStorage = (() => {
            try {
                const probe = `${draftPrefix}probe`;
                sessionStorage.setItem(probe, '1');
                sessionStorage.removeItem(probe);
                return sessionStorage;
            } catch (error) {
                return null;
            }
        })();
        const hasStoredDraft = () => {
            if (!draftStorage) return false;
            for (let index = 0; index < draftStorage.length; index += 1) {
                if ((draftStorage.key(index) || '').startsWith(draftPrefix)) return true;
            }
            return false;
        };
        const isLoginPage = document.body.classList.contains('login-page');
        if (isLoginPage && hasStoredDraft()) {
            draftStorage?.setItem(authResumeKey, String(Date.now()));
        }
        const resumeAt = Number(draftStorage?.getItem(authResumeKey) || 0);
        const shouldResumeDraft = !isLoginPage && resumeAt > 0 && Date.now() - resumeAt <= draftTtlMs;
        const sensitiveDraftName = /(?:^|_)(?:password|secret|token|code|credential|authorization)(?:$|_)/i;
        const safeDraftUrl = (value) => {
            if (value === '') return true;
            try {
                const url = new URL(value);
                if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return false;
                return ![...url.searchParams.keys()].some((name) => /token|key|signature|sig|auth|password|secret|credential|code/i.test(name));
            } catch (error) {
                return false;
            }
        };
        const safeDraftControl = (control) => {
            if (!(control instanceof HTMLInputElement || control instanceof HTMLTextAreaElement || control instanceof HTMLSelectElement)
                || !control.name || control.disabled || sensitiveDraftName.test(control.name)) return false;
            if (control instanceof HTMLInputElement) {
                if (['password', 'file', 'hidden', 'submit', 'button', 'image', 'reset'].includes(control.type)) return false;
                if (control.type === 'url' && !safeDraftUrl(control.value)) return false;
            }
            return control.value.length <= 4096;
        };
        document.querySelectorAll('form[data-preserve-draft]').forEach((form) => {
            if (!(form instanceof HTMLFormElement) || !draftStorage) return;
            const key = draftPrefix + form.dataset.preserveDraft;
            const saveDraft = () => {
                const fields = {};
                const sensitiveRequired = [];
                form.querySelectorAll('input, textarea, select').forEach((control) => {
                    if (control instanceof HTMLInputElement && control.type === 'password'
                        && control.name && control.value !== '') {
                        sensitiveRequired.push(control.name);
                    }
                    if (!safeDraftControl(control)) return;
                    fields[control.name] = control instanceof HTMLInputElement && ['checkbox', 'radio'].includes(control.type)
                        ? {checked: control.checked, value: control.value}
                        : {value: control.value};
                });
                const encoded = JSON.stringify({savedAt: Date.now(), fields, sensitiveRequired});
                if (encoded.length <= 32768) draftStorage.setItem(key, encoded);
            };
            if (shouldResumeDraft) {
                try {
                    const draft = JSON.parse(draftStorage.getItem(key) || 'null');
                    if (draft && Number(draft.savedAt) >= Date.now() - draftTtlMs && draft.fields && typeof draft.fields === 'object') {
                        form.querySelectorAll('input, textarea, select').forEach((control) => {
                            if (!safeDraftControl(control) || !Object.hasOwn(draft.fields, control.name)) return;
                            const saved = draft.fields[control.name];
                            if (!saved || typeof saved !== 'object') return;
                            if (control instanceof HTMLInputElement && ['checkbox', 'radio'].includes(control.type)) {
                                control.checked = saved.value === control.value && saved.checked === true;
                            } else if (typeof saved.value === 'string') {
                                if (!(control instanceof HTMLInputElement) || control.type !== 'url' || safeDraftUrl(saved.value)) {
                                    control.value = saved.value;
                                }
                            }
                        });
                        if (Array.isArray(draft.sensitiveRequired)) {
                            form.querySelectorAll('input[type="password"][name]').forEach((passwordInput) => {
                                if (!draft.sensitiveRequired.includes(passwordInput.name)) return;
                                passwordInput.required = true;
                                passwordInput.setAttribute('aria-invalid', 'true');
                                const field = passwordInput.closest('label');
                                if (field && !field.querySelector('[data-draft-password-error]')) {
                                    const error = document.createElement('span');
                                    error.className = 'field-error';
                                    error.dataset.draftPasswordError = 'true';
                                    error.id = `draft-password-error-${form.dataset.preserveDraft}-${passwordInput.name}`;
                                    error.textContent = '草稿已恢复，请重新输入此密码。';
                                    field.append(error);
                                    passwordInput.setAttribute('aria-describedby', error.id);
                                }
                                let details = passwordInput.closest('details');
                                while (details instanceof HTMLDetailsElement) {
                                    details.open = true;
                                    details = details.parentElement?.closest('details') || null;
                                }
                                if (passwordInput.name === 'access_password' && form.dataset.preserveDraft === 'create-link') {
                                    setHiddenFormValue(form, 'access_password_required', '1');
                                }
                            });
                        }
                        form.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                } catch (error) {
                }
                draftStorage.removeItem(key);
            } else {
                draftStorage.removeItem(key);
            }
            let draftTimer;
            form.addEventListener('input', () => {
                clearTimeout(draftTimer);
                draftTimer = setTimeout(saveDraft, 150);
            });
            form.addEventListener('change', saveDraft);
            form.addEventListener('submit', saveDraft);
        });
        if (shouldResumeDraft) draftStorage?.removeItem(authResumeKey);
        const purgeDialog = document.querySelector('[data-purge-dialog]');
        const purgeCount = purgeDialog?.querySelector('[data-purge-count]');
        const purgeRecords = purgeDialog?.querySelector('[data-purge-records]');
        const purgeCancel = purgeDialog?.querySelector('[data-purge-cancel]');
        const purgeConfirm = purgeDialog?.querySelector('[data-purge-confirm]');
        let pendingPurge = null;
        const openPurgeDialog = (form, submitter, records) => {
            if (!(purgeDialog instanceof HTMLDialogElement) || typeof purgeDialog.showModal !== 'function') {
                const summary = records.slice(0, 5).map(({slug, title}) => `短码：${slug}；标题：${title}`).join('\n');
                if (window.confirm(`将永久删除 ${records.length} 条链接且无法恢复：\n${summary}`)) {
                    form.dataset.purgeConfirmed = 'true';
                    form.requestSubmit(submitter instanceof HTMLElement ? submitter : undefined);
                }
                return;
            }
            pendingPurge = {form, submitter};
            if (purgeCount) purgeCount.textContent = `${records.length} 条链接`;
            if (purgeRecords) {
                purgeRecords.replaceChildren();
                records.slice(0, 5).forEach(({slug, title}) => {
                    const item = document.createElement('li');
                    const code = document.createElement('code');
                    const label = document.createElement('span');
                    code.textContent = slug;
                    label.textContent = title;
                    item.append(code, label);
                    purgeRecords.append(item);
                });
                if (records.length > 5) {
                    const remainder = document.createElement('li');
                    remainder.className = 'purge-records-remainder';
                    remainder.textContent = `另有 ${records.length - 5} 条链接`;
                    purgeRecords.append(remainder);
                }
            }
            purgeDialog.showModal();
            requestAnimationFrame(() => purgeCancel?.focus());
        };
        purgeCancel?.addEventListener('click', () => {
            pendingPurge = null;
            purgeDialog.close();
        });
        purgeConfirm?.addEventListener('click', () => {
            const pending = pendingPurge;
            pendingPurge = null;
            purgeDialog.close();
            if (!pending) return;
            pending.form.dataset.purgeConfirmed = 'true';
            pending.form.requestSubmit(pending.submitter instanceof HTMLElement ? pending.submitter : undefined);
        });
        purgeDialog?.addEventListener('close', () => { pendingPurge = null; });
        const bulkPreviewDialog = document.querySelector('[data-bulk-preview-dialog]');
        const bulkPreviewItems = bulkPreviewDialog?.querySelector('[data-bulk-preview-items]');
        const bulkPreviewWarning = bulkPreviewDialog?.querySelector('[data-bulk-preview-warning]');
        const bulkPreviewConfirm = bulkPreviewDialog?.querySelector('[data-bulk-preview-confirm]');
        let pendingBulkPreview = null;
        const submitBulkOperation = (form, submitter, preview) => {
            setHiddenFormValue(form, 'operation_id', preview.operation_id || '');
            const purgeConfirmation = form.querySelector('[data-bulk-purge-confirm]');
            if (purgeConfirmation instanceof HTMLInputElement) {
                purgeConfirmation.value = preview.action === 'purge' ? '1' : '';
            }
            form.dataset.bulkConfirmed = 'true';
            form.requestSubmit(submitter instanceof HTMLElement ? submitter : undefined);
        };
        const openBulkPreview = async (form, submitter) => {
            const previewAction = form.dataset.bulkPreviewAction;
            if (!previewAction) return;
            if (submitter instanceof HTMLButtonElement) {
                submitter.disabled = true;
                submitter.setAttribute('aria-busy', 'true');
            }
            try {
                const response = await fetch(previewAction, {
                    method: 'POST',
                    body: new FormData(form),
                    credentials: 'same-origin',
                    headers: {'X-Requested-With': 'XMLHttpRequest'},
                });
                const preview = await response.json();
                if (typeof preview.redirect === 'string') {
                    const destination = new URL(preview.redirect, window.location.href);
                    if (destination.origin !== window.location.origin) throw new Error('登录地址无效。');
                    window.location.assign(destination.href);
                    return;
                }
                if (!response.ok || preview.error) throw new Error(preview.error || '无法生成影响预览。');
                if (!(bulkPreviewDialog instanceof HTMLDialogElement) || typeof bulkPreviewDialog.showModal !== 'function') {
                    const warning = preview.reversible ? '操作后可在 24 小时内撤销。' : '此操作永久删除数据且不可撤销。';
                    if (window.confirm(`将变更 ${preview.would_change} 条；${warning}`)) {
                        submitBulkOperation(form, submitter, preview);
                    }
                    return;
                }
                pendingBulkPreview = {form, submitter, preview};
                const previewDescription = bulkPreviewDialog.querySelector('[data-bulk-preview-description]');
                if (previewDescription) {
                    const parameters = preview.parameters_label ? `（${preview.parameters_label}）` : '';
                    previewDescription.textContent = `操作：${preview.action_label || preview.action || '批量处理'}${parameters}。确认后才会修改链接。`;
                }
                const setCount = (selector, value) => {
                    const element = bulkPreviewDialog.querySelector(selector);
                    if (element) element.textContent = String(value || 0);
                };
                setCount('[data-bulk-preview-change]', preview.would_change);
                setCount('[data-bulk-preview-unchanged]', preview.unchanged);
                setCount('[data-bulk-preview-ineligible]', preview.ineligible);
                if (bulkPreviewWarning instanceof HTMLElement) {
                    bulkPreviewWarning.hidden = false;
                    bulkPreviewWarning.classList.toggle('danger', !preview.reversible);
                    bulkPreviewWarning.textContent = preview.reversible
                        ? '确认后可在 24 小时内撤销；若链接期间被修改，撤销会被拒绝。'
                        : '永久删除会连同统计及相关记录一起移除，无法撤销。';
                }
                if (bulkPreviewItems) {
                    bulkPreviewItems.replaceChildren();
                    (preview.items || []).slice(0, 12).forEach((item) => {
                        const row = document.createElement('li');
                        row.className = `bulk-preview-${item.state || 'ineligible'}`;
                        const identity = document.createElement('span');
                        const code = document.createElement('code');
                        const title = document.createElement('small');
                        const impact = document.createElement('span');
                        const reason = document.createElement('strong');
                        code.textContent = item.slug || `ID ${item.id}`;
                        title.textContent = item.title || '未命名';
                        impact.className = 'bulk-preview-impact';
                        impact.textContent = item.impact || '';
                        impact.hidden = !item.impact;
                        reason.textContent = item.reason || '';
                        identity.append(code, title, impact);
                        row.append(identity, reason);
                        bulkPreviewItems.append(row);
                    });
                    if ((preview.selected || 0) > 12) {
                        const remainder = document.createElement('li');
                        remainder.className = 'bulk-preview-remainder';
                        remainder.textContent = `另有 ${preview.selected - 12} 条，数量已计入上方汇总`;
                        bulkPreviewItems.append(remainder);
                    }
                }
                if (bulkPreviewConfirm instanceof HTMLButtonElement) {
                    bulkPreviewConfirm.disabled = Number(preview.would_change || 0) === 0;
                    bulkPreviewConfirm.classList.toggle('button-danger', !preview.reversible);
                }
                bulkPreviewDialog.showModal();
                requestAnimationFrame(() => bulkPreviewDialog.querySelector('[data-bulk-preview-cancel]')?.focus());
            } catch (error) {
                showCopyFeedback(error instanceof Error ? error.message : '无法生成影响预览。', true);
            } finally {
                if (submitter instanceof HTMLButtonElement) {
                    submitter.disabled = false;
                    submitter.removeAttribute('aria-busy');
                }
            }
        };
        bulkPreviewDialog?.querySelectorAll('[data-bulk-preview-cancel]').forEach((button) => {
            button.addEventListener('click', () => {
                pendingBulkPreview = null;
                bulkPreviewDialog.close();
            });
        });
        bulkPreviewConfirm?.addEventListener('click', () => {
            const pending = pendingBulkPreview;
            pendingBulkPreview = null;
            bulkPreviewDialog.close();
            if (pending) submitBulkOperation(pending.form, pending.submitter, pending.preview);
        });
        bulkPreviewDialog?.addEventListener('close', () => { pendingBulkPreview = null; });
        const submittingForms = new Set();
        const submissionStates = new WeakMap();
        const restoreSubmittingState = (form) => {
            const state = submissionStates.get(form);
            if (state) {
                clearTimeout(state.timeoutId);
                state.controls.forEach(({control, disabled}) => { control.disabled = disabled; });
                if (state.submitter instanceof HTMLButtonElement) {
                    state.submitter.innerHTML = state.submitterHtml;
                    state.submitter.classList.remove('is-loading');
                    state.submitter.removeAttribute('aria-busy');
                }
                state.submittedValue?.remove();
                submissionStates.delete(form);
            }
            delete form.dataset.submitting;
            submittingForms.delete(form);
        };
        const restoreAllSubmittingStates = () => {
            [...submittingForms].forEach(restoreSubmittingState);
        };
        window.addEventListener('pageshow', restoreAllSubmittingStates);
        if ('navigation' in window && typeof window.navigation?.addEventListener === 'function') {
            window.navigation.addEventListener('navigateerror', restoreAllSubmittingStates);
        }
        document.addEventListener('submit', (event) => {
            if (!(event.target instanceof HTMLFormElement)) return;
            if (event.defaultPrevented) return;
            if (event.target.dataset.submitting === 'true') {
                event.preventDefault();
                return;
            }
            if (event.target.matches('[data-bulk-form]')) {
                const action = event.target.querySelector('[name="bulk_action"]')?.value;
                const selectedCount = rowSelections.filter((input) => input.checked).length;
                if (selectedCount === 0) {
                    event.preventDefault();
                    return;
                }
                if (event.submitter?.matches('[data-bulk-submit]') && event.target.dataset.bulkConfirmed !== 'true') {
                    event.preventDefault();
                    openBulkPreview(event.target, event.submitter);
                    return;
                }
                delete event.target.dataset.bulkConfirmed;
            }
            if (event.target.matches('[data-purge-form]')) {
                if (event.target.dataset.purgeConfirmed !== 'true') {
                    event.preventDefault();
                    openPurgeDialog(event.target, event.submitter, [{
                        slug: event.target.dataset.purgeSlug || `ID ${event.target.querySelector('[name="id"]')?.value || ''}`,
                        title: event.target.dataset.purgeTitle || '未命名',
                    }]);
                    return;
                }
                delete event.target.dataset.purgeConfirmed;
                const confirmationInput = event.target.querySelector('[data-confirmation-token]');
                const confirmationToken = event.target.dataset.confirmToken;
                if (!(confirmationInput instanceof HTMLInputElement) || !confirmationToken) {
                    event.preventDefault();
                    return;
                }
                confirmationInput.value = confirmationToken;
            }
            if (event.target.querySelector('[name="return_q"]')) {
                setHiddenFormValue(event.target, 'return_scroll', String(Math.max(0, Math.round(window.scrollY))));
            }
            let confirmMessage = event.target.dataset.confirm;
            const domainToggle = event.target.action.endsWith('/domains/toggle')
                && event.target.querySelector('[name="enabled"]')?.value === '0';
            if (!confirmMessage && domainToggle) {
                const domainId = event.target.querySelector('[name="id"]')?.value || '';
                const hostname = event.target.closest('article')?.querySelector('.domain-heading strong')?.textContent?.trim()
                    || '该域名';
                const deleteForms = Array.from(document.querySelectorAll('.domain-delete-list form'));
                const countForm = deleteForms.find((form) => form.querySelector('[name="id"]')?.value === domainId);
                const linkCount = countForm?.parentElement?.querySelector('small')?.textContent?.trim() || '0 条链接使用';
                confirmMessage = `确定停用 ${hostname} 吗？${linkCount}，停用后这些短链接将立即无法访问。`;
            }
            if (confirmMessage && !window.confirm(confirmMessage)) {
                event.preventDefault();
                return;
            }
            if (confirmMessage) {
                const confirmationInput = event.target.querySelector('[data-confirmation-token]');
                const confirmationToken = event.target.dataset.confirmToken;
                if (confirmationInput instanceof HTMLInputElement) {
                    if (!confirmationToken) {
                        event.preventDefault();
                        return;
                    }
                    confirmationInput.value = confirmationToken;
                }
            }
            [
                ['[data-expiration-input]', '[data-expiration-offset]'],
                ['[data-start-input]', '[data-start-offset]'],
            ].forEach(([inputSelector, offsetSelector]) => {
                const dateInput = event.target.querySelector(inputSelector);
                const offsetInput = event.target.querySelector(offsetSelector);
                if (dateInput && offsetInput && dateInput.value) {
                    const localDate = new Date(dateInput.value);
                    if (!Number.isNaN(localDate.getTime())) offsetInput.value = String(localDate.getTimezoneOffset());
                }
            });

            if (event.submitter?.matches('[data-selected-export]')) return;

            const submitter = event.submitter;
            const controls = [...event.target.querySelectorAll('button[type="submit"], input[type="submit"]')];
            const submissionState = {
                controls: controls.map((control) => ({control, disabled: control.disabled})),
                submitter,
                submitterHtml: submitter instanceof HTMLButtonElement ? submitter.innerHTML : '',
                submittedValue: null,
                timeoutId: 0,
            };
            submissionStates.set(event.target, submissionState);
            submittingForms.add(event.target);
            if (submitter instanceof HTMLButtonElement) {
                if (submitter.name) {
                    const submittedValue = document.createElement('input');
                    submittedValue.type = 'hidden';
                    submittedValue.name = submitter.name;
                    submittedValue.value = submitter.value;
                    event.target.append(submittedValue);
                    submissionState.submittedValue = submittedValue;
                }
                submitter.setAttribute('aria-busy', 'true');
                submitter.classList.add('is-loading');
                submitter.innerHTML = '<span class="button-spinner" aria-hidden="true"></span>处理中';
            }
            event.target.dataset.submitting = 'true';
            controls.forEach((control) => { control.disabled = true; });
            submissionState.timeoutId = window.setTimeout(() => restoreSubmittingState(event.target), 30000);
        });

        const formatFileSize = (bytes) => {
            if (bytes < 1024) return `${bytes} B`;
            if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(bytes < 10240 ? 1 : 0)} KB`;
            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        };
        document.querySelectorAll('[data-import-form]').forEach((form) => {
            const fileInput = form.querySelector('[data-import-file]');
            const summary = form.querySelector('[data-file-summary]');
            const clearButton = form.querySelector('[data-file-clear]');
            const previewButton = form.querySelector('[data-import-preview]');
            const progressContainer = form.querySelector('[data-import-progress]');
            const progress = progressContainer?.querySelector('progress');
            const progressLabel = form.querySelector('[data-import-progress-label]');
            if (!(fileInput instanceof HTMLInputElement) || !summary) return;
            const updateFileSelection = () => {
                const file = fileInput.files?.[0];
                summary.textContent = file ? `${file.name} · ${formatFileSize(file.size)}` : '未选择文件';
                summary.classList.toggle('has-file', Boolean(file));
                summary.setAttribute('title', file?.name || '');
                if (clearButton instanceof HTMLButtonElement) clearButton.hidden = !file;
                if (previewButton instanceof HTMLButtonElement) previewButton.disabled = !file;
                if (progressContainer instanceof HTMLElement && form.dataset.uploading !== 'true') progressContainer.hidden = true;
            };
            fileInput.addEventListener('change', updateFileSelection);
            clearButton?.addEventListener('click', () => {
                fileInput.value = '';
                updateFileSelection();
                fileInput.focus();
            });
            form.addEventListener('reset', () => setTimeout(updateFileSelection));
            window.addEventListener('pageshow', updateFileSelection);
            form.addEventListener('submit', (event) => {
                const file = fileInput.files?.[0];
                if (!file || !(progressContainer instanceof HTMLElement) || !(progress instanceof HTMLProgressElement) || !progressLabel) return;

                event.preventDefault();
                const payload = new FormData(form);
                const request = new XMLHttpRequest();
                const setProgress = (value, label) => {
                    progressContainer.hidden = false;
                    if (value === null) {
                        progress.removeAttribute('value');
                    } else {
                        progress.value = value;
                    }
                    progressLabel.textContent = label;
                };
                const restoreImportForm = () => {
                    delete form.dataset.uploading;
                    fileInput.disabled = false;
                    if (clearButton instanceof HTMLButtonElement) clearButton.disabled = false;
                    if (previewButton instanceof HTMLButtonElement) previewButton.disabled = !fileInput.files?.[0];
                };
                const showImportError = (message) => {
                    restoreImportForm();
                    progress.value = 0;
                    progressContainer.hidden = false;
                    progressLabel.textContent = message;
                    progressContainer.setAttribute('tabindex', '-1');
                    progressContainer.focus();
                    showCopyFeedback(message, true);
                };

                form.dataset.uploading = 'true';
                fileInput.disabled = true;
                if (clearButton instanceof HTMLButtonElement) clearButton.disabled = true;
                if (previewButton instanceof HTMLButtonElement) previewButton.disabled = true;
                setProgress(0, '正在上传 0%');

                request.upload.addEventListener('progress', (progressEvent) => {
                    if (!progressEvent.lengthComputable) {
                        setProgress(null, '正在上传文件');
                        return;
                    }
                    const percentage = Math.min(100, Math.round(progressEvent.loaded / progressEvent.total * 100));
                    setProgress(percentage, `正在上传 ${percentage}%`);
                });
                request.upload.addEventListener('load', () => setProgress(null, '上传完成，正在分析文件'));
                request.addEventListener('load', () => {
                    if (request.status >= 200 && request.status < 400) {
                        try {
                            const response = JSON.parse(request.responseText);
                            const destination = new URL(response.redirect, window.location.href);
                            if (destination.origin !== window.location.origin) throw new Error('Invalid redirect origin');
                            setProgress(100, '分析完成，正在打开预览');
                            window.location.assign(destination.href);
                            return;
                        } catch (error) {
                            showImportError('服务器响应无效，请重试。');
                            return;
                        }
                    }
                    showImportError('导入失败，请重试。');
                });
                request.addEventListener('error', () => showImportError('网络异常，导入未完成。'));
                request.addEventListener('timeout', () => showImportError('导入超时，请重试。'));
                request.addEventListener('abort', () => showImportError('导入已取消。'));
                request.open((form.method || 'POST').toUpperCase(), form.action, true);
                request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                request.timeout = 60000;
                request.send(payload);
            });
            updateFileSelection();
        });

        const copyFeedback = document.getElementById('copy-feedback');
        let copyFeedbackTimer;
        const showCopyFeedback = (message, isError = false) => {
            if (!copyFeedback) return;
            clearTimeout(copyFeedbackTimer);
            copyFeedback.textContent = message;
            copyFeedback.classList.toggle('error', isError);
            copyFeedback.hidden = false;
            copyFeedbackTimer = setTimeout(() => copyFeedback.hidden = true, isError ? 5000 : 1800);
        };
        document.querySelectorAll('[data-toast]').forEach((toast) => {
            let dismissTimer;
            const dismiss = () => {
                clearTimeout(dismissTimer);
                toast.hidden = true;
            };
            const scheduleDismiss = () => {
                clearTimeout(dismissTimer);
                dismissTimer = setTimeout(dismiss, 4000);
            };
            toast.querySelector('[data-dismiss-toast]')?.addEventListener('click', dismiss);
            toast.addEventListener('mouseenter', () => clearTimeout(dismissTimer));
            toast.addEventListener('mouseleave', scheduleDismiss);
            toast.addEventListener('focusin', () => clearTimeout(dismissTimer));
            toast.addEventListener('focusout', (event) => {
                if (!(event.relatedTarget instanceof Node) || !toast.contains(event.relatedTarget)) scheduleDismiss();
            });
            scheduleDismiss();
        });
        const selectCopyTarget = (button) => {
            const target = document.getElementById(button.dataset.copyTarget || '');
            if (!target) return false;
            if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
                target.focus(); target.select(); target.setSelectionRange(0, target.value.length); return true;
            }
            const selection = window.getSelection();
            if (!selection) return false;
            const range = document.createRange();
            range.selectNodeContents(target); selection.removeAllRanges(); selection.addRange(range); target.scrollIntoView({block: 'nearest'});
            return true;
        };
        const copyText = async (value) => {
            if (!value) return false;
            if (window.isSecureContext && typeof navigator.clipboard?.writeText === 'function') {
                try {
                    await navigator.clipboard.writeText(value);
                    return true;
                } catch (error) {
                    // Continue to the legacy fallback for restricted clipboard permissions.
                }
            }
            const input = document.createElement('textarea');
            input.value = value;
            input.readOnly = true;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.append(input);
            input.select();
            let copied = false;
            try {
                copied = document.execCommand('copy');
            } catch (error) {
                copied = false;
            }
            input.remove();
            return copied;
        };
        const sensitiveValue = (button) => {
            const source = document.getElementById(button.dataset.sensitiveSource || '');
            if (source instanceof HTMLInputElement || source instanceof HTMLTextAreaElement) return source.value;
            return source?.textContent || '';
        };
        const downloadSensitiveValue = (button) => {
            const value = sensitiveValue(button);
            if (!value) return false;
            const blob = new Blob([value + '\n'], {type: 'text/plain;charset=utf-8'});
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = button.dataset.sensitiveFilename || 'linkvault-secret.txt';
            document.body.append(anchor);
            anchor.click();
            anchor.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            return true;
        };
        const printSensitiveValue = (button) => {
            const value = sensitiveValue(button);
            if (!value) return false;
            const label = button.closest('[data-sensitive-result]')?.dataset.sensitiveLabel || '离线凭据';
            const printWindow = window.open('', '_blank', 'popup,width=720,height=640');
            if (!printWindow) return false;
            printWindow.opener = null;
            const style = printWindow.document.createElement('style');
            style.textContent = 'body{margin:48px;color:#111;font:16px/1.5 system-ui,sans-serif}h1{font-size:24px}pre{white-space:pre-wrap;overflow-wrap:anywhere;border:1px solid #bbb;padding:18px;font:15px/1.65 ui-monospace,monospace}p{color:#555;font-size:13px}@media print{body{margin:20mm}}';
            const heading = printWindow.document.createElement('h1');
            heading.textContent = label;
            const content = printWindow.document.createElement('pre');
            content.textContent = value;
            const note = printWindow.document.createElement('p');
            note.textContent = '请离线保管；不要与数据库备份存放在同一位置。';
            printWindow.document.title = label;
            printWindow.document.head.append(style);
            printWindow.document.body.replaceChildren(heading, content, note);
            setTimeout(() => { printWindow.focus(); printWindow.print(); }, 50);
            return true;
        };
        document.querySelectorAll('[data-sensitive-result]').forEach((result) => {
            const checkbox = result.querySelector('[data-offline-saved]');
            checkbox?.addEventListener('change', () => {
                result.classList.toggle('is-confirmed', checkbox.checked);
            });
        });
        document.querySelectorAll('.domain-heading code').forEach((record, index) => {
            if (!(record instanceof HTMLElement) || record.nextElementSibling?.matches('[data-copy-dns]')) return;
            if (!record.id) record.id = `dns-verification-record-${index + 1}`;
            const copyButton = document.createElement('button');
            copyButton.className = 'button-secondary button-small';
            copyButton.type = 'button';
            copyButton.dataset.copy = record.textContent || '';
            copyButton.dataset.copyTarget = record.id;
            copyButton.dataset.copyLabel = 'DNS 验证记录';
            copyButton.dataset.copyDns = 'true';
            copyButton.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#icon-copy"/></svg>复制 DNS 记录';
            record.after(copyButton);
        });
        document.querySelectorAll('.link-table .target-url').forEach((target, index) => {
            if (!(target instanceof HTMLAnchorElement) || target.parentElement?.matches('.target-link-heading')) return;
            if (!target.id) target.id = `target-url-${index + 1}`;
            const heading = document.createElement('div');
            heading.className = 'target-link-heading';
            target.before(heading);
            heading.append(target);
            const copyButton = document.createElement('button');
            copyButton.className = 'button-secondary button-small icon-button target-copy';
            copyButton.type = 'button';
            copyButton.dataset.copy = target.href;
            copyButton.dataset.copyTarget = target.id;
            copyButton.dataset.copyLabel = '原始地址';
            copyButton.title = `复制原始地址 ${target.textContent?.trim() || ''}`;
            copyButton.setAttribute('aria-label', copyButton.title);
            copyButton.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#icon-copy"/></svg>';
            heading.append(copyButton);
        });
        const hasUnconfirmedSensitiveResult = () => [...document.querySelectorAll('[data-sensitive-result]')]
            .some((result) => !result.querySelector('[data-offline-saved]')?.checked);
        window.addEventListener('beforeunload', (event) => {
            if (!hasUnconfirmedSensitiveResult()) return;
            event.preventDefault();
            event.returnValue = '';
        });
        document.addEventListener('submit', (event) => {
            if (!hasUnconfirmedSensitiveResult()
                || window.confirm('凭据尚未确认已离线保存，仍要离开此页吗？')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);
        document.addEventListener('click', (event) => {
            const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
            if (!(anchor instanceof HTMLAnchorElement) || anchor.hasAttribute('download')
                || !hasUnconfirmedSensitiveResult()
                || window.confirm('凭据尚未确认已离线保存，仍要离开此页吗？')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
        }, true);

        document.querySelectorAll('[data-expiration-days]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = button.closest('fieldset')?.querySelector('[data-expiration-input]');
                if (!(input instanceof HTMLInputElement)) return;
                const date = new Date(Date.now() + Number(button.dataset.expirationDays) * 86400000);
                input.value = [date.getFullYear(), padDatePart(date.getMonth() + 1), padDatePart(date.getDate())].join('-')
                    + 'T' + [padDatePart(date.getHours()), padDatePart(date.getMinutes())].join(':');
                input.focus();
            });
        });
        document.querySelectorAll('[data-expiration-clear]').forEach((button) => {
            button.addEventListener('click', () => {
                const input = button.closest('fieldset')?.querySelector('[data-expiration-input]');
                if (input instanceof HTMLInputElement) input.value = '';
            });
        });

        const selectAll = document.querySelector('[data-select-all]');
        const rowSelections = [...document.querySelectorAll('[data-row-select]')];
        const bulkSubmit = document.querySelector('[data-bulk-submit]');
        const maintenanceRecheck = document.querySelector('[data-maintenance-recheck]');
        const selectedExport = document.querySelector('[data-selected-export]');
        const selectedCopy = document.querySelector('[data-selected-copy]');
        const selectedCountLabel = document.querySelector('[data-selected-count]');
        const updateSelectAll = () => {
            const selectedCount = rowSelections.filter((input) => input.checked).length;
            if (selectAll instanceof HTMLInputElement) {
                selectAll.checked = rowSelections.length > 0 && selectedCount === rowSelections.length;
                selectAll.indeterminate = selectedCount > 0 && selectedCount < rowSelections.length;
            }
            if (bulkSubmit instanceof HTMLButtonElement) bulkSubmit.disabled = selectedCount === 0;
            if (maintenanceRecheck instanceof HTMLButtonElement) maintenanceRecheck.disabled = selectedCount === 0 || selectedCount > 50;
            if (selectedExport instanceof HTMLButtonElement) {
                selectedExport.disabled = selectedCount === 0;
                const icon = selectedExport.querySelector('svg');
                selectedExport.replaceChildren();
                if (icon) selectedExport.append(icon);
                selectedExport.append(document.createTextNode(selectedCount > 0 ? `导出所选链接（${selectedCount}）` : '导出所选链接'));
            }
            if (selectedCopy instanceof HTMLButtonElement) selectedCopy.disabled = selectedCount === 0;
            if (selectedCountLabel) selectedCountLabel.textContent = `已选择 ${selectedCount} 条`;
        };
        selectAll?.addEventListener('change', () => {
            rowSelections.forEach((input) => { input.checked = selectAll.checked; });
            updateSelectAll();
        });
        rowSelections.forEach((input) => input.addEventListener('change', updateSelectAll));
        updateSelectAll();
        selectedCopy?.addEventListener('click', async () => {
            const urls = rowSelections.filter((input) => input.checked).map((input) => {
                const link = input.closest('tr')?.querySelector('.short-url a');
                return link instanceof HTMLAnchorElement ? link.href : '';
            }).filter(Boolean);
            if (!urls.length) return;
            if (await copyText(urls.join('\n'))) {
                showCopyFeedback(`已复制 ${urls.length} 条短链接。`);
                return;
            }
            const fallback = document.createElement('textarea');
            fallback.value = urls.join('\n');
            fallback.readOnly = true;
            fallback.className = 'bulk-copy-fallback';
            document.body.append(fallback);
            fallback.focus();
            fallback.select();
            showCopyFeedback('无法自动复制，已选中短链接，请按 Ctrl+C。', true);
        });

        document.querySelectorAll('[data-bulk-action]').forEach((control) => {
            const form = control.closest('form');
            const daysField = form?.querySelector('[data-bulk-days]');
            const tagsField = form?.querySelector('[data-bulk-tags]');
            const updateBulkFields = () => {
                const action = control.value;
                if (daysField) daysField.hidden = action !== 'extend';
                if (tagsField) tagsField.hidden = !['add_tags', 'remove_tags'].includes(action);
                const daysInput = daysField?.querySelector('input');
                const tagsInput = tagsField?.querySelector('input');
                if (daysInput) daysInput.required = action === 'extend';
                if (tagsInput) tagsInput.required = ['add_tags', 'remove_tags'].includes(action);
            };
            control.addEventListener('change', updateBulkFields);
            updateBulkFields();
        });

        document.querySelectorAll('[data-qr-value]').forEach((container) => {
            const download = container.closest('.qr-panel')?.querySelector('[data-qr-download]');
            const showQrError = () => {
                container.textContent = '二维码生成失败，请稍后重试。';
                container.classList.add('qr-error');
                container.setAttribute('role', 'alert');
                container.removeAttribute('aria-label');
                if (download instanceof HTMLAnchorElement) {
                    download.hidden = true;
                    download.removeAttribute('href');
                    download.setAttribute('aria-disabled', 'true');
                }
            };
            try {
                if (typeof window.qrcode !== 'function' || !container.dataset.qrValue) throw new Error('QR library unavailable');
                window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];
                const code = window.qrcode(0, 'M');
                code.addData(container.dataset.qrValue);
                code.make();
                const svg = code.createSvgTag({cellSize: 6, margin: 16, scalable: true, title: '短链接二维码'});
                if (!svg) throw new Error('QR output is empty');
                container.innerHTML = svg;
                container.classList.remove('qr-error');
                container.setAttribute('role', 'img');
                container.setAttribute('aria-label', container.dataset.qrLabel || '短链接二维码');
                container.removeAttribute('aria-live');
                if (download instanceof HTMLAnchorElement) {
                    const objectUrl = URL.createObjectURL(new Blob([svg], {type: 'image/svg+xml'}));
                    download.href = objectUrl;
                    download.hidden = false;
                    download.removeAttribute('aria-disabled');
                    window.addEventListener('pagehide', () => URL.revokeObjectURL(objectUrl), {once: true});
                }
            } catch (error) {
                showQrError();
            }
        });

        document.querySelectorAll('dialog[data-auto-open]').forEach((dialog) => {
            if (!(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function') return;
            if (dialog.open) dialog.close();
            dialog.showModal();
        });

        const firstInvalid = invalidControls[0];
        if (firstInvalid instanceof HTMLElement) {
            const details = firstInvalid.closest('details');
            if (details instanceof HTMLDetailsElement) details.open = true;
            requestAnimationFrame(() => firstInvalid.focus({preventScroll: false}));
        } else {
            const errorFlash = document.querySelector('[data-error-focus]');
            if (errorFlash instanceof HTMLElement) {
                requestAnimationFrame(() => errorFlash.focus({preventScroll: false}));
            } else {
                if (Number.isFinite(requestedScroll) && requestedScroll > 0) {
                    requestAnimationFrame(() => requestAnimationFrame(() => {
                        window.scrollTo({top: requestedScroll, behavior: 'auto'});
                        const cleanUrl = new URL(window.location.href);
                        cleanUrl.searchParams.delete('scroll');
                        history.replaceState(history.state, '', cleanUrl);
                    }));
                }
            }
        }

        const prepareReturnLink = (link) => {
            if (!(link instanceof HTMLAnchorElement)) return;
            if (!document.body.classList.contains('dashboard-page')
                || document.body.classList.contains('detail-page')
                || document.body.classList.contains('edit-page')) return;
            const destinationUrl = new URL(link.href, window.location.href);
            if (destinationUrl.origin !== window.location.origin
                || (!destinationUrl.pathname.endsWith('/link') && !destinationUrl.pathname.endsWith('/edit'))) return;
            const listUrl = new URL(window.location.href);
            const parameterMap = {
                q: 'return_q', view: 'return_view', page: 'return_page', status: 'return_status',
                sort: 'return_sort', tag: 'return_tag', favorite: 'return_favorite',
                section: 'return_section', maintenance: 'return_maintenance',
            };
            Object.entries(parameterMap).forEach(([source, destination]) => {
                if (listUrl.searchParams.has(source)) destinationUrl.searchParams.set(destination, listUrl.searchParams.get(source));
            });
            destinationUrl.searchParams.set('return_scroll', String(Math.max(0, Math.round(window.scrollY))));
            link.href = destinationUrl.href;
        };
        document.addEventListener('pointerdown', (event) => {
            if (event.target instanceof Element) prepareReturnLink(event.target.closest('a'));
        });

        const mobileActionMenus = [...document.querySelectorAll('.mobile-action-menu')];
        mobileActionMenus.forEach((menu) => {
            menu.addEventListener('toggle', () => {
                if (!menu.open) return;
                mobileActionMenus.forEach((otherMenu) => {
                    if (otherMenu !== menu) otherMenu.open = false;
                });
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            mobileActionMenus.forEach((menu) => { menu.open = false; });
        });

        document.addEventListener('click', async (event) => {
            if (!(event.target instanceof Element)) return;
            const sensitiveDownload = event.target.closest('[data-sensitive-download]');
            if (sensitiveDownload) {
                const downloaded = downloadSensitiveValue(sensitiveDownload);
                showCopyFeedback(downloaded ? '凭据文件已下载。' : '无法生成下载文件。', !downloaded);
                return;
            }
            const sensitivePrint = event.target.closest('[data-sensitive-print]');
            if (sensitivePrint) {
                const printed = printSensitiveValue(sensitivePrint);
                showCopyFeedback(printed ? '打印视图已打开。' : '无法打开打印视图，请检查浏览器弹窗设置。', !printed);
                return;
            }
            if (!event.target.closest('.mobile-action-menu')) {
                mobileActionMenus.forEach((menu) => { menu.open = false; });
            }
            prepareReturnLink(event.target.closest('a'));
            const renameFilterButton = event.target.closest('[data-rename-filter]');
            if (renameFilterButton) {
                const dialog = document.getElementById('rename-filter-dialog');
                const idInput = dialog?.querySelector('[data-rename-filter-id]');
                const nameInput = dialog?.querySelector('[data-rename-filter-name]');
                if (dialog instanceof HTMLDialogElement && idInput instanceof HTMLInputElement && nameInput instanceof HTMLInputElement) {
                    idInput.value = renameFilterButton.dataset.filterId || '';
                    nameInput.value = renameFilterButton.dataset.filterName || '';
                    dialog.showModal();
                    requestAnimationFrame(() => { nameInput.focus(); nameInput.select(); });
                }
                return;
            }
            const renameAnalyticsViewButton = event.target.closest('[data-rename-analytics-view]');
            if (renameAnalyticsViewButton) {
                const dialog = document.getElementById('rename-analytics-view-dialog');
                const idInput = dialog?.querySelector('[data-rename-analytics-view-id]');
                const nameInput = dialog?.querySelector('[data-rename-analytics-view-name]');
                if (dialog instanceof HTMLDialogElement && idInput instanceof HTMLInputElement && nameInput instanceof HTMLInputElement) {
                    idInput.value = renameAnalyticsViewButton.dataset.viewId || '';
                    nameInput.value = renameAnalyticsViewButton.dataset.viewName || '';
                    dialog.showModal();
                    requestAnimationFrame(() => { nameInput.focus(); nameInput.select(); });
                }
                return;
            }
            const openButton = event.target.closest('[data-open-dialog]');
            if (openButton) {
                const dialog = document.getElementById(openButton.dataset.openDialog);
                if (dialog instanceof HTMLDialogElement && typeof dialog.showModal === 'function') {
                    event.preventDefault();
                    if (dialog.open) dialog.close();
                    dialog.showModal();
                }
                return;
            }
            const closeButton = event.target.closest('[data-close-dialog]');
            if (closeButton) {
                const dialog = closeButton.closest('dialog');
                if (dialog instanceof HTMLDialogElement) {
                    event.preventDefault();
                    dialog.close();
                }
                return;
            }
            const shareButton = event.target.closest('[data-share]');
            if (shareButton) {
                const actionMenu = shareButton.closest('.mobile-action-menu');
                if (actionMenu instanceof HTMLDetailsElement) actionMenu.open = false;
                const url = (shareButton.dataset.shareUrl || '').trim();
                const shareData = {title: shareButton.dataset.shareTitle || '短链接', url};
                try {
                    if (!url || typeof navigator.share !== 'function'
                        || (typeof navigator.canShare === 'function' && !navigator.canShare(shareData))) {
                        throw new Error('Web Share API unavailable');
                    }
                    await navigator.share(shareData);
                    showCopyFeedback('分享已完成。');
                } catch (error) {
                    if (error?.name === 'AbortError') return;
                    if (await copyText(url)) {
                        showCopyFeedback('当前设备不支持系统分享，短链接已复制。');
                    } else {
                        const selected = selectCopyTarget(shareButton);
                        const manualAction = matchMedia('(pointer: coarse)').matches ? '请长按已选链接复制。' : '请按 Ctrl+C。';
                        showCopyFeedback(selected ? `无法打开系统分享，已选中短链接，${manualAction}` : '无法打开系统分享，请手动复制短链接。', true);
                    }
                }
                return;
            }
            const button = event.target.closest('[data-copy]');
            if (!button) return;
            if (!await copyText(button.dataset.copy || '')) {
                const selected = selectCopyTarget(button);
                const copyLabel = button.dataset.copyLabel
                    || button.closest('[data-sensitive-result]')?.dataset.sensitiveLabel || '内容';
                const manualAction = matchMedia('(pointer: coarse)').matches ? '请长按已选内容复制。' : '请按 Ctrl+C。';
                showCopyFeedback(selected ? `无法自动复制，已选中${copyLabel}，${manualAction}` : `复制失败，请手动选择${copyLabel}。`, true);
                return;
            }
            const original = button.innerHTML;
            button.innerHTML = '<svg class="icon" aria-hidden="true"><use href="#icon-check-circle"/></svg>已复制';
            setTimeout(() => button.innerHTML = original, 1200);
            const copyLabel = button.dataset.copyLabel
                || button.closest('[data-sensitive-result]')?.dataset.sensitiveLabel || '内容';
            showCopyFeedback(`${copyLabel}已复制。`);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize, {once: true});
    } else {
        initialize();
    }
})();
