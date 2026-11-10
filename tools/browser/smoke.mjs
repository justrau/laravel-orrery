// One operator pass over every screen of the Orrery SPA (bin/verify-install).
//
//   node smoke.mjs --base=http://127.0.0.1:8000 --login=/verify-login/operator@verify.test
//       [--path=orrery] [--expect-text=<text>] [--allow-console=<regex>] [--screenshots=<dir>]
//
// --login is a path of the host application that signs the operator in (GET).
// It loads every screen in a new document, waits until the screen sets its
// title, and fails (exit 1) on any `securitypolicyviolation` event, page
// error, console error that --allow-console does not match, or response of
// 400 or more from the dashboard. PW_EXE names the Chrome binary.
// It prints one JSON summary on stdout. Exit 0 pass, 1 fail, 64 usage.

import {chromium} from 'playwright-core';
import {mkdirSync} from 'node:fs';

const DEFAULT_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SETTLE_MS = 750;
const SCREEN_TIMEOUT_MS = 20000;

function parseArgs(argv) {
    const options = {path: 'orrery', expectText: [], allowConsole: null, screenshots: null};

    for (const arg of argv) {
        const match = /^--([a-z-]+)=(.*)$/s.exec(arg);

        if (!match) {
            throw new Error(`unknown argument: ${arg}`);
        }

        const [, name, value] = match;

        if (name === 'base') options.base = value.replace(/\/+$/, '');
        else if (name === 'login') options.login = value;
        else if (name === 'path') options.path = value.replace(/^\/+|\/+$/g, '');
        else if (name === 'expect-text') options.expectText.push(value);
        else if (name === 'allow-console') options.allowConsole = new RegExp(value);
        else if (name === 'screenshots') options.screenshots = value;
        else throw new Error(`unknown option: --${name}`);
    }

    if (!options.base || !options.login) {
        throw new Error('--base and --login are required');
    }

    return options;
}

// Each screen: its route below the dashboard path and the title it sets.
// The job detail route is added at run time from the newest completed job.
const SCREENS = [
    ['overview', 'Overview'],
    ['queues', 'Queues'],
    ['queues/database/default', 'Queues'],
    ['workers', 'Workers'],
    ['jobs', 'Jobs'],
    ['tags', 'Tags'],
    ['classes', 'Job classes'],
    ['failed', 'Failure groups'],
    ['batches', 'Batches'],
];

async function main() {
    let options;

    try {
        options = parseArgs(process.argv.slice(2));
    } catch (error) {
        console.error(`smoke.mjs: ${error.message}`);
        process.exit(64);
    }

    const problems = [];
    const visited = [];
    const browser = await chromium.launch({executablePath: process.env.PW_EXE || DEFAULT_CHROME, headless: true});

    try {
        const context = await browser.newContext({viewport: {width: 1440, height: 900}});
        let current = '(login)';

        // The binding and the listener come through the DevTools protocol,
        // before any script of the page, so the page's CSP does not block them.
        await context.exposeBinding('__orreryCsp', (_source, text) => problems.push(`csp violation on ${current}: ${text}`));
        await context.addInitScript(() => {
            document.addEventListener('securitypolicyviolation', event => {
                window.__orreryCsp(`${event.violatedDirective} blocked ${event.blockedURI || '(inline)'}`);
            });
        });

        const page = await context.newPage();
        page.on('pageerror', error => problems.push(`page error on ${current}: ${error.message}`));
        page.on('console', message => {
            if (message.type() === 'error' && !(options.allowConsole && options.allowConsole.test(message.text()))) {
                problems.push(`console error on ${current}: ${message.text()}`);
            }
        });
        page.on('response', response => {
            if (response.status() >= 400 && response.url().startsWith(`${options.base}/${options.path}`)) {
                problems.push(`HTTP ${response.status()} on ${current}: ${response.url()}`);
            }
        });

        const login = await page.goto(`${options.base}${options.login}`);

        if (!login || login.status() >= 400) {
            throw new Error(`the login path answered ${login ? login.status() : 'nothing'}`);
        }

        const screens = [...SCREENS];
        const jobs = await page.request.get(`${options.base}/${options.path}/api/jobs?status=completed`, {headers: {Accept: 'application/json'}});
        const uuid = jobs.ok() ? ((await jobs.json()).jobs || [])[0]?.id : null;

        if (uuid) {
            screens.push([`jobs/${uuid}`, 'Job']);
        } else {
            problems.push(`no job for the job detail screen (/api/jobs answered ${jobs.status()})`);
        }

        if (options.screenshots) {
            mkdirSync(options.screenshots, {recursive: true});
        }

        for (const [route, title] of screens) {
            current = `/${route}`;
            const response = await page.goto(`${options.base}/${options.path}/${route}`, {waitUntil: 'load'});

            if (!response || response.status() !== 200) {
                problems.push(`the page /${route} answered ${response ? response.status() : 'nothing'}`);
                continue;
            }

            if (!response.headers()['content-security-policy']) {
                problems.push(`the page /${route} has no Content-Security-Policy header`);
            }

            try {
                await page.waitForFunction(expected => document.title === `${expected} · Orrery`, title, {timeout: SCREEN_TIMEOUT_MS});
                await page.waitForLoadState('networkidle', {timeout: SCREEN_TIMEOUT_MS}).catch(() => {});
                await page.waitForTimeout(SETTLE_MS);
            } catch {
                problems.push(`the screen /${route} did not render (title "${await page.title()}")`);
                continue;
            }

            const text = await page.evaluate(() => document.querySelector('#orrery')?.innerText || '');

            if (text.trim() === '') {
                problems.push(`the screen /${route} rendered no text`);
            }

            if (options.screenshots) {
                await page.screenshot({path: `${options.screenshots}/${route.replace(/[^a-z0-9]+/gi, '-')}.png`, fullPage: true});
            }

            visited.push(`/${route}`);
        }

        // Navigation inside the SPA: from the overview, follow the sidebar link to the queues.
        current = '/overview (navigation)';
        await page.goto(`${options.base}/${options.path}/overview`);
        await page.waitForFunction(() => document.title === 'Overview · Orrery', null, {timeout: SCREEN_TIMEOUT_MS});
        await page.click(`a[href$="/${options.path}/queues"]`, {timeout: SCREEN_TIMEOUT_MS});
        await page.waitForFunction(() => document.title === 'Queues · Orrery', null, {timeout: SCREEN_TIMEOUT_MS})
            .catch(() => problems.push('the sidebar link to the queues did not open them'));

        // Rendered data: the completed jobs list shows the expected texts (for example a job class).
        if (options.expectText.length > 0) {
            current = '/jobs?status=completed (data)';
            await page.goto(`${options.base}/${options.path}/jobs?status=completed`);
            await page.waitForFunction(() => document.title === 'Jobs · Orrery', null, {timeout: SCREEN_TIMEOUT_MS});

            for (const expected of options.expectText) {
                await page.waitForFunction(text => document.body.innerText.includes(text), expected, {timeout: SCREEN_TIMEOUT_MS})
                    .catch(() => problems.push(`the jobs list does not show "${expected}"`));
            }
        }
    } catch (error) {
        problems.push(`smoke stopped: ${error.message}`);
    } finally {
        await browser.close();
    }

    console.log(JSON.stringify({ok: problems.length === 0, visited, problems}, null, 2));
    process.exit(problems.length === 0 ? 0 : 1);
}

await main();
