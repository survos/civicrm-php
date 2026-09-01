# survos/civicrm-php

CiviCRM APIv4 REST client. Framework-agnostic — it takes a `HttpClientInterface` and needs
nothing else, so it works from Symfony, from WordPress, or from plain PHP. That last one is not
hypothetical: VillageDesk's CiviCRM runs inside WordPress.

For the Symfony integration — configuration, Messenger-backed mail and exports, console
commands — see `survos/civicrm-bundle`, which is where the interesting argument lives.

```php
$civi = new CiviCrmClient($httpClient, 'https://village.example.org', $apiKey);

$contacts = $civi->call('Contact', 'get', [
    'select' => ['display_name', 'email_primary.email'],
    'where'  => [['contact_type', '=', 'Individual']],
    'limit'  => 25,
]);

foreach ($civi->all('Contribution') as $row) {   // pages, yields, constant memory
    // ...
}
```

Three things the wire format gets wrong if you guess, each encoded in exactly one place here:

- Parameters go as a **single JSON string in a `params` field**, not a JSON body. Get it wrong
  and you get an empty result rather than an error.
- The API reports failures **inside an HTTP 200**, so the body is authoritative. Anything
  checking only the status code reads `{"error_message": "..."}` as success.
- Authentication is `X-Civi-Auth: Bearer`, not the documented `?_authx=` query form. A key in a
  URL ends up in access logs, proxy logs and browser history.

Reads are retried on 429, 5xx and transport failures with exponential backoff and full jitter.
**Writes never are** — APIv4 is POST-only, so at the HTTP layer `Contact.get` and
`Contact.create` are indistinguishable, and retrying a timed-out create makes two contacts. The
retryable set is an allowlist, so a write action added upstream defaults to not retrying.

Not yet run against a live CiviCRM; the `authx` path is the least certain part.
