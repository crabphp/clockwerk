# Changelog

Crab Clockwerk follows [semantic versioning](https://semver.org). Before 1.0, a minor version may break what an application writes; the entry says what to change.

## 0.2.4 — 2026-11-25

- Show workers replaced by fresh ones: a Replaced card on the workers page counts them in the range by reason, and a table lists each with its group, host, the memory it held and what it had handled. A worker records this as it stops after passing its limits, `server.max_requests` or `server.max_memory` in Crab 0.7.6 and later. With an older framework nothing is recorded. The API gains `replaced`.
- The host count moves into the Processes card's footnote.

## 0.2.3 — 2026-11-23

- Make scheduled tasks easy to scan: common cron expressions in words, the next run with its date, the tasks that need a look first, and summary cards for tasks, failures, runs and the next task due.
- Show workers as one table grouped by process group, with summary cards for processes, hosts, memory and jobs handled.
- Soften chips into pills, give tab counts and actions a clear shape, put headline numbers in cards, and drop the repeated heading on the exceptions page.
- On phones, hide secondary table columns instead of scrolling sideways.

## 0.2.2 — 2026-11-23

- Center the dashboard workspace with horizontal navigation, clearer metric and table typography, and links from overview warnings to the relevant detail pages.
- Follow the system color scheme by default, or choose a persistent light or dark theme.

## 0.2.1 — 2026-11-12

- Requires `crabphp/framework` ^0.7.1. The dashboard's middleware (`Gate`, `OwnPage`, `RecordRequests`) implement Crab's own `Middleware::handle(Request $request, Handler $next)`, now that the framework has dropped PSR-15.

## 0.2.0 — 2026-11-12

- Requires `crabphp/framework` ^0.7, for its 0.7.0 release.

## 0.1.1 — 2026-11-07

- Check a job action's full browser origin and JSON media type. Use the framework's error responses, prevent dashboard framing, and suppress referrers containing a bootstrap token.
- Keep each worker to one metrics write at a time, so a slow store cannot overwrite newer counts or accumulate heartbeat tasks. Use the application's clock for recording, retrying and schedule previews.
- Keep slow API polling from superseding every answer. Show unserved queues and impossible cron dates, include the default queue supervisor and overlap protection, and handle worker names such as `constructor`.
- Match encoded static routes correctly, record application routes beside the dashboard, and group invented HTTP methods under `OTHER (no route)`.
- Redact complete overlapping secret values, including form URL encoding, and use the framework's trace formatter without call arguments. Label a processing job's renewed reservation separately from its running time. Validate table prefixes and `clockwerk:status --minutes` before using them.
- Update the page, installation examples and API documentation for framework 0.6. CI runs the PHP and JavaScript tests on PHP 8.4 and 8.5 against framework v0.6.1 and main.

## 0.1.0 — 2026-11-05

- **First release,** for `crabphp/framework` 0.6: a dashboard for an application on the Crab framework: requests, the queue, scheduled tasks, workers and exceptions.
