# IDCard Pro: কাজের অগ্রগতি ও হ্যান্ডঅফ

> নতুন চ্যাট বা নতুন ডেভেলপার এই ফাইল থেকে শুরু করবেন। পুরো পরিকল্পনা আছে [ROADMAP.md](ROADMAP.md)-এ।
> সর্বশেষ আপডেট: ৫ অক্টোবর ২০২৬। **Phase 1 আর Phase 2 সম্পন্ন।** Phase 3 চলছে: ৩.১ Signatories, ৩.২ কার্ড নম্বর, ৩.৩ টেমপ্লেট, ৩.৪ টেমপ্লেট ডিজাইনার (ক–ছ: বারকোড, প্রতিষ্ঠানের ফন্ট, ছবির লাইব্রেরি, ছায়া, হেলানো, গ্রুপসহ) সম্পন্ন।
> **এখান থেকে শুরু:** §৮ → **৩.৫ কার্ড ইস্যু** (৩.৪ছ সম্পূর্ণ: বারকোড, প্রতিষ্ঠানের ফন্ট, ছবির লাইব্রেরি, ছায়া, যেকোনো কোণে হেলানো, গ্রুপ)। শেষ commit: `git log --oneline -1`।

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

**মান:** ১৬৮টা টেস্ট পাস, Larastan (level 5) আর Pint পরিষ্কার, TypeScript টাইপ-চেক পরিষ্কার। প্রতিটা পেজ headless Chrome-এ চালিয়ে যাচাই করা হয়েছে।

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
| কার্ডে ছাপার মান | `Cards\CardDataResolver` (প্রিভিউ এখন, প্রিন্ট ৩.৬-এ) | `SignatoryRepository::activeFor()` |
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
| ডার্ক মোডে গাঢ় ব্র্যান্ড রঙে `text-brand` লেখা প্রায় অদৃশ্য (যেমন বাঁ মেনুর চালু আইটেম) | ডিজাইনারে চালু অবস্থা `bg-brand text-brand-contrast` দিয়ে ঠিক করা। **পুরো অ্যাপে এখনো বাকি**: ডার্ক মোডে ব্র্যান্ড রঙ হালকা করা বা `text-brand`-এর বদল |
| স্বয়ংক্রিয় ব্রাউজার যাচাইয়ে অল্প সময়ে বহুবার লগইন করলে লগইন আটকে যায় (`throttle:auth`) | প্রতিটা চালানোর মাঝে ~১ মিনিট বিরতি, অথবা এক স্ক্রিপ্টে একবার লগইন করে সব যাচাই |
| Pest-এ `assertJsonPath('data.fields.custom\\.class')` কাজ করে না | ডটওয়ালা key-র জন্য `->json(...)` নিয়ে `toMatchArray` ব্যবহার করুন |
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
     - পরিবারের সদস্যের নিজের ছবি বা সই এখনো নেই, তাই পরিবারের কার্ডের প্রিভিউতে ছবি ফাঁকা। ৩.৫-এ ঠিক করতে হবে।
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
5. **৩.৫ কার্ড ইস্যু** — ➡️ চলছে (৫ অক্টোবর)। পুরনো সিস্টেম দেখা হয়েছে: `card_information` (প্রতি জনে একটা সারি, ওভাররাইট হতো; card_type = টেমপ্লেট, card_id_number, rf_card_no, ইস্যু/মেয়াদ), `card_print_history` (user, `card_get` = new/promotion/lost, notes, template, number; শেষ ৫টা দেখাত), একবার প্রিন্টের পর "New" লুকাত, promotion/lost-এ নোট + ডকুমেন্টের ছবি (বাগ: ছবিটা কার্ডধারীর `finger` ঘরে বসত), টেমপ্লেট বদলালে নতুন নম্বর আর একই টেমপ্লেটে আবার প্রিন্টে একই নম্বর, পরিবারের কার্ডের কোনো রেকর্ড/ইতিহাস ছিল না, রিপোর্ট: প্রিন্ট ইতিহাস আর মেয়াদোত্তীর্ণ কার্ড।
   - **ইউজারের সিদ্ধান্ত:** আবার ইস্যুতে নম্বর = সেটআপে সেটিং (`tenants.settings.card_number_on_reissue`: `keep` ডিফল্ট = পুরনো আচরণ, অথবা `new`)।
   - ✅ **ধাপ ১** (commit `04e05c6`): `cards` টেবিল (holder = cardholder বা family member, template + version, `layout` snapshot JSON, number, rf_card_no, reason, notes, document/photo/signature file id, signatory, issue/expiry, status active/replaced/revoked, replaced_by, issued_by)। নম্বর unique নয় (keep নীতি), তার বদলে `active_number_key` আর `active_holder_key` (শুধু সক্রিয় কার্ডে বসে) — DB-তে প্রতি নম্বরে আর প্রতি জনে একটাই সক্রিয় কার্ড। `config/cards.php` (কারণ: new, renewal, promotion, lost, damaged, correction; কোনটায় নোট/ডকুমেন্ট), `App\Enums\CardStatus`, পারমিশন `cards.view|issue|update|revoke` (Operator: view+issue, Viewer: view)।
   - ✅ **ধাপ ২** (commit `72f38fd`): `CardIssueService::issue()` (`Data\Cards\IssueCardData`, `CardRepository`): টেমপ্লেট সক্রিয় আর audience মিল (পরিবারের টেমপ্লেট ↔ পরিবারের সদস্য), নোট/ডকুমেন্ট কারণ অনুযায়ী, ডকুমেন্ট আপলোড হলে কার্ডধারীর ডকুমেন্টে যায়, RF নম্বর অন্য জনের সক্রিয় কার্ডে থাকলে আটকায়, মেয়াদ = টেমপ্লেটের `validity_months` (না থাকলে খালি)। ট্রানজ্যাকশনে: আগের সক্রিয় কার্ড lock → replaced, নম্বর (keep হলে একই holder-এর একই টেমপ্লেট-কোডের শেষ কার্ডের নম্বর, নইলে `CardNumberService::next`), snapshot (`CardTemplateDesignService::snapshot()`), চালু ছবি/সই আর সেদিনের সইকারীর id। API: `GET|POST /cardholders/{id}/cards`, `GET /cardholders/{id}/cards/next-number?template=&family_member=`। ১৭৬টা টেস্ট।
   - ✅ **ধাপ ৩** (commit `1fcc8c2`): সিদ্ধান্ত — কার্ড দলিল, তাই **ছাপার লেখাও ইস্যুর সময় স্থির** (`cards.values` = `CardDataResolver::fields()`); তথ্য বদলালে "সংশোধন" কারণে নতুন কার্ড। `CardDataResolver::forCard()` = স্থির লেখা + `card.number/issue_date/expiry_date` + কার্ডের নিজের ছবি/সই/সইকারী। `GET /cards/{id}` → `data` (CardResource) + `meta.layout` (`CardLayoutPresenter`, ডিজাইনারের উপাদানের আকারে, ছবির signed লিংকসহ) + `meta.values`। টেমপ্লেট সেভে কোনো ইস্যু হওয়া কার্ডের snapshot-এ থাকা ছবি মোছে না (`CardRepository::snapshotImagePaths`)। টেস্টের সাহায্যকারী `cardTemplate()` এখন `tests/Pest.php`-এ। ১৭৯টা টেস্ট।
   - ✅ **ধাপ ৪** (commit `ee82cd0`): `CardService` — `PATCH /cards/{id}` (RF নম্বর, নোট, মেয়াদ; `cards.update`), `POST /cards/{id}/revoke` {note} (শুধু সক্রিয়; `cards.revoke`; বাতিলের পর নতুন ইস্যু করা যায়)। মোছা আটকানো (বাংলা/ইংরেজি `cards.in_use.*`): কার্ডধারী, পরিবারের সদস্য, টেমপ্লেট (নিষ্ক্রিয় করতে বলে), সইকারী, ছাপা ছবি/সই ফাইল, লাইব্রেরির ছবি, ফন্ট। লাইব্রেরির ছবি আর ফন্টের ব্যবহার JSON খুঁজে নয় — ইস্যুর সময় `card_assets` (kind + reference; নতুন ধরন যোগ করা যায়) টেবিলে লেখা হয়।
   - ✅ **ধাপ ৫** (commit `d350adf`): `GET /cards` (`Data\Cards\CardFilter`, `ListCardsRequest`) — পুরনো "Card Print History" আর "Expired Card" রিপোর্টের জায়গায়: `filter[search]` (নম্বর, RF, নাম, কর্মী নম্বর, পরিবারের সদস্যের নাম), `status`, `reason`, `template`, `holder` (cardholder/family), `issued_from/to`, `validity` (expired / expiring + `expiring_days` / valid — শুধু সক্রিয় কার্ড), `department`, `unit`; sort `issue_date|expiry_date|number|created_at`, `per_page`। `GET /cards/summary?expiring_days=` → সক্রিয়, মেয়াদোত্তীর্ণ, শীঘ্রই শেষ, বাতিলের সংখ্যা (ড্যাশবোর্ড আর ৫-এর মেয়াদ-রিমাইন্ডারেও কাজে লাগবে)। টেস্টে রোলের পারমিশন বদলাতে `TenantContext::run` + `Role::findByName(..., PermissionRegistry::GUARD)`।
   - ➡️ বাকি ধাপ: (৬) কার্ডধারীর পাতায় "কার্ড" ট্যাব আর ইস্যু ডায়ালগ; (৭) "কার্ড ইস্যু" মেনুর পেজ আর সেটিংসে নম্বরের নিয়ম; (৮) পরিবারের সদস্যের নিজের ছবি/সই (পরে)।
   - মূল পরিকল্পনা (পুরনো `card_information`): কারণ (new / promotion / lost / renewal), নোট, মেয়াদ, RF card no। প্রতিবার ইস্যুতে নতুন রেকর্ড, আগের কার্ড বাতিল। **যে ছবি, সই আর টেমপ্লেট সংস্করণ ব্যবহার হলো তার id রাখতে হবে।** পরিবারের সদস্যের কার্ডও এখানে।
   - **ছাপার নিয়ম যা ডিজাইনারে আছে, সেগুলো মানতে হবে:** লুকানো উপাদান বাদ, আসল ছবি না থাকলে উপাদানের বিকল্প ছবি, auto-fit, ঘোরানো। সবচেয়ে নিরাপদ: প্রিন্টেও `DesignerCanvas bare` + `DesignerElement` ব্যবহার করা।
   - **নকশা যেন পরে না বদলায়:** ইস্যুর সময় টেমপ্লেটের লেআউটের snapshot (elements JSON + version) কার্ডের সাথে রাখতে হবে, নইলে পরে টেমপ্লেট বদলালে পুরনো কার্ড আবার ছাপলে অন্যরকম আসবে। লোগো/ব্যাকগ্রাউন্ডের ফাইলও তখন মোছা যাবে না (এখন লেআউট থেকে বাদ পড়লে মুছে যায়)।
   - `card.*` ঘর (number, issue_date, expiry_date) `CardDataResolver`-এ যোগ করতে হবে; এখন প্রিভিউতে নমুনা দেখায়।
   - পরিবারের সদস্যের নিজের ছবি ও সই রাখার ব্যবস্থা (এখন নেই, তাই পরিবারের কার্ডে ছবি ফাঁকা)।
   - ✅ লাইব্রেরির ছবি আর ফন্ট ইস্যু হওয়া কার্ডে থাকলে মোছা যায় না (ধাপ ৪, `card_assets`)।
   - **বারকোড:** ইস্যুর সময় মান এনকোড হয় কিনা যাচাই (যেমন EAN-13-এ অক্ষর) — না হলে সতর্ক করা; প্রিন্টে লাল ভুল-বক্স ছাপা যাবে না (৩.৬)।
