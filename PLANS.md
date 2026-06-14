# Project Plans

## Next Milestone: Quinielas CRUD

The next planned feature is a CRUD interface for `quinielas`. This will build on the current `partidos` CRUD and prepare the data model for dashboard views.

## Data Relationships

The quinielas workflow should link these tables:

- `partidos`: match schedule, teams, generated `id_partido`, and final results.
- `participantes`: people or users submitting predictions.
- `quinielas`: submitted predictions per participant and match.

The `quinielas` table should reference both a partido and a participante, then store predicted scores and any calculated result fields needed later.

## Implementation Direction

Start by verifying the database schema for `participantes` and `quinielas`. After that, create a CRUD similar to `partidos.html` and `partidos_api.php`, using the same API pattern and JSON responses.

Expected next files may include:

- `quinielas.html`
- `quinielas_api.php`
- Optional shared helpers if duplicated API logic becomes significant.

## Dashboard Goal

After the three core areas are connected, create a dashboard that summarizes standings, participant points, match results, and prediction accuracy. The dashboard should consume the existing CRUD APIs or a dedicated dashboard endpoint if aggregate queries become complex.

When ready to build the dashboard, start from this query shape:

```sql
SELECT
    p.nombre,
    q.result_eq1,
    q.result_eq2,
    q.puntos
FROM quinielas AS q
JOIN participantes AS p
    ON p.id = q.participante
GROUP BY q.id_partido;
```

Before implementation, confirm whether the dashboard should show one row per participant, one grouped summary per partido, or both. The final query may need aggregation depending on that decision.


This other query might also be useful
```
SELECT
    CONCAT(prts.equipo1, ' vs ', prts.equipo2) as partido,
    prts.result_eq1,
    prts.result_eq2,
    p.nombre,
    q.result_eq1,
    q.result_eq2,
    q.puntos
FROM quinielas AS q
JOIN participantes AS p
    ON p.id = q.participante
JOIN partidos AS prts
	ON q.id_partido = prts.id_partido
GROUP BY q.id_partido;
```