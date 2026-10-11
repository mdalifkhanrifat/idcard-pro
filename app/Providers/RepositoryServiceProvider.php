<?php

namespace App\Providers;

use App\Repositories\Contracts\ActivityRepositoryInterface;
use App\Repositories\Contracts\BiometricCaptureRepositoryInterface;
use App\Repositories\Contracts\CardBiometricImageRepositoryInterface;
use App\Repositories\Contracts\CardholderFileRepositoryInterface;
use App\Repositories\Contracts\CardholderImportRepositoryInterface;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Repositories\Contracts\CardSequenceRepositoryInterface;
use App\Repositories\Contracts\CardTemplateElementRepositoryInterface;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\CardVerificationRepositoryInterface;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Repositories\Contracts\LoginEventRepositoryInterface;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Repositories\Contracts\PasswordResetTokenRepositoryInterface;
use App\Repositories\Contracts\QualificationRepositoryInterface;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\SignatoryRepositoryInterface;
use App\Repositories\Contracts\TenantFontRepositoryInterface;
use App\Repositories\Contracts\TenantImageRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Eloquent\ActivityRepository;
use App\Repositories\Eloquent\BiometricCaptureRepository;
use App\Repositories\Eloquent\CardBiometricImageRepository;
use App\Repositories\Eloquent\CardholderFileRepository;
use App\Repositories\Eloquent\CardholderImportRepository;
use App\Repositories\Eloquent\CardholderRepository;
use App\Repositories\Eloquent\CardPrintRepository;
use App\Repositories\Eloquent\CardRepository;
use App\Repositories\Eloquent\CardSequenceRepository;
use App\Repositories\Eloquent\CardTemplateElementRepository;
use App\Repositories\Eloquent\CardTemplateRepository;
use App\Repositories\Eloquent\CardVerificationRepository;
use App\Repositories\Eloquent\CustomFieldRepository;
use App\Repositories\Eloquent\FamilyMemberRepository;
use App\Repositories\Eloquent\LoginEventRepository;
use App\Repositories\Eloquent\MasterDataRepository;
use App\Repositories\Eloquent\PasswordResetTokenRepository;
use App\Repositories\Eloquent\QualificationRepository;
use App\Repositories\Eloquent\RoleRepository;
use App\Repositories\Eloquent\SignatoryRepository;
use App\Repositories\Eloquent\TenantFontRepository;
use App\Repositories\Eloquent\TenantImageRepository;
use App\Repositories\Eloquent\TenantRepository;
use App\Repositories\Eloquent\UserRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds repository contracts to their implementations.
 * Swap an implementation here (e.g. for caching or another data source) without touching services.
 */
class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        MasterDataRepositoryInterface::class => MasterDataRepository::class,
        CardholderRepositoryInterface::class => CardholderRepository::class,
        CardholderFileRepositoryInterface::class => CardholderFileRepository::class,
        BiometricCaptureRepositoryInterface::class => BiometricCaptureRepository::class,
        CardBiometricImageRepositoryInterface::class => CardBiometricImageRepository::class,
        FamilyMemberRepositoryInterface::class => FamilyMemberRepository::class,
        QualificationRepositoryInterface::class => QualificationRepository::class,
        CustomFieldRepositoryInterface::class => CustomFieldRepository::class,
        CardholderImportRepositoryInterface::class => CardholderImportRepository::class,
        SignatoryRepositoryInterface::class => SignatoryRepository::class,
        TenantFontRepositoryInterface::class => TenantFontRepository::class,
        CardRepositoryInterface::class => CardRepository::class,
        CardPrintRepositoryInterface::class => CardPrintRepository::class,
        CardVerificationRepositoryInterface::class => CardVerificationRepository::class,
        TenantImageRepositoryInterface::class => TenantImageRepository::class,
        CardSequenceRepositoryInterface::class => CardSequenceRepository::class,
        CardTemplateRepositoryInterface::class => CardTemplateRepository::class,
        CardTemplateElementRepositoryInterface::class => CardTemplateElementRepository::class,
        UserRepositoryInterface::class => UserRepository::class,
        RoleRepositoryInterface::class => RoleRepository::class,
        TenantRepositoryInterface::class => TenantRepository::class,
        ActivityRepositoryInterface::class => ActivityRepository::class,
        LoginEventRepositoryInterface::class => LoginEventRepository::class,
        PasswordResetTokenRepositoryInterface::class => PasswordResetTokenRepository::class,
    ];
}
