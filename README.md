# V-huset Medlemmer

WordPress plugin for Værkstedshuset i Hannovergade. Spec: https://claude.ai/artifact/LAtPZ6e5Ho3ipGycQ6d7mu

The plugin itself is in `vhus-medlemmer/` (zip that folder to install).

## Status

| Phase | Content | Status |
| --- | --- | --- |
| 1. Core | Tables, roles, settings, Excel import with dry run, member and workshop screens | Done |
| 2. Sign-up and waiting lists | Public form, contact approval, e-mails, ready-to-invoice list | Done |
| 3. Dinero sync | Contact linking, nightly invoice sync, paid status | Done (tested against a mock; needs real credentials) |
| 4. Gate queue | Gate actions, EasyAccess import file, XML match | Done (XML format guessed; check with a real export) |
| 5. GDPR and polish | Retention jobs, privacy notice, audit log, user guides | Done |

## Layout

```
vhus-medlemmer/
  vhus-medlemmer.php        bootstrap
  uninstall.php             removes roles; keeps data unless VHUS_DELETE_DATA_ON_UNINSTALL
  includes/
    class-db.php            table names + schema (dbDelta)
    class-installer.php     activation, upgrades, seed workshops (AT FX GS MC TX TM)
    class-roles.php         roles, capabilities, workshop scoping
    class-settings.php      fees, dates, group name, retention (option vhus_settings)
    class-util.php          phone/postcode cleaning, membership-year maths
    class-members.php       members, seats, periods, keys
    class-workshops.php     workshops
    class-log.php           audit log
    class-xlsx-reader.php   .xlsx/.csv reader, no Composer
    class-importer.php      dry-run plan + commit
    class-flow.php          sign-up, approve, decline, invoiced (business rules)
    class-mailer.php        templated plain-text e-mails
    class-signup.php        public form, shortcode [vhus_tilmelding]
    class-dinero-client.php read-only Dinero API client (token cache, paging, 429/401 handling)
    class-dinero-sync.php   nightly sync: contact linking, invoice → period matching, paid status
    class-gate.php          EasyAccess action queue, event rules, reminders, access groups
    class-easyaccess.php    tolerant reader for the EasyAccess XML user export + phone matching
    class-xlsx-writer.php   .xlsx writer for the EasyAccess import file (all cells as text)
    class-privacy.php       retention job, member erasure, WP privacy exporter/eraser, policy text
  public/signup.css         form layout (inherits theme styles)
  admin/
    class-admin.php         menus, screens, admin-post handlers
    class-members-table.php WP_List_Table
    class-queues.php        Ventelister (contacts) and Fakturering (cashier)
    class-dinero-page.php   Dinero screen: status, sync now, product mapping, manual linking, import check
    class-gate-page.php     Port screen: open actions, import-file download, done/undo, XML comparison
    class-tools-pages.php   Persondata (retention), Log (audit trail), Vejledning (guide per role), member delete
```

## Sign-up page

Put the shortcode `[vhus_tilmelding]` on a page, e.g. v-hus.dk/tilmelding. Fields are prefixed `vhus_`
because `name` and similar are WordPress query vars. Spam protection: honeypot, signed minimum fill time
(3 s), max 5 sign-ups per IP per hour. A sign-up with an existing e-mail only adds waiting places; it never
changes the stored details.

## Flow rules

- Approve (Ventelister) → seat approved, current-year period created as "Klar til fakturering" with
  amounts frozen at approval: full or half fee by date, plus key fee for people with no earlier period or key.
  A member who already has this year's period gets no second invoice.
- Approve/decline from Ventelister sends e-mails; status changes on the member page are silent corrections.
- Cashier marks periods invoiced (and can undo). "Paid" will come only from the Dinero sync.

## Dinero setup

Credentials go in `wp-config.php` only (never in the repo or the database):

```php
define( 'VHUS_DINERO_CLIENT_ID', '...' );
define( 'VHUS_DINERO_CLIENT_SECRET', '...' );
define( 'VHUS_DINERO_API_KEY', '...' );  // created in V-huset's Dinero organisation
define( 'VHUS_DINERO_ORG_ID', '...' );
```

Then open V-huset → Dinero, choose which Dinero products are the full fee, half fee and key fee
(until then, lines are matched on amount: 1000 / 500 / 200), and press "Synkronisér nu".
It also runs nightly at 03:00 via WP-cron; on a low-traffic site, point a real cron job at `wp-cron.php`.

Sync rules:

- Read-only. Up to 50 requests per run (Dinero allows 60/min); HTTP 429 stops the run, the next run continues.
- Members are linked to Dinero contacts by e-mail; the rest are listed for manual linking.
- `GET invoices?changesSince=` since the last complete run (first run: 3 months before the membership year).
  Lines are read once per invoice with `GET invoices/{guid}`.
- An invoice pays, in order: the period already linked to it; the member's oldest open period; the period for
  its membership year (invoices dated from `renewal_from`, default 1 March, count for the next year); else a new period.
- Periods only move forward. Paid is never undone; an imported period only changes when Dinero shows it paid.
  Imported periods Dinero hasn't confirmed are listed for the cashier, who can mark them "ikke betalt".
- A deleted invoice puts an unpaid period back on the cashier's list.
- Changing fees, dates or the product mapping re-reads invoices from the start.
- Two failures in a row e-mail the administrator. A failed run changes nothing.
- Hooks for Phase 4: `vhus_period_paid( $period_id, $member_id )`, `vhus_key_fee_paid( $member_id, $invoice_guid )`.

## Gate queue (EasyAccess)

