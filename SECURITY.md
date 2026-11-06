# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately instead, in either of these ways:

1. On GitHub: open the **Security** tab of this repository and choose **Report a vulnerability**
   (or go to <https://github.com/Idrees-Saifuddin/blast-radius/security/advisories/new>).
2. By email: idreessaifuddin@gmail.com, with "BlastRadius security" in the subject.

Please include what you found, how to reproduce it, and which version you used
(`composer show idrees-saifuddin/blast-radius`).

This is a one-person project, so I answer on a best-effort basis: I aim to reply within a few days,
and to publish a fix and a note in the [changelog](CHANGELOG.md) as soon as one is ready. You will be
credited in the release notes if you wish.

## Supported versions

Only the latest release receives fixes. While the project is 0.x, upgrading to the newest version
is the supported way to get a fix.

## What to keep in mind when using BlastRadius

BlastRadius is a **development tool**. It shows your routes, source file paths, function names and
the SQL your application runs (not the bound values), so treat its page like a debugger:

- Install it with `--dev`, so a normal production deploy does not include it.
- It is off in production unless you set `BLASTRADIUS_ENABLED=true`.
- The page at `/blast-radius` is only reachable from this machine and private networks by default.
  If you open it up (`UiAccess::using()`), protect it with your own authentication.
- `BLASTRADIUS_ENABLED=false` switches everything off.

Reports about a way around these protections, or about the package reading, writing or exposing more
than described above, are exactly what this policy is for.
