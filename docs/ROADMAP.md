# IDCard Pro: আর্কিটেকচার ও রোডম্যাপ

একটি পুরনো আইডি কার্ড সিস্টেমের (Laravel 7 + Vue 2) **একই বিজনেস, একই প্রসেস, একই ফিচার** নতুনভাবে বানানো হচ্ছে। কোনো নির্দিষ্ট প্রতিষ্ঠানের সাথে বাঁধা থাকবে না, যেকোনো কোম্পানির জন্য কনফিগার করা যাবে এমন **multi-tenant SaaS** হিসেবে।

## ১. মূল সিদ্ধান্ত

| বিষয় | সিদ্ধান্ত |
|---|---|
| Backend | Laravel 13, PHP 8.4, versioned REST API (`/api/v1`) |
| Frontend | Vue 3 + TypeScript SPA (Vite, Pinia, Vue Router, vue-i18n, Tailwind 4) |
| Tenancy | এক ডাটাবেসে থাকবে, প্রতিটা ডেটায় `tenant_id` কলাম, আর প্রতিটা কোয়েরি স্বয়ংক্রিয়ভাবে কোম্পানি অনুযায়ী ফিল্টার হবে। প্ল্যাটফর্ম অ্যাডমিনের `tenant_id` থাকবে null |
| Auth | নিজস্ব auth flow, Sanctum-এর SPA cookie আর API token-এর উপর বানানো: tenant-aware লগইন, DB-তে সংরক্ষিত lockout, TOTP MFA, password policy, লগইন অডিট |
| RBAC | spatie/permission-এর teams মোড, যেখানে team = tenant। প্রতিটা কোম্পানির নিজস্ব রোল, আর পারমিশনের একটাই রেজিস্ট্রি (`config/permissions.php`) |
| Audit | spatie/activitylog (শুধু নির্দিষ্ট ফিল্ড, বায়োমেট্রিক বা গোপন ফিল্ড লগে যাবে না) |
| Biometrics | `BiometricDriver` interface। শুরুতে `simulator` আর `upload` driver, পরে SecuGen, ZKTeco, DigitalPersona ইত্যাদি একটা লোকাল bridge agent দিয়ে |
| Notifications | Laravel Notifications, ইমেইল আর SMS driver (শুরুতে `log`), queue-তে চলবে |
| Infra | Docker Compose: php-fpm, nginx, mysql 8.4, redis, mailpit, queue, scheduler, vite |

## ২. ফিচার প্যারিটি: পুরনো থেকে নতুন

