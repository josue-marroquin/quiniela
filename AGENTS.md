# Repository Guidelines

## Project Structure & Module Organization

This repository is a small PHP/MySQL CRUD for managing partidos.

- `partidos.html`: browser UI, styles, and client-side JavaScript. It calls the PHP API with `fetch`.
- `partidos_api.php`: backend API for CRUD operations against the MySQL/MariaDB table `partidos` or `pardidos`.
- `db.json`: local database connection settings used by `partidos_api.php`.

There are currently no dedicated `tests/`, `assets/`, or build directories. Keep new files in the root only while the project remains this small; introduce folders once there are multiple files of the same kind.

## Build, Test, and Development Commands

Run the project through a PHP-capable server. Opening `partidos.html` with `file://` will not work because it must call `partidos_api.php`.

```bash
php -S localhost:8000
```

Starts a local PHP server from the repository root. Then visit `http://localhost:8000/partidos.html`.

```bash
php -l partidos_api.php
```

Checks PHP syntax when PHP is installed.

```bash
python3 -m json.tool db.json
```

Validates that `db.json` remains valid JSON.

## Coding Style & Naming Conventions

Use 2-space indentation in HTML, CSS, and JavaScript. Use 4-space indentation in PHP. Keep JavaScript identifiers in `camelCase` and database fields in the existing `snake_case` style, such as `fecha_hora`, `id_partido`, `result_eq1`, and `result_eq2`.

Prefer clear, small functions over inline logic. Keep API responses JSON-only and return useful `error` messages for failures. Avoid adding dependencies unless they remove real complexity.

## Testing Guidelines

No automated test suite exists yet. Before committing, manually verify:

- The page loads through a PHP server.
- `GET`, `POST`, `PUT`, and `DELETE` work against the configured database.
- `id_partido` is generated as `{id}_{equipo1}_{equipo2}_{yyyymmdd}`.
- Empty result fields are stored as `NULL`.

When tests are added, place them under `tests/` and name them after the behavior being verified, for example `partidos_api_create_test.php`.

## Commit & Pull Request Guidelines

The current history uses concise, imperative commit messages, for example `Initial partidos CRUD`. Continue with short messages such as `Add partido validation` or `Fix result update handling`.

Pull requests should include a brief description, database/schema impact, manual test notes, and screenshots for UI changes. Mention any required `db.json` or server configuration changes explicitly.

## Security & Configuration Tips

Do not commit production credentials. `db.json` currently contains local connection details; replace secrets with environment-specific values before sharing beyond a trusted dev setup. Keep SQL parameterized with prepared statements.
