# IDCard Pro: কাজের অগ্রগতি ও হ্যান্ডঅফ

> নতুন চ্যাট বা নতুন ডেভেলপার এই ফাইল থেকে শুরু করবেন। পুরো পরিকল্পনা আছে [ROADMAP.md](ROADMAP.md)-এ।
> সর্বশেষ আপডেট: ১০ অক্টোবর ২০২৬। **Phase 1, 2, 3 সম্পন্ন।** **Phase 4 (বায়োমেট্রিক) চলছে:** ৪.১ সেটিং, ৪.২ এনক্রিপ্ট করা সংরক্ষণ, ৪.৩ এনরোলমেন্ট API (পুরো ব্যাকএন্ড) আর ৪.৪ Organization পাতায় সেটিংয়ের UI সম্পন্ন; **পরের কাজ ৪.৫** (ব্রাউজারের ড্রাইভার কাঠামো)। ৩০৯টা টেস্ট।
> **এখান থেকে শুরু:** ঠিক নিচের "পরের চ্যাট এখান থেকে শুরু (৯ অক্টোবর রাত, Phase 4)" — সেখানে চালুর কমান্ড, পুরো প্রজেক্টের অবস্থা, Phase 4-এর ধাপের টেবিল (একমাত্র তালিকা), API-র বিবরণ আর সাবধানতা। তার নিচের "আগামীকাল এখান থেকে শুরু (৯ অক্টোবর)" আর পুরনো তারিখের অংশগুলো ইতিহাস (Phase 3)। শেষ commit: `git log --oneline -1`।

### পরের চ্যাট এখান থেকে শুরু (৯ অক্টোবর রাত, Phase 4) — নতুন চ্যাটের জন্য

> **এক লাইনে:** Phase 1–3 সম্পন্ন। এখন **Phase 4 (বায়োমেট্রিক)** চলছে — ১১ ধাপের মধ্যে **৪.১–৪.৪ সম্পন্ন** (পুরো ব্যাকএন্ড + Organization পাতায় "Biometrics" কার্ড, ১০ অক্টোবর)। **পরের কাজ ৪.৫** (ব্রাউজারের ড্রাইভার কাঠামো: simulator + upload)। কার্ডধারীর বায়োমেট্রিক ট্যাব এখনো নেই (৪.৬)। ৩০৯টা টেস্ট, phpstan পরিষ্কার।

**১. চালু করে মিলিয়ে নিন:**
```bash
cd /var/www/php74/idcard/idcard-pro && docker compose up -d && docker compose ps --format '{{.Service}} {{.State}}'
git log --oneline -1                                           # শেষ commit: ৪.৪-এর পরের docs commit (তার আগে 3b812c2)
docker compose exec app php artisan migrate --no-interaction   # নতুন মেশিনে: biometric_captures টেবিল
docker compose exec app php artisan app:sync-permissions       # নতুন মেশিনে: biometrics.* পারমিশন (Owner পায়)
grep -c '^BIOMETRIC_KEY=base64:' .env                          # 1 হওয়া উচিত; না থাকলে: docker compose exec app php artisan app:biometric-key
docker compose exec app php artisan test --compact             # ৩০৯টা টেস্ট পাস হওয়ার কথা
docker compose run --rm --no-deps vite npm run build           # ফ্রন্টএন্ড (vue-tsc + vite)
```
**সাবধান — BIOMETRIC_KEY:** dev `.env`-এ বসানো (৯ অক্টোবর)। সরাসরি কখনো বদলাবেন না — পুরনোটা `BIOMETRIC_PREVIOUS_KEYS`-এ রেখে তবেই `app:biometric-key --force`, নইলে সংরক্ষিত বায়োমেট্রিক আর পড়া যাবে না (কমান্ড নিজেও `--force` ছাড়া বদলায় না)।

**২. পুরো প্রজেক্ট কোথায় (ROADMAP §৪):**

| Phase | অবস্থা |
|---|---|
| 1 ভিত্তি, 2 Master data, 3 কার্ড | ✅ সম্পন্ন (৩ ও ৯ অক্টোবর) |
| **4 বায়োমেট্রিক** | 🔄 চলছে — নিচের ধাপের তালিকা |
| 5 অপারেশন (অ্যাপয়েন্টমেন্ট + ইমেইল/SMS, রিপোর্ট — পুরনো Card Print History আর Expired card রিপোর্ট এখানে, মেয়াদের রিমাইন্ডার) | ⬜ |
| 6 ইন্টিগ্রেশন (webhooks, API token UI, SSO, পুরনো ডেটা মাইগ্রেশন, HR sync) | ⬜ |

**৩. Phase 4-এর ধাপ (এটাই একমাত্র তালিকা — নতুন কিছু যোগ হলে এখানেই):**

| ধাপ | কী | অবস্থা |
|---|---|---|
| ৪.১ | `config/biometrics.php` রেজিস্ট্রি + প্রতিষ্ঠানের সেটিং `tenants.settings.biometrics` (API `/settings`) | ✅ `3bbca00`, iris `27c6d45` |
| ৪.২ | টেবিল `biometric_captures`, `BiometricVault` (AES-256-GCM, নিজস্ব চাবি), `app:biometric-key`, মুছলে ফাইলও মোছে | ✅ `b58c8b5` |
| ৪.৩ | এনরোলমেন্ট API + শর্তের হিসাব (`BiometricRequirement`) + পারমিশন `biometrics.view/enroll/delete` | ✅ `d898d3b` (সাথে audit ফিল্টারের ফাঁক `b7d0c8b`) |
| ৪.৪ | Organization পাতায় "বায়োমেট্রিক" কার্ড: চালু/বন্ধ; প্রতিটা ধরন (`biometric_options.modalities` থেকে লুপ — আঙুল/মুখ/চোখ কোডে লেখা নয়) বন্ধ/ঐচ্ছিক/বাধ্যতামূলক, অবস্থান বাছাই (আঙুলের জন্য দুই হাতের ছবি/গ্রিড), কয়টা, stand-in, ন্যূনতম quality, চেষ্টা, ড্রাইভার; পরিবারের সদস্য; কখন লাগবে (issue/print); কার্ডে ছাপা। পাঠানো: `biometrics[...]` FormData-তে (তালিকা না পাঠালে = কিছু নয়)। en/bn লেখা। ব্রাউজারে যাচাই + ফোনের মাপ। | ✅ ক `bde20ce` (রেজিস্ট্রিতে অবস্থানের `groups` — ডান/বাঁ হাত — আর ড্রাইভারের `testing` API-তে; **বাগ ঠিক:** বায়োমেট্রিক বন্ধ থাকলে রাখা সেটিংয়ের ধরনভিত্তিক যাচাই আর Organization পাতার সেভ আটকায় না, চালু করার সময় সব যাচাই হয়), খ `3b812c2` (`BiometricSettingsCard.vue` — নিচে ৫-এ বিবরণ) |
| ৪.৫ ⬜ **পরের কাজ** | ব্রাউজারের ড্রাইভার কাঠামো `resources/js/lib/biometrics/`: interface `{ id, modalities, probe(), capture({modality, position}) → {imageBase64, imageFormat, templateBase64?, templateFormat?, quality?, width?, height?, dpi?, device?} }`, রেজিস্ট্রি (নাম = `config/biometrics.php`-এর driver key), প্রথমে `simulator` (canvas-এ বানানো ছবি + এলোমেলো quality) আর `upload` | ⬜ |
| ৪.৬ | কার্ডধারীর পাতায় "বায়োমেট্রিক" ট্যাব (শুধু `biometrics.view` থাকলে আর প্রতিষ্ঠানে চালু থাকলে): `meta.status` দেখানো (কোনটা বাকি — `next`), অবস্থান বাছাই, ড্রাইভার বাছাই (স্টেশনের পছন্দ localStorage-এ), quality-র দণ্ড, কম quality হলে আবার চেষ্টা/রাখা, "পাওয়া যায়নি" + কারণ, মোছা, পরিবারের সদস্য (সেটিং চালু থাকলে) | ⬜ |
| ৪.৭ | আসল স্ক্যানার ড্রাইভার: SecuGen (WebAPI, `https://localhost:8443`), DigitalPersona, ZKTeco + নিজস্ব bridge agent-এর নিয়ম `docs/BIOMETRIC_BRIDGE.md`; হার্ডওয়্যার ছাড়া CDP Fetch দিয়ে নকল উত্তরে যাচাই; লোকাল সার্ভিসের ঠিকানা স্টেশনে বদলানো যায় | ⬜ |
| ৪.৮ | মুখ (ওয়েবক্যাম `getUserMedia`) — একই ড্রাইভার কাঠামোয়; চোখের মণি bridge দিয়ে | ⬜ |
| ৪.৯ | সেটিং `required_before` অনুযায়ী ইস্যু/প্রিন্টের আগে বাধা (`BiometricService::status()` → `complete`), কার্ডধারীর তালিকায় "বায়োমেট্রিক সম্পূর্ণ/অসম্পূর্ণ" ফিল্টার | ⬜ |
| ৪.১০ | ডিজাইনারে আঙুলের ছাপের ঘর (শুধু `print_on_card` চালু আর modality `printable` হলে), প্রিন্ট/PDF-এ ডিক্রিপ্ট করা ছবি | ⬜ |
| ৪.১১ | Phase 4 শেষের পরীক্ষা (সব সেটিং বদলে বদলে, পরিবারের সদস্য, পারমিশন, ফোনের মাপ) | ⬜ |
| পরে | ১:১ মিলিয়ে দেখা আর ডুপ্লিকেট খোঁজা — matcher লাগবে (যেমন SourceAFIS আলাদা container-এ); **ইউজারকে জিজ্ঞেস করে** | ⬜ |

**৪. ইউজারের সিদ্ধান্ত আর স্থায়ী নির্দেশ (Phase 4):**
- সব স্ক্যানারের ব্যবস্থা থাকবে; কে কোনটা কখন ব্যবহার করবে — প্রতিষ্ঠানের সেটিং (প্রতিটা ধরনের `drivers`) + স্টেশনের পছন্দ।
- ছবি + টেমপ্লেট দুটোই, এনক্রিপ্ট করে।
- কোন আঙুল, কয়টা (১/২/৩…), আঙুল না থাকলে কী, মুখ/চোখের মণি থাকবে কিনা, কখন লাগবে — **সব প্রতিষ্ঠানের সেটিং থেকে**; আঙুল, মুখ, চোখের মণি সব **একইভাবে ডাইনামিক** (৯ অক্টোবর রাতে পরীক্ষা করা হয়েছে: `app/`, `resources/js/`, `lang/`, `routes/`-এ কোনো ধরন/অবস্থান/ড্রাইভারের নাম নেই; শুধু অর্থবাহী মান `off/optional/required` আর `issue/print`)। **নতুন প্রতিটা ধাপও এভাবে করতে হবে** — UI-তেও ধরন/অবস্থানের তালিকা API (`biometric_options`) থেকে।
- ডিজাইনারে আঙুলের ছাপের ঘর, প্রতিষ্ঠানের সেটিংয়ে চালু-বন্ধ (ডিফল্ট বন্ধ)।
- পুরনো সিস্টেমে আসল বায়োমেট্রিক ছিল না (`personal_infos.finger` = Lost/Promotion কার্ডের "Add Document" ছবি, ৩.৫-এ আছে) — তাই পুরনোর কিছু বাদ পড়ার প্রশ্ন নেই।

**৫. ব্যাকএন্ড যেভাবে কাজ করে (৪.৪–৪.৬-এর জন্য দরকারি):**
- **সেটিং** `GET/POST /api/v1/settings`: `data.biometrics` = `{enabled, modalities: {<ধরন>: {mode, count, positions[], substitutes, min_quality, max_attempts, drivers[]}}, family_members, required_before[], print_on_card}`; `data.biometric_options` = `{modalities: {<ধরন>: {positions[], printable, drivers[]}}, drivers: {<নাম>: {modalities[], local_url}}, modes[], required_before[], unavailable_reasons[]}`। সেভে `biometrics` পাঠালে: না পাঠানো ধরন আগের মতো; পাঠানো ধরনে `positions`/`drivers` না এলে = কিছু নয়; `required_before` না এলে = কিছু নয় (`BiometricSettings::merge()`)। নিয়ম: চালু ধরনে অবস্থান আর ড্রাইভার লাগবে; stand-in ছাড়া `count` ≤ অবস্থান; চালু করলে কমপক্ষে একটা ধরন। simulator শুধু production-এর বাইরে (`BIOMETRIC_SIMULATOR`)।
- **এনরোলমেন্ট** (সব `?family_member=<id>` বা বডিতে `family_member` নেয়):
  - `GET /cardholders/{id}/biometrics` (`biometrics.view`) → `data[]` চলতি capture + `meta.status`।
  - `POST /cardholders/{id}/biometrics` (`biometrics.enroll`) → `modality, position, driver, image_base64` (data URL চলে) **বা** `image` ফাইল, `image_format` (png/jpeg/bmp/wsq — রেজিস্ট্রির `image_types`, প্রথম বাইট মিলিয়ে দেখা), ঐচ্ছিক `template_base64` + `template_format`, `quality` (০–১০০, null = জানা নেই), `attempts`, `accept_low_quality`, `width/height/dpi`, `device{vendor,model,serial}` → 201 + `meta.status`।
  - quality < `min_quality`: `attempts` < `max_attempts` হলে 422 `quality` ("আবার চেষ্টা, আর n বার"); শেষ চেষ্টায় `accept_low_quality` ছাড়া 422 ("তবুও রাখবেন?"); দিলে রাখা হয়, `low_quality: true`।
  - `POST /cardholders/{id}/biometrics/unavailable` → `modality, position, reason` (রেজিস্ট্রির `unavailable_reasons`; `other`-এ `note` লাগবে — `unavailable_reasons_with_note`)।
  - `DELETE /cardholders/{id}/biometrics/{capture}` (`biometrics.delete`) → রেকর্ড আর ফাইল মোছে।
  - ছবি: resource-এর `image_url` = ৫ মিনিটের signed link (`/api/v1/biometric-images/{id}`, `Cache-Control: no-store`); WSQ ব্রাউজারে দেখা যায় না বলে `image_url` null। টেমপ্লেটের বাইট কখনো API-তে আসে না (`has_template`, `template_format` শুধু)। `position_code` = ISO কোড (অন্য সিস্টেমের জন্য)।
  - একই অবস্থানে আবার নিলে আগেরটা ইতিহাসে যায় **ডেটা ছাড়া** (ছবি/টেমপ্লেট মুছে যায়, শুধু কবে কী নেওয়া হয়েছিল থাকে) — গোপনীয়তার জন্য।
- **`meta.status`** (`BiometricRequirement::evaluate()`): `{enabled, applies (পরিবারের সদস্য আর family_members বন্ধ = false), complete, modalities: {<ধরন>: {mode, required, needed, captured[], unavailable[], counted, complete, with_exceptions, next[]}}}`। পূরণ = গোনা অবস্থান ≥ `count` (stand-in চালু হলে যেকোনো আঙুল গোনে); সম্ভাব্য সব অবস্থান নেওয়া/"পাওয়া যায়নি" হলে **ব্যতিক্রমসহ পূরণ** (আঙুল নেই এমন মানুষ আটকাবেন না); ঐচ্ছিক ধরন কখনো আটকায় না। ৪.৯-এ ইস্যু/প্রিন্টের বাধা এটাই ব্যবহার করবে।
- **সেটিংয়ের UI (৪.৪):** `resources/js/pages/settings/BiometricSettingsCard.vue` (Organization পাতায়, `v-model` = `data.biometrics`, `options` = `data.biometric_options`)। ধরন/অবস্থান/ড্রাইভার/mode/সময় সব `biometric_options` থেকে লুপ; অবস্থান রেজিস্ট্রির `groups` অনুযায়ী সারিতে (দলে না থাকা অবস্থান শেষ সারিতে — `lib/biometrics/labels.ts` → `positionRows()`), চাপলে বাছাই, সংখ্যা = পছন্দের ক্রম, ক্রমের তালিকায় উপর/নিচ; একটাই অবস্থান থাকলে (মুখ) বাছাই/count/stand-in দেখায় না আর চালু করলে নিজে বাছাই হয়; stand-in বন্ধ হলে বা অবস্থান কমালে count নিজে সীমায় আসে; ড্রাইভারের পছন্দের ক্রম থাকে (নতুনটা শেষে), simulator-এ "Test only", স্ক্যানারের লোকাল ঠিকানা দেখায়; "কার্ডে ছাপা" শুধু `printable` ধরন থাকলে। পাঠানো `lib/biometrics/settings.ts` → `appendBiometricSettings()`: বন্ধ থাকলে শুধু enabled/family/print/required_before (ধরনগুলো আগের মতো থাকে)। লুকানো অংশের এরর (বন্ধ অবস্থায় বা বন্ধ ধরনে) দৃশ্যমান জায়গায় দেখায়। নামের লেখা `biometrics.{modalities,positions,groups,drivers,modes,reasons}.<key>` (en/bn locale-এ); অনুবাদ না থাকলে key থেকে লেখা ("right_palm" → "Right palm") — `biometricLabel()`, ৪.৬-এও এটাই ব্যবহার করবেন।
- **ফাইল:** `app/Services/Biometrics/{BiometricSettings,BiometricVault,BiometricRequirement,BiometricService}.php`, `BiometricCaptureController`, `Requests/Biometrics/*`, `BiometricCaptureResource`, `Data/Biometrics/CaptureData`, `BiometricCaptureRepository`, টেস্ট `tests/Feature/Biometrics/{BiometricSettingsTest,BiometricStorageTest,BiometricEnrollmentTest}.php`।

**৬. জানা সাবধানতা (এই সেশনে পাওয়া):**
- MySQL-এ index-এর নাম সর্বোচ্চ ৬৪ অক্ষর; টেস্ট sqlite-এ চলে বলে ধরা পড়ে না — লম্বা composite index-এ নিজে নাম দিন, migration dev MySQL-এ চালিয়ে দেখুন।
- টেস্টে `Sanctum::actingAs($user)`-এর ইউজার অবজেক্ট সব request-এ একই, তাই `$user->tenant` cache থাকে — টেস্টের মাঝে প্রতিষ্ঠানের সেটিং বদলালে `$user->unsetRelation('tenant')` (আসল চালনায় সমস্যা নেই)।
- নতুন পারমিশন `app:sync-permissions`-এ শুধু **Owner** নিজে পায়; আগে থেকে থাকা প্রতিষ্ঠানের Administrator/Operator রোলে (`default_roles`) নতুন পারমিশন নিজে থেকে যায় না (নতুন প্রতিষ্ঠানে যায়) — রোল পাতা থেকে দিতে হয়। dev-এ Acme-এর Administrator/Operator-এর `biometrics.*` নেই। (আগের মডিউলগুলোতেও একই আচরণ; বদলাতে চাইলে ইউজারকে জিজ্ঞেস।)
- audit-এর "কী" ফিল্টার এখন API-র `meta.log_names` থেকে (নতুন মডিউল নিজে থেকে আসে); লেখা `audit.logs.<নাম>` (না থাকলে নামটাই)।

**৭. dev ডাটাবেসের অবস্থা:** `biometric_captures` খালি; Acme-এর বায়োমেট্রিক সেটিং সংরক্ষিত নেই (ডিফল্ট = বন্ধ; ৪.৪-এর ব্রাউজার যাচাইয়ে সেভ CDP Fetch দিয়ে নকল উত্তরে করা হয়েছে, ডেটাবেসে কিছু লেখা হয়নি — স্ক্রিপ্ট scratchpad-এ `qa-bio-settings.mjs` ধরনে); পারমিশন sync করা (৪৩টা)। ব্রাউজারে যাচাই (৯ অক্টোবর রাত): audit পাতার "কী"-তে Acme-এর ১০ ধরন, "Cardholders" দিয়ে ছাঁকা কাজ করে; রোল ডায়ালগে "Biometrics" + "Enroll" — কিছু সেভ করা হয়নি। বাকি সব আগের মতো (নিচে "আগামীকাল এখান থেকে শুরু (৯ অক্টোবর)" → ৪)।

**৮. পরের চ্যাটে কী করবেন:** ৪.৫ থেকে শুরু (উপরের টেবিল)। প্রতিটা ধাপ: আগে বাংলায় বলা → কোড (Controller → Service → Repository; UI-তে ধরন/অবস্থান API থেকে) → টেস্ট + pint + phpstan + build → ব্রাউজারে যাচাই (`.claude/qa/cdp.mjs` scratchpad-এ কপি করে) → commit (ইউজারের git পরিচয়, AI attribution নয়) → এই টেবিলে ✅ + commit → বাংলায় জানানো।

### আগামীকাল এখান থেকে শুরু (৯ অক্টোবর) — নতুন চ্যাটের জন্য

**১. আগে চালু করুন আর মিলিয়ে নিন** (বিস্তারিত নিচে "কাল শুরুর কমান্ড"):
```bash
cd /var/www/php74/idcard/idcard-pro && docker compose up -d && docker compose ps --format '{{.Service}} {{.State}}'
git log --oneline -1                                    # শেষ commit (৮ অক্টোবরের শেষে: docs commit, তার আগে e588781)
docker compose exec app php artisan migrate --no-interaction   # ৮ অক্টোবরের ২টা migration চালানো আছে; নতুন মেশিনে লাগবে
docker compose exec app php artisan test --compact      # ২৪৮টা টেস্ট পাস হওয়ার কথা
```

**২. ৮ অক্টোবরে কী হলো (ক্রমে; প্রতিটার বিস্তারিত নিচের "৮ অক্টোবর ২০২৬"-এ):**

| commit | কী |
|---|---|
| `cc6b1b1`, `e618018` | ৩.৬ ধাপ ৪গ: `/cards`-এ "এই তালিকার (সব পাতার) ছাপা যায় এমন সব কার্ড বাছাই" (`GET /cards/printable`) |
| `1424cbd`, `3dcf438` | ৩.৬ ধাপ ৬: বারকোড ফাঁকা ছাপা হবে এমন হলে ইস্যু/প্রিন্টের আগে সতর্কতা (UI + সার্ভার `meta.warnings`); ছাপায় লাল ভুল-বক্স আর নয় |
| `da7a43f`, `2c653b7` | কার্ডে **আসল QR** (npm `qrcode-generator`, UTF-8, পুরনো "লেবেল:মান" লাইন, error correction ডিফল্ট H) + QR-এর সতর্কতা |
| `b9bfae6` | npm audit-এর critical (`shell-quote`) ঠিক |
| `8d014b5` | বাগ: ডার্ক মোডে ব্র্যান্ড রঙের লেখা অদৃশ্য → `brand-ink` token (**নতুন কোডে `text-brand` নয়, `text-brand-ink`**) |
| `7ffffcc` | বাগ: অন্য ট্যাবে ছাপার পরে বিস্তারিত/তালিকা পুরনো থাকত → BroadcastChannel `card-prints` |
| `40a7dba` … `d877624` | **৩.৭ কার্ড যাচাই** (ক কোড + সেটিং, খ পাবলিক API, গ সেটিংয়ের পাতা, ঘ পাবলিক পাতা `/v/{code}`, ঙ যাচাইয়ের লগ) |
| `e588781` | কার্ড থাকলে কার্ডধারীর মোছার বোতাম আগে থেকেই বন্ধ (কারণসহ) |

**৩. ইউজারের স্থায়ী নির্দেশ (৮ অক্টোবর):** কোনো জানা বাগ বা পুরনো সিস্টেমের ফাঁক রেখে সামনে যাওয়া যাবে না — পাওয়া মাত্র ঠিক করা; ভালো সমাধানে লাইব্রেরি/সেটিং লাগলে যোগ করা যায় (কোনটা আর কেন, বাংলায় বলে)। প্রতিষ্ঠান-নির্দিষ্ট আচরণ **প্রতিষ্ঠানের নিজের সেটিং** থেকে (৩.৭-এর মতো)। ছোট ধাপ → টেস্ট → commit (ইউজারের git পরিচয়, AI attribution নয়) → PROGRESS → বাংলায় জানানো।

**৪. dev ডাটাবেসের অবস্থা:**
- Acme-এর কার্ড যাচাই **বন্ধ** (পরীক্ষার পরে আগের সেটিং হুবহু ফেরানো)। দেখাতে চাইলে Organization পাতা → "কার্ড যাচাই" চালু → টেমপ্লেটের QR-এ "যাচাইয়ের লিংক" ঘর → প্রিন্ট → স্ক্যান।
- ইউজারের কার্ড **DEMO/26000001** — কখনো মুছবেন না, লগ লিখবেন না (৮ অক্টোবর migration-এ শুধু `verify_code` বসেছে)।
- পরীক্ষার কার্ড = "QA designer check" টেমপ্লেটের সব কার্ড (QA/26000001–3); QA/26000001 আর QA/26000002-এ একটা করে যাচাইয়ের লগ। মোছার কমান্ড নিচে ("পরীক্ষার কার্ড মোছা"; `card_verifications` cascade-এ মুছে যায়)।
- ব্রাউজার-যাচাই: `idcard-pro/.claude/qa/cdp.mjs` (এখন `b.on(listener)`-সহ) scratchpad-এ কপি করে; QR পড়তে scratchpad-এ `npm install jsqr pngjs` (প্রজেক্টে নয়); DB-তে না লিখে যাচাই করতে CDP `Fetch` দিয়ে উত্তর বদলানো/নকল 201।

**৫. পরের কাজ (ক্রমে):**

