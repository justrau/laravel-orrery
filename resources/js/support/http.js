/**
 * The HTTP client of the dashboard (the shared `$http` of app.js): a small
 * wrapper around fetch() with the part of the axios interface that the
 * screens, errors.js and shell/poll.js use.
 *
 * - `get(url, config)`, `delete(url, config)`, `post(url, data, config)`,
 *   `put(url, data, config)`, `patch(url, data, config)` and
 *   `request(config)`. A config holds `method`, `url`, `params` (the query),
 *   `data` (sent as JSON), `headers`, `timeout` (ms, 0 for none), `signal`
 *   and any other key (such as `handles`, errors.js), which stays on the
 *   config of the answer and of the error.
 * - Every request sends `X-Requested-With: XMLHttpRequest` (every write
 *   needs it, else 403 `header_missing`); a config cannot remove it.
 * - An answer is `{data, status, statusText, headers, config}`. `data` is
 *   the parsed JSON body, else the body text ('' when empty).
 * - A status outside 200–299 rejects with an Error that carries `response`
 *   (the answer) and `config`, as axios did. A timeout rejects with the code
 *   `ETIMEDOUT`, no answer with `ERR_NETWORK`, and an abort of the caller's
 *   signal with `__CANCEL__` and the code `ERR_CANCELED`, without a
 *   response (errors.js failureKind() and describeFailure()).
 * - Interceptors as in axios: `interceptors.request.use(fulfilled,
 *   rejected)` runs the last one added first, `interceptors.response.use()`
 *   the first one added first, and a request interceptor that rejects skips
 *   the request and reaches the response interceptors' `rejected`.
 * - `onRequest(listener)` calls the listener with the promise of every new
 *   request, synchronously when the request starts (the shell's held
 *   navigation waits for the reads that a new screen starts,
 *   support/navigation.js); it returns the removal. A listener that throws
 *   never breaks the request.
 */

/** The Accept header that axios sent. */
const ACCEPT = 'application/json, text/plain, */*';

/**
 * The URL with `params` as its query: null and undefined values are left
 * out, an array sends `key[]` once per item, and every other value is sent
 * as its text.
 */
export function queryUrl(url, params) {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params || {})) {
        if (value === null || value === undefined) continue;

        if (Array.isArray(value)) {
            for (const item of value) {
                if (item !== null && item !== undefined) search.append(key + '[]', String(item));
            }
        } else {
            search.append(key, String(value));
        }
    }

    const query = search.toString();

    if (query === '') return url;

    return url + (url.includes('?') ? '&' : '?') + query;
}

/** An Error with the fields that errors.js and the screens read. */
function requestError(message, code, config, response) {
    const error = new Error(message);

    error.name = 'HttpError';
    error.code = code;
    error.config = config;

    if (response !== undefined) error.response = response;

    return error;
}

/** The error of a request that its caller's signal aborted (describeFailure() shows nothing for it). */
function cancelError(config) {
    const error = requestError('canceled', 'ERR_CANCELED', config);

    error.name = 'CanceledError';
    error.__CANCEL__ = true;

    return error;
}

/** The body as JSON, else as its text. */
function parseBody(text) {
    if (text === '') return '';

    try {
        return JSON.parse(text);
    } catch (error) {
        return text;
    }
}

/**
 * A client. `headers` go with every request (the CSRF token); `fetch` is
 * the fetch function (Node tests pass a fake one).
 */
export function createHttp({headers: instanceHeaders = {}, fetch: fetchImpl = (...args) => globalThis.fetch(...args)} = {}) {
    const requestHandlers = [];
    const responseHandlers = [];
    const listeners = new Set();

    async function dispatch(config) {
        const own = config.signal;

        if (own && own.aborted) throw cancelError(config);

        const headers = {Accept: ACCEPT, ...instanceHeaders, ...(config.headers || {})};
        let body;

        if (config.data !== undefined && config.data !== null) {
            body = JSON.stringify(config.data);
            headers['Content-Type'] = 'application/json';
        }

        // Every write needs it (Http\Middleware\Authenticate); no request config removes it.
        headers['X-Requested-With'] = 'XMLHttpRequest';

        const controller = new AbortController();
        let reason = null;
        const stop = cause => {
            if (reason === null) reason = cause;

            controller.abort();
        };
        const onAbort = () => stop('cancel');
        const timeout = typeof config.timeout === 'number' && config.timeout > 0 ? config.timeout : 0;
        const timer = timeout > 0 ? setTimeout(() => stop('timeout'), timeout) : null;

        if (own) own.addEventListener('abort', onAbort, {once: true});

        let response;
        let text;

        try {
            response = await fetchImpl(queryUrl(config.url, config.params), {
                method: config.method.toUpperCase(),
                headers,
                body,
                credentials: 'same-origin',
                signal: controller.signal,
            });
            // The timeout and the caller's signal cover the body too.
            text = await response.text();
        } catch (cause) {
            if (reason === 'timeout') throw requestError(`timeout of ${timeout}ms exceeded`, 'ETIMEDOUT', config);

            if (reason === 'cancel') throw cancelError(config);

            throw requestError('Network Error', 'ERR_NETWORK', config);
        } finally {
            if (timer !== null) clearTimeout(timer);

            if (own) own.removeEventListener('abort', onAbort);
        }

        const answer = {data: parseBody(text), status: response.status, statusText: response.statusText, headers: response.headers, config};

        if (response.status >= 200 && response.status < 300) return answer;

        throw requestError(`Request failed with status code ${response.status}`, response.status < 500 ? 'ERR_BAD_REQUEST' : 'ERR_BAD_RESPONSE', config, answer);
    }

    function request(config) {
        let chain = Promise.resolve({...config, method: String(config.method || 'get').toLowerCase()});

        for (const [fulfilled, rejected] of [...requestHandlers].reverse()) chain = chain.then(fulfilled, rejected);

        chain = chain.then(dispatch);

        for (const [fulfilled, rejected] of responseHandlers) chain = chain.then(fulfilled, rejected);

        for (const listener of listeners) {
            try {
                listener(chain);
            } catch (error) {
                // A listener never breaks a request.
            }
        }

        return chain;
    }

    const use = handlers => (fulfilled, rejected) => {
        handlers.push([fulfilled, rejected]);
    };

    return {
        interceptors: {
            request: {use: use(requestHandlers)},
            response: {use: use(responseHandlers)},
        },
        request,
        onRequest(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        get: (url, config = {}) => request({...config, url, method: 'get'}),
        delete: (url, config = {}) => request({...config, url, method: 'delete'}),
        post: (url, data, config = {}) => request({...config, url, method: 'post', data}),
        put: (url, data, config = {}) => request({...config, url, method: 'put', data}),
        patch: (url, data, config = {}) => request({...config, url, method: 'patch', data}),
    };
}
