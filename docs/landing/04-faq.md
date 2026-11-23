---
view: components.home.faq
badge: FAQ
title: "{package.name}"
highlight: Questions
subtitle: "Quick answers about installing and using {package.name}."
---

## What is {package.name}?

{card.description} It's a free, open-source {technology.name} package by Pharaonic.

## How do I install {package.name}?

Run `composer require {package.composer}` in your project's root directory.

## What does {package.name} require?

The latest release requires {package.requiresText}.

## Does it add /api/v1 to my URLs?

No. Versions are route metadata. A URL prefix is only added when you configure a path template such as `api/{version}`, or write the prefix yourself.

## Does it work with route:cache?

Yes. Version constraints and controller maps are plain strings in the route action, and the package is tested by caching routes and serving requests from a freshly booted application.

## What happens when a version has no controller?

The request is not found (404 by default). The package never falls back to another version's controller unless the route explicitly falls back to its own.

## Is {package.name} free to use?

Yes. {package.name} is open source under the {package.license} license, so you can use it in personal and commercial projects.

## Where can I find the {package.name} documentation?

Read the [{package.name} documentation]({package.docsUrl}) for setup, configuration, and usage examples.

## How do I report a bug or contribute to {package.name}?

Open an issue or a pull request on [GitHub]({package.githubUrl}), ask in [GitHub Discussions](https://github.com/orgs/Pharaonic/discussions), or chat with us on [Discord](https://discord.gg/XQG9RhvEvf).