**(ক) ৩.৮ ইমপোর্টে কার্ডের কলাম** — পুরনো সিস্টেমের ফাঁক (এখন ইমপোর্টের প্রিভিউতে এগুলো "বাদ পড়া কলাম")। পুরনো `ExcelController` দেখা হয়েছে (৮ অক্টোবর): CSV-এর কলাম ০ = card no, ৯ = issue date, ১০ = last print date, ১১ = last print time, ১২ = notes; টেমপ্লেট **হার্ডকোড 44**, মেয়াদ **হার্ডকোড ২ বছর**, ছাপার ইউজার **হার্ডকোড 10**, `card_get = new`, আর **বাগ: সময় `'m:i:s'`** (ঘণ্টার জায়গায় মাস)। নতুন নকশার প্রস্তাব (ছোট ভাগে):
   1. ব্যাকএন্ড: `config/cardholder_import.php`-এ কার্ডের কলাম (`card no`/`card_id_number`, `issue date`, `expiry date`, `last print date`, `last print time`, `card notes`, `rf card no`) + ইমপোর্টে ঐচ্ছিক "কার্ডও ইমপোর্ট করো" আর **কোন টেমপ্লেটে** (প্রিভিউতে বাছাই; হার্ডকোড নয়), কারণ `new`। মেয়াদ না থাকলে টেমপ্লেটের `validity_months` থেকে। প্রতি সারিতে `CardIssueService`-এর নতুন পথ (যেমন `importCard()`): দেওয়া নম্বর হুবহু, layout/values snapshot আজকের টেমপ্লেট আর কার্ডধারীর তথ্য থেকে, `verify_code` নিজে বসে। শেষ ছাপা থাকলে `card_prints`-এ একটা সারি (`method: import` — `config cards.print.methods`-এ যোগ, copies ১, `printed_at` = তারিখ+সময় প্রতিষ্ঠানের timezone-এ) আর `print_count`/`last_printed_at`। একই নম্বর যেন সিকোয়েন্সের সাথে না মেলে: নম্বর প্রতিষ্ঠানের ফরম্যাটে পড়লে `card_sequences` সেই মান পর্যন্ত এগিয়ে নেওয়া। ভুল সারি প্রিভিউতে দেখানো (তারিখ, নম্বর অন্যের সক্রিয় কার্ডে)।
   2. UI: ইমপোর্টের প্রিভিউতে "কার্ডও ইমপোর্ট" টিক + টেমপ্লেট বাছাই + কত কার্ড হবে; ফলাফলে কার্ডের সংখ্যা।
   3. টেস্ট (পুরনো ধাঁচের CSV, সময়ের বাগ নেই, ভুল সারি) + ব্রাউজারে।
   - **অগ্রগতি (৯ অক্টোবর):** ✅ ক-১ নম্বর পড়া আর সিকোয়েন্স এগোনো (`CardNumberFormat::parse()` → `{key, sequence}`, `CardSequenceRepository::advanceTo()` শুধু উপরে যায়, `CardNumberService::claim()`)। ✅ ক-২ `CardIssueService::checkImport()` (ভুলের তালিকা + `skip`; নতুন কার্ডধারী = null) আর `importCard()` (ইস্যুর সাথে একই `createCard()`; reason `new`; শেষ ছাপা থাকলে `CardPrintService::recordPast()` → `card_prints` `method: import` (`CardPrint::METHOD_IMPORT`, API-র প্রিন্টে নেওয়া হয় না), `printed_by` null; ছাপার তারিখ প্রতিষ্ঠানের timezone-এ ইস্যুর তারিখের সাথে মেলানো; আরও নতুন কার্ড থাকলে ভুল `cards.import.newer_card`), DTO `ImportCardData`, `CardRepository::numberInUse()`; টেস্ট `CardImportTest`। ✅ খ ইমপোর্টে কার্ড (ব্যাকএন্ড): `config/cardholder_import.php`-এ `card_columns` (card no/card id, issue/expiry date, RF, last print date/time, card notes = পুরনো "Print Reason") আর **পুরনো CSV-র হেডারের alias** (Officer Name, Service ID, Rank Name, Branch Name, Comm Date → joining date — আগে এগুলো চিনত না, পুরনো ফাইলে নামের কলামই পেত না); বিকল্প `import_cards` + `card_template` (সক্রিয়, কার্ডধারীর টেমপ্লেট; **`cards.issue` পারমিশনও লাগে**); `CardholderImportMapper::toCard()` (তারিখ/সময়: 14:05:09, 2:30 PM, Excel ভগ্নাংশ, একই ঘরে তারিখ+সময়; প্রতিষ্ঠানের timezone; পুরনো `m:i:s` বাগ নেই); প্রতি সারিতে কার্ডধারী + কার্ড একসাথে (একটা ভুল হলে দুটোই নয়), ফাইলে একই কার্ড নম্বর দুবার = ভুল; `summary.cards` {imported, skipped}, `summary.card_columns`, `summary.card_template`, চালানোর পরে `summary.cards_result`; কার্ড ইমপোর্ট বন্ধ থাকলে কার্ডের কলাম "বাদ পড়া"-তে; টেমপ্লেট CSV-তে কার্ডের কলাম। টেস্ট `CardholderCardImportTest`। ✅ গ+ঘ UI আর ব্রাউজার: `CardholderImportPage.vue`-তে "তাদের কার্ডও ইমপোর্ট করুন" (শুধু `cards.issue` থাকলে) + টেমপ্লেট বাছাই (সক্রিয়, কার্ডধারীর), কার্ডের সারাংশ (কত আসবে/আগেই আছে → চালানোর পরে কত এল), ম্যাপিংয়ে কার্ডের কলামের নাম, প্রিভিউর সারিতে "+ কার্ড"/"কার্ড আগেই আছে", কার্ড ইমপোর্ট বন্ধ থাকলে কার্ডের কলাম নিয়ে পরামর্শ, ইতিহাসে কার্ডের সংখ্যা। ব্রাউজারে পুরনো ধাঁচের CSV দিয়ে যাচাই (ম্যাপিং, ২ কার্ড, ফাইলে একই নম্বর আর ভুল সময় = ভুল সারি; শেষের অবস্থা CDP Fetch-এর নকল উত্তরে — আসল ইমপোর্ট চালানো হয়নি, বিশ্লেষণ দুটো মুছে দেওয়া হয়েছে; ফোনের মাপ ঠিক)। **৩.৮ সম্পন্ন।**
   - **ইউজারের সিদ্ধান্ত (৯ অক্টোবর, তিনটাই সুপারিশ):** (১) ফরম্যাটে না পড়লেও নম্বর **হুবহু** নেওয়া হবে (ফরম্যাটে পড়লে সিকোয়েন্স এগিয়ে যাবে; অন্যের সক্রিয় কার্ডের নম্বর = ভুল সারি)। (২) কার্ডধারীর সক্রিয় কার্ড থাকলে: **একই নম্বর হলে কার্ড বাদ** (আবার চালালেও ক্ষতি নেই), **আলাদা নম্বর হলে পুরনোটা "replaced"** আর নতুনটা সক্রিয়, ইস্যুর মতোই। (৩) শেষ ছাপার তারিখ না থাকলে কার্ড **"এখনো ছাপা হয়নি"** (PENDING); তারিখ থাকলে `card_prints`-এ `method: import`-এর একটা সারি।
**(খ) Phase 3 শেষের পরীক্ষা (৯ অক্টোবর চলছে):** পুরনো সিস্টেমের সাথে মেলানো — রিপোর্ট দুটো (Card Print History, Expired card, Print/Excel) ROADMAP অনুযায়ী Phase 5; কার্ডের ট্যাব (কারণ, নোট, Add Document, টেমপ্লেট, নম্বর, RF, তারিখ) সব আছে; **ফাঁক পাওয়া ও ঠিক:** কার্ডধারীর তালিকায় কার্ড নম্বর/RF নম্বর দিয়ে খোঁজা (পুরনো তালিকায় ছিল) — `CardholderFilter::searchCards` (শুধু `cards.view` থাকলে; নিজের বা পরিবারের যেকোনো কার্ড)। **আরেকটা ফাঁক ঠিক:** পুরনো সিস্টেমে প্রথম কার্ডের পরে "New" কারণ আর দেখাত না — এখন `CardIssueService::issue()`-এ নিয়ম (যে holder-এর আগে কার্ড ছিল তার "new" → ভুল `cards.issue.new_not_first`; `CardRepository::existsForHolder()`; পরিবারের প্রতিটা সদস্য আলাদা holder; ইমপোর্ট আগের মতো `new`) আর ইস্যু ডায়ালগে "New" লুকানো, ডিফল্ট "Renewal" (`previousHolders` prop)। ✅ **ব্রাউজারে পুরো পথ** (৯ অক্টোবর): ডিজাইনার খোলে (কিছু সেভ নয়) → কার্ডধারী #31-কে UI থেকে QA টেমপ্লেটে "Renewal" ইস্যু (নম্বর QA/26000002 রাখা, আগেরটা replaced; ডায়ালগে "New" নেই) → প্রিন্ট পাতা ("ঠিকমতো ছাপা হয়েছে?" নিশ্চিত করলে লগ `browser`, `print_count` ১) → সার্ভারের PDF (Gotenberg, ২ পাতা 85.6×54 mm, record=0-তে লগ নয়) → দুই PDF-এর QR পড়া (`ID:ACME-0031` + নাম) → Organization পাতায় যাচাই চালু → লগইন ছাড়া ফোন-মাপে `/v/{code}` "Valid card" (noindex, ঢাকার সময়), replaced কার্ড "Card replaced", revoked "Revoked card", যাচাইয়ের লগ ১ → সেটিং হুবহু ফেরানো (`verification` আর Save-এ ঢোকা `card_number_on_reissue: keep` দুটোই সরানো; এখন শুধু `default_card_size`, `card_number_format`)। dev DB-তে নতুন: QA টেমপ্লেটের আরেকটা কার্ড QA/26000002 (১ প্রিন্ট, ১ যাচাই) — মোছার কমান্ডে পড়ে। scratchpad-এ QR পড়ার টুল: `qrtool/` (`npm install jsqr pngjs`, প্রজেক্টে নয়)। আগের পরিকল্পনা: পুরো কার্ডের পথ একবার (টেমপ্লেট → ইস্যু → প্রিন্ট/PDF → যাচাই) ব্রাউজারে, পুরনো সিস্টেমের সাথে মিলিয়ে বাদ পড়া কিছু আছে কিনা — তারপর ROADMAP-এ Phase 3 ✅।
**(গ) Phase 4: বায়োমেট্রিক (৯ অক্টোবর শুরু)।** পুরনো সিস্টেমে আসল বায়োমেট্রিক ছিল না — `personal_infos.finger` ঘরটা UI-তে শুধু Lost/Promotion কার্ডের "Add Document" ছবি (৩.৫-এ আছে); তাই পুরোটা নতুন। **ইউজারের সিদ্ধান্ত (৯ অক্টোবর):** (১) সব স্ক্যানারের ড্রাইভার থাকবে (SecuGen, DigitalPersona, ZKTeco, simulator, ছবি আপলোড, bridge) — কে কোনটা কখন ব্যবহার করবে প্রতিষ্ঠানের সেটিং থেকে; (২) ছবি + টেমপ্লেট (ISO 19794-2), এনক্রিপ্ট করে; (৩) কোন আঙুল, কয়টা, আঙুল না থাকলে ছাড়, মুখ স্ক্যান থাকবে কিনা, কখন লাগবে — সব প্রতিষ্ঠানের সেটিংয়ে; (৪) ডিজাইনারে আঙুলের ছাপের ঘর, প্রতিষ্ঠানের সেটিংয়ে চালু-বন্ধ (ডিফল্ট বন্ধ)। **ধাপ:** ৪.১ `config/biometrics.php` রেজিস্ট্রি (modality: আঙুল ১০ ISO অবস্থান + মুখ; ড্রাইভার) + সেটিং `tenants.settings.biometrics` · ৪.২ `biometric_captures` + আলাদা চাবিতে এনক্রিপ্ট করা ফাইল, সংস্করণ · ৪.৩ এনরোলমেন্ট API + পারমিশন `biometrics.*` (অডিটে বায়োমেট্রিক ডেটা নয়) · ৪.৪ Organization পাতায় সেটিং · ৪.৫ ব্রাউজারের ড্রাইভার কাঠামো + simulator + upload · ৪.৬ কার্ডধারীর "বায়োমেট্রিক" ট্যাব · ৪.৭ SecuGen/DigitalPersona/ZKTeco + bridge agent spec · ৪.৮ মুখ (ওয়েবক্যাম) · ৪.৯ সেটিং অনুযায়ী ইস্যু/প্রিন্টের আগে বাধা + তালিকার ফিল্টার · ৪.১০ ডিজাইনারে আঙুলের ছাপ · ৪.১১ শেষের পরীক্ষা। **অগ্রগতি:** ✅ ৪.১ (৯ অক্টোবর): `config/biometrics.php` (modalities: fingerprint ১০টা ISO 19794 অবস্থান + face; image/template ফরম্যাট; `printable`; drivers upload/simulator/webcam/secugen/digitalpersona/zkteco/bridge + `local_url`; `simulator` env `BIOMETRIC_SIMULATOR`, production-এ বন্ধ; unavailable_reasons; modes off/optional/required; required_before issue/print; defaults)। `App\Services\Biometrics\BiometricSettings` (`drivers()`, `driversFor()`, `merge()` — না পাঠানো modality আগের মতো, তালিকা না এলে = কিছু নয়; `normalize()`), `Tenant::biometricSettings()`, `UpdateTenantSettingsRequest`-এ নিয়ম + `after()` (চালু modality-তে অবস্থান আর ড্রাইভার লাগবে; stand-in ছাড়া count ≤ অবস্থান; চালু করলে কমপক্ষে একটা modality), `TenantResource.biometrics` + `biometric_options`; টেস্ট `BiometricSettingsTest` (২৮৪টা টেস্ট)। ✅ iris (৯ অক্টোবর): রেজিস্ট্রিতে `iris` (ISO/IEC 19794-6: right_iris 1, left_iris 2; ডিফল্ট বন্ধ; drivers bridge/upload/simulator); বার্তায় ধরনের নাম নেই; টেস্টে palm যোগ করে প্রমাণ যে কোড না বদলেই সেটিংয়ে আসে। ✅ ৪.২ (৯ অক্টোবর): টেবিল `biometric_captures` (holder = `cardholder_id` + `family_member_id`; modality, position, status captured/unavailable + কারণ + নোট, driver, device JSON, এনক্রিপ্ট করা `image_path`/`template_path` + ফরম্যাট/মাপ/dpi, quality, `low_quality`, attempts, captured_by/at, `replaced_at` = ইতিহাস, `current_key` unique = এক অবস্থানে একটাই চলতি; index-এর নাম `biometric_captures_holder_index` — MySQL-এর ৬৪ অক্ষরের সীমা, sqlite টেস্টে ধরা পড়ে না)। `BiometricCapture` (activity log-এ শুধু কী নেওয়া হলো, ফাইল/ডিভাইস নয়), factory, `BiometricCaptureRepository` (`currentFor`, `markReplaced`, `locationsFor`, `locationsForMember`)। `BiometricVault`: AES-256-GCM, নিজস্ব চাবি `BIOMETRIC_KEY` (APP_KEY নয়), `BIOMETRIC_PREVIOUS_KEYS` দিয়ে চাবি বদল, চাবি না থাকলে কিছুই রাখে না, এলোমেলো নাম `biometrics/{tenant}/{ulid}.bin`, disk `BIOMETRIC_DISK` (ডিফল্ট private local)। কমান্ড `php artisan app:biometric-key` (`--show`, `--force`; থাকা চাবি এমনিতে বদলায় না)। `BiometricService` + `CardholderService::delete()`/`FamilyMemberService::delete()` — মুছলে এনক্রিপ্ট করা ফাইলও মোছে। phpunit.xml-এ পরীক্ষার চাবি। টেস্ট `BiometricStorageTest` (২৯৫টা টেস্ট)। ✅ ৪.৩ (৯ অক্টোবর রাত, `d898d3b`): এনরোলমেন্ট API আর শর্তের হিসাব — বিস্তারিত উপরের "পরের চ্যাট এখান থেকে শুরু" → ৫। সাথে audit ফিল্টারের পুরনো ফাঁক (`b7d0c8b`)। **ধাপের তালিকা আর অবস্থা এখন একটাই জায়গায়: উপরের "পরের চ্যাট এখান থেকে শুরু" → ৩।** **পরে (ইউজারকে জিজ্ঞেস করে):** ১:১ মিলিয়ে দেখা আর ডুপ্লিকেট খোঁজা (matcher, যেমন SourceAFIS আলাদা container-এ)। অ্যাপয়েন্টমেন্ট আর নোটিফিকেশন ROADMAP অনুযায়ী Phase 5 (ইমেইল Mailpit-এ, SMS `log` ড্রাইভার — বাইরে কিছু নয়)।
**(ঘ) পরে ভাবার মতো নতুন জিনিস:** অফলাইন যাচাই (QR-এ স্বাক্ষরিত তথ্য, নেটওয়ার্ক ছাড়া গার্ডের অ্যাপে), যাচাই/প্রিন্টের ঘটনা HR-কে webhook-এ (Phase 6), কার্ডের তালিকায় "যাচাই হয়েছে/হয়নি" ফিল্টার, ইমপোর্টের এরর রিপোর্ট CSV; পুরনো "Print Reason" (Initial/Promotion/defaced/Lost Case…) থেকে কার্ডের কারণ মেলানো (প্রতিষ্ঠানের সেটিংয়ে শব্দ→কারণ; এখন কারণ `new` আর লেখাটা নোটে, পুরনোর মতো)।

### ৮ অক্টোবর ২০২৬

**৩.৭ — কোথায় কী দেখবেন:** Organization পাতা → "কার্ড যাচাই" (চালু করুন, তথ্য বাছাই/ক্রম, নিজের লিংক, বার্তা, লগ) → কার্ড টেমপ্লেটের ডিজাইনারে QR-এর লাইনে "যাচাইয়ের লিংক" ঘর → কার্ড প্রিন্ট → QR স্ক্যান করলে `/v/{code}` পাতা; কার্ডের বিস্তারিততে "যাচাইয়ের ইতিহাস"। **dev-এ Acme-এর যাচাই এখন বন্ধ** (পরীক্ষার পরে আগের সেটিং হুবহু ফেরানো হয়েছে) — দেখতে চাইলে Organization পাতা থেকে চালু করুন। পরীক্ষার কার্ড QA/26000001 আর QA/26000002-এ একটা করে যাচাইয়ের লগ আছে।

**আজকের সিদ্ধান্ত (ইউজার):** ৩.৭-এ **সব কিছু প্রতিষ্ঠানের নিজের সেটিং থেকে** (যাচাই চালু/বন্ধ, কী দেখাবে, ক্রম, কারণ, নিজের লিংক, বার্তা, লগ)। কোনো জানা বাগ বা পুরনো সিস্টেমের ফাঁক রেখে সামনে যাওয়া যাবে না — পাওয়া মাত্র ঠিক করা; ভালো সমাধানে লাইব্রেরি বা সেটিং লাগলে যোগ করা যাবে (কোনটা আর কেন, ইউজারকে বলে)। সেই অনুযায়ী নতুন npm প্যাকেজ `qrcode-generator` যোগ হয়েছে। বারকোড/QR-এর সমস্যা ইস্যু বা প্রিন্ট **আটকায় না**, শুধু সতর্ক করে; ছাপায় জায়গা ফাঁকা থাকে। QR-এর লেখা = পুরনো নিয়ম ("লেবেল:মান" লাইন), ডিফল্ট error correction H (পুরনো)।

**QR/বারকোড স্ক্যান করে যাচাই:** scratchpad-এ `qrtool/` (`npm install jsqr pngjs`, প্রজেক্টে নয়) + `decode.mjs` (`decodePng(buffer)`); পাতার `[data-qr] svg`-এর জায়গা `Page.captureScreenshot` `clip` + `scale: 4` দিয়ে কেটে পড়া; PDF হলে `pdftoppm -r 300`। DB-তে না লিখে যাচাই: CDP `Fetch` দিয়ে সার্ভারের উত্তরে উপাদান বসানো বা লগের POST-এ নকল 201 (`cdp.mjs` → `b.on(listener)`)।

- ✅ **৩.৬ ধাপ ৪গ-১ (ব্যাকএন্ড): "ফিল্টারের সব কার্ড"।** `GET /cards/printable?filter[...]&sort=` — `/cards` তালিকার **একই ফিল্টার আর একই ক্রমে** শুধু ছাপা যায় এমন কার্ডের public id, সর্বোচ্চ `max_batch` (২০০): `{data: [ids], meta: {total, limit}}` (`total` = মোট ছাপার-যোগ্য, সীমার বেশি হলেও)। `cards.print` পারমিশন লাগে, ফিল্টারের যাচাই `ListCardsRequest`। কোড: `CardPrintController::printable()` → `CardPrintService::printableIds()` → `CardRepository::printablePublicIds()`। তালিকা আর এই API যাতে কখনো আলাদা না হয়, `CardRepository::list()`-এর ফিল্টার আর ক্রম এখন `filtered()`-এ; "সক্রিয় আর মেয়াদ আছে" (= `CardPrintService::printable()`) এক জায়গায় `whereValid()` (তালিকার `validity=valid`, `summary.pending` আর এই API তিনটাই এটা ব্যবহার করে)। HR সিস্টেমও এক ডাকে "ছাপা বাকি সব কার্ড" নিয়ে `POST /cards/pdf`-এ দিতে পারে। টেস্ট `CardPrintTest` (বাতিল/মেয়াদোত্তীর্ণ বাদ, ক্রম, ফিল্টার, সীমা, Viewer 403); ২১৫টা টেস্ট।
- ✅ **৩.৬ ধাপ ৪গ-২ (UI): "এই তালিকার সব কার্ড বাছাই"** (Gmail-এর মতো)। `/cards`-এ পাতার সব ছাপার-যোগ্য কার্ড বাছাই করা থাকলে আর তালিকায় আরও পাতা থাকলে বাছাইয়ের বারে লিংক (`data-select-all-matching`) → `cards.ts` → `findPrintableCards(list.queryParams())` → `addToSelection()` (আগের বাছাই থাকে, ডুপ্লিকেট নয়, `max_batch` ছাড়ায় না)। পরে লিংকের জায়গায় লেখা (`data-all-matching-selected`): "এই তালিকার ছাপা যায় এমন সব N টা বাছাই করা", অথবা সীমা ছাড়ালে "M টার মধ্যে ২০০টা … এগুলো ছাপুন, তারপর আবার বাছাই করুন" ("এখনো ছাপা হয়নি" টাইলে ছাপা কার্ড তালিকা থেকে সরে যায়, তাই আবার বাছাই করলে পরের ২০০টা আসে)। ফিল্টার বা ক্রম বদলালে লিংক আবার আসে। `useListQuery` → নতুন `queryParams()` (ফিল্টার + ক্রম, পাতা ছাড়া; `load()`-ও এটা ব্যবহার করে, তাই তালিকা আর "সব বাছাই" কখনো আলাদা ফিল্টার পায় না)। ভাষা `cards.batch.selectMatching|matchingSelected|matchingLimited`। ব্রাউজারে যাচাই (`?per_page=1` দিয়ে কয়েক পাতা বানিয়ে): সব কার্ড আর "এখনো ছাপা হয়নি" ফিল্টার — দুটোতেই ২টা বাছাই, প্রিন্টের লিংকে দুটো id, লিংক চলে যায়; bn/ডার্ক/ফোন (৩৯০ px), কোনো JS error নেই, কোনো লগ লেখা হয়নি। সীমা ছাড়ানোর লেখা ব্রাউজারে দেখা হয়নি (dev-এ ছাপার-যোগ্য কার্ড মাত্র ২টা), সীমা ব্যাকএন্ড টেস্টে ঢাকা।

