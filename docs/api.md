# Dashboard HTTP API

These paths are relative to `clockwerk.path`, `/clockwerk` by default. With `clockwerk.path` set to `/`, the state endpoint is `/api`.

| Method | Path | Result |
| --- | --- | --- |
| `GET` | `/clockwerk` | The HTML page, with inline CSS and JavaScript |
| `GET` | `/clockwerk/api` | Recorded activity and current queue, schedule and worker state |
| `POST` | `/clockwerk/api/jobs/retry` | Put failed jobs back on their queues |
| `POST` | `/clockwerk/api/jobs/forget` | Delete failed jobs |

The two job actions are registered only when a queue store is bound before `ClockwerkPlugin`. Add `QueuePlugin` before it when using the framework queue. A normal `GET` cannot retry or forget a job.

## Authentication

Set `clockwerk.token` and send `Authorization: Bearer <token>`. API requests can also send `?token=<token>`; a Bearer header takes precedence. An absent or incorrect credential returns `401` with `WWW-Authenticate: Bearer`. Without a configured token the API returns `403`. These responses are not cached and use the application's error renderer; Crab's default JSON format is `{"error":{"status":401,"message":"..."}}` when the request accepts JSON.

The HTML page contains no application data and can load without the token. It asks for the token, keeps it in session storage for the browser tab, and sends it in the API request header. A token in the initial page address is removed with `history.replaceState`. Enter the token on the page to keep it out of access logs, and use HTTPS for remote access.

Setting `clockwerk.middleware` replaces the token guard on every API route and also guards the HTML page. Those middleware must authenticate the caller and authorize access to the dashboard, including its job actions. The page blocks framing and sends `Referrer-Policy: no-referrer`.

## Reading state

```sh
curl -H "Authorization: Bearer $CLOCKWERK_TOKEN" \
     -H 'Accept: application/json' \
     'https://app.example/clockwerk/api?minutes=60&list=pending'
```

`minutes` is an integer, defaults to 60, and is clamped to 5–1440. The range includes the current minute: `since` is the start of the first included minute. Invalid parameter types return `422`. `list` is optional and takes only `pending`, `processing`, `delayed`, or the empty string; other values return `422`.

| Field | Meaning |
| --- | --- |
| `app`, `now`, `minutes`, `since` | Display name, current Unix timestamp, effective range and its start |
| `requests` | Request totals, errors, average and maximum milliseconds, minute series, and up to 300 route totals |
| `jobs` | Job-try totals and minute series, plus queue information described below |
| `tasks` | Whether a schedule is installed and its declared tasks with counts in the range |
| `workers` | Workers whose heartbeat is no more than three write intervals plus up to three seconds old |
| `exceptions` | Up to 200 recorded exceptions in the range, newest first |

The range filters recorded counts and events. Live queue contents, failed jobs, worker heartbeats and declared schedules are independent of it. Reads are observations taken across several queries, not a transactional snapshot.

Recorded metric names are truncated to 160 characters before aggregation. Names that differ only after that point merge, and a longer declared task name does not match its recorded totals. Use names within that limit; fixing existing data requires a storage migration that preserves full names.

`jobs.installed` says whether the queue is available. `supervisors` describes configured queue assignments and worker limits, including the framework's default `queue` group when no supervisors are declared. `queues` includes configured queues, queues holding jobs, and queues with recorded attempts in the range. Each gives pending, delayed and processing counts, completed and failed counts in the range, retrying attempts, average duration, and the longest pending wait.

`jobs.list` is populated only when `list` names a state. It contains at most 100 jobs across the queues: pending jobs by availability time, delayed jobs by their next due time, and processing jobs by reservation time, earliest first. `jobs.list_state` says which list was requested. Reading a list does not reserve or change jobs. `reserved_at` is the last reservation or lease renewal, so its age is not a job's full running time.

`jobs.recent` contains up to 100 recorded tries with an outcome of `completed`, `retrying`, or `failed`. Each worker retains at most 25 individual tries between writes; aggregate counts still include every try. `jobs.failed` contains up to 200 failed jobs in the queue store's order, reversed for display. `failed_total` counts the jobs returned; when `failed_more` is true this is a lower bound, not the backlog's exact size. Failed jobs remain until retried or forgotten, regardless of the selected range or `clockwerk.keep`.

Secret job fields are replaced recursively with `(hidden)` according to `clockwerk.hide`. Known secret values of at least four bytes are removed from recorded job errors, including raw and form URL-encoded forms. Error redaction is best effort; keep the dashboard restricted to trusted operators. Exception traces contain no call arguments.

Each task includes `one_server`, `without_overlapping`, `when`, `next`, and `next_error`. Interval tasks have no predicted `next` because their timing depends on the scheduler's start. A cron expression with no possible next date has `next: null` and a diagnostic `next_error`; it does not prevent the rest of the state from loading. Counts and last-run times are aggregated by minute.

## Retrying and forgetting

```sh
curl -X POST -H "Authorization: Bearer $CLOCKWERK_TOKEN" \
     -H 'Accept: application/json' -H 'Content-Type: application/json' \
     -d '{"id":"123"}' \
     'https://app.example/clockwerk/api/jobs/retry'
```

The body must contain a string `id`. A missing, null, numeric, boolean or array id returns `422`; an id in the query string does not supply the body field. Malformed JSON returns `400`.

- Retry returns `{"retried":1}` and resets a failed job's attempts, error and reservation so it is available again. It does not run the job inside the HTTP request.
- Forget returns `{"forgotten":1}` and permanently removes a failed job.
- `{"id":"all"}` affects every failed job, including jobs outside the 200 shown on the page. The page asks for confirmation before sending either bulk action.
- An id that is absent or no longer failed returns a count of zero. Pending and processing jobs are not affected.

Both actions require `Content-Type: application/json`, optionally with parameters such as `charset=utf-8`. The browser's `Sec-Fetch-Site` must not indicate another site or origin. Without that header, an `Origin` must match the public application's scheme, host and effective port. Set `app.url` behind an HTTPS proxy for browsers without Fetch Metadata; arbitrary forwarding headers are not trusted. Command-line requests can omit these browser headers but still require authentication. Refused origins or media types return `403` before the queue is changed.

Use configured supervisors for accurate worker assignments on the dashboard. Heartbeats currently report group names, not the effective queues supplied by a separate `queue:work --queue=...` command.
