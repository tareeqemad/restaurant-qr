# Operational repair plan

This plan tracks the issues reported after the first month of restaurant use.
Local fixes require regression checks before customer deployment. Production
response times must be measured on the restaurant's server and devices.

## 1. Trustworthy financial actions

- Prevent cashier background refresh from cancelling a selected bill.
- Reject malformed responses; never acknowledge payment without confirmation.
- Make accountant retries safe after validation/business failures and retain
  protection against concurrent or repeated successful posting.
- Keep invoice, payment, permission and branch protections intact.

## 2. Kitchen and bar operations

- Make partly ready orders visible in the ready view.
- Preserve table labels and distinguish preparation variants and exclusions.
- Match undo controls to server rules; act only on visible station items.
- Report server freshness and actual sound readiness accurately.
- Return updated station data with actions/changed pulses and simplify text.

## 3. Navigation and accountant performance

- Reuse permission lookups within one request and remove repeated schema scans.
- Fetch the selected cashier workspace without rebuilding the full queue.
- Apply selected-session filters before queue limits.
- Replace growing historical loads with bounded or aggregate queries.
- Avoid running a closing checklist per displayed period.
- Keep report dates/search consistent across navigation and exports.
- Use internal navigation for internal links.

## 4. Everyday interaction

- Remove artificial waiter submit delays; preserve success/warning feedback.
- Add an ordinary item directly; open required choices before adding.
- Put optional notes, staff meals and help behind secondary controls.
- Reduce cashier issue/payment steps without bypassing server checks.
- Persist the exact unresolved waiter submission across reloads, scope it to
  the operator/branch/table/session, and replay its durable server receipt.
- Keep edits locked while a submission has an unknown outcome; unlock only
  after a definite rejection or clear after acknowledged success.
- Give the teleported waiter cart its own theme variables so the header and
  submit button remain visible; raise small accounting report text.
- Space notification polling after completion, pause hidden/offline tabs,
  and reject aborted or obsolete responses without overlapping requests.

## Verification and release

- Run meaningful PHP regressions against dedicated disposable databases.
- Run JavaScript race, response and board-filter tests.
- Build the frontend and check operational flows in a local browser.
- Review the combined diff and fix newly discovered related issues.
- Record validation results here; apply additive migrations and the built
  frontend through the existing backed-up deployment process.
- Before customer acceptance, measure page opening and order-to-station timing
  with the actual waiter, kitchen/bar, cashier and accountant during a busy shift.

## Confirmed local checks

- Navigation and first-run checks: 10 PHP tests passed, 175 assertions.
  Repeated permission checks use 3 queries per request; the installed setup
  guard uses 2 existence queries without schema inspection.
- Cashier: 86 PHP cases passed across the focused/broader runs and reruns.
- Kitchen board: 21 PHP cases passed, 292 assertions; KDS workflow and
  cancellation-disposition cases also passed in the initial combined run.
- Accounting: 47 PHP cases passed across the broader run and fixture rerun.
- Waiter: 24 PHP cases passed, 235 assertions; final receipt replay/rollback
  cases rerun after the post-commit refresh adjustment (2 passed).
- Cross-module integration: 102 PHP cases passed, 668 assertions, covering
  role permissions, branch isolation, floor/table screens and live refresh.
- Frontend: 35 JavaScript regression tests passed.
- Final production frontend build and PHP formatting check passed.
- Browser checks passed on desktop and tablet-sized viewports: quick-add,
  separate customization, visible cart controls, and acknowledged submission.
- The submitted order reached the correct kitchen/bar stations. A partly
  ready order showed only its ready line in the ready view, while its
  "without onion" variant remained separate. Station actions completed.
- Invoice issuance opened payment directly. A demo cash payment was recorded
  once; paid amount became 12.50 and remaining amount zero. The selected bill
  remained visible and the table stayed occupied until service completion.
- Report dates carried from September into the balance sheet with its
  September 30 cutoff. The zero-result report displayed neutral wording.
  No console errors were captured during the final browser walkthrough.

## Release and field acceptance

- Three additive migrations create accounting-command and waiter-submission
  receipts and add the recipe removal flag. Run the normal backed-up deployment/migration workflow before
  serving the new frontend. Do not run demo seeders on customer data.
- Deploy between shifts, after existing in-progress submissions are resolved;
  refresh staff devices together so older clients are not left active.
- Review legacy unscoped waiter drafts before rollout. They remain stored
  but are not automatically imported into another operator's scoped cart.
- Kitchen/bar cross-device polling is still every 5 seconds. The new action
  response updates the acting device immediately; this is not a push system.
- Production network/server timings and a busy-shift staff acceptance check
  remain necessary. Local tests do not establish a production speedup ratio.
- No customer database migration or production deployment was performed here.