- ✅ **৩.৬ ধাপ ৬ক (UI): বারকোড যাচাই।** `cards.ts` → `barcodeProblems(layout, values)` (`missingImages()`-এর মতো; লুকানো ঘর বাদ; যে এনকোডার আঁকে সেটাই — `encodeBarcode()` — দিয়ে যাচাই, তাই সতর্কতা = কার্ডে সত্যিই ফাঁকা): মান নেই, অথবা ধরনে বসে না (যেমন EAN-13-এ "ACME-0031")। নতুন `CardBarcodeProblems.vue` প্রতিটা সমস্যা আলাদা লাইনে: ঘরের নাম, মান, আর ধরনটা কী নেয় (`cards.barcode.accepts.<format>`; কাস্টম ফিল্ড key দিয়ে)। দেখায় ইস্যু ডায়ালগে (`data-barcode-problems`) আর প্রিন্টের পাতায় (`data-print-barcodes`); প্রিন্টের পাতায় এই সতর্কতা থাকলেও নিজে থেকে প্রিন্ট হয় না। **বাগ ঠিক:** ছাপা কার্ডে (`DesignerCanvas bare` → `DesignerElement :bare`) ভুল বারকোডের লাল "ভুল" বক্স কাগজে চলে যেত — এখন জায়গা ফাঁকা (ডিজাইনারে লাল বক্স আগের মতো)। **আগের বাগ ঠিক:** ইস্যু ডায়ালগে প্রিভিউ থাকলেও, ছবির সতর্কতা না থাকলে "Choose a template to see the card." দেখাত (`v-else` ভুল উপাদানে)। যাচাই: headless Chrome-এ সার্ভারের উত্তরে (CDP `Fetch`, DB-তে কিছু লেখা নয়) তিনটা বারকোড বসিয়ে — EAN-13 ভুল মান, Code128 ঠিক, Code39 মান নেই: সতর্কতায় দুটো, কার্ডে শুধু Code128 আঁকা, লাল বক্স ০, নিজে থেকে প্রিন্ট ০; ইস্যু ডায়ালগেও একই। cdp.mjs-এ `b.on(listener)` যোগ (CDP event শোনা)।
- ✅ **৩.৬ ধাপ ৬খ (ব্যাকএন্ড): সার্ভারে একই সতর্কতা।** `App\Support\Barcodes\BarcodeValues::encodable(value, format)` — JsBarcode 3.12-এর `valid()` নিয়ম হুবহু (EAN-13/EAN-8/UPC: check digit ছাড়া অঙ্ক, বা সঠিক check digit-সহ; Code39 আর Codabar আগে বড় হাতের; শুধু অঙ্কের Codabar-এ A…A; অজানা ধরন = Code128)। মেলানো হয়েছে: ৩৮টা মান × ৭ ধরন = ২৬৬টা ফল Node-এ আসল JsBarcode আর PHP-তে, অমিল ০ (প্রতিনিধি মানগুলো `tests/Unit/BarcodeValuesTest.php`-এ)। `Cards\CardWarnings` (`forCard()`, `check(elements, values)`): `{type: missing_image, image}` আর `{type: barcode, field, format, value, reason: empty|invalid}`; লুকানো ঘর বাদ, একই ঘর+ধরন একবার; সতর্কতা কখনো ইস্যু/প্রিন্ট আটকায় না। API: `GET /cards/{id}` আর ইস্যু (`POST /cardholders/{id}/cards`, 201) → `meta.warnings` — HR সিস্টেম API দিয়ে ইস্যু করলেই জানে কী ফাঁকা ছাপা হবে। প্রিভিউ API-তে দেওয়া হয়নি ইচ্ছা করে: ইস্যুর আগে `card.*` মান থাকে না, তাই নম্বরের বারকোড ভুল করে "মান নেই" দেখাত (UI নিজে পরের নম্বর বসিয়ে যাচাই করে)। চালু অ্যাপে যাচাই: QA/26000002 → photo আর cardholder_signature নেই = প্রিন্টের পাতার সতর্কতার সাথে মেলে। ২৩৯টা টেস্ট।
- ✅ **QR-ক: কার্ডে আসল QR কোড** (পুরনো সিস্টেমে ছিল — `simple-qrcode`, error correction H, "লেবেল:মান" লাইনগুলো `\n` দিয়ে; নতুন সিস্টেমে এতদিন শুধু আইকন আঁকা হতো, ছাপা কার্ডেও)। নতুন npm প্যাকেজ **`qrcode-generator` ^2.0.4** (ইউজারের সাধারণ অনুমতি, ৮ অক্টোবর; MIT, কোনো dependency নেই, শুধু হিসাব, বাইরে কোনো network নয়; `qrcode`-এর ৩টা dependency, `uqr` 0.x — তাই বাদ)। `designer/qr.ts`: `qrText(lines, valueOf)` — পুরনো নিয়ম: ঘর → "লেবেল:মান" বা শুধু মান, লেখা → যেমন আছে, মান না থাকলে লাইন বাদ; `encodeQr(text, level)` — **UTF-8 bytes** হিসেবে (লাইব্রেরি নিজে শুধু Latin-1 রাখে, বাংলা ভাঙত), সবচেয়ে ছোট মাপ, SVG path (পাশাপাশি কালো ঘর এক আয়তক্ষেত্রে), quiet zone ৪ module (মান অনুযায়ী)। `DesignerCanvas` → `contentOf()`: QR-এর লেখা এখানে এক জায়গায় বানানো, তাই ডিজাইনার (নমুনা/কার্ডধারীর মান), থাম্বনেইল, starter, ইস্যুর প্রিভিউ, প্রিন্ট আর সার্ভারের PDF সব একই। `DesignerElement`: `data-qr` SVG, রং = `style.color` (ডিফল্ট কালো); লেখা নেই → ডিজাইনারে আইকন, ছাপায় ফাঁকা; অনেক লম্বা → ডিজাইনারে লাল "QR কোডের জন্য লেখা অনেক বেশি", ছাপায় ফাঁকা। নতুন সেটিং `style.qr_error_correction` (L/M/Q/H, ডিফল্ট H = পুরনো; config `card_template_elements.style.qr` আর `qr_error_levels`, যাচাই `SaveCardTemplateElementsRequest`, catalog-এ `qr_error_levels`)। ডিজাইনারের প্যানেলে: error correction, "কোডের রং", নমুনা মানে সবচেয়ে ছোট বর্গের মাপ (০.৩ mm-এর কম হলে সতর্কতা)। যাচাই **স্ক্যান করে** (scratchpad-এ `jsqr` + `pngjs`, git-এ নয়): প্রিন্টের পাতা — বসানো QR `"কার্ড যাচাই\nName:Jaylon Kassulke Jr.\nCard:QA/26000002"` (বাংলা ঠিক, ফাঁকা ঘর বাদ), টেমপ্লেটের নিজের QR `"ID:ACME-0031\nJaylon Kassulke Jr."`; Gotenberg PDF (৩০০ dpi) — একই; ডিজাইনার — নমুনা `"ID:E-102345\nJane Doe"`, প্যানেল en/bn। কোনো লগ বা সেভ হয়নি। ২৪০টা টেস্ট।
- ✅ **QR-খ: QR-এর সতর্কতা।** UI: `cards.ts` → `qrProblems(layout, values)` (`qrText()` + `encodeQr()` — আঁকার একই কোড): `empty` (কোনো লাইনের মান নেই) / `too_long` (`bytes`, `level`); `qr.ts` → `QR_MAX_BYTES` (version 40: L 2953, M 2331, Q 1663, H 1273 — qrcode-generator-এ চালিয়ে ঠিক এই সীমায় থামে দেখা হয়েছে)। একই হলুদ বক্সে ("যে বারকোড বা QR ফাঁকা ছাপা হবে"): `CardBarcodeProblems :qr`, ইস্যু ডায়ালগ আর প্রিন্টের পাতা (থাকলে নিজে থেকে প্রিন্ট নয়)। সার্ভার: `BarcodeValues::QR_MAX_BYTES`/`fitsQr()`, `CardWarnings` → `{type: qr, reason: empty|too_long, level, bytes}` (QR-এর লেখা ব্রাউজারের মতো বানায়, `Str::trim` = JS `trim`)। টেস্ট: `BarcodeValuesTest` (সীমার দুই পাশ, বাংলা = ৩ byte), `CardShowTest` (ঠিক QR, ফাঁকা, H-এ লম্বা কিন্তু L-এ চলে, লুকানো)। ব্রাউজারে: সার্ভারের উত্তরে দুটো সমস্যার QR বসিয়ে — দুই জায়গাতেই দুটো বার্তা, ছাপায় ফাঁকা, টেমপ্লেটের নিজের QR আঁকা।
- ✅ **নিরাপত্তা: npm audit-এর critical সতর্কতা** (আগে থেকেই ছিল): dev টুল `concurrently` → `shell-quote` 1.9.0 (GHSA-pqg4-j6r4-53mv, `quote()`-এ command injection)। `npm audit fix` → `concurrently` 10.0.6, `shell-quote` 1.12.0 (শুধু package-lock, package.json অপরিবর্তিত); এখন `npm audit` → 0 vulnerabilities, বিল্ড চলে।
- ✅ **বাগ: ডার্ক মোডে ব্র্যান্ড রঙের লেখা প্রায় অদৃশ্য** (§৬-এ পুরনো জানা সমস্যা; হলুদের মতো হালকা রঙে লাইট মোডেও একই)। নতুন token `brand-ink` (`app.css`; `lib/brand.ts` → WCAG কন্ট্রাস্ট মেপে রং মেশায়) আর ৩৩টা ফাইলে ৫২ জায়গায় `text-brand`/`border-brand`/`outline-brand` → `*-brand-ink`; `--ring`-ও এখন ink থেকে; `--brand-contrast` এখন সত্যিকারের কন্ট্রাস্ট দেখে কালো/সাদা বাছে। মাপা (ব্রাউজারে প্রতিটা `.text-brand-ink` আর তার পটভূমি): Acme #280750 — ডার্কে ink #9483a8, কম-সে-কম ৫.২৮ (আগে প্রায় ১.৫), লাইটে ১৫.৮৪; হলুদ #ffd400 (শুধু প্রিভিউ, সেভ নয়) — লাইটে ink #806a00 = ৪.৯২, ডার্কে ১২.৭৬।
- ✅ **বাগ: অন্য ট্যাবে ছাপার পরে কার্ডের তথ্য পুরনো থাকত** (কার্ডের বিস্তারিত, কার্ডধারীর Cards ট্যাব, কার্ডধারীর তালিকার "ছাপা বাকি/ছাপা হয়েছে")। `cards.ts` → `announcePrinted(ids)` (প্রিন্টের পাতা লগ লেখার পরে — ব্রাউজারের প্রিন্ট আর "PDF + লিখে রাখো") আর `useCardsPrinted(cb)` — **BroadcastChannel `card-prints`** (একই ব্রাউজারের এই অ্যাপের ট্যাবগুলোর মধ্যে; বাইরে কিছু যায় না)। শোনে: `CardDetailDialog` (শুধু নিজের কার্ড হলে, চুপচাপ আবার আনে, খোলা থাকে), `CardholderCards` (নিজের কোনো কার্ড হলে), `CardholdersPage` (তালিকার কারো বর্তমান কার্ড হলে), `CardsPage` (তালিকা + টাইল; আগের `focus`-কৌশলের জায়গায়, তাই ফেরার আগেই হালনাগাদ)। যাচাই (DB-তে কিছু লেখা নয়: লগের POST Chrome-এর ভেতরে নকল 201): বার্তায় চার জায়গাতেই আবার আনে, অন্য কার্ডের বার্তায় বিস্তারিত আনে না, প্রিন্টের পাতায় "লিখে রাখো" → বার্তা `{cards: [id]}`; আসল `print_count` ০-ই থাকে।
- ✅ **৩.৭ক: কার্ড যাচাইয়ের কোড আর প্রতিষ্ঠানের সেটিং** (পুরনো সিস্টেমে যাচাই ছিল না — নতুন ফিচার)। ইউজারের সিদ্ধান্ত: **সব কিছু প্রতিষ্ঠানের নিজের সেটিং থেকে**। নকশা: প্রতি কার্ডে এলোমেলো `cards.verify_code` (২৪ অক্ষর a–z0–9 ≈ ১২৪ bit, unique; MySQL case ধরে না তাই শুধু ছোট হাতের; `Card::newVerifyCode()`, `creating`-এ নিজে বসে; migration আগের সব কার্ডে বসিয়েছে, DEMO-সহ — শুধু এই কলাম) — ইস্যুতে স্থির, তাই প্রতিটা reprint-এ একই লিংক। লিংক = `Tenant::verifyUrl(code)`: প্রতিষ্ঠানের নিজের ঠিকানা (`{code}`-সহ, যেমন HR পোর্টাল) বা `APP_URL` + `config cards.verification.path` (`/v/{code}`); যাচাই বন্ধ থাকলে null। নতুন ঘর **`card.verify_url`** (`CardDataResolver::forCard()`, ডিজাইনারের `card` তালিকায়) — QR-এর লাইনে দিলে লাইনের সাথে লিংক, একা দিলে শুধু লিংক; বারকোড/লেখাতেও চলে; বন্ধ থাকলে মান নেই (QR/বারকোডের সতর্কতা ধরে)। সেটিং `tenants.settings.verification` (`Tenant::verificationSettings()` — ডিফল্ট `config cards.verification.defaults`): `enabled` (**ডিফল্ট বন্ধ** — প্রতিষ্ঠান না চাইলে কিছু পাবলিক নয়), `fields` (পাতায় কী দেখাবে; শুধু `config cards.verification.fields`-এর তালিকা থেকে — NID, পাসপোর্ট, ফোন, ইমেইল, ঠিকানা, জন্মতারিখ ইচ্ছা করে নেই), `show_revoke_reason` (ডিফল্ট না), `link`, `note` (যেমন "পাওয়া গেলে যোগাযোগ…")। `POST /settings` → `verification` (`UpdateTenantSettingsRequest`; লিংক `{code}`-এর জায়গায় নমুনা বসিয়ে http/https URL যাচাই — `{}` URL-এ বৈধ নয়), `TenantService::updateSettings()`, `TenantResource.verification`। টেস্ট `CardVerificationTest` (কোড unique/স্থির, ডিফল্ট বন্ধ, চালু → লিংক, নিজের ঠিকানা, নিষিদ্ধ ঘর/ভুল লিংক 422)। ২৪৪টা টেস্ট।
- ✅ **৩.৭খ: পাবলিক যাচাই API** `GET /api/v1/verify/{code}` — লগইন ছাড়া, `throttle` (`config cards.verification.throttle` = ৩০/মিনিট/IP), `Cache-Control: no-store`। `CardVerificationController` → `Cards\CardVerificationService::verify()` → `CardRepository::findByVerifyCode()` (tenant scope ছাড়া, তারপর সেই প্রতিষ্ঠানের context-এ)। কোড বড় হাতের হলেও চলে; ভুল আকারের কোড, অজানা কোড, যাচাই বন্ধ, প্রতিষ্ঠান স্থগিত — **সব একই 404** (`cards.verification.not_found`), যাতে বাইরে থেকে কিছু বোঝা না যায়। উত্তর: `status` (valid / expired / revoked / replaced — অবস্থা সবসময় এখনকার), `status_changed_at`, `revoke_reason` (শুধু সেটিং দিলে), `holder` (cardholder/family), `photo_url` (signed, শুধু `photo` বাছাই থাকলে), `details` [{key, value}] — **প্রতিষ্ঠানের বাছাই করা ক্রমে**, মান ইস্যুর সময়ের (`template` = টেমপ্লেটের নাম), `organization` {name, logo_url, primary_color}, `note`, `checked_at`। কার্ডের id বা বাছাই না করা কোনো তথ্য যায় না (টেস্টে NID দিয়ে যাচাই)। HR-এর জন্য `CardResource.verify_url` (তালিকা আর বিস্তারিত; বন্ধ থাকলে null)। টেস্ট: `CardVerificationTest`-এ ৩টা নতুন। চালু অ্যাপে: বন্ধ অবস্থায় 404। ২৪৭টা টেস্ট।
- ✅ **৩.৭গ (UI): প্রতিষ্ঠানের পাতায় "কার্ড যাচাই"** (`OrganizationPage.vue`, `data-verification-settings`): চালু/বন্ধ (বন্ধে কিছু দেখায় না), পাতায় কোন তথ্য (`TenantResource.verification_fields` = config-এর তালিকা, নাম `cards.ts` → `verificationFieldLabel()`; ছবি আর "কার্ডের ধরন" নিজস্ব নাম), **ক্রম** (↑↓, `data-verification-order`; সার্ভার এই ক্রম রাখে), বাতিলের কারণ, নিজের লিংক (সাথে নমুনা লিংক `data-verification-sample`), বার্তা, আর "লিংক ছাপতে QR-এ যাচাইয়ের লিংক ঘর যোগ করুন"। `cards.ts` → `verifyUrlFor(tenant, code, evenIfOff)` (= `Tenant::verifyUrl`) আর `SAMPLE_VERIFY_CODE` — ডিজাইনারের নমুনা (বন্ধ থাকলেও, QR-এর মাপ বুঝতে) আর ইস্যুর প্রিভিউ (শুধু চালু থাকলে) একই দৈর্ঘ্যের নমুনা লিংক; ঘরের নাম `designer.fields.card.verify_url`। **বাগ ঠিক (৩.৭খ-এর ফল):** প্রতিষ্ঠানের পাতা সার্ভারের পুরো রেকর্ড (`Object.assign(form, data)`) আবার পাঠাত — নতুন `verification` অবজেক্ট `"[object Object]"` হয়ে সেভ 422 দিত; এখন শুধু ফর্মের নিজের ঘর। **বাগ ঠিক:** vue-i18n লেখার ভেতরের `{code}`-কে placeholder ভেবে মুছে দিত → `{'{'}code{'}'}`। যাচাই: অপরিবর্তিত সেভ চলে, চালু করে ঘর বদলে ক্রম উল্টে সেভ = সার্ভারে একই ক্রম, ভুল লিংকে বার্তা, bn/ডার্ক/ফোন ৩৯০ px; প্রিন্টের পাতায় `card.verify_url`-এর QR স্ক্যান = আসল কোডের লিংক। **dev-এ Acme-এর যাচাই এখন চালু** (পরীক্ষার জন্য; আগের সেটিং `{"default_card_size":…,"card_number_format":…}` — ৩.৭ শেষে ফেরানো হবে)।
- ✅ **৩.৭ঘ (UI): পাবলিক যাচাইয়ের পাতা `/v/:code`** (`pages/verify/CardVerifyPage.vue`, রাউট `cards.verify`, `meta.public` — লগইন লাগে না)। প্রতিষ্ঠানের রঙে মাথা (লোগো + নাম), বড় অবস্থা (`data-verify-status`: বৈধ সবুজ / মেয়াদোত্তীর্ণ কমলা / বাতিল আর বদলে যাওয়া লাল, তারিখ "… থেকে", অনুমতি থাকলে কারণ), ছবি, তথ্য প্রতিষ্ঠানের ক্রমে (পরিবারের কার্ডে নামের লেবেল "যাঁর পরিবার"), বার্তা, যাচাইয়ের সময়; অজানা কোড/বন্ধ (৪০৪), অনেক বার (৪২৯), সংযোগ ব্যর্থ — আলাদা বার্তা (`data-verify-problem`); ভাষা/থিম বদল (`LocaleThemeSwitch`)। নতুন: `<meta name="robots" content="noindex, nofollow">` (কার্ডের পাতা সার্চে নয়)। প্রতিষ্ঠানের রং শুধু এই অংশে: `lib/brand.ts` → `brandTokens(hex)` (`applyBrand` এখন এটা ব্যবহার করে) + CSS `[data-brand-scope]` (ink আর ring নিজের) — লগইন করা কেউ অন্য প্রতিষ্ঠানের কার্ড খুললেও অ্যাপের রং বদলায় না। যাচাই (লগইন ছাড়া নতুন ব্রাউজার, শুধু QA কার্ড): বৈধ/বদলে যাওয়া/বাতিল/অজানা — ঠিক অবস্থা আর তথ্য, কারণ লুকানো, `noindex`, `--brand` অ্যাপে অপরিবর্তিত; bn/ডার্ক/ফোন ৩৯০ px; কোনো JS error নেই।
- ✅ **৩.৭ঙ-১ (ব্যাকএন্ড): যাচাইয়ের লগ** (নতুন সংযোজন — নকল/হারানো কার্ড ধরা সহজ)। টেবিল `card_verifications` (card, সেই মুহূর্তের `status`, `device`, `verified_at`; timestamps নেই) + `cards.verify_count`/`last_verified_at`। **IP জমা থাকে না**: `device` = HMAC-SHA256(প্রতিষ্ঠান + IP, app key)-এর ১৬ অক্ষর (আলাদা ডিভাইস গোনা যায়, দুই প্রতিষ্ঠানের লগ মেলানো যায় না)। একই ডিভাইস `log_repeat_seconds` (৬০)-এর মধ্যে আবার দেখলে (রিফ্রেশ) গোনা হয় না। `CardVerification` MassPrunable — `log_days` (৩৬৫) পরে মুছে যায়; `routes/console.php` → `Schedule::command('model:prune')->daily()` (scheduler কন্টেইনার চালায়)। সেটিং `verification.log_checks` (ডিফল্ট চালু; বন্ধ করলে কিছুই লেখা হয় না)। `CardVerificationService::verify(code, ip)` লগ লেখে, `history(card)`; API `GET /cards/{id}/verifications` (`cards.view`) → `data` [{status, device (৬ অক্ষর), verified_at}] নতুন আগে (সর্বোচ্চ `history_limit` ৫০), `meta` {total, devices}; `CardResource.verify_count`/`last_verified_at`। `CardVerificationRepository` (`recentFor`, `devicesFor`, `checkedSince`), `CardRepository::addVerification()`। টেস্ট: এক মিনিটে একবার, আলাদা ডিভাইস, বাতিলের পরে `revoked`, বন্ধে লগ নয়, IP কোথাও নেই, ৩৬৬ দিনে prune, Viewer দেখে। ২৪৮টা টেস্ট।
- ✅ **৩.৭ঙ-২ (UI): কার্ডের বিস্তারিততে "যাচাইয়ের ইতিহাস"** (`CardDetailDialog.vue`, `data-verify-history`; কার্ডের সাথে একসাথে `GET /cards/{id}/verifications`): "N বার যাচাই · M ডিভাইস", যাচাইয়ের পাতা খোলার লিংক (`data-verify-link`) আর কপি বোতাম, প্রতিটা যাচাই (সময়, ছোট ডিভাইস-পরিচয়, তখনকার অবস্থা), আর **বাতিল/বদলে যাওয়ার পরে যাচাই হলে লাল সতর্কতা** (`data-verify-invalid-checks` — কার্ডটা হয়তো এখনো ব্যবহার হচ্ছে)। দেখায় যাচাই চালু থাকলে বা আগে কখনো যাচাই হয়ে থাকলে। সেটিংয়ে "যাচাইয়ের লগ রাখো" (`verification.log_checks`)। টাইপ `CardVerificationLog`, `IssuedCard.verify_count/last_verified_at/verify_url`। যাচাই: লগইন ছাড়া QA/26000002 (বৈধ) একবার আর QA/26000001 (বদলে যাওয়া) দুবার → বিস্তারিততে ১ আর ১ (এক মিনিটে একবার), দ্বিতীয়টায় লাল সতর্কতা; এই দুটো লগ পরীক্ষার কার্ডে রয়ে গেছে (কার্ড মুছলে cascade-এ মুছে যায়)।
- ✅ **৩.৮-এর দ্বিতীয় অংশ (কার্ড থাকলে কার্ডধারী মোছা আটকানো) — আগেই ছিল** (৩.৫-এ: `CardholderService::delete()` আর `FamilyMemberService` → `cards.in_use.*`, টেস্ট `CardLifecycleTest`); তালিকায় ভুল করে বাকি লেখা ছিল। **আজ UI ভালো করা হলো:** আগে বোতাম চাপলে "নিশ্চিত?" জিজ্ঞেস করে তারপর ভুলের বার্তা আসত; এখন `CardholderResource.has_cards` (তালিকায় `withExists('cards')` — আলাদা query নয়; বিস্তারিত/তৈরি/হালনাগাদে `loadExists`) — নিজের বা পরিবারের কোনো কার্ড থাকলে তালিকা আর কার্ডধারীর পাতায় মোছার বোতাম বন্ধ, tooltip-এ কারণ ("নিষ্ক্রিয় করুন", `cardholders.hasCards`)। যাচাই: ৪৫ জনের মধ্যে ঠিক ৩ জনের বোতাম বন্ধ (যাদের কার্ড আছে), বন্ধ বোতামে ডায়ালগ খোলে না, কিছু মোছা হয়নি।
### ৭ অক্টোবর ২০২৬

- ✅ **৩.৬ ধাপ ৪ক** — `/cards` তালিকায় বাছাই করে প্রিন্ট (শুধু UI): প্রতি সারিতে checkbox (ছাপা যায় না এমন কার্ডে বন্ধ, tooltip-এ কারণ), হেডারে "এই পাতার সব" (আধা-বাছাই দেখায়), বাছাই **পাতা আর ফিল্টার বদলালেও থাকে** (কয়েক পাতা থেকে জমানো যায়, বাছাইয়ের ক্রমে ছাপা)। বার: "N টা বাছাই করা" · বাছাই করা প্রিন্ট (`openPrintPage`) · বাছাই করাদের PDF (`downloadCardsPdf`, দুই পাশ, লগ নয় — লগসহ PDF প্রিন্টের পাতায়) · বাছাই মুছুন; `max_batch`-এর বেশি হলে সতর্কতা আর বোতাম বন্ধ। প্রিন্টের ট্যাব খোলার পর এই ট্যাবে ফিরলে (`focus`) তালিকা আর টাইল নিজে হালনাগাদ। সব কিছু শুধু `cards.print` থাকলে। ভাষা `cards.batch.*`। ব্রাউজারে যাচাই (বাছাই = সার্ভারের `printable`, প্রিন্টের লিংক, PDF ১০০ KB, পাতা বদলে বাছাই থাকে, bn/ডার্ক/ফোন), কোনো লগ লেখা হয়নি।
- ✅ **বাগ: ফোনে পাতা চওড়া হয়ে যেত** (আগে থেকেই ছিল, ৪ক-এর আগের কোডেও যাচাই করা): `/cards` পাতার layout viewport ৩৯০ px-এর জায়গায় ৮৪৪ (checkbox-সহ ৮৮৪), কার্ডধারীর পাতা ৪৪১ — ফলে পাতা পাশে সরত আর টোস্ট পর্দার বাইরে যেত। দুটো কারণ: (১) টেবিলের শেষ কলামের লুকানো শিরোনাম (`sr-only` = `position: absolute`) মোড়কে `position` না থাকায় স্ক্রল-বাক্স থেকে বেরিয়ে টেবিলের ডান কিনারায় বসত → `CardsPage.vue` আর `CardholderCards.vue`-এ মোড়ক `relative overflow-x-auto`; (২) কার্ডধারীর পাতার ট্যাবের সারি এক লাইনে আঁটত না → `CardholderShowPage.vue`-এ ফর্ম পাতার মতো `overflow-x-auto` + `whitespace-nowrap`। ১৯টা প্রধান পাতা ফোনের মাপে মাপা, সব ৩৯০ px। নিয়ম §৬-এ।
- ✅ **৩.৬ ধাপ ৪খ-১ (ব্যাকএন্ড): কাগজের শিট।** `config/cards.php` → `print.sheets` (`a4` 210×297, `letter` 215.9×279.4 mm, খাড়া; নতুন কাগজ = শুধু config)। `CardRenderService::SHEET_CARD` (`card` = প্রতি পাশ এক পাতা, এখনকার মতো), `sheetKeys()`, `sheetSize()`, `sheets()` — options, যাচাই আর render তিনটাই এখান থেকে। `GET /cards/options` → `print.sheets` [{key, width_mm, height_mm}]। PDF: `GET /cards/{id}/pdf?sheet=` আর `POST /cards/pdf {sheet}` (`CardPdfRequest`, `CardPdfData::$sheet`; অজানা → 422) → signed render লিংকে `sheet` (বদলালে 403) → `GET /card-render` → `sheet` = কাগজের মাপ বা null। ২১৪টা টেস্ট।
- ✅ **৩.৬ ধাপ ৪খ-২ (ফ্রন্টএন্ড): শিটে ছাপা।** `cards.ts` → `printPages(cards, sides, copies, sheet)` — পাতা সাজানোর একমাত্র জায়গা (pure): শিট না থাকলে আগের মতো প্রতি পাশ এক পাতা (কার্ডের মাপে); শিটে একই মাপের কার্ড গ্রিডে (মার্জিন `SHEET_MARGIN_MM` ১০, ফাঁক `SHEET_GAP_MM` ৬; আড়াআড়ি মাঝখানে, উপর থেকে), CR80 → A4-এ ৮টা; প্রতিটা সামনের শিটের পরে তার পেছনের শিট, **কলাম উল্টো** (লম্বা কিনারা ধরে duplex-এ মেলে; "শুধু পেছনে" পাসও আবার ঢোকানো সামনের গাদায় মেলে); পেছন নেই এমন কার্ডের জায়গা ফাঁকা, আর কাজে কোনো দুই-পাশের কার্ড থাকলে পেছনের শিট খালি হলেও থাকে (যাতে সামনে-পেছন সরে না যায়); আলাদা মাপের কার্ড আলাদা শিটে; কপি = পুরো তালিকা আবার। `CardSheets.vue` শুধু আঁকে: প্রতি পাতার মাপে নামের `@page`, কার্ড mm-এ absolute, সামনের শিটে SVG কাটার দাগ (কোণ থেকে ১ mm দূরে, ৩ mm, কিনারার লাইনে — ফাঁকে থাকে, পাশের কার্ডে পৌঁছায় না); পর্দায় সাদা কাগজ, ফোনে নিজের বাক্সে স্ক্রল। প্রিন্টের পাতা: "পাতা" বাছাই (`data-print-sheet`; কার্ড প্রিন্টার / `{কাগজ} শিট, কাটার দাগসহ`; কাগজের নাম `cards.print.papers.*`, অজানা key হলে বড় হাতের), এই ডিভাইসে মনে থাকে (localStorage `card-print-sheet`, না থাকলে কার্ড মোড), ১০০% স্কেল আর "Flip on long edge"-এর পরামর্শ; PDF ডাউনলোডেও `sheet`। render পাতা (Gotenberg) `data.sheet` মানে। যাচাই: ২ কার্ড × ৫ কপি → A4-এ ৪ পাতা (৮ + ২), পেছনে উল্টো কলাম; ব্রাউজারের PDF আর সার্ভারের PDF দুটোই A4 (594.96 × 841.92 pt), ছবিতে একই; কার্ড মোড আগের মতো (242.88 × 153.12 pt); রিলোডে বাছাই থাকে; bn/ফোন; কোনো লগ লেখা হয়নি।

