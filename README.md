# V-huset Medlemmer

WordPress plugin for Værkstedshuset i Hannovergade. Spec: https://claude.ai/artifact/LAtPZ6e5Ho3ipGycQ6d7mu

The plugin itself is in `vhus-medlemmer/` (zip that folder to install).

## Status

| Phase | Content | Status |
| --- | --- | --- |
| 1. Core | Tables, roles, settings, Excel import with dry run, member and workshop screens | Done |
| 2. Sign-up and waiting lists | Public form, contact approval, e-mails, ready-to-invoice list | Done |
| 3. Dinero sync | Contact linking, nightly invoice sync, paid status | Next |
| 4. Gate queue | Gate actions, EasyAccess import file, XML match | |
| 5. GDPR and polish | Retention jobs, privacy notice, user guides | |

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
  public/signup.css         form layout (inherits theme styles)
  admin/
    class-admin.php         menus, screens, admin-post handlers
    class-members-table.php WP_List_Table
    class-queues.php        Ventelister (contacts) and Fakturering (cashier)
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
by a test-only mu-plugin. It lives in a temporary folder and will disappear; it is not needed for the plugin.
