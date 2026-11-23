---
view: components.packages.package-hero
badges:
  - label: Laravel Package
    color: blue
  - label: "{package.latestVersionLabel}"
    color: green
  - label: "{package.license} License"
    color: purple
  - label: "{package.downloadsShort}+ downloads"
    color: blue
eyebrow: "{package.name}"
title: Version your API
highlight: with Laravel's own routes
buttons:
  - label: View Full Documentation
    href: "{card.docsUrl}"
    style: primary
    icon: arrow-right
  - label: View on GitHub
    href: "{package.githubUrl}"
    style: ghost
    external: true
install: "{card.install}"
labels:
  copy: Copy
  copied: Copied!
---

{package.name} adds versions to the routes you already write with `Route`. Read the version from a header, the query string or the path, serve a different controller per version from one cacheable route, and shape JSON resources per version, without a custom router, base class or forced URL.
