## Lifecycle Headers

Each version has a lifecycle status:

```php title="config/pharaonic/api-versioning.php"
'lifecycle' => [
    'v1' => ['status' => 'retired', 'sunset_at' => '2026-01-01'],
    'v2' => [
        'status' => 'deprecated',
        'deprecated_at' => '2026-06-01',
        'sunset_at' => '2027-01-01',
        'link' => 'https://example.com/docs/migrating-to-v3',
    ],
    'v3' => ['status' => 'sunset-announced', 'sunset_at' => '2028-01-01'],
],
```

| Status | Responses |
| --- | --- |
| `active` (default) | no lifecycle headers |
| `deprecated` | `Deprecation: @1780272000` (RFC 9745, needs `deprecated_at`), `Sunset` when `sunset_at` is set, `Link: <…>; rel="deprecation"` when `link` is set |
| `sunset-announced` | `Sunset: Fri, 01 Jan 2027 00:00:00 GMT` (RFC 8594, needs `sunset_at`), `Deprecation` when `deprecated_at` is set, `Link: <…>; rel="sunset"` |
| `retired` | the request is rejected with `status.retired` (410 by default) and the `Sunset` header when set |

Dates are read in UTC. A sunset date is only announced: a version stops being served when you set its status to `retired`, never automatically. Headers your application already sets are kept, `Link` values are added next to yours, and response bodies are never changed.
