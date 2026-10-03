# IDCard Pro: কাজের অগ্রগতি ও হ্যান্ডঅফ

> নতুন চ্যাট বা নতুন ডেভেলপার এই ফাইল থেকে শুরু করবেন। পুরো পরিকল্পনা আছে [ROADMAP.md](ROADMAP.md)-এ।
> সর্বশেষ আপডেট: ৩ অক্টোবর ২০২৬। **Phase 1 আর Phase 2 সম্পন্ন।** Phase 3 চলছে: ৩.১ Signatories আর ৩.২ কার্ড নম্বরের ফরম্যাট সম্পন্ন। পরের কাজ ৩.৩, কার্ড টেমপ্লেট (§৮ দেখুন)।

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
| 2.4 | পরিবারের সদস্য (বিস্তারিত পেজের ট্যাব) | `FamilyMemberService`, `CardholderFamily.vue` |
| 2.5 | শিক্ষা ও প্রশিক্ষণ কোর্স (এক টেবিলে), কাস্টম ফিল্ড (সেটআপ পেজ, প্রিসেট, ফর্ম ট্যাব, ফিল্টার) | `QualificationService`, `app/Services/CustomFields`, `config/custom_field_presets.php`, `pages/custom-fields` |
| 2.6 | CSV/XLSX ইমপোর্ট: যাচাই আর প্রিভিউ, queue-তে ইমপোর্ট, ইতিহাস, টেমপ্লেট | `CardholderImportService`, `CardholderImportMapper`, `app/Jobs/ImportCardholders.php`, `app/Support/Spreadsheets`, `config/cardholder_import.php` |

**মান:** ১৩০টা টেস্ট পাস (১১৯ feature + ১১ unit), Larastan (level 5) আর Pint পরিষ্কার, TypeScript টাইপ-চেক পরিষ্কার। প্রতিটা পেজ headless Chrome-এ চালিয়ে যাচাই করা হয়েছে।

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
| ইমপোর্ট | `Cardholders\CardholderImportService`, `Cardholders\CardholderImportMapper` (+ `Support\Spreadsheets\TabularFileReader`) | `CardholderImportRepository` |

## ৩. চালানো

```bash
cd idcard-pro
docker compose up -d                                   # app, web, mysql, redis, mailpit, queue, scheduler
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
- **পুরনো সিস্টেমের কোনো ফিচার বাদ যাবে না।** নতুন ফিচার যোগ হবে।
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
3. ➡️ **এখান থেকে শুরু, ৩.৩ টেমপ্লেট** (পুরনো `templates` + template type): title, type (কর্মী/পরিবার/অতিথি...), code, orientation, width/height (mm), সামনে/পেছনে, status, সংস্করণ।
4. **৩.৪ টেমপ্লেট ডিজাইনার** (পুরনো `template_sections`): লোগো, ব্যাকগ্রাউন্ড, ছবি, সই, ডেটাবেস ফিল্ড (কাস্টম ফিল্ডসহ), QR, টেক্সট; পজিশন, ফন্ট, রঙ; ড্র্যাগ-ড্রপ।
5. **৩.৫ কার্ড ইস্যু** (পুরনো `card_information`): কারণ (new / promotion / lost / renewal), নোট, মেয়াদ, RF card no। প্রতিবার ইস্যুতে নতুন রেকর্ড, আগের কার্ড বাতিল। **যে ছবি, সই আর টেমপ্লেট সংস্করণ ব্যবহার হলো তার id রাখতে হবে।** পরিবারের সদস্যের কার্ডও এখানে।
6. **৩.৬ প্রিন্ট আর ইতিহাস** (পুরনো `card_print_history`): ব্রাউজার প্রিন্ট আর সার্ভারে PDF (CR80), ব্যাচ প্রিন্ট, প্রতিটা প্রিন্ট ইউজারের নামে লগ।
7. **৩.৭ স্বাক্ষরিত QR আর পাবলিক ভেরিফিকেশন পেজ।**
8. **৩.৮ ইমপোর্টে কার্ডের কলাম** (উপরে দেখুন) আর কার্ডধারী মোছার নিয়ম (কার্ড থাকলে মোছা যাবে না)।

**পুরনো দুটো বাগ এড়াতে হবে:** কার্ডে ছাপা নম্বর আর রেকর্ডের নম্বর আলাদা হয়ে যাওয়া, আর একই নম্বর একাধিক কার্ডে বসে যাওয়া।

**নতুন ফিচারের প্রস্তাব (পরে বিবেচনার জন্য):** ব্রাউজারে কার্ডের অনুপাতে ছবি ক্রপ, ওয়েবক্যাম থেকে সরাসরি ছবি, সই-এর পটভূমি স্বয়ংক্রিয়ভাবে স্বচ্ছ করা, ডকুমেন্টের মেয়াদের তারিখ, পরিবারের সদস্যদের কাস্টম ফিল্ড, ইমপোর্টের এরর রিপোর্ট CSV হিসেবে ডাউনলোড।

### Phase 4–6
বায়োমেট্রিক ড্রাইভার → অ্যাপয়েন্টমেন্ট আর নোটিফিকেশন (ইমেইল/SMS) → রিপোর্ট আর এক্সপোর্ট → webhooks আর SSO। বিস্তারিত আছে ROADMAP-এ।

## ৯. নতুন চ্যাট শুরু করার প্রম্পট (কপি করে দিন)

```
আমি /var/www/php74/idcard/idcard-pro প্রজেক্টে কাজ চালিয়ে যেতে চাই।
প্রথমে docs/PROGRESS.md আর docs/ROADMAP.md পড়ো, তারপর ওখানের "পরের ধাপ" থেকে শুরু করো।
নিয়ম: ছোট ছোট ধাপে কাজ করবে, প্রতিটা ধাপের আগে ও পরে আমাকে বাংলায় জানাবে,
Controller → Service → Repository কাঠামো মানবে, আর প্রতিটা ধাপ শেষে টেস্ট চালিয়ে
আমার git পরিচয়ে (কোনো AI attribution ছাড়া) commit করবে।
```
