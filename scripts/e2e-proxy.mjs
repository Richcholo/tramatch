/**
 * A serialising proxy in front of PHP's built-in server.
 *
 * WHY THIS EXISTS, and it is not a workaround for a broken app: PHP's
 * `php -S` server is SINGLE-THREADED. It handles one request at a time, and
 * PHP_CLI_SERVER_WORKERS — the supported way to fix that — requires fork(), so
 * it is silently ignored on Windows. A browser opens up to six connections per
 * host, so the five that arrive while the first is in flight are answered with
 * ERR_CONNECTION_REFUSED. Every page in this app loads a stylesheet, a script,
 * a font and images, so this happened on every single page.
 *
 * The symptom was spectacularly misleading: the page's own HTML request
 * succeeded (one connection, nothing else in flight), the browser reported a
 * page, and then the assets refused. Without the console-error fixture the
 * tests would have "passed" on a completely unstyled, non-functional page.
 *
 * So: this proxy accepts the browser's parallel requests and hands them to PHP
 * one at a time. Node is single-threaded too, but its work here is I/O, so the
 * queue costs nothing — the only cost is that the suite is a little slower,
 * which is the right trade for not depending on an operating system feature
 * this platform does not have.
 *
 * It is deliberately a proxy and not a static-file server. Serving assets
 * ourselves would be faster, and would also mean the browser suite never
 * exercised a real asset request — which is the whole point of checking that
 * the page works in a browser.
 *
 * Usage: node scripts/e2e-proxy.mjs <public-port> <php-port>
 */

import http from 'node:http';

const [publicPort, phpPort] = process.argv.slice(2).map(Number);

if (!publicPort || !phpPort) {
    console.error('Usage: node scripts/e2e-proxy.mjs <public-port> <php-port>');
    process.exit(1);
}

const target = `http://127.0.0.1:${phpPort}`;

/**
 * Headers that describe this hop rather than the message, and must not be
 * forwarded to the application.
 *
 * `host` is DELIBERATELY NOT in this list, and that is worth explaining
 * because every other reverse proxy strips it. Laravel generates asset and URL
 * absolute paths from the request's host, so a stripped Host leaves the app
 * building links against its own upstream port: every `@vite` asset came out as
 * `http://127.0.0.1:8130/build/...` while the browser was on 8123, and each one
 * failed. Passing the client's Host through is what nginx's
 * `proxy_set_header Host $host` does, and it is the only way the application
 * learns the origin the browser is actually using.
 */
const HOP_BY_HOP = new Set([
    'connection',
    'keep-alive',
    'proxy-authenticate',
    'proxy-authorization',
    'te',
    'trailer',
    'transfer-encoding',
    'upgrade',
    'content-length',
]);

/**
 * One request at a time.
 *
 * Resets on failure, so a PHP crash mid-queue does not wedge every later
 * request behind a rejected promise forever.
 */
let queue = Promise.resolve();

function enqueue(work) {
    const result = queue.then(work);

    queue = result.then(
        () => undefined,
        () => undefined,
    );

    return result;
}

function readBody(request) {
    return new Promise((resolve, reject) => {
        const chunks = [];

        request.on('data', (chunk) => chunks.push(chunk));
        request.on('end', () => resolve(Buffer.concat(chunks)));
        request.on('error', reject);
    });
}

async function forward(request, response) {
    const headers = {};

    for (const [name, value] of Object.entries(request.headers)) {
        if (!HOP_BY_HOP.has(name.toLowerCase())) {
            headers[name] = value;
        }
    }

    const body = ['GET', 'HEAD'].includes(request.method) ? undefined : await readBody(request);

    // Node's `fetch` will not let us SET a Host header — it is a forbidden
    // header name and is computed from the target URL — so the upstream request
    // arrived with Host: 127.0.0.1:8130 and Laravel built every asset URL
    // against its own port instead of the browser's.
    //
    // The forward headers below are the protocol the app already speaks:
    // `bootstrap/app.php` trusts HEADER_X_FORWARDED_HOST, PORT and PROTO
    // explicitly, precisely because the production host terminates TLS in front
    // of PHP. This is the same mechanism, exercised locally.
    const forwardedHost = (request.headers.host ?? '').split(':')[0];
    const forwardedPort = (request.headers.host ?? '').split(':')[1];

    headers['x-forwarded-host'] = forwardedHost || '127.0.0.1';
    headers['x-forwarded-proto'] = 'http';
    headers['x-forwarded-port'] = forwardedPort || String(publicPort);

    const upstream = await fetch(target + request.url, {
        method: request.method,
        headers,
        body,
        // Laravel's 302s must reach the BROWSER, not be followed here:
        // following them in the proxy would rewrite the session cookie's
        // domain context and make every login in the suite subtly wrong.
        redirect: 'manual',
        // Node's fetch keeps connections alive, which is what makes the
        // single-threaded PHP server usable at all — without it every request
        // pays a fresh TCP handshake and the queue is the bottleneck.
        keepalive: true,
    });

    const payload = Buffer.from(await upstream.arrayBuffer());

    const outHeaders = {};

    upstream.headers.forEach((value, name) => {
        if (!HOP_BY_HOP.has(name.toLowerCase())) {
            outHeaders[name] = value;
        }
    });

    outHeaders['content-length'] = String(payload.length);

    response.writeHead(upstream.status, outHeaders);
    response.end(payload);
}

http
    .createServer((request, response) => {
        enqueue(() => forward(request, response)).catch((error) => {
            console.error(`proxy failed: ${error.message}`);
            response.writeHead(502, { 'content-type': 'text/plain' });
            response.end(`The PHP server on ${phpPort} did not answer.`);
        });
    })
    .listen(publicPort, '127.0.0.1', () => {
        console.log(`e2e proxy on http://127.0.0.1:${publicPort} -> php ${phpPort}`);
    });