The main gate opens when a member calls it from a mobile number that EasyAccess knows, so the phone number
is the only access item. EasyAccess has no API, so the plugin keeps a to-do list for the gate admin (V-huset → Port):

| Event | Action |
| --- | --- |
| Membership paid, member not in EasyAccess | Opret bruger (in the downloadable import file) |
| Membership paid, member already in EasyAccess (renewal) | Tilføj til gruppe (e.g. Medlem 2027/28) |
| New phone number for someone in EasyAccess | Ret bruger |
| Last approved workshop seat ends | Slet bruger (snapshot kept, so it survives data deletion) |

- "In EasyAccess" is a flag on the member: set for everyone imported from the Excel list, for members
  matched from an EasyAccess XML export, and when an "Opret bruger" is marked done; a done "Slet bruger" clears it.
- Import file columns follow the EasyAccess template: Fulde navn, ID (= member id), Pinkode (empty),
  Nøglekort/Brik (empty, not used), Telefon (+45…), Gruppenavn. All cells are text.
- Daily at 07:00: one e-mail a week about tasks older than 7 days; in March, a reminder to create next year's
  group with its dates (year start → `grace_until` the year after).
- XML comparison: the export's exact layout isn't documented, so the reader accepts both element-style
  (`<Bruger><Telefon>…`) and attribute-style (`<User Phone="…"/>`) records and matches on phone number.

## Payments outside Dinero

Dinero is the normal source of "paid", but people who paid before the system, in cash, or otherwise can be
registered by the cashier or an administrator:

- Member page → Kontingent → *Registrér betaling uden om Dinero*: year (last, current or next), full/half fee and
  amount, optional key fee, date, note, and whether the member already has gate access for that year
  (no gate task) or needs it (queues "Opret bruger" / "Tilføj til gruppe" like a Dinero payment).
- *Tilføj medlem* with status *Godkendt* has an "existing member" block: already paid this year, already has
  gate access, already has the workshop key. Nothing is invoiced, queued or charged for what they already have.
- Manual payments are marked "Registreret manuelt af …" with the note, logged, and can be undone (back to the
  cashier's list). Payments from Dinero cannot be undone by hand, and the sync never overrides a paid period.

## Members and WordPress users

- A member can be linked to one WordPress user (`members.wp_user_id`). Members and users with the same e-mail
  are linked automatically (when either is created, and once for existing data on upgrade).
- Member page → *Login og roller* (administrators): create a WordPress user for the member (username from the
  e-mail; WordPress sends its own "set your password" e-mail), link to an existing user with the same e-mail,
  tick the plugin roles (Værkstedskontakt, Kasserer, Portadministrator), or remove the link. Only these three
  roles can be given here; WordPress administrator rights are managed under Users as usual.
- WordPress user profile → *V-huset*: shows the linked member, or *Opret som medlem* for a user who isn't one.
- The member list has a *Rolle* column; the Users list has a *V-huset-medlem* column.
- Deleting a member never deletes the WordPress user, and deleting a user only removes the link.

## Workshop keys

Each workshop has its own physical key; the 200 kr key fee pays for it. Keys are not in EasyAccess.

- On the member page, an admin or the workshop's contact records "Udlevér nøgle" for a workshop, with an
  optional number and a payment choice: *Med i indmeldelsen* (the joining invoice included the key fee;
  suggested for a member's first key), *Skal betales* (goes on Fakturering until Dinero shows the key fee paid),
  or *Ingen betaling*.
- A new key for a workshop where the member already holds one replaces it ("Erstattet").
- When a member leaves a workshop while holding its key, the key shows as "Ikke afleveret" until someone
  marks it handed back.
- The table column is still called `fob_number` (it holds the optional key number).

## Personal data (GDPR)

- Daily (07:00, with the gate reminders): declined applicants are deleted `retention_declined` months
  (default 6) after the decline; former members `retention_former` months (default 12) after leaving their
  last workshop. Anyone still waiting or approved anywhere is never deleted automatically.
- Deleting a member removes their member row, seats, periods and keys, and their done gate actions.
  An open gate action keeps its snapshot so the gate admin can finish it; if the member was in EasyAccess and
  no removal was queued, one is queued first. Dinero is not touched.
- Log entries and done gate actions are purged after `retention_log` months (default 36). The log holds
  ids and field names only, never the values.
- Persondata screen: who is deleted within 30 days, "run now", and a data-processor checklist.
- Right of access/erasure: WordPress Tools → Export/Erase Personal Data include the member data;
  administrators can also delete a member from the member page (type the name to confirm).
- Suggested privacy-policy text in Danish appears under Settings → Privacy → Policy guide.

## Roles

| Role | Capabilities |
| --- | --- |
| Værkstedskontakt | `vhus_manage_own_workshop`: members and seats in workshops where they are set as contact |
| Kasserer | `vhus_view_all_members`, `vhus_invoice` |
| Portadministrator | `vhus_view_all_members`, `vhus_gate` |
| Administrator | all of the above + `vhus_manage_all_members`, `vhus_admin` |

## Local test site

A throwaway WordPress on SQLite (Local's PHP 8.2 + WP-CLI) was used for testing; `.claude/launch.json`
starts it on http://localhost:8891. Test logins there: `admin` / `admin-test-pw`, `kontakt` / `kontakt-test-pw`
(contact for Textil), `kasserer` / `kasserer-test-pw`. Outgoing mail is written to `mail.log` in the scratchpad
by a test-only mu-plugin. A mock Dinero API (`dinero-mock` in launch.json, port 8892) stands in for Dinero there. It lives in a temporary folder and will disappear; it is not needed for the plugin.
