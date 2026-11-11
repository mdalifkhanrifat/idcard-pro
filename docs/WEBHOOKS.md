# Webhooks — for the system that receives them

> সারাংশ (বাংলা): প্রতিষ্ঠানের কিছু ঘটলে IDCard Pro তার বেছে নেওয়া ঠিকানায় একটা সই-করা `POST` পাঠায়। গ্রাহক সিস্টেমের ডেভেলপার এই নিয়মে সই যাচাই করে দ্রুত `2xx` উত্তর দেবেন; ব্যর্থ হলে অ্যাপ নিজে আবার চেষ্টা করে, তাই একই `id` দুবার আসতে পারে আর ক্রম নিশ্চিত নয়।

When something happens in an organization, IDCard Pro sends a signed `POST` to the address the
organization chose, so an HR system, a door system or anything else can react without polling. This
page is what you need to build the receiving end. Webhooks are set up on the *Integrations* page, or
through the API below.

## What arrives

```http
POST /hooks HTTP/1.1
Content-Type: application/json
User-Agent: IDCardPro-Webhooks/1
X-Webhook-Id: 01kbx7h3z9qv4m2n8r6t5y0wce
X-Webhook-Event: card.issued
X-Webhook-Timestamp: 1793534400
X-Webhook-Signature: sha256=5f0c2e…

{"id":"01kbx7h3z9qv4m2n8r6t5y0wce","event":"card.issued","occurred_at":"2026-11-01T10:00:00Z","tenant":"acme","data":{…}}
```

