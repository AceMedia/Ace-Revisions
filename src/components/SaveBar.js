/**
 * SaveBar component shared across the Ace plugins
 *
 * Redis-style fixed bottom save bar with auto-save and change tracking.
 *
 * @package AceMedia
 * @since 1.0.3
 */

const $ = window.jQuery;

class SaveBar {
    constructor(options = {}) {
        this.options = {
            containerSelector: '#ace-seo-settings-form',
            saveButtonSelector: '#ace-redis-save-btn',
            messageContainerSelector: '#ace-redis-messages',
            onSave: null,
            ajaxUrl: window.ajaxurl,
            action: '',
            nonce: '',
            storageKey: 'ace_auto_save_enabled',
            ...options
        };

        this.isInitialized = false;
        this.hasUnsavedChanges = false;
        this.isSaving = false;
        this.isSuccess = false;
        this.message = '';
        this.elapsedTime = 0;
        this.intervalId = null;
        this.originalFormData = null;

        try {
            const stored = localStorage.getItem(this.options.storageKey || 'ace_auto_save_enabled');
            this.isAutoSaveEnabled = stored === null ? true : stored === '1';
        } catch (e) {
            this.isAutoSaveEnabled = true;
        }

        this.init();
    }

    init() {
        if (this.isInitialized) return;

        this.createSaveBar();
        this.setupEventListeners();
        this.captureOriginalFormData();
        this.updateSaveButtonState();
        this.isInitialized = true;
    }

