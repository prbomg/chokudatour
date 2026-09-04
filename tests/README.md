Run from this directory with Node.js 24 (the version used for verification):

```sh
npm install --ignore-scripts
npm test
```

The tests execute the actual PHP pages in PHP 8.4 WebAssembly with an in-memory SQLite fixture. No connection to the application database is made, and Telegram calls are stubbed. The adapter translates `SHOW COLUMNS`, `GROUP_CONCAT` separators and table creation syntax; it is not a substitute for a MySQL staging check.

Coverage: all PHP syntax; filtered dashboard income/expenses/profit including empty results, cancellations, multiple expenses and events without bookings; consistent participant counts in the event card, calendar, calendar feed, list and analytics; archived AJAX responses; real create/update handlers in all three booking forms, with both current and legacy schemas; preservation of historical conflicts on reads.

Set `CRM_TEST_HTML=/absolute/path/index.html` to save the rendered dashboard with synthetic data for mobile browser checks. Check 320, 390, 768 and 1024 px, including editing and archived rows. Do not serve the repository itself with its real database configuration.

Legacy count policy: all screens preserve the event card's precedence (`places`, then `seats`, then 1). Disagreements are displayed in the event card and participant list. Confirm the correct count in either edit form; saving writes both existing columns together. No automatic historical migration is performed.