| Field | Meaning |
|---|---|
| `id` | What this is about, the same on every attempt and when sent again by hand. Same as `X-Webhook-Id`. |
| `event` | One of the events below. Same as `X-Webhook-Event`. |
| `occurred_at` | When it was logged for sending, in UTC. |
| `tenant` | The organization's short name. |
| `data` | The record, exactly as this API describes it (see [Events](#events)), with its public ids. |

Words inside `data`, such as a status name, are in the organization's language. **Photos, signatures,
documents and biometric data are never sent.** To get those, call the API with the record's id.

## Check the signature

Do this before anything else, on the **raw bytes of the body** as they arrived. Parsing the JSON and
encoding it again gives different bytes, and the check fails.

1. Take `X-Webhook-Timestamp` and join: `<timestamp>.<raw body>`.
2. Compute HMAC-SHA256 of that, keyed by the webhook's signing secret (`whsec_…`), in lowercase hex.
3. Compare `sha256=<result>` with `X-Webhook-Signature`, using a constant-time comparison.
4. Turn it away if the timestamp is more than 5 minutes from your clock. This stops an old request
   being played again; keep your clock right with NTP. Each attempt has a fresh timestamp and
   signature, so this does not refuse a late retry.

```php
// PHP
$body = file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';
$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

if (! hash_equals($expected, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '') || abs(time() - (int) $timestamp) > 300) {
    http_response_code(401);
    exit;
}
```

```js
// Node.js: rawBody is the Buffer as it arrived
import { createHmac, timingSafeEqual } from 'node:crypto';

function isGenuine(rawBody, timestamp, signature, secret) {
    const expected = Buffer.from(
        'sha256=' + createHmac('sha256', secret).update(`${timestamp}.`).update(rawBody).digest('hex'),
    );
    const given = Buffer.from(signature ?? '');

    return expected.length === given.length
        && timingSafeEqual(expected, given)
        && Math.abs(Date.now() / 1000 - Number(timestamp)) <= 300;
}
```

```python
# Python: raw_body is the bytes as they arrived
import hashlib, hmac, time

def is_genuine(raw_body: bytes, timestamp: str, signature: str, secret: str) -> bool:
    message = timestamp.encode() + b"." + raw_body
    expected = "sha256=" + hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()

    return hmac.compare_digest(expected, signature or "") and abs(time.time() - int(timestamp)) <= 300
```

The secret is shown once, when the webhook is made and whenever it is changed. Changing it stops the
old one at once, so anything still on its way is signed with the new one: have the new secret ready.

## Answer quickly

- **Any `2xx` means received.** Everything else is a failure, a move elsewhere (`3xx`) included:
  moves are not followed, so save the final address.
- Answer within **15 seconds** (5 to connect). Store the event, answer, and do the work afterwards.
- What you answer is thrown away; only the status is kept.

## Repeats and order

- **The same `id` can arrive more than once:** an attempt can succeed while its answer is lost, and
  people can send a failed one again. Remember the ids you have handled and skip repeats.
- **Order is not guaranteed.** A retry can arrive after something newer. When only the latest state
  matters, use the id in `data` to read the record from the API rather than trusting the arrival
  order.

## When your address fails

By default a delivery is tried up to **six times**: at once, then after 1 minute, 5 minutes,
30 minutes, 2 hours and 6 hours, about nine hours in all. After that it is marked failed in the log,
where it can be seen and sent again by hand, with the same body and `id`.

By default, after **20 deliveries in a row** have failed, the webhook turns itself off, and the people
who look after integrations are told. Nothing is sent while it is off, and what happens meanwhile is
not stored for later. Any success starts the count again, as does turning it back on.

The organization chooses both in its settings (Organization → Webhooks): from one try to six, and
from 1 to 100 failed deliveries. Fewer tries keep the first waits (three tries: at once, after
1 minute, after 5 more), so plan your downtime around the organization's choice. The webhook list
answers with the count in force as `meta.disable_after`.

## Addresses

An address must be `https` and public: addresses inside a private network are refused, and it cannot
carry a name or password (deliveries are signed instead). This is checked when saving and again on
every attempt.

## Events

| Event | Sent when | `data` is |
|---|---|---|
| `cardholder.created` | A cardholder is added | `GET /api/v1/cardholders/{id}` |
| `cardholder.updated` | A cardholder's own record changes | the same |
| `cardholder.deleted` | A cardholder is deleted | the same, as last saved |
| `cardholder.imported` | A file of cardholders ends | see [Imports](#imports) |
| `cardholder.synced` | A request of the HR sync API ends | see [HR sync](#hr-sync) |
| `cardholder.family_changed` | A family member is added, changed or removed, or their photo changes | the cardholder, with `change` (see [What changed](#what-changed)) |
| `cardholder.photo_changed` | The cardholder's own photo is uploaded, replaced or removed | the same |
| `card.issued` | A card is issued | `GET /api/v1/cards/{id}` |
| `card.printed` | A card is printed | the same |
| `card.updated` | A card's details change | the same |
| `card.revoked` | A card is revoked | the same |
| `appointment.booked` | An appointment is booked | `GET /api/v1/appointments/{id}` |
| `appointment.confirmed` | An appointment is confirmed | the same |
| `appointment.moved` | An appointment moves to another time | the same |
| `appointment.cancelled` | An appointment is cancelled | the same |
| `appointment.status_changed` | An appointment moves to another step | the same |
| `webhook.test` | Someone presses *Test* | `{"test": true}` |

New events can be added, and new fields can appear in `data`: ignore what you do not know.

### What changed

The cardholder's record does not say what happened to a family member or a file, so these two events
add `data.change`, with ids and words only:

```json
{"family_member": {"id": "01K…", "action": "created"}}
{"file": {"id": "01K…", "collection": "photo", "action": "uploaded"}}
{"family_member": {"id": "01K…", "action": "updated"}, "file": {"id": "01K…", "collection": "photo", "action": "uploaded"}}
```

`family_member.action` is `created`, `updated` or `deleted`; `file.action` is `uploaded` (a new photo,
which replaces the old one) or `deleted`. The last line is a family member's own photo: it is told as
`cardholder.family_changed`, never as `cardholder.photo_changed`.

**No photo is ever sent, nor a link to one.** To fetch it, read
`GET /api/v1/cardholders/{id}/files?collection=photo` with your token (add `&family_member={id}` for a
family member's): the answer holds a link that works for a few minutes. Signatures and other documents are not told of.

## Imports

A file can hold thousands of rows. A webhook that chooses `cardholder.imported` gets one delivery for
the whole file, and hears nothing of `cardholder.created` or `cardholder.updated` from that file. A
webhook that does not choose it goes on hearing of each row. Card events are not affected.

```json
{
  "id": "01kbx7j8m2…",
  "status": "completed",
  "created": 2,
  "updated": 1,
  "failed": 1,
  "cards": { "imported": 0, "skipped": 0 },
  "started_at": "2026-11-01T10:00:00Z",
  "finished_at": "2026-11-01T10:00:09Z",
  "cardholders": { "created": ["01kbx7…", "01kbx8…"], "updated": ["01kbx9…"] }
}
```

`cardholders` holds ids only: read the records from the API. Nothing is sent when no row saved.

A file that breaks off comes with `"status": "failed"` and the rows that did save. If the run was cut
off from outside, the ids are no longer known: `created`, `updated` and `cardholders` are `null`, and
some rows may have saved, so compare for yourself.

## HR sync

One request to `POST /api/v1/sync/cardholders` (or to `POST /api/v1/sync/cardholders/deactivate`, for
the people who left) can hold hundreds of people. A webhook that chooses `cardholder.synced` gets one
delivery for the whole request, and hears nothing of `cardholder.created`, `cardholder.updated` or
`cardholder.family_changed` from it (a person whose family changed is listed in `cardholders.updated`).
A webhook that does not choose it goes on hearing of each person and each family member. A dry run is
never told of.

```json
{
  "id": "01kbz2c4p9…",
  "kind": "upsert",
  "total": 4,
  "created": 1,
  "updated": 1,
  "unchanged": 1,
  "failed": 1,
  "not_found": 0,
  "started_at": "2026-11-08T10:00:00Z",
  "finished_at": "2026-11-08T10:00:01Z",
  "cardholders": { "created": ["01kbz2…"], "updated": ["01kbz3…"] }
}
```

`id` is the one the sync request answered with (`data.id`). `kind` says which request it was:
`upsert` (people created or updated) or `deactivate` (people who left: those marked as no longer active
are counted in `updated` and listed in `cardholders.updated`; `not_found` counts the employee numbers that
matched nobody, and is `0` for an upsert). `cardholders` holds ids only: read the
records from the API. Nothing is sent when nobody was created or updated. A request that broke off
comes with `"finished_at": null` and the people saved until then.

## Managing webhooks through the API

All of these need the `integrations.manage` permission. To send an event somewhere, the account must
also be allowed to see that kind of record.

| Call | What it does |
|---|---|
| `GET /api/v1/webhooks` | The list; `meta.events` holds what can be chosen |
| `POST /api/v1/webhooks` | Make one: `name`, `url`, `events`. The answer holds the `secret`, once |
| `GET`, `PUT`, `DELETE /api/v1/webhooks/{id}` | Read, change (send only what changes), delete |
| `POST /api/v1/webhooks/{id}/secret` | A new secret, in this answer only |
| `POST /api/v1/webhooks/{id}/test` | Send a test now, and answer how it went |
| `GET /api/v1/webhooks/{id}/deliveries` | The log, newest first; `filter[status]`, `filter[event]` |
| `GET /api/v1/webhooks/{id}/deliveries/{delivery}` | One delivery, with what was sent in `payload` |
| `POST /api/v1/webhooks/{id}/deliveries/{delivery}/resend` | Send a failed one again |
