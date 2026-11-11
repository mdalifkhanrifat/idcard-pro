# HR sync — for the system that sends people

> সারাংশ (বাংলা): HR সিস্টেম একটা ইন্টিগ্রেশন অ্যাকাউন্টের token দিয়ে `POST /api/v1/sync/cardholders`-এ কর্মীদের পাঠায় — কর্মী নম্বর দিয়ে খুঁজে প্রত্যেককে তৈরি বা হালনাগাদ করা হয় (পরিবারসহ), প্রতিটা রেকর্ডের আলাদা ফল আসে; যাঁরা চলে গেছেন তাঁদের `.../deactivate`-এ পাঠালে নিষ্ক্রিয় হন, কখনো মোছা হন না। আগে `dry_run` দিয়ে দেখে নিন, প্রতিটা ব্যাচে একটা `Idempotency-Key` দিন।

An HR system (or a payroll, or a spreadsheet script) keeps IDCard Pro's people up to date by sending
them here, as often as it likes. Each person is found by **employee number**, then created or updated
by the same rules as the form; one bad record never holds back the others. People who left are marked
inactive, never deleted: their cards and appointments are records of what happened. This page is what
you need to build the sending end. Webhooks, the other direction, are in [WEBHOOKS.md](WEBHOOKS.md).

## Before you start

1. On the *Integrations* page, make an **account** for your system and give it a role that may
   **Sync from HR** (`cardholders.sync`). A role with only that right can sync and do nothing else: it
   cannot even read the people list.
2. Make a **token** for the account. It is shown once; keep it like a password. It ends on the day
   chosen when it was made (the organization sets the usual and the longest length).
3. If the account has a list of addresses it works from, call from one of them.

Every call sends the token and asks for JSON:

```http
Authorization: Bearer 12|kD9…
Accept: application/json
Content-Type: application/json
Accept-Language: en
```

`Accept-Language` (`en` or `bn`) picks the language of every message in the answer. An account may
make a number of calls a minute (600 unless the organization chose otherwise); the answer's
`X-RateLimit-Remaining` says how many are left, and too many get `429` with `Retry-After`. One call can
hold hundreds of people, so send batches, not one call per person.

The *Integrations → HR sync* tab shows both requests ready to copy, in the organization's own field
names, and the runs your system made.

## Add or update people

`POST /api/v1/sync/cardholders`

```json
{
  "dry_run": false,
  "create_missing_lookups": false,
  "records": [
    {
      "employee_no": "E-1001",
      "full_name": "Rahim Uddin",
      "department": "Finance",
      "date_of_birth": "1990-04-15",
      "family": [{ "external_id": "F-77", "relation": "spouse", "name": "Salma Begum" }]
    },
    { "employee_no": "E-1002", "shoe_size": 42 }
  ]
}
```

| Field | Meaning |
|---|---|
| `records` | 1 to 500 people (a bigger list is several calls) |
| `dry_run` | `true`: check everything and say what would happen; nothing is written, nothing is told to webhooks |
| `create_missing_lookups` | `true`: a department, rank and the like not found by name is created (only for a record that is saved) |

The answer is `200` **even when some records failed** — each has its own result:

```json
{
  "data": {
    "id": "01m75c868wxma80ts417tx8k8y",
    "dry_run": false,
    "summary": { "total": 2, "created": 1, "updated": 0, "unchanged": 0, "failed": 1 },
    "records": [
      {
        "index": 0,
        "employee_no": "E-1001",
        "result": "created",
        "id": "01m75c86955wqzp04grh9ejz9d",
        "errors": {},
        "family": [{ "index": 0, "id": "01m75c869c7adjg1bvhzb10ea0", "external_id": "F-77", "result": "created" }]
      },
      {
        "index": 1,
        "employee_no": "E-1002",
        "result": "failed",
        "id": null,
        "errors": {
          "shoe_size": ["“shoe_size” is not a cardholder field."],
          "full_name": ["The Full name field is required."]
        }
      }
    ],
    "lookups_created": []
  }
}
```

