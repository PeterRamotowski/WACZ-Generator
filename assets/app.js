import './app.css';

/**
 * Collection field management for dynamic form collections
 */
class CollectionManager {
    constructor() {
        this.collections = new Map();
        this.init();
    }

    init() {
        document.addEventListener('DOMContentLoaded', () => {
            this.initializeCollections();
        });
    }

    initializeCollections() {
        this.registerCollection('exclude-urls-collection', {
            fieldName: 'excludeUrls',
            placeholder: this.getTranslation('placeholders.enter_url_to_exclude'),
            removeLabel: this.getTranslation('actions.remove')
        });

        this.registerCollection('exclude-patterns-collection', {
            fieldName: 'excludePatterns', 
            placeholder: this.getTranslation('placeholders.enter_pattern_to_exclude'),
            removeLabel: this.getTranslation('actions.remove')
        });
    }

    registerCollection(collectionId, config) {
        const collectionElement = document.getElementById(collectionId);
        if (!collectionElement) return;

        this.collections.set(collectionId, config);
    }

    addItem(collectionId) {
        const config = this.collections.get(collectionId);
        if (!config) return;

        const collection = document.getElementById(collectionId);
        const index = collection.children.length;
        const itemDiv = document.createElement('div');
        itemDiv.className = 'flex items-center space-x-2 collection-item';

        itemDiv.innerHTML = `
            <input type="text" 
                   name="wacz_generation_request[${config.fieldName}][${index}]" 
                   class="flex-1 px-3 py-2 rounded-md border-gray-400 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                   placeholder="${config.placeholder}">
            <button type="button" 
                    class="inline-flex items-center px-2 py-1 border border-red-300 text-sm font-medium rounded text-red-700 bg-red-50 hover:bg-red-100" 
                    onclick="collectionManager.removeItem(this, '${collectionId}')">
                ${config.removeLabel}
            </button>
        `;

        collection.appendChild(itemDiv);

        const newInput = itemDiv.querySelector('input');
        if (newInput) {
            newInput.focus();
        }
    }

    removeItem(button, collectionId) {
        const item = button.closest('.collection-item');
        if (item) {
            item.remove();
            this.reindexCollection(collectionId);
        }
    }

    reindexCollection(collectionId) {
        const config = this.collections.get(collectionId);
        if (!config) return;

        const collection = document.getElementById(collectionId);
        const inputs = collection.querySelectorAll('input');

        inputs.forEach((input, index) => {
            input.name = `wacz_generation_request[${config.fieldName}][${index}]`;
        });
    }

    getTranslation(key) {
        if (typeof window.translations !== 'undefined' && window.translations[key]) {
            return window.translations[key];
        }

        const fallbacks = {
            'placeholders.enter_url_to_exclude': 'Enter URL to exclude',
            'placeholders.enter_pattern_to_exclude': 'Enter pattern to exclude',
            'actions.remove': 'Remove'
        };

        return fallbacks[key] || key;
    }
}

/**
 * Status management for WACZ processing
 */
class WaczStatusManager {
    constructor() {
        this.statusCheckInterval = null;
        this.lastCrawledPagesCount = 0;
        this.currentStatus = null;
        this.translations = {};
        this.config = {};
        this.init();
    }

    init() {
        document.addEventListener('DOMContentLoaded', () => {
            this.initializeStatusPage();
        });
    }

    initializeStatusPage() {
        const statusContainer = document.querySelector('[data-status-url]');
        if (!statusContainer) return;

        this.config.statusUrl = statusContainer.dataset.statusUrl;
        this.config.processUrl = statusContainer.dataset.processUrl;
        this.config.maxPages = parseInt(statusContainer.dataset.maxPages) || 0;
        this.currentStatus = statusContainer.dataset.currentStatus;
        this.lastCrawledPagesCount = parseInt(statusContainer.dataset.crawledPagesCount) || 0;

        this.translations = window.waczTranslations || {};

        if (this.currentStatus === 'processing') {
            this.startStatusChecking();
        }

        this.setupStartProcessingButton();
    }

    setupStartProcessingButton() {
        const startBtn = document.getElementById('start-processing-btn');
        if (!startBtn) return;

        startBtn.addEventListener('click', (e) => {
            e.preventDefault();
            this.startProcessing();
        });
    }

