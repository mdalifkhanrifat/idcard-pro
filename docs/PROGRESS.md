# IDCard Pro: কাজের অগ্রগতি ও হ্যান্ডঅফ

> নতুন চ্যাট বা নতুন ডেভেলপার এই ফাইল থেকে শুরু করবেন। পুরো পরিকল্পনা আছে [ROADMAP.md](ROADMAP.md)-এ।
> সর্বশেষ আপডেট: ৩ অক্টোবর ২০২৬, Phase 1 আর Phase 2-এর প্রথম অংশ (Master Data) সম্পন্ন।

---

## ১. প্রজেক্টের পরিচয়

- **কী:** যেকোনো প্রতিষ্ঠানের জন্য আইডি কার্ড ব্যবস্থাপনার multi-tenant SaaS। একটা পুরনো Laravel 7 + Vue 2 আইডি কার্ড সিস্টেমের সব ফিচার নতুন করে বানানো হচ্ছে, সাথে নতুন ফিচার যোগ হবে।
- **ফোল্ডার:** `idcard-pro/`। পুরনো সিস্টেমটা পাশের `../idcard/` ফোল্ডারে, শুধু রেফারেন্স হিসেবে। সেখানে কিছু লেখা বা বদলানো যাবে না।
- **স্ট্যাক:** Laravel 13 (PHP 8.4), Vue 3 + TypeScript SPA, MySQL 8.4, Redis, Docker Compose।

## ২. কী কী সম্পন্ন হয়েছে

| ধাপ | বিষয় | মূল ফাইল/ফোল্ডার |
|---|---|---|
| Phase 1 | Docker, multi-tenancy, কাস্টম লগইন (lockout, TOTP MFA, পাসওয়ার্ড রিসেট, API token), রোল-পারমিশন, ইউজার, অডিট লগ, SPA শেল, en/bn, লাইট/ডার্ক | `docker/`, `compose.yaml`, `app/Support/Tenancy`, `app/Services/Auth`, `resources/js` |
| 2.1 | Master Data ব্যাকএন্ড: ১২টা রেফারেন্স তালিকা, প্যারেন্ট-চাইল্ড, ডিফল্ট আইটেম | `config/master_data.php`, `app/Services/MasterData` |
| 2.2 | Master Data পেজ (সেটআপ → মাস্টার ডেটা) | `resources/js/pages/master-data` |
| R1–R4 | সব মডিউল Controller → Service → Repository কাঠামোয় | `app/Services`, `app/Repositories`, `app/Data` |

**মান:** ৫৮টা feature test পাস, Larastan (level 5) আর Pint পরিষ্কার, TypeScript টাইপ-চেক পরিষ্কার।

**বর্তমান মডিউল আর তাদের service:**

| মডিউল | Service | Repository |
|---|---|---|
| ইউজার | `Users\UserService` | `UserRepository` |
| রোল | `Roles\RoleService` | `RoleRepository` |
| প্রতিষ্ঠান (tenant) | `Tenants\TenantService` | `TenantRepository` |
| অডিট | `Audit\AuditService` | `ActivityRepository`, `LoginEventRepository` |
| লগইন | `Auth\AuthenticationService`, `Auth\PasswordResetService`, `Auth\TwoFactorAuthenticator` | `UserRepository`, `PasswordResetTokenRepository` |
| নিজের অ্যাকাউন্ট | `Account\AccountService` | `UserRepository` |
| মাস্টার ডেটা | `MasterData\MasterDataService` | `MasterDataRepository` |

## ৩. চালানো

```bash
cd idcard-pro
docker compose up -d                                   # app, web, mysql, redis, mailpit, queue, scheduler
docker compose exec app php artisan migrate --seed     # প্রথমবার (বা migrate:fresh --seed)
docker compose run --rm --no-deps vite npm run build   # ফ্রন্টএন্ড বিল্ড
docker compose --profile dev up -d vite                # অথবা hot reload
```

- অ্যাপ: http://localhost:8080 (`127.0.0.1:8080`-ও চলে)
- ইমেইল দেখা: http://localhost:8025 (Mailpit, কোনো ইমেইল বাইরে যায় না)
- ডেমো লগইন: `owner@acme.test` / প্রতিষ্ঠান `acme` / `Demo@Pass2026!` (প্ল্যাটফর্ম অ্যাডমিন: `platform@idcard.local`, প্রতিষ্ঠান ফাঁকা)

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

## ৭. পরের ধাপ (এই ক্রমে)

### ধাপ ২.৩: Cardholder (কার্ডধারী), মূল রেকর্ড
পুরনো "Employee Info" বা ব্যক্তিগত তথ্য + অফিসের তথ্যের সমান। একসাথে না করে উপ-ধাপে ভাগ করে করতে হবে।

**২.৩ক, ব্যাকএন্ড:** `cardholders` টেবিল (tenant-scoped, `public_id`)

| ভাগ | ফিল্ড (পুরনো সিস্টেমে যা ছিল) |
|---|---|
| পরিচয় | `full_name`, `full_name_local`, `date_of_birth`, `gender`, `marital_status`, `nationality`, `national_id`, `passport_no`, `blood_group_id`, `religion_id` (দুটোই master_data FK) |
| যোগাযোগ | `email`, `personal_phone`, `emergency_phone`, `present_address`, `permanent_address`, `region_id`, `sub_region_id` (master_data), `postal_code` |
| শারীরিক বৈশিষ্ট্য | `height`, `weight`, `eye_color`, `complexion`, `identification_mark` |
| অফিস | `employee_no` (পুরনো service no, প্রতি tenant-এ unique), `secondary_no` (পুরনো TS no), `department_id`, `unit_id`, `rank_id`, `designation_id`, `branch_id`, `trade_id`, `appointment_id`, `joining_date`, `retirement_date`, `retired_id_no`, `posting`, `previous_office`, `employment_type` (staff / contractor / visitor ইত্যাদি) |
| বাইরের প্রতিষ্ঠান | `company_name`, `company_address`, `company_phone` (ঠিকাদার বা অতিথির জন্য) |
| অবস্থা | `status` (active / inactive / retired), `created_by` |

