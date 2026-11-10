// Screenshots of the Orrery dashboard for the README and the docs.
//
//   node screenshots.mjs --base=http://127.0.0.1:8000 --out=../../docs/images [--path=orrery] [--chrome=<path>]
//
// Serve the showcase Workbench first (`composer workbench:showcase`, which
// builds the shop app of ShowcaseSeeder; every visitor may open it), and start
// its workers within a minute (`composer workbench:showcase-workers`). The
// script writes overview.png, jobs.png, job-detail.png, failures.png,
// queues.png, metrics.png, workers.png and batches.png into --out, at a
// 1440x900 viewport and a device scale factor of 2. Resize them afterwards
// (`sips --resampleWidth 1600 *.png`); a file that stays above 400 KB can go
// to 256 colours (Pillow, FASTOCTREE, no dither). The job detail stops above
// the exception text, which holds local paths.
// --chrome=<path>, else PW_EXE, names the Chrome binary. Exit 0 pass, 1 fail, 64 usage.

import {chromium} from 'playwright-core';
import {mkdirSync} from 'node:fs';

const DEFAULT_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const SETTLE_MS = 1000;
const SCREEN_TIMEOUT_MS = 20000;
const JOB_DETAIL_CLASS = 'ChargeSubscription';

function parseArgs(argv) {
    const options = {path: 'orrery'};

    for (const arg of argv) {
        const match = /^--([a-z-]+)=(.*)$/s.exec(arg);

        if (!match) {
            throw new Error(`unknown argument: ${arg}`);
        }

        const [, name, value] = match;

        if (name === 'base') options.base = value.replace(/\/+$/, '');
        else if (name === 'out') options.out = value;
        else if (name === 'chrome') options.chrome = value;
        else if (name === 'path') options.path = value.replace(/^\/+|\/+$/g, '');
        else throw new Error(`unknown option: --${name}`);
    }

    if (!options.base || !options.out) {
        throw new Error('--base and --out are required');
    }

    return options;
}

/** The uuid of the newest failed job of a class (a short name), from the jobs API. */
async function failedJobOf(page, options, shortName) {
    const response = await page.request.get(`${options.base}/${options.path}/api/jobs?status=failed`, {headers: {Accept: 'application/json'}});

    if (!response.ok()) {
        throw new Error(`/api/jobs answered ${response.status()}`);
    }

    const job = ((await response.json()).jobs || []).find(row => row.name.endsWith(`\\${shortName}`));

    if (!job) {
        throw new Error(`no failed ${shortName} for the job detail`);
    }

    return job.id;
}

// Each shot: the file, the route below the dashboard path (null: the job
// detail of a failed job), the title the screen sets, the viewport height,
// and an optional step that opens a row before the shot.
const SHOTS = [
    {file: 'overview', route: 'overview', title: 'Overview', height: 1170},
    {file: 'jobs', route: 'jobs?status=completed', title: 'Jobs'},
    {file: 'job-detail', route: null, title: 'Job', height: 1170},
    {file: 'failures', route: 'failed', title: 'Failure groups', open: page => page.getByText('ChargeSubscription', {exact: true}).first().click()},
    {file: 'queues', route: 'queues', title: 'Queues'},
    {file: 'metrics', route: 'classes', title: 'Job classes'},
    {file: 'workers', route: 'workers', title: 'Workers'},
    {file: 'batches', route: 'batches', title: 'Batches', open: page => page.getByText('Import customers', {exact: true}).click()},
];

/** Waits until the screen has its data: no loading placeholder, the network idle, a short settle. */
async function settle(page) {
    await page.waitForLoadState('networkidle', {timeout: SCREEN_TIMEOUT_MS}).catch(() => {});
    await page.waitForFunction(() => !/\bLoading\b/i.test(document.querySelector('#orrery')?.innerText || ''), null, {timeout: SCREEN_TIMEOUT_MS});
    await page.waitForTimeout(SETTLE_MS);
}

async function main() {
    let options;

    try {
        options = parseArgs(process.argv.slice(2));
    } catch (error) {
        console.error(`screenshots.mjs: ${error.message}`);
        process.exit(64);
    }

    mkdirSync(options.out, {recursive: true});

    const problems = [];
    const written = [];
    const browser = await chromium.launch({executablePath: options.chrome || process.env.PW_EXE || DEFAULT_CHROME, headless: true});

    try {
        const context = await browser.newContext({viewport: {width: 1440, height: 900}, deviceScaleFactor: 2, colorScheme: 'light'});
        const page = await context.newPage();
        page.on('pageerror', error => problems.push(`page error: ${error.message}`));

        for (const {file, route, title, height = 900, open = null} of SHOTS) {
            const target = route ?? `jobs/${await failedJobOf(page, options, JOB_DETAIL_CLASS)}`;
            await page.setViewportSize({width: 1440, height});
            const response = await page.goto(`${options.base}/${options.path}/${target}`, {waitUntil: 'load'});

            if (!response || response.status() !== 200) {
                problems.push(`/${target} answered ${response ? response.status() : 'nothing'}`);
                continue;
            }

            try {
                await page.waitForFunction(expected => document.title === `${expected} · Orrery`, title, {timeout: SCREEN_TIMEOUT_MS});
                await settle(page);

                if (open) {
                    await open(page);
                    await settle(page);
                }
            } catch {
                problems.push(`/${target} did not finish loading (title "${await page.title()}")`);
                continue;
            }

            const path = `${options.out}/${file}.png`;
            await page.screenshot({path});
            written.push(path);
        }
    } catch (error) {
        problems.push(`screenshots stopped: ${error.message}`);
    } finally {
        await browser.close();
    }

    console.log(JSON.stringify({ok: problems.length === 0, written, problems}, null, 2));
    process.exit(problems.length === 0 ? 0 : 1);
}

await main();