    startProcessing() {
        const button = document.getElementById('start-processing-btn');
        const textSpan = document.getElementById('processing-text');

        if (!button || !textSpan) return;

        button.disabled = true;
        textSpan.textContent = this.translations.processing_in_progress || 'Processing in progress...';
        button.className = button.className.replace('bg-blue-600', 'bg-yellow-600');

        this.currentStatus = 'processing';
        if (!this.statusCheckInterval) {
            this.startStatusChecking();
            setTimeout(() => this.checkStatus(), 1000);
        }

        fetch(this.config.processUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: ''
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                this.showMessage(
                    this.translations.processing_started_successfully || 'Processing started successfully',
                    'success'
                );
            } else {
                this.showMessage(
                    (this.translations.processing_failed || 'Processing failed: %message%')
                        .replace('%message%', data.message),
                    'error'
                );
            }
        })
        .catch(error => {
            this.showMessage(
                (this.translations.error_starting_processing || 'Error starting processing: %error%')
                    .replace('%error%', error.message),
                'error'
            );
        });
    }

    startStatusChecking() {
        if (this.statusCheckInterval) {
            clearInterval(this.statusCheckInterval);
        }
        this.statusCheckInterval = setInterval(() => this.checkStatus(), 2000);
    }

    checkStatus() {
        if (!this.config.statusUrl) return;

        fetch(this.config.statusUrl)
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (data.status !== this.currentStatus) {
                    this.currentStatus = data.status;

                    if (this.currentStatus === 'completed' || this.currentStatus === 'failed') {
                        clearInterval(this.statusCheckInterval);
                        location.reload();
                        return;
                    }
                }

                if (this.currentStatus === 'processing') {
                    this.showProgressSection();
                    this.updateProgressValues(data);

                    // Reload page if new pages were crawled (to show updated list)
                    if (data.crawled_pages_count > this.lastCrawledPagesCount) {
                        this.lastCrawledPagesCount = data.crawled_pages_count;
                        if (data.crawled_pages_count % 10 === 0) {
                            location.reload();
                        }
                    }
                }
            })
            .catch(error => {
                console.error('Error fetching progress:', error);
                // If there's an error fetching progress, don't stop the interval
                // This might be a temporary network issue
            });
    }

    showProgressSection() {
        const progressSection = document.getElementById('progress-section');
        if (progressSection) {
            progressSection.style.display = 'block';
        }
    }

    updateProgressValues(data) {
        const progressBar = document.getElementById('progress-bar');
        if (progressBar) {
            progressBar.style.width = data.progress_percentage + '%';
        }

        const progressText = document.querySelector('#progress-text span');
        if (progressText) {
            progressText.textContent = data.total_pages + '/' + this.config.maxPages;
        }

        const progressPercent = document.getElementById('progress-percentage');
        if (progressPercent) {
            progressPercent.textContent = data.progress_percentage.toFixed(1) + '%';
        }

        const estimatedTime = document.getElementById('estimated-completion');
        if (estimatedTime) {
            if (data.estimated_completion) {
                estimatedTime.textContent = (this.translations.estimated_completion || 'Estimated completion') + ' ' + data.estimated_completion;
                estimatedTime.style.display = 'block';
            } else {
                estimatedTime.style.display = 'none';
            }
        }

        const elements = {
            'total-pages': data.total_pages,
            'successful-pages': data.successful_pages,
            'error-pages': data.error_pages,
            'skipped-pages': data.skipped_pages
        };

        for (const [id, value] of Object.entries(elements)) {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value;
            }
        }
    }

    showMessage(message, type) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `flash-message ${type === 'success' ? 'flash-success' : 'flash-error'}`;
        alertDiv.innerHTML = `<p>${message}</p>`;

        const container = document.querySelector('.flash-messages');
        if (container) {
            container.innerHTML = '';
            container.insertAdjacentElement('afterbegin', alertDiv);
            setTimeout(() => alertDiv.remove(), 5000);
        }
    }
}

const collectionManager = new CollectionManager();
const waczStatusManager = new WaczStatusManager();

window.collectionManager = collectionManager;
window.waczStatusManager = waczStatusManager;