### আগের সেশন (৬ অক্টোবর ২০২৬)

**আজ কী হলো (commit ক্রমে):**

| commit | কী |
|---|---|
| `b3271ee` | ২ক: প্রিন্টের পাতা `/cards/print?ids=a,b` — আসল মাপে কার্ড, প্রতি পাশ এক পাতা, কপি, পাশ বাছাই, বাদ পড়া কার্ড ও "ছবি নেই" সতর্কতা, ছাপার পরে "ঠিকমতো হয়েছে?" → লগ; API-তে `printable` |
| `cb3b1ae` | বাগ: প্ল্যাটফর্ম অ্যাডমিন রিলোড/লিংকে প্রতিষ্ঠান হারাত — এখন localStorage `acting-tenant`, "Open"-এর পরে `?redirect=`-এ ফেরে; `useListQuery` অন্য query রাখে |
| `0b68f2e` | ৫ক: Gotenberg কন্টেইনার (ইন্টারনেট ছাড়া internal network) + `PdfRenderer` |
| `d9d115d`, `902e36e` | ৫খ: PDF ইঞ্জিনের জন্য signed render লিংক/API আর `/render/cards` পাতা; কার্ড আঁকা এক জায়গায় (`CardSheets.vue`) |
| `8ce2fa0` | ৫গ: `GET /cards/{id}/pdf`, `POST /cards/pdf` (HRM-ও API token দিয়ে PDF নিতে পারে) |
| `2a28835` | ২খ: "প্রিন্ট" বোতাম — কার্ডের বিস্তারিত, কার্ডধারীর Cards ট্যাব (বর্তমান কার্ড + ইতিহাস), Card issuance তালিকা |
| `d96c2db` | ৫ঘ: প্রিন্টের পাতায় "PDF ডাউনলোড" + "প্রিন্ট হিসেবে লিখে রাখো" |
| `1735cff` | ২গ: ইস্যু ডায়ালগে "Issue and print" (popup আটকালে একই ট্যাবে) |
| `59c00a7` | ৩ক: ছাপা বাকি/ছাপা হয়েছে আর ছাপার তারিখের ফিল্টার (প্রতিষ্ঠানের timezone), `summary.pending`, কার্ডধারীর `current_card` + `filter[card]` |
| `19a07ff` | ৩খ: কার্ডের বিস্তারিততে প্রিন্টের ইতিহাস; Card issuance-এ "এখনো ছাপা হয়নি" টাইল, ফিল্টার, "N বার ছাপা" |
| `062f733` | ৩গ: কার্ডধারীর তালিকায় পুরনো "Status" PENDING/PRINTED (Status ঘরের দ্বিতীয় লাইনে) + ফিল্টার |

**আজকের সিদ্ধান্ত:**
- সার্ভারের PDF = **Gotenberg** (ইউজার অনুমোদিত)। কারণ: Chromium আমাদের প্রিন্টের পাতাই খোলে, তাই PDF = স্ক্রিন (বাংলা, নিজের ফন্ট, ঘোরানো লেখা)। dompdf/mPDF-এ বাংলা ভাঙে আর নকশা দুবার লিখতে হতো। নতুন PHP/npm প্যাকেজ নেই।
- PDF ডাউনলোড নিজে "ছাপা" হিসেবে লগ হয় না; `record` দিলে হয় (`method: pdf`)।
- পুরনো "Status" PENDING/PRINTED = কার্ডধারীর **নিজের সক্রিয় কার্ড** কখনো ছাপা হয়েছে কিনা (পরিবারের কার্ড আর বাতিল কার্ড ধরা হয় না)।
- "ছাপার তারিখ" ফিল্টারের দিন = **প্রতিষ্ঠানের timezone** (অ্যাপ UTC-তে চলে; ঢাকায় রাত ১টার ছাপা সেই দিনেই)।

**কোথায় কী দেখবেন (UI):**
- লগইন: সুপার অ্যাডমিন `platform@idcard.local` (Organization ফাঁকা) → Acme "Open"; অথবা Owner `owner@acme.test` / প্রতিষ্ঠান `acme`। পাসওয়ার্ড দুটোরই `Demo@Pass2026!`।
- **Card issuance** (`/cards`): টাইল (সক্রিয়, মেয়াদোত্তীর্ণ, ৩০ দিনে শেষ, বাতিল, এখনো ছাপা হয়নি), ফিল্টার (ছাপা, ছাপার তারিখ…), সারির 🖨 আইকন, সারিতে ক্লিক → বিস্তারিত + প্রিন্টের ইতিহাস + Print।
- **Cardholders** → কারো পাতা → **Cards** ট্যাব: Issue card / Issue and print, বর্তমান কার্ডে Print, ইতিহাসে 🖨।
- প্রিন্টের পাতা: পাশ, কপি, **প্রিন্ট** (ব্রাউজার; "Save as PDF"-ও), **PDF ডাউনলোড** (সার্ভার, ফোনেও চলে)।

**dev ডাটাবেসের অবস্থা (জরুরি):**
- ইউজারের নিজের কার্ড: **DEMO/26000001** (Juliet Adams, "Designer demo (copy)", সক্রিয়, ছাপা হয়নি) — **কখনো মুছবেন না, এতে লগ লিখবেন না।**
- পরীক্ষার কার্ড: **"QA designer check" টেমপ্লেটের সব কার্ড** (QA/26000001 Juliet — replaced, ৩ বার ছাপা; QA/26000002 Jaylon — একই নম্বরে ৪ বার ইস্যু (মোট ৬টা পরীক্ষার কার্ড), সর্বশেষটা সক্রিয়; QA/26000003 Abagail — বাতিল) আর তাদের `card_prints`, `card_assets`, `card_sequences`-এর `QA/26` সারি, `activity_log` (`log_name = cards`, id > 279 আর `subject` এই কার্ডগুলো)। এই সেশনে মোছার কমান্ড অনুমতি-ব্যবস্থা আটকে দিয়েছে; **ইউজার চাইলে নিচের কমান্ড নিজে চালাবেন** (অথবা পরের যাচাইয়ে এগুলো ব্যবহার করা যায়)।
- পারমিশন: ডেমো Operator রোলে `cards.view`/`cards.print` নেই (ইচ্ছাকৃত; Roles পাতায় ইউজার ঠিক করবেন)।

**ইউজারের কাছে খোলা প্রশ্ন:** (১) পরীক্ষার কার্ড মুছবেন কিনা; (২) ফোন থেকে দেখতে `.env`-এর `SANCTUM_STATEFUL_DOMAINS`-এ `192.168.10.129:8080` যোগ করা হবে কিনা (শুধু লোকাল সেটিং)।

### কাল শুরুর কমান্ড

```bash
cd /var/www/php74/idcard/idcard-pro
docker compose up -d                                         # app, web, mysql, redis, mailpit, queue, scheduler, gotenberg
docker compose ps --format '{{.Service}} {{.State}}'         # সব "running" হওয়া উচিত
docker compose exec app sh -c "curl -s http://gotenberg:3000/health"   # {"status":"up",...} = PDF ইঞ্জিন চালু
git log --oneline -3                                         # শেষ commit মিলিয়ে নিন
docker compose run --rm --no-deps vite npm run build         # ফ্রন্টএন্ড বিল্ড (হোস্টে npm run build চলে না)
# খুলুন: http://localhost:8080  (Mailpit: http://localhost:8025)
```

**প্রতিটা ধাপের শেষে মান যাচাই:**
```bash
docker compose exec app php artisan test --compact                       # এখন ৩০৯টা টেস্ট (১০ অক্টোবর)
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app vendor/bin/pint --dirty --format agent
docker compose run --rm --no-deps vite npx vue-tsc --noEmit
```

**ব্রাউজারে যাচাই (headless Chrome, git-এ নয়):** helper `idcard-pro/.claude/qa/cdp.mjs` (gitignored, তাই টিকে থাকে) — সেশনের scratchpad-এ কপি করে `SCRATCH=$PWD node script.mjs`। দেয়: `launch()`, `b.login(login, org, password)`, `b.goto()`, `b.waitFor()`, `b.eval(expr, userGesture)` (নতুন ট্যাব খোলা যাচাইয়ে `userGesture = true`), `b.api(method, path, body)`, `b.shot()`, `b.pdf()` (`Page.printToPDF`)। PDF-এর পাতা দেখতে `pdftoppm -r 100 -png file.pdf out`। ডাউনলোড যাচাই: `Page.setDownloadBehavior` + **নতুন খালি ফোল্ডার** (`rm` দিয়ে মোছা নিরাপত্তা-চেক আটকায়)। লগইন throttle-এর জন্য চালানোর মাঝে ~২০–৬০ সেকেন্ড বিরতি।

**পরীক্ষার কার্ড মোছা (ইউজার নিজে চালাবেন; শুধু "QA designer check"-এর কার্ড, DEMO ছোঁয় না):**
```bash
docker compose exec app php artisan tinker --execute '
$template = DB::table("card_templates")->where("public_id", "01m43pg1vy3g7g46j90jv5zjxm")->value("id");
$ids = DB::table("cards")->where("card_template_id", $template)->pluck("id");
DB::transaction(function () use ($ids) {
    DB::table("cards")->whereIn("replaced_by_id", $ids)->update(["replaced_by_id" => null]);
    DB::table("card_prints")->whereIn("card_id", $ids)->delete();
    DB::table("card_assets")->whereIn("card_id", $ids)->delete();
    DB::table("activity_log")->where("log_name", "cards")->where("subject_type", App\Models\Card::class)->whereIn("subject_id", $ids)->delete();
    DB::table("cards")->whereIn("id", $ids)->delete();
    DB::table("card_sequences")->where("key", "QA/26")->delete();
});
echo "deleted ", count($ids), " test cards\n";'
```
(আগে `DB::table("cards")->where("card_template_id", $template)->count()` দিয়ে সংখ্যা দেখে নিন; ইউজারের অন্য টেমপ্লেটের কার্ড থাকলে সেগুলো এই কমান্ডে পড়বে না। DEMO/26000001-এর `replaced_by_id` নেই, তাই অক্ষত থাকে।)

### পরের কাজ (ক্রমে, প্রতিটা ছোট ধাপ → টেস্ট → commit → ইউজারকে জানানো)

১–৩ আর ৫ সম্পন্ন (৮ অক্টোবর); এখন ৪ (৩.৮) থেকে।

1. ✅ **৩.৬ ধাপ ৪: ব্যাচ প্রিন্ট (নতুন ফিচার, পুরনো সিস্টেমে ছিল না)**
   - ✅ ৪ক (UI, ৭ অক্টোবর — উপরে দেখুন): `CardsPage.vue`-তে সারিতে checkbox (শুধু `printable` কার্ড), "সব বাছাই" (এই পাতার), বাছাই করা সংখ্যা; "বাছাই করা প্রিন্ট" → `openPrintPage(ids)` আর "PDF" → `downloadCardsPdf(ids, …)`; সীমা `printOptions.max_batch` (২০০)। ফিল্টার দিয়ে বাছাই সহজ (যেমন "এখনো ছাপা হয়নি" টাইল → সব বাছাই → প্রিন্ট)। লগ আগে থেকেই এক `batch` id-তে (`POST /cards/prints`)।
   - ✅ ৪খ (নতুন বিকল্প; ৪খ-১ ব্যাকএন্ড, ৪খ-২ ফ্রন্টএন্ড, ৭ অক্টোবর — উপরে দেখুন): প্রিন্টের পাতায় "পাতার ধরন": কার্ড প্রিন্টার (প্রতি পাশ এক পাতা, এখনকার মতো) / **A4 শিট** (এক পাতায় কয়েকটা কার্ড, কাটার দাগসহ; সামনের শিটের পরে পেছনের শিট উল্টো ক্রমে, যাতে দুই পাশ মেলে)। `CardSheets.vue`-তে `layout` prop; সার্ভারের PDF-এও (`sides`-এর মতো `sheet` প্যারামিটার, `CardRenderService::link()` আর `CardPdfRequest`-এ)।
   - ৪গ: তালিকার ফিল্টার মেনে "ফিল্টারের সব কার্ড" (পাতার বাইরে); ২০০-র সীমা মেনে।
     - ✅ ৪গ-১ (ব্যাকএন্ড, ৮ অক্টোবর — উপরে দেখুন): `GET /cards/printable`।
     - ✅ ৪গ-২ (UI, ৮ অক্টোবর — উপরে দেখুন): বাছাইয়ের বারে "এই তালিকার (সব পাতার) ছাপা যায় এমন সব কার্ড বাছাই"।
2. **৩.৬ ধাপ ৬: বারকোড/QR যাচাই** — ✅ ৬ক (UI), ✅ ৬খ (সার্ভার `meta.warnings`), ৮ অক্টোবর; ✅ **আসল QR** (QR-ক) আর ✅ QR-এর সতর্কতা (QR-খ), ৮ অক্টোবর। — ইস্যু আর প্রিন্টের আগে মান এনকোড হয় কিনা (যেমন EAN-13-এ অক্ষর); `missingImages()`-এর মতো সতর্কতা, প্রিন্টে লাল ভুল-বক্স নয়।
3. ✅ **৩.৭ কার্ড যাচাই** (৮ অক্টোবর; ক–ঙ, উপরে দেখুন)। পরে ভাবা যায়: অফলাইন যাচাই (QR-এ স্বাক্ষরিত তথ্য, নেটওয়ার্ক ছাড়া গার্ডের অ্যাপে পড়া), যাচাইয়ের ঘটনা HR-কে webhook-এ (Phase 6)।
4. ✅ **৩.৮ ইমপোর্টে কার্ডের কলাম** (৯ অক্টোবর) — বিস্তারিত উপরের "আগামীকাল এখান থেকে শুরু" → (ক)-এর "অগ্রগতি"-তে। ➡️ এরপর Phase 3 শেষের পরীক্ষা। (কার্ড থাকলে কার্ডধারী মোছা আটকানো আগেই ছিল; ৮ অক্টোবর বোতামও বন্ধ করা হয়েছে।)
5. ছোট বাকি কাজ: ✅ ডার্ক মোডে `text-brand` (৮ অক্টোবর, `brand-ink`); ✅ অন্য ট্যাবে ছাপার পরে কার্ডের বিস্তারিত/কার্ডধারীর তথ্য হালনাগাদ (৮ অক্টোবর, BroadcastChannel)।

### আগের সেশন (৫ অক্টোবর ২০২৬, সন্ধ্যা)

**আজ কী হলো (commit ক্রমে):**
1. `e543af9` — পরিবারের সদস্যের নিজের ছবি ও সই (ব্যাকএন্ড): `cardholder_files.family_member_id`; পরিবারের কার্ডে শুধু সদস্যের ছবি/সই (`CardDataResolver::holderImages()`), না থাকলে ফাঁকা।
2. `38bf956` — একই কাজের UI: সদস্যের ফর্মে ছবি/সই, তালিকায় ছোট ছবি, ইস্যু ডায়ালগে "ছবি নেই" সতর্কতা (`cards.ts` → `missingImages()`), আপলোডের সাধারণ helper `uploadHolderImages()`।
3. `80e7f70` — docs: ৩.৫ সম্পূর্ণ, পুরনো প্রিন্ট দেখে ৩.৬-এর পরিকল্পনা।
4. `992beb4` — ৩.৬ ধাপ ১: প্রিন্টের লগ (`card_prints`), কার্ডে `print_count`/`last_printed_at`, পারমিশন `cards.print`, একক আর ব্যাচ প্রিন্টের API, রোল পেজে কার্ডের পারমিশনের লেবেল।

**আজকের সিদ্ধান্ত (ইউজার/নকশা):**
- পরিবারের কার্ডে **কখনো কার্ডধারীর ছবি/সই নয়** — সদস্যের নিজেরটা, না থাকলে টেমপ্লেটের বিকল্প ছবি বা ফাঁকা (পুরনো সিস্টেমে কার্ডধারীর ছবি ছাপা হতো; ইউজার বদলাতে বলেছেন)।
- কোন ফাইলের ধরন সদস্যের জন্য চলবে তা config-এ (`config/cardholder_files.php` → `family`)।
- **ইস্যু আর প্রিন্ট আলাদা**: একই কার্ড বারবার ছাপা যায়, প্রতিবার লগ, নতুন নম্বর/কার্ড নয়। **শুধু সক্রিয় আর মেয়াদ থাকা কার্ড ছাপা যায়** (বাতিল/বদলে যাওয়া/মেয়াদোত্তীর্ণ → 422, নতুন ইস্যু করতে বলে)। ব্যাচে একটাও না চললে কিছুই লগ হয় না।
- `print_count` = মোট ছাপা কপি (০ = পুরনো "PENDING")।
- প্রিন্টের পদ্ধতি config-এ (`config/cards.php` → `print.methods`: browser, pdf), কপি সর্বোচ্চ ১০, ব্যাচে সর্বোচ্চ ২০০।

**পুরনো সিস্টেমের যে ফাঁক পাওয়া গেছে (এখনো বাকি):** কার্ডধারীর তালিকায় "Status: PENDING / PRINTED" কলাম — ৩.৬ ধাপ ৩-এ (`cards.print_count` দিয়ে)।

**dev ডাটাবেসের অবস্থা:** migration সব চালানো, `app:sync-permissions` চালানো (৪০টা পারমিশন)। acme-এর আগে থেকে থাকা Administrator/Operator রোলে `cards.print` নিজে থেকে বসেনি (ইচ্ছাকৃত, §৬) — Owner দিয়ে লগইন করলে সব আছে; অন্য রোলে দরকার হলে Roles পেজে টিক দিন। আজকের ব্রাউজার-যাচাইয়ে কোনো কার্ড ইস্যু করা হয়নি; অস্থায়ী QA টেমপ্লেট/সদস্য মুছে ফেলা হয়েছে।

**মান:** ১৯৫টা টেস্ট পাস, Larastan/Pint/vue-tsc পরিষ্কার, `npm run build` চলে।

---

## ১. প্রজেক্টের পরিচয়

- **কী:** যেকোনো প্রতিষ্ঠানের জন্য আইডি কার্ড ব্যবস্থাপনার multi-tenant SaaS। একটা পুরনো Laravel 7 + Vue 2 আইডি কার্ড সিস্টেমের সব ফিচার নতুন করে বানানো হচ্ছে, সাথে নতুন ফিচার যোগ হবে।
- **ফোল্ডার:** `idcard-pro/`। পুরনো সিস্টেমটা পাশের `../idcard/` ফোল্ডারে, শুধু রেফারেন্স হিসেবে। সেখানে কিছু লেখা বা বদলানো যাবে না।
- **স্ট্যাক:** Laravel 13 (PHP 8.4), Vue 3 + TypeScript SPA, MySQL 8.4, Redis, Docker Compose।

## ২. কী কী সম্পন্ন হয়েছে

| ধাপ | বিষয় | মূল ফাইল/ফোল্ডার |
|---|---|---|
| Phase 1 | Docker, multi-tenancy, কাস্টম লগইন (lockout, TOTP MFA, পাসওয়ার্ড রিসেট, API token), রোল-পারমিশন, ইউজার, অডিট লগ, SPA শেল, en/bn, লাইট/ডার্ক | `docker/`, `compose.yaml`, `app/Support/Tenancy`, `app/Services/Auth`, `resources/js` |
| 2.1–2.2 | Master Data: ১২টা রেফারেন্স তালিকা, প্যারেন্ট-চাইল্ড, ডিফল্ট আইটেম, ব্যবস্থাপনার পেজ | `config/master_data.php`, `app/Services/MasterData`, `pages/master-data` |
| R1–R4 | সব মডিউল Controller → Service → Repository কাঠামোয় | `app/Services`, `app/Repositories`, `app/Data` |
| 2.3 | কার্ডধারী: CRUD API, ১২টা ফিল্টার, তালিকা, ছয় ট্যাবের ফর্ম, বিস্তারিত পেজ | `app/Services/Cardholders`, `pages/cardholders` |
| 2.3খ | কার্ডধারীর ফাইল: ছবি ও সই (সংস্করণসহ), একাধিক ডকুমেন্ট, ছবি পরিশোধন, private disk, signed link | `config/cardholder_files.php`, `CardholderFileService`, `app/Support/Media` |
| 2.4 | পরিবারের সদস্য (বিস্তারিত পেজের ট্যাব; ৩.৫-এ নিজের ছবি ও সই) | `FamilyMemberService`, `CardholderFamily.vue`, `FamilyMemberDialog.vue` |
| 2.5 | শিক্ষা ও প্রশিক্ষণ কোর্স (এক টেবিলে), কাস্টম ফিল্ড (সেটআপ পেজ, প্রিসেট, ফর্ম ট্যাব, ফিল্টার) | `QualificationService`, `app/Services/CustomFields`, `config/custom_field_presets.php`, `pages/custom-fields` |
| 2.6 | CSV/XLSX ইমপোর্ট: যাচাই আর প্রিভিউ, queue-তে ইমপোর্ট, ইতিহাস, টেমপ্লেট | `CardholderImportService`, `CardholderImportMapper`, `app/Jobs/ImportCardholders.php`, `app/Support/Spreadsheets`, `config/cardholder_import.php` |

**মান:** ২৪৮টা টেস্ট পাস, Larastan (level 5) আর Pint পরিষ্কার, TypeScript টাইপ-চেক পরিষ্কার। প্রতিটা পেজ headless Chrome-এ চালিয়ে যাচাই করা হয়েছে।

**বর্তমান মডিউল আর তাদের service:**

| মডিউল | Service | Repository |
|---|---|---|
| ইউজার | `Users\UserService` | `UserRepository` |
| রোল | `Roles\RoleService` | `RoleRepository` |
| প্রতিষ্ঠান (tenant) | `Tenants\TenantService` | `TenantRepository` |
| অডিট | `Audit\AuditService` | `ActivityRepository`, `LoginEventRepository` |
| লগইন | `Auth\AuthenticationService`, `Auth\PasswordResetService`, `Auth\TwoFactorAuthenticator` | `UserRepository`, `PasswordResetTokenRepository` |
| নিজের অ্যাকাউন্ট | `Account\AccountService` | `UserRepository` |
| মাস্টার ডেটা | `MasterData\MasterDataService` | `MasterDataRepository`, `CardholderRepository` ও `FamilyMemberRepository` (ব্যবহার যাচাই) |
| কার্ডধারী | `Cardholders\CardholderService`, `Cardholders\CardholderValidator` (ফর্ম আর ইমপোর্টের একই নিয়ম) | `CardholderRepository`, `MasterDataRepository` |
| কার্ডধারীর ফাইল | `Cardholders\CardholderFileService` (+ `Support\Media\ImageSanitizer`) | `CardholderFileRepository` |
| পরিবারের সদস্য | `Cardholders\FamilyMemberService` | `FamilyMemberRepository` |
| শিক্ষা ও কোর্স | `Cardholders\QualificationService` | `QualificationRepository` |
| কাস্টম ফিল্ড | `CustomFields\CustomFieldService` | `CustomFieldRepository` |
| কার্ড টেমপ্লেট ও ডিজাইনার | `CardTemplates\CardTemplateService`, `CardTemplates\CardTemplateDesignService`, `CardTemplates\CardTemplateImageService` | `CardTemplateRepository`, `CardTemplateElementRepository` |
| প্রতিষ্ঠানের ফন্ট | `Fonts\TenantFontService` (+ `Support\Media\FontInspector`) | `TenantFontRepository`, `CardTemplateElementRepository::templatesUsingFont()` |
| ছবির লাইব্রেরি | `ImageLibrary\TenantImageService` (+ `ImageSanitizer`) | `TenantImageRepository`, `CardTemplateElementRepository::templatesUsingLibraryImage()/replaceLibraryImage()`, `CardTemplateRepository::incrementVersions()` |
| কার্ডে ছাপার মান | `Cards\CardDataResolver` (প্রিভিউ, ইস্যু, `forCard()`; প্রিন্ট ৩.৬-এ) | `SignatoryRepository::activeFor()` |
| কার্ড ইস্যু | `Cards\CardIssueService`, `Cards\CardService`, `Cards\CardNumberService`, `Cards\CardLayoutPresenter` | `CardRepository`, `CardSequenceRepository` (lockForUpdate) |
| কার্ড প্রিন্টের লগ | `Cards\CardPrintService` (`printCard`, `printCards`, `history`, `printable`) | `CardPrintRepository`, `CardRepository::lockedByPublicIds()/addPrints()` |
| প্রিন্টের পাতা ও PDF | `Cards\CardRenderService` (signed render লিংক, শিট), `Cards\CardPdfService` (+ `Support\Pdf\PdfRenderer` → Gotenberg) | `CardRepository::byPublicIds()`, `TenantRepository` |
| ছাপার আগের সতর্কতা | `Cards\CardWarnings` (ছবি নেই, বারকোড/QR ফাঁকা; + `Support\Barcodes\BarcodeValues` = JsBarcode-এর নিয়ম, QR byte-সীমা) | — |
| কার্ড যাচাই (৩.৭) | `Cards\CardVerificationService` (`verify`, লগ, `history`), সেটিং `Tenant::verificationSettings()/verifyUrl()` | `CardRepository::findByVerifyCode()/addVerification()`, `CardVerificationRepository` |
| ইমপোর্ট | `Cardholders\CardholderImportService`, `Cardholders\CardholderImportMapper` (+ `Support\Spreadsheets\TabularFileReader`) | `CardholderImportRepository` |

