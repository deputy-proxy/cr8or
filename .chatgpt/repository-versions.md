# Repository framework and dependency version matrix

Last verified: 2026-11-19.

Versions below are read from the committed `composer.lock` and npm/Yarn lockfile where available, not inferred from Composer/npm version ranges. PHP column is the root Composer platform constraint. Node is the version configured in CI; if no CI version is defined, it is marked accordingly.

| Repository | PHP constraint | Laravel | Filament | Livewire | PHPUnit | Pest | Larastan | Pint | Node | Vite | Tailwind | Laravel Vite plugin |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Cr8or | ^8.4 | 13.32.0 | 5.8.4 | 4.4.5 | 13.3.4 | 5.2.1 | 3.12.2 | 1.27.1 | 22 | 8.3.0 | 4.3.3 | 3.2.0 |
| Conrad | ^8.3 | 13.7.0 | 5.6.1* | 4.3.0 | 12.5.24 | — | — | 1.29.1 | 22 | 8.0.10 | 4.2.4 | 3.1.0 |
| EasyEnglish | ^8.4 | 13.30.1 | 5.8.0 | 4.4.4 | 13.3.2 | 5.1.4 | 3.11.0 | 1.31.0 | 22 | 8.3.5 | 4.3.3 | 3.2.0 |
| Engleza Wiki | ^8.3 | 13.42.0 | 5.11.4 | 4.4.8 | 12.5.44 | — | 3.15.0 | 1.32.3 | 22 (Yarn 1.22.22) | 8.x constraint; exact lock entry not found | 4.3.3 | 3.2.0 |
| EdVenture / Management-OS | ^8.4.1 | 13.43.0 | 5.11.4 | 4.4.8 | 12.5.45 | — | 3.15.0 | 1.32.3 | 22 | 8.3.8 | 4.3.5 | 3.2.1 |
| Toti Învățăm | ^8.2 | 11.56.1 | 3.3.55 | 3.8.8 | 11.5.56 | — | — | 1.32.1 | Not specified | 5.4.21 | 3.4.19 | 1.3.0 |

* Conrad's lockfile resolves Filament 5.6.1*, but its `composer.json` still declares `filament/filament: "*"`. This is an unrestricted constraint and remains tracked by issue [#33](https://github.com/deputy-proxy/conrad-works/issues/33).

## Notes

- Cr8or, Conrad, EasyEnglish, Engleza Wiki, and EdVenture use Laravel 13, Filament 5, and Livewire 4.
- Toti Învățăm remains on Laravel 11, Filament 3, and Livewire 3.
- Conrad does not currently require Pest or Larastan in its Composer manifest/lockfile.
- Toti Învățăm does not currently require Pest or Larastan.
- Engleza Wiki uses Yarn Classic 1.22.22. Its lockfile resolved Tailwind 4.3.3 and Laravel Vite plugin 3.2.0; the exact Vite package entry was not found in the retrieved lockfile, so only the declared `^8.0.0` range is known.
- Toti Învățăm's inspected repository does not define a Node version in GitHub Actions; do not assume Node 22 for it.
- These are dependency versions, not a statement that every package is current or vulnerability-free. Run the repository's locked security audits for current security status.
