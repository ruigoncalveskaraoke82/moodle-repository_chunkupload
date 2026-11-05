// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upload form of the Large file upload repository: drag and drop, several files, chunked upload
 * with progress, automatic retry and resume.
 *
 * @module     repository_chunkupload/uploader
 * @copyright  2026 Rui Pratas
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getStrings} from 'core/str';

const COMPONENT = 'repository_chunkupload';
const STORAGE_PREFIX = 'repository_chunkupload:';
const MAX_RETRIES = 10;
const MAX_RETRY_DELAY = 30000;

const STRING_KEYS = [
    'dropfile', 'dropfiles', 'maxsize', 'remove', 'retry', 'someerrors', 'statusdone', 'statusfailed',
    'statusoffline', 'statusprocessing', 'statusresuming', 'statusretrying', 'statusuploading', 'statuswaiting',
    'toolarge', 'emptyfile',
];

/** @type {Object|null} Configuration sent by the server. */
let config = null;

/** @type {Object} Loaded strings, by key. */
const str = {};

/** @type {number} Number of uploads in progress on the page. */
let activeUploads = 0;

/**
 * An error that must not be retried (the server refused the request).
 */
class FatalError extends Error {
}

/**
 * Pause for some time.
 *
 * @param {number} ms
 * @returns {Promise}
 */
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Wait until the browser is back online.
 *
 * @returns {Promise}
 */
const waitOnline = () => new Promise((resolve) => {
    if (navigator.onLine) {
        resolve();
        return;
    }
    window.addEventListener('online', () => resolve(), {once: true});
});

/**
 * Replace {$a} in a string.
 *
 * @param {string} text
 * @param {string|number} value
 * @returns {string}
 */
const fill = (text, value) => text.replace('{$a}', value);

/**
 * Human readable size.
 *
 * @param {number} bytes
 * @returns {string}
 */
const formatSize = (bytes) => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = bytes;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }
    return (unit === 0 ? size : size.toFixed(1)) + ' ' + units[unit];
};

/**
 * Read from the browser storage, ignoring errors (private mode, disabled storage).
 *
 * @param {string} key
 * @returns {string|null}
 */
const storageGet = (key) => {
    try {
        return window.localStorage.getItem(STORAGE_PREFIX + key);
    } catch (e) {
        return null;
    }
};

/**
 * Write to the browser storage, ignoring errors.
 *
 * @param {string} key
 * @param {string|null} value null to remove
 */
const storageSet = (key, value) => {
    try {
        if (value === null) {
            window.localStorage.removeItem(STORAGE_PREFIX + key);
        } else {
            window.localStorage.setItem(STORAGE_PREFIX + key, value);
        }
    } catch (e) {
        // Storage is optional: without it, an interrupted upload starts again from the beginning.
    }
};

/**
 * Key that identifies a file of the user's computer, to resume its upload later.
 *
 * @param {File} file
 * @returns {string}
 */
const fileKey = (file) => [config.userid, file.name, file.size, file.lastModified].join(':');

/**
 * Send a request to the chunk endpoint.
 *
 * @param {Object} state form state
 * @param {string} action start|chunk|cancel
 * @param {Object} params
 * @param {Blob|null} blob chunk data
 * @param {AbortSignal|undefined} signal
 * @returns {Promise<Object>}
 */
const callEndpoint = async(state, action, params, blob = null, signal = undefined) => {
    const body = new FormData();
    body.append('sesskey', window.M.cfg.sesskey);
    body.append('action', action);
    body.append('contextid', state.contextId);
    Object.entries(params).forEach(([name, value]) => {
        if (Array.isArray(value)) {
            value.forEach((v) => body.append(name + '[]', v));
        } else {
            body.append(name, value);
        }
    });
    if (blob) {
        body.append('chunk', blob, 'chunk');
    }
    const response = await fetch(config.endpoint, {method: 'POST', body, credentials: 'same-origin', signal});
    if (!response.ok) {
        throw new Error(response.statusText || String(response.status));
    }
    let data;
    try {
        data = await response.json();
    } catch (e) {
        throw new Error(e.message);
    }
    if (data && data.error) {
        throw new FatalError(data.error);
    }
    return data;
};

/**
 * Accepted types of the file picker, as a list.
 *
 * @param {Object} picker
 * @returns {string[]}
 */
const acceptedTypes = (picker) => {
    const types = picker.options.accepted_types;
    if (!types || types === '*') {
        return ['*'];
    }
    return Array.isArray(types) ? types : Object.values(types);
};

/**
 * Whether the file picker accepts several files.
 *
 * @param {Object} picker
 * @returns {boolean}
 */
const allowsMultiple = (picker) => picker.options.env === 'filemanager' && picker.options.maxfiles !== 1;