| পুরনো সিস্টেম | নতুন (IDCard Pro) | Phase |
|---|---|---|
| Admin login + ৫ বার ভুলে lock | Tenant-aware login, DB-তে lockout, MFA, লগইন ইতিহাস | 1 |
| Role / Dominion / Process / Permission / Authorized Menu | Roles আর permissions (রেজিস্ট্রি থেকে), মেনু আসবে পারমিশন থেকে | 1 |
| Admin users | Users (প্রতি কোম্পানির), invite, status | 1 |
| Activity log | Audit log (ফিল্টারসহ) | 1 |
| Organization Setup (configuration) | Tenant settings: ব্র্যান্ডিং, লোগো, রঙ, ভাষা, timezone, কার্ড নম্বরের ফরম্যাট | 2 |
| Master Setup: Rank, Branch/Trade, Base/Unit, Appointment, Designation, Blood group, Religion, District/Upazila | **Generic lookup lists**: প্রতিটা কোম্পানি নিজের তালিকা বানাবে (Department, Designation, Grade, Location…) | 2 |
| Personal/Office info | **Cardholder** (৬ ট্যাবের ফর্ম, বিস্তারিত পেজ, ১২+ ফিল্টার) ✅ | 2 |
| Family info | পরিবারের সদস্য (স্বামী/স্ত্রী, ভাই/বোন যোগ; রক্তের গ্রুপ এখন সত্যিই সেভ হয়; নতুন: নিজের ছবি ও সই, পরিবারের কার্ডে ছাপা হয়) ✅ | 2–3 |
| Education info, Other course | শিক্ষা ও প্রশিক্ষণ (এক টেবিলে, কোর্স এখন ব্যক্তির সাথে যুক্ত) ✅ | 2 |
| Student info | কাস্টম ফিল্ড + "student" প্রিসেট; যেকোনো প্রতিষ্ঠান নিজের ঘর বানাতে পারে ✅ | 2 |
| Picture, holder sign, PDF, files আপলোড | ছবি/সই (সংস্করণসহ) আর একাধিক ডকুমেন্ট, ছবি পরিশোধন, private disk, signed link ✅ | 2 |
| Template Type, Template, Template Setup | Card templates: ড্র্যাগ-ড্রপ ডিজাইনার (হ্যান্ডেল, mm, স্ন্যাপ ও স্মার্ট গাইড, লেয়ার, লক/লুকানো, একাধিক বাছাই ও সারিবদ্ধ, Undo, আকৃতি, গ্রেডিয়েন্ট, বর্ডার, ঘোরানো, auto-fit, বিকল্প ছবি, তৈরি নকশা, লেআউট export/import), সামনে/পেছনে, ভার্সনিং, আসল কার্ডধারী দিয়ে প্রিভিউ, self-hosted ফন্ট, ডিফল্ট কার্ড মাপ (Template Setup) ✅ | 3 |
| Authorized Signature, Authority Type | Signatories (সই, পদ, কার্যকর তারিখ) ✅ | 3 |
| Auto card number `CODE/YY######` | কনফিগারযোগ্য নম্বর ফরম্যাট, ট্রানজ্যাকশন আর লক দিয়ে, unique ✅ | 3 |
| Card print + reason (New/Promotion/Lost) + history | Card issuance: প্রতিবার ইস্যুতে নতুন রেকর্ড, কারণ, নোট, পুরনো কার্ড বাতিল ✅ (৩.৫); প্রতিটা প্রিন্টের লগ ✅ (৩.৬ ধাপ ১, API), পাতা/বোতাম ৩.৬-এ | 3 |
| Cardholder list "Status" (PENDING / PRINTED) | কার্ডধারীর তালিকায় কার্ডের অবস্থা কলাম আর ফিল্টার (৩.৬) | 3 |
| QR code (টেক্সট) | আসল QR (পুরনো লাইন) + অনুমান-অসম্ভব যাচাইয়ের লিংক + পাবলিক ভেরিফিকেশন পেজ, সব প্রতিষ্ঠানের সেটিংয়ে ✅ | 3 |
| Browser print (PrintArea) | ব্রাউজার প্রিন্ট আর সার্ভারে PDF (CR80), ব্যাচ প্রিন্ট | 3 |
| Finger image upload | Biometric enrollment (driver-ভিত্তিক), quality score, এনক্রিপ্টেড template | 4 |
| Card Print History report, Expired card report | রিপোর্ট আর অ্যাডভান্সড ফিল্টার, Excel/PDF এক্সপোর্ট, মেয়াদ শেষের রিমাইন্ডার | 5 |
| CSV import | CSV/XLSX ইমপোর্ট: যাচাই, প্রিভিউ, queue, ইতিহাস, পুরনো কলামের নাম চেনে ✅; কার্ডের কলাম (নম্বর, ইস্যু, শেষ ছাপা) টেমপ্লেট বাছাই করে ✅ (৯ অক্টোবর, Phase 3) | 2 |
| — (নতুন) | **আইডি কার্ডের অ্যাপয়েন্টমেন্ট** আর স্বয়ংক্রিয় ইমেইল/SMS | 5 |
| — (নতুন) | Webhooks, API tokens, SSO (OIDC/SAML), HR সিস্টেমের সাথে sync | 6 |
| — (নতুন) | বারকোড, প্রতিষ্ঠানের নিজের ফন্ট, ছবির লাইব্রেরি (একবার আপলোড, সব টেমপ্লেটে যুক্ত), ছায়া, যেকোনো কোণে হেলানো, গ্রুপ ✅ | 3 |
| Website (slider, gallery, content) | বাদ। এটা আইডি কার্ড সিস্টেমের অংশ নয় | — |
| Module Create (কোড জেনারেটর) | বাদ। এর বদলে কাস্টম ফিল্ড আর lookup list | — |

