# Biometric bridge agent — protocol 1

> সারাংশ (বাংলা): স্টেশনের কম্পিউটারে চলা ছোট একটা এজেন্ট যেকোনো স্ক্যানার নির্মাতার SDK মুড়ে নিচের নিয়মে কথা বললে IDCard Pro কোনো বদল ছাড়াই সেই স্ক্যানার ব্যবহার করতে পারে। ব্রাউজার শুধু এই কম্পিউটারের ঠিকানায় (127.0.0.1) কথা বলে; এজেন্ট কিছু জমায় না, বাইরে কিছু পাঠায় না।

IDCard Pro captures biometrics in the browser. Scanners whose maker offers no browser service are
connected through a **bridge agent**: a small program on the enrollment station that wraps the
vendor's SDK and answers the three HTTP endpoints below. Any language or SDK can be used; the
application only knows this protocol (driver `bridge` in `config/biometrics.php`, browser driver
`resources/js/lib/biometrics/drivers/bridge.ts`).

## Rules

- **Listen on the loopback address only** (`127.0.0.1`, default port `47800`). The browser refuses
  any address that is not on the same computer. A station can use another port: *Station settings*
  in the Biometrics panel (kept in that browser).
- **Store nothing and call nothing outside the computer.** The agent hands the capture to the
  browser, which sends it to the application; images and templates are encrypted there.
- **CORS:** answer with `Access-Control-Allow-Origin` set to the application's origin (an allow-list
  in the agent's own configuration is recommended; `*` only for testing). Requests are "simple"
  requests (GET, or POST with a `text/plain` body), so no preflight is needed. Chrome's local network
  access may still send an `OPTIONS` preflight from a public site: answer it with `204`,
  `Access-Control-Allow-Origin`, `Access-Control-Allow-Methods: GET, POST`,
  `Access-Control-Allow-Private-Network: true`. The first time, the browser may ask the operator to
  allow access to devices on the local network.
- **Bodies are JSON.** Requests carry JSON in a `text/plain;charset=UTF-8` body; answers are
  `application/json`. Binary data is base64 without a `data:` prefix.
- **One capture at a time.** A second capture while one is waiting answers `busy`.
- If the application ever sends a Content-Security-Policy, its `connect-src` must allow the
  station addresses (e.g. `http://127.0.0.1:*`).

## `GET /v1/info`

Whether the agent runs and which devices are ready. Called when a station opens the panel and on
*Check again* (2.5 s timeout).

```json
{
  "protocol": 1,
  "name": "Acme bridge",
  "version": "1.0.0",
  "devices": [
    { "id": "usb-1", "vendor": "ZKTeco", "model": "SLK20R", "serial": "ABC123", "modalities": ["fingerprint"], "ready": true }
  ]
}
```

- `protocol` must be `1`; another value shows "different version, update it".
- `modalities` use the registry's keys (`fingerprint`, `face`, `iris`, …). The station is ready when
  a device with `ready: true` supports the kind being captured.

## `POST /v1/capture`

Capture one position. The agent waits for the finger (or eye, or face) up to `timeout_ms`, then
answers. The browser gives up after 35 s.

Request:

```json
{
  "modality": "fingerprint",
  "position": "right_thumb",
  "image_formats": ["png", "jpeg", "bmp", "wsq"],
  "template_formats": ["iso-19794-2", "ansi-378", "iso-19794-2-2011"],
  "timeout_ms": 30000
}
```

- `position` is a registry key; its ISO/IEC 19794 code is in `config/biometrics.php`.
- `image_formats` / `template_formats`: what the application accepts for this kind, preferred
  first. Answer with one of them; `template_formats` may be empty (image only).

Answer when captured:

```json
{
  "ok": true,
  "image": { "format": "png", "base64": "iVBORw0KGgo…", "width": 300, "height": 400, "dpi": 500 },
  "template": { "format": "iso-19794-2", "base64": "Rk1SACAyMAAA…" },
  "quality": 72,
  "device": { "vendor": "ZKTeco", "model": "SLK20R", "serial": "ABC123" }
}
```

- `quality`: 0–100 (higher is better), or `null` when the device cannot tell. Convert vendor
  scales (e.g. NFIQ 1–5) to 0–100. The station compares it with the organization's minimum and asks
  for another try itself — the agent does not retry.
- `template` is optional; leave it out (or `null`) for image-only devices.
- The image must really be the named format (the server checks the first bytes) and at most
  `max_image_kb` (2048 KB by default).

Answer when not captured (HTTP 200):

```json
{ "ok": false, "error": "no_finger", "message": "No finger detected" }
```

| `error` | Meaning shown to the operator |
|---|---|
| `no_finger` | Nothing was placed on the scanner in time |
| `timeout` | The device did not answer in time |
| `busy` | Another capture is running |
| `no_device` | No scanner for this kind is connected and ready |
| `not_supported` | The device cannot capture this kind or position |
| `cancelled` | Stopped by `/v1/cancel` |
| `device` | Any other device or SDK error (`message` helps support) |

## `POST /v1/cancel`

Body `{}`. Stop a waiting capture (the operator pressed cancel or left the page); the waiting
`/v1/capture` answers `cancelled`. Answer `{ "ok": true }` even when nothing was running.

## Testing without hardware

The browser driver is checked with faked agent answers (Chrome DevTools Protocol `Fetch`), see the
QA notes in `docs/PROGRESS.md`. An agent can be checked the same way from its side with `curl`:

```bash
curl -s http://127.0.0.1:47800/v1/info
curl -s -H 'Content-Type: text/plain' -d '{"modality":"fingerprint","position":"right_thumb","image_formats":["png"],"template_formats":[],"timeout_ms":10000}' http://127.0.0.1:47800/v1/capture
```
