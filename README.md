# AdminKit

`admin-kit` is a private, developer-first Laravel admin scaffolding installer. It is designed to save you from rebuilding the exact same administrative foundation in every new project by copying real, production-ready code directly into your application (`app/`, `resources/`, `routes/`, `config/`, and `database/`).

After installation, your application fully owns and controls the code. There are no runtime package dependencies, no vendor view namespaces (`admin::`), no module registry abstractions, and no vendor publishing layers. The package is only required during scaffolding (`composer require --dev`).

---

## Features

- **Developer-Owned Code**: All code is installed as first-class application files. Edit, customize, or remove anything freely.
- **Strict Clean Architecture**: Every module strictly follows Controller -> Service -> Repository layers. Repositories return Eloquent `Builder` instances for DataTables rather than executing eager queries (`->get()`).
- **ID-Less Security Routes**: Routes avoid route parameter IDs (`/roles/edit`, `/roles/update`, `/roles/delete`). Entity IDs travel securely inside request payloads and authorization checks are enforced inside dedicated Form Request classes.
- **Idempotent Scaffolding**: Re-running the installer will never duplicate migrations, append duplicate `.env` keys, or corrupt provider registrations.
- **Safe by Default**: Existing files are never overwritten without the explicit `--force` flag.
- **Drift Detection (`admin:diff`)**: An `admin-kit.lock.json` file records installed modules and SHA-1 hashes of installed stubs. Run `php artisan admin:diff` at any time to identify modified, missing, or upstream-changed files.
- **5 Curated Color Themes**: High-contrast, modern UI presets built on OKLCH color tokens and Tailwind CSS.

---

## Requirements

- PHP 8.2 or higher (fully tested on PHP 8.5)
- Laravel 11.x or 12.x
- Composer 2.x

---

## Installation in a New Project

Add the package repository to your application's `composer.json` (via local path or your private Git URL):

```json
"repositories": [
    {
        "type": "path",
        "url": "../admin-kit"
    }
]
```

Require `admin-kit` as a development dependency:

```bash
composer require hj4104/admin-kit:@dev --dev
```

---

## Usage

### 1. Inspect Available Modules and Themes

```bash
php artisan admin:install --list
```

### 2. Install Modules and Choose a Theme

To install specific modules with a theme:

```bash
php artisan admin:install --modules=core,rbac,settings,uploads --theme=slate-indigo
```

To install all modules:

```bash
php artisan admin:install --modules=core,rbac,settings,settings_pdfs,uploads,banners,notifications,seo_drawer,document_studio --theme=slate-indigo
```

### 3. Overwriting Existing Files

By default, existing application files are safely preserved and reported as `skipped`. Pass `--force` to overwrite:

```bash
php artisan admin:install --modules=rbac --force
```

### 4. Detecting Code Drift

Compare your application's files against the recorded lockfile and stub definitions:

```bash
php artisan admin:diff
```

To view every inspected file with its status:

```bash
php artisan admin:diff -v
```

Possible statuses:
- `clean`: Matches the installed stub hash.
- `locally modified`: You modified the file after installation.
- `missing`: The file was deleted or moved.
- `upstream modified`: The stub in `admin-kit` changed since you installed it.

---

## Available Modules

| Module | Description | Dependencies |
| :--- | :--- | :--- |
| `core` | Base admin layout, sidebar navigation, trilingual auto-translation (`en`, `ru`, `kk`), base `AdminRequest`, and shared Blade components. | None (Always installed) |
| `rbac` | Role-based access control with Spatie permissions, super-admin bypass gate, permission seeder, and DataTables user/role management. | `core` |
| `settings` | Global site settings: contact info, STD phone selector, social links, SEO, notification audio tone, and maintenance mode toggle. | `core` |
| `settings_pdfs` | PDF uploader and downloadable brochures modal for the Site Settings panel. | `settings`, `uploads` |
| `uploads` | Direct and chunked staged file uploader, SHA-256 integrity verification, image processing fallback, and scheduled cleanup command (`uploads:clean`). | `core` |
| `banners` | Hero banner management for target pages with trilingual text and direct/chunked image uploads. | `core`, `uploads` |
| `notifications` | Slide-over notification drawer with Pusher broadcasting, Web Audio synthesizer chime fallback, unread badges, and bulk actions. | `core` |
| `seo_drawer` | Slide-over SEO drawer with multilingual Google SERP previews, character countdowns, auto-generation from content, and metadata extractor service. | `core` |
| `document_studio` | Dual-mode TipTap ProseMirror document studio (Visual, Code, Split), table management tools, floating bubble menu, semantic HTML cleaner, and Quill fallback. | `core` |

---

## Theme Presets

All themes are defined using OKLCH CSS color variables compatible with Tailwind CSS:

1. `emerald-orange` (Satguru theme: deep emerald primary with vibrant orange accents)
2. `sovereign-silk-road` (STTS-KAZ theme: sovereign gold and silk road burgundy)
3. `gold-burgundy` (Liquor Junction theme: warm gold and rich burgundy)
4. `slate-indigo` (Modern SaaS theme: crisp slate with energetic indigo)
5. `zinc-emerald` (Minimalist tech theme: high-contrast monochrome zinc with emerald accents)

The selected theme is written to `resources/css/admin-theme.css`. Simply import it into your `resources/css/app.css`:

```css
@import "./admin-theme.css";
```

---

## Creating a New Module

1. Create a directory: `stubs/modules/<module_name>/`.
2. Place your source files under `stubs/modules/<module_name>/files/`. Every file must end with the `.stub` extension.
3. If the module includes migrations, place them in `files/database/migrations/<name>.php.stub`.
4. Create `stubs/modules/<module_name>/manifest.json` following this schema:

```json
{
    "name": "example_module",
    "description": "Short summary of what this module provides.",
    "requires": ["core"],
    "files": {
        "app/Services/ExampleService.php.stub": "app/Services/ExampleService.php",
        "app/Http/Controllers/Admin/ExampleController.php.stub": "app/Http/Controllers/Admin/ExampleController.php"
    },
    "migrations": {
        "database/migrations/create_examples_table.php.stub": "create_examples_table"
    },
    "env": {
        "ADMIN_EXAMPLE_FEATURE": "true"
    },
    "providers": [
        "App\\Providers\\AdminExampleServiceProvider"
    ],
    "composer": [
        "vendor/package"
    ],
    "npm": [
        "package-name"
    ],
    "notes": [
        "Run php artisan migrate to create the examples table."
    ]
}
```

---

## Code Quality and Standards

- **Laravel Pint**: Run `vendor/bin/pint --dirty --format agent` to maintain strict PSR-12 and Laravel style guidelines.
- **Unit Testing**: Run `vendor/bin/phpunit` in the `admin-kit` package to execute the automated installer test suite.
