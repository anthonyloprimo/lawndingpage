# LawndingPage Module System

This directory contains pane modules used by the admin and public sites.
Each module is a folder with a manifest and render templates.

## Module Folder Layout

Each module lives at:

    admin/modules/<moduleId>/

Required files:
- `<moduleId>.json`: module manifest (schema below).
- `public.php`: public-facing pane markup.
- `admin.php`: admin-facing pane markup.

Optional files:
- `preview.<ext>`: preview image for the "Add Pane" module picker.
- Additional assets/scripts used only by the module.
- `IGNORE_ME.txt`: empty file to keep a module hidden while in-progress. Remove to enable the module.

## Manifest Schema (`<moduleId>.json`)

```json
{
  "id": "basicText",
  "name": "Basic Text Pane",
  "version": "1.0.0",
  "description": "Markdown-backed text pane.",
  "preview": "preview.png",
  "data_files": [
    { "type": "md", "pattern": "{paneId}.md" }
  ],
  "save_map": [
    { "key": "content", "type": "md", "file": "{paneId}.md" }
  ]
}
```

Field meanings:
- `id`: Must match the module folder name and manifest filename.
- `name`: Display name shown in the admin UI.
- `version`: Module version string (for future compatibility checks).
- `description`: Short summary used in module listings.
- `preview`: Optional filename for the preview image inside the module folder.
- `data_files`: Declares data file types and patterns used by pane instances.
- `save_map`: Declares which form inputs are saved by the "Save all changes" flow.
Migration note: pane migration resets pane fields. Copy any content you want to keep before running migration.

### `data_files`
Each entry declares a file type and a name pattern. The `{paneId}` token is replaced
with the pane instance id (camelCase). File paths resolve to `public/res/data/`.
Preview images are resolved to the module folder and served through `public/res/scr/module-preview.php`.

Example:

```json
"data_files": [
  { "type": "md", "pattern": "{paneId}.md" },
  { "type": "json", "pattern": "{paneId}.json" }
]
```

### `save_map`
Each entry maps a form key to a file type and filename pattern. The save script reads
`pane[<paneId>][<key>]` from the request payload and writes the value to the target file.

Example:

```json
"save_map": [
  { "key": "content", "type": "md", "file": "{paneId}.md" },
  { "key": "settings", "type": "json", "file": "{paneId}.json" }
]
```

## Pane Instance IDs and Data Files

Pane names are normalized into camelCase ids using alphanumeric characters only.
Whitespace separates words. Examples:
- "About" -> `about`
- "About LI Furs" -> `aboutLiFurs`
- "FAQ" -> `faq`

Pane instance ids drive the filenames for module data. If a pane is renamed,
its associated data files are renamed to match the new id.

## Discovery and Pane Management

Module manifests are discovered from this directory and exposed to the pane
management UI for adding/changing pane types. Pane instances live in
`public/res/data/panes.json` and reference a module id. If a pane references
a missing module, the admin UI warns and migration is blocked until it is restored.

## Rendering

Each module has two templates:
- `public.php` renders the public-facing pane.
- `admin.php` renders the admin editing interface.

The platform includes these files directly. Modules are responsible for any
custom markup and scripts. Use the shared data directory helpers for storage.

## Saving

The platform has a single "Save all changes" flow. Modules participate by:
1) Rendering inputs named `pane[<paneId>][<key>]`.
2) Declaring `save_map` entries in the manifest.

Immediate saves (e.g., file uploads) and custom save buttons are handled
by module code and any shared save helpers.
Module-specific CSS should be included inline in `public.php`/`admin.php` or stored in the module folder.

## Icons

Pane icons are configured per pane instance (not per module). Icons can be:
- SVG strings stored in `panes.json`, or
- Image files stored in `public/res/img/panes/`.

Unused icon files are removed when no panes reference them.

## Security

Module files are trusted PHP. Pane instance names are sanitized into safe ids.
Raw SVG is rejected if it contains `<script>` tags or inline `on*` event handlers.

## Example Module

A basic text module could use:
- `basicText.json` declaring an `md` data file and a `content` save_map key.
- `public.php` to render the markdown.
- `admin.php` to render a textarea named `pane[<paneId>][content]`.
## Template Module

Use the `_template` folder as a starting point for new modules. Remove `IGNORE_ME.txt` once the module is ready to be discovered.
