# Third-party skills

Copied by hand on 2026-10-07 from the repositories below, skill folders only; no installer
was run. Each folder keeps its own licence. To update one, copy the folder again from a
newer commit, read the diff, and change the commit here.

| Skills | Repository | Commit | Licence |
|---|---|---|---|
| `impeccable` | [pbakaus/impeccable](https://github.com/pbakaus/impeccable), its `.claude/skills/impeccable` | `bbcb29d9dee6c94915d760bcfc36818ad5be66ad` | Apache 2.0 (`LICENSE`, `NOTICE.md`) |
| `transitions-dev`, `transitions-polish` | [jakubantalik/transitions.dev](https://github.com/jakubantalik/transitions.dev), its `skills/` | `ddf17a82e11e5a5230e2ead99e0c949c9377be83` | Transitions.dev licence (`LICENSE.txt`): use in your own products; no redistribution as a competing library |
| `gsap-core`, `gsap-timeline`, `gsap-scrolltrigger`, `gsap-plugins`, `gsap-utils`, `gsap-react`, `gsap-frameworks`, `gsap-performance` | [greensock/gsap-skills](https://github.com/greensock/gsap-skills), its `skills/` | `aed9cfd3277740755f6bfc1155c7aa645403b760` | MIT (`LICENSE`) |
| `make-interfaces-feel-better` | [jakubkrehel/make-interfaces-feel-better](https://github.com/jakubkrehel/make-interfaces-feel-better), its `skills/` | `35545ea1512ad59fa463e6b1f95ca9c052981fe6` | MIT (`LICENSE`) |

## Local changes

- `gsap-plugins/SKILL.md`: one adjective in the Flip `simple` row is reworded so the
  footprint test passes ("less precise").

## What runs

Only `impeccable` runs code. Its commands call `impeccable/scripts/impeccable`, a shell
launcher that downloads a pinned engine binary (`scripts/VERSION`) from the project's
GitHub releases on first use, checks it against the release's `.sha256` file, caches it in
`~/.impeccable/` and runs it. The engine checks impeccable.style for updates once a day and
sends an anonymous ping when a design direction is chosen. `.claude/settings.json` turns
both off (`DO_NOT_TRACK`, `IMPECCABLE_NO_TELEMETRY`, `IMPECCABLE_NO_UPDATE_CHECK`). Its
editor hooks are not installed. The other skills are text only.