### Busy-shift acceptance checklist

- Record actual page-opening and order-to-station timings before and after
  rollout on the restaurant server, desktop and tablets.
- Have staff complete mixed kitchen/bar orders with exclusions, partial
  readiness, delivery and a further round on the same table.
- Verify an interrupted submission/retry creates one order and retains the
  original details; verify payment retries create one payment.
- Have the cashier check partial/full collection and printing, then have the
  accountant reconcile the same period and its filtered export.
- Record any remaining delays or confusing steps with the role, screen,
  action and time, so each follow-up has a reproducible case.

Status: implementation and local regression/browser checks complete.
Customer deployment, production timing measurements and staff acceptance
remain pending.

## Dar Al Sarw trial identity and dataset

- Replaced the default restaurant with the fictional Dar Al Sarw identity:
  terracotta, warm ivory and dark ink, local Cairo fonts, a new SVG mark and
  bundled menu illustrations. Applied the identity to the guest, waiter,
  kitchen/bar, cashier, accounting and administration interfaces.
- The dedicated demo has 24 dishes, 12 tables, two stations and nine role
  accounts. Recipes and bounded stock support real trial orders. Historical
  sales and accounting transactions are not fabricated by the seeder.
- The local trial runs on a separate database. The previous restaurant
  database was not erased, migrated or seeded.
- Demo initialization checks the target database, requires a private password
  and preserves changed values on reruns. Production initialization creates
  reference data only. See `demo-dar-al-sarw.md` for setup and handover.
- The final accounting walkthrough exposed four inactive default posting
  accounts and no current fiscal period in the demo. Demo initialization now
  supplies missing role mappings and empty current-year monthly periods,
  preserving custom mappings and closed periods without inventing postings.
- Passed the focused seeder/production/first-run regressions, 35 JavaScript
  cases, the final frontend build and PHP formatting checks.
- Browser verification covered the menu at 390px and desktop width, the waiter
  cart and submission, correct food/drink station routing, kitchen contrast,
  and the cashier's 41.00 ILS demo account. No console errors were captured in
  the final cashier view. Prices are illustrative, not current market quotes.
- Customer packaging excludes local secrets, tool folders, runtime storage,
  old uploaded media and legacy logo assets; it includes the new built assets,
  fonts and illustrations. Hosting and restaurant field acceptance remain
  separate from these local checks.

## Complete interface redesign verification — 2026-10-11

- Reworked all 127 Vue pages and their shared foundations: 213 Vue files
  compile successfully, with no missing or unclaimed pages in the inventory.
  `ui-redesign-audit.json` records each file and distinguishes source checks
  from the pages actually exercised in a browser.
- Replaced the administration shell with a searchable permission-aware sidebar,
  quiet utility bar, consistent page headings, readable forms and tables,
  and a keyboard-accessible drawer for tablets and phones. The palette uses
  terracotta, warm ivory and dark ink with locally bundled Cairo fonts.
- Reorganized kitchen and bar work into new / preparing / ready lanes; kept
  the station's own items and actions independent. The waiter has a persistent
  current-order panel on wider screens, the cashier has a queue and checkout
  workspace, and accounting has consistent filters, summaries and ledgers.
- Simplified guest and waiter customization: ingredient details and optional
  removals are collapsed, while required choices and allergens stay visible.
  Dialog focus, Escape, focus return and nested body-scroll locks were checked.
- Corrected legacy stylesheet conflicts found in actual browser review,
  including low-contrast station badges, inventory metrics, mobile navigation
  labels and notification placement. Audited compiled scoped CSS to ensure
  inventory navigation styles no longer escape into the whole admin shell.
- Added a separate recipe `is_removable` flag, defaulting to true for existing
  recipes. The demo's sealed water bottle cannot be removed from itself.
  Customer and waiter payloads and order validation use the same rule; recipe
  quantities, optional costing and stock tracking retain their meaning.
- Verification passed: 148 backend tests / 3,859 assertions, 41 JavaScript
  tests, 2 filesystem-only release tests / 109 assertions, and the production
  frontend build. Backend tests use a separate test database. The new additive
  recipe migration and one guarded water flag correction were applied only to
  the dedicated demo; the original restaurant database was not used.
- Actual browser review covered desktop (1280px), tablet (1024px), and phone
  (390px) layouts across dashboard, navigation, inventory, purchasing,
  accounting, reports, settings, tables, waiter, guest, cashier and station
  screens. The bar's test item moved through all three stages while the food
  remained in the kitchen. Local guest/waiter draft checks were cleared.
- This is source coverage for every page plus representative browser checks,
  not a claim that every permission, data state, printer, browser engine or
  real busy shift has been exercised. Production timing and staff acceptance
  remain part of the field trial described above.