6. **৩.৬ প্রিন্ট আর ইতিহাস** (পুরনো `card_print_history`): ব্রাউজার প্রিন্ট আর সার্ভারে PDF (CR80), ব্যাচ প্রিন্ট, প্রতিটা প্রিন্ট ইউজারের নামে লগ। মান আসবে `CardDataResolver` থেকে, আঁকা হবে `DesignerCanvas bare` + `styles.ts` দিয়ে (mm ভিত্তিক, তাই প্রিন্টে `scale` = 96/25.4 বা PDF-এর DPI)। PDF বানাতে বাইরের কোনো সেবা নয়; ফন্ট `@fontsource` ফাইল থেকে।
7. **৩.৭ স্বাক্ষরিত QR আর পাবলিক ভেরিফিকেশন পেজ।**
8. **৩.৮ ইমপোর্টে কার্ডের কলাম** (উপরে দেখুন) আর কার্ডধারী মোছার নিয়ম (কার্ড থাকলে মোছা যাবে না)।

**পুরনো দুটো বাগ এড়াতে হবে:** কার্ডে ছাপা নম্বর আর রেকর্ডের নম্বর আলাদা হয়ে যাওয়া, আর একই নম্বর একাধিক কার্ডে বসে যাওয়া।

**নতুন ফিচারের প্রস্তাব (পরে বিবেচনার জন্য):** ব্রাউজারে কার্ডের অনুপাতে ছবি ক্রপ, ওয়েবক্যাম থেকে সরাসরি ছবি, সই-এর পটভূমি স্বয়ংক্রিয়ভাবে স্বচ্ছ করা, ডকুমেন্টের মেয়াদের তারিখ, পরিবারের সদস্যদের কাস্টম ফিল্ড, ইমপোর্টের এরর রিপোর্ট CSV হিসেবে ডাউনলোড।

