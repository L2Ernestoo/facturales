<?php

namespace Lc\Fel\Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Lc\Fel\Contracts\CertifierInterface;
use Lc\Fel\Data\FelItemData;
use Lc\Fel\Models\FelAnnulment;
use Lc\Fel\Models\FelDocument;
use Lc\Fel\Services\Certifiers\GuatefacturasCertifier;
use Lc\Fel\Services\FelManager;

class FelManagerTest extends FeatureTestCase
{
    private FakeCertifier $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeCertifier();
        $this->app->instance(CertifierInterface::class, $this->fake);
    }

    private function manager(): FelManager
    {
        return $this->app->make(FelManager::class);
    }

    public function test_issue_certifies_and_stores_snapshot(): void
    {
        $company = $this->createCompany();
        $data = $this->documentData($company, [new FelItemData('1', 'Producto', 2, 56, '1', 12, ['source_item_id' => 7])]);

        $result = $this->manager()->issue($data);

        $this->assertTrue($result['success']);
        $this->assertSame(FelDocument::STATUS_CERTIFIED, $result['document']->status);
        $this->assertSame('100.00', $result['document']->items->first()->line_total);
        $this->assertSame('7', $result['document']->items->first()->source_item_id);
        $this->assertArrayNotHasKey('total_mismatch', $result['document']->metadata);
    }

    public function test_certified_document_is_never_sent_twice(): void
    {
        $company = $this->createCompany();
        $data = $this->documentData($company);
        $document = $this->manager()->issue($data)['document'];

        $result = $this->manager()->certifyExisting($document, $data);

        $this->assertSame(1, $this->fake->certifyCalls);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['already_certified']);
        $this->assertSame($document->fel_uuid, $result['data']['uuid']);
    }

    public function test_annulled_document_cannot_be_recertified(): void
    {
        $company = $this->createCompany();
        $data = $this->documentData($company);
        $document = $this->manager()->issue($data)['document'];
        $document->update(['status' => FelDocument::STATUS_ANNULLED]);

        $result = $this->manager()->certifyExisting($document, $data);

        $this->assertFalse($result['success']);
        $this->assertSame(1, $this->fake->certifyCalls);
    }

    public function test_retry_refreshes_snapshot_with_new_data(): void
    {
        $company = $this->createCompany();
        $this->fake->succeed = false;
        $document = $this->manager()->issue($this->documentData($company))['document'];
        $this->assertSame(FelDocument::STATUS_ERROR, $document->status);

        $this->fake->succeed = true;
        $retryData = $this->documentData($company, [
            new FelItemData('2', 'Otro', 1, 50),
            new FelItemData('3', 'Más', 2, 25),
        ], null, [], 'Juan Perez');
        $result = $this->manager()->certifyExisting($document, $retryData);

        $this->assertTrue($result['success']);
        $this->assertSame('Juan Perez', $result['document']->buyer_name);
        $this->assertSame('100.00', $result['document']->total);
        $this->assertSame(['2', '3'], $result['document']->items->pluck('product_code')->all());
    }

    public function test_concurrent_certification_is_rejected_while_locked(): void
    {
        $company = $this->createCompany();
        $this->fake->succeed = false;
        $data = $this->documentData($company);
        $document = $this->manager()->issue($data)['document'];
        $this->fake->succeed = true;

        $lock = Cache::lock(sprintf(
            'fel:certify:%s:%s:%s',
            $document->getConnection()->getDatabaseName(),
            $document->getTable(),
            $document->getKey()
        ), 60);
        $this->assertTrue($lock->get());

        $result = $this->manager()->certifyExisting($document, $data);
        $lock->release();

        $this->assertFalse($result['success']);
        $this->assertTrue($result['busy']);
        $this->assertSame(1, $this->fake->certifyCalls);
    }

    public function test_annul_requires_a_certified_document(): void
    {
        $company = $this->createCompany();
        $this->fake->succeed = false;
        $document = $this->manager()->issue($this->documentData($company))['document'];

        $result = $this->manager()->annul($document, 'Prueba');

        $this->assertFalse($result['success']);
        $this->assertNull($result['annulment']);
        $this->assertSame(0, $this->fake->annulCalls);
        $this->assertSame(0, FelAnnulment::query()->count());
    }

    public function test_annul_marks_document_and_rejects_second_annulment(): void
    {
        $company = $this->createCompany();
        $document = $this->manager()->issue($this->documentData($company))['document'];

        $first = $this->manager()->annul($document, 'Error en datos', ['user_id' => 5]);
        $second = $this->manager()->annul($document, 'Otra vez');

        $this->assertTrue($first['success']);
        $this->assertSame('ANNULLED', $first['annulment']->status);
        $this->assertSame(FelDocument::STATUS_ANNULLED, $document->fresh()->status);
        $this->assertFalse($second['success']);
        $this->assertSame(1, $this->fake->annulCalls);
    }

    public function test_total_mismatch_is_recorded_without_blocking_by_default(): void
    {
        $company = $this->createCompany();

        $result = $this->manager()->issue($this->documentData($company, null, 150));

        $this->assertTrue($result['success']);
        $this->assertEquals(['declared' => 150, 'items' => 112], $result['document']->metadata['total_mismatch']);
    }

    public function test_strict_totals_blocks_mismatched_documents(): void
    {
        config(['fel.strict_totals' => true]);
        $company = $this->createCompany();

        $result = $this->manager()->issue($this->documentData($company, null, 150));

        $this->assertFalse($result['success']);
        $this->assertTrue($result['blocked']);
        $this->assertSame(0, FelDocument::query()->count());
    }

    public function test_each_company_uses_its_own_certifier(): void
    {
        $this->app->forgetInstance(CertifierInterface::class);
        $this->app->bind(CertifierInterface::class, GuatefacturasCertifier::class);
        $otro = new FakeCertifier();
        $this->app->instance(FakeCertifier::class, $otro);
        config(['fel.certifiers.otro' => FakeCertifier::class]);

        $this->createCompany();
        $second = $this->createCompany(['name' => 'Otra', 'nit' => '7654321', 'certifier' => 'otro']);

        $result = $this->manager()->issue($this->documentData($second));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $otro->certifyCalls);
    }
}
