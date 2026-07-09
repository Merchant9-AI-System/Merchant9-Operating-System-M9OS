<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jemisys_category_mirror', function (Blueprint $table) {
            $table->id();

            $table->string('CategoryCode', 20);
            $table->string('CategoryGroup', 50);
            $table->string('Description', 60)->nullable();
            $table->string('Description2', 60)->nullable();
            $table->string('Prefix', 5)->nullable();
            $table->string('AutoNoFrom', 10);
            $table->string('AutoNoTo', 10);
            $table->decimal('NextAutoNo', 18, 0);
            $table->string('PersonInCharge', 10)->nullable();
            $table->string('ReportType', 50)->nullable();
            $table->string('ReportType2', 50)->nullable();
            $table->string('Prefix2', 4)->nullable();
            $table->string('Prefix3', 4)->nullable();
            $table->string('Prefix4', 4)->nullable();
            $table->string('Prefix5', 4)->nullable();
            $table->string('Prefix6', 4)->nullable();
            $table->integer('NextInternalCode');
            $table->boolean('IsMiscCategory');
            $table->smallInteger('ExchangeDays');
            $table->smallInteger('OrderOfDisplay');
            $table->string('CreatedBy', 20)->nullable();
            $table->dateTime('CreatedDate');
            $table->string('ModifiedBy', 20)->nullable();
            $table->dateTime('ModifiedDate');
            $table->string('Polling', 1)->nullable();
            $table->smallInteger('UpgradeDays');
            $table->boolean('IsGSTExempted');
            $table->boolean('IsNoStockCategory');
            $table->boolean('IsFreeGiftCategory');
            $table->boolean('TransferThruTR');
            $table->boolean('ItemRemarksPOSVisible');
            $table->boolean('CanPrint');
            $table->smallInteger('PromotionDays');
            $table->decimal('CostPrice', 19, 4);
            $table->decimal('ListingPrice', 19, 4);

            $table->timestamp('synced_at');

            $table->unique('CategoryCode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jemisys_category_mirror');
    }
};