### Phase 4–6
বায়োমেট্রিক ড্রাইভার → অ্যাপয়েন্টমেন্ট আর নোটিফিকেশন (ইমেইল/SMS) → রিপোর্ট আর এক্সপোর্ট → webhooks আর SSO। বিস্তারিত আছে ROADMAP-এ।

## ৯. নতুন চ্যাট শুরু করার প্রম্পট (কপি করে দিন)

```
আমি /var/www/php74/idcard/idcard-pro প্রজেক্টে কাজ চালিয়ে যেতে চাই।
প্রথমে docs/PROGRESS.md আর docs/ROADMAP.md পড়ো, তারপর §৮-এর "এখান থেকে শুরু" দেখো।
টেমপ্লেট ডিজাইনার (৩.৪) শেষ; ৩.৪ছ-এর তালিকা থেকে আমি যেগুলো বলব সেগুলো আগে,
তারপর ৩.৫ কার্ড ইস্যু। পুরনো প্রজেক্ট (../idcard) ভালো করে ফলো করবে, আগের কোনো ফিচার বাদ দেবে না।
নিয়ম: ছোট ছোট ধাপে কাজ করবে, প্রতিটা ধাপের আগে ও পরে আমাকে বাংলায় জানাবে,
Controller → Service → Repository কাঠামো মানবে, প্রতিটা পেজ ব্রাউজারে চালিয়ে যাচাই করবে,
আর প্রতিটা ধাপ শেষে টেস্ট চালিয়ে আমার git পরিচয়ে (কোনো AI attribution ছাড়া) commit করবে।
```

