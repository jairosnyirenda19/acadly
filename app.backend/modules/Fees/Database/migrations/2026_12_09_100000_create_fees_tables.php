<?php

declare(strict_types=1);

use Acadly\Kernel\Database\AppendOnly;
use Acadly\Kernel\Support\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-091. Fees: what the school charges and what families pay. Not an
 * accounting system: no journals, no chart of accounts, one currency.
 *
 * Money is a whole number of minor units (tambala), never a decimal.
 * Charges, their lines, adjustments, payments, payment shares and voids are
 * facts and never change: a mistake is a new row. Which instalment a payment
 * paid is worked out from the facts, oldest due first (the Ledger); a
 * receipt keeps what it said when it was given.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What the school charges for: tuition, development fund, boarding.
        Schema::create('fee_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->boolean('optional')->default(false);
            $table->unsignedInteger('position');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        // The learners who take an optional item, such as boarding.
        Schema::create('fee_item_takers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('item_id')->constrained('fee_items');
            $table->uuid('learner_id');
            $table->uuid('set_by')->nullable();
            $table->timestampTz('started_at', 6);
            $table->timestampTz('ended_at', 6)->nullable();
            $table->index('learner_id');
        });
        DB::statement('CREATE UNIQUE INDEX fee_item_takers_one_open ON fee_item_takers (item_id, learner_id) WHERE ended_at IS NULL');

        // The fees of one term for one division: amounts by level, and the instalment plan.
        Schema::create('fee_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('period_id');
            $table->uuid('division_id');
            $table->timestampTz('billed_at', 6)->nullable();
            $table->uuid('billed_by')->nullable();
            $table->timestampsTz(6);
            $table->unique(['period_id', 'division_id']);
        });

        Schema::create('fee_schedule_amounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('fee_schedules');
            $table->foreignUuid('item_id')->constrained('fee_items');
            $table->string('level_code', 20);
            $table->bigInteger('amount_minor');
            $table->unique(['schedule_id', 'item_id', 'level_code']);
        });

        Schema::create('fee_schedule_instalments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_id')->constrained('fee_schedules');
            $table->unsignedSmallInteger('position');
            $table->string('label', 60);
            $table->date('due_on');
            // A share of the fees in basis points: 5000 is 50%.
            $table->unsignedSmallInteger('share_bp');
            $table->unique(['schedule_id', 'position']);
        });

        // What one learner is charged: a term's fees, or a sale from the school shop.
        Schema::create('fee_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->uuid('learner_id');
            $table->string('kind', 12);
            $table->uuid('schedule_id')->nullable();
            $table->string('label', 120);
            $table->bigInteger('total_minor');
            $table->date('issued_on');
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at', 6);
            $table->index('learner_id');
        });
        DB::statement('CREATE UNIQUE INDEX fee_charges_one_term_bill ON fee_charges (schedule_id, learner_id) WHERE schedule_id IS NOT NULL');
        DB::statement('ALTER TABLE fee_charges ADD CONSTRAINT fee_charges_positive CHECK (total_minor > 0)');

        Schema::create('fee_charge_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('charge_id')->constrained('fee_charges');
            $table->uuid('item_id')->nullable();
            $table->string('label', 120);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->bigInteger('amount_minor');
            $table->unsignedSmallInteger('position');
        });

        // When a charge is due, in parts. An agreed plan replaces the school's plan; the old rows stay.
        Schema::create('fee_instalments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('charge_id')->constrained('fee_charges');
            $table->unsignedSmallInteger('position');
            $table->string('label', 60);
            $table->date('due_on');
            $table->bigInteger('amount_minor');
            $table->string('plan', 12);
            $table->text('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at', 6);
            $table->timestampTz('superseded_at', 6)->nullable();
            $table->index(['charge_id', 'superseded_at']);
        });

        // A discount, bursary, waiver or write-off: less to pay on one charge.
        Schema::create('fee_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->foreignUuid('charge_id')->constrained('fee_charges');
            $table->uuid('learner_id');
            $table->string('kind', 12);
            $table->bigInteger('amount_minor');
            $table->text('reason');
            $table->uuid('created_by');
            $table->date('made_on');
            $table->timestampTz('created_at', 6);
            $table->index('learner_id');
        });
        DB::statement('ALTER TABLE fee_adjustments ADD CONSTRAINT fee_adjustments_positive CHECK (amount_minor > 0)');

        // Money received, once, with its receipt number.
        Schema::create('fee_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('number', 24)->unique();
            $table->date('received_on');
            $table->string('method', 16);
            $table->string('reference', 80)->nullable();
            $table->string('payer_name', 120);
            $table->string('payer_phone', 30)->nullable();
            $table->bigInteger('amount_minor');
            $table->uuid('received_by');
            $table->timestampTz('created_at', 6);
            $table->index('received_on');
        });
        DB::statement('CREATE UNIQUE INDEX fee_payments_one_reference ON fee_payments (method, lower(reference)) WHERE reference IS NOT NULL');
        DB::statement('ALTER TABLE fee_payments ADD CONSTRAINT fee_payments_positive CHECK (amount_minor > 0)');

        // One child's part of a payment, and what the receipt said it paid.
        Schema::create('fee_payment_shares', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('fee_payments');
            $table->uuid('learner_id');
            $table->uuid('charge_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->jsonb('applied');
            $table->bigInteger('balance_after_minor');
            $table->index('learner_id');
        });
        DB::statement('ALTER TABLE fee_payment_shares ADD CONSTRAINT fee_payment_shares_positive CHECK (amount_minor > 0)');

        // A cancelled receipt: the payment stays, and the cancellation is its own line.
        Schema::create('fee_payment_voids', function (Blueprint $table): void {
            $table->foreignUuid('payment_id')->primary()->constrained('fee_payments');
            $table->string('number', 24)->unique();
            $table->text('reason');
            $table->uuid('voided_by');
            $table->date('voided_on');
            $table->timestampTz('created_at', 6);
        });

        // Gap-free numbers, one series per kind per year.
        Schema::create('fee_sequences', function (Blueprint $table): void {
            $table->string('scope', 8);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number');
            $table->primary(['scope', 'year']);
        });

        // A closed day: cash counted against the cash receipts.
        Schema::create('fee_days', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->bigInteger('expected_cash_minor');
            $table->bigInteger('counted_cash_minor');
            $table->text('note')->nullable();
            $table->uuid('closed_by');
            $table->timestampTz('closed_at', 6);
        });

        // What the school shop sells, at a price.
        Schema::create('fee_shop_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('size', 30)->nullable();
            $table->bigInteger('price_minor');
            $table->unsignedInteger('position');
            $table->timestampTz('retired_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        foreach (['fee_charges', 'fee_charge_lines', 'fee_adjustments', 'fee_payments', 'fee_payment_shares', 'fee_payment_voids', 'fee_days'] as $table) {
            AppendOnly::protect($table);
        }

        // The items a school starts from. It changes them in Settings.
        $now = now();
        foreach ([['Tuition', false], ['Development fund', false], ['Examination', false], ['Boarding', true], ['Transport', true]] as $i => [$name, $optional]) {
            DB::table('fee_items')->insert(['id' => Ids::next(), 'name' => $name, 'optional' => $optional, 'position' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['fee_shop_items', 'fee_days', 'fee_sequences', 'fee_payment_voids', 'fee_payment_shares', 'fee_payments', 'fee_adjustments', 'fee_instalments',
            'fee_charge_lines', 'fee_charges', 'fee_schedule_instalments', 'fee_schedule_amounts', 'fee_schedules', 'fee_item_takers', 'fee_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
