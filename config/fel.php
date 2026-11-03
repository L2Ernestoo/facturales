<?php

use Lc\Fel\Contracts\CertifierInterface;
use Lc\Fel\Contracts\FelConfigRepositoryInterface;
use Lc\Fel\Repositories\DatabaseFelConfigRepository;
use Lc\Fel\Services\Certifiers\GuatefacturasCertifier;

return [
    'timezone' => 'America/Guatemala',

    // true: issue() rechaza documentos cuyo total no coincide con la suma de
    // sus items. false: solo se registra un warning y metadata.total_mismatch.
    'strict_totals' => false,

    'default_certifier' => 'guatefacturas',
    'certifiers' => [
        'guatefacturas' => GuatefacturasCertifier::class,
    ],

    'bindings' => [
        CertifierInterface::class => GuatefacturasCertifier::class,
        FelConfigRepositoryInterface::class => DatabaseFelConfigRepository::class,
    ],

    'tables' => [
        'companies' => 'fel_companies',
        'credentials' => 'fel_company_credentials',
        'branch_settings' => 'fel_branch_settings',
        'documents' => 'fel_documents',
        'document_items' => 'fel_document_items',
        'annulments' => 'fel_annulments',
    ],

    'document_types' => [
        'FACT',
        'FCAM',
        'FPEQ',
        'FCAP',
        'FESP',
        'NABN',
        'RDON',
        'RECI',
        'NDEB',
        'NCRE',
    ],

    'defaults' => [
        'mode' => 'test',
        'regime' => 'general',
        'document_type' => 'FACT',
        'show_pos_switch' => false,
        'auto_mark_pos_switch' => false,
        'monthly_dte_limit' => null,
        'machine_id' => '1',
        'establishment_code' => '1',
        'receiver' => [
            'nit' => 'CF',
            'name' => 'Consumidor Final',
            'address' => 'Ciudad',
        ],
    ],
];
