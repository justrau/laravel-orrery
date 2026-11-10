# Notices

Orrery is licensed under the MIT License. See [LICENSE](LICENSE).

## Laravel Horizon

Parts of Orrery derive from Laravel Horizon (https://github.com/laravel/horizon), © Taylor Otwell, under the MIT License. Orrery copied these parts at its start and changed them since. The exact Horizon revision of the copy is not recorded.

These files and directories derive from Horizon:

- The dashboard front end:
  - `resources/js/`: the application setup and parts of the screens and components;
  - `resources/views/layout.blade.php`: the page layout;
  - `dist/`: the built files of the front end above.
- Parts of the PHP code:
  - `src/Http/Controllers/`: parts of the controllers;
  - `src/Http/Middleware/Authenticate.php` and `src/Exceptions/ForbiddenException.php`: the access check;
  - `src/Orrery.php`, `src/OrreryApplicationServiceProvider.php` and `stubs/OrreryServiceProvider.stub`: the `auth()` gate pattern, the asset inlining and the notification routes;
  - `src/Notifications/LongWaitDetected.php` and `src/Events/LongWaitDetected.php`: the long-wait notification;
  - `src/Tags.php` and `src/JobPayload.php`: the tag extraction and the payload handling;
  - `src/Contracts/`: `WorkloadRepository`, `Silenced` and `LongWaitDetectedNotification`;
  - `src/Console/`: the commands `orrery:install`, `orrery:clear`, `orrery:clear-metrics` and `orrery:forget` follow Horizon's commands of the same purpose.

Horizon's license:

```text
The MIT License (MIT)

Copyright (c) Taylor Otwell

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
```

## Bundled npm libraries

The built files in `dist/` bundle the code of these npm packages, all under the MIT License: Vue (`@vue/reactivity`, `@vue/runtime-core`, `@vue/runtime-dom` and `@vue/shared`) and Vue Router (`vue-router`). The dashboard bundles no font: it uses the system font.

`dist/THIRD_PARTY_NOTICES.txt` holds the name, version, license and license text of each of these packages. `pnpm run build` writes it from the packages that the bundle contains (the plugin `thirdPartyNotices()` in `vite.config.js`), so it stays current with `pnpm-lock.yaml`.