/**
 * Update the status line and progress bar of a file.
 *
 * @param {Object} item
 * @param {string} status
 * @param {number|null} percent
 * @param {string} cssclass waiting|uploading|done|failed
 */
const setStatus = (item, status, percent = null, cssclass = 'uploading') => {
    item.statusNode.textContent = status;
    if (percent !== null) {
        const value = Math.max(0, Math.min(100, Math.floor(percent)));
        item.barNode.style.width = value + '%';
        item.barNode.parentNode.setAttribute('aria-valuenow', value);
    }
    ['waiting', 'uploading', 'done', 'failed'].forEach((name) => {
        item.node.classList.toggle('repository_chunkupload-' + name, name === cssclass);
    });
    item.retryNode.hidden = cssclass !== 'failed';
};

/**
 * Percentage of a file already sent.
 *
 * @param {Object} item
 * @param {number} received
 * @returns {number}
 */
const percentOf = (item, received) => (item.file.size ? 100 * received / item.file.size : 100);

/**
 * Send one chunk, retrying on network problems.
 *
 * @param {Object} state
 * @param {Object} item
 * @param {number} offset
 * @param {Blob} blob
 * @returns {Promise<number>} bytes the server has received
 */
const sendChunk = async(state, item, offset, blob) => {
    let attempts = 0;
    for (;;) {
        try {
            const data = await callEndpoint(state, 'chunk', {uuid: item.uuid, offset}, blob, item.controller.signal);
            return parseInt(data.received, 10);
        } catch (e) {
            if (e instanceof FatalError || item.cancelled) {
                throw e;
            }
            if (!navigator.onLine) {
                setStatus(item, str.statusoffline);
                await waitOnline();
                continue;
            }
            attempts++;
            if (attempts > MAX_RETRIES) {
                throw e;
            }
            const delay = Math.min(MAX_RETRY_DELAY, 1000 * Math.pow(2, attempts - 1));
            setStatus(item, fill(str.statusretrying, Math.round(delay / 1000)));
            await sleep(delay);
        }
    }
};

/**
 * Ask the file picker to add a completely uploaded file to the draft area.
 *
 * @param {Object} state
 * @param {Object} item
 * @returns {Promise<Object>} file picker response (url, id, file)
 */
