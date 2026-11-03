<?php

namespace Lc\Fel\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Lc\Fel\Data\FelBranchData;
use Lc\Fel\Data\FelCompanyData;
use Lc\Fel\Data\FelDocumentData;
use Lc\Fel\Data\FelItemData;
use Lc\Fel\Data\FelReceiverData;
use Lc\Fel\Models\FelBranchSetting;
use Lc\Fel\Models\FelCompany;
use Lc\Fel\Models\FelCompanyCredential;
use Lc\Fel\Providers\FelServiceProvider;
use Orchestra\Testbench\TestCase;

abstract class FeatureTestCase extends TestCase
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [FelServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('cache.default', 'array');
    }

    protected function createCompany(array $attributes = []): FelCompany
    {
        $company = FelCompany::create(array_merge([
            'name' => 'Empresa Demo',
            'nit' => '1234567',
            'regime' => 'general',
            'certifier' => 'guatefacturas',
            'mode' => 'test',
        ], $attributes));

        FelCompanyCredential::create([
            'fel_company_id' => $company->id,
            'user' => 'USR',
            'password' => 'SECRET-PASS',
            'url_test' => 'https://example.test/guatefac',
        ]);

        FelBranchSetting::create([
            'fel_company_id' => $company->id,
            'branch_source_id' => 1,
            'fiscal_name' => 'Sucursal',
            'establishment_code' => '1',
        ]);

        return $company;
    }

    /**
     * @param array<int, FelItemData>|null $items
     */
    protected function documentData(FelCompany $company, ?array $items = null, ?float $total = null, array $options = [], string $buyer = 'Consumidor Final'): FelDocumentData
    {
        $items ??= [new FelItemData('1', 'Producto', 1, 112, '1', 0, ['source_item_id' => 10])];
        $branch = $company->branches()->first();

        return new FelDocumentData(
            documentType: 'FACT',
            reference: 'ORD-1',
            date: Carbon::parse('2026-06-06'),
            company: new FelCompanyData($company->id, $company->name, $company->nit, $company->regime, $company->certifier, $company->mode),
            branch: new FelBranchData($branch->id, 1, $company->id, 'Sucursal', 'Ciudad', '1'),
            receiver: new FelReceiverData('CF', $buyer, 'Ciudad'),
            items: $items,
            total: $total ?? array_sum(array_map(fn ($item) => $item->quantity * $item->unitPrice - $item->discount, $items)),
            sourceType: 'App\\Models\\Order',
            sourceId: 1,
            options: $options,
        );
    }
}