## ৩. পুরনো সমস্যা আর তাদের সমাধান

| পুরনো সমস্যা | নতুন ডিজাইনে সমাধান |
|---|---|
| Orphan child রেকর্ড, ভুল ব্যক্তির ডেটা কার্ডে | FK + cascade, প্রতিটা সম্পর্ক স্পষ্টভাবে সংজ্ঞায়িত, সক্রিয় কার্ড একটাই |
| কার্ডে ছাপা নম্বর ≠ রেকর্ডের নম্বর | প্রিন্ট সবসময় DB-তে সেভ হওয়া issuance থেকেই রেন্ডার হবে |
| ডুপ্লিকেট কার্ড নম্বর | `(tenant_id, number)` unique + `lockForUpdate` সিকোয়েন্স |
| লগইন ছাড়া খোলা রাউট | সব API `auth:sanctum` + tenant + permission মিডলওয়্যারের পেছনে। ব্যতিক্রম কেবল যাচাই করা public verify |
| অনিরাপদ ফাইল আপলোড | MIME যাচাই, এলোমেলো নাম, private disk, signed URL, nginx-এ PHP চালানো নিষেধ |
| সেশন-ভিত্তিক lockout | DB-তে `failed_login_attempts` আর `locked_until` |
| শেয়ার করা অ্যাকাউন্ট | প্রতি ব্যক্তি আলাদা ইউজার, MFA, প্রতিটা প্রিন্ট ইউজারের নামে লগ |
| Activity log-এ সব ব্যক্তিগত ডেটা | শুধু অনুমোদিত ফিল্ড লগ হবে, বায়োমেট্রিক কখনো নয় |

## ৪. Phase পরিকল্পনা

