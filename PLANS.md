# Project Plans

## Current State

The project already includes the core `partidos` and `quinielas` CRUD flows, plus a family dashboard view in `quiniela_ohana.html`.

The dashboard currently shows:

- Accumulated points ranking.
- Accumulated exact-hit ranking.
- Summary stats for leaders, exact hits, closed matches, and total predictions.
- Match cards ordered from newest to oldest.
- Pagination for match cards, loading the 10 most recent first and revealing 10 more with `Mostrar mas`.

There is also a bot-facing endpoint available in `partidos_bot_api.php` for batch match creation and result updates.

## Data Relationships

The quinielas workflow links these tables:

- `partidos`: match schedule, teams, generated `id_partido`, and final results.
- `participantes`: people or users submitting predictions.
- `quinielas`: submitted predictions per participant and match.

The `quinielas` table references both a partido and a participante, then stores predicted scores and calculated points.

## Active Dashboard Direction

The current dashboard is built from the existing CRUD APIs and focuses on:

- Standings by accumulated points.
- Exact-hit tracking by participant.
- Per-match breakdowns of predictions and points awarded.

Any future dashboard work should preserve the current mobile-friendly behavior:

- Adaptive chart scales.
- Horizontal scrolling for dense ranking charts on small screens.
- Paginated match history instead of rendering every card at once.

## Next Useful Improvements

Potential next steps:

- Add filters for open vs closed matches in the match history.
- Add date-range or stage filters for both ranking boards.
- Surface participant trend changes between matches.
- Introduce security for `partidos_bot_api.php` before broader automation use.
- Consider a dedicated dashboard endpoint if the UI needs heavier aggregation or better performance.