## ৩. চালানো

```bash
cd idcard-pro
docker compose up -d                                   # app, web, mysql, redis, mailpit, queue, scheduler, gotenberg (PDF)
docker compose exec app php artisan migrate --seed     # প্রথমবার (বা migrate:fresh --seed)
docker compose exec app php artisan storage:link       # প্রথমবার: প্রতিষ্ঠানের লোগো দেখানোর জন্য
docker compose run --rm --no-deps vite npm run build   # ফ্রন্টএন্ড বিল্ড
docker compose --profile dev up -d vite                # অথবা hot reload
docker compose restart queue                           # PHP কোড বদলানোর পর (ইমপোর্টের জব queue-তে চলে)
```

- অ্যাপ: http://localhost:8080 (`127.0.0.1:8080`-ও চলে)
- ইমেইল দেখা: http://localhost:8025 (Mailpit, কোনো ইমেইল বাইরে যায় না)
- ডেমো লগইন: `owner@acme.test` / প্রতিষ্ঠান `acme` / `Demo@Pass2026!` (প্ল্যাটফর্ম অ্যাডমিন: `platform@idcard.local`, প্রতিষ্ঠান ফাঁকা)
- ডেমো ডেটা: ৪০ জন বানানো কার্ডধারী আর বিভাগভিত্তিক ইউনিট (`DemoCardholderSeeder`)। এগুলো আসল কোনো ব্যক্তির তথ্য নয়।

**মান যাচাই** (প্রতিটা ধাপের শেষে চালাতে হবে):
```bash
docker compose exec app php artisan test --compact
docker compose exec app vendor/bin/phpstan analyse --memory-limit=1G
docker compose exec app vendor/bin/pint --format agent
docker compose run --rm --no-deps vite npx vue-tsc --noEmit
```

**দরকারি কমান্ড:**
- `php artisan app:sync-permissions`: `config/permissions.php` থেকে পারমিশন সিঙ্ক করে। Owner রোল সবসময় সব পারমিশন পায়।
- `php artisan app:sync-master-data`: বর্তমান প্রতিষ্ঠানগুলোতে নতুন ডিফল্ট master data যোগ করে। বারবার চালানো নিরাপদ।

**API এক নজরে (কার্ড, ৩.৫–৩.৬):** `GET|POST /cardholders/{id}/cards`, `GET /cardholders/{id}/cards/next-number?template=&family_member=`, `GET /cards` (ফিল্টার §৮ ধাপ ৫), `GET /cards/summary`, `GET /cards/options`, `GET|PATCH /cards/{id}` (show-এ `meta.layout` + `meta.values`), `POST /cards/{id}/revoke` (কার্ডে `printable`, `print_count`, `last_printed_at`; options-এ `print`), `GET|POST /cards/{id}/prints` {method, copies, note}, `GET /cards/{id}/pdf`, `POST /cards/pdf` (PDF), `POST /cards/prints` {cards: [...], method, copies, note} (ব্যাচ)। ফাইল: `POST /cardholders/{id}/files` + ঐচ্ছিক `family_member`, `GET .../files?family_member=`।

**API এক নজরে (Phase 3 পর্যন্ত):** `/api/v1/signatories`, `/api/v1/card-templates` (+ `/{id}/duplicate`, `/{id}/elements`, `/{id}/images`, `/{id}/preview`), `/api/v1/card-template-images/{template}/{image}` (signed), `/api/v1/fonts` (index/store/destroy) + `/api/v1/font-files/{font}` (signed), `/api/v1/library-images` (index/store/update/destroy, `/{id}/replace`) + `/api/v1/library-image-files/{image}` (signed)।

**API এক নজরে (Phase 2):** `/api/v1/cardholders` (+ `/{id}/files`, `/{id}/family-members`, `/{id}/qualifications`), `/api/v1/custom-fields` (+ `/presets`), `/api/v1/cardholder-imports` (+ `/template`, `/{id}/start`), `/api/v1/cardholder-files/{id}` (signed link, লগইন লাগে না)।

## ৪. নতুন মডিউল যোগ করার চেকলিস্ট

উদাহরণ হিসেবে Master Data মডিউল দেখুন। প্রতিটা নতুন মডিউলে এই ক্রম মানতে হবে:

1. **Migration + Model + Factory:** `php artisan make:model X -mf`
   - `tenant_id` FK (`cascadeOnDelete`) আর `public_id` (ULID) কলাম থাকবে।
   - মডেলে `BelongsToTenant`, `HasUlids` (`uniqueIds() => ['public_id']`), `getRouteKeyName() => 'public_id'`।
   - `@property` ডকব্লক লিখতে হবে।
   - `LogsActivity` দিয়ে শুধু অনুমোদিত ফিল্ড লগ হবে। বায়োমেট্রিক বা গোপন ফিল্ড কখনো লগ হবে না।
2. **পারমিশন:** `config/permissions.php`-এর `modules`-এ `x => ['view','create','update','delete']`, আর `default_roles`-এ যোগ। তারপর `app:sync-permissions`।
3. **DTO:** `app/Data/X/XFilter.php` (`final readonly`, `SORTS` constant, `fromArray()`)।
4. **Repository:** `app/Repositories/Contracts/XRepositoryInterface.php` (extends `RepositoryInterface`) আর `app/Repositories/Eloquent/XRepository.php` (extends `EloquentRepository`)। তারপর `RepositoryServiceProvider::$bindings`-এ যোগ।
5. **Service:** `app/Services/X/XService.php`
   - সব বিজনেস নিয়ম এখানে থাকবে।
   - নিয়ম ভাঙলে `BusinessRuleException` (HTTP 422)।
   - একাধিক টেবিল বদলালে `DB::transaction`।
6. **Form Request:**
   - `ListXRequest`-এ `filter` থাকবে `array:<অনুমোদিত কী>`, সাথে `sort` আর `per_page`।
   - `SaveXRequest`-এ unique নিয়ম `tenant_id` দিয়ে সীমিত থাকবে।
   - `authorize()`-এ পারমিশন চেক।
7. **API Resource:** `app/Http/Resources/XResource.php`। বাইরে শুধু `public_id` যাবে, ভেতরের id কখনো না।
8. **Controller:** `app/Http/Controllers/Api/V1/XController.php`। শুধু service ডাকবে।
9. **Route:** `routes/api.php`-এ `tenant.required` গ্রুপের ভেতরে।
10. **ভাষা:** `lang/en`, `lang/bn` আর `resources/js/locales/{en,bn}.json`।
11. **টেস্ট:** `tests/Feature/...`। থাকতেই হবে: CRUD, কোম্পানির ডেটা আলাদা থাকা (অন্য tenant থেকে 404), পারমিশন (403), ফিল্টার, ভ্যালিডেশন।
12. **ফ্রন্টএন্ড:**
    - `resources/js/pages/x/`;
    - লিস্টের জন্য `useListQuery`, আর UI কিট `components/ui/*`;
    - রাউটে `meta.permission`;
    - `AppLayout.vue`-তে মেনু।
13. **শেষ কাজ:** মান যাচাইয়ের সব কমান্ড চালানো → ব্রাউজারে যাচাই → commit।

## ৫. কাজের নিয়ম

- **ছোট ছোট ধাপে কাজ।** প্রতিটা ধাপের আগে "এখন করছি: …" বলা, শেষে কী হলো জানানো। একসাথে অনেক কিছু নয়।
  - **একটা ছোট ধাপ (একটা ব্যাকএন্ড বা একটা পেজের বদল) → টেস্ট → commit → থেমে ইউজারকে জানানো**, ইউজার বললে তবেই পরের ধাপ (ইউজারের নির্দেশ, ৫ অক্টোবর)।
  - আউটপুটে কোনো "এরর" এলে (যেমন যাচাই-স্ক্রিপ্টে 422) সাথে সাথে সহজ ভাষায় বলা: অ্যাপের বাগ, নাকি শুধু স্ক্রিপ্ট/টেস্টের ব্যাপার।
- **বাড়ানো যায়, নির্ভরযোগ্য, আর যেকোনো HR ম্যানেজমেন্ট সিস্টেমের সাথে যুক্ত করা যায় এমন কাজ** (ইউজারের লক্ষ্য): সব ফিচার API-first (`/api/v1`, `public_id`, Resource দিয়ে স্থির আকার), কোনো প্রতিষ্ঠান-নির্দিষ্ট জিনিস কোডে বাঁধা নয় (config/master data/কাস্টম ফিল্ডে), বিজনেস নিয়ম service-এ (যাতে UI, ইমপোর্ট আর ভবিষ্যৎ HR-sync একই নিয়ম মানে), প্রতিটা বদল টেস্টসহ। HR সংযোগের পরিকল্পনা Phase 6 (webhooks, API token, কার্ডধারী sync) — নতুন কিছু বানানোর সময় সেটা যেন সহজে যোগ করা যায় খেয়াল রাখা।
- **পুরনো সিস্টেমের কোনো ফিচার বাদ যাবে না।** নতুন ফিচার যোগ হবে।
- **কোনো জানা বাগ রেখে সামনে যাওয়া নয়** (ইউজার, ৮ অক্টোবর): কাজের মধ্যে পাওয়া বাগ বা পুরনো সিস্টেমের ফাঁক সাথে সাথে ঠিক করা। ভালো সমাধানে লাইব্রেরি বা সেটিং লাগলে যোগ করা যায় — কোন প্যাকেজ, কেন (রক্ষণাবেক্ষণ, dependency, বাইরে network নয়) ইউজারকে বলে।
- **প্রতিষ্ঠান-নির্দিষ্ট আচরণ প্রতিষ্ঠানের নিজের সেটিং থেকে** (ইউজার, ৮ অক্টোবর; যেমন কার্ড যাচাই): ডিফল্ট `config/*.php`-এ, প্রতিষ্ঠানের মান `tenants.settings`-এ, Tenant-এ accessor (ডিফল্টসহ), `UpdateTenantSettingsRequest` + `TenantService::updateSettings()` + `TenantResource`, Organization পাতায় অংশ।
- **ভাষা:** ইউজারের সাথে যোগাযোগ বাংলায়। কোড আর কমেন্ট ইংরেজিতে।
- **Git:**
  - commit হবে ইউজারের নিজের git পরিচয়ে, কোনো AI attribution বা `Co-Authored-By` ছাড়া।
  - `.claude/`, `CLAUDE.md`, `AGENTS.md`, `.mcp.json`, `boost.json`, `.env` git-এ যাবে না।
  - পুরনো সিস্টেমের ডেটা বা প্রতিষ্ঠানের নামও git-এ যাবে না।
  - remote যোগ করা বা push করা যাবে না। ইউজার নিজে করবেন।
- **বাইরে কোনো ডেটা যাবে না:**
  - ইমেইল যাবে Mailpit-এ, SMS ড্রাইভার `log`।
  - কোনো থার্ড-পার্টি API নয়। `Password::uncompromised()` ব্যবহার নিষেধ।
  - ফন্ট বিল্ডের সময় প্রজেক্টের ভেতরে রাখা হয়।
- **পুরনো সিস্টেমের ডেটা সংবেদনশীল।** রিপোর্ট বা ডেটা কোথাও পাবলিশ করা যাবে না।

## ৬. জানা সমস্যা ও তার সমাধান

| সমস্যা | সমাধান |
|---|---|
| VS Code Intelephense-এ "Undefined type", যেখানে ক্লাস টার্মিনাল থেকে নতুন ফোল্ডারে বানানো হয়েছে | `Ctrl+Shift+P` → "Intelephense: Index workspace"। কোডে কোনো সমস্যা নেই। `idcard-pro` আলাদা উইন্ডোতে খুললে ভালো |
| `App.vue`-তে Vetur এরর | Vetur (Vue 2) বন্ধ করে "Vue - Official" এক্সটেনশন ব্যবহার করুন |
| TypeScript 7, vue-tsc-এর সাথে চলে না | `typescript ~6.0.3`-এ আটকে রাখা আছে। আপগ্রেড করবেন না |
| টেস্ট ভুল করে ডেভেলপমেন্ট ডাটাবেসে চলা | compose-এ `env_file` রাখবেন না। phpunit.xml-এর sqlite/array সেটিং যেন কাজ করে |
| কন্টেইনার রিস্টার্টের পর nginx 502 | `docker/nginx/default.conf`-এ resolver দিয়ে ঠিক করা আছে |
| spatie Role-এর `users()` সম্পর্ক Sanctum guard-এ 500 দেয় | `App\Models\Role` ব্যবহার করুন (`config/permission.php`-এ সেট করা আছে) |
| রাউট গ্রুপে `whereIn` | `->group()`-এর **আগে** বসাতে হবে, পরে বসালে কাজ করে না |
| প্রতিষ্ঠানের লোগো `/storage/...`-এ 403 | `public/storage` লিংক নেই। `php artisan storage:link` চালান (gitignore-এ আছে, তাই প্রতি নতুন সেটআপে একবার) |
| ইমপোর্ট "Queued" অবস্থায় আটকে থাকে | queue worker পুরনো কোড চালাচ্ছে। `docker compose restart queue` |
| FormRequest-এ `validator()` নামে নিজের মেথড | Laravel এটাকে নিজের hook হিসেবে ডাকে। অন্য নাম দিন |
| `apiResource`-এ `$this->route('customField')` null | রাউট প্যারামিটার snake_case (`custom_field`)। Controller-এর আর্গুমেন্ট camelCase হলেও চলে |
| `type="number"` ইনপুটের মান `.trim()`-এ এরর | Vue এই মানকে সংখ্যা বানায়। `String(value ?? '').trim()` |
| দশমিক মানসহ (যেমন 85.6) ফর্ম জমাই হয় না, কোনো বার্তাও আসে না | `type="number"`-এর ডিফল্ট step 1। `UiInput`-এ `step="any"` দিন |
| আগে থেকে থাকা প্রতিষ্ঠানের রোলে নতুন মডিউলের পারমিশন নেই (যেমন ডেমো Operator-এর `templates.view`) | `app:sync-permissions` শুধু নতুন পারমিশন বানায় আর Owner-কে দেয়; অন্য রোল প্রতিষ্ঠান নিজে Roles পেজে ঠিক করে। ইচ্ছাকৃত |
| ডার্ক মোডে গাঢ় ব্র্যান্ড রঙে `text-brand` লেখা প্রায় অদৃশ্য (যেমন বাঁ মেনুর চালু আইটেম), আর হালকা ব্র্যান্ড রঙ (হলুদ) লাইট মোডে অদৃশ্য | ঠিক করা (৮ অক্টোবর): লেখা আর কিনারায় **`text-brand-ink` / `border-brand-ink` / `outline-brand-ink`** ব্যবহার করুন, `text-brand` নয়। `lib/brand.ts` → `applyBrand()` প্রতিষ্ঠানের রং থেকে `--brand-ink-light` (দরকার হলে কালোর দিকে) আর `--brand-ink-dark` (সাদার দিকে) বানায়, যতক্ষণ না সব surface-এ কন্ট্রাস্ট ≥ ৪.৫ (WCAG AA); `.dark`-এ `--brand-ink` বদলায়। ভরাট জায়গা (`bg-brand`) আসল রং, তার ওপর লেখা `text-brand-contrast` (যেটায় কন্ট্রাস্ট বেশি — কালো বা সাদা) |
| স্বয়ংক্রিয় ব্রাউজার যাচাইয়ে অল্প সময়ে বহুবার লগইন করলে লগইন আটকে যায় (`throttle:auth`) | প্রতিটা চালানোর মাঝে ~১ মিনিট বিরতি, অথবা এক স্ক্রিপ্টে একবার লগইন করে সব যাচাই |
| Pest-এ `assertJsonPath('data.fields.custom\\.class')` কাজ করে না | ডটওয়ালা key-র জন্য `->json(...)` নিয়ে `toMatchArray` ব্যবহার করুন |
| প্ল্যাটফর্ম অ্যাডমিন হিসেবে প্রতিষ্ঠানে কাজ করার সময় রিলোডে বা লিংকে প্রতিষ্ঠানের তালিকায় ফিরে যাওয়া | ঠিক করা (৬ অক্টোবর): বাছাই করা প্রতিষ্ঠান localStorage-এ (`acting-tenant`), তালিকা থেকে "Open" চাপলে `?redirect=`-এর পাতায় ফেরে। নিজে যাচাই করতে চাইলে Owner (`owner@acme.test`, প্রতিষ্ঠান `acme`) দিয়েও ঢোকা যায় |
| ফোনে পাতা পর্দার চেয়ে চওড়া, পাশে সরে, টোস্ট ডানে বাইরে চলে যায় | টেবিলের `overflow-x-auto` মোড়কের ভেতরে `sr-only` (বা অন্য `absolute`) থাকলে মোড়কে `relative` দিন, নইলে সেটা স্ক্রল-বাক্সের বাইরে বসে। ট্যাবের সারিতে `overflow-x-auto` + বোতামে `whitespace-nowrap`। যাচাই: ফোনের মাপে `document.documentElement.scrollWidth` = ৩৯০ |
| vue-i18n লেখার ভেতরে `{code}`/`{CODE}`-এর মতো আক্ষরিক বন্ধনী গায়েব হয়ে যায় | vue-i18n এগুলোকে placeholder ভাবে; লেখায় `{'{'}code{'}'}` লিখুন |
| প্রতিষ্ঠানের পাতা সার্ভারের পুরো রেকর্ড আবার পাঠালে নতুন অবজেক্ট-ঘর `"[object Object]"` হয়ে 422 | ঠিক করা (৮ অক্টোবর): `OrganizationPage.vue` শুধু ফর্মের নিজের ঘর পাঠায়; নতুন সেটিং যোগ করলে `save()`-এ নিজে append করুন |
| Signed link এক হোস্টে বানানো, অন্য হোস্টে 403 | ফাইলের লিংক relative signed (`signed:relative`), তাই `localhost` আর `127.0.0.1` দুটোতেই চলে |

## ৭. Phase 2-এ নেওয়া সিদ্ধান্ত (পরের কাজের জন্য জরুরি)

**কার্ডধারী (২.৩):**
- `employee_no` আর `national_id` ঐচ্ছিক, তবে থাকলে প্রতি tenant-এ unique।
- API-তে master data FK যায় `public_id` হিসেবে (`department_id` ইত্যাদি), রেসপন্সে `department: {id, name, name_local}`। কলাম → টাইপের ম্যাপ `Cardholder::LOOKUPS`।
- `unit` অবশ্যই নির্বাচিত `department`-এর, আর `sub_region` নির্বাচিত `region`-এর হতে হবে। ব্যবহৃত master data মোছা যায় না (422)।
- অডিট লগে শুধু নাম, নম্বর, অফিস আর অবস্থা লেখা হয়। NID, পাসপোর্ট, ফোন আর ঠিকানা কখনো লগ হয় না।
- মুছলে এখন hard delete, সাথে ফাইল, পরিবার আর শিক্ষার রেকর্ডও মোছে। **Phase 3-এ কার্ড ইস্যু থাকলে মোছা আটকাতে হবে।**

**ফাইল (২.৩খ):**
- ফাইলের ধরন `config/cardholder_files.php`-এ: `photo` (পুরনো `picture`), `signature` (পুরনো `holder_sign`), `document` (পুরনো `pdf` + `files`)।
- ছবি আর সই একটাই চালু থাকে, পুরনোগুলো `replaced_at` দিয়ে সংরক্ষিত। **Phase 3-এর কার্ড ইস্যু যে ফাইল ছাপা হয়েছিল তার id ধরে রাখবে।** চালু ছবি মুছলে আগের সংস্করণ নিজে থেকে চালু হয় না।
- ছবি GD দিয়ে নতুন করে encode হয় (EXIF/GPS মোছে, দিক ঠিক করে, মাপ সীমিত করে)। লিংক ১৫ মিনিটের।

**পরিবার (২.৪):** পুরনো সব ঘর আছে। পুরনো সিস্টেমে রক্তের গ্রুপ ফর্মে নেওয়া হলেও DB-তে সেভ হতো না, এখানে ঠিক করা হয়েছে। স্বামী/স্ত্রী, ভাই/বোন আর অন্যান্য সম্পর্ক যোগ হয়েছে। `card_no` এখন হাতে লেখা নম্বর। পরিবারের কার্ড আর টেমপ্লেট Phase 3-এ।

**শিক্ষা ও কোর্স (২.৫):** একটাই `qualifications` টেবিল (`kind` = education / course)। পুরনো `other_courses` কোনো ব্যক্তির সাথে যুক্ত ছিল না, এটা পুরনো সিস্টেমের বাগ ছিল।

**কাস্টম ফিল্ড (২.৫):**
- সংজ্ঞা থাকে `custom_fields` টেবিলে, মান থাকে `cardholders.custom_fields` (JSON)-এ।
- ধরন: text, textarea, number, date, select, checkbox। key, ধরন আর entity তৈরির পর বদলানো যায় না।
- নিষ্ক্রিয় বা মোছা ঘরের পুরনো মান রেকর্ডে থেকে যায়।
- পুরনো `student_information` (class, section, session, registration) এখন "student" প্রিসেট। নতুন প্রিসেট যোগ হয় `config/custom_field_presets.php`-এ।
- `entity` কলাম রাখা আছে, যাতে পরে পরিবারের সদস্যদের জন্যও চালু করা যায়।
- ফিল্টার: `filter[custom][key]=value`। তালিকায় select আর checkbox ঘরগুলো ফিল্টার হিসেবে দেখায়।

**ইমপোর্ট (২.৬):**
- ধাপ: আপলোড → সব সারি যাচাই, DB-তে কিছু লেখা হয় না → প্রিভিউ → শুরু → queue জব → ফলাফল। কাজ শেষে ফাইল মুছে যায়।
- হেডার মেলানো হয় নাম দিয়ে, পুরনো নামসহ (`service no`, `ts no`, `bg_group`, `dob`, `district`, `upazila`...)। তালিকা আছে `config/cardholder_import.php`-এ। কাস্টম ফিল্ড মেলে key বা লেবেল দিয়ে।
- অপশন: অজানা master data তৈরি (ইউনিট তৈরি হয় তার বিভাগের ভেতরে), আর employee no মিললে আগের রেকর্ড হালনাগাদ।
- প্রতিটা সারি আলাদা ট্রানজ্যাকশনে যায়। তারিখ চলে Y-m-d, d/m/Y, d-m-Y আর Excel serial।
- XLSX পড়া হয় নিজস্ব হালকা রিডার দিয়ে (`zip` + `xmlreader`), কোনো নতুন প্যাকেজ নেই। শুধু প্রথম শিট পড়া হয়, ফর্মুলার ফল (cached value) নেওয়া হয়।
- **পুরনো ইমপোর্টের কার্ডের কলাম** (card no, issue date, last print date/time, notes) এখন "বাদ পড়া কলাম" হিসেবে দেখায়। **Phase 3-এ কার্ড মডিউলের সাথে এগুলো যোগ করতে হবে।**
- পারমিশন `cardholders.import` (Owner আর Administrator)।

## ৮. পরের ধাপ: Phase 3, কার্ড (পুরনো সিস্টেমের মূল কাজ)

প্রস্তাবিত ক্রম। প্রতিটা ভাগ ব্যাকএন্ড আর পেজ আলাদা করে করতে হবে, আগের মতো।

1. **৩.১ Signatories** (পুরনো `authorized_signatures`: name, designation, signature, status; authority type এখন master data `authority_type`)
   - ✅ **৩.১ক সম্পন্ন:** `signatories` টেবিল (name/name_local, designation/designation_local, `authority_type_id`, `signature_disk`/`signature_path`, `effective_from`/`effective_to`, `is_active` ডিফল্ট false, পুরনো সিস্টেমের মতো), `Signatory` model + factory, `signatories.*` পারমিশন (Administrator সব)।
   - ✅ **৩.১খ সম্পন্ন:** `SignatoryService` + `SignatoryRepository`, `/api/v1/signatories` (CRUD, `filter[search|is_active]`), সই আপলোড/মোছা `POST|DELETE /signatories/{id}/signature` (`ImageSanitizer`, private ডিস্ক, পুরনো ফাইল মুছে যায়), `signature_url` = ১৫ মিনিটের relative signed link (ছবির path-এর hash লিংকে থাকে, তাই ছবি বদলালে পুরনো লিংক অকেজো)। ব্যবহৃত `authority_type` master data থেকে মোছা যায় না। ৫টা টেস্ট।
   - ✅ **৩.১গ সম্পন্ন:** সেটআপ → সইকারী পেজ (`pages/signatories`): তালিকায় সইয়ের ছবি, খোঁজা আর অবস্থার ফিল্টার; ডায়ালগে সব ঘর আর সই আপলোড বা মোছা (`CardholderImagePicker` আবার ব্যবহার করা হয়েছে)। ব্রাউজারে যাচাই করা।
   - **নোট:** Operator রোলের এখন `signatories.view` নেই। ৩.৫-এ কার্ড ইস্যুর সময় Operator-কে সইকারী বাছতে হলে এটা দিতে হবে।