- **Phase 1: ভিত্তি।** ✅ সম্পন্ন (৩ অক্টোবর ২০২৬)। Docker, tenancy, custom auth (MFA সহ), RBAC, users, audit, SPA shell, i18n (en/bn), ২৮টা feature test।
- **Phase 2: Master data।** ✅ সম্পন্ন (৩ অক্টোবর ২০২৬)। Lookup lists, cardholders (ফাইল, পরিবার, শিক্ষা, কাস্টম ফিল্ড), অ্যাডভান্সড ফিল্টার, CSV/XLSX ইমপোর্ট, ১১২টা টেস্ট। কার্ড নম্বরের ফরম্যাট সেটিং কার্ড মডিউলের সাথে Phase 3-এ যাবে। বিস্তারিত: [PROGRESS.md](PROGRESS.md)।
- **Phase 3: কার্ড।** ✅ সম্পন্ন (৯ অক্টোবর ২০২৬; ২৭৮টা টেস্ট)। ✅ signatories, নম্বর সিকোয়েন্স, টেমপ্লেট আর ডিজাইনার (৪ অক্টোবর ২০২৬); বারকোড, প্রতিষ্ঠানের ফন্ট, ছবির লাইব্রেরি, ছায়া, হেলানো আর গ্রুপ; কার্ড ইস্যু (পরিবারের সদস্যের নিজের ছবি ও সইসহ), প্রিন্টের লগ (৫ অক্টোবর ২০২৬)। ✅ প্রিন্ট (পাতা, PENDING/PRINTED, ব্যাচ, A4 শিট, সার্ভারের PDF, বারকোড/QR, কার্ডে আসল QR) আর কার্ড যাচাই (প্রতিষ্ঠানের সেটিং, পাবলিক পাতা, যাচাইয়ের লগ) — ৮ অক্টোবর ২০২৬। ✅ ইমপোর্টে কার্ডের কলাম, কার্ড থাকলে কার্ডধারী মোছা আটকানো, শেষের পরীক্ষা (পুরনো সিস্টেমের সাথে মিলিয়ে দুটো ফাঁক ঠিক: কার্ড নম্বর দিয়ে কার্ডধারী খোঁজা, "New" শুধু প্রথম কার্ডে) — ৯ অক্টোবর ২০২৬। পুরনো দুটো রিপোর্ট (Card Print History, Expired card + Print/Excel) পরিকল্পনামতো Phase 5-এ।
- **Phase 4: Biometrics।** ✅ সম্পন্ন (৯–১১ অক্টোবর ২০২৬; ৩৪৯টা PHP + ৩২টা JS টেস্ট)। আঙুলের ছাপ, মুখ আর চোখের মণি — কোনটা, কয়টা, কখন, কোন স্ক্যানারে, সব প্রতিষ্ঠানের সেটিংয়ে; নতুন ধরন/ব্র্যান্ড শুধু `config/biometrics.php`-এ। Driver interface (SecuGen, bridge — DigitalPersona/ZKTeco/যেকোনো SDK এর মাধ্যমে, ক্যামেরা, simulator, upload), bridge agent spec, enrollment UI, ছবি + টেমপ্লেট নিজস্ব চাবিতে এনক্রিপ্ট। ✅ ৪.১ সেটিং, ৪.২ এনক্রিপ্ট করা সংরক্ষণ, ৪.৩ এনরোলমেন্ট API আর শর্তের হিসাব (৯ অক্টোবর ২০২৬)। ✅ ৪.৪ Organization পাতায় সেটিং, ৪.৫ ব্রাউজারের ড্রাইভার কাঠামো (plug-in module), ৪.৬ কার্ডধারীর বায়োমেট্রিক ট্যাব, ৪.৭ স্ক্যানার-লাইব্রেরি (bridge agent protocol — `docs/BIOMETRIC_BRIDGE.md`, DigitalPersona/ZKTeco bridge দিয়ে, SecuGen WebAPI, Vitest), ৪.৮ ওয়েবক্যামে মুখ, ৪.৯ সংরক্ষিত বায়োমেট্রিক অবস্থা + কার্ডের শর্ত কাঠামো (ইস্যু/প্রিন্ট/PDF-এ বাধা, override কারণসহ audit-এ) (১০ অক্টোবর ২০২৬)। ✅ ৪.১০ কার্ডে বায়োমেট্রিক ছবি — ডিজাইনারের ঘর, প্রতিষ্ঠানের বাছাই "ইস্যুর সময়ের (কার্ডের নিজের এনক্রিপ্ট করা কপি) / প্রিন্টের সময়ের সর্বশেষ", প্রিন্ট/PDF, সতর্কতা (১১ অক্টোবর ২০২৬)। ✅ ৪.১১ শেষের পরীক্ষা (রেজিস্ট্রিতে নতুন ধরনের পুরো পথের টেস্ট, পারমিশন, আসল + নকল ব্রাউজার যাচাই) — ১১ অক্টোবর ২০২৬। ধাপের তালিকা: [PROGRESS.md](PROGRESS.md)-এর শুরুতে।
- **Phase 5: অপারেশন।** অ্যাপয়েন্টমেন্ট আর নোটিফিকেশন (ইমেইল/SMS), রিপোর্ট আর এক্সপোর্ট, মেয়াদ শেষের রিমাইন্ডার, ইমপোর্ট। 🔄 চলছে (১২ অক্টোবর ২০২৬ থেকে)। ✅ ৫.১ পুরনো দুটো রিপোর্ট (Card Print History, Expired card) একটা রিপোর্ট-রেজিস্ট্রিতে — নতুন রিপোর্ট = একটা ক্লাস + `config/reports.php`-এ এক লাইন; কার্ডধারীর সব ঘর/master data/কাস্টম ফিল্ড থেকে ঘর বাছাই (নিজের আর প্রতিষ্ঠানের ডিফল্ট), CSV/Excel/PDF/প্রিন্ট (ফরম্যাটও plug-in), প্রতিটা ডাউনলোড audit-এ। বাকি ক্রম: ৫.২ নোটিফিকেশন (৫.২ক–গ ✅ ১৪ অক্টোবর: রেজিস্ট্রি + প্রতিষ্ঠানের সেটিং — ভাষা আর চ্যানেল সব সেটিং থেকে, লগ + ইচ্ছাধীন মোছা, পাঠানোর ইঞ্জিন — ইমেইল/SMS plug-in, queue; ৫.২ঘ ✅ ১৪–১৫ অক্টোবর: পারমিশন `notifications.view/send`, পাঠানো বার্তার লগের তালিকা API — পুরো ঠিকানা আর লেখা, রেজিস্ট্রি থেকে ফিল্টার —, ব্যর্থ/না-যাওয়া বার্তা আবার পাঠানো — নতুন সারি, এখনকার ঠিকানা, audit-এ —, পরীক্ষার বার্তা — যেকোনো চ্যানেলে, প্রতিষ্ঠানের ভাষায়, সেটিং বন্ধ থাকলেও; ৫.২ঙ-১ ✅ সেটিংয়ের নিজস্ব API; ৫.২ঙ-২ ✅ preview-এর নমুনা মান (মেমরিতে বানানো নকল কার্ড, আসল বার্তার হুবহু মানে) + ব্রাউজারে লেখা ভরা আর SMS-এর ভাগ গোনা, ডিফল্ট বাংলা SMS এক ভাগে; ৫.২ঙ ✅ (১৫ অক্টোবর) আলাদা "নোটিফিকেশন" পাতা (প্রশাসন মেনুতে; শুধু-দেখার রূপ) + Organization পাতায় সারাংশ — সাধারণ সেটিং ("SMS-এর ভাষা"সহ), ঘটনা-প্রতি চালু/চ্যানেল/নিজের লেখা (সম্পাদক, আসলের হুবহু preview, SMS-এর ভাগ), পরীক্ষার বার্তা; ৫.২চ-১ ✅ পাঠানো বার্তার ট্যাব (ফিল্টার, বিবরণ, আবার পাঠানো); ৫.২চ পাঠানো বার্তা → ৫.২ছ যাচাই) → ৫.৩–৫.৭ অ্যাপয়েন্টমেন্ট আর স্বয়ংক্রিয় বার্তা → ৫.৮ নতুন রিপোর্ট → ৫.৯ শেষের পরীক্ষা। ধাপের তালিকা: [PROGRESS.md](PROGRESS.md)। ✅ ৫.১চ-১…৬ (১৩ অক্টোবর): পুরনোর সাথে মিলিয়ে সব ফাঁক বন্ধ — কার্ডের ধরন, তারিখের সীমা, "#", সব তালিকায় প্রতি পাতায় ১০–২০০০ সারি (`config/lists.php`), আর পুরনোর প্রায় সব তালিকার Print/PDF/Excel এখন রেজিস্ট্রির **১৩টা রিপোর্ট** (কার্ডধারী, ইস্যু করা কার্ড, পরিবার, শিক্ষা, ইউজার, রোল, master data, সইকারী, টেমপ্লেট, audit-এর পরিবর্তন আর লগইন + আগের দুটো) — তালিকার পাতায় "ডাউনলোড" মেনু; বাংলা validation। ✅ ৫.১চ-৭ (১৪ অক্টোবর) শেষের যাচাই: রিপোর্টের পারমিশন (কার্ডের রিপোর্টে `cards.view`), ফোন/বাংলা/ডার্ক, ২,০০০ সারির PDF (৩ সেকেন্ডের নিচে), ঘরের সংখ্যায় PDF-এর কাগজ (`reports.pdf_layouts`, সর্বোচ্চ ২০ ঘর), কার্ডধারীর তারিখ/সংখ্যা আসল তারিখ/সংখ্যা, বাংলায় ২৪ ঘণ্টার সময় — **৫.১ সম্পন্ন**।
- **Phase 6: ইন্টিগ্রেশন।** Webhooks, API token UI, SSO, পুরনো সিস্টেম থেকে ডেটা মাইগ্রেশন টুল, **যেকোনো HR ম্যানেজমেন্ট সিস্টেমের সাথে কার্ডধারী sync** (API/webhook দিয়ে; তাই সব নিয়ম service-এ আর API স্থির রাখা হয়)।
- **পরে (ইউজারের ভাবনা, ১১ অক্টোবর ২০২৬ — সব Phase শেষ হওয়ার পরে, এখনকার ধারা না ভেঙে):**
  - **ছবির কাজ:** ছবির পটভূমি সরানো (যেমন `rembg` আলাদা container-এ, ইন্টারনেট ছাড়া), ফরম্যাট বদল/মাপ ঠিক করা; WSQ আঙুলের ছাপ PNG-তে বদলে কার্ডে ছাপা (NIST NBIS `dwsq`, public domain, আলাদা container) — এখন WSQ বাদ পড়ে আর সতর্কতা দেখায়।
  - **পুরনো কার্ড থেকে টেমপ্লেট:** অন্য অ্যাপে বানানো/সম্পাদিত কার্ডের ছবি আপলোড করলে একই ধাঁচের টেমপ্লেট নিজে থেকে তৈরি (লেখা, ছবি, লোগোর জায়গা চিনে ডিজাইনারের ঘর; পরে ছবি/তথ্য সবার জন্য ডাইনামিক)। লোকালে চলা layout/OCR মডেল (যেমন PaddleOCR) — বাইরের AI সার্ভিসে ডেটা পাঠানো নয়।
  - **১:১ মিলিয়ে দেখা আর ডুপ্লিকেট খোঁজা:** matcher (যেমন SourceAFIS আলাদা container) — ইউজারকে জিজ্ঞেস করে।