    createSaveBar() {
        if (document.querySelector('.ace-redis-save-bar')) {
            return;
        }

        const autoSaveToggle = this.isAutoSaveEnabled ? 'checked' : '';

        const saveBarHTML = `
            <div class="ace-redis-save-bar">
                <div class="save-bar-content">
                    <div class="save-bar-left">
                        <span class="save-message"></span>
                    </div>
                    <div class="save-bar-right">
                        <div class="auto-save-toggle-wrapper">
                            <label class="ace-switch" for="auto-save-toggle">
                                <input type="checkbox" id="auto-save-toggle" ${autoSaveToggle}>
                                <span class="ace-slider"></span>
                            </label>
                            <span class="toggle-label">Auto-save</span>
                        </div>
                        <button type="button" id="save-bar-button" class="button button-primary" disabled>
                            <span class="dashicons dashicons-admin-settings"></span>
                            <span class="button-text">Saved</span>
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', saveBarHTML);
        this.updateFixedPosition();
    }

    setupEventListeners() {
        $(this.options.containerSelector).on('input change', 'input, select, textarea', () => {
            setTimeout(() => this.checkForChanges(), 10);
        });

        $(document).on('click', '#save-bar-button', (e) => {
            e.preventDefault();
            this.handleSave();
        });

        $(document).on('change', '#auto-save-toggle', () => {
            this.toggleAutoSave();
        });

        $(window).on('resize scroll load', () => this.updateFixedPosition());

        if (window.wp && window.wp.hooks) {
            window.wp.hooks.addAction('wp-collapse-menu', 'ace-crawl-enhancer', () => {
                setTimeout(() => this.updateFixedPosition(), 300);
            });
        }

        $(window).on('beforeunload', (e) => {
            if (this.hasUnsavedChanges && !this.isSaving) {
                const message = 'You have unsaved changes. Are you sure you want to leave?';
                e.originalEvent.returnValue = message;
                return message;
            }
        });
    }

    updateFixedPosition() {
        const saveBar = document.querySelector('.ace-redis-save-bar');
        if (!saveBar) return;

        const adminMenuWrap = document.querySelector('#adminmenuwrap');
        if (adminMenuWrap) {
            saveBar.style.left = `${adminMenuWrap.offsetWidth}px`;
        }
    }

    captureOriginalFormData() {
        const $form = $(this.options.containerSelector);
        this.originalFormData = this.getFormDataObject($form);
    }

    getFormDataObject($form) {
        const formData = {};

        $form.serializeArray().forEach(field => {
            formData[field.name] = field.value;
        });

        $form.find('input[type="checkbox"]').each(function() {
            const name = $(this).attr('name');
            if (name) {
                formData[name] = $(this).is(':checked') ? '1' : '0';
            }
        });

        return formData;
    }

    checkForChanges() {
        if (!this.originalFormData) return;

        const $form = $(this.options.containerSelector);
        const currentData = this.getFormDataObject($form);
        const hasChanges = JSON.stringify(this.originalFormData) !== JSON.stringify(currentData);

        this.setUnsavedChanges(hasChanges);
    }

    setUnsavedChanges(hasChanges) {
        if (this.hasUnsavedChanges !== hasChanges) {
            this.hasUnsavedChanges = hasChanges;
            this.updateSaveButtonState();

            if (hasChanges) {
                this.startElapsedTimeTracking();
                if (this.isAutoSaveEnabled) {
                    setTimeout(() => this.handleAutoSave(), 500);
                }
            } else {
                this.stopElapsedTimeTracking();
            }
        }
    }

    updateSaveButtonState() {
        const $button = $('#save-bar-button');
        const $buttonText = $button.find('.button-text');
        const $icon = $button.find('.dashicons');

        if (this.isSaving) {
            $button.prop('disabled', true).removeClass('success');
            $buttonText.text('Saving...');
            $icon.removeClass('dashicons-admin-settings dashicons-yes-alt').addClass('dashicons-update');
        } else if (this.isSuccess) {
            $button.prop('disabled', true).addClass('success');
            $buttonText.text('Saved!');
            $icon.removeClass('dashicons-admin-settings dashicons-update').addClass('dashicons-yes-alt');
        } else if (this.hasUnsavedChanges) {
            $button.prop('disabled', false).removeClass('success');
            $buttonText.text('Save Changes');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
        } else {
            $button.prop('disabled', true).removeClass('success');
            $buttonText.text('Saved');
            $icon.removeClass('dashicons-update dashicons-yes-alt').addClass('dashicons-admin-settings');
        }
    }

    async handleSave() {
        if (!this.hasUnsavedChanges || this.isSaving) return;

        this.setSaving(true);

        try {
            const success = this.options.onSave && typeof this.options.onSave === 'function'
                ? await this.options.onSave()
                : await this.defaultSave();

            if (success) {
                this.showMessage('Settings saved successfully!', 'success');
                this.setSuccess(true);
                this.captureOriginalFormData();
                this.setUnsavedChanges(false);
                setTimeout(() => this.setSuccess(false), 3000);
            } else {
                this.showMessage('Save failed. Please try again.', 'error');
            }
        } catch (error) {
            this.showMessage('An error occurred while saving.', 'error');
        } finally {
            this.setSaving(false);
        }
    }

    async handleAutoSave() {
        if (!this.hasUnsavedChanges || this.isSaving) return;

        try {
            const success = this.options.onSave && typeof this.options.onSave === 'function'
                ? await this.options.onSave()
                : await this.defaultSave();

            if (success) {
                this.showMessage('Changes auto-saved!', 'success');
                this.captureOriginalFormData();
                this.setUnsavedChanges(false);
            } else {
                this.showMessage('Auto-save failed', 'error');
            }
        } catch (error) {
            this.showMessage('Auto-save error occurred', 'error');
        }
    }

    async defaultSave() {
        return new Promise((resolve) => {
            const formEl = document.querySelector(this.options.containerSelector);
            if (!formEl) {
                resolve(false);
                return;
            }

            const formData = new FormData(formEl);
            formData.append('action', this.options.action);
            formData.append('nonce', this.options.nonce || '');

            $.ajax({
                url: this.options.ajaxUrl || window.ajaxurl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: (response) => {
                    resolve(!!(response && response.success));
                },
                error: () => {
                    resolve(false);
                }
            });
        });
    }

    setSaving(isSaving) {
        this.isSaving = isSaving;
        this.updateSaveButtonState();
    }

    setSuccess(isSuccess) {
        this.isSuccess = isSuccess;
        this.updateSaveButtonState();
    }

    showMessage(message, type = 'info') {
        this.message = message;
        this.updateMessageDisplay(type);

        if (type === 'success') {
            this.startElapsedTimeTracking();
        }

        const hideDelay = type === 'error' ? 8000 : (type === 'success' ? 5000 : 3000);
        setTimeout(() => {
            this.clearMessage();
        }, hideDelay);
    }

    updateMessageDisplay(type = 'info') {
        const $messageContainer = $('.save-message');

        if (this.message) {
            $messageContainer
                .text(this.message)
                .addClass('visible')
                .removeClass('error success info')
                .addClass(type);
        } else {
            $messageContainer
                .removeClass('visible error success info')
                .text('');
        }
    }

    clearMessage() {
        this.message = '';
        this.updateMessageDisplay();
        this.stopElapsedTimeTracking();
    }

    startElapsedTimeTracking() {
        this.stopElapsedTimeTracking();
        this.elapsedTime = 0;

        this.intervalId = setInterval(() => {
            this.elapsedTime++;
            this.updateElapsedTimeDisplay();
        }, 1000);
    }

    stopElapsedTimeTracking() {
        if (this.intervalId) {
            clearInterval(this.intervalId);
            this.intervalId = null;
        }
    }

    updateElapsedTimeDisplay() {
        if (this.elapsedTime > 0) {
            $('.save-message').text(this.formatElapsedTime(this.elapsedTime));
        }
    }

    formatElapsedTime(seconds) {
        if (seconds < 60) return `${seconds}s ago`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    toggleAutoSave() {
        this.isAutoSaveEnabled = $('#auto-save-toggle').is(':checked');

        try {
            localStorage.setItem(this.options.storageKey || 'ace_auto_save_enabled', this.isAutoSaveEnabled ? '1' : '0');
        } catch (e) {
            // ignore
        }

        if (this.isAutoSaveEnabled) {
            this.showMessage('Auto-save enabled - changes will be saved automatically', 'success');
        } else {
            this.showMessage('Auto-save disabled - manual save required', 'info');
        }
    }
}

export default SaveBar;