**ব্রাউজারে যাচাইয়ের নোট (৫ অক্টোবর):** স্ক্রিপ্ট আগের সেশনের scratchpad থেকে হারায়; নতুন করে লিখতে হয় (`cdp.mjs`: Chrome চালানো, লগইন, `b.api()` দিয়ে পেজ থেকে JSON কল, `DOM.setFileInputFiles` দিয়ে ফাইল আপলোড)। পরীক্ষার ফন্ট: `/usr/share/fonts/truetype/lohit-bengali/Lohit-Bengali.ttf` (OFL)। সাবধান: QA টেমপ্লেটে লেআউট **import** করলে পুরনো লোগো নতুন ফাইল হিসেবে আপলোড হয় আর পুরনো ফাইল মুছে যায়, তাই পরে আগের JSON দিয়ে ফেরানো যায় না (422) — বর্তমান লেআউট থেকে পরীক্ষার উপাদান বাদ দিয়ে ফেরাতে হয়। পরীক্ষায় বানানো ফন্ট/লাইব্রেরির ছবি শেষে মুছে ফেলতে হবে।

**ব্রাউজারে যাচাইয়ের নোট:** headless Chrome (`google-chrome`) CDP দিয়ে চালানো হয় (Node 24-এর নিজস্ব WebSocket, কোনো প্যাকেজ নয়), স্ক্রিপ্ট সেশনের scratchpad-এ থাকে, git-এ নয়। ডেভেলপমেন্ট ডাটাবেসে স্ক্রিপ্ট শুধু "QA designer check" আর "QA family check" টেমপ্লেট বদলায়; ইউজারের নিজের টেমপ্লেট (Designer demo, TE, TEST…) কখনো রিসেট করা যাবে না। ক্যানভাসে উপাদান খুঁজতে `[data-content-key]` (ছাপার অংশ) আর `[data-key]` (সম্পাদনার বাক্স) ব্যবহার করুন।
