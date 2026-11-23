---
name: API Versioning

action:
  label: View on Packagist
  href: "{package.packagistUrl}"

views: components.packages

breadcrumbs:
  - label: Home
    href: route:home
  - label: Packages
    href: route:packages.index
  - label: "{technology.name} Packages"
    href: "url:/packages/{technology.slug}"
  - label: "{package.name}"

card:
  topic: api
  icon: code-brackets
  tags: api versioning rest routing header query path controllers json-resources deprecation sunset ai-ready laravel-boost
  description: Laravel-native API versioning. Version groups and constraints on Route, header, query and path resolution, a controller per version on one cacheable route, and versioned JSON resources.

seo:
  title: "{package.fullName} - Header, Query and Path API Versioning"
  description: "{package.name} is a Laravel package for API versioning with native routes: version groups, since/until constraints, version-specific controllers, versioned JSON resources and Deprecation/Sunset headers. {package.downloadsShort}+ downloads, {package.license} licensed."
  keywords: laravel api versioning, api version header, laravel versioned routes, versioned controllers, json resource versioning, deprecation header, sunset header, laravel boost, ai ready
  author: Pharaonic
  images:
    - "{package.cover}"
  openGraph:
    type: website
    siteName: Pharaonic
  twitter:
    card: summary_large_image

schema:
  "@type": SoftwareSourceCode
  name: "{package.name}"
  description: "{package.name} is a Laravel package for API versioning with native routes, version-specific controllers and versioned JSON resources."
  image: "{package.cover}"
  codeRepository: "{package.githubUrl}"
  programmingLanguage: PHP
  runtimePlatform: "{technology.name}"
  version: "{package.version}"
  datePublished: "{package.publishedAt}"
  dateModified: "{package.updatedAt}"
  license: "https://opensource.org/licenses/{package.license}"
  isAccessibleForFree: true
  sameAs:
    - "{package.githubUrl}"
    - "{package.packagistUrl}"
  author:
    "@id": url:/#organization
  publisher:
    "@id": url:/#organization
  interactionStatistic:
    "@type": InteractionCounter
    interactionType: https://schema.org/DownloadAction
    userInteractionCount: "{package.downloads}"
---
