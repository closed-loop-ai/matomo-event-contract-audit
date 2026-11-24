# Event Contract Audit for Matomo

## Description

A free, open-source command by ClosedLoop AI for comparing a tracking plan with one Matomo Events report. Supply exact category/action pairs and get JSON showing observed, unexpected, unobserved and indeterminate pairs, with native event-hit counts.

Version 0.1.0 requires Matomo 6.x and PHP 8.1 or newer. Integration tests passed on Matomo 6.0.0 (upstream commit b9c4e36f76c7b20e7947cf53b0762f37ba9c9af6) with PHP 8.4.28. The eight native tests cover real tracking and archives, exact labels and counts, invalid plans, empty reports, native site access, and truncated category/action reports. Other version combinations and WordPress have not been tested.

## Install

1. Extract the package so its directory is `plugins/EventContractAudit/` inside your Matomo installation.
2. From the Matomo directory, run `php console plugin:activate EventContractAudit`, or activate it in Matomo's Plugins administration.
3. Run `php console list --raw` and confirm `event-contract:audit` is listed. Read any activation messages; activation's exit status alone does not establish that Matomo loaded the plugin.

Use a staging installation before production. The package contains this plugin's complete source and license; Matomo itself is a separate dependency.

## Compare a tracking plan

Save a local JSON file:

```json
{
  "schemaVersion": 1,
  "pairs": [
    {"category": "Checkout", "action": "Continue"},
    {"category": "Catalog > Browse", "action": "Open/Details"}
  ]
}
```

Then run from your Matomo directory:

```sh
php console event-contract:audit \
  --idsite=1 \
  --period=day \
  --date=2026-01-15 \
  --plan=/absolute/path/events.json \
  --format=json
```

Choose your own site ID, date and file path. Use one resulting report period; selectors that expand into multiple reports are rejected. JSON is the only output format.

Categories and actions are exact logical display labels: case, punctuation, Unicode and whitespace matter. The command decodes Matomo's stored HTML label representation once and keeps your plan spelling unchanged. It does not compare the optional event name. Empty plans are valid; duplicate pairs, unsupported properties, invalid types and malformed JSON are rejected.

## Read the result

| Field | Meaning |
| --- | --- |
| `observedPairs` | Visible category/action pairs and Matomo event-hit counts in `events`, sorted by category and action. Counts are not unique visitors. |
| `expectedPairsObserved` | Planned pairs present in the visible report, in plan order. |
| `expectedPairsNotObserved` | Unseen planned pairs when no explicit summary or unavailable subtable was detected. |
| `expectedPairsIndeterminate` | Unseen planned pairs when detected incomplete coverage prevents a confident absence comparison. |
| `unexpectedPairsObserved` | Visible pairs absent from your plan. |
| `coverage` | `bounded` or `incomplete`, with reason codes and a short explanation. |

Coverage remains **bounded** even when no summary is visible: native archive ranking can discard identities. A detected category/action summary or unavailable action subtable makes coverage **incomplete**. In that case unseen expectations are indeterminate and `expectedPairsNotObserved` is empty; visible expected pairs remain observed. A normal empty report is bounded. Missing from a report does not establish broken tracking.

Exit status is 0 for a completed comparison, 2 for invalid plan/command input, and 1 for a native access/report failure. A completed comparison with missing or unexpected pairs still returns 0; inspect the JSON fields to enforce your own policy.

## Access and data handling

This is an administrator's local command. Matomo executes console commands with privileged installation authority. The comparison service also checks native view access for the selected site before reading any report, including empty-plan comparisons; it adds no HTTP endpoint or new user grant.

The plugin reads Matomo's aggregate Events reporting APIs. Ordinary report reads can create or update Matomo archives. It does not repair tracking, change your tracking configuration, add custom tables, or retain a separate result file. Reports read during a changing period are not a transactionally frozen snapshot.

The plugin adds no network client, telemetry, external account or ClosedLoop AI connection. Matomo and other installed plugins retain their own behavior. Event labels, counts, plan files and command output can still contain sensitive information. Redirected output is stored wherever you choose; protect and delete it according to your data policy.

## Support and removal

The complete source and setup notes are in the [project repository](https://github.com/closed-loop-ai/matomo-event-contract-audit). Report problems through its issue tracker, keeping private event data and credentials out of the report.

Deactivate EventContractAudit in Matomo's Plugins administration and remove its directory to remove the command. Existing Matomo events and archives remain.

## License

Copyright 2026 ClosedLoop Labs LLC. GNU General Public License version 3 or any later version, without warranty. See [LICENSE](LICENSE). Matomo is not bundled with this package.