2. ✅ **৩.২ কার্ড নম্বরের ফরম্যাট সম্পন্ন:**
   - ফরম্যাট থাকে `tenants.settings.card_number_format`-এ, ডিফল্ট `{CODE}/{YY}{SEQ:6}` (পুরনো `CODE/YY######`)। টোকেন: `{CODE}`, `{YYYY}`, `{YY}`, `{MM}`, `{SEQ:n}`। যাচাই আর মান বসানোর কাজ `App\Services\Cards\CardNumberFormat`-এ।
   - `card_sequences` টেবিল (`tenant_id` + `key`, unique)। key হলো SEQ-এর আগের অংশ, তাই কোড বা বছর বদলালে গণনা ১ থেকে শুরু হয়।
   - `CardNumberService::next($code)` `lockForUpdate` দিয়ে নম্বর দেয়। **৩.৫-এ কার্ড সেভ করার ট্রানজ্যাকশনের ভেতরেই এটা ডাকতে হবে**, আর কার্ডের টেবিলে `(tenant_id, number)` unique দিতে হবে।
   - সেটিংস পেজে "কার্ড নম্বর" অংশ আছে, সার্ভার থেকে লাইভ প্রিভিউসহ (`GET /settings/card-number-preview`, কোনো নম্বর আটকে রাখে না)। ১৩টা টেস্ট।
   - vue-i18n বার্তায় `{CODE}` লিখতে হলে literal সিনট্যাক্স `{'{CODE}'}` ব্যবহার করুন, নইলে ফাঁকা দেখায়।
3. ✅ **৩.৩ টেমপ্লেট সম্পন্ন** (পুরনো `templates`: template_type, title, code, template_structure, card_width/height, h_w_extention, status, authority_type)
   - `card_templates` টেবিল: name, code (কার্ড নম্বরের `{CODE}`, বড় হাতের অক্ষরে রাখা হয়), `card_type_id` (master data-র নতুন `card_type` তালিকা, ডিফল্ট Employee/Family/Visitor Card, পুরনো `employee_types`), `audience` (cardholder / family_member; পুরনো "Family Card" টেমপ্লেট), orientation, width/height + `size_unit` (mm/px, ডিফল্ট CR80 85.6×54 mm), `authority_type_id` (সক্রিয় সইকারী বাছতে), `validity_months` (আগে ইমপোর্টে ২ বছর কোডে বাঁধা ছিল), `is_double_sided`, `is_active`, `version`।
   - নকশার ঘর (orientation, মাপ, একক, দুই পাশ) বদলালে `version` বাড়ে। শুধু নাম বদলালে বাড়ে না।
   - `POST /card-templates/{id}/duplicate`: পুরনো "copy template", কপি নিষ্ক্রিয় খসড়া হিসেবে আসে। **৩.৪-এ সেকশনগুলোও কপি করতে হবে।**
   - ব্যবহৃত `card_type`/`authority_type` master data থেকে মোছা যায় না। পারমিশন `templates.*`, Operator পায় শুধু `templates.view`।
   - পেজ: মেনু → Cards → Card templates, আসল অনুপাতে কার্ডের আকৃতি, ফিল্টার, ডায়ালগ (CR80 বোতাম), কপি। ৬টা টেস্ট।
   - `UiInput`-এ `step` prop যোগ হয়েছে। দশমিক সংখ্যার ঘরে `step="any"` না দিলে ব্রাউজার ফর্ম জমাই দেয় না, আর কিছু জানায়ও না।
4. **৩.৪ টেমপ্লেট ডিজাইনার** (ইউজার অনুমোদিত: পুরনোটার সব থাকবে, সাথে নতুন সুবিধা)

   **পুরনো ডিজাইনার যেভাবে কাজ করে** (`../idcard/resources/js/views/backend/Template/Create.vue`, `DesignModule.vue`, `TemplateSection.vue`):
   - টেমপ্লেট তৈরির পেজেই ডিজাইনার: বাঁ দিকে সেকশনের তালিকা আর সেটিংস, ডান দিকে কার্ডের প্রিভিউ (ডিফল্ট 332×214 px), উপরে Front/Back টগল।
   - প্রতিটা সেকশন jQuery UI `draggable({containment: 'parent'})` দিয়ে কার্ডের ভেতরে টেনে বসানো যায়। ছাড়লে `left/top` সেভ হয় `section_position`-এ, আর `width/height` সেভ হয় `section_width/height`-এ (CSS স্ট্রিং, px)। সাইজ টেনে বদলানো যায় না, ঘরে লিখতে হয়।
   - সেকশনের ধরন: `fr_logo1-5`, `bg_logo1-5`, `bg_image`/`back_bg_image`, `profile`, `sign` (authority type-এর সক্রিয় সইকারী), `emp_sign`, `qr_code` (+ `qrcode_info`), `db_field` (+ `relation_name`: personalInfo / familyInfo / studentInfo / custom), `general_section`।
   - স্টাইল: label, label_color, fornt_family (Arial, Arial Narrow/Black, Berlin, GILC, Impact, ParashMJ, SutonnyMJ, sans-serif, monospace, cursive, roboto, SolaimanLipi, AdorshoLipi, Nikosh), fornt_size, fornt_color, bg_color, font_weight, font_style, text_decoration, text_align।
   - পুরনো সিস্টেম চালাতে: `cd ../idcard && php7.4 artisan serve --port=8000` (nginx-এর `php74.local` 500 দেয়, কারণ `storage`-এর মালিক `alif` আর FPM চলে `www-data` হিসেবে)। লগইন লাগে, seeder-এ কোনো অ্যাডমিন নেই।

   - ✅ **৩.৪ক সম্পন্ন:** `config/card_template_elements.php` (উপাদানের ধরন, পুরনো → নতুন ম্যাপ, ঘরের তালিকা: cardholder / family / card, ফন্ট), `card_template_elements` টেবিল (side front/back, type, field, label, content, image, **x/y/width/height mm-এ**, z_index, style JSON), `CardTemplateElement` model, `CardTemplate::elements()`। টেমপ্লেট কপি করলে উপাদানও কপি হয়।
   - ✅ **৩.৪খ সম্পন্ন — উপাদানের API** (`CardTemplateDesignService`, `CardTemplateImageService`, `CardTemplateElementRepository`, ৮টা টেস্ট):
     - নতুন কলাম `qr_lines` (পুরনো `qrcode_info`): প্রতিটা লাইন `{field, label?}` (ছাপা হবে `label:মান`) বা `{text}`।
     - `GET /card-templates/{id}/elements`: উপাদান (back আগে, তারপর front; প্রতি পাশে নিচের লেয়ার আগে), `meta.version`, আর `meta.catalog` = `types`, `fields` (cardholder / card; family_member শুধু পরিবারের টেমপ্লেটে), `custom_fields` (`custom.<key>` + লেবেল), `fonts`, `size_mm`।
     - `PUT /card-templates/{id}/elements` `{elements: [...]}`: পুরো লেআউট ট্রানজ্যাকশনে replace। **প্রতি পাশে অ্যারের ক্রমই লেয়ারের ক্রম** (`z_index` সার্ভার বসায়), তাই প্রতিবার সেভে উপাদানের `id` বদলায়, ফ্রন্টএন্ড নিজের key রাখবে। যাচাই: ধরন, ঘর (audience আর কাস্টম ফিল্ড অনুযায়ী), টেক্সটে লেখা, QR লাইন, কার্ডের সীমা (px টেমপ্লেট 96 dpi ধরে mm-এ মাপা, `CardTemplate::sizeInMm()`), প্রতি পাশে একটা background, এক পাশের টেমপ্লেটে back নয়, স্টাইলের key আর মান। ভুল আসে `elements.3.field` key-তে। নকশা সত্যিই বদলালে তবেই `version++` (স্টাইলের "1"/true, "9"/9 একই ধরা হয়)।
     - স্টাইলের key: `font_family`, `font_size` (pt), `color`, `label_color`, `background`, `bold`, `italic`, `underline`, `align`।
     - ছবি: `POST /card-templates/{id}/images` (jpg/png/webp, ৫ MB) → `{image, image_url}`। ছবি `ImageSanitizer` দিয়ে পরিষ্কার হয়ে `tenants/<t>/card-templates/<template>/`-এ যায়। লেআউটে logo/background উপাদানে `image` = সেই নাম; অন্য টেমপ্লেটের বা বানানো নাম চলে না। সেভে বাদ পড়া ছবি মুছে যায়; আপলোড করে সেভ না করা ছবি টেমপ্লেট মুছলে মোছে। লিংক `/card-template-images/{template}/{image}` (relative signed, ১৫ মিনিট)।
     - টেমপ্লেট কপি করলে ছবিও আলাদা ফাইল হিসেবে কপি হয়; টেমপ্লেট মুছলে তার ছবির ফোল্ডারও মোছে।
     - পুরনো ডিজাইনারে profile/sign/emp_sign-এর জন্যও ছবি আপলোড ছিল, সেটা শুধু ডিজাইনারে দেখানোর নমুনা। নতুনটায় নমুনা ছবি ফ্রন্টএন্ড নিজে দেখাবে, ছাপার সময় আসল ছবি আসবে কার্ডধারী আর সইকারী থেকে।
   - ✅ **৩.৪গ সম্পন্ন — ডিজাইনার পেজ** (`pages/card-templates/CardTemplateDesignerPage.vue` + `designer/`: `useDesigner.ts` স্টেট আর Undo, `DesignerCanvas.vue`, `DesignerElement.vue`, `ElementPalette.vue`, `ElementSettings.vue`, `FieldSelect.vue`, `PreviewPicker.vue`, `sample.ts`):
     - রাউট `/card-templates/:id/design` (`templates.view`), টেমপ্লেট তালিকার প্রতিটা কার্ডে "Design" বোতাম।
     - আসল অনুপাতে ক্যানভাস (px/mm জুম, শুরুতে কলামের মাপে বসে, 100% = আসল মাপ), Front/Back (এক পাশের টেমপ্লেটে পেছনে পুরনো উপাদান থাকলে Back দেখায়, যাতে মোছা যায়)।
     - **পুরনো ডিজাইনারের সব:** সব ধরনের উপাদান, যেকোনো সংখ্যক লোগো, প্রতি পাশে একটা ব্যাকগ্রাউন্ড (পুরো পাশ cover), ডেটার ঘর (লেবেল `:` মান, লেবেলের আলাদা রঙ), লেখা (লেবেলসহ), QR-এর একাধিক লাইন (ঘর + ঐচ্ছিক লেবেল, বা কাস্টম লেখা), ফন্ট, সাইজ, মানের/লেবেলের/পটভূমির রঙ, বোল্ড/ইটালিক/আন্ডারলাইন, alignment, টেনে সরানো (কার্ডের বাইরে নয়), প্রস্থ/উচ্চতা লেখা।
     - **নতুন:** ৮ দিকের হ্যান্ডেলে সাইজ বদল, mm-এ x/y/প্রস্থ/উচ্চতা, তীর ১ mm (Shift ০.১ mm), Delete, Ctrl+D কপি, লেয়ার উপরে/নিচে/সবার উপরে/সবার নিচে, Undo/Redo (Ctrl+Z, Ctrl+Shift+Z/Ctrl+Y; থেমে যাওয়া বদল এক ধাপ, সর্বোচ্চ ১০০), গ্রিডে স্ন্যাপ (০.৫/১/২/৫ mm, গ্রিড দেখায়), Ctrl+S, লেয়ারের তালিকা, না-সেভ বদলে পেজ ছাড়ার সতর্কতা, সার্ভারের ভুল উপাদানে লাল আর লেয়ারে ⚠️, নমুনা ডেটা।
     - **আসল কার্ডধারী দিয়ে প্রিভিউ** (`cardholders.view` লাগে): `GET /card-templates/{id}/preview?cardholder=&family_member=`। মান বানায় **`Cards\CardDataResolver`**: প্রতিষ্ঠানের ভাষায় (`tenant.locale`) enum লেবেল (`lang/*/cards.php` → `values`), তারিখ `d/m/Y`, master data-র নাম, কাস্টম ফিল্ড (checkbox হ্যাঁ/না), আজকের দিনে কার্যকর সক্রিয় সইকারী, ছবি/সইয়ের signed link। ফাঁকা মান বাদ যায়, তাই প্রিভিউতে ফাঁকা দেখায় (ছাপাতেও তাই হবে)। `card.*` ঘর ইস্যুর আগে জানা নেই, তাই নমুনা থাকে। **৩.৬-এর প্রিন্ট/PDF-এ এই resolver-ই ব্যবহার করতে হবে**, যাতে প্রিভিউ আর ছাপা কার্ড এক থাকে।
     - Operator (শুধু `templates.view`): কেবল দেখা, প্যালেট/হ্যান্ডেল/সেভ নেই।
     - ✅ পরিবারের কার্ডে এখন সদস্যের নিজের ছবি ও সই (৩.৫ ধাপ ৮); না থাকলে ফাঁকা, কার্ডধারীর ছবি নয়।
     - ব্রাউজারে যাচাই (headless Chrome): টেনে সরানো, কিনারায় আটকানো, হ্যান্ডেল, সেভ-রিলোড, সার্ভারের ভুল, Undo/Redo, স্ন্যাপ, ছবি আপলোড, QR লাইন, en/bn, লাইট/ডার্ক, ফোনের প্রস্থ, Operator। ডেভেলপমেন্ট ডাটাবেসে যাচাইয়ের জন্য "QA designer check" আর "QA family check" টেমপ্লেট বানানো হয়েছে (বানানো ডেটা, মুছে ফেলা যায়)।
   - ✅ **ক্যানভাসের বাড়তি সুবিধা** (ইউজারের অনুরোধে, পুরনো ডিজাইনারের "ফাঁকা General Section + bg_color" দিয়ে রঙিন ব্লক বানানোর বদলে):
     - নতুন উপাদান `shape` (আয়তক্ষেত্র / গোল-উপবৃত্ত); প্যালেটে "আকৃতি", "রেখা" (০.৩ mm) আর "রঙিন ব্যান্ড" (পুরো প্রস্থ)। সর্বনিম্ন মাপ এখন ০.২ mm (পাতলা রেখার জন্য)।
     - সব উপাদানের "ভরাট ও বর্ডার": ভরাট রঙ, দুই রঙের গ্রেডিয়েন্ট (৪ দিক), বর্ডারের রঙ ও পুরুত্ব, গোল কোণা (ছবিতে "গোল" বোতাম), স্বচ্ছতা।
     - লেখা: উপরে/মাঝে/নিচে বসানো, লাইনের উচ্চতা, অক্ষরের ফাঁক, ভেতরের ফাঁক, বড় হাতের অক্ষর। ছবি: cover/contain।
     - কার্ডের পাশ: ছবি ছাড়াই শুধু রঙ বা গ্রেডিয়েন্ট, বা রঙের উপর ছবি।
     - স্টাইলের key-র তালিকা `config/card_template_elements.php` → `style`; CSS-এ রূপান্তর এক জায়গায়: `designer/styles.ts` (৩.৬-এর প্রিন্টেও এটাই ব্যবহার করুন)।
     - ক্যানভাস দুই স্তরে: নিচে যা ছাপা হবে (কার্ডের গোল কোণায় কাটা), উপরে outline আর হ্যান্ডেল (কাটা পড়ে না, সবসময় দেখা যায়)। `bare` prop দিলে শুধু ছাপার অংশ।
   - ✅ **৩.৪ঘ ফন্ট সম্পন্ন:** OFL-1.1 `@fontsource` প্যাকেজ (ইউজার অনুমোদিত): Inter, Roboto, Noto Sans/Serif Bengali, Hind Siliguri, Tiro Bangla, Baloo Da 2, Anek Bangla। বিল্ডে ঢোকে, কোনো CDN নেই (ব্রাউজারে যাচাই: বাইরের request শূন্য)। UI-র ফন্ট `lib/fonts.ts`, কার্ডের ফন্ট `designer/cardFonts.ts`। config-এ `bundled_fonts` আর `system_fonts` (SolaimanLipi, Nikosh, AdorshoLipi, SutonnyMJ, Arial…, লাইসেন্স অস্পষ্ট বা বাণিজ্যিক, তাই বান্ডল নয়; শুধু ইনস্টল থাকলে দেখায়, প্যানেলে সতর্কতা)। SutonnyMJ ANSI (বিজয়) ফন্ট, ইউনিকোড বাংলা এতে ভুল দেখায়, প্যানেলে আলাদা সতর্কতা আছে।
   - ✅ **৩.৪ঙ থাম্বনেইল সম্পন্ন:** `GET /card-templates?include=elements`; তালিকায় `CardThumbnail.vue` (সামনের পাশ, নমুনা ডেটা, `DesignerCanvas bare`)। `CardTemplate::elements()` এখন `chaperone('template')`, তাই ছবির লিংক বানাতে বাড়তি query হয় না।
   - ✅ **পুরনো সিস্টেমের সাথে শেষ মিলিয়ে দেখা** (ডিজাইনার ছাড়াও তালিকা, View, Template Setup, Copy modal আর প্রিন্টের `PersonalInfo/TemplateSectionView.vue`, `FamilyCardTemplateSections.vue`)। বাদ পড়া চারটা জিনিস যোগ হয়েছে:
     - **বিকল্প ছবি:** profile/sign/emp_sign-এ টেমপ্লেটে আপলোড করা ছবি পুরনো সিস্টেমে প্রিন্টে বিকল্প হিসেবে ছাপা হতো। এখন photo, signatory_signature আর cardholder_signature-এ ঐচ্ছিক `image`। **ছাপার নিয়ম: আসল ছবি/সই থাকলে সেটা, না থাকলে উপাদানের ছবি** (`DesignerElement`: `imageUrl ?? element.image_url`)।
     - **পরিবারের কার্ডে কার্ডধারীর ছবি ও সই** (পুরনো আচরণ; `CardDataResolver`)।
     - **ডিফল্ট কার্ড মাপ** (পুরনো Template Setup): `tenants.settings.default_card_size` (mm, আড়াআড়ি; `Tenant::defaultCardSize()`), সেটিংস পেজে ঘর। নতুন টেমপ্লেটে মাপ না দিলে এটাই, খাড়ায় ঘুরিয়ে। টেমপ্লেট ডায়ালগে orientation বদলালে প্রস্থ-উচ্চতা অদলবদল (পুরনো `card_w_h`), "ডিফল্ট মাপ" আর "CR80" বোতাম।
     - **কপি ডায়ালগ:** নাম আর কোড (পুরনো Copy Template; `POST /card-templates/{id}/duplicate` এখন ঐচ্ছিক `code` নেয়)।
     - পুরনো তারিখের ফরম্যাট `DD/MM/YYYY` = `CardDataResolver::DATE_FORMAT` (`d/m/Y`), মিলে যায়। পরিবারের কার্ডে পুরনো সিস্টেম লিঙ্গ সম্পর্ক থেকে আন্দাজ করত (Father → Male); এখন সদস্যের নিজের লিঙ্গ ঘর আছে।
   - ✅ **৩.৪চ — ডিজাইনারের নতুন সুবিধা:**
     - লক (সরানো/বদলানো/মোছা যায় না; ছাপায় প্রভাব নেই, তাই version বাড়ে না), লুকানো (`hidden`: ডিজাইনারে আবছা, **থাম্বনেইল আর ছাপায় বাদ** — ৩.৬-এ `DesignerCanvas bare` এটা নিজে করে), অন্য পাশে কপি, ড্যাশ/ডট বর্ডার, নিরাপদ সীমা (৩ mm) আর মাঝ-রেখার টগল।
     - ঘোরানো ০/৯০/১৮০/২৭০°: বাক্স ঘোরে না, ভেতরের অংশ ঘোরে (`styles.ts` → `rotationCss`), তাই সার্ভারের সীমা-যাচাই আগের মতোই।
     - একাধিক বাছাই (Shift/Ctrl-ক্লিক, ফাঁকা জায়গা থেকে বাক্স, Ctrl+A), একসাথে টানা/তীর/Delete/Ctrl+D (দল কার্ডের ভেতরে আটকায়), সারিবদ্ধ (একটা বাছাইয়ে কার্ডের সাপেক্ষে) আর সমান দূরত্ব। একাধিক বাছাইয়ের মধ্যে না টেনে ক্লিক করলে বাছাই সেই একটায় নামে।
     - স্মার্ট গাইড: টানা আর সাইজ বদলের সময় অন্য উপাদান ও কার্ডের কিনারা/মাঝে ৬ px-এর মধ্যে বসে যায়, গোলাপি দাগ দেখায়; Alt চাপলে বন্ধ। গ্রিডের আগে গাইড।
     - লেখা: "ঘরে আঁটাও" (`auto_fit`, ফন্ট ৪ pt পর্যন্ত ছোট হয়, মাপা হয় `DesignerElement`-এ binary search দিয়ে, ফন্ট লোড হলে আবার) আর "এক লাইনে" (`single_line`)। **৩.৬-এর প্রিন্টও `DesignerElement` দিয়ে আঁকতে হবে**, নইলে ছোট হওয়া মিলবে না।
     - লেআউট ফাইল: export (JSON, `idcard-pro.card-layout` v1, লোগো/ব্যাকগ্রাউন্ড/বিকল্প ছবি data URL হিসেবে ভেতরে) আর import (ছবি নতুন করে আপলোড; মাপ আলাদা হলে এক অনুপাতে ছোট/বড় করে মাঝে বসানো, ফন্ট-বর্ডারও; ব্যাকগ্রাউন্ড পুরো কার্ড; এক পাশের টেমপ্লেটে পেছন বাদ; সেভ না করা পর্যন্ত Undo চলে)। `designer/transfer.ts`।
     - তিনটা তৈরি নকশা (হেডার ব্যান্ড, পাশের ফিতা, খাড়া কার্ড; আসল ডেটার ঘরসহ): `designer/starters.ts`, `StarterPicker.vue`।
     - "মাপে বসানো" জুম এখন জানালার উচ্চতাও দেখে (খাড়া কার্ড পর্দার বাইরে যেত)।
   - **৩.৪ছ** (ইউজার চারটাই বেছেছেন, ৫ অক্টোবর):
     - ✅ **বারকোড উপাদান** (commit `cba1521`, `47692ff`): type `barcode` (`needs_field`), স্টাইল `barcode_format` (code128 ডিফল্ট, code39, ean13, ean8, upc, itf, codabar; config `barcode_formats`) আর `barcode_text` (নিচে মান)। এনকোড করে **JsBarcode** (MIT, ইউজার অনুমোদিত, কোনো network নেই) — `designer/barcode.ts` → `encodeBarcode()` বারের তালিকা দেয়, `DesignerElement` SVG `rect` আঁকে (বাক্সে টেনে বসে, দুই পাশে ১০ module quiet zone)। এনকোড না হলে লাল বক্স "দেখানো যায় না" (ভুল বারকোড কখনো নয়)। প্যানেলে নমুনা মানে সবচেয়ে সরু বারের মাপ, ০.২ mm-এর নিচে সতর্কতা। পুরনো `BarcodeTrait` (Code128, লেখা ছাড়া) ছিল কিন্তু কোথাও ডাকা হতো না।
     - ✅ **প্রতিষ্ঠানের ফন্ট** (commit `c1fed92`, `19b4eac`, `a91e9a6`, `e1563e8`): `tenant_fonts` (family, weight 100–900, style normal/italic, format, private ফাইল; unique per face)। `FontInspector` ফাইলের বাইট দেখে TTF/OTF/WOFF/WOFF2 চেনে (`.ttc` বাতিল), table directory যাচাই করে, `name` টেবিল থেকে ফ্যামিলির নাম পড়ে (WOFF2 বাদে)। লাইসেন্স নিশ্চিত করা (`licence_confirmed`) বাধ্যতামূলক; বিল্ট-ইন/জেনেরিক নাম নেওয়া যায় না। টেমপ্লেটে ব্যবহৃত ফ্যামিলির শেষ ফাইল মোছা যায় না। লিংক signed, এক দিন স্থির (cache হয়)। পারমিশন `fonts.view|create|delete`; তালিকা `templates.view` থাকলেও পাওয়া যায় (কার্ড আঁকতে লাগে)। পেজ: সেটআপ → ফন্ট। ডিজাইনারে "প্রতিষ্ঠানের ফন্ট" দল, `lib/tenantFonts.ts` FontFace দিয়ে লোড করে (ডিজাইনার, থাম্বনেইল, Operator), লোডের পর auto-fit আবার মাপে। অন্য প্রতিষ্ঠানের লেআউট import-এ অজানা ফন্ট ডিফল্টে বদলায়।
     - ✅ **ছবির লাইব্রেরি** (commit `1fa7382`, `a462bac`, `65380f2`, `5122151`; ইউজারের সিদ্ধান্ত: **যুক্ত থাকবে, কপি নয়**): `tenant_images` (ফাইল কখনো বদলায় না), উপাদানে `library_image_id` (FK restrict)। API-তে উপাদানের `library_image` = public id (`image`-এর সাথে একসাথে নয়)। ব্যবহৃত ছবি মোছা যায় না; "সব টেমপ্লেটে বদলাও" (`POST /library-images/{id}/replace {with}`, `images.update` + `templates.update`) সব উপাদান নতুন ছবিতে নেয় আর প্রতিটা টেমপ্লেটের `version++`। টেমপ্লেট কপি করলে লিংকও কপি হয়। পারমিশন `images.*`; তালিকা `templates.view`-এও। পেজ: সেটআপ → ছবির লাইব্রেরি (একসাথে কয়েকটা আপলোড, নাম বদল, ব্যবহার সংখ্যা)। ডিজাইনারে "Library" বোতাম → `LibraryPicker.vue` (সেখান থেকেই লাইব্রেরিতে আপলোড)। লেআউট export-এ `library_image` থাকে; একই প্রতিষ্ঠানে import করলে আবার যুক্ত হয়, অন্য জায়গায় ছবি আপলোড হয়।
     - ➡️ **বাকি: ছায়া, যেকোনো কোণে ঘোরানো, একাধিক উপাদানকে স্থায়ী গ্রুপ।** এখানে থেকে শুরু। ছোট ভাগে করার প্রস্তাব: (ক) ছায়া — ✅ ব্যাকএন্ড (commit `acef175`: config `style.shadow` = `shadow_color` (থাকলে ছায়া চালু), `shadow_x/y` −১০..১০ mm, `shadow_blur` ০..১০ mm, `shadow_opacity` ০.০৫..১; ১৬৬টা টেস্ট)। ✅ ফ্রন্টএন্ড (commit `ad0bdbb`): `styles.ts` → `shadowCss()`, `boxCss`-এর `filter: drop-shadow(...)` উপাদানের বাইরের বাক্সে — যা আঁকা আছে তার আকৃতি ধরে (ভরাট ছাড়া লেখা → অক্ষর, স্বচ্ছ PNG → লোগোর আকৃতি, উপবৃত্ত → গোল), তাই আলাদা text/box নিয়ম নেই; ডিজাইনার, থাম্বনেইল আর প্রিন্ট একই। প্যানেলে "ছায়া" অংশ (যোগ করলে সব মান স্পষ্ট বসে: কালো, ০.৫/০.৫ mm, ঝাপসা ০.৮ mm, ৩৫%; ০ মান ০-ই থাকে)। background-এ ছায়া নেই। ব্রাউজারে যাচাই করা। (খ) যেকোনো কোণ — ইউজারের সিদ্ধান্ত: **দুটোই** (ডিফল্টে হেলানো আকৃতিও কার্ডের ভেতরে; `bleed` চালু করলে শুধু না-হেলানো বাক্স ভেতরে, বাকিটা কিনারায় কাটা)। পুরনো `rotation` (০/৯০/১৮০/২৭০, ভেতরের অংশ ঘোরে) যেমন ছিল তেমন, যাতে আগের লেআউট না বদলায়; নতুন `angle` (−১৮০..১৮০°, ০.১° পর্যন্ত, ০ হলে বাদ) = পুরো উপাদান মাঝখান ধরে হেলে (`rotation`-এর পরে)। ✅ ব্যাকএন্ড (commit `dc1a452`): `CardTemplateDesignService::tiltedInside()` হেলানো বাক্সের bounding box দিয়ে যাচাই, ভুল আসে `elements.N.style.angle`-এ; ১৬৭টা টেস্ট। ✅ ফ্রন্টএন্ড (commit `4c6029a`): `DesignerCanvas` → `box()`-এ `transform: rotate(angle)` (ছাপার অংশ আর outline/হ্যান্ডেল একসাথে; থাম্বনেইল/প্রিন্টও একই `box()`)। নতুন **ঘোরানোর হ্যান্ডেল** (উপরের গোল বিন্দু, টেনে হেলানো, Shift = ১৫° ধাপ)। হেলানো উপাদানের সাইজ বদল নিজের অক্ষে, উল্টো কিনারা পর্দায় স্থির (`resizeTilted`)। `designer/geometry.ts`: `tiltedBounds` (সার্ভারের একই সূত্র), `coveredArea` (বাক্স ∪ হেলানো অংশ — সার্ভার দুটোই যাচাই করে), `fitTilted`; `useDesigner.clampRect(rect, style)` আর `setAngle()` এগুলো মানে, দল সরানোও। প্যানেলে "হেলানো (°)" আর "কার্ডের কিনারায় কাটা চলবে" (টিক তুললে ভেতরে ফেরে)। ব্রাউজারে যাচাই। (গ) গ্রুপ — ✅ (commit `8905900` ব্যাকএন্ড, `8b3a892` ফ্রন্টএন্ড): স্টাইলের `group` (state key, `^[a-z0-9]{1,12}$`), একই পাশে একই id = এক দল; সার্ভার একার দল বাদ দেয় (`dropSingleGroups`); শুধু সম্পাদনার জিনিস, তাই `locked`-এর মতো version বাড়ায় না। ডিজাইনার: Ctrl+G / Ctrl+Shift+G আর প্যানেলে "গ্রুপ করুন / গ্রুপ ভাঙুন"; ক্যানভাসে ক্লিক বা বাক্স-বাছাই পুরো দল নেয় (`useDesigner.groupOf`), টানলে দল একসাথে সরে; একটা সদস্য আলাদা বদলাতে দুবার ক্লিক বা লেয়ারের তালিকা (`select(key, additive, single)`); Ctrl+D-তে কপিরা নতুন দল (`duplicateMany`), অন্য পাশে কপি দল ছাড়া; লেয়ারে দলের চিহ্ন। ব্রাউজারে যাচাই। **৩.৪ছ সম্পূর্ণ।**
     - ✅ ক্যানভাস আর লাফায় না (commit `3e98a1c`): বড় পর্দায় ক্যানভাসের অংশ নির্দিষ্ট উচ্চতার আর sticky, তাই প্যানেলের উচ্চতা বদলালে কার্ড সরে না আর লম্বা প্যানেল স্ক্রল করলেও কার্ড দেখা যায়। ফোনে আগের মতো।
     - **ব্রাউজার-স্ক্রিপ্টের শিক্ষা:** না-সেভ বদল থাকলে পেজ ছাড়ার সতর্কতা নেভিগেশন আটকে দেয়; ক্লিকের আগে উপাদানের অবস্থান নতুন করে মাপতে হয়।
