import { readdirSync, readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import vue from '@vitejs/plugin-vue';

/**
 * The directory of the npm package that holds a file, or null when the file is
 * not in node_modules/. The last node_modules/ segment decides, so a nested
 * package counts as itself, and so does a package in pnpm's store layout
 * (node_modules/.pnpm/<name>@<version>/node_modules/<name>/, the real path
 * that Vite resolves a package to).
 */
function packageDirectory(file) {
    const path = file.split('?')[0].replaceAll('\\', '/');
    const at = path.lastIndexOf('/node_modules/');
    if (at === -1) {
        return null;
    }
    const parts = path.slice(at + '/node_modules/'.length).split('/');
    const name = parts[0].startsWith('@') ? `${parts[0]}/${parts[1]}` : parts[0];

    return `${path.slice(0, at)}/node_modules/${name}`;
}

/**
 * Writes dist/THIRD_PARTY_NOTICES.txt: the name, version, license and license
 * text (and NOTICE text, when the package has one) of every npm package whose
 * code or files end up in dist/. A JavaScript module counts when the bundle
 * renders code of it (a module that tree shaking removed does not); a file of
 * a package that a stylesheet names with url() counts as it is inlined (the
 * stylesheets name none today).
 */
function thirdPartyNotices() {
    const assetPackages = new Set();

    return {
        name: 'orrery-third-party-notices',
        enforce: 'pre',
        transform(code, id) {
            if (!id.split('?')[0].endsWith('.css')) {
                return null;
            }
            for (const match of code.matchAll(/url\(\s*['"]?([^'")]+)['"]?\s*\)/g)) {
                const directory = packageDirectory(resolve(dirname(id), match[1]));
                if (directory !== null) {
                    assetPackages.add(directory);
                }
            }

            return null;
        },
        generateBundle(options, bundle) {
            const directories = new Set(assetPackages);
            for (const file of Object.values(bundle)) {
                if (file.type !== 'chunk') {
                    continue;
                }
                for (const [id, module] of Object.entries(file.modules)) {
                    const directory = packageDirectory(id);
                    if (directory !== null && module.renderedLength > 0) {
                        directories.add(directory);
                    }
                }
            }

            const entries = [...directories].map((directory) => {
                const manifest = JSON.parse(readFileSync(`${directory}/package.json`, 'utf8'));
                const files = readdirSync(directory).sort();
                const licenseFile = files.find((name) => /^licen[cs]e/i.test(name));
                const noticeFile = files.find((name) => /^notice/i.test(name));
                const text = (licenseFile === undefined
                    ? `The package ships no license file. Its package.json names the license ${manifest.license}.`
                    : readFileSync(`${directory}/${licenseFile}`, 'utf8').trim())
                    + (noticeFile === undefined ? '' : `\n\n${readFileSync(`${directory}/${noticeFile}`, 'utf8').trim()}`);
                if (typeof manifest.license !== 'string') {
                    this.error(`${manifest.name ?? directory} names no license in its package.json.`);
                }

                return { name: manifest.name, version: manifest.version, license: manifest.license, text };
            }).sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));

            const rule = '='.repeat(72);
            const sections = entries.map((entry) => `${rule}\n${entry.name} ${entry.version} (${entry.license})\n${rule}\n\n${entry.text}\n`);
            this.emitFile({
                type: 'asset',
                fileName: 'THIRD_PARTY_NOTICES.txt',
                source: 'Third-party code in the built files of dist/ (written by pnpm run build from the\n'
                    + 'npm packages of the bundle). See NOTICE.md for the rest of the attribution.\n\n'
                    + sections.join('\n'),
            });
        },
    };
}

/** @type {import('vite').UserConfig} */
export default {
    plugins: [vue(), thirdPartyNotices()],
    build: {
        assetsDir: '',
        // The package serves no asset files: Orrery::css() and Orrery::js() inline
        // dist/ into the page, so the build must write no file besides them.
        // The bundle budget of bin/check-assets (bundle_budget_bytes), in kB of
        // 1,000. Vite counts UTF-16 code units and the check counts
        // bytes: the check is the authority, and Vite stays silent up to the
        // difference that non-ASCII characters make.
        chunkSizeWarningLimit: 650,
        rolldownOptions: {
            input: ['resources/js/app.js'],
            output: {
                entryFileNames: '[name].js',
                chunkFileNames: '[name].js',
                assetFileNames: '[name].[ext]',
            },
        },
    },
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
};