- `result` is `created`, `updated`, `unchanged` (every value was already so) or `failed`. A dry run
  cannot know `unchanged` before saving, so it says `updated` for anyone it found.
- `errors` holds the problems **by field**, in the reader's language; a failed record changed nothing
  (not even its family, and master data made for it is undone).
- `id` is the person's id in this API (`GET /api/v1/cardholders/{id}`); `data.id` is this run's id (null
  in a dry run), also in the run log and in the `cardholder.synced` webhook.
- `lookups_created` lists master data the call created (or would create), as `"department: Finance"`.

### The fields

A record uses the **cardholder API's field names** — `GET /api/v1/sync/cardholders/fields` lists every
one with its name in the reader's language. Values are taken as they are: dates as `YYYY-MM-DD`,
choices by their keys (`"gender": "male"`, `"status": "active"`), custom fields under `custom_fields`
by their keys. A number sent as a JSON number is fine where text is expected (an employee number, a
phone).

- **Only what is sent changes.** A field left out stays as it is; `null` clears it (a required field
  cannot be cleared). So a record can be as small as `{"employee_no": "E-1001", "posting": "Dhaka"}`.
- **Master data** (department, unit, rank, designation, blood group, …) by its **name, local name or
  code**, in any case — `"rank": "Captain"` or `{"code": "CPT"}` — or as this API gives it, `{"id": "01…"}`
  or `"rank_id": "01…"`. Not both `rank` and `rank_id`. A unit is looked up inside the department sent,
  or else inside the person's own department.
- What this API returns but nobody sets (`id`, `photo`, `created_at`, `has_cards`, …) is skipped, so a
  person read from `GET /api/v1/cardholders/{id}` can be sent back as it came. Any other unknown field is
  that record's mistake, so a misspelt name is caught at once.
- `employee_no` is required in every record, and the same number twice in one call is a mistake.

**Your own field names.** If your system calls `employee_no` `emp_id`, the organization can say so once
(*Integrations → HR sync → HR field names*, or `PUT /api/v1/sync/cardholders/settings` with
`{"sync_fields": {"emp_id": "employee_no", "dependents": "family"}}`), and you send your names. Ours
always work too; a field under both names is that record's mistake.

### The family

`family` is the person's list of family members, named as in `GET /api/v1/cardholders/{id}/family-members`
(`relation`, `name`, `name_local`, `gender`, `date_of_birth`, `phone`, `blood_group`, `national_id`, …).
Each member is found:

1. by our `id`, if sent (as that list gives it);
2. else by **your id for them, `external_id`** — send it, it is what keeps a member the same person
   when their name is corrected;
3. else by `relation` and `name` (any case). A member recorded by hand who has no `external_id` yet is
   found this way and **takes the one you send**, instead of being made twice. Two members that match
   are a mistake: send `external_id` or `id` to say which.

A member not found is created (`relation` and `name` required). A member found keeps what the item
leaves out. Each member's result comes back in the record's `family`; problems are named by place
(`"family.0.name"`), and any one of them fails the whole record.

**Members the list leaves out** are kept — unless the record says `"family_mode": "replace"`:

| `family_mode` | Members not in the list |
|---|---|
| `merge` (the default) | stay |
| `replace` | are removed — **`"family": []` with `replace` removes everyone** |

A member with cards or appointments is **never** removed: the record fails with the reason, and nothing
of it is saved. Removed members come back in `family` as `{"index": null, …, "result": "removed"}`.
Send `family_mode` only with `family`.

## People who left

`POST /api/v1/sync/cardholders/deactivate`

```json
{ "employee_nos": ["E-1001", "E-1404"], "status": "inactive", "dry_run": false }
```