5. **৩.৫ কার্ড ইস্যু** — ✅ সম্পন্ন (৫ অক্টোবর)। পুরনো সিস্টেম দেখা হয়েছে: `card_information` (প্রতি জনে একটা সারি, ওভাররাইট হতো; card_type = টেমপ্লেট, card_id_number, rf_card_no, ইস্যু/মেয়াদ), `card_print_history` (user, `card_get` = new/promotion/lost, notes, template, number; শেষ ৫টা দেখাত), একবার প্রিন্টের পর "New" লুকাত, promotion/lost-এ নোট + ডকুমেন্টের ছবি (বাগ: ছবিটা কার্ডধারীর `finger` ঘরে বসত), টেমপ্লেট বদলালে নতুন নম্বর আর একই টেমপ্লেটে আবার প্রিন্টে একই নম্বর, পরিবারের কার্ডের কোনো রেকর্ড/ইতিহাস ছিল না, রিপোর্ট: প্রিন্ট ইতিহাস আর মেয়াদোত্তীর্ণ কার্ড।
   - **ইউজারের সিদ্ধান্ত:** আবার ইস্যুতে নম্বর = সেটআপে সেটিং (`tenants.settings.card_number_on_reissue`: `keep` ডিফল্ট = পুরনো আচরণ, অথবা `new`)।
   - ✅ **ধাপ ১** (commit `04e05c6`): `cards` টেবিল (holder = cardholder বা family member, template + version, `layout` snapshot JSON, number, rf_card_no, reason, notes, document/photo/signature file id, signatory, issue/expiry, status active/replaced/revoked, replaced_by, issued_by)। নম্বর unique নয় (keep নীতি), তার বদলে `active_number_key` আর `active_holder_key` (শুধু সক্রিয় কার্ডে বসে) — DB-তে প্রতি নম্বরে আর প্রতি জনে একটাই সক্রিয় কার্ড। `config/cards.php` (কারণ: new, renewal, promotion, lost, damaged, correction; কোনটায় নোট/ডকুমেন্ট), `App\Enums\CardStatus`, পারমিশন `cards.view|issue|update|revoke` (Operator: view+issue, Viewer: view)।
   - ✅ **ধাপ ২** (commit `72f38fd`): `CardIssueService::issue()` (`Data\Cards\IssueCardData`, `CardRepository`): টেমপ্লেট সক্রিয় আর audience মিল (পরিবারের টেমপ্লেট ↔ পরিবারের সদস্য), নোট/ডকুমেন্ট কারণ অনুযায়ী, ডকুমেন্ট আপলোড হলে কার্ডধারীর ডকুমেন্টে যায়, RF নম্বর অন্য জনের সক্রিয় কার্ডে থাকলে আটকায়, মেয়াদ = টেমপ্লেটের `validity_months` (না থাকলে খালি)। ট্রানজ্যাকশনে: আগের সক্রিয় কার্ড lock → replaced, নম্বর (keep হলে একই holder-এর একই টেমপ্লেট-কোডের শেষ কার্ডের নম্বর, নইলে `CardNumberService::next`), snapshot (`CardTemplateDesignService::snapshot()`), চালু ছবি/সই আর সেদিনের সইকারীর id। API: `GET|POST /cardholders/{id}/cards`, `GET /cardholders/{id}/cards/next-number?template=&family_member=`। ১৭৬টা টেস্ট।
   - ✅ **ধাপ ৩** (commit `1fcc8c2`): সিদ্ধান্ত — কার্ড দলিল, তাই **ছাপার লেখাও ইস্যুর সময় স্থির** (`cards.values` = `CardDataResolver::fields()`); তথ্য বদলালে "সংশোধন" কারণে নতুন কার্ড। `CardDataResolver::forCard()` = স্থির লেখা + `card.number/issue_date/expiry_date` + কার্ডের নিজের ছবি/সই/সইকারী। `GET /cards/{id}` → `data` (CardResource) + `meta.layout` (`CardLayoutPresenter`, ডিজাইনারের উপাদানের আকারে, ছবির signed লিংকসহ) + `meta.values`। টেমপ্লেট সেভে কোনো ইস্যু হওয়া কার্ডের snapshot-এ থাকা ছবি মোছে না (`CardRepository::snapshotImagePaths`)। টেস্টের সাহায্যকারী `cardTemplate()` এখন `tests/Pest.php`-এ। ১৭৯টা টেস্ট।
   - ✅ **ধাপ ৪** (commit `ee82cd0`): `CardService` — `PATCH /cards/{id}` (RF নম্বর, নোট, মেয়াদ; `cards.update`), `POST /cards/{id}/revoke` {note} (শুধু সক্রিয়; `cards.revoke`; বাতিলের পর নতুন ইস্যু করা যায়)। মোছা আটকানো (বাংলা/ইংরেজি `cards.in_use.*`): কার্ডধারী, পরিবারের সদস্য, টেমপ্লেট (নিষ্ক্রিয় করতে বলে), সইকারী, ছাপা ছবি/সই ফাইল, লাইব্রেরির ছবি, ফন্ট। লাইব্রেরির ছবি আর ফন্টের ব্যবহার JSON খুঁজে নয় — ইস্যুর সময় `card_assets` (kind + reference; নতুন ধরন যোগ করা যায়) টেবিলে লেখা হয়।
   - ✅ **ধাপ ৫** (commit `d350adf`): `GET /cards` (`Data\Cards\CardFilter`, `ListCardsRequest`) — পুরনো "Card Print History" আর "Expired Card" রিপোর্টের জায়গায়: `filter[search]` (নম্বর, RF, নাম, কর্মী নম্বর, পরিবারের সদস্যের নাম), `status`, `reason`, `template`, `holder` (cardholder/family), `issued_from/to`, `validity` (expired / expiring + `expiring_days` / valid — শুধু সক্রিয় কার্ড), `department`, `unit`; sort `issue_date|expiry_date|number|created_at`, `per_page`। `GET /cards/summary?expiring_days=` → সক্রিয়, মেয়াদোত্তীর্ণ, শীঘ্রই শেষ, বাতিলের সংখ্যা (ড্যাশবোর্ড আর ৫-এর মেয়াদ-রিমাইন্ডারেও কাজে লাগবে)। টেস্টে রোলের পারমিশন বদলাতে `TenantContext::run` + `Role::findByName(..., PermissionRegistry::GUARD)`।
   - ✅ **ধাপ ৬** (commit `9f36133`): কার্ডধারীর পাতায় "কার্ড" ট্যাব (`cards.view`; `?tab=cards` দিয়ে সরাসরি খোলে) — `CardholderCards.vue`: বর্তমান কার্ড ছাপার মতো আঁকা, পুরো ইতিহাস (নম্বর, ধারক, টেমপ্লেট, কারণ+নোট, মেয়াদকাল, অবস্থা — মেয়াদ পেরোলে "মেয়াদোত্তীর্ণ", ইস্যুকারী)। `pages/cards/`: `IssueCardDialog.vue` (কার জন্য, টেমপ্লেট audience অনুযায়ী, কারণ, RF, তারিখ — মেয়াদ টেমপ্লেট থেকে, নোট/ডকুমেন্ট কারণ অনুযায়ী; ডানে আসল তথ্য দিয়ে **লাইভ প্রিভিউ** আর পরের নম্বর "আগের নম্বর/নতুন নম্বর"), `CardDetailDialog.vue` (সামনে-পেছনে, তথ্য, ডকুমেন্ট, হালনাগাদ, কারণসহ বাতিল), `CardRender.vue` (**কার্ড আঁকার একটাই কম্পোনেন্ট** — `DesignerCanvas bare`; ৩.৬-এর প্রিন্টেও এটাই), `cards.ts`। কারণের তালিকা সার্ভার থেকে: `GET /cards/options` (config থেকে, তাই নতুন কারণে ফ্রন্টএন্ড বদলাতে হয় না)। `lib/tenantFonts.ts` → `ensureTenantFonts()` (পেজে একবার)। ব্রাউজারে যাচাই (ইস্যু, একই নম্বরে আবার ইস্যু, বাতিল, bn/ডার্ক, ফোন); যাচাইয়ের কার্ড, সিকোয়েন্স আর লগ পরে মুছে ফেলা হয়েছে।
   - ✅ **ধাপ ৭** (commit `c1fdaeb`): মেনু → কার্ড → "কার্ড ইস্যু" (`/cards`, `cards.view`; "শীঘ্রই আসছে" থেকে সরানো) — `CardsPage.vue`: উপরে ৪টা টাইল (সক্রিয়, মেয়াদোত্তীর্ণ-কিন্তু-সক্রিয়, ৩০ দিনে শেষ, বাতিল; চাপলে তালিকা ফিল্টার হয়, আবার চাপলে সব), খোঁজা, ফিল্টার (অবস্থা, মেয়াদ + কত দিন, কারণ, টেমপ্লেট, ধারক, বিভাগ/ইউনিট, ইস্যুর তারিখ), sort, পাতা, ধারকের নামে চাপলে তাঁর "কার্ড" ট্যাব, সারিতে চাপলে কার্ডের বিস্তারিত। ফিল্টার URL-এ থাকে (`useListQuery`)। সেটিংস → কার্ড নম্বর → "একই ব্যক্তির নতুন কার্ডে নম্বর" (রাখো/নতুন)। ব্রাউজারে যাচাই করা, পরীক্ষার কার্ড মুছে ফেলা হয়েছে।
   - ✅ **ধাপ ৮** (commit `e543af9`, `38bf956`): **পরিবারের সদস্যের নিজের ছবি ও সই।** ইউজারের সিদ্ধান্ত (৫ অক্টোবর): **সদস্যের ছবি না থাকলে পরিবারের কার্ডে কোনো ছবি নয়** (কার্ডধারীর ছবি আর ছাপা হবে না — পুরনো আচরণ বদলাচ্ছে; উপাদানের বিকল্প ছবি থাকলে সেটা, নইলে ফাঁকা; ইস্যুর প্রিভিউতে "ছবি নেই" সতর্কতা)। সইয়ের ক্ষেত্রেও একই নিয়ম। পরিকল্পনা (ছোট ভাগে):
     - ✅ (৮ক ব্যাকএন্ড, সম্পন্ন) `cardholder_files.family_member_id` (cascade; rollback-এ আগে FK, পরে index)। `config/cardholder_files.php`-এ প্রতি ধরনে `family` (photo, signature = true; document = false — দরকারে শুধু config বদলালেই চলবে)। "একটাই চালু ফাইল" হিসাব প্রতি জনে (`CardholderFileRepository::ownedBy`): কার্ডধারীর নিজের ফাইল = `family_member_id` null, তাই কার্ডধারীর ছবি/ফাইল তালিকায় সদস্যের ফাইল মেশে না। API: `POST /cardholders/{id}/files` + ঐচ্ছিক `family_member`, `GET .../files?family_member=` (অন্য কার্ডধারীর সদস্য বা document → 422); ফাইল জমা থাকে `.../cardholders/{C}/family/{M}/{collection}/`। `FamilyMember::photo()/signature()/files()`, `FamilyMemberResource`-এ `photo`/`signature` (`{id,url,width,height}`)। `CardDataResolver::holderImages()` — পরিবারের কার্ডে শুধু সদস্যের ছবি/সই (না থাকলে null → উপাদানের বিকল্প ছবি বা ফাঁকা); প্রিভিউ আর `CardIssueService` দুটোই এটা ব্যবহার করে। সদস্য মুছলে তাঁর জমা ফাইলও মোছে। ১৯০টা টেস্ট। মূল পরিকল্পনা ছিল: `cardholder_files`-এ nullable `family_member_id` (FK cascade); চালু ফাইল/`replaced_at` হিসাব (cardholder, collection, family_member_id) ধরে (`CardholderFileRepository::markReplaced`, `CardholderFileService::upload`); `Cardholder::photo()/signature()` শুধু `family_member_id` null; `FamilyMember::photo()/signature()`; `POST /cardholders/{id}/files`-এ ঐচ্ছিক `family_member`, তালিকায় ফিল্টার; `FamilyMemberResource`-এ `photo_url`/`signature_url`; `CardDataResolver::resolve()` — সদস্য থাকলে সদস্যের ছবি/সই (না থাকলে null); `CardIssueService` — পরিবারের কার্ডে `photo_file_id`/`signature_file_id` সদস্যের ফাইল; সদস্য মুছলে তাঁর ফাইল মোছে (কার্ড থাকলে মোছা এমনিতেই আটকায়); টেস্ট।
     - ✅ (৮খ UI, সম্পন্ন) `FamilyMemberDialog.vue`-তে ছবি আর সই (`CardholderImagePicker`; সেভের পর আপলোড), পরিবারের তালিকায় ছোট ছবি (কার্ডধারীর তালিকার মতো)। আপলোডের কোড এক জায়গায়: `cardholder.ts` → `uploadHolderImages(cardholderId, images, onError, familyMemberId?)` (কার্ডধারীর ফর্মও এটা ব্যবহার করে)। `cards.ts` → `missingImages(layout, values)`: দৃশ্যমান ছবির উপাদান (photo, ধারকের সই, সইকারীর সই — `CardValues.images`-এর যেকোনো key) যার ছাপার কিছু নেই; ইস্যু ডায়ালগে সতর্কতা (`data-missing-images`), পরিবারের কার্ডে আলাদা বার্তা। **৩.৬-এর ব্যাচ প্রিন্টেও এটা ব্যবহার করা যাবে।** ব্রাউজারে যাচাই (অস্থায়ী QA টেমপ্লেট আর সদস্য, পরে মোছা)। মূল পরিকল্পনা: `FamilyMemberDialog.vue`-তে ছবি আর সই (`CardholderImagePicker` আবার ব্যবহার), পরিবারের তালিকায় ছোট ছবি, ইস্যু ডায়ালগে সদস্যের ছবি না থাকলে সতর্কতা; ব্রাউজারে যাচাই।
     - ✅ (৮গ) PROGRESS আর ROADMAP হালনাগাদ; পুরনো প্রিন্ট দেখে ৩.৬-এর পরিকল্পনা (নিচে)।
   - **ব্রাউজারে কার্ড যাচাইয়ের পর পরিষ্কার করা:** কার্ড মোছার API নেই (ইচ্ছাকৃত), তাই dev ডাটাবেসে পরীক্ষার কার্ড tinker দিয়ে মুছতে হয় — `cards.replaced_by_id` null → `card_prints` → `card_assets` → `cards` → `card_sequences` (পরীক্ষার আগে যা ছিল তা রেখে) → `activity_log` (`log_name = cards`)। শুধু "QA designer check"/"QA family check" টেমপ্লেট আর ডেমো কার্ডধারী ব্যবহার করুন; ইউজারের টেমপ্লেটে কার্ড ইস্যু করলে সেগুলো আর মোছা যাবে না।
   - মূল পরিকল্পনা (পুরনো `card_information`): কারণ (new / promotion / lost / renewal), নোট, মেয়াদ, RF card no। প্রতিবার ইস্যুতে নতুন রেকর্ড, আগের কার্ড বাতিল। **যে ছবি, সই আর টেমপ্লেট সংস্করণ ব্যবহার হলো তার id রাখতে হবে।** পরিবারের সদস্যের কার্ডও এখানে।
   - **ছাপার নিয়ম যা ডিজাইনারে আছে, সেগুলো মানতে হবে:** লুকানো উপাদান বাদ, আসল ছবি না থাকলে উপাদানের বিকল্প ছবি, auto-fit, ঘোরানো। সবচেয়ে নিরাপদ: প্রিন্টেও `DesignerCanvas bare` + `DesignerElement` ব্যবহার করা।
   - **নকশা যেন পরে না বদলায়:** ইস্যুর সময় টেমপ্লেটের লেআউটের snapshot (elements JSON + version) কার্ডের সাথে রাখতে হবে, নইলে পরে টেমপ্লেট বদলালে পুরনো কার্ড আবার ছাপলে অন্যরকম আসবে। লোগো/ব্যাকগ্রাউন্ডের ফাইলও তখন মোছা যাবে না (এখন লেআউট থেকে বাদ পড়লে মুছে যায়)।
   - `card.*` ঘর (number, issue_date, expiry_date) `CardDataResolver`-এ যোগ করতে হবে; এখন প্রিভিউতে নমুনা দেখায়।
   - ✅ পরিবারের সদস্যের নিজের ছবি ও সই রাখার ব্যবস্থা (ধাপ ৮)।
   - ✅ লাইব্রেরির ছবি আর ফন্ট ইস্যু হওয়া কার্ডে থাকলে মোছা যায় না (ধাপ ৪, `card_assets`)।
   - **বারকোড:** ইস্যুর সময় মান এনকোড হয় কিনা যাচাই (যেমন EAN-13-এ অক্ষর) — না হলে সতর্ক করা; প্রিন্টে লাল ভুল-বক্স ছাপা যাবে না (৩.৬)।
