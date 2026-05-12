# Shared-Core Instance Template

This folder contains the thin launcher files and instance-local structure used by a shared-core deployment.

Expected target layout:

```text
instances/
    _core/
    example-instance/
        lp-instance.php
        public/
            index.php
            admin/index.php
            .htaccess
        data/
            public/
                res/
                    data/
                    img/
            admin/
            logs/
            state/
        modules/
```

Notes:

- `public/index.php` and `public/admin/index.php` are thin launchers into `_core`.
- Public instance-owned site content lives under `data/public/res/data` and `data/public/res/img`.
- `data/admin`, `data/logs`, and `data/state` hold private runtime files.
- Browser URLs remain `/res/data/...` and `/res/img/...`; Apache rewrites serve those requests from the instance data tree.
- The `.htaccess` file is a starting point for Apache-based shared-core routing and may need environment-specific adjustment.