## ৫. কোডের কাঠামো (প্রতিটি মডিউলে একই নিয়ম)

```
Controller  →  Service  →  Repository (interface)  →  Eloquent Model
   │              │               │
   │              │               └─ app/Repositories/Contracts + Eloquent/  (শুধু ডাটাবেস কোয়েরি)
   │              └─ app/Services/<Module>/   (বিজনেস নিয়ম, ট্রানজ্যাকশন, BusinessRuleException)
   └─ app/Http/Controllers/Api/V1/            (শুধু রিকোয়েস্ট → রেসপন্স)
```

- **Form Request:** ইনপুট ভ্যালিডেশন আর পারমিশন চেক।
- **DTO:** `app/Data/<Module>/`, ফিল্টার বা ইনপুট টাইপ-সেফ অবজেক্ট হিসেবে।
- **Resource:** `app/Http/Resources/`, API রেসপন্সের আকার ঠিক করে।
- **Repository binding:** `App\Providers\RepositoryServiceProvider::$bindings`।
- বিজনেস নিয়ম ভাঙলে service থেকে `BusinessRuleException` ছোঁড়া হয়, যা HTTP 422 হয়ে ফেরত যায়।
- উদাহরণ মডিউল: Master Data।

## ৬. চালানো

```bash
cp .env.example .env    # পাসওয়ার্ড বদলান
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan storage:link
docker compose --profile dev up -d vite   # ডেভেলপমেন্টে hot reload
```

- অ্যাপ: http://localhost:8080
- টেস্ট ইমেইল: http://localhost:8025 (Mailpit)
- টেস্ট চালাতে: `docker compose exec app php artisan test --compact`

## ৭. ডেমো লগইন (শুধু লোকাল)

| লগইন | প্রতিষ্ঠান | পাসওয়ার্ড |
|---|---|---|
| platform@idcard.local | (ফাঁকা) | Demo@Pass2026! |
| owner@acme.test | acme | Demo@Pass2026! |
| operator@acme.test | acme | Demo@Pass2026! |

## ৮. মান যাচাই

```bash
docker compose exec app php artisan test --compact     # Pest
docker compose exec app vendor/bin/phpstan analyse     # Larastan level 5
docker compose exec app vendor/bin/pint                # কোড স্টাইল
docker compose run --rm vite npx vue-tsc --noEmit      # TypeScript
```