6. **৩.৬ প্রিন্ট আর ইতিহাস** — ✅ সম্পন্ন (৫–৮ অক্টোবর)। মান আসবে `CardDataResolver::forCard()` থেকে, আঁকা হবে `CardRender.vue` (`DesignerCanvas bare` + `styles.ts`) দিয়ে (mm ভিত্তিক, তাই প্রিন্টে `scale` = 96/25.4 বা PDF-এর DPI)। PDF বানাতে বাইরের কোনো সেবা নয়; ফন্ট `@fontsource` ফাইল থেকে।
   - **পুরনো সিস্টেম দেখা হয়েছে:** কার্ডধারীর পাতায় "Card Print" ফর্ম (ধরন + নোট + ডকুমেন্ট) → "Print" চাপলে `card_print_history`-তে সারি **আর সাথে সাথে** `printArea` popup-এ কার্ড (সামনে+পেছনে) ছাপা — অর্থাৎ ইস্যু আর ছাপা একসাথে। পরিবারের কার্ড: সদস্যের পাশে "Print" (সামনে, ১০ সেকেন্ড পরে পেছনে, কোনো রেকর্ড নেই)। পাতায় শেষ ৫টা প্রিন্ট ইতিহাস। **কার্ডধারীর তালিকায় "Status" কলাম: PENDING (কখনো ছাপা হয়নি) / PRINTED** — নতুন সিস্টেমে এখনো নেই, ৩.৬-এ যোগ করতে হবে। একসাথে অনেক কার্ড ছাপার (ব্যাচ) ব্যবস্থা পুরনো সিস্টেমে ছিল না (তালিকার "Print" বোতাম শুধু টেবিল ছাপে)। রিপোর্ট "Card Print History" (তারিখ-পরিসর, ঘর ধরে খোঁজা) — ধাপ ৫-এর `/cards` তালিকা এর জায়গা নিয়েছে, ছাপার তারিখ দিয়ে ফিল্টার ৩.৬-এ।
   - **নতুন সিস্টেমে ইস্যু ≠ ছাপা:** একটা ইস্যু হওয়া কার্ড একাধিকবার ছাপা হতে পারে (প্রিন্টার আটকে গেলে, ব্যাচে), প্রতিবার আলাদা লগ; নতুন নম্বর বা নতুন কার্ড হয় না। শুধু সক্রিয় কার্ড ছাপা যায়।
   - পরিকল্পনা (ছোট ভাগে, প্রতিটা আলাদা commit):
     - ✅ (ধাপ ১ ব্যাকএন্ড, commit `992beb4`) `card_prints` (public_id, card, `batch` ULID — একসাথে ছাপা কার্ডে একই, `method`, `copies`, `note`, `printed_by`, `printed_at`; নিজেই লগ, তাই activity log-এ আলাদা লেখা হয় না) + `cards.print_count` (মোট কপি) / `last_printed_at` (index `tenant_id, last_printed_at`)। `CardPrint` model + factory, `Data\Cards\PrintCardsData`, `PrintCardsRequest` (একই ক্লাস: route-এ `card` থাকলে একক, নইলে ব্যাচ `cards[]`), `CardPrintResource` (`card {id, number}`, `printed_by {id, name}`), `CardPrintController` (index/store/batch)। `CardPrintService::printCards()`: ট্রানজ্যাকশনে কার্ডগুলো `lockForUpdate` (দেওয়া ক্রমে, ডুপ্লিকেট বাদ), অজানা id → 422 `cards`, ছাপা যায় না এমন কার্ড থাকলে নম্বরসহ 422 আর কিছুই লগ হয় না, একাধিক কার্ড হলে batch id। `CardResource`-এ `print_count`, `last_printed_at`। পারমিশন `cards.print` (Administrator, Operator; Viewer শুধু ইতিহাস দেখে)। রোল পেজে "Issued cards" মডিউল আর Issue/Print/Revoke লেবেল (আগে কাঁচা key দেখাত)। ৫টা টেস্ট (`tests/Feature/Cards/CardPrintTest.php`)।
     - ✅ **(ধাপ ২ক, ৬ অক্টোবর)** প্রিন্টের পাতা `pages/cards/CardPrintPage.vue`, রাউট `cards.print` = `/cards/print?ids=a,b` (`cards.print` পারমিশন, অ্যাপের মেনু ছাড়া)। প্রতিটা কার্ড `GET /cards/{id}` (একসাথে ৬টা করে) → `CardRender` এক পাশ করে, প্রতি পাশ এক `.print-sheet` (mm-এ মাপ, `box` = লম্বা দিক × 96/25.4)। **প্রতিটা কার্ড-মাপের জন্য আলাদা নামের `@page`** (`@page card-0 { size: 85.6mm 54mm; margin: 0 }`), তাই আলাদা মাপের কার্ড একসাথে ছাপা যায়; ছাপায় ছায়া/গোল কোণা নেই, `print-color-adjust: exact`, টোস্ট লুকানো। টুলবার: পাশ (সামনে ও পেছনে / শুধু সামনে / শুধু পেছনে — duplex নেই এমন প্রিন্টারের জন্য), কপি (১–`max_copies`, পাতা সেই অনুযায়ী বাড়ে), প্রিন্ট, বন্ধ (নতুন ট্যাবে খুললে ট্যাব বন্ধ, নইলে `/cards`)। বাদ পড়া কার্ড (বাতিল/বদলে যাওয়া/মেয়াদোত্তীর্ণ/পাওয়া যায়নি) কারণসহ, `missingImages()` সতর্কতা; কোনো সতর্কতা না থাকলে লোড শেষে নিজে থেকেই প্রিন্ট (পুরনো আচরণ)। ছাপার আগে ফন্ট, `<img>` আর CSS পটভূমির ছবি লোড হওয়া পর্যন্ত অপেক্ষা। ছাপার পরে ডায়ালগ: নোটসহ "হ্যাঁ, লিখে রাখো" (একটা → `POST /cards/{id}/prints`, একাধিক → `POST /cards/prints`, `method: browser`), "আবার প্রিন্ট", "প্রিন্ট হয়নি" (লগ নয়); "শুধু সামনে" ছাপলে আগে "পেছনের দিক প্রিন্ট", তারপর একবার লগ। ব্যাকএন্ড: `CardResource.printable` (`CardPrintService::printable()` — একটাই নিয়ম, HR সিস্টেমও এটা পড়ে), `/cards/options` → `print`। যাচাই: headless Chrome-এ `window.print` stub, PDF-এ ৪ পাতা 242.9 × 153.1 pt (= ৮৫.৬ × ৫৪ mm), শেষে খালি পাতা নেই; bn/ডার্ক/ফোন।
     - ✅ **(ধাপ ২খ, ৬ অক্টোবর)** "প্রিন্ট" বোতাম: `pages/cards/CardPrintButton.vue` (নিজেই দেখে `auth.can('cards.print') && card.printable`; `compact` = শুধু আইকন; `@click.stop`, তাই সারির ক্লিক খোলে না) → `cards.ts` → `openPrintPage(ids)` (নতুন ট্যাবে `cards.print`)। বসানো: `CardDetailDialog.vue` (Edit/Revoke-এর পাশে), `CardholderCards.vue` (বর্তমান কার্ডের শিরোনামে আর ইতিহাসের প্রতিটা সারিতে — পরিবারের কার্ডসহ, পুরনো সিস্টেমের সদস্যের পাশের "Print"-এর জায়গা), `CardsPage.vue`-র প্রতিটা সারি। ব্রাউজারে যাচাই (Owner; বাতিল/বদলে যাওয়া কার্ডে বোতাম নেই)।
     - ✅ (ধাপ ২গ, ৬ অক্টোবর) `IssueCardDialog.vue`-তে "ইস্যু করে প্রিন্ট" (`data-issue-print`, শুধু `cards.print` থাকলে; পুরনো আচরণ: ইস্যুর সাথে সাথেই ছাপা)। ক্লিকের মুহূর্তেই ফাঁকা ট্যাব খোলে (পরে খুললে popup blocker আটকায়), ইস্যু সফল হলে সেখানে `printPageHref([নতুন id])`, ব্যর্থ হলে ট্যাব বন্ধ; ব্রাউজার নতুন ট্যাব একেবারে আটকালে একই ট্যাবে প্রিন্টের পাতা। সাধারণ "Issue" বোতাম DOM-এ আগে (`order-last` দিয়ে দেখায় শেষে), তাই Enter চাপলে প্রিন্ট ছাড়া ইস্যু। পরিবারের কার্ডেও একই। যাচাই: ভুল ফর্মে ট্যাব থাকে না, ঠিক ফর্মে নতুন কার্ডের পাতা, popup আটকানো অবস্থায় একই ট্যাবে।
     - মূল নকশা (ধাপ ২):
       - নতুন রাউট, যেমন `/cards/print?ids=a,b` (একই পাতা একক আর ব্যাচ দুটোর জন্য; `meta.permission: 'cards.print'`, অ্যাপের মেনু/হেডার ছাড়া ফাঁকা লেআউট)। প্রতিটা কার্ডের জন্য `GET /cards/{id}` → `meta.layout` + `meta.values` → `CardRender` (`:sides`-এ একটা করে পাশ, যাতে প্রতিটা পাশ আলাদা পাতায়)। `CardRender`-এর নিচের "Front/Back" লেখা প্রিন্টে লুকাতে হবে (prop বা `print:hidden`)।
       - আসল মাপ: `box` = লম্বা দিক mm × 96/25.4 px; CSS `@page { size: <width>mm <height>mm; margin: 0 }` (কার্ডের `layout.size_mm` থেকে, অনুভূমিক/খাড়া অনুযায়ী), প্রতি পাশের পরে `break-after: page`। ফন্ট আর ছবি লোড হওয়ার পর (`document.fonts.ready` + সব `img` `complete`) `window.print()`।
       - ছাপার পরে ব্রাউজার জানায় না সত্যিই ছাপা হয়েছে কিনা, তাই `afterprint`-এর পর ছোট ডায়ালগ: "ঠিকমতো ছাপা হয়েছে?" → হ্যাঁ হলে `POST /cards/{id}/prints` (একটা হলে) বা `POST /cards/prints` (একাধিক) `method: 'browser'`; "আবার ছাপো" আর "বাতিল" (লগ নয়)।
       - বোতাম (`auth.can('cards.print')` আর `printable` = সক্রিয় + মেয়াদ আছে): `CardDetailDialog.vue`, কার্ডধারীর "কার্ড" ট্যাবের বর্তমান কার্ড (`CardholderCards.vue`), `CardsPage.vue`-র সারি। `IssueCardDialog.vue`-তে "ইস্যু করে প্রিন্ট" (পুরনো আচরণ: ইস্যুর সাথে সাথেই ছাপা) — ইস্যুর পরে নতুন কার্ডের id দিয়ে প্রিন্টের পাতা খোলা। পরিবারের কার্ডেও একই।
       - `missingImages()` দিয়ে প্রিন্টের আগে সতর্কতা দেখানো যায়।
       - ব্রাউজারে যাচাই: headless Chrome-এ `window.print` stub করে পাতার মাপ/পাতা ভাঙা দেখা; পরীক্ষার কার্ড ইস্যু করলে পরে tinker দিয়ে মুছতে হবে (নিচের "পরিষ্কার করা" নোট, এখন `card_prints`-ও আগে মুছতে হবে)।
     - ✅ (ধাপ ৩, ৬ অক্টোবর) প্রিন্টের ইতিহাস আর **PENDING/PRINTED** (পুরনো সিস্টেমের ফাঁক পূরণ):
       - ✅ (৩ক ব্যাকএন্ড) `GET /cards`: `filter[printed]` = `pending` (কখনো ছাপা হয়নি) / `printed`, `filter[printed_from]`/`filter[printed_to]` (যেকোনো সময় ছাপা হয়েছে সেই দিনগুলোতে — **প্রতিষ্ঠানের timezone ধরে দিন**, `CardRepository::dayStart()`; পুরনো "Card Print History" রিপোর্টের জায়গা), `sort=last_printed_at`; `GET /cards/summary` → `pending` (সক্রিয়, মেয়াদ আছে, কখনো ছাপা হয়নি)। কার্ডধারী: `Cardholder::currentCard()` (নিজের সক্রিয় কার্ড, পরিবারের কার্ড বাদ), তালিকায় eager load → `CardholderResource.current_card` {id, number, print_count, last_printed_at} (null = কার্ড নেই); `filter[card]` = `none` / `pending` / `printed` (`CardholderFilter::CARD_STATES`)। টেস্ট: `CardListTest` (ঢাকার রাত ১টা = সেই দিন), `CardholderCardStatusTest` (বাতিল নিজের কার্ড + শুধু পরিবারের কার্ড = "কার্ড নেই"; সারি বাড়লেও query বাড়ে না)।
       - ✅ (৩খ UI) `CardDetailDialog.vue`: "প্রিন্টের ইতিহাস" (`data-print-history`; কার্ডের সাথে একসাথে `GET /cards/{id}/prints` — সময়, পদ্ধতি `cards.printMethods.*`, কপি, কে, ব্যাচে কিনা, নোট; শিরোনামে "N বার ছাপা"/"এখনো ছাপা হয়নি")। `CardsPage.vue`: পঞ্চম টাইল "এখনো ছাপা হয়নি" (`summary.pending`, চাপলে `validity=valid` + `printed=pending`; টাইলের key-গুলো `TILE_KEYS`-এ এক জায়গায়), ফিল্টারে "ছাপা" আর "ছাপার তারিখ থেকে/পর্যন্ত", অবস্থার ঘরে "N বার ছাপা" (শেষ ছাপার সময় tooltip) বা "এখনো ছাপা হয়নি" (শুধু ছাপা যায় এমন কার্ডে কমলা)। টাইপ `CardPrintLog`। ব্রাউজারে যাচাই (টাইল, আজকের ছাপা, ইতিহাস, bn/ডার্ক)।
       - ✅ (৩গ UI) `CardholdersPage.vue`: "Status" ঘরের দ্বিতীয় লাইনে নিজের কার্ডের অবস্থা (`data-card-state`: "কার্ড নেই" / "ছাপা বাকি" কমলা / "ছাপা হয়েছে" সবুজ + নম্বর, শেষ ছাপার সময় tooltip) — পুরনো সিস্টেমের "Status" কলামের মতো একই ঘরে (আলাদা কলামে ল্যাপটপে টেবিল চওড়া হয়ে যেত); ফিল্টার "কার্ড" (`filter[card]`)। শুধু `cards.view` থাকলে দেখায়, **সার্ভারও `current_card` শুধু তখনই পাঠায়** (টেস্টসহ)।
     - ✅ (ধাপ ৪) ব্যাচ প্রিন্ট (নতুন; ৪ক তালিকায় বাছাই, ৪খ A4/Letter শিট — ৭ অক্টোবর; ৪গ তালিকার সব পাতা থেকে "সব বাছাই" (`GET /cards/printable`) — ৮ অক্টোবর): `/cards` তালিকা থেকে বাছাই করে একসাথে ছাপা — প্রতি কার্ডে এক পাতা (কার্ড প্রিন্টার) অথবা A4-এ কয়েকটা কার্ড কাটার দাগসহ; প্রতিটা কার্ডের লগ এক batch id-তে।
     - ➡️ (ধাপ ৫) সার্ভারে PDF — ইউজার Gotenberg অনুমোদন করেছেন (৬ অক্টোবর)। কেন: Chromium প্রিন্টের পাতাটাই খোলে, তাই PDF = স্ক্রিন (বাংলা যুক্তাক্ষর, নিজের ফন্ট, ঘোরানো লেখা); dompdf/mPDF-এ নকশা PHP-তে আবার আঁকতে হতো আর বাংলা ভাঙত; jsPDF+html2canvas ঝাপসা। নতুন PHP/npm প্যাকেজ নেই (Laravel HTTP client)।
       - ✅ (৫ক) compose-এ `gotenberg` (`gotenberg/gotenberg:8`, ~2.5 GB): শুধু internal network `pdf`-এ (ইন্টারনেটের রাস্তাই নেই; `app`/`queue`/`scheduler`/`web`-ও এই network-এ), Chromium allow-list `^http://web(/|$)`, পাবলিক IP নিষেধ, webhook/download-from/LibreOffice বন্ধ, কোনো port বাইরে খোলা নয়। যাচাই: Chromium নিজে Google-এ যেতে চেয়েছিল — allow-list আটকেছে, আর কন্টেইনার থেকে ইন্টারনেট পৌঁছায় না। কোড: `app/Support/Pdf` — `PdfRenderer` (interface, `renderUrl(url, PdfOptions)`), `GotenbergPdfRenderer` (`/forms/chromium/convert/url`, CSS page size, margin 0, `failOnConsoleExceptions`, `waitForExpression`), `DisabledPdfRenderer`, `PdfRenderException`; বাঁধা `AppServiceProvider`-এ `config/pdf.php` → `driver` (`PDF_DRIVER`, খালি = বন্ধ), `app_url` (Chromium-এর চোখে অ্যাপ: `http://web`)। টেস্ট `tests/Feature/Pdf/PdfRendererTest.php` (HTTP fake)।
       - ✅ (৫খ-১ ব্যাকএন্ড) `Cards\CardRenderService`: `link(ids, sides)` → `pdf.app_url` + `/render/cards?` + signed query (`tenant` = প্রতিষ্ঠানের public_id, `cards`, `sides` both/front/back, মেয়াদ `pdf.link_minutes` = ৫ মিনিট); `data(tenant, ids, sides)` → প্রতিটা কার্ড (দেওয়া ক্রমে, শুধু সেই প্রতিষ্ঠানের) `{id, number, printable, layout, values}` + প্রতিষ্ঠানের ফন্ট। রাউট `GET /api/v1/card-render` (`signed:relative`, লগইন নেই) — render পাতা নিজের query হুবহু এখানে পাঠায়। `TenantRepository::findByPublicId()`, `CardRepository::byPublicIds()`। টেস্ট `CardRenderTest` (বদলানো/মেয়াদোত্তীর্ণ লিংক 403, অন্য প্রতিষ্ঠানের কার্ড বাদ)।
       - ✅ (৫খ-২ ফ্রন্টএন্ড) `CardSheets.vue` — কার্ডের পাতা সাজানোর একমাত্র জায়গা (প্রতি পাশ এক পাতা, নামের `@page`, ছাপার CSS, `waitForAssets()` expose); `CardPrintPage` আর নতুন `CardRenderPage` দুটোই এটা ব্যবহার করে, তাই ব্রাউজারে ছাপা = সার্ভারের PDF। `cards.ts` → `PrintSides`, `SheetCard`, `sheetSides()`। রাউট `cards.render` = `/render/cards` (`meta.public`: guard লগইন দেখে না)। পাতা নিজের query হুবহু `GET /card-render`-এ পাঠায়, ফন্ট `provideTenantFonts()` দিয়ে (`/fonts` ডাকে না), শুধু `printable` কার্ড আঁকে; শেষে `window.cardsReady = true`, ব্যর্থ হলে `false` + Vue-এর বাইরে exception (Gotenberg `failOnConsoleExceptions` → 409, সাথে সাথে)। অপেক্ষার শর্ত `CardRenderService::READY_EXPRESSION`। HTTP client-এর সময়সীমা ৬৫ s (Gotenberg-এর ৬০ s-এর চেয়ে বেশি, যাতে তার এরর ফেরে)। যাচাই: Gotenberg-এর PDF ৪ পাতা 242.9 × 153.1 pt, ছবি করে দেখা = ব্রাউজারের প্রিন্ট; বদলানো লিংক/শুধু বাতিল কার্ড → ০.৩ s-এ এরর, ফাঁকা PDF নয়।
       - ✅ (৫গ) `GET /api/v1/cards/{id}/pdf?sides=&record=&note=` আর `POST /api/v1/cards/pdf` {cards[], sides, record, note} (একটা PDF, দেওয়া ক্রমে) — `CardPdfController` → `Cards\CardPdfService::make()` (`CardPdfRequest`, `Data\Cards\CardPdfData`)। `cards.print` পারমিশন; আগে `CardPrintService::printableCards()` (প্রিন্টের লগের একই নিয়ম, এখন `assertPrintable()`-এ এক জায়গায়) — অজানা → 422 `cards`, ছাপা যায় না → নম্বরসহ 422, ইঞ্জিন ডাকাই হয় না। ইঞ্জিন ব্যর্থ → 503 (`cards.print.pdf_unavailable`, মূল এরর `report()`-এ)। `record` দিলে PDF হওয়ার পরে `method: pdf`, copies ১ হিসেবে লগ (ব্যাচে এক batch id); নইলে লগ নয়। ফাইলের নাম `card-<নম্বর>.pdf` / `cards-<তারিখ-সময়>.pdf`। throttle: একক ৬০/মিনিট, ব্যাচ ২০/মিনিট। টেস্ট `CardPdfTest` (নকল renderer)। আসল যাচাই: একক ০.৫ s, ২ কার্ড ০.৩ s, লাইব্রেরির পটভূমির ছবিসহ ঠিক। **HRM/অন্য সিস্টেম API token দিয়ে সরাসরি এই PDF নিতে পারে।**
       - ✅ (৫ঘ) প্রিন্টের পাতায় "PDF ডাউনলোড" বোতাম আর "প্রিন্ট হিসেবে লিখে রাখো" টিক (শুরুতে বন্ধ) — শুধু `printOptions.pdf` হলে দেখায় (`/cards/options` → `print.pdf` = `pdf.driver` চালু কিনা)। `cards.ts` → `downloadCardsPdf(ids, {sides, record})` (একটা → `GET /cards/{id}/pdf`, একাধিক → `POST /cards/pdf`; `responseType: blob`, `Content-Disposition`-এর নামে ডাউনলোড; ব্যর্থ হলে blob থেকে JSON বার্তা পড়ে)। পাতার "পাশ" বাছাই PDF-এও খাটে; কপি শুধু ব্রাউজারের প্রিন্টে। প্রিন্টের ডায়ালগ লাগে না, তাই ফোনেও চলে। যাচাই: একক PDF + লগ (`pdf`), ব্যাচ ৪ পাতা (বাতিল কার্ড বাদ, লগ নয়), Gotenberg বন্ধ → পরিষ্কার বার্তা, ফোনের মাপ।
     - ✅ (ধাপ ৬, ৮ অক্টোবর) বারকোড: ইস্যু আর প্রিন্টের আগে সতর্কতা (UI `barcodeProblems()`, সার্ভার `CardWarnings` → `meta.warnings`), ছাপায় লাল ভুল-বক্স নয়, ফাঁকা।
   - পুরনো ইমপোর্টের "last print date/time" কলাম (৩.৮) `card_prints`-এ যাবে।
7. **৩.৭ কার্ড যাচাই (স্বাক্ষরিত/অনুমান-অসম্ভব QR লিংক আর পাবলিক পাতা)** — ✅ সম্পন্ন (৮ অক্টোবর)। নকশা আর ফাইল: উপরের "৮ অক্টোবর" (৩.৭ক–ঙ)। মূল জায়গা: `config/cards.php` → `verification`, `Tenant::verificationSettings()/verifyUrl()`, `Cards\CardVerificationService`, `CardVerificationController`, `pages/verify/CardVerifyPage.vue`, `OrganizationPage.vue` ("কার্ড যাচাই"), `CardDetailDialog.vue` ("যাচাইয়ের ইতিহাস"), ঘর `card.verify_url`।
8. ✅ **৩.৮ ইমপোর্টে কার্ডের কলাম** (৯ অক্টোবর; বিস্তারিত উপরের "আগামীকাল এখান থেকে শুরু" → (ক))। কার্ড থাকলে কার্ডধারী মোছা আটকানো ✅ (৩.৫; ৮ অক্টোবর UI-তে বোতাম বন্ধ)।

**পুরনো দুটো বাগ এড়াতে হবে:** কার্ডে ছাপা নম্বর আর রেকর্ডের নম্বর আলাদা হয়ে যাওয়া, আর একই নম্বর একাধিক কার্ডে বসে যাওয়া।

**নতুন ফিচারের প্রস্তাব (পরে বিবেচনার জন্য):** ব্রাউজারে কার্ডের অনুপাতে ছবি ক্রপ, ওয়েবক্যাম থেকে সরাসরি ছবি, সই-এর পটভূমি স্বয়ংক্রিয়ভাবে স্বচ্ছ করা, ডকুমেন্টের মেয়াদের তারিখ, পরিবারের সদস্যদের কাস্টম ফিল্ড, ইমপোর্টের এরর রিপোর্ট CSV হিসেবে ডাউনলোড।

### Phase 4–6
বায়োমেট্রিক ড্রাইভার → অ্যাপয়েন্টমেন্ট আর নোটিফিকেশন (ইমেইল/SMS) → রিপোর্ট আর এক্সপোর্ট → webhooks আর SSO। বিস্তারিত আছে ROADMAP-এ।

## ৯. নতুন চ্যাট শুরু করার প্রম্পট (কপি করে দিন)

```
আমি /var/www/php74/idcard/idcard-pro প্রজেক্টে কাজ চালিয়ে যেতে চাই।
প্রথমে docs/PROGRESS.md-এর একদম উপরের "পরের চ্যাট এখান থেকে শুরু (৯ অক্টোবর রাত, Phase 4)" পুরোটা পড়ো
(চালুর কমান্ড, BIOMETRIC_KEY-এর সাবধানতা, পুরো প্রজেক্টের অবস্থা, Phase 4-এর ধাপের টেবিল, ব্যাকএন্ড
যেভাবে কাজ করে, জানা সাবধানতা, dev ডাটাবেস), তারপর "কাল শুরুর কমান্ড" আর §৫ কাজের নিয়ম;
দরকার হলে docs/ROADMAP.md। Phase 1–3 শেষ; Phase 4-এর ৪.১–৪.৩ (পুরো ব্যাকএন্ড) শেষ — এখন ৪.৪ থেকে।
আঙুল, মুখ, চোখের মণি — সবকিছু রেজিস্ট্রি আর প্রতিষ্ঠানের সেটিং থেকে ডাইনামিক রাখবে, UI-তেও।
পুরনো প্রজেক্ট (../idcard) ভালো করে ফলো করবে, আগের কোনো ফিচার বাদ দেবে না; প্রতিটা কাজে
বাড়ানো যায়, নির্ভরযোগ্য আর যেকোনো HR সিস্টেমের সাথে যুক্ত করা যায় এমনভাবে করবে, আর নতুন কী যোগ করা যায় ভাববে;
প্রতিষ্ঠান-নির্দিষ্ট আচরণ প্রতিষ্ঠানের নিজের সেটিং থেকে।
নিয়ম: ছোট ছোট ধাপে কাজ করবে, প্রতিটা ধাপের আগে ও পরে আমাকে বাংলায় জানাবে,
Controller → Service → Repository কাঠামো মানবে, প্রতিটা পেজ ব্রাউজারে চালিয়ে যাচাই করবে,
প্রতিটা ধাপ শেষে টেস্ট চালিয়ে আমার git পরিচয়ে (কোনো AI attribution ছাড়া) commit করবে,
আর প্রতিটা ধাপের পর docs/PROGRESS.md হালনাগাদ রাখবে। কোনো বাগ রেখে সামনে যাবে না;
ভালো সমাধানে লাইব্রেরি বা সেটিং লাগলে যোগ করবে, তবে কোনটা আর কেন তা আমাকে জানাবে।
```

**ব্রাউজারে যাচাইয়ের নোট (৫ অক্টোবর):** আগের সেশনের scratchpad সাধারণত `/tmp/claude-1000/-var-www-php74-idcard/<session>/scratchpad/`-এ থেকে যায় (`cdp.mjs` সেখান থেকে কপি করা যায়); ফাইল বাছাই সহজে করা যায় পেজের ভেতরে canvas → `DataTransfer` → `input.files` দিয়ে। কার্ড ইস্যু না করে যাচাই করলে পরিষ্কার করা সহজ (অস্থায়ী QA টেমপ্লেট/সদস্য API দিয়ে মোছা যায়)। স্ক্রিপ্ট হারালে নতুন করে লিখতে হয় (`cdp.mjs`: Chrome চালানো, লগইন, `b.api()` দিয়ে পেজ থেকে JSON কল, `DOM.setFileInputFiles` দিয়ে ফাইল আপলোড)। পরীক্ষার ফন্ট: `/usr/share/fonts/truetype/lohit-bengali/Lohit-Bengali.ttf` (OFL)। সাবধান: QA টেমপ্লেটে লেআউট **import** করলে পুরনো লোগো নতুন ফাইল হিসেবে আপলোড হয় আর পুরনো ফাইল মুছে যায়, তাই পরে আগের JSON দিয়ে ফেরানো যায় না (422) — বর্তমান লেআউট থেকে পরীক্ষার উপাদান বাদ দিয়ে ফেরাতে হয়। পরীক্ষায় বানানো ফন্ট/লাইব্রেরির ছবি শেষে মুছে ফেলতে হবে।

**ব্রাউজারে যাচাইয়ের নোট:** headless Chrome (`google-chrome`) CDP দিয়ে চালানো হয় (Node 24-এর নিজস্ব WebSocket, কোনো প্যাকেজ নয়), স্ক্রিপ্ট সেশনের scratchpad-এ থাকে, git-এ নয়। ডেভেলপমেন্ট ডাটাবেসে স্ক্রিপ্ট শুধু "QA designer check" আর "QA family check" টেমপ্লেট বদলায়; ইউজারের নিজের টেমপ্লেট (Designer demo, TE, TEST…) কখনো রিসেট করা যাবে না। ক্যানভাসে উপাদান খুঁজতে `[data-content-key]` (ছাপার অংশ) আর `[data-key]` (সম্পাদনার বাক্স) ব্যবহার করুন।