```json
{
  "data": {
    "id": "01m75c86ej4kn1m9rqsnk05vzd",
    "dry_run": false,
    "summary": { "total": 2, "deactivated": 1, "unchanged": 0, "not_found": 1, "failed": 0 },
    "records": [
      { "index": 0, "employee_no": "E-1001", "result": "deactivated", "id": "01m75c86955wqzp04grh9ejz9d", "status": "inactive", "errors": {} },
      { "index": 1, "employee_no": "E-1404", "result": "not_found", "id": null, "status": null,
        "errors": { "employee_no": ["No cardholder has employee no E-1404."] } }
    ]
  }
}
```

- `status` is `inactive` (the default) or `retired`. Without it, someone already not active is left as
  they are (a retired person stays retired); with it, that status is set.
- Nobody is deleted and no card is revoked. An inactive person cannot book appointments by the public
  link and gets no card expiry reminders.
- Someone who comes back: send them again to `POST /api/v1/sync/cardholders` with `"status": "active"`.
- A number twice in one call `failed`; a number no one has is `not_found` — neither stops the others.

## Send a batch once: `Idempotency-Key`

A network error leaves you not knowing whether a call arrived. Send a key of your own with every
batch (1 to 255 visible ASCII characters, no spaces — e.g. a UUID or `payroll-2026-11-11-1`), and **send it
again with the same body** when you retry:

```http
Idempotency-Key: payroll-2026-11-11-1
```

| When the same key comes again | Answer |
|---|---|
| with the same request, after it finished | the first answer, word for word, with `Idempotent-Replayed: true` — nothing is done twice |
| with a different body | `422`, `errors.idempotency_key` — use a new key for a new batch |
| while the first is still running | `409` — wait and send again |
| after the first failed on the server's side (`5xx`) | it runs again |

Keys are remembered for 24 hours, per account. Without the header, every call runs.

## Answers that are not 200

| Status | Meaning |
|---|---|
| `401` | No token, a wrong one, or one past its last day |
| `403` | The account may not sync, or calls from an address not on its list |
| `409` | The same `Idempotency-Key` is still running |
| `422` | The request's shape is wrong (`records` not a list, more than 500, a status that is not allowed, an `Idempotency-Key` reused for something else) — `errors` says where; nothing was done |
| `429` | Too many calls this minute: wait `Retry-After` seconds |

A record's own mistakes never give `422`: they come back in its `errors`, inside a `200`.

## What your system ran

`GET /api/v1/sync/cardholders/runs` lists your account's runs, newest first (`filter[kind]=upsert` or
`deactivate`, `per_page`). Someone who manages integrations sees every account's. What was sent is never
kept: only who, when, and how many of each result.

```json
{ "id": "01m75c86ej4kn1m9rqsnk05vzd", "kind": "deactivate",
  "account": { "id": "01m75c86450fqkwb50qv1p1rfd", "name": "Payroll", "kind": "integration", "deleted": false },
  "total": 2, "created": 0, "updated": 1, "unchanged": 0, "failed": 0, "not_found": 1,
  "started_at": "2026-11-11T13:20:11.000000Z", "finished_at": "2026-11-11T13:20:11.000000Z" }
```

`finished_at` is `null` for a run that broke off (what it saved until then stays). The organization may
choose to delete runs older than some days; by default they are kept.

## Being told back

Each person created or changed is noted in the organization's audit log with your account as the one
who did it, and told to webhooks: one `cardholder.synced` for the whole call to those that chose it, or
`cardholder.created` / `cardholder.updated` / `cardholder.family_changed` one by one to the others (see
[WEBHOOKS.md](WEBHOOKS.md#hr-sync)). A dry run is told to nobody.

## A sync that works well

- **Dry run first** whenever the mapping changes, and read every `errors`.
- **Send changes, not everything:** only the people and the fields that changed since the last run.
  Sending everyone is safe too (unchanged people stay `unchanged`), just slower.
- **One `Idempotency-Key` per batch**, kept until the batch is answered.
- **Send `external_id` for family members** from the start.
- **People who left go to `/deactivate`**; a person missing from your list is never deactivated by itself.
- **Look at `failed`** in `summary` and fix those records at the source.
- New fields can appear in answers: ignore what you do not know.