- **Media আলাদা টেবিলে:** ছবি, সই, ডকুমেন্ট, PDF সবকিছুর জন্য `cardholder_files` (type, private disk, signed URL)। পুরনো সিস্টেমে `picture`, `holder_sign`, `pdf`, `files` ছিল। **আঙুলের ছাপ Phase 4-এ আলাদা।**
- Master data FK সবসময় একই tenant-এর আর সঠিক টাইপের হতে হবে। এটা ভ্যালিডেশনে যাচাই করতে হবে।
- **ফিল্টার:** নাম, employee no, ফোন, বিভাগ, র‍্যাংক, পদবি, শাখা, রক্তের গ্রুপ, অবস্থা, যোগদানের তারিখের সীমা।

**২.৩খ:** ছবি আর সই আপলোড (MIME যাচাই, এলোমেলো নাম, private disk)। **২.৩গ:** Cardholder লিস্ট পেজ ও ফিল্টার। **২.৩ঘ:** তৈরি ও সম্পাদনার ফর্ম (ট্যাবে ভাগ করা)। **২.৩ঙ:** বিস্তারিত দেখার পেজ।

### ধাপ ২.৪: পরিবারের সদস্য
পুরনো `family_infos`-এ ছিল: relation, name, gender, mobile, dob, blood group, nid, passport, identification remark, card no।
নতুন `cardholder_family_members` টেবিল হবে। পরিবারের কার্ড Phase 3-এ আসবে।

### ধাপ ২.৫: শিক্ষা, কোর্স আর অতিরিক্ত তথ্য
- পুরনো `education_infos`: exam, board, passing year, group, degree, gpa।
- পুরনো `other_courses`: course name, institute, year, result, certificate no।
- পুরনো `student_information`: class, section, session, registration।
- **প্রস্তাব:** শিক্ষা আর কোর্স আলাদা টেবিলে থাকবে। ছাত্রের তথ্য আর প্রতিষ্ঠানভেদে বাড়তি ঘরের জন্য **কাস্টম ফিল্ড** ব্যবস্থা থাকবে (প্রতি tenant নিজের ফিল্ড সংজ্ঞায়িত করবে)।

### ধাপ ২.৬: CSV/Excel ইমপোর্ট
পুরনো সিস্টেমে লগইন ছাড়া CSV আপলোড ছিল। নতুনটায় হবে: অনুমতি লাগবে, আগে প্রিভিউ আর যাচাই, তারপর queue-তে ইমপোর্ট।

### Phase 3: কার্ড (পুরনো সিস্টেমের মূল কাজ)
- **টেমপ্লেট** (পুরনো `templates`): title, type, authority type, code, structure (horizontal/vertical), width/height (px/mm), status।
- **সেকশন** (পুরনো `template_sections`): section type (লোগো, ব্যাকগ্রাউন্ড, ছবি, সই, ডেটাবেস ফিল্ড, QR, টেক্সট), field binding, label, ফন্ট, সাইজ, রঙ, পজিশন, সামনে/পেছনে।
- **Signatories** (পুরনো `authorized_signatures`): name, designation, signature image, authority type, status।
- **কার্ড ইস্যু** (পুরনো `card_information` + `card_print_history`): নম্বর ফরম্যাট (`CODE/YY######`, তবে কনফিগারযোগ্য), কারণ (new / promotion / lost), নোট, মেয়াদ, RF card no।
- **পুরনো দুটো বাগ এখানে এড়াতে হবে:**
  - কার্ডে ছাপা নম্বর আর রেকর্ডের নম্বর আলাদা হয়ে যেত।
  - একই নম্বর একাধিক কার্ডে বসে যেত। সমাধান: unique নিয়ম + `lockForUpdate`।
- স্বাক্ষরিত QR + পাবলিক ভেরিফিকেশন পেজ, সার্ভারে PDF বানানো, ব্যাচ প্রিন্ট।

### Phase 4–6
বায়োমেট্রিক ড্রাইভার → অ্যাপয়েন্টমেন্ট আর নোটিফিকেশন (ইমেইল/SMS) → রিপোর্ট আর এক্সপোর্ট → webhooks আর SSO। বিস্তারিত আছে ROADMAP-এ।

## ৮. নতুন চ্যাট শুরু করার প্রম্পট (কপি করে দিন)

```
আমি /var/www/php74/idcard/idcard-pro প্রজেক্টে কাজ চালিয়ে যেতে চাই।
প্রথমে docs/PROGRESS.md আর docs/ROADMAP.md পড়ো, তারপর ওখানের "পরের ধাপ" থেকে শুরু করো।
নিয়ম: ছোট ছোট ধাপে কাজ করবে, প্রতিটা ধাপের আগে ও পরে আমাকে বাংলায় জানাবে,
Controller → Service → Repository কাঠামো মানবে, আর প্রতিটা ধাপ শেষে টেস্ট চালিয়ে
আমার git পরিচয়ে (কোনো AI attribution ছাড়া) commit করবে।
```
