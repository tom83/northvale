# ADR 0001: Publish the public site as static HTML with Tome

- Status: Accepted (Phase 0 spike passed with the `council_tome` workaround)
- Date: 2026-11-26

## Context

This is a portfolio project. The public site has to be online all the time
and cost close to nothing. A normal Drupal host (PHP + database) costs money
every month and has to be patched.

## Decision

- Build with Drupal 11 as normal. Publish the anonymous, public routes as flat
  HTML using **Tome Static**, and host them on a free static host (Cloudflare
  Pages, Netlify or GitHub Pages).
- Use **Tome Sync** as the source of truth: config goes to `config/`, content
  to `content/` (JSON), and uploaded files to `files/`, all committed to git.
  The database is disposable and a rebuild (`drush site:install --existing-config`
  then `drush tome:import`) restores it.
- GitHub Actions rebuilds the site from git on every push, plus on a nightly
  schedule so date-based listings (upcoming events) stay correct.

## Consequences

- There is no live admin UI on the public site. The editor experience is shown
  through a screen recording and a Codespaces / DDEV setup reviewers can run.
- Anything that needs the server at request time has to be redesigned:
  - Search uses Pagefind (a static index).
  - Forms post to an external endpoint or are shown in the demo backend only.
  - Exposed filters and query-string routes become path-based routes or
    client-side filtering.
  - Redirects are generated into the host's redirect file.
- Content edits need a commit and a build before they appear. That's fine for
  a portfolio site, but it wouldn't suit a real council newsroom.

## Phase 0 spike results (`scripts/spike/run.sh`)

Run on 2026-11-26 with Drupal 11.4.14 and Tome 1.16.0.

| Check | Result |
|---|---|
| Aggregated CSS/JS in the static output | Pass |
| Image style derivatives in the static output | Pass |
| Pagers | Pass. Tome rewrites `?page=1` links to `/page/2/index.html`. |
| Layout Builder inline blocks survive export, wipe and rebuild | **Failed with plain Tome; passes with `council_tome`** |

### Why inline blocks failed

The problem was wider than inline blocks. **Tome dropped every per-entity
Layout Builder override**:

- Core forbids view access to the layout field for every user
  (`LayoutSectionItemList::defaultAccess()`, drupal.org issue #2942975).
- The serializer silently skips fields the user can't view, so the exported
  node had no layout at all.

The fallback planned here (limit editors to reusable blocks) would not have
helped, because the whole override was lost.

Even with the field exported, three more things break the round trip:

1. Layouts reference inline blocks by **revision ID**. Tome doesn't keep IDs,
   so after a rebuild they point at the wrong revision.
2. Tome orders imports by entity reference fields only. Nothing guarantees an
   inline block imports before the page that uses it.
3. When a page is deleted, Layout Builder keeps its inline blocks with a NULL
   usage record until cron runs. Exporting one of them crashes Layout
   Builder's access check, so a full `tome:export` fails.

## Decision: fix it in a `council_tome` module

`web/modules/custom/council_core/modules/council_tome`:

- `LayoutOverrideNormalizer` exports the layout field and references inline
  blocks by UUID. On import it resolves each UUID to the block's current
  revision, and fails loudly if the block is missing.
- `InlineBlockSyncSubscriber` adds each inline block to its page's
  dependencies in Tome's index, so blocks import first. After import it
  records the block's Layout Builder usage.
- `CouncilTomeHooks::entityDelete()` deletes a page's inline blocks
  immediately, instead of leaving them for cron.
- `LayoutOverrideSyncTest` (kernel) covers export, import with different
  revision IDs, deletion, and a missing block.

Consequences:

- The module extends Tome's `@internal` entity normalizer and uses its
  `@internal` index trait. Tome is pinned in `composer.lock`, and upgrades
  must re-run the kernel test and the spike.
- Fallback if a future Tome release breaks this: landing pages with a fixed
  default layout plus a flexible "sections" field instead of per-node
  overrides.

## Other findings

- Tome 1.16 has no `drush tome:install` command. The rebuild is
  `drush site:install --existing-config -y`, then `drush tome:import -y`.
- Importing an aliased node creates a duplicate path alias: one from the
  node's `path` field and one from the exported `path_alias` entity. Revisit
  when pathauto is added in Phase 2.