const addToDraftArea = async(state, item) => {
    const picker = state.picker;
    const form = state.form;
    const params = new URLSearchParams();
    params.append('repo_id', picker.active_repo.id);
    params.append('env', picker.options.env);
    params.append('sesskey', window.M.cfg.sesskey);
    params.append('client_id', state.clientId);
    params.append('itemid', picker.options.itemid || 0);
    params.append('maxbytes', picker.options.maxbytes || -1);
    params.append('areamaxbytes', picker.options.areamaxbytes || -1);
    params.append('ctx_id', state.contextId);
    params.append('savepath', picker.options.savepath || '/');
    params.append('title', '');
    params.append('author', form.querySelector('.fp-setauthor input').value);
    params.append('license', form.querySelector('.fp-setlicense select').value);
    params.append('repo_upload_file', item.uuid);
    acceptedTypes(picker).forEach((type) => params.append('accepted_types[]', type));

    const response = await fetch(config.ajaxurl + '?action=upload', {
        method: 'POST',
        body: params,
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error(response.statusText || String(response.status));
    }
    const data = await response.json();
    if (data && data.error) {
        throw new FatalError(data.error);
    }
    return data;
};

/**
 * Upload one file, from the start or from where an earlier attempt stopped.
 *
 * @param {Object} state
 * @param {Object} item
 * @returns {Promise<Object>} file picker response
 */
const uploadFile = async(state, item) => {
    const picker = state.picker;
    const key = fileKey(item.file);
    item.cancelled = false;
    item.controller = new AbortController();

    const start = await callEndpoint(state, 'start', {
        filename: item.file.name,
        filesize: item.file.size,
        resume: item.uuid || storageGet(key) || '',
        itemid: picker.options.itemid || 0,
        maxbytes: picker.options.maxbytes || -1,
        areamaxbytes: picker.options.areamaxbytes || -1,
        // eslint-disable-next-line camelcase
        accepted_types: acceptedTypes(picker),
    });
    item.uuid = start.uuid;
    storageSet(key, item.uuid);

    let received = parseInt(start.received, 10) || 0;
    if (received > 0) {
        setStatus(item, fill(str.statusresuming, Math.floor(percentOf(item, received))), percentOf(item, received));
    }
    while (received < item.file.size) {
        if (item.cancelled) {
            throw new FatalError('cancelled');
        }
        const end = Math.min(received + config.chunksize, item.file.size);
        received = await sendChunk(state, item, received, item.file.slice(received, end));
        const percent = percentOf(item, received);
        setStatus(item, fill(str.statusuploading, Math.floor(percent)), percent);
    }

    setStatus(item, str.statusprocessing, 100);
    const result = await addToDraftArea(state, item);
    storageSet(key, null);
    item.uuid = null;
    return result;
};

/**
 * Give the uploaded files to the file picker, as its own upload form does.
 *
 * @param {Object} state
 * @param {Object} result last file picker response
 * @param {boolean} close whether to close the file picker
 */
const finish = (state, result, close) => {
    const picker = state.picker;
    if (picker.options.editor_target && picker.options.env === 'editor') {
        picker.options.editor_target.value = result.url;
        picker.options.editor_target.dispatchEvent(new Event('change'), {bubbles: true});
    }
    if (close) {
        picker.hide();
    }
    result.client_id = state.clientId; // eslint-disable-line camelcase
    const scope = picker.options.magicscope ? picker.options.magicscope : picker;
    picker.options.formcallback.apply(scope, [result]);
};

/**
 * Enable or disable the form while uploading.
 *
 * @param {Object} state
 */
const refreshControls = (state) => {
    const pending = state.items.filter((item) => !item.result && !item.invalid);
    state.button.disabled = state.busy || pending.length === 0;
    state.input.disabled = state.busy;
    state.dropzone.classList.toggle('repository_chunkupload-disabled', state.busy);
};

/**
 * Upload every file of the list that is not done yet.
 *
 * @param {Object} state
 */
const uploadAll = async(state) => {
    if (state.busy) {
        return;
    }
    state.busy = true;
    activeUploads++;
    refreshControls(state);
    state.messageNode.hidden = true;

    let last = null;
    let failures = 0;
    for (const item of state.items) {
        if (item.result || item.invalid || item.removed) {
            continue;
        }
        try {
            item.result = await uploadFile(state, item);
            last = item.result;
            setStatus(item, str.statusdone, 100, 'done');
            item.removeNode.hidden = true;
        } catch (e) {
            if (item.removed) {
                continue;
            }
            failures++;
            setStatus(item, fill(str.statusfailed, e.message), null, 'failed');
        }
    }

    activeUploads--;
    state.busy = false;
    refreshControls(state);
    if (last) {
        finish(state, last, failures === 0);
    }
    if (failures) {
        state.messageNode.textContent = str.someerrors;
        state.messageNode.hidden = false;
    } else if (last) {
        state.items = [];
        state.list.innerHTML = '';
    }
};

/**
 * Remove a file from the list, cancelling its upload.
 *
 * @param {Object} state
 * @param {Object} item
 */
const removeItem = (state, item) => {
    item.removed = true;
    item.cancelled = true;
    if (item.controller) {
        item.controller.abort();
    }
    if (item.uuid) {
        callEndpoint(state, 'cancel', {uuid: item.uuid}).catch(() => null);
        storageSet(fileKey(item.file), null);
    }
    item.node.remove();
    state.items = state.items.filter((other) => other !== item);
    refreshControls(state);
};

/**
 * Build the list entry of a file.
 *
 * @param {Object} state
 * @param {File} file
 * @returns {Object} item
 */
const createItem = (state, file) => {
    const node = document.createElement('li');
    node.className = 'repository_chunkupload-item';

    const head = document.createElement('div');
    head.className = 'd-flex align-items-start';
    const name = document.createElement('span');
    name.className = 'repository_chunkupload-name flex-grow-1';
    name.textContent = file.name + ' (' + formatSize(file.size) + ')';
    const retry = document.createElement('button');
    retry.type = 'button';
    retry.className = 'btn btn-link btn-sm p-0 ml-2 ms-2';
    retry.textContent = str.retry;
    retry.hidden = true;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'btn btn-link btn-sm p-0 ml-2 ms-2';
    remove.setAttribute('aria-label', fill(str.remove, file.name));
    remove.title = fill(str.remove, file.name);
    remove.textContent = '×';
    head.append(name, retry, remove);

    const progress = document.createElement('div');
    progress.className = 'progress my-1';
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-valuemin', '0');
    progress.setAttribute('aria-valuemax', '100');
    progress.setAttribute('aria-valuenow', '0');
    progress.setAttribute('aria-label', file.name);
    const bar = document.createElement('div');
    bar.className = 'progress-bar';
    bar.style.width = '0%';
    progress.append(bar);

    const status = document.createElement('div');
    status.className = 'small text-muted';

    node.append(head, progress, status);
    state.list.append(node);

    const item = {file, node, statusNode: status, barNode: bar, retryNode: retry, removeNode: remove,
        uuid: null, result: null, invalid: false, removed: false, cancelled: false, controller: null};
    remove.addEventListener('click', () => removeItem(state, item));
    retry.addEventListener('click', () => uploadAll(state));
    return item;
};

/**
 * Add files chosen or dropped by the user.
 *
 * @param {Object} state
 * @param {FileList|File[]} files
 */
const addFiles = (state, files) => {
    let list = Array.from(files);
    if (!state.multiple) {
        list = list.slice(0, 1);
        state.items.filter((item) => !item.result).forEach((item) => removeItem(state, item));
    }
    list.forEach((file) => {
        if (state.items.some((item) => item.file.name === file.name && item.file.size === file.size)) {
            return;
        }
        const item = createItem(state, file);
        state.items.push(item);
        if (config.maxfilesize > 0 && file.size > config.maxfilesize) {
            item.invalid = true;
            setStatus(item, fill(str.toolarge, config.maxfilesizetext), 0, 'failed');
            item.retryNode.hidden = true;
        } else if (file.size === 0) {
            item.invalid = true;
            setStatus(item, fill(str.emptyfile, file.name), 0, 'failed');
            item.retryNode.hidden = true;
        } else {
            setStatus(item, str.statuswaiting, 0, 'waiting');
        }
    });
    refreshControls(state);
};

/**
 * Prepare an upload form that the file picker has just shown.
 *
 * @param {HTMLElement} root
 */
const setupForm = (root) => {
    root.dataset.initialised = '1';
    const form = root.querySelector('form');
    const prefix = config.formid + '_';
    if (!form || !form.id || form.id.indexOf(prefix) !== 0) {
        return;
    }
    const clientId = form.id.substring(prefix.length);
    const picker = window.M && window.M.core_filepicker && window.M.core_filepicker.instances[clientId];
    if (!picker) {
        return;
    }
    const contextId = picker.options.context && picker.options.context.id ? picker.options.context.id : window.M.cfg.contextid;

    const message = document.createElement('div');
    message.className = 'alert alert-warning';
    message.hidden = true;
    root.querySelector('[data-region="list"]').after(message);

    const state = {
        root, form, picker, clientId, contextId,
        multiple: allowsMultiple(picker),
        items: [],
        busy: false,
        input: root.querySelector('[data-region="fileinput"]'),
        dropzone: root.querySelector('[data-region="dropzone"]'),
        list: root.querySelector('[data-region="list"]'),
        button: root.querySelector('[data-action="upload"]'),
        messageNode: message,
    };

    state.input.multiple = state.multiple;
    root.querySelector('[data-region="prompt"]').textContent = state.multiple ? str.dropfiles : str.dropfile;
    if (config.maxfilesizetext) {
        root.querySelector('[data-region="limits"]').textContent = fill(str.maxsize, config.maxfilesizetext);
    }

    form.addEventListener('submit', (e) => e.preventDefault());
    state.input.addEventListener('change', () => {
        addFiles(state, state.input.files);
        state.input.value = '';
    });
    ['dragenter', 'dragover'].forEach((type) => state.dropzone.addEventListener(type, (e) => {
        e.preventDefault();
        if (!state.busy) {
            state.dropzone.classList.add('repository_chunkupload-dragover');
        }
    }));
    ['dragleave', 'dragend'].forEach((type) => state.dropzone.addEventListener(type, () => {
        state.dropzone.classList.remove('repository_chunkupload-dragover');
    }));
    state.dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        state.dropzone.classList.remove('repository_chunkupload-dragover');
        if (!state.busy && e.dataTransfer && e.dataTransfer.files.length) {
            addFiles(state, e.dataTransfer.files);
        }
    });
    state.button.addEventListener('click', (e) => {
        e.preventDefault();
        if (picker.options.rememberuserlicensepref) {
            picker.set_preference('recentlicense', form.querySelector('.fp-setlicense select').value);
        }
        uploadAll(state);
    });
    refreshControls(state);
};

/**
 * Prepare every upload form on the page that is not ready yet.
 */
const scan = () => {
    document.querySelectorAll('.repository_chunkupload-root:not([data-initialised])').forEach(setupForm);
};

/**
 * Start watching the page for the upload form of the file picker.
 *
 * @param {Object} settings
 * @param {string} settings.endpoint URL of chunk.php
 * @param {string} settings.ajaxurl URL of repository_ajax.php
 * @param {number} settings.chunksize bytes per chunk
 * @param {number} settings.maxfilesize maximum file size in bytes, 0 for none
 * @param {string} settings.maxfilesizetext maximum file size for display
 * @param {string} settings.formid id prefix of the upload form
 * @param {number} settings.userid current user, to keep the resume information per user
 */
export const init = async(settings) => {
    if (config) {
        return;
    }
    config = settings;
    const values = await getStrings(STRING_KEYS.map((key) => ({key, component: COMPONENT})));
    STRING_KEYS.forEach((key, index) => {
        str[key] = values[index];
    });
    window.addEventListener('beforeunload', (e) => {
        if (activeUploads > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
    scan();
    new MutationObserver(scan).observe(document.body, {childList: true, subtree: true});
};
