# ADR 0001: Publish the public site as static HTML with Tome

- Status: Accepted (pending the Phase 0 spike)
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
  The database is disposable and `drush tome:install` rebuilds it.
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

## Risks checked in Phase 0 (`scripts/spike/run.sh`)

1. Layout Builder inline (non-reusable) blocks survive export and reinstall.
2. Aggregated CSS/JS are included in the static output.
3. Image style derivatives are included in the static output.
4. How pagers are exported.

**Fallback if check 1 fails:** limit editors to reusable blocks in Layout
Builder, or keep Tome Static but take content from a committed database
snapshot instead of Tome Sync.
