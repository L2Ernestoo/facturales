<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('fel.tables.companies', 'fel_companies'), function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('nit', 30);
            $table->string('regime', 40)->default('general');
            $table->string('certifier', 40)->default('guatefacturas');
            $table->string('mode', 20)->default('test');
            $table->string('default_document_type', 10)->default('FACT');
            $table->boolean('show_pos_switch')->default(false);
            $table->boolean('auto_mark_pos_switch')->default(false);
            $table->unsignedInteger('monthly_dte_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'certifier']);
        });

        Schema::create(config('fel.tables.credentials', 'fel_company_credentials'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('fel_company_id')->constrained(config('fel.tables.companies', 'fel_companies'))->cascadeOnDelete();
            $table->string('user')->nullable();
            $table->text('password')->nullable();
            $table->string('basic_user')->nullable();
            $table->text('basic_password')->nullable();
            $table->text('url_test')->nullable();
            $table->text('url_prod')->nullable();
            $table->string('machine_id', 40)->default('1');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique('fel_company_id');
        });

        Schema::create(config('fel.tables.branch_settings', 'fel_branch_settings'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('fel_company_id')->constrained(config('fel.tables.companies', 'fel_companies'))->cascadeOnDelete();
            $table->unsignedBigInteger('branch_source_id')->nullable();
            $table->string('fiscal_name')->nullable();
            $table->string('address')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('establishment_code', 40)->default('1');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['fel_company_id', 'branch_source_id']);
            $table->index(['branch_source_id', 'is_active']);
        });

        Schema::create(config('fel.tables.documents', 'fel_documents'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('fel_company_id')->constrained(config('fel.tables.companies', 'fel_companies'))->restrictOnDelete();
            $table->foreignId('fel_branch_setting_id')->nullable()->constrained(config('fel.tables.branch_settings', 'fel_branch_settings'))->nullOnDelete();
            $table->string('source_type', 120);
            $table->string('source_id', 80);
            $table->string('document_type', 10);
            $table->string('status', 20)->default('PENDING');
            $table->string('reference', 80)->nullable();
            $table->string('buyer_nit', 45)->nullable();
            $table->string('buyer_name', 200)->nullable();
            $table->string('buyer_address', 255)->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->string('currency', 10)->default('GTQ');
            $table->string('fel_serie', 80)->nullable();
            $table->string('fel_preimpreso', 80)->nullable();
            $table->string('fel_uuid', 120)->nullable();
            $table->longText('request_xml')->nullable();
            $table->longText('response_body')->nullable();
            $table->longText('soap_request')->nullable();
            $table->dateTime('certified_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['document_type', 'status']);
            $table->index(['fel_company_id', 'certified_at']);
            $table->unique('fel_uuid');
        });

        Schema::create(config('fel.tables.document_items', 'fel_document_items'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('fel_document_id')->constrained(config('fel.tables.documents', 'fel_documents'))->cascadeOnDelete();
            $table->string('source_item_id')->nullable();
            $table->string('product_code', 60)->nullable();
            $table->string('description', 500);
            $table->string('measure', 20)->default('1');
            $table->decimal('quantity', 15, 5);
            $table->decimal('unit_price', 15, 7);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create(config('fel.tables.annulments', 'fel_annulments'), function (Blueprint $table) {
            $table->id();
            $table->foreignId('fel_document_id')->constrained(config('fel.tables.documents', 'fel_documents'))->cascadeOnDelete();
            $table->string('reason', 500);
            $table->dateTime('annulled_at')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->longText('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['fel_document_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('fel.tables.annulments', 'fel_annulments'));
        Schema::dropIfExists(config('fel.tables.document_items', 'fel_document_items'));
        Schema::dropIfExists(config('fel.tables.documents', 'fel_documents'));
        Schema::dropIfExists(config('fel.tables.branch_settings', 'fel_branch_settings'));
        Schema::dropIfExists(config('fel.tables.credentials', 'fel_company_credentials'));
        Schema::dropIfExists(config('fel.tables.companies', 'fel_companies'));
    }
};
