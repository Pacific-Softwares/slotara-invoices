<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per business: what goes on its invoices and how numbers are made.
        Schema::create('slotara_invoices_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name')->nullable();
            $table->text('business_details')->nullable();   // address, tax/VAT number, contact
            $table->string('prefix', 20)->default('INV-');
            $table->unsignedInteger('next_number')->default(1);
            $table->string('tax_label', 30)->default('Tax');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedSmallInteger('due_days')->default(14);
            $table->boolean('auto_from_bookings')->default(true);
            $table->text('payment_instructions')->nullable();
            $table->text('footer')->nullable();
            $table->timestamps();
        });

        Schema::create('slotara_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('slot_reservations')->nullOnDelete();
            $table->string('number', 40);
            $table->string('status', 16)->default('draft'); // draft | sent | paid | void
            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->text('customer_details')->nullable();
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->string('tax_label', 30)->default('Tax');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('public_token', 48)->unique();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Schema::create('slotara_invoices_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('slotara_invoices')->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slotara_invoices_items');
        Schema::dropIfExists('slotara_invoices');
        Schema::dropIfExists('slotara_invoices_settings');
    }
};